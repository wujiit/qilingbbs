<?php
/**
 * 消息通知服务类
 *
 * @package Qibbs_Community
 * @subpackage Notification
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Notification_Service {

    /**
     * 高频互动通知聚合时间窗口（秒）
     *
     * @var int
     */
    const INTERACTION_AGGREGATE_WINDOW = 300;

    /**
     * 单例实例
     *
     * @var Qibbs_Notification_Service|null
     */
    private static $instance = null;

    /**
     * 获取单例实例
     *
     * @return Qibbs_Notification_Service
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 通用通知发送接口（支持静态调用与单例调用）
     *
     * @param int    $user_id     目标用户 ID
     * @param string $type        通知类型
     * @param string $content     通知内容
     * @param int    $object_id   关联对象 ID
     * @param string $object_type 关联对象类型
     * @param int    $actor_id    操作人 ID
     * @return int|false
     */
    public static function send( $user_id, $type, $content = '', $object_id = 0, $object_type = 'system', $actor_id = 0 ) {
        return self::instance()->dispatch_notification( $user_id, $type, $content, $object_id, $object_type, $actor_id );
    }

    /**
     * 发送通知核心分发实现
     *
     * @param int    $user_id     目标用户 ID
     * @param string $type        通知类型
     * @param string $content     通知内容
     * @param int    $object_id   关联对象 ID
     * @param string $object_type 关联对象类型
     * @param int    $actor_id    操作人 ID
     * @return int|false
     */
    public function dispatch_notification( $user_id, $type, $content = '', $object_id = 0, $object_type = 'system', $actor_id = 0 ) {
        if ( ! $this->is_system_enabled() ) {
            return false;
        }

        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }

        if ( function_exists( 'qibbs_can_send_notification_type' ) && ! qibbs_can_send_notification_type( $user_id, $type ) ) {
            return false;
        }

        return Qibbs_Notification_Delivery::insert( array(
            'user_id'     => $user_id,
            'type'        => sanitize_key( $type ),
            'actor_id'    => absint( $actor_id ),
            'object_type' => sanitize_key( $object_type ),
            'object_id'   => absint( $object_id ),
            'content'     => wp_strip_all_tags( (string) $content ),
            'created_at'  => current_time( 'mysql' ),
        ) );
    }

    /**
     * 创建系统通知（兼容 Order_Service 等模块调用）
     *
     * @param int    $user_id     目标用户 ID
     * @param string $content     通知文本
     * @param int    $object_id   关联对象 ID
     * @param string $object_type 关联对象类型
     * @return int|false
     */
    public function create_system_notification( $user_id, $content, $object_id = 0, $object_type = 'system' ) {
        return $this->dispatch_notification( $user_id, 'system', $content, $object_id, $object_type, 0 );
    }

    /**
     * 通知系统全局开关状态
     *
     * @return bool
     */
    private function is_system_enabled() {
        return (bool) qibbs_get_option( 'enable_notifications', true );
    }

    /**
     * 通知功能关闭时提前阻断 AJAX 请求
     *
     * @return void
     */
    private function ensure_system_enabled_ajax() {
        if ( ! $this->is_system_enabled() ) {
            wp_send_json_error(
                array( 'message' => qibbs_get_module_disabled_message( 'notifications' ) ),
                403
            );
        }
    }

    /**
     * 获取可配置的通知偏好选项列表
     *
     * @return array
     */
    public function get_preference_items() {
        return array(
            'like'         => __( '点赞通知', 'qibbs-community' ),
            'comment_like' => __( '评论点赞通知', 'qibbs-community' ),
            'comment'      => __( '评论通知', 'qibbs-community' ),
            'reply'        => __( '回复通知', 'qibbs-community' ),
            'best_answer'  => __( '最佳答案通知', 'qibbs-community' ),
            'follow'       => __( '关注通知', 'qibbs-community' ),
            'favorite'     => __( '收藏通知', 'qibbs-community' ),
            'forum_post'   => __( '圈子订阅动态通知', 'qibbs-community' ),
            'ticket_reply' => __( '工单回复通知', 'qibbs-community' ),
            'ticket_resolved' => __( '工单解决通知', 'qibbs-community' ),
            'ticket_published' => __( '工单公开通知', 'qibbs-community' ),
            'ticket_sla'   => __( '工单 SLA 提醒', 'qibbs-community' ),
        );
    }

    /**
     * 获取包含文案标签的用户通知偏好
     *
     * @param int $user_id User ID.
     * @return array
     */
    public function get_user_preferences( $user_id ) {
        if ( ! $this->is_system_enabled() ) {
            return array();
        }

        $items = $this->get_preference_items();
        $prefs = function_exists( 'qibbs_get_user_notification_preferences' )
            ? qibbs_get_user_notification_preferences( $user_id )
            : array();

        $data = array();
        foreach ( $items as $key => $label ) {
            $data[] = array(
                'key'     => $key,
                'label'   => $label,
                'enabled' => isset( $prefs[ $key ] ) ? (bool) $prefs[ $key ] : true,
            );
        }

        return $data;
    }

    /**
     * 更新用户站内通知接收偏好
     *
     * @param int   $user_id User ID.
     * @param array $input   Raw preference map.
     * @return bool
     */
    public function update_user_preferences( $user_id, $input ) {
        $user_id = absint( $user_id );
        if ( ! $this->is_system_enabled() || ! $user_id || ! function_exists( 'qibbs_update_user_notification_preferences' ) ) {
            return false;
        }

        $input = is_array( $input ) ? $input : array();
        $items = $this->get_preference_items();
        $sanitized = array();

        foreach ( $items as $key => $label ) {
            $raw_value = isset( $input[ $key ] ) ? $input[ $key ] : false;
            $sanitized[ $key ] = filter_var( $raw_value, FILTER_VALIDATE_BOOLEAN );
        }

        return qibbs_update_user_notification_preferences( $user_id, $sanitized );
    }

    public function get_notifications( $user_id, $limit = 20, $offset = 0, $unread_only = false ) {
        if ( ! $this->is_system_enabled() ) {
            return array();
        }

        global $wpdb;
        $table = Qibbs_Database::notifications();

        $where_unread = $unread_only ? " AND is_read = 0" : "";

        // 排除私信类型通知（私信独立在私信模块管理）
        $notifications = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND type != 'message'{$where_unread}
             ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ) );

        return $this->enrich_notifications( $notifications );
    }

    public function get_unread_count( $user_id ) {
        if ( ! $this->is_system_enabled() ) {
            return 0;
        }

        return Qibbs_Notification_Delivery::get_unread_count( $user_id, true );
    }

    /**
     * 获取社区顶栏未读角标统计（通知与私信）
     *
     * @param int $user_id User ID.
     * @return array<string,int>
     */
    public function get_unread_badge_counts( $user_id ) {
        $user_id = absint( $user_id );
        $counts = array(
            'notifications' => 0,
            'messages'      => 0,
        );

        if ( ! $user_id ) {
            return $counts;
        }

        if ( qibbs_is_module_enabled( 'notifications' ) && $this->is_system_enabled() ) {
            $counts['notifications'] = $this->get_unread_count( $user_id );
        }

        if ( qibbs_is_module_enabled( 'messages' ) ) {
            $message_service = new Qibbs_Message_Service();
            $counts['messages'] = $message_service->get_unread_count( $user_id );
        }

        return $counts;
    }

    public function mark_as_read( $notification_id, $user_id ) {
        global $wpdb;
        $table = Qibbs_Database::notifications();

        $result = $wpdb->update(
            $table,
            array( 'is_read' => 1 ),
            array( 'id' => $notification_id, 'user_id' => $user_id, 'is_read' => 0 ),
            array( '%d' ),
            array( '%d', '%d', '%d' )
        );

        if ( $result > 0 ) {
            Qibbs_Notification_Delivery::decrement_unread_cache( $user_id, 1, true );
        } elseif ( false === $result ) {
            Qibbs_Notification_Delivery::invalidate_unread_cache( $user_id );
        }

        return $result;
    }

    public function mark_all_as_read( $user_id ) {
        global $wpdb;
        $table = Qibbs_Database::notifications();

        $result = $wpdb->update(
            $table,
            array( 'is_read' => 1 ),
            array( 'user_id' => $user_id, 'is_read' => 0 ),
            array( '%d' ),
            array( '%d', '%d' )
        );

        if ( false !== $result ) {
            Qibbs_Notification_Delivery::set_unread_cache_value( $user_id, 0, true );
        }

        return $result;
    }

    private function enrich_notifications( $notifications ) {
        if ( empty( $notifications ) ) {
            return array();
        }

        $type_labels = array(
            'like'             => __( '赞了你的动态', 'qibbs-community' ),
            'comment_like'     => __( '赞了你的评论', 'qibbs-community' ),
            'comment'          => __( '评论了你的动态', 'qibbs-community' ),
            'reply'            => __( '回复了你的评论', 'qibbs-community' ),
            'best_answer'      => __( '将你的回复设为最佳答案', 'qibbs-community' ),
            'follow'           => __( '关注了你', 'qibbs-community' ),
            'favorite'         => __( '收藏了你的动态', 'qibbs-community' ),
            'forum_post'       => __( '你订阅的圈子有新动态', 'qibbs-community' ),
            'report'           => __( '收到了新的举报', 'qibbs-community' ),
            'post_blocked'     => __( '你的动态已被管理员屏蔽', 'qibbs-community' ),
            'post_deleted'     => __( '你的动态已被管理员移入回收站', 'qibbs-community' ),
            'moderation_result'=> __( '审核结果通知', 'qibbs-community' ),
            'system'           => __( '系统通知', 'qibbs-community' ),
            'prop_purchased'   => __( '道具选购成功', 'qibbs-community' ),
            'promotion_expired'=> __( '置顶推广已到期', 'qibbs-community' ),
            'redpacket_won'    => __( '抢到了动态红包', 'qibbs-community' ),
            'redpacket_refunded'=> __( '未领红包已退回', 'qibbs-community' ),
            'bounty_awarded'   => __( '获得了动态悬赏', 'qibbs-community' ),
            'bounty_refunded'  => __( '未结悬赏已退回', 'qibbs-community' ),
            'uid_recycled'     => __( 'UID 号码已回收', 'qibbs-community' ),
            'c2c_sold'         => __( 'UID 号码已售出', 'qibbs-community' ),
            'c2c_bought'       => __( 'UID 号码购买成功', 'qibbs-community' ),
            'ticket_reply'     => __( '回复了你的工单', 'qibbs-community' ),
            'ticket_resolved'  => __( '你的工单已解决', 'qibbs-community' ),
            'ticket_published' => __( '你的工单已公开分享', 'qibbs-community' ),
            'ticket_sla'       => __( '工单 SLA 提醒', 'qibbs-community' ),
        );

        /**
         * 通知类型文案过滤器
         *
         * @since 1.1.0
         * @param array $type_labels Notification type labels keyed by type.
         */
        $type_labels = apply_filters( 'qibbs_notification_types', $type_labels );

        // 关联帖子并需展示内容摘要的通知类型
        $post_types = array( 'like', 'comment', 'favorite', 'reply', 'best_answer' );

        $actor_ids = array();
        $post_ids_for_preview = array();
        $comment_ids_for_link = array();

        foreach ( $notifications as $notif_item ) {
            if ( ! in_array( $notif_item->type, array( 'post_blocked', 'post_deleted' ), true ) && ! empty( $notif_item->actor_id ) ) {
                $actor_ids[] = (int) $notif_item->actor_id;
            }

            if ( empty( $notif_item->content ) && in_array( $notif_item->type, $post_types, true ) && ! empty( $notif_item->object_id ) ) {
                $post_ids_for_preview[] = (int) $notif_item->object_id;
            }

            if ( 'comment' === (string) $notif_item->object_type && ! empty( $notif_item->object_id ) ) {
                $comment_ids_for_link[] = (int) $notif_item->object_id;
            }
        }

        $actor_map = array();
        $actor_ids = array_values( array_unique( array_filter( $actor_ids ) ) );
        if ( ! empty( $actor_ids ) ) {
            $actors = get_users( array(
                'include' => $actor_ids,
                'fields'  => array( 'ID', 'display_name' ),
                'count_total' => false,
            ) );
            foreach ( $actors as $actor_user ) {
                $actor_map[ (int) $actor_user->ID ] = $actor_user;
            }
        }

        $post_content_map = array();
        $post_ids_for_preview = array_values( array_unique( array_filter( $post_ids_for_preview ) ) );
        if ( ! empty( $post_ids_for_preview ) ) {
            global $wpdb;
            $posts_table = Qibbs_Database::posts();
            $post_placeholders = implode( ',', array_fill( 0, count( $post_ids_for_preview ), '%d' ) );
            $post_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, content FROM {$posts_table} WHERE id IN ({$post_placeholders})",
                    $post_ids_for_preview
                )
            );
            foreach ( $post_rows as $post_row ) {
                $post_content_map[ (int) $post_row->id ] = $post_row->content;
            }
        }

        $comment_post_map = array();
        $comment_ids_for_link = array_values( array_unique( array_filter( $comment_ids_for_link ) ) );
        if ( ! empty( $comment_ids_for_link ) ) {
            global $wpdb;
            $comments_table = Qibbs_Database::comments();
            $comment_placeholders = implode( ',', array_fill( 0, count( $comment_ids_for_link ), '%d' ) );
            $comment_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, post_id FROM {$comments_table} WHERE id IN ({$comment_placeholders})",
                    $comment_ids_for_link
                )
            );

            foreach ( $comment_rows as $comment_row ) {
                $comment_post_map[ (int) $comment_row->id ] = (int) $comment_row->post_id;
            }
        }

        foreach ( $notifications as &$notif ) {
            // 管理操作（屏蔽/删除）触发者统一显示为系统
            if ( in_array( $notif->type, array( 'post_blocked', 'post_deleted', 'system', 'ticket_sla' ), true ) || ( 'moderation_result' === $notif->type && empty( $notif->actor_id ) ) ) {
                $notif->actor = array(
                    'id'           => 0,
                    'display_name' => __( '系统通知', 'qibbs-community' ),
                    'avatar'       => get_avatar_url( 0, array( 'size' => 48 ) ),
                );
            } else {
                $actor_id = (int) $notif->actor_id;
                $actor = isset( $actor_map[ $actor_id ] ) ? $actor_map[ $actor_id ] : null;
                $notif->actor = array(
                    'id'           => $actor_id,
                    'display_name' => $actor ? $actor->display_name : __( '未知用户', 'qibbs-community' ),
                    'avatar'       => get_avatar_url( $actor_id, array( 'size' => 48 ) ),
                    'profile_url'  => qibbs_get_profile_url( $actor_id ),
                );
            }
            
            // 构建通知类型文本
            $notif->type_label = $type_labels[ $notif->type ] ?? $notif->type;
            
            // 为帖子相关通知自动补充正文预览
            if ( empty( $notif->content ) && in_array( $notif->type, $post_types, true ) && $notif->object_id ) {
                $post_content = isset( $post_content_map[ (int) $notif->object_id ] ) ? $post_content_map[ (int) $notif->object_id ] : '';
                if ( $post_content ) {
                    $notif->content = mb_substr( wp_strip_all_tags( $post_content ), 0, 30 );
                    if ( mb_strlen( wp_strip_all_tags( $post_content ) ) > 30 ) {
                        $notif->content .= '...';
                    }
                }
            }
            
            $notif->time_ago = qibbs_human_time_diff( $notif->created_at );
            $notif->link = $this->get_notification_link( $notif, $comment_post_map );
        }
        return $notifications;
    }

    private function get_notification_link( $notif, $comment_post_map = array() ) {
        switch ( $notif->object_type ) {
            case 'post':
                return qibbs_get_post_url( $notif->object_id );
            case 'comment':
                $comment_id = absint( $notif->object_id );
                if ( ! $comment_id ) {
                    return qibbs_get_community_url();
                }

                if ( isset( $comment_post_map[ $comment_id ] ) ) {
                    $post_id = (int) $comment_post_map[ $comment_id ];
                } else {
                    static $comment_post_fallback_map = array();
                    if ( ! isset( $comment_post_fallback_map[ $comment_id ] ) ) {
                        global $wpdb;
                        $comments_table = Qibbs_Database::comments();
                        $post_id = (int) $wpdb->get_var(
                            $wpdb->prepare(
                                "SELECT post_id FROM {$comments_table} WHERE id = %d LIMIT 1",
                                $comment_id
                            )
                        );
                        $comment_post_fallback_map[ $comment_id ] = $post_id;
                    }

                    $post_id = isset( $comment_post_fallback_map[ $comment_id ] ) ? (int) $comment_post_fallback_map[ $comment_id ] : 0;
                }
                if ( $post_id > 0 ) {
                    return qibbs_get_post_url( $post_id, 'comment-' . $comment_id );
                }

                // 历史数据兼容：兼容旧版存入的评论与帖子关联
                return qibbs_get_post_url( $comment_id );
            case 'user':
                return qibbs_get_profile_url( $notif->object_id );
            case 'message':
                return qibbs_get_messages_url( array( 'user' => absint( $notif->actor_id ) ) );
            case 'ticket':
                // 若可用则使用主题集成类跳转至个人中心
                if ( class_exists( 'Qibbs_Qiling_Integration' ) ) {
                    return Qibbs_Qiling_Integration::get_ticket_detail_url( $notif->object_id );
                }
                $ticket_page_id = qibbs_get_option( 'ticket_page', 0 );
                if ( $ticket_page_id ) {
                    return qibbs_translate_frontend_internal_url(
                        add_query_arg( array( 'action' => 'view', 'id' => $notif->object_id ), get_permalink( $ticket_page_id ) )
                    );
                }
                return '#';
            case 'report':
                if ( current_user_can( 'manage_options' ) ) {
                    return admin_url( 'admin.php?page=qibbs-reports&tab=pending' );
                }
                return qibbs_get_community_url();
            case 'prop':
                return function_exists( 'qibbs_get_prop_mall_url' ) ? qibbs_get_prop_mall_url() : qibbs_get_community_url();
            default:
                return qibbs_get_community_url();
        }
    }

    public function ajax_get_notifications() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $this->ensure_system_enabled_ajax();

        $page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page = 20;
        $offset = ( $page - 1 ) * $per_page;

        $notifications = $this->get_notifications( $user_id, $per_page, $offset );
        $unread_count = $this->get_unread_count( $user_id );

        wp_send_json_success( array(
            'notifications' => $notifications,
            'unread_count'  => $unread_count,
        ) );
    }

    /**
     * AJAX 轻量接口：获取未读通知数
     */
    public function ajax_get_unread_count() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $this->ensure_system_enabled_ajax();

        wp_send_json_success( array(
            'unread_count' => $this->get_unread_count( $user_id ),
        ) );
    }

    /**
     * AJAX 接口：获取通知与私信合并未读计数
     */
    public function ajax_get_unread_counts() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        wp_send_json_success( $this->get_unread_badge_counts( $user_id ) );
    }

    /**
     * AJAX 接口：获取当前用户通知偏好
     */
    public function ajax_get_preferences() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $this->ensure_system_enabled_ajax();

        wp_send_json_success( array(
            'items' => $this->get_user_preferences( $user_id ),
        ) );
    }

    /**
     * AJAX 接口：保存当前用户通知偏好
     */
    public function ajax_save_preferences() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $this->ensure_system_enabled_ajax();

        $raw_prefs = isset( $_POST['preferences'] ) ? (array) wp_unslash( $_POST['preferences'] ) : array();
        $updated = $this->update_user_preferences( $user_id, $raw_prefs );
        if ( ! $updated ) {
            wp_send_json_error( array( 'message' => __( '保存失败，请稍后重试', 'qibbs-community' ) ) );
        }

        wp_send_json_success( array(
            'message' => __( '通知偏好已保存', 'qibbs-community' ),
            'items'   => $this->get_user_preferences( $user_id ),
        ) );
    }

    public function ajax_mark_read() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $this->ensure_system_enabled_ajax();

        $notification_id = isset( $_POST['notification_id'] ) ? absint( $_POST['notification_id'] ) : 0;

        if ( $notification_id ) {
            $this->mark_as_read( $notification_id, $user_id );
        } else {
            $this->mark_all_as_read( $user_id );
        }

        wp_send_json_success( array( 'unread_count' => $this->get_unread_count( $user_id ) ) );
    }

    /**
     * 管理操作通知投递（帖子屏蔽/删除）
     *
     * @param int    $user_id         Target user ID
     * @param string $type            Notification type (post_blocked, post_deleted)
     * @param int    $post_id         Post ID
     * @param int    $admin_id        Admin user ID
     * @param string $content_preview Optional content preview
     */
    public function create_admin_notification( $user_id, $type, $post_id, $admin_id = 0, $content_preview = '' ) {
        if ( ! $this->is_system_enabled() || ! $user_id || ! in_array( $type, array( 'post_blocked', 'post_deleted' ), true ) ) {
            return false;
        }

        if ( function_exists( 'qibbs_can_send_notification_type' ) && ! qibbs_can_send_notification_type( $user_id, $type ) ) {
            return false;
        }

        // 采用管理员 ID 或系统身份（0）
        $actor_id = $admin_id ? $admin_id : 0;

        return Qibbs_Notification_Delivery::insert( array(
            'user_id'     => $user_id,
            'type'        => $type,
            'actor_id'    => $actor_id,
            'object_type' => 'post',
            'object_id'   => $post_id,
            'content'     => $content_preview,
            'created_at'  => current_time( 'mysql' ),
        ) );
    }

    /**
     * 创建内容审核结果通知
     *
     * @param int    $user_id     Target user ID.
     * @param string $object_type Object type.
     * @param int    $object_id   Object ID.
     * @param string $action      approve|reject|delete|trash|restore|purge|pin|unpin|feature|unfeature|lock|unlock|move_forum|blacklist|unblacklist|set_post_cooldown|clear_post_cooldown.
     * @param string $content     Message content.
     * @param int    $admin_id    Admin user ID.
     * @return bool|int
     */
    public function create_moderation_notification( $user_id, $object_type, $object_id, $action, $content = '', $admin_id = 0 ) {
        $user_id = absint( $user_id );
        $object_id = absint( $object_id );
        $admin_id = absint( $admin_id );
        $action = sanitize_key( $action );
        $object_type = sanitize_key( $object_type );

        if ( ! $this->is_system_enabled() || ! $user_id || ! $object_id || ! in_array( $action, array( 'approve', 'reject', 'delete', 'trash', 'restore', 'purge', 'pin', 'unpin', 'feature', 'unfeature', 'lock', 'unlock', 'move_forum', 'blacklist', 'unblacklist', 'set_post_cooldown', 'clear_post_cooldown' ), true ) ) {
            return false;
        }

        if ( function_exists( 'qibbs_can_send_notification_type' ) && ! qibbs_can_send_notification_type( $user_id, 'moderation_result' ) ) {
            return false;
        }

        $action_label = array(
            'approve' => __( '已通过', 'qibbs-community' ),
            'reject'  => __( '未通过', 'qibbs-community' ),
            'delete'  => __( '已删除', 'qibbs-community' ),
            'trash'   => __( '已移入回收站', 'qibbs-community' ),
            'restore' => __( '已恢复为待审', 'qibbs-community' ),
            'purge'   => __( '已彻底删除', 'qibbs-community' ),
            'pin'     => __( '已被置顶', 'qibbs-community' ),
            'unpin'   => __( '已取消置顶', 'qibbs-community' ),
            'feature' => __( '已设为精华', 'qibbs-community' ),
            'unfeature' => __( '已取消精华', 'qibbs-community' ),
            'lock'    => __( '已被锁帖', 'qibbs-community' ),
            'unlock'  => __( '已解除锁帖', 'qibbs-community' ),
            'move_forum' => __( '已被移动到其他圈子', 'qibbs-community' ),
            'blacklist' => __( '已被限制发言', 'qibbs-community' ),
            'unblacklist' => __( '已解除限制发言', 'qibbs-community' ),
            'set_post_cooldown' => __( '已被设置发帖冷却', 'qibbs-community' ),
            'clear_post_cooldown' => __( '已解除发帖冷却', 'qibbs-community' ),
        );

        $object_prefix_map = array(
            'post'    => __( '你的动态', 'qibbs-community' ),
            'comment' => __( '你的评论', 'qibbs-community' ),
            'user'    => __( '你的账号', 'qibbs-community' ),
            'forum'   => __( '圈子配置', 'qibbs-community' ),
        );

        $prefix = sprintf(
            '%s%s',
            isset( $object_prefix_map[ $object_type ] ) ? $object_prefix_map[ $object_type ] : __( '你的内容', 'qibbs-community' ),
            $action_label[ $action ]
        );

        $message = $prefix;
        if ( ! empty( $content ) ) {
            $message .= '：' . sanitize_text_field( $content );
        }

        return Qibbs_Notification_Delivery::insert( array(
            'user_id'     => $user_id,
            'type'        => 'moderation_result',
            'actor_id'    => $admin_id,
            'object_type' => $object_type,
            'object_id'   => $object_id,
            'content'     => $message,
            'created_at'  => current_time( 'mysql' ),
        ) );
    }

    /**
     * 用户提交举报时向所有管理员推送待审通知
     *
     * @param int    $report_id    Report ID.
     * @param int    $post_id      Reported post ID.
     * @param int    $reporter_id  Reporter user ID.
     * @param string $reason       Report reason.
     * @return int Number of notifications queued.
     */
    public function create_report_notification_for_admins( $report_id, $post_id, $reporter_id, $reason = '' ) {
        $report_id = absint( $report_id );
        $post_id = absint( $post_id );
        $reporter_id = absint( $reporter_id );
        $reason = sanitize_text_field( (string) $reason );

        if ( ! $this->is_system_enabled() || ! $report_id || ! $post_id || ! $reporter_id ) {
            return 0;
        }

        $admin_ids = $this->get_notification_admin_ids();
        if ( empty( $admin_ids ) ) {
            return 0;
        }

        $content = sprintf( __( '动态 #%d 收到新举报', 'qibbs-community' ), $post_id );
        if ( ! empty( $reason ) ) {
            $snippet = mb_substr( $reason, 0, 80 );
            $content .= '：' . $snippet;
        }

        $rows = array();
        foreach ( $admin_ids as $admin_id ) {
            if ( function_exists( 'qibbs_can_send_notification_type' ) && ! qibbs_can_send_notification_type( $admin_id, 'report' ) ) {
                continue;
            }

            $rows[] = array(
                'user_id'     => $admin_id,
                'type'        => 'report',
                'actor_id'    => $reporter_id,
                'object_type' => 'report',
                'object_id'   => $report_id,
                'content'     => $content,
                'dedupe_key'  => sprintf( 'report:%d:%d', (int) $admin_id, (int) $report_id ),
                'created_at'  => current_time( 'mysql' ),
            );
        }

        if ( empty( $rows ) ) {
            return 0;
        }

        return Qibbs_Notification_Delivery::queue_batch( $rows, 80, 'report_admin' );
    }

    /**
     * AJAX 获取未读私信数
     */
    public function ajax_get_unread_message_count() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        if ( ! qibbs_is_module_enabled( 'messages' ) ) {
            wp_send_json_success( array( 'unread_count' => 0 ) );
        }

        $message_service = new Qibbs_Message_Service();
        $unread_count = $message_service->get_unread_count( $user_id );

        wp_send_json_success( array( 'unread_count' => $unread_count ) );
    }

    private function get_interaction_aggregate_window() {
        $window = (int) apply_filters( 'qibbs_notification_interaction_aggregate_window', self::INTERACTION_AGGREGATE_WINDOW );
        return max( 0, $window );
    }

    private function should_aggregate_interaction_type( $type ) {
        return in_array( (string) $type, array( 'like', 'favorite' ), true );
    }

    private function maybe_refresh_aggregated_interaction( $target_user_id, $type, $object_type, $object_id, $actor_id ) {
        if ( ! $this->should_aggregate_interaction_type( $type ) ) {
            return false;
        }

        $window = $this->get_interaction_aggregate_window();
        if ( $window <= 0 ) {
            return false;
        }

        global $wpdb;
        $table = Qibbs_Database::notifications();
        $cutoff = qibbs_format_datetime( 'Y-m-d H:i:s', qibbs_current_timestamp() - $window );

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$table}
                 WHERE user_id = %d
                   AND type = %s
                   AND object_type = %s
                   AND object_id = %d
                   AND is_read = 0
                   AND created_at >= %s
                 ORDER BY id DESC
                 LIMIT 1",
                $target_user_id,
                $type,
                $object_type,
                $object_id,
                $cutoff
            )
        );

        if ( $existing_id <= 0 ) {
            return false;
        }

        $updated = $wpdb->update(
            $table,
            array(
                'actor_id'   => $actor_id,
                'created_at' => current_time( 'mysql' ),
                'is_read'    => 0,
            ),
            array(
                'id'      => $existing_id,
                'user_id' => $target_user_id,
            ),
            array( '%d', '%s', '%d' ),
            array( '%d', '%d' )
        );

        if ( false === $updated ) {
            return false;
        }

        Qibbs_Notification_Delivery::invalidate_unread_cache( $target_user_id );
        return true;
    }

    /**
     * 创建带去重机制的互动通知
     * 同一用户/同一帖子/同一类型在时间窗口内仅提醒一次
     *
     * @param int    $target_user_id Target user to notify
     * @param int    $actor_id       User who performed the action
     * @param string $type           Notification type (like, comment, reply, follow, favorite)
     * @param string $object_type    Object type (post, comment, user)
     * @param int    $object_id      Object ID
     * @return bool|int False if already notified, insert ID on success
     */
    public function create_interaction_notification( $target_user_id, $actor_id, $type, $object_type, $object_id ) {
        // 自己对自己的操作不发送通知
        if ( ! $this->is_system_enabled() || $target_user_id == $actor_id || ! $target_user_id || ! $actor_id ) {
            return false;
        }

        if ( function_exists( 'qibbs_can_send_notification_type' ) && ! qibbs_can_send_notification_type( $target_user_id, $type ) ) {
            return false;
        }

        global $wpdb;
        $table = Qibbs_Database::notifications();

        // 检查是否存在完全重复的通知（防刷去重）
        // 相同触发者、目标用户、类型与实体对象仅生成单条通知
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} 
             WHERE user_id = %d AND actor_id = %d AND type = %s AND object_type = %s AND object_id = %d",
            $target_user_id, $actor_id, $type, $object_type, $object_id
        ) );

        if ( $existing ) {
            // 该互动已通知过，跳过重复写入
            return false;
        }

        /**
         * 通知发送前置权限过滤
         *
         * @since 1.1.0
         * @param bool   $should_send   Whether to send the notification.
         * @param string $type          Notification type.
         * @param int    $target_user_id Target user ID.
         * @param int    $actor_id      Actor user ID.
         */
        $should_send = apply_filters( 'qibbs_should_send_notification', true, $type, $target_user_id, $actor_id );
        if ( ! $should_send ) {
            return false;
        }

        if ( $this->maybe_refresh_aggregated_interaction( $target_user_id, $type, $object_type, $object_id, $actor_id ) ) {
            return true;
        }

        $dedupe_key = sprintf(
            'interaction:%d:%s:%d:%s:%d',
            (int) $target_user_id,
            (string) $type,
            (int) $actor_id,
            (string) $object_type,
            (int) $object_id
        );

        // 创建单条站内通知
        return Qibbs_Notification_Delivery::insert( array(
            'user_id'     => $target_user_id,
            'type'        => $type,
            'actor_id'    => $actor_id,
            'object_type' => $object_type,
            'object_id'   => $object_id,
            'is_read'     => 0,
            'dedupe_key'  => $dedupe_key,
            'created_at'  => current_time( 'mysql' ),
        ) );
    }

    /**
     * 创建收藏动态通知
     *
     * @param int $target_user_id Post author to notify
     * @param int $actor_id       User who favorited
     * @param int $post_id        Post ID
     * @return bool|int
     */
    public function notify_favorite( $target_user_id, $actor_id, $post_id ) {
        return $this->create_interaction_notification(
            $target_user_id,
            $actor_id,
            'favorite',
            'post',
            $post_id
        );
    }

    /**
     * 获取所有具备管理权限的用户 ID 列表
     *
     * @return array
     */
    private function get_notification_admin_ids() {
        $cache_key = 'qibbs_notification_admin_ids';
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $admin_ids = get_users( array(
            'fields'     => 'ID',
            'capability' => 'manage_options',
        ) );

        $admin_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $admin_ids ) ) ) );

        // 兼容低版本环境下基于角色的管理员查找
        if ( empty( $admin_ids ) ) {
            $user_rows = get_users( array( 'fields' => array( 'ID' ) ) );
            foreach ( $user_rows as $user_row ) {
                $uid = isset( $user_row->ID ) ? absint( $user_row->ID ) : 0;
                if ( $uid && user_can( $uid, 'manage_options' ) ) {
                    $admin_ids[] = $uid;
                }
            }
            $admin_ids = array_values( array_unique( $admin_ids ) );
        }

        set_transient( $cache_key, $admin_ids, MINUTE_IN_SECONDS * 5 );
        return $admin_ids;
    }
}
