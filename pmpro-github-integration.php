<?php
/**
 * Plugin Name: PMPro GitHub Integration
 * Description: Integrates Paid Memberships Pro (PMPro) with GitHub Organizations, automating GitHub org invites and team assignments based on membership levels.
 * Version: 1.1.0
 * Author: Kris Longmore
 * License: GPL2
 * Text Domain: pmpro-github-integration
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}
 
// Define plugin constants
define('PMPRO_GITHUB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PMPRO_GITHUB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PMPRO_GITHUB_USER_AGENT', 'RobotWealth-PMProGitHub/1.0');

// Add github.com to allowed redirect hosts
// This is necessary for the OAuth callback URL to work correctly
add_filter( 'allowed_redirect_hosts', function ( $hosts ) {
	$hosts[] = 'github.com';
	return $hosts;
} );


// Load Action Scheduler
if ( ! class_exists( 'ActionScheduler' ) ) {
    require_once PMPRO_GITHUB_PLUGIN_DIR . 'libraries/action-scheduler/action-scheduler.php';
}

// Include required files
require_once PMPRO_GITHUB_PLUGIN_DIR . 'includes/class-oauth-handler.php';
require_once PMPRO_GITHUB_PLUGIN_DIR . 'includes/class-sync-manager.php';
require_once PMPRO_GITHUB_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once PMPRO_GITHUB_PLUGIN_DIR . 'includes/class-shortcodes.php';

// Centralize user agent and timeout settings
function pmpro_github_http_defaults($args = []) {
    $args['headers']['User-Agent'] = PMPRO_GITHUB_USER_AGENT;
    $args['timeout'] = 15;
    return $args;
}

// REST protection for GitHub tokens and usernames
register_meta('user', '_pmpro_github_token', ['show_in_rest' => false, 'single' => true]);
register_meta('user', '_pmpro_github_username', ['show_in_rest' => false, 'single' => true]);

// Initialize classes
function pmpro_github_init() {
    new PMPro_GitHub_OAuth_Handler();
    new PMPro_GitHub_Sync_Manager();
    new PMPro_GitHub_Admin_Settings();
    new PMPro_GitHub_Shortcodes();
}
add_action('plugins_loaded', 'pmpro_github_init');
