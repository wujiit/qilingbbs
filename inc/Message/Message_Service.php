<?php
/**
 * 站内私信服务类
 *
 * @package Qibbs_Community
 * @subpackage Message
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Message_Service {

    const UNREAD_CACHE_TTL = 45;
    const CONVERSATION_CACHE_TTL = 30;
    const VERSION_CACHE_TTL = 30 * DAY_IN_SECONDS;

    /**
     * 请求级消息缓存版本字典
     *
     * @var array<string,string>
     */
    private static $runtime_versions = array();

    /**
     * 检查持久化对象缓存（Redis/Memcached）是否可用
     *
     * @return bool
     */
    private static function use_object_cache() {
        return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
    }

    /**
     * 从对象缓存或 Transient 读取缓存数据
     *
     * @param string $key   Cache key.
     * @param string $group Cache group.
     * @param bool   $found Whether cache exists.
     * @return mixed
     */
    private static function cache_get( $key, $group, &$found = false ) {
        if ( self::use_object_cache() ) {
            return wp_cache_get( $key, $group, false, $found );
        }

        $value = get_transient( $key );
        $found = ( false !== $value );
        return $value;
    }

    /**
     * 写入对象缓存或 Transient 存储
     *
     * @param string $key   Cache key.
     * @param mixed  $value Cache value.
     * @param int    $ttl   Cache TTL.
     * @param string $group Cache group.
     * @return void
     */
    private static function cache_set( $key, $value, $ttl, $group ) {
        $ttl = max( 1, absint( $ttl ) );

        if ( self::use_object_cache() ) {
            wp_cache_set( $key, $value, $group, $ttl );
            return;
        }

        set_transient( $key, $value, $ttl );
    }

    /**
     * 获取用户当前消息缓存版本号
     *
     * @param int $user_id User ID.
     * @param string $bucket Cache bucket.
     * @return string
     */
    private static function get_cache_version( $user_id, $bucket = 'conversations' ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return '0';
        }

        $bucket = sanitize_key( (string) $bucket );
        $runtime_key = $bucket . ':' . $user_id;
        if ( isset( self::$runtime_versions[ $runtime_key ] ) ) {
            return self::$runtime_versions[ $runtime_key ];
        }

        $key = 'qibbs_msg_cache_ver_' . $bucket . '_' . $user_id;
        $version = self::cache_get( $key, 'qibbs_message_versions', $found );
        if ( ! $found || '' === $version ) {
            $version = uniqid( 'v', true );
            self::cache_set( $key, $version, self::VERSION_CACHE_TTL, 'qibbs_message_versions' );
        }

        self::$runtime_versions[ $runtime_key ] = (string) $version;
        return (string) $version;
    }

    /**
     * 构建未读消息数缓存键名
     *
     * @param int $user_id User ID.
     * @return string
     */
    private static function get_unread_cache_key( $user_id ) {
        return 'qibbs_msg_unread_' . absint( $user_id ) . '_' . self::get_cache_version( $user_id, 'unread' );
    }

    /**
     * 构建会话列表缓存键名
     *
     * @param int $user_id User ID.
     * @param int $limit   Limit.
     * @param int $offset  Offset.
     * @return string
     */
    private static function get_conversations_cache_key( $user_id, $limit, $offset ) {
        return 'qibbs_msg_conv_' . absint( $user_id ) . '_' . absint( $limit ) . '_' . absint( $offset ) . '_' . self::get_cache_version( $user_id, 'conversations' );
    }

    /**
     * 使用户维度的消息相关缓存失效
     *
     * @param array $user_ids User IDs.
     * @param array $buckets  Buckets to invalidate.
     * @return void
     */
    private static function invalidate_caches_for_users( $user_ids, $buckets = array( 'conversations', 'unread' ) ) {
        $user_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) );
        $buckets = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $buckets ) ) ) );
        if ( empty( $buckets ) ) {
            return;
        }

        foreach ( $user_ids as $user_id ) {
            foreach ( $buckets as $bucket ) {
                $key = 'qibbs_msg_cache_ver_' . $bucket . '_' . $user_id;
                $version = uniqid( 'v', true );
                self::$runtime_versions[ $bucket . ':' . $user_id ] = $version;
                self::cache_set( $key, $version, self::VERSION_CACHE_TTL, 'qibbs_message_versions' );
            }
        }
    }

    /**
     * 增量修正未读计数缓存
     *
     * @param int $user_id User ID.
     * @param int $delta   Count delta.
     * @return void
     */
    private static function adjust_unread_cache_value( $user_id, $delta ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return;
        }

        $cache_key = self::get_unread_cache_key( $user_id );
        $cached = self::cache_get( $cache_key, 'qibbs_message_data', $found );
        if ( ! $found ) {
            return;
        }

        self::cache_set(
            $cache_key,
            max( 0, (int) $cached + (int) $delta ),
            self::UNREAD_CACHE_TTL,
            'qibbs_message_data'
        );
    }

    public function send_message( $sender_id, $receiver_id, $content ) {
        if ( ! qibbs_is_module_enabled( 'messages' ) ) {
            return array( 'success' => false, 'message' => qibbs_get_module_disabled_message( 'messages' ) );
        }

        if ( ! $sender_id || ! $receiver_id || empty( $content ) ) {
            return array( 'success' => false, 'message' => __( '参数错误', 'qibbs-community' ) );
        }

        if (
            function_exists( 'qibbs_is_user_blacklisted' ) &&
            qibbs_is_user_blacklisted( $sender_id ) &&
            ! user_can( $sender_id, 'manage_options' )
        ) {
            return array( 'success' => false, 'message' => __( '你的账号已被限制，暂时无法发送私信', 'qibbs-community' ) );
        }

        if ( $sender_id === $receiver_id ) {
            return array( 'success' => false, 'message' => __( '不能给自己发消息', 'qibbs-community' ) );
        }

        /**
         * 私信发送权限过滤器
         *
         * @since 1.1.0
         * @param bool $can_send    Whether the user can send message.
         * @param int  $sender_id   Sender user ID.
         * @param int  $receiver_id Receiver user ID.
         */
        $can_send = apply_filters( 'qibbs_can_send_message', true, $sender_id, $receiver_id );
        if ( ! $can_send ) {
            return array( 'success' => false, 'message' => __( '没有发送私信权限', 'qibbs-community' ) );
        }

        /**
         * 私信正文发送前安全过滤
         *
         * @since 1.1.0
         * @param string $content     Message content.
         * @param int    $sender_id   Sender user ID.
         * @param int    $receiver_id Receiver user ID.
         */
        $content = apply_filters( 'qibbs_message_content', $content, $sender_id, $receiver_id );

        if ( class_exists( 'Qibbs_Risk_Service' ) ) {
            $risk_check = Qibbs_Risk_Service::evaluate_content_submission(
                $sender_id,
                'message',
                $content,
                array(
                    'receiver_id' => $receiver_id,
                )
            );
            if ( is_wp_error( $risk_check ) ) {
                return array( 'success' => false, 'message' => $risk_check->get_error_message() );
            }
        }

        global $wpdb;
        $table = Qibbs_Database::messages();

        $result = $wpdb->insert( $table, array(
            'sender_id'   => $sender_id,
            'receiver_id' => $receiver_id,
            'content'     => qibbs_sanitize_content( $content ),
            'is_read'     => 0,
            'created_at'  => current_time( 'mysql' ),
        ), array( '%d', '%d', '%s', '%d', '%s' ) );

        if ( ! $result ) {
            return array( 'success' => false, 'message' => __( '发送失败', 'qibbs-community' ) );
        }

        $message_id = $wpdb->insert_id;

        self::invalidate_caches_for_users( array( $sender_id, $receiver_id ), array( 'conversations' ) );
        self::adjust_unread_cache_value( $receiver_id, 1 );

        // 私信消息通过私信面板独立呈现，不重复生成系统通知
        // 用户直接在私信页面查阅对话记录
        // 私信导航栏未读角标自动联动未读计数

        do_action( 'qibbs_message_sent', $message_id, $sender_id, $receiver_id );

        return array( 'success' => true, 'message_id' => $message_id );
    }

    public function get_conversations( $user_id, $limit = 20, $offset = 0 ) {
        if ( ! qibbs_is_module_enabled( 'messages' ) ) {
            return array();
        }

        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return array();
        }
        $limit   = max( 1, min( 100, absint( $limit ) ) );
        $offset  = max( 0, absint( $offset ) );
        $cache_key = self::get_conversations_cache_key( $user_id, $limit, $offset );
        $cached = self::cache_get( $cache_key, 'qibbs_message_data', $found );
        if ( $found && is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;
        $table = Qibbs_Database::messages();

        // 查询当前用户的会话列表及最近一条消息
        $conversations = $wpdb->get_results( $wpdb->prepare(
            "SELECT 
                CASE WHEN sender_id = %d THEN receiver_id ELSE sender_id END as partner_id,
                MAX(id) as last_message_id,
                MAX(created_at) as last_time
             FROM {$table}
             WHERE sender_id = %d OR receiver_id = %d
             GROUP BY partner_id
             ORDER BY last_time DESC
             LIMIT %d OFFSET %d",
            $user_id, $user_id, $user_id, $limit, $offset
        ) );

        if ( empty( $conversations ) ) {
            self::cache_set( $cache_key, array(), self::CONVERSATION_CACHE_TTL, 'qibbs_message_data' );
            return array();
        }

        $partner_ids = array();
        $last_message_ids = array();
        foreach ( $conversations as $conv_item ) {
            $partner_ids[] = (int) $conv_item->partner_id;
            $last_message_ids[] = (int) $conv_item->last_message_id;
        }

        $partner_ids = array_values( array_unique( array_filter( $partner_ids ) ) );
        $last_message_ids = array_values( array_unique( array_filter( $last_message_ids ) ) );

        $partner_map = array();
        if ( ! empty( $partner_ids ) ) {
            $partners = get_users( array(
                'include' => $partner_ids,
                'fields'  => array( 'ID', 'display_name' ),
                'count_total' => false,
            ) );
            foreach ( $partners as $partner_user ) {
                $partner_map[ (int) $partner_user->ID ] = $partner_user;
            }
        }

        $last_message_map = array();
        if ( ! empty( $last_message_ids ) ) {
            $message_placeholders = implode( ',', array_fill( 0, count( $last_message_ids ), '%d' ) );
            $last_messages = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, content, sender_id, created_at FROM {$table} WHERE id IN ({$message_placeholders})",
                    $last_message_ids
                )
            );
            foreach ( $last_messages as $last_message_row ) {
                $last_message_map[ (int) $last_message_row->id ] = $last_message_row;
            }
        }

        $unread_counts = array();
        $should_query_unread_rows = ! empty( $partner_ids );
        if ( $should_query_unread_rows ) {
            $total_unread_cached = self::cache_get( self::get_unread_cache_key( $user_id ), 'qibbs_message_data', $found );
            if ( $found && (int) $total_unread_cached <= 0 ) {
                $should_query_unread_rows = false;
            }
        }

        if ( $should_query_unread_rows ) {
            $partner_placeholders = implode( ',', array_fill( 0, count( $partner_ids ), '%d' ) );
            $unread_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT sender_id, COUNT(*) AS unread_count
                     FROM {$table}
                     WHERE receiver_id = %d AND is_read = 0 AND sender_id IN ({$partner_placeholders})
                     GROUP BY sender_id",
                    array_merge( array( $user_id ), $partner_ids )
                )
            );

            foreach ( $unread_rows as $unread_row ) {
                $unread_counts[ (int) $unread_row->sender_id ] = (int) $unread_row->unread_count;
            }
        }

        foreach ( $conversations as &$conv ) {
            $partner_id = (int) $conv->partner_id;
            $user = isset( $partner_map[ $partner_id ] ) ? $partner_map[ $partner_id ] : null;
            $conv->partner = array(
                'id'           => $partner_id,
                'display_name' => $user ? $user->display_name : __( '未知用户', 'qibbs-community' ),
                'avatar'       => get_avatar_url( $partner_id, array( 'size' => 64 ) ),
            );

            $last_message_id = (int) $conv->last_message_id;
            $conv->last_message = isset( $last_message_map[ $last_message_id ] ) ? $last_message_map[ $last_message_id ] : null;
            $conv->unread_count = isset( $unread_counts[ $partner_id ] ) ? $unread_counts[ $partner_id ] : 0;
        }

        self::cache_set( $cache_key, $conversations, self::CONVERSATION_CACHE_TTL, 'qibbs_message_data' );

        return $conversations;
    }

    public function get_messages( $user_id, $partner_id, $limit = 50, $before_id = 0 ) {
        if ( ! qibbs_is_module_enabled( 'messages' ) ) {
            return array();
        }

        global $wpdb;
        $table = Qibbs_Database::messages();

        $before_clause = $before_id ? $wpdb->prepare( " AND id < %d", $before_id ) : '';

        $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE ((sender_id = %d AND receiver_id = %d) OR (sender_id = %d AND receiver_id = %d))
             {$before_clause}
             ORDER BY created_at DESC LIMIT %d",
            $user_id, $partner_id, $partner_id, $user_id, $limit
        ) );

        // 标记当前会话为已读
        $this->mark_as_read( $user_id, $partner_id );

        // 扩展消息属性与时间格式化
        foreach ( $messages as &$msg ) {
            $msg->is_self = ( $msg->sender_id == $user_id );
            $msg->time_ago = qibbs_human_time_diff( $msg->created_at );
        }

        return array_reverse( $messages );
    }

    public function mark_as_read( $user_id, $partner_id ) {
        $user_id    = absint( $user_id );
        $partner_id = absint( $partner_id );
        if ( ! $user_id || ! $partner_id ) {
            return false;
        }

        global $wpdb;
        $table = Qibbs_Database::messages();

        $result = $wpdb->update(
            $table,
            array( 'is_read' => 1 ),
            array( 'receiver_id' => $user_id, 'sender_id' => $partner_id, 'is_read' => 0 ),
            array( '%d' ),
            array( '%d', '%d', '%d' )
        );

        if ( false !== $result ) {
            self::invalidate_caches_for_users( array( $user_id ), array( 'conversations' ) );
            if ( $result > 0 ) {
                self::adjust_unread_cache_value( $user_id, - (int) $result );
            }
        }

        return $result;
    }

    public function get_unread_count( $user_id ) {
        if ( ! qibbs_is_module_enabled( 'messages' ) ) {
            return 0;
        }

        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return 0;
        }

        $cache_key = self::get_unread_cache_key( $user_id );
        $cached    = self::cache_get( $cache_key, 'qibbs_message_data', $found );
        if ( $found ) {
            return max( 0, (int) $cached );
        }

        global $wpdb;
        $table = Qibbs_Database::messages();
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE receiver_id = %d AND is_read = 0",
            $user_id
        ) );
        self::cache_set( $cache_key, $count, self::UNREAD_CACHE_TTL, 'qibbs_message_data' );
        return $count;
    }

    private function get_unread_count_from( $user_id, $partner_id ) {
        global $wpdb;
        $table = Qibbs_Database::messages();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE receiver_id = %d AND sender_id = %d AND is_read = 0",
            $user_id, $partner_id
        ) );
    }

    private function create_message_notification( $sender_id, $receiver_id, $message_id ) {
        Qibbs_Notification_Delivery::insert( array(
            'user_id'     => $receiver_id,
            'type'        => 'message',
            'actor_id'    => $sender_id,
            'object_type' => 'message',
            'object_id'   => $message_id,
            'created_at'  => current_time( 'mysql' ),
        ) );
    }

    public function ajax_send_message() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! qibbs_is_module_enabled( 'messages' ) ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'messages' ) ), 403 );
        }

        $sender_id = get_current_user_id();
        if ( ! $sender_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $receiver_id = isset( $_POST['receiver_id'] ) ? absint( $_POST['receiver_id'] ) : 0;
        $content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';

        $result = $this->send_message( $sender_id, $receiver_id, $content );
        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    public function ajax_load_messages() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! qibbs_is_module_enabled( 'messages' ) ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'messages' ) ), 403 );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $partner_id = isset( $_POST['partner_id'] ) ? absint( $_POST['partner_id'] ) : 0;
        $before_id = isset( $_POST['before_id'] ) ? absint( $_POST['before_id'] ) : 0;

        $messages = $this->get_messages( $user_id, $partner_id, 50, $before_id );
        wp_send_json_success( array( 'messages' => $messages ) );
    }

    public function ajax_get_conversations() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! qibbs_is_module_enabled( 'messages' ) ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'messages' ) ), 403 );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $conversations = $this->get_conversations( $user_id );
        wp_send_json_success( array( 'conversations' => $conversations ) );
    }
}
