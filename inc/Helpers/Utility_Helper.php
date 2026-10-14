<?php
/**
 * 格式化、媒体校验、统计重构与通用工具函数
 *
 * @package Qibbs_Community
 * @subpackage Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 将逗号分隔或数组格式的 ID 标准化为去重的整型列表
 *
 * @param mixed $raw_ids Raw IDs.
 * @return array
 */
function qibbs_parse_id_list( $raw_ids ) {
    if ( is_array( $raw_ids ) ) {
        $ids = $raw_ids;
    } else {
        $raw_ids = trim( (string) $raw_ids );
        $ids = '' === $raw_ids ? array() : explode( ',', $raw_ids );
    }

    $ids = array_map( 'absint', $ids );
    $ids = array_values( array_unique( array_filter( $ids ) ) );

    return $ids;
}

/**
 * 将媒体 ID 列表转换为标准逗号分隔字符串
 *
 * @param mixed $raw_ids Raw media IDs.
 * @return string
 */
function qibbs_media_ids_to_csv( $raw_ids ) {
    return implode( ',', qibbs_parse_id_list( $raw_ids ) );
}

/**
 * 校验媒体附件所有权是否归属当前用户
 *
 * @param int   $user_id   User ID.
 * @param mixed $media_ids Raw media IDs.
 * @param array $args      Validation flags.
 * @return array|WP_Error
 */
function qibbs_validate_media_ids_for_user( $user_id, $media_ids, $args = array() ) {
    $defaults = array(
        'strict'      => false,
        'allow_admin' => true,
    );
    $args = wp_parse_args( $args, $defaults );

    $user_id = absint( $user_id );
    $media_ids = qibbs_parse_id_list( $media_ids );

    if ( empty( $media_ids ) ) {
        return array();
    }

    if ( $args['allow_admin'] && $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
        return $media_ids;
    }

    if ( $user_id <= 0 ) {
        return $args['strict']
            ? new WP_Error( 'qibbs_invalid_media_owner', __( '包含无效或无权限使用的附件', 'qibbs-community' ) )
            : array();
    }

    global $wpdb;
    $media_table = Qibbs_Database::media();
    $placeholders = implode( ',', array_fill( 0, count( $media_ids ), '%d' ) );
    $valid_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT id FROM {$media_table} WHERE user_id = %d AND id IN ({$placeholders})",
            array_merge( array( $user_id ), $media_ids )
        )
    );

    $valid_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $valid_ids ) ) ) );

    if ( $args['strict'] && count( $valid_ids ) !== count( $media_ids ) ) {
        return new WP_Error( 'qibbs_invalid_media_owner', __( '包含无效或无权限使用的附件', 'qibbs-community' ) );
    }

    return $valid_ids;
}

/**
 * 统计媒体文件在全站内容中的引用次数
 *
 * @param int   $media_id Media ID.
 * @param array $exclude  Optional content to exclude from the count.
 * @return int
 */
function qibbs_count_media_references( $media_id, $exclude = array() ) {
    global $wpdb;

    $media_id = absint( $media_id );
    if ( $media_id <= 0 ) {
        return 0;
    }

    $exclude = wp_parse_args(
        $exclude,
        array(
            'post_id'   => 0,
            'ticket_id' => 0,
        )
    );

    $posts_table = Qibbs_Database::posts();
    $tickets_table = Qibbs_Database::tickets();

    $post_where = 'FIND_IN_SET(%d, media_ids) > 0';
    $post_args = array( $media_id );
    if ( ! empty( $exclude['post_id'] ) ) {
        $post_where .= ' AND id != %d';
        $post_args[] = absint( $exclude['post_id'] );
    }

    $ticket_where = 'FIND_IN_SET(%d, attachments) > 0';
    $ticket_args = array( $media_id );
    if ( ! empty( $exclude['ticket_id'] ) ) {
        $ticket_where .= ' AND id != %d';
        $ticket_args[] = absint( $exclude['ticket_id'] );
    }

    $post_refs = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$posts_table} WHERE {$post_where}",
            $post_args
        )
    );

    $ticket_refs = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tickets_table} WHERE {$ticket_where}",
            $ticket_args
        )
    );

    return $post_refs + $ticket_refs;
}

/**
 * 基于有效帖子重新统计并同步标签发帖数
 *
 * @param array $tag_ids Tag IDs.
 * @return void
 */
function qibbs_rebuild_tag_post_counts( $tag_ids ) {
    global $wpdb;

    if ( ! class_exists( 'Qibbs_Database' ) ) {
        return;
    }

    $tag_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $tag_ids ) ) ) );
    if ( empty( $tag_ids ) ) {
        return;
    }

    $tags_table = Qibbs_Database::tags();
    $post_tags_table = Qibbs_Database::post_tags();
    $posts_table = Qibbs_Database::posts();

    foreach ( $tag_ids as $tag_id ) {
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$post_tags_table} pt
                 INNER JOIN {$posts_table} p ON p.id = pt.post_id
                 WHERE pt.tag_id = %d AND p.status = 'publish'",
                $tag_id
            )
        );

        $wpdb->update(
            $tags_table,
            array( 'post_count' => $count ),
            array( 'id' => $tag_id ),
            array( '%d' ),
            array( '%d' )
        );
    }
}

/**
 * 基于有效帖子重新统计并同步版块发帖数
 *
 * @param array $forum_ids Forum IDs.
 * @return void
 */
function qibbs_rebuild_forum_post_counts( $forum_ids ) {
    global $wpdb;

    if ( ! class_exists( 'Qibbs_Database' ) ) {
        return;
    }

    $forum_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $forum_ids ) ) ) );
    if ( empty( $forum_ids ) ) {
        return;
    }

    $posts_table = Qibbs_Database::posts();
    $forums_table = Qibbs_Database::forums();

    foreach ( $forum_ids as $forum_id ) {
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$posts_table} WHERE forum_id = %d AND status = 'publish'",
                $forum_id
            )
        );

        $wpdb->update(
            $forums_table,
            array( 'post_count' => $count ),
            array( 'id' => $forum_id ),
            array( '%d' ),
            array( '%d' )
        );
    }
}

/**
 * 基于有效帖子重新统计并同步用户发帖数
 *
 * @param array $user_ids User IDs.
 * @return void
 */
function qibbs_rebuild_user_post_counts( $user_ids ) {
    global $wpdb;

    if ( ! class_exists( 'Qibbs_Database' ) ) {
        return;
    }

    $user_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) );
    if ( empty( $user_ids ) ) {
        return;
    }

    $posts_table = Qibbs_Database::posts();
    $stats_table = Qibbs_Database::user_stats();

    foreach ( $user_ids as $user_id ) {
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$posts_table}
                 WHERE author_id = %d AND status = 'publish' AND ( source_type = '' OR source_type IS NULL )",
                $user_id
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$stats_table} (user_id, post_count)
                 VALUES (%d, %d)
                 ON DUPLICATE KEY UPDATE post_count = VALUES(post_count)",
                $user_id,
                $count
            )
        );
    }
}

/**
 * 同步帖子关联的版块与标签计数器
 *
 * @param int|array $post_ids One post ID or a list.
 * @param int       $user_id  Optional actor ID from hooks.
 * @return void
 */
function qibbs_sync_post_visibility_counters( $post_ids, $user_id = 0 ) {
    global $wpdb;

    if ( ! class_exists( 'Qibbs_Database' ) ) {
        return;
    }

    $post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) );
    if ( empty( $post_ids ) ) {
        return;
    }

    $posts_table = Qibbs_Database::posts();
    $post_tags_table = Qibbs_Database::post_tags();
    $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

    $forum_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT forum_id FROM {$posts_table} WHERE id IN ({$placeholders}) AND forum_id > 0",
            $post_ids
        )
    );
    qibbs_rebuild_forum_post_counts( $forum_ids );

    $author_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT author_id FROM {$posts_table} WHERE id IN ({$placeholders}) AND author_id > 0",
            $post_ids
        )
    );
    qibbs_rebuild_user_post_counts( $author_ids );

    $tag_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT tag_id FROM {$post_tags_table} WHERE post_id IN ({$placeholders})",
            $post_ids
        )
    );
    qibbs_rebuild_tag_post_counts( $tag_ids );
}
add_action( 'qibbs_post_created', 'qibbs_sync_post_visibility_counters', 20, 2 );
add_action( 'qibbs_post_deleted', 'qibbs_sync_post_visibility_counters', 20, 2 );
add_action( 'qibbs_post_blocked', 'qibbs_sync_post_visibility_counters', 20, 1 );

/**
 * 获取站内通知默认偏好配置
 *
 * @return array
 */
function qibbs_notification_preference_defaults() {
    return array(
        'like'              => true,
        'comment_like'      => true,
        'comment'           => true,
        'reply'             => true,
        'best_answer'       => true,
        'follow'            => true,
        'favorite'          => true,
        'forum_post'        => true,
        'ticket_reply'      => true,
        'ticket_resolved'   => true,
        'ticket_published'  => true,
        'ticket_sla'        => true,
        'moderation_result' => true,
        'post_blocked'      => true,
        'post_deleted'      => true,
        'system'            => true,
        'report'            => true,
    );
}

/**
 * 获取用户个性化通知接收偏好
 *
 * @param int $user_id User ID.
 * @return array
 */
function qibbs_get_user_notification_preferences( $user_id = 0 ) {
    $user_id = absint( $user_id );
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }

    $defaults = qibbs_notification_preference_defaults();
    if ( ! $user_id ) {
        return $defaults;
    }

    $saved = get_user_meta( $user_id, '_qibbs_notification_preferences', true );
    if ( ! is_array( $saved ) ) {
        return $defaults;
    }

    $normalized = array();
    foreach ( $defaults as $key => $default_value ) {
        $normalized[ $key ] = array_key_exists( $key, $saved ) ? (bool) $saved[ $key ] : (bool) $default_value;
    }

    return $normalized;
}

/**
 * 保存用户站内通知接收偏好
 *
 * @param int   $user_id      User ID.
 * @param array $preferences  Preference map.
 * @return bool
 */
function qibbs_update_user_notification_preferences( $user_id, $preferences = array() ) {
    $user_id = absint( $user_id );
    if ( ! $user_id ) {
        return false;
    }

    $defaults = qibbs_notification_preference_defaults();
    $preferences = is_array( $preferences ) ? $preferences : array();

    $sanitized = array();
    foreach ( $defaults as $key => $default_value ) {
        $sanitized[ $key ] = array_key_exists( $key, $preferences )
            ? (bool) $preferences[ $key ]
            : (bool) $default_value;
    }

    $current = get_user_meta( $user_id, '_qibbs_notification_preferences', true );
    if ( is_array( $current ) ) {
        $current_normalized = array();
        foreach ( $defaults as $key => $default_value ) {
            $current_normalized[ $key ] = array_key_exists( $key, $current )
                ? (bool) $current[ $key ]
                : (bool) $default_value;
        }
        if ( $current_normalized === $sanitized ) {
            return true;
        }
    }

    return false !== update_user_meta( $user_id, '_qibbs_notification_preferences', $sanitized );
}

/**
 * 校验用户是否允许接收指定类型的站内通知
 *
 * @param int    $user_id User ID.
 * @param string $type    Notification type.
 * @return bool
 */
function qibbs_can_send_notification_type( $user_id, $type ) {
    $user_id = absint( $user_id );
    $type = sanitize_key( (string) $type );

    if ( ! $user_id || '' === $type ) {
        return false;
    }

    if ( ! qibbs_get_option( 'enable_notifications', true ) ) {
        return false;
    }

    $prefs = qibbs_get_user_notification_preferences( $user_id );
    if ( ! array_key_exists( $type, $prefs ) ) {
        return true;
    }

    return (bool) $prefs[ $type ];
}

/**
 * 依据用户偏好过滤站内通知投递
 *
 * @param bool   $should_send   Existing permission.
 * @param string $type          Notification type.
 * @param int    $target_user_id Target user ID.
 * @param int    $actor_id      Actor user ID.
 * @return bool
 */
function qibbs_filter_notification_delivery_by_preferences( $should_send, $type, $target_user_id, $actor_id ) {
    if ( ! $should_send ) {
        return false;
    }

    return qibbs_can_send_notification_type( $target_user_id, $type );
}
add_filter( 'qibbs_should_send_notification', 'qibbs_filter_notification_delivery_by_preferences', 10, 4 );

/**
 * 构建固定窗口频率限流的计数器缓存键名
 *
 * @param string     $namespace  Counter namespace.
 * @param mixed      $subject    Counter subject.
 * @param int        $window     Window size in seconds.
 * @param int|null   $expires_at Receives the bucket expiry timestamp.
 * @return string
 */
function qibbs_build_scoped_rate_limit_counter_key( $namespace, $subject, $window, &$expires_at = null ) {
    $namespace = sanitize_key( (string) $namespace );
    $window = max( 1, absint( $window ) );
    $now = function_exists( 'qibbs_current_timestamp' ) ? qibbs_current_timestamp() : time();
    $window_start = (int) floor( $now / $window ) * $window;
    $expires_at = $window_start + $window;

    if ( is_scalar( $subject ) || null === $subject ) {
        $subject_token = (string) $subject;
    } else {
        $subject_token = wp_json_encode( $subject );
        if ( false === $subject_token ) {
            $subject_token = maybe_serialize( $subject );
        }
    }

    return 'qibbs_rlv2_' . str_pad( (string) $expires_at, 10, '0', STR_PAD_LEFT ) . '_' . md5( $namespace . '|' . $subject_token . '|' . $window_start . '|' . $window );
}

/**
 * 机会式清理 wp_options 中过期的限流瞬态计数器
 *
 * @return void
 */
function qibbs_maybe_cleanup_scoped_rate_limit_counters() {
    static $attempted = false;

    if ( $attempted ) {
        return;
    }

    $attempted = true;

    if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
        return;
    }

    if ( 1 !== wp_rand( 1, 250 ) ) {
        return;
    }

    global $wpdb;
    $now = function_exists( 'qibbs_current_timestamp' ) ? qibbs_current_timestamp() : time();

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE %s
               AND CAST(SUBSTRING(option_name, 12, 10) AS UNSIGNED) < %d",
            'qibbs_rlv2_%',
            $now
        )
    );
}

/**
 * 原子递增固定窗口限流计数器
 *
 * @param string $namespace Counter namespace.
 * @param mixed  $subject   Counter subject.
 * @param int    $window    Window size in seconds.
 * @return int|false
 */
function qibbs_increment_scoped_rate_limit_counter( $namespace, $subject, $window ) {
    $namespace = sanitize_key( (string) $namespace );
    $window = max( 1, absint( $window ) );

    if ( '' === $namespace ) {
        return false;
    }

    $counter_key = qibbs_build_scoped_rate_limit_counter_key( $namespace, $subject, $window, $expires_at );

    if (
        function_exists( 'wp_using_ext_object_cache' ) &&
        wp_using_ext_object_cache() &&
        function_exists( 'wp_cache_add' ) &&
        function_exists( 'wp_cache_incr' ) &&
        ( ! function_exists( 'wp_cache_supports' ) || wp_cache_supports( 'incr' ) )
    ) {
        wp_cache_add( $counter_key, 0, 'qibbs_rate_limit', $window );
        $count = wp_cache_incr( $counter_key, 1, 'qibbs_rate_limit' );
        if ( false !== $count ) {
            return (int) $count;
        }
    }

    qibbs_maybe_cleanup_scoped_rate_limit_counters();

    global $wpdb;

    $inserted = $wpdb->query(
        $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES ( %s, %s, 'no' )",
            $counter_key,
            '0'
        )
    );
    if ( false === $inserted ) {
        return false;
    }

    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1)
             WHERE option_name = %s",
            $counter_key
        )
    );
    if ( false === $updated ) {
        return false;
    }

    $count = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
    if ( $count > 0 ) {
        return $count;
    }

    $fallback_count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $counter_key
        )
    );

    return null !== $fallback_count ? (int) $fallback_count : false;
}

/**
 * 消耗单次固定时间窗口的限流配额
 *
 * @param string $namespace Counter namespace.
 * @param mixed  $subject   Counter subject.
 * @param int    $limit     Maximum requests in the current window.
 * @param int    $window    Window size in seconds.
 * @return bool
 */
function qibbs_consume_scoped_rate_limit( $namespace, $subject, $limit = 60, $window = 60 ) {
    $namespace = sanitize_key( (string) $namespace );
    $limit = absint( $limit );
    $window = max( 1, absint( $window ) );

    if ( '' === $namespace || $limit <= 0 ) {
        return true;
    }

    $count = qibbs_increment_scoped_rate_limit_counter( $namespace, $subject, $window );
    if ( false !== $count ) {
        return (int) $count <= $limit;
    }

    $fallback_key = 'qibbs_rlfb_' . md5( $namespace . '|' . maybe_serialize( $subject ) . '|' . floor( ( function_exists( 'qibbs_current_timestamp' ) ? qibbs_current_timestamp() : time() ) / $window ) . '|' . $window );
    $fallback_count = (int) get_transient( $fallback_key );
    if ( $fallback_count >= $limit ) {
        return false;
    }

    set_transient( $fallback_key, $fallback_count + 1, $window );
    return true;
}

/**
 * 基于 IP 的公共接口轻量级防刷限流器
 *
 * @param string $action Action key.
 * @param int    $limit  Max requests in window.
 * @param int    $window Window seconds.
 * @return bool
 */
function qibbs_rate_limit_by_ip( $action, $limit = 60, $window = 60 ) {
    if ( class_exists( 'Qibbs_Risk_Service' ) ) {
        return Qibbs_Risk_Service::enforce_rate_limit( $action, $limit, $window );
    }

    $action = sanitize_key( $action );
    $limit = max( 1, absint( $limit ) );
    $window = max( 1, absint( $window ) );

    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
    $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
    $fingerprint = md5( $ip . '|' . substr( $ua, 0, 120 ) );

    return qibbs_consume_scoped_rate_limit( 'ip_' . $action, $fingerprint, $limit, $window );
}

/**
 * 发帖内容多层次安全过滤与 XSS/SQL 注入防御
 * 强化跨站脚本（XSS）与 SQL 注入双重防御
 *
 * @param string $content Raw content.
 * @return string Sanitized content.
 */
function qibbs_sanitize_content( $content ) {
    // 彻底移除 script 和 style 标签
    $content = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $content );
    $content = preg_replace( '/<style\b[^>]*>(.*?)<\/style>/is', '', $content );
    
    // 移除所有 HTML 行内事件处理器（如 onclick、onload、onerror 等）
    $content = preg_replace( '/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $content );
    $content = preg_replace( '/\s*on\w+\s*=\s*[^\s>]+/i', '', $content );
    
    // 移除 javascript: 和 data: 危险协议链接
    $content = preg_replace( '/href\s*=\s*["\']?\s*javascript:[^"\'>\s]*/i', '', $content );
    $content = preg_replace( '/src\s*=\s*["\']?\s*(javascript:|data:text\/html)[^"\'>\s]*/i', '', $content );
    
    // 移除任何 PHP 脚本标签
    $content = preg_replace( '/<\?php.*?\?>/is', '', $content );
    $content = preg_replace( '/<\?=.*?\?>/is', '', $content );
    
    // 结合 wp_kses_post 允许安全 HTML，并执行严格防跨站转义
    $content = wp_kses_post( $content );
    
    // 标准化多余空白符与换行
    $content = preg_replace( '/[\r\n]+/', "\n", $content );
    $content = trim( $content );
    
    return $content;
}

/**
 * 获取站点配置的时区对象（兼容低版本环境）
 *
 * @return DateTimeZone
 */
function qibbs_wp_timezone() {
    if ( function_exists( 'wp_timezone' ) ) {
        return wp_timezone();
    }

    $timezone_string = (string) get_option( 'timezone_string' );
    if ( '' !== $timezone_string ) {
        try {
            return new DateTimeZone( $timezone_string );
        } catch ( Exception $e ) {
            // 降级使用 GMT 偏移量计算
        }
    }

    $offset = (float) get_option( 'gmt_offset', 0 );
    $sign = $offset < 0 ? '-' : '+';
    $abs_offset = abs( $offset );
    $hours = (int) floor( $abs_offset );
    $minutes = (int) round( ( $abs_offset - $hours ) * 60 );

    try {
        return new DateTimeZone( sprintf( '%s%02d:%02d', $sign, $hours, $minutes ) );
    } catch ( Exception $e ) {
        return new DateTimeZone( 'UTC' );
    }
}

/**
 * 按照站点时区格式化 Unix 时间戳
 *
 * @param string $format    PHP date format.
 * @param int    $timestamp Unix timestamp.
 * @return string
 */
function qibbs_format_datetime( $format, $timestamp ) {
    $timestamp = (int) $timestamp;
    if ( $timestamp <= 0 ) {
        return '';
    }

    $timezone = qibbs_wp_timezone();

    if ( function_exists( 'wp_date' ) ) {
        return wp_date( $format, $timestamp, $timezone );
    }

    try {
        return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone )->format( $format );
    } catch ( Exception $e ) {
        return date( $format, $timestamp );
    }
}

/**
 * 获取站点时区上下文中的当前 Unix 时间戳
 *
 * @return int
 */
function qibbs_current_timestamp() {
    try {
        return ( new DateTimeImmutable( 'now', qibbs_wp_timezone() ) )->getTimestamp();
    } catch ( Exception $e ) {
        return time();
    }
}

/**
 * 将数据库存储的时间字符串按照站点时区转换为 Unix 时间戳
 *
 * @param string|int|DateTimeInterface $datetime Datetime value.
 * @return int
 */
function qibbs_datetime_to_timestamp( $datetime ) {
    if ( $datetime instanceof DateTimeInterface ) {
        return (int) $datetime->getTimestamp();
    }

    if ( is_numeric( $datetime ) ) {
        return (int) $datetime;
    }

    $datetime = trim( (string) $datetime );
    if ( '' === $datetime ) {
        return 0;
    }

    $timezone = qibbs_wp_timezone();
    $formats = array( 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' );

    foreach ( $formats as $format ) {
        $parsed = DateTimeImmutable::createFromFormat( $format, $datetime, $timezone );
        $errors = DateTimeImmutable::getLastErrors();
        $has_errors = is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) );

        if ( $parsed instanceof DateTimeImmutable && ! $has_errors ) {
            return (int) $parsed->getTimestamp();
        }
    }

    try {
        return (int) ( new DateTimeImmutable( $datetime, $timezone ) )->getTimestamp();
    } catch ( Exception $e ) {
        $fallback = strtotime( $datetime );
        return $fallback ? (int) $fallback : 0;
    }
}

/**
 * 计算人类可读的相对时间差（如“刚刚”、“5分钟前”）
 *
 * @param string $datetime MySQL datetime string.
 * @return string Human readable time.
 */
function qibbs_human_time_diff( $datetime ) {
    // 处理空时间输入
    if ( empty( $datetime ) ) {
        return __( '刚刚', 'qibbs-community' );
    }
    
    // 将时间字符串按站点时区解析为时间戳
    $timestamp = qibbs_datetime_to_timestamp( $datetime );
    
    // 处理无效时间戳输入
    if ( $timestamp === false || $timestamp <= 0 ) {
        return __( '刚刚', 'qibbs-community' );
    }
    
    // 使用站点时区下的当前时间戳计算时间差
    $current = qibbs_current_timestamp();
    $diff = $current - $timestamp;

    // If diff is negative (future time) or very small, show "刚刚"
    if ( $diff < 60 ) {
        return __( '刚刚', 'qibbs-community' );
    } elseif ( $diff < 3600 ) {
        $minutes = floor( $diff / 60 );
        return sprintf( __( '%d 分钟前', 'qibbs-community' ), $minutes );
    } elseif ( $diff < 86400 ) {
        $hours = floor( $diff / 3600 );
        return sprintf( __( '%d 小时前', 'qibbs-community' ), $hours );
    } elseif ( $diff < 604800 ) {
        $days = floor( $diff / 86400 );
        return sprintf( __( '%d 天前', 'qibbs-community' ), $days );
    } else {
        return qibbs_format_datetime( get_option( 'date_format' ), $timestamp );
    }
}

/**
 * 格式化输出精确日期时间字符串（支持自定义格式，默认年月日时分秒）
 *
 * @param string|int|DateTimeInterface $datetime Datetime string or timestamp.
 * @param string                       $format   PHP date format. Default 'Y-m-d H:i:s'.
 * @return string Formatted datetime string.
 */
function qibbs_format_exact_datetime( $datetime, $format = 'Y-m-d H:i:s' ) {
    if ( empty( $datetime ) ) {
        return '';
    }

    $timestamp = qibbs_datetime_to_timestamp( $datetime );
    if ( $timestamp <= 0 ) {
        return '';
    }

    return qibbs_format_datetime( $format, $timestamp );
}

/**
 * 获取带有数据库前缀的微社区数据表名
 *
 * @param string $table Table name without prefix.
 * @return string Full table name with WordPress prefix.
 */
function qibbs_get_table_name( $table ) {
    global $wpdb;
    return $wpdb->prefix . 'qibbs_' . $table;
}
