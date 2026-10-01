<?php
/*
 * media-audit-test.php: the reference finder of assets/wp-media-audit.php, without WordPress.
 *
 *   php scripts/media-audit-test.php
 *
 * Tests the pure functions only (what counts as a path, what counts as an attachment ID, how sure).
 * Set WPMA_PLUGIN_FILE to test another copy of the plugin (used for mutation testing).
 */
define( 'ABSPATH', __DIR__ . '/' );
function add_action() {}
require getenv( 'WPMA_PLUGIN_FILE' ) ?: __DIR__ . '/../assets/wp-media-audit.php';

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) {
	global $failed, $n; $n++;
	echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . $detail : '' ) . "\n";
	if ( ! $ok ) { $failed++; }
}
function paths( $t ) { $p = wpma_paths( wpma_norm_text( $t ), 'wp-content/uploads' ); sort( $p ); return $p; }
function ids( $t ) { $i = wpma_ids_in_text( wpma_norm_text( $t ) ); ksort( $i ); return $i; }
function same( $a, $b ) { return json_encode( $a ) === json_encode( $b ); }
function j( $v ) { return json_encode( $v ); }

echo "--- paths\n";
$r = paths( '<img src="https://example.com/wp-content/uploads/2020/05/a.jpg" srcset="https://example.com/wp-content/uploads/2020/05/a-300x200.jpg 300w, https://example.com/wp-content/uploads/2020/05/a-768x512.jpg 768w">' );
check( 'img src and every srcset entry', same( $r, array( '2020/05/a-300x200.jpg', '2020/05/a-768x512.jpg', '2020/05/a.jpg' ) ), j( $r ) );
$r = paths( '{"src":"https:\/\/example.com\/wp-content\/uploads\/2021\/01\/b.png"}' );
check( 'JSON with escaped slashes', same( $r, array( '2021/01/b.png' ) ), j( $r ) );
$r = paths( 'a:1:{s:3:"css";s:70:"{\\\\\"bg\\\\\":\\\\\"https:\\\\\\/\\\\\\/example.com\\\\\\/wp-content\\\\\\/uploads\\\\\\/c.jpg\\\\\"}";}' );
check( 'JSON escaped twice inside a serialized value', same( $r, array( 'c.jpg' ) ), j( $r ) );
$dash = chr( 92 ) . 'u002d';   // backslash u002d: how a block comment writes one "-" of a "--"
$r = paths( '<!-- wp:divi/image {"src":"https://example.com/wp-content/uploads/2022/02/my' . $dash . $dash . 'file.jpg"} /-->' );
check( 'a block comment that writes -- as two unicode escapes', same( $r, array( '2022/02/my--file.jpg' ) ), j( $r ) );
$r = ids( 'data-settings="{&quot;imageId&quot;:&quot;77&quot;,&quot;id&quot;:78}"' );
check( 'JSON inside an HTML attribute (&quot;): IDs are read', same( $r, array( 77 => 's', 78 => 'w' ) ), j( $r ) );
$r = paths( '.hero{background:url(/wp-content/uploads/2019/12/hero.jpg) no-repeat}.x{background:url("/wp-content/uploads/x.svg")}' );
check( 'CSS url() with and without quotes, no host', same( $r, array( '2019/12/hero.jpg', 'x.svg' ) ), j( $r ) );
$r = paths( 'data-settings="{&quot;image&quot;:&quot;https://old-domain.example/wp-content/uploads/2018/03/d.jpg&quot;}"' );
check( 'JSON inside an HTML attribute (&quot;), and another domain still counts', same( $r, array( '2018/03/d.jpg' ) ), j( $r ) );
$r = paths( 'href="https://share.example/?u=https%3A%2F%2Fexample.com%2Fwp-content%2Fuploads%2F2020%2F05%2Fe.pdf"' );
check( 'a URL-encoded link', same( $r, array( '2020/05/e.pdf' ) ), j( $r ) );
$r = paths( 'src="/wp-content/uploads/2020/05/my%20file.jpg?ver=3#top"' );
check( '%20 is decoded, query string and fragment are dropped', same( $r, array( '2020/05/my file.jpg' ) ), j( $r ) );
$r = paths( 'See https://example.com/wp-content/uploads/2020/05/f.jpg.' );
check( 'a full stop at the end of a sentence is not part of the name', same( $r, array( '2020/05/f.jpg' ) ), j( $r ) );
check( 'no uploads path, no result', same( paths( 'https://example.com/wp-content/themes/x/a.jpg and /uploads.txt' ), array() ) );
check( 'a path that climbs out with .. is dropped', same( paths( '/wp-content/uploads/../../wp-config.php' ), array() ) );
$r = paths( 'src="/wp-content/uploads/2024/10/Open-Day..jpg" and /wp-content/uploads/2024/10/a/../b.jpg' );
check( 'two dots INSIDE a file name are a name, not a climb (found on a real site)', same( $r, array( '2024/10/Open-Day..jpg' ) ), j( $r ) );
$r = wpma_paths( wpma_norm_text( 'https://example.com/files/2020/a.jpg and https://example.com/wp-content/uploads/b.jpg' ), 'files' );
check( 'a site with a custom upload folder', same( $r, array( '2020/a.jpg' ) ), j( $r ) );

echo "--- which key names mean media\n";
foreach ( array( 'image_1', '_thumbnail_id', 'backgroundImageId', 'gallery_ids', 'logo', 'site_icon', 'custom_logo', 'featured-image', 'bg_img', 'pdf_file', 'video_poster', 'attachment_id', 'mediaId' ) as $k ) {
	check( "media: $k", wpma_key_is_media( $k ) );
}
foreach ( array( 'image_width', 'imageHeight', 'thumbnail_size_w', 'profile_id', 'slide_count', 'gallery_columns', 'id', 'post_id', 'page_id', 'icon_size', 'image_alt', 'logo_max_width', 'video_duration', '' ) as $k ) {
	check( "not media: " . ( '' === $k ? '(empty)' : $k ), ! wpma_key_is_media( $k ) );
}

echo "--- IDs in text\n";
$r = ids( '<img class="alignnone size-full wp-image-123" src="x"> <a rel="attachment wp-att-124"> [caption id="attachment_125" align="x"]' );
check( 'wp-image-, wp-att-, attachment_ are strong', same( $r, array( 123 => 's', 124 => 's', 125 => 's' ) ), j( $r ) );
$r = ids( '[gallery columns="4" ids="10,11, 12" size="large"] [playlist ids="20"] [gallery include="30,31"]' );
check( '[gallery ids], [playlist ids], [gallery include] are strong; columns="4" is not an ID', same( $r, array( 10 => 's', 11 => 's', 12 => 's', 20 => 's', 30 => 's', 31 => 's' ) ), j( $r ) );
$r = ids( '[et_pb_gallery gallery_ids="40,41" posts_number="9"][et_pb_image image_id="42" width="300"][contact-form-7 id="50"]' );
check( 'Divi 4: gallery_ids and image_id strong; a bare id="50" weak; posts_number and width ignored', same( $r, array( 40 => 's', 41 => 's', 42 => 's', 50 => 'w' ) ), j( $r ) );
$r = ids( '<!-- wp:image {"id":60,"sizeSlug":"large"} --><!-- wp:x {"mediaId":"61","imageWidth":300,"galleryIds":[62,"63"],"opacity":"0.5","zIndex":64} -->' );
check( 'JSON: mediaId and galleryIds strong, "id" weak, imageWidth / opacity / zIndex ignored', same( $r, array( 60 => 'w', 61 => 's', 62 => 's', 63 => 's' ) ), j( $r ) );
$r = ids( '<!-- wp:x/gallery {"module":{"meta":{"adminLabel":{"desktop":{"value":"Gallery (157 images)"}}}},"galleryIds":{"innerContent":{"desktop":{"value":"65,66, 67"},"tablet":{"value":"68"}}},"columns":{"desktop":{"value":"4"}},"imageWidth":{"desktop":{"value":"300"}}} /-->' );
check( 'Divi 5 block: IDs nested under a media key, for every screen size; columns and imageWidth are not IDs', same( $r, array( 65 => 's', 66 => 's', 67 => 's', 68 => 's' ) ), j( $r ) );
$r = ids( '{"image":{"innerContent":{"desktop":{"value":{"src":"x","id":"69"}}}},"icon":{"desktop":{"value":"0.5"}}}' );
check( 'Divi 5 image block: the "id" four levels down stays a weak match; "0.5" is not an ID', same( $r, array( 69 => 'w' ) ), j( $r ) );
$r = ids( '{\"backgroundImageId\":\"70\"}' );
check( 'JSON with escaped quotes', same( $r, array( 70 => 's' ) ), j( $r ) );
$r = ids( 'a:3:{s:11:"custom_logo";i:80;s:10:"header_img";s:2:"81";s:7:"gallery";a:2:{i:0;i:82;i:1;s:2:"83";}}' );
check( 'serialized: media keys strong, and list positions 0 and 1 are not IDs', same( $r, array( 80 => 's', 81 => 's', 82 => 's', 83 => 's' ) ), j( $r ) );
$r = ids( 'a:2:{s:5:"width";i:90;s:8:"nav_menu";i:91;}' );
check( 'serialized: other keys give nothing', same( $r, array() ), j( $r ) );
check( 'a phone number or a year in plain text is not an ID', same( ids( 'Call 082 555 1234 in 2024, room 12.' ), array() ) );
$r = ids( 'wp-image-99 and id="99"' );
check( 'strong wins over weak for the same ID', same( $r, array( 99 => 's' ) ), j( $r ) );

echo "--- IDs in meta and options\n";
check( '_thumbnail_id = 5 is strong', same( wpma_ids_in_meta( '_thumbnail_id', '5' ), array( 5 => 's' ) ) );
check( 'image_3 = 7 (a custom field) is strong', same( wpma_ids_in_meta( 'image_3', '7' ), array( 7 => 's' ) ) );
check( '_product_image_gallery = "8,9" is strong', same( wpma_ids_in_meta( '_product_image_gallery', '8,9' ), array( 8 => 's', 9 => 's' ) ) );
check( 'site_icon = 11 (an option) is strong', same( wpma_ids_in_meta( 'site_icon', '11' ), array( 11 => 's' ) ) );
$r = wpma_ids_in_meta( 'photos', 'a:2:{i:0;s:2:"12";i:1;s:2:"13";}' );
check( 'a serialized list of IDs under a media key is strong (positions are not IDs)', same( $r, array( 12 => 's', 13 => 's' ) ), j( $r ) );
check( 'an unknown key with a bare number is weak', same( wpma_ids_in_meta( 'related_item', '14' ), array( 14 => 'w' ) ) );
foreach ( array( '_edit_last', '_menu_item_object_id', '_wp_old_date', 'wp_user_level', 'page_on_front', 'thumbnail_size_w', 'db_version', 'posts_per_page' ) as $k ) {
	check( "$k with a bare number is nothing", same( wpma_ids_in_meta( $k, '15' ), array() ) );
}
$r = wpma_ids_in_meta( 'theme_mods_x', 'a:2:{s:11:"custom_logo";i:16;s:9:"nav_menus";a:1:{s:4:"main";i:17;}}' );
check( 'a mixed serialized option falls through to the text rules', same( $r, array( 16 => 's' ) ), j( $r ) );
check( 'a price is not an ID', same( wpma_ids_in_meta( '_price', '19.99' ), array() ) );

echo "--- old-style gallery\n";
check( '[gallery] with no ids shows the post\'s own uploads', wpma_has_bare_gallery( 'text [gallery] text' ) );
check( '[gallery columns="3"] too', wpma_has_bare_gallery( '[gallery columns="3"]' ) );
check( '[gallery ids="1,2"] does not', ! wpma_has_bare_gallery( '[gallery ids="1,2"]' ) );
check( 'one of each: yes', wpma_has_bare_gallery( '[gallery ids="1"] and [gallery link="file"]' ) );

echo "--- class of a reference\n";
check( 'published page: live', 'live' === wpma_class( 'page', 'publish' ) );
check( 'draft: live (a draft is somebody\'s work)', 'live' === wpma_class( 'post', 'draft' ) );
check( 'revision: revision', 'revision' === wpma_class( 'revision', 'inherit' ) );
check( 'auto-draft: revision', 'revision' === wpma_class( 'post', 'auto-draft' ) );
check( 'trashed: trash', 'trash' === wpma_class( 'page', 'trash' ) );
check( 'meta of a post that no longer exists: orphan', 'orphan' === wpma_class( null, null ) );

echo "--- scan_text: filter and merge\n";
$hits = array();
wpma_scan_text( $hits, '<img class="wp-image-5" src="/wp-content/uploads/a.jpg"> wp-image-6', 'wp-content/uploads', array( 5 => 0 ), 'live', 'posts|1|page|publish' );
wpma_scan_text( $hits, '<img class="wp-image-5" src="/wp-content/uploads/a.jpg">', 'wp-content/uploads', array( 5 => 0 ), 'live', 'posts|2|page|publish' );
$h = array_values( $hits );
check( 'an ID that is not an attachment is dropped; repeats are merged with a count and both places', 2 === count( $h ) && same( $h[0], array( 'p', 'a.jpg', 's', 'live', 2, array( 'posts|1|page|publish', 'posts|2|page|publish' ) ) ) && same( $h[1], array( 'i', 5, 's', 'live', 2, array( 'posts|1|page|publish', 'posts|2|page|publish' ) ) ), j( $h ) );
$hits = array();
wpma_scan_text( $hits, '21', 'wp-content/uploads', null, 'trash', 'postmeta|9|_thumbnail_id|page|trash', '_thumbnail_id' );
$h = array_values( $hits );
check( 'a meta value goes through the meta rules and keeps its class', same( $h, array( array( 'i', 21, 's', 'trash', 1, array( 'postmeta|9|_thumbnail_id|page|trash' ) ) ) ), j( $h ) );

echo "\n" . ( $failed ? "FAILED: $failed of $n" : "ALL $n CHECKS PASSED" ) . "\n";
exit( $failed ? 1 : 0 );
