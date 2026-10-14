<?php
/**
 * 模板定位与加载器
 *
 * @package Qibbs_Community
 * @subpackage Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Template_Loader {

    public static function get_template( $template_name, $args = array() ) {
        if ( ! empty( $args ) && is_array( $args ) ) {
            extract( $args, EXTR_SKIP );
        }

        // 优先检索主题目录下的模板覆盖
        $theme_template = locate_template( 'qibbs/' . $template_name );
        if ( $theme_template ) {
            include $theme_template;
            return;
        }

        // 兜底使用插件内置模板
        $plugin_template = QIBBS_PLUGIN_DIR . 'templates/' . $template_name;
        if ( file_exists( $plugin_template ) ) {
            include $plugin_template;
        }
    }

    public static function get_template_part( $slug, $name = null, $args = array() ) {
        $template = $name ? "{$slug}-{$name}.php" : "{$slug}.php";
        self::get_template( $template, $args );
    }
}

// 全局模板辅助函数
function qibbs_get_template( $template_name, $args = array() ) {
    Qibbs_Template_Loader::get_template( $template_name, $args );
}

function qibbs_get_template_part( $slug, $name = null, $args = array() ) {
    Qibbs_Template_Loader::get_template_part( $slug, $name, $args );
}
