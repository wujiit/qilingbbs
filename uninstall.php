<?php
/**
 * 插件彻底卸载清理脚本
 * 当插件被彻底删除卸载时执行
 * 根据站长配置可选择性清理所有微社区自定义数据表、配置项及瞬态缓存
 */

// 禁止非卸载流程直接访问
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// 检查是否配置了卸载时清理所有数据
$delete_data = get_option( 'qibbs_delete_data_on_uninstall', false );
if ( ! $delete_data ) {
    $options = get_option( 'qibbs_options', array() );
    if ( is_array( $options ) && ! empty( $options['delete_data_on_uninstall'] ) ) {
        $delete_data = true;
    }
}

if ( ! $delete_data ) {
    return;
}

global $wpdb;

// 批量删除所有微社区自定义数据表（共34张表）
$tables = array(
    'qibbs_posts',
    'qibbs_search_index',
    'qibbs_forums',
    'qibbs_forum_members',
    'qibbs_forum_subscriptions',
    'qibbs_tags',
    'qibbs_post_tags',
    'qibbs_likes',
    'qibbs_comment_likes',
    'qibbs_favorites',
    'qibbs_comments',
    'qibbs_follows',
    'qibbs_messages',
    'qibbs_notifications',
    'qibbs_user_meta',
    'qibbs_user_stats',
    'qibbs_media',
    'qibbs_reports',
    'qibbs_blacklist',
    'qibbs_ticket_categories',
    'qibbs_tickets',
    'qibbs_ticket_replies',
    'qibbs_risk_logs',
    'qibbs_ip_bans',
    'qibbs_moderation_logs',
    'qibbs_members',
    'qibbs_vanity_uids',
    'qibbs_orders',
    'qibbs_c2c_trades',
    'qibbs_bounties',
    'qibbs_redpackets',
    'qibbs_redpacket_logs',
    'qibbs_props',
    'qibbs_user_props',
);

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
}

// 清理所有已知的插件配置项
$options_to_delete = array(
    'qibbs_options',
    'qibbs_activated',
    'qibbs_db_version',
    'qibbs_cache_version',
    'qibbs_community_page_id',
    'qibbs_messages_page_id',
    'qibbs_notifications_page_id',
    'qibbs_delete_data_on_uninstall',
    'qibbs_search_index_rebuild_pending',
    'qibbs_search_index_rebuild_cursor',
    'qibbs_ticket_admin_metrics_cache_version',
    'qibbs_ticket_sla_scan_cursor',
    'qibbs_uid_free_cursor',
    'qibbs_forum_governance_settings',
);

foreach ( $options_to_delete as $option_name ) {
    delete_option( $option_name );
}

// 批量清理以 qibbs_ 开头的所有遗留配置项
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'qibbs_' ) . '%' ) );

// 清理用户扩展元数据（包含 _qibbs_ 与 qibbs_ 前缀）
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $wpdb->esc_like( '_qibbs_' ) . '%', $wpdb->esc_like( 'qibbs_' ) . '%' ) );

// 清理瞬态缓存
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_qibbs_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_qibbs_' ) . '%' ) );

// 清理所有已注册的计划任务
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
    } else {
        $timestamp = wp_next_scheduled( $event );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $event );
        }
    }
}

// 刷新重写规则
flush_rewrite_rules();
