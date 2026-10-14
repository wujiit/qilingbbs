<?php
/**
 * 后台管理设置类
 *
 * @package Qibbs_Community
 * @subpackage Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Admin_Settings {

    private $option_name = 'qibbs_options';

    /**
     * 判断当前后台页面是否属于微社区管理范围
     *
     * @return bool
     */
    private function is_qibbs_admin_screen() {
        if ( function_exists( 'get_current_screen' ) ) {
            $screen = get_current_screen();
            if ( $screen && ! empty( $screen->id ) && false !== strpos( (string) $screen->id, 'qibbs' ) ) {
                return true;
            }
        }

        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return '' !== $page && 0 === strpos( $page, 'qibbs' );
    }

    /**
     * 在微社区后台页面注入专有 body class
     *
     * @param string $classes Existing admin body classes.
     * @return string
     */
    public function add_admin_body_class( $classes ) {
        if ( $this->is_qibbs_admin_screen() ) {
            $classes .= ' qibbs-admin-shell';
        }
        return $classes;
    }

    /**
     * 获取微社区后台顶部快捷导航菜单
     *
     * @return array<string,string>
     */
    private function get_admin_nav_items() {
        return array(
            'qibbs-settings'          => __( '基础设置', 'qibbs-community' ),
            'qibbs-growth-rewards'    => __( '成长激励', 'qibbs-community' ),
            'qibbs-interactive'       => __( '悬赏红包', 'qibbs-community' ),
            'qibbs-dashboard'         => __( '社区看板', 'qibbs-community' ),
            'qibbs-review-queue'      => __( '审核队列', 'qibbs-community' ),
            'qibbs-reports'           => __( '举报管理', 'qibbs-community' ),
            'qibbs-forums'            => __( '圈子管理', 'qibbs-community' ),
            'qibbs-moderators'        => __( '圈子版主', 'qibbs-community' ),
            'qibbs-moderator-logs'    => __( '版主日志', 'qibbs-community' ),
            'qibbs-members'           => __( '用户管理', 'qibbs-community' ),
            'qibbs-user-badges'       => __( '用户徽章', 'qibbs-community' ),
            'qibbs-tags'              => __( '话题标签', 'qibbs-community' ),
            'qibbs-messages'          => __( '私信管理', 'qibbs-community' ),
            'qibbs-blacklist'         => __( '用户黑名单', 'qibbs-community' ),
            'qibbs-tickets'           => __( '工单管理', 'qibbs-community' ),
            'qibbs-ticket-categories' => __( '工单分类', 'qibbs-community' ),
            'qibbs-uid-settings'      => __( 'UID号段', 'qibbs-community' ),
            'qibbs-payment-settings'  => __( '支付交易', 'qibbs-community' ),
            'qibbs-props'             => __( '道具大厅', 'qibbs-community' ),
            'qibbs-risk'              => __( '风控中心', 'qibbs-community' ),
        );
    }

    /**
     * 渲染微社区后台顶部统一导航栏
     *
     * @return void
     */
    public function render_admin_top_nav() {
        if ( ! current_user_can( 'manage_options' ) || ! $this->is_qibbs_admin_screen() ) {
            return;
        }

        $current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( '' === $current_page ) {
            return;
        }

        $items = $this->get_admin_nav_items();
        if ( empty( $items ) ) {
            return;
        }
        ?>
        <div class="qibbs-admin-topnav-wrap">
            <nav class="qibbs-admin-topnav" aria-label="<?php esc_attr_e( '启灵bbs后台导航', 'qibbs-community' ); ?>">
                <?php foreach ( $items as $page_slug => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page_slug ) ); ?>" class="qibbs-admin-topnav-link <?php echo ( $current_page === $page_slug ) ? 'is-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php
    }

    /**
     * 获取待审举报数量用于后台菜单角标
     *
     * @return int
     */
    private function get_pending_reports_count() {
        global $wpdb;
        $reports_table = Qibbs_Database::reports();
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $reports_table ) );
        if ( $exists !== $reports_table ) {
            return 0;
        }

        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reports_table} WHERE status = %s", 'pending' ) );
    }

    public function add_admin_menu() {
        // 主菜单：基础设置
        add_menu_page(
            __( '启灵bbs', 'qibbs-community' ),
            __( '启灵bbs', 'qibbs-community' ),
            'manage_options',
            'qibbs-settings',
            array( $this, 'render_settings_page' ),
            'dashicons-groups',
            30
        );

        // 子菜单：基础设置
        add_submenu_page(
            'qibbs-settings',
            __( '基础设置', 'qibbs-community' ),
            __( '基础设置', 'qibbs-community' ),
            'manage_options',
            'qibbs-settings',
            array( $this, 'render_settings_page' )
        );

        // 子菜单：成长与激励
        add_submenu_page(
            'qibbs-settings',
            __( '成长与激励', 'qibbs-community' ),
            __( '成长与激励', 'qibbs-community' ),
            'manage_options',
            'qibbs-growth-rewards',
            array( $this, 'render_growth_rewards_page' )
        );

        // 子菜单：悬赏与红包
        add_submenu_page(
            'qibbs-settings',
            __( '悬赏与红包', 'qibbs-community' ),
            __( '悬赏与红包', 'qibbs-community' ),
            'manage_options',
            'qibbs-interactive',
            array( $this, 'render_interactive_page' )
        );

        // 子菜单：举报管理
        $pending_reports = $this->get_pending_reports_count();
        $reports_badge = $pending_reports > 0 ? ' <span class="awaiting-mod">' . $pending_reports . '</span>' : '';
        add_submenu_page(
            'qibbs-settings',
            __( '举报管理', 'qibbs-community' ),
            __( '举报管理', 'qibbs-community' ) . $reports_badge,
            'manage_options',
            'qibbs-reports',
            array( $this, 'render_reports_page' )
        );

        // 子菜单：圈子管理（使用自定义数据表）
        add_submenu_page(
            'qibbs-settings',
            __( '圈子管理', 'qibbs-community' ),
            __( '圈子管理', 'qibbs-community' ),
            'manage_options',
            'qibbs-forums',
            array( $this, 'render_forums_page' )
        );

        add_submenu_page(
            'qibbs-settings',
            __( '圈子版主', 'qibbs-community' ),
            __( '圈子版主', 'qibbs-community' ),
            'manage_options',
            'qibbs-moderators',
            array( $this, 'render_moderators_page' )
        );

        add_submenu_page(
            'qibbs-settings',
            __( '版主日志', 'qibbs-community' ),
            __( '版主日志', 'qibbs-community' ),
            'manage_options',
            'qibbs-moderator-logs',
            array( $this, 'render_moderator_logs_page' )
        );

        // 子菜单：社区用户管理
        add_submenu_page(
            'qibbs-settings',
            __( '用户管理', 'qibbs-community' ),
            __( '用户管理', 'qibbs-community' ),
            'manage_options',
            'qibbs-members',
            array( $this, 'render_members_page' )
        );

        add_submenu_page(
            'qibbs-settings',
            __( '用户徽章', 'qibbs-community' ),
            __( '用户徽章', 'qibbs-community' ),
            'manage_options',
            'qibbs-user-badges',
            array( $this, 'render_user_badges_page' )
        );

        // 子菜单：话题标签管理（使用自定义数据表）
        add_submenu_page(
            'qibbs-settings',
            __( '话题标签管理', 'qibbs-community' ),
            __( '话题标签管理', 'qibbs-community' ),
            'manage_options',
            'qibbs-tags',
            array( $this, 'render_tags_page' )
        );

        // 子菜单：私信管理
        add_submenu_page(
            'qibbs-settings',
            __( '私信管理', 'qibbs-community' ),
            __( '私信管理', 'qibbs-community' ),
            'manage_options',
            'qibbs-messages',
            array( $this, 'render_messages_page' )
        );

        // 子菜单：用户黑名单
        add_submenu_page(
            'qibbs-settings',
            __( '用户黑名单', 'qibbs-community' ),
            __( '用户黑名单', 'qibbs-community' ),
            'manage_options',
            'qibbs-blacklist',
            array( $this, 'render_blacklist_page' )
        );

        // 子菜单：工单管理
        $ticket_service = new Qibbs_Ticket_Service();
        $pending_count = $ticket_service->get_pending_count();
        $pending_badge = $pending_count > 0 ? ' <span class="awaiting-mod">' . $pending_count . '</span>' : '';
        
        add_submenu_page(
            'qibbs-settings',
            __( '工单管理', 'qibbs-community' ),
            __( '工单管理', 'qibbs-community' ) . $pending_badge,
            'manage_options',
            'qibbs-tickets',
            array( 'Qibbs_Ticket_Admin', 'render_tickets_page' )
        );

        // 子菜单：工单分类管理
        add_submenu_page(
            'qibbs-settings',
            __( '工单分类', 'qibbs-community' ),
            __( '工单分类', 'qibbs-community' ),
            'manage_options',
            'qibbs-ticket-categories',
            array( 'Qibbs_Ticket_Admin', 'render_categories_page' )
        );

        // 子菜单：UID与号段管理
        add_submenu_page(
            'qibbs-settings',
            __( 'UID号段管理', 'qibbs-community' ),
            __( 'UID号段管理', 'qibbs-community' ),
            'manage_options',
            'qibbs-uid-settings',
            array( $this, 'render_uid_settings_page' )
        );

        // 子菜单：支付交易设置
        add_submenu_page(
            'qibbs-settings',
            __( '支付交易设置', 'qibbs-community' ),
            __( '支付交易设置', 'qibbs-community' ),
            'manage_options',
            'qibbs-payment-settings',
            array( $this, 'render_payment_settings_page' )
        );

        // 子菜单：订单交易管理
        $pending_orders_badge = '';
        if ( class_exists( 'Qibbs_Database' ) ) {
            global $wpdb;
            $orders_table = Qibbs_Database::orders();
            $pending_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders_table} WHERE status = 'pending'" );
            if ( $pending_count > 0 ) {
                $pending_orders_badge = sprintf( ' <span class="update-plugins count-%1$d"><span class="plugin-count">%1$d</span></span>', $pending_count );
            }
        }

        add_submenu_page(
            'qibbs-settings',
            __( '订单交易管理', 'qibbs-community' ),
            __( '订单交易管理', 'qibbs-community' ) . $pending_orders_badge,
            'manage_options',
            'qibbs-orders',
            array( $this, 'render_orders_page' )
        );

        // 子菜单：道具大厅管理
        add_submenu_page(
            'qibbs-settings',
            __( '道具大厅', 'qibbs-community' ),
            __( '道具大厅', 'qibbs-community' ),
            'manage_options',
            'qibbs-props',
            array( $this, 'render_props_page' )
        );
    }

    public function register_settings() {
        register_setting( 'qibbs_settings_group', $this->option_name, array(
            'sanitize_callback' => array( $this, 'sanitize_options' ),
        ) );
        register_setting( 'qibbs_growth_rewards_group', $this->option_name, array(
            'sanitize_callback' => array( $this, 'sanitize_options' ),
        ) );
        register_setting( 'qibbs_interactive_group', $this->option_name, array(
            'sanitize_callback' => array( $this, 'sanitize_options' ),
        ) );

        // === 基础设置 Tab 1: 常规与模块 (qibbs-settings-general) ===
        add_settings_section( 'qibbs_general', __( '基础设置', 'qibbs-community' ), null, 'qibbs-settings-general' );

        add_settings_field( 'posts_per_page', __( '每页显示', 'qibbs-community' ), array( $this, 'field_posts_per_page' ), 'qibbs-settings-general', 'qibbs_general' );
        add_settings_field( 'infinite_scroll', __( '无限滚动', 'qibbs-community' ), array( $this, 'field_infinite_scroll' ), 'qibbs-settings-general', 'qibbs_general' );
        add_settings_field( 'enable_draft_autosave', __( '草稿自动保存', 'qibbs-community' ), array( $this, 'field_enable_draft_autosave' ), 'qibbs-settings-general', 'qibbs_general' );

        add_settings_section( 'qibbs_modules', __( '模块开关', 'qibbs-community' ), null, 'qibbs-settings-general' );

        add_settings_field( 'enable_likes', __( '启用点赞', 'qibbs-community' ), array( $this, 'field_enable_likes' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_favorites', __( '启用收藏', 'qibbs-community' ), array( $this, 'field_enable_favorites' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_comments', __( '启用评论', 'qibbs-community' ), array( $this, 'field_enable_comments' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_follows', __( '启用关注', 'qibbs-community' ), array( $this, 'field_enable_follows' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_messages', __( '启用私信', 'qibbs-community' ), array( $this, 'field_enable_messages' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_notifications', __( '启用通知', 'qibbs-community' ), array( $this, 'field_enable_notifications' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_forum_subscription_notifications', __( '圈子订阅通知', 'qibbs-community' ), array( $this, 'field_enable_forum_subscription_notifications' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_discussion_status_system', __( '讨论状态体系', 'qibbs-community' ), array( $this, 'field_enable_discussion_status_system' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_owner_reopen_question', __( '楼主可追问/重开', 'qibbs-community' ), array( $this, 'field_enable_owner_reopen_question' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'owner_reopen_max_days', __( '楼主重开时限（天）', 'qibbs-community' ), array( $this, 'field_owner_reopen_max_days' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'discussion_status_forums', __( '讨论状态生效圈子', 'qibbs-community' ), array( $this, 'field_discussion_status_forums' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_mentions', __( '启用 @提及', 'qibbs-community' ), array( $this, 'field_enable_mentions' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'mention_suggest_limit', __( '@提及候选数量', 'qibbs-community' ), array( $this, 'field_mention_suggest_limit' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_reporting', __( '启用举报', 'qibbs-community' ), array( $this, 'field_enable_reporting' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_voting', __( '启用投票', 'qibbs-community' ), array( $this, 'field_enable_voting' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_tickets', __( '启用工单', 'qibbs-community' ), array( $this, 'field_enable_tickets' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_vanity_mall', __( '靓号交易大厅', 'qibbs-community' ), array( $this, 'field_enable_vanity_mall' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_prop_mall', __( '道具大厅', 'qibbs-community' ), array( $this, 'field_enable_prop_mall' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'prop_max_promoted_per_forum', __( '圈子推广置顶上限', 'qibbs-community' ), array( $this, 'field_prop_max_promoted_per_forum' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'ticket_page', __( '工单页面', 'qibbs-community' ), array( $this, 'field_ticket_page' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'enable_ticket_push_notifications', __( '新工单群推送', 'qibbs-community' ), array( $this, 'field_enable_ticket_push_notifications' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'ticket_push_channels', __( '工单推送通道', 'qibbs-community' ), array( $this, 'field_ticket_push_channels' ), 'qibbs-settings-general', 'qibbs_modules' );
        add_settings_field( 'ticket_push_title_prefix', __( '推送标题前缀', 'qibbs-community' ), array( $this, 'field_ticket_push_title_prefix' ), 'qibbs-settings-general', 'qibbs_modules' );

        // === 基础设置 Tab 2: 外观与布局 (qibbs-settings-appearance) ===
        add_settings_section( 'qibbs_appearance', __( '外观设置', 'qibbs-community' ), null, 'qibbs-settings-appearance' );

        add_settings_field( 'theme_style', __( '配色风格', 'qibbs-community' ), array( $this, 'field_theme_style' ), 'qibbs-settings-appearance', 'qibbs_appearance' );
        add_settings_field( 'custom_primary_color', __( '自定义主色', 'qibbs-community' ), array( $this, 'field_custom_primary_color' ), 'qibbs-settings-appearance', 'qibbs_appearance' );
        add_settings_field( 'custom_accent_color', __( '自定义强调色', 'qibbs-community' ), array( $this, 'field_custom_accent_color' ), 'qibbs-settings-appearance', 'qibbs_appearance' );

        add_settings_section( 'qibbs_sidebar', __( '侧边栏与导航入口', 'qibbs-community' ), null, 'qibbs-settings-appearance' );

        add_settings_field( 'show_checkin_widget', __( '显示每日签到卡片', 'qibbs-community' ), array( $this, 'field_show_checkin_widget' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );
        add_settings_field( 'show_hot_topics', __( '显示热门话题', 'qibbs-community' ), array( $this, 'field_show_hot_topics' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );
        add_settings_field( 'hot_topic_order', __( '热门话题排序', 'qibbs-community' ), array( $this, 'field_hot_topic_order' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );
        add_settings_field( 'hot_topic_count', __( '热门话题数量', 'qibbs-community' ), array( $this, 'field_hot_topic_count' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );
        add_settings_field( 'show_community_stats', __( '显示社区统计', 'qibbs-community' ), array( $this, 'field_show_community_stats' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );
        add_settings_field( 'enable_search', __( '启用搜索功能', 'qibbs-community' ), array( $this, 'field_enable_search' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );
        add_settings_field( 'show_hot_posts', __( '显示热门动态', 'qibbs-community' ), array( $this, 'field_show_hot_posts' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );
        add_settings_field( 'hot_default_window', __( '热门默认时间窗', 'qibbs-community' ), array( $this, 'field_hot_default_window' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );
        add_settings_field( 'enable_featured_posts', __( '显示推荐动态', 'qibbs-community' ), array( $this, 'field_enable_featured_posts' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );
        add_settings_field( 'show_voting_posts', __( '显示投票动态', 'qibbs-community' ), array( $this, 'field_show_voting_posts' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );
        add_settings_field( 'show_redpacket_posts', __( '显示红包动态', 'qibbs-community' ), array( $this, 'field_show_redpacket_posts' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );
        add_settings_field( 'show_bounty_posts', __( '显示悬赏动态', 'qibbs-community' ), array( $this, 'field_show_bounty_posts' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );
        add_settings_field( 'show_sidebar_categories', __( '显示文章分类', 'qibbs-community' ), array( $this, 'field_show_sidebar_categories' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );
        add_settings_field( 'left_nav_switches', __( '左侧顶部入口开关', 'qibbs-community' ), array( $this, 'field_left_nav_switches' ), 'qibbs-settings-appearance', 'qibbs_sidebar' );

        // === 基础设置 Tab 3: 权限与安全 (qibbs-settings-permissions) ===
        add_settings_section( 'qibbs_permissions', __( '权限与频控限流', 'qibbs-community' ), null, 'qibbs-settings-permissions' );

        add_settings_field( 'who_can_post', __( '发帖权限', 'qibbs-community' ), array( $this, 'field_who_can_post' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'allow_guest_access', __( '游客访问社区', 'qibbs-community' ), array( $this, 'field_allow_guest_access' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'guest_access_notice_title', __( '游客限制提示标题', 'qibbs-community' ), array( $this, 'field_guest_access_notice_title' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'guest_access_notice_text', __( '游客限制提示内容', 'qibbs-community' ), array( $this, 'field_guest_access_notice_text' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'blacklist_access_mode', __( '黑名单访问模式', 'qibbs-community' ), array( $this, 'field_blacklist_access_mode' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'blacklist_notice_title', __( '黑名单提示标题', 'qibbs-community' ), array( $this, 'field_blacklist_notice_title' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'blacklist_notice_text', __( '黑名单提示内容', 'qibbs-community' ), array( $this, 'field_blacklist_notice_text' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'enable_level_rate_limits', __( '等级安全频控联动', 'qibbs-community' ), array( $this, 'field_enable_level_rate_limits' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'post_rate_limit_minute', __( '发帖限流（每分钟）', 'qibbs-community' ), array( $this, 'field_post_rate_limit_minute' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'post_rate_limit', __( '发帖限流（每小时）', 'qibbs-community' ), array( $this, 'field_post_rate_limit' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'comment_rate_limit_minute', __( '评论限流（每分钟）', 'qibbs-community' ), array( $this, 'field_comment_rate_limit_minute' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'comment_rate_limit', __( '评论限流（每小时）', 'qibbs-community' ), array( $this, 'field_comment_rate_limit' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'ticket_rate_limit_minute', __( '工单限流（每分钟）', 'qibbs-community' ), array( $this, 'field_ticket_rate_limit_minute' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'ticket_rate_limit', __( '工单限流（每小时）', 'qibbs-community' ), array( $this, 'field_ticket_rate_limit' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'ticket_block_if_open', __( '禁止未关闭重复提单', 'qibbs-community' ), array( $this, 'field_ticket_block_if_open' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'post_edit_days', __( '动态编辑时限', 'qibbs-community' ), array( $this, 'field_post_edit_days' ), 'qibbs-settings-permissions', 'qibbs_permissions' );
        add_settings_field( 'enable_moderation', __( '启用审核', 'qibbs-community' ), array( $this, 'field_enable_moderation' ), 'qibbs-settings-permissions', 'qibbs_permissions' );

        // === 基础设置 Tab 4: 内容过滤 (qibbs-settings-moderation) ===
        add_settings_section( 'qibbs_content_moderation', __( '内容过滤与违规词', 'qibbs-community' ), null, 'qibbs-settings-moderation' );

        add_settings_field( 'enable_banned_words', __( '启用违规词过滤', 'qibbs-community' ), array( $this, 'field_enable_banned_words' ), 'qibbs-settings-moderation', 'qibbs_content_moderation' );
        add_settings_field( 'banned_words', __( '违规词列表', 'qibbs-community' ), array( $this, 'field_banned_words' ), 'qibbs-settings-moderation', 'qibbs_content_moderation' );
        add_settings_field( 'report_auto_hide_threshold', __( '举报自动下线阈值', 'qibbs-community' ), array( $this, 'field_report_auto_hide_threshold' ), 'qibbs-settings-moderation', 'qibbs_content_moderation' );

        // === 基础设置 Tab 5: 媒体与同步 (qibbs-settings-media) ===
        add_settings_section( 'qibbs_media', __( '媒体上传设置', 'qibbs-community' ), null, 'qibbs-settings-media' );

        add_settings_field( 'max_upload_size', __( '最大上传大小', 'qibbs-community' ), array( $this, 'field_max_upload_size' ), 'qibbs-settings-media', 'qibbs_media' );
        add_settings_field( 'allowed_image_formats', __( '允许的图片格式', 'qibbs-community' ), array( $this, 'field_allowed_image_formats' ), 'qibbs-settings-media', 'qibbs_media' );
        add_settings_field( 'sync_to_wp_media_library', __( '同步后台媒体库', 'qibbs-community' ), array( $this, 'field_sync_to_wp_media_library' ), 'qibbs-settings-media', 'qibbs_media' );

        add_settings_section( 'qibbs_article_sync', __( '文章同步', 'qibbs-community' ), null, 'qibbs-settings-media' );

        add_settings_field( 'enable_article_sync', __( '启用文章同步', 'qibbs-community' ), array( $this, 'field_enable_article_sync' ), 'qibbs-settings-media', 'qibbs_article_sync' );
        add_settings_field( 'sync_categories', __( '同步分类', 'qibbs-community' ), array( $this, 'field_sync_categories' ), 'qibbs-settings-media', 'qibbs_article_sync' );
        add_settings_field( 'sync_excerpt_length', __( '截取字数', 'qibbs-community' ), array( $this, 'field_sync_excerpt_length' ), 'qibbs-settings-media', 'qibbs_article_sync' );
        add_settings_field( 'sync_images', __( '同步图片', 'qibbs-community' ), array( $this, 'field_sync_images' ), 'qibbs-settings-media', 'qibbs_article_sync' );
        add_settings_field( 'sync_historical', __( '同步历史文章', 'qibbs-community' ), array( $this, 'field_sync_historical' ), 'qibbs-settings-media', 'qibbs_article_sync' );

        // === 独立设置页: 成长等级与双轨激励 (qibbs-growth-rewards) ===
        add_settings_section( 'qibbs_growth_rewards', __( '成长等级与双轨激励', 'qibbs-community' ), array( $this, 'section_growth_rewards' ), 'qibbs-growth-rewards' );

        add_settings_field( 'enable_growth_levels', __( '成长等级体系开关', 'qibbs-community' ), array( $this, 'field_enable_growth_levels' ), 'qibbs-growth-rewards', 'qibbs_growth_rewards' );
        add_settings_field( 'growth_levels_table', __( '成长等级阶梯设置', 'qibbs-community' ), array( $this, 'field_growth_levels_table' ), 'qibbs-growth-rewards', 'qibbs_growth_rewards' );
        add_settings_field( 'enable_points_integration', __( '启灵商城积分联动', 'qibbs-community' ), array( $this, 'field_enable_points_integration' ), 'qibbs-growth-rewards', 'qibbs_growth_rewards' );
        add_settings_field( 'points_integration_allow_staff', __( '管理人员参与奖励', 'qibbs-community' ), array( $this, 'field_points_integration_allow_staff' ), 'qibbs-growth-rewards', 'qibbs_growth_rewards' );
        add_settings_field( 'show_form_reward_hints', __( '前台输入框激励提示', 'qibbs-community' ), array( $this, 'field_show_form_reward_hints' ), 'qibbs-growth-rewards', 'qibbs_growth_rewards' );
        add_settings_field( 'dual_rewards_table', __( '双轨行为激励矩阵', 'qibbs-community' ), array( $this, 'field_dual_rewards_table' ), 'qibbs-growth-rewards', 'qibbs_growth_rewards' );

        // === 独立设置页: 悬赏与红包互动 (qibbs-interactive) ===
        add_settings_section( 'qibbs_interactive_points', __( '悬赏与红包互动设置', 'qibbs-community' ), array( $this, 'section_interactive_points' ), 'qibbs-interactive' );

        add_settings_field( 'enable_bounty_post', __( '悬赏帖功能开关', 'qibbs-community' ), array( $this, 'field_enable_bounty_post' ), 'qibbs-interactive', 'qibbs_interactive_points' );
        add_settings_field( 'bounty_amount_range', __( '悬赏积分范围', 'qibbs-community' ), array( $this, 'field_bounty_amount_range' ), 'qibbs-interactive', 'qibbs_interactive_points' );
        add_settings_field( 'bounty_expire_days', __( '悬赏自主采纳时限', 'qibbs-community' ), array( $this, 'field_bounty_expire_days' ), 'qibbs-interactive', 'qibbs_interactive_points' );
        add_settings_field( 'bounty_arbitration_rules', __( '悬赏到期仲裁与退款', 'qibbs-community' ), array( $this, 'field_bounty_arbitration_rules' ), 'qibbs-interactive', 'qibbs_interactive_points' );
        add_settings_field( 'bounty_min_level_to_post', __( '发布悬赏最低等级', 'qibbs-community' ), array( $this, 'field_bounty_min_level_to_post' ), 'qibbs-interactive', 'qibbs_interactive_points' );

        add_settings_field( 'enable_redpacket_post', __( '红包帖功能开关', 'qibbs-community' ), array( $this, 'field_enable_redpacket_post' ), 'qibbs-interactive', 'qibbs_interactive_points' );
        add_settings_field( 'redpacket_amount_range', __( '红包总额范围', 'qibbs-community' ), array( $this, 'field_redpacket_amount_range' ), 'qibbs-interactive', 'qibbs_interactive_points' );
        add_settings_field( 'redpacket_count_range', __( '红包份数范围', 'qibbs-community' ), array( $this, 'field_redpacket_count_range' ), 'qibbs-interactive', 'qibbs_interactive_points' );
        add_settings_field( 'redpacket_min_single_amount', __( '单份红包保底积分', 'qibbs-community' ), array( $this, 'field_redpacket_min_single_amount' ), 'qibbs-interactive', 'qibbs_interactive_points' );
        add_settings_field( 'redpacket_expire_hours', __( '红包超时退回时限', 'qibbs-community' ), array( $this, 'field_redpacket_expire_hours' ), 'qibbs-interactive', 'qibbs_interactive_points' );
        add_settings_field( 'redpacket_permissions', __( '红包领取规则与门槛', 'qibbs-community' ), array( $this, 'field_redpacket_permissions' ), 'qibbs-interactive', 'qibbs_interactive_points' );
    }

    public function sanitize_options( $input ) {
        if ( ! is_array( $input ) ) {
            return get_option( $this->option_name, array() );
        }

        $existing = get_option( $this->option_name, array() );
        $existing = is_array( $existing ) ? $existing : array();

        $option_page = isset( $_POST['option_page'] ) ? sanitize_text_field( wp_unslash( $_POST['option_page'] ) ) : '';

        // 分支 1: 成长与激励独立表单提交 (qibbs_growth_rewards_group)
        if ( 'qibbs_growth_rewards_group' === $option_page ) {
            $sanitized = $existing;
            $sanitized['enable_growth_levels'] = ! empty( $input['enable_growth_levels'] );

            // 成长等级阶梯配置保存
            if ( isset( $input['growth_levels_config'] ) && is_array( $input['growth_levels_config'] ) ) {
                $levels_config = array();
                $default_levels = function_exists( 'qibbs_get_default_growth_levels' ) ? qibbs_get_default_growth_levels() : array();
                foreach ( $default_levels as $lvl => $def ) {
                    $raw_lvl = isset( $input['growth_levels_config'][ $lvl ] ) && is_array( $input['growth_levels_config'][ $lvl ] )
                        ? $input['growth_levels_config'][ $lvl ]
                        : array();
                    $levels_config[ $lvl ] = array(
                        'level'                => (int) $lvl,
                        'name'                 => ! empty( $raw_lvl['name'] ) ? sanitize_text_field( trim( (string) $raw_lvl['name'] ) ) : $def['name'],
                        'min_exp'              => 1 === $lvl ? 0 : absint( $raw_lvl['min_exp'] ?? $def['min_exp'] ),
                        'color'                => ! empty( $raw_lvl['color'] ) ? sanitize_hex_color( (string) $raw_lvl['color'] ) : $def['color'],
                        'badge_class'          => ! empty( $raw_lvl['badge_class'] ) ? sanitize_html_class( (string) $raw_lvl['badge_class'] ) : $def['badge_class'],
                        'privilege'            => ! empty( $raw_lvl['privilege'] ) ? sanitize_textarea_field( trim( (string) $raw_lvl['privilege'] ) ) : ( $def['privilege'] ?? '' ),
                        'post_hourly_limit'    => isset( $raw_lvl['post_hourly_limit'] ) ? max( 0, absint( $raw_lvl['post_hourly_limit'] ) ) : (int) ( $def['post_hourly_limit'] ?? 3 ),
                        'comment_hourly_limit' => isset( $raw_lvl['comment_hourly_limit'] ) ? max( 0, absint( $raw_lvl['comment_hourly_limit'] ) ) : (int) ( $def['comment_hourly_limit'] ?? 10 ),
                    );
                }
                $sanitized['growth_levels_config'] = $levels_config;
            }

            $sanitized['enable_points_integration']     = ! empty( $input['enable_points_integration'] );
            $sanitized['points_integration_allow_staff'] = ! empty( $input['points_integration_allow_staff'] );
            $sanitized['show_form_reward_hints']         = ! empty( $input['show_form_reward_hints'] );

            // 双轨激励动作配置保存 (成长值 EXP + 商城积分 Points)
            $dual_reward_keys = function_exists( 'qibbs_get_dual_reward_definitions' )
                ? array_keys( qibbs_get_dual_reward_definitions() )
                : array( 'post_publish', 'comment_approved', 'post_liked', 'checkin', 'best_answer', 'featured_post', 'ticket_published' );

            foreach ( $dual_reward_keys as $reward_key ) {
                // 成长值 (EXP)
                $exp_enabled_key = 'reward_' . $reward_key . '_exp_enabled';
                $exp_amount_key  = 'reward_' . $reward_key . '_exp_amount';
                $exp_daily_key   = 'reward_' . $reward_key . '_exp_daily_limit';
                $sanitized[ $exp_enabled_key ] = ! empty( $input[ $exp_enabled_key ] );
                $sanitized[ $exp_amount_key ]  = max( 0, absint( $input[ $exp_amount_key ] ?? 0 ) );
                $sanitized[ $exp_daily_key ]   = max( 0, min( 9999, absint( $input[ $exp_daily_key ] ?? 0 ) ) );

                // 商城积分 (Points)
                $pts_enabled_key = 'points_reward_' . $reward_key . '_enabled';
                $pts_amount_key  = 'points_reward_' . $reward_key . '_amount';
                $pts_daily_key   = 'points_reward_' . $reward_key . '_daily_limit';
                $raw_pts_amount  = $input[ $pts_amount_key ] ?? 0;
                $pts_amount      = is_numeric( $raw_pts_amount ) ? (float) $raw_pts_amount : 0.0;
                $sanitized[ $pts_enabled_key ] = ! empty( $input[ $pts_enabled_key ] );
                $sanitized[ $pts_amount_key ]  = max( 0.0, round( $pts_amount, 2 ) );
                $sanitized[ $pts_daily_key ]   = max( 0, min( 9999, absint( $input[ $pts_daily_key ] ?? 0 ) ) );
            }

            return $sanitized;
        }

        // 分支 2: 悬赏与红包独立表单提交 (qibbs_interactive_group)
        if ( 'qibbs_interactive_group' === $option_page ) {
            $sanitized = $existing;
            $sanitized['enable_bounty_post']                  = ! empty( $input['enable_bounty_post'] );
            $sanitized['bounty_min_amount']                   = max( 0.01, round( (float) ( $input['bounty_min_amount'] ?? 5 ), 2 ) );
            $sanitized['bounty_max_amount']                   = max( $sanitized['bounty_min_amount'], round( (float) ( $input['bounty_max_amount'] ?? 1000 ), 2 ) );
            $sanitized['bounty_expire_days']                  = max( 1, min( 90, absint( $input['bounty_expire_days'] ?? 7 ) ) );
            $sanitized['bounty_auto_refund_on_zero_comments'] = ! empty( $input['bounty_auto_refund_on_zero_comments'] );
            $sanitized['bounty_require_admin_arbitration']    = ! empty( $input['bounty_require_admin_arbitration'] );
            $sanitized['bounty_min_level_to_post']            = max( 1, min( 10, absint( $input['bounty_min_level_to_post'] ?? 1 ) ) );

            $sanitized['enable_redpacket_post']               = ! empty( $input['enable_redpacket_post'] );
            $sanitized['redpacket_min_total_amount']          = max( 0.01, round( (float) ( $input['redpacket_min_total_amount'] ?? 10 ), 2 ) );
            $sanitized['redpacket_max_total_amount']          = max( $sanitized['redpacket_min_total_amount'], round( (float) ( $input['redpacket_max_total_amount'] ?? 10000 ), 2 ) );
            $sanitized['redpacket_min_count']                 = max( 1, min( 1000, absint( $input['redpacket_min_count'] ?? 5 ) ) );
            $sanitized['redpacket_max_count']                 = max( $sanitized['redpacket_min_count'], min( 10000, absint( $input['redpacket_max_count'] ?? 100 ) ) );
            $sanitized['redpacket_min_single_amount']         = max( 0.01, round( (float) ( $input['redpacket_min_single_amount'] ?? 0.01 ), 2 ) );
            $sanitized['redpacket_expire_hours']              = max( 1, min( 720, absint( $input['redpacket_expire_hours'] ?? 24 ) ) );
            $sanitized['redpacket_allow_author_claim']        = ! empty( $input['redpacket_allow_author_claim'] );
            $sanitized['redpacket_min_level_to_claim']        = max( 1, min( 10, absint( $input['redpacket_min_level_to_claim'] ?? 1 ) ) );

            return $sanitized;
        }

        // 判定是否来自常规基础设置表单（settings.php / options.php 提交）
        $is_general_settings_form = (
            ( isset( $_POST['option_page'] ) && 'qibbs_settings_group' === $_POST['option_page'] ) ||
            ( isset( $_POST['action'] ) && 'update' === $_POST['action'] && isset( $_POST['qibbs_options'] ) && isset( $_POST['posts_per_page'] ) )
        );

        if ( ! $is_general_settings_form ) {
            // 来自 UID号段规则、C2C交易、支付、风控或程序化调用等其他来源保存
            // 严禁丢弃调用方传入的数据！
            $sanitized = array_merge( $existing, $input );

            if ( isset( $input['uid_free_start'] ) ) {
                $sanitized['uid_free_start'] = max( 1, absint( $input['uid_free_start'] ) );
            }
            if ( isset( $input['uid_reserved_ranges'] ) ) {
                $sanitized['uid_reserved_ranges'] = sanitize_textarea_field( (string) $input['uid_reserved_ranges'] );
            }
            if ( isset( $input['uid_reserved_singles'] ) ) {
                $sanitized['uid_reserved_singles'] = sanitize_text_field( (string) $input['uid_reserved_singles'] );
            }
            if ( isset( $input['uid_vanity_ranges'] ) ) {
                $sanitized['uid_vanity_ranges'] = sanitize_textarea_field( (string) $input['uid_vanity_ranges'] );
            }
            if ( isset( $input['uid_grade_definitions'] ) && is_array( $input['uid_grade_definitions'] ) ) {
                $clean_grades = array();
                foreach ( $input['uid_grade_definitions'] as $gid => $gdef ) {
                    $gid = absint( $gid );
                    if ( $gid > 0 ) {
                        $clean_grades[ $gid ] = array(
                            'name'   => sanitize_text_field( $gdef['name'] ?? '' ),
                            'points' => max( 0, absint( $gdef['points'] ?? 0 ) ),
                            'cash'   => max( 0.0, (float) ( $gdef['cash'] ?? 0 ) ),
                        );
                    }
                }
                $sanitized['uid_grade_definitions'] = $clean_grades;
            }
            if ( isset( $input['enable_c2c_trading'] ) ) {
                $sanitized['enable_c2c_trading'] = ! empty( $input['enable_c2c_trading'] );
            }
            if ( isset( $input['c2c_fee_rate'] ) ) {
                $sanitized['c2c_fee_rate'] = max( 0.0, min( 100.0, (float) $input['c2c_fee_rate'] ) );
            }
            if ( isset( $input['enable_uid_recycle'] ) ) {
                $sanitized['enable_uid_recycle'] = ! empty( $input['enable_uid_recycle'] );
            }
            if ( isset( $input['uid_recycle_rebate_rate'] ) ) {
                $sanitized['uid_recycle_rebate_rate'] = max( 1.0, min( 100.0, (float) $input['uid_recycle_rebate_rate'] ) );
            }
            if ( isset( $input['uid_recycle_payout_type'] ) ) {
                $sanitized['uid_recycle_payout_type'] = sanitize_key( (string) $input['uid_recycle_payout_type'] );
            }

            unset( $sanitized['uid_exclude_digits'], $sanitized['uid_skip_excluded_digits'], $sanitized['uid_divert_excluded_to_free'] );

            return $sanitized;
        }

        $sanitized = $existing;
        
        $sanitized['layout_mode'] = 'three-column';
        $sanitized['posts_per_page'] = absint( $input['posts_per_page'] ?? 20 );
        $sanitized['infinite_scroll'] = ! empty( $input['infinite_scroll'] );
        $sanitized['enable_draft_autosave'] = ! empty( $input['enable_draft_autosave'] );
        
        $sanitized['enable_likes'] = ! empty( $input['enable_likes'] );
        $sanitized['enable_favorites'] = ! empty( $input['enable_favorites'] );
        $sanitized['enable_comments'] = ! empty( $input['enable_comments'] );
        $sanitized['enable_follows'] = ! empty( $input['enable_follows'] );
        $sanitized['enable_messages'] = ! empty( $input['enable_messages'] );
        $sanitized['enable_notifications'] = ! empty( $input['enable_notifications'] );
        $sanitized['enable_forum_subscription_notifications'] = ! empty( $input['enable_forum_subscription_notifications'] );
        $sanitized['enable_discussion_status_system'] = ! empty( $input['enable_discussion_status_system'] );
        $sanitized['enable_owner_reopen_question'] = ! empty( $input['enable_owner_reopen_question'] );
        $owner_reopen_max_days = absint( $input['owner_reopen_max_days'] ?? 30 );
        $sanitized['owner_reopen_max_days'] = max( 1, min( 3650, $owner_reopen_max_days ) );
        $sanitized['discussion_status_forums'] = isset( $input['discussion_status_forums'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $input['discussion_status_forums'] ) ) ) ) : array();
        $sanitized['enable_mentions'] = ! empty( $input['enable_mentions'] );
        $mention_limit = absint( $input['mention_suggest_limit'] ?? 8 );
        $sanitized['mention_suggest_limit'] = max( 3, min( 20, $mention_limit ) );
        $sanitized['enable_reporting'] = ! empty( $input['enable_reporting'] );
        $sanitized['enable_voting'] = ! empty( $input['enable_voting'] );
        $sanitized['enable_tickets'] = ! empty( $input['enable_tickets'] );
        $sanitized['enable_vanity_mall'] = ! empty( $input['enable_vanity_mall'] );
        $sanitized['enable_prop_mall'] = ! empty( $input['enable_prop_mall'] );
        $sanitized['prop_max_promoted_per_forum'] = max( 1, min( 50, absint( $input['prop_max_promoted_per_forum'] ?? 5 ) ) );
        $sanitized['ticket_page'] = absint( $input['ticket_page'] ?? 0 );
        $sanitized['enable_ticket_push_notifications'] = ! empty( $input['enable_ticket_push_notifications'] );
        $sanitized['ticket_push_title_prefix'] = isset( $input['ticket_push_title_prefix'] ) ? sanitize_text_field( $input['ticket_push_title_prefix'] ) : '新工单通知';
        $raw_ticket_push_channels = isset( $input['ticket_push_channels'] ) ? (array) $input['ticket_push_channels'] : array();
        $ticket_push_channels = array();
        foreach ( $raw_ticket_push_channels as $channel_id ) {
            $clean_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $channel_id );
            if ( '' !== $clean_id ) {
                $ticket_push_channels[] = $clean_id;
            }
        }
        $sanitized['ticket_push_channels'] = array_values( array_unique( $ticket_push_channels ) );
        
        $sanitized['who_can_post'] = in_array( $input['who_can_post'] ?? '', array( 'logged_in', 'subscriber', 'contributor', 'author' ) )
            ? $input['who_can_post'] : 'logged_in';
        $sanitized['allow_guest_access'] = ! empty( $input['allow_guest_access'] );
        $sanitized['guest_access_notice_title'] = isset( $input['guest_access_notice_title'] ) ? sanitize_text_field( $input['guest_access_notice_title'] ) : '';
        $sanitized['guest_access_notice_text'] = isset( $input['guest_access_notice_text'] ) ? sanitize_textarea_field( $input['guest_access_notice_text'] ) : '';
        $blacklist_mode = isset( $input['blacklist_access_mode'] ) ? sanitize_key( $input['blacklist_access_mode'] ) : 'read_only';
        $sanitized['blacklist_access_mode'] = in_array( $blacklist_mode, array( 'read_only', 'deny_all' ), true ) ? $blacklist_mode : 'read_only';
        $sanitized['blacklist_notice_title'] = isset( $input['blacklist_notice_title'] ) ? sanitize_text_field( $input['blacklist_notice_title'] ) : '';
        $sanitized['blacklist_notice_text'] = isset( $input['blacklist_notice_text'] ) ? sanitize_textarea_field( $input['blacklist_notice_text'] ) : '';
        $post_minute = absint( $input['post_rate_limit_minute'] ?? 2 );
        $sanitized['post_rate_limit_minute'] = max( 0, min( 60, $post_minute ) );
        $post_hour = absint( $input['post_rate_limit'] ?? 5 );
        $sanitized['post_rate_limit'] = max( 1, min( 500, $post_hour ) );
        $comment_minute = absint( $input['comment_rate_limit_minute'] ?? 6 );
        $sanitized['comment_rate_limit_minute'] = max( 0, min( 300, $comment_minute ) );
        $comment_hour = absint( $input['comment_rate_limit'] ?? 20 );
        $sanitized['comment_rate_limit'] = max( 1, min( 2000, $comment_hour ) );
        $ticket_minute = absint( $input['ticket_rate_limit_minute'] ?? 1 );
        $sanitized['ticket_rate_limit_minute'] = max( 0, min( 60, $ticket_minute ) );
        $ticket_hour = absint( $input['ticket_rate_limit'] ?? 3 );
        $sanitized['ticket_rate_limit'] = max( 0, min( 500, $ticket_hour ) );
        $sanitized['ticket_block_if_open'] = ! empty( $input['ticket_block_if_open'] );
        $sanitized['enable_moderation'] = ! empty( $input['enable_moderation'] );
        $sanitized['auto_approve_posts'] = empty( $input['enable_moderation'] );
        $sanitized['post_edit_days'] = absint( $input['post_edit_days'] ?? 0 );

        $sanitized['enable_article_sync'] = ! empty( $input['enable_article_sync'] );
        $sanitized['sync_categories'] = isset( $input['sync_categories'] ) ? array_map( 'absint', (array) $input['sync_categories'] ) : array();
        $sanitized['sync_excerpt_length'] = absint( $input['sync_excerpt_length'] ?? 80 );
        $sanitized['sync_images'] = ! empty( $input['sync_images'] );

        // 侧边栏小工具与内容设置
        $sanitized['show_checkin_widget'] = ! empty( $input['show_checkin_widget'] );
        $sanitized['show_hot_topics'] = ! empty( $input['show_hot_topics'] );
        $sanitized['hot_topic_order'] = in_array( $input['hot_topic_order'] ?? '', array( 'comments', 'likes', 'favorites', 'usage' ) )
            ? $input['hot_topic_order'] : 'usage';
        $sanitized['hot_topic_count'] = absint( $input['hot_topic_count'] ?? 10 );
        $sanitized['show_community_stats'] = ! empty( $input['show_community_stats'] );
        $sanitized['enable_search'] = ! empty( $input['enable_search'] );
        $sanitized['show_hot_posts'] = ! empty( $input['show_hot_posts'] );
        $hot_default_window = absint( $input['hot_default_window'] ?? 7 );
        $sanitized['hot_default_window'] = in_array( $hot_default_window, array( 1, 7, 30 ), true ) ? $hot_default_window : 7;
        $sanitized['enable_featured_posts'] = ! empty( $input['enable_featured_posts'] );
        $sanitized['show_voting_posts'] = ! empty( $input['show_voting_posts'] );
        $sanitized['show_redpacket_posts'] = ! empty( $input['show_redpacket_posts'] );
        $sanitized['show_bounty_posts'] = ! empty( $input['show_bounty_posts'] );
        $sanitized['show_sidebar_categories'] = ! empty( $input['show_sidebar_categories'] );
        $sanitized['show_nav_growth'] = ! empty( $input['show_nav_growth'] );
        $sanitized['show_nav_mall'] = ! empty( $input['show_nav_mall'] );
        $sanitized['show_nav_prop_mall'] = ! empty( $input['show_nav_prop_mall'] );
        $sanitized['show_nav_following'] = ! empty( $input['show_nav_following'] );
        $sanitized['show_nav_notifications'] = ! empty( $input['show_nav_notifications'] );
        $sanitized['show_nav_messages'] = ! empty( $input['show_nav_messages'] );
        $sanitized['show_nav_tickets'] = ! empty( $input['show_nav_tickets'] );
        $sanitized['show_nav_profile'] = ! empty( $input['show_nav_profile'] );

        // 内容审核与敏感词
        $sanitized['enable_banned_words'] = ! empty( $input['enable_banned_words'] );
        $sanitized['banned_words'] = isset( $input['banned_words'] ) ? sanitize_textarea_field( $input['banned_words'] ) : '';
        $report_threshold = absint( $input['report_auto_hide_threshold'] ?? 3 );
        $sanitized['report_auto_hide_threshold'] = max( 0, min( 30, $report_threshold ) );

        // 媒体上传与同步
        $max_size = absint( $input['max_upload_size'] ?? 5 );
        $sanitized['max_upload_size'] = max( 1, min( 50, $max_size ) ); // 限制在 1-50MB
        $allowed_formats = isset( $input['allowed_image_formats'] ) ? sanitize_text_field( $input['allowed_image_formats'] ) : 'jpg,jpeg,png,webp,gif';
        // 过滤只保留有效字符 (字母、逗号)
        $sanitized['allowed_image_formats'] = preg_replace( '/[^a-zA-Z,]/', '', $allowed_formats );
        $sanitized['sync_to_wp_media_library'] = ! empty( $input['sync_to_wp_media_library'] );

        // 外观与主题预设
        $sanitized['theme_style'] = in_array( $input['theme_style'] ?? '', array( 'default', 'fresh', 'tech', 'gaming' ) )
            ? $input['theme_style'] : 'default';
        $sanitized['custom_primary_color'] = isset( $input['custom_primary_color'] ) ? sanitize_hex_color( $input['custom_primary_color'] ) : '';
        $sanitized['custom_accent_color'] = isset( $input['custom_accent_color'] ) ? sanitize_hex_color( $input['custom_accent_color'] ) : '';

        // 清理已废弃的历史配置键
        unset(
            $sanitized['uid_exclude_digits'],
            $sanitized['uid_skip_excluded_digits'],
            $sanitized['uid_divert_excluded_to_free'],
            $sanitized['enable_media_optimize'],
            $sanitized['media_optimize_quality'],
            $sanitized['media_convert_webp']
        );

        return $sanitized;
    }

    public function field_posts_per_page() {
        $value = qibbs_get_option( 'posts_per_page', 20 );
        echo '<input type="number" name="qibbs_options[posts_per_page]" value="' . esc_attr( $value ) . '" min="5" max="50" class="small-text">';
        echo '<p class="description">' . __( '每页显示的动态数量', 'qibbs-community' ) . '</p>';
    }

    public function field_infinite_scroll() {
        $checked = qibbs_get_option( 'infinite_scroll', true );
        echo '<label><input type="checkbox" name="qibbs_options[infinite_scroll]" value="1"' . checked( $checked, true, false ) . '> ' . __( '滚动到底部自动加载更多', 'qibbs-community' ) . '</label>';
    }

    public function field_enable_draft_autosave() {
        $checked = qibbs_get_option( 'enable_draft_autosave', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_draft_autosave]" value="1"' . checked( $checked, true, false ) . '> ' . __( '发帖输入时自动保存草稿到本地浏览器', 'qibbs-community' ) . '</label>';
    }

    public function field_enable_likes() {
        $checked = qibbs_get_option( 'enable_likes', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_likes]" value="1"' . checked( $checked, true, false ) . '> ' . __( '允许用户点赞动态', 'qibbs-community' ) . '</label>';
    }

    public function field_enable_favorites() {
        $checked = qibbs_get_option( 'enable_favorites', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_favorites]" value="1"' . checked( $checked, true, false ) . '> ' . __( '允许用户收藏动态', 'qibbs-community' ) . '</label>';
    }

    public function field_enable_comments() {
        $checked = qibbs_get_option( 'enable_comments', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_comments]" value="1"' . checked( $checked, true, false ) . '> ' . __( '允许用户评论动态', 'qibbs-community' ) . '</label>';
    }

    public function field_enable_follows() {
        $checked = qibbs_get_option( 'enable_follows', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_follows]" value="1"' . checked( $checked, true, false ) . '> ' . __( '允许用户互相关注', 'qibbs-community' ) . '</label>';
    }

    public function field_enable_messages() {
        $checked = qibbs_get_option( 'enable_messages', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_messages]" value="1"' . checked( $checked, true, false ) . '> ' . __( '允许用户发送私信', 'qibbs-community' ) . '</label>';
    }

    public function field_enable_notifications() {
        $checked = qibbs_get_option( 'enable_notifications', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_notifications]" value="1"' . checked( $checked, true, false ) . '> ' . __( '启用通知系统', 'qibbs-community' ) . '</label>';
    }

    public function field_enable_forum_subscription_notifications() {
        $checked = qibbs_get_option( 'enable_forum_subscription_notifications', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_forum_subscription_notifications]" value="1"' . checked( $checked, true, false ) . '> ' . __( '允许用户订阅圈子，并在圈子有新动态时接收通知', 'qibbs-community' ) . '</label>';
    }

    public function field_enable_discussion_status_system() {
        $checked = qibbs_get_option( 'enable_discussion_status_system', false );
        echo '<label><input type="checkbox" name="qibbs_options[enable_discussion_status_system]" value="1"' . checked( $checked, true, false ) . '> ' . __( '启用“讨论中 / 已解决 / 已关闭”状态体系', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '仅在下方选中的圈子生效；未选中的圈子按普通动态处理。', 'qibbs-community' ) . '</p>';
    }

    public function field_enable_owner_reopen_question() {
        $checked = qibbs_get_option( 'enable_owner_reopen_question', false );
        echo '<label><input type="checkbox" name="qibbs_options[enable_owner_reopen_question]" value="1"' . checked( $checked, true, false ) . '> ' . __( '允许楼主将“已解决”动态重开为讨论中', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '关闭后，楼主不可重开历史动态；管理员和圈子版主仍可执行管理性重开。', 'qibbs-community' ) . '</p>';
    }

    public function field_owner_reopen_max_days() {
        $value = absint( qibbs_get_option( 'owner_reopen_max_days', 30 ) );
        $value = max( 1, min( 3650, $value ) );
        echo '<input type="number" name="qibbs_options[owner_reopen_max_days]" value="' . esc_attr( $value ) . '" min="1" max="3650" class="small-text">';
        echo '<span> ' . __( '天', 'qibbs-community' ) . '</span>';
        echo '<p class="description">' . __( '仅在发布后 N 天内，楼主可执行“追问/重开”。超时后仅管理员/版主可重开。', 'qibbs-community' ) . '</p>';
    }

    public function field_discussion_status_forums() {
        global $wpdb;
        $selected_forums = qibbs_get_option( 'discussion_status_forums', array() );
        $selected_forums = is_array( $selected_forums ) ? array_map( 'absint', $selected_forums ) : array();
        $selected_forums = array_values( array_unique( array_filter( $selected_forums ) ) );

        $forums_table = Qibbs_Database::forums();
        $forums = $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM {$forums_table} WHERE 1 = %d ORDER BY sort_order ASC, id DESC", 1 ) );

        if ( empty( $forums ) ) {
            echo '<p class="description">' . __( '暂无圈子，请先在「圈子管理」中创建圈子。', 'qibbs-community' ) . '</p>';
            return;
        }

        echo '<div style="display:flex;flex-wrap:wrap;gap:10px 14px;">';
        foreach ( (array) $forums as $forum_item ) {
            $forum_id = (int) $forum_item->id;
            echo '<label style="display:inline-flex;align-items:center;gap:6px;">';
            echo '<input type="checkbox" name="qibbs_options[discussion_status_forums][]" value="' . esc_attr( $forum_id ) . '"' . checked( in_array( $forum_id, $selected_forums, true ), true, false ) . '>';
            echo esc_html( $forum_item->name ) . ' (#' . $forum_id . ')';
            echo '</label>';
        }
        echo '</div>';
        echo '<p class="description">' . __( '建议只为“问答/工单/支持”类圈子开启该体系，资讯圈子可保持普通讨论流。', 'qibbs-community' ) . '</p>';
    }

    public function field_enable_mentions() {
        $checked = qibbs_get_option( 'enable_mentions', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_mentions]" value="1"' . checked( $checked, true, false ) . '> ' . __( '输入 @ 时显示用户自动补全', 'qibbs-community' ) . '</label>';
    }

    public function field_mention_suggest_limit() {
        $value = absint( qibbs_get_option( 'mention_suggest_limit', 8 ) );
        $value = max( 3, min( 20, $value ) );
        echo '<input type="number" name="qibbs_options[mention_suggest_limit]" value="' . esc_attr( $value ) . '" min="3" max="20" class="small-text">';
        echo '<p class="description">' . __( '每次最多返回多少个 @提及候选用户', 'qibbs-community' ) . '</p>';
    }

    public function field_enable_reporting() {
        $checked = qibbs_get_option( 'enable_reporting', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_reporting]" value="1"' . checked( $checked, true, false ) . '> ' . __( '允许登录用户举报动态', 'qibbs-community' ) . '</label>';
    }

    public function field_enable_voting() {
        // 检查投票插件（Qivoting）是否已启用
        if ( ! defined( 'QIVOTING_VERSION' ) ) {
            echo '<p class="description" style="color:#d63638;">' . __( '请先安装并启用 "启灵投票" 插件', 'qibbs-community' ) . '</p>';
            return;
        }

        $checked = qibbs_get_option( 'enable_voting', false );
        echo '<label><input type="checkbox" name="qibbs_options[enable_voting]" value="1"' . checked( $checked, true, false ) . '> ' . __( '启用投票功能', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '启用后，用户发布动态时可以创建投票', 'qibbs-community' ) . '</p>';
    }

    public function field_enable_tickets() {
        $checked = qibbs_get_option( 'enable_tickets', false );
        echo '<label><input type="checkbox" name="qibbs_options[enable_tickets]" value="1"' . checked( $checked, true, false ) . '> ' . __( '在导航显示工单入口', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '启用后，左侧导航栏会显示"工单"入口', 'qibbs-community' ) . '</p>';
    }

    public function field_enable_vanity_mall() {
        $checked = qibbs_get_option( 'enable_vanity_mall', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_vanity_mall]" value="1"' . checked( $checked, true, false ) . '> ' . __( '在导航显示交易大厅入口', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '启用后，左侧导航栏会显示"交易大厅"入口，允许用户浏览官方商城、参与C2C寄售并管理号码背包', 'qibbs-community' ) . '</p>';
    }

    public function field_enable_prop_mall() {
        $checked = qibbs_get_option( 'enable_prop_mall', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_prop_mall]" value="1"' . checked( $checked, true, false ) . '> ' . __( '启用独立道具大厅', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '启用后，用户可消耗商城积分兑换个性名字颜色、圈子置顶推广等社区实用工具，仅支持积分结算，不支持现金提现与转让', 'qibbs-community' ) . '</p>';
    }

    public function field_prop_max_promoted_per_forum() {
        $value = absint( qibbs_get_option( 'prop_max_promoted_per_forum', 5 ) );
        echo '<input type="number" name="qibbs_options[prop_max_promoted_per_forum]" value="' . esc_attr( $value ) . '" min="1" max="50" class="small-text">';
        echo '<p class="description">' . __( '单个圈子内允许同时生效的付费推广置顶最大篇数（默认 5 篇，超出需等待当前置顶到期）', 'qibbs-community' ) . '</p>';
    }

    public function field_ticket_page() {
        $selected = qibbs_get_option( 'ticket_page', 0 );
        $pages = get_pages( array( 'post_status' => 'publish' ) );
        
        echo '<select name="qibbs_options[ticket_page]">';
        echo '<option value="0">' . __( '-- 请选择页面 --', 'qibbs-community' ) . '</option>';
        foreach ( $pages as $page ) {
            echo '<option value="' . esc_attr( $page->ID ) . '"' . selected( $selected, $page->ID, false ) . '>' . esc_html( $page->post_title ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . __( '选择使用 [qibbs_tickets] 短代码的页面', 'qibbs-community' ) . '</p>';
    }

    public function field_enable_ticket_push_notifications() {
        $checked = qibbs_get_option( 'enable_ticket_push_notifications', false );
        echo '<label><input type="checkbox" name="qibbs_options[enable_ticket_push_notifications]" value="1"' . checked( $checked, true, false ) . '> ' . __( '新工单创建后推送到飞书/钉钉群', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '仅推送“新工单”事件，不推送回复/状态变化。需启用「启灵消息推送」插件。', 'qibbs-community' ) . '</p>';
    }

    public function field_ticket_push_channels() {
        $selected_channels = qibbs_get_option( 'ticket_push_channels', array() );
        $selected_channels = is_array( $selected_channels ) ? $selected_channels : array();
        $selected_channels = array_values( array_unique( array_filter( array_map( function( $channel_id ) {
            return preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $channel_id );
        }, $selected_channels ) ) ) );

        $channels = $this->get_qilinghook_channel_choices();
        if ( empty( $channels ) ) {
            echo '<p class="description" style="color:#b45309;">' . __( '暂无可用通道。请先在「启灵消息推送」插件中新增并启用飞书/钉钉通道。', 'qibbs-community' ) . '</p>';
            return;
        }

        echo '<fieldset>';
        foreach ( $channels as $channel_id => $channel_label ) {
            echo '<label style="display:block; margin-bottom:6px;">';
            echo '<input type="checkbox" name="qibbs_options[ticket_push_channels][]" value="' . esc_attr( $channel_id ) . '"' . checked( in_array( $channel_id, $selected_channels, true ), true, false ) . '> ';
            echo esc_html( $channel_label );
            echo '</label>';
        }
        echo '</fieldset>';
        echo '<p class="description">' . __( '可多选。仅会向已启用的通道发送工单新建通知。', 'qibbs-community' ) . '</p>';
    }

    public function field_ticket_push_title_prefix() {
        $value = qibbs_get_option( 'ticket_push_title_prefix', '新工单通知' );
        echo '<input type="text" name="qibbs_options[ticket_push_title_prefix]" value="' . esc_attr( $value ) . '" class="regular-text" maxlength="60">';
        echo '<p class="description">' . __( '推送消息标题前缀，例如：新工单通知 / 紧急工单提醒。', 'qibbs-community' ) . '</p>';
    }

    public function section_growth_rewards() {
        $is_shop_active = class_exists( 'QilingShop_Points' );
        echo '<p class="description">' . esc_html__( '社区构建「成长值 (EXP)」与「商城积分 (Points)」双轨驱动体系：成长值决定用户论坛等级、称号与专属标牌；商城积分直接联动启灵积分商城账户用于兑换消费。双轨完全解耦，支持任意互动行为单边赠送（如只加成长值不送积分）或双边齐发，数值与开关均可自由定制，绝不写死。', 'qibbs-community' ) . '</p>';
        if ( ! $is_shop_active ) {
            echo '<p class="description" style="color:#b45309;">' . esc_html__( '提示：当前未检测到「启灵积分商城」插件；积分项配置会被妥善保存，但在商城插件启用前仅累加社区成长值。', 'qibbs-community' ) . '</p>';
        }
    }

    public function section_points_integration() {
        $this->section_growth_rewards();
    }

    public function field_enable_growth_levels() {
        $checked = function_exists( 'qibbs_is_growth_levels_enabled' ) ? qibbs_is_growth_levels_enabled() : true;
        echo '<label><input type="checkbox" name="qibbs_options[enable_growth_levels]" value="1"' . checked( $checked, true, false ) . '> ' . esc_html__( '启用社区成长等级体系', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . esc_html__( '开启后，前台动态作者、评论者将展示等级标牌，个人主页与侧边栏将展示 EXP 升级经验条。关闭后优雅回退旧版展示。', 'qibbs-community' ) . '</p>';
    }

    public function field_growth_levels_table() {
        $levels = function_exists( 'qibbs_get_growth_levels_config' ) ? qibbs_get_growth_levels_config() : array();
        ?>
        <div class="qibbs-levels-config-wrap" style="max-width: 1080px; overflow-x: auto;">
            <table class="widefat striped" style="border-radius: 8px; overflow: hidden; border: 1px solid #ccd0d4; border-collapse: separate; border-spacing: 0;">
                <thead>
                    <tr style="background: #f8fafc;">
                        <th style="width: 50px; text-align: center; padding: 10px;"><?php esc_html_e( '等级', 'qibbs-community' ); ?></th>
                        <th style="width: 120px; padding: 10px;"><?php esc_html_e( '称号名称', 'qibbs-community' ); ?></th>
                        <th style="width: 120px; padding: 10px;"><?php esc_html_e( '升级所需经验', 'qibbs-community' ); ?></th>
                        <th style="min-width: 220px; padding: 10px;"><?php esc_html_e( '专属特权说明 (前台展示)', 'qibbs-community' ); ?></th>
                        <th style="width: 90px; text-align: center; padding: 10px;"><?php esc_html_e( '时发动态限额', 'qibbs-community' ); ?><br><span style="font-size: 10px; font-weight: normal; color: #64748b;"><?php esc_html_e( '篇/时 (0不限)', 'qibbs-community' ); ?></span></th>
                        <th style="width: 90px; text-align: center; padding: 10px;"><?php esc_html_e( '时回帖限额', 'qibbs-community' ); ?><br><span style="font-size: 10px; font-weight: normal; color: #64748b;"><?php esc_html_e( '条/时 (0不限)', 'qibbs-community' ); ?></span></th>
                        <th style="width: 80px; padding: 10px;"><?php esc_html_e( '标牌配色', 'qibbs-community' ); ?></th>
                        <th style="width: 120px; text-align: center; padding: 10px;"><?php esc_html_e( '实时预览', 'qibbs-community' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $levels as $lvl => $meta ) : 
                        $lvl = (int) $lvl;
                        $is_base = ( 1 === $lvl );
                        $color = esc_attr( $meta['color'] );
                        $name  = esc_attr( $meta['name'] );
                        $min_exp = (int) $meta['min_exp'];
                        $privilege = esc_textarea( $meta['privilege'] ?? '' );
                        $post_limit = isset( $meta['post_hourly_limit'] ) ? (int) $meta['post_hourly_limit'] : 3;
                        $comment_limit = isset( $meta['comment_hourly_limit'] ) ? (int) $meta['comment_hourly_limit'] : 10;
                    ?>
                    <tr>
                        <td style="text-align: center; font-weight: bold; color: <?php echo $color; ?>; padding: 10px; vertical-align: middle;">
                            Lv.<?php echo $lvl; ?>
                            <input type="hidden" name="qibbs_options[growth_levels_config][<?php echo $lvl; ?>][level]" value="<?php echo $lvl; ?>">
                            <input type="hidden" name="qibbs_options[growth_levels_config][<?php echo $lvl; ?>][badge_class]" value="qibbs-level-<?php echo $lvl; ?>">
                        </td>
                        <td style="padding: 10px; vertical-align: middle;">
                            <input type="text" name="qibbs_options[growth_levels_config][<?php echo $lvl; ?>][name]" value="<?php echo $name; ?>" class="regular-text" style="width: 100%; max-width: 120px;" maxlength="20" required>
                        </td>
                        <td style="padding: 10px; vertical-align: middle;">
                            <?php if ( $is_base ) : ?>
                                <input type="number" name="qibbs_options[growth_levels_config][<?php echo $lvl; ?>][min_exp]" value="0" readonly class="small-text" style="background: #f0f0f1; cursor: not-allowed; width: 55px;">
                                <span class="description" style="margin-left: 4px;"><?php esc_html_e( '0 经验值', 'qibbs-community' ); ?></span>
                            <?php else : ?>
                                <input type="number" name="qibbs_options[growth_levels_config][<?php echo $lvl; ?>][min_exp]" value="<?php echo $min_exp; ?>" min="1" max="9999999" step="1" class="small-text" style="width: 65px;" required>
                                <span class="description" style="margin-left: 4px;"><?php esc_html_e( '经验值', 'qibbs-community' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 10px; vertical-align: middle;">
                            <textarea name="qibbs_options[growth_levels_config][<?php echo $lvl; ?>][privilege]" rows="2" style="width: 100%; font-size: 12px; resize: vertical;" placeholder="<?php esc_attr_e( '自定义该等级尊享特权说明...', 'qibbs-community' ); ?>"><?php echo $privilege; ?></textarea>
                        </td>
                        <td style="padding: 10px; vertical-align: middle; text-align: center;">
                            <input type="number" min="0" max="9999" name="qibbs_options[growth_levels_config][<?php echo $lvl; ?>][post_hourly_limit]" value="<?php echo esc_attr( $post_limit ); ?>" class="small-text" style="width: 60px; text-align: center;">
                        </td>
                        <td style="padding: 10px; vertical-align: middle; text-align: center;">
                            <input type="number" min="0" max="9999" name="qibbs_options[growth_levels_config][<?php echo $lvl; ?>][comment_hourly_limit]" value="<?php echo esc_attr( $comment_limit ); ?>" class="small-text" style="width: 60px; text-align: center;">
                        </td>
                        <td style="padding: 10px; vertical-align: middle;">
                            <input type="text" name="qibbs_options[growth_levels_config][<?php echo $lvl; ?>][color]" value="<?php echo $color; ?>" class="small-text" style="width: 75px;" maxlength="7">
                        </td>
                        <td style="text-align: center; padding: 10px; vertical-align: middle;">
                            <span class="qibbs-level-badge qibbs-level-<?php echo $lvl; ?>" style="display: inline-flex; align-items: center; justify-content: center; gap: 4px; padding: 0 8px; height: 20px; border-radius: 9999px; font-size: 11px; font-weight: 700; border: 1px solid <?php echo $color; ?>; color: <?php echo $color; ?>; background: rgba(0,0,0,0.02);">
                                <svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" aria-hidden="true"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>Lv.<?php echo $lvl; ?> <?php echo esc_html( $meta['name'] ); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description" style="margin-top: 8px;">
                <?php esc_html_e( '所有等级名称、经验门槛、专属特权说明、每小时发帖/回帖频控限额（0为不限制）与配色均可自由定制。用户发布互动累积经验值达到门槛后将自动晋升并点亮对应等级标牌。', 'qibbs-community' ); ?>
            </p>
        </div>
        <?php
    }

    public function field_enable_points_integration() {
        $checked = qibbs_get_option( 'enable_points_integration', false );
        echo '<label><input type="checkbox" name="qibbs_options[enable_points_integration]" value="1"' . checked( $checked, true, false ) . '> ' . __( '启用商城积分联动', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '开启后，社区互动行为将依据下方矩阵向启灵积分商城发放真实积分账户余额。', 'qibbs-community' ) . '</p>';
    }

    public function field_points_integration_allow_staff() {
        $checked = qibbs_get_option( 'points_integration_allow_staff', false );
        echo '<label><input type="checkbox" name="qibbs_options[points_integration_allow_staff]" value="1"' . checked( $checked, true, false ) . '> ' . __( '允许管理员和圈子版主也获得积分与成长值奖励', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '关闭后，拥有管理员或版主权限的用户在社区内的行为将跳过发分与加经验。', 'qibbs-community' ) . '</p>';
    }

    public function field_show_form_reward_hints() {
        $checked = (bool) qibbs_get_option( 'show_form_reward_hints', false );
        echo '<label><input type="checkbox" name="qibbs_options[show_form_reward_hints]" value="1"' . checked( $checked, true, false ) . '> ' . esc_html__( '在前台发帖和评论输入框下方显示激励标签（如“评论通过可得 +1 灵力”）', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . esc_html__( '默认关闭。关闭后前台评论区与发帖框保持纯净清爽，不显示干扰性提示标签（规则与激励已集中在顶部“成长与积分中心”展示）。', 'qibbs-community' ) . '</p>';
    }

    public function field_dual_rewards_table() {
        $definitions = function_exists( 'qibbs_get_dual_reward_definitions' ) ? qibbs_get_dual_reward_definitions() : array();
        ?>
        <div class="qibbs-dual-rewards-matrix-wrap" style="max-width: 1040px; overflow-x: auto;">
            <table class="widefat striped" style="border-radius: 8px; overflow: hidden; border: 1px solid #ccd0d4; border-collapse: separate; border-spacing: 0;">
                <thead>
                    <tr style="background: #f8fafc;">
                        <th style="min-width: 180px; padding: 10px;"><?php esc_html_e( '互动动作 / 触发节点', 'qibbs-community' ); ?></th>
                        <th style="min-width: 290px; background: rgba(99, 102, 241, 0.05); padding: 10px;"><?php esc_html_e( '社区成长值 (EXP) 配置', 'qibbs-community' ); ?></th>
                        <th style="min-width: 290px; background: rgba(245, 158, 11, 0.05); padding: 10px;"><?php esc_html_e( '启灵商城积分 (Points) 赠送', 'qibbs-community' ); ?></th>
                        <th style="width: 120px; text-align: center; padding: 10px;"><?php esc_html_e( '当前激励模式', 'qibbs-community' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $definitions as $action_key => $def ) : 
                        $settings = function_exists( 'qibbs_get_dual_reward_settings' ) ? qibbs_get_dual_reward_settings( $action_key ) : array();
                        $exp_enabled_key = 'reward_' . $action_key . '_exp_enabled';
                        $exp_amount_key  = 'reward_' . $action_key . '_exp_amount';
                        $exp_daily_key   = 'reward_' . $action_key . '_exp_daily_limit';

                        $pts_enabled_key = 'points_reward_' . $action_key . '_enabled';
                        $pts_amount_key  = 'points_reward_' . $action_key . '_amount';
                        $pts_daily_key   = 'points_reward_' . $action_key . '_daily_limit';

                        $is_exp_on = ! empty( $settings['exp_enabled'] );
                        $is_pts_on = ! empty( $settings['pts_enabled'] );

                        $mode_label = __( '未启用', 'qibbs-community' );
                        $mode_style = 'background:#f1f5f9;color:#64748b;';
                        if ( $is_exp_on && $is_pts_on ) {
                            $mode_label = __( '双轨齐赠', 'qibbs-community' );
                            $mode_style = 'background:#dcfce7;color:#15803d;border:1px solid #86efac;';
                        } elseif ( $is_exp_on && ! $is_pts_on ) {
                            $mode_label = __( '单边成长值', 'qibbs-community' );
                            $mode_style = 'background:#e0e7ff;color:#4338ca;border:1px solid #a5b4fc;';
                        } elseif ( ! $is_exp_on && $is_pts_on ) {
                            $mode_label = __( '单边商城积分', 'qibbs-community' );
                            $mode_style = 'background:#fef3c7;color:#b45309;border:1px solid #fde68a;';
                        }
                    ?>
                    <tr>
                        <td style="padding: 10px;">
                            <strong><?php echo esc_html( $def['label'] ); ?></strong>
                            <p class="description" style="margin: 4px 0 0; font-size: 12px;"><?php echo esc_html( $def['description'] ); ?></p>
                        </td>
                        <td style="background: rgba(99, 102, 241, 0.02); padding: 10px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 500;">
                                <input type="checkbox" name="qibbs_options[<?php echo esc_attr( $exp_enabled_key ); ?>]" value="1" <?php checked( $is_exp_on, true ); ?>>
                                <?php esc_html_e( '增加社区成长值', 'qibbs-community' ); ?>
                            </label>
                            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px;">
                                    <span><?php esc_html_e( '单次 +', 'qibbs-community' ); ?></span>
                                    <input type="number" name="qibbs_options[<?php echo esc_attr( $exp_amount_key ); ?>]" value="<?php echo esc_attr( $settings['exp_amount'] ?? 0 ); ?>" min="0" max="99999" class="small-text">
                                    <span>EXP</span>
                                </label>
                                <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px;">
                                    <span><?php esc_html_e( '日上限', 'qibbs-community' ); ?></span>
                                    <input type="number" name="qibbs_options[<?php echo esc_attr( $exp_daily_key ); ?>]" value="<?php echo esc_attr( $settings['exp_daily_limit'] ?? 0 ); ?>" min="0" max="9999" class="small-text">
                                    <span><?php esc_html_e( '次', 'qibbs-community' ); ?></span>
                                </label>
                            </div>
                        </td>
                        <td style="background: rgba(245, 158, 11, 0.02); padding: 10px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 500;">
                                <input type="checkbox" name="qibbs_options[<?php echo esc_attr( $pts_enabled_key ); ?>]" value="1" <?php checked( $is_pts_on, true ); ?>>
                                <?php esc_html_e( '赠送商城积分', 'qibbs-community' ); ?>
                            </label>
                            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px;">
                                    <span><?php esc_html_e( '单次 +', 'qibbs-community' ); ?></span>
                                    <input type="number" name="qibbs_options[<?php echo esc_attr( $pts_amount_key ); ?>]" value="<?php echo esc_attr( $settings['pts_amount'] ?? 0 ); ?>" min="0" max="99999" step="0.01" class="small-text">
                                    <span><?php esc_html_e( '分', 'qibbs-community' ); ?></span>
                                </label>
                                <label style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px;">
                                    <span><?php esc_html_e( '日上限', 'qibbs-community' ); ?></span>
                                    <input type="number" name="qibbs_options[<?php echo esc_attr( $pts_daily_key ); ?>]" value="<?php echo esc_attr( $settings['pts_daily_limit'] ?? 0 ); ?>" min="0" max="9999" class="small-text">
                                    <span><?php esc_html_e( '次', 'qibbs-community' ); ?></span>
                                </label>
                            </div>
                        </td>
                        <td style="text-align: center; vertical-align: middle; padding: 10px;">
                            <span style="display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; <?php echo $mode_style; ?>">
                                <?php echo esc_html( $mode_label ); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description" style="margin-top: 8px;">
                <?php esc_html_e( '日上限填 0 表示不限制每日发放次数。所有数值与开关独立生效，完全支持单边奖励（仅加成长值或仅送积分）或双边齐发。', 'qibbs-community' ); ?>
            </p>
        </div>
        <?php
    }

    private function render_points_reward_field( $reward_key ) {
        $settings = qibbs_get_points_reward_settings( $reward_key );
        if ( empty( $settings ) || ! is_array( $settings ) ) {
            echo '<p class="description">' . esc_html__( '奖励配置不存在。', 'qibbs-community' ) . '</p>';
            return;
        }

        $enabled_key = qibbs_get_points_reward_option_key( $reward_key, 'enabled' );
        $amount_key = qibbs_get_points_reward_option_key( $reward_key, 'amount' );
        $daily_limit_key = qibbs_get_points_reward_option_key( $reward_key, 'daily_limit' );

        echo '<fieldset>';
        echo '<label><input type="checkbox" name="qibbs_options[' . esc_attr( $enabled_key ) . ']" value="1"' . checked( ! empty( $settings['enabled'] ), true, false ) . '> ' . esc_html__( '启用该奖励', 'qibbs-community' ) . '</label>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:12px 18px;align-items:flex-end;margin-top:10px;">';
        echo '<label style="display:flex;flex-direction:column;gap:6px;">';
        echo '<span>' . esc_html__( '奖励积分', 'qibbs-community' ) . '</span>';
        echo '<input type="number" name="qibbs_options[' . esc_attr( $amount_key ) . ']" value="' . esc_attr( $settings['amount'] ) . '" min="0" max="999999" step="0.01" class="small-text">';
        echo '</label>';
        echo '<label style="display:flex;flex-direction:column;gap:6px;">';
        echo '<span>' . esc_html__( '每日上限', 'qibbs-community' ) . '</span>';
        echo '<input type="number" name="qibbs_options[' . esc_attr( $daily_limit_key ) . ']" value="' . esc_attr( (int) $settings['daily_limit'] ) . '" min="0" max="9999" step="1" class="small-text">';
        echo '</label>';
        echo '</div>';
        echo '<p class="description">' . esc_html( (string) $settings['description'] ) . '</p>';
        echo '<p class="description">' . esc_html__( '每日上限填 0 表示不限制；同一条内容/评论/工单只会按关联 ID 记一次奖励。', 'qibbs-community' ) . '</p>';
        echo '</fieldset>';
    }

    public function field_points_reward_post_publish() {
        $this->render_points_reward_field( 'post_publish' );
    }

    public function field_points_reward_comment_approved() {
        $this->render_points_reward_field( 'comment_approved' );
    }

    public function field_points_reward_best_answer() {
        $this->render_points_reward_field( 'best_answer' );
    }

    public function field_points_reward_featured_post() {
        $this->render_points_reward_field( 'featured_post' );
    }

    public function field_points_reward_ticket_published() {
        $this->render_points_reward_field( 'ticket_published' );
    }

    public function section_interactive_points() {
        $points_name = qibbs_get_points_name();
        echo '<p class="description">' . sprintf(
            esc_html__( '配置社区「悬赏帖」与「红包帖」交互规则。发帖扣除/冻结%s，结算或抢包即时派发至答主与参与者账户。未领完红包在到期后自动退回原作者；悬赏帖到期若有回复，强制进入管理员仲裁结赏/退款，杜绝发帖人白嫖社区答主心血。', 'qibbs-community' ),
            esc_html( $points_name )
        ) . '</p>';
    }

    public function field_enable_bounty_post() {
        $checked = (bool) qibbs_get_option( 'enable_bounty_post', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_bounty_post]" value="1"' . checked( $checked, true, false ) . '> ' . esc_html__( '启用悬赏帖类型', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . esc_html__( '关闭后，前台发帖类型选择框将隐藏悬赏帖选项。', 'qibbs-community' ) . '</p>';
    }

    public function field_bounty_amount_range() {
        $min = (float) qibbs_get_option( 'bounty_min_amount', 5 );
        $max = (float) qibbs_get_option( 'bounty_max_amount', 1000 );
        $points_name = qibbs_get_points_name();
        echo '<div style="display:flex;align-items:center;gap:8px;">';
        echo '<label>' . esc_html__( '最低悬赏：', 'qibbs-community' ) . '<input type="number" step="0.01" min="0.01" name="qibbs_options[bounty_min_amount]" value="' . esc_attr( $min ) . '" class="small-text"></label>';
        echo '<span>-</span>';
        echo '<label>' . esc_html__( '最高悬赏：', 'qibbs-community' ) . '<input type="number" step="0.01" min="0.01" name="qibbs_options[bounty_max_amount]" value="' . esc_attr( $max ) . '" class="small-text"></label>';
        echo '<span>' . esc_html( $points_name ) . '</span>';
        echo '</div>';
        echo '<p class="description">' . esc_html__( '限制发帖人设定的悬赏金额区间。', 'qibbs-community' ) . '</p>';
    }

    public function field_bounty_expire_days() {
        $days = absint( qibbs_get_option( 'bounty_expire_days', 7 ) );
        echo '<input type="number" min="1" max="90" name="qibbs_options[bounty_expire_days]" value="' . esc_attr( $days ) . '" class="small-text"> ' . esc_html__( '天', 'qibbs-community' );
        echo '<p class="description">' . esc_html__( '发帖人在此期限内可自主采纳最佳答案并结算悬赏；超期未采纳将转入待仲裁或退款流程。', 'qibbs-community' ) . '</p>';
    }

    public function field_bounty_arbitration_rules() {
        $auto_refund = (bool) qibbs_get_option( 'bounty_auto_refund_on_zero_comments', true );
        $require_arbitration = (bool) qibbs_get_option( 'bounty_require_admin_arbitration', true );
        echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="qibbs_options[bounty_auto_refund_on_zero_comments]" value="1"' . checked( $auto_refund, true, false ) . '> ' . esc_html__( '零回复到期自动全额退还给楼主', 'qibbs-community' ) . '</label>';
        echo '<label style="display:block;"><input type="checkbox" name="qibbs_options[bounty_require_admin_arbitration]" value="1"' . checked( $require_arbitration, true, false ) . '> ' . esc_html__( '有回帖且超期未采纳时：必须由管理员审核仲裁（代为采纳结赏或退还）', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . esc_html__( '强烈建议开启强制仲裁：防止楼主装死故意拖延至超时以白嫖答主优质解答。', 'qibbs-community' ) . '</p>';
    }

    public function field_bounty_min_level_to_post() {
        $level = absint( qibbs_get_option( 'bounty_min_level_to_post', 1 ) );
        echo '<label>' . esc_html__( '最低成长等级：Lv.', 'qibbs-community' ) . '<input type="number" min="1" max="10" name="qibbs_options[bounty_min_level_to_post]" value="' . esc_attr( $level ) . '" class="small-text"></label>';
        echo '<p class="description">' . esc_html__( '限制新手发帖门槛，防止未认证或低级小号发布恶意悬赏。', 'qibbs-community' ) . '</p>';
    }

    public function field_enable_redpacket_post() {
        $checked = (bool) qibbs_get_option( 'enable_redpacket_post', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_redpacket_post]" value="1"' . checked( $checked, true, false ) . '> ' . esc_html__( '启用红包帖类型', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . esc_html__( '开启后用户可发布互动红包（支持回帖即领与随机抽奖两种模式）。', 'qibbs-community' ) . '</p>';
    }

    public function field_redpacket_amount_range() {
        $min = (float) qibbs_get_option( 'redpacket_min_total_amount', 10 );
        $max = (float) qibbs_get_option( 'redpacket_max_total_amount', 10000 );
        $points_name = qibbs_get_points_name();
        echo '<div style="display:flex;align-items:center;gap:8px;">';
        echo '<label>' . esc_html__( '最低总额：', 'qibbs-community' ) . '<input type="number" step="0.01" min="0.01" name="qibbs_options[redpacket_min_total_amount]" value="' . esc_attr( $min ) . '" class="small-text"></label>';
        echo '<span>-</span>';
        echo '<label>' . esc_html__( '最高总额：', 'qibbs-community' ) . '<input type="number" step="0.01" min="0.01" name="qibbs_options[redpacket_max_total_amount]" value="' . esc_attr( $max ) . '" class="small-text"></label>';
        echo '<span>' . esc_html( $points_name ) . '</span>';
        echo '</div>';
    }

    public function field_redpacket_count_range() {
        $min = absint( qibbs_get_option( 'redpacket_min_count', 5 ) );
        $max = absint( qibbs_get_option( 'redpacket_max_count', 100 ) );
        echo '<div style="display:flex;align-items:center;gap:8px;">';
        echo '<label>' . esc_html__( '最少份数：', 'qibbs-community' ) . '<input type="number" min="1" name="qibbs_options[redpacket_min_count]" value="' . esc_attr( $min ) . '" class="small-text"></label>';
        echo '<span>-</span>';
        echo '<label>' . esc_html__( '最多份数：', 'qibbs-community' ) . '<input type="number" min="1" name="qibbs_options[redpacket_max_count]" value="' . esc_attr( $max ) . '" class="small-text"></label>';
        echo '<span>' . esc_html__( '份', 'qibbs-community' ) . '</span>';
        echo '</div>';
    }

    public function field_redpacket_min_single_amount() {
        $min_single = (float) qibbs_get_option( 'redpacket_min_single_amount', 0.01 );
        $points_name = qibbs_get_points_name();
        echo '<input type="number" step="0.01" min="0.01" name="qibbs_options[redpacket_min_single_amount]" value="' . esc_attr( $min_single ) . '" class="small-text"> ' . esc_html( $points_name );
        echo '<p class="description">' . esc_html__( '每份红包切片拆分的最低保底积分，防止因份数过多导致单份为零。', 'qibbs-community' ) . '</p>';
    }

    public function field_redpacket_expire_hours() {
        $hours = absint( qibbs_get_option( 'redpacket_expire_hours', 24 ) );
        echo '<input type="number" min="1" max="720" name="qibbs_options[redpacket_expire_hours]" value="' . esc_attr( $hours ) . '" class="small-text"> ' . esc_html__( '小时', 'qibbs-community' );
        echo '<p class="description">' . esc_html__( '红包有效时长，超时后未被领取的剩余积分将由 WP-Cron 自动原路退还至发帖人账户。', 'qibbs-community' ) . '</p>';
    }

    public function field_redpacket_permissions() {
        $allow_author = (bool) qibbs_get_option( 'redpacket_allow_author_claim', false );
        $min_level = absint( qibbs_get_option( 'redpacket_min_level_to_claim', 1 ) );
        echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="qibbs_options[redpacket_allow_author_claim]" value="1"' . checked( $allow_author, true, false ) . '> ' . esc_html__( '允许作者自己参与抢自己发布的红包', 'qibbs-community' ) . '</label>';
        echo '<label>' . esc_html__( '抢红包最低成长等级：Lv.', 'qibbs-community' ) . '<input type="number" min="1" max="10" name="qibbs_options[redpacket_min_level_to_claim]" value="' . esc_attr( $min_level ) . '" class="small-text"></label>';
        echo '<p class="description">' . esc_html__( '建议设置等级限制，防止羊毛党注册批量小号刷取红包。', 'qibbs-community' ) . '</p>';
    }

    public function field_who_can_post() {
        $value = qibbs_get_option( 'who_can_post', 'logged_in' );
        ?>
        <select name="qibbs_options[who_can_post]">
            <option value="logged_in" <?php selected( $value, 'logged_in' ); ?>><?php _e( '登录用户', 'qibbs-community' ); ?></option>
            <option value="subscriber" <?php selected( $value, 'subscriber' ); ?>><?php _e( '订阅者及以上', 'qibbs-community' ); ?></option>
            <option value="contributor" <?php selected( $value, 'contributor' ); ?>><?php _e( '投稿者及以上', 'qibbs-community' ); ?></option>
            <option value="author" <?php selected( $value, 'author' ); ?>><?php _e( '作者及以上', 'qibbs-community' ); ?></option>
        </select>
        <p class="description"><?php _e( '哪些用户可以发布社区动态', 'qibbs-community' ); ?></p>
        <?php
    }

    public function field_allow_guest_access() {
        $checked = qibbs_get_option( 'allow_guest_access', false );
        echo '<label><input type="checkbox" name="qibbs_options[allow_guest_access]" value="1"' . checked( $checked, true, false ) . '> ' . __( '允许游客查看社区内容（游客仍不可发帖、评论、互动）', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '关闭后，游客访问社区时将显示登录提示卡片', 'qibbs-community' ) . '</p>';
    }

    public function field_guest_access_notice_title() {
        $value = qibbs_get_option( 'guest_access_notice_title', __( '当前功能仅限已登录用户访问', 'qibbs-community' ) );
        echo '<input type="text" name="qibbs_options[guest_access_notice_title]" value="' . esc_attr( $value ) . '" class="regular-text">';
    }

    public function field_guest_access_notice_text() {
        $value = qibbs_get_option( 'guest_access_notice_text', __( '请先登录后继续访问社区功能。', 'qibbs-community' ) );
        echo '<textarea name="qibbs_options[guest_access_notice_text]" rows="3" class="large-text">' . esc_textarea( $value ) . '</textarea>';
        echo '<p class="description">' . __( '当“游客访问社区”关闭时显示，支持简短说明文案。', 'qibbs-community' ) . '</p>';
    }

    public function field_blacklist_access_mode() {
        $mode = qibbs_get_option( 'blacklist_access_mode', 'read_only' );
        ?>
        <select name="qibbs_options[blacklist_access_mode]">
            <option value="read_only" <?php selected( $mode, 'read_only' ); ?>><?php esc_html_e( '仅禁止发帖/评论/私信（可浏览）', 'qibbs-community' ); ?></option>
            <option value="deny_all" <?php selected( $mode, 'deny_all' ); ?>><?php esc_html_e( '完全禁止访问社区', 'qibbs-community' ); ?></option>
        </select>
        <p class="description"><?php esc_html_e( '黑名单用户始终不可发帖、评论、私信；此处可额外控制是否允许浏览社区页面。', 'qibbs-community' ); ?></p>
        <?php
    }

    public function field_blacklist_notice_title() {
        $value = qibbs_get_option( 'blacklist_notice_title', __( '你的账号已被限制访问社区', 'qibbs-community' ) );
        echo '<input type="text" name="qibbs_options[blacklist_notice_title]" value="' . esc_attr( $value ) . '" class="regular-text">';
    }

    public function field_blacklist_notice_text() {
        $value = qibbs_get_option( 'blacklist_notice_text', __( '如有疑问请联系管理员。', 'qibbs-community' ) );
        echo '<textarea name="qibbs_options[blacklist_notice_text]" rows="3" class="large-text">' . esc_textarea( $value ) . '</textarea>';
        echo '<p class="description">' . __( '当黑名单访问模式为“完全禁止访问社区”时显示。', 'qibbs-community' ) . '</p>';
    }

    public function field_enable_level_rate_limits() {
        $checked = (bool) qibbs_get_option( 'enable_level_rate_limits', true );
        echo '<label><input type="checkbox" name="qibbs_options[enable_level_rate_limits]" value="1"' . checked( $checked, true, false ) . '> ' . esc_html__( '启用等级发帖/回帖安全频控矩阵（等级越高限额越大）', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . esc_html__( '开启后，发帖与回帖小时频控将严格遵循上方【成长等级阶梯设置】中针对各等级配置的额度（填 0 表示免受限制，管理员永久豁免）；关闭后回退至下方全局统一定额。', 'qibbs-community' ) . '</p>';
    }

    public function field_post_rate_limit_minute() {
        $value = absint( qibbs_get_option( 'post_rate_limit_minute', 2 ) );
        $value = max( 0, min( 60, $value ) );
        echo '<input type="number" name="qibbs_options[post_rate_limit_minute]" value="' . esc_attr( $value ) . '" min="0" max="60" class="small-text">';
        echo '<p class="description">' . __( '每分钟最多发帖数，0 表示关闭分钟限流', 'qibbs-community' ) . '</p>';
    }

    public function field_post_rate_limit() {
        $value = absint( qibbs_get_option( 'post_rate_limit', 5 ) );
        $value = max( 1, min( 500, $value ) );
        echo '<input type="number" name="qibbs_options[post_rate_limit]" value="' . esc_attr( $value ) . '" min="1" max="500" class="small-text">';
        echo '<p class="description">' . __( '每小时最多发帖数', 'qibbs-community' ) . '</p>';
    }

    public function field_comment_rate_limit_minute() {
        $value = absint( qibbs_get_option( 'comment_rate_limit_minute', 6 ) );
        $value = max( 0, min( 300, $value ) );
        echo '<input type="number" name="qibbs_options[comment_rate_limit_minute]" value="' . esc_attr( $value ) . '" min="0" max="300" class="small-text">';
        echo '<p class="description">' . __( '每分钟最多评论数，0 表示关闭分钟限流', 'qibbs-community' ) . '</p>';
    }

    public function field_comment_rate_limit() {
        $value = absint( qibbs_get_option( 'comment_rate_limit', 20 ) );
        $value = max( 1, min( 2000, $value ) );
        echo '<input type="number" name="qibbs_options[comment_rate_limit]" value="' . esc_attr( $value ) . '" min="1" max="2000" class="small-text">';
        echo '<p class="description">' . __( '每小时最多评论数', 'qibbs-community' ) . '</p>';
    }

    public function field_ticket_rate_limit_minute() {
        $value = absint( qibbs_get_option( 'ticket_rate_limit_minute', 1 ) );
        $value = max( 0, min( 60, $value ) );
        echo '<input type="number" name="qibbs_options[ticket_rate_limit_minute]" value="' . esc_attr( $value ) . '" min="0" max="60" class="small-text">';
        echo '<p class="description">' . __( '每分钟最多提交工单数，0 表示关闭分钟限流。', 'qibbs-community' ) . '</p>';
    }

    public function field_ticket_rate_limit() {
        $value = absint( qibbs_get_option( 'ticket_rate_limit', 3 ) );
        $value = max( 0, min( 500, $value ) );
        echo '<input type="number" name="qibbs_options[ticket_rate_limit]" value="' . esc_attr( $value ) . '" min="0" max="500" class="small-text">';
        echo '<p class="description">' . __( '每小时最多提交工单数，0 表示关闭小时限流。', 'qibbs-community' ) . '</p>';
    }

    public function field_ticket_block_if_open() {
        $checked = qibbs_get_option( 'ticket_block_if_open', false );
        echo '<label><input type="checkbox" name="qibbs_options[ticket_block_if_open]" value="1"' . checked( $checked, true, false ) . '> ' . __( '用户存在未关闭工单时，禁止继续提交新工单', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '开启后将严格限制为“先处理旧工单，再提新工单”。关闭时按上面的限流次数控制。', 'qibbs-community' ) . '</p>';
    }

    public function field_enable_moderation() {
        $checked = qibbs_get_option( 'enable_moderation', false );
        echo '<label><input type="checkbox" name="qibbs_options[enable_moderation]" value="1"' . checked( $checked, true, false ) . '> ' . __( '新发布的内容需要管理员审核', 'qibbs-community' ) . '</label>';
    }

    public function field_post_edit_days() {
        $value = qibbs_get_option( 'post_edit_days', 0 );
        echo '<input type="number" name="qibbs_options[post_edit_days]" value="' . esc_attr( $value ) . '" min="0" max="365" class="small-text">';
        echo '<span> ' . __( '天', 'qibbs-community' ) . '</span>';
        echo '<p class="description">' . __( '用户发布动态后可编辑的天数。设置为 0 表示禁止编辑。', 'qibbs-community' ) . '</p>';
    }

    public function field_enable_article_sync() {
        $checked = qibbs_get_option( 'enable_article_sync', false );
        echo '<label><input type="checkbox" name="qibbs_options[enable_article_sync]" value="1"' . checked( $checked, true, false ) . '> ' . __( '启用文章同步功能（手动同步，在社区左侧边栏显示文章分类入口）', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '启用后可在下方手动同步历史文章到社区', 'qibbs-community' ) . '</p>';
    }

    public function field_sync_categories() {
        $selected = qibbs_get_option( 'sync_categories', array() );
        $categories = get_categories( array( 'hide_empty' => false ) );
        
        echo '<div class="qibbs-category-checkboxes" style="max-height:200px;overflow-y:auto;border:1px solid #ddd;padding:10px;border-radius:4px;">';
        echo '<label style="display:block;margin-bottom:8px;font-weight:600;"><input type="checkbox" class="qibbs-select-all-cats" ' . ( empty( $selected ) ? 'checked' : '' ) . '> ' . __( '全部分类', 'qibbs-community' ) . '</label>';
        
        foreach ( $categories as $cat ) {
            $is_checked = in_array( $cat->term_id, $selected );
            echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="qibbs_options[sync_categories][]" value="' . esc_attr( $cat->term_id ) . '"' . checked( $is_checked, true, false ) . '> ' . esc_html( $cat->name ) . '</label>';
        }
        echo '</div>';
        echo '<p class="description">' . __( '选择要同步的文章分类，不选择则同步全部', 'qibbs-community' ) . '</p>';
    }

    public function field_sync_excerpt_length() {
        $value = qibbs_get_option( 'sync_excerpt_length', 80 );
        echo '<input type="number" name="qibbs_options[sync_excerpt_length]" value="' . esc_attr( $value ) . '" min="20" max="500" class="small-text">';
        echo '<span> ' . __( '字符', 'qibbs-community' ) . '</span>';
        echo '<p class="description">' . __( '同步文章内容时截取的字数', 'qibbs-community' ) . '</p>';
    }

    public function field_sync_images() {
        $checked = qibbs_get_option( 'sync_images', true );
        echo '<label><input type="checkbox" name="qibbs_options[sync_images]" value="1"' . checked( $checked, true, false ) . '> ' . __( '同步文章时自动提取图片', 'qibbs-community' ) . '</label>';
    }

    public function field_sync_historical() {
        // 获取待同步历史文章总数
        $sync_service = new Qibbs_Article_Sync_Service();
        $unsynced_count = $sync_service->get_unsynced_count();
        $is_enabled = qibbs_get_option( 'enable_article_sync', false );
        
        if ( ! $is_enabled ) {
            echo '<p class="description" style="color:#999;">' . __( '请先启用文章同步功能', 'qibbs-community' ) . '</p>';
            return;
        }
        
        echo '<div class="qibbs-sync-historical">';
        echo '<p>' . sprintf( __( '共有 %d 篇文章尚未同步到社区动态', 'qibbs-community' ), $unsynced_count ) . '</p>';
        
        if ( $unsynced_count > 0 ) {
            echo '<p><button type="button" class="button button-secondary" id="qibbs-sync-historical-btn">';
            echo __( '同步历史文章', 'qibbs-community' );
            echo '</button>';
            echo '<span id="qibbs-sync-status" style="margin-left: 10px; color: #666;"></span></p>';
            echo '<p class="description">' . __( '将已发布的文章同步为社区动态，此操作可能需要一些时间', 'qibbs-community' ) . '</p>';
        }
        echo '</div>';
    }

    public function field_show_hot_topics() {
        $checked = qibbs_get_option( 'show_hot_topics', true );
        echo '<label><input type="checkbox" name="qibbs_options[show_hot_topics]" value="1"' . checked( $checked, true, false ) . '> ' . __( '在右侧边栏显示热门话题', 'qibbs-community' ) . '</label>';
    }

    public function field_hot_topic_order() {
        $value = qibbs_get_option( 'hot_topic_order', 'usage' );
        $options = array(
            'usage'     => __( '使用次数最多', 'qibbs-community' ),
            'comments'  => __( '评论最多', 'qibbs-community' ),
            'likes'     => __( '点赞最多', 'qibbs-community' ),
            'favorites' => __( '收藏最多', 'qibbs-community' ),
        );
        echo '<select name="qibbs_options[hot_topic_order]">';
        foreach ( $options as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '"' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . __( '定义什么是热门话题的排序标准', 'qibbs-community' ) . '</p>';
    }

    public function field_hot_topic_count() {
        $value = qibbs_get_option( 'hot_topic_count', 10 );
        echo '<input type="number" name="qibbs_options[hot_topic_count]" value="' . esc_attr( $value ) . '" min="3" max="30" class="small-text">';
        echo '<span> ' . __( '个', 'qibbs-community' ) . '</span>';
        echo '<p class="description">' . __( '右侧边栏显示的热门话题数量', 'qibbs-community' ) . '</p>';
    }

    public function field_show_community_stats() {
        $checked = qibbs_get_option( 'show_community_stats', true );
        echo '<label><input type="checkbox" name="qibbs_options[show_community_stats]" value="1"' . checked( $checked, true, false ) . '> ' . __( '在右侧边栏显示社区统计数据（总动态数、总用户数、总评论数等）', 'qibbs-community' ) . '</label>';
    }

    public function field_show_checkin_widget() {
        $checked = qibbs_get_option( 'show_checkin_widget', true );
        $points_enabled = function_exists( 'qibbs_is_points_integration_available' ) && qibbs_is_points_integration_available();
        echo '<label><input type="checkbox" name="qibbs_options[show_checkin_widget]" value="1"' . checked( $checked, true, false ) . '> ' . __( '在右侧边栏显示每日签到打卡卡片', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '打卡获得的积分奖励与连续签到倍数统一依托于启灵商城。', 'qibbs-community' );
        if ( ! $points_enabled ) {
            echo ' <span style="color:#d63638;">' . __( '（未检测到启灵商城积分系统，请先在商城中启用）', 'qibbs-community' ) . '</span>';
        } else {
            echo ' <a href="' . esc_url( admin_url( 'admin.php?page=qilingshop-settings&tab=points' ) ) . '" target="_blank">' . __( '前往启灵商城配置签到奖励规则 &rarr;', 'qibbs-community' ) . '</a>';
        }
        echo '</p>';
    }


    public function field_enable_search() {
        $checked = qibbs_get_option( 'enable_search', false );
        echo '<label><input type="checkbox" name="qibbs_options[enable_search]" value="1"' . checked( $checked, true, false ) . '> ' . __( '在右侧边栏显示搜索框，支持搜索社区动态', 'qibbs-community' ) . '</label>';
    }

    public function field_show_hot_posts() {
        $checked = qibbs_get_option( 'show_hot_posts', false );
        echo '<label><input type="checkbox" name="qibbs_options[show_hot_posts]" value="1"' . checked( $checked, true, false ) . '> ' . __( '在左侧导航显示"热门动态"入口，按评论数排序展示动态', 'qibbs-community' ) . '</label>';
    }

    public function field_hot_default_window() {
        $value = absint( qibbs_get_option( 'hot_default_window', 7 ) );
        if ( ! in_array( $value, array( 1, 7, 30 ), true ) ) {
            $value = 7;
        }
        echo '<select name="qibbs_options[hot_default_window]">';
        echo '<option value="1"' . selected( $value, 1, false ) . '>' . esc_html__( '24 小时', 'qibbs-community' ) . '</option>';
        echo '<option value="7"' . selected( $value, 7, false ) . '>' . esc_html__( '7 天', 'qibbs-community' ) . '</option>';
        echo '<option value="30"' . selected( $value, 30, false ) . '>' . esc_html__( '30 天', 'qibbs-community' ) . '</option>';
        echo '</select>';
        echo '<p class="description">' . __( '热门动态默认展示时间窗（前台可切换）', 'qibbs-community' ) . '</p>';
    }

    public function field_enable_featured_posts() {
        $checked = qibbs_get_option( 'enable_featured_posts', false );
        echo '<label><input type="checkbox" name="qibbs_options[enable_featured_posts]" value="1"' . checked( $checked, true, false ) . '> ' . __( '在左侧导航显示"推荐动态"入口，管理员可手动推荐动态', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '启用后，管理员可在动态右上角菜单中推荐动态，并设置推荐时长', 'qibbs-community' ) . '</p>';
    }

    public function field_show_voting_posts() {
        $checked = qibbs_get_option( 'show_voting_posts', false );
        echo '<label><input type="checkbox" name="qibbs_options[show_voting_posts]" value="1"' . checked( $checked, true, false ) . '> ' . __( '在左侧导航显示"投票动态"入口，展示所有包含投票的动态', 'qibbs-community' ) . '</label>';
    }

    public function field_show_redpacket_posts() {
        $checked = qibbs_get_option( 'show_redpacket_posts', true );
        echo '<label><input type="checkbox" name="qibbs_options[show_redpacket_posts]" value="1"' . checked( $checked, true, false ) . '> ' . __( '在左侧“热门圈子”显示“红包动态”入口，展示所有包含红包的动态（优先展示可领红包）', 'qibbs-community' ) . '</label>';
    }

    public function field_show_bounty_posts() {
        $checked = qibbs_get_option( 'show_bounty_posts', true );
        echo '<label><input type="checkbox" name="qibbs_options[show_bounty_posts]" value="1"' . checked( $checked, true, false ) . '> ' . __( '在左侧“热门圈子”显示“悬赏动态”入口，展示所有悬赏问答动态（优先展示进行中的悬赏）', 'qibbs-community' ) . '</label>';
    }

    public function field_show_sidebar_categories() {
        $checked = qibbs_get_option( 'show_sidebar_categories', true );
        echo '<label><input type="checkbox" name="qibbs_options[show_sidebar_categories]" value="1"' . checked( $checked, true, false ) . '> ' . __( '在左侧侧边栏展示同步的文章分类列表（需启用文章同步）', 'qibbs-community' ) . '</label>';
    }

    public function field_left_nav_switches() {
        $nav_items = array(
            'show_nav_growth'        => __( '成长中心', 'qibbs-community' ),
            'show_nav_mall'          => __( '交易大厅', 'qibbs-community' ),
            'show_nav_prop_mall'     => __( '道具大厅', 'qibbs-community' ),
            'show_nav_following'     => __( '关注动态', 'qibbs-community' ),
            'show_nav_notifications' => __( '站内通知', 'qibbs-community' ),
            'show_nav_messages'      => __( '私信消息', 'qibbs-community' ),
            'show_nav_tickets'       => __( '服务工单', 'qibbs-community' ),
            'show_nav_profile'       => __( '个人主页', 'qibbs-community' ),
        );
        echo '<fieldset style="display:flex;flex-wrap:wrap;gap:14px;max-width:650px;">';
        foreach ( $nav_items as $key => $label ) {
            $checked = qibbs_get_option( $key, true );
            echo '<label style="margin-right:12px;white-space:nowrap;"><input type="checkbox" name="qibbs_options[' . esc_attr( $key ) . ']" value="1"' . checked( $checked, true, false ) . '> ' . esc_html( $label ) . '</label>';
        }
        echo '</fieldset>';
        echo '<p class="description">' . __( '控制左侧顶部导航网格中各入口的显示或隐藏，默认均开启。首页为核心入口固定常驻。', 'qibbs-community' ) . '</p>';
    }

    public function field_enable_banned_words() {
        $checked = qibbs_get_option( 'enable_banned_words', false );
        echo '<label><input type="checkbox" name="qibbs_options[enable_banned_words]" value="1"' . checked( $checked, true, false ) . '> ' . __( '启用后，用户发布的动态将检查违规词', 'qibbs-community' ) . '</label>';
    }

    public function field_banned_words() {
        $value = qibbs_get_option( 'banned_words', '' );
        echo '<textarea name="qibbs_options[banned_words]" rows="5" class="large-text">' . esc_textarea( $value ) . '</textarea>';
        echo '<p class="description">' . __( '输入违规词，多个词用英文逗号分隔。例如：违规词1,违规词2,违规词3', 'qibbs-community' ) . '</p>';
    }

    public function field_report_auto_hide_threshold() {
        $value = absint( qibbs_get_option( 'report_auto_hide_threshold', 3 ) );
        $value = max( 0, min( 30, $value ) );
        echo '<input type="number" name="qibbs_options[report_auto_hide_threshold]" value="' . esc_attr( $value ) . '" min="0" max="30" class="small-text">';
        echo '<span> ' . __( '次', 'qibbs-community' ) . '</span>';
        echo '<p class="description">' . __( '同一动态待处理举报达到该值后，自动改为待审核。设为 0 表示关闭自动下线。', 'qibbs-community' ) . '</p>';
    }

    public function field_max_upload_size() {
        $value = qibbs_get_option( 'max_upload_size', 5 );
        echo '<input type="number" name="qibbs_options[max_upload_size]" value="' . esc_attr( $value ) . '" min="1" max="50" class="small-text">';
        echo '<span> MB</span>';
        echo '<p class="description">' . __( '允许上传的图片最大大小，范围 1-50 MB', 'qibbs-community' ) . '</p>';
    }

    public function field_allowed_image_formats() {
        $value = qibbs_get_option( 'allowed_image_formats', 'jpg,jpeg,png,webp,gif' );
        echo '<input type="text" name="qibbs_options[allowed_image_formats]" value="' . esc_attr( $value ) . '" class="regular-text">';
        echo '<p class="description">' . __( '允许上传的图片格式，用英文逗号分隔。例如：jpg,jpeg,png,webp,gif', 'qibbs-community' ) . '</p>';
        echo '<p class="description" style="color:#666;">' . __( '系统会同时验证文件扩展名和真实文件类型，防止伪造格式', 'qibbs-community' ) . '</p>';
    }

    public function field_sync_to_wp_media_library() {
        $checked = qibbs_get_option( 'sync_to_wp_media_library', true );
        echo '<label><input type="checkbox" name="qibbs_options[sync_to_wp_media_library]" value="1"' . checked( $checked, true, false ) . '> ' . __( '将前台上传的媒体同步注册至 WordPress 后台媒体库', 'qibbs-community' ) . '</label>';
        echo '<p class="description">' . __( '开启后，前台用户在社区发布的动态配图、富文本粘贴图片将同步在 WordPress 原生媒体库（wp-admin/upload.php）生成附件记录，方便站长集中查看与管理。', 'qibbs-community' ) . '</p>';

        echo '<div style="margin-top: 10px; padding: 10px 14px; background: #f0fdf4; border-left: 4px solid #16a34a; border-radius: 4px; font-size: 13px; line-height: 1.6; color: #166534; max-width: 680px;">';
        echo '<strong style="display: flex; align-items: center; gap: 4px; margin-bottom: 4px; color: #15803d;"><span class="dashicons dashicons-info" style="font-size:16px;width:16px;height:16px;"></span>' . esc_html__( '主题与对象存储无缝协同说明：', 'qibbs-community' ) . '</strong>';
        echo esc_html__( '• 社区图片上传现已完全融入 WordPress 原生附件处理链路。启灵主题（qiling）内置的 WebP 自动转换、启灵对象存储（qilingstorage）的云端同步与 CDN 分发将自动原生生效，无需社区插件进行二次处理，稳定高效零冲突。', 'qibbs-community' );
        echo '</div>';
    }

    public function field_theme_style() {
        $value = qibbs_get_option( 'theme_style', 'default' );
        $styles = array(
            'default' => array(
                'label' => __( '默认风格', 'qibbs-community' ),
                'desc'  => __( '现代靛蓝渐变', 'qibbs-community' ),
                'color' => '#6366f1',
            ),
            'fresh' => array(
                'label' => __( '小清新', 'qibbs-community' ),
                'desc'  => __( '清新翠绿自然风', 'qibbs-community' ),
                'color' => '#10b981',
            ),
            'tech' => array(
                'label' => __( '科技风', 'qibbs-community' ),
                'desc'  => __( '冷色调科技感', 'qibbs-community' ),
                'color' => '#0ea5e9',
            ),
            'gaming' => array(
                'label' => __( '游戏风格', 'qibbs-community' ),
                'desc'  => __( '活力炫彩配色', 'qibbs-community' ),
                'color' => '#f43f5e',
            ),
        );
        ?>
        <div class="qibbs-theme-style-selector" style="display: flex; flex-wrap: wrap; gap: 12px;">
            <?php foreach ( $styles as $key => $style ) : ?>
                <label style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; border: 2px solid <?php echo $value === $key ? $style['color'] : '#ddd'; ?>; border-radius: 8px; cursor: pointer; background: <?php echo $value === $key ? 'rgba(' . $this->hex_to_rgb( $style['color'] ) . ', 0.08)' : '#fff'; ?>; transition: all 0.2s;">
                    <input type="radio" name="qibbs_options[theme_style]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $value, $key ); ?> style="margin: 0;">
                    <span style="width: 20px; height: 20px; border-radius: 50%; background: <?php echo esc_attr( $style['color'] ); ?>;"></span>
                    <span>
                        <strong style="display: block;"><?php echo esc_html( $style['label'] ); ?></strong>
                        <small style="color: #666;"><?php echo esc_html( $style['desc'] ); ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="description" style="margin-top: 10px;"><?php _e( '选择社区的配色风格，将应用到整个社区界面', 'qibbs-community' ); ?></p>
        <?php
    }

    public function field_custom_primary_color() {
        $value = qibbs_get_option( 'custom_primary_color', '' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_style( 'wp-color-picker' );
        ?>
        <input type="text" name="qibbs_options[custom_primary_color]" value="<?php echo esc_attr( $value ); ?>" class="qibbs-color-picker" data-default-color="">
        <p class="description"><?php _e( '自定义主色调（如按钮、链接颜色）。留空则使用风格预设颜色', 'qibbs-community' ); ?></p>
        <script>
        jQuery(document).ready(function($) {
            $('.qibbs-color-picker').wpColorPicker();
        });
        </script>
        <?php
    }

    public function field_custom_accent_color() {
        $value = qibbs_get_option( 'custom_accent_color', '' );
        ?>
        <input type="text" name="qibbs_options[custom_accent_color]" value="<?php echo esc_attr( $value ); ?>" class="qibbs-color-picker" data-default-color="">
        <p class="description"><?php _e( '自定义强调色（如渐变副色、高亮）。留空则使用风格预设颜色', 'qibbs-community' ); ?></p>
        <?php
    }

    /**
     * 工具方法：十六进制色值转 RGB 字符串
     */
    private function hex_to_rgb( $hex ) {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        return "{$r}, {$g}, {$b}";
    }

    /**
     * 从启灵 Webhook 配置读取已开启的推送渠道
     *
     * @return array<string,string>
     */
    private function get_qilinghook_channel_choices() {
        $choices = array();
        $settings = get_option( 'qilinghook_settings', array() );
        if ( ! is_array( $settings ) || empty( $settings['channels'] ) || ! is_array( $settings['channels'] ) ) {
            return $choices;
        }

        foreach ( $settings['channels'] as $channel ) {
            if ( ! is_array( $channel ) ) {
                continue;
            }

            $channel_id = isset( $channel['id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $channel['id'] ) : '';
            if ( '' === $channel_id || empty( $channel['enabled'] ) ) {
                continue;
            }

            $name = isset( $channel['name'] ) && '' !== $channel['name'] ? (string) $channel['name'] : $channel_id;
            $provider = isset( $channel['provider'] ) ? (string) $channel['provider'] : 'feishu';
            $provider_name = ( 'dingtalk' === $provider ) ? __( '钉钉', 'qibbs-community' ) : __( '飞书', 'qibbs-community' );
            $choices[ $channel_id ] = sprintf( '%s（%s）', $name, $provider_name );
        }

        return $choices;
    }

    public function render_settings_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/settings.php';
    }

    public function render_growth_rewards_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/growth-rewards.php';
    }

    public function render_interactive_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/interactive-settings.php';
    }

    public function render_management_page() {
        wp_safe_redirect( admin_url( 'admin.php?page=qibbs-review-queue' ) );
        exit;
    }

    /**
     * 渲染举报内容审核管理面板
     */
    public function render_reports_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/reports.php';
    }

    private function render_posts_management() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/posts.php';
    }

    /**
     * 渲染私信记录管理面板
     */
    public function enqueue_admin_assets( $hook ) {
        if ( ! $this->is_qibbs_admin_screen() ) {
            return;
        }
        wp_enqueue_style( 'qibbs-admin', QIBBS_PLUGIN_URL . 'assets/css/admin.css', array(), QIBBS_VERSION );
    }

    /**
     * AJAX 接口：刷新前台静态资源时间戳缓存
     */
    public function ajax_refresh_cache() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'qibbs-community' ) ) );
        }

        $version = time();
        update_option( 'qibbs_cache_version', $version );

        wp_send_json_success( array(
            'version' => $version,
            'message' => __( '缓存已刷新', 'qibbs-community' ),
        ) );
    }

    /**
     * AJAX 接口：重建微社区独立全文搜索索引
     *
     * @return void
     */
    public function ajax_rebuild_search_index() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'qibbs-community' ) ) );
        }

        $service = new Qibbs_Search_Index_Service();
        $result = $service->rebuild_index();

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( $result );
        }

        wp_send_json_success( $result );
    }

    public function render_messages_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/messages.php';
    }

    /**
     * 渲染版块管理面板（基于 wp_qibbs_forums 数据表）
     */
    public function render_forums_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/forums.php';
    }

    /**
     * 渲染话题标签管理面板（基于 wp_qibbs_tags 数据表）
     */
    public function render_tags_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/tags.php';
    }

    /**
     * 渲染用户黑名单管理面板
     */
    public function render_blacklist_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/blacklist.php';
    }

    /**
     * 渲染版主权限与版块委任管理面板
     */
    /**
     * 从文本框批量解析用户 ID 列表
     *
     * @param string $raw Raw input.
     * @return array
     */
    public function parse_user_ids_input( $raw ) {
        $raw = (string) $raw;
        if ( '' === trim( $raw ) ) {
            return array();
        }

        $parts = preg_split( '/[\s,，]+/u', $raw );
        if ( ! is_array( $parts ) ) {
            return array();
        }

        $ids = array_values(
            array_unique(
                array_filter(
                    array_map( 'absint', $parts )
                )
            )
        );

        return $ids;
    }

    /**
     * 将版务操作动作标识转换为中文可读文案
     *
     * @param string $action Action key.
     * @return string
     */
    public function get_moderator_action_label( $action ) {
        $map = array(
            'forum_add_moderator'     => __( '新增圈子版主', 'qibbs-community' ),
            'forum_remove_moderator'  => __( '移除圈子版主', 'qibbs-community' ),
            'moderator_delete_post'   => __( '删除动态', 'qibbs-community' ),
            'moderator_block_post'    => __( '屏蔽动态', 'qibbs-community' ),
            'moderator_pin_post'      => __( '置顶动态', 'qibbs-community' ),
            'moderator_unpin_post'    => __( '取消置顶', 'qibbs-community' ),
            'moderator_feature_post'  => __( '设为精华', 'qibbs-community' ),
            'moderator_unfeature_post' => __( '取消精华', 'qibbs-community' ),
            'moderator_lock_post'     => __( '锁帖', 'qibbs-community' ),
            'moderator_unlock_post'   => __( '解除锁帖', 'qibbs-community' ),
            'moderator_move_forum_post' => __( '移动圈子', 'qibbs-community' ),
            'moderator_trash_post'    => __( '移入回收站', 'qibbs-community' ),
            'moderator_block_post'    => __( '屏蔽动态', 'qibbs-community' ),
            'moderator_update_discussion_status' => __( '调整讨论状态', 'qibbs-community' ),
            'moderator_reopen_question' => __( '重开讨论', 'qibbs-community' ),
            'moderator_set_best_answer' => __( '设置最佳答案', 'qibbs-community' ),
            'moderator_clear_best_answer' => __( '取消最佳答案', 'qibbs-community' ),
            'moderator_pin_quality_comment' => __( '优质回复置顶', 'qibbs-community' ),
            'moderator_unpin_quality_comment' => __( '取消优质置顶', 'qibbs-community' ),
            'moderator_delete_comment'=> __( '删除评论', 'qibbs-community' ),
            'moderator_block_comment' => __( '屏蔽评论', 'qibbs-community' ),
            'moderator_blacklist_user' => __( '加入黑名单', 'qibbs-community' ),
            'moderator_unblacklist_user' => __( '移出黑名单', 'qibbs-community' ),
            'moderator_set_post_cooldown' => __( '设置发帖冷却', 'qibbs-community' ),
            'moderator_clear_post_cooldown' => __( '清除发帖冷却', 'qibbs-community' ),
            'moderator_update_forum_governance' => __( '更新圈子规则', 'qibbs-community' ),
        );

        $action = sanitize_key( (string) $action );
        return isset( $map[ $action ] ) ? $map[ $action ] : $action;
    }

    public function render_moderators_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/moderators.php';
    }

    /**
     * 渲染版务操作审核日志面板
     */
    public function render_moderator_logs_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/moderator-logs.php';
    }

    /**
     * 渲染用户专属身份徽章配置面板
     */
    public function render_user_badges_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/user-badges.php';
    }

    /**
     * 渲染社区 UID 与号段设置面板
     */
    public function render_uid_settings_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/uid-settings.php';
    }

    /**
     * 渲染社区会员中心与用户全维管理面板
     */
    public function render_members_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/members.php';
    }

    /**
     * 渲染社区内置支付网关设置面板
     */
    public function render_payment_settings_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/payment-settings.php';
    }

    /**
     * 渲染订单交易管理后台视图
     */
    public function render_orders_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/orders.php';
    }

    /**
     * 渲染道具大厅管理后台视图
     */
    public function render_props_page() {
        include QIBBS_PLUGIN_DIR . 'inc/Admin/Views/props.php';
    }
}

