<?php
/**
 * Plugin Name: 启灵bbs
 * Plugin URI: https://www.jingxialai.com
 * Description: 启灵主题微社区插件，支持工单系统。
 * Version: 1.8.0
 * Author: Summer
 * Author URI: https://www.jingxialai.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: qibbs-community
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package Qibbs_Community
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 插件基础常量定义
define( 'QIBBS_VERSION', '1.8.0' );
define( 'QIBBS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'QIBBS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'QIBBS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'QIBBS_DB_VERSION', '1.8.0' );

// 优先加载全局核心辅助函数库（确保所有底层方法在后续初始化与钩子中始终就绪）
require_once QIBBS_PLUGIN_DIR . 'inc/Helpers/Option_Helper.php';
require_once QIBBS_PLUGIN_DIR . 'inc/Helpers/Points_Helper.php';
require_once QIBBS_PLUGIN_DIR . 'inc/Helpers/Level_Helper.php';
require_once QIBBS_PLUGIN_DIR . 'inc/Helpers/Url_Helper.php';
require_once QIBBS_PLUGIN_DIR . 'inc/Helpers/Permission_Helper.php';
require_once QIBBS_PLUGIN_DIR . 'inc/Helpers/Utility_Helper.php';
require_once QIBBS_PLUGIN_DIR . 'inc/Helpers/UID_Helper.php';

// 优先加载主题兼容适配类
require_once QIBBS_PLUGIN_DIR . 'inc/Core/Theme_Compatibility.php';

// 定义主题兼容常量
define( 'QIBBS_QILING_THEME', Qibbs_Theme_Compatibility::is_qiling_theme() );

/**
 * 主题兼容性后台提示通知
 */
function qibbs_theme_compatibility_notice() {
    Qibbs_Theme_Compatibility::admin_compatibility_notice();
}
add_action( 'admin_notices', 'qibbs_theme_compatibility_notice' );

/**
 * 在页面底部注入登录弹窗触发交互脚本
 */
function qibbs_output_modal_trigger_script() {
    Qibbs_Theme_Compatibility::output_modal_trigger_script();
}
add_action( 'wp_footer', 'qibbs_output_modal_trigger_script', 100 );

/**
 * 核心类自动加载器
 *
 * @param string $class_name The class name to load.
 */
function qibbs_autoloader( $class_name ) {
    // 仅自动加载 Qibbs 命名空间类
    if ( strpos( $class_name, 'Qibbs_' ) !== 0 ) {
        return;
    }

    $raw = substr( $class_name, 6 ); // 去除 'Qibbs_'
    $class_file_nested = str_replace( '_', '/', $raw );
    $trimmed_name = preg_replace( '/^[A-Za-z]+_/', '', $raw );

    $dirs = array(
        'Core',
        'Payment',
        'Payment/Gateways',
        'PostType',
        'Taxonomy',
        'Service',
        'REST',
        'Follow',
        'Message',
        'Notification',
        'Profile',
        'Layout',
        'Admin',
        'Helpers',
        'Compatibility',
    );

    foreach ( $dirs as $dir ) {
        // 1. 扁平名称匹配: inc/{dir}/{ClassName}.php
        $flat_path = QIBBS_PLUGIN_DIR . 'inc/' . $dir . '/' . $raw . '.php';
        if ( file_exists( $flat_path ) ) {
            require_once $flat_path;
            return;
        }

        // 2. 层级名称匹配: inc/{dir}/{Class/Name}.php
        $nested_path = QIBBS_PLUGIN_DIR . 'inc/' . $dir . '/' . $class_file_nested . '.php';
        if ( file_exists( $nested_path ) ) {
            require_once $nested_path;
            return;
        }

        // 3. 模块去前缀匹配: inc/{dir}/{Trimmed_Name}.php (如 Qibbs_Payment_Alipay_Gateway -> Alipay_Gateway)
        if ( ! empty( $trimmed_name ) && $trimmed_name !== $raw ) {
            $trimmed_path = QIBBS_PLUGIN_DIR . 'inc/' . $dir . '/' . $trimmed_name . '.php';
            if ( file_exists( $trimmed_path ) ) {
                require_once $trimmed_path;
                return;
            }
        }
    }
}
spl_autoload_register( 'qibbs_autoloader' );

/**
 * 插件激活钩子回调
 */
function qibbs_activate() {
    require_once QIBBS_PLUGIN_DIR . 'inc/Core/Activator.php';
    Qibbs_Activator::activate();
}
register_activation_hook( __FILE__, 'qibbs_activate' );

/**
 * 插件停用钩子回调
 */
function qibbs_deactivate() {
    require_once QIBBS_PLUGIN_DIR . 'inc/Core/Deactivator.php';
    Qibbs_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'qibbs_deactivate' );

/**
 * 插件初始化主入口
 */
function qibbs_init() {
    // 加载多语言语言包
    load_plugin_textdomain( 'qibbs-community', false, dirname( QIBBS_PLUGIN_BASENAME ) . '/languages' );

    // 检查并执行数据库增量升级（新表、索引与配置）
    require_once QIBBS_PLUGIN_DIR . 'inc/Core/Activator.php';
    Qibbs_Activator::maybe_upgrade();

    // 初始化核心模块加载器
    require_once QIBBS_PLUGIN_DIR . 'inc/Core/Loader.php';
    $loader = new Qibbs_Loader();
    $loader->run();

    // 若启用投票功能则初始化投票集成模块
    if ( qibbs_get_option( 'enable_voting', false ) && defined( 'QIVOTING_VERSION' ) ) {
        if ( file_exists( QIBBS_PLUGIN_DIR . 'inc/Compatibility/Qivoting/class-voting-integration.php' ) ) {
            require_once QIBBS_PLUGIN_DIR . 'inc/Compatibility/Qivoting/class-voting-integration.php';
            $voting_integration = new Qibbs_Qivoting_Integration();
            $voting_integration->init();
        }
    }

    // 启用启灵主题个人中心工单联动适配
    if ( QIBBS_QILING_THEME && file_exists( QIBBS_PLUGIN_DIR . 'inc/Compatibility/Qiling_Integration.php' ) ) {
        require_once QIBBS_PLUGIN_DIR . 'inc/Compatibility/Qiling_Integration.php';
    }

    // 初始化启灵商城积分联动服务
    if ( file_exists( QIBBS_PLUGIN_DIR . 'inc/Compatibility/QilingShop_Points_Integration.php' ) ) {
        require_once QIBBS_PLUGIN_DIR . 'inc/Compatibility/QilingShop_Points_Integration.php';
    }

    // 初始化启灵商城统一支付收银联动服务
    if ( file_exists( QIBBS_PLUGIN_DIR . 'inc/Compatibility/QilingShop_Payment_Integration.php' ) ) {
        require_once QIBBS_PLUGIN_DIR . 'inc/Compatibility/QilingShop_Payment_Integration.php';
    }

    // 初始化社区成长等级与双轨激励核心服务
    if ( class_exists( 'Qibbs_Level_Service' ) ) {
        Qibbs_Level_Service::get_instance();
    }

    // 触发微社区加载完成扩展动作钩子
    do_action( 'qibbs_loaded' );
}
add_action( 'plugins_loaded', 'qibbs_init' );

/**
 * 在插件列表操作栏注入“设置”快捷入口
 *
 * @param array $links Plugin action links.
 * @return array Modified links.
 */
function qibbs_plugin_action_links( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=qibbs-settings' ) ) . '">' . __( '设置', 'qibbs-community' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . QIBBS_PLUGIN_BASENAME, 'qibbs_plugin_action_links' );
