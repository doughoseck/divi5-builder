<?php
/**
 * Plugin Name: WP Media Quarantine (moves files, reversible)
 * Description: For the divi5-builder skill's media-quarantine.js. MOVES named files out of the uploads folder into wp-content/media-audit-quarantine/<batch>/ and can move them back. It never deletes a file and never touches the database: a quarantined media library item keeps its row and simply shows as a broken image until it is restored. Administrators only. Remove this file when the clean-up is done.
 * Version: 1.0.0
 * Author: divi5-builder skill
 *
 * INSTALL: copy this file to wp-content/mu-plugins/wp-media-quarantine.php
 * REMOVE:  delete the file. The quarantine folder stays until YOU delete it (that is the permanent step) or restore it.
 * Needs PHP 7.0 or newer.
 *
 * Routes (namespace wp-media-quarantine/v1, all need the manage_options capability):
 *   POST /move     { batch, files: [paths relative to uploads], dry_run }   at most 1000 files per call
 *   POST /restore  { batch, files: [...] | all: true, dry_run }             at most 1000 files per call
 *   GET  /batches                                                           what is in quarantine
 *
 * Every batch folder has a manifest.json: each file, its size, when it was moved and whether it was restored.
 * The quarantine folder gets an .htaccess that refuses all web requests (Apache; on nginx block it in the server config).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'WPMQ_VERSION' ) ) { define( 'WPMQ_VERSION', '1.0.0' ); }

if ( ! function_exists( 'wpmq_clean_rel' ) ) :

	// A path relative to the uploads folder, or null if it is not a plain path inside it.
	function wpmq_clean_rel( $rel ) {
		if ( ! is_string( $rel ) ) { return null; }
		$rel = str_replace( '\\', '/', $rel );
		if ( '' === $rel || strlen( $rel ) > 500 || '/' === $rel[0] || false !== strpos( $rel, "\0" ) || preg_match( '#(^|/)\.\.?(/|$)#', $rel ) || false !== strpos( $rel, '//' ) || preg_match( '#^[A-Za-z]:#', $rel ) ) { return null; }
		return $rel;
	}

	function wpmq_batch_name( $b ) {
		return is_string( $b ) && preg_match( '/^[a-z0-9][a-z0-9\-]{0,39}$/', $b ) ? $b : null;
	}

	function wpmq_uploads() {
		$u = wp_upload_dir( null, false );
		return rtrim( str_replace( '\\', '/', (string) realpath( $u['basedir'] ) ), '/' );
	}

	function wpmq_root() {
		return rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/media-audit-quarantine';
	}

	// Makes the quarantine folder and closes it to the web.
	function wpmq_prepare_root() {
		$root = wpmq_root();
		if ( ! is_dir( $root ) && ! @mkdir( $root, 0755, true ) ) { return false; }
		if ( ! file_exists( $root . '/.htaccess' ) ) { @file_put_contents( $root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); }
		if ( ! file_exists( $root . '/index.php' ) ) { @file_put_contents( $root . '/index.php', "<?php // Silence is golden.\n" ); }
		return true;
	}

	function wpmq_manifest_read( $batch ) {
		$f = wpmq_root() . '/' . $batch . '/manifest.json';
		$m = file_exists( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : null;
		return is_array( $m ) && isset( $m['files'] ) && is_array( $m['files'] ) ? $m : array( 'batch' => $batch, 'created' => gmdate( 'c' ), 'files' => array() );
	}

	function wpmq_manifest_write( $batch, $m ) {
		$f   = wpmq_root() . '/' . $batch . '/manifest.json';
		$tmp = $f . '.tmp';
		if ( false === @file_put_contents( $tmp, json_encode( $m ) ) ) { return false; }
		return @rename( $tmp, $f );
	}

	// Is $full a real file inside $base (no links, nothing that resolves to somewhere else)?
	function wpmq_inside( $full, $base ) {
		if ( is_link( $full ) || ! is_file( $full ) ) { return false; }
		$real = str_replace( '\\', '/', (string) realpath( $full ) );
		return '' !== $real && 0 === strpos( $real, $base . '/' );
	}

	// Move files from uploads into the batch. Returns array( moved, skipped ). Never overwrites.
	function wpmq_move( $batch, $files, $dry ) {
		$up = wpmq_uploads(); $dest = wpmq_root() . '/' . $batch . '/files';
		$m  = wpmq_manifest_read( $batch ); $moved = array(); $skipped = array(); $bytes = 0;
		foreach ( $files as $raw ) {
			$rel = wpmq_clean_rel( $raw );
			if ( null === $rel ) { $skipped[] = array( is_string( $raw ) ? $raw : '?', 'not a plain path inside uploads' ); continue; }
			$src = $up . '/' . $rel;
			if ( ! file_exists( $src ) ) {
				$skipped[] = array( $rel, isset( $m['files'][ $rel ] ) && empty( $m['files'][ $rel ]['restored'] ) ? 'already in quarantine' : 'no such file' );
				continue;
			}
			if ( ! wpmq_inside( $src, $up ) ) { $skipped[] = array( $rel, 'not a regular file inside uploads' ); continue; }
			$dst = $dest . '/' . $rel;
			if ( file_exists( $dst ) ) { $skipped[] = array( $rel, 'a file of that name is already in this batch' ); continue; }
			$size = (int) filesize( $src ); $mtime = (int) filemtime( $src );
			if ( ! $dry ) {
				if ( ! is_dir( dirname( $dst ) ) && ! @mkdir( dirname( $dst ), 0755, true ) ) { $skipped[] = array( $rel, 'cannot make the folder in quarantine' ); continue; }
				if ( ! @rename( $src, $dst ) ) { $skipped[] = array( $rel, 'the move failed' ); continue; }
				$m['files'][ $rel ] = array( 'bytes' => $size, 'mtime' => $mtime, 'moved' => gmdate( 'c' ) );
			}
			$moved[] = $rel; $bytes += $size;
		}
		if ( ! $dry && $moved && ! wpmq_manifest_write( $batch, $m ) ) {
			return new WP_Error( 'manifest', 'files were moved but the manifest could not be written; they are in ' . $dest, array( 'status' => 500, 'moved' => $moved ) );
		}
		return array( 'moved' => $moved, 'bytes' => $bytes, 'skipped' => $skipped );
	}

	// Move files of a batch back to where they were. Never overwrites a file that exists in uploads.
	function wpmq_restore( $batch, $files, $dry ) {
		$up = wpmq_uploads(); $src_root = wpmq_root() . '/' . $batch . '/files';
		$m  = wpmq_manifest_read( $batch ); $restored = array(); $skipped = array(); $bytes = 0;
		foreach ( $files as $raw ) {
			$rel = wpmq_clean_rel( $raw );
			if ( null === $rel ) { $skipped[] = array( is_string( $raw ) ? $raw : '?', 'not a plain path' ); continue; }
			$src = $src_root . '/' . $rel;
			if ( ! is_file( $src ) || is_link( $src ) ) { $skipped[] = array( $rel, 'not in this batch' ); continue; }
			$dst = $up . '/' . $rel;
			if ( file_exists( $dst ) ) { $skipped[] = array( $rel, 'a file of that name exists in uploads again' ); continue; }
			$size = (int) filesize( $src );
			if ( ! $dry ) {
				if ( ! is_dir( dirname( $dst ) ) && ! @mkdir( dirname( $dst ), 0755, true ) ) { $skipped[] = array( $rel, 'cannot make the folder in uploads' ); continue; }
				if ( ! @rename( $src, $dst ) ) { $skipped[] = array( $rel, 'the move failed' ); continue; }
				if ( isset( $m['files'][ $rel ] ) ) { $m['files'][ $rel ]['restored'] = gmdate( 'c' ); }
				if ( isset( $m['files'][ $rel ]['mtime'] ) ) { @touch( $dst, (int) $m['files'][ $rel ]['mtime'] ); }
			}
			$restored[] = $rel; $bytes += $size;
		}
		if ( ! $dry && $restored ) { wpmq_manifest_write( $batch, $m ); }
		return array( 'restored' => $restored, 'bytes' => $bytes, 'skipped' => $skipped );
	}

	function wpmq_batches() {
		$root = wpmq_root(); $out = array();
		foreach ( is_dir( $root ) ? (array) scandir( $root ) : array() as $b ) {
			if ( null === wpmq_batch_name( $b ) || ! is_dir( $root . '/' . $b ) ) { continue; }
			$m = wpmq_manifest_read( $b ); $in = 0; $bytes = 0; $back = 0;
			foreach ( $m['files'] as $f ) { if ( empty( $f['restored'] ) ) { $in++; $bytes += (int) $f['bytes']; } else { $back++; } }
			$out[] = array( 'batch' => $b, 'created' => $m['created'], 'inQuarantine' => $in, 'bytes' => $bytes, 'restored' => $back );
		}
		return $out;
	}

	add_action( 'rest_api_init', function () {
		$perm = function () { return current_user_can( 'manage_options' ); };
		$ns   = 'wp-media-quarantine/v1';
		$args = function ( $req ) {
			$batch = wpmq_batch_name( $req->get_param( 'batch' ) );
			if ( null === $batch ) { return new WP_Error( 'bad_batch', 'batch: 1 to 40 of a-z, 0-9 and -', array( 'status' => 400 ) ); }
			$files = $req->get_param( 'files' );
			return array( $batch, is_array( $files ) ? array_values( $files ) : array(), (bool) $req->get_param( 'dry_run' ) );
		};

		register_rest_route( $ns, '/move', array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( $req ) use ( $args ) {
				$a = $args( $req );
				if ( is_wp_error( $a ) ) { return $a; }
				list( $batch, $files, $dry ) = $a;
				if ( ! $files || count( $files ) > 1000 ) { return new WP_Error( 'bad_files', 'files: 1 to 1000 paths relative to uploads', array( 'status' => 400 ) ); }
				if ( ! $dry && ! wpmq_prepare_root() ) { return new WP_Error( 'no_root', 'cannot create wp-content/media-audit-quarantine', array( 'status' => 500 ) ); }
				$r = wpmq_move( $batch, $files, $dry );
				return is_wp_error( $r ) ? $r : array( 'ok' => true, 'batch' => $batch, 'dryRun' => $dry ) + $r;
			},
		) );

		register_rest_route( $ns, '/restore', array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( $req ) use ( $args ) {
				$a = $args( $req );
				if ( is_wp_error( $a ) ) { return $a; }
				list( $batch, $files, $dry ) = $a;
				$left = 0;
				if ( $req->get_param( 'all' ) ) {
					$m = wpmq_manifest_read( $batch ); $files = array();
					foreach ( $m['files'] as $rel => $f ) { if ( empty( $f['restored'] ) ) { if ( count( $files ) < 1000 ) { $files[] = $rel; } else { $left++; } } }
				}
				if ( count( $files ) > 1000 ) { return new WP_Error( 'bad_files', 'at most 1000 files per call', array( 'status' => 400 ) ); }
				return array( 'ok' => true, 'batch' => $batch, 'dryRun' => $dry, 'more' => $left ) + wpmq_restore( $batch, $files, $dry );
			},
		) );

		register_rest_route( $ns, '/batches', array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function () { return array( 'ok' => true, 'version' => WPMQ_VERSION, 'folder' => 'wp-content/media-audit-quarantine', 'batches' => wpmq_batches() ); },
		) );
	} );

endif;
