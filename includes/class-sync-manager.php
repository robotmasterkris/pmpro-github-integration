<?php

if (!defined('ABSPATH')) exit;

class PMPro_GitHub_Sync_Manager {

    public function __construct() {
        add_action('pmpro_after_change_membership_level', array($this, 'sync_user_teams'), 10, 2);
        add_action('pmpro_github_batch_sync', array($this, 'batch_sync_users'));
        add_action('pmpro_github_sync_user', array($this, 'handle_user_sync'));
        add_action('init', array($this, 'handle_github_disconnect'));
        add_action('pmpro_github_disconnect_user', array($this, 'handle_manual_github_disconnect'));
        add_action('pmpro_github_accept_invite', array($this, 'accept_invite'));
        add_action('pmpro_github_bulk', array($this, 'bulk_sync_users'));
    }

    public function sync_user_teams($level_id, $user_id) {
        $result = as_enqueue_async_action('pmpro_github_sync_user', [ $user_id ] );
        if (is_wp_error($result)) {
            $failed_jobs = (int)get_option('pmpro_github_failed_jobs', 0);
            update_option('pmpro_github_failed_jobs', $failed_jobs + 1);
        }
    }

    public function batch_sync_users() {
        $users = get_users(array('meta_key' => '_pmpro_github_username'));
        $chunks = array_chunk($users, 50);

        foreach ($chunks as $chunk) {
            as_enqueue_async_action('pmpro_github_bulk', [ wp_list_pluck( $chunk, 'ID' ) ] );
        }
    }

    public function bulk_sync_users($user_ids) {
        foreach ($user_ids as $user_id) {
            as_enqueue_async_action('pmpro_github_sync_user', [ $user_id ] );
        }
    }

    public function handle_user_sync($user_id) {
        if (PMPRO_GITHUB_VERBOSE) error_log( "GH-Sync ▶ begin user {$user_id}" );
        $github_username = get_user_meta($user_id, '_pmpro_github_username', true);
        if (empty($github_username)) {
            update_user_meta( $user_id, '_pmpro_github_reconnect_needed', 1 );
            if (PMPRO_GITHUB_VERBOSE) error_log( "GH-Sync ▶ abort – missing github_username for user {$user_id}" );
            return;
        }

        $pat = get_option('pmpro_github_pat');
        $org = get_option('pmpro_github_org_name');

        $user_levels = pmpro_getMembershipLevelsForUser($user_id);
        $mapped_teams = $this->get_mapped_teams($user_levels);

        // --- Fetch current teams for the user (correct approach) ---
        $all_org_teams = $this->get_org_teams();
        $cached_teams = array();
        $all_team_slugs = array();
        if ( is_array( $all_org_teams ) ) {
            foreach ( $all_org_teams as $team ) {
                if ( isset( $team->slug ) ) {
                    $all_team_slugs[] = $team->slug;
                    // Check if user is a member of this team
                    $membership_url = "https://api.github.com/orgs/{$org}/teams/{$team->slug}/memberships/{$github_username}";
                    $membership_response = $this->github_api_request_pat(
                        $membership_url,
                        array(
                            'method' => 'GET',
                            'headers' => array(
                                'Accept' => 'application/vnd.github.v3+json',
                                'User-Agent' => PMPRO_GITHUB_USER_AGENT
                            )
                        ),
                        $user_id
                    );
                    $membership_status = is_wp_error($membership_response) ? $membership_response->get_error_code() : wp_remote_retrieve_response_code($membership_response);
                    if ($membership_status === 200) {
                        $cached_teams[] = $team->slug;
                    } elseif ($membership_status !== 404) {
                        if (PMPRO_GITHUB_VERBOSE) error_log("GH-TEAMCHECK ▶ Error checking team {$team->slug} for user {$github_username}: status {$membership_status}");
                    }
                }
            }
        } else {
            if (PMPRO_GITHUB_VERBOSE) error_log("GH-REMOVE ▶ Failed to fetch org teams for user {$github_username}");
        }
        set_transient('pmpro_github_current_teams_' . $user_id, $cached_teams, 300);

        $invite_triggered = false;                    

        // --- Remove user from teams they should no longer be in ---
        if (PMPRO_GITHUB_VERBOSE) error_log("GH-CHECK ▶ User {$github_username} mapped_teams: " . json_encode($mapped_teams));
        if (PMPRO_GITHUB_VERBOSE) error_log("GH-CHECK ▶ User {$github_username} cached_teams: " . json_encode($cached_teams));
        // Teams the user is currently in but not mapped to their current levels
        $teams_to_remove = array_diff( $cached_teams, $mapped_teams );
        if (PMPRO_GITHUB_VERBOSE) error_log("GH-REMOVE ▶ User {$github_username} teams to remove: " . json_encode($teams_to_remove));
        
        foreach ( $teams_to_remove as $team_slug ) {
            if ( in_array( $team_slug, $all_team_slugs, true ) ) {
                if (PMPRO_GITHUB_VERBOSE) error_log( "GH-REMOVE ▶ Removing user {$github_username} from team {$team_slug}" );
                $remove_response = $this->github_api_request_pat(
                    "https://api.github.com/orgs/{$org}/teams/{$team_slug}/memberships/{$github_username}",
                    array(
                        'method' => 'DELETE',
                        'headers' => array(
                            'Accept' => 'application/vnd.github.v3+json',
                            'User-Agent' => PMPRO_GITHUB_USER_AGENT
                        )
                    ),
                    $user_id
                );
                if ( is_wp_error( $remove_response ) ) {
                    if (PMPRO_GITHUB_VERBOSE) error_log( 'GH-REMOVE ▶ Error removing user: ' . $remove_response->get_error_message() );
                } else {
                    $remove_status = wp_remote_retrieve_response_code( $remove_response );
                    if (PMPRO_GITHUB_VERBOSE) error_log( "GH-REMOVE ▶ HTTP {$remove_status} for team {$team_slug}" );

                    delete_transient('pmpro_github_user_teams_' . $user_id);
                }
            } else {
                if (PMPRO_GITHUB_VERBOSE) error_log( "GH-REMOVE ▶ Team {$team_slug} not found in org teams for user {$github_username}" );
            }
        }

        foreach ( $mapped_teams as $team_slug ) {

            if ( in_array( $team_slug, $cached_teams, true ) ) {
                continue;
            }

            /*
                Adds a user to a team. If the user is not already a member of the organization,
                sends an invitation to the user. Membership will be in the "pending" state
                until the user accepts the invitation.

                More info:
                https://docs.github.com/en/rest/teams/members?apiVersion=2022-11-28#add-or-update-team-membership-for-a-user
            */

            $response = $this->github_api_request_pat(
                "https://api.github.com/orgs/{$org}/teams/{$team_slug}/memberships/{$github_username}",
                array_merge(
                    pmpro_github_http_defaults(),
                    [
                        'method'  => 'PUT',
                        'body'    => wp_json_encode( [ 'role' => 'member' ] ),
                        'headers' => [ 'Content-Type' => 'application/json' ],
                    ]
                ),
                $user_id
            );

            $status = wp_remote_retrieve_response_code( $response );
            $body_raw = wp_remote_retrieve_body( $response );
            $body = json_decode( $body_raw );
            if (PMPRO_GITHUB_VERBOSE) error_log( "GH-PUT ▶ HTTP {$status} payload={$body_raw}" );

            // Any status that means “membership *will* exist soon”.
            if ( in_array( $status, [ 202, 201, 200, 404, 409, 422 ], true ) ) {

                // 200 / 201 ─ success right away
                // 202        ─ accepted, but async on GitHub’s side
                // 404 / 409 / 422 ─ user not in org or validation conflict → invite flow

                if ( $status === 202 ||                                   // “request accepted”
                    ( $status === 200 &&                               // 200 but
                    isset( $body->state ) && $body->state === 'pending' ) // still pending
                ) {
                    if (PMPRO_GITHUB_VERBOSE) error_log( "GH-Pending ▶ invitation pending, scheduling accept_invite" );
                    // cache that an invite exists so we stop looping
                    update_user_meta( $user_id, '_pmpro_github_invite_sent', time() );

                    $invite_triggered = true;

                    // queue the auto-accept job (arg must be a named array!)
                    $job = as_enqueue_async_action(
                        'pmpro_github_accept_invite',
                        [ 'user_id' => $user_id ]
                    );
                    error_log(
                        is_wp_error( $job )
                            ? "GH-Enqueue ▶ " . $job->get_error_message()
                            : "GH-Enqueue ▶ job {$job} queued (auto-invite)"
                    );
                    // return;                                              // done for now
                }

                if ( in_array( $status, [ 404, 409, 422 ], true ) ) {
                    if (PMPRO_GITHUB_VERBOSE) error_log( 'User not in org – sending invite.' );
                    $this->invite_user_to_org( $github_username, $user_id );
                    $invite_triggered = true;
                }

                continue;                               // try the next team right away
            }

            // Anything else we treat as a hard error.
            if ( is_wp_error( $response ) ) {
                if (PMPRO_GITHUB_VERBOSE) error_log( 'GitHub API error: ' . $response->get_error_message() );
            } else {
                if (PMPRO_GITHUB_VERBOSE) error_log( "GitHub unexpected status {$status}" );
            }
            delete_transient('pmpro_github_user_teams_' . $user_id);
        }

        /* -----------------------------------------------------------
        * If we sent (or reused) an invitation for ANY team, enqueue
        * one follow-up sync x minutes from now.  That follow-up will
        * pick up extra teams once the org membership is active, or
        * simply do nothing if everything’s already in place.
        * ---------------------------------------------------------- */
        if ( $invite_triggered && ! get_user_meta( $user_id, '_pmpro_github_followup_scheduled', true ) ) {
            if (PMPRO_GITHUB_VERBOSE) error_log( "GH-Followup ▶ invite sent, scheduling follow-up sync" );
            update_user_meta( $user_id, '_pmpro_github_followup_scheduled', time() );

            $job = as_schedule_single_action(
                time() + 60,                             // 1 min
                'pmpro_github_sync_user',
                [ 'user_id' => $user_id ],
                'pmpro_github'                            // optional custom group
            );

            error_log(
                is_wp_error( $job )
                    ? 'GH-Enqueue ▶ ' . $job->get_error_message()
                    : "GH-Enqueue ▶ follow-up job {$job} queued"
            );
        }
        // Clear the followup flag if the user is now a member of all mapped teams (no invite triggered)
        if ( ! $invite_triggered ) {
            delete_user_meta( $user_id, '_pmpro_github_followup_scheduled' );
        }
    }

    public function handle_github_disconnect() {
        if (isset($_GET['pmpro_github_disconnect']) && is_user_logged_in()) {
            $user_id = get_current_user_id();
            $github_username = get_user_meta($user_id, '_pmpro_github_username', true);
            if ($github_username) {
                $pat = get_option('pmpro_github_pat');
                $org = get_option('pmpro_github_org_name');

                $teams = $this->get_mapped_teams(pmpro_getMembershipLevelsForUser($user_id));
                foreach ($teams as $team_slug) {
                    $this->github_api_request_pat("https://api.github.com/orgs/{$org}/teams/{$team_slug}/memberships/{$github_username}", array(
                        'method' => 'DELETE',
                        'headers' => array(
                            'Accept' => 'application/vnd.github.v3+json',
                            'User-Agent' => PMPRO_GITHUB_USER_AGENT
                        )
                    ), $user_id);
                }
                delete_transient('pmpro_github_user_teams_' . $user_id);
                delete_user_meta($user_id, '_pmpro_github_username');
            }

            wp_redirect(home_url());
            exit;
        }
    }

    public function handle_manual_github_disconnect($user_id) {
        $github_username = get_user_meta($user_id, '_pmpro_github_username', true);
        if ($github_username) {
            $pat = get_option('pmpro_github_pat');
            $org = get_option('pmpro_github_org_name');

            $teams = $this->get_mapped_teams(pmpro_getMembershipLevelsForUser($user_id));
            foreach ($teams as $team_slug) {
                $this->github_api_request_pat("https://api.github.com/orgs/{$org}/teams/{$team_slug}/memberships/{$github_username}", array(
                    'method' => 'DELETE',
                    'headers' => array(
                        'Accept' => 'application/vnd.github.v3+json',
                        'User-Agent' => PMPRO_GITHUB_USER_AGENT
                    )
                ), $user_id);
            }
            delete_transient('pmpro_github_user_teams_' . $user_id);
            delete_user_meta($user_id, '_pmpro_github_username');
        }
    }

    private function get_mapped_teams($user_levels) {
        $mapped_teams = array();
        $team_mappings = get_option('pmpro_github_team_mappings', array());

        foreach ($user_levels as $level) {
            if (isset($team_mappings[$level->id])) {
                $mapped_teams = array_merge($mapped_teams, $team_mappings[$level->id]);
            }
        }

        return array_unique($mapped_teams);
    }

    private function github_api_request($url, $args, $user_id) {
        $token_encrypted = get_user_meta($user_id, '_pmpro_github_token', true);
        $token = robotwealth_decrypt_value( get_user_meta( $user_id, '_pmpro_github_token', true ) );

        if ( $token === false ) {
            // wipe bad value and show “Reconnect” banner
            delete_user_meta( $user_id, '_pmpro_github_token' );
            update_user_meta( $user_id, '_pmpro_github_reconnect_needed', 1 );
            return [];           // treat as not connected
        }

        $args['headers']['Authorization'] = 'token ' . $token;
        $response = wp_remote_request($url, pmpro_github_http_defaults($args));

        if (wp_remote_retrieve_response_code($response) === 401) {
            // Remove user from all teams if token is revoked
            $this->remove_user_from_all_teams($user_id);
            delete_user_meta($user_id, '_pmpro_github_token');
            update_user_meta($user_id, '_pmpro_github_reconnect_needed', 1);
            delete_transient('pmpro_github_user_teams_' . $user_id);
            return new WP_Error('token_revoked', 'GitHub token revoked or invalid.');
        }

        return $response;
    }

    /**
     * Low-level GitHub call with owner PAT and built-in error handling.
     *
     * @param string $url      Full https://api.github.com/… URL
     * @param array  $args     Request args (method, headers, body …)
     * @param int    $user_id  Unused; kept for signature parity
     *
     * @return array|WP_Error
     */
    private function github_api_request_pat( $url, $args, $user_id ) {

        $pat = get_option( 'pmpro_github_pat' );
        if ( empty( $pat ) ) {
            return new WP_Error( 'no_pat', __( 'Owner PAT not configured.', 'pmpro-github' ) );
        }

        $args['headers']['Authorization'] = 'token ' . $pat;

        /* helper for one retry on 5xx ------------------------------------- */
        $make = function () use ( $url, $args ) {
            return wp_remote_request( $url, pmpro_github_http_defaults( $args ) );
        };

        $response = $make();

        /* Network-level error (DNS, SSL, timeout …) */
        if ( is_wp_error( $response ) ) {
            if (PMPRO_GITHUB_VERBOSE) error_log( 'GH-API ▶ network error: ' . $response->get_error_message() );
            return $response;              // bubble up the WP_Error
        }

        $code = wp_remote_retrieve_response_code( $response );

        /* Retry once on transient 5xx */
        if ( $code >= 500 && $code < 600 ) {
            sleep( 1 );                    // small back-off
            $response = $make();
            $code     = wp_remote_retrieve_response_code( $response );
        }

        /* Non-success status? return WP_Error */
        if ( $code < 200 || $code >= 300 ) {
            $body = wp_remote_retrieve_body( $response );
            if (PMPRO_GITHUB_VERBOSE) error_log( sprintf( 'GH-API ▶ %s %s → HTTP %d – %s', $args['method'], $url, $code, $body ) );

            return new WP_Error(
                'github_http_' . $code,
                sprintf( 'GitHub API returned HTTP %d', $code ),
                [
                    'status' => $code,
                    'body'   => $body,
                ]
            );
        }

        return $response;  // success (2xx)
    }

    private function invite_user_to_org($github_username, $user_id) {
        $pat = get_option('pmpro_github_pat');
        $org = get_option('pmpro_github_org_name');

        $uid = (int) get_user_meta( $user_id, '_pmpro_github_uid', true );
        if ( ! $uid ) {
            if (PMPRO_GITHUB_VERBOSE) error_log( "GH-Invite ▶ abort – missing _pmpro_github_uid for user {$user_id}" );
            return;
        }

        if (PMPRO_GITHUB_VERBOSE) error_log('GH-Invite ▶ POST start – uid={$uid} pat={$pat} org={$org}');

        $invite_response = wp_remote_post("https://api.github.com/orgs/{$org}/invitations", array_merge(pmpro_github_http_defaults(), [
            'headers' => [
                'Authorization' => 'token ' . $pat,
                'Accept' => 'application/vnd.github.v3+json'
            ],
            'body'    => wp_json_encode( [ 'invitee_id' => $uid ] ),
        ]));

        if ( is_wp_error( $invite_response ) ) {
            if (PMPRO_GITHUB_VERBOSE) error_log( 'GH-Invite ▶ WP_Error: ' . $invite_response->get_error_message() );
            return;
        }

        $invite_code = wp_remote_retrieve_response_code($invite_response);
        if (PMPRO_GITHUB_VERBOSE) error_log( "GH-Invite ▶ HTTP {$invite_code}" );

        if ( $invite_code >= 200 && $invite_code < 300 || $invite_code === 422) {
            update_user_meta($user_id, '_pmpro_github_invite_sent', time());
            $job = as_enqueue_async_action('pmpro_github_accept_invite',  [ 'user_id' => $user_id ] );
            error_log( is_wp_error( $job )  ? 'GH-Enqueue ▶ ' . $job->get_error_message()
                                            : "GH-Enqueue ▶ job {$job} queued" );
        } else {
            update_user_meta($user_id, '_pmpro_github_invite_pending', time());
            if (PMPRO_GITHUB_VERBOSE) error_log( "GH-Invite ▶ failed – unexpected HTTP {$code}" );
        }
    }

    public function accept_invite($user_id) {
        if (PMPRO_GITHUB_VERBOSE) error_log( "GH-Accept ▶ fired for user {$user_id}" );
        $org = get_option(strtolower('pmpro_github_org_name'));
        $response = $this->github_api_request("https://api.github.com/user/memberships/orgs/{$org}", array(
            'method' => 'PATCH',
            'headers' => array('User-Agent' => PMPRO_GITHUB_USER_AGENT),
            'body' => json_encode(array('state' => 'active'))
        ), $user_id);

        $response_code = wp_remote_retrieve_response_code($response);

        if (PMPRO_GITHUB_VERBOSE) error_log( 'GH-Accept ▶ HTTP ' . $response_code .
               ' – body: ' . wp_remote_retrieve_body( $response ) );

        if ($response_code === 403 || $response_code === 404) {
            $tries = (int)get_user_meta($user_id, '_pmpro_github_accept_tries', true);
            if ($tries < 3) {
                update_user_meta($user_id, '_pmpro_github_accept_tries', $tries + 1);
                $delay = pow(2, $tries);
                as_schedule_single_action(time() + $delay, 'pmpro_github_accept_invite', array('user_id' => $user_id));
            } else {
                update_user_meta($user_id, '_pmpro_github_invite_pending', time());
                if (PMPRO_GITHUB_VERBOSE) error_log('GitHub invite pending after multiple retries.');
            }
        } else {
            delete_user_meta($user_id, '_pmpro_github_invite_pending');
            delete_user_meta($user_id, '_pmpro_github_accept_tries'); // Clear retry counter after successful acceptance
        }
    }

    private function get_org_teams() {
        $cached_teams = get_transient('pmpro_github_org_teams');
        if ($cached_teams !== false) {
            return $cached_teams;
        }

        $pat = get_option('pmpro_github_pat');
        $org = get_option('pmpro_github_org_name');

        $response = wp_remote_get("https://api.github.com/orgs/{$org}/teams", pmpro_github_http_defaults([
            'headers' => [
                'Authorization' => 'token ' . $pat,
                'Accept' => 'application/vnd.github.v3+json'
            ]
        ]));

        if (is_wp_error($response)) {
            return array();
        }

        $teams = json_decode(wp_remote_retrieve_body($response));
        set_transient('pmpro_github_org_teams', $teams, 600);

        return $teams;
    }

    private function remove_user_from_all_teams($user_id) {
        $github_username = get_user_meta($user_id, '_pmpro_github_username', true);
        if (empty($github_username)) {
            if (PMPRO_GITHUB_VERBOSE) error_log("GH-REMOVEALL ▶ No GitHub username for user {$user_id}");
            return;
        }
        $org = get_option('pmpro_github_org_name');
        $all_org_teams = $this->get_org_teams();
        if (!is_array($all_org_teams)) {
            if (PMPRO_GITHUB_VERBOSE) error_log("GH-REMOVEALL ▶ Failed to fetch org teams for user {$github_username}");
            return;
        }
        foreach ($all_org_teams as $team) {
            if (isset($team->slug)) {
                $remove_response = $this->github_api_request_pat(
                    "https://api.github.com/orgs/{$org}/teams/{$team->slug}/memberships/{$github_username}",
                    array(
                        'method' => 'DELETE',
                        'headers' => array(
                            'Accept' => 'application/vnd.github.v3+json',
                            'User-Agent' => PMPRO_GITHUB_USER_AGENT
                        )
                    ),
                    $user_id
                );
                $remove_status = is_wp_error($remove_response) ? $remove_response->get_error_message() : wp_remote_retrieve_response_code($remove_response);
                if (PMPRO_GITHUB_VERBOSE) error_log("GH-REMOVEALL ▶ Remove from {$team->slug}: {$remove_status}");
            }
        }
    }

}
