<?php
/**
 * 启灵主题深度集成类
 * 负责工单系统与启灵主题个人中心无缝融合
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 启灵主题集成器
 */
class Qibbs_Qiling_Integration {

    /**
     * 单例实例
     *
     * @var Qibbs_Qiling_Integration
     */
    private static $instance = null;

    /**
     * 获取单例实例
     *
     * @return Qibbs_Qiling_Integration
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // 仅在启灵主题生效且工单模块开启时初始化
        if ( ! QIBBS_QILING_THEME || ! qibbs_get_option( 'enable_tickets', false ) ) {
            return;
        }

        $this->init_hooks();
    }

    /**
     * 注册主题集成钩子
     */
    private function init_hooks() {
        // 个人中心注入工单导航标签
        add_filter( 'qiling_account_tabs', array( $this, 'add_ticket_tab' ), 10, 2 );

        // 个人中心渲染工单面板内容
        add_action( 'qiling_account_tab_content', array( $this, 'render_tab_content' ), 10, 2 );

        // 注入工单剪贴板图标
        add_filter( 'qiling_account_icons', array( $this, 'add_ticket_icon' ) );

        // 加载个人中心工单页面专用样式
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
    }

    /**
     * 注入工单模块图标 SVG
     *
     * @param array $icons Existing icons.
     * @return array Modified icons.
     */
    public function add_ticket_icon( $icons ) {
        $icons['clipboard'] = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><line x1="16" y1="10" x2="8" y2="10"/><line x1="16" y1="14" x2="8" y2="14"/><line x1="12" y1="18" x2="8" y2="18"/></svg>';
        return $icons;
    }

    /**
     * 渲染个人中心工单面板内容
     *
     * @param string $active_tab Current active tab.
     * @param int    $user_id Current user ID.
     */
    public function render_tab_content( $active_tab, $user_id ) {
        if ( $active_tab !== 'tickets' ) {
            return;
        }

        self::render_ticket_content( $user_id );
    }

    /**
     * 在个人中心导航中追加工单 Tab
     *
     * @param array $tabs Existing tabs.
     * @param int   $user_id Current user ID.
     * @return array Modified tabs.
     */
    public function add_ticket_tab( $tabs, $user_id ) {
        // 将工单 Tab 排在安全设置之前
        $new_tabs = array();
        
        foreach ( $tabs as $key => $tab ) {
            if ( $key === 'security' ) {
                // 将工单 Tab 排在安全设置之前
                $new_tabs['tickets'] = array(
                    'icon'  => 'clipboard',
                    'label' => __( '我的工单', 'qibbs-community' ),
                );
            }
            $new_tabs[ $key ] = $tab;
        }

        // 未找到安全设置 Tab 则追加在末尾
        if ( ! isset( $new_tabs['tickets'] ) ) {
            $new_tabs['tickets'] = array(
                'icon'  => 'clipboard',
                'label' => __( '我的工单', 'qibbs-community' ),
            );
        }

        return $new_tabs;
    }

    /**
     * 个人中心工单页面资源入队
     */
    public function enqueue_styles() {
        // 仅在个人中心工单页面加载
        if ( ! is_page_template( 'templates/template-account.php' ) ) {
            return;
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
        
        if ( $active_tab !== 'tickets' ) {
            return;
        }

        $version = get_option( 'qibbs_cache_version', QIBBS_VERSION );
        $acc_ticket_css_file = QIBBS_PLUGIN_DIR . 'assets/css/account-ticket.css';
        if ( file_exists( $acc_ticket_css_file ) ) {
            $version .= '-' . filemtime( $acc_ticket_css_file );
        }

        wp_enqueue_style(
            'qibbs-account-ticket',
            QIBBS_PLUGIN_URL . 'assets/css/account-ticket.css',
            array( 'developer-starter-account' ),
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
     * 获取个人中心工单列表 URL
     *
     * @return string Ticket page URL.
     */
    public static function get_ticket_url() {
        $account_page_url = self::get_account_page_url();
        
        if ( $account_page_url ) {
            return add_query_arg( 'tab', 'tickets', $account_page_url );
        }

        // 降级为短代码页面 URL
        $ticket_page_id = qibbs_get_option( 'ticket_page', 0 );
        return $ticket_page_id ? qibbs_translate_frontend_internal_url( get_permalink( $ticket_page_id ) ) : '#';
    }

    /**
     * 获取个人中心工单详情 URL
     *
     * @param int $ticket_id Ticket ID.
     * @return string Ticket detail URL.
     */
    public static function get_ticket_detail_url( $ticket_id ) {
        $account_page_url = self::get_account_page_url();
        
        if ( $account_page_url ) {
            return add_query_arg( array(
                'tab'    => 'tickets',
                'action' => 'view',
                'id'     => absint( $ticket_id ),
            ), $account_page_url );
        }

        // 降级为短代码页面 URL
        $ticket_page_id = qibbs_get_option( 'ticket_page', 0 );
        if ( $ticket_page_id ) {
            return qibbs_translate_frontend_internal_url(
                add_query_arg( array(
                    'action' => 'view',
                    'id'     => absint( $ticket_id ),
                ), get_permalink( $ticket_page_id ) )
            );
        }

        return '#';
    }

    /**
     * 获取个人中心提交工单 URL
     *
     * @return string Ticket submit URL.
     */
    public static function get_ticket_submit_url() {
        $account_page_url = self::get_account_page_url();
        
        if ( $account_page_url ) {
            return add_query_arg( array(
                'tab'    => 'tickets',
                'action' => 'submit',
            ), $account_page_url );
        }

        // 降级为短代码页面 URL
        $ticket_page_id = qibbs_get_option( 'ticket_page', 0 );
        if ( $ticket_page_id ) {
            return qibbs_translate_frontend_internal_url(
                add_query_arg( 'action', 'submit', get_permalink( $ticket_page_id ) )
            );
        }

        return '#';
    }

    /**
     * 获取启灵主题个人中心主页 URL
     *
     * @return string|false Account page URL or false if not found.
     */
    public static function get_account_page_url() {
        // 检查启灵主题是否激活
        if ( ! QIBBS_QILING_THEME ) {
            return false;
        }

        // 优先从主题配置读取
        if ( function_exists( 'developer_starter_get_option' ) ) {
            $account_page_id = developer_starter_get_option( 'account_page_id', '' );
            if ( $account_page_id ) {
                return qibbs_translate_frontend_internal_url( get_permalink( $account_page_id ) );
            }
        }

        // 兜底通过模板文件名查找页面
        $pages = get_pages( array(
            'meta_key'   => '_wp_page_template',
            'meta_value' => 'templates/template-account.php',
            'number'     => 1,
        ) );

        if ( ! empty( $pages ) ) {
            return qibbs_translate_frontend_internal_url( get_permalink( $pages[0]->ID ) );
        }

        return false;
    }

    /**
     * 渲染个人中心工单主内容模板
     *
     * @param int $user_id Current user ID.
     */
    public static function render_ticket_content( $user_id ) {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
        $ticket_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        switch ( $action ) {
            case 'submit':
                include QIBBS_PLUGIN_DIR . 'templates/account-tickets.php';
                break;

            case 'view':
                if ( $ticket_id ) {
                    include QIBBS_PLUGIN_DIR . 'templates/account-tickets.php';
                } else {
                    include QIBBS_PLUGIN_DIR . 'templates/account-tickets.php';
                }
                break;

            case 'list':
            default:
                include QIBBS_PLUGIN_DIR . 'templates/account-tickets.php';
                break;
        }
    }

    /**
     * 检查当前请求是否落在个人中心工单 Tab
     *
     * @return bool
     */
    public static function is_account_tickets_page() {
        if ( ! is_page_template( 'templates/template-account.php' ) ) {
            return false;
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
        return $active_tab === 'tickets';
    }
}

// 初始化启灵商城适配组件
Qibbs_Qiling_Integration::get_instance();
