<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'rest_api_init', function () {
    $auth = array( 'YRS_ChatGPT_Report_Plugin', 'authorize' );
    register_rest_route( 'yuru-report/v1', '/fixed-pages', array(
        array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'yrs_fp_list', 'permission_callback' => $auth ),
        array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'yrs_fp_create', 'permission_callback' => $auth ),
    ) );
    register_rest_route( 'yuru-report/v1', '/fixed-pages/(?P<id>\\d+)', array(
        array( 'methods' => WP_REST_Server::READABLE, 'callback' => 'yrs_fp_get', 'permission_callback' => $auth ),
        array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => 'yrs_fp_update', 'permission_callback' => $auth ),
    ) );
} );

function yrs_fp_data( $id ) {
    $p = get_post( absint( $id ) );
    if ( ! $p || 'page' !== $p->post_type ) {
        return new WP_Error( 'yrs_fixed_page_not_found', '固定ページが見つかりません。', array( 'status' => 404 ) );
    }
    $content = (string) $p->post_content;
    return array(
        'ok' => true,
        'page_id' => (int) $p->ID,
        'type' => 'page',
        'title' => (string) $p->post_title,
        'content' => $content,
        'excerpt' => (string) $p->post_excerpt,
        'slug' => (string) $p->post_name,
        'status' => (string) $p->post_status,
        'parent' => (int) $p->post_parent,
        'menu_order' => (int) $p->menu_order,
        'featured_media' => (int) get_post_thumbnail_id( $p->ID ),
        'seo_title' => (string) get_post_meta( $p->ID, 'the_page_seo_title', true ),
        'meta_description' => (string) get_post_meta( $p->ID, 'the_page_meta_description', true ),
        'modified_gmt' => (string) $p->post_modified_gmt,
        'content_hash' => hash( 'sha256', $content ),
        'permalink' => (string) get_permalink( $p->ID ),
        'edit_url' => (string) admin_url( 'post.php?post=' . $p->ID . '&action=edit' ),
    );
}

function yrs_fp_list( WP_REST_Request $r ) {
    $page = max( 1, absint( $r->get_param( 'page' ) ?: 1 ) );
    $per = min( 100, max( 1, absint( $r->get_param( 'per_page' ) ?: 20 ) ) );
    $args = array(
        'post_type' => 'page', 'post_status' => array( 'publish','draft','pending','private' ),
        'posts_per_page' => $per, 'paged' => $page, 'orderby' => 'title', 'order' => 'ASC',
    );
    $status = sanitize_key( (string) $r->get_param( 'status' ) );
    if ( in_array( $status, array( 'publish','draft','pending','private' ), true ) ) { $args['post_status'] = $status; }
    $search = sanitize_text_field( (string) $r->get_param( 'search' ) );
    if ( '' !== $search ) { $args['s'] = $search; }
    $slug = sanitize_title( (string) $r->get_param( 'slug' ) );
    if ( '' !== $slug ) { $args['name'] = $slug; }
    if ( null !== $r->get_param( 'parent' ) ) { $args['post_parent'] = absint( $r->get_param( 'parent' ) ); }
    $q = new WP_Query( $args );
    $items = array();
    foreach ( $q->posts as $p ) {
        $items[] = array(
            'page_id' => (int) $p->ID, 'title' => (string) $p->post_title,
            'slug' => (string) $p->post_name, 'status' => (string) $p->post_status,
            'parent' => (int) $p->post_parent, 'featured_media' => (int) get_post_thumbnail_id( $p->ID ),
            'modified_gmt' => (string) $p->post_modified_gmt, 'permalink' => (string) get_permalink( $p->ID ),
        );
    }
    return rest_ensure_response( array( 'ok'=>true, 'page'=>$page, 'per_page'=>$per, 'total'=>(int)$q->found_posts, 'total_pages'=>(int)$q->max_num_pages, 'items'=>$items ) );
}

function yrs_fp_get( WP_REST_Request $r ) {
    $v = yrs_fp_data( $r['id'] );
    return is_wp_error( $v ) ? $v : rest_ensure_response( $v );
}

function yrs_fp_status( $status ) {
    return in_array( $status, array( 'draft','pending','private','publish' ), true ) ? $status : 'draft';
}

function yrs_fp_parent_ok( $id ) {
    return 0 === absint( $id ) || 'page' === get_post_type( absint( $id ) );
}

function yrs_fp_media_ok( $id ) {
    $id = absint( $id );
    return 0 === $id || ( 'attachment' === get_post_type( $id ) && 0 === strpos( (string) get_post_mime_type( $id ), 'image/' ) );
}

function yrs_fp_create( WP_REST_Request $r ) {
    $d = $r->get_json_params();
    if ( ! is_array( $d ) ) { return new WP_Error( 'yrs_invalid_json', 'JSON形式が必要です。', array( 'status'=>400 ) ); }
    $title = sanitize_text_field( $d['title'] ?? '' );
    if ( '' === $title ) { return new WP_Error( 'yrs_missing_title', 'titleは必須です。', array( 'status'=>400 ) ); }
    $slug = sanitize_title( $d['slug'] ?? '' );
    if ( $slug && get_page_by_path( $slug, OBJECT, 'page' ) ) { return new WP_Error( 'yrs_duplicate_slug', '同じslugの固定ページが存在します。', array( 'status'=>409 ) ); }
    $parent = absint( $d['parent'] ?? 0 );
    if ( ! yrs_fp_parent_ok( $parent ) ) { return new WP_Error( 'yrs_invalid_parent', 'parentには固定ページIDを指定してください。', array( 'status'=>400 ) ); }
    $media = absint( $d['featured_media'] ?? 0 );
    if ( ! yrs_fp_media_ok( $media ) ) { return new WP_Error( 'yrs_invalid_media', 'featured_mediaは画像IDを指定してください。', array( 'status'=>400 ) ); }
    $status = yrs_fp_status( sanitize_key( $d['status'] ?? 'draft' ) );
    $settings = get_option( 'yrs_chatgpt_report_settings', array() );
    if ( 'publish' === $status && empty( $settings['allow_publish'] ) ) { $status = 'draft'; }
    $a = array(
        'post_type'=>'page', 'post_title'=>$title, 'post_content'=>(string)($d['content'] ?? ''),
        'post_excerpt'=>sanitize_textarea_field( $d['excerpt'] ?? '' ), 'post_status'=>$status,
        'post_parent'=>$parent, 'menu_order'=>intval( $d['menu_order'] ?? 0 ),
        'comment_status'=>'closed', 'ping_status'=>'closed',
    );
    if ( $slug ) { $a['post_name'] = $slug; }
    $id = wp_insert_post( wp_slash( $a ), true );
    if ( is_wp_error( $id ) ) { return $id; }
    if ( $media ) { set_post_thumbnail( $id, $media ); }
    if ( array_key_exists( 'seo_title', $d ) ) { update_post_meta( $id, 'the_page_seo_title', sanitize_text_field( $d['seo_title'] ) ); }
    if ( array_key_exists( 'meta_description', $d ) ) { update_post_meta( $id, 'the_page_meta_description', sanitize_textarea_field( $d['meta_description'] ) ); }
    return rest_ensure_response( yrs_fp_data( $id ) );
}

function yrs_fp_update( WP_REST_Request $r ) {
    $id = absint( $r['id'] );
    $p = get_post( $id );
    if ( ! $p || 'page' !== $p->post_type ) { return new WP_Error( 'yrs_fixed_page_not_found', '固定ページが見つかりません。', array( 'status'=>404 ) ); }
    $d = $r->get_json_params();
    if ( ! is_array( $d ) || empty( $d ) ) { return new WP_Error( 'yrs_invalid_json', '更新内容が必要です。', array( 'status'=>400 ) ); }
    if ( array_key_exists( 'content', $d ) ) {
        $m = sanitize_text_field( $d['expected_modified_gmt'] ?? '' );
        $h = sanitize_text_field( $d['expected_content_hash'] ?? '' );
        if ( ! $m || ! $h ) { return new WP_Error( 'yrs_missing_guard', 'content更新にはexpected_modified_gmtとexpected_content_hashが必要です。', array( 'status'=>400 ) ); }
        if ( ! hash_equals( (string)$p->post_modified_gmt, $m ) || ! hash_equals( hash( 'sha256', (string)$p->post_content ), $h ) ) {
            return new WP_Error( 'yrs_page_changed', '取得後に固定ページが変更されています。再取得してください。', array( 'status'=>409 ) );
        }
    }
    $a = array( 'ID'=>$id );
    if ( array_key_exists( 'title', $d ) ) { $a['post_title'] = sanitize_text_field( $d['title'] ); }
    if ( array_key_exists( 'content', $d ) ) { $a['post_content'] = (string)$d['content']; }
    if ( array_key_exists( 'excerpt', $d ) ) { $a['post_excerpt'] = sanitize_textarea_field( $d['excerpt'] ); }
    if ( array_key_exists( 'slug', $d ) ) {
        $slug = sanitize_title( $d['slug'] );
        $e = $slug ? get_page_by_path( $slug, OBJECT, 'page' ) : null;
        if ( $e && (int)$e->ID !== $id ) { return new WP_Error( 'yrs_duplicate_slug', '同じslugの別固定ページが存在します。', array( 'status'=>409 ) ); }
        $a['post_name'] = $slug;
    }
    if ( array_key_exists( 'status', $d ) ) {
        $status = yrs_fp_status( sanitize_key( $d['status'] ) );
        $settings = get_option( 'yrs_chatgpt_report_settings', array() );
        if ( 'publish' === $status && empty( $settings['allow_publish'] ) ) { $status = 'draft'; }
        $a['post_status'] = $status;
    }
    if ( array_key_exists( 'parent', $d ) ) {
        $parent = absint( $d['parent'] );
        if ( $parent === $id || ! yrs_fp_parent_ok( $parent ) ) { return new WP_Error( 'yrs_invalid_parent', 'parentが不正です。', array( 'status'=>400 ) ); }
        $a['post_parent'] = $parent;
    }
    if ( array_key_exists( 'menu_order', $d ) ) { $a['menu_order'] = intval( $d['menu_order'] ); }
    if ( count( $a ) > 1 ) { $u = wp_update_post( wp_slash( $a ), true ); if ( is_wp_error( $u ) ) { return $u; } }
    if ( array_key_exists( 'featured_media', $d ) ) {
        $media = absint( $d['featured_media'] );
        if ( ! yrs_fp_media_ok( $media ) ) { return new WP_Error( 'yrs_invalid_media', 'featured_mediaが不正です。', array( 'status'=>400 ) ); }
        $media ? set_post_thumbnail( $id, $media ) : delete_post_thumbnail( $id );
    }
    if ( array_key_exists( 'seo_title', $d ) ) { update_post_meta( $id, 'the_page_seo_title', sanitize_text_field( $d['seo_title'] ) ); }
    if ( array_key_exists( 'meta_description', $d ) ) { update_post_meta( $id, 'the_page_meta_description', sanitize_textarea_field( $d['meta_description'] ) ); }
    return rest_ensure_response( yrs_fp_data( $id ) );
}
