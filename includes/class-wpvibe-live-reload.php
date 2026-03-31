<?php
/**
 * Enqueues the live reload polling script for logged-in users.
 *
 * Frontend pages: auto-reload when WPVibe makes changes.
 * wp-admin pages: toast notification with action button.
 * Permissions are enforced by the REST endpoint, not the script.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Live_Reload {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		// Fallback: error pages (e.g., "not allowed") don't fire admin_footer.
		// Inject a minimal inline polling script in admin_head so the user isn't stuck.
		add_action( 'admin_head', array( $this, 'inject_head_fallback' ), 9998 );
	}

	/**
	 * Check if the current user should get the live reload script.
	 *
	 * @return bool
	 */
	private function should_load() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// Only inject when WPVibe has been active recently (transient exists).
		$changes = get_transient( WPVibe_Change_Tracker::TRANSIENT_KEY );
		if ( empty( $changes ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Enqueue live reload script and styles.
	 */
	public function enqueue_scripts() {
		if ( ! $this->should_load() ) {
			return;
		}

		wp_enqueue_style(
			'wpvibe-live-reload',
			WPVIBE_PLUGIN_URL . 'assets/css/live-reload.css',
			array(),
			WPVIBE_VERSION
		);

		wp_enqueue_script(
			'wpvibe-live-reload',
			WPVIBE_PLUGIN_URL . 'assets/js/live-reload.js',
			array(),
			WPVIBE_VERSION,
			true // Load in footer.
		);

		// Detect current post ID for same-post auto-refresh in admin.
		$post_id = 0;
		if ( is_admin() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL param, not processing form.
			$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		}

		wp_localize_script( 'wpvibe-live-reload', 'wpvibeLiveReload', array(
			'endpoint' => esc_url( rest_url( 'wpvibe/v1/last-change' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'isAdmin'  => is_admin() ? '1' : '',
			'postId'   => (string) $post_id,
			'userId'   => (string) get_current_user_id(),
		) );
	}

	/**
	 * Minimal fallback for admin pages that don't fire admin_footer (e.g., error pages).
	 * Only handles navigation so the user isn't stuck on an error page.
	 */
	public function inject_head_fallback() {
		if ( ! $this->should_load() ) {
			return;
		}

		$rest_url   = rest_url( 'wpvibe/v1/last-change' );
		$nonce      = wp_create_nonce( 'wp_rest' );
		$current_id = get_current_user_id();
		?>
		<script id="wpvibe-live-reload-fallback">
		document.addEventListener('DOMContentLoaded', function() {
			if (window.__wpvibe_live_reload) return;

			var endpoint = <?php echo wp_json_encode( esc_url( $rest_url ) ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			var userId = <?php echo (int) $current_id; ?>;
			var lastTs = 0;

			setInterval(function() {
				fetch(endpoint, { headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' })
				.then(function(r) { return r.ok ? r.json() : null; })
				.then(function(data) {
					if (!data || !data.timestamp) return;
					if (lastTs === 0) { lastTs = data.timestamp; return; }
					if (data.timestamp <= lastTs) return;
					lastTs = data.timestamp;

					var action = data.action || {};
					var isMyChange = userId && data.user_id && userId === data.user_id;
					if (!isMyChange) return;
					var url = action.admin_url || action.url || '';
					if (url) {
						window.location.href = url;
					} else {
						location.reload();
					}
				})
				.catch(function() {});
			}, 3000);
		});
		</script>
		<?php
	}
}
