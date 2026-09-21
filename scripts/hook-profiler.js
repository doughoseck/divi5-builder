#!/usr/bin/env node
/**
 * Hook profiler: WHICH callback makes a WordPress / Divi page slow?  (read-only, temporary, key-gated)
 *
 *   node scripts/hook-profiler.js gen [out-dir]        -> writes d5b-profiler.php + d5b-profiler.key into out-dir (default: cwd)
 *   node scripts/hook-profiler.js run <page-url> [--key-file F] [--runs N]   -> loads the page with the key, prints the report
 *
 * The user uploads d5b-profiler.php to wp-content/mu-plugins/ and DELETES IT when done. Without ?d5bprof=<key> in the URL the file
 * does nothing at all (checked: no key and a wrong key register zero hooks). With the key it wraps every callback on wp,
 * template_redirect, wp_head, wp_enqueue_scripts, wp_footer, the_content, et_builder_render_layout and the block-render hooks with
 * a timer, and prints the slowest as ONE HTML comment at the end of the page: seconds, calls, PHP files loaded during the callback,
 * hook, priority, callback. It also reports who is attached to Divi's per-block parser hooks, runs two raw CPU benchmarks (compare:
 * about 0.35s and 0.10s on a 2024 desktop), times WordPress core's block parser against parse_blocks() as the site runs it, on the
 * page's real content and on four variants (no preset refs / current version stamps / both / no attributes), and step-times Divi's
 * enqueue_global_numeric_and_fonts_vars chain. Nothing is written or stored. Times are inclusive.
 *
 * Reading it: a callback with 0 new files and seconds of time is pure computation. If core's parser is milliseconds and
 * parse_blocks() is seconds, the cost is the theme's parser, not the server. See references/divi5-interactions-canvases.md.
 * Never commit the .key file or a generated .php (both are git-ignored).
 */
const fs = require('fs'), path = require('path'), crypto = require('crypto');
const [cmd, ...rest] = process.argv.slice(2); const flag = (n, d) => { const i = rest.indexOf('--' + n); return i >= 0 ? rest[i + 1] : d; };
if (cmd === 'run') { const url = rest.find(a => /^https?:/.test(a)); if (!url) { console.error('usage: hook-profiler.js run <page-url> [--key-file F] [--runs N]'); process.exit(2); }
  const key = fs.readFileSync(flag('key-file', path.join(process.cwd(), 'd5b-profiler.key')), 'utf8').trim(); const runs = Number(flag('runs', 2));
  (async () => { for (let i = 1; i <= runs; i++) { const t0 = Date.now(); const r = await fetch(url + (url.includes('?') ? '&' : '?') + 'd5bprof=' + key, { headers: { 'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126 Safari/537.36' } }); const h = await r.text();
      console.log(`\n===== run ${i}: HTTP ${r.status}  ${Date.now() - t0}ms  html ${Math.round(h.length / 1024)}KB`); if (i === runs) { const m = h.match(/<!-- D5BPROF([\s\S]*?)D5BPROF -->/); console.log(m ? m[1].split(key).join('<key>') : 'NO PROFILER REPORT IN THE PAGE (file not uploaded, or a page cache served it)'); } } })().catch(e => { console.error('ERROR ' + e.message); process.exit(1); });
  return; }
if (cmd !== 'gen') { console.log(fs.readFileSync(__filename, 'utf8').split('*/')[0].replace(/^#!.*\n\/\*\*\n?/, '').replace(/^ \* ?/gm, '')); process.exit(cmd ? 2 : 0); }
const outDir = path.resolve(rest[0] || process.cwd()); fs.mkdirSync(outDir, { recursive: true }); const key = crypto.randomBytes(12).toString('hex'); fs.writeFileSync(path.join(outDir, 'd5b-profiler.key'), key + '\n');
const php = `<?php
/**
 * Plugin Name: D5B temporary hook profiler  (DELETE AFTER USE)
 * Description: Read-only timing. Only acts when the URL carries ?d5bprof=<key>. Wraps every callback on a few hooks with a timer and
 * prints the slowest as an HTML comment at the very end of the page. Changes nothing, stores nothing. Remove this file when done.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! isset( \$_GET['d5bprof'] ) || ! hash_equals( '${key}', (string) \$_GET['d5bprof'] ) ) { return; }

\$GLOBALS['d5bprof'] = array( 'rows' => array(), 't0' => microtime( true ), 'depth' => 0 );

function d5bprof_name( \$cb ) {
	if ( is_string( \$cb ) ) { return \$cb; }
	if ( is_array( \$cb ) ) { return ( is_object( \$cb[0] ) ? get_class( \$cb[0] ) : (string) \$cb[0] ) . '::' . \$cb[1]; }
	if ( \$cb instanceof Closure ) { \$r = new ReflectionFunction( \$cb ); return 'closure ' . str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', wp_normalize_path( (string) \$r->getFileName() ) ) . ':' . \$r->getStartLine(); }
	return is_object( \$cb ) ? get_class( \$cb ) : 'callable';
}
function d5bprof_wrap( \$hook ) {
	global \$wp_filter;
	if ( empty( \$wp_filter[ \$hook ] ) ) { return; }
	foreach ( \$wp_filter[ \$hook ]->callbacks as \$prio => &\$cbs ) {
		foreach ( \$cbs as \$id => &\$cb ) {
			if ( ! empty( \$cb['d5bprof'] ) ) { continue; }
			\$orig = \$cb['function']; \$name = d5bprof_name( \$orig );
			\$cb['function'] = function () use ( \$orig, \$name, \$hook, \$prio ) {
				\$t = microtime( true ); \$f0 = count( get_included_files() ); \$GLOBALS['d5bprof']['depth']++;
				\$ret = call_user_func_array( \$orig, func_get_args() );
				\$GLOBALS['d5bprof']['depth']--;
				\$GLOBALS['d5bprof']['rows'][] = array( \$hook, \$prio, \$name, microtime( true ) - \$t, \$GLOBALS['d5bprof']['depth'], count( get_included_files() ) - \$f0 );
				return \$ret;
			};
			// accepted_args is left alone: WordPress then passes exactly what the original asked for (PHP 8 built-ins reject extras).
			\$cb['d5bprof'] = true;
		}
	}
}
// Wrap as late as possible before each hook fires, so callbacks added by the theme are included.
// The two hooks Divi's block parser fires for EVERY block it parses (BlockParser::_load_module_from_block_name).
\$GLOBALS['d5bprof_parser_hooks'] = array( 'divi_block_parser_block_to_class_map', 'divi_block_parser_before_load_module', 'divi_front_end_block_parser_reset_order_index', 'divi_front_end_block_parser_new_store_instance', 'block_parser_class' );
add_action( 'wp_loaded', function () { foreach ( array_merge( array( 'wp', 'template_redirect' ), \$GLOBALS['d5bprof_parser_hooks'] ) as \$h ) { d5bprof_wrap( \$h ); } }, PHP_INT_MAX );
add_action( 'template_redirect', function () { foreach ( array( 'wp_head', 'wp_footer', 'wp_enqueue_scripts', 'wp_print_styles', 'wp_print_footer_scripts', 'et_builder_render_layout', 'the_content', 'render_block_data', 'render_block', 'pre_render_block' ) as \$h ) { d5bprof_wrap( \$h ); } foreach ( \$GLOBALS['d5bprof_parser_hooks'] as \$h ) { d5bprof_wrap( \$h ); } }, -PHP_INT_MAX );
add_action( 'wp_head', function () { foreach ( array( 'wp_footer', 'wp_print_footer_scripts', 'et_builder_render_layout', 'the_content', 'render_block_data', 'render_block', 'pre_render_block' ) as \$h ) { d5bprof_wrap( \$h ); } }, PHP_INT_MAX );

// STEP TIMER: run the steps of FrontEnd::enqueue_global_numeric_and_fonts_vars ourselves, in its order, each with a stopwatch,
// just BEFORE Divi runs it (wp_footer priority 9). All are public static read-only getters with their own static caches, so
// Divi's own call afterwards should get fast: that drop is itself a confirmation. Any failure is caught and reported.
add_action( 'wp_footer', function () {
	\$S = array(); \$lap = function ( \$label, \$fn ) use ( &\$S ) { \$t = microtime( true ); try { \$r = \$fn(); } catch ( \\Throwable \$e ) { \$r = null; \$label .= ' [threw ' . get_class( \$e ) . ': ' . \$e->getMessage() . ']'; } \$S[] = array( \$label, microtime( true ) - \$t ); return \$r; };
	\$St = '\\\\ET\\\\Builder\\\\FrontEnd\\\\Module\\\\Style'; \$GD = '\\\\ET\\\\Builder\\\\Packages\\\\GlobalData\\\\GlobalData'; \$DU = '\\\\ET\\\\Builder\\\\FrontEnd\\\\Assets\\\\DynamicAssetsUtils'; \$OC = '\\\\ET\\\\Builder\\\\VisualBuilder\\\\OffCanvas\\\\OffCanvasHooks'; \$DF = '\\\\ET\\\\Builder\\\\FrontEnd\\\\Assets\\\\DetectFeature';
	if ( ! class_exists( \$St ) || ! class_exists( \$OC ) ) { \$GLOBALS['d5bprof']['steps'] = array( array( 'Divi 5 classes not found', 0 ) ); return; }
	\$lap( '1 Style::get_global_numeric_and_fonts_vars_style()', function () use ( \$St ) { return \$St::get_global_numeric_and_fonts_vars_style(); } );
	\$pid = \$lap( '2 Style::get_current_post_id_reverse()', function () use ( \$St ) { return \$St::get_current_post_id_reverse(); } );
	\$lap( '3 GlobalData::get_global_variables()', function () use ( \$GD ) { return \$GD::get_global_variables(); } );
	\$content = (string) get_post_field( 'post_content', \$pid );
	\$ids = \$lap( '4 DynamicAssetsUtils::get_theme_builder_template_ids()', function () use ( \$DU ) { return \$DU::get_theme_builder_template_ids(); } );
	foreach ( (array) \$ids as \$id ) { \$p = get_post( (int) \$id ); if ( \$p && ! empty( \$p->post_content ) ) { \$content .= ' ' . \$p->post_content; } }
	\$S[] = array( '  (post id ' . \$pid . ', template ids ' . implode( ',', (array) \$ids ) . ', content to scan ' . strlen( \$content ) . ' bytes)', 0 );
	// THE SPLIT: same content, same request. Raw CPU, WordPress core's parser, then parse_blocks() (= whatever parser class the site uses).
	\$lap( 'CPU benchmark: 2,000,000 x md5 of a short string', function () { \$x = 'a'; for ( \$i = 0; \$i < 2000000; \$i++ ) { \$x = md5( \$x ); } return \$x; } );
	\$lap( 'CPU benchmark: json_decode + json_encode of 100KB x 200', function () use ( \$content ) { \$j = wp_json_encode( array_fill( 0, 400, array( 'a' => str_repeat( 'x', 200 ), 'b' => array( 1, 2, 3 ) ) ) ); for ( \$i = 0; \$i < 200; \$i++ ) { \$d = json_decode( \$j, true ); \$j = wp_json_encode( \$d ); } return strlen( \$j ); } );
	\$lap( 'WP_Block_Parser (WordPress CORE parser) on the ' . strlen( \$content ) . ' bytes', function () use ( \$content ) { \$p = new \\WP_Block_Parser(); return count( \$p->parse( \$content ) ); } );
	\$S[] = array( '  (parser class the site uses: ' . apply_filters( 'block_parser_class', 'WP_Block_Parser' ) . ')', 0 );
	\$lap( 'parse_blocks() on the same bytes, 1st call', function () use ( \$content ) { return count( parse_blocks( \$content ) ); } );
	\$lap( 'parse_blocks() on the same bytes, 2nd call', function () use ( \$content ) { return count( parse_blocks( \$content ) ); } );
	// WHAT in the content makes Divi's parser slow? Same bytes, one thing changed at a time. Nothing is saved anywhere.
	\$noPreset = preg_replace( '/,?"modulePreset":\\[[^\\]]*\\]/', '', \$content ); \$stamped = preg_replace( '/"builderVersion":"[^"]+"/', '"builderVersion":"5.13"', \$content );
	\$lap( 'variant A: every "modulePreset":[...] reference removed', function () use ( \$noPreset ) { return count( parse_blocks( \$noPreset ) ); } );
	\$lap( 'variant B: every builderVersion stamp set to 5.13', function () use ( \$stamped ) { return count( parse_blocks( \$stamped ) ); } );
	\$lap( 'variant C: A and B together', function () use ( \$noPreset ) { return count( parse_blocks( preg_replace( '/"builderVersion":"[^"]+"/', '"builderVersion":"5.13"', \$noPreset ) ) ); } );
	\$lap( 'variant D: every block keeps its name and nesting but has NO attributes', function () use ( \$content ) { return count( parse_blocks( preg_replace( '/(<!--\\s+wp:divi\\/[a-z-]+)\\s+\\{.*?\\}(\\s+\\/?-->)/s', '\$1\$2', \$content ) ) ); } );
	foreach ( (array) \$ids as \$tid ) { \$tc = (string) get_post_field( 'post_content', (int) \$tid ); \$lap( 'variant E: only Theme Builder layout ' . (int) \$tid . ' (' . strlen( \$tc ) . ' bytes)', function () use ( \$tc ) { return count( parse_blocks( \$tc ) ); } ); }
	\$lap( 'variant F: only the PAGE body (' . strlen( (string) get_post_field( 'post_content', \$pid ) ) . ' bytes)', function () use ( \$pid ) { return count( parse_blocks( (string) get_post_field( 'post_content', \$pid ) ) ); } );
	\$lap( '5a OffCanvasHooks::extract_interaction_target_ids_from_content(content)', function () use ( \$OC, \$content ) { return \$OC::extract_interaction_target_ids_from_content( \$content ); } );
	\$lap( '5b DynamicAssetsUtils::get_all_canvas_data_for_post(post, content)', function () use ( \$DU, \$pid, \$content ) { return \$DU::get_all_canvas_data_for_post( (int) \$pid, \$content ); } );
	\$canvas = \$lap( '5  OffCanvasHooks::get_all_appended_canvas_content_for_post_and_templates()', function () use ( \$OC, \$pid, \$content ) { return \$OC::get_all_appended_canvas_content_for_post_and_templates( (int) \$pid, \$content ); } );
	\$all = \$content . ' ' . (string) \$canvas;
	\$lap( '6a DetectFeature::get_block_preset_ids(content)', function () use ( \$DF, \$all ) { return \$DF::get_block_preset_ids( \$all ); } );
	\$lap( '6b DetectFeature::get_group_preset_ids(content)', function () use ( \$DF, \$all ) { return \$DF::get_group_preset_ids( \$all ); } );
	\$lap( '6  DetectFeature::get_page_global_variable_ids(content + canvas)', function () use ( \$DF, \$all ) { return \$DF::get_page_global_variable_ids( \$all ); } );
	\$GLOBALS['d5bprof']['steps'] = \$S;
}, 9 );

add_action( 'shutdown', function () {
	\$rows = \$GLOBALS['d5bprof']['rows']; \$sum = array();
	foreach ( \$rows as \$r ) { \$k = \$r[0] . ' | prio ' . \$r[1] . ' | ' . \$r[2]; if ( ! isset( \$sum[ \$k ] ) ) { \$sum[ \$k ] = array( 0, 0.0, 0 ); } \$sum[ \$k ][0]++; \$sum[ \$k ][1] += \$r[3]; \$sum[ \$k ][2] += \$r[5]; }
	uasort( \$sum, function ( \$a, \$b ) { return \$b[1] <=> \$a[1]; } );
	echo "\\n<!-- D5BPROF total " . round( microtime( true ) - \$GLOBALS['d5bprof']['t0'], 3 ) . "s since plugin load | PHP " . PHP_VERSION . " | files included this request: " . count( get_included_files() ) . "\\n";
	\$oc = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : null;
	if ( is_array( \$oc ) ) { \$m = \$oc['memory_usage']; \$s = \$oc['opcache_statistics']; \$cfg = function_exists( 'opcache_get_configuration' ) ? @opcache_get_configuration() : array(); \$d = isset( \$cfg['directives'] ) ? \$cfg['directives'] : array();
		echo 'OPCACHE enabled=' . var_export( \$oc['opcache_enabled'], true ) . ' cache_full=' . var_export( \$oc['cache_full'], true ) . ' restart_pending=' . var_export( \$oc['restart_pending'], true )
			. ' | memory used ' . round( \$m['used_memory'] / 1048576 ) . 'MB free ' . round( \$m['free_memory'] / 1048576 ) . 'MB wasted ' . round( \$m['wasted_memory'] / 1048576 ) . 'MB'
			. ' | scripts cached ' . \$s['num_cached_scripts'] . ' of max ' . \$s['max_cached_keys'] . ' keys | hits ' . \$s['hits'] . ' misses ' . \$s['misses'] . ' hit rate ' . round( \$s['opcache_hit_rate'], 1 ) . '%'
			. ' | oom_restarts ' . \$s['oom_restarts'] . ' hash_restarts ' . \$s['hash_restarts'] . ' manual_restarts ' . \$s['manual_restarts']
			. ' | memory_consumption=' . ( isset( \$d['opcache.memory_consumption'] ) ? round( \$d['opcache.memory_consumption'] / 1048576 ) . 'MB' : '?' ) . ' max_accelerated_files=' . ( isset( \$d['opcache.max_accelerated_files'] ) ? \$d['opcache.max_accelerated_files'] : '?' ) . ' validate_timestamps=' . ( isset( \$d['opcache.validate_timestamps'] ) ? var_export( \$d['opcache.validate_timestamps'], true ) : '?' ) . ' revalidate_freq=' . ( isset( \$d['opcache.revalidate_freq'] ) ? \$d['opcache.revalidate_freq'] : '?' ) . "\\n";
	} else { echo "OPCACHE status not available (function disabled or opcache off): " . ( function_exists( 'opcache_get_status' ) ? 'opcache_get_status() returned false' : 'no opcache_get_status()' ) . ' | opcache.enable ini=' . ini_get( 'opcache.enable' ) . "\\n"; }
	echo "seconds   calls   new-files  hook | priority | callback\\n";
	\$i = 0; foreach ( \$sum as \$k => \$v ) { if ( \$v[1] < 0.02 || ++\$i > 45 ) { break; } echo str_pad( number_format( \$v[1], 3 ) . 's', 10 ) . str_pad( 'x' . \$v[0], 8 ) . str_pad( (string) \$v[2], 11 ) . str_replace( '--', '- -', \$k ) . "\\n"; }
	echo "PARSER HOOKS (fired once per block parsed). Who is attached, how often it ran, total time:\\n";
	foreach ( \$GLOBALS['d5bprof_parser_hooks'] as \$h ) { \$n = 0; \$tt = 0.0; \$who = array(); foreach ( \$rows as \$r ) { if ( \$r[0] === \$h ) { \$n++; \$tt += \$r[3]; \$who[ \$r[2] ] = ( isset( \$who[ \$r[2] ] ) ? \$who[ \$r[2] ] : 0 ) + \$r[3]; } }
		global \$wp_filter; \$attached = empty( \$wp_filter[ \$h ] ) ? 0 : array_sum( array_map( 'count', \$wp_filter[ \$h ]->callbacks ) );
		echo str_pad( number_format( \$tt, 3 ) . 's', 10 ) . str_pad( 'x' . \$n, 8 ) . \$h . '  (callbacks attached now: ' . \$attached . ')' . "\\n"; arsort( \$who ); foreach ( \$who as \$name => \$t ) { echo '            ' . number_format( \$t, 3 ) . 's  ' . str_replace( '--', '- -', \$name ) . "\\n"; } }
	if ( ! empty( \$GLOBALS['d5bprof']['steps'] ) ) { echo "STEPS of enqueue_global_numeric_and_fonts_vars, run by the profiler just before Divi's own call:\\n"; foreach ( \$GLOBALS['d5bprof']['steps'] as \$s ) { echo str_pad( \$s[1] > 0 ? number_format( \$s[1], 3 ) . 's' : '', 10 ) . str_replace( '--', '- -', \$s[0] ) . "\\n"; } }
	echo "(times are INCLUSIVE: a callback that renders blocks includes the block callbacks listed under it)\\nD5BPROF -->";
}, PHP_INT_MAX );
`;
fs.writeFileSync(path.join(outDir, 'd5b-profiler.php'), php);
console.log('written ' + path.join(outDir, 'd5b-profiler.php') + ' (' + php.length + ' bytes) and d5b-profiler.key\nUpload the .php to wp-content/mu-plugins/, run: node hook-profiler.js run <page-url> --key-file ' + path.join(outDir, 'd5b-profiler.key') + '\nDELETE the .php from the site when done.');
