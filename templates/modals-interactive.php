<?php
/**
 * 社区互动专属弹窗模板（手气榜与管理员仲裁弹窗）
 *
 * @package Qibbs_Community
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<!-- 红包手气榜弹窗 -->
<div class="qibbs-modal-overlay" id="qibbs-redpacket-modal" style="display: none;" aria-hidden="true">
    <div class="qibbs-modal-dialog qibbs-modal-redpacket">
        <div class="qibbs-modal-header">
            <h3 class="qibbs-modal-title">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                    <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 2v3.5l-7 4.38L5 8.5V5h14zm-7 8.5 7-4.38V19H5V9.12l7 4.38z"/>
                </svg>
                <span><?php esc_html_e( '红包手气榜', 'qibbs-community' ); ?></span>
            </h3>
            <button type="button" class="qibbs-modal-close" aria-label="<?php esc_attr_e( '关闭', 'qibbs-community' ); ?>">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                    <path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
        </div>
        <div class="qibbs-modal-body">
            <div class="qibbs-redpacket-logs-list" id="qibbs-redpacket-logs-list">
                <div class="qibbs-modal-loading"><?php esc_html_e( '正在加载记录...', 'qibbs-community' ); ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ( current_user_can( 'manage_options' ) ) : ?>
<!-- 悬赏管理员仲裁弹窗 -->
<div class="qibbs-modal-overlay" id="qibbs-arbitration-modal" style="display: none;" aria-hidden="true">
    <div class="qibbs-modal-dialog qibbs-modal-arbitration">
        <div class="qibbs-modal-header">
            <h3 class="qibbs-modal-title">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
                <span><?php esc_html_e( '悬赏帖子管理员仲裁', 'qibbs-community' ); ?></span>
            </h3>
            <button type="button" class="qibbs-modal-close" aria-label="<?php esc_attr_e( '关闭', 'qibbs-community' ); ?>">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                    <path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
        </div>
        <div class="qibbs-modal-body">
            <form id="qibbs-arbitration-form">
                <input type="hidden" name="post_id" id="qibbs-arbitrate-post-id" value="0">
                <div class="qibbs-arbitrate-group">
                    <label class="qibbs-arbitrate-label"><?php esc_html_e( '仲裁裁决方案', 'qibbs-community' ); ?></label>
                    <div class="qibbs-arbitrate-options">
                        <label class="qibbs-radio-card">
                            <input type="radio" name="arbitration_action" value="award" checked>
                            <span class="qibbs-radio-card-content">
                                <strong><?php esc_html_e( '代为采纳并结赏', 'qibbs-community' ); ?></strong>
                                <small><?php esc_html_e( '判定存在有效解答，选择其中一条优质回答采纳并将悬赏积分派发给该答主', 'qibbs-community' ); ?></small>
                            </span>
                        </label>
                        <label class="qibbs-radio-card">
                            <input type="radio" name="arbitration_action" value="refund">
                            <span class="qibbs-radio-card-content">
                                <strong><?php esc_html_e( '无有效答案，退还楼主', 'qibbs-community' ); ?></strong>
                                <small><?php esc_html_e( '判定回帖中无切实解决问题的答案，将冻结的悬赏积分全额退回发帖人账户', 'qibbs-community' ); ?></small>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="qibbs-arbitrate-group" id="qibbs-arbitrate-comment-select-wrap">
                    <label class="qibbs-arbitrate-label"><?php esc_html_e( '选择获胜回答', 'qibbs-community' ); ?></label>
                    <div id="qibbs-arbitrate-comments-list" class="qibbs-arbitrate-comments-list">
                        <div class="qibbs-modal-loading"><?php esc_html_e( '正在检索评论候选列表...', 'qibbs-community' ); ?></div>
                    </div>
                </div>

                <div class="qibbs-arbitrate-group">
                    <label class="qibbs-arbitrate-label" for="qibbs-arbitrate-reason"><?php esc_html_e( '仲裁说明（将记录至日志并通知当事人）', 'qibbs-community' ); ?></label>
                    <textarea name="reason" id="qibbs-arbitrate-reason" class="qibbs-form-textarea" rows="3" placeholder="<?php esc_attr_e( '输入仲裁说明或原因（选填）...', 'qibbs-community' ); ?>"></textarea>
                </div>

                <div class="qibbs-modal-footer">
                    <button type="button" class="qibbs-btn-secondary qibbs-modal-cancel-btn"><?php esc_html_e( '取消', 'qibbs-community' ); ?></button>
                    <button type="submit" class="qibbs-btn-primary" id="qibbs-submit-arbitration-btn"><?php esc_html_e( '确认仲裁并执行', 'qibbs-community' ); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
