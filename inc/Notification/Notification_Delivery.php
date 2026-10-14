<?php
/**
 * 站内通知高性能分发层
 *
 * 集中管理通知异步入库、批量投递与未读红点缓存
 *
 * @package Qibbs_Community
 * @subpackage Notification
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Notification_Delivery {

    /**
     * 未读计数缓存有效期（秒）
     *
     * @var int
     */
    const UNREAD_CACHE_TTL = 120;

    /**
     * WP-Cron 异步批量通知投递钩子
     *
     * @var string
     */
    const BATCH_CRON_HOOK = 'qibbs_notification_batch_event';

    /**
     * 检查持久化对象缓存（Redis/Memcached）是否可用
     *
     * @return bool
     */
    private static function use_object_cache() {
        return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
    }

    /**
     * 构建对象缓存分组标识
     *
     * @param string $bucket Cache bucket.
     * @return string
     */
    private static function get_cache_group( $bucket ) {
        return 'qibbs_notification_' . sanitize_key( (string) $bucket );
    }

    /**
     * 从对象缓存或 Transient 读取缓存数据
     *
     * @param string $key    Cache key.
     * @param string $bucket Cache bucket.
     * @param bool   $found  Whether cache exists.
     * @return mixed
     */
    private static function cache_get( $key, $bucket, &$found = false ) {
        if ( self::use_object_cache() ) {
            return wp_cache_get( $key, self::get_cache_group( $bucket ), false, $found );
        }

        $value = get_transient( $key );
        $found = ( false !== $value );
        return $value;
    }

    /**
     * 写入对象缓存或 Transient 存储
     *
     * @param string $key    Cache key.
     * @param mixed  $value  Cache value.
     * @param int    $ttl    Cache TTL.
     * @param string $bucket Cache bucket.
     * @return void
     */
    private static function cache_set( $key, $value, $ttl, $bucket ) {
        $ttl = max( 1, absint( $ttl ) );

        if ( self::use_object_cache() ) {
            wp_cache_set( $key, $value, self::get_cache_group( $bucket ), $ttl );
            return;
        }

        set_transient( $key, $value, $ttl );
    }

    /**
     * 从对象缓存或 Transient 中删除指定缓存
     *
     * @param string $key    Cache key.
     * @param string $bucket Cache bucket.
     * @return void
     */
    private static function cache_delete( $key, $bucket ) {
        if ( self::use_object_cache() ) {
            wp_cache_delete( $key, self::get_cache_group( $bucket ) );
            return;
        }

        delete_transient( $key );
    }

    /**
     * 将批量通知推入异步处理队列
     *
     * @param array  $rows       Notification rows.
     * @param int    $chunk_size Batch size.
     * @param string $context    Queue context label.
     * @param int    $delay      Dispatch delay in seconds.
     * @return int Number of normalized rows queued.
     */
    public static function queue_batch( $rows, $chunk_size = 100, $context = 'default', $delay = 5 ) {
        $rows = is_array( $rows ) ? $rows : array();
        if ( empty( $rows ) ) {
            return 0;
        }

        $normalized_rows = array();
        foreach ( $rows as $row ) {
            $normalized = self::normalize_row( $row );
            if ( ! empty( $normalized ) ) {
                $normalized_rows[] = $normalized;
            }
        }

        if ( empty( $normalized_rows ) ) {
            return 0;
        }

        $chunk_size = max( 1, absint( $chunk_size ) );
        $context = sanitize_key( (string) $context );
        $delay = max(
            1,
            absint(
                apply_filters(
                    'qibbs_notification_batch_async_delay',
                    $delay,
                    $context,
                    count( $normalized_rows )
                )
            )
        );

        $batch_id = self::build_queue_batch_id( $normalized_rows, $chunk_size, $context );
        $storage_key = self::get_queue_batch_storage_key( $batch_id );
        $payload = array(
            'rows'       => $normalized_rows,
            'chunk_size' => $chunk_size,
        );

        self::cache_set( $storage_key, $payload, self::get_async_batch_ttl(), 'batch' );

        $args = array( $batch_id );
        if ( ! wp_next_scheduled( self::BATCH_CRON_HOOK, $args ) ) {
            wp_schedule_single_event( time() + $delay, self::BATCH_CRON_HOOK, $args );
        }

        return count( $normalized_rows );
    }

    /**
     * 处理队列中的批量通知任务
     *
     * @param string $batch_id Queue batch ID.
     * @return void
     */
    public function process_queued_batch( $batch_id ) {
        $batch_id = preg_replace( '/[^a-f0-9]/', '', (string) $batch_id );
        if ( '' === $batch_id ) {
            return;
        }

        $storage_key = self::get_queue_batch_storage_key( $batch_id );
        $payload = self::cache_get( $storage_key, 'batch', $found );
        if ( ! $found ) {
            $payload = false;
        }
        if ( ! is_array( $payload ) || empty( $payload['rows'] ) ) {
            return;
        }

        self::insert_batch(
            $payload['rows'],
            isset( $payload['chunk_size'] ) ? absint( $payload['chunk_size'] ) : 100
        );

        self::cache_delete( $storage_key, 'batch' );
    }

    /**
     * 单条通知写入数据库
     *
     * @param array $data Notification row data.
     * @return int|false Insert ID on success, false on failure.
     */
    public static function insert( $data ) {
        global $wpdb;

        $row = self::normalize_row( $data );
        if ( empty( $row ) ) {
            return false;
        }

        $table = Qibbs_Database::notifications();
        $result = $wpdb->insert(
            $table,
            $row,
            array( '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s' )
        );

        if ( false === $result ) {
            if ( self::is_duplicate_key_error( $wpdb->last_error ) && ! empty( $row['dedupe_key'] ) ) {
                $existing_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$table} WHERE dedupe_key = %s LIMIT 1",
                        $row['dedupe_key']
                    )
                );
                if ( $existing_id > 0 ) {
                    return $existing_id;
                }
            }
            return false;
        }

        self::bump_unread_cache_on_insert( (int) $row['user_id'], (string) $row['type'], (int) $row['is_read'] );

        return (int) $wpdb->insert_id;
    }

    /**
     * 批量高效写入通知记录
     *
     * @param array $rows Notification rows.
     * @param int   $chunk_size Batch size.
     * @return int Number of inserted rows.
     */
    public static function insert_batch( $rows, $chunk_size = 100 ) {
        global $wpdb;

        $rows = is_array( $rows ) ? $rows : array();
        if ( empty( $rows ) ) {
            return 0;
        }

        $normalized_rows = array();
        foreach ( $rows as $row ) {
            $normalized = self::normalize_row( $row );
            if ( ! empty( $normalized ) ) {
                $normalized_rows[] = $normalized;
            }
        }

        if ( empty( $normalized_rows ) ) {
            return 0;
        }

        $chunk_size = max( 1, absint( $chunk_size ) );
        $table = Qibbs_Database::notifications();
        $inserted = 0;
        $affected_user_ids = array();

        $field_sql = '(user_id, type, actor_id, object_type, object_id, content, is_read, created_at, dedupe_key)';
        $value_sql_with_dedupe = '(%d,%s,%d,%s,%d,%s,%d,%s,%s)';
        $value_sql_with_null_dedupe = '(%d,%s,%d,%s,%d,%s,%d,%s,NULL)';

        foreach ( array_chunk( $normalized_rows, $chunk_size ) as $chunk ) {
            $placeholders = array();
            $values = array();

            foreach ( $chunk as $row ) {
                $has_dedupe_key = ! empty( $row['dedupe_key'] );
                $placeholders[] = $has_dedupe_key ? $value_sql_with_dedupe : $value_sql_with_null_dedupe;
                $values[] = (int) $row['user_id'];
                $values[] = (string) $row['type'];
                $values[] = (int) $row['actor_id'];
                $values[] = (string) $row['object_type'];
                $values[] = (int) $row['object_id'];
                $values[] = (string) $row['content'];
                $values[] = (int) $row['is_read'];
                $values[] = (string) $row['created_at'];
                if ( $has_dedupe_key ) {
                    $values[] = (string) $row['dedupe_key'];
                }

                if ( 0 === (int) $row['is_read'] ) {
                    $uid = (int) $row['user_id'];
                    $affected_user_ids[ $uid ] = true;
                }
            }

            $sql = "INSERT IGNORE INTO {$table} {$field_sql} VALUES " . implode( ',', $placeholders );
            $prepared = $wpdb->prepare( $sql, $values );
            if ( false === $prepared ) {
                continue;
            }

            $result = $wpdb->query( $prepared );
            if ( false !== $result ) {
                $inserted += (int) $result;
            }
        }

        if ( ! empty( $affected_user_ids ) ) {
            self::invalidate_unread_cache_for_users( array_keys( $affected_user_ids ) );
        }

        return $inserted;
    }

    /**
     * 查询用户未读通知总数
     *
     * @param int  $user_id User ID.
     * @param bool $exclude_message Whether to exclude `message` type.
     * @return int
     */
    public static function get_unread_count( $user_id, $exclude_message = true ) {
        global $wpdb;

        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return 0;
        }

        $cache_key = self::get_unread_cache_key( $user_id, $exclude_message );
        $cached = self::cache_get( $cache_key, 'unread', $found );
        if ( $found ) {
            return max( 0, (int) $cached );
        }

        $table = Qibbs_Database::notifications();
        if ( $exclude_message ) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0 AND type != 'message'",
                $user_id
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_read = 0",
                $user_id
            );
        }

        $count = (int) $wpdb->get_var( $sql );
        self::cache_set( $cache_key, $count, self::get_unread_cache_ttl(), 'unread' );
        return $count;
    }

    /**
     * 清除单用户的未读通知缓存
     *
     * @param int $user_id User ID.
     * @return void
     */
    public static function invalidate_unread_cache( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return;
        }

        self::cache_delete( self::get_unread_cache_key( $user_id, true ), 'unread' );
        self::cache_delete( self::get_unread_cache_key( $user_id, false ), 'unread' );
    }

    /**
     * 批量清除多用户的未读通知缓存
     *
     * @param array $user_ids User IDs.
     * @return void
     */
    public static function invalidate_unread_cache_for_users( $user_ids ) {
        $user_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) );
        foreach ( $user_ids as $user_id ) {
            self::invalidate_unread_cache( $user_id );
        }
    }

    /**
     * 格式化通知数据载体
     *
     * @param array $row Raw row.
     * @return array
     */
    private static function normalize_row( $row ) {
        $row = is_array( $row ) ? $row : array();

        $user_id = absint( $row['user_id'] ?? 0 );
        $type = sanitize_key( (string) ( $row['type'] ?? '' ) );
        $actor_id = absint( $row['actor_id'] ?? 0 );
        $object_type = sanitize_key( (string) ( $row['object_type'] ?? 'post' ) );
        $object_id = absint( $row['object_id'] ?? 0 );
        $content = isset( $row['content'] ) ? wp_strip_all_tags( (string) $row['content'] ) : '';
        $is_read = isset( $row['is_read'] ) ? absint( $row['is_read'] ) : 0;
        $created_at = isset( $row['created_at'] ) ? (string) $row['created_at'] : current_time( 'mysql' );
        $dedupe_key_raw = isset( $row['dedupe_key'] ) ? trim( sanitize_text_field( (string) $row['dedupe_key'] ) ) : '';
        $dedupe_key = '' !== $dedupe_key_raw ? hash( 'sha256', $dedupe_key_raw ) : null;

        if ( ! $user_id || '' === $type ) {
            return array();
        }

        return array(
            'user_id'     => $user_id,
            'type'        => $type,
            'actor_id'    => $actor_id,
            'object_type' => '' !== $object_type ? $object_type : 'post',
            'object_id'   => $object_id,
            'content'     => $content,
            'is_read'     => $is_read ? 1 : 0,
            'created_at'  => $created_at,
            'dedupe_key'  => $dedupe_key,
        );
    }

    /**
     * 检查 SQL 异常是否为唯一键冲突
     *
     * @param string $error SQL error message.
     * @return bool
     */
    private static function is_duplicate_key_error( $error ) {
        if ( ! is_string( $error ) || '' === $error ) {
            return false;
        }

        return false !== stripos( $error, 'Duplicate entry' );
    }

    /**
     * 构建未读消息数缓存键名
     *
     * @param int  $user_id User ID.
     * @param bool $exclude_message Exclude message type.
     * @return string
     */
    private static function get_unread_cache_key( $user_id, $exclude_message ) {
        return 'qibbs_notice_unread_' . (int) $user_id . '_' . ( $exclude_message ? 'nm' : 'all' );
    }

    /**
     * 构建确定性异步批次 ID
     *
     * @param array  $rows       Normalized notification rows.
     * @param int    $chunk_size Batch size.
     * @param string $context    Queue context.
     * @return string
     */
    private static function build_queue_batch_id( $rows, $chunk_size, $context ) {
        $signature_rows = array();

        foreach ( (array) $rows as $row ) {
            $signature_rows[] = array(
                'user_id'     => (int) ( $row['user_id'] ?? 0 ),
                'type'        => (string) ( $row['type'] ?? '' ),
                'actor_id'    => (int) ( $row['actor_id'] ?? 0 ),
                'object_type' => (string) ( $row['object_type'] ?? '' ),
                'object_id'   => (int) ( $row['object_id'] ?? 0 ),
                'dedupe_key'  => (string) ( $row['dedupe_key'] ?? '' ),
                'content'     => (string) ( $row['content'] ?? '' ),
            );
        }

        return md5(
            wp_json_encode(
                array(
                    'context'    => sanitize_key( (string) $context ),
                    'chunk_size' => absint( $chunk_size ),
                    'rows'       => $signature_rows,
                )
            )
        );
    }

    /**
     * 构建队列批次载荷的 Transient 键名
     *
     * @param string $batch_id Queue batch ID.
     * @return string
     */
    private static function get_queue_batch_storage_key( $batch_id ) {
        return 'qibbs_notice_batch_' . preg_replace( '/[^a-f0-9]/', '', (string) $batch_id );
    }

    /**
     * 队列批次载荷存储超时时间
     *
     * @return int
     */
    private static function get_async_batch_ttl() {
        $ttl = (int) apply_filters( 'qibbs_notification_batch_async_ttl', 15 * MINUTE_IN_SECONDS );
        return max( 5 * MINUTE_IN_SECONDS, $ttl );
    }

    /**
     * 新增通知时同步增加未读计数缓存
     *
     * @param int    $user_id User ID.
     * @param string $type Notification type.
     * @param int    $is_read Read status.
     * @return void
     */
    private static function bump_unread_cache_on_insert( $user_id, $type, $is_read ) {
        if ( $is_read ) {
            return;
        }

        if ( 'message' !== $type ) {
            self::adjust_unread_cache_value( $user_id, 1, true );
        }
        self::adjust_unread_cache_value( $user_id, 1, false );
    }

    /**
     * 增量调整未读计数缓存
     *
     * @param int  $user_id User ID.
     * @param int  $delta Delta.
     * @param bool $exclude_message Exclude message type.
     * @return void
     */
    private static function adjust_unread_cache_value( $user_id, $delta, $exclude_message ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return;
        }

        $cache_key = self::get_unread_cache_key( $user_id, $exclude_message );
        $cached = self::cache_get( $cache_key, 'unread', $found );
        if ( ! $found ) {
            return;
        }

        $value = max( 0, (int) $cached + (int) $delta );
        self::cache_set( $cache_key, $value, self::get_unread_cache_ttl(), 'unread' );
    }

    /**
     * 强制设置未读计数缓存值
     *
     * @param int  $user_id          User ID.
     * @param int  $value            Unread count.
     * @param bool $exclude_message  Exclude message type.
     * @return void
     */
    public static function set_unread_cache_value( $user_id, $value, $exclude_message = true ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return;
        }

        self::cache_set(
            self::get_unread_cache_key( $user_id, $exclude_message ),
            max( 0, (int) $value ),
            self::get_unread_cache_ttl(),
            'unread'
        );
    }

    /**
     * 递减未读计数缓存
     *
     * @param int  $user_id         User ID.
     * @param int  $delta           Delta to subtract.
     * @param bool $exclude_message Exclude message type.
     * @return void
     */
    public static function decrement_unread_cache( $user_id, $delta = 1, $exclude_message = true ) {
        self::adjust_unread_cache_value( $user_id, - absint( $delta ), $exclude_message );
    }

    /**
     * 获取未读计数缓存有效期
     *
     * @return int
     */
    private static function get_unread_cache_ttl() {
        $ttl = (int) apply_filters( 'qibbs_notification_unread_cache_ttl', self::UNREAD_CACHE_TTL );
        return max( 15, $ttl );
    }
}
