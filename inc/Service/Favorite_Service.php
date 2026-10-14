<?php
/**
 * 帖子收藏服务类
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Favorite_Service {

    public function toggle_favorite( $user_id, $post_id ) {
        if ( ! qibbs_is_module_enabled( 'favorites' ) ) {
            return array( 'success' => false, 'message' => qibbs_get_module_disabled_message( 'favorites' ) );
        }

        if ( ! $user_id || ! $post_id ) {
            return array( 'success' => false, 'message' => __( '参数错误', 'qibbs-community' ) );
        }

        global $wpdb;
        $table = Qibbs_Database::favorites();
        $posts_table = Qibbs_Database::posts();

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND post_id = %d",
            $user_id, $post_id
        ) );

        if ( $existing ) {
            $deleted = $wpdb->delete( $table, array( 'user_id' => $user_id, 'post_id' => $post_id ), array( '%d', '%d' ) );
            if ( false === $deleted ) {
                return array( 'success' => false, 'message' => __( '取消收藏失败，请稍后重试', 'qibbs-community' ) );
            }

            if ( $deleted > 0 ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$posts_table} SET favorite_count = GREATEST(favorite_count - 1, 0) WHERE id = %d",
                    $post_id
                ) );
            }

            $favorited = false;
        } else {
            $inserted = $wpdb->insert( $table, array(
                'user_id'    => $user_id,
                'post_id'    => $post_id,
                'created_at' => current_time( 'mysql' ),
            ), array( '%d', '%d', '%s' ) );
            $favorited = true;

            if ( false === $inserted ) {
                if ( false === stripos( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
                    return array( 'success' => false, 'message' => __( '收藏失败，请稍后重试', 'qibbs-community' ) );
                }
            } else {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$posts_table} SET favorite_count = favorite_count + 1 WHERE id = %d",
                    $post_id
                ) );
                $this->create_favorite_notification( $user_id, $post_id );
            }
        }

        $favorite_count = $wpdb->get_var( $wpdb->prepare(
            "SELECT favorite_count FROM {$posts_table} WHERE id = %d", $post_id
        ) );

        do_action( 'qibbs_favorite_toggled', $user_id, $post_id, $favorited );

        return array(
            'success'        => true,
            'favorited'      => $favorited,
            'favorite_count' => (int) $favorite_count,
        );
    }

    /**
     * 创建收藏互动站内通知
     *
     * @param int $user_id User who favorited.
     * @param int $post_id Post ID.
     * @return void
     */
    private function create_favorite_notification( $user_id, $post_id ) {
        global $wpdb;
        $posts_table = Qibbs_Database::posts();

        $post = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT author_id FROM {$posts_table} WHERE id = %d",
                $post_id
            )
        );

        if ( ! $post || (int) $post->author_id === (int) $user_id ) {
            return;
        }

        if ( ! class_exists( 'Qibbs_Notification_Service' ) ) {
            return;
        }

        $notification_service = new Qibbs_Notification_Service();
        $notification_service->notify_favorite( (int) $post->author_id, (int) $user_id, (int) $post_id );
    }

    public function has_favorited( $user_id, $post_id ) {
        global $wpdb;
        $table = Qibbs_Database::favorites();
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND post_id = %d",
            $user_id, $post_id
        ) );
    }

    public function get_user_favorites( $user_id, $limit = 20, $offset = 0 ) {
        global $wpdb;
        $favorites_table = Qibbs_Database::favorites();
        $posts_table = Qibbs_Database::posts();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*, f.created_at as favorited_at FROM {$posts_table} p
             INNER JOIN {$favorites_table} f ON p.id = f.post_id
             WHERE f.user_id = %d AND p.status = 'publish'
             ORDER BY f.created_at DESC LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ) );
    }

    public function ajax_toggle_favorite() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! qibbs_is_module_enabled( 'favorites' ) ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'favorites' ) ), 403 );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => __( '帖子不存在', 'qibbs-community' ) ) );
        }

        /**
         * 校验用户是否允许收藏帖子
         *
         * @since 1.1.0
         * @param bool $can_favorite Whether the user can favorite.
         * @param int  $user_id      User ID.
         * @param int  $post_id      Post ID.
         */
        $can_favorite = apply_filters( 'qibbs_can_favorite', true, $user_id, $post_id );
        if ( ! $can_favorite ) {
            wp_send_json_error( array( 'message' => __( '没有收藏权限', 'qibbs-community' ) ) );
        }

        $result = $this->toggle_favorite( $user_id, $post_id );
        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }
}
