<?php
/**
 * 启灵微社区 - 启灵商城支付与收银台无缝对接
 * 当站点同时安装了启灵商城时，可实现统一支付网关复用、商城收银台跳转与支付履约交割
 *
 * @package Qibbs_Community
 * @subpackage Compatibility
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_QilingShop_Payment_Integration {

    /**
     * 单例实例
     *
     * @var Qibbs_QilingShop_Payment_Integration|null
     */
    private static $instance = null;

    /**
     * 获取单例实例
     *
     * @return Qibbs_QilingShop_Payment_Integration
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数：初始化钩子
     */
    private function __construct() {
        if ( ! class_exists( 'QilingShop_Order' ) && ! class_exists( 'QilingShop_Payment' ) ) {
            return;
        }

        $this->init_hooks();
    }

    /**
     * 注册订单支付成功监听动作
     */
    private function init_hooks() {
        // 监听启灵商城订单支付成功事件
        add_action( 'qilingshop_order_paid', array( $this, 'handle_qilingshop_order_paid' ), 10, 2 );
    }

    /**
     * 检查启灵商城支付是否已就绪且可用
     *
     * @return bool
     */
    public function is_available() {
        return class_exists( 'QilingShop_Payment' ) && ! empty( QilingShop_Payment::instance()->get_enabled_gateways() );
    }

    /**
     * 处理启灵商城订单支付完成事件
     *
     * @param int    $order_id       QilingShop order ID.
     * @param string $payment_method Payment method identifier.
     * @return void
     */
    public function handle_qilingshop_order_paid( $order_id, $payment_method = '' ) {
        $order_id = absint( $order_id );
        if ( $order_id <= 0 || ! class_exists( 'QilingShop_Order' ) ) {
            return;
        }

        $order = QilingShop_Order::instance()->get( $order_id );
        if ( ! $order || empty( $order->order_no ) ) {
            return;
        }

        // 仅处理属于社区靓号/寄售的订单类型
        if ( 'qibbs_vanity' !== $order->order_type && 'qibbs_c2c' !== $order->order_type ) {
            return;
        }

        $order_sn = (string) $order->order_no;
        $amount   = (float) ( $order->final_price ?: $order->price_rmb );
        $gateway  = $payment_method ?: (string) $order->payment_method;
        $trade_no = ! empty( $order->trade_no ) ? (string) $order->trade_no : $order_sn;

        // 调用社区订单服务完成原子履约交付（更新靓号归属、更新C2C记录、写入流水）
        if ( class_exists( 'Qibbs_Order_Service' ) ) {
            Qibbs_Order_Service::instance()->process_paid( $order_sn, $trade_no, $amount, $gateway );
        }

        // 触发启灵商城订单完成钩子
        do_action( 'qilingshop_order_completed', $order_id );
    }
}

// 自动初始化
Qibbs_QilingShop_Payment_Integration::instance();
