<?php
/**
 * 社区原生交易订单与资金流水全维管理后台视图
 * 提供全站订单多维检索、生命周期跟踪、待支付审核、异常人工补单与交付交割、订单对账与导出
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( '您没有足够的权限访问此页面。', 'qibbs-community' ) );
}

$order_service = class_exists( 'Qibbs_Order_Service' ) ? Qibbs_Order_Service::instance() : null;
if ( ! $order_service ) {
    wp_die( esc_html__( '订单服务组件未就绪。', 'qibbs-community' ) );
}

$notice      = '';
$notice_type = 'success';

// 1. 处理订单人工补单交付提交
if ( isset( $_POST['qibbs_manual_complete_order'] ) && check_admin_referer( 'qibbs_order_action_nonce', 'qibbs_order_nonce' ) ) {
    $order_sn = sanitize_text_field( wp_unslash( $_POST['target_order_sn'] ?? '' ) );
    $res      = $order_service->manual_complete_order( $order_sn, get_current_user_id() );
    $notice   = $res['message'];
    $notice_type = ! empty( $res['success'] ) ? 'success' : 'error';
}

// 2. 处理订单手动取消提交
if ( isset( $_POST['qibbs_cancel_order'] ) && check_admin_referer( 'qibbs_order_action_nonce', 'qibbs_order_nonce' ) ) {
    $order_sn = sanitize_text_field( wp_unslash( $_POST['target_order_sn'] ?? '' ) );
    $res      = $order_service->cancel_order( $order_sn );
    $notice   = $res['message'];
    $notice_type = ! empty( $res['success'] ) ? 'success' : 'error';
}

// 3. 处理订单记录删除提交
if ( isset( $_POST['qibbs_delete_order'] ) && check_admin_referer( 'qibbs_order_action_nonce', 'qibbs_order_nonce' ) ) {
    $order_sn = sanitize_text_field( wp_unslash( $_POST['target_order_sn'] ?? '' ) );
    $res      = $order_service->delete_order( $order_sn );
    $notice   = $res['message'];
    $notice_type = ! empty( $res['success'] ) ? 'success' : 'error';
}

// 4. 处理订单数据 CSV 导出
if ( isset( $_GET['action'] ) && 'export_csv' === $_GET['action'] && check_admin_referer( 'qibbs_export_orders' ) ) {
    $export_filter = array(
        'status'         => sanitize_key( $_GET['status'] ?? 'all' ),
        'payment_method' => sanitize_key( $_GET['payment_method'] ?? 'all' ),
        'item_type'      => sanitize_key( $_GET['item_type'] ?? 'all' ),
        'search'         => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
        'date_start'     => sanitize_text_field( wp_unslash( $_GET['date_start'] ?? '' ) ),
        'date_end'       => sanitize_text_field( wp_unslash( $_GET['date_end'] ?? '' ) ),
        'page'           => 1,
        'per_page'       => 1000,
    );

    $export_data = $order_service->get_orders_list( $export_filter );
    $filename    = 'qibbs_orders_' . gmdate( 'Ymd_His' ) . '.csv';

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    // 输出 UTF-8 BOM 防 Excel 中文乱码
    echo "\xEF\xBB\xBF";

    $output = fopen( 'php://output', 'w' );
    fputcsv( $output, array( '订单号', '业务类型', '标的物/靓号', '商品标题', '买家UID', '买家用户名', '实付金额', '支付网关', '订单状态', '网关交易号', '客户端IP', '创建时间', '支付时间' ) );

    foreach ( $export_data['items'] as $item ) {
        fputcsv( $output, array(
            $item['order_sn'],
            $item['item_type'],
            $item['item_id'] ? '#' . $item['item_id'] : '-',
            $item['item_name'],
            $item['user_id'],
            $item['display_name'],
            number_format( (float) $item['amount'], 2, '.', '' ),
            $item['payment_method'],
            $item['status'],
            $item['trade_no'] ?: '-',
            $item['client_ip'],
            $item['created_at'],
            $item['paid_at'] ?: '-',
        ) );
    }

    fclose( $output );
    exit;
}

// 筛选参数收集
$current_status = sanitize_key( $_GET['status'] ?? 'all' );
$current_method = sanitize_key( $_GET['payment_method'] ?? 'all' );
$current_type   = sanitize_key( $_GET['item_type'] ?? 'all' );
$search_query   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
$date_start     = sanitize_text_field( wp_unslash( $_GET['date_start'] ?? '' ) );
$date_end       = sanitize_text_field( wp_unslash( $_GET['date_end'] ?? '' ) );
$paged          = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page       = max( 1, min( 100, absint( $_GET['per_page'] ?? 20 ) ) );

$filter_args = array(
    'status'         => $current_status,
    'payment_method' => $current_method,
    'item_type'      => $current_type,
    'search'         => $search_query,
    'date_start'     => $date_start,
    'date_end'       => $date_end,
    'page'           => $paged,
    'per_page'       => $per_page,
);

// 获取列表与统计数据
$order_result = $order_service->get_orders_list( $filter_args );
$orders       = $order_result['items'];
$total_orders = $order_result['total'];
$total_pages  = $order_result['total_pages'];
$kpi_stats    = $order_service->get_order_kpi_stats();

// 统计各状态数量
global $wpdb;
$orders_table = Qibbs_Database::orders();
$status_counts = array(
    'all'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders_table}" ),
    'pending'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders_table} WHERE status = 'pending'" ),
    'paid'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders_table} WHERE status = 'paid'" ),
    'cancelled' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$orders_table} WHERE status = 'cancelled'" ),
);
?>

<div class="wrap qibbs-admin-orders-wrap">
    <h1 class="wp-heading-inline">
        <?php esc_html_e( '订单交易管理', 'qibbs-community' ); ?>
    </h1>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-payment-settings' ) ); ?>" class="page-title-action" style="display: inline-flex; align-items: center; gap: 4px;">
        <span class="dashicons dashicons-admin-generic" style="font-size:15px;width:15px;height:15px;"></span><?php esc_html_e( '支付网关设置', 'qibbs-community' ); ?>
    </a>
    <hr class="wp-header-end">

    <?php if ( ! empty( $notice ) ) : ?>
        <div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible" style="margin-top:15px;">
            <p><?php echo esc_html( $notice ); ?></p>
        </div>
    <?php endif; ?>

    <!-- ==================== 1. 核心数据看板 (KPI Overview) ==================== -->
    <div class="qibbs-kpi-grid">
        <div class="qibbs-kpi-card is-primary">
            <div class="qibbs-kpi-icon">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                    <path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/>
                </svg>
            </div>
            <div class="qibbs-kpi-body">
                <span class="qibbs-kpi-title"><?php esc_html_e( '今日成交总额', 'qibbs-community' ); ?></span>
                <div class="qibbs-kpi-val">
                    <small>¥</small><?php echo esc_html( number_format( $kpi_stats['today_sales'], 2 ) ); ?>
                </div>
                <span class="qibbs-kpi-sub"><?php echo esc_html( sprintf( __( '今日已履约成交 %d 笔', 'qibbs-community' ), $kpi_stats['today_orders'] ) ); ?></span>
            </div>
        </div>

        <div class="qibbs-kpi-card is-success">
            <div class="qibbs-kpi-icon">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                    <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                </svg>
            </div>
            <div class="qibbs-kpi-body">
                <span class="qibbs-kpi-title"><?php esc_html_e( '累计总成交额', 'qibbs-community' ); ?></span>
                <div class="qibbs-kpi-val">
                    <small>¥</small><?php echo esc_html( number_format( $kpi_stats['total_sales'], 2 ) ); ?>
                </div>
                <span class="qibbs-kpi-sub"><?php echo esc_html( sprintf( __( '累计历史成单 %d 笔', 'qibbs-community' ), $kpi_stats['total_orders'] ) ); ?></span>
            </div>
        </div>

        <div class="qibbs-kpi-card <?php echo $kpi_stats['pending_count'] > 0 ? 'is-warning' : 'is-muted'; ?>">
            <div class="qibbs-kpi-icon">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                    <path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/>
                </svg>
            </div>
            <div class="qibbs-kpi-body">
                <span class="qibbs-kpi-title"><?php esc_html_e( '待支付 / 挂起订单', 'qibbs-community' ); ?></span>
                <div class="qibbs-kpi-val">
                    <?php echo esc_html( $kpi_stats['pending_count'] ); ?><small><?php esc_html_e( '笔', 'qibbs-community' ); ?></small>
                </div>
                <span class="qibbs-kpi-sub">
                    <?php echo $kpi_stats['pending_count'] > 0 ? esc_html__( '有未付款锁定中的订单', 'qibbs-community' ) : esc_html__( '暂无挂起积压订单', 'qibbs-community' ); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- ==================== 2. 状态快捷筛选 Tab ==================== -->
    <ul class="subsubsub" style="margin: 15px 0;">
        <li class="all">
            <a href="<?php echo esc_url( add_query_arg( array( 'status' => 'all', 'paged' => 1 ) ) ); ?>" class="<?php echo 'all' === $current_status ? 'current' : ''; ?>">
                <?php esc_html_e( '全部订单', 'qibbs-community' ); ?>
                <span class="count">(<?php echo esc_html( $status_counts['all'] ); ?>)</span>
            </a> |
        </li>
        <li class="pending">
            <a href="<?php echo esc_url( add_query_arg( array( 'status' => 'pending', 'paged' => 1 ) ) ); ?>" class="<?php echo 'pending' === $current_status ? 'current' : ''; ?>">
                <?php esc_html_e( '待支付', 'qibbs-community' ); ?>
                <span class="count">(<?php echo esc_html( $status_counts['pending'] ); ?>)</span>
            </a> |
        </li>
        <li class="paid">
            <a href="<?php echo esc_url( add_query_arg( array( 'status' => 'paid', 'paged' => 1 ) ) ); ?>" class="<?php echo 'paid' === $current_status ? 'current' : ''; ?>">
                <?php esc_html_e( '已支付交付', 'qibbs-community' ); ?>
                <span class="count">(<?php echo esc_html( $status_counts['paid'] ); ?>)</span>
            </a> |
        </li>
        <li class="cancelled">
            <a href="<?php echo esc_url( add_query_arg( array( 'status' => 'cancelled', 'paged' => 1 ) ) ); ?>" class="<?php echo 'cancelled' === $current_status ? 'current' : ''; ?>">
                <?php esc_html_e( '已取消/已超时', 'qibbs-community' ); ?>
                <span class="count">(<?php echo esc_html( $status_counts['cancelled'] ); ?>)</span>
            </a>
        </li>
    </ul>

    <!-- ==================== 3. 复合筛选控制栏 ==================== -->
    <div class="qibbs-orders-filter-bar">
        <form method="get" action="">
            <input type="hidden" name="page" value="qibbs-orders">
            <?php if ( 'all' !== $current_status ) : ?>
                <input type="hidden" name="status" value="<?php echo esc_attr( $current_status ); ?>">
            <?php endif; ?>

            <div class="qibbs-filter-inputs">
                <input type="search" name="s" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( '搜索单号 / 网关流水 / 靓号 / 用户名...', 'qibbs-community' ); ?>" class="qibbs-search-box">

                <select name="item_type">
                    <option value="all"><?php esc_html_e( '全部业务标的', 'qibbs-community' ); ?></option>
                    <option value="vanity_uid" <?php selected( 'vanity_uid', $current_type ); ?>><?php esc_html_e( '官方靓号自营购买', 'qibbs-community' ); ?></option>
                    <option value="c2c_vanity" <?php selected( 'c2c_vanity', $current_type ); ?>><?php esc_html_e( 'C2C 玩家寄售交易', 'qibbs-community' ); ?></option>
                </select>

                <select name="payment_method">
                    <option value="all"><?php esc_html_e( '全部支付渠道', 'qibbs-community' ); ?></option>
                    <option value="alipay" <?php selected( 'alipay', $current_method ); ?>><?php esc_html_e( '支付宝官方直连', 'qibbs-community' ); ?></option>
                    <option value="wechat" <?php selected( 'wechat', $current_method ); ?>><?php esc_html_e( '微信官方直连', 'qibbs-community' ); ?></option>
                    <option value="epay" <?php selected( 'epay', $current_method ); ?>><?php esc_html_e( '易支付通用接口', 'qibbs-community' ); ?></option>
                    <option value="qilingshop_alipay" <?php selected( 'qilingshop_alipay', $current_method ); ?>><?php esc_html_e( '启灵商城·支付宝', 'qibbs-community' ); ?></option>
                    <option value="qilingshop_wechat" <?php selected( 'qilingshop_wechat', $current_method ); ?>><?php esc_html_e( '启灵商城·微信支付', 'qibbs-community' ); ?></option>
                </select>

                <div class="qibbs-date-range">
                    <input type="date" name="date_start" value="<?php echo esc_attr( $date_start ); ?>" title="<?php esc_attr_e( '起始日期', 'qibbs-community' ); ?>">
                    <span>~</span>
                    <input type="date" name="date_end" value="<?php echo esc_attr( $date_end ); ?>" title="<?php esc_attr_e( '截止日期', 'qibbs-community' ); ?>">
                </div>

                <button type="submit" class="button button-primary"><?php esc_html_e( '过滤筛选', 'qibbs-community' ); ?></button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-orders' ) ); ?>" class="button"><?php esc_html_e( '重置', 'qibbs-community' ); ?></a>
            </div>
        </form>

        <div class="qibbs-filter-actions">
            <?php
            $export_url = wp_nonce_url( add_query_arg( array( 'action' => 'export_csv' ) ), 'qibbs_export_orders' );
            ?>
            <a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">
                <span class="dashicons dashicons-download" style="margin-top:4px;"></span>
                <?php esc_html_e( '导出 CSV 报表', 'qibbs-community' ); ?>
            </a>
        </div>
    </div>

    <!-- ==================== 4. 订单数据列表表格 ==================== -->
    <div class="qibbs-orders-table-card">
        <table class="wp-list-table widefat striped qibbs-orders-table">
            <thead>
                <tr>
                    <th style="width:200px;"><?php esc_html_e( '订单编号 / 渠道来源', 'qibbs-community' ); ?></th>
                    <th><?php esc_html_e( '标的商品与靓号', 'qibbs-community' ); ?></th>
                    <th style="width:170px;"><?php esc_html_e( '购买买家', 'qibbs-community' ); ?></th>
                    <th style="width:110px;"><?php esc_html_e( '订单金额', 'qibbs-community' ); ?></th>
                    <th style="width:130px;"><?php esc_html_e( '支付方式', 'qibbs-community' ); ?></th>
                    <th style="width:95px;"><?php esc_html_e( '状态', 'qibbs-community' ); ?></th>
                    <th style="width:170px;"><?php esc_html_e( '网关流水号', 'qibbs-community' ); ?></th>
                    <th style="width:150px;"><?php esc_html_e( '下单时间', 'qibbs-community' ); ?></th>
                    <th style="width:140px;text-align:right;"><?php esc_html_e( '操作管理', 'qibbs-community' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $orders ) ) : ?>
                    <tr>
                        <td colspan="9" style="text-align:center;padding:40px 20px;color:#64748b;">
                            <div style="font-size:36px;margin-bottom:8px;color:#94a3b8;"><span class="dashicons dashicons-archive" style="font-size:38px;width:38px;height:38px;"></span></div>
                            <p style="margin:0;font-size:15px;"><?php esc_html_e( '没有找到符合条件的交易订单', 'qibbs-community' ); ?></p>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $orders as $order ) : ?>
                        <?php
                        $status_badges = array(
                            'paid'      => array( 'label' => __( '已支付交付', 'qibbs-community' ), 'class' => 'status-paid' ),
                            'pending'   => array( 'label' => __( '待支付', 'qibbs-community' ),     'class' => 'status-pending' ),
                            'cancelled' => array( 'label' => __( '已取消', 'qibbs-community' ),   'class' => 'status-cancelled' ),
                        );
                        $badge_info = $status_badges[ $order['status'] ] ?? array( 'label' => $order['status'], 'class' => 'status-other' );

                        // 解析网关友好名
                        $gw_name = $order['payment_method'];
                        if ( false !== strpos( $gw_name, 'qilingshop' ) ) {
                            $gw_name = str_replace( 'qilingshop_', '', $gw_name );
                            $gw_label = sprintf( __( '启灵商城·%s', 'qibbs-community' ), strtoupper( $gw_name ) );
                        } elseif ( 'alipay' === $gw_name ) {
                            $gw_label = __( '支付宝官方直连', 'qibbs-community' );
                        } elseif ( 'wechat' === $gw_name || 'wxpay' === $gw_name ) {
                            $gw_label = __( '微信支付官方', 'qibbs-community' );
                        } elseif ( 'epay' === $gw_name ) {
                            $gw_label = __( '易支付通道', 'qibbs-community' );
                        } elseif ( 'manual_admin' === $gw_name ) {
                            $gw_label = __( '管理员人工补单', 'qibbs-community' );
                        } else {
                            $gw_label = strtoupper( $gw_name ?: __( '未指定', 'qibbs-community' ) );
                        }
                        ?>
                        <tr>
                            <!-- 订单编号 -->
                            <td>
                                <div class="qibbs-order-sn-cell">
                                    <strong class="qibbs-order-sn-text"><?php echo esc_html( $order['order_sn'] ); ?></strong>
                                    <button type="button" class="qibbs-btn-copy" onclick="copyText('<?php echo esc_js( $order['order_sn'] ); ?>', this)" title="<?php esc_attr_e( '复制单号', 'qibbs-community' ); ?>">
                                        <span class="dashicons dashicons-admin-page"></span>
                                    </button>
                                </div>
                                <div class="qibbs-order-tags">
                                    <?php if ( $order['is_qilingshop'] ) : ?>
                                        <span class="qibbs-tag-shop"><?php esc_html_e( '启灵商城通道', 'qibbs-community' ); ?></span>
                                    <?php else : ?>
                                        <span class="qibbs-tag-native"><?php esc_html_e( '社区原生订单', 'qibbs-community' ); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- 标的商品 -->
                            <td>
                                <div class="qibbs-order-item-cell">
                                    <?php if ( ! empty( $order['item_id'] ) ) : ?>
                                        <div class="qibbs-order-cuid-chip">
                                            <span class="qibbs-cuid-val">#<?php echo esc_html( $order['item_id'] ); ?></span>
                                            <?php if ( ! empty( $order['grade_label'] ) ) : ?>
                                                <span class="qibbs-grade-badge"><?php echo esc_html( $order['grade_label'] ); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="qibbs-order-item-name" title="<?php echo esc_attr( $order['item_name'] ); ?>">
                                        <?php echo esc_html( $order['item_name'] ); ?>
                                    </div>
                                </div>
                            </td>

                            <!-- 购买买家 -->
                            <td>
                                <div class="qibbs-user-cell">
                                    <?php if ( ! empty( $order['user_avatar'] ) ) : ?>
                                        <img src="<?php echo esc_url( $order['user_avatar'] ); ?>" class="qibbs-user-avatar" alt="">
                                    <?php endif; ?>
                                    <div class="qibbs-user-info">
                                        <span class="qibbs-user-name"><?php echo esc_html( $order['display_name'] ); ?></span>
                                        <?php if ( ! empty( $order['user_cuid'] ) ) : ?>
                                            <span class="qibbs-user-cuid">#<?php echo esc_html( $order['user_cuid'] ); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <!-- 金额 -->
                            <td>
                                <strong class="qibbs-order-amount">
                                    ¥<?php echo esc_html( number_format( (float) $order['amount'], 2 ) ); ?>
                                </strong>
                            </td>

                            <!-- 支付方式 -->
                            <td>
                                <span class="qibbs-gw-badge"><?php echo esc_html( $gw_label ); ?></span>
                            </td>

                            <!-- 状态 -->
                            <td>
                                <span class="qibbs-status-badge <?php echo esc_attr( $badge_info['class'] ); ?>">
                                    <?php echo esc_html( $badge_info['label'] ); ?>
                                </span>
                            </td>

                            <!-- 网关流水号 -->
                            <td>
                                <?php if ( ! empty( $order['trade_no'] ) ) : ?>
                                    <div class="qibbs-trade-no-cell">
                                        <code><?php echo esc_html( $order['trade_no'] ); ?></code>
                                        <button type="button" class="qibbs-btn-copy" onclick="copyText('<?php echo esc_js( $order['trade_no'] ); ?>', this)" title="<?php esc_attr_e( '复制流水号', 'qibbs-community' ); ?>">
                                            <span class="dashicons dashicons-admin-page"></span>
                                        </button>
                                    </div>
                                <?php else : ?>
                                    <span style="color:#94a3b8;">-</span>
                                <?php endif; ?>
                            </td>

                            <!-- 下单时间 -->
                            <td>
                                <div class="qibbs-time-cell">
                                    <span><?php echo esc_html( $order['created_at'] ); ?></span>
                                    <?php if ( ! empty( $order['paid_at'] ) ) : ?>
                                        <small style="color:#10b981;" title="<?php esc_attr_e( '支付完成时间', 'qibbs-community' ); ?>">付: <?php echo esc_html( $order['paid_at'] ); ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- 操作管理 -->
                            <td style="text-align:right;">
                                <div class="qibbs-actions-cell">
                                    <button type="button" class="button button-small" onclick='showOrderDetail(<?php echo wp_json_encode( $order ); ?>)'>
                                        <?php esc_html_e( '详情', 'qibbs-community' ); ?>
                                    </button>

                                    <?php if ( 'pending' === $order['status'] ) : ?>
                                        <!-- 人工补单按钮 -->
                                        <form method="post" action="" style="display:inline-block;" onsubmit="return confirm('【人工补单警告】\n确定要将此订单强制标记为【已支付】并自动将靓号交付发放至买家背包吗？');">
                                            <?php wp_nonce_field( 'qibbs_order_action_nonce', 'qibbs_order_nonce' ); ?>
                                            <input type="hidden" name="target_order_sn" value="<?php echo esc_attr( $order['order_sn'] ); ?>">
                                            <button type="submit" name="qibbs_manual_complete_order" class="button button-small button-primary" title="<?php esc_attr_e( '网关漏单时手动补单并交付靓号', 'qibbs-community' ); ?>">
                                                <?php esc_html_e( '补单', 'qibbs-community' ); ?>
                                            </button>
                                        </form>

                                        <!-- 手动取消按钮 -->
                                        <form method="post" action="" style="display:inline-block;" onsubmit="return confirm('确定要取消此订单并释放该靓号锁定吗？');">
                                            <?php wp_nonce_field( 'qibbs_order_action_nonce', 'qibbs_order_nonce' ); ?>
                                            <input type="hidden" name="target_order_sn" value="<?php echo esc_attr( $order['order_sn'] ); ?>">
                                            <button type="submit" name="qibbs_cancel_order" class="button button-small button-link-delete">
                                                <?php esc_html_e( '取消', 'qibbs-community' ); ?>
                                            </button>
                                        </form>
                                    <?php elseif ( 'cancelled' === $order['status'] ) : ?>
                                        <!-- 删除记录按钮 -->
                                        <form method="post" action="" style="display:inline-block;" onsubmit="return confirm('确定彻底删除该已取消订单记录吗？');">
                                            <?php wp_nonce_field( 'qibbs_order_action_nonce', 'qibbs_order_nonce' ); ?>
                                            <input type="hidden" name="target_order_sn" value="<?php echo esc_attr( $order['order_sn'] ); ?>">
                                            <button type="submit" name="qibbs_delete_order" class="button button-small button-link-delete">
                                                <?php esc_html_e( '删除', 'qibbs-community' ); ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ==================== 5. 分页控制器 ==================== -->
    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num"><?php echo esc_html( sprintf( __( '共 %d 条交易订单', 'qibbs-community' ), $total_orders ) ); ?></span>
                <span class="pagination-links">
                    <?php if ( $paged > 1 ) : ?>
                        <a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1 ) ); ?>">&laquo;</a>
                        <a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">&lsaquo;</a>
                    <?php endif; ?>
                    <span class="paging-input">
                        <span class="total-pages"><?php echo esc_html( $paged ); ?> / <?php echo esc_html( $total_pages ); ?></span>
                    </span>
                    <?php if ( $paged < $total_pages ) : ?>
                        <a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>">&rsaquo;</a>
                        <a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages ) ); ?>">&raquo;</a>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ==================== 6. 订单详情查看弹窗 (Modal) ==================== -->
<div id="qibbs-order-detail-modal" class="qibbs-modal-overlay" style="display:none;">
    <div class="qibbs-modal-box">
        <div class="qibbs-modal-header">
            <h3><?php esc_html_e( '订单详细档案与履约全貌', 'qibbs-community' ); ?></h3>
            <button type="button" class="qibbs-modal-close-btn" onclick="closeOrderDetail()">&times;</button>
        </div>
        <div class="qibbs-modal-body" id="qibbs-order-modal-content">
            <!-- 动态填充 -->
        </div>
        <div class="qibbs-modal-footer">
            <button type="button" class="button button-secondary" onclick="closeOrderDetail()"><?php esc_html_e( '关闭', 'qibbs-community' ); ?></button>
        </div>
    </div>
</div>

<style>
/* 后台看板视觉排版与卡片样式 */
.qibbs-admin-orders-wrap {
    margin-right: 20px;
}
.qibbs-kpi-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    margin: 20px 0 15px 0;
}
.qibbs-kpi-card {
    background: #ffffff;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.qibbs-kpi-card.is-primary { border-left: 4px solid #2271b1; }
.qibbs-kpi-card.is-success { border-left: 4px solid #00a32a; }
.qibbs-kpi-card.is-warning { border-left: 4px solid #dba617; background: #fffdf5; }
.qibbs-kpi-card.is-muted   { border-left: 4px solid #c3c4c7; }

.qibbs-kpi-icon {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    background: #f0f0f1;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.qibbs-kpi-card.is-primary .qibbs-kpi-icon { background: #e7f3fd; color: #2271b1; }
.qibbs-kpi-card.is-success .qibbs-kpi-icon { background: #edfaef; color: #00a32a; }
.qibbs-kpi-card.is-warning .qibbs-kpi-icon { background: #fcf9e8; color: #dba617; }

.qibbs-kpi-title {
    font-size: 13px;
    color: #646970;
    margin-bottom: 2px;
    display: block;
}
.qibbs-kpi-val {
    font-size: 24px;
    font-weight: 700;
    color: #1d2327;
    line-height: 1.2;
}
.qibbs-kpi-val small {
    font-size: 14px;
    font-weight: normal;
    margin-right: 2px;
}
.qibbs-kpi-sub {
    font-size: 12px;
    color: #8c8f94;
    display: block;
    margin-top: 4px;
}

/* 筛选与搜索工具栏 */
.qibbs-orders-filter-bar {
    background: #ffffff;
    border: 1px solid #ccd0d4;
    border-radius: 6px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}
.qibbs-filter-inputs {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
.qibbs-search-box {
    width: 280px;
}
.qibbs-date-range {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.qibbs-date-range input[type="date"] {
    padding: 2px 6px;
    font-size: 13px;
}

/* 数据表格细节 */
.qibbs-orders-table-card {
    background: #ffffff;
    border: 1px solid #ccd0d4;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 1px 2px rgba(0,0,0,0.03);
}
.qibbs-orders-table th {
    font-weight: 600;
    background: #f6f7f7;
    padding: 10px 12px;
}
.qibbs-orders-table td {
    padding: 12px;
    vertical-align: middle;
}
.qibbs-order-sn-cell {
    display: flex;
    align-items: center;
    gap: 6px;
}
.qibbs-order-sn-text {
    font-family: monospace;
    font-size: 13px;
    color: #1d2327;
}
.qibbs-btn-copy {
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    color: #a7aaad;
    display: inline-flex;
    align-items: center;
}
.qibbs-btn-copy:hover { color: #2271b1; }
.qibbs-order-tags {
    margin-top: 4px;
}
.qibbs-tag-shop {
    display: inline-block;
    padding: 1px 6px;
    background: #e0f2fe;
    color: #0369a1;
    font-size: 11px;
    font-weight: 600;
    border-radius: 4px;
}
.qibbs-tag-native {
    display: inline-block;
    padding: 1px 6px;
    background: #f1f5f9;
    color: #475569;
    font-size: 11px;
    border-radius: 4px;
}

.qibbs-order-item-cell {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.qibbs-order-cuid-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.qibbs-cuid-val {
    font-size: 16px;
    font-weight: 800;
    color: #0f172a;
    font-family: monospace;
}
.qibbs-grade-badge {
    background: #fef3c7;
    color: #b45309;
    font-size: 11px;
    font-weight: 600;
    padding: 1px 6px;
    border-radius: 4px;
}
.qibbs-order-item-name {
    font-size: 12px;
    color: #64748b;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.qibbs-user-cell {
    display: flex;
    align-items: center;
    gap: 8px;
}
.qibbs-user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
}
.qibbs-user-info {
    display: flex;
    flex-direction: column;
}
.qibbs-user-name {
    font-size: 13px;
    font-weight: 600;
    color: #334155;
}
.qibbs-user-cuid {
    font-size: 11px;
    color: #00a32a;
    font-family: monospace;
}

.qibbs-order-amount {
    font-size: 15px;
    color: #dc2626;
    font-family: monospace;
}
.qibbs-gw-badge {
    background: #f1f5f9;
    color: #334155;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}

.qibbs-status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}
.qibbs-status-badge.status-paid { background: #edfaef; color: #00a32a; }
.qibbs-status-badge.status-pending { background: #fcf9e8; color: #b45309; }
.qibbs-status-badge.status-cancelled { background: #f0f0f1; color: #646970; }

.qibbs-trade-no-cell {
    display: flex;
    align-items: center;
    gap: 4px;
}
.qibbs-trade-no-cell code {
    background: #f6f7f7;
    padding: 2px 4px;
    font-size: 11px;
}

.qibbs-time-cell {
    font-size: 12px;
    color: #646970;
    line-height: 1.4;
}

.qibbs-actions-cell {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
}

/* 详情弹窗 */
.qibbs-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.6);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.qibbs-modal-box {
    background: #ffffff;
    width: 600px;
    max-width: 90%;
    border-radius: 8px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25);
    overflow: hidden;
}
.qibbs-modal-header {
    padding: 16px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.qibbs-modal-header h3 { margin: 0; font-size: 16px; }
.qibbs-modal-close-btn {
    background: none;
    border: none;
    font-size: 22px;
    cursor: pointer;
    color: #94a3b8;
}
.qibbs-modal-body {
    padding: 20px;
    max-height: 70vh;
    overflow-y: auto;
}
.qibbs-modal-footer {
    padding: 12px 20px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    text-align: right;
}
.qibbs-detail-grid {
    display: grid;
    grid-template-columns: 130px 1fr;
    row-gap: 12px;
    font-size: 13px;
}
.qibbs-detail-label { color: #64748b; font-weight: 500; }
.qibbs-detail-val { color: #0f172a; word-break: break-all; }

@media (max-width: 960px) {
    .qibbs-kpi-grid { grid-template-columns: 1fr; }
    .qibbs-filter-inputs { flex-direction: column; align-items: stretch; }
    .qibbs-search-box { width: 100%; }
}
</style>

<script>
function copyText(text, btn) {
    if (!navigator.clipboard) {
        var input = document.createElement('textarea');
        input.value = text;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
    } else {
        navigator.clipboard.writeText(text);
    }
    var $icon = btn.querySelector('.dashicons');
    if ($icon) {
        $icon.className = 'dashicons dashicons-yes';
        setTimeout(function() {
            $icon.className = 'dashicons dashicons-admin-page';
        }, 1500);
    }
}

function showOrderDetail(order) {
    var html = '<div class="qibbs-detail-grid">' +
        '<div class="qibbs-detail-label">订单流水号:</div>' +
        '<div class="qibbs-detail-val"><code>' + escapeHtml(order.order_sn) + '</code></div>' +
        '<div class="qibbs-detail-label">业务类别:</div>' +
        '<div class="qibbs-detail-val">' + (order.item_type === 'vanity_uid' ? '官方自营靓号发售' : 'C2C 玩家寄售交易') + '</div>' +
        '<div class="qibbs-detail-label">商品标的:</div>' +
        '<div class="qibbs-detail-val"><strong>#' + escapeHtml(order.item_id) + '</strong> (' + escapeHtml(order.item_name) + ')</div>' +
        '<div class="qibbs-detail-label">购买用户:</div>' +
        '<div class="qibbs-detail-val">' + escapeHtml(order.display_name) + ' (ID: ' + order.user_id + ')</div>' +
        '<div class="qibbs-detail-label">应付金额:</div>' +
        '<div class="qibbs-detail-val"><strong style="color:#dc2626;">¥' + parseFloat(order.amount).toFixed(2) + '</strong></div>' +
        '<div class="qibbs-detail-label">支付通道:</div>' +
        '<div class="qibbs-detail-val">' + escapeHtml(order.payment_method) + (order.is_qilingshop ? ' <span class="qibbs-tag-shop">启灵商城</span>' : '') + '</div>' +
        '<div class="qibbs-detail-label">订单当前状态:</div>' +
        '<div class="qibbs-detail-val"><strong>' + escapeHtml(order.status) + '</strong></div>' +
        '<div class="qibbs-detail-label">网关凭据/交易号:</div>' +
        '<div class="qibbs-detail-val">' + (order.trade_no ? '<code>' + escapeHtml(order.trade_no) + '</code>' : '<span style="color:#94a3b8;">暂无</span>') + '</div>' +
        '<div class="qibbs-detail-label">下单终端 IP:</div>' +
        '<div class="qibbs-detail-val">' + (order.client_ip ? escapeHtml(order.client_ip) : '未知') + '</div>' +
        '<div class="qibbs-detail-label">下单时间:</div>' +
        '<div class="qibbs-detail-val">' + escapeHtml(order.created_at) + '</div>' +
        '<div class="qibbs-detail-label">完成支付时间:</div>' +
        '<div class="qibbs-detail-val">' + (order.paid_at ? escapeHtml(order.paid_at) : '<span style="color:#dba617;">待支付</span>') + '</div>' +
        '</div>';

    document.getElementById('qibbs-order-modal-content').innerHTML = html;
    document.getElementById('qibbs-order-detail-modal').style.display = 'flex';
}

function closeOrderDetail() {
    document.getElementById('qibbs-order-detail-modal').style.display = 'none';
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>
