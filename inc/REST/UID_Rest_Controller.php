<?php
/**
 * 社区 UID 与靓号商城 REST API 控制器
 * 提供号码背包、佩戴切换、官方靓号商城货架检索、兑换购买与回售接口
 *
 * @package Qibbs_Community
 * @subpackage REST
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UID REST API 控制器
 */
class Qibbs_UID_Rest_Controller {

    /**
     * REST 路由命名空间
     *
     * @var string
     */
    protected $namespace = 'qibbs/v1';

    /**
     * 注册 REST API 路由
     */
    public function register_routes() {
        // 1. 获取当前用户号码背包
        register_rest_route( $this->namespace, '/uid/backpack', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_backpack' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ) );

        // 2. 切换当前佩戴的 UID
        register_rest_route( $this->namespace, '/uid/equip', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'equip' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
            'args'                => array(
                'cuid' => array(
                    'required'          => true,
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param ) && absint( $param ) > 0;
                    },
                ),
            ),
        ) );

        // 3. 靓号商城货架列表
        register_rest_route( $this->namespace, '/uid/mall', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_mall' ),
            'permission_callback' => array( $this, 'check_public_access' ),
        ) );

        // 4. 购买商城靓号
        register_rest_route( $this->namespace, '/uid/mall/buy', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'buy_mall_item' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
            'args'                => array(
                'cuid' => array(
                    'required'          => true,
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param ) && absint( $param ) > 0;
                    },
                ),
            ),
        ) );

        // 5. 回售靓号到系统池
        register_rest_route( $this->namespace, '/uid/recycle', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'recycle_item' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
            'args'                => array(
                'cuid' => array(
                    'required'          => true,
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param ) && absint( $param ) > 0;
                    },
                ),
            ),
        ) );

        // 6. 号码详情与品相规则预检
        register_rest_route( $this->namespace, '/uid/check/(?P<cuid>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'check_uid' ),
            'permission_callback' => array( $this, 'check_public_access' ),
        ) );

        // 7. 社区靓号交易流水与成交记录
        register_rest_route( $this->namespace, '/uid/trade-records', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_trade_records' ),
            'permission_callback' => array( $this, 'check_public_access' ),
        ) );
    }

    /**
     * 权限检查：是否已登录
     *
     * @return bool
     */
    public function check_logged_in() {
        return is_user_logged_in();
    }

    /**
     * 权限检查：公共访问控制
     *
     * @return bool
     */
    public function check_public_access() {
        if ( function_exists( 'qibbs_require_login' ) && qibbs_require_login() ) {
            return is_user_logged_in();
        }
        return true;
    }

    /**
     * 成功响应构建
     *
     * @param mixed $data Response payload.
     * @return WP_REST_Response
     */
    private function success( $data ) {
        return new WP_REST_Response( array( 'success' => true, 'data' => $data ), 200 );
    }

    /**
     * 错误响应构建
     *
     * @param string $message Error message.
     * @param int    $code    HTTP Status code.
     * @return WP_REST_Response
     */
    private function error( $message, $code = 400 ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => $message ), $code );
    }

    /**
     * 获取当前用户号码背包
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_backpack( $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return $this->error( __( '请先登录', 'qibbs-community' ), 401 );
        }

        $active_cuid = qibbs_get_user_cuid( $user_id );
        $items       = qibbs_get_user_backpack( $user_id );

        $points_balance = null;
        if ( function_exists( 'qibbs_get_user_points_balance' ) && qibbs_is_points_integration_available() ) {
            $points_balance = qibbs_get_user_points_balance( $user_id );
        }

        return $this->success( array(
            'active_cuid'    => $active_cuid,
            'items'          => $items,
            'points_balance' => $points_balance,
            'points_name'    => function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' ),
        ) );
    }

    /**
     * 切换佩戴 UID
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function equip( $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return $this->error( __( '请先登录', 'qibbs-community' ), 401 );
        }

        $cuid   = absint( $request->get_param( 'cuid' ) );
        $result = qibbs_equip_cuid( $user_id, $cuid );

        if ( ! empty( $result['success'] ) ) {
            return $this->success( array(
                'message'     => $result['message'],
                'active_cuid' => $cuid,
            ) );
        }

        return $this->error( ! empty( $result['message'] ) ? $result['message'] : __( '佩戴失败', 'qibbs-community' ), 400 );
    }

    /**
     * 获取靓号商城货架在售列表
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_mall( $request ) {
        $page     = max( 1, absint( $request->get_param( 'page' ) ) );
        $per_page = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ) ?: 20 ) );
        $grade    = absint( $request->get_param( 'grade' ) );
        $order_by = sanitize_key( $request->get_param( 'order_by' ) ?: 'sale_price' );
        $order    = sanitize_key( $request->get_param( 'order' ) ?: 'ASC' );

        $mall_data = Qibbs_Vanity_Pool_Service::instance()->get_mall_items( array(
            'page'     => $page,
            'per_page' => $per_page,
            'grade'    => $grade,
            'order_by' => $order_by,
            'order'    => $order,
        ) );

        $user_id = get_current_user_id();
        if ( $user_id > 0 && function_exists( 'qibbs_get_user_points_balance' ) && qibbs_is_points_integration_available() ) {
            $mall_data['user_balance'] = qibbs_get_user_points_balance( $user_id );
        }
        $mall_data['has_shop_points'] = function_exists( 'qibbs_is_points_integration_available' ) && qibbs_is_points_integration_available();
        $mall_data['points_name']     = function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' );

        return $this->success( $mall_data );
    }

    /**
     * 购买商城靓号
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function buy_mall_item( $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return $this->error( __( '请先登录', 'qibbs-community' ), 401 );
        }

        $cuid   = absint( $request->get_param( 'cuid' ) );
        $result = Qibbs_Vanity_Pool_Service::instance()->purchase_vanity_number( $user_id, $cuid );

        if ( ! empty( $result['success'] ) ) {
            return $this->success( array(
                'message' => $result['message'],
                'cuid'    => $cuid,
            ) );
        }

        return $this->error( ! empty( $result['message'] ) ? $result['message'] : __( '购买失败', 'qibbs-community' ), 400 );
    }

    /**
     * 回售靓号到系统池
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function recycle_item( $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return $this->error( __( '请先登录', 'qibbs-community' ), 401 );
        }

        $cuid   = absint( $request->get_param( 'cuid' ) );
        $result = Qibbs_Vanity_Pool_Service::instance()->user_recycle_vanity( $user_id, $cuid );

        if ( ! empty( $result['success'] ) ) {
            return $this->success( array(
                'message' => $result['message'],
                'refund'  => isset( $result['refund'] ) ? $result['refund'] : 0,
            ) );
        }

        return $this->error( ! empty( $result['message'] ) ? $result['message'] : __( '回售失败', 'qibbs-community' ), 400 );
    }

    /**
     * 预检号码属性与估价
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function check_uid( $request ) {
        $cuid = absint( $request['cuid'] );
        if ( $cuid <= 0 ) {
            return $this->error( __( '号码无效', 'qibbs-community' ), 400 );
        }

        $rule_service = Qibbs_UID_Rule_Service::instance();

        $grade        = $rule_service->detect_pattern_grade( $cuid );
        $price        = $rule_service->get_reference_price( $cuid );
        $is_reserved  = $rule_service->is_reserved( $cuid );
        $is_vanity    = $rule_service->is_vanity( $cuid );
        $has_excluded = $rule_service->contains_excluded_digits( $cuid );

        $grade_labels = array( 0 => __( '普通号', 'qibbs-community' ) ) + $rule_service->get_grade_labels_map();

        // 检索该号当前资产归属状态
        global $wpdb;
        $vanity_table  = Qibbs_Database::vanity_uids();
        $members_table = Qibbs_Database::members();

        $vanity_row = $wpdb->get_row(
            $wpdb->prepare( "SELECT status, owner_id, sale_price FROM {$vanity_table} WHERE cuid = %d LIMIT 1", $cuid )
        );

        $status = 'unclaimed';
        if ( $vanity_row ) {
            $status = (string) $vanity_row->status;
            if ( $vanity_row->sale_price > 0 ) {
                $price = (int) $vanity_row->sale_price;
            }
        } else {
            $is_member = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT user_id FROM {$members_table} WHERE default_cuid = %d OR active_cuid = %d LIMIT 1", $cuid, $cuid )
            );
            if ( $is_member > 0 ) {
                $status = 'allocated_default';
            }
        }

        return $this->success( array(
            'cuid'             => $cuid,
            'grade'            => $grade,
            'grade_label'      => isset( $grade_labels[ $grade ] ) ? $grade_labels[ $grade ] : __( '普通号', 'qibbs-community' ),
            'reference_price'  => $price,
            'is_reserved'      => $is_reserved,
            'is_vanity'        => $is_vanity,
            'has_excluded'     => $has_excluded,
            'status'           => $status,
        ) );
    }

    /**
     * 获取全网成交动态或个人交易记录
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_trade_records( $request ) {
        $tab        = sanitize_key( $request->get_param( 'tab' ) ?: 'market' );
        $trade_type = sanitize_key( $request->get_param( 'trade_type' ) ?: 'all' );
        $page       = max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
        $per_page   = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );
        $offset     = ( $page - 1 ) * $per_page;

        $current_user_id = get_current_user_id();

        if ( 'mine' === $tab && $current_user_id <= 0 ) {
            return $this->error( __( '请先登录后查看个人交易记录', 'qibbs-community' ), 401 );
        }

        global $wpdb;
        $trades_table = Qibbs_Database::c2c_trades();
        $vanity_table = Qibbs_Database::vanity_uids();

        $where_clauses = array( "t.status = 'completed'" );
        $where_args    = array();

        if ( 'mine' === $tab ) {
            $where_clauses[] = "(t.seller_id = %d OR t.buyer_id = %d)";
            $where_args[]    = $current_user_id;
            $where_args[]    = $current_user_id;
        }

        if ( in_array( $trade_type, array( 'official', 'c2c', 'recycle' ), true ) ) {
            $where_clauses[] = "t.trade_type = %s";
            $where_args[]    = $trade_type;
        }

        $where_sql = implode( ' AND ', $where_clauses );

        // 统计符合条件的总记录数
        $count_sql = "SELECT COUNT(*) FROM {$trades_table} t WHERE {$where_sql}";
        if ( ! empty( $where_args ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $where_args );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // 分页获取记录（关联 vanity_uids 获取品相 grade）
        $query_sql = "SELECT t.*, v.grade 
                      FROM {$trades_table} t 
                      LEFT JOIN {$vanity_table} v ON t.cuid = v.cuid 
                      WHERE {$where_sql} 
                      ORDER BY t.created_at DESC 
                      LIMIT %d OFFSET %d";
        $query_args = array_merge( $where_args, array( $per_page, $offset ) );
        $rows       = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_args ) );

        $rule_service = class_exists( 'Qibbs_UID_Rule_Service' ) ? Qibbs_UID_Rule_Service::instance() : null;
        $items = array();

        foreach ( (array) $rows as $row ) {
            $cuid  = (int) $row->cuid;
            $grade = (int) ( $row->grade ?: ( $rule_service ? $rule_service->detect_pattern_grade( $cuid ) : 1 ) );
            $grade_label = $rule_service ? $rule_service->get_grade_label( $grade ) : sprintf( '%d级靓号', $grade );

            // 买家信息
            $buyer_id   = (int) $row->buyer_id;
            $buyer_info = array(
                'user_id' => $buyer_id,
                'name'    => __( '系统回购池', 'qibbs-community' ),
                'avatar'  => '',
                'cuid'    => 0,
            );
            if ( $buyer_id > 0 ) {
                $b_user = get_userdata( $buyer_id );
                $buyer_info['name']   = $b_user ? $b_user->display_name : sprintf( 'UID:%d', $buyer_id );
                $buyer_info['avatar'] = get_avatar_url( $buyer_id, array( 'size' => 48 ) );
                $buyer_info['cuid']   = function_exists( 'qibbs_get_user_cuid' ) ? qibbs_get_user_cuid( $buyer_id ) : 0;
            }

            // 卖家信息
            $seller_id   = (int) $row->seller_id;
            $seller_info = array(
                'user_id' => $seller_id,
                'name'    => __( '官方商城', 'qibbs-community' ),
                'avatar'  => '',
                'cuid'    => 0,
            );
            if ( $seller_id > 0 ) {
                $s_user = get_userdata( $seller_id );
                $seller_info['name']   = $s_user ? $s_user->display_name : sprintf( 'UID:%d', $seller_id );
                $seller_info['avatar'] = get_avatar_url( $seller_id, array( 'size' => 48 ) );
                $seller_info['cuid']   = function_exists( 'qibbs_get_user_cuid' ) ? qibbs_get_user_cuid( $seller_id ) : 0;
            }

            // 交易类别标签
            $trade_type_label = __( '玩家寄售', 'qibbs-community' );
            if ( 'official' === $row->trade_type ) {
                $trade_type_label = __( '官方发售', 'qibbs-community' );
            } elseif ( 'recycle' === $row->trade_type ) {
                $trade_type_label = __( '官方回收', 'qibbs-community' );
            }

            // 交易金额与币种判定
            $amount_cash   = (float) $row->amount_cash;
            $amount_points = (int) $row->amount_points;
            $is_cash       = ( 'cash' === $row->payment_type || ( $amount_cash > 0 && $amount_points <= 0 ) );

            $created_time = strtotime( $row->created_at );
            $time_ago     = $created_time ? human_time_diff( $created_time, current_time( 'timestamp' ) ) . __( '前', 'qibbs-community' ) : '';

            $items[] = array(
                'id'               => (int) $row->id,
                'trade_sn'         => $row->trade_sn,
                'order_sn'         => $row->order_sn,
                'cuid'             => $cuid,
                'grade'            => $grade,
                'grade_label'      => $grade_label,
                'trade_type'       => $row->trade_type,
                'trade_type_label' => $trade_type_label,
                'payment_type'     => $row->payment_type,
                'is_cash'          => $is_cash,
                'amount_cash'      => number_format( $amount_cash, 2, '.', '' ),
                'amount_points'    => $amount_points,
                'buyer'            => $buyer_info,
                'seller'           => $seller_info,
                'created_at'       => $row->created_at,
                'time_ago'         => $time_ago,
            );
        }

        $points_name = function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' );

        return $this->success( array(
            'items'       => $items,
            'total'       => $total,
            'total_pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
            'page'        => $page,
            'tab'         => $tab,
            'points_name' => $points_name,
        ) );
    }
}
