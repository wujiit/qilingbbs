<?php
/**
 * 社区原生交易订单与资金履约服务
 * 负责订单创建、防并发排他锁定、支付状态原子交付与交易防重
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 社区原生订单服务
 */
class Qibbs_Order_Service {

    /**
     * 单例实例
     *
     * @var Qibbs_Order_Service|null
     */
    private static $instance = null;

    /**
     * 获取单例实例
     *
     * @return Qibbs_Order_Service
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 为购买靓号创建现金支付订单
     *
     * @param int    $user_id    User ID.
     * @param int    $cuid       Vanity number.
     * @param string $gateway_id Gateway ID ('alipay', 'wechat', 'epay').
     * @param string $client_ip  Client IP address.
     * @param string $return_url Redirect return URL.
     * @return array
     */
    public function create_vanity_order( $user_id, $cuid, $gateway_id, $client_ip = '', $return_url = '', $item_type = 'vanity_uid' ) {
        $user_id    = absint( $user_id );
        $cuid       = absint( $cuid );
        $gateway_id = sanitize_key( $gateway_id );
        $item_type  = in_array( $item_type, array( 'vanity_uid', 'c2c_vanity' ), true ) ? $item_type : 'vanity_uid';

        if ( $user_id <= 0 || $cuid <= 0 ) {
            return array(
                'success' => false,
                'message' => __( '用户或号码参数无效', 'qibbs-community' ),
            );
        }

        if ( ! class_exists( 'QilingShop_Order' ) || ! class_exists( 'QilingShop_Payment' ) ) {
            return array(
                'success' => false,
                'message' => __( '在线交易支付服务暂未开启，请联系管理员配置。', 'qibbs-community' ),
            );
        }

        $shop_gateways = QilingShop_Payment::instance()->get_enabled_gateways();
        if ( empty( $shop_gateways ) ) {
            return array(
                'success' => false,
                'message' => __( '暂无可用的在线支付通道（微信/支付宝等），请联系管理员开启。', 'qibbs-community' ),
            );
        }

        if ( ! isset( $shop_gateways[ $gateway_id ] ) ) {
            $gateway_keys = array_keys( $shop_gateways );
            $gateway_id   = reset( $gateway_keys );
        }

        global $wpdb;
        $vanity_table = Qibbs_Database::vanity_uids();
        $orders_table = Qibbs_Database::orders();

        // 校验靓号在售状态
        $vanity = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cuid, owner_id, grade, sale_price, price_cash, c2c_seller_id, c2c_price_cash, status FROM {$vanity_table} WHERE cuid = %d LIMIT 1",
                $cuid
            )
        );

        if ( 'c2c_vanity' === $item_type ) {
            if ( ! $vanity || 'c2c_listed' !== $vanity->status ) {
                return array(
                    'success' => false,
                    'message' => __( '该玩家寄售靓号已下架或已被他人抢先拍下', 'qibbs-community' ),
                );
            }

            $seller_id = (int) ( $vanity->c2c_seller_id > 0 ? $vanity->c2c_seller_id : $vanity->owner_id );
            if ( $seller_id === $user_id ) {
                return array(
                    'success' => false,
                    'message' => __( '您不能购买自己寄售的号码', 'qibbs-community' ),
                );
            }

            $amount = (float) $vanity->c2c_price_cash;
            if ( $amount <= 0 ) {
                return array(
                    'success' => false,
                    'message' => __( '卖家未设置该号码的现金售价', 'qibbs-community' ),
                );
            }
            $subject = sprintf( __( '购买玩家寄售靓号 %d', 'qibbs-community' ), $cuid );
        } else {
            if ( ! $vanity || 'in_pool' !== $vanity->status ) {
                return array(
                    'success' => false,
                    'message' => __( '该靓号已被买走或未在在售货架中', 'qibbs-community' ),
                );
            }

            // 计算订单现金金额 (优先 price_cash，其次 sale_price，再次规则估价)
            $amount = (float) $vanity->price_cash;
            if ( $amount <= 0 ) {
                $amount = (float) $vanity->sale_price;
            }
            if ( $amount <= 0 ) {
                $amount = (float) Qibbs_UID_Rule_Service::instance()->get_reference_price( $cuid );
            }
            if ( $amount <= 0 ) {
                $amount = 1.00;
            }
            $subject = sprintf( __( '购买社区靓号 %d', 'qibbs-community' ), $cuid );
        }

        // 全站统一订单标题：完全直接继承启灵商城全局固定标题（防风控拦截）
        $shop_fixed_title = function_exists( 'get_option' ) ? trim( (string) get_option( 'qilingshop_fixed_order_title', '' ) ) : '';
        if ( '' !== $shop_fixed_title ) {
            $subject = $shop_fixed_title;
        }

        // 若未传递 return_url，默认自动回到交易大厅并触发成功交付
        if ( empty( $return_url ) ) {
            $mall_url = function_exists( 'qibbs_get_vanity_mall_url' ) ? qibbs_get_vanity_mall_url() : home_url( '/' );
            $return_url = add_query_arg(
                array(
                    'view'        => 'vanity-mall',
                    'pay_success' => '1',
                    'cuid'        => $cuid,
                ),
                $mall_url
            );
        }

        // 并发防刷保护：检查是否有其他用户正在为该号码付款中 (锁定窗口期)
        $expire_minutes = max( 3, absint( qibbs_get_option( 'order_expire_minutes', 15 ) ) );
        $lock_threshold = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $expire_minutes * MINUTE_IN_SECONDS ) );

        $pending_order = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, order_sn, user_id, created_at FROM {$orders_table}
                 WHERE item_type = %s AND item_id = %d AND status = 'pending' AND created_at >= %s
                 ORDER BY id DESC LIMIT 1",
                $item_type,
                $cuid,
                $lock_threshold
            )
        );

        if ( $pending_order && (int) $pending_order->user_id !== $user_id ) {
            return array(
                'success' => false,
                'message' => sprintf( __( '该号码正在被其他用户付款抢购中（锁定中），请在 %d 分钟后刷新重试', 'qibbs-community' ), $expire_minutes ),
            );
        }

        // 若当前用户已有未过期的旧 pending 订单，标记取消并新建
        if ( $pending_order && (int) $pending_order->user_id === $user_id ) {
            $wpdb->update(
                $orders_table,
                array( 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ),
                array( 'id' => (int) $pending_order->id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        }

        // 委托启灵商城统一支付收银通道创建订单与调起支付
        $shop_order_no = QilingShop_Order::instance()->create( array(
            'user_id'        => $user_id,
            'post_id'        => 0,
            'post_title'     => $subject,
            'order_type'     => 'qibbs_vanity',
            'price_rmb'      => $amount,
            'final_price'    => $amount,
            'payment_method' => $gateway_id,
            'remark'         => wp_json_encode( array(
                'cuid'      => $cuid,
                'item_type' => $item_type,
                'user_id'   => $user_id,
            ) ),
        ) );

        if ( ! $shop_order_no ) {
            return array(
                'success' => false,
                'message' => __( '创建支付订单失败，请稍后重试', 'qibbs-community' ),
            );
        }

        $order_sn = $shop_order_no;
        $now      = current_time( 'mysql' );

        $wpdb->insert(
            $orders_table,
            array(
                'order_sn'       => $order_sn,
                'user_id'        => $user_id,
                'item_type'      => $item_type,
                'item_id'        => $cuid,
                'item_name'      => $subject,
                'amount'         => number_format( $amount, 2, '.', '' ),
                'payment_method' => 'qilingshop_' . $gateway_id,
                'status'         => 'pending',
                'client_ip'      => $client_ip ?: ( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ),
                'created_at'     => $now,
                'updated_at'     => $now,
            ),
            array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        $pay_result = QilingShop_Payment::instance()->create_payment(
            'qibbs_vanity',
            $order_sn,
            $amount,
            $gateway_id,
            array(
                'return_url' => $return_url,
            )
        );

        if ( empty( $pay_result['success'] ) ) {
            $wpdb->update(
                $orders_table,
                array( 'status' => 'cancelled', 'updated_at' => current_time( 'mysql' ) ),
                array( 'order_sn' => $order_sn ),
                array( '%s', '%s' ),
                array( '%s' )
            );

            return array(
                'success' => false,
                'message' => ! empty( $pay_result['message'] ) ? $pay_result['message'] : __( '调起支付通道失败', 'qibbs-community' ),
            );
        }

        return array_merge( $pay_result, array(
            'success'  => true,
            'order_sn' => $order_sn,
            'amount'   => $amount,
            'cuid'     => $cuid,
            'mode'     => 'qilingshop',
        ) );
    }

    /**
     * 根据订单号查询订单
     *
     * @param string $order_sn Order serial number.
     * @return object|null
     */
    public function get_order_by_sn( $order_sn ) {
        $order_sn = sanitize_text_field( (string) $order_sn );
        if ( '' === $order_sn ) {
            return null;
        }

        global $wpdb;
        $orders_table = Qibbs_Database::orders();

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$orders_table} WHERE order_sn = %s LIMIT 1", $order_sn )
        );
    }

    /**
     * 处理订单支付成功（原子事务与交付）
     *
     * @param string $order_sn    Order serial number.
     * @param string $trade_no    Gateway transaction number.
     * @param float  $paid_amount Paid amount from gateway.
     * @param string $gateway     Gateway identifier.
     * @return array{success:bool, message:string}
     */
    public function process_paid( $order_sn, $trade_no, $paid_amount, $gateway ) {
        $order_sn = sanitize_text_field( (string) $order_sn );
        $trade_no = sanitize_text_field( (string) $trade_no );
        $paid_amount = (float) $paid_amount;
        $gateway     = sanitize_key( (string) $gateway );

        if ( '' === $order_sn ) {
            return array( 'success' => false, 'message' => __( '缺少订单号', 'qibbs-community' ) );
        }

        global $wpdb;
        $orders_table = Qibbs_Database::orders();

        // 开启数据库事务防并发重复交付
        $wpdb->query( 'START TRANSACTION' );

        $order = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$orders_table} WHERE order_sn = %s FOR UPDATE", $order_sn )
        );

        if ( ! $order ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'success' => false, 'message' => __( '订单不存在', 'qibbs-community' ) );
        }

        // 幂等处理：若已成功处理过，直接返回成功
        if ( 'paid' === $order->status ) {
            $wpdb->query( 'COMMIT' );
            return array( 'success' => true, 'message' => __( '订单已被处理过', 'qibbs-community' ) );
        }

        if ( 'pending' !== $order->status ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'success' => false, 'message' => __( '订单状态非待支付', 'qibbs-community' ) );
        }

        // 金额容错校验 (允许 0.01 精度浮动)
        if ( (float) $order->amount > ( $paid_amount + 0.01 ) ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'success' => false, 'message' => __( '支付金额小于应付金额', 'qibbs-community' ) );
        }

        $now = current_time( 'mysql' );

        // 更新订单状态为已支付
        $updated = $wpdb->update(
            $orders_table,
            array(
                'status'         => 'paid',
                'trade_no'       => $trade_no,
                'payment_method' => $gateway,
                'paid_at'        => $now,
                'updated_at'     => $now,
            ),
            array( 'id' => (int) $order->id ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'success' => false, 'message' => __( '更新订单状态失败', 'qibbs-community' ) );
        }

        // 业务履约资产交割
        if ( 'vanity_uid' === $order->item_type ) {
            $cuid    = (int) $order->item_id;
            $user_id = (int) $order->user_id;

            $fulfilled = Qibbs_Vanity_Pool_Service::instance()->fulfill_vanity_order( $cuid, $user_id, $paid_amount, $order_sn );
            if ( ! $fulfilled ) {
                $wpdb->query( 'ROLLBACK' );
                return array( 'success' => false, 'message' => __( '靓号资产交割失败', 'qibbs-community' ) );
            }
        } elseif ( 'c2c_vanity' === $order->item_type ) {
            $cuid    = (int) $order->item_id;
            $user_id = (int) $order->user_id;

            $fulfilled = Qibbs_C2C_Trade_Service::instance()->fulfill_c2c_cash_order( $order_sn, $cuid, $user_id, $paid_amount, $gateway, $trade_no );
            if ( ! $fulfilled ) {
                $wpdb->query( 'ROLLBACK' );
                return array( 'success' => false, 'message' => __( 'C2C 交易过户结算失败', 'qibbs-community' ) );
            }
        }

        $wpdb->query( 'COMMIT' );

        // 发送站内消息通知
        if ( class_exists( 'Qibbs_Notification_Service' ) ) {
            $notice_content = sprintf(
                __( '恭喜！您支付的订单 %1$s 已成功到账，社区靓号 %2$d 已放入您的背包，随时可在个人中心切换佩戴！', 'qibbs-community' ),
                $order_sn,
                (int) $order->item_id
            );
            $notice_service = new Qibbs_Notification_Service();
            $notice_service->create_system_notification( (int) $order->user_id, $notice_content );
        }

        /**
         * 社区原生订单支付完成动作钩子
         *
         * @param object $order       Order row object.
         * @param string $trade_no    Gateway trade number.
         * @param string $gateway     Gateway identifier.
         */
        do_action( 'qibbs_order_paid', $order, $trade_no, $gateway );

        return array(
            'success' => true,
            'message' => __( '支付成功并已完成交付', 'qibbs-community' ),
        );
    }

    /**
     * 查询订单实时状态
     *
     * @param string $order_sn Order serial number.
     * @return array
     */
    public function query_order_status( $order_sn ) {
        $order = $this->get_order_by_sn( $order_sn );
        if ( ! $order ) {
            return array(
                'success' => false,
                'message' => __( '订单不存在', 'qibbs-community' ),
            );
        }

        return array(
            'success'   => true,
            'order_sn'  => $order->order_sn,
            'status'    => $order->status,
            'is_paid'   => ( 'paid' === $order->status ),
            'item_type' => $order->item_type,
            'item_id'   => (int) $order->item_id,
            'amount'    => (float) $order->amount,
            'paid_at'   => $order->paid_at,
        );
    }

    /**
     * 后台分页获取订单列表与详细信息
     *
     * @param array $args Filter & pagination arguments.
     * @return array{items: array, total: int, total_pages: int, page: int, per_page: int}
     */
    public function get_orders_list( $args = array() ) {
        global $wpdb;
        $orders_table = Qibbs_Database::orders();
        $users_table  = $wpdb->users;

        $page     = max( 1, absint( $args['page'] ?? 1 ) );
        $per_page = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where  = array( '1=1' );
        $params = array();

        // 状态筛选
        if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
            $where[]  = 'o.status = %s';
            $params[] = sanitize_key( $args['status'] );
        }

        // 支付方式筛选
        if ( ! empty( $args['payment_method'] ) && 'all' !== $args['payment_method'] ) {
            $where[]  = 'o.payment_method = %s';
            $params[] = sanitize_key( $args['payment_method'] );
        }

        // 业务类型筛选
        if ( ! empty( $args['item_type'] ) && 'all' !== $args['item_type'] ) {
            $where[]  = 'o.item_type = %s';
            $params[] = sanitize_key( $args['item_type'] );
        }

        // 关键词搜索（订单号、网关交易号、商品名、用户名、用户ID、靓号ID）
        if ( ! empty( $args['search'] ) ) {
            $kw = '%' . $wpdb->esc_like( sanitize_text_field( trim( $args['search'] ) ) ) . '%';
            $search_id = is_numeric( trim( $args['search'] ) ) ? absint( trim( $args['search'] ) ) : 0;
            $where[] = '(o.order_sn LIKE %s OR o.trade_no LIKE %s OR o.item_name LIKE %s OR u.user_login LIKE %s OR u.display_name LIKE %s OR o.user_id = %d OR o.item_id = %d)';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $search_id;
            $params[] = $search_id;
        }

        // 日期范围
        if ( ! empty( $args['date_start'] ) ) {
            $where[]  = 'o.created_at >= %s';
            $params[] = sanitize_text_field( $args['date_start'] ) . ' 00:00:00';
        }
        if ( ! empty( $args['date_end'] ) ) {
            $where[]  = 'o.created_at <= %s';
            $params[] = sanitize_text_field( $args['date_end'] ) . ' 23:59:59';
        }

        $where_sql = implode( ' AND ', $where );

        // 统计总数
        $count_sql = "SELECT COUNT(*) FROM {$orders_table} o LEFT JOIN {$users_table} u ON o.user_id = u.ID WHERE {$where_sql}";
        if ( ! empty( $params ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $params );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // 列表数据
        $query_sql = "SELECT o.*, u.user_login, u.display_name, u.user_email 
                      FROM {$orders_table} o 
                      LEFT JOIN {$users_table} u ON o.user_id = u.ID 
                      WHERE {$where_sql} 
                      ORDER BY o.created_at DESC 
                      LIMIT %d OFFSET %d";
        $query_params = array_merge( $params, array( $per_page, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_params ) );

        $items = array();
        $rule_service = class_exists( 'Qibbs_UID_Rule_Service' ) ? Qibbs_UID_Rule_Service::instance() : null;

        foreach ( (array) $rows as $row ) {
            $user_id = (int) $row->user_id;
            $cuid = (int) $row->item_id;
            $grade = $rule_service ? $rule_service->detect_pattern_grade( $cuid ) : 1;
            $grade_label = $rule_service ? $rule_service->get_grade_label( $grade ) : '';

            $items[] = array(
                'id'             => (int) $row->id,
                'order_sn'       => $row->order_sn,
                'user_id'        => $user_id,
                'user_login'     => $row->user_login ?: '',
                'display_name'   => $row->display_name ?: ( $user_id ? "UID:{$user_id}" : __( '访客', 'qibbs-community' ) ),
                'user_avatar'    => $user_id ? get_avatar_url( $user_id, array( 'size' => 48 ) ) : '',
                'user_cuid'      => $user_id && function_exists( 'qibbs_get_user_cuid' ) ? qibbs_get_user_cuid( $user_id ) : 0,
                'item_type'      => $row->item_type,
                'item_id'        => $cuid,
                'item_name'      => $row->item_name,
                'grade'          => $grade,
                'grade_label'    => $grade_label,
                'amount'         => (float) $row->amount,
                'payment_method' => $row->payment_method,
                'status'         => $row->status,
                'trade_no'       => $row->trade_no,
                'client_ip'      => $row->client_ip,
                'paid_at'        => $row->paid_at,
                'created_at'     => $row->created_at,
                'updated_at'     => $row->updated_at,
                'is_qilingshop'  => ( 0 === strpos( $row->order_sn, 'QLS' ) || false !== strpos( $row->payment_method, 'qilingshop' ) ),
            );
        }

        return array(
            'items'       => $items,
            'total'       => $total,
            'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
            'page'        => $page,
            'per_page'    => $per_page,
        );
    }

    /**
     * 获取订单管理看板 KPI 核心指标
     *
     * @return array
     */
    public function get_order_kpi_stats() {
        global $wpdb;
        $orders_table = Qibbs_Database::orders();
        $today_start  = current_time( 'Y-m-d' ) . ' 00:00:00';

        // 今日成交额与订单数
        $today_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS paid_count, COALESCE(SUM(amount), 0) AS sales_amount 
                FROM {$orders_table} 
                WHERE status = 'paid' AND paid_at >= %s",
                $today_start
            )
        );

        // 累计总成交额与总成单数
        $total_row = $wpdb->get_row(
            "SELECT COUNT(*) AS total_paid, COALESCE(SUM(amount), 0) AS total_sales 
            FROM {$orders_table} 
            WHERE status = 'paid'"
        );

        // 待支付/待处理数
        $pending_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$orders_table} WHERE status = 'pending'"
        );

        return array(
            'today_sales'   => (float) ( $today_row->sales_amount ?? 0 ),
            'today_orders'  => (int) ( $today_row->paid_count ?? 0 ),
            'total_sales'   => (float) ( $total_row->total_sales ?? 0 ),
            'total_orders'  => (int) ( $total_row->total_paid ?? 0 ),
            'pending_count' => $pending_count,
        );
    }

    /**
     * 管理员人工补单标记已支付并执行履约交割
     *
     * @param string $order_sn Order serial number.
     * @param int    $admin_id Current admin user ID.
     * @return array
     */
    public function manual_complete_order( $order_sn, $admin_id = 0 ) {
        $order = $this->get_order_by_sn( $order_sn );
        if ( ! $order ) {
            return array( 'success' => false, 'message' => __( '订单不存在', 'qibbs-community' ) );
        }
        if ( 'paid' === $order->status ) {
            return array( 'success' => false, 'message' => __( '该订单已经是已支付状态，无需重复补单', 'qibbs-community' ) );
        }

        $trade_no = 'MANUAL_' . current_time( 'YmdHis' ) . wp_rand( 100, 999 );
        $gateway  = ! empty( $order->payment_method ) ? $order->payment_method : 'manual_admin';

        $result = $this->process_paid( $order_sn, $trade_no, (float) $order->amount, $gateway );

        if ( ! empty( $result['success'] ) ) {
            $msg = sprintf( __( '订单 %s 已人工补单成功，靓号资产已成功交付至用户背包！', 'qibbs-community' ), $order_sn );
            return array( 'success' => true, 'message' => $msg );
        }

        return $result;
    }

    /**
     * 管理员取消订单（释放锁定）
     *
     * @param string $order_sn Order serial number.
     * @return array
     */
    public function cancel_order( $order_sn, $reason = '' ) {
        global $wpdb;
        $orders_table = Qibbs_Database::orders();
        $order = $this->get_order_by_sn( $order_sn );
        if ( ! $order ) {
            return array( 'success' => false, 'message' => __( '订单不存在', 'qibbs-community' ) );
        }
        if ( 'paid' === $order->status ) {
            return array( 'success' => false, 'message' => __( '已支付完成的订单不可直接取消，请走退款/资产回收流程', 'qibbs-community' ) );
        }

        $now = current_time( 'mysql' );
        $updated = $wpdb->update(
            $orders_table,
            array(
                'status'     => 'cancelled',
                'updated_at' => $now,
            ),
            array( 'id' => (int) $order->id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return array( 'success' => false, 'message' => __( '取消订单操作失败', 'qibbs-community' ) );
        }

        return array( 'success' => true, 'message' => sprintf( __( '订单 %s 已成功标记为取消', 'qibbs-community' ), $order_sn ) );
    }

    /**
     * 管理员删除订单记录
     *
     * @param string $order_sn Order serial number.
     * @return array
     */
    public function delete_order( $order_sn ) {
        global $wpdb;
        $orders_table = Qibbs_Database::orders();
        $order = $this->get_order_by_sn( $order_sn );
        if ( ! $order ) {
            return array( 'success' => false, 'message' => __( '订单不存在', 'qibbs-community' ) );
        }
        if ( 'paid' === $order->status ) {
            return array( 'success' => false, 'message' => __( '为保证资金与资产流水可审计，不允许删除已完成支付的订单！', 'qibbs-community' ) );
        }

        $deleted = $wpdb->delete(
            $orders_table,
            array( 'id' => (int) $order->id ),
            array( '%d' )
        );

        if ( false === $deleted ) {
            return array( 'success' => false, 'message' => __( '删除订单记录失败', 'qibbs-community' ) );
        }

        return array( 'success' => true, 'message' => sprintf( __( '订单 %s 已成功删除', 'qibbs-community' ), $order_sn ) );
    }
}
