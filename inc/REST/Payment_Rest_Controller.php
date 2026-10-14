<?php
/**
 * 社区原生支付 REST API 控制器
 * 提供可用网关列表、创建支付订单收银台、订单状态轮询及异步回调通知处理
 *
 * @package Qibbs_Community
 * @subpackage REST
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 支付 REST 控制器
 */
class Qibbs_Payment_Rest_Controller {

    /**
     * REST 路由命名空间
     *
     * @var string
     */
    protected $namespace = 'qibbs/v1';

    /**
     * 注册支付相关 REST API 路由
     */
    public function register_routes() {
        // 1. 获取当前启用的支付方式列表
        register_rest_route( $this->namespace, '/payment/gateways', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_gateways' ),
            'permission_callback' => array( $this, 'check_public_access' ),
        ) );

        // 2. 调起支付收银台下单
        register_rest_route( $this->namespace, '/payment/checkout', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'checkout' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
            'args'                => array(
                'cuid' => array(
                    'required'          => true,
                    'validate_callback' => function( $param ) {
                        return is_numeric( $param ) && absint( $param ) > 0;
                    },
                ),
                'gateway' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                ),
            ),
        ) );

        // 3. 轮询订单实时支付状态
        register_rest_route( $this->namespace, '/payment/status/(?P<order_sn>[A-Za-z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_order_status' ),
            'permission_callback' => '__return_true',
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
     * 获取可用支付网关列表
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_gateways( $request ) {
        $payment_manager = Qibbs_Payment_Manager::instance();
        $gateways = $payment_manager->get_available_gateways();

        return new WP_REST_Response( array(
            'success'         => true,
            'gateways'        => $gateways,
            'currency_symbol' => $payment_manager->get_currency_symbol(),
            'is_enabled'      => $payment_manager->is_enabled(),
        ), 200 );
    }

    /**
     * 创建支付订单并获取支付参数
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function checkout( $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        $cuid       = absint( $request->get_param( 'cuid' ) );
        $gateway_id = sanitize_key( $request->get_param( 'gateway' ) );
        $return_url = sanitize_text_field( (string) $request->get_param( 'return_url' ) );
        $item_type  = sanitize_key( (string) $request->get_param( 'item_type' ) ) ?: 'vanity_uid';
        $client_ip  = (string) ( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' );

        $order_service = Qibbs_Order_Service::instance();
        $result = $order_service->create_vanity_order( $user_id, $cuid, $gateway_id, $client_ip, $return_url, $item_type );

        if ( empty( $result['success'] ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => ! empty( $result['message'] ) ? $result['message'] : __( '创建订单失败', 'qibbs-community' ),
            ), 400 );
        }

        return new WP_REST_Response( array(
            'success'         => true,
            'order_sn'        => $result['order_sn'],
            'amount'          => $result['amount'],
            'currency_symbol' => Qibbs_Payment_Manager::instance()->get_currency_symbol(),
            'type'            => $result['type'] ?? 'redirect',
            'pay_url'         => $result['pay_url'] ?? '',
            'qr_code'         => $result['qr_code'] ?? '',
        ), 200 );
    }

    /**
     * 轮询查询订单支付状态
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function get_order_status( $request ) {
        $order_sn = sanitize_text_field( $request['order_sn'] );
        $result   = Qibbs_Order_Service::instance()->query_order_status( $order_sn );

        if ( empty( $result['success'] ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => __( '订单不存在', 'qibbs-community' ) ), 404 );
        }

        return new WP_REST_Response( $result, 200 );
    }
}
