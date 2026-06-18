<?php
/**
 * Plugin Name: ゆる歴史散歩 ChatGPT レポート投稿
 * Description: 非公開カスタムGPTから、画像付き散歩レポートを安全に下書き投稿します。
 * Version: 1.5.1
 * Update URI: https://github.com/tsu58-rgb/yuru-report-chatgpt-plugin
 * Author: ゆる歴史散歩会
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'YRS_CHATGPT_REPORT_PLUGIN_FILE' ) ) {
    define( 'YRS_CHATGPT_REPORT_PLUGIN_FILE', __FILE__ );
}

/**
 * WP External Links は公開ページでは通常どおり動かし、
 * Gutenberg と GPT Actions が利用する REST API 応答には介入させません。
 */
if ( ! function_exists( 'yrs_chatgpt_report_disable_wpel_on_rest' ) ) {
    function yrs_chatgpt_report_disable_wpel_on_rest( $apply = true ) {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }
        return $apply;
    }
}
add_filter( 'wpel_apply_settings', 'yrs_chatgpt_report_disable_wpel_on_rest', PHP_INT_MAX, 1 );

if ( ! function_exists( 'yrs_chatgpt_report_normalize_github_source' ) ) {
    function yrs_chatgpt_report_normalize_github_source( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( empty( $hook_extra['plugin'] ) || plugin_basename( YRS_CHATGPT_REPORT_PLUGIN_FILE ) !== $hook_extra['plugin'] ) {
            return $source;
        }

        $desired = trailingslashit( $remote_source ) . 'yuru-report-chatgpt/';
        if ( trailingslashit( $source ) === $desired ) {
            return $source;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem ) {
            return new WP_Error( 'yrs_update_filesystem_error', '更新先のファイルシステムを利用できません。' );
        }

        if ( $wp_filesystem->exists( $desired ) ) {
            $wp_filesystem->delete( $desired, true );
        }

        if ( ! $wp_filesystem->move( $source, $desired, true ) ) {
            return new WP_Error( 'yrs_update_source_error', '更新用フォルダーを正しい名前へ変更できませんでした。' );
        }

        return $desired;
    }
}
add_filter( 'upgrader_source_selection', 'yrs_chatgpt_report_normalize_github_source', 5, 4 );

$yrs_source = '';
for ( $yrs_index = 0; $yrs_index < 8; $yrs_index++ ) {
    $yrs_part = __DIR__ . '/release-source/part-' . str_pad( (string) $yrs_index, 2, '0', STR_PAD_LEFT );
    if ( ! is_readable( $yrs_part ) ) {
        add_action(
            'admin_notices',
            static function () {
                echo '<div class="notice notice-error"><p>ChatGPTレポート投稿プラグインの構成ファイルが不足しています。</p></div>';
            }
        );
        return;
    }
    $yrs_source .= file_get_contents( $yrs_part );
}

$yrs_source = preg_replace( '/^\xEF\xBB\xBF?\s*<\?php\s*/', '', $yrs_source, 1 );
$yrs_source = str_replace( '__FILE__', 'YRS_CHATGPT_REPORT_PLUGIN_FILE', $yrs_source );
eval( $yrs_source );
