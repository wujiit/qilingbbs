<?php
/**
 * 精华推荐帖子服务类
 *
 * 处理推荐动态的设置、取消、查询和过期清理
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Featured_Service {

    /**
     * 设置动态为推荐
     *
     * @param int $post_id 动态ID
     * @param int $days 推荐天数（0表示永久）
     * @return bool
     */
    public function feature_post( $post_id, $days = 7 ) {
        global $wpdb;
        $table = Qibbs_Database::posts();
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return false;
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, author_id, status, is_featured FROM {$table} WHERE id = %d LIMIT 1",
                $post_id
            )
        );
        if ( ! $existing ) {
            return false;
        }
        
        $featured_until = null;
        if ( $days > 0 ) {
            $expiry_dt = current_datetime();
            $expiry_dt = $expiry_dt->modify( '+' . absint( $days ) . ' days' );
            $featured_until = $expiry_dt ? $expiry_dt->format( 'Y-m-d H:i:s' ) : null;
        }
        
        $result = $wpdb->update(
            $table,
            array(
                'is_featured'    => 1,
                'featured_until' => $featured_until,
            ),
            array( 'id' => $post_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        if ( false !== $result ) {
            do_action( 'qibbs_post_edited', $post_id, (int) $existing->author_id );
        }

        if ( false !== $result && empty( $existing->is_featured ) && 'publish' === (string) $existing->status ) {
            do_action( 'qibbs_post_featured', $post_id, (int) $existing->author_id );
        }

        return $result !== false;
    }

    /**
     * 取消动态推荐
     *
     * @param int $post_id 动态ID
     * @return bool
     */
    public function unfeature_post( $post_id ) {
        global $wpdb;
        $table = Qibbs_Database::posts();
        $post_id = absint( $post_id );

        if ( ! $post_id ) {
            return false;
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, author_id FROM {$table} WHERE id = %d LIMIT 1",
                $post_id
            )
        );
        if ( ! $existing ) {
            return false;
        }
        
        $result = $wpdb->update(
            $table,
            array(
                'is_featured'    => 0,
                'featured_until' => null,
            ),
            array( 'id' => $post_id ),
            array( '%d', null ),
            array( '%d' )
        );

        if ( false !== $result ) {
            do_action( 'qibbs_post_edited', $post_id, (int) $existing->author_id );
        }
        
        return $result !== false;
    }

    /**
     * 检查动态是否正在推荐中
     *
     * @param int $post_id 动态ID
     * @return bool
     */
    public function is_featured( $post_id ) {
        global $wpdb;
        $table = Qibbs_Database::posts();
        
        $post = $wpdb->get_row( $wpdb->prepare(
            "SELECT is_featured, featured_until FROM {$table} WHERE id = %d",
            $post_id
        ) );
        
        if ( ! $post || ! $post->is_featured ) {
            return false;
        }
        
        // 检查是否过期
        $now_mysql = current_time( 'mysql' );
        if ( $post->featured_until && $post->featured_until < $now_mysql ) {
            // 已过期，自动取消推荐
            $this->unfeature_post( $post_id );
            return false;
        }
        
        return true;
    }

    /**
     * 获取推荐动态列表
     *
     * @param int $page 页码
     * @param int $per_page 每页数量
     * @return array
     */
    public function get_featured_posts( $page = 1, $per_page = 20 ) {
        global $wpdb;
        $table = Qibbs_Database::posts();
        
        // 先清理过期的推荐
        $this->maybe_clear_expired_features();
        
        $offset = ( $page - 1 ) * $per_page;
        $now_mysql = current_time( 'mysql' );
        
        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} 
            WHERE is_featured = 1 AND status = 'publish'
            AND (featured_until IS NULL OR featured_until > %s)
            ORDER BY featured_until IS NULL DESC, featured_until DESC, created_at DESC
            LIMIT %d OFFSET %d",
            $now_mysql,
            $per_page,
            $offset
        ) );
        
        return $posts ? $posts : array();
    }

    /**
     * 获取推荐动态总数
     *
     * @return int
     */
    public function get_featured_count() {
        global $wpdb;
        $table = Qibbs_Database::posts();
        
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE is_featured = 1 AND status = 'publish'
                 AND (featured_until IS NULL OR featured_until > %s)",
                current_time( 'mysql' )
            )
        );
    }

    /**
     * 清理过期的推荐动态
     *
     * @return int 清理的数量
     */
    public function clear_expired_features() {
        global $wpdb;
        $table = Qibbs_Database::posts();
        
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET is_featured = 0, featured_until = NULL
                 WHERE is_featured = 1 AND featured_until IS NOT NULL AND featured_until < %s",
                current_time( 'mysql' )
            )
        );
        
        return $result ? $result : 0;
    }

    /**
     * 节流执行过期清理，避免每次请求都触发写入。
     *
     * @return int 清理的数量
     */
    public function maybe_clear_expired_features() {
        $lock_key = 'qibbs_featured_expire_cleanup_lock';
        if ( get_transient( $lock_key ) ) {
            return 0;
        }

        set_transient( $lock_key, 1, MINUTE_IN_SECONDS );
        return $this->clear_expired_features();
    }

    /**
     * 定时任务：清理过期推荐（可挂载到 wp_cron）
     */
    public static function scheduled_cleanup() {
        $service = new self();
        $service->clear_expired_features();
    }
}
