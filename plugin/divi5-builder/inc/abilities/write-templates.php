<?php
/**
 * Write abilities for block themes (#32, release 2.3.0): templates-update and global-styles-set.
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here.
 *
 * Rules (design 2026-10-30, "Templates and global styles (block themes only)"):
 *  - Both are risky (the Templates risky switch) and need edit_theme_options, as core's templates and global styles
 *    controllers (fix wave M8); templates-update also needs unfiltered_html (block markup), checked by the permission
 *    callback and again by the handler (needs_unfiltered_html).
 *  - On a classic theme (every Divi site) both answer { is_block_theme: false, note } and write nothing: no history row.
 *  - templates-update: id is theme//slug as templates-list returns it, of the active theme; it is looked up as the given
 *    type (wp_template or wp_template_part), or without one as both: an id that is both is ambiguous. content (at most 1 MB) must parse as block markup (d5b_blocks_parse), must not bring back a
 *    masked read ([redacted]), and expect_hash is md5 of the content templates-get returns (for a template the theme ships,
 *    the file's content). A customised template (source custom) is updated in place; a theme-file one becomes a customised
 *    post the way core's templates controller does it (prepare_item_for_database, the theme-file case): same slug, type,
 *    status publish, the wp_theme term, origin meta, title and description of the file (and the area of a part). The
 *    read-back must find WordPress using that post for the id. History: object_type the post type, object_id the post,
 *    before = the previous content in full + its source, revision_id when a revision holds the previous content.
 *  - global-styles-set: writes the USER layer only (the active theme's wp_global_styles post), never the merged data.
 *    styles is an object (or JSON text of one, at most 1 MB) with styles and/or settings; the version and
 *    isGlobalStylesUserThemeJSON keys a read returns are accepted and set by the plugin. MERGED as core merges it (fix
 *    wave I4): a part left out keeps its stored value; an explicit null removes it (core keeps it: the one difference).
 *    Built as core's global styles controller builds it (prepare_item_for_database: styles, settings, the flag, version
 *    LATEST_SCHEMA; markup in styles.css refused, here in every css key at any depth). Sanitised as core's kses filter
 *    wp_filter_global_styles_post does (WP_Theme_JSON::remove_insecure_properties) ONLY for a caller without
 *    unfiltered_html, as core (kses_init adds that filter only then); when the sanitiser would remove anything (in the
 *    part given or in a stored part merged in) the write is refused invalid_input naming the paths, never dropped
 *    silently. An administrator's styles are stored as given, as core stores them. A stored layer holding a value under a secret-named key (hidden on
 *    reading: global-styles-get says masked) is not written over (not_allowed), and an input holding one is refused, so
 *    the history never keeps one. No change (the same data, key order aside) writes nothing. expect_hash is user_hash
 *    from global-styles-get (md5 of the stored JSON, md5 of '' when there is none yet). History: object_type
 *    wp_global_styles, before = the previous JSON text.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wtpl_h_update' ) ) {

	/** Content and styles are held to this many bytes. */
	function d5b_wtpl_max_bytes(): int {
		return 1048576;
	}

	/** The refusal for a call while the Templates risky switch is off (the registry hides the abilities too). */
	function d5b_wtpl_gate(): ?WP_Error {
		return d5b_abilities_risky_on( 'templates' ) ? null : new WP_Error( 'not_allowed', 'Changing templates and global styles is a risky action and the Templates risky switch is off.' );
	}

	function d5b_wtpl_unknown( array $in, array $allowed ): ?WP_Error {
		foreach ( array_keys( $in ) as $k ) {
			if ( ! in_array( $k, $allowed, true ) ) {
				return new WP_Error( 'invalid_input', 'Unknown input "' . substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k ), 0, 100 ) . '". Allowed: ' . implode( ', ', $allowed ) . '.' );
			}
		}
		return null;
	}

	function d5b_wtpl_json( $v ): string {
		$j = function_exists( 'wp_json_encode' ) ? wp_json_encode( $v ) : json_encode( $v );
		return is_string( $j ) ? $j : '';
	}

	/** The expect_hash input, checked against $current (md5, any case). @return true|WP_Error */
	function d5b_wtpl_check_hash( $expect, string $current, string $where ) {
		if ( ! is_string( $expect ) || '' === $expect ) { return new WP_Error( 'invalid_input', 'expect_hash is required: read it first (' . $where . ') and pass its hash.' ); }
		$now = md5( $current );
		if ( hash_equals( $now, strtolower( $expect ) ) ) { return true; }
		return new WP_Error( 'stale', 'It changed since it was read (the hash does not match). Read it again (' . $where . '), apply the change to what is there now and pass the new hash.', array( 'hash' => $now ) );
	}

	function d5b_wtpl_can_update(): bool {
		return d5b_rs_ok( 'templates' ) && current_user_can( 'unfiltered_html' );
	}

	// ---- templates-update

	/**
	 * The template of the active theme with this id, of $type when given, else of either type; null when there is none.
	 * A template and a template part may share a slug (core keeps them apart by post type): without a type, an id that is
	 * both is ambiguous (fix wave M2). @return object|WP_Error|null
	 */
	function d5b_wtpl_find( string $id, ?string $type = null ) {
		if ( ! function_exists( 'get_block_template' ) ) { return null; }
		$theme = substr( $id, 0, (int) strpos( $id, '//' ) );
		if ( $theme !== get_stylesheet() ) { return null; }
		$hits = array();
		foreach ( null === $type ? array( 'wp_template', 'wp_template_part' ) : array( $type ) as $ty ) {
			$t = get_block_template( $id, $ty );
			if ( is_object( $t ) && (string) ( $t->id ?? '' ) === $id ) { $hits[ $ty ] = $t; }
		}
		if ( count( $hits ) > 1 ) {
			return new WP_Error( 'ambiguous', 'The active theme has both a template and a template part with the id "' . $id . '". Pass type: wp_template or wp_template_part.', array( 'types' => array_keys( $hits ) ) );
		}
		return $hits ? reset( $hits ) : null;
	}

	/** The post core's templates controller inserts for a theme-file template (prepare_item_for_database, update_item). */
	function d5b_wtpl_customise_post( $t, string $content ): array {
		$type = (string) $t->type;
		$arr  = array(
			'post_name' => (string) $t->slug, 'post_type' => $type, 'post_status' => 'publish',
			'tax_input' => array( 'wp_theme' => (string) $t->theme ), 'meta_input' => array( 'origin' => (string) $t->source ),
			'post_content' => $content, 'post_title' => (string) ( $t->title ?? '' ), 'post_excerpt' => (string) ( $t->description ?? '' ),
		);
		if ( 'wp_template_part' === $type ) {
			$area = (string) ( $t->area ?? '' );
			if ( '' !== $area && function_exists( '_filter_block_template_part_area' ) ) { $area = (string) _filter_block_template_part_area( $area ); }
			$arr['tax_input']['wp_template_part_area'] = '' !== $area ? $area : ( defined( 'WP_TEMPLATE_PART_AREA_UNCATEGORIZED' ) ? WP_TEMPLATE_PART_AREA_UNCATEGORIZED : 'uncategorized' );
		}
		return $arr;
	}

	function d5b_wtpl_h_update( array $in ) {
		$e = d5b_rs_guard( 'templates' ); if ( $e ) { return $e; }
		$e = d5b_wtpl_gate(); if ( $e ) { return $e; }
		$e = d5b_wtpl_unknown( $in, array( 'id', 'content', 'expect_hash', 'type' ) ); if ( $e ) { return $e; }
		$want = $in['type'] ?? null;
		if ( null !== $want && ! in_array( $want, array( 'wp_template', 'wp_template_part' ), true ) ) { return new WP_Error( 'invalid_input', 'type must be wp_template or wp_template_part.' ); }
		if ( ! d5b_rs_block_theme() ) { return d5b_rs_classic_note(); }
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return new WP_Error( 'needs_unfiltered_html', 'Templates are block markup, and saving them needs the unfiltered_html capability (an administrator on a single site). Without it WordPress would strip parts of the markup.' );
		}
		$id = $in['id'] ?? null;
		if ( ! is_string( $id ) || 1 !== preg_match( '#^[A-Za-z0-9_.\-]+//[A-Za-z0-9_.\-]+$#D', $id ) ) { return new WP_Error( 'invalid_input', 'id must look like theme//slug, as templates-list returns it.' ); }
		$content = $in['content'] ?? null;
		if ( ! is_string( $content ) ) { return new WP_Error( 'invalid_input', 'content is required: the whole new block markup of the template.' ); }
		if ( strlen( $content ) > d5b_wtpl_max_bytes() ) { return new WP_Error( 'invalid_input', 'content is too long: at most 1 MB.' ); }
		if ( 1 !== preg_match( '//u', $content ) ) { return new WP_Error( 'invalid_input', 'content must be text (UTF-8).' ); }
		$t = d5b_wtpl_find( $id, $want );
		if ( is_wp_error( $t ) ) { return $t; }
		if ( null === $t ) { return new WP_Error( 'not_found', 'The active theme has no ' . ( null === $want ? 'template or template part' : $want ) . ' with the id "' . $id . '". List them with templates-list.' ); }
		$cur    = (string) ( $t->content ?? '' );
		$custom = 'custom' === (string) ( $t->source ?? '' );
		$source = $custom ? 'custom' : (string) ( $t->source ?? 'theme' );
		$ok = d5b_wc_check_masked( $content, $cur ); if ( true !== $ok ) { return $ok; }
		$tree = d5b_blocks_parse( $content );
		if ( is_wp_error( $tree ) ) { return new WP_Error( 'invalid_input', 'content is not valid block markup: ' . $tree->get_error_message(), $tree->get_error_data() ); }
		$ok = d5b_wtpl_check_hash( $in['expect_hash'] ?? null, $cur, 'templates-get or templates-list' ); if ( true !== $ok ) { return $ok; }
		$type = (string) $t->type;
		$post = null; $rev = 0;
		if ( $custom ) {
			$post = get_post( (int) ( $t->wp_id ?? 0 ) );
			if ( ! is_object( $post ) || (string) $post->post_type !== $type ) { return new WP_Error( 'write_failed', 'WordPress lists this template as customised, but its post cannot be found.' ); }
		}
		if ( $cur === $content && $custom ) { return array( 'id' => $id, 'type' => $type, 'source' => 'custom', 'wp_id' => (int) $post->ID, 'hash' => md5( $cur ), 'created' => false, 'changed' => false ); }
		if ( $cur === $content ) { return array( 'id' => $id, 'type' => $type, 'source' => $source, 'wp_id' => 0, 'hash' => md5( $cur ), 'created' => false, 'changed' => false ); }
		$big = d5b_wc_check_content_size( $cur ); if ( true !== $big ) { return $big; }

		if ( $custom ) {
			$rev = d5b_wc_matching_revision( $post );
			$r   = wp_update_post( wp_slash( array( 'ID' => (int) $post->ID, 'post_content' => $content ) ), true );
		} else {
			$r = wp_insert_post( wp_slash( d5b_wtpl_customise_post( $t, $content ) ), true );
		}
		if ( is_wp_error( $r ) ) { return d5b_wc_core_error( $r ); }
		if ( ! $r ) { return new WP_Error( 'write_failed', 'WordPress did not save the template.' ); }
		$pid = (int) $r;

		// Read back: WordPress must now use that post for the id.
		$now = get_block_template( $id, $type );
		if ( ! is_object( $now ) || 'custom' !== (string) ( $now->source ?? '' ) || (int) ( $now->wp_id ?? 0 ) !== $pid ) {
			return new WP_Error( 'write_failed', 'The template was saved as post ' . $pid . ', but WordPress does not use that post for "' . $id . '" (its theme term may be missing). Check it in the Site Editor.', array( 'post_id' => $pid ) );
		}
		$after = (string) ( $now->content ?? '' );
		$out   = array( 'id' => $id, 'type' => $type, 'source' => 'custom', 'wp_id' => $pid, 'hash' => md5( $after ), 'created' => ! $custom, 'changed' => true );
		if ( $after !== $content ) { $out['warnings'] = array( 'WordPress stored the template differently from what was given (another plugin may change it); read it again.' ); }
		$p   = get_post( $pid );
		$obj = is_object( $p ) ? $p : (object) array( 'post_type' => $type, 'ID' => $pid );
		$ok  = d5b_wc_history( 'divi5-builder/templates-update', $obj, 'update', array( 'content' => $cur, 'source' => $source ),
			array( 'content_hash' => md5( $after ), 'source' => 'custom', 'created' => ! $custom ), $rev, 'Updated template ' . $id );
		if ( ! $ok ) { $out['_history_failed'] = true; }
		return $out;
	}

	// ---- global-styles-set

	/** The value with every object's keys sorted, every level: core's sanitising may reorder keys without removing anything. */
	function d5b_wtpl_canon( $v ) {
		if ( ! is_array( $v ) ) { return $v; }
		if ( array() !== $v && array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) { ksort( $v, SORT_STRING ); }
		foreach ( $v as $k => $x ) { $v[ $k ] = d5b_wtpl_canon( $x ); }
		return $v;
	}

	/**
	 * The first css value holding markup, at any depth (styles.css, styles.blocks.<block>.css, any other css key), as a
	 * dotted path; null when there is none. Core's controller checks styles.css only (validate_custom_css: #</?\w+#); a
	 * per-block css is printed in a style element too, so it gets the same check.
	 */
	function d5b_wtpl_css_markup( $v, string $path = '', int $depth = 0 ): ?string {
		if ( ! is_array( $v ) || $depth > 32 ) { return null; }
		foreach ( $v as $k => $x ) {
			$p = '' === $path ? (string) $k : $path . '.' . $k;
			if ( 'css' === $k && is_string( $x ) && 1 === preg_match( '#</?\w+#', $x ) ) { return $p; }
			$found = d5b_wtpl_css_markup( $x, $p, $depth + 1 );
			if ( null !== $found ) { return $found; }
		}
		return null;
	}

	/**
	 * The dotted paths of what $clean lost against $before: a key gone (an object gone whole is named by its leaves), or a
	 * value changed (a list counts whole). @return string[]
	 */
	function d5b_wtpl_lost_paths( $before, $clean, string $path = '', int $depth = 0 ): array {
		if ( ! is_array( $before ) ) { return array(); }
		$out  = array();
		$list = array() !== $before && array_keys( $before ) === range( 0, count( $before ) - 1 );
		if ( $list || $depth > 32 ) { return d5b_wtpl_json( d5b_wtpl_canon( $before ) ) === d5b_wtpl_json( d5b_wtpl_canon( $clean ) ) ? array() : array( '' === $path ? '(all)' : $path ); }
		foreach ( $before as $k => $v ) {
			$p = '' === $path ? (string) $k : $path . '.' . $k;
			if ( ! is_array( $clean ) || ! array_key_exists( $k, $clean ) ) {
				// gone whole: name its leaves (an object), or the key itself (a value or a list)
				$leaves = is_array( $v ) && array() !== $v && array_keys( $v ) !== range( 0, count( $v ) - 1 ) ? d5b_wtpl_lost_paths( $v, array(), $p, $depth + 1 ) : array();
				$out    = array_merge( $out, $leaves ? $leaves : array( $p ) );
				continue;
			}
			if ( is_array( $v ) ) { $out = array_merge( $out, d5b_wtpl_lost_paths( $v, $clean[ $k ], $p, $depth + 1 ) ); }
			elseif ( $v !== $clean[ $k ] ) { $out[] = $p; }
		}
		return $out;
	}

	/** The styles input as an array (the new user layer). @return array|WP_Error */
	function d5b_wtpl_styles_input( $v ) {
		if ( is_string( $v ) ) {
			if ( strlen( $v ) > d5b_wtpl_max_bytes() ) { return new WP_Error( 'invalid_input', 'styles is too long: at most 1 MB.' ); }
			if ( '{' !== substr( ltrim( $v ), 0, 1 ) ) { return new WP_Error( 'invalid_input', 'styles must be a JSON object.' ); }
			$v = json_decode( $v, true );
			if ( ! is_array( $v ) ) { return new WP_Error( 'invalid_input', 'styles is not valid JSON.' ); }
			return $v;
		}
		$v = d5b_wc_unobject( $v );
		if ( ! is_array( $v ) || ( array() !== $v && array_keys( $v ) === range( 0, count( $v ) - 1 ) ) ) { return new WP_Error( 'invalid_input', 'styles must be an object with styles and/or settings, as global-styles-get returns user.' ); }
		$enc = d5b_wtpl_json( $v );
		if ( '' === $enc ) { return new WP_Error( 'invalid_input', 'styles must be valid JSON data (UTF-8 text).' ); }
		if ( strlen( $enc ) > d5b_wtpl_max_bytes() ) { return new WP_Error( 'invalid_input', 'styles is too long: at most 1 MB once encoded.' ); }
		return $v;
	}

	function d5b_wtpl_h_styles_set( array $in ) {
		$e = d5b_rs_guard( 'templates' ); if ( $e ) { return $e; }
		$e = d5b_wtpl_gate(); if ( $e ) { return $e; }
		$e = d5b_wtpl_unknown( $in, array( 'styles', 'expect_hash' ) ); if ( $e ) { return $e; }
		if ( ! d5b_rs_block_theme() ) { return d5b_rs_classic_note(); }
		if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) || ! class_exists( 'WP_Theme_JSON' ) ) { return new WP_Error( 'unavailable', 'This WordPress has no global styles.' ); }
		if ( ! array_key_exists( 'styles', $in ) || null === $in['styles'] ) { return new WP_Error( 'invalid_input', 'styles is required: the new user layer, an object with styles and/or settings.' ); }
		$layer = d5b_wtpl_styles_input( $in['styles'] );
		if ( is_wp_error( $layer ) ) { return $layer; }
		$given = false;
		foreach ( $layer as $k => $v ) {
			if ( in_array( $k, array( 'version', 'isGlobalStylesUserThemeJSON' ), true ) ) { continue; } // what a read returns; the plugin sets both
			if ( ! in_array( $k, array( 'styles', 'settings' ), true ) ) { return new WP_Error( 'invalid_input', 'styles may hold only the keys styles and settings (and version and isGlobalStylesUserThemeJSON, which are set by the plugin), not "' . substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k ), 0, 100 ) . '".' ); }
			$given = true;
			if ( null === $v ) { continue; } // an explicit null removes that part of the layer
			if ( ! is_array( $v ) || ( array() !== $v && array_keys( $v ) === range( 0, count( $v ) - 1 ) ) ) { return new WP_Error( 'invalid_input', $k . ' must be an object (or null to remove it).' ); }
		}
		if ( ! $given ) { return new WP_Error( 'invalid_input', 'styles must hold styles and/or settings: a part left out keeps its stored value, null removes it.' ); }
		// core's controller: markup in the custom CSS is refused (validate_custom_css); here for every css key at any depth
		// (per-block css too, fix wave M3).
		$bad = d5b_wtpl_css_markup( $layer );
		if ( null !== $bad ) { return new WP_Error( 'invalid_input', 'Markup is not allowed in CSS (' . $bad . ').' ); }
		if ( ! d5b_wc_secret_free( $layer ) ) { return new WP_Error( 'invalid_input', 'styles holds a secret-named key; secrets are not written by an ability. Change it in the Site Editor.' ); }
		list( $found, $cur ) = d5b_rs_user_styles();
		// The stored layer: a value under a secret-named key is hidden on reading, would be lost or kept in the history in
		// full, so such a layer is not written over (as theme mods and widgets).
		$existing = '' === $cur ? array() : json_decode( $cur, true );
		if ( '' !== $cur && ! is_array( $existing ) ) { return new WP_Error( 'not_allowed', 'The stored global styles are not valid JSON; edit them in the Site Editor.' ); }
		if ( ! d5b_wc_secret_free( $existing ) ) { return new WP_Error( 'not_allowed', 'The stored global styles hold a value under a secret-named key; edit them in the Site Editor.' ); }
		$ok = d5b_wc_check_masked( d5b_wtpl_json( $layer ), $cur ); if ( true !== $ok ) { return $ok; }
		$ok = d5b_wtpl_check_hash( $in['expect_hash'] ?? null, $cur, 'global-styles-get user_hash' ); if ( true !== $ok ) { return $ok; }

		// As core's controller builds it (prepare_item_for_database: a part left out keeps the stored one, from a layer
		// that carries the isGlobalStylesUserThemeJSON flag). Unlike core, an explicit null removes a part (core keeps it).
		// Then, for a caller WITHOUT unfiltered_html only (core's kses_init adds wp_filter_global_styles_post only then),
		// as that filter cleans it: remove_insecure_properties. Whatever it would remove is refused, never dropped.
		if ( empty( $existing['isGlobalStylesUserThemeJSON'] ) ) { $existing = array(); }
		$config = array();
		foreach ( array( 'styles', 'settings' ) as $part ) {
			if ( array_key_exists( $part, $layer ) ) {
				if ( null !== $layer[ $part ] ) { $config[ $part ] = $layer[ $part ]; }
			} elseif ( isset( $existing[ $part ] ) ) {
				$config[ $part ] = $existing[ $part ];
			}
		}
		$config['version'] = WP_Theme_JSON::LATEST_SCHEMA;
		$clean = $config;
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$clean = WP_Theme_JSON::remove_insecure_properties( $config );
			if ( ! is_array( $clean ) ) { return new WP_Error( 'write_failed', 'WordPress could not sanitise the styles.' ); }
			if ( ! isset( $clean['version'] ) ) { $clean['version'] = WP_Theme_JSON::LATEST_SCHEMA; }
			$lost = d5b_wtpl_lost_paths( $config, $clean );
			if ( $lost ) {
				$more = count( $lost ) > 20 ? ' (and ' . ( count( $lost ) - 20 ) . ' more)' : '';
				return new WP_Error( 'invalid_input', 'Without unfiltered_html WordPress sanitises global styles and would remove: ' . implode( ', ', array_slice( $lost, 0, 20 ) ) . $more . '. Nothing was written. Leave these out (a stored part you did not send counts too: send it without them), or ask an administrator.', array( 'removed' => array_slice( $lost, 0, 100 ) ) );
			}
		}
		$clean['isGlobalStylesUserThemeJSON'] = true;
		$json = d5b_wtpl_json( $clean );
		if ( '' === $json ) { return new WP_Error( 'invalid_input', 'styles could not be encoded as JSON.' ); }
		// No change: the stored layer holds the same data (key order and JSON spelling aside).
		if ( '' !== $cur && is_array( $existing ) && array() !== $existing && d5b_wtpl_json( d5b_wtpl_canon( $clean ) ) === d5b_wtpl_json( d5b_wtpl_canon( json_decode( $cur, true ) ) ) ) {
			return array( 'id' => $found, 'user_hash' => md5( $cur ), 'created' => false, 'changed' => false );
		}
		$big = d5b_wc_check_content_size( $cur ); if ( true !== $big ) { return $big; }

		$pid = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$p   = $pid > 0 ? get_post( $pid ) : null;
		if ( ! is_object( $p ) || 'wp_global_styles' !== (string) $p->post_type || ( $found > 0 && $found !== $pid ) ) {
			return new WP_Error( 'write_failed', 'WordPress could not find or make the global styles post of the active theme.' );
		}
		$rev = $found > 0 ? d5b_wc_matching_revision( $p ) : 0;
		$r   = wp_update_post( wp_slash( array( 'ID' => $pid, 'post_content' => $json ) ), true );
		if ( is_wp_error( $r ) ) { return d5b_wc_core_error( $r ); }
		if ( ! $r ) { return new WP_Error( 'write_failed', 'WordPress did not save the global styles.' ); }

		list( , $after ) = d5b_rs_user_styles();
		$out  = array( 'id' => $pid, 'user_hash' => md5( $after ), 'created' => 0 === $found, 'changed' => true );
		$warn = array();
		if ( $after !== $json ) { $warn[] = 'WordPress stored the global styles differently from what was sent (another plugin may change them); read them again.'; }
		if ( $warn ) { $out['warnings'] = $warn; }
		$ok = d5b_wc_history( 'divi5-builder/global-styles-set', get_post( $pid ) ?: $p, 'update', array( 'json' => $cur ), array( 'json_hash' => md5( $after ), 'created' => 0 === $found ), $rev, 'Updated global styles' );
		if ( ! $ok ) { $out['_history_failed'] = true; }
		return $out;
	}

	// ---- the definitions

	d5b_wc_def( 'templates-update', 'templates', 'Update a block template',
		'Replaces the block markup of one template or template part of the active block theme. id is theme//slug as templates-list returns it; type (optional) is wp_template or wp_template_part, needed when a template and a template part share the id (ambiguous otherwise); content is the whole new markup (at most 1 MB) and must parse as blocks; expect_hash is the hash templates-get or templates-list returned (for a template the theme ships, the hash of the file\'s content); if it changed since, the answer is stale with the current hash. A risky action: needs the Templates risky switch, edit_theme_options and unfiltered_html (needs_unfiltered_html otherwise); a broken template can blank a page until it is put back. A template the theme ships becomes a customised template (a wp_template or wp_template_part post), as the Site Editor does. Refuses text holding [redacted] (masked_read). On a classic theme it answers is_block_theme false with a note and changes nothing. Returns id, type, source, wp_id, hash (pass it as the next expect_hash), created and changed. One change-history row keeps the previous content in full and where it came from.',
		array(
			'id'          => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 255, 'description' => 'The template id as templates-list returns it, like twentytwentyfive//home.' ),
			'content'     => array( 'type' => 'string', 'maxLength' => 1048576, 'description' => 'The whole new block markup, at most 1 MB.' ),
			'expect_hash' => array( 'type' => 'string', 'description' => 'hash from templates-get or templates-list (md5 of the current content).' ),
			'type'        => array( 'type' => 'string', 'enum' => array( 'wp_template', 'wp_template_part' ), 'description' => 'Optional: wp_template or wp_template_part; needed when a template and a template part share the id.' ),
		), array( 'id', 'content', 'expect_hash' ), 'd5b_wtpl_h_update', 'd5b_wtpl_can_update',
		array( 'idempotent' => true ), array( 'risky' => true ) );

	d5b_wc_def( 'global-styles-set', 'templates', 'Set the global styles',
		'Changes the site owner\'s layer of the global styles of the active block theme (the user layer only, never the theme\'s own theme.json, so theme updates still show through). styles is an object (or JSON text of one, at most 1 MB) with styles and/or settings, as global-styles-get returns user (its version and isGlobalStylesUserThemeJSON are accepted and set by the plugin). As in WordPress, each of styles and settings that is given replaces that part whole, and a part left out keeps its stored value; null removes a part. Markup in any css value is refused. For a caller without unfiltered_html the layer is sanitised as WordPress sanitises global styles for such users (settings keep only presets, blockGap and layout sizes; unsafe values and custom CSS go); a write that would lose anything that way is refused (invalid_input, data.removed lists the paths) and nothing is written. An administrator\'s styles are stored as given, as WordPress does. expect_hash is user_hash from global-styles-get; if the layer changed since, the answer is stale with the current hash. A risky action: needs the Templates risky switch and edit_theme_options. Refuses text holding [redacted] (masked_read), styles holding a secret-named key (invalid_input), and a stored layer holding a value under a secret-named key (not_allowed: global-styles-get shows it masked; edit it in the Site Editor). On a classic theme it answers is_block_theme false with a note and changes nothing. Returns id, user_hash (pass it as the next expect_hash), created and changed. One change-history row keeps the previous layer in full.',
		array(
			'styles'      => array( 'type' => array( 'object', 'string' ), 'description' => 'The new user layer: { "styles": {...}, "settings": {...} }, or that as JSON text.' ),
			'expect_hash' => array( 'type' => 'string', 'description' => 'user_hash from global-styles-get (md5 of the stored user layer).' ),
		), array( 'styles', 'expect_hash' ), 'd5b_wtpl_h_styles_set', d5b_rs_perm( 'templates' ),
		array( 'idempotent' => true ), array( 'risky' => true ) );

	d5b_ability_set_content_keys( array( 'templates-update', 'global-styles-set' ) );
}
