<?php

if (!defined('ABSPATH')) exit;

class PMPro_GitHub_Shortcodes {

    public function __construct() {
        add_shortcode('pmpro_github_connect_button', array($this, 'render_github_connect_button'));
    }

    private function get_user_teams($user_id) {
        $cached_teams = get_transient('pmpro_github_user_teams_' . $user_id);
        if ($cached_teams !== false) {
            return $cached_teams;
        }

        $token_encrypted = get_user_meta($user_id, '_pmpro_github_token', true);
        $token = robotwealth_decrypt_value( get_user_meta( $user_id, '_pmpro_github_token', true ) );

        if ( $token === false ) {
            // wipe bad value and show “Reconnect” banner
            delete_user_meta( $user_id, '_pmpro_github_token' );
            update_user_meta( $user_id, '_pmpro_github_reconnect_needed', 1 );
            return [];           // treat as not connected
        }

        $response = wp_remote_get('https://api.github.com/user/teams', pmpro_github_http_defaults([
            'headers' => [
                'Authorization' => 'token ' . $token,
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => PMPRO_GITHUB_USER_AGENT
            ]
        ]));

        if (is_wp_error($response)) {
            return array();
        }

        $teams = json_decode(wp_remote_retrieve_body($response));
        $team_names = wp_list_pluck($teams, 'name');

        set_transient('pmpro_github_user_teams_' . $user_id, $team_names, 300);

        return $team_names;
    }

    public function render_github_connect_button() {
        if (!is_user_logged_in()) return '';

        $user_id = get_current_user_id();
        $reconnect_needed = get_user_meta($user_id, '_pmpro_github_reconnect_needed', true);
        $github_username = get_user_meta($user_id, '_pmpro_github_username', true);

        if ($reconnect_needed) {
            $oauth_url = esc_url(add_query_arg('pmpro_github_oauth', '1', home_url()));
            return '<a href="' . $oauth_url . '" class="button pmpro-gh-alert">' . esc_html__('Reconnect GitHub Account', 'pmpro-github') . '</a>';
        }

        if ($github_username) {
            $teams = $this->get_user_teams($user_id);
            $team_list = !empty($teams) ? implode(', ', array_map('esc_html', $teams)) : esc_html__('No teams found', 'pmpro-github');

            $invite_pending = (int)get_user_meta($user_id, '_pmpro_github_invite_pending', true);
            if ($invite_pending && (time() - $invite_pending) < WEEK_IN_SECONDS) {
                $retry_url = esc_url(add_query_arg('pmpro_github_oauth', '1', home_url()));
                return '<a href="' . $retry_url . '" class="button pmpro-github-banner">' . esc_html__('Invitation Pending - Retry', 'pmpro-github') . '</a>';
            }

            return '<button class="button" disabled>' . esc_html__('Member of: ', 'pmpro-github') . $team_list . '</button>';
        }

        $oauth_url = esc_url(add_query_arg('pmpro_github_oauth', '1', home_url()));
        return '<a href="' . $oauth_url . '" class="button">' . esc_html__('Link your GitHub account', 'pmpro-github') . '</a>';
    }
}

// Clear cached teams transient upon disconnect
add_action('pmpro_github_disconnect_user', function($user_id) {
    delete_transient('pmpro_github_user_teams_' . $user_id);
});
