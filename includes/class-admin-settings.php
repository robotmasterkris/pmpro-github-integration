<?php

if (!defined('ABSPATH')) exit;

class PMPro_GitHub_Admin_Settings {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function add_admin_menu() {
        add_submenu_page('pmpro-dashboard', 'GitHub Integration', 'GitHub Integration', 'manage_options', 'pmpro-github-integration', array($this, 'settings_page'));
    }

    public function register_settings() {
        register_setting('pmpro_github_settings', 'pmpro_github_pat');
        register_setting('pmpro_github_settings', 'pmpro_github_client_id');
        register_setting('pmpro_github_settings', 'pmpro_github_client_secret');
        register_setting('pmpro_github_settings', 'pmpro_github_team_mappings');
        register_setting('pmpro_github_settings', 'pmpro_github_button_text_connected');
        register_setting('pmpro_github_settings', 'pmpro_github_button_color_connected');
        register_setting('pmpro_github_settings', 'pmpro_github_button_text_not_connected');
        register_setting('pmpro_github_settings', 'pmpro_github_button_color_not_connected');
        register_setting('pmpro_github_settings', 'pmpro_github_org_name');
    }

    private function fetch_github_teams() {
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
        if (empty($teams)) {
            return array();
        }

        $team_slugs = array();
        foreach ($teams as $team) {
            $team_slugs[$team->slug] = $team->name;
        }

        return $team_slugs;
    }

    public function settings_page() {
        // Ensure jQuery and Select2 are loaded for multi-select dropdown
        wp_enqueue_script('jquery');
        wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), '4.0.13', true);
        wp_enqueue_style('select2-css', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css', array(), '4.0.13');
        ?>
        <div class="wrap">
            <h1>PMPro GitHub Integration Settings</h1>
            <h2 class="nav-tab-wrapper">
                <a href="#general" class="nav-tab nav-tab-active">General Settings</a>
                <a href="#team-mapping" class="nav-tab">Team Mapping</a>
                <a href="#linked-users" class="nav-tab">GitHub Linked Users</a>
            </h2>

            <!-- Only wrap General and Team Mapping in the main form -->
            <form method="post" action="options.php">
                <?php
                settings_fields('pmpro_github_settings');
                do_settings_sections('pmpro_github_settings');
                ?>

                <div id="general" class="tab-content">
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">GitHub Personal Access Token (PAT)</th>
                            <td><input type="text" name="pmpro_github_pat" value="<?php echo esc_attr(get_option('pmpro_github_pat')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">OAuth Client ID</th>
                            <td><input type="text" name="pmpro_github_client_id" value="<?php echo esc_attr(get_option('pmpro_github_client_id')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">OAuth Client Secret</th>
                            <td><input type="text" name="pmpro_github_client_secret" value="<?php echo esc_attr(get_option('pmpro_github_client_secret')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">GitHub Organization Name</th>
                            <td><input type="text" name="pmpro_github_org_name" value="<?php echo esc_attr(get_option('pmpro_github_org_name')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Button Text (Connected)</th>
                            <td><input type="text" name="pmpro_github_button_text_connected" value="<?php echo esc_attr(get_option('pmpro_github_button_text_connected', 'GitHub account connected')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Button Color (Connected)</th>
                            <td><input type="color" name="pmpro_github_button_color_connected" value="<?php echo esc_attr(get_option('pmpro_github_button_color_connected', '#28a745')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Button Text (Not Connected)</th>
                            <td><input type="text" name="pmpro_github_button_text_not_connected" value="<?php echo esc_attr(get_option('pmpro_github_button_text_not_connected', 'Link your GitHub account')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Button Color (Not Connected)</th>
                            <td><input type="color" name="pmpro_github_button_color_not_connected" value="<?php echo esc_attr(get_option('pmpro_github_button_color_not_connected', '#0366d6')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Test GitHub API Connection</th>
                            <td><button type="button" class="button" onclick="testGitHubAPIConnection()">Test API Connection</button></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Test OAuth Configuration</th>
                            <td><button type="button" class="button" onclick="testOAuthConfiguration()">Test OAuth</button></td>
                        </tr>
                    </table>
                </div>

                <div id="team-mapping" class="tab-content" style="display:none;">
                    <h3>Membership Level to GitHub Team Mapping</h3>
                    <?php
                    $levels = pmpro_getAllLevels();
                    $team_mappings = get_option('pmpro_github_team_mappings', array());
                    $github_teams = $this->fetch_github_teams();

                    foreach ($levels as $level) {
                        $mapped_teams = isset($team_mappings[$level->id]) ? $team_mappings[$level->id] : array();
                        echo '<p><strong>' . esc_html($level->name) . '</strong><br/>';
                        echo '<select multiple name="pmpro_github_team_mappings[' . esc_attr($level->id) . '][]" style="width:100%;">';
                        foreach ($github_teams as $slug => $name) {
                            $selected = in_array($slug, $mapped_teams) ? 'selected' : '';
                            echo '<option value="' . esc_attr($slug) . '" ' . $selected . '>' . esc_html($name) . '</option>';
                        }
                        echo '</select></p>';
                    }
                    ?>
                </div>

                <?php submit_button(); ?>
            </form>

            <!-- Linked Users tab is outside the main form -->
            <div id="linked-users" class="tab-content" style="display:none;">
                <h3>GitHub Linked Users</h3>
                <form method="post" action="">
                    <input type="hidden" name="pmpro_github_bulk_sync" value="1" />
                    <button type="submit" class="button button-primary" id="pmpro-github-bulk-sync-btn" style="margin-bottom: 16px;">Bulk Sync All Members</button>
                    <button type="button" class="button" id="pmpro-github-cancel-sync-btn" style="margin-bottom: 16px; margin-left: 8px;" disabled>Cancel Sync</button>
                </form>
                <div id="pmpro-github-bulk-sync-msg" style="margin-bottom: 16px; display:none;"></div>
                <table class="widefat fixed">
                    <thead>
                        <tr>
                            <th>WordPress Username</th>
                            <th>GitHub Username</th>
                            <th>PMPro Level(s)</th>
                            <th>Current GitHub Team(s)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $users = get_users(array('meta_key' => '_pmpro_github_username'));
                        // Update Disconnect button with confirmation prompt
                        foreach ($users as $user) {
                            $github_username = get_user_meta($user->ID, '_pmpro_github_username', true);
                            $levels = pmpro_getMembershipLevelsForUser($user->ID);
                            $level_names = wp_list_pluck($levels, 'name');
                            echo '<tr>';
                            echo '<td>' . esc_html($user->user_login) . '</td>';
                            echo '<td>' . esc_html($github_username) . '</td>';
                            echo '<td>' . esc_html(implode(', ', $level_names)) . '</td>';
                            echo '<td>...</td>'; // Placeholder for current GitHub teams
                            echo '<td>
                                <a href="' . admin_url('admin-post.php?action=pmpro_github_manual_sync&user_id=' . $user->ID) . '" class="button">Re-sync</a>
                                <a href="' . admin_url('admin-post.php?action=pmpro_github_manual_disconnect&user_id=' . $user->ID) . '" class="button" style="background-color:#dc3545;color:#fff;" onclick="return confirm(\'Are you sure you want to disconnect this user from GitHub?\');">Disconnect</a>
                            </td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                $('.nav-tab').click(function(e) {
                    e.preventDefault();
                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                    $('.tab-content').hide();
                    $($(this).attr('href')).show();
                });
                $('select[multiple]').select2();

                // Bulk sync/cancel logic
                function checkBulkSyncStatus() {
                    $.post(ajaxurl, { action: 'pmpro_github_bulk_sync_status' }, function(response) {
                        if (response.success && response.data.in_progress) {
                            $('#pmpro-github-cancel-sync-btn').prop('disabled', false);
                        } else {
                            $('#pmpro-github-cancel-sync-btn').prop('disabled', true);
                        }
                    });
                }
                checkBulkSyncStatus();
                setInterval(checkBulkSyncStatus, 10000); // poll every 10s

                // Show message after bulk sync starts
                $('form').on('submit', function(e) {
                    if ($(this).find('input[name="pmpro_github_bulk_sync"]').length) {
                        $('#pmpro-github-bulk-sync-msg').html('Bulk sync has started. You can safely navigate away from this page; jobs will continue to execute in the background.').show();
                    }
                });

                // Cancel sync button
                $('#pmpro-github-cancel-sync-btn').on('click', function() {
                    if (confirm('Are you sure you want to cancel all pending bulk sync jobs?')) {
                        $.post(ajaxurl, { action: 'pmpro_github_cancel_bulk_sync' }, function(response) {
                            if (response.success) {
                                alert('Pending bulk sync jobs have been cancelled.');
                                $('#pmpro-github-cancel-sync-btn').prop('disabled', true);
                            } else {
                                alert('Failed to cancel jobs: ' + response.data);
                            }
                        });
                    }
                });
            });

            function testGitHubAPIConnection() {
                jQuery.post(ajaxurl, { action: 'test_github_api_connection' }, function(response) {
                    alert(response.success ? response.data : response.data);
                });
            }

            function testOAuthConfiguration() {
                jQuery.post(ajaxurl, { action: 'test_oauth_configuration' }, function(response) {
                    alert(response.success ? response.data : response.data);
                });
            }
        </script>
        <?php
    }

}

// Site-wide admin notices for critical errors
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;

    $pat = get_option('pmpro_github_pat');
    if (empty($pat)) {
        echo '<div class="notice notice-error"><p>GitHub PAT is missing or invalid. Please update your settings.</p></div>';
    }

    // Check for Action Scheduler job failures
    $failed_jobs = get_option('pmpro_github_failed_jobs', 0);
    if ($failed_jobs >= 10) {
        echo '<div class="notice notice-error"><p>GitHub sync jobs have failed repeatedly. Please check your logs.</p></div>';
    }
});

// Per-user dashboard banner
add_action('wp_footer', function() {
    if (!is_user_logged_in()) return;

    $user_id = get_current_user_id();
    $invite_pending = get_user_meta($user_id, '_pmpro_github_invite_pending', true);

    if ($invite_pending && (time() - $invite_pending) < 604800) { // 7 days
        echo '<div class="pmpro-github-banner">Invitation pending – check your GitHub notifications or <a href="?pmpro_github_oauth=1">click to retry</a>.</div>';
    }

    $token = get_user_meta($user_id, '_pmpro_github_token', true);
    if (empty($token)) {
        echo '<div class="pmpro-github-banner">Your GitHub connection has been revoked. Please <a href="?pmpro_github_oauth=1">reconnect</a>.</div>';
    }
});

// AJAX handlers for testing GitHub API and OAuth connectivity
add_action('wp_ajax_test_github_api_connection', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $pat = get_option('pmpro_github_pat');
    $org = get_option('pmpro_github_org_name');

    $response = wp_remote_get("https://api.github.com/orgs/{$org}", pmpro_github_http_defaults([
        'headers' => [
            'Authorization' => 'token ' . $pat,
            'Accept' => 'application/vnd.github.v3+json'
        ]
    ]));

    if (is_wp_error($response)) {
        wp_send_json_error('Connection error: ' . $response->get_error_message());
    }

    $status_code = wp_remote_retrieve_response_code($response);

    if ($status_code === 200) {
        wp_send_json_success('GitHub API connection successful and PAT has correct permissions for the organization.');
    } elseif ($status_code === 404) {
        wp_send_json_error('Organization not found or PAT lacks access to the organization.');
    } elseif ($status_code === 403) {
        wp_send_json_error('PAT lacks necessary permissions.');
    } else {
        wp_send_json_error('Unexpected error occurred. HTTP Status Code: ' . $status_code);
    }
});

add_action('wp_ajax_test_oauth_configuration', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $client_id = get_option('pmpro_github_client_id');
    $client_secret = get_option('pmpro_github_client_secret');

    if (empty($client_id) || empty($client_secret)) {
        wp_send_json_error('OAuth credentials are not configured correctly.');
    }

    wp_send_json_success('OAuth configuration appears correct.');
});

add_action('admin_post_pmpro_github_manual_sync', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $user_id = intval($_GET['user_id']);
    if ($user_id) {
        $result = as_enqueue_async_action('pmpro_github_sync_user', [ $user_id ]);
        if (is_wp_error($result)) {
            $failed_jobs = (int)get_option('pmpro_github_failed_jobs', 0);
            update_option('pmpro_github_failed_jobs', $failed_jobs + 1);
        }
    }

    wp_redirect(wp_get_referer());
    exit;
});

// Update manual disconnect action to redirect back to GitHub Linked Users tab
add_action('admin_post_pmpro_github_manual_disconnect', function() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $user_id = intval($_GET['user_id']);
    if ($user_id) {
        as_enqueue_async_action('pmpro_github_disconnect_user', [ $user_id ]);
    }

    wp_redirect(admin_url('admin.php?page=pmpro-github-integration#linked-users'));
    exit;
});

// Handle bulk sync POST from Linked Users tab
add_action('admin_init', function() {
    if (
        isset($_POST['pmpro_github_bulk_sync']) &&
        current_user_can('manage_options')
    ) {
        do_action('pmpro_github_batch_sync');
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>Bulk GitHub sync has been started. This may take a few minutes depending on the number of users.</p></div>';
        });
        // Prevent resubmission on refresh
        wp_redirect(add_query_arg('pmpro_github_bulk_sync_started', '1', wp_get_referer()));
        exit;
    }
});

// AJAX handler to check if bulk sync is in progress
add_action('wp_ajax_pmpro_github_bulk_sync_status', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    // Check for pending pmpro_github_bulk or pmpro_github_sync_user jobs
    if (function_exists('as_get_scheduled_actions')) {
        $pending = as_get_scheduled_actions([
            'hook' => ['pmpro_github_bulk', 'pmpro_github_sync_user'],
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 1
        ]);
        $in_progress = !empty($pending);
        wp_send_json_success(['in_progress' => $in_progress]);
    } else {
        wp_send_json_success(['in_progress' => false]);
    }
});

// AJAX handler to cancel all pending bulk sync jobs
add_action('wp_ajax_pmpro_github_cancel_bulk_sync', function() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    if (function_exists('as_unschedule_all_actions')) {
        // Remove all pending pmpro_github_bulk and pmpro_github_sync_user jobs
        as_unschedule_all_actions('pmpro_github_bulk');
        as_unschedule_all_actions('pmpro_github_sync_user');
        wp_send_json_success();
    } else {
        wp_send_json_error('Action Scheduler not available');
    }
});
