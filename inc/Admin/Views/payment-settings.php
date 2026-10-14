<?php
/**
 * 视图模板：会员中心与交易收银设置
 * 社区交易与启灵商城支付联动管理后台视图
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( '权限不足', 'qibbs-community' ) );
}

global $wpdb;
$orders_table = Qibbs_Database::orders();

$notice_message = '';
$notice_type    = '';

// 处理保存交易设置
if ( isset( $_POST['qibbs_save_payment_settings'] ) && check_admin_referer( 'qibbs_payment_settings_action' ) ) {
    $options = get_option( 'qibbs_options', array() );
    if ( ! is_array( $options ) ) {
        $options = array();
    }

    $options['c2c_fee_rate']         = max( 0.0, min( 100.0, (float) ( $_POST['c2c_fee_rate'] ?? 5.0 ) ) );
    $options['order_expire_minutes'] = max( 3, min( 1440, absint( $_POST['order_expire_minutes'] ?? 15 ) ) );
    unset( $options['payment_currency_symbol'], $options['fixed_order_title'] );

    if ( function_exists( 'qibbs_update_options' ) ) {
        qibbs_update_options( $options );
    } else {
        update_option( 'qibbs_options', $options );
    }

    $notice_message = __( '交易规则配置已成功保存！', 'qibbs-community' );
    $notice_type    = 'success';
}

$options = get_option( 'qibbs_options', array() );
if ( ! is_array( $options ) ) {
    $options = array();
}

$is_shop_installed = class_exists( 'QilingShop_Payment' ) && class_exists( 'QilingShop_Order' );
$shop_gateways     = $is_shop_installed ? QilingShop_Payment::instance()->get_enabled_gateways() : array();
$withdraw_admin_url = admin_url( 'admin.php?page=qilingshop-withdrawals' );
$payment_admin_url  = admin_url( 'admin.php?page=qilingshop-settings&tab=payment' );
$shop_fixed_title   = function_exists( 'get_option' ) ? trim( (string) get_option( 'qilingshop_fixed_order_title', '' ) ) : '';
$shop_withdraw_fee  = function_exists( 'get_option' ) ? floatval( get_option( 'qilingshop_withdraw_fee_rate', 0 ) ) : 0;

// 查询最新 20 笔订单
$recent_orders = $wpdb->get_results(
    "SELECT * FROM {$orders_table} ORDER BY id DESC LIMIT 20"
);
?>

<div class="wrap qibbs-admin-page">
    <h1 class="wp-heading-inline"><?php esc_html_e( '交易大厅与支付联动设置', 'qibbs-community' ); ?></h1>
    <p class="description">
        <?php esc_html_e( '启灵微社区的所有在线支付、订单履约及资金提现完全依托于【启灵商城】插件。买家现金购买靓号款项直达平台商户号，卖家提成净收益直入商城【可提现余额】，统一由商城审核打款。', 'qibbs-community' ); ?>
    </p>
    <hr class="wp-header-end">

    <?php if ( ! empty( $notice_message ) ) : ?>
        <div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible" style="margin-top:15px;">
            <p><strong><?php echo esc_html( $notice_message ); ?></strong></p>
        </div>
    <?php endif; ?>

    <!-- 1. 启灵商城底层联动状态卡片 -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:20px;margin:20px 0;">
        <!-- 商城支付网关状态 -->
        <div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo $is_shop_installed && ! empty( $shop_gateways ) ? '#00a32a' : '#d63638'; ?>;border-radius:4px;padding:16px 20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:6px;">
                    <span class="dashicons <?php echo $is_shop_installed ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" style="color:<?php echo $is_shop_installed ? '#00a32a' : '#d63638'; ?>;"></span>
                    <?php esc_html_e( '启灵商城支付网关联动', 'qibbs-community' ); ?>
                </h3>
                <span style="font-size:12px;padding:2px 8px;border-radius:10px;font-weight:600;background:<?php echo $is_shop_installed ? '#edf7ed;color:#1e4620;' : '#fdeded;color:#5f2120;'; ?>">
                    <?php echo $is_shop_installed ? esc_html__( '已连接', 'qibbs-community' ) : esc_html__( '未安装/未启用', 'qibbs-community' ); ?>
                </span>
            </div>
            <p style="color:#646970;font-size:13px;line-height:1.6;margin:0 0 12px 0;">
                <?php if ( $is_shop_installed ) : ?>
                    <?php if ( ! empty( $shop_gateways ) ) : ?>
                        <?php printf( esc_html__( '当前商城已启用 %d 个支付通道：', 'qibbs-community' ), count( $shop_gateways ) ); ?>
                        <strong style="color:#1d2327;">
                            <?php echo esc_html( implode( '、', array_column( $shop_gateways, 'name' ) ) ); ?>
                        </strong>。社区收银台已自动无缝接入。<br>
                        <span style="color:#475569;">
                            <?php esc_html_e( '统一订单商品标题：', 'qibbs-community' ); ?>
                            <strong><?php echo $shop_fixed_title ? esc_html( $shop_fixed_title ) : esc_html__( '未固定（按实际商品名称显示）', 'qibbs-community' ); ?></strong>
                            （直接继承启灵商城全局设置，无需在此重复配置）
                        </span>
                    <?php else : ?>
                        <span style="color:#d63638;"><?php esc_html_e( '商城已安装，但尚未配置或启用任何支付网关（微信/支付宝等）。', 'qibbs-community' ); ?></span>
                    <?php endif; ?>
                <?php else : ?>
                    <span style="color:#d63638;"><?php esc_html_e( '未检测到启灵商城插件。请安装并激活启灵商城以开启社区在线支付功能。', 'qibbs-community' ); ?></span>
                <?php endif; ?>
            </p>
            <div>
                <a href="<?php echo esc_url( $payment_admin_url ); ?>" class="button button-secondary" target="_blank">
                    <span class="dashicons dashicons-admin-generic" style="vertical-align:text-top;font-size:16px;"></span>
                    <?php esc_html_e( '前往商城配置微信/支付宝网关 &rarr;', 'qibbs-community' ); ?>
                </a>
            </div>
        </div>

        <!-- 商城提现审核直达 -->
        <div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:4px;padding:16px 20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <h3 style="margin:0;font-size:15px;display:flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-money-alt" style="color:#2271b1;"></span>
                    <?php esc_html_e( '全站统一提现审核中心', 'qibbs-community' ); ?>
                </h3>
                <span style="font-size:12px;padding:2px 8px;border-radius:10px;font-weight:600;background:#f0f6fc;color:#185abc;">
                    <?php esc_html_e( '统一对账', 'qibbs-community' ); ?>
                </span>
            </div>
            <p style="color:#646970;font-size:13px;line-height:1.6;margin:0 0 12px 0;">
                <?php esc_html_e( '玩家在交易大厅售出靓号的现金收入会自动存入商城的【可提现余额】。用户在个人中心发起提现（填写支付宝/微信/银行卡），管理员在商城后台一站式审核并打款。', 'qibbs-community' ); ?><br>
                <span style="color:#475569;">
                    <?php printf( esc_html__( '商城全局提现手续费率：当前为 %s%%（由商城统一设定与收取）。', 'qibbs-community' ), esc_html( $shop_withdraw_fee ) ); ?>
                </span>
            </p>
            <div>
                <a href="<?php echo esc_url( $withdraw_admin_url ); ?>" class="button button-primary" target="_blank">
                    <span class="dashicons dashicons-money-alt" style="vertical-align:text-top;font-size:16px;"></span>
                    <?php esc_html_e( '进入启灵商城提现审批管理 &rarr;', 'qibbs-community' ); ?>
                </a>
            </div>
        </div>
    </div>

    <!-- 2. 社区交易业务规则配置表单 -->
    <form method="post" action="">
        <?php wp_nonce_field( 'qibbs_payment_settings_action' ); ?>

        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;border-radius:4px;margin-top:20px;">
            <h2 style="margin-top:0;"><?php esc_html_e( '交易大厅业务规则', 'qibbs-community' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'C2C 寄售平台手续费 (%)', 'qibbs-community' ); ?></th>
                    <td>
                        <input type="number" step="0.1" min="0" max="100" name="c2c_fee_rate" value="<?php echo esc_attr( $options['c2c_fee_rate'] ?? 5.0 ); ?>" class="small-text"> %
                        <p class="description">
                            <?php esc_html_e( '玩家在交易大厅挂牌售出靓号时，社区收取的平台交易服务费抽成比例（默认 5.0%）。', 'qibbs-community' ); ?><br>
                            <?php esc_html_e( '扣除交易手续费后的净收益将自动存入卖家的启灵商城【可提现余额】中。玩家后续申请提现到银行卡/微信/支付宝时，将按启灵商城后台配置的提现手续费率另行结算提现出金费。', 'qibbs-community' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( '订单防刷抢单保护 (分钟)', 'qibbs-community' ); ?></th>
                    <td>
                        <input type="number" step="1" min="3" max="1440" name="order_expire_minutes" value="<?php echo esc_attr( $options['order_expire_minutes'] ?? 15 ); ?>" class="small-text"> <?php esc_html_e( '分钟', 'qibbs-community' ); ?>
                        <p class="description"><?php esc_html_e( '买家发起支付后，该靓号将被临时锁定，防止并发超卖。若买家超时未付，自动解除锁定。', 'qibbs-community' ); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit" style="margin-bottom:0;padding-bottom:0;">
                <button type="submit" name="qibbs_save_payment_settings" class="button button-primary button-large">
                    <?php esc_html_e( '保存交易规则', 'qibbs-community' ); ?>
                </button>
            </p>
        </div>
    </form>

    <!-- 3. 最近交易订单记录流水 -->
    <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin-top:30px;border-radius:4px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:15px;">
            <h2 style="margin:0;"><?php esc_html_e( '最近交易流水', 'qibbs-community' ); ?></h2>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-orders' ) ); ?>" class="button button-secondary" style="font-weight:600;">
                <?php esc_html_e( '进入订单管理中心 &rarr;', 'qibbs-community' ); ?>
            </a>
        </div>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th style="width:170px;"><?php esc_html_e( '订单号', 'qibbs-community' ); ?></th>
                    <th><?php esc_html_e( '商品内容', 'qibbs-community' ); ?></th>
                    <th style="width:120px;"><?php esc_html_e( '购买用户', 'qibbs-community' ); ?></th>
                    <th style="width:100px;"><?php esc_html_e( '金额', 'qibbs-community' ); ?></th>
                    <th style="width:120px;"><?php esc_html_e( '支付方式', 'qibbs-community' ); ?></th>
                    <th style="width:90px;"><?php esc_html_e( '状态', 'qibbs-community' ); ?></th>
                    <th style="width:180px;"><?php esc_html_e( '网关交易号', 'qibbs-community' ); ?></th>
                    <th style="width:160px;"><?php esc_html_e( '创建时间', 'qibbs-community' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $recent_orders ) ) : ?>
                    <tr><td colspan="8" style="text-align:center;padding:20px;"><?php esc_html_e( '暂无交易订单记录', 'qibbs-community' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $recent_orders as $ord ) : ?>
                        <?php
                        $user = get_userdata( (int) $ord->user_id );
                        $status_colors = array(
                            'paid'      => '#00a32a',
                            'pending'   => '#dba617',
                            'cancelled' => '#646970',
                        );
                        $status_labels = array(
                            'paid'      => __( '已支付', 'qibbs-community' ),
                            'pending'   => __( '待支付', 'qibbs-community' ),
                            'cancelled' => __( '已取消', 'qibbs-community' ),
                        );
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-orders&s=' . urlencode( $ord->order_sn ) ) ); ?>">
                                    <code><?php echo esc_html( $ord->order_sn ); ?></code>
                                </a>
                            </td>
                            <td><strong><?php echo esc_html( $ord->item_name ); ?></strong></td>
                            <td>
                                <?php if ( $user ) : ?>
                                    <?php echo esc_html( $user->display_name ); ?> (#<?php echo (int) $user->ID; ?>)
                                <?php else : ?>
                                    #<?php echo (int) $ord->user_id; ?>
                                <?php endif; ?>
                            </td>
                            <td><strong style="color:#d63638;"><?php echo esc_html( $options['payment_currency_symbol'] ?? '¥' ); ?><?php echo number_format( (float) $ord->amount, 2 ); ?></strong></td>
                            <td><?php echo esc_html( strtoupper( $ord->payment_method ?: '-' ) ); ?></td>
                            <td>
                                <span style="display:inline-block;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr( $status_colors[ $ord->status ] ?? '#646970' ); ?>;">
                                    <?php echo esc_html( $status_labels[ $ord->status ] ?? $ord->status ); ?>
                                </span>
                            </td>
                            <td><small><?php echo esc_html( $ord->trade_no ?: '-' ); ?></small></td>
                            <td><?php echo esc_html( $ord->created_at ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
