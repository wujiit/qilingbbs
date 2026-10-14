<?php
/**
 * 社区玩家 C2C 号码交易行与官方回收服务引擎
 * 负责玩家靓号挂牌寄售、自由过户、原子交易撮合、平台服务费抽成与官方回购变现
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * C2C 交易行与系统回收核心服务
 */
class Qibbs_C2C_Trade_Service {

    /**
     * 单例实例
     *
     * @var Qibbs_C2C_Trade_Service|null
     */
    private static $instance = null;

    /**
     * 获取单例实例
     *
     * @return Qibbs_C2C_Trade_Service
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 玩家将背包中的靓号挂牌寄售到 C2C 交易行
     *
     * @param int   $user_id      卖家用户ID.
     * @param int   $cuid         靓号号码.
     * @param float $price_cash   现金标价（人民币，可选）.
     * @param int   $price_points 积分标价（可选）.
     * @return array{success:bool, message:string}
     */
    public function list_number( $user_id, $cuid, $price_cash = 0.0, $price_points = 0 ) {
        $user_id      = absint( $user_id );
        $cuid         = absint( $cuid );
        $price_cash   = round( max( 0.0, (float) $price_cash ), 2 );
        $price_points = max( 0, absint( $price_points ) );

        if ( $user_id <= 0 || $cuid <= 0 ) {
            return array( 'success' => false, 'message' => __( '请求参数无效', 'qibbs-community' ) );
        }

        if ( ! qibbs_get_option( 'enable_c2c_trading', true ) ) {
            return array( 'success' => false, 'message' => __( '社区玩家自由交易行当前已关闭', 'qibbs-community' ) );
        }

        if ( $price_cash <= 0.0 && $price_points <= 0 ) {
            return array( 'success' => false, 'message' => __( '请至少设置现金售价或积分售价之一', 'qibbs-community' ) );
        }

        global $wpdb;
        $vanity_table  = Qibbs_Database::vanity_uids();
        $members_table = Qibbs_Database::members();

        // 检查号码当前所有权与状态
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cuid, owner_id, status FROM {$vanity_table} WHERE cuid = %d LIMIT 1",
                $cuid
            )
        );

        if ( ! $row || (int) $row->owner_id !== $user_id ) {
            return array( 'success' => false, 'message' => __( '您未持有该号码，无法挂牌寄售', 'qibbs-community' ) );
        }

        if ( 'owned' !== $row->status ) {
            return array( 'success' => false, 'message' => __( '该号码当前状态不可上架（可能已在寄售中或已被冻结）', 'qibbs-community' ) );
        }

        // 若当前用户正在佩戴该号码，自动将其主号回退为系统保底号，确保挂售期间展示安全
        $member = $wpdb->get_row(
            $wpdb->prepare( "SELECT default_cuid, active_cuid FROM {$members_table} WHERE user_id = %d LIMIT 1", $user_id )
        );

        if ( $member && (int) $member->active_cuid === $cuid ) {
            $default_cuid = (int) $member->default_cuid;
            $wpdb->update(
                $members_table,
                array(
                    'active_cuid' => $default_cuid,
                    'updated_at'  => current_time( 'mysql' ),
                ),
                array( 'user_id' => $user_id ),
                array( '%d', '%s' ),
                array( '%d' )
            );
            Qibbs_Member_Service::instance()->invalidate_user_cache( $user_id, $cuid );
        }

        $now = current_time( 'mysql' );
        $updated = $wpdb->update(
            $vanity_table,
            array(
                'status'           => 'c2c_listed',
                'c2c_seller_id'    => $user_id,
                'c2c_price_cash'   => $price_cash,
                'c2c_price_points' => $price_points,
                'c2c_listed_at'    => $now,
                'updated_at'       => $now,
            ),
            array(
                'cuid'     => $cuid,
                'owner_id' => $user_id,
                'status'   => 'owned',
            ),
            array( '%s', '%d', '%f', '%d', '%s', '%s' ),
            array( '%d', '%d', '%s' )
        );

        if ( ! $updated ) {
            return array( 'success' => false, 'message' => __( '挂牌失败，请稍后重试', 'qibbs-community' ) );
        }

        /**
         * 玩家成功挂牌寄售动作钩子
         *
         * @param int   $user_id      卖家ID.
         * @param int   $cuid         靓号.
         * @param float $price_cash   现金价.
         * @param int   $price_points 积分价.
         */
        do_action( 'qibbs_c2c_number_listed', $user_id, $cuid, $price_cash, $price_points );

        return array(
            'success' => true,
            'message' => sprintf( __( '靓号 %d 已成功挂牌寄售至交易行！', 'qibbs-community' ), $cuid ),
        );
    }

    /**
     * 玩家下架撤单（或管理员强制下架违规标价）
     *
     * @param int  $user_id  操作用户ID.
     * @param int  $cuid     靓号号码.
     * @param bool $is_admin 是否管理员强制下架.
     * @return array{success:bool, message:string}
     */
    public function delist_number( $user_id, $cuid, $is_admin = false ) {
        $user_id = absint( $user_id );
        $cuid    = absint( $cuid );

        if ( $user_id <= 0 || $cuid <= 0 ) {
            return array( 'success' => false, 'message' => __( '请求参数无效', 'qibbs-community' ) );
        }

        global $wpdb;
        $vanity_table = Qibbs_Database::vanity_uids();
        $orders_table = Qibbs_Database::orders();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cuid, owner_id, c2c_seller_id, status FROM {$vanity_table} WHERE cuid = %d LIMIT 1",
                $cuid
            )
        );

        if ( ! $row || 'c2c_listed' !== $row->status ) {
            return array( 'success' => false, 'message' => __( '未找到该挂牌记录或该号码已下架', 'qibbs-community' ) );
        }

        if ( ! $is_admin && (int) $row->owner_id !== $user_id && (int) $row->c2c_seller_id !== $user_id ) {
            return array( 'success' => false, 'message' => __( '您无权下架该号码', 'qibbs-community' ) );
        }

        // 检查是否有用户正在收银台扫码支付该号码（未超期的 pending 订单锁定）
        $expire_minutes = max( 5, (int) qibbs_get_option( 'order_expire_minutes', 15 ) );
        $active_order = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT order_sn FROM {$orders_table} 
                WHERE item_type = 'c2c_vanity' AND item_id = %d AND status = 'pending' 
                AND created_at >= %s LIMIT 1",
                $cuid,
                date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $expire_minutes * MINUTE_IN_SECONDS ) )
            )
        );

        if ( $active_order && ! $is_admin ) {
            return array(
                'success' => false,
                'message' => __( '当前有买家正在收银台结算该号码，请等待几分钟订单超时后再下架', 'qibbs-community' ),
            );
        }

        $now = current_time( 'mysql' );
        $updated = $wpdb->update(
            $vanity_table,
            array(
                'status'           => 'owned',
                'c2c_seller_id'    => 0,
                'c2c_price_cash'   => 0.00,
                'c2c_price_points' => 0,
                'c2c_listed_at'    => null,
                'updated_at'       => $now,
            ),
            array(
                'cuid'   => $cuid,
                'status' => 'c2c_listed',
            ),
            array( '%s', '%d', '%f', '%d', '%s', '%s' ),
            array( '%d', '%s' )
        );

        if ( ! $updated ) {
            return array( 'success' => false, 'message' => __( '下架失败，请稍后重试', 'qibbs-community' ) );
        }

        /**
         * 玩家靓号撤单下架动作钩子
         *
         * @param int  $user_id  卖家ID.
         * @param int  $cuid     靓号.
         * @param bool $is_admin 是否管理员操作.
         */
        do_action( 'qibbs_c2c_number_delisted', $user_id, $cuid, $is_admin );

        return array(
            'success' => true,
            'message' => sprintf( __( '靓号 %d 已成功撤单下架，安全返回您的背包！', 'qibbs-community' ), $cuid ),
        );
    }

    /**
     * 查询 C2C 寄售交易行在售货架列表
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_market_items( $args = array() ) {
        global $wpdb;
        $vanity_table = Qibbs_Database::vanity_uids();

        $defaults = array(
            'page'          => 1,
            'per_page'      => 20,
            'grade'         => 0,
            'currency_type' => 'all', // 'all', 'cash', 'points'
            'keyword'       => '',
            'order_by'      => 'c2c_listed_at',
            'order'         => 'DESC',
        );
        $params = wp_parse_args( $args, $defaults );

        $page     = max( 1, absint( $params['page'] ) );
        $per_page = min( 100, max( 1, absint( $params['per_page'] ) ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where  = array( "status = 'c2c_listed'" );
        $values = array();

        if ( ! empty( $params['grade'] ) ) {
            $where[]  = 'grade = %d';
            $values[] = absint( $params['grade'] );
        }

        if ( 'cash' === $params['currency_type'] ) {
            $where[] = 'c2c_price_cash > 0';
        } elseif ( 'points' === $params['currency_type'] ) {
            $where[] = 'c2c_price_points > 0';
        }

        if ( ! empty( $params['keyword'] ) ) {
            $kw = sanitize_text_field( $params['keyword'] );
            if ( is_numeric( $kw ) ) {
                $where[]  = 'cuid LIKE %s';
                $values[] = '%' . $wpdb->esc_like( $kw ) . '%';
            }
        }

        $where_sql = implode( ' AND ', $where );

        // 统计总数
        if ( ! empty( $values ) ) {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$vanity_table} WHERE {$where_sql}", $values )
            );
        } else {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vanity_table} WHERE {$where_sql}" );
        }

        $allowed_orders = array( 'c2c_listed_at', 'c2c_price_cash', 'c2c_price_points', 'grade', 'cuid' );
        $order_by       = in_array( strtolower( $params['order_by'] ), $allowed_orders, true ) ? strtolower( $params['order_by'] ) : 'c2c_listed_at';
        $order          = strtoupper( $params['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $limit_values = array_merge( $values, array( $offset, $per_page ) );
        $sql = "SELECT cuid, owner_id, grade, c2c_seller_id, c2c_price_cash, c2c_price_points, c2c_listed_at, transfer_count 
                FROM {$vanity_table} 
                WHERE {$where_sql} 
                ORDER BY {$order_by} {$order} 
                LIMIT %d, %d";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit_values ) );

        $grade_labels = Qibbs_UID_Rule_Service::instance()->get_grade_labels_map();

        $items = array();
        foreach ( (array) $rows as $row ) {
            $seller_id   = (int) ( $row->c2c_seller_id > 0 ? $row->c2c_seller_id : $row->owner_id );
            $seller_name = get_the_author_meta( 'display_name', $seller_id ) ?: __( '匿名玩家', 'qibbs-community' );
            $seller_cuid = Qibbs_Member_Service::instance()->get_user_cuid( $seller_id );

            $items[] = array(
                'cuid'           => (int) $row->cuid,
                'grade'          => (int) $row->grade,
                'grade_label'    => isset( $grade_labels[ $row->grade ] ) ? $grade_labels[ $row->grade ] : __( '靓号', 'qibbs-community' ),
                'price_cash'     => (float) $row->c2c_price_cash,
                'price_points'   => (int) $row->c2c_price_points,
                'seller_id'      => $seller_id,
                'seller_name'    => $seller_name,
                'seller_cuid'    => $seller_cuid,
                'seller_avatar'  => get_avatar_url( $seller_id, array( 'size' => 64 ) ),
                'transfer_count' => (int) $row->transfer_count,
                'listed_at'      => $row->c2c_listed_at,
            );
        }

        return array(
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total / $per_page ),
        );
    }

    /**
     * 买家通过社区商城积分购买玩家 C2C 寄售靓号
     *
     * @param int $buyer_id 买家用户ID.
     * @param int $cuid     目标靓号.
     * @return array{success:bool, message:string, trade_sn?:string}
     */
    public function buy_with_points( $buyer_id, $cuid ) {
        $buyer_id = absint( $buyer_id );
        $cuid     = absint( $cuid );

        if ( $buyer_id <= 0 || $cuid <= 0 ) {
            return array( 'success' => false, 'message' => __( '请求参数无效', 'qibbs-community' ) );
        }

        if ( ! qibbs_get_option( 'enable_c2c_trading', true ) ) {
            return array( 'success' => false, 'message' => __( '社区玩家交易行当前已关闭', 'qibbs-community' ) );
        }

        global $wpdb;
        $vanity_table = Qibbs_Database::vanity_uids();
        $trades_table = Qibbs_Database::c2c_trades();

        // 启动事务并获取排他行锁
        $wpdb->query( 'START TRANSACTION' );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cuid, owner_id, c2c_seller_id, c2c_price_points, status, transfer_count 
                FROM {$vanity_table} WHERE cuid = %d FOR UPDATE",
                $cuid
            )
        );

        if ( ! $row || 'c2c_listed' !== $row->status ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'success' => false, 'message' => __( '手慢了，该靓号不存在或已被其他玩家拍下', 'qibbs-community' ) );
        }

        $seller_id    = (int) ( $row->c2c_seller_id > 0 ? $row->c2c_seller_id : $row->owner_id );
        $price_points = (int) $row->c2c_price_points;

        if ( $seller_id === $buyer_id ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'success' => false, 'message' => __( '您不能购买自己寄售的号码', 'qibbs-community' ) );
        }

        if ( $price_points <= 0 ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'success' => false, 'message' => __( '卖家未开启该号码的积分交易方式', 'qibbs-community' ) );
        }

        // 积分系统状态与余额校验
        if ( ! function_exists( 'qibbs_is_points_integration_available' ) || ! qibbs_is_points_integration_available() ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'success' => false, 'message' => __( '社区积分系统尚未就绪，暂无法完成积分交易', 'qibbs-community' ) );
        }

        $balance = qibbs_get_user_points_balance( $buyer_id );
        if ( null === $balance || $balance < $price_points ) {
            $wpdb->query( 'ROLLBACK' );
            return array(
                'success' => false,
                'message' => sprintf(
                    __( '您的积分余额不足（当前: %s，所需: %s）', 'qibbs-community' ),
                    qibbs_format_points_amount( $balance ),
                    qibbs_format_points_amount( $price_points )
                ),
            );
        }

        // 计算平台抽成手续费与卖家净收益（默认 5.0%）
        $fee_rate = max( 0.0, (float) qibbs_get_option( 'c2c_fee_rate', 5.0 ) );
        $platform_fee_points = (int) floor( $price_points * ( $fee_rate / 100.0 ) );
        $seller_earnings_points = max( 0, $price_points - $platform_fee_points );

        // 扣除买家积分
        $deducted = qibbs_deduct_user_points(
            $buyer_id,
            $price_points,
            'c2c_buy_vanity_uid',
            sprintf( __( '交易行购买玩家靓号 %d', 'qibbs-community' ), $cuid ),
            $cuid
        );

        if ( ! $deducted ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'success' => false, 'message' => __( '扣除积分失败，请稍后重试', 'qibbs-community' ) );
        }

        // 结算积分给卖家
        if ( $seller_earnings_points > 0 ) {
            qibbs_add_user_points(
                $seller_id,
                $seller_earnings_points,
                'c2c_sale_vanity_uid',
                sprintf( __( '交易行售出靓号 %d（扣除平台服务费 %d 积分）', 'qibbs-community' ), $cuid, $platform_fee_points ),
                $cuid
            );
        }

        // 确保买家社区档案就绪
        Qibbs_Member_Service::instance()->get_user_cuid( $buyer_id );

        // 过户号码给买家
        $now = current_time( 'mysql' );
        $wpdb->update(
            $vanity_table,
            array(
                'owner_id'         => $buyer_id,
                'status'           => 'owned',
                'c2c_seller_id'    => 0,
                'c2c_price_cash'   => 0.00,
                'c2c_price_points' => 0,
                'c2c_listed_at'    => null,
                'obtained_at'      => $now,
                'transfer_count'   => ( (int) $row->transfer_count ) + 1,
                'updated_at'       => $now,
            ),
            array( 'cuid' => $cuid ),
            array( '%d', '%s', '%d', '%f', '%d', '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );

        // 生成交易流水号并写入 C2C 交易表
        $trade_sn = 'TR' . gmdate( 'YmdHis' ) . wp_rand( 1000, 9999 );
        $wpdb->insert(
            $trades_table,
            array(
                'trade_sn'               => $trade_sn,
                'order_sn'               => '',
                'cuid'                   => $cuid,
                'seller_id'              => $seller_id,
                'buyer_id'               => $buyer_id,
                'payment_type'           => 'points',
                'amount_cash'            => 0.00,
                'amount_points'          => $price_points,
                'fee_rate'               => $fee_rate,
                'platform_fee_cash'      => 0.00,
                'platform_fee_points'    => $platform_fee_points,
                'seller_earnings_cash'   => 0.00,
                'seller_earnings_points' => $seller_earnings_points,
                'trade_type'             => 'c2c',
                'status'                 => 'completed',
                'created_at'             => $now,
                'updated_at'             => $now,
            ),
            array( '%s', '%s', '%d', '%d', '%d', '%s', '%f', '%d', '%f', '%f', '%d', '%f', '%d', '%s', '%s', '%s', '%s' )
        );

        $wpdb->query( 'COMMIT' );

        // 发送通知消息给买家与卖家
        $this->send_trade_notifications( $trade_sn, $cuid, $seller_id, $buyer_id, 'points', $price_points, $seller_earnings_points, $platform_fee_points );

        /**
         * 玩家 C2C 交易完成动作钩子
         *
         * @param string $trade_sn     流水号.
         * @param int    $cuid         号码.
         * @param int    $seller_id    卖家.
         * @param int    $buyer_id     买家.
         * @param string $payment_type 支付类型 ('points'|'cash').
         * @param mixed  $amount       总金额/总积分.
         */
        do_action( 'qibbs_c2c_trade_completed', $trade_sn, $cuid, $seller_id, $buyer_id, 'points', $price_points );

        return array(
            'success'  => true,
            'message'  => sprintf( __( '恭喜！您已成功以 %d 积分购得靓号 %d，已存入您的背包！', 'qibbs-community' ), $price_points, $cuid ),
            'trade_sn' => $trade_sn,
        );
    }

    /**
     * 现金网关异步回调成功时，原子化交付 C2C 寄售订单并结算给卖家
     *
     * @param string $order_sn 订单号.
     * @param int    $cuid     号码.
     * @param int    $buyer_id 买家用户ID.
     * @param float  $amount   现金实付总额.
     * @param string $gateway  支付网关.
     * @param string $trade_no 网关单号.
     * @return bool
     */
    public function fulfill_c2c_cash_order( $order_sn, $cuid, $buyer_id, $amount, $gateway, $trade_no ) {
        $cuid     = absint( $cuid );
        $buyer_id = absint( $buyer_id );
        $amount   = round( (float) $amount, 2 );

        if ( $cuid <= 0 || $buyer_id <= 0 || $amount <= 0 ) {
            return false;
        }

        global $wpdb;
        $vanity_table  = Qibbs_Database::vanity_uids();
        $members_table = Qibbs_Database::members();
        $trades_table  = Qibbs_Database::c2c_trades();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cuid, owner_id, c2c_seller_id, c2c_price_cash, status, transfer_count 
                FROM {$vanity_table} WHERE cuid = %d FOR UPDATE",
                $cuid
            )
        );

        if ( ! $row || 'c2c_listed' !== $row->status ) {
            return false;
        }

        $seller_id = (int) ( $row->c2c_seller_id > 0 ? $row->c2c_seller_id : $row->owner_id );

        // 确保买卖双方社区核心记录已建档
        Qibbs_Member_Service::instance()->get_user_cuid( $seller_id );
        Qibbs_Member_Service::instance()->get_user_cuid( $buyer_id );

        // 计算平台手续费与卖家净收入（默认 5.0%）
        $fee_rate            = max( 0.0, (float) qibbs_get_option( 'c2c_fee_rate', 5.0 ) );
        $platform_fee_cash   = round( $amount * ( $fee_rate / 100.0 ), 2 );
        $seller_earnings_cash = max( 0.0, round( $amount - $platform_fee_cash, 2 ) );

        // 实时打入卖家在启灵商城的可提现余额（与商城提现中心无缝对接）
        if ( $seller_earnings_cash > 0 ) {
            // 净收益全额实时存入卖家的启灵商城【可提现余额】，社区不保留独立本地钱包
            if ( class_exists( 'QilingShop_Points' ) ) {
                QilingShop_Points::instance()->add_withdrawable_balance(
                    $seller_id,
                    $seller_earnings_cash,
                    'c2c_vanity_sale',
                    sprintf( __( '交易行售出靓号 %d（扣除平台服务费 ¥%s）', 'qibbs-community' ), $cuid, number_format( $platform_fee_cash, 2 ) ),
                    $cuid
                );
            }
        }

        // 过户号码所有权给买家
        $now = current_time( 'mysql' );
        $wpdb->update(
            $vanity_table,
            array(
                'owner_id'         => $buyer_id,
                'status'           => 'owned',
                'c2c_seller_id'    => 0,
                'c2c_price_cash'   => 0.00,
                'c2c_price_points' => 0,
                'c2c_listed_at'    => null,
                'obtained_at'      => $now,
                'transfer_count'   => ( (int) $row->transfer_count ) + 1,
                'updated_at'       => $now,
            ),
            array( 'cuid' => $cuid ),
            array( '%d', '%s', '%d', '%f', '%d', '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );

        // 写入 C2C 交易流水表
        $trade_sn = 'TR' . gmdate( 'YmdHis' ) . wp_rand( 1000, 9999 );
        $wpdb->insert(
            $trades_table,
            array(
                'trade_sn'               => $trade_sn,
                'order_sn'               => $order_sn,
                'cuid'                   => $cuid,
                'seller_id'              => $seller_id,
                'buyer_id'               => $buyer_id,
                'payment_type'           => 'cash',
                'amount_cash'            => $amount,
                'amount_points'          => 0,
                'fee_rate'               => $fee_rate,
                'platform_fee_cash'      => $platform_fee_cash,
                'platform_fee_points'    => 0,
                'seller_earnings_cash'   => $seller_earnings_cash,
                'seller_earnings_points' => 0,
                'trade_type'             => 'c2c',
                'status'                 => 'completed',
                'created_at'             => $now,
                'updated_at'             => $now,
            ),
            array( '%s', '%s', '%d', '%d', '%d', '%s', '%f', '%d', '%f', '%f', '%d', '%f', '%d', '%s', '%s', '%s', '%s' )
        );

        // 发送通知
        $this->send_trade_notifications( $trade_sn, $cuid, $seller_id, $buyer_id, 'cash', $amount, $seller_earnings_cash, $platform_fee_cash );

        /**
         * 玩家 C2C 现金交易完成动作钩子
         *
         * @param string $trade_sn     流水号.
         * @param int    $cuid         号码.
         * @param int    $seller_id    卖家.
         * @param int    $buyer_id     买家.
         * @param string $payment_type 'cash'.
         * @param float  $amount       成交现金金额.
         */
        do_action( 'qibbs_c2c_trade_completed', $trade_sn, $cuid, $seller_id, $buyer_id, 'cash', $amount );

        return true;
    }

    /**
     * 玩家将背包中闲置靓号回售给系统（官方回收变现）
     *
     * @param int $user_id 持有者用户ID.
     * @param int $cuid    靓号号码.
     * @return array{success:bool, message:string, refund_type?:string, refund_amount?:float|int}
     */
    public function recycle_to_system( $user_id, $cuid ) {
        $user_id = absint( $user_id );
        $cuid    = absint( $cuid );

        if ( $user_id <= 0 || $cuid <= 0 ) {
            return array( 'success' => false, 'message' => __( '请求参数无效', 'qibbs-community' ) );
        }

        if ( ! qibbs_get_option( 'enable_uid_recycle', true ) ) {
            return array( 'success' => false, 'message' => __( '官方靓号回收功能当前已关闭', 'qibbs-community' ) );
        }

        global $wpdb;
        $vanity_table  = Qibbs_Database::vanity_uids();
        $members_table = Qibbs_Database::members();
        $trades_table  = Qibbs_Database::c2c_trades();

        $wpdb->query( 'START TRANSACTION' );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cuid, owner_id, status, sale_price, price_cash, transfer_count 
                FROM {$vanity_table} WHERE cuid = %d FOR UPDATE",
                $cuid
            )
        );

        if ( ! $row || (int) $row->owner_id !== $user_id || 'owned' !== $row->status ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'success' => false, 'message' => __( '您未持有该号码或当前状态不可回售给系统', 'qibbs-community' ) );
        }

        // 计算回收折价退款额度
        $rebate_rate = max( 1.0, min( 100.0, (float) qibbs_get_option( 'uid_recycle_rebate_rate', 50.0 ) ) );
        $payout_type = sanitize_key( qibbs_get_option( 'uid_recycle_payout_type', 'balance' ) );

        $cash_base   = (float) $row->price_cash > 0 ? (float) $row->price_cash : (float) $row->sale_price;
        $points_base = (int) $row->sale_price > 0 ? (int) $row->sale_price : (int) $row->price_cash;

        $refund_cash   = 0.00;
        $refund_points = 0;

        if ( 'balance' === $payout_type ) {
            $refund_cash = round( $cash_base * ( $rebate_rate / 100.0 ), 2 );
            if ( $refund_cash <= 0.0 ) {
                $refund_cash = 1.00; // 最低保底回收价 1 元
            }
        } else {
            $refund_points = (int) round( $points_base * ( $rebate_rate / 100.0 ) );
            if ( $refund_points <= 0 ) {
                $refund_points = 10;
            }
        }

        // 若当前佩戴该号码，主号自动回退至保底号
        $member = $wpdb->get_row(
            $wpdb->prepare( "SELECT default_cuid, active_cuid FROM {$members_table} WHERE user_id = %d LIMIT 1", $user_id )
        );

        if ( $member && (int) $member->active_cuid === $cuid ) {
            $default_cuid = (int) $member->default_cuid;
            $wpdb->update(
                $members_table,
                array(
                    'active_cuid' => $default_cuid,
                    'updated_at'  => current_time( 'mysql' ),
                ),
                array( 'user_id' => $user_id ),
                array( '%d', '%s' ),
                array( '%d' )
            );
            Qibbs_Member_Service::instance()->invalidate_user_cache( $user_id, $cuid );
        }

        // 资金返还：社区不设立独立本地钱包，全额打入启灵商城【可提现余额】或商城积分
        if ( 'balance' === $payout_type && $refund_cash > 0 ) {
            if ( class_exists( 'QilingShop_Points' ) ) {
                QilingShop_Points::instance()->add_withdrawable_balance(
                    $user_id,
                    $refund_cash,
                    'recycle_vanity_uid',
                    sprintf( __( '官方回收靓号 %d 返还现金', 'qibbs-community' ), $cuid ),
                    $cuid
                );
            }
        } elseif ( 'points' === $payout_type && $refund_points > 0 ) {
            qibbs_add_user_points(
                $user_id,
                $refund_points,
                'recycle_vanity_uid',
                sprintf( __( '官方回收靓号 %d 返还积分', 'qibbs-community' ), $cuid ),
                $cuid
            );
        }

        // 号码回归系统货架池
        $now = current_time( 'mysql' );
        $wpdb->update(
            $vanity_table,
            array(
                'owner_id'         => 0,
                'status'           => 'in_pool',
                'c2c_seller_id'    => 0,
                'c2c_price_cash'   => 0.00,
                'c2c_price_points' => 0,
                'c2c_listed_at'    => null,
                'obtained_at'      => null,
                'updated_at'       => $now,
            ),
            array( 'cuid' => $cuid ),
            array( '%d', '%s', '%d', '%f', '%d', '%s', '%s', '%s' ),
            array( '%d' )
        );

        // 写入回收流水
        $trade_sn = 'RC' . gmdate( 'YmdHis' ) . wp_rand( 1000, 9999 );
        $wpdb->insert(
            $trades_table,
            array(
                'trade_sn'               => $trade_sn,
                'order_sn'               => '',
                'cuid'                   => $cuid,
                'seller_id'              => $user_id,
                'buyer_id'               => 0,
                'payment_type'           => $payout_type,
                'amount_cash'            => $refund_cash,
                'amount_points'          => $refund_points,
                'fee_rate'               => 0.00,
                'platform_fee_cash'      => 0.00,
                'platform_fee_points'    => 0,
                'seller_earnings_cash'   => $refund_cash,
                'seller_earnings_points' => $refund_points,
                'trade_type'             => 'recycle',
                'status'                 => 'completed',
                'created_at'             => $now,
                'updated_at'             => $now,
            ),
            array( '%s', '%s', '%d', '%d', '%d', '%s', '%f', '%d', '%f', '%f', '%d', '%f', '%d', '%s', '%s', '%s', '%s' )
        );

        $wpdb->query( 'COMMIT' );

        // 站内通知
        $points_name = function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' );
        $amount_str  = 'balance' === $payout_type
            ? '¥' . number_format( $refund_cash, 2 ) . '（启灵商城【可提现余额】）'
            : $refund_points . ' ' . $points_name . '（启灵商城积分）';

        if ( class_exists( 'Qibbs_Notification_Service' ) ) {
            Qibbs_Notification_Service::send(
                $user_id,
                'uid_recycled',
                0,
                'vanity_uid',
                $cuid,
                sprintf( __( '您已将靓号 [%d] 成功回售给系统，官方折价变现 %s 已入账！', 'qibbs-community' ), $cuid, $amount_str )
            );
        }

        do_action( 'qibbs_uid_recycled', $user_id, $cuid, $payout_type, ( 'balance' === $payout_type ? $refund_cash : $refund_points ) );

        return array(
            'success'       => true,
            'message'       => sprintf( __( '靓号 %d 已成功回售给系统！变现退款 %s 已到账。', 'qibbs-community' ), $cuid, $amount_str ),
            'refund_type'   => $payout_type,
            'refund_amount' => 'balance' === $payout_type ? $refund_cash : $refund_points,
        );
    }

    /**
     * 获取用户在启灵商城的【可提现余额】（社区不保留独立本地钱包）
     *
     * @param int $user_id User ID.
     * @return float
     */
    public function get_user_balance( $user_id ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return 0.00;
        }

        if ( function_exists( 'qibbs_get_user_withdrawable_balance' ) ) {
            return qibbs_get_user_withdrawable_balance( $user_id );
        }

        if ( class_exists( 'QilingShop_Points' ) ) {
            $user_info = QilingShop_Points::instance()->get_user_info( $user_id );
            if ( $user_info && isset( $user_info->withdrawable_balance ) ) {
                return (float) $user_info->withdrawable_balance;
            }
        }

        return 0.00;
    }

    /**
     * 发送买卖交易站内信提醒
     *
     * @param string $trade_sn  流水号.
     * @param int    $cuid      号码.
     * @param int    $seller_id 卖家.
     * @param int    $buyer_id  买家.
     * @param string $type      'cash'|'points'.
     * @param mixed  $total     总计.
     * @param mixed  $net       净收益.
     * @param mixed  $fee       手续费.
     */
    private function send_trade_notifications( $trade_sn, $cuid, $seller_id, $buyer_id, $type, $total, $net, $fee ) {
        if ( ! class_exists( 'Qibbs_Notification_Service' ) ) {
            return;
        }

        $buyer_name = get_the_author_meta( 'display_name', $buyer_id ) ?: __( '社区玩家', 'qibbs-community' );

        if ( 'cash' === $type ) {
            $total_str = '¥' . number_format( (float) $total, 2 );
            $net_str   = '¥' . number_format( (float) $net, 2 );
            $fee_str   = '¥' . number_format( (float) $fee, 2 );

            // 卖家通知
            Qibbs_Notification_Service::send(
                $seller_id,
                'c2c_sold',
                $buyer_id,
                'vanity_uid',
                $cuid,
                sprintf( __( '喜报！您的靓号 [%d] 已被玩家【%s】以 %s 拍下！扣除平台服务费 %s 后，净收益 %s 已自动转入您的可提现余额，可随时申请提现！', 'qibbs-community' ), $cuid, $buyer_name, $total_str, $fee_str, $net_str )
            );

            // 买家通知
            Qibbs_Notification_Service::send(
                $buyer_id,
                'c2c_bought',
                $seller_id,
                'vanity_uid',
                $cuid,
                sprintf( __( '恭喜！您已成功以 %s 购得玩家靓号 [%d]，号码已存入您的背包，可随时佩戴！', 'qibbs-community' ), $total_str, $cuid )
            );
        } else {
            $points_name = function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' );
            $total_str = (int) $total . ' ' . $points_name;
            $net_str   = (int) $net . ' ' . $points_name;
            $fee_str   = (int) $fee . ' ' . $points_name;

            // 卖家通知
            Qibbs_Notification_Service::send(
                $seller_id,
                'c2c_sold',
                $buyer_id,
                'vanity_uid',
                $cuid,
                sprintf( __( '喜报！您的靓号 [%d] 已被玩家【%s】以 %s 拍下！扣除平台服务费 %s 后，净入账 %s！', 'qibbs-community' ), $cuid, $buyer_name, $total_str, $fee_str, $net_str )
            );

            // 买家通知
            Qibbs_Notification_Service::send(
                $buyer_id,
                'c2c_bought',
                $seller_id,
                'vanity_uid',
                $cuid,
                sprintf( __( '恭喜！您已成功以 %s 购得玩家靓号 [%d]，号码已存入您的背包，可随时佩戴！', 'qibbs-community' ), $total_str, $cuid )
            );
        }
    }

    /**
     * 获取指定用户的 C2C 寄售列表与交易流水
     *
     * @param int $user_id User ID.
     * @return array
     */
    public function get_user_c2c_dashboard( $user_id ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return array(
                'balance'  => 0.00,
                'listings' => array(),
                'trades'   => array(),
            );
        }

        global $wpdb;
        $vanity_table = Qibbs_Database::vanity_uids();
        $trades_table = Qibbs_Database::c2c_trades();

        // 正在寄售的号码
        $listings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cuid, grade, c2c_price_cash, c2c_price_points, c2c_listed_at 
                FROM {$vanity_table} 
                WHERE (c2c_seller_id = %d OR owner_id = %d) AND status = 'c2c_listed' 
                ORDER BY c2c_listed_at DESC",
                $user_id,
                $user_id
            )
        );

        // 最近 15 笔交易记录（我作为买家或卖家）
        $trades = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT trade_sn, cuid, seller_id, buyer_id, payment_type, amount_cash, amount_points, 
                        seller_earnings_cash, seller_earnings_points, platform_fee_cash, platform_fee_points, trade_type, created_at 
                FROM {$trades_table} 
                WHERE seller_id = %d OR buyer_id = %d 
                ORDER BY created_at DESC LIMIT 15",
                $user_id,
                $user_id
            )
        );

        return array(
            'balance'  => $this->get_user_balance( $user_id ),
            'listings' => $listings ?: array(),
            'trades'   => $trades ?: array(),
        );
    }
}
