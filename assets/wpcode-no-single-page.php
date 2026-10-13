<?php
/* WPCode snippet: "<Type>: no single page"  (PHP, Run Everywhere)
   For a post type that is only ever shown inside a Divi FilterGrid popup: the popup loads /<type>/<slug>/?dp_action=dfg_popup_fetch
   in an iframe, so the single page must answer for THAT request only. A direct visit (typed, shared, crawled) is sent to
   the page that holds the grid; the popup request gets a noindex header so the iframe address is never indexed.
   Pair it with: Yoast > Content types > <Type> "Show in search results: No" (noindex + out of the sitemap) and
   "Exclude from search" on the post type (site search). Edit the three constants. */
add_action( 'template_redirect', function () {
	$post_type  = 'tour';                             // the post type
	$taxonomy   = 'tour-category';                    // optional: a taxonomy whose term decides the target page ('' = always the fallback)
	$targets    = array(                              // term slug => the page that holds that category's grid
		'day-trips' => '/tours/day-trips/',
		'weekends'  => '/tours/weekends/',
	);
	$fallback   = '/tours/';

	if ( ! is_singular( $post_type ) ) {
		return;
	}
	if ( ! empty( $_GET['dp_action'] ) ) {              // the popup's own request
		header( 'X-Robots-Tag: noindex, nofollow', true );
		return;
	}
	$target = $fallback;
	if ( $taxonomy ) {
		$terms = get_the_terms( get_queried_object_id(), $taxonomy );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( isset( $targets[ $term->slug ] ) ) {
					$target = $targets[ $term->slug ];
					break;
				}
			}
		}
	}
	wp_safe_redirect( home_url( $target ), 301 );
	exit;
} );
