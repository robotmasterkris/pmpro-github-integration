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
        error_log( "GH-Sync ▶ begin user {$user_id}" );
        $github_username = get_user_meta($user_id, '_pmpro_github_username', true);
        if (empty($github_username)) {
            update_user_meta( $user_id, '_pmpro_github_reconnect_needed', 1 );
            error_log( "GH-Sync ▶ abort – missing github_username for user {$user_id}" );
            return;
        }

        $pat = get_option('pmpro_github_pat');
        $org = get_option('pmpro_github_org_name');

        $user_levels = pmpro_getMembershipLevelsForUser($user_id);
        $mapped_teams = $this->get_mapped_teams($user_levels);

        // Fetch and cache current teams for the user
        $cached_teams = get_transient('pmpro_github_current_teams_' . $user_id);
        if ($cached_teams === false) {
            $response = wp_remote_get("https://api.github.com/orgs/{$org}/members/{$github_username}/teams", pmpro_github_http_defaults());

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $teams_data = json_decode(wp_remote_retrieve_body($response));
                $cached_teams = wp_list_pluck($teams_data, 'slug');
                set_transient('pmpro_github_current_teams_' . $user_id, $cached_teams, 300);
            } else {
                $cached_teams = [];
            }
        }

        $invite_triggered = false;                    

        foreach ( $mapped_teams as $team_slug ) {

            if ( in_array( $team_slug, $cached_teams, true ) ) {
                continue;
            }

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
            $body   = wp_remote_retrieve_body( $response );
            error_log( "GH-PUT ▶ HTTP {$status} payload={$body}" );

            // Any status that means “membership *will* exist soon”.
            if ( in_array( $status, [ 202, 201, 200, 404, 409, 422 ], true ) ) {

                // 200 / 201 ─ success right away
                // 202        ─ accepted, but async on GitHub’s side
                // 404 / 409 / 422 ─ user not in org or validation conflict → invite flow

                if ( in_array( $status, [ 404, 409, 422 ], true ) ) {
                    error_log( 'User not in org – sending invite.' );
                    $this->invite_user_to_org( $github_username, $user_id );
                }

                $invite_triggered = true;               // set flag no matter which one
                continue;                               // try the next team right away
            }

            // Anything else we treat as a hard error.
            if ( is_wp_error( $response ) ) {
                error_log( 'GitHub API error: ' . $response->get_error_message() );
            } else {
                error_log( "GitHub unexpected status {$status}" );
            }
        }

        /* -----------------------------------------------------------
        * If we sent (or reused) an invitation for ANY team, enqueue
        * one follow-up sync x minutes from now.  That follow-up will
        * pick up extra teams once the org membership is active, or
        * simply do nothing if everything’s already in place.
        * ---------------------------------------------------------- */
        if ( $invite_triggered ) {

            $job = as_schedule_single_action(
                time() + 300,                             // 5 min
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
            delete_user_meta($user_id, '_pmpro_github_token');
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
            error_log( 'GH-API ▶ network error: ' . $response->get_error_message() );
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
            error_log( sprintf( 'GH-API ▶ %s %s → HTTP %d – %s', $args['method'], $url, $code, $body ) );

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
            error_log( "GH-Invite ▶ abort – missing _pmpro_github_uid for user {$user_id}" );
            return;
        }

        error_log(
            sprintf( 'GH-Invite ▶ POST start – uid=%d pat=%s', $uid, $pat ? 'set' : 'EMPTY' )
        );

        $invite_response = wp_remote_post("https://api.github.com/orgs/{$org}/invitations", array_merge(pmpro_github_http_defaults(), [
            'headers' => [
                'Authorization' => 'token ' . $pat,
                'Accept' => 'application/vnd.github.v3+json'
            ],
            'body'    => wp_json_encode( [ 'invitee_id' => $uid ] ),
        ]));

        if ( is_wp_error( $invite_response ) ) {
            error_log( 'GH-Invite ▶ WP_Error: ' . $invite_response->get_error_message() );
            return;
        }

        $invite_code = wp_remote_retrieve_response_code($invite_response);
        error_log( "GH-Invite ▶ HTTP {$invite_code}" );

        if ( $invite_code >= 200 && $invite_code < 300 || $invite_code === 422) {
            update_user_meta($user_id, '_pmpro_github_invite_sent', time());
            $job = as_enqueue_async_action('pmpro_github_accept_invite',  [ 'user_id' => $user_id ] );
            error_log( is_wp_error( $job )  ? 'GH-Enqueue ▶ ' . $job->get_error_message()
                                            : "GH-Enqueue ▶ job {$job} queued" );
        } else {
            update_user_meta($user_id, '_pmpro_github_invite_pending', time());
            error_log( "GH-Invite ▶ failed – unexpected HTTP {$code}" );
        }
    }

    public function accept_invite($user_id) {
        error_log( "GH-Accept ▶ fired for user {$user_id}" );
        $org = get_option(strtolower('pmpro_github_org_name'));
        $response = $this->github_api_request("https://api.github.com/user/memberships/orgs/{$org}", array(
            'method' => 'PATCH',
            'headers' => array('User-Agent' => PMPRO_GITHUB_USER_AGENT),
            'body' => json_encode(array('state' => 'active'))
        ), $user_id);

        $response_code = wp_remote_retrieve_response_code($response);

        error_log( 'GH-Accept ▶ HTTP ' . wp_remote_retrieve_response_code( $resp ) .
               ' – body: ' . wp_remote_retrieve_body( $resp ) );

        if ($response_code === 403 || $response_code === 404) {
            $tries = (int)get_user_meta($user_id, '_pmpro_github_accept_tries', true);
            if ($tries < 3) {
                update_user_meta($user_id, '_pmpro_github_accept_tries', $tries + 1);
                $delay = pow(2, $tries);
                as_schedule_single_action(time() + $delay, 'pmpro_github_accept_invite', array('user_id' => $user_id));
            } else {
                update_user_meta($user_id, '_pmpro_github_invite_pending', time());
                error_log('GitHub invite pending after multiple retries.');
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

}
