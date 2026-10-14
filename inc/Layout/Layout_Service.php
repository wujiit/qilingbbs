<?php
/**
 * 前台布局与样式服务类
 *
 * @package Qibbs_Community
 * @subpackage Layout
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Layout_Service {

    /**
     * 获取布局模式（固定返回三栏布局）
     *
     * @return string
     */
    public function get_layout_mode() {
        return 'three-column';
    }

    public function enqueue_assets() {
        // 仅在社区相关页面加载资源，避免在首页、文章页等非社区页面加载
        if ( ! $this->is_qibbs_page() ) {
            return;
        }
        
        // 基于文件修改时间戳生成资源版本号，防止浏览器缓存旧版 CSS/JS
        $cache_version = (string) get_option( 'qibbs_cache_version', QIBBS_VERSION );
        $style_file = QIBBS_PLUGIN_DIR . 'assets/css/qibbs-community.css';
        $script_file = QIBBS_PLUGIN_DIR . 'assets/js/qibbs-community.js';
        $style_version = $cache_version;
        $script_version = $cache_version;

        if ( file_exists( $style_file ) ) {
            $style_version = $cache_version . '-' . (string) filemtime( $style_file );
        }

        if ( file_exists( $script_file ) ) {
            $script_version = $cache_version . '-' . (string) filemtime( $script_file );
        }

        $voting_style_version = $cache_version;
        $voting_script_version = $cache_version;
        $voting_style_file = QIBBS_PLUGIN_DIR . 'assets/css/qibbs-voting.css';
        $voting_script_file = QIBBS_PLUGIN_DIR . 'assets/js/qibbs-voting.js';

        if ( file_exists( $voting_style_file ) ) {
            $voting_style_version = $cache_version . '-' . (string) filemtime( $voting_style_file );
        }

        if ( file_exists( $voting_script_file ) ) {
            $voting_script_version = $cache_version . '-' . (string) filemtime( $voting_script_file );
        }

        $current_user_id = get_current_user_id();
        $unread_counts = array(
            'notifications' => 0,
            'messages'      => 0,
        );
        $unread_counts_preloaded = false;
        if ( $current_user_id ) {
            $notification_service = new Qibbs_Notification_Service();
            $unread_counts = $notification_service->get_unread_badge_counts( $current_user_id );
            $unread_counts_preloaded = true;
        }
        $comment_reward_hint = $current_user_id ? qibbs_get_points_reward_hint( 'comment_approved', __( '评论通过可得', 'qibbs-community' ), $current_user_id ) : '';
        $inline_reply_hint_parts = array( __( '支持 Ctrl/Command + Enter 快速回复', 'qibbs-community' ) );
        if ( '' !== $comment_reward_hint ) {
            $inline_reply_hint_parts[] = $comment_reward_hint;
        }

        // 主样式表入队
        wp_enqueue_style(
            'qibbs-community',
            QIBBS_PLUGIN_URL . 'assets/css/qibbs-community.css',
            array(),
            $style_version
        );

        // 前台核心交互脚本
        wp_enqueue_script(
            'qibbs-community',
            QIBBS_PLUGIN_URL . 'assets/js/qibbs-community.js',
            array( 'jquery' ),
            $script_version,
            true
        );

        // 注入前台本地化配置变量与 Nonce
        wp_localize_script( 'qibbs-community', 'qibbsData', array(
            'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
            'restUrl'              => rest_url( 'qibbs/v1/' ),
            'nonce'                => wp_create_nonce( 'qibbs_nonce' ),
            'restNonce'            => wp_create_nonce( 'wp_rest' ),
            'isLoggedIn'           => is_user_logged_in(),
            'isAdmin'              => current_user_can( 'manage_options' ),
            'currentUser'          => get_current_user_id(),
            'layoutMode'           => $this->get_layout_mode(),
            'maxUploadSize'        => absint( qibbs_get_option( 'max_upload_size', 5 ) ),
            'allowedImageFormats'  => strtolower( qibbs_get_option( 'allowed_image_formats', 'jpg,jpeg,png,webp,gif' ) ),
            'themeStyle'           => qibbs_get_option( 'theme_style', 'default' ),
            'infiniteScroll'       => (bool) qibbs_get_option( 'infinite_scroll', true ),
            'enableFeaturedPosts'  => (bool) qibbs_get_option( 'enable_featured_posts', false ),
            'enableMentions'       => (bool) qibbs_get_option( 'enable_mentions', true ),
            'enableForumSubscriptionNotifications' => (bool) qibbs_get_option( 'enable_forum_subscription_notifications', true ),
            'mentionSuggestLimit'  => max( 3, min( 20, absint( qibbs_get_option( 'mention_suggest_limit', 8 ) ) ) ),
            'enableDraftAutosave'  => (bool) qibbs_get_option( 'enable_draft_autosave', true ),
            'enableReporting'      => (bool) qibbs_get_option( 'enable_reporting', true ),
            'hotDefaultWindow'     => in_array( absint( qibbs_get_option( 'hot_default_window', 7 ) ), array( 1, 7, 30 ), true ) ? absint( qibbs_get_option( 'hot_default_window', 7 ) ) : 7,
            'unreadCounts'         => $unread_counts,
            'unreadCountsPreloaded'=> $unread_counts_preloaded,
            'urls'                 => array(
                'community'     => qibbs_get_community_url(),
                'notifications' => qibbs_get_notifications_url(),
                'messages'      => qibbs_get_messages_url(),
                'profile'       => get_current_user_id() ? qibbs_get_profile_url( get_current_user_id() ) : '',
                'vanityMall'    => qibbs_get_vanity_mall_url(),
                'propMall'      => function_exists( 'qibbs_get_prop_mall_url' ) ? qibbs_get_prop_mall_url() : '',
                'gamesShop'     => function_exists( 'qibbs_get_game_shop_url' ) ? qibbs_get_game_shop_url( 'shop' ) : ( function_exists( 'qlg_get_shop_url' ) ? qlg_get_shop_url() : home_url( '/' ) ),
            ),
            'vanityMall'           => array(
                'enabled'          => qibbs_is_module_enabled( 'vanity_mall' ),
                'gradeDefinitions' => class_exists( 'Qibbs_UID_Rule_Service' ) ? Qibbs_UID_Rule_Service::instance()->get_grade_definitions() : array(),
                'gradeLabels'      => class_exists( 'Qibbs_UID_Rule_Service' ) ? Qibbs_UID_Rule_Service::instance()->get_grade_labels_map() : array(),
                'pointsName'       => function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' ),
                'c2cEnabled'       => (bool) qibbs_get_option( 'enable_c2c_trading', true ),
                'c2cFeeRate'       => (float) qibbs_get_option( 'c2c_fee_rate', 5.0 ),
                'recycleEnabled'   => (bool) qibbs_get_option( 'enable_uid_recycle', true ),
                'recycleRebate'    => (float) qibbs_get_option( 'uid_recycle_rebate_rate', 50.0 ),
                'userPoints'       => ( $current_user_id && function_exists( 'qibbs_get_user_points_balance' ) && qibbs_is_points_integration_available() ) ? qibbs_get_user_points_balance( $current_user_id ) : null,
                'currentCuid'      => $current_user_id ? qibbs_get_user_cuid( $current_user_id ) : 0,
                'hasShopPoints'    => function_exists( 'qibbs_is_points_integration_available' ) ? qibbs_is_points_integration_available() : false,
            ),
            'hasShopPoints'        => function_exists( 'qibbs_is_points_integration_available' ) ? qibbs_is_points_integration_available() : false,
            'checkin'              => function_exists( 'qibbs_get_user_checkin_status' ) ? qibbs_get_user_checkin_status( $current_user_id ) : array( 'enabled' => false ),
            'paymentEnabled'       => class_exists( 'Qibbs_Payment_Manager' ) && Qibbs_Payment_Manager::instance()->is_enabled(),
            'currencySymbol'       => class_exists( 'Qibbs_Payment_Manager' ) ? Qibbs_Payment_Manager::instance()->get_currency_symbol() : '¥',
            'strings'              => array(
                'loading'       => __( '加载中...', 'qibbs-community' ),
                'loadMore'      => __( '加载更多', 'qibbs-community' ),
                'noMore'        => __( '没有更多了', 'qibbs-community' ),
                'error'         => __( '操作失败', 'qibbs-community' ),
                'loginRequired' => __( '请先登录', 'qibbs-community' ),
                'confirm'       => __( '确认', 'qibbs-community' ),
                'cancel'        => __( '取消', 'qibbs-community' ),
                'deleteConfirm' => __( '确定要删除吗？', 'qibbs-community' ),
                'blockConfirm'  => __( '确定要屏蔽吗？', 'qibbs-community' ),
                'featureSuccess' => __( '推荐成功', 'qibbs-community' ),
                'unfeatureSuccess' => __( '已取消推荐', 'qibbs-community' ),
                'mentionLoading' => __( '正在搜索用户...', 'qibbs-community' ),
                'draftSaved'    => __( '草稿已自动保存', 'qibbs-community' ),
                'draftRestored' => __( '已恢复草稿', 'qibbs-community' ),
                'draftCleared'  => __( '草稿已清除', 'qibbs-community' ),
                'reportTitle'   => __( '举报动态', 'qibbs-community' ),
                'reportPlaceholder' => __( '请简要描述举报原因（如广告、辱骂、违规内容）', 'qibbs-community' ),
                'reportSubmit'  => __( '提交举报', 'qibbs-community' ),
                'reportCancel'  => __( '取消', 'qibbs-community' ),
                'forumSubscriptionDisabled' => __( '圈子订阅通知已关闭', 'qibbs-community' ),
                'statusToDiscussion' => __( '设为讨论中', 'qibbs-community' ),
                'statusToResolved' => __( '标记为已解决', 'qibbs-community' ),
                'statusToClosed' => __( '关闭讨论', 'qibbs-community' ),
                'statusLabelDiscussion' => __( '讨论中', 'qibbs-community' ),
                'statusLabelResolved' => __( '已解决', 'qibbs-community' ),
                'statusLabelClosed' => __( '已关闭', 'qibbs-community' ),
                'discussionStatusTitle' => __( '更新讨论状态', 'qibbs-community' ),
                'discussionStatusConfirmPrefix' => __( '确定执行：', 'qibbs-community' ),
                'discussionStatusUpdated' => __( '讨论状态已更新', 'qibbs-community' ),
                'discussionStatusUpdateFailed' => __( '讨论状态更新失败', 'qibbs-community' ),
                'discussionClosedNote' => __( '该动态已关闭讨论，暂不支持新评论。', 'qibbs-community' ),
                'setBestAnswer' => __( '最佳答案', 'qibbs-community' ),
                'clearBestAnswer' => __( '取消最佳', 'qibbs-community' ),
                'pinQualityReply' => __( '置顶', 'qibbs-community' ),
                'unpinQualityReply' => __( '取消置顶', 'qibbs-community' ),
                'likeComment' => __( '赞', 'qibbs-community' ),
                'likedComment' => __( '已赞', 'qibbs-community' ),
                'commentRewardHint' => $comment_reward_hint,
                'inlineReplyHint' => implode( ' · ', array_filter( $inline_reply_hint_parts ) ),
                'bestAnswerConfirm' => __( '确定将该回复设为最佳答案吗？', 'qibbs-community' ),
                'clearBestAnswerConfirm' => __( '确定取消当前最佳答案吗？', 'qibbs-community' ),
                'pinQualityConfirm' => __( '确定置顶该优质回复吗？', 'qibbs-community' ),
                'unpinQualityConfirm' => __( '确定取消该优质置顶吗？', 'qibbs-community' ),
                'reopenQuestion' => __( '追问/重开', 'qibbs-community' ),
                'reopenQuestionConfirm' => __( '确定要重开该讨论吗？这会清除最佳答案并恢复到“讨论中”。', 'qibbs-community' ),
            ),
        ) );

        // 靓号商城与交易大厅资源
        if ( qibbs_is_module_enabled( 'vanity_mall' ) ) {
            $mall_style_file = QIBBS_PLUGIN_DIR . 'assets/css/qibbs-vanity-mall.css';
            $mall_style_version = $cache_version;
            if ( file_exists( $mall_style_file ) ) {
                $mall_style_version = $cache_version . '-' . (string) filemtime( $mall_style_file );
            }
            wp_enqueue_style(
                'qibbs-vanity-mall',
                QIBBS_PLUGIN_URL . 'assets/css/qibbs-vanity-mall.css',
                array( 'qibbs-community' ),
                $mall_style_version
            );

            $mall_script_file = QIBBS_PLUGIN_DIR . 'assets/js/qibbs-vanity-mall.js';
            $mall_script_version = $cache_version;
            if ( file_exists( $mall_script_file ) ) {
                $mall_script_version = $cache_version . '-' . (string) filemtime( $mall_script_file );
            }
            wp_enqueue_script(
                'qibbs-vanity-mall',
                QIBBS_PLUGIN_URL . 'assets/js/qibbs-vanity-mall.js',
                array( 'jquery', 'qibbs-community' ),
                $mall_script_version,
                true
            );
        }

        // 投票功能资源 - 仅在启用投票功能且投票插件已激活时加载
        if ( defined( 'QIVOTING_VERSION' ) && qibbs_get_option( 'enable_voting', false ) ) {
            // 投票 CSS
            wp_enqueue_style(
                'qibbs-voting',
                QIBBS_PLUGIN_URL . 'assets/css/qibbs-voting.css',
                array( 'qibbs-community' ),
                $voting_style_version
            );

            // 投票 JS
            wp_enqueue_script(
                'qibbs-voting',
                QIBBS_PLUGIN_URL . 'assets/js/qibbs-voting.js',
                array( 'jquery', 'qibbs-community' ),
                $voting_script_version,
                true
            );

            // 本地化投票脚本数据
            wp_localize_script( 'qibbs-voting', 'qibbsVotingData', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'qibbs_voting_nonce' ),
                'strings' => array(
                    'voteSuccess'   => __( '投票成功', 'qibbs-community' ),
                    'voteFailed'    => __( '投票失败', 'qibbs-community' ),
                    'alreadyVoted'  => __( '您已经投过票了', 'qibbs-community' ),
                    'loginRequired' => __( '请先登录后再投票', 'qibbs-community' ),
                ),
            ) );
        }

        // 原生支付与号码交易客户端脚本
        $payment_script_file    = QIBBS_PLUGIN_DIR . 'assets/js/qibbs-payment.js';
        $payment_script_version = file_exists( $payment_script_file ) ? $cache_version . '-' . (string) filemtime( $payment_script_file ) : $cache_version;

        if ( file_exists( QIBBS_PLUGIN_DIR . 'assets/js/jquery-qrcode.min.js' ) ) {
            wp_enqueue_script(
                'qibbs-qrcode',
                QIBBS_PLUGIN_URL . 'assets/js/jquery-qrcode.min.js',
                array( 'jquery' ),
                $cache_version,
                true
            );
        }

        wp_enqueue_script(
            'qibbs-payment',
            QIBBS_PLUGIN_URL . 'assets/js/qibbs-payment.js',
            array( 'jquery', 'qibbs-community' ),
            $payment_script_version,
            true
        );

        // 注入自定义主题配色内联样式
        $this->enqueue_custom_colors();
    }

    /**
     * 展示访问权限受限提示（游客拦截模式或黑名单拦截）
     */
    public function maybe_require_login() {
        if ( is_admin() ) {
            return;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }

        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            return;
        }

        if ( ! $this->is_qibbs_page() ) {
            return;
        }

        if ( is_user_logged_in() ) {
            $user_id = get_current_user_id();
            if (
                $user_id &&
                ! user_can( $user_id, 'manage_options' ) &&
                function_exists( 'qibbs_blacklist_denies_access' ) &&
                qibbs_blacklist_denies_access( $user_id )
            ) {
                $this->render_access_restricted( array(
                    'type'       => 'blacklist',
                    'title'      => qibbs_get_option( 'blacklist_notice_title', __( '你的账号已被限制访问社区', 'qibbs-community' ) ),
                    'text'       => qibbs_get_option( 'blacklist_notice_text', __( '如有疑问请联系管理员。', 'qibbs-community' ) ),
                    'show_login' => false,
                ) );
            }
            return;
        }

        if ( ! function_exists( 'qibbs_require_login' ) || ! qibbs_require_login() ) {
            return;
        }

        $this->render_access_restricted( array(
            'type'       => 'guest',
            'title'      => qibbs_get_option( 'guest_access_notice_title', __( '当前功能仅限已登录用户访问', 'qibbs-community' ) ),
            'text'       => qibbs_get_option( 'guest_access_notice_text', __( '请先登录后继续访问社区功能。', 'qibbs-community' ) ),
            'show_login' => true,
        ) );
    }

    /**
     * 渲染访问受限模板及上下文提示
     *
     * @param array $context Access context.
     */
    private function render_access_restricted( $context = array() ) {
        status_header( 403 );
        nocache_headers();

        $template = QIBBS_PLUGIN_DIR . 'templates/access-restricted.php';
        if ( file_exists( $template ) ) {
            $GLOBALS['qibbs_access_restricted_context'] = is_array( $context ) ? $context : array();
            include $template;
            unset( $GLOBALS['qibbs_access_restricted_context'] );
            exit;
        }

        wp_die( esc_html__( '当前功能仅限已登录用户访问。', 'qibbs-community' ) );
        exit;
    }
    
    /**
     * 检测当前页面是否为社区相关页面
     * 
     * @return bool
     */
    public function is_qibbs_page() {
        if ( $this->is_registered_page_request( 'qibbs_community_page_id', array( 'community' ), array( 'qibbs_community' ) ) ) {
            return true;
        }

        if ( $this->is_registered_page_request( 'qibbs_notifications_page_id', array( 'notifications', 'notification', '通知' ), array( 'qibbs_notifications' ) ) ) {
            return true;
        }

        if ( $this->is_registered_page_request( 'qibbs_messages_page_id', array( 'messages', 'message', '私信' ), array( 'qibbs_messages' ) ) ) {
            return true;
        }

        if ( get_query_var( 'qibbs_member_id' ) || ! empty( qibbs_match_profile_request_path()['member_id'] ) ) {
            return true;
        }

        if ( get_query_var( 'qibbs_post_id' ) || ! empty( qibbs_match_post_request_path()['post_id'] ) ) {
            return true;
        }
        
        // 动态话题页
        if ( is_tax( 'qibbs_topic' ) ) {
            return true;
        }
        
        if ( is_page() && $this->current_page_has_qibbs_shortcode( array( 'qibbs_community', 'qibbs_messages', 'qibbs_notifications' ) ) ) {
            return true;
        }

        if ( is_page() ) {
            $template = get_page_template_slug();
            if ( $template && strpos( $template, 'qibbs' ) !== false ) {
                return true;
            }
        }
        
        // 允许其他插件/主题扩展检测
        return apply_filters( 'qibbs_is_plugin_page', false );
    }

    /**
     * 加载自定义颜色样式覆盖
     */
    private function enqueue_custom_colors() {
        $primary = qibbs_get_option( 'custom_primary_color', '' );
        $accent  = qibbs_get_option( 'custom_accent_color', '' );

        if ( empty( $primary ) && empty( $accent ) ) {
            return;
        }

        $css = ':root {';

        if ( ! empty( $primary ) ) {
            $css .= '--qibbs-primary: ' . esc_attr( $primary ) . ';';
            // 计算悬停加深颜色值
            $css .= '--qibbs-primary-hover: ' . esc_attr( $this->adjust_brightness( $primary, -20 ) ) . ';';
            // 计算浅色背景强调值
            $css .= '--qibbs-primary-light: ' . esc_attr( $this->adjust_brightness( $primary, 80 ) ) . ';';
        }

        if ( ! empty( $accent ) ) {
            $css .= '--qibbs-accent: ' . esc_attr( $accent ) . ';';
            $css .= '--qibbs-secondary: ' . esc_attr( $accent ) . ';';
        }

        $css .= '}';

        wp_add_inline_style( 'qibbs-community', $css );
    }

    /**
     * 微调颜色亮度辅助函数
     */
    private function adjust_brightness( $hex, $percent ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );

        $r = max( 0, min( 255, $r + ( $r * $percent / 100 ) ) );
        $g = max( 0, min( 255, $g + ( $g * $percent / 100 ) ) );
        $b = max( 0, min( 255, $b + ( $b * $percent / 100 ) ) );

        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }

    /**
     * 获取主题风格属性标识
     *
     * @return string HTML attribute for theme style
     */
    public function get_theme_attribute() {
        $theme = qibbs_get_option( 'theme_style', 'default' );
        if ( $theme !== 'default' ) {
            return ' data-qibbs-theme="' . esc_attr( $theme ) . '"';
        }
        return '';
    }

    /**
     * 注册社区侧边栏小工具区域
     */
    public function register_widgets() {
        register_sidebar( array(
            'name'          => __( '启灵社区右侧边栏', 'qibbs-community' ),
            'id'            => 'qibbs-sidebar',
            'description'   => __( '添加自定义内容到社区页面右侧边栏', 'qibbs-community' ),
            'before_widget' => '<div id="%1$s" class="qibbs-widget widget %2$s">',
            'after_widget'  => '</div>',
            'before_title'  => '<h3 class="qibbs-widget-title">',
            'after_title'   => '</h3>',
        ) );
    }

    public function maybe_override_template( $template ) {
        // 社区主页
        if ( $this->is_registered_page_request( 'qibbs_community_page_id', array( 'community' ), array( 'qibbs_community' ) ) || is_singular( 'qibbs_post' ) ) {
            $layout_template = QIBBS_PLUGIN_DIR . 'templates/layout-three-column.php';
            
            if ( file_exists( $layout_template ) ) {
                return $layout_template;
            }
        }
        
        // 消息通知页
        if ( $this->is_registered_page_request( 'qibbs_notifications_page_id', array( 'notifications', 'notification', '通知' ), array( 'qibbs_notifications' ) ) ) {
            $notif_template = QIBBS_PLUGIN_DIR . 'templates/notifications.php';
            if ( file_exists( $notif_template ) ) {
                return $notif_template;
            }
        }
        
        // 私信消息页
        if ( $this->is_registered_page_request( 'qibbs_messages_page_id', array( 'messages', 'message', '私信' ), array( 'qibbs_messages' ) ) ) {
            $msg_template = QIBBS_PLUGIN_DIR . 'templates/messages.php';
            if ( file_exists( $msg_template ) ) {
                return $msg_template;
            }
        }

        return $template;
    }

    public function render_layout( $content_callback = null ) {
        $template = QIBBS_PLUGIN_DIR . 'templates/layout-three-column.php';

        if ( file_exists( $template ) ) {
            include $template;
        } else {
            $this->render_three_column( $content_callback );
        }
    }

    public function render_three_column( $content_callback = null ) {
        ?>
        <div class="qibbs-layout qibbs-three-column">
            <nav class="qibbs-nav-sidebar">
                <?php do_action( 'qibbs_nav_content' ); ?>
            </nav>
            <main class="qibbs-main">
                <?php
                if ( is_callable( $content_callback ) ) {
                    call_user_func( $content_callback );
                } else {
                    do_action( 'qibbs_main_content' );
                }
                ?>
            </main>
            <aside class="qibbs-sidebar">
                <?php do_action( 'qibbs_sidebar_content' ); ?>
            </aside>
        </div>
        <?php
    }

    /**
     * 检查当前请求是否落在社区配置页面
     *
     * @param string $option_name Stored page option.
     * @param array  $fallbacks   Fallback slugs/titles.
     * @param array  $shortcodes  Accepted page shortcodes.
     * @return bool
     */
    private function is_registered_page_request( $option_name, $fallbacks = array(), $shortcodes = array() ) {
        $page_id = function_exists( 'qibbs_get_configured_page_id' )
            ? qibbs_get_configured_page_id( $option_name )
            : 0;

        if ( $page_id > 0 && is_page( $page_id ) ) {
            return true;
        }

        $fallbacks = array_values( array_filter( array_map( 'strval', (array) $fallbacks ) ) );
        if ( ! empty( $fallbacks ) && is_page( $fallbacks ) ) {
            return true;
        }

        if ( is_page() && $this->current_page_has_qibbs_shortcode( $shortcodes ) ) {
            return true;
        }

        return false;
    }

    /**
     * 检查当前页面内容是否包含社区短代码
     *
     * @param array $shortcodes Shortcode tags.
     * @return bool
     */
    private function current_page_has_qibbs_shortcode( $shortcodes ) {
        $post = get_post();
        if ( ! ( $post instanceof WP_Post ) ) {
            return false;
        }

        foreach ( array_filter( (array) $shortcodes ) as $shortcode ) {
            if ( has_shortcode( (string) $post->post_content, (string) $shortcode ) ) {
                return true;
            }
        }

        return false;
    }
}

/**
 * 全局布局渲染函数
 */
function qibbs_layout_render( $content_callback = null ) {
    $layout_service = new Qibbs_Layout_Service();
    $layout_service->render_layout( $content_callback );
    
    do_action( 'qibbs_layout_render', $layout_service->get_layout_mode() );
}
