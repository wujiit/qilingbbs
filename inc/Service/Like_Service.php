<?php
/**
 * 点赞互动服务类
 *
 * 处理帖子点赞、取消赞、点赞列表与计数同步
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 点赞服务核心类
 *
 * 管理社区点赞互动
 */
class Qibbs_Like_Service {

    /**
     * 切换帖子点赞/取消赞状态
     *
     * @param int $user_id User ID.
     * @param int $post_id Post ID.
     * @return array Result with success status and new like count.
     */
    public function toggle_like( $user_id, $post_id ) {
        if ( ! qibbs_is_module_enabled( 'likes' ) ) {
            return array(
                'success' => false,
                'message' => qibbs_get_module_disabled_message( 'likes' ),
            );
        }

        if ( ! $user_id || ! $post_id ) {
            return array(
                'success' => false,
                'message' => __( '参数错误', 'qibbs-community' ),
            );
        }

        global $wpdb;
        $table = Qibbs_Database::likes();
        $posts_table = Qibbs_Database::posts();

        // 检查是否已点赞
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d AND post_id = %d",
                $user_id,
                $post_id
            )
        );

        if ( $existing ) {
            // 取消点赞处理
            $deleted = $wpdb->delete(
                $table,
                array(
                    'user_id' => $user_id,
                    'post_id' => $post_id,
                ),
                array( '%d', '%d' )
            );

            if ( false === $deleted ) {
                return array(
                    'success' => false,
                    'message' => __( '取消点赞失败，请稍后重试', 'qibbs-community' ),
                );
            }

            if ( $deleted > 0 ) {
                // 仅在成功删除点赞关联时才递减计数
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$posts_table} SET like_count = GREATEST(like_count - 1, 0) WHERE id = %d",
                        $post_id
                    )
                );
            }

            $liked = false;
        } else {
            // 新增点赞处理
            $inserted = $wpdb->insert(
                $table,
                array(
                    'user_id'    => $user_id,
                    'post_id'    => $post_id,
                    'created_at' => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%s' )
            );

            if ( false === $inserted ) {
                if ( false === stripos( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
                    return array(
                        'success' => false,
                        'message' => __( '点赞失败，请稍后重试', 'qibbs-community' ),
                    );
                }
            } else {
                // 同步更新帖子 like_count 计数
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$posts_table} SET like_count = like_count + 1 WHERE id = %d",
                        $post_id
                    )
                );

                // 创建点赞站内通知
                $this->create_like_notification( $user_id, $post_id );
            }

            $liked = true;
        }

        // 获取更新后的点赞总数
        $like_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT like_count FROM {$posts_table} WHERE id = %d",
                $post_id
            )
        );

        /**
         * 点赞状态切换完成动作钩子
         *
         * @since 1.0.0
         * @param int  $user_id User ID.
         * @param int  $post_id Post ID.
         * @param bool $liked   Whether the post was liked or unliked.
         */
        do_action( 'qibbs_like_toggled', $user_id, $post_id, $liked );

        return array(
            'success'    => true,
            'liked'      => $liked,
            'like_count' => (int) $like_count,
        );
    }

    /**
     * 检查单用户是否已点赞帖子
     *
     * @param int $user_id User ID.
     * @param int $post_id Post ID.
     * @return bool
     */
    public function has_liked( $user_id, $post_id ) {
        global $wpdb;
        $table = Qibbs_Database::likes();

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d AND post_id = %d",
                $user_id,
                $post_id
            )
        );
    }

    /**
     * 查询点赞了指定帖子的用户列表
     *
     * @param int $post_id Post ID.
     * @param int $limit   Limit.
     * @param int $offset  Offset.
     * @return array
     */
    public function get_post_likes( $post_id, $limit = 20, $offset = 0 ) {
        global $wpdb;
        $table = Qibbs_Database::likes();

        $likes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, created_at FROM {$table} 
                 WHERE post_id = %d 
                 ORDER BY created_at DESC 
                 LIMIT %d OFFSET %d",
                $post_id,
                $limit,
                $offset
            )
        );

        // 装配用户头像与昵称数据
        foreach ( $likes as &$like ) {
            $user = get_userdata( $like->user_id );
            $like->display_name = $user ? $user->display_name : __( '未知用户', 'qibbs-community' );
            $like->avatar = get_avatar_url( $like->user_id, array( 'size' => 48 ) );
        }

        return $likes;
    }

    /**
     * 查询指定用户点赞过的帖子列表
     *
     * @param int $user_id  User ID.
     * @param int $limit    Limit.
     * @param int $offset   Offset.
     * @return array
     */
    public function get_user_likes( $user_id, $limit = 20, $offset = 0 ) {
        global $wpdb;
        $likes_table = Qibbs_Database::likes();
        $posts_table = Qibbs_Database::posts();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.* FROM {$posts_table} p
                 INNER JOIN {$likes_table} l ON p.id = l.post_id
                 WHERE l.user_id = %d AND p.status = 'publish'
                 ORDER BY l.created_at DESC
                 LIMIT %d OFFSET %d",
                $user_id,
                $limit,
                $offset
            )
        );
    }

    /**
     * 创建点赞互动通知
     *
     * @param int $user_id User ID (who liked).
     * @param int $post_id Post ID.
     */
    private function create_like_notification( $user_id, $post_id ) {
        global $wpdb;
        
        $posts_table = Qibbs_Database::posts();

        // 读取帖子作者 ID
        $post = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT author_id FROM {$posts_table} WHERE id = %d",
                $post_id
            )
        );

        // 自己对自己的操作不发送通知
        if ( ! $post || $post->author_id == $user_id ) {
            return;
        }

        if ( function_exists( 'qibbs_can_send_notification_type' ) && ! qibbs_can_send_notification_type( (int) $post->author_id, 'like' ) ) {
            return;
        }

        $notifications_table = Qibbs_Database::notifications();
        $aggregate_window = (int) apply_filters( 'qibbs_notification_interaction_aggregate_window', 300 );
        $aggregate_window = max( 0, $aggregate_window );

        if ( $aggregate_window > 0 ) {
            $cutoff = qibbs_format_datetime( 'Y-m-d H:i:s', qibbs_current_timestamp() - $aggregate_window );
            $aggregated_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM {$notifications_table}
                     WHERE user_id = %d
                       AND type = %s
                       AND object_type = %s
                       AND object_id = %d
                       AND is_read = 0
                       AND created_at >= %s
                     ORDER BY id DESC
                     LIMIT 1",
                    (int) $post->author_id,
                    'like',
                    'post',
                    $post_id,
                    $cutoff
                )
            );

            if ( $aggregated_id > 0 ) {
                $wpdb->update(
                    $notifications_table,
                    array(
                        'actor_id'   => $user_id,
                        'created_at' => current_time( 'mysql' ),
                        'is_read'    => 0,
                    ),
                    array(
                        'id'      => $aggregated_id,
                        'user_id' => (int) $post->author_id,
                    ),
                    array( '%d', '%s', '%d' ),
                    array( '%d', '%d' )
                );
                return;
            }
        }

        $dedupe_key = sprintf(
            'like:%d:%d:%d',
            (int) $post->author_id,
            (int) $user_id,
            (int) $post_id
        );

        Qibbs_Notification_Delivery::insert( array(
            'user_id'     => $post->author_id,
            'type'        => 'like',
            'actor_id'    => $user_id,
            'object_type' => 'post',
            'object_id'   => $post_id,
            'dedupe_key'  => $dedupe_key,
            'created_at'  => current_time( 'mysql' ),
        ) );
    }

    /**
     * AJAX 接口：切换点赞状态
     */
    public function ajax_toggle_like() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! qibbs_is_module_enabled( 'likes' ) ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'likes' ) ), 403 );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array(
                'message' => __( '请先登录', 'qibbs-community' ),
            ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( array(
                'message' => __( '帖子不存在', 'qibbs-community' ),
            ) );
        }

        /**
         * 点赞前置权限校验过滤器
         *
         * @since 1.1.0
         * @param bool $can_like Whether the user can like.
         * @param int  $user_id  User ID.
         * @param int  $post_id  Post ID.
         */
        $can_like = apply_filters( 'qibbs_can_like', true, $user_id, $post_id );
        if ( ! $can_like ) {
            wp_send_json_error( array(
                'message' => __( '没有点赞权限', 'qibbs-community' ),
            ) );
        }

        // 点赞频率限流保护
        if ( ! $this->check_rate_limit( $user_id ) ) {
            wp_send_json_error( array(
                'message' => __( '操作过于频繁，请稍后再试', 'qibbs-community' ),
            ) );
        }

        $result = $this->toggle_like( $user_id, $post_id );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * 检查点赞操作频次限制
     *
     * @param int $user_id User ID.
     * @return bool
     */
    public function check_rate_limit( $user_id ) {
        $limit = max( 1, absint( qibbs_get_option( 'like_rate_limit', 100 ) ) );

        return function_exists( 'qibbs_consume_scoped_rate_limit' )
            ? qibbs_consume_scoped_rate_limit( 'post_like_hour', $user_id, $limit, HOUR_IN_SECONDS )
            : true;
    }
}
