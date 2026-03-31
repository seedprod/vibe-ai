<?php
/**
 * Sandboxed file operations for WPVibe Connect.
 *
 * All operations are scoped to the active draft theme directory.
 * Path traversal is blocked. Only allowed extensions can be written.
 */

defined( 'ABSPATH' ) || exit;

/*
 * File system note: This plugin uses PHP's native file functions rather than
 * WP_Filesystem. WP_Filesystem is designed for admin-UI plugins needing FTP/SSH
 * fallback. This plugin operates exclusively via REST API with application
 * password auth — no admin UI context for credential prompts.
 *
 * Security is enforced via path sandboxing (realpath validation), extension
 * allowlist, PHP syntax validation, WordPress capability checks, and
 * DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS respect.
 */
class WPVibe_File_Ops {

	/** Allowed file extensions for write/create operations. */
	const ALLOWED_EXTENSIONS = array( 'php', 'css', 'js', 'json', 'html', 'txt', 'svg' );

	/**
	 * Get the draft theme directory path.
	 *
	 * @return string|WP_Error
	 */
	private function get_draft_dir() {
		$draft_slug = get_option( 'wpvibe_draft_theme' );
		if ( ! $draft_slug ) {
			return new WP_Error( 'no_draft', __( 'No draft theme active. Create one first with create_draft_theme.', 'vibe-ai' ), array( 'status' => 400 ) );
		}

		$dir = get_theme_root() . '/' . $draft_slug;
		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'draft_missing', __( 'Draft theme directory not found.', 'vibe-ai' ), array( 'status' => 404 ) );
		}

		return $dir;
	}

	/**
	 * Resolve and validate a file path within the draft theme.
	 *
	 * @param string $relative_path Relative path within the theme.
	 * @return string|WP_Error Absolute path or error.
	 */
	private function resolve_path( $relative_path ) {
		$draft_dir = $this->get_draft_dir();
		if ( is_wp_error( $draft_dir ) ) {
			return $draft_dir;
		}

		// Block path traversal.
		if ( strpos( $relative_path, '..' ) !== false ) {
			return new WP_Error( 'path_traversal', __( 'Path traversal is not allowed.', 'vibe-ai' ), array( 'status' => 403 ) );
		}

		$full_path = realpath( $draft_dir ) . '/' . ltrim( $relative_path, '/' );

		// After resolving, verify it's still within the draft dir.
		$real = realpath( dirname( $full_path ) );
		if ( false === $real || strpos( $real, realpath( $draft_dir ) ) !== 0 ) {
			return new WP_Error( 'path_traversal', __( 'Resolved path is outside the draft theme.', 'vibe-ai' ), array( 'status' => 403 ) );
		}

		return $full_path;
	}

	/**
	 * Check if a file extension is allowed for writes.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_allowed_extension( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $ext, self::ALLOWED_EXTENSIONS, true );
	}

	/**
	 * Validate PHP syntax using php -l.
	 *
	 * @param string $file_path Absolute path to the PHP file.
	 * @return true|WP_Error
	 */
	private function validate_php_syntax( $file_path ) {
		if ( pathinfo( $file_path, PATHINFO_EXTENSION ) !== 'php' ) {
			return true;
		}

		if ( ! function_exists( 'exec' ) ) {
			// Can't validate — allow but log a warning.
			return true;
		}

		$output = array();
		$code   = 0;
		/*
		 * exec() is used solely for `php -l` syntax validation. The file path
		 * is escaped with escapeshellarg(). If exec() is disabled, validation
		 * is skipped gracefully (line above returns true).
		 */
		exec( 'php -l ' . escapeshellarg( $file_path ) . ' 2>&1', $output, $code );

		if ( 0 !== $code ) {
			return new WP_Error( 'php_syntax', implode( "\n", $output ), array( 'status' => 422 ) );
		}

		return true;
	}

	/**
	 * Read a file, optionally a specific line range.
	 *
	 * @param string   $path       Relative path within draft theme.
	 * @param int|null $start_line Start line (1-based).
	 * @param int|null $end_line   End line (inclusive).
	 * @return WP_REST_Response|WP_Error
	 */
	public function read( $path, $start_line = null, $end_line = null ) {
		$full_path = $this->resolve_path( $path );
		if ( is_wp_error( $full_path ) ) {
			return $full_path;
		}

		if ( ! file_exists( $full_path ) ) {
			/* translators: %s: file path */
			return new WP_Error( 'not_found', sprintf( __( 'File not found: %s', 'vibe-ai' ), $path ), array( 'status' => 404 ) );
		}

		$content = file_get_contents( $full_path );

		if ( null !== $start_line || null !== $end_line ) {
			$lines      = explode( "\n", $content );
			$total      = count( $lines );
			$start      = max( 1, (int) $start_line ) - 1;
			$end        = null !== $end_line ? min( $total, (int) $end_line ) : $total;
			$slice      = array_slice( $lines, $start, $end - $start );
			$content    = implode( "\n", $slice );

			return rest_ensure_response( array(
				'path'       => $path,
				'content'    => $content,
				'start_line' => $start + 1,
				'end_line'   => $start + count( $slice ),
				'total_lines' => $total,
			) );
		}

		return rest_ensure_response( array(
			'path'        => $path,
			'content'     => $content,
			'total_lines' => substr_count( $content, "\n" ) + 1,
		) );
	}

	/**
	 * Edit a file using str_replace (old_content → new_content).
	 *
	 * old_content must match exactly once in the file.
	 *
	 * @param string $path        Relative path within draft theme.
	 * @param string $old_content Exact string to find.
	 * @param string $new_content Replacement string.
	 * @return WP_REST_Response|WP_Error
	 */
	public function edit( $path, $old_content, $new_content ) {
		$full_path = $this->resolve_path( $path );
		if ( is_wp_error( $full_path ) ) {
			return $full_path;
		}

		if ( ! file_exists( $full_path ) ) {
			/* translators: %s: file path */
			return new WP_Error( 'not_found', sprintf( __( 'File not found: %s', 'vibe-ai' ), $path ), array( 'status' => 404 ) );
		}

		if ( ! $this->is_allowed_extension( $full_path ) ) {
			return new WP_Error( 'forbidden_ext', __( 'File extension not allowed for editing.', 'vibe-ai' ), array( 'status' => 403 ) );
		}

		$content = file_get_contents( $full_path );
		$count   = substr_count( $content, $old_content );

		if ( 0 === $count ) {
			return new WP_Error( 'no_match', __( 'old_content not found in file.', 'vibe-ai' ), array( 'status' => 422 ) );
		}

		if ( $count > 1 ) {
			/* translators: %d: number of matching locations */
			return new WP_Error( 'multiple_matches', sprintf( __( 'old_content matches %d locations. Provide more context to make it unique.', 'vibe-ai' ), $count ), array( 'status' => 422 ) );
		}

		$updated = str_replace( $old_content, $new_content, $content );

		// Write to a temp file first for PHP syntax check.
		$tmp = $full_path . '.wpvibe-tmp';
		file_put_contents( $tmp, $updated );

		$syntax = $this->validate_php_syntax( $tmp );
		if ( is_wp_error( $syntax ) ) {
			wp_delete_file( $tmp );
			return $syntax;
		}

		if ( ! copy( $tmp, $full_path ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'write_failed', __( 'Failed to save file.', 'vibe-ai' ), array( 'status' => 500 ) );
		}
		wp_delete_file( $tmp );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "File edited: {$path}",
			'action_label' => 'Preview Theme',
		) );

		return rest_ensure_response( array(
			'path'    => $path,
			'status'  => 'edited',
			'message' => __( 'File updated successfully.', 'vibe-ai' ),
		) );
	}

	/**
	 * Write a new file or fully overwrite an existing file.
	 *
	 * @param string $path    Relative path within draft theme.
	 * @param string $content Full file contents.
	 * @return WP_REST_Response|WP_Error
	 */
	public function write( $path, $content ) {
		$full_path = $this->resolve_path( $path );
		if ( is_wp_error( $full_path ) ) {
			return $full_path;
		}

		if ( ! $this->is_allowed_extension( $full_path ) ) {
			return new WP_Error( 'forbidden_ext', __( 'File extension not allowed.', 'vibe-ai' ), array( 'status' => 403 ) );
		}

		// Ensure parent directory exists.
		$dir = dirname( $full_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$is_new = ! file_exists( $full_path );

		// Write to temp for syntax check.
		$tmp = $full_path . '.wpvibe-tmp';
		file_put_contents( $tmp, $content );

		$syntax = $this->validate_php_syntax( $tmp );
		if ( is_wp_error( $syntax ) ) {
			wp_delete_file( $tmp );
			return $syntax;
		}

		if ( ! copy( $tmp, $full_path ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'write_failed', __( 'Failed to save file.', 'vibe-ai' ), array( 'status' => 500 ) );
		}
		wp_delete_file( $tmp );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => $is_new ? "File created: {$path}" : "File updated: {$path}",
			'action_label' => 'Preview Theme',
		) );

		return rest_ensure_response( array(
			'path'    => $path,
			'status'  => $is_new ? 'created' : 'overwritten',
			'message' => $is_new ? __( 'File created successfully.', 'vibe-ai' ) : __( 'File overwritten successfully.', 'vibe-ai' ),
		) );
	}

	/**
	 * Delete a file from the draft theme.
	 *
	 * @param string $path Relative path within draft theme.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( $path ) {
		$full_path = $this->resolve_path( $path );
		if ( is_wp_error( $full_path ) ) {
			return $full_path;
		}

		if ( ! file_exists( $full_path ) ) {
			/* translators: %s: file path */
			return new WP_Error( 'not_found', sprintf( __( 'File not found: %s', 'vibe-ai' ), $path ), array( 'status' => 404 ) );
		}

		if ( is_dir( $full_path ) ) {
			return new WP_Error( 'is_directory', __( 'Cannot delete directories. Only files can be deleted.', 'vibe-ai' ), array( 'status' => 400 ) );
		}

		wp_delete_file( $full_path );

		WPVibe_Change_Tracker::mark( array(
			'summary'      => "File deleted: {$path}",
			'action_label' => 'Preview Theme',
		) );

		return rest_ensure_response( array(
			'path'    => $path,
			'status'  => 'deleted',
			'message' => __( 'File deleted successfully.', 'vibe-ai' ),
		) );
	}

	/**
	 * List all files in the draft theme, optionally filtered by glob pattern.
	 *
	 * @param string|null $pattern Optional glob pattern (e.g., "*.php", "template-parts/*").
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_files( $pattern = null ) {
		$draft_dir = $this->get_draft_dir();
		if ( is_wp_error( $draft_dir ) ) {
			return $draft_dir;
		}

		$draft_slug = get_option( 'wpvibe_draft_theme' );
		$max_entries = 5000;
		$files       = array();
		$truncated   = false;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $draft_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( count( $files ) >= $max_entries ) {
				$truncated = true;
				break;
			}

			$relative = $iterator->getSubPathName();

			// Apply glob pattern filter if provided.
			if ( $pattern && ! $item->isDir() && ! fnmatch( $pattern, $relative, FNM_PATHNAME | FNM_CASEFOLD ) ) {
				continue;
			}

			$entry = array(
				'path' => $relative,
				'type' => $item->isDir() ? 'directory' : 'file',
			);

			if ( ! $item->isDir() ) {
				$entry['size']      = $item->getSize();
				$entry['extension'] = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
			}

			$files[] = $entry;
		}

		$file_count = count( array_filter( $files, function ( $f ) {
			return 'file' === $f['type'];
		} ) );

		return rest_ensure_response( array(
			'draft_slug'  => $draft_slug,
			'files'       => $files,
			'total_files' => $file_count,
			'truncated'   => $truncated,
		) );
	}

	/**
	 * Search file contents across the draft theme (grep-like).
	 *
	 * @param string      $pattern        Search string.
	 * @param bool        $case_sensitive Whether the search is case-sensitive.
	 * @param array|null  $extensions     File extensions to search (defaults to ALLOWED_EXTENSIONS).
	 * @param int         $max_results    Maximum number of matches to return.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search_files( $pattern, $case_sensitive = false, $extensions = null, $max_results = 100 ) {
		$draft_dir = $this->get_draft_dir();
		if ( is_wp_error( $draft_dir ) ) {
			return $draft_dir;
		}

		if ( empty( $pattern ) ) {
			return new WP_Error( 'empty_pattern', __( 'Search pattern cannot be empty.', 'vibe-ai' ), array( 'status' => 400 ) );
		}

		$allowed_ext   = $extensions ? $extensions : self::ALLOWED_EXTENSIONS;
		$matches       = array();
		$files_searched = 0;
		$truncated     = false;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $draft_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				continue;
			}

			$ext = strtolower( pathinfo( $item->getFilename(), PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed_ext, true ) ) {
				continue;
			}

			$relative = $iterator->getSubPathName();
			$content  = file_get_contents( $item->getPathname() );
			$lines    = explode( "\n", $content );
			$files_searched++;

			foreach ( $lines as $i => $line ) {
				if ( count( $matches ) >= $max_results ) {
					$truncated = true;
					break 2;
				}

				$found = $case_sensitive
					? ( strpos( $line, $pattern ) !== false )
					: ( stripos( $line, $pattern ) !== false );

				if ( $found ) {
					$match = array(
						'file'    => $relative,
						'line'    => $i + 1,
						'content' => mb_substr( trim( $line ), 0, 200 ),
					);

					// Add 1 line of context above and below.
					if ( $i > 0 ) {
						$match['context_before'] = mb_substr( trim( $lines[ $i - 1 ] ), 0, 200 );
					}
					if ( $i < count( $lines ) - 1 ) {
						$match['context_after'] = mb_substr( trim( $lines[ $i + 1 ] ), 0, 200 );
					}

					$matches[] = $match;
				}
			}
		}

		return rest_ensure_response( array(
			'pattern'        => $pattern,
			'matches'        => $matches,
			'total_matches'  => count( $matches ),
			'files_searched' => $files_searched,
			'truncated'      => $truncated,
		) );
	}

	/**
	 * Get a structural outline of a file (functions, hooks, landmarks with line numbers).
	 *
	 * @param string $path Relative path within draft theme.
	 * @return WP_REST_Response|WP_Error
	 */
	public function outline( $path ) {
		$full_path = $this->resolve_path( $path );
		if ( is_wp_error( $full_path ) ) {
			return $full_path;
		}

		if ( ! file_exists( $full_path ) ) {
			/* translators: %s: file path */
			return new WP_Error( 'not_found', sprintf( __( 'File not found: %s', 'vibe-ai' ), $path ), array( 'status' => 404 ) );
		}

		$content     = file_get_contents( $full_path );
		$lines       = explode( "\n", $content );
		$total_lines = count( $lines );
		$ext         = strtolower( pathinfo( $full_path, PATHINFO_EXTENSION ) );
		$outline     = array();

		// PHP patterns.
		if ( 'php' === $ext ) {
			foreach ( $lines as $i => $line ) {
				// Class definitions.
				if ( preg_match( '/^\s*(?:abstract\s+)?class\s+(\w+)/', $line, $m ) ) {
					$outline[] = array( 'line' => $i + 1, 'type' => 'class', 'name' => $m[1] );
				}

				// Function definitions.
				if ( preg_match( '/^\s*(?:public|private|protected|static\s+)*function\s+(\w+)\s*\(/', $line, $m ) ) {
					$outline[] = array( 'line' => $i + 1, 'type' => 'function', 'name' => $m[1] );
				}

				// WordPress hooks.
				if ( preg_match( '/\b(add_action|add_filter|do_action|apply_filters)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $line, $m ) ) {
					$outline[] = array( 'line' => $i + 1, 'type' => 'hook', 'name' => $m[2], 'detail' => $m[1] );
				}

				// Template parts.
				if ( preg_match( '/\bget_template_part\s*\(\s*[\'"]([^\'"]+)[\'"]/', $line, $m ) ) {
					$outline[] = array( 'line' => $i + 1, 'type' => 'template_part', 'name' => $m[1] );
				}

				// HTML landmarks in PHP templates.
				if ( preg_match( '/<(header|footer|main|nav|aside|section|article)[\s>]/', $line, $m ) ) {
					$id_or_class = '';
					if ( preg_match( '/(?:id|class)\s*=\s*["\']([^"\']+)["\']/', $line, $attr ) ) {
						$id_or_class = $attr[1];
					}
					$outline[] = array( 'line' => $i + 1, 'type' => 'landmark', 'name' => '<' . $m[1] . '>', 'detail' => $id_or_class );
				}

				// HTML comment sections.
				if ( preg_match( '/<!--\s*(.+?)\s*-->/', $line, $m ) ) {
					$comment = mb_substr( $m[1], 0, 80 );
					if ( strlen( $comment ) > 5 ) {
						$outline[] = array( 'line' => $i + 1, 'type' => 'comment_section', 'name' => $comment );
					}
				}

				// WordPress template tags.
				if ( preg_match( '/\b(get_header|get_footer|get_sidebar|the_content|the_title|wp_head|wp_footer)\s*\(/', $line, $m ) ) {
					$outline[] = array( 'line' => $i + 1, 'type' => 'template_tag', 'name' => $m[1] . '()' );
				}
			}
		}

		// CSS patterns.
		if ( 'css' === $ext ) {
			foreach ( $lines as $i => $line ) {
				// Major comment blocks.
				if ( preg_match( '/^\/\*/', $line ) ) {
					$comment = mb_substr( trim( $line, "/* \t\n\r" ), 0, 80 );
					if ( ! empty( $comment ) ) {
						$outline[] = array( 'line' => $i + 1, 'type' => 'comment_section', 'name' => $comment );
					}
				}

				// Media queries.
				if ( preg_match( '/@media\s*(.+?)\s*\{/', $line, $m ) ) {
					$outline[] = array( 'line' => $i + 1, 'type' => 'media_query', 'name' => '@media ' . mb_substr( $m[1], 0, 80 ) );
				}
			}
		}

		// JS patterns.
		if ( 'js' === $ext ) {
			foreach ( $lines as $i => $line ) {
				// Function declarations.
				if ( preg_match( '/^\s*(?:export\s+)?(?:async\s+)?function\s+(\w+)/', $line, $m ) ) {
					$outline[] = array( 'line' => $i + 1, 'type' => 'function', 'name' => $m[1] );
				}

				// Arrow function assignments.
				if ( preg_match( '/^\s*(?:export\s+)?(?:const|let|var)\s+(\w+)\s*=\s*(?:async\s+)?\(/', $line, $m ) ) {
					$outline[] = array( 'line' => $i + 1, 'type' => 'function', 'name' => $m[1] );
				}

				// Event listeners.
				if ( preg_match( '/\b(?:addEventListener|on\w+)\s*\(\s*[\'"](\w+)[\'"]/', $line, $m ) ) {
					$outline[] = array( 'line' => $i + 1, 'type' => 'event_listener', 'name' => $m[1] );
				}
			}
		}

		// HTML patterns (for .html files — PHP templates get landmarks above).
		if ( 'html' === $ext ) {
			foreach ( $lines as $i => $line ) {
				if ( preg_match( '/<(header|footer|main|nav|aside|section|article)[\s>]/', $line, $m ) ) {
					$id_or_class = '';
					if ( preg_match( '/(?:id|class)\s*=\s*["\']([^"\']+)["\']/', $line, $attr ) ) {
						$id_or_class = $attr[1];
					}
					$outline[] = array( 'line' => $i + 1, 'type' => 'landmark', 'name' => '<' . $m[1] . '>', 'detail' => $id_or_class );
				}

				if ( preg_match( '/<!--\s*(.+?)\s*-->/', $line, $m ) ) {
					$comment = mb_substr( $m[1], 0, 80 );
					if ( strlen( $comment ) > 5 ) {
						$outline[] = array( 'line' => $i + 1, 'type' => 'comment_section', 'name' => $comment );
					}
				}
			}
		}

		return rest_ensure_response( array(
			'path'        => $path,
			'type'        => $ext,
			'total_lines' => $total_lines,
			'outline'     => $outline,
		) );
	}
}
