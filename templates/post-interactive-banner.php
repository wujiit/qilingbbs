<?php
/**
 * 社区动态互动横幅模板（悬赏与红包前台卡片组件）
 *
 * @package Qibbs_Community
 * @var object $post Post object with enriched properties
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( empty( $post ) ) {
    return;
}

$post_id          = (int) $post->id;
$current_user_id  = get_current_user_id();
$is_admin         = current_user_can( 'manage_options' );
$is_owner         = $current_user_id && (int) $current_user_id === (int) $post->author_id;
$points_name      = function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' );

$bounty = isset( $post->bounty ) ? $post->bounty : null;
if ( ! $bounty && function_exists( 'qibbs_get_bounty' ) && 'bounty' === ( $post->post_type ?? '' ) ) {
    $bounty = qibbs_get_bounty( $post_id );
}

$redpacket = isset( $post->redpacket ) ? $post->redpacket : null;
if ( ! $redpacket && function_exists( 'qibbs_get_redpacket' ) && 'redpacket' === ( $post->post_type ?? '' ) ) {
    $redpacket = qibbs_get_redpacket( $post_id );
}
?>

<?php if ( ! empty( $bounty ) ) : 
    $b_status = (string) $bounty->status;
    $b_amount = function_exists( 'qibbs_format_points_amount' ) ? qibbs_format_points_amount( $bounty->amount ) : number_format_i18n( (float) $bounty->amount, 2 );

    $status_label = __( '进行中', 'qibbs-community' );
    $status_class = 'is-pending';
    if ( 'pending_arbitration' === $b_status ) {
        $status_label = __( '待管理员仲裁', 'qibbs-community' );
        $status_class = 'is-arbitration';
    } elseif ( 'awarded' === $b_status ) {
        $status_label = __( '已结赏', 'qibbs-community' );
        $status_class = 'is-awarded';
    } elseif ( 'refunded' === $b_status ) {
        $status_label = __( '已退还', 'qibbs-community' );
        $status_class = 'is-refunded';
    }
?>
    <!-- 悬赏互动卡片 -->
    <div class="qibbs-interactive-banner qibbs-banner-bounty <?php echo esc_attr( $status_class ); ?>" data-post-id="<?php echo esc_attr( $post_id ); ?>">
        <div class="qibbs-banner-main">
            <div class="qibbs-banner-icon-box">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                    <path d="M19 5h-2V3H7v2H5c-1.1 0-2 .9-2 2v1c0 2.55 1.92 4.63 4.39 4.94A5.01 5.01 0 0 0 11 15.9V19H7v2h10v-2h-4v-3.1a5.01 5.01 0 0 0 3.61-2.96C19.08 12.63 21 10.55 21 8V7c0-1.1-.9-2-2-2zM5 8V7h2v3.82C5.84 10.4 5 9.3 5 8zm14 0c0 1.3-.84 2.4-2 2.82V7h2v1z"/>
                </svg>
            </div>
            <div class="qibbs-banner-content">
                <div class="qibbs-banner-title-row">
                    <span class="qibbs-banner-tag"><?php esc_html_e( '悬赏任务', 'qibbs-community' ); ?></span>
                    <strong class="qibbs-banner-amount"><?php echo esc_html( $b_amount ); ?> <small><?php echo esc_html( $points_name ); ?></small></strong>
                    <span class="qibbs-banner-status-badge <?php echo esc_attr( $status_class ); ?>">
                        <?php echo esc_html( $status_label ); ?>
                    </span>
                </div>
                <div class="qibbs-banner-desc">
                    <?php if ( 'pending' === $b_status ) : ?>
                        <span><?php esc_html_e( '楼主采纳最佳回复即可结赏', 'qibbs-community' ); ?></span>
                    <?php elseif ( 'pending_arbitration' === $b_status ) : ?>
                        <span><?php esc_html_e( '超出采纳期限，等待管理员仲裁', 'qibbs-community' ); ?></span>
                    <?php elseif ( 'awarded' === $b_status ) : ?>
                        <span><?php esc_html_e( '悬赏已结案并成功发放', 'qibbs-community' ); ?></span>
                    <?php elseif ( 'refunded' === $b_status ) : ?>
                        <span><?php esc_html_e( '无有效解答，悬赏积分已退回', 'qibbs-community' ); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ( 'pending_arbitration' === $b_status && $is_admin ) : ?>
            <div class="qibbs-banner-actions">
                <button type="button" class="qibbs-btn-action qibbs-btn-arbitrate-trigger" data-post-id="<?php echo esc_attr( $post_id ); ?>">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                    </svg>
                    <span><?php esc_html_e( '管理员仲裁', 'qibbs-community' ); ?></span>
                </button>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ( ! empty( $redpacket ) ) : 
    $p_status     = (string) $redpacket->status;
    $p_draw_type  = (string) $redpacket->draw_type;
    $p_alloc_type = (string) $redpacket->alloc_type;
    $p_total      = function_exists( 'qibbs_format_points_amount' ) ? qibbs_format_points_amount( $redpacket->total_amount ) : number_format_i18n( (float) $redpacket->total_amount, 2 );
    $p_rem_num    = (int) $redpacket->remaining_num;
    $p_total_num  = (int) $redpacket->total_num;

    $p_is_expired = ( ! empty( $redpacket->expires_at ) && $redpacket->expires_at <= current_time( 'mysql' ) );

    $p_status_class = 'is-active';
    $p_status_text  = sprintf( __( '剩余 %d/%d 份', 'qibbs-community' ), $p_rem_num, $p_total_num );
    if ( 'finished' === $p_status || $p_rem_num <= 0 ) {
        $p_status_class = 'is-finished';
        $p_status_text  = __( '已抢完', 'qibbs-community' );
    } elseif ( in_array( $p_status, array( 'refunded', 'expired_refunded' ), true ) || $p_is_expired ) {
        $p_status_class = 'is-refunded';
        $p_status_text  = __( '已超时退回', 'qibbs-community' );
    }

    $draw_type_label  = ( 'lottery' === $p_draw_type ) ? __( '回帖抽奖', 'qibbs-community' ) : __( '回帖即领', 'qibbs-community' );
    $alloc_type_label = ( 'equal' === $p_alloc_type ) ? __( '等额', 'qibbs-community' ) : __( '拼手气', 'qibbs-community' );
?>
    <!-- 红包互动卡片 -->
    <div class="qibbs-interactive-banner qibbs-banner-redpacket <?php echo esc_attr( $p_status_class ); ?>" data-post-id="<?php echo esc_attr( $post_id ); ?>" data-redpacket-id="<?php echo esc_attr( $redpacket->id ); ?>">
        <div class="qibbs-banner-main">
            <div class="qibbs-banner-icon-box">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                    <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 2v3.5l-7 4.38L5 8.5V5h14zm-7 8.5 7-4.38V19H5V9.12l7 4.38z"/>
                </svg>
            </div>
            <div class="qibbs-banner-content">
                <div class="qibbs-banner-title-row">
                    <span class="qibbs-banner-tag"><?php echo esc_html( $draw_type_label ); ?> · <?php echo esc_html( $alloc_type_label ); ?></span>
                    <strong class="qibbs-banner-amount"><?php echo esc_html( $p_total ); ?> <small><?php echo esc_html( $points_name ); ?></small></strong>
                    <span class="qibbs-banner-status-badge <?php echo esc_attr( $p_status_class ); ?>">
                        <?php echo esc_html( $p_status_text ); ?>
                    </span>
                </div>
                <div class="qibbs-banner-desc">
                    <?php 
                    $allow_author = (bool) qibbs_get_option( 'redpacket_allow_author_claim', false );
                    ?>
                    <?php if ( 'active' === $p_status && $p_rem_num > 0 && ! $p_is_expired ) : ?>
                        <?php if ( 'lottery' === $p_draw_type ) : ?>
                            <span>
                                <?php esc_html_e( '回帖参与抽奖，由楼主开奖', 'qibbs-community' ); ?>
                                <?php if ( ! $allow_author ) : ?>
                                    <span class="qibbs-banner-owner-note"><?php echo $is_owner ? esc_html__( '（您是发帖人，不参与）', 'qibbs-community' ) : esc_html__( '（发帖人不参与）', 'qibbs-community' ); ?></span>
                                <?php endif; ?>
                            </span>
                        <?php else : 
                            $has_claimed = ( $current_user_id && function_exists( 'qibbs_has_user_claimed_redpacket' ) )
                                ? qibbs_has_user_claimed_redpacket( $redpacket->id, $current_user_id )
                                : false;
                        ?>
                            <span>
                                <?php if ( ! $allow_author && $is_owner ) : ?>
                                    <?php esc_html_e( '回帖自动领取', 'qibbs-community' ); ?> <span class="qibbs-banner-owner-note"><?php esc_html_e( '（发帖人不参与）', 'qibbs-community' ); ?></span>
                                <?php elseif ( $has_claimed ) : ?>
                                    <span class="qibbs-banner-owner-note"><?php esc_html_e( '您已领过该红包（每人限领 1 份）', 'qibbs-community' ); ?></span>
                                <?php else : ?>
                                    <?php esc_html_e( '回帖自动领取，每人限 1 份', 'qibbs-community' ); ?>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    <?php elseif ( 'finished' === $p_status || $p_rem_num <= 0 ) : ?>
                        <span><?php esc_html_e( '红包已被抢完，可查看手气榜', 'qibbs-community' ); ?></span>
                    <?php else : ?>
                        <span><?php esc_html_e( '红包已超时结束，剩余积分已退还', 'qibbs-community' ); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="qibbs-banner-actions">
            <button type="button" class="qibbs-btn-action qibbs-btn-view-packet-logs" data-redpacket-id="<?php echo esc_attr( $redpacket->id ); ?>" data-post-id="<?php echo esc_attr( $post_id ); ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor">
                    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                </svg>
                <span><?php esc_html_e( '手气榜', 'qibbs-community' ); ?></span>
            </button>

            <?php if ( 'active' === $p_status && 'lottery' === $p_draw_type && ( $is_owner || $is_admin ) ) : ?>
                <button type="button" class="qibbs-btn-action qibbs-btn-action-primary qibbs-btn-draw-packet-trigger" data-post-id="<?php echo esc_attr( $post_id ); ?>">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor">
                        <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                    </svg>
                    <span><?php esc_html_e( '立即开奖', 'qibbs-community' ); ?></span>
                </button>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
