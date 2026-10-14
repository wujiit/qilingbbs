<?php
/**
 * 发帖器互动扩展抽屉表单（悬赏贴与红包贴专属配置）
 *
 * @package Qibbs_Community
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id = get_current_user_id();
if ( ! $user_id ) {
    return;
}

$points_name      = function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' );
$points_balance   = function_exists( 'qibbs_get_user_points_balance' ) ? qibbs_get_user_points_balance( $user_id ) : 0.0;
$balance_formatted= function_exists( 'qibbs_format_points_amount' ) ? qibbs_format_points_amount( $points_balance ) : number_format_i18n( $points_balance, 2 );

$bounty_enabled   = function_exists( 'qibbs_is_bounty_enabled' ) ? qibbs_is_bounty_enabled() : false;
$bounty_min       = (float) qibbs_get_option( 'bounty_min_amount', 5 );
$bounty_max       = (float) qibbs_get_option( 'bounty_max_amount', 1000 );
$bounty_days      = absint( qibbs_get_option( 'bounty_expire_days', 7 ) );

$redpacket_enabled= function_exists( 'qibbs_is_redpacket_enabled' ) ? qibbs_is_redpacket_enabled() : false;
$packet_min_amount= (float) qibbs_get_option( 'redpacket_min_total_amount', 10 );
$packet_max_amount= (float) qibbs_get_option( 'redpacket_max_total_amount', 10000 );
$packet_min_num   = max( 1, absint( qibbs_get_option( 'redpacket_min_count', 5 ) ) );
$packet_max_num   = max( $packet_min_num, absint( qibbs_get_option( 'redpacket_max_count', 100 ) ) );
$packet_hours     = absint( qibbs_get_option( 'redpacket_expire_hours', 24 ) );
?>

<?php if ( $bounty_enabled ) : ?>
<!-- 悬赏帖专属配置抽屉 -->
<div class="qibbs-interactive-drawer qibbs-bounty-drawer" id="qibbs-bounty-fields" style="display: none;" data-min="<?php echo esc_attr( $bounty_min ); ?>" data-max="<?php echo esc_attr( $bounty_max ); ?>">
    <div class="qibbs-drawer-header">
        <span class="qibbs-drawer-icon qibbs-drawer-icon-bounty">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                <path d="M19 5h-2V3H7v2H5c-1.1 0-2 .9-2 2v1c0 2.55 1.92 4.63 4.39 4.94A5.01 5.01 0 0 0 11 15.9V19H7v2h10v-2h-4v-3.1a5.01 5.01 0 0 0 3.61-2.96C19.08 12.63 21 10.55 21 8V7c0-1.1-.9-2-2-2zM5 8V7h2v3.82C5.84 10.4 5 9.3 5 8zm14 0c0 1.3-.84 2.4-2 2.82V7h2v1z"/>
            </svg>
        </span>
        <span class="qibbs-drawer-title"><?php esc_html_e( '悬赏设置', 'qibbs-community' ); ?></span>
        <span class="qibbs-drawer-balance">
            <?php echo esc_html( sprintf( __( '当前可用：%s %s', 'qibbs-community' ), $balance_formatted, $points_name ) ); ?>
        </span>
    </div>
    <div class="qibbs-drawer-body">
        <div class="qibbs-form-row">
            <div class="qibbs-input-group">
                <span class="qibbs-input-label"><?php esc_html_e( '悬赏金额', 'qibbs-community' ); ?></span>
                <input
                    type="number"
                    name="bounty_amount"
                    id="qibbs-bounty-amount-input"
                    class="qibbs-form-input"
                    step="0.01"
                    min="<?php echo esc_attr( $bounty_min ); ?>"
                    max="<?php echo esc_attr( $bounty_max ); ?>"
                    placeholder="<?php echo esc_attr( sprintf( __( '范围 %s ~ %s', 'qibbs-community' ), $bounty_min, $bounty_max ) ); ?>"
                >
                <span class="qibbs-input-suffix"><?php echo esc_html( $points_name ); ?></span>
            </div>
            <div class="qibbs-drawer-tips">
                <span><?php echo esc_html( sprintf( __( '采纳最佳答案后即刻结算；自主采纳期为 %d 天，超期待管理员仲裁。', 'qibbs-community' ), $bounty_days ) ); ?></span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ( $redpacket_enabled ) : ?>
<!-- 红包帖专属配置抽屉 -->
<div class="qibbs-interactive-drawer qibbs-redpacket-drawer" id="qibbs-redpacket-fields" style="display: none;" data-min-amount="<?php echo esc_attr( $packet_min_amount ); ?>" data-max-amount="<?php echo esc_attr( $packet_max_amount ); ?>" data-min-num="<?php echo esc_attr( $packet_min_num ); ?>" data-max-num="<?php echo esc_attr( $packet_max_num ); ?>">
    <div class="qibbs-drawer-header">
        <span class="qibbs-drawer-icon qibbs-drawer-icon-redpacket">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 2v3.5l-7 4.38L5 8.5V5h14zm-7 8.5 7-4.38V19H5V9.12l7 4.38z"/>
            </svg>
        </span>
        <span class="qibbs-drawer-title"><?php esc_html_e( '互动红包设置', 'qibbs-community' ); ?></span>
        <span class="qibbs-drawer-balance">
            <?php echo esc_html( sprintf( __( '当前可用：%s %s', 'qibbs-community' ), $balance_formatted, $points_name ) ); ?>
        </span>
    </div>
    <div class="qibbs-drawer-body">
        <div class="qibbs-drawer-grid">
            <div class="qibbs-input-group">
                <span class="qibbs-input-label"><?php esc_html_e( '红包总额', 'qibbs-community' ); ?></span>
                <input
                    type="number"
                    name="redpacket_amount"
                    id="qibbs-redpacket-amount-input"
                    class="qibbs-form-input"
                    step="0.01"
                    min="<?php echo esc_attr( $packet_min_amount ); ?>"
                    max="<?php echo esc_attr( $packet_max_amount ); ?>"
                    placeholder="<?php echo esc_attr( sprintf( __( '最低 %s', 'qibbs-community' ), $packet_min_amount ) ); ?>"
                >
                <span class="qibbs-input-suffix"><?php echo esc_html( $points_name ); ?></span>
            </div>

            <div class="qibbs-input-group">
                <span class="qibbs-input-label"><?php esc_html_e( '红包份数', 'qibbs-community' ); ?></span>
                <input
                    type="number"
                    name="redpacket_num"
                    id="qibbs-redpacket-num-input"
                    class="qibbs-form-input"
                    step="1"
                    min="<?php echo esc_attr( $packet_min_num ); ?>"
                    max="<?php echo esc_attr( $packet_max_num ); ?>"
                    placeholder="<?php echo esc_attr( sprintf( __( '%d ~ %d 份', 'qibbs-community' ), $packet_min_num, $packet_max_num ) ); ?>"
                >
                <span class="qibbs-input-suffix"><?php esc_html_e( '份', 'qibbs-community' ); ?></span>
            </div>

            <div class="qibbs-input-group">
                <span class="qibbs-input-label"><?php esc_html_e( '发放方式', 'qibbs-community' ); ?></span>
                <select name="redpacket_draw_type" id="qibbs-redpacket-draw-type" class="qibbs-form-select">
                    <option value="instant"><?php esc_html_e( '回帖即领（顺位先到先得）', 'qibbs-community' ); ?></option>
                    <option value="lottery"><?php esc_html_e( '回帖抽奖（候选中奖抽取）', 'qibbs-community' ); ?></option>
                </select>
            </div>

            <div class="qibbs-input-group">
                <span class="qibbs-input-label"><?php esc_html_e( '金额分配', 'qibbs-community' ); ?></span>
                <select name="redpacket_alloc_type" id="qibbs-redpacket-alloc-type" class="qibbs-form-select">
                    <option value="random"><?php esc_html_e( '拼手气随机红包', 'qibbs-community' ); ?></option>
                    <option value="equal"><?php esc_html_e( '普通等额红包', 'qibbs-community' ); ?></option>
                </select>
            </div>
        </div>
        <div class="qibbs-drawer-tips">
            <span><?php echo esc_html( sprintf( __( '红包积分将全额冻结；默认发帖人自身回复不参与抢红包（可在后台开启）；%d 小时内未领完的剩余积分由系统自动原路退还至您的账户。', 'qibbs-community' ), $packet_hours ) ); ?></span>
        </div>
    </div>
</div>
<?php endif; ?>
