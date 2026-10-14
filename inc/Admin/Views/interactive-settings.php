<?php
/**
 * 视图模板：社区悬赏问答与红包互动配置
 * 悬赏与红包互动专属管理面板
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap qibbs-admin-page">
    <h1><?php esc_html_e( '悬赏与红包互动设置', 'qibbs-community' ); ?></h1>

    <div class="notice notice-info" style="margin: 16px 0 20px; padding: 12px 16px; border-radius: 8px; border-left-color: #1f6feb;">
        <p style="margin: 0; font-size: 13.5px; line-height: 1.6;">
            <span class="dashicons dashicons-info" style="color: #1f6feb; vertical-align: -2px; margin-right: 4px;"></span><strong><?php esc_html_e( '提示：', 'qibbs-community' ); ?></strong>
            <?php esc_html_e( '此处配置社区「悬赏帖」与「回帖红包」的经济规则。发帖冻结积分，采纳或抢包即时结算。未领完红包到期自动退回原作者；悬赏帖到期若有回复自动转入管理员仲裁结案。采纳最佳答案具有终局不可逆效力。', 'qibbs-community' ); ?>
        </p>
    </div>

    <form method="post" action="options.php" id="qibbs-interactive-form">
        <?php
        settings_fields( 'qibbs_interactive_group' );
        do_settings_sections( 'qibbs-interactive' );
        submit_button( __( '保存悬赏与红包设置', 'qibbs-community' ) );
        ?>
    </form>
</div>
