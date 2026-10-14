<?php
/**
 * 社区靓号资产池与货架管理服务
 * 负责官方靓号库存管理、商城在售货架、人工指派与回购回收
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 靓号资产与货架服务
 */
class Qibbs_Vanity_Pool_Service {

    /**
     * 单例实例
     *
     * @var Qibbs_Vanity_Pool_Service|null
     */
    private static $instance = null;

    /**
     * 获取单例实例
     *
     * @return Qibbs_Vanity_Pool_Service
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 将号码录入官方靓号池
     *
     * @param int $cuid  Vanity Number.
     * @param int $grade Grade (1-4, 0 for auto detect).
     * @param int $price Price in points (0 for auto detect).
     * @return array{success:bool, message:string}
     */
    public function add_vanity_number( $cuid, $grade = 0, $price = 0, $price_cash = 0.0 ) {
        $cuid = absint( $cuid );
        if ( $cuid <= 0 ) {
            return array(
                'success' => false,
                'message' => __( '号码必须为正整数', 'qibbs-community' ),
            );
        }

        $rule_service = Qibbs_UID_Rule_Service::instance();

        global $wpdb;
        $vanity_table  = Qibbs_Database::vanity_uids();
        $members_table = Qibbs_Database::members();

        // 检查是否已存在于靓号表
        $exists_vanity = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT cuid FROM {$vanity_table} WHERE cuid = %d LIMIT 1", $cuid )
        );
        if ( $exists_vanity > 0 ) {
            return array(
                'success' => false,
                'message' => __( '该靓号已在资产表中，请勿重复添加', 'qibbs-community' ),
            );
        }

        // 检查是否已被分配为某用户的保底号
        $exists_member = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT user_id FROM {$members_table} WHERE default_cuid = %d LIMIT 1", $cuid )
        );
        if ( $exists_member > 0 ) {
            return array(
                'success' => false,
                'message' => __( '该号码已作为普通保底号被分配，无法作为靓号上架', 'qibbs-community' ),
            );
        }

        // 自动探测品相与基准定价
        if ( $grade <= 0 ) {
            $grade = max( 1, $rule_service->detect_pattern_grade( $cuid ) );
        }
        if ( $price <= 0 ) {
            $price = $rule_service->get_reference_price( $cuid );
        }

        $price_cash = (float) $price_cash;
        if ( $price_cash <= 0 ) {
            $price_cash = (float) $price;
        }

        $now = current_time( 'mysql' );
        $inserted = $wpdb->insert(
            $vanity_table,
            array(
                'cuid'           => $cuid,
                'owner_id'       => 0,
                'grade'          => $grade,
                'status'         => 'in_pool',
                'sale_price'     => $price,
                'price_cash'     => $price_cash,
                'obtained_at'    => null,
                'transfer_count' => 0,
                'created_at'     => $now,
                'updated_at'     => $now,
            ),
            array( '%d', '%d', '%d', '%s', '%d', '%f', '%s', '%d', '%s', '%s' )
        );

        if ( false === $inserted ) {
            return array(
                'success' => false,
                'message' => __( '入库失败，请稍后重试', 'qibbs-community' ),
            );
        }

        return array(
            'success' => true,
            'message' => __( '靓号已成功录入资产货架', 'qibbs-community' ),
        );
    }

    /**
     * 管理员编辑官方在售靓号信息（品相、积分标价、现金标价）
     *
     * @param int   $cuid       Vanity Number.
     * @param int   $grade      Grade level (1-5).
     * @param int   $sale_price Price in points.
     * @param float $price_cash Price in cash.
     * @return array{success:bool, message:string}
     */
    public function update_vanity_number( $cuid, $grade, $sale_price, $price_cash = 0.0 ) {
        $cuid       = absint( $cuid );
        $grade      = max( 1, min( 5, absint( $grade ) ) );
        $sale_price = max( 0, absint( $sale_price ) );
        $price_cash = max( 0.0, (float) $price_cash );

        if ( $cuid <= 0 ) {
            return array(
                'success' => false,
                'message' => __( '号码必须为正整数', 'qibbs-community' ),
            );
        }

        global $wpdb;
        $vanity_table = Qibbs_Database::vanity_uids();

        $existing = $wpdb->get_row(
            $wpdb->prepare( "SELECT cuid, owner_id, status FROM {$vanity_table} WHERE cuid = %d LIMIT 1", $cuid )
        );

        if ( ! $existing ) {
            return array(
                'success' => false,
                'message' => __( '未找到该靓号资产记录', 'qibbs-community' ),
            );
        }

        if ( 'in_pool' !== $existing->status && 0 !== (int) $existing->owner_id ) {
            return array(
                'success' => false,
                'message' => __( '该号码已被用户持有或处于寄售状态，无法在此直接修改售价', 'qibbs-community' ),
            );
        }

        $now = current_time( 'mysql' );
        $updated = $wpdb->update(
            $vanity_table,
            array(
                'grade'      => $grade,
                'sale_price' => $sale_price,
                'price_cash' => $price_cash,
                'updated_at' => $now,
            ),
            array( 'cuid' => $cuid ),
            array( '%d', '%d', '%f', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return array(
                'success' => false,
                'message' => __( '数据库更新失败，请重试', 'qibbs-community' ),
            );
        }

        return array(
            'success' => true,
            'message' => sprintf( __( '靓号 #%d 信息已成功更新！', 'qibbs-community' ), $cuid ),
        );
    }

    /**
     * 管理员从官方资产库彻底下架/删除未售出的靓号
     *
     * @param int $cuid Vanity Number.
     * @return array{success:bool, message:string}
     */
    public function delete_vanity_number( $cuid ) {
        $cuid = absint( $cuid );
        if ( $cuid <= 0 ) {
            return array(
                'success' => false,
                'message' => __( '号码参数无效', 'qibbs-community' ),
            );
        }

        global $wpdb;
        $vanity_table = Qibbs_Database::vanity_uids();

        $existing = $wpdb->get_row(
            $wpdb->prepare( "SELECT cuid, owner_id, status FROM {$vanity_table} WHERE cuid = %d LIMIT 1", $cuid )
        );

        if ( ! $existing ) {
            return array(
                'success' => false,
                'message' => __( '未找到该靓号资产记录', 'qibbs-community' ),
            );
        }

        if ( 'in_pool' !== $existing->status || (int) $existing->owner_id > 0 ) {
            return array(
                'success' => false,
                'message' => __( '该号码已被用户持有，无法直接删除。如需收回请使用【强制收回官方池】操作。', 'qibbs-community' ),
            );
        }

        $deleted = $wpdb->delete(
            $vanity_table,
            array( 'cuid' => $cuid ),
            array( '%d' )
        );

        if ( ! $deleted ) {
            return array(
                'success' => false,
                'message' => __( '删除失败，请稍后重试', 'qibbs-community' ),
            );
        }

        return array(
            'success' => true,
            'message' => sprintf( __( '靓号 #%d 已成功从官方号池货架中移除！', 'qibbs-community' ), $cuid ),
        );
    }

    /**
     * 管理员强制回收或将号码收回系统池
     *
     * @param int    $cuid   Vanity Number.
     * @param string $status Target status ('in_pool', 'cooling', 'banned').
     * @return array{success:bool, message:string}
     */
    public function reclaim_to_pool( $cuid, $status = 'in_pool' ) {
        $cuid = absint( $cuid );
        if ( $cuid <= 0 ) {
            return array( 'success' => false, 'message' => __( '无效的号码', 'qibbs-community' ) );
        }

        global $wpdb;
        $vanity_table  = Qibbs_Database::vanity_uids();
        $members_table = Qibbs_Database::members();

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT cuid, owner_id, status FROM {$vanity_table} WHERE cuid = %d LIMIT 1", $cuid )
        );

        if ( ! $row ) {
            return array( 'success' => false, 'message' => __( '未找到该靓号资产记录', 'qibbs-community' ) );
        }

        $current_owner = (int) $row->owner_id;

        // 若当前拥有者正在佩戴该号，将其主号自动回退至其保底号
        if ( $current_owner > 0 ) {
            $member = $wpdb->get_row(
                $wpdb->prepare( "SELECT default_cuid, active_cuid FROM {$members_table} WHERE user_id = %d LIMIT 1", $current_owner )
            );

            if ( $member && (int) $member->active_cuid === $cuid ) {
                $default_cuid = (int) $member->default_cuid;
                $wpdb->update(
                    $members_table,
                    array(
                        'active_cuid' => $default_cuid,
                        'updated_at'  => current_time( 'mysql' ),
                    ),
                    array( 'user_id' => $current_owner ),
                    array( '%d', '%s' ),
                    array( '%d' )
                );
            }

            Qibbs_Member_Service::instance()->invalidate_user_cache( $current_owner, $cuid );
        }

        $wpdb->update(
            $vanity_table,
            array(
                'owner_id'    => 0,
                'status'      => sanitize_key( $status ),
                'obtained_at' => null,
                'updated_at'  => current_time( 'mysql' ),
            ),
            array( 'cuid' => $cuid ),
            array( '%d', '%s', '%s', '%s' ),
            array( '%d' )
        );

        return array(
            'success' => true,
            'message' => __( '号码已成功解除绑定并回归系统池', 'qibbs-community' ),
        );
    }

    /**
     * 将靓号指派给指定用户
     *
     * @param int $cuid    Vanity Number.
     * @param int $user_id Target User ID.
     * @return array{success:bool, message:string}
     */
    public function assign_to_user( $cuid, $user_id ) {
        $cuid    = absint( $cuid );
        $user_id = absint( $user_id );

        if ( $cuid <= 0 || $user_id <= 0 ) {
            return array( 'success' => false, 'message' => __( '无效的号码或用户 ID', 'qibbs-community' ) );
        }

        $wp_user = get_userdata( $user_id );
        if ( ! $wp_user ) {
            return array( 'success' => false, 'message' => sprintf( __( 'WordPress 用户 (ID: %d) 不存在！', 'qibbs-community' ), $user_id ) );
        }

        global $wpdb;
        $vanity_table  = Qibbs_Database::vanity_uids();
        $members_table = Qibbs_Database::members();

        // 1. 核心操作：直接更新会员的主体社区 UID（保底UID与佩戴UID均更新为该号）
        $member_service = Qibbs_Member_Service::instance();
        $update_res     = $member_service->update_user_cuid( $user_id, $cuid, true );
        if ( ! $update_res['success'] ) {
            return $update_res;
        }

        // 2. 同步登记到靓号资产表，标记归属权
        $exists_vanity = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT cuid FROM {$vanity_table} WHERE cuid = %d LIMIT 1", $cuid )
        );

        $now = current_time( 'mysql' );
        if ( $exists_vanity > 0 ) {
            $wpdb->update(
                $vanity_table,
                array(
                    'owner_id'       => $user_id,
                    'status'         => 'owned',
                    'obtained_at'    => $now,
                    'transfer_count' => 1,
                    'updated_at'     => $now,
                ),
                array( 'cuid' => $cuid ),
                array( '%d', '%s', '%s', '%d', '%s' ),
                array( '%d' )
            );
        } else {
            $rule_service = Qibbs_UID_Rule_Service::instance();
            $grade        = max( 1, $rule_service->detect_pattern_grade( $cuid ) );
            $price        = $rule_service->get_reference_price( $cuid );
            $price_cash   = $rule_service->get_reference_cash_price( $cuid );

            $wpdb->insert(
                $vanity_table,
                array(
                    'cuid'           => $cuid,
                    'owner_id'       => $user_id,
                    'grade'          => $grade,
                    'status'         => 'owned',
                    'sale_price'     => $price,
                    'price_cash'     => $price_cash,
                    'transfer_count' => 1,
                    'obtained_at'    => $now,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ),
                array( '%d', '%d', '%d', '%s', '%d', '%f', '%d', '%s', '%s', '%s' )
            );
        }

        // 3. 彻底刷新用户 UID 映射与所有前端缓存
        $member_service->invalidate_user_cache( $user_id );
        $member_service->cache_mapping( $user_id, $cuid );

        return array(
            'success' => true,
            'message' => sprintf( __( '已成功将号码 %d 直接指派给用户【%s】(ID: %d)！该用户的社区 UID 现已生效变更为：%d。', 'qibbs-community' ), $cuid, $wp_user->display_name, $user_id, $cuid ),
        );
    }

    /**
     * 获取商城上架在售的靓号列表
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_mall_items( $args = array() ) {
        global $wpdb;
        $vanity_table = Qibbs_Database::vanity_uids();

        $defaults = array(
            'page'     => 1,
            'per_page' => 20,
            'grade'    => 0,
            'order_by' => 'sale_price',
            'order'    => 'ASC',
        );
        $params = wp_parse_args( $args, $defaults );

        $page     = max( 1, absint( $params['page'] ) );
        $per_page = min( 100, max( 1, absint( $params['per_page'] ) ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where  = array( "status = 'in_pool'" );
        $values = array();

        if ( ! empty( $params['grade'] ) ) {
            $where[]  = 'grade = %d';
            $values[] = absint( $params['grade'] );
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

        $order_by = in_array( strtolower( $params['order_by'] ), array( 'cuid', 'grade', 'sale_price', 'created_at' ), true ) ? strtolower( $params['order_by'] ) : 'sale_price';
        $order    = strtoupper( $params['order'] ) === 'DESC' ? 'DESC' : 'ASC';

        $limit_values = array_merge( $values, array( $offset, $per_page ) );
        $sql = "SELECT cuid, grade, sale_price, price_cash, created_at FROM {$vanity_table} WHERE {$where_sql} ORDER BY {$order_by} {$order} LIMIT %d, %d";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit_values ) );

        $grade_labels = Qibbs_UID_Rule_Service::instance()->get_grade_labels_map();

        $items = array();
        foreach ( (array) $rows as $row ) {
            $points_price = (int) $row->sale_price;
            $cash_price   = (float) $row->price_cash > 0 ? (float) $row->price_cash : (float) ( $points_price > 0 ? $points_price : 0 );
            $items[] = array(
                'cuid'         => (int) $row->cuid,
                'grade'        => (int) $row->grade,
                'grade_label'  => isset( $grade_labels[ $row->grade ] ) ? $grade_labels[ $row->grade ] : __( '靓号', 'qibbs-community' ),
                'price'        => $points_price,
                'price_points' => $points_price,
                'price_cash'   => $cash_price,
                'created_at'   => $row->created_at,
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
     * 用户从商城购买靓号
     *
     * @param int $user_id User ID.
     * @param int $cuid    Vanity number.
     * @return array{success:bool, message:string, cuid?:int}
     */
    public function purchase_vanity_number( $user_id, $cuid ) {
        $user_id = absint( $user_id );
        $cuid    = absint( $cuid );

        if ( $user_id <= 0 || $cuid <= 0 ) {
            return array( 'success' => false, 'message' => __( '参数无效', 'qibbs-community' ) );
        }

        global $wpdb;
        $vanity_table = Qibbs_Database::vanity_uids();

        // 检查靓号是否在售
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cuid, grade, sale_price, status FROM {$vanity_table} WHERE cuid = %d LIMIT 1",
                $cuid
            )
        );

        if ( ! $row || 'in_pool' !== $row->status ) {
            return array(
                'success' => false,
                'message' => __( '该靓号不存在或已被抢购', 'qibbs-community' ),
            );
        }

        $price = max( 0, (int) $row->sale_price );

        // 积分支付校验与扣减
        if ( $price > 0 ) {
            if ( ! function_exists( 'qibbs_is_points_integration_available' ) || ! qibbs_is_points_integration_available() ) {
                return array(
                    'success' => false,
                    'message' => __( '站点尚未启用积分系统，暂无法使用积分购买', 'qibbs-community' ),
                );
            }

            $balance = qibbs_get_user_points_balance( $user_id );
            if ( null === $balance || $balance < $price ) {
                $points_name = function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' );
                return array(
                    'success' => false,
                    'message' => sprintf(
                        __( '您的%s不足（当前: %s，所需: %s）', 'qibbs-community' ),
                        $points_name,
                        qibbs_format_points_amount( (float) $balance ),
                        qibbs_format_points_amount( $price )
                    ),
                );
            }

            $deducted = qibbs_deduct_user_points(
                $user_id,
                $price,
                'buy_vanity_uid',
                sprintf( __( '购买社区靓号 %d', 'qibbs-community' ), $cuid ),
                $cuid
            );

            if ( ! $deducted ) {
                return array(
                    'success' => false,
                    'message' => __( '积分支付扣除失败，请稍后重试', 'qibbs-community' ),
                );
            }
        }

        // 确保用户社区档案已初始化
        Qibbs_Member_Service::instance()->get_user_cuid( $user_id );

        // 原子更新所有权
        $now = current_time( 'mysql' );
        $updated = $wpdb->update(
            $vanity_table,
            array(
                'owner_id'       => $user_id,
                'status'         => 'owned',
                'obtained_at'    => $now,
                'transfer_count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT transfer_count FROM {$vanity_table} WHERE cuid = %d", $cuid ) ) + 1,
                'updated_at'     => $now,
            ),
            array(
                'cuid'   => $cuid,
                'status' => 'in_pool',
            ),
            array( '%d', '%s', '%s', '%d', '%s' ),
            array( '%d', '%s' )
        );

        if ( ! $updated ) {
            // 如果并发下抢购失败且已扣款，回滚退款
            if ( $price > 0 ) {
                qibbs_add_user_points(
                    $user_id,
                    $price,
                    'buy_vanity_rollback',
                    sprintf( __( '购买社区靓号 %d 失败自动退款', 'qibbs-community' ), $cuid ),
                    $cuid
                );
            }

            return array(
                'success' => false,
                'message' => __( '手慢了，该靓号已被其他用户抢先拍下', 'qibbs-community' ),
            );
        }

        // 记录到社区交易流水总表
        $trades_table = Qibbs_Database::c2c_trades();
        $trade_sn     = 'OF' . gmdate( 'YmdHis' ) . wp_rand( 1000, 9999 );
        $wpdb->insert(
            $trades_table,
            array(
                'trade_sn'               => $trade_sn,
                'order_sn'               => '',
                'cuid'                   => $cuid,
                'seller_id'              => 0, // 0 代表官方商城
                'buyer_id'               => $user_id,
                'payment_type'           => 'points',
                'amount_cash'            => 0.00,
                'amount_points'          => $price,
                'fee_rate'               => 0.00,
                'platform_fee_cash'      => 0.00,
                'platform_fee_points'    => $price,
                'seller_earnings_cash'   => 0.00,
                'seller_earnings_points' => 0,
                'trade_type'             => 'official',
                'status'                 => 'completed',
                'created_at'             => $now,
                'updated_at'             => $now,
            ),
            array( '%s', '%s', '%d', '%d', '%d', '%s', '%f', '%d', '%f', '%f', '%d', '%f', '%d', '%s', '%s', '%s', '%s' )
        );

        /**
         * 用户成功购买社区靓号动作钩子
         *
         * @param int $user_id User ID.
         * @param int $cuid    Vanity number.
         * @param int $price   Sale price paid.
         */
        do_action( 'qibbs_uid_purchased', $user_id, $cuid, $price );

        return array(
            'success' => true,
            'message' => sprintf( __( '恭喜！靓号 %d 已成功存入您的背包', 'qibbs-community' ), $cuid ),
            'cuid'    => $cuid,
        );
    }

    /**
     * 用户将背包中的靓号回售给系统池
     *
     * @param int $user_id User ID.
     * @param int $cuid    Vanity number.
     * @return array{success:bool, message:string, refund?:int}
     */
    public function user_recycle_vanity( $user_id, $cuid ) {
        $user_id = absint( $user_id );
        $cuid    = absint( $cuid );

        if ( $user_id <= 0 || $cuid <= 0 ) {
            return array( 'success' => false, 'message' => __( '参数无效', 'qibbs-community' ) );
        }

        global $wpdb;
        $vanity_table  = Qibbs_Database::vanity_uids();
        $members_table = Qibbs_Database::members();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cuid, owner_id, status, sale_price FROM {$vanity_table} WHERE cuid = %d LIMIT 1",
                $cuid
            )
        );

        if ( ! $row || (int) $row->owner_id !== $user_id || 'owned' !== $row->status ) {
            return array(
                'success' => false,
                'message' => __( '您未持有该靓号或该号状态不可回售', 'qibbs-community' ),
            );
        }

        // 系统回购折现预计算与校验
        $rebate_rate   = (float) apply_filters( 'qibbs_uid_recycle_rebate_rate', 0.5, $cuid, $user_id );
        $refund_amount = 0;
        if ( $rebate_rate > 0 ) {
            $refund_amount = (int) round( (int) $row->sale_price * $rebate_rate );
            if ( $refund_amount > 0 && ( ! function_exists( 'qibbs_is_points_integration_available' ) || ! qibbs_is_points_integration_available() ) ) {
                return array(
                    'success' => false,
                    'message' => __( '站点尚未启用积分系统，暂无法办理靓号回收返还', 'qibbs-community' ),
                );
            }
        }

        // 若当前正佩戴该号码，自动将其主号回退为保底号
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

        // 回归系统在售池
        $wpdb->update(
            $vanity_table,
            array(
                'owner_id'    => 0,
                'status'      => 'in_pool',
                'obtained_at' => null,
                'updated_at'  => current_time( 'mysql' ),
            ),
            array( 'cuid' => $cuid ),
            array( '%d', '%s', '%s', '%s' ),
            array( '%d' )
        );

        // 系统回购折现返还
        if ( $refund_amount > 0 ) {
            qibbs_add_user_points(
                $user_id,
                $refund_amount,
                'recycle_vanity_uid',
                sprintf( __( '回售社区靓号 %d 获得积分', 'qibbs-community' ), $cuid ),
                $cuid
            );
        }

        // 记录回收流水到交易流水表
        $trades_table = Qibbs_Database::c2c_trades();
        $trade_sn     = 'RC' . gmdate( 'YmdHis' ) . wp_rand( 1000, 9999 );
        $now          = current_time( 'mysql' );
        $wpdb->insert(
            $trades_table,
            array(
                'trade_sn'               => $trade_sn,
                'order_sn'               => '',
                'cuid'                   => $cuid,
                'seller_id'              => $user_id,
                'buyer_id'               => 0, // 0 代表系统回收
                'payment_type'           => 'points',
                'amount_cash'            => 0.00,
                'amount_points'          => (int) $refund_amount,
                'fee_rate'               => 0.00,
                'platform_fee_cash'      => 0.00,
                'platform_fee_points'    => 0,
                'seller_earnings_cash'   => 0.00,
                'seller_earnings_points' => (int) $refund_amount,
                'trade_type'             => 'recycle',
                'status'                 => 'completed',
                'created_at'             => $now,
                'updated_at'             => $now,
            ),
            array( '%s', '%s', '%d', '%d', '%d', '%s', '%f', '%d', '%f', '%f', '%d', '%f', '%d', '%s', '%s', '%s', '%s' )
        );

        /**
         * 用户回售社区靓号动作钩子
         *
         * @param int $user_id       User ID.
         * @param int $cuid          Vanity number.
         * @param int $refund_amount Points refunded.
         */
        do_action( 'qibbs_uid_recycled', $user_id, $cuid, $refund_amount );

        $msg = ( $refund_amount > 0 )
            ? sprintf( __( '靓号 %d 已回售给系统，获得 %s 积分返还', 'qibbs-community' ), $cuid, qibbs_format_points_amount( $refund_amount ) )
            : sprintf( __( '靓号 %d 已成功解除绑定并回归系统池', 'qibbs-community' ), $cuid );

        return array(
            'success' => true,
            'message' => $msg,
            'refund'  => $refund_amount,
        );
    }

    /**
     * 现金订单完成支付后交付靓号至用户背包
     *
     * @param int    $cuid        Vanity number.
     * @param int    $user_id     User ID.
     * @param float  $paid_amount Amount paid.
     * @param string $order_sn    Order serial number.
     * @return bool
     */
    public function fulfill_vanity_order( $cuid, $user_id, $paid_amount, $order_sn ) {
        $cuid    = absint( $cuid );
        $user_id = absint( $user_id );
        if ( $cuid <= 0 || $user_id <= 0 ) {
            return false;
        }

        global $wpdb;
        $vanity_table = Qibbs_Database::vanity_uids();

        // 确保会员档案已就绪
        Qibbs_Member_Service::instance()->get_user_cuid( $user_id );

        $now = current_time( 'mysql' );
        $updated = $wpdb->update(
            $vanity_table,
            array(
                'owner_id'       => $user_id,
                'status'         => 'owned',
                'obtained_at'    => $now,
                'transfer_count' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT transfer_count FROM {$vanity_table} WHERE cuid = %d", $cuid ) ) + 1,
                'updated_at'     => $now,
            ),
            array( 'cuid' => $cuid ),
            array( '%d', '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return false;
        }

        Qibbs_Member_Service::instance()->invalidate_user_cache( $user_id, $cuid );

        // 记录到交易流水总表（防止重复写入）
        $trades_table = Qibbs_Database::c2c_trades();
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$trades_table} WHERE order_sn = %s LIMIT 1", $order_sn ) );
        if ( ! $existing ) {
            $trade_sn = 'OF' . gmdate( 'YmdHis' ) . wp_rand( 1000, 9999 );
            $wpdb->insert(
                $trades_table,
                array(
                    'trade_sn'               => $trade_sn,
                    'order_sn'               => $order_sn,
                    'cuid'                   => $cuid,
                    'seller_id'              => 0, // 官方发售
                    'buyer_id'               => $user_id,
                    'payment_type'           => 'cash',
                    'amount_cash'            => $paid_amount,
                    'amount_points'          => 0,
                    'fee_rate'               => 0.00,
                    'platform_fee_cash'      => $paid_amount,
                    'platform_fee_points'    => 0,
                    'seller_earnings_cash'   => 0.00,
                    'seller_earnings_points' => 0,
                    'trade_type'             => 'official',
                    'status'                 => 'completed',
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ),
                array( '%s', '%s', '%d', '%d', '%d', '%s', '%f', '%d', '%f', '%f', '%d', '%f', '%d', '%s', '%s', '%s', '%s' )
            );
        }

        /**
         * 现金支付成功完成靓号交付动作钩子
         *
         * @param int    $user_id     User ID.
         * @param int    $cuid        Vanity number.
         * @param float  $paid_amount Paid amount.
         * @param string $order_sn    Order SN.
         */
        do_action( 'qibbs_uid_order_fulfilled', $user_id, $cuid, $paid_amount, $order_sn );

        return true;
    }
}
