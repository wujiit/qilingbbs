<?php
/**
 * 插件停用管理类
 * 负责停用时的定时任务清理、缓存失效及状态重置
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 停用执行器
 */
class Qibbs_Deactivator {

    /**
     * 执行停用清理任务（保留数据表及用户数据）
     */
    public static function deactivate() {
        // 刷新重写规则
        flush_rewrite_rules();

        // 清理已计划的定时事件
        self::clear_scheduled_events();

        // 清理瞬态缓存数据
        self::clear_transients();

        /**
         * 插件停用完成动作钩子
         *
         * @since 1.0.0
         */
        do_action( 'qibbs_deactivated' );
    }

    /**
     * 卸载所有已注册的定时任务
     */
    private static function clear_scheduled_events() {
        $events = array(
            'qibbs_daily_cleanup',
            'qibbs_hourly_stats_update',
            'qibbs_notification_digest',
            'qibbs_notification_batch_event',
            'qibbs_forum_subscription_notify_event',
            'qibbs_risk_cleanup_event',
            'qibbs_search_index_sync_event',
            'qibbs_ticket_sla_scan_event',
            'qibbs_ticket_push_event',
            'qibbs_bounty_expiration_check',
            'qibbs_prop_expiration_check',
            'qibbs_redpacket_expiration_check',
        );

        foreach ( $events as $event ) {
            if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
                wp_clear_scheduled_hook( $event );
                continue;
            }

            $timestamp = wp_next_scheduled( $event );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, $event );
            }
        }
    }

    /**
     * 清理所有带有 qibbs_ 前缀的瞬态缓存
     */
    private static function clear_transients() {
        global $wpdb;

        // 批量删除 qibbs_ 前缀瞬态缓存
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_qibbs_%' 
             OR option_name LIKE '_transient_timeout_qibbs_%'"
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                'qibbs_search_index_dirty_post_%'
            )
        );

        // 重置工单 SLA 扫描游标
        delete_option( 'qibbs_ticket_sla_scan_cursor' );
    }
}
