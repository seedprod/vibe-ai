<?php
/**
 * Uninstall WPVibe Connect.
 *
 * Removes all plugin options and transients on uninstall.
 *
 * @package WPVibe_Connect
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin options.
delete_option( 'wpvibe_draft_theme' );
delete_option( 'wpvibe_draft_source' );
delete_option( 'wpvibe_preview_token' );

// Remove transients.
delete_transient( 'wpvibe_last_change' );
