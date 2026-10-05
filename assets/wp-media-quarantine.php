<?php
/**
 * Plugin Name: WP Media Quarantine (moves files, reversible)
 * Description: For the divi5-builder skill's media-quarantine.js. MOVES named files out of the uploads folder into wp-content/media-audit-quarantine-<random>/<batch>/ and can move them back. It never deletes a file and never touches the database: a quarantined media library item keeps its row and simply shows as a broken image until it is restored. Administrators only (network admins on multisite). Remove this file when the clean-up is done.
 * Version: 1.0.1
 * Author: divi5-builder skill
 *
 * INSTALL: copy this file to wp-content/mu-plugins/wp-media-quarantine.php
 * REMOVE:  delete the file. The quarantine folder stays until YOU delete it (that is the permanent step) or restore it.
 * Needs PHP 7.0 or newer.
 *
 * 1.0.1 (security): the folder name has a random part (media-audit-quarantine-<24 hex>) so it cannot
 * be guessed where the .htaccess below does not apply (nginx); dot files such as uploads/.htaccess are
 * never moved; restore checks real paths like move does; on multisite a network admin is required.
 * A 1.0.0 folder (plain media-audit-quarantine) is still listed and restorable, never added to.
 * On nginx you can also deny the folder in the server config:  location ~ /media-audit-quarantine { deny all; }
 *
 * Routes (namespace wp-media-quarantine/v1, all need manage_options, or manage_network_options on multisite):
 *   POST /move     { batch, files: [paths relative to uploads], dry_run }   at most 1000 files per call
 *   POST /restore  { batch, files: [...] | all: true, dry_run }             at most 1000 files per call
 *   GET  /batches                                                           what is in quarantine
 *
 * Every batch folder has a manifest.json: each file, its size, when it was moved and whether it was restored.
 * The quarantine folder gets an .htaccess that refuses all web requests (Apache; on nginx block it in the server config).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'WPMQ_VERSION' ) ) { define( 'WPMQ_VERSION', '1.0.1' ); }

if ( ! function_exists( 'wpmq_clean_rel' ) ) :

	// A path relative to the uploads folder, or null if it is not a plain path inside it.
	// 1.0.1: a name starting with a dot (.htaccess, .user.ini, .well-known/...) is refused
	// for MOVES: uploads/.htaccess is often a security plugin's "no PHP here" rule, and
	// moving it away silently removes that protection. Restore may bring one back.
	function wpmq_clean_rel( $rel, $allow_dot = false ) {
		if ( ! is_string( $rel ) ) { return null; }
		$rel = str_replace( '\\', '/', $rel );
		if ( '' === $rel || strlen( $rel ) > 500 || '/' === $rel[0] || false !== strpos( $rel, "\0" ) || preg_match( '#(^|/)\.\.?(/|$)#', $rel ) || false !== strpos( $rel, '//' ) || preg_match( '#^[A-Za-z]:#', $rel ) ) { return null; }
		if ( ! $allow_dot && preg_match( '#(^|/)\.#', $rel ) ) { return null; }
		return $rel;
	}

	function wpmq_batch_name( $b ) {
		return is_string( $b ) && preg_match( '/^[a-z0-9][a-z0-9\-]{0,39}$/', $b ) ? $b : null;
	}

	function wpmq_norm( $p ) { return rtrim( str_replace( '\\', '/', (string) $p ), '/' ); }

	function wpmq_uploads() {
		$u = wp_upload_dir( null, false );
		return wpmq_norm( realpath( $u['basedir'] ) );
	}

	// 1.0.1: the quarantine folder has an UNGUESSABLE name, media-audit-quarantine-<24 hex>.
	// 1.0.0 used plain "media-audit-quarantine", protected only by an Apache .htaccess,
	// so on nginx (or Apache without AllowOverride) the moved files and manifest.json
	// could be downloaded at a guessable URL. The name is found on disk, so nothing is
	// stored: a site has one such folder, created on the first real move.
	function wpmq_root() {
		static $root = null;
		if ( null !== $root ) { return $root; }
		$base  = wpmq_norm( WP_CONTENT_DIR );
		$found = array();
		foreach ( (array) @scandir( $base ) as $d ) {
			if ( preg_match( '/^media-audit-quarantine-[a-f0-9]{24}$/', (string) $d ) && is_dir( $base . '/' . $d ) && ! is_link( $base . '/' . $d ) ) { $found[] = $d; }
		}
		sort( $found );
		$root = $base . '/' . ( $found ? $found[0] : 'media-audit-quarantine-' . bin2hex( random_bytes( 12 ) ) );
		return $root;
	}

	// The 1.0.0 folder. Its batches can still be listed and restored, never added to.
	function wpmq_legacy_root() { return wpmq_norm( WP_CONTENT_DIR ) . '/media-audit-quarantine'; }

	// Where a batch lives: in the current folder, or (if only there) in the 1.0.0 one.
	function wpmq_batch_dir( $batch ) {
		$new = wpmq_root() . '/' . $batch;
		$old = wpmq_legacy_root() . '/' . $batch;
		return ( ! is_dir( $new ) && is_dir( $old ) && ! is_link( $old ) ) ? $old : $new;
	}

	// Is directory $dir (which may not exist yet) inside $base, once every link is resolved?
	// Walks up to the nearest existing folder and resolves that, so a planted symlink
	// anywhere on the way cannot send a move or restore somewhere else.
	function wpmq_dir_inside( $dir, $base ) {
		$base = wpmq_norm( realpath( $base ) );
		if ( '' === $base ) { return false; }
		// realpath() (not file_exists/is_dir) decides "exists": on Windows a junction answers
		// false to is_dir() and is_link() yet realpath() follows it, and that is the case to catch.
		$d = wpmq_norm( $dir );
		while ( '' !== $d && false === realpath( $d ) ) {
			$up = wpmq_norm( dirname( $d ) );
			if ( $up === $d ) { return false; }
			$d = $up;
		}
		$real = wpmq_norm( realpath( $d ) );
		return '' !== $real && ( $real === $base || 0 === strpos( $real . '/', $base . '/' ) );
	}

	// A quarantine root is only trusted if it resolves to a real folder directly inside
	// wp-content, so a root replaced by a link/junction cannot redirect everything below it.
	function wpmq_root_ok( $root ) {
		$real = wpmq_norm( realpath( $root ) );
		return '' !== $real && ! is_link( $root )
			&& wpmq_norm( dirname( $real ) ) === wpmq_norm( realpath( WP_CONTENT_DIR ) )
			&& basename( $real ) === basename( wpmq_norm( $root ) );
	}

	// Makes the quarantine folder and closes it to the web (Apache rule + no listing).
	function wpmq_prepare_root() {
		$root = wpmq_root();
		if ( false === realpath( $root ) && ! @mkdir( $root, 0755, true ) ) { return false; }
		if ( ! wpmq_root_ok( $root ) ) { return false; }
		if ( ! file_exists( $root . '/.htaccess' ) ) { @file_put_contents( $root . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); }
		if ( ! file_exists( $root . '/index.php' ) ) { @file_put_contents( $root . '/index.php', "<?php // Silence is golden.\n" ); }
		return true;
	}

	function wpmq_manifest_read( $batch ) {
		$f = wpmq_batch_dir( $batch ) . '/manifest.json';
		$m = file_exists( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : null;
		return is_array( $m ) && isset( $m['files'] ) && is_array( $m['files'] ) ? $m : array( 'batch' => $batch, 'created' => gmdate( 'c' ), 'files' => array() );
	}

	function wpmq_manifest_write( $batch, $m ) {
		$f   = wpmq_batch_dir( $batch ) . '/manifest.json';
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
		if ( wpmq_batch_dir( $batch ) !== wpmq_root() . '/' . $batch ) {
			return new WP_Error( 'legacy_batch', 'this batch is in the old (1.0.0) quarantine folder; restore it, or use a new batch name', array( 'status' => 409 ) );
		}
		$up = wpmq_uploads(); $dest = wpmq_root() . '/' . $batch . '/files';
		$m  = wpmq_manifest_read( $batch ); $moved = array(); $skipped = array(); $bytes = 0;
		foreach ( $files as $raw ) {
			$rel = wpmq_clean_rel( $raw );
			if ( null === $rel ) {
				$dot = null !== wpmq_clean_rel( $raw, true );
				$skipped[] = array( is_string( $raw ) ? $raw : '?', $dot ? 'a dot file or folder (e.g. .htaccess) is never moved' : 'not a plain path inside uploads' );
				continue;
			}
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
				if ( ! wpmq_dir_inside( dirname( $dst ), wpmq_root() ) ) { $skipped[] = array( $rel, 'the quarantine folder resolves outside quarantine (a link?)' ); continue; }
				if ( ! is_dir( dirname( $dst ) ) && ! @mkdir( dirname( $dst ), 0755, true ) ) { $skipped[] = array( $rel, 'cannot make the folder in quarantine' ); continue; }
				if ( ! wpmq_dir_inside( dirname( $dst ), wpmq_root() ) ) { $skipped[] = array( $rel, 'the quarantine folder resolves outside quarantine (a link?)' ); continue; }
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
		$up = wpmq_uploads(); $src_root = wpmq_batch_dir( $batch ) . '/files';
		$qroot = wpmq_norm( dirname( wpmq_batch_dir( $batch ) ) );
		// The batch's files folder must resolve inside a trusted quarantine root; else nothing is restored.
		$src_base = wpmq_root_ok( $qroot ) && wpmq_dir_inside( $src_root, $qroot ) ? wpmq_norm( realpath( $src_root ) ) : '';
		$m  = wpmq_manifest_read( $batch ); $restored = array(); $skipped = array(); $bytes = 0;
		foreach ( $files as $raw ) {
			// A dot file may come back (an .htaccess an older version moved restores protection).
			$rel = wpmq_clean_rel( $raw, true );
			if ( null === $rel ) { $skipped[] = array( is_string( $raw ) ? $raw : '?', 'not a plain path' ); continue; }
			$src = $src_root . '/' . $rel;
			// 1.0.1: the same containment as move: a real file whose real path is inside this
			// batch, so a link planted in quarantine cannot pull a file in from elsewhere.
			if ( '' === $src_base || ! wpmq_inside( $src, $src_base ) ) { $skipped[] = array( $rel, 'not in this batch' ); continue; }
			$dst = $up . '/' . $rel;
			if ( file_exists( $dst ) || is_link( $dst ) ) { $skipped[] = array( $rel, 'a file of that name exists in uploads again' ); continue; }
			$size = (int) filesize( $src );
			if ( ! $dry ) {
				// ... and the destination folder must resolve inside uploads, before and after it is made.
				if ( ! wpmq_dir_inside( dirname( $dst ), $up ) ) { $skipped[] = array( $rel, 'the folder in uploads resolves outside uploads (a link?)' ); continue; }
				if ( ! is_dir( dirname( $dst ) ) && ! @mkdir( dirname( $dst ), 0755, true ) ) { $skipped[] = array( $rel, 'cannot make the folder in uploads' ); continue; }
				if ( ! wpmq_dir_inside( dirname( $dst ), $up ) ) { $skipped[] = array( $rel, 'the folder in uploads resolves outside uploads (a link?)' ); continue; }
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
		$out = array(); $seen = array();
		foreach ( array( wpmq_root() => false, wpmq_legacy_root() => true ) as $root => $legacy ) {
			if ( ! wpmq_root_ok( $root ) ) { continue; }
			foreach ( (array) scandir( $root ) as $b ) {
				if ( null === wpmq_batch_name( $b ) || ! is_dir( $root . '/' . $b ) || isset( $seen[ $b ] ) ) { continue; }
				$seen[ $b ] = true;
				$m = wpmq_manifest_read( $b ); $in = 0; $bytes = 0; $back = 0;
				foreach ( $m['files'] as $f ) { if ( empty( $f['restored'] ) ) { $in++; $bytes += (int) $f['bytes']; } else { $back++; } }
				$row = array( 'batch' => $b, 'created' => $m['created'], 'inQuarantine' => $in, 'bytes' => $bytes, 'restored' => $back );
				if ( $legacy ) { $row['legacyFolder'] = true; $row['warning'] = 'in the old guessable folder wp-content/media-audit-quarantine: on nginx it may be downloadable. Restore it, or delete it when you are sure.'; }
				$out[] = $row;
			}
		}
		return $out;
	}

	add_action( 'rest_api_init', function () {
		// 1.0.1: on multisite the quarantine folder is shared by every site, and a subsite
		// admin has manage_options, so it needs a network admin there.
		$perm = function () { return current_user_can( function_exists( 'is_multisite' ) && is_multisite() ? 'manage_network_options' : 'manage_options' ); };
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
			'callback'            => function () { return array( 'ok' => true, 'version' => WPMQ_VERSION, 'folder' => is_dir( wpmq_root() ) ? 'wp-content/' . basename( wpmq_root() ) : null, 'batches' => wpmq_batches() ); },
		) );
	} );

endif;
