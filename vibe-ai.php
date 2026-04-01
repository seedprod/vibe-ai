<?php
/**
 * Plugin Name: Vibe AI – Connect Your Site to Claude, ChatGPT & AI Assistants
 * Description: Connect any AI assistant to your WordPress site. Manage content, edit themes, and automate site tasks with Claude, ChatGPT, Cursor & more via MCP.
 * Version: 1.2.0
 * Author: SeedProd
 * Author URI: https://wpvibe.ai
 * License: GPL-2.0-or-later
 * Text Domain: vibe-ai
 * Domain Path: /languages/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'WPVIBE_VERSION', '1.2.0' );
define( 'WPVIBE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPVIBE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Core includes.
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-rest.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-file-ops.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-draft-theme.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-preview.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-cli.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-change-tracker.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-live-reload.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-classic-theme.php';
require_once WPVIBE_PLUGIN_DIR . 'includes/class-wpvibe-admin.php';

/**
 * Bootstrap the plugin.
 */
function wpvibe_init() {
	WPVibe_REST::instance();
	WPVibe_Preview::instance();
	WPVibe_Live_Reload::instance();
	if ( is_admin() ) {
		WPVibe_Admin::instance();
	}
}
add_action( 'plugins_loaded', 'wpvibe_init' );
