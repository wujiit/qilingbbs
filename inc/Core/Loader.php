<?php
/**
 * 微社区核心加载器
 * 统一调度、加载各个业务服务模块并集中注册全部 Hook 钩子
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 核心模块加载器
 */
class Qibbs_Loader {

    /**
     * 待注册的动作钩子列表
     *
     * @var array
     */
    protected $actions = array();

    /**
     * 待注册的过滤器钩子列表
     *
     * @var array
     */
    protected $filters = array();

    /**
     * 已加载的服务模块实例映射
     *
     * @var array
     */
    protected $modules = array();

    /**
     * Constructor
     */
    public function __construct() {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    /**
     * 实例化并加载核心依赖模块
     */
    private function load_dependencies() {
        // 核心支持类
        require_once QIBBS_PLUGIN_DIR . 'inc/Core/Database.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Core/I18n.php';
        
        // 自定义实体与分类服务
        require_once QIBBS_PLUGIN_DIR . 'inc/PostType/Post_Type.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Taxonomy/Taxonomy.php';
        
        // 核心业务服务层
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Search_Index_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Feed_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Like_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Favorite_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Comment_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Tag_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Article_Sync_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Post_Page_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Featured_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Cache_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Risk_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Moderation_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Dashboard_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Forum_Subscription_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Forum_Moderator_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Badge_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/UID_Rule_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Member_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Vanity_Pool_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/C2C_Trade_Service.php';
        
        // 用户关注服务
        require_once QIBBS_PLUGIN_DIR . 'inc/Follow/Follow_Service.php';
        
        // 私信会话服务
        require_once QIBBS_PLUGIN_DIR . 'inc/Message/Message_Service.php';
        
        // 站内消息通知服务
        require_once QIBBS_PLUGIN_DIR . 'inc/Notification/Notification_Delivery.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Notification/Notification_Service.php';
        
        // 用户个人主页服务
        require_once QIBBS_PLUGIN_DIR . 'inc/Profile/Profile_Service.php';
        
        // 前台社区布局服务
        require_once QIBBS_PLUGIN_DIR . 'inc/Layout/Layout_Service.php';
        
        // 管理后台控制器
        if ( is_admin() ) {
            require_once QIBBS_PLUGIN_DIR . 'inc/Admin/Admin_Settings.php';
            require_once QIBBS_PLUGIN_DIR . 'inc/Admin/Dashboard_Admin.php';
            require_once QIBBS_PLUGIN_DIR . 'inc/Admin/Risk_Admin.php';
            require_once QIBBS_PLUGIN_DIR . 'inc/Admin/Moderation_Admin.php';
        }
        
        // 原生支付与订单服务
        require_once QIBBS_PLUGIN_DIR . 'inc/Payment/Payment_Manager.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Order_Service.php';

        // REST API 接口控制器
        require_once QIBBS_PLUGIN_DIR . 'inc/REST/REST_Controller.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/REST/UID_Rest_Controller.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/REST/Payment_Rest_Controller.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/REST/C2C_Rest_Controller.php';
        
        // 辅助工具类
        require_once QIBBS_PLUGIN_DIR . 'inc/Helpers/Template_Loader.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Helpers/Ajax_Handler.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Helpers/Content_Filter.php';
        
        // 工单系统核心服务
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Ticket_Category_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Ticket_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Service/Ticket_Push_Service.php';
        require_once QIBBS_PLUGIN_DIR . 'inc/Core/Ticket_Shortcodes.php';
        
        // 工单后台管理
        if ( is_admin() ) {
            require_once QIBBS_PLUGIN_DIR . 'inc/Admin/Ticket_Admin.php';
        }
    }

    /**
     * 注册后台管理专有钩子
     */
    private function define_admin_hooks() {
        if ( ! is_admin() ) {
            return;
        }

        $admin = new Qibbs_Admin_Settings();
        $dashboard_admin = new Qibbs_Dashboard_Admin();
        $risk_admin = new Qibbs_Risk_Admin();
        $moderation_admin = new Qibbs_Moderation_Admin();
        
        $this->add_action( 'admin_menu', $admin, 'add_admin_menu' );
        $this->add_action( 'admin_menu', $dashboard_admin, 'add_admin_menu' );
        $this->add_action( 'admin_menu', $risk_admin, 'add_admin_menu' );
        $this->add_action( 'admin_menu', $moderation_admin, 'add_admin_menu' );
        $this->add_action( 'admin_init', $admin, 'register_settings' );
        $this->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_admin_assets' );
        $this->add_filter( 'admin_body_class', $admin, 'add_admin_body_class' );
        $this->add_action( 'admin_notices', $admin, 'render_admin_top_nav', 1 );
        $this->add_action( 'wp_ajax_qibbs_refresh_cache', $admin, 'ajax_refresh_cache' );
        $this->add_action( 'wp_ajax_qibbs_rebuild_search_index', $admin, 'ajax_rebuild_search_index' );
    }

    /**
     * 注册前台公共业务钩子
     */
    private function define_public_hooks() {
        // 微社区全量采用独立自定义数据表（wp_qibbs_*），与 WordPress 原生实体彻底解耦
        
        // 前端布局与模版服务
        $layout = new Qibbs_Layout_Service();
        $this->add_action( 'wp_enqueue_scripts', $layout, 'enqueue_assets' );
        $this->add_filter( 'template_include', $layout, 'maybe_override_template' );
        $this->add_action( 'template_redirect', $layout, 'maybe_require_login', 1 );
        $this->add_action( 'widgets_init', $layout, 'register_widgets' );
        
        // 动态流服务
        $feed = new Qibbs_Feed_Service();
        $this->add_action( 'wp_ajax_qibbs_load_feed', $feed, 'ajax_load_feed' );
        if ( function_exists( 'qibbs_allow_guest_access' ) && qibbs_allow_guest_access() ) {
            $this->add_action( 'wp_ajax_nopriv_qibbs_load_feed', $feed, 'ajax_load_feed' );
        }

        // Redis / 内存缓存服务
        $cache = new Qibbs_Cache_Service();
        $this->add_action( 'qibbs_post_created', $cache, 'invalidate_post_created_cache', 10, 2 );
        $this->add_action( 'qibbs_post_deleted', $cache, 'invalidate_post_visibility_changed_cache', 10, 2 );
        $this->add_action( 'qibbs_post_blocked', $cache, 'invalidate_post_visibility_changed_cache' );
        $this->add_action( 'qibbs_post_edited', $cache, 'invalidate_post_edited_cache', 10, 3 );
        $this->add_action( 'qibbs_comment_added', $cache, 'invalidate_hot_feed' );
        $this->add_action( 'qibbs_comment_deleted', $cache, 'invalidate_hot_feed' );
        $this->add_action( 'qibbs_comment_added', $cache, 'invalidate_sidebar' );
        $this->add_action( 'qibbs_comment_deleted', $cache, 'invalidate_sidebar' );
        $this->add_action( 'qibbs_like_toggled', $cache, 'invalidate_like_toggle_cache', 10, 3 );
        $this->add_action( 'qibbs_favorite_toggled', $cache, 'invalidate_favorite_toggle_cache', 10, 3 );
        $this->add_action( 'qibbs_follow_toggled', $cache, 'invalidate_follow_toggle_cache', 10, 3 );

        // 搜索索引与全文检索服务
        if ( class_exists( 'Qibbs_Search_Index_Service' ) ) {
            $search_index = new Qibbs_Search_Index_Service();
            $this->add_action( 'qibbs_post_created', $search_index, 'sync_post' );
            $this->add_action( 'qibbs_post_edited', $search_index, 'queue_post_sync_from_post_event' );
            $this->add_action( 'qibbs_post_deleted', $search_index, 'delete_post' );
            $this->add_action( 'qibbs_post_blocked', $search_index, 'delete_post' );
            $this->add_action( 'qibbs_post_featured', $search_index, 'sync_post_from_feature' );
            $this->add_action( 'qibbs_like_toggled', $search_index, 'sync_post_from_like', 10, 3 );
            $this->add_action( 'qibbs_comment_added', $search_index, 'sync_post_from_comment', 10, 3 );
            $this->add_action( 'qibbs_comment_approved', $search_index, 'sync_post_from_comment', 10, 3 );
            $this->add_action( 'qibbs_comment_deleted', $search_index, 'sync_post_from_comment', 10, 3 );
            $this->add_action( 'qibbs_best_answer_set', $search_index, 'sync_post_from_best_answer', 10, 1 );
            $this->add_action( 'qibbs_ticket_published', $search_index, 'sync_post_from_ticket_publish', 10, 3 );
            $this->add_action( Qibbs_Search_Index_Service::CRON_HOOK, $search_index, 'process_sync_queue' );
        }

        // 版块订阅服务
        $forum_subscription = new Qibbs_Forum_Subscription_Service();
        $this->add_action( 'wp_ajax_qibbs_toggle_forum_subscription', $forum_subscription, 'ajax_toggle_subscription' );
        $this->add_action( 'qibbs_post_created', $forum_subscription, 'notify_subscribers_on_post', 10, 2 );
        $this->add_action( Qibbs_Forum_Subscription_Service::CRON_HOOK, $forum_subscription, 'process_subscribers_on_post', 10, 2 );

        // 风控防刷与安全评分服务
        $risk = new Qibbs_Risk_Service();
        $this->add_action( 'init', $risk, 'maybe_block_ajax_requests', 1 );
        $this->add_filter( 'rest_pre_dispatch', $risk, 'maybe_block_rest_requests', 10, 3 );
        $this->add_action( 'init', $risk, 'maybe_cleanup_logs', 20 );
        $this->add_action( Qibbs_Risk_Service::CLEANUP_CRON_HOOK, $risk, 'process_cleanup_logs' );
        
        // 点赞互动服务
        $like = new Qibbs_Like_Service();
        $this->add_action( 'wp_ajax_qibbs_toggle_like', $like, 'ajax_toggle_like' );
        
        // 收藏服务
        $favorite = new Qibbs_Favorite_Service();
        $this->add_action( 'wp_ajax_qibbs_toggle_favorite', $favorite, 'ajax_toggle_favorite' );
        
        // 评论流与二级回复服务
        $comment = new Qibbs_Comment_Service();
        $this->add_action( 'wp_ajax_qibbs_add_comment', $comment, 'ajax_add_comment' );
        $this->add_action( 'wp_ajax_qibbs_load_comments', $comment, 'ajax_load_comments' );
        $this->add_action( 'wp_ajax_qibbs_load_comment_replies', $comment, 'ajax_load_comment_replies' );
        if ( function_exists( 'qibbs_allow_guest_access' ) && qibbs_allow_guest_access() ) {
            $this->add_action( 'wp_ajax_nopriv_qibbs_load_comments', $comment, 'ajax_load_comments' );
            $this->add_action( 'wp_ajax_nopriv_qibbs_load_comment_replies', $comment, 'ajax_load_comment_replies' );
        }
        $this->add_action( 'wp_ajax_qibbs_delete_comment', $comment, 'ajax_delete_comment' );
        $this->add_action( 'wp_ajax_qibbs_block_comment', $comment, 'ajax_block_comment' );
        $this->add_action( 'wp_ajax_qibbs_toggle_comment_like', $comment, 'ajax_toggle_comment_like' );
        $this->add_action( 'wp_ajax_qibbs_set_best_answer', $comment, 'ajax_set_best_answer' );
        $this->add_action( 'wp_ajax_qibbs_clear_best_answer', $comment, 'ajax_clear_best_answer' );
        $this->add_action( 'wp_ajax_qibbs_toggle_quality_pin', $comment, 'ajax_toggle_quality_pin' );
        $this->add_action( 'wp_ajax_qibbs_reopen_post_question', $comment, 'ajax_reopen_post_question' );
        
        // 话题标签服务
        $tag = new Qibbs_Tag_Service();
        $this->add_action( 'wp_ajax_qibbs_search_tags', $tag, 'ajax_search_tags' );
        if ( function_exists( 'qibbs_allow_guest_access' ) && qibbs_allow_guest_access() ) {
            $this->add_action( 'wp_ajax_nopriv_qibbs_search_tags', $tag, 'ajax_search_tags' );
        }

        // 工单 SLA 履约监控扫描任务调度
        $ticket_service = new Qibbs_Ticket_Service();
        $this->add_action( 'init', $ticket_service, 'maybe_dispatch_sla_notifications', 30 );
        $this->add_action( Qibbs_Ticket_Service::SLA_SCAN_CRON_HOOK, $ticket_service, 'process_sla_notifications' );
        $ticket_push = new Qibbs_Ticket_Push_Service();
        $this->add_action( 'qibbs_ticket_created', $ticket_push, 'queue_new_ticket_notification', 10, 2 );
        $this->add_action( Qibbs_Ticket_Push_Service::CRON_HOOK, $ticket_push, 'notify_new_ticket', 10, 2 );
        
        // 启灵文章同步至社区服务
        $article_sync = new Qibbs_Article_Sync_Service();
        $article_sync->init();
        
        // 关注互动服务
        $follow = new Qibbs_Follow_Service();
        $this->add_action( 'wp_ajax_qibbs_toggle_follow', $follow, 'ajax_toggle_follow' );
        
        // 私信消息服务
        $message = new Qibbs_Message_Service();
        $this->add_action( 'wp_ajax_qibbs_send_message', $message, 'ajax_send_message' );
        $this->add_action( 'wp_ajax_qibbs_load_messages', $message, 'ajax_load_messages' );
        $this->add_action( 'wp_ajax_qibbs_get_conversations', $message, 'ajax_get_conversations' );
        
        // 消息通知投递服务
        $notification_delivery = new Qibbs_Notification_Delivery();
        $this->add_action( Qibbs_Notification_Delivery::BATCH_CRON_HOOK, $notification_delivery, 'process_queued_batch' );
        $notification = new Qibbs_Notification_Service();
        $this->add_action( 'wp_ajax_qibbs_get_notifications', $notification, 'ajax_get_notifications' );
        $this->add_action( 'wp_ajax_qibbs_get_unread_counts', $notification, 'ajax_get_unread_counts' );
        $this->add_action( 'wp_ajax_qibbs_get_unread_notification_count', $notification, 'ajax_get_unread_count' );
        $this->add_action( 'wp_ajax_qibbs_mark_notification_read', $notification, 'ajax_mark_read' );
        $this->add_action( 'wp_ajax_qibbs_get_unread_message_count', $notification, 'ajax_get_unread_message_count' );
        $this->add_action( 'wp_ajax_qibbs_get_notification_preferences', $notification, 'ajax_get_preferences' );
        $this->add_action( 'wp_ajax_qibbs_save_notification_preferences', $notification, 'ajax_save_preferences' );
        
        // 个人主页服务
        $profile = new Qibbs_Profile_Service();
        $this->add_action( 'init', $profile, 'add_rewrite_rules' );
        $this->add_filter( 'query_vars', $profile, 'add_query_vars' );
        $this->add_filter( 'template_include', $profile, 'load_profile_template' );
        $this->add_filter( 'redirect_canonical', $profile, 'disable_profile_canonical', 0, 2 );
        
        // 帖子详情独立页面服务
        $post_page = new Qibbs_Post_Page_Service();
        $this->add_action( 'init', $post_page, 'add_rewrite_rules' );
        $this->add_filter( 'query_vars', $post_page, 'add_query_vars' );
        $this->add_filter( 'template_include', $post_page, 'load_single_post_template' );
        $this->add_filter( 'redirect_canonical', $post_page, 'disable_post_canonical', 0, 2 );
        
        // REST API 接口控制器
        $rest = new Qibbs_REST_Controller();
        $this->add_action( 'rest_api_init', $rest, 'register_routes' );

        $uid_rest = new Qibbs_UID_Rest_Controller();
        $this->add_action( 'rest_api_init', $uid_rest, 'register_routes' );

        $payment_rest = new Qibbs_Payment_Rest_Controller();
        $this->add_action( 'rest_api_init', $payment_rest, 'register_routes' );

        $c2c_rest = new Qibbs_C2C_Rest_Controller();
        $this->add_action( 'rest_api_init', $c2c_rest, 'register_routes' );
        
        // 异步 AJAX 请求分发器
        $ajax = new Qibbs_Ajax_Handler();
        $this->add_action( 'wp_ajax_qibbs_create_post', $ajax, 'create_post' );
        $this->add_action( 'wp_ajax_qibbs_delete_post', $ajax, 'delete_post' );
        $this->add_action( 'wp_ajax_qibbs_block_post', $ajax, 'block_post' );
        $this->add_action( 'wp_ajax_qibbs_update_post_discussion_status', $ajax, 'update_post_discussion_status' );
        $this->add_action( 'wp_ajax_qibbs_upload_media', $ajax, 'upload_media' );
        $this->add_action( 'wp_ajax_qibbs_search_posts', $ajax, 'search_posts' );
        if ( function_exists( 'qibbs_allow_guest_access' ) && qibbs_allow_guest_access() ) {
            $this->add_action( 'wp_ajax_nopriv_qibbs_search_posts', $ajax, 'search_posts' );
        }
        $this->add_action( 'wp_ajax_qibbs_search_users', $ajax, 'search_users' );
        $this->add_action( 'wp_ajax_qibbs_report_post', $ajax, 'report_post' );
        $this->add_action( 'wp_ajax_qibbs_edit_post', $ajax, 'edit_post' );
        $this->add_action( 'wp_ajax_qibbs_get_post_content', $ajax, 'get_post_content' );
        $this->add_action( 'wp_ajax_qibbs_arbitrate_bounty', $ajax, 'ajax_arbitrate_bounty' );
        $this->add_action( 'wp_ajax_qibbs_draw_redpacket', $ajax, 'ajax_draw_redpacket' );
        $this->add_action( 'wp_ajax_qibbs_get_redpacket_logs', $ajax, 'ajax_get_redpacket_logs' );
        if ( function_exists( 'qibbs_allow_guest_access' ) && qibbs_allow_guest_access() ) {
            $this->add_action( 'wp_ajax_nopriv_qibbs_get_redpacket_logs', $ajax, 'ajax_get_redpacket_logs' );
        }

        // 道具大厅与背包操作
        $this->add_action( 'wp_ajax_qibbs_purchase_prop', $ajax, 'ajax_purchase_prop' );
        $this->add_action( 'wp_ajax_qibbs_equip_name_color', $ajax, 'ajax_equip_name_color' );
        $this->add_action( 'wp_ajax_qibbs_unequip_name_color', $ajax, 'ajax_unequip_name_color' );
        $this->add_action( 'wp_ajax_qibbs_get_user_backpack', $ajax, 'ajax_get_user_backpack' );
        $this->add_action( 'wp_ajax_qibbs_get_user_promotable_posts', $ajax, 'ajax_get_user_promotable_posts' );

        // 帖子删除时自动退还未结悬赏与未领红包
        if ( class_exists( 'Qibbs_Bounty_Service' ) ) {
            $this->add_action( 'qibbs_post_deleted', Qibbs_Bounty_Service::instance(), 'on_post_deleted' );
        }
        if ( class_exists( 'Qibbs_Redpacket_Service' ) ) {
            $this->add_action( 'qibbs_post_deleted', Qibbs_Redpacket_Service::instance(), 'on_post_deleted' );
        }
        if ( class_exists( 'Qibbs_Prop_Service' ) ) {
            $this->add_action( Qibbs_Prop_Service::CRON_HOOK, Qibbs_Prop_Service::instance(), 'check_and_expire_props' );
        }
    }

    /**
     * 添加 Action 动作钩子
     *
     * @param string $hook          Hook name.
     * @param object $component     Component object.
     * @param string $callback      Callback method.
     * @param int    $priority      Priority.
     * @param int    $accepted_args Accepted arguments.
     */
    public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
        $this->actions = $this->add( $this->actions, $hook, $component, $callback, $priority, $accepted_args );
    }

    /**
     * 添加 Filter 过滤器钩子
     *
     * @param string $hook          Hook name.
     * @param object $component     Component object.
     * @param string $callback      Callback method.
     * @param int    $priority      Priority.
     * @param int    $accepted_args Accepted arguments.
     */
    public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
        $this->filters = $this->add( $this->filters, $hook, $component, $callback, $priority, $accepted_args );
    }

    /**
     * 收集钩子绑定到执行队列
     *
     * @param array  $hooks         Hooks collection.
     * @param string $hook          Hook name.
     * @param object $component     Component object.
     * @param string $callback      Callback method.
     * @param int    $priority      Priority.
     * @param int    $accepted_args Accepted arguments.
     * @return array
     */
    private function add( $hooks, $hook, $component, $callback, $priority, $accepted_args ) {
        $hooks[] = array(
            'hook'          => $hook,
            'component'     => $component,
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        );
        return $hooks;
    }

    /**
     * 批量向 WordPress 核心注册所有钩子
     */
    public function run() {
        // 批量注册过滤器
        foreach ( $this->filters as $hook ) {
            add_filter(
                $hook['hook'],
                array( $hook['component'], $hook['callback'] ),
                $hook['priority'],
                $hook['accepted_args']
            );
        }

        // 批量注册动作钩子
        foreach ( $this->actions as $hook ) {
            add_action(
                $hook['hook'],
                array( $hook['component'], $hook['callback'] ),
                $hook['priority'],
                $hook['accepted_args']
            );
        }

    }
}
