<?php
/**
 * 工单短代码组件类
 * 注册并解析工单列表、提交表单及详情视图短代码
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Ticket_Shortcodes {

    /**
     * 初始化短代码解析器
     */
    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * 注册工单系统短代码
     */
    public static function register_shortcodes() {
        add_shortcode( 'qibbs_ticket_submit', array( __CLASS__, 'render_ticket_submit' ) );
        add_shortcode( 'qibbs_ticket_list', array( __CLASS__, 'render_ticket_list' ) );
        add_shortcode( 'qibbs_ticket_detail', array( __CLASS__, 'render_ticket_detail' ) );
        add_shortcode( 'qibbs_tickets', array( __CLASS__, 'render_tickets' ) ); // 聚合工单短代码（自适应展示列表、详情或提交）
    }

    /**
     * 加载工单页面所需的 CSS 与 JS 资源
     */
    public static function enqueue_assets() {
        // 仅在包含工单短代码的页面按需加载资源
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) ) {
            return;
        }

        $has_shortcode = has_shortcode( $post->post_content, 'qibbs_ticket_submit' )
            || has_shortcode( $post->post_content, 'qibbs_ticket_list' )
            || has_shortcode( $post->post_content, 'qibbs_ticket_detail' )
            || has_shortcode( $post->post_content, 'qibbs_tickets' );

        if ( ! $has_shortcode ) {
            return;
        }

        $version = get_option( 'qibbs_cache_version', QIBBS_VERSION );
        $ticket_css_file = QIBBS_PLUGIN_DIR . 'assets/css/ticket.css';
        if ( file_exists( $ticket_css_file ) ) {
            $version .= '-' . filemtime( $ticket_css_file );
        }

        wp_enqueue_style(
            'qibbs-ticket',
            QIBBS_PLUGIN_URL . 'assets/css/ticket.css',
            array(),
            $version
        );

        wp_enqueue_script(
            'qibbs-ticket',
            QIBBS_PLUGIN_URL . 'assets/js/ticket.js',
            array( 'jquery' ),
            $version,
            true
        );

        wp_localize_script( 'qibbs-ticket', 'qibbsTicket', array(
            'ajaxurl'             => admin_url( 'admin-ajax.php' ),
            'nonce'               => wp_create_nonce( 'qibbs_nonce' ),
            'maxUploadSize'       => qibbs_get_option( 'max_upload_size', 5 ),
            'allowedImageFormats' => qibbs_get_option( 'allowed_image_formats', 'jpg,jpeg,png,webp,gif' ),
            'i18n'                => array(
                'submitting'    => __( '提交中...', 'qibbs-community' ),
                'uploading'     => __( '上传图片中...', 'qibbs-community' ),
                'sending'       => __( '发送中...', 'qibbs-community' ),
                'error'         => __( '操作失败', 'qibbs-community' ),
                'maxImages'     => __( '最多上传9张图片', 'qibbs-community' ),
                'invalidFormat' => __( '不支持的格式: ', 'qibbs-community' ),
                'fileTooLarge'  => __( '文件太大: ', 'qibbs-community' ),
            ),
        ) );
    }

    /**
     * 渲染聚合工单视图短代码
     * 根据 URL 参数自适应切换提交、列表与工单详情
     */
    public static function render_tickets( $atts ) {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
        $ticket_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        ob_start();

        switch ( $action ) {
            case 'submit':
                include QIBBS_PLUGIN_DIR . 'templates/ticket-submit.php';
                break;

            case 'view':
                if ( $ticket_id ) {
                    include QIBBS_PLUGIN_DIR . 'templates/ticket-detail.php';
                } else {
                    include QIBBS_PLUGIN_DIR . 'templates/ticket-list.php';
                }
                break;

            case 'list':
            default:
                include QIBBS_PLUGIN_DIR . 'templates/ticket-list.php';
                break;
        }

        return ob_get_clean();
    }

    /**
     * 渲染提交工单表单短代码
     */
    public static function render_ticket_submit( $atts ) {
        ob_start();
        include QIBBS_PLUGIN_DIR . 'templates/ticket-submit.php';
        return ob_get_clean();
    }

    /**
     * 渲染工单列表短代码
     */
    public static function render_ticket_list( $atts ) {
        ob_start();
        include QIBBS_PLUGIN_DIR . 'templates/ticket-list.php';
        return ob_get_clean();
    }

    /**
     * 渲染工单详情短代码
     */
    public static function render_ticket_detail( $atts ) {
        ob_start();
        include QIBBS_PLUGIN_DIR . 'templates/ticket-detail.php';
        return ob_get_clean();
    }
}

// 初始化工单短代码分发器
Qibbs_Ticket_Shortcodes::init();
