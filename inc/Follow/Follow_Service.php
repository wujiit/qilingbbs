<?php
/**
 * 用户关注服务类
 *
 * @package Qibbs_Community
 * @subpackage Follow
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Follow_Service {

    public function toggle_follow( $follower_id, $following_id ) {
        if ( ! qibbs_is_module_enabled( 'follows' ) ) {
            return array( 'success' => false, 'message' => qibbs_get_module_disabled_message( 'follows' ) );
        }

        if ( ! $follower_id || ! $following_id ) {
            return array( 'success' => false, 'message' => __( '参数错误', 'qibbs-community' ) );
        }

        // 无法关注自己
        if ( $follower_id === $following_id ) {
            return array( 'success' => false, 'message' => __( '不能关注自己', 'qibbs-community' ) );
        }

        global $wpdb;
        $table = Qibbs_Database::follows();
        $stats_table = Qibbs_Database::user_stats();

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE follower_id = %d AND following_id = %d",
            $follower_id, $following_id
        ) );

        if ( $existing ) {
            // 取消关注处理
            $deleted = $wpdb->delete(
                $table,
                array(
                    'follower_id'  => $follower_id,
                    'following_id' => $following_id,
                ),
                array( '%d', '%d' )
            );

            if ( false === $deleted ) {
                return array( 'success' => false, 'message' => __( '取消关注失败，请稍后重试', 'qibbs-community' ) );
            }

            if ( $deleted > 0 ) {
                // 仅在成功解除关注关系时递减统计计数
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$stats_table} SET following_count = GREATEST(following_count - 1, 0) WHERE user_id = %d",
                    $follower_id
                ) );
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$stats_table} SET follower_count = GREATEST(follower_count - 1, 0) WHERE user_id = %d",
                    $following_id
                ) );
            }
            
            $following = false;
        } else {
            // 新增关注处理
            $inserted = $wpdb->insert( $table, array(
                'follower_id'  => $follower_id,
                'following_id' => $following_id,
                'created_at'   => current_time( 'mysql' ),
            ), array( '%d', '%d', '%s' ) );

            if ( false === $inserted ) {
                if ( false === stripos( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
                    return array( 'success' => false, 'message' => __( '关注失败，请稍后重试', 'qibbs-community' ) );
                }
            } else {
                // 更新或初始化用户关注计数统计
                $this->ensure_user_stats( $follower_id );
                $this->ensure_user_stats( $following_id );
                
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$stats_table} SET following_count = following_count + 1 WHERE user_id = %d",
                    $follower_id
                ) );
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$stats_table} SET follower_count = follower_count + 1 WHERE user_id = %d",
                    $following_id
                ) );

                // 创建关注站内通知
                $this->create_follow_notification( $follower_id, $following_id );
            }
            
            $following = true;
        }

        $follower_count = $this->get_follower_count( $following_id );

        do_action( 'qibbs_follow_toggled', $follower_id, $following_id, $following );

        return array(
            'success'        => true,
            'following'      => $following,
            'follower_count' => $follower_count,
        );
    }

    public function is_following( $follower_id, $following_id ) {
        global $wpdb;
        $table = Qibbs_Database::follows();

        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE follower_id = %d AND following_id = %d",
            $follower_id, $following_id
        ) );
    }

    public function get_followers( $user_id, $limit = 20, $offset = 0 ) {
        global $wpdb;
        $table = Qibbs_Database::follows();

        $followers = $wpdb->get_results( $wpdb->prepare(
            "SELECT follower_id, created_at FROM {$table} 
             WHERE following_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ) );

        return $this->enrich_users( $followers, 'follower_id' );
    }

    public function get_following( $user_id, $limit = 20, $offset = 0 ) {
        global $wpdb;
        $table = Qibbs_Database::follows();

        $following = $wpdb->get_results( $wpdb->prepare(
            "SELECT following_id, created_at FROM {$table} 
             WHERE follower_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $user_id, $limit, $offset
        ) );

        return $this->enrich_users( $following, 'following_id' );
    }

    public function get_follower_count( $user_id ) {
        global $wpdb;
        $table = Qibbs_Database::follows();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE following_id = %d", $user_id
        ) );
    }

    public function get_following_count( $user_id ) {
        global $wpdb;
        $table = Qibbs_Database::follows();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE follower_id = %d", $user_id
        ) );
    }

    private function enrich_users( $records, $id_field ) {
        $current_user_id = get_current_user_id();
        foreach ( $records as &$record ) {
            $user_id = $record->$id_field;
            $user = get_userdata( $user_id );
            $record->user = array(
                'id'           => $user_id,
                'display_name' => $user ? $user->display_name : __( '未知用户', 'qibbs-community' ),
                'avatar'       => get_avatar_url( $user_id, array( 'size' => 64 ) ),
            );
            $record->is_following = $current_user_id ? $this->is_following( $current_user_id, $user_id ) : false;
        }
        return $records;
    }

    private function ensure_user_stats( $user_id ) {
        global $wpdb;
        $table = Qibbs_Database::user_stats();
        
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$table} WHERE user_id = %d", $user_id ) );
        if ( ! $exists ) {
            $wpdb->insert( $table, array( 'user_id' => $user_id ), array( '%d' ) );
        }
    }

    private function create_follow_notification( $follower_id, $following_id ) {
        if ( function_exists( 'qibbs_can_send_notification_type' ) && ! qibbs_can_send_notification_type( $following_id, 'follow' ) ) {
            return;
        }

        global $wpdb;
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
                       AND is_read = 0
                       AND created_at >= %s
                     ORDER BY id DESC
                     LIMIT 1",
                    $following_id,
                    'follow',
                    'user',
                    $cutoff
                )
            );

            if ( $aggregated_id > 0 ) {
                $wpdb->update(
                    $notifications_table,
                    array(
                        'actor_id'   => $follower_id,
                        'object_id'  => $follower_id,
                        'created_at' => current_time( 'mysql' ),
                        'is_read'    => 0,
                    ),
                    array(
                        'id'      => $aggregated_id,
                        'user_id' => $following_id,
                    ),
                    array( '%d', '%d', '%s', '%d' ),
                    array( '%d', '%d' )
                );
                return;
            }
        }

        $dedupe_key = sprintf(
            'follow:%d:%d',
            (int) $following_id,
            (int) $follower_id
        );

        Qibbs_Notification_Delivery::insert( array(
            'user_id'     => $following_id,
            'type'        => 'follow',
            'actor_id'    => $follower_id,
            'object_type' => 'user',
            'object_id'   => $follower_id,
            'dedupe_key'  => $dedupe_key,
            'created_at'  => current_time( 'mysql' ),
        ) );
    }

    public function ajax_toggle_follow() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! qibbs_is_module_enabled( 'follows' ) ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'follows' ) ), 403 );
        }

        $follower_id = get_current_user_id();
        if ( ! $follower_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $following_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        if ( ! $following_id ) {
            wp_send_json_error( array( 'message' => __( '用户不存在', 'qibbs-community' ) ) );
        }

        /**
         * 校验用户是否允许执行关注动作
         *
         * @since 1.1.0
         * @param bool $can_follow   Whether the user can follow.
         * @param int  $follower_id  Follower user ID.
         * @param int  $following_id User being followed ID.
         */
        $can_follow = apply_filters( 'qibbs_can_follow', true, $follower_id, $following_id );
        if ( ! $can_follow ) {
            wp_send_json_error( array( 'message' => __( '没有关注权限', 'qibbs-community' ) ) );
        }

        $result = $this->toggle_follow( $follower_id, $following_id );
        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }
}
