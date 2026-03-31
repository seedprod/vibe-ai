<?php
/**
 * Draft theme preview — swaps the active theme for requests
 * that include a valid wpvibe_preview token.
 *
 * Also injects:
 * - A preview banner so the user knows they're viewing the draft.
 * - JavaScript to rewrite local links so navigation stays in preview mode.
 */

defined( 'ABSPATH' ) || exit;

class WPVibe_Preview {

	private static $instance = null;
	private $preview_token   = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'template', array( $this, 'swap_template' ) );
		add_filter( 'stylesheet', array( $this, 'swap_stylesheet' ) );

		// Inject banner and link rewriting only on preview requests.
		if ( $this->get_preview_slug() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Preview token verified via hash_equals in get_preview_slug().
			$this->preview_token = isset( $_GET['wpvibe_preview'] ) ? sanitize_text_field( wp_unslash( $_GET['wpvibe_preview'] ) ) : '';
			add_action( 'wp_footer', array( $this, 'inject_preview_ui' ), 9999 );
			add_action( 'wp_head', array( $this, 'inject_preview_styles' ), 9999 );
		}
	}

	/**
	 * Check if the current request is a valid preview.
	 *
	 * @return string|false Draft theme slug or false.
	 */
	private function get_preview_slug() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		if ( ! isset( $_GET['wpvibe_preview'] ) || empty( $_GET['wpvibe_preview'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preview token verified via hash_equals, not nonce.
		$input = sanitize_text_field( wp_unslash( $_GET['wpvibe_preview'] ) );
		$token = get_option( 'wpvibe_preview_token' );
		if ( ! $token || ! hash_equals( $token, $input ) ) {
			return false;
		}

		$draft_slug = get_option( 'wpvibe_draft_theme' );
		if ( ! $draft_slug || ! is_dir( get_theme_root() . '/' . $draft_slug ) ) {
			return false;
		}

		return $draft_slug;
	}

	public function swap_template( $template ) {
		$slug = $this->get_preview_slug();
		return $slug ? $slug : $template;
	}

	public function swap_stylesheet( $stylesheet ) {
		$slug = $this->get_preview_slug();
		return $slug ? $slug : $stylesheet;
	}

	/**
	 * Inject preview banner styles into <head>.
	 */
	public function inject_preview_styles() {
		?>
		<style id="wpvibe-preview-styles">
			#wpvibe-preview-banner {
				position: fixed;
				bottom: 0;
				left: 0;
				right: 0;
				z-index: 999999;
				background: linear-gradient(135deg, #1e293b, #334155);
				color: #f8fafc;
				font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
				font-size: 13px;
				padding: 10px 20px;
				display: flex;
				align-items: center;
				justify-content: space-between;
				box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.15);
				gap: 12px;
			}
			#wpvibe-preview-banner .wpvibe-badge {
				display: inline-flex;
				align-items: center;
				gap: 8px;
				font-weight: 600;
			}
			#wpvibe-preview-banner .wpvibe-dot {
				width: 8px;
				height: 8px;
				background: #22c55e;
				border-radius: 50%;
				animation: wpvibe-pulse 2s infinite;
			}
			#wpvibe-preview-banner .wpvibe-info {
				color: #94a3b8;
			}
			#wpvibe-preview-banner .wpvibe-btn {
				display: inline-block;
				padding: 6px 14px;
				border-radius: 6px;
				font-size: 12px;
				font-weight: 500;
				text-decoration: none;
				cursor: pointer;
				border: none;
				transition: all 0.15s ease;
			}
			#wpvibe-preview-banner .wpvibe-btn-live {
				background: transparent;
				color: #94a3b8;
				border: 1px solid #475569;
			}
			#wpvibe-preview-banner .wpvibe-btn-live:hover {
				background: #475569;
				color: #f8fafc;
			}
			@keyframes wpvibe-pulse {
				0%, 100% { opacity: 1; }
				50% { opacity: 0.4; }
			}
			/* Push page content up so banner doesn't overlap */
			body.wpvibe-preview-active {
				padding-bottom: 50px !important;
			}
		</style>
		<?php
	}

	/**
	 * Inject preview banner and link-rewriting JS into the footer.
	 */
	public function inject_preview_ui() {
		$token    = esc_attr( $this->preview_token );
		$live_url = esc_url( home_url( '/' ) );
		$draft    = esc_html( get_option( 'wpvibe_draft_theme', '' ) );
		?>
		<!-- WPVibe Draft Preview Banner -->
		<div id="wpvibe-preview-banner">
			<span class="wpvibe-badge">
				<span class="wpvibe-dot"></span>
				<?php esc_html_e( 'WPVibe Draft Preview', 'vibe-ai' ); ?>
			</span>
			<span class="wpvibe-info">
				<?php esc_html_e( 'Changes are only visible to you. The live site is unaffected.', 'vibe-ai' ); ?>
			</span>
			<a href="<?php echo esc_url( $live_url ); ?>" class="wpvibe-btn wpvibe-btn-live"><?php esc_html_e( 'View Live Site', 'vibe-ai' ); ?></a>
		</div>

		<script>
		(function() {
			// Add body class for padding.
			document.body.classList.add('wpvibe-preview-active');

			// Rewrite all local links to include the preview token.
			var token = <?php echo wp_json_encode( $this->preview_token ); ?>;
			var param = 'wpvibe_preview';
			var origin = location.origin;

			function addTokenToUrl(href) {
				if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) {
					return href;
				}
				try {
					var url = new URL(href, origin);
					// Only rewrite same-origin links.
					if (url.origin !== origin) return href;
					// Don't double-add.
					if (url.searchParams.has(param)) return href;
					url.searchParams.set(param, token);
					return url.toString();
				} catch(e) {
					return href;
				}
			}

			// Rewrite existing links.
			document.querySelectorAll('a[href]').forEach(function(a) {
				a.href = addTokenToUrl(a.getAttribute('href'));
			});

			// Rewrite form actions.
			document.querySelectorAll('form[action]').forEach(function(f) {
				f.action = addTokenToUrl(f.getAttribute('action'));
			});

			// Observe dynamically added links (e.g., AJAX navigation).
			var observer = new MutationObserver(function(mutations) {
				mutations.forEach(function(m) {
					m.addedNodes.forEach(function(node) {
						if (node.nodeType !== 1) return;
						if (node.tagName === 'A' && node.href) {
							node.href = addTokenToUrl(node.getAttribute('href'));
						}
						var links = node.querySelectorAll ? node.querySelectorAll('a[href]') : [];
						links.forEach(function(a) {
							a.href = addTokenToUrl(a.getAttribute('href'));
						});
					});
				});
			});
			observer.observe(document.body, { childList: true, subtree: true });
		})();
		</script>
		<?php
	}
}
