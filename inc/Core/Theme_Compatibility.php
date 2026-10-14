<?php
/**
 * 启灵主题兼容适配类
 * 负责微社区与启灵主题样式规范、登录弹窗及个人中心深度打通
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 主题兼容适配器
 */
class Qibbs_Theme_Compatibility {

    /**
     * 检查当前活跃主题是否为启灵主题
     *
     * @return bool True if Qiling theme is active.
     */
    public static function is_qiling_theme() {
        // 检查启灵主题专有特征函数
        if ( function_exists( 'developer_starter_get_option' ) ) {
            return true;
        }

        // 兜底：检查主题目录名称
        $theme = wp_get_theme();
        $theme_slug = $theme->get_template();
        
        if ( strpos( $theme_slug, 'developer-starter' ) !== false 
             || strpos( $theme_slug, 'qiling' ) !== false ) {
            return true;
        }

        return false;
    }

    /**
     * 检查启灵主题是否启用了快捷登录弹窗
     *
     * @return bool True if login modal is available.
     */
    public static function has_login_modal() {
        if ( ! self::is_qiling_theme() ) {
            return false;
        }

        // 检查启灵主题顶栏登录按钮配置
        if ( function_exists( 'developer_starter_get_option' ) ) {
            return (bool) developer_starter_get_option( 'header_login_enable', '' );
        }

        return false;
    }

    /**
     * 获取启灵主题配置的独立注册页 URL
     *
     * @return string Register page URL or WordPress default.
     */
    public static function get_register_url() {
        if ( self::is_qiling_theme() && function_exists( 'developer_starter_get_option' ) ) {
            $register_page_id = developer_starter_get_option( 'register_page_id', '' );
            if ( $register_page_id ) {
                $url = get_permalink( $register_page_id );
                if ( $url ) {
                    return $url;
                }
            }
        }
        
        // 兜底使用 WordPress 原生注册链接
        return wp_registration_url();
    }

    /**
     * 获取唤起启灵登录弹窗的按钮 HTML（无弹窗时平滑降级为链接）
     *
     * @param string $class       CSS class for the button.
     * @param string $text        Button text.
     * @param string $fallback_url Fallback URL if modal is not available.
     * @return string Button HTML.
     */
    public static function get_login_button_html( $class = '', $text = '' ) {
        if ( empty( $text ) ) {
            $text = __( '登录', 'qibbs-community' );
        }

        $fallback_url = wp_login_url( get_permalink() );

        if ( self::has_login_modal() ) {
            // 返回可唤起主题登录弹窗的按钮
            return sprintf(
                '<button type="button" class="%s qibbs-login-modal-trigger" data-fallback-url="%s">%s</button>',
                esc_attr( $class ),
                esc_url( $fallback_url ),
                esc_html( $text )
            );
        }

        // 降级为常规登录页面跳转链接
        return sprintf(
            '<a href="%s" class="%s">%s</a>',
            esc_url( $fallback_url ),
            esc_attr( $class ),
            esc_html( $text )
        );
    }

    /**
     * 获取注册按钮 HTML 节点
     *
     * @param string $class CSS class for the button.
     * @param string $text  Button text.
     * @return string Button HTML.
     */
    public static function get_register_button_html( $class = '', $text = '' ) {
        if ( empty( $text ) ) {
            $text = __( '注册', 'qibbs-community' );
        }

        $register_url = self::get_register_url();

        return sprintf(
            '<a href="%s" class="%s">%s</a>',
            esc_url( $register_url ),
            esc_attr( $class ),
            esc_html( $text )
        );
    }

    /**
     * 非启灵主题环境下的后台友好适配提示
     */
    public static function admin_compatibility_notice() {
        if ( self::is_qiling_theme() ) {
            return;
        }

        $class = 'notice notice-warning is-dismissible';
        $message = sprintf(
            /* translators: %s: theme name */
            __( '<strong>启灵BBS 插件</strong> 专为 <strong>启灵主题</strong> 设计。当前主题 (%s) 可能无法完全兼容所有功能，建议使用启灵主题以获得最佳体验。', 'qibbs-community' ),
            wp_get_theme()->get( 'Name' )
        );

        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), $message );
    }

    /**
     * 插件初始化时检查主题兼容状态
     *
     * @return bool True if compatible.
     */
    public static function check_compatibility() {
        return self::is_qiling_theme();
    }

    /**
     * 在页面底部注入登录弹窗触发交互脚本
     */
    public static function output_modal_trigger_script() {
        if ( ! self::has_login_modal() || is_user_logged_in() ) {
            return;
        }
        ?>
        <script>
        (function($) {
            // 监听微社区登录引导按钮点击事件
            $(document).on('click', '.qibbs-login-modal-trigger', function(e) {
                e.preventDefault();
                
                // 触发启灵主题原生登录弹窗
                var themeLoginBtn = document.getElementById('header-login-toggle');
                if (themeLoginBtn) {
                    themeLoginBtn.click();
                } else {
                    // 降级跳转至登录页
                    var fallbackUrl = $(this).data('fallback-url');
                    if (fallbackUrl) {
                        window.location.href = fallbackUrl;
                    } else {
                        window.location.href = '<?php echo esc_js( wp_login_url() ); ?>';
                    }
                }
            });
        })(jQuery);
        </script>
        <?php
    }
}
