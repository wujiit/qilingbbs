<?php
/**
 * 社区 C2C 寄售交易行与官方回收 REST API 控制器
 *
 * @package Qibbs_Community
 * @subpackage REST
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * C2C 交易与回收 API 控制器
 */
class Qibbs_C2C_Rest_Controller extends WP_REST_Controller {

    /**
     * API 命名空间
     *
     * @var string
     */
    protected $namespace = 'qibbs/v1';

    /**
     * 基础路由
     *
     * @var string
     */
    protected $rest_base = 'c2c';

    /**
     * 注册路由
     */
    public function register_routes() {
        // 获取 C2C 寄售大厅货架列表
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/market', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_market' ),
                'permission_callback' => array( $this, 'check_public_access' ),
            ),
        ) );

        // 玩家挂牌寄售靓号
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/list', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'list_number' ),
                'permission_callback' => array( $this, 'check_user_login' ),
            ),
        ) );

        // 玩家撤单下架靓号（或管理员违规下架）
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/delist', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'delist_number' ),
                'permission_callback' => array( $this, 'check_user_login' ),
            ),
        ) );

        // 积分直购玩家 C2C 靓号
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/buy-points', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'buy_with_points' ),
                'permission_callback' => array( $this, 'check_user_login' ),
            ),
        ) );

        // 官方折价回购变现
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/recycle', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'recycle_number' ),
                'permission_callback' => array( $this, 'check_user_login' ),
            ),
        ) );

        // 查询官方回购变现预估报价
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/recycle-quote', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_recycle_quote' ),
                'permission_callback' => array( $this, 'check_user_login' ),
            ),
        ) );

        // 获取用户个人寄售与交易流水面板
        register_rest_route( $this->namespace, '/' . $this->rest_base . '/my-dashboard', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_my_dashboard' ),
                'permission_callback' => array( $this, 'check_user_login' ),
            ),
        ) );
    }

    /**
     * 权限检查：公开或根据站点登录要求
     */
    public function check_public_access() {
        if ( function_exists( 'qibbs_require_login' ) && qibbs_require_login() ) {
            return is_user_logged_in();
        }
        return true;
    }

    /**
     * 权限检查：需登录
     */
    public function check_user_login() {
        return is_user_logged_in();
    }

    /**
     * 查询 C2C 寄售大厅
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_market( $request ) {
        $service = Qibbs_C2C_Trade_Service::instance();

        $args = array(
            'page'          => $request->get_param( 'page' ) ? absint( $request->get_param( 'page' ) ) : 1,
            'per_page'      => $request->get_param( 'per_page' ) ? absint( $request->get_param( 'per_page' ) ) : 20,
            'grade'         => $request->get_param( 'grade' ) ? absint( $request->get_param( 'grade' ) ) : 0,
            'currency_type' => sanitize_key( (string) $request->get_param( 'currency_type' ) ) ?: 'all',
            'keyword'       => sanitize_text_field( (string) $request->get_param( 'keyword' ) ),
            'order_by'      => sanitize_key( (string) $request->get_param( 'order_by' ) ) ?: 'c2c_listed_at',
            'order'         => strtoupper( (string) $request->get_param( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC',
        );

        $result = $service->get_market_items( $args );

        return new WP_REST_Response( array(
            'success'   => true,
            'data'      => $result,
            'fee_rate'  => (float) qibbs_get_option( 'c2c_fee_rate', 5.0 ),
            'enabled'   => (bool) qibbs_get_option( 'enable_c2c_trading', true ),
        ), 200 );
    }

    /**
     * 挂牌寄售靓号
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function list_number( $request ) {
        $user_id      = get_current_user_id();
        $cuid         = absint( $request->get_param( 'cuid' ) );
        $price_cash   = (float) $request->get_param( 'price_cash' );
        $price_points = absint( $request->get_param( 'price_points' ) );

        $service = Qibbs_C2C_Trade_Service::instance();
        $res     = $service->list_number( $user_id, $cuid, $price_cash, $price_points );

        if ( ! $res['success'] ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => $res['message'] ), 400 );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'message' => $res['message'],
        ), 200 );
    }

    /**
     * 下架撤单靓号
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function delist_number( $request ) {
        $user_id  = get_current_user_id();
        $cuid     = absint( $request->get_param( 'cuid' ) );
        $is_admin = current_user_can( 'manage_options' );

        $service = Qibbs_C2C_Trade_Service::instance();
        $res     = $service->delist_number( $user_id, $cuid, $is_admin );

        if ( ! $res['success'] ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => $res['message'] ), 400 );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'message' => $res['message'],
        ), 200 );
    }

    /**
     * 积分购买玩家靓号
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function buy_with_points( $request ) {
        $buyer_id = get_current_user_id();
        $cuid     = absint( $request->get_param( 'cuid' ) );

        $service = Qibbs_C2C_Trade_Service::instance();
        $res     = $service->buy_with_points( $buyer_id, $cuid );

        if ( ! $res['success'] ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => $res['message'] ), 400 );
        }

        return new WP_REST_Response( array(
            'success'  => true,
            'message'  => $res['message'],
            'trade_sn' => $res['trade_sn'] ?? '',
        ), 200 );
    }

    /**
     * 官方回收变现
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function recycle_number( $request ) {
        $user_id = get_current_user_id();
        $cuid    = absint( $request->get_param( 'cuid' ) );

        $service = Qibbs_C2C_Trade_Service::instance();
        $res     = $service->recycle_to_system( $user_id, $cuid );

        if ( ! $res['success'] ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => $res['message'] ), 400 );
        }

        return new WP_REST_Response( array(
            'success'       => true,
            'message'       => $res['message'],
            'refund_type'   => $res['refund_type'],
            'refund_amount' => $res['refund_amount'],
        ), 200 );
    }

    /**
     * 获取回收变现预估报价
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_recycle_quote( $request ) {
        $user_id = get_current_user_id();
        $cuid    = absint( $request->get_param( 'cuid' ) );

        global $wpdb;
        $vanity_table = Qibbs_Database::vanity_uids();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT cuid, owner_id, status, sale_price, price_cash FROM {$vanity_table} WHERE cuid = %d LIMIT 1",
                $cuid
            )
        );

        if ( ! $row || (int) $row->owner_id !== $user_id || 'owned' !== $row->status ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => __( '您未持有该号码或当前状态不可回售', 'qibbs-community' ),
            ), 400 );
        }

        $rebate_rate = max( 1.0, min( 100.0, (float) qibbs_get_option( 'uid_recycle_rebate_rate', 50.0 ) ) );
        $payout_type = sanitize_key( qibbs_get_option( 'uid_recycle_payout_type', 'balance' ) );

        $cash_base   = (float) $row->price_cash > 0 ? (float) $row->price_cash : (float) $row->sale_price;
        $points_base = (int) $row->sale_price > 0 ? (int) $row->sale_price : (int) $row->price_cash;

        $refund_cash   = round( $cash_base * ( $rebate_rate / 100.0 ), 2 );
        $refund_points = (int) round( $points_base * ( $rebate_rate / 100.0 ) );

        $payout_label = ( 'balance' === $payout_type )
            ? __( '商城【可提现余额】', 'qibbs-community' )
            : ( function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() . __( '（商城积分）', 'qibbs-community' ) : __( '商城积分', 'qibbs-community' ) );

        return new WP_REST_Response( array(
            'success'       => true,
            'cuid'          => $cuid,
            'rebate_rate'   => $rebate_rate,
            'payout_type'   => $payout_type,
            'payout_label'  => $payout_label,
            'refund_cash'   => max( 1.00, $refund_cash ),
            'refund_points' => max( 10, $refund_points ),
            'enabled'       => (bool) qibbs_get_option( 'enable_uid_recycle', true ),
        ), 200 );
    }

    /**
     * 获取个人寄售与交易流水面板
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_my_dashboard( $request ) {
        $user_id = get_current_user_id();
        $service = Qibbs_C2C_Trade_Service::instance();

        $data = $service->get_user_c2c_dashboard( $user_id );

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => $data,
        ), 200 );
    }
}
