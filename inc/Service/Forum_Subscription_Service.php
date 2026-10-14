<?php
/**
 * 版块订阅与新帖推送服务
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Forum_Subscription_Service {

    /**
     * WP-Cron 异步广播版块订阅新帖通知钩子
     *
     * @var string
     */
    const CRON_HOOK = 'qibbs_forum_subscription_notify_event';

    /**
     * AJAX 接口：订阅/取消订阅版块
     */
    public function ajax_toggle_subscription() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        if ( ! qibbs_get_option( 'enable_forum_subscription_notifications', true ) ) {
            wp_send_json_error( array( 'message' => __( '圈子订阅通知已关闭', 'qibbs-community' ) ), 403 );
        }

        $forum_id = isset( $_POST['forum_id'] ) ? absint( $_POST['forum_id'] ) : 0;
        if ( ! $forum_id ) {
            wp_send_json_error( array( 'message' => __( '参数错误', 'qibbs-community' ) ) );
        }

        global $wpdb;
        $forums_table = Qibbs_Database::forums();
        $forum = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, name FROM {$forums_table} WHERE id = %d",
                $forum_id
            )
        );
        if ( ! $forum ) {
            wp_send_json_error( array( 'message' => __( '圈子不存在', 'qibbs-community' ) ) );
        }

        $subscriptions_table = Qibbs_Database::forum_subscriptions();
        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$subscriptions_table} WHERE forum_id = %d AND user_id = %d LIMIT 1",
                $forum_id,
                $user_id
            )
        );

        if ( $existing_id > 0 ) {
            $deleted = $wpdb->delete(
                $subscriptions_table,
                array(
                    'id'      => $existing_id,
                    'forum_id'=> $forum_id,
                    'user_id' => $user_id,
                ),
                array( '%d', '%d', '%d' )
            );

            if ( false === $deleted ) {
                wp_send_json_error( array( 'message' => __( '取消订阅失败，请稍后重试', 'qibbs-community' ) ) );
            }

            wp_send_json_success( array(
                'subscribed' => false,
                'label'      => __( '订阅', 'qibbs-community' ),
                'message'    => sprintf( __( '已取消订阅圈子：%s', 'qibbs-community' ), $forum->name ),
            ) );
        }

        $inserted = $wpdb->insert(
            $subscriptions_table,
            array(
                'forum_id'   => $forum_id,
                'user_id'    => $user_id,
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s' )
        );

        if ( false === $inserted ) {
            wp_send_json_error( array( 'message' => __( '订阅失败，请稍后重试', 'qibbs-community' ) ) );
        }

        wp_send_json_success( array(
            'subscribed' => true,
            'label'      => __( '已订阅', 'qibbs-community' ),
            'message'    => sprintf( __( '已订阅圈子：%s', 'qibbs-community' ), $forum->name ),
        ) );
    }

    /**
     * 批量查询当前用户对指定版块的订阅状态
     *
     * @param int   $user_id User ID.
     * @param array $forum_ids Forum IDs.
     * @return array
     */
    public function get_user_subscriptions_for_forums( $user_id, $forum_ids ) {
        $user_id = absint( $user_id );
        $forum_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $forum_ids ) ) ) );
        if ( ! $user_id || empty( $forum_ids ) ) {
            return array();
        }

        global $wpdb;
        $subscriptions_table = Qibbs_Database::forum_subscriptions();
        $placeholders = implode( ',', array_fill( 0, count( $forum_ids ), '%d' ) );
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT forum_id FROM {$subscriptions_table}
                 WHERE user_id = %d AND forum_id IN ({$placeholders})",
                array_merge( array( $user_id ), $forum_ids )
            )
        );

        $map = array();
        foreach ( (array) $rows as $forum_id ) {
            $map[ (int) $forum_id ] = true;
        }
        return $map;
    }

    /**
     * 新帖发布时通知版块订阅用户
     *
     * @param int $post_id Post ID.
     * @param int $author_id Author ID.
     */
    public function notify_subscribers_on_post( $post_id, $author_id = 0 ) {
        if ( ! qibbs_get_option( 'enable_forum_subscription_notifications', true ) ) {
            return;
        }

        $post_id = absint( $post_id );
        $author_id = absint( $author_id );
        if ( ! $post_id ) {
            return;
        }

        $args = array( $post_id, $author_id );
        if ( wp_next_scheduled( self::CRON_HOOK, $args ) ) {
            return;
        }

        $delay = max(
            1,
            absint(
                apply_filters(
                    'qibbs_forum_subscription_notification_delay',
                    5,
                    $post_id,
                    $author_id
                )
            )
        );

        wp_schedule_single_event( time() + $delay, self::CRON_HOOK, $args );
    }

    /**
     * 处理队列中版块订阅用户的异步批量通知
     *
     * @param int $post_id   Post ID.
     * @param int $author_id Author ID.
     * @return void
     */
    public function process_subscribers_on_post( $post_id, $author_id = 0 ) {
        if ( ! qibbs_get_option( 'enable_forum_subscription_notifications', true ) ) {
            return;
        }

        $post_id = absint( $post_id );
        $author_id = absint( $author_id );
        if ( ! $post_id ) {
            return;
        }

        global $wpdb;
        $posts_table = Qibbs_Database::posts();
        $post = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, forum_id, status FROM {$posts_table} WHERE id = %d",
                $post_id
            )
        );

        if ( ! $post || (int) $post->forum_id <= 0 || 'publish' !== $post->status ) {
            return;
        }

        $forum_id = (int) $post->forum_id;
        $forum_name = '';
        $forums_table = Qibbs_Database::forums();
        $forum_row = $wpdb->get_row(
            $wpdb->prepare( "SELECT name FROM {$forums_table} WHERE id = %d", $forum_id )
        );
        if ( $forum_row && isset( $forum_row->name ) ) {
            $forum_name = (string) $forum_row->name;
        }

        $subscriptions_table = Qibbs_Database::forum_subscriptions();

        $content = ! empty( $forum_name )
            ? sprintf( __( '你订阅的圈子「%s」有新动态', 'qibbs-community' ), $forum_name )
            : __( '你订阅的圈子有新动态', 'qibbs-community' );

        $scan_batch_size = max(
            20,
            absint(
                apply_filters(
                    'qibbs_forum_subscription_notification_scan_batch_size',
                    200,
                    $forum_id,
                    $post_id
                )
            )
        );
        $insert_chunk_size = max(
            1,
            absint(
                apply_filters(
                    'qibbs_forum_subscription_notification_insert_chunk_size',
                    120,
                    $forum_id,
                    $post_id
                )
            )
        );
        $created_at = current_time( 'mysql' );
        $last_user_id = 0;

        do {
            $subscriber_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT user_id FROM {$subscriptions_table}
                     WHERE forum_id = %d AND user_id > %d
                     ORDER BY user_id ASC
                     LIMIT %d",
                    $forum_id,
                    $last_user_id,
                    $scan_batch_size
                )
            );

            $subscriber_ids = array_values( array_filter( array_map( 'absint', (array) $subscriber_ids ) ) );
            if ( empty( $subscriber_ids ) ) {
                break;
            }

            if ( function_exists( 'update_meta_cache' ) ) {
                update_meta_cache( 'user', $subscriber_ids );
            }

            $rows = array();
            foreach ( $subscriber_ids as $subscriber_id ) {
                if ( $subscriber_id === $author_id ) {
                    continue;
                }
                if ( function_exists( 'qibbs_can_send_notification_type' ) && ! qibbs_can_send_notification_type( $subscriber_id, 'forum_post' ) ) {
                    continue;
                }

                $rows[] = array(
                    'user_id'     => $subscriber_id,
                    'type'        => 'forum_post',
                    'actor_id'    => $author_id,
                    'object_type' => 'post',
                    'object_id'   => $post_id,
                    'content'     => $content,
                    'dedupe_key'  => sprintf( 'forum_post:%d:%d', (int) $subscriber_id, (int) $post_id ),
                    'created_at'  => $created_at,
                );
            }

            if ( ! empty( $rows ) ) {
                Qibbs_Notification_Delivery::insert_batch( $rows, $insert_chunk_size );
            }

            $last_user_id = (int) $subscriber_ids[ count( $subscriber_ids ) - 1 ];
        } while ( count( $subscriber_ids ) === $scan_batch_size );
    }
}
