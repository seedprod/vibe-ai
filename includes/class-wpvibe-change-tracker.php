<?php
/**
 * Tracks recent changes made via WPVibe MCP for live reload.
 *
 * Stores a ring buffer of changes in a single transient — auto-expiring,
 * object-cache friendly, no schema changes. Each polling client passes
 * ?since=<timestamp> and receives only the changes it missed.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Change_Tracker {

	const TRANSIENT_KEY = 'wpvibe_last_change';
	const TTL           = 600; // 10 minutes
	const MAX_ENTRIES   = 50;

	/**
	 * Record a change.
	 *
	 * @param array $args {
	 *     @type string $summary      Human-readable summary (e.g., "Post updated: Hello World").
	 *     @type string $action_label Button text (e.g., "View Post", "Preview Theme", "Refresh").
	 *     @type string $url          Frontend URL to navigate to (permalink, preview, etc.).
	 *     @type string $admin_url    Admin URL (editor link). Falls back to $url if empty.
	 *     @type int    $post_id      Affected post ID (for same-post auto-refresh in admin).
	 *     @type bool   $force        Navigate immediately — no toast.
	 * }
	 */
	public static function mark( $args = array() ) {
		$defaults = array(
			'summary'      => '',
			'action_label' => 'Refresh',
			'url'          => '',
			'admin_url'    => '',
			'post_id'      => 0,
			'force'        => false,
		);

		$args = wp_parse_args( $args, $defaults );

		$entry = array(
			'timestamp' => microtime( true ),
			'user_id'   => get_current_user_id(),
			'summary'   => sanitize_text_field( $args['summary'] ),
			'post_id'   => absint( $args['post_id'] ),
			'action'    => array(
				'label'     => sanitize_text_field( $args['action_label'] ),
				'url'       => esc_url_raw( $args['url'] ),
				'admin_url' => esc_url_raw( $args['admin_url'] ),
				'force'     => (bool) $args['force'],
			),
		);

		$changes = get_transient( self::TRANSIENT_KEY );

		// Handle legacy single-entry transient from before the ring buffer upgrade.
		if ( is_array( $changes ) && isset( $changes['timestamp'] ) ) {
			$changes = array( $changes );
		} elseif ( ! is_array( $changes ) ) {
			$changes = array();
		}

		$changes[] = $entry;

		// Prune entries older than TTL and cap at MAX_ENTRIES.
		$cutoff  = microtime( true ) - self::TTL;
		$changes = array_values( array_filter( $changes, function ( $c ) use ( $cutoff ) {
			return isset( $c['timestamp'] ) && $c['timestamp'] > $cutoff;
		} ) );
		if ( count( $changes ) > self::MAX_ENTRIES ) {
			$changes = array_slice( $changes, -self::MAX_ENTRIES );
		}

		set_transient( self::TRANSIENT_KEY, $changes, self::TTL );
	}

	/**
	 * Get the last recorded change (backward compat).
	 *
	 * @return array Single change entry in the original format.
	 */
	public static function get() {
		$changes = get_transient( self::TRANSIENT_KEY );

		if ( ! is_array( $changes ) || empty( $changes ) ) {
			return self::empty_response();
		}

		// Handle legacy single-entry transient.
		if ( isset( $changes['timestamp'] ) ) {
			return self::enrich( $changes );
		}

		$latest = end( $changes );
		return self::enrich( $latest );
	}

	/**
	 * Get all changes since a given timestamp.
	 *
	 * @param float $since_timestamp Microtime threshold — returns entries strictly newer.
	 * @return array List of change entries.
	 */
	public static function get_since( $since_timestamp = 0 ) {
		$changes = get_transient( self::TRANSIENT_KEY );

		if ( ! is_array( $changes ) || empty( $changes ) ) {
			return array();
		}

		// Handle legacy single-entry transient.
		if ( isset( $changes['timestamp'] ) ) {
			$changes = array( $changes );
		}

		$result = array();
		foreach ( $changes as $entry ) {
			if ( isset( $entry['timestamp'] ) && $entry['timestamp'] > (float) $since_timestamp ) {
				$result[] = self::enrich( $entry );
			}
		}
		return $result;
	}

	/**
	 * Enrich a change entry with preview URL if applicable.
	 */
	private static function enrich( $data ) {
		if ( empty( $data['action']['url'] ) ) {
			$token = get_option( 'wpvibe_preview_token' );
			if ( $token && ! empty( $data['action']['label'] ) && strpos( $data['action']['label'], 'Theme' ) !== false ) {
				$preview_url = add_query_arg( 'wpvibe_preview', $token, home_url( '/' ) );
				$data['action']['url']       = $preview_url;
				$data['action']['admin_url'] = $preview_url;
			}
		}
		return $data;
	}

	/**
	 * Empty response for when no changes exist.
	 */
	private static function empty_response() {
		return array(
			'timestamp' => 0,
			'summary'   => '',
			'post_id'   => 0,
			'action'    => array(
				'label'     => '',
				'url'       => '',
				'admin_url' => '',
				'force'     => false,
			),
		);
	}
}
