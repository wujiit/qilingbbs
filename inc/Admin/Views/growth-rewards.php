<?php
/**
 * 视图模板：成长升级与积分奖励配置
 * 成长等级与双轨激励独立管理面板
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap qibbs-admin-page">
    <h1><?php esc_html_e( '成长等级与激励体系', 'qibbs-community' ); ?></h1>

    <div class="notice notice-info" style="margin: 16px 0 20px; padding: 12px 16px; border-radius: 8px; border-left-color: #1f6feb;">
        <p style="margin: 0; font-size: 13.5px; line-height: 1.6;">
            <span class="dashicons dashicons-info" style="color: #1f6feb; vertical-align: -2px; margin-right: 4px;"></span><strong><?php esc_html_e( '提示：', 'qibbs-community' ); ?></strong>
            <?php esc_html_e( '此处配置社区用户的成长阶梯与行为奖励。支持「成长值（EXP）」与「商城积分（Points）」双轨齐发或单轨奖励。积分中枢统一联动启灵商城，自动执行频控与幂等防刷。', 'qibbs-community' ); ?>
        </p>
    </div>

    <form method="post" action="options.php" id="qibbs-growth-rewards-form">
        <?php
        settings_fields( 'qibbs_growth_rewards_group' );
        do_settings_sections( 'qibbs-growth-rewards' );
        submit_button( __( '保存成长与激励设置', 'qibbs-community' ) );
        ?>
    </form>
</div>
