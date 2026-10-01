<?php
/**
 * Plugin Name: WP Media Audit (read-only)
 * Description: Read-only REST routes for the divi5-builder skill's media-audit.js: lists media library items, files on disk with sizes, and WHERE upload files and attachment IDs are referenced in the database. Works on any WordPress site (no Divi needed). Writes nothing, deletes nothing, returns no content: only file names, sizes, attachment IDs and the place a reference was found. Administrators only. Remove the file when the audit is done.
 * Version: 1.1.1
 * Author: divi5-builder skill
 *
 * INSTALL: copy this file to wp-content/mu-plugins/wp-media-audit.php (create the folder if it is not there).
 * REMOVE:  delete the file. It stores nothing, so nothing is left behind.
 * Needs PHP 7.0 or newer.
 *
 * Routes (namespace wp-media-audit/v1, all GET, all need the manage_options capability):
 *   /info                      versions, upload folder, limits, database tables with sizes
 *   /attachments?after=&limit= media library rows with every file each one owns (main file, sizes, original, backups)
 *   /files?root=&dir=&deep=    files in a folder: name, bytes, modified time. root = uploads | content | abspath
 *   /du?root=&dir=&skip=       size of each child of a folder, and the biggest files in it
 *   /refs?table=&after=&limit= references found in one database table, in pages
 *   /db                        what takes the space in the database: counts and sizes per post type, revisions, meta keys, options
 *   /theme-refs                references found in the active theme's (and parent theme's) code files
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'WPMA_VERSION' ) ) { define( 'WPMA_VERSION', '1.1.0' ); }

if ( ! function_exists( 'wpma_key_is_media' ) ) :

	/*
	 * ---- Pure functions: no WordPress, no database. Tested by scripts/media-audit-test.php -----------------------
	 */

	// Does a key name (meta key, shortcode attribute, JSON key) say "this holds a media item"?
	// image_1, _thumbnail_id, backgroundImageId, gallery_ids: yes.  image_width, profile_id, slide_count: no.
	function wpma_key_is_media( $key ) {
		$key   = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '_', (string) $key );
		$words = preg_split( '/[^a-z]+/', strtolower( $key ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) { return false; }
		static $yes = null, $no = null;
		if ( null === $yes ) {
			$yes = array_flip( array( 'image', 'images', 'img', 'imgs', 'gallery', 'galleries', 'attachment', 'attachments', 'media', 'logo', 'logos', 'thumb', 'thumbs', 'thumbnail', 'thumbnails', 'poster', 'photo', 'photos', 'picture', 'pictures', 'pic', 'icon', 'icons', 'favicon', 'avatar', 'background', 'bg', 'banner', 'cover', 'slide', 'slides', 'video', 'videos', 'audio', 'file', 'files', 'pdf', 'download', 'downloads', 'upload', 'uploads' ) );
			$no  = array_flip( array( 'width', 'height', 'size', 'sizes', 'count', 'opacity', 'quality', 'ratio', 'speed', 'duration', 'columns', 'column', 'order', 'index', 'zoom', 'position', 'offset', 'radius', 'margin', 'padding', 'time', 'delay', 'limit', 'number', 'num', 'per', 'page', 'alt', 'title', 'type', 'class', 'style', 'color', 'colour', 'blur', 'spacing', 'gap', 'max', 'min', 'version', 'enabled', 'show', 'hide', 'date' ) );
		}
		$media = false;
		foreach ( $words as $w ) {
			if ( isset( $no[ $w ] ) ) { return false; }
			if ( isset( $yes[ $w ] ) ) { $media = true; }
		}
		return $media;
	}

	// Undo the escaping that hides a path: JSON (\/ and \" and \u002d), HTML (&quot;), and whole URLs that were URL-encoded.
	function wpma_norm_text( $t ) {
		$t = (string) $t;
		if ( false !== strpos( $t, '\\' ) ) {
			$t = preg_replace( '~\\\\+/~', '/', $t );
			$t = preg_replace( '~\\\\+"~', '"', $t );
			$t = preg_replace_callback( '~\\\\+u00([0-7][0-9a-fA-F])~', function ( $m ) { return chr( hexdec( $m[1] ) ); }, $t );
		}
		if ( false !== strpos( $t, '&' ) ) {
			$t = str_replace( array( '&quot;', '&#34;', '&#034;', '&#x22;', '&#47;', '&#x2F;', '&#x2f;' ), array( '"', '"', '"', '"', '/', '/', '/' ), $t );
		}
		if ( false !== stripos( $t, '%2F' ) ) {
			$t .= "\n" . rawurldecode( $t );
		}
		return $t;
	}

	// Every upload path in a text, relative to the uploads folder. $base is the URL path of that folder,
	// for example "wp-content/uploads". The host is ignored, so a link to the old domain still counts.
	// $t must already be normalised with wpma_norm_text().
	function wpma_paths( $t, $base ) {
		$out = array();
		if ( '' === $base || false === stripos( $t, $base ) ) { return $out; }
		if ( ! preg_match_all( '~' . preg_quote( $base, '~' ) . '/([^\s"\'<>()\\\\?#,;&\[\]{}|`^*]+)~i', $t, $m ) ) { return $out; }
		foreach ( $m[1] as $p ) {
			$p = rtrim( $p, '.:!/' );
			if ( false !== strpos( $p, '%' ) ) { $p = rawurldecode( $p ); }
			// a ".." FOLDER climbs out of uploads; two dots inside a file name ("Open-Day..jpg") are just a name
			if ( '' === $p || strlen( $p ) > 400 || preg_match( '#(^|/)\.\.(/|$)#', $p ) ) { continue; }
			$out[ $p ] = true;
		}
		return array_keys( $out );
	}

	// Attachment IDs in a text, with how sure we are: 's' = strong (the text says it is a media item),
	// 'w' = weak (a bare id="12" or "id":12 that could be anything). Returns array( id => 's'|'w' ).
	// $t must already be normalised with wpma_norm_text().
	function wpma_ids_in_text( $t ) {
		$out = array();
		if ( ! preg_match( '/\d/', $t ) ) { return $out; }
		$add = function ( $list, $s ) use ( &$out ) {
			foreach ( preg_split( '/[^0-9]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY ) as $n ) {
				if ( strlen( $n ) > 12 ) { continue; }
				$n = (int) $n;
				if ( $n < 1 ) { continue; }
				if ( 's' === $s || ! isset( $out[ $n ] ) ) { $out[ $n ] = $s; }
			}
		};
		// class="wp-image-12", rel="attachment wp-att-12", id="attachment_12" (WordPress writes these itself)
		if ( preg_match_all( '/\b(?:wp-image-|wp-att-|attachment_)(\d+)/', $t, $m ) ) {
			foreach ( $m[1] as $n ) { $add( $n, 's' ); }
		}
		// [gallery ids="1,2"]  [playlist ids="1,2"]  [gallery include="1,2"]
		if ( preg_match_all( '/\[(?:gallery|playlist)\b[^\]]*?\b(?:ids|include)\s*=\s*["\']?([\d,\s]+)/i', $t, $m ) ) {
			foreach ( $m[1] as $n ) { $add( $n, 's' ); }
		}
		// attribute="12" or attribute="1,2,3" in a shortcode or HTML tag
		if ( preg_match_all( '/([A-Za-z][A-Za-z0-9_:\-]*)\s*=\s*(["\'])(\d[\d,\s]*)\2/', $t, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				if ( wpma_key_is_media( $x[1] ) ) { $add( $x[3], 's' ); }
				elseif ( preg_match( '/^(id|ids|include)$/i', $x[1] ) ) { $add( $x[3], 'w' ); }
			}
		}
		// JSON: "key":12  "key":"12"  "key":"1,2"  "key":[1,"2"]
		if ( preg_match_all( '/"([A-Za-z][A-Za-z0-9_\-]*)"\s*:\s*(?:"(\d[\d,\s]*)"|(\d+)(?![.\d])|\[((?:\s*"?\d+"?\s*,?)+)\])/', $t, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$val = isset( $x[4] ) && '' !== $x[4] ? $x[4] : ( isset( $x[3] ) && '' !== $x[3] ? $x[3] : $x[2] );
				if ( wpma_key_is_media( $x[1] ) ) { $add( $val, 's' ); }
				elseif ( preg_match( '/^(id|ids)$/i', $x[1] ) ) { $add( $val, 'w' ); }
			}
		}
		// JSON where the value sits a few levels down, one per screen size (Divi 5 blocks):
		// "galleryIds":{"innerContent":{"desktop":{"value":"1,2"},"tablet":{"value":"3"}}}
		if ( false !== strpos( $t, '"value"' ) && preg_match_all( '/"([A-Za-z][A-Za-z0-9_\-]*)"\s*:\s*(\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\})/', $t, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				if ( ! wpma_key_is_media( $x[1] ) ) { continue; }
				if ( preg_match_all( '/"value"\s*:\s*(?:"(\d[\d,\s]*)"|(\d+)(?![.\d])|\[((?:\s*"?\d+"?\s*,?)+)\])/', $x[2], $mm, PREG_SET_ORDER ) ) {
					foreach ( $mm as $y ) { $add( isset( $y[3] ) && '' !== $y[3] ? $y[3] : ( isset( $y[2] ) && '' !== $y[2] ? $y[2] : $y[1] ), 's' ); }
				}
			}
		}
		// PHP serialized: s:4:"logo";i:12;  s:4:"logo";s:2:"12";  s:7:"gallery";a:2:{i:0;i:12;i:1;s:2:"13";}
		if ( false !== strpos( $t, '";' ) && preg_match_all( '/s:\d+:"([^"]{1,80})";(?:i:(\d+);|s:\d+:"(\d[\d,\s]*)";|a:\d+:\{((?:i:\d+;(?:i:\d+;|s:\d+:"\d+";))+)\})/', $t, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				if ( ! wpma_key_is_media( $x[1] ) ) { continue; }
				if ( isset( $x[4] ) && '' !== $x[4] ) {
					// only the values of the inner list, not its 0,1,2 positions
					if ( preg_match_all( '/i:\d+;(?:i:(\d+);|s:\d+:"(\d+)";)/', $x[4], $mm, PREG_SET_ORDER ) ) {
						foreach ( $mm as $y ) { $add( isset( $y[2] ) && '' !== $y[2] ? $y[2] : $y[1], 's' ); }
					}
				} else {
					$add( isset( $x[3] ) && '' !== $x[3] ? $x[3] : $x[2], 's' );
				}
			}
		}
		return $out;
	}

	// Attachment IDs in a key + value pair (post meta, term meta, user meta, an option).
	// A value that is nothing but a number or a list of numbers is an ID only if the key says so; with any other
	// key it is a weak match, except for keys that are known to hold something else.
	function wpma_ids_in_meta( $key, $value ) {
		$key   = (string) $key;
		$value = (string) $value;
		$out   = array();
		$trim  = trim( $value );
		$list  = null;
		if ( preg_match( '/^\d+(\s*,\s*\d+)*$/', $trim ) ) {
			$list = $trim;
		} elseif ( preg_match( '/^a:\d+:\{((?:i:\d+;(?:i:\d+;|s:\d+:"\d+";))+)\}$/', $trim, $m ) && preg_match_all( '/i:\d+;(?:i:(\d+);|s:\d+:"(\d+)";)/', $m[1], $mm, PREG_SET_ORDER ) ) {
			$vals = array();
			foreach ( $mm as $y ) { $vals[] = isset( $y[2] ) && '' !== $y[2] ? $y[2] : $y[1]; }
			$list = implode( ',', $vals );
		}
		if ( null !== $list ) {
			$s = null;
			if ( wpma_key_is_media( $key ) ) { $s = 's'; }
			elseif ( ! preg_match( '/^(_edit_last|_edit_lock|_wp_old_.*|_wp_trash_.*|_wp_desired_post_slug|_menu_item_.*|_wp_page_template|_pingme|_encloseme|.*_count|.*_version|.*user_level|.*capabilities|.*user-settings.*|session_tokens|db_version|.*_db_version|posts_per_page|posts_per_rss|page_on_front|page_for_posts|wp_page_for_privacy_policy|default_category|default_.*|.*_size_[wh]|thumbnail_crop|start_of_week|comments_per_page|blog_public|users_can_register|close_comments_.*|thread_comments.*|uploads_use_yearmonth_folders|.*_timestamp|.*_time|cron)$/', $key ) ) { $s = 'w'; }
			if ( $s ) {
				foreach ( explode( ',', $list ) as $n ) {
					$n = trim( $n );
					if ( strlen( $n ) > 12 ) { continue; }
					$n = (int) $n;
					if ( $n > 0 ) { $out[ $n ] = $s; }
				}
			}
			return $out;
		}
		return wpma_ids_in_text( wpma_norm_text( $value ) );
	}

	// An old-style [gallery] with no ids shows every image that was uploaded to that post.
	function wpma_has_bare_gallery( $t ) {
		if ( false === stripos( $t, '[gallery' ) ) { return false; }
		if ( ! preg_match_all( '/\[gallery\b([^\]]*)\]/i', $t, $m ) ) { return false; }
		foreach ( $m[1] as $attrs ) {
			if ( ! preg_match( '/\b(ids|include)\s*=/i', $attrs ) ) { return true; }
		}
		return false;
	}

	// How much a reference from this post counts: live, trash, revision (old versions and caches) or orphan.
	function wpma_class( $type, $status ) {
		if ( null === $type ) { return 'orphan'; }
		if ( 'revision' === $type || 'auto-draft' === $status || 'oembed_cache' === $type || 'customize_changeset' === $type ) { return 'revision'; }
		if ( 'trash' === $status ) { return 'trash'; }
		return 'live';
	}

	// Collects hits and merges repeats. A hit = kind (p path, i id, g bare gallery), token, strength, class, place.
	function wpma_hit( &$hits, $kind, $token, $strength, $class, $where ) {
		$k = $kind . '|' . $token . '|' . $strength . '|' . $class;
		if ( ! isset( $hits[ $k ] ) ) { $hits[ $k ] = array( $kind, $token, $strength, $class, 0, array() ); }
		$hits[ $k ][4]++;
		if ( count( $hits[ $k ][5] ) < 3 && ! in_array( $where, $hits[ $k ][5], true ) ) { $hits[ $k ][5][] = $where; }
	}

	// All hits in one text. $ids = array of attachment IDs as keys (others are dropped), or null to keep all.
	function wpma_scan_text( &$hits, $text, $base, $ids, $class, $where, $meta_key = null ) {
		if ( null === $text || '' === $text ) { return; }
		$t = wpma_norm_text( $text );
		foreach ( wpma_paths( $t, $base ) as $p ) { wpma_hit( $hits, 'p', $p, 's', $class, $where ); }
		$found = null === $meta_key ? wpma_ids_in_text( $t ) : wpma_ids_in_meta( $meta_key, $text );
		foreach ( $found as $id => $s ) {
			if ( null === $ids || isset( $ids[ $id ] ) ) { wpma_hit( $hits, 'i', $id, $s, $class, $where ); }
		}
	}

	/*
	 * ---- WordPress side ------------------------------------------------------------------------------------------
	 */

	function wpma_upload() {
		$u    = wp_upload_dir( null, false );
		$path = trim( (string) parse_url( $u['baseurl'], PHP_URL_PATH ), '/' );
		$home = trim( (string) parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) { $path = substr( $path, strlen( $home ) + 1 ); }
		return array( 'basedir' => $u['basedir'], 'base' => $path );
	}

	function wpma_tables() {
		global $wpdb;
		$all = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		$out = array();
		foreach ( (array) $all as $t ) {
			// on the main site of a multisite, wp_2_posts and friends belong to other sites
			if ( is_multisite() && is_main_site() && preg_match( '/^' . preg_quote( $wpdb->prefix, '/' ) . '\d+_/', $t ) ) { continue; }
			$out[] = $t;
		}
		return $out;
	}

	function wpma_attachment_ids() {
		global $wpdb;
		return array_flip( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'" ) ) );
	}

	function wpma_root( $name ) {
		if ( 'uploads' === $name ) { $u = wpma_upload(); return $u['basedir']; }
		if ( 'content' === $name ) { return WP_CONTENT_DIR; }
		if ( 'abspath' === $name ) { return ABSPATH; }
		return null;
	}

	// A folder inside one of the three roots. Refuses anything that leaves the root.
	function wpma_resolve( $root, $dir ) {
		$r    = wpma_root( $root );
		$base = $r ? realpath( $r ) : false;
		if ( ! $base ) { return new WP_Error( 'bad_root', 'root must be uploads, content or abspath', array( 'status' => 400 ) ); }
		$dir = trim( str_replace( '\\', '/', (string) $dir ), '/' );
		if ( '' === $dir ) { return array( $base, '' ); }
		if ( preg_match( '#(^|/)\.\.?(/|$)#', $dir ) ) { return new WP_Error( 'bad_dir', 'dir may not contain . or ..', array( 'status' => 400 ) ); }
		$full = realpath( $base . '/' . $dir );
		if ( ! $full || ! is_dir( $full ) || 0 !== strpos( $full . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR ) ) {
			return new WP_Error( 'bad_dir', 'no such folder inside that root', array( 'status' => 404 ) );
		}
		return array( $full, $dir );
	}

	function wpma_ls( $full ) {
		$h = @opendir( $full );
		if ( ! $h ) { return array(); }
		$out = array();
		while ( false !== ( $e = readdir( $h ) ) ) {
			if ( '.' === $e || '..' === $e ) { continue; }
			$out[] = $e;
		}
		closedir( $h );
		sort( $out );
		return $out;
	}

	// Total bytes and file count under a folder. Links are not followed. Stops at the deadline.
	function wpma_du( $full, $rel, $deadline, $big, &$top ) {
		$bytes = 0; $files = 0; $complete = true;
		$stack = array( array( $full, $rel ) );
		while ( $stack ) {
			list( $d, $r ) = array_pop( $stack );
			foreach ( wpma_ls( $d ) as $e ) {
				$p = $d . '/' . $e;
				if ( is_link( $p ) ) { continue; }
				if ( is_dir( $p ) ) { $stack[] = array( $p, $r . '/' . $e ); continue; }
				$s = (int) @filesize( $p );
				$bytes += $s; $files++;
				if ( $s >= $big && count( $top ) < 300 ) { $top[] = array( $r . '/' . $e, $s, (int) @filemtime( $p ) ); }
			}
			if ( microtime( true ) > $deadline ) { $complete = ! $stack; break; }
		}
		return array( $bytes, $files, $complete );
	}

	add_action( 'rest_api_init', function () {
		$perm = function () { return current_user_can( 'manage_options' ); };
		$ns   = 'wp-media-audit/v1';

		register_rest_route( $ns, '/info', array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function () {
				global $wpdb;
				$u      = wpma_upload();
				$mine   = array_flip( wpma_tables() );
				$tables = array();
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) as $t ) {
					if ( ! isset( $mine[ $t->Name ] ) ) { continue; }
					$tables[] = array( 'name' => $t->Name, 'rows' => (int) $t->Rows, 'bytes' => (int) $t->Data_length + (int) $t->Index_length, 'engine' => $t->Engine );
				}
				$theme = wp_get_theme();
				return array(
					'ok'          => true,
					'version'     => WPMA_VERSION,
					'wp'          => get_bloginfo( 'version' ),
					'php'         => PHP_VERSION,
					'multisite'   => is_multisite(),
					'home'        => home_url(),
					'prefix'      => $wpdb->prefix,
					'uploadsBase' => $u['base'],
					'uploadsDir'  => str_replace( '\\', '/', $u['basedir'] ),
					'contentDir'  => str_replace( '\\', '/', WP_CONTENT_DIR ),
					'abspath'     => str_replace( '\\', '/', ABSPATH ),
					'mediaTrash'  => defined( 'MEDIA_TRASH' ) && MEDIA_TRASH,
					'timeLimit'   => (int) ini_get( 'max_execution_time' ),
					'memoryLimit' => ini_get( 'memory_limit' ),
					'theme'       => array( 'stylesheet' => $theme->get_stylesheet(), 'template' => $theme->get_template() ),
					'attachments' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ),
					'tables'      => $tables,
				);
			},
		) );

		register_rest_route( $ns, '/attachments', array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( $req ) {
				global $wpdb;
				$after = max( 0, (int) $req->get_param( 'after' ) );
				$limit = min( 2000, max( 1, (int) ( $req->get_param( 'limit' ) ?: 1000 ) ) );
				$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_mime_type, post_parent, post_date FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID LIMIT %d", $after, $limit ) );
				$out   = array();
				if ( $rows ) {
					$ids  = implode( ',', array_map( function ( $r ) { return (int) $r->ID; }, $rows ) );
					$meta = array();
					foreach ( (array) $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($ids) AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes')" ) as $m ) {
						$meta[ (int) $m->post_id ][ $m->meta_key ] = $m->meta_value;
					}
					foreach ( $rows as $r ) {
						$id    = (int) $r->ID;
						$mm    = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
						$file  = isset( $mm['_wp_attached_file'] ) ? str_replace( '\\', '/', (string) $mm['_wp_attached_file'] ) : '';
						$dir   = false !== strpos( $file, '/' ) ? substr( $file, 0, strrpos( $file, '/' ) + 1 ) : '';
						$files = array();
						if ( '' !== $file ) { $files[ $file ] = true; }
						$md = isset( $mm['_wp_attachment_metadata'] ) ? maybe_unserialize( $mm['_wp_attachment_metadata'] ) : null;
						if ( is_array( $md ) ) {
							if ( ! empty( $md['file'] ) && is_string( $md['file'] ) ) { $files[ str_replace( '\\', '/', $md['file'] ) ] = true; }
							if ( ! empty( $md['original_image'] ) && is_string( $md['original_image'] ) ) { $files[ $dir . $md['original_image'] ] = true; }
							if ( ! empty( $md['sizes'] ) && is_array( $md['sizes'] ) ) {
								foreach ( $md['sizes'] as $s ) {
									if ( is_array( $s ) && ! empty( $s['file'] ) && is_string( $s['file'] ) ) { $files[ $dir . basename( $s['file'] ) ] = true; }
								}
							}
						}
						$bk = isset( $mm['_wp_attachment_backup_sizes'] ) ? maybe_unserialize( $mm['_wp_attachment_backup_sizes'] ) : null;
						if ( is_array( $bk ) ) {
							foreach ( $bk as $s ) {
								if ( is_array( $s ) && ! empty( $s['file'] ) && is_string( $s['file'] ) ) { $files[ $dir . basename( $s['file'] ) ] = true; }
							}
						}
						$out[] = array( 'id' => $id, 'title' => $r->post_title, 'mime' => $r->post_mime_type, 'parent' => (int) $r->post_parent, 'date' => $r->post_date, 'file' => $file, 'files' => array_keys( $files ) );
					}
				}
				$last = $rows ? (int) end( $rows )->ID : $after;
				return array( 'ok' => true, 'items' => $out, 'next' => $last, 'done' => count( $rows ) < $limit );
			},
		) );

		register_rest_route( $ns, '/files', array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( $req ) {
				$res = wpma_resolve( (string) ( $req->get_param( 'root' ) ?: 'uploads' ), $req->get_param( 'dir' ) );
				if ( is_wp_error( $res ) ) { return $res; }
				list( $full, $rel ) = $res;
				$deep     = (bool) $req->get_param( 'deep' );
				$max      = min( 50000, max( 100, (int) ( $req->get_param( 'max' ) ?: 25000 ) ) );
				$deadline = microtime( true ) + 8;
				$files    = array(); $dirs = array(); $truncated = false;
				$stack    = array( array( $full, $rel ) );
				while ( $stack && ! $truncated ) {
					list( $d, $r ) = array_pop( $stack );
					foreach ( wpma_ls( $d ) as $e ) {
						$p  = $d . '/' . $e;
						$rp = '' === $r ? $e : $r . '/' . $e;
						if ( is_link( $p ) ) { continue; }
						if ( is_dir( $p ) ) {
							if ( $deep ) { $stack[] = array( $p, $rp ); } else { $dirs[] = $rp; }
							continue;
						}
						$files[] = array( $rp, (int) @filesize( $p ), (int) @filemtime( $p ) );
					}
					if ( count( $files ) > $max || microtime( true ) > $deadline ) { $truncated = (bool) $stack; break; }
				}
				return array( 'ok' => true, 'dir' => $rel, 'deep' => $deep, 'truncated' => $truncated, 'files' => $files, 'dirs' => $dirs );
			},
		) );

		register_rest_route( $ns, '/du', array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( $req ) {
				$res = wpma_resolve( (string) ( $req->get_param( 'root' ) ?: 'content' ), $req->get_param( 'dir' ) );
				if ( is_wp_error( $res ) ) { return $res; }
				list( $full, $rel ) = $res;
				$skip     = array_flip( array_filter( explode( ',', (string) $req->get_param( 'skip' ) ) ) );
				$big      = max( 1, (int) ( $req->get_param( 'big' ) ?: 5 * 1024 * 1024 ) );
				$deadline = microtime( true ) + 8;
				$top      = array(); $children = array();
				foreach ( wpma_ls( $full ) as $e ) {
					$p  = $full . '/' . $e;
					$rp = '' === $rel ? $e : $rel . '/' . $e;
					if ( is_link( $p ) ) { $children[] = array( 'name' => $e, 'link' => true ); continue; }
					if ( ! is_dir( $p ) ) {
						$s = (int) @filesize( $p );
						$children[] = array( 'name' => $e, 'bytes' => $s, 'mtime' => (int) @filemtime( $p ) );
						if ( $s >= $big && count( $top ) < 300 ) { $top[] = array( $rp, $s, (int) @filemtime( $p ) ); }
						continue;
					}
					if ( isset( $skip[ $e ] ) ) { $children[] = array( 'name' => $e, 'dir' => true, 'skipped' => true ); continue; }
					if ( microtime( true ) > $deadline ) { $children[] = array( 'name' => $e, 'dir' => true, 'complete' => false, 'bytes' => 0, 'files' => 0 ); continue; }
					list( $b, $n, $ok ) = wpma_du( $p, $rp, $deadline, $big, $top );
					$children[] = array( 'name' => $e, 'dir' => true, 'bytes' => $b, 'files' => $n, 'complete' => $ok );
				}
				return array( 'ok' => true, 'dir' => $rel, 'children' => $children, 'big' => $top );
			},
		) );

		register_rest_route( $ns, '/refs', array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( $req ) {
				global $wpdb;
				$t0    = microtime( true );
				$table = (string) $req->get_param( 'table' );
				if ( ! in_array( $table, wpma_tables(), true ) ) { return new WP_Error( 'bad_table', 'not a table of this site', array( 'status' => 400 ) ); }
				$after  = max( 0, (int) $req->get_param( 'after' ) );
				$limit  = min( 5000, max( 1, (int) ( $req->get_param( 'limit' ) ?: 1000 ) ) );
				$budget = 6 * 1024 * 1024;
				$u      = wpma_upload();
				$base   = $u['base'];
				$ids    = wpma_attachment_ids();
				$hits   = array();
				$name   = substr( $table, strlen( $wpdb->prefix ) );
				$next   = $after; $rows = 0; $done = false;

				// the rows of one page: never more than $budget bytes of text, so one huge row cannot exhaust memory
				$page = function ( $pk, $len_sql, $from_where ) use ( $wpdb, $after, $limit, $budget ) {
					$sizes = $wpdb->get_results( $wpdb->prepare( "SELECT $pk AS k, $len_sql AS n $from_where AND $pk > %d ORDER BY $pk LIMIT %d", $after, $limit ) );
					$upto  = $after; $sum = 0; $count = 0;
					foreach ( (array) $sizes as $s ) {
						if ( $count && $sum + (int) $s->n > $budget ) { break; }
						$sum += (int) $s->n; $upto = (int) $s->k; $count++;
					}
					return array( $upto, $count, count( (array) $sizes ) < $limit && $count === count( (array) $sizes ) );
				};

				if ( 'posts' === $name ) {
					list( $upto, $rows, $done ) = $page( 'ID', '(LENGTH(post_content) + LENGTH(post_excerpt) + LENGTH(post_content_filtered))', "FROM `$table` WHERE post_type <> 'attachment'" );
					if ( $rows ) {
						foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_status, post_content, post_excerpt, post_content_filtered FROM `$table` WHERE post_type <> 'attachment' AND ID > %d AND ID <= %d ORDER BY ID", $after, $upto ) ) as $r ) {
							$class = wpma_class( $r->post_type, $r->post_status );
							$where = 'posts|' . $r->ID . '|' . $r->post_type . '|' . $r->post_status;
							foreach ( array( $r->post_content, $r->post_excerpt, $r->post_content_filtered ) as $txt ) {
								wpma_scan_text( $hits, $txt, $base, $ids, $class, $where );
								if ( $txt && wpma_has_bare_gallery( $txt ) ) { wpma_hit( $hits, 'g', (int) $r->ID, 's', $class, $where ); }
							}
						}
					}
					$next = $upto;
				} elseif ( 'postmeta' === $name ) {
					$from = "FROM `$table` pm LEFT JOIN `{$wpdb->posts}` p ON p.ID = pm.post_id WHERE (p.post_type IS NULL OR p.post_type <> 'attachment')";
					list( $upto, $rows, $done ) = $page( 'pm.meta_id', 'COALESCE(LENGTH(pm.meta_value), 0)', $from );
					if ( $rows ) {
						foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_status $from AND pm.meta_id > %d AND pm.meta_id <= %d ORDER BY pm.meta_id", $after, $upto ) ) as $r ) {
							$class = wpma_class( $r->post_type, $r->post_status );
							// page builders keep an old copy of the page in these: an old version, not a use
							// (same list as BACKUP_META in media-audit.js)
							if ( 'live' === $class && preg_match( '/^(_et_pb_old_content|_et_pb_divi_4_content|_et_pb_ab_.*|_elementor_data_backup.*|_oembed_.*|_wp_old_.*)$/', $r->meta_key ) ) { $class = 'revision'; }
							wpma_scan_text( $hits, $r->meta_value, $base, $ids, $class, 'postmeta|' . $r->post_id . '|' . $r->meta_key . '|' . $r->post_type . '|' . $r->post_status, $r->meta_key );
						}
					}
					$next = $upto;
				} elseif ( 'options' === $name ) {
					// %% because these strings go through $wpdb->prepare()
					$from = "FROM `$table` WHERE option_name NOT LIKE '\\_transient\\_%%' AND option_name NOT LIKE '\\_site\\_transient\\_%%'";
					list( $upto, $rows, $done ) = $page( 'option_id', 'LENGTH(option_value)', $from );
					if ( $rows ) {
						foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value $from AND option_id > %d AND option_id <= %d ORDER BY option_id", $after, $upto ) ) as $r ) {
							wpma_scan_text( $hits, $r->option_value, $base, $ids, 'live', 'options|' . $r->option_name, $r->option_name );
						}
					}
					$next = $upto;
				} elseif ( in_array( $name, array( 'termmeta', 'usermeta', 'commentmeta' ), true ) ) {
					$pk  = 'usermeta' === $name ? 'umeta_id' : 'meta_id';
					$own = 'termmeta' === $name ? 'term_id' : ( 'usermeta' === $name ? 'user_id' : 'comment_id' );
					list( $upto, $rows, $done ) = $page( $pk, 'COALESCE(LENGTH(meta_value), 0)', "FROM `$table` WHERE 1 = 1" );
					if ( $rows ) {
						foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT $pk AS k, $own AS o, meta_key, meta_value FROM `$table` WHERE $pk > %d AND $pk <= %d ORDER BY $pk", $after, $upto ) ) as $r ) {
							wpma_scan_text( $hits, $r->meta_value, $base, $ids, 'live', $name . '|' . $r->o . '|' . $r->meta_key, $r->meta_key );
						}
					}
					$next = $upto;
				} else {
					// any other table: every text column; a numeric primary key pages it, otherwise by offset
					$pk = null; $cols = array();
					foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM `$table`" ) as $c ) {
						if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $c->Field ) ) { continue; }
						if ( 'PRI' === $c->Key && preg_match( '/int/i', $c->Type ) ) { $pk = null === $pk ? $c->Field : false; }
						if ( preg_match( '/char|text|blob|json/i', $c->Type ) ) { $cols[] = $c->Field; }
					}
					if ( ! $cols ) {
						$done = true;
					} else {
						$list = '`' . implode( '`, `', $cols ) . '`';
						$len  = '(' . implode( ' + ', array_map( function ( $c ) { return "COALESCE(LENGTH(`$c`), 0)"; }, $cols ) ) . ')';
						if ( $pk ) {
							list( $upto, $rows, $done ) = $page( "`$pk`", $len, "FROM `$table` WHERE 1 = 1" );
							$data = $rows ? $wpdb->get_results( $wpdb->prepare( "SELECT `$pk` AS wpma_k, $list FROM `$table` WHERE `$pk` > %d AND `$pk` <= %d ORDER BY `$pk`", $after, $upto ), ARRAY_A ) : array();
							$next = $upto;
						} else {
							$limit = min( $limit, 500 );
							$data  = $wpdb->get_results( $wpdb->prepare( "SELECT $list FROM `$table` LIMIT %d OFFSET %d", $limit, $after ), ARRAY_A );
							$rows  = count( (array) $data );
							$next  = $after + $rows;
							$done  = $rows < $limit;
						}
						foreach ( (array) $data as $i => $r ) {
							$k = isset( $r['wpma_k'] ) ? $r['wpma_k'] : ( $after + $i );
							foreach ( $cols as $c ) {
								wpma_scan_text( $hits, $r[ $c ], $base, $ids, 'live', $name . '|' . $k . '|' . $c );
							}
						}
					}
				}
				return array( 'ok' => true, 'table' => $table, 'rows' => $rows, 'next' => $next, 'done' => (bool) $done, 'seconds' => round( microtime( true ) - $t0, 2 ), 'hits' => array_values( $hits ) );
			},
		) );

		// What takes the space in the database. Counts and sizes only, never content.
		register_rest_route( $ns, '/db', array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function () {
				global $wpdb;
				$rows = function ( $sql ) use ( $wpdb ) { return (array) $wpdb->get_results( $sql, ARRAY_A ); };
				$one  = function ( $sql ) use ( $wpdb ) { $r = $wpdb->get_row( $sql, ARRAY_A ); return $r ? array_map( 'intval', $r ) : array( 'n' => 0, 'bytes' => 0 ); };
				$len  = '(LENGTH(post_content) + LENGTH(post_title) + LENGTH(post_excerpt) + LENGTH(post_content_filtered))';
				$p = $wpdb->posts; $pm = $wpdb->postmeta; $o = $wpdb->options; $c = $wpdb->comments; $cm = $wpdb->commentmeta; $tm = $wpdb->termmeta; $tr = $wpdb->term_relationships; $um = $wpdb->usermeta;
				return array(
					'ok'               => true,
					'version'          => WPMA_VERSION,
					'revisionsSetting' => defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : 'not set (unlimited)',
					'activePlugins'    => array_values( (array) get_option( 'active_plugins', array() ) ),
					'posts'            => $rows( "SELECT post_type AS type, post_status AS status, COUNT(*) AS n, SUM($len) AS bytes FROM $p GROUP BY post_type, post_status ORDER BY bytes DESC" ),
					'revisionsOfLive'  => $one( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(r.post_content)), 0) AS bytes FROM $p r JOIN $p x ON x.ID = r.post_parent WHERE r.post_type = 'revision'" ),
					'revisionsOrphan'  => $one( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(r.post_content)), 0) AS bytes FROM $p r LEFT JOIN $p x ON x.ID = r.post_parent WHERE r.post_type = 'revision' AND x.ID IS NULL" ),
					'revisionsTop'     => $rows( "SELECT r.post_parent AS id, COUNT(*) AS n, SUM(LENGTH(r.post_content)) AS bytes FROM $p r WHERE r.post_type = 'revision' GROUP BY r.post_parent ORDER BY bytes DESC LIMIT 15" ),
					'postmetaKeys'     => $rows( "SELECT meta_key AS k, COUNT(*) AS n, SUM(COALESCE(LENGTH(meta_value), 0)) AS bytes FROM $pm GROUP BY meta_key ORDER BY bytes DESC LIMIT 30" ),
					'postmetaOrphan'   => $one( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(m.meta_value)), 0) AS bytes FROM $pm m LEFT JOIN $p x ON x.ID = m.post_id WHERE x.ID IS NULL" ),
					'postmetaOfRevisions' => $one( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(m.meta_value)), 0) AS bytes FROM $pm m JOIN $p x ON x.ID = m.post_id WHERE x.post_type = 'revision'" ),
					'optionsAutoload'  => $one( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes FROM $o WHERE autoload IN ('yes', 'on', 'auto', 'auto-on')" ),
					'optionsTransient' => $one( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes FROM $o WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" ),
					'optionsTop'       => $rows( "SELECT option_name AS k, LENGTH(option_value) AS bytes, autoload FROM $o ORDER BY bytes DESC LIMIT 30" ),
					'comments'         => $rows( "SELECT comment_approved AS status, comment_type AS type, COUNT(*) AS n FROM $c GROUP BY comment_approved, comment_type" ),
					'commentmetaOrphan' => $one( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(m.meta_value)), 0) AS bytes FROM $cm m LEFT JOIN $c x ON x.comment_ID = m.comment_id WHERE x.comment_ID IS NULL" ),
					'termRelOrphan'    => $one( "SELECT COUNT(*) AS n, 0 AS bytes FROM $tr r LEFT JOIN $p x ON x.ID = r.object_id WHERE x.ID IS NULL" ),
					'usermetaOrphan'   => $one( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(m.meta_value)), 0) AS bytes FROM $um m LEFT JOIN {$wpdb->users} x ON x.ID = m.user_id WHERE x.ID IS NULL" ),
				);
			},
		) );

		register_rest_route( $ns, '/theme-refs', array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function () {
				$u        = wpma_upload();
				$ids      = wpma_attachment_ids();
				$hits     = array();
				$deadline = microtime( true ) + 8;
				$seen     = 0; $complete = true;
				$dirs     = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
				foreach ( $dirs as $root ) {
					$label = basename( $root );
					$stack = array( array( $root, $label ) );
					while ( $stack ) {
						list( $d, $r ) = array_pop( $stack );
						foreach ( wpma_ls( $d ) as $e ) {
							$p = $d . '/' . $e;
							if ( is_link( $p ) ) { continue; }
							if ( is_dir( $p ) ) {
								if ( 'node_modules' !== $e && '.git' !== $e ) { $stack[] = array( $p, $r . '/' . $e ); }
								continue;
							}
							if ( ! preg_match( '/\.(css|scss|php|js|html?|json|twig)$/i', $e ) || @filesize( $p ) > 2 * 1024 * 1024 ) { continue; }
							$seen++;
							$txt = wpma_norm_text( (string) @file_get_contents( $p ) );
							foreach ( wpma_paths( $txt, $u['base'] ) as $path ) { wpma_hit( $hits, 'p', $path, 's', 'live', 'theme|' . $r . '/' . $e ); }
							// in code only the forms WordPress itself writes count as an ID
							if ( preg_match_all( '/\b(?:wp-image-|wp-att-)(\d+)/', $txt, $m ) ) {
								foreach ( $m[1] as $n ) { if ( isset( $ids[ (int) $n ] ) ) { wpma_hit( $hits, 'i', (int) $n, 's', 'live', 'theme|' . $r . '/' . $e ); } }
							}
						}
						if ( $seen > 4000 || microtime( true ) > $deadline ) { $complete = ! $stack; break 2; }
					}
				}
				return array( 'ok' => true, 'files' => $seen, 'complete' => $complete, 'hits' => array_values( $hits ) );
			},
		) );
	} );

endif;
