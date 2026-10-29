<?php
/**
 * The blocks engine (#32, 2.2.0): a PHP port of scripts/blocks.js, for divi-section-edit and divi-text-edit.
 * Pure functions on a string of post content: no WordPress function is called (WP_Error is the only WordPress name
 * used). This file only defines functions.
 *
 * Divi 5 stores a page as block comments: <!-- wp:divi/section {json} --> ... <!-- /wp:divi/section -->, or
 * <!-- wp:divi/x {json} /--> for a block with no inside, usually wrapped in <!-- wp:divi/placeholder -->.
 * The parser is the one in blocks.js, byte offset for byte offset:
 *   - a comment starts at "<!--" and ends at the FIRST "-->" after it. WordPress and Divi write "--" inside the
 *     attributes as two JSON escapes of U+002D, so a real attribute never holds "-->"; a raw "-->" in a hand-written attribute
 *     ends the comment there, exactly as blocks.js and WordPress read it.
 *   - the comment body must match ^\s+(/)?wp:<name>\s+({json}\s+)?(/)?$ (JavaScript's \s, end of string only),
 *     otherwise it is an ordinary HTML comment. A bare name is core/<name>.
 * Addresses are blocks.js addresses: 3, 3.2.1.4 (section, row, column, module; the placeholder is not counted),
 * label:<admin label>, #<css id>.
 *
 * A node is array( name, attrs, start, innerStart, innerEnd, end, selfClosing, addr, children ): byte offsets into the
 * source; start..end covers the opening comment to the end of the closing one; innerStart..innerEnd is the inside
 * (innerEnd is null for a self-closing block); addr is null for a block that is not a Divi block (the placeholder, or a
 * core block).
 *
 * Errors: invalid_input (does not parse, bad op, missing with, kind mismatch, the result would not parse),
 * not_found (nothing at the address), ambiguous (label: or #id matching more than one block; data.candidates).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_blocks_parse' ) ) {

	/** JavaScript's \s as bytes of UTF-8, so that the opener regex is the one blocks.js runs. */
	define( 'D5B_BLOCKS_WS', '(?:[\t\n\x0B\f\r ]|\xC2\xA0|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80|\xEF\xBB\xBF)' );

	/** The text-bearing elements of each module when there is no catalogue (and always, added to the catalogue's). */
	define( 'D5B_BLOCKS_TEXT_BUILTIN', array(
		'divi/heading'            => array( 'title' ),
		'divi/text'               => array( 'content' ),
		'divi/button'             => array( 'button' ),
		'divi/blurb'              => array( 'title', 'content' ),
		'divi/cta'                => array( 'title', 'content', 'button' ),
		'divi/toggle'             => array( 'title', 'closedTitle', 'content' ),
		'divi/accordion-item'     => array( 'title', 'content' ),
		'divi/testimonial'        => array( 'content', 'author', 'jobTitle', 'company' ),
		'divi/slide'              => array( 'title', 'content', 'button' ),
		'divi/tab'                => array( 'title', 'content' ),
		'divi/pricing-table'      => array( 'title', 'subtitle', 'price', 'content', 'button' ),
		'divi/number-counter'     => array( 'title' ),
		'divi/countdown-timer'    => array( 'title' ),
		'divi/team-member'        => array( 'name', 'position', 'content' ),
		'divi/map-pin'            => array( 'title', 'content' ),
		'divi/fullwidth-header'   => array( 'title', 'subhead', 'content', 'buttonOne', 'buttonTwo' ),
	) );

	/** @return WP_Error */
	function d5b_blocks_error( $code, $message, $data = null ) { return new WP_Error( $code, $message, $data ); }

	/** The UTF-16 length of a UTF-8 string: what JavaScript's .length and string offsets count. */
	function d5b_blocks_u16( $s ) {
		return strlen( $s ) - preg_match_all( '/[\x80-\xBF]/', $s ) + preg_match_all( '/[\xF0-\xF7]/', $s );
	}

	/**
	 * Match one comment body against the block grammar of blocks.js.
	 * @return array|null|false  array( closing, name, json, json offset in body, self ) ; null = ordinary comment ; false = regex failure.
	 */
	function d5b_blocks_match_opener( $body ) {
		$ws = D5B_BLOCKS_WS;
		$re = '/^' . $ws . '+(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)' . $ws . '+(\{[\s\S]*\}' . $ws . '+)?(\/)?\z/';
		$r  = preg_match( $re, $body, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL );
		if ( false === $r ) { return false; }
		if ( 0 === $r ) { return null; }
		$raw = $m[2][0];
		return array(
			'closing' => null !== $m[1][0],
			'name'    => false !== strpos( $raw, '/' ) ? $raw : 'core/' . $raw,
			'json'    => $m[3][0],
			'jsonAt'  => null !== $m[3][0] ? $m[3][1] : -1,
			'self'    => isset( $m[4] ) && null !== $m[4][0],
		);
	}

	/**
	 * The parse of blocks.js, flat: every node with its parent and children as indexes, plus every error. Never fails.
	 * @return array array( nodes, errors ); nodes[0] is the root.
	 */
	function d5b_blocks_parse_flat( $src ) {
		$len    = strlen( $src );
		$nodes  = array( array( 'name' => '#root', 'attrs' => null, 'start' => 0, 'innerStart' => 0, 'innerEnd' => $len, 'end' => $len, 'selfClosing' => false, 'addr' => null, 'children' => array(), 'parent' => null ) );
		$stack  = array( 0 );
		$errors = array();
		$i      = 0;
		while ( false !== ( $i = strpos( $src, '<!--', $i ) ) ) {
			$close = strpos( $src, '-->', $i + 4 );
			if ( false === $close ) { break; }
			$end = $close + 3;
			$m   = d5b_blocks_match_opener( substr( $src, $i + 4, $close - $i - 4 ) );
			if ( false === $m ) { $errors[] = 'the comment at char ' . d5b_blocks_u16( substr( $src, 0, $i ) ) . ' could not be read (regular expression limit)'; $i = $end; continue; }
			if ( null === $m ) { $i = $end; continue; }   // an ordinary HTML comment
			$name = $m['name'];
			if ( $m['closing'] ) {
				$top = $stack[ count( $stack ) - 1 ];
				if ( 0 === $top || $nodes[ $top ]['name'] !== $name ) {
					$errors[] = 'closing ' . $name . ' at char ' . d5b_blocks_u16( substr( $src, 0, $i ) ) . ' does not match the open ' . ( 0 === $top ? 'nothing' : $nodes[ $top ]['name'] );
					$i = $end; continue;
				}
				$nodes[ $top ]['end'] = $end; $nodes[ $top ]['innerEnd'] = $i;
				array_pop( $stack );
			} else {
				$attrs = null;
				if ( null !== $m['json'] ) {
					$attrs = json_decode( $m['json'], true, 100000 );
					if ( null === $attrs && JSON_ERROR_NONE !== json_last_error() ) {
						$errors[] = $name . ' at char ' . d5b_blocks_u16( substr( $src, 0, $i ) ) . ': its attributes are not valid JSON (' . json_last_error_msg() . ')';
						$attrs = null;
					}
				}
				$parent  = $stack[ count( $stack ) - 1 ];
				$idx     = count( $nodes );
				$nodes[] = array( 'name' => $name, 'attrs' => $attrs, 'start' => $i, 'innerStart' => $end, 'innerEnd' => null, 'end' => $m['self'] ? $end : -1, 'selfClosing' => $m['self'], 'addr' => null, 'children' => array(), 'parent' => $parent );
				$nodes[ $parent ]['children'][] = $idx;
				if ( ! $m['self'] ) { $stack[] = $idx; }
			}
			$i = $end;
		}
		foreach ( array_slice( $stack, 1 ) as $b ) { $errors[] = $nodes[ $b ]['name'] . ' opened at char ' . d5b_blocks_u16( substr( $src, 0, $nodes[ $b ]['start'] ) ) . ' is never closed'; }
		// addresses, as blocks.js walk() gives them
		$go = function ( $b, $addr ) use ( &$go, &$nodes ) {
			foreach ( d5b_blocks_divi_children_flat( $nodes, $b ) as $k => $c ) {
				$a = '' === $addr ? (string) ( $k + 1 ) : $addr . '.' . ( $k + 1 );
				$nodes[ $c ]['addr'] = $a;
				$go( $c, $a );
			}
		};
		$go( 0, '' );
		return array( $nodes, $errors );
	}

	/** The kind of a block name: section, row, column, placeholder, module or other (blocks.js kindOf). */
	function d5b_blocks_kind( $name ) {
		$n = preg_replace( '/-inner\z/', '', (string) $name );
		if ( 'divi/section' === $n ) { return 'section'; }
		if ( 'divi/row' === $n ) { return 'row'; }
		if ( 'divi/column' === $n ) { return 'column'; }
		if ( 'divi/placeholder' === $n ) { return 'placeholder'; }
		return 0 === strncmp( $n, 'divi/', 5 ) ? 'module' : 'other';
	}

	/** The Divi children of a flat node, looking through the placeholder and any other non-Divi wrapper. */
	function d5b_blocks_divi_children_flat( array $nodes, $b ) {
		$out = array();
		foreach ( $nodes[ $b ]['children'] as $c ) {
			$k = d5b_blocks_kind( $nodes[ $c ]['name'] );
			if ( 'placeholder' === $k || 'other' === $k ) { $out = array_merge( $out, d5b_blocks_divi_children_flat( $nodes, $c ) ); } else { $out[] = $c; }
		}
		return $out;
	}

	/** The same, on a nested node. */
	function d5b_blocks_divi_children( array $node ) {
		$out = array();
		foreach ( $node['children'] as $c ) {
			$k = d5b_blocks_kind( $c['name'] );
			if ( 'placeholder' === $k || 'other' === $k ) { $out = array_merge( $out, d5b_blocks_divi_children( $c ) ); } else { $out[] = $c; }
		}
		return $out;
	}

	function d5b_blocks_nest( array $nodes, $b ) {
		$n = $nodes[ $b ]; unset( $n['parent'] );
		$kids = array(); foreach ( $n['children'] as $c ) { $kids[] = d5b_blocks_nest( $nodes, $c ); }
		$n['children'] = $kids;
		return $n;
	}

	/** Every Divi block of a nested tree, in document order (blocks.js walk()). */
	function d5b_blocks_walk( array $node ) {
		$out = array();
		foreach ( $node['children'] as $c ) { if ( null !== $c['addr'] ) { $out[] = $c; } $out = array_merge( $out, d5b_blocks_walk( $c ) ); }
		return $out;
	}

	/**
	 * Parse post content into the block tree.
	 * @return array|WP_Error the root node; invalid_input with the first error as the message and all in data.errors.
	 */
	function d5b_blocks_parse( $src ) {
		list( $nodes, $errors ) = d5b_blocks_parse_flat( (string) $src );
		if ( $errors ) { return d5b_blocks_error( 'invalid_input', $errors[0], array( 'errors' => $errors ) ); }
		return d5b_blocks_nest( $nodes, 0 );
	}

	function d5b_blocks_desk_val( $o ) { return ( is_array( $o ) && isset( $o['desktop'] ) && is_array( $o['desktop'] ) && array_key_exists( 'value', $o['desktop'] ) ) ? $o['desktop']['value'] : null; }
	function d5b_blocks_label( array $b ) {
		$a = $b['attrs'];
		$v = ( is_array( $a ) && isset( $a['module']['meta'] ) && is_array( $a['module'] ) && is_array( $a['module']['meta'] ) && isset( $a['module']['meta']['adminLabel'] ) ) ? d5b_blocks_desk_val( $a['module']['meta']['adminLabel'] ) : null;
		return is_string( $v ) ? $v : '';
	}
	function d5b_blocks_css_id( array $b ) {
		$a = $b['attrs'];
		$v = ( is_array( $a ) && isset( $a['module'] ) && is_array( $a['module'] ) && isset( $a['module']['advanced'] ) && is_array( $a['module']['advanced'] ) && isset( $a['module']['advanced']['htmlAttributes'] ) ) ? d5b_blocks_desk_val( $a['module']['advanced']['htmlAttributes'] ) : null;
		return ( is_array( $v ) && isset( $v['id'] ) && is_string( $v['id'] ) ) ? $v['id'] : '';
	}

	/**
	 * Every Divi block with its address, as `blocks.js tree --json` lists them (also for a page that does not parse).
	 * @return array list of array( address, block, label?, cssId?, chars ); chars in UTF-16 units, as node counts them.
	 */
	function d5b_blocks_tree( $src ) {
		$src = (string) $src;
		list( $nodes ) = d5b_blocks_parse_flat( $src );
		$out = array();
		foreach ( d5b_blocks_walk( d5b_blocks_nest( $nodes, 0 ) ) as $b ) {
			$e = array( 'address' => $b['addr'], 'block' => $b['name'] );
			$l = d5b_blocks_label( $b ); if ( '' !== $l ) { $e['label'] = $l; }
			$c = d5b_blocks_css_id( $b ); if ( '' !== $c ) { $e['cssId'] = $c; }
			$e['chars'] = $b['end'] < 0 ? -1 - d5b_blocks_u16( substr( $src, 0, $b['start'] ) ) : d5b_blocks_u16( substr( $src, $b['start'], $b['end'] - $b['start'] ) );
			$out[] = $e;
		}
		return $out;
	}

	/**
	 * The one block at an address.
	 * @param array  $tree a root from d5b_blocks_parse()
	 * @return array|WP_Error the node; invalid_input (not an address), not_found, ambiguous (data.candidates).
	 */
	function d5b_blocks_resolve( array $tree, $at, $what = 'the page' ) {
		$s   = trim( (string) $at );
		$all = d5b_blocks_walk( $tree );
		if ( preg_match( '/^\d+(\.\d+)*\z/', $s ) ) { $hits = array_values( array_filter( $all, function ( $b ) use ( $s ) { return $b['addr'] === $s; } ) ); }
		elseif ( 0 === strncmp( $s, 'label:', 6 ) ) { $l = (string) substr( $s, 6 ); $hits = array_values( array_filter( $all, function ( $b ) use ( $l ) { return d5b_blocks_label( $b ) === $l; } ) ); }
		elseif ( 0 === strncmp( $s, '#', 1 ) ) { $id = (string) substr( $s, 1 ); $hits = array_values( array_filter( $all, function ( $b ) use ( $id ) { return d5b_blocks_css_id( $b ) === $id; } ) ); }
		else { return d5b_blocks_error( 'invalid_input', '"' . $at . '" is not an address: use 3.2.1, label:<admin label> or #<css id>' ); }
		if ( ! $hits ) { return d5b_blocks_error( 'not_found', 'nothing at "' . $at . '" in ' . $what . '. Run `blocks.js tree` (or read the tree) to see the addresses' ); }
		if ( count( $hits ) > 1 ) {
			$c = array_column( $hits, 'addr' );
			return d5b_blocks_error( 'ambiguous', '"' . $at . '" matches ' . count( $hits ) . ' blocks in ' . $what . ' (at ' . implode( ', ', $c ) . '): use one of those addresses', array( 'candidates' => $c ) );
		}
		return $hits[0];
	}

	/** The kind a child of this block has (blocks.js childKind). */
	function d5b_blocks_child_kind( array $b ) {
		if ( '#root' === $b['name'] ) { return 'section'; }
		$k = d5b_blocks_kind( $b['name'] );
		if ( 'section' === $k ) {
			foreach ( d5b_blocks_divi_children( $b ) as $c ) { if ( 'module' === d5b_blocks_kind( $c['name'] ) ) { return 'module'; } }
			return 'row';
		}
		$map = array( 'placeholder' => 'section', 'row' => 'column', 'column' => 'module' );
		return isset( $map[ $k ] ) ? $map[ $k ] : 'module';
	}

	/**
	 * Replace src[start..end) with insert, parse the result again, and prove that the text outside is the original.
	 * Every edit goes through here.
	 * @return string|WP_Error
	 */
	function d5b_blocks_splice( $src, $start, $end, $insert ) {
		$out = substr( $src, 0, $start ) . $insert . substr( $src, $end );
		list( , $errors ) = d5b_blocks_parse_flat( $out );
		if ( $errors ) { return d5b_blocks_error( 'invalid_input', "the edited page would not parse; nothing written:\n  " . implode( "\n  ", $errors ), array( 'errors' => $errors ) ); }
		if ( substr( $out, 0, $start ) !== substr( $src, 0, $start ) || substr( $out, $start + strlen( $insert ) ) !== substr( $src, $end ) ) { return d5b_blocks_error( 'invalid_input', 'internal: text outside the edit changed' ); }
		return $out;
	}

	/**
	 * Edit one block: replace, before, after, append (a new last child) or remove. Everything outside the addressed
	 * block stays byte for byte; the result is parsed again before it is returned.
	 * @param string|null $with  block markup (a compiled page is unwrapped); required unless $op is remove
	 * @param string|null $from  an address inside $with: take only that block (blocks.js --from)
	 * @return string|WP_Error
	 */
	function d5b_blocks_edit( $src, $op, $at, $with = null, $from = null ) {
		$src = (string) $src;
		if ( ! in_array( $op, array( 'replace', 'before', 'after', 'append', 'remove' ), true ) ) { return d5b_blocks_error( 'invalid_input', 'unknown operation "' . $op . '"' ); }
		$root = d5b_blocks_parse( $src );
		if ( is_wp_error( $root ) ) { return d5b_blocks_error( 'invalid_input', "the page does not parse, so it will not be edited:\n  " . implode( "\n  ", $root->get_error_data()['errors'] ), $root->get_error_data() ); }
		$t = d5b_blocks_resolve( $root, $at );
		if ( is_wp_error( $t ) ) { return $t; }
		$kind = d5b_blocks_kind( $t['name'] );
		$frag = '';
		if ( 'remove' !== $op ) {
			if ( null === $with ) { return d5b_blocks_error( 'invalid_input', $op . ' needs --with <file> (the `with` markup)' ); }
			$with = (string) $with;
			$fr   = d5b_blocks_parse( $with );
			if ( is_wp_error( $fr ) ) { return d5b_blocks_error( 'invalid_input', "the --with file does not parse:\n  " . implode( "\n  ", $fr->get_error_data()['errors'] ), $fr->get_error_data() ); }
			if ( null !== $from ) { $one = d5b_blocks_resolve( $fr, $from, 'the --with file' ); if ( is_wp_error( $one ) ) { return $one; } $blocks = array( $one ); }
			else { $blocks = d5b_blocks_divi_children( $fr ); }
			if ( ! $blocks ) { return d5b_blocks_error( 'invalid_input', 'the --with file has no Divi blocks' ); }
			$want = 'append' === $op ? d5b_blocks_child_kind( $t ) : $kind;
			if ( 'append' === $op && $t['selfClosing'] ) { return d5b_blocks_error( 'invalid_input', $t['name'] . ' at ' . $t['addr'] . ' has no inside to append to' ); }
			foreach ( $blocks as $b ) {
				$got = d5b_blocks_kind( $b['name'] );
				if ( ! ( $want === $got || ( 'module' === $want && 'row' === $got ) ) ) {   // a nested row sits where a module does
					$where = 'append' === $op ? 'inside' : ( 'replace' === $op ? 'in place of' : $op );
					return d5b_blocks_error( 'invalid_input', 'a ' . $got . ' (' . $b['name'] . ') cannot go ' . $where . ' a ' . $kind . ' (' . $t['name'] . ' at ' . $t['addr'] . '): it needs ' . ( 'module' === $want ? 'modules' : $want . 's' ) . '. Use --from to pick the right part of the file' );
				}
			}
			$parts = array(); foreach ( $blocks as $b ) { $parts[] = substr( $with, $b['start'], $b['end'] - $b['start'] ); }
			$frag = implode( "\n", $parts );
		}
		$len       = strlen( $src );
		$lineStart = 0 === $t['start'] || "\n" === $src[ $t['start'] - 1 ];
		$insert    = '';
		if ( 'replace' === $op ) { $start = $t['start']; $end = $t['end']; $insert = $frag; }
		elseif ( 'remove' === $op ) { $start = $t['start']; $end = $t['end']; if ( $lineStart && $end < $len && "\n" === $src[ $end ] ) { $end++; } }   // a block on its own line takes its line break with it
		elseif ( 'before' === $op ) { $start = $end = $t['start']; $insert = $frag . ( $lineStart ? "\n" : '' ); }
		elseif ( 'after' === $op ) { $start = $end = $t['end']; $insert = ( ( $end < $len && "\n" === $src[ $end ] ) || $end === $len ? "\n" : '' ) . $frag; }
		else { $start = $end = $t['innerEnd']; $insert = $frag . "\n"; if ( 0 === $start || "\n" !== $src[ $start - 1 ] ) { $insert = "\n" . $insert; } }
		return d5b_blocks_splice( $src, $start, $end, $insert );
	}

	// ---- text fields ----------------------------------------------------------------------------------------------

	/**
	 * The text-bearing elements of each module: module name => list of element names (title, content, button ...).
	 * From catalog/catalog.json (scripts/catalog.js writes it at the repository root) when it is there: an element is
	 * text-bearing when one of its fields has path "<element>.innerContent", is not hidden, and either has component
	 * divi/text or divi/richtext, or has no component and dynamic content of type "text". The built-in list is always
	 * added (the catalogue gives no component for some text, e.g. the blurb title).
	 * @param string|null $catalog path to catalog.json; null = the default place; '' = the built-in list only
	 */
	function d5b_blocks_text_elements( $catalog = null ) {
		static $cache = array();
		if ( null === $catalog ) { $catalog = dirname( __DIR__, 3 ) . '/catalog/catalog.json'; }
		if ( isset( $cache[ $catalog ] ) ) { return $cache[ $catalog ]; }
		$map = D5B_BLOCKS_TEXT_BUILTIN;
		if ( '' !== $catalog && is_file( $catalog ) && is_readable( $catalog ) ) {
			$cat = json_decode( (string) file_get_contents( $catalog ), true );
			if ( is_array( $cat ) && isset( $cat['modules'] ) && is_array( $cat['modules'] ) ) {
				foreach ( $cat['modules'] as $name => $m ) {
					if ( ! is_array( $m ) || ! isset( $m['elements'] ) || ! is_array( $m['elements'] ) ) { continue; }
					foreach ( $m['elements'] as $el => $e ) {
						if ( ! is_array( $e ) || ! isset( $e['fields'] ) || ! is_array( $e['fields'] ) ) { continue; }
						foreach ( $e['fields'] as $f ) {
							if ( ! is_array( $f ) || ( $f['path'] ?? '' ) !== $el . '.innerContent' || ! empty( $f['hidden'] ) ) { continue; }
							$comp = $f['component'] ?? null;
							if ( in_array( $comp, array( 'divi/text', 'divi/richtext' ), true ) || ( null === $comp && 'text' === ( $f['dynamic'] ?? null ) ) ) {
								if ( ! in_array( (string) $el, $map[ $name ] ?? array(), true ) ) { $map[ $name ][] = (string) $el; }
							}
						}
					}
				}
			}
		}
		return $cache[ $catalog ] = $map;
	}

	/**
	 * The text a visitor reads in one module: field => the stored HTML string (JSON-decoded, entities left as stored).
	 * A field is the attribute path: <element>.innerContent.<breakpoint>.<state>, plus .text when the value is an
	 * object holding the text (button, blurb title). Every breakpoint and state is listed.
	 * @return array
	 */
	function d5b_blocks_text_fields( array $node, $catalog = null ) {
		$els = d5b_blocks_text_elements( $catalog )[ $node['name'] ?? '' ] ?? array();
		$out = array();
		if ( ! $els || ! is_array( $node['attrs'] ?? null ) ) { return $out; }
		foreach ( $node['attrs'] as $el => $v ) {
			if ( ! in_array( (string) $el, $els, true ) || ! is_array( $v ) || ! isset( $v['innerContent'] ) || ! is_array( $v['innerContent'] ) ) { continue; }
			foreach ( $v['innerContent'] as $bp => $states ) {
				if ( ! is_array( $states ) || false !== strpos( (string) $bp, '.' ) ) { continue; }
				foreach ( $states as $st => $val ) {
					if ( false !== strpos( (string) $st, '.' ) ) { continue; }
					$p = $el . '.innerContent.' . $bp . '.' . $st;
					if ( is_string( $val ) ) { $out[ $p ] = $val; }
					elseif ( is_array( $val ) && isset( $val['text'] ) && is_string( $val['text'] ) ) { $out[ $p . '.text' ] = $val['text']; }
				}
			}
		}
		return $out;
	}

	/**
	 * A string as Divi writes it inside a block comment: JSON with "/" and non-ASCII left raw, then a JSON unicode escape
	 * (backslash, u, four hex digits) for: an escaped quote (0022), an escaped backslash (005c), each "-" of "--"
	 * (002d), and < > & (003c, 003e, 0026). The Visual Builder fixtures hold exactly these.
	 * @return string|null null when the string is not valid UTF-8
	 */
	function d5b_blocks_json_string( $s ) {
		$j = json_encode( (string) $s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS );
		if ( false === $j ) { return null; }
		$j = preg_replace_callback( '/\\\\(["\\\\])/', function ( $m ) { return '"' === $m[1] ? '\\u0022' : '\\u005c'; }, $j );
		return str_replace( array( '--', '<', '>', '&' ), array( '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026' ), $j );
	}

	function d5b_blocks_json_ws( $j, &$i ) { $n = strlen( $j ); while ( $i < $n && false !== strpos( " \t\n\r", $j[ $i ] ) ) { $i++; } }
	function d5b_blocks_json_str_end( $j, $i ) { $n = strlen( $j ); for ( $k = $i + 1; $k < $n; $k++ ) { if ( '\\' === $j[ $k ] ) { $k++; continue; } if ( '"' === $j[ $k ] ) { return $k + 1; } } return -1; }
	function d5b_blocks_json_scan( $j, &$i, array $path, array &$out ) {
		$n = strlen( $j );
		d5b_blocks_json_ws( $j, $i );
		if ( $i >= $n ) { return false; }
		$c = $j[ $i ];
		if ( '{' === $c || '[' === $c ) {
			$obj = '{' === $c; $close = $obj ? '}' : ']'; $k = 0;
			$i++; d5b_blocks_json_ws( $j, $i );
			if ( $i < $n && $close === $j[ $i ] ) { $i++; return true; }
			while ( true ) {
				d5b_blocks_json_ws( $j, $i );
				if ( $obj ) {
					if ( $i >= $n || '"' !== $j[ $i ] ) { return false; }
					$e = d5b_blocks_json_str_end( $j, $i ); if ( $e < 0 ) { return false; }
					$key = json_decode( substr( $j, $i, $e - $i ) ); $i = $e;
					d5b_blocks_json_ws( $j, $i );
					if ( $i >= $n || ':' !== $j[ $i ] ) { return false; }
					$i++;
				} else { $key = $k++; }
				$sub = $path; $sub[] = (string) $key;
				if ( ! d5b_blocks_json_scan( $j, $i, $sub, $out ) ) { return false; }
				d5b_blocks_json_ws( $j, $i );
				if ( $i < $n && ',' === $j[ $i ] ) { $i++; continue; }
				if ( $i < $n && $close === $j[ $i ] ) { $i++; return true; }
				return false;
			}
		}
		if ( '"' === $c ) { $e = d5b_blocks_json_str_end( $j, $i ); if ( $e < 0 ) { return false; } $out[ json_encode( $path ) ] = array( $i, $e ); $i = $e; return true; }
		$m = strspn( $j, '0123456789+-.eEtruefalsn', $i ); if ( 0 === $m ) { return false; } $i += $m; return true;
	}
	/** Byte range of every string value in a JSON text, keyed by json_encode(path); a key given twice: the last wins, as in json_decode. */
	function d5b_blocks_json_strings( $j ) { $i = 0; $out = array(); return d5b_blocks_json_scan( $j, $i, array(), $out ) ? $out : null; }

	/**
	 * Write new HTML into one text field of one block. Only that JSON string changes (a splice of its bytes, not a
	 * re-encoding of the whole attribute object, so key order, numbers and every other escape stay as stored); the
	 * new string is encoded as Divi encodes it, so writing back the value as read gives back the page byte for byte.
	 * @param array $node a node of d5b_blocks_parse( $src )
	 * @return string|WP_Error
	 */
	function d5b_blocks_set_text_field( $src, array $node, $field, $new_html ) {
		$src    = (string) $src;
		$fields = d5b_blocks_text_fields( $node );
		if ( ! array_key_exists( (string) $field, $fields ) ) { return d5b_blocks_error( 'invalid_input', '"' . $field . '" is not a text field of ' . ( $node['name'] ?? '?' ) . ( $fields ? ' (it has: ' . implode( ', ', array_keys( $fields ) ) . ')' : '' ) ); }
		$s = (int) $node['start']; $o = (int) $node['innerStart'];
		$m = ( $s >= 0 && $o - 3 > $s + 4 && $o <= strlen( $src ) && '<!--' === substr( $src, $s, 4 ) && '-->' === substr( $src, $o - 3, 3 ) ) ? d5b_blocks_match_opener( substr( $src, $s + 4, $o - 3 - $s - 4 ) ) : null;
		if ( ! $m || $m['closing'] || $m['name'] !== $node['name'] || null === $m['json'] || json_decode( $m['json'], true, 100000 ) !== $node['attrs'] ) {
			return d5b_blocks_error( 'invalid_input', 'the node is not the block at its place in src: parse src again and pass a node of that parse' );
		}
		$enc = d5b_blocks_json_string( $new_html );
		if ( null === $enc ) { return d5b_blocks_error( 'invalid_input', 'the new text is not valid UTF-8' ); }
		$path  = explode( '.', (string) $field );
		$spans = d5b_blocks_json_strings( $m['json'] );
		$key   = json_encode( $path );
		if ( null === $spans || ! isset( $spans[ $key ] ) ) { return d5b_blocks_error( 'invalid_input', 'internal: the field ' . $field . ' was not found in the stored attributes' ); }
		$base = $s + 4 + $m['jsonAt'];
		$out  = d5b_blocks_splice( $src, $base + $spans[ $key ][0], $base + $spans[ $key ][1], $enc );
		if ( is_wp_error( $out ) ) { return $out; }
		// proof: the attributes now decode to the old ones with exactly this one value changed
		$want = $node['attrs']; $ref = &$want; foreach ( $path as $p ) { $ref = &$ref[ $p ]; } $ref = (string) $new_html; unset( $ref );
		$m2 = d5b_blocks_match_opener( substr( $out, $s + 4, $o - 3 - $s - 4 + strlen( $out ) - strlen( $src ) ) );
		if ( ! $m2 || json_decode( (string) $m2['json'], true, 100000 ) !== $want ) { return d5b_blocks_error( 'invalid_input', 'internal: the written attributes do not read back as intended' ); }
		return $out;
	}
}
