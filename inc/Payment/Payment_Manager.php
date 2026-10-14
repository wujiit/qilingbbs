<?php
/**
 * 社区支付适配管理器
 * 统一委托启灵商城（QilingShop）支付收银网关与订单结算中枢
 *
 * @package Qibbs_Community
 * @subpackage Payment
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 支付管理器（启灵商城支付代理适配器）
 */
class Qibbs_Payment_Manager {

    /**
     * 单例实例
     *
     * @var Qibbs_Payment_Manager|null
     */
    private static $instance = null;

    /**
     * 获取单例实例
     *
     * @return Qibbs_Payment_Manager
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct() {}

    /**
     * 检查现金支付功能是否开启（必须且完全依赖启灵商城支付网关驱动）
     *
     * @return bool
     */
    public function is_enabled() {
        return $this->is_qilingshop_payment_active();
    }

    /**
     * 检查当前系统是否安装并激活了启灵商城支付网关
     *
     * @return bool
     */
    public function is_qilingshop_payment_active() {
        return class_exists( 'QilingShop_Payment' ) && ! empty( QilingShop_Payment::instance()->get_enabled_gateways() );
    }

    /**
     * 获取当前系统现金支付工作模式：一律统一为启灵商城
     *
     * @return string
     */
    public function get_payment_mode() {
        return 'qilingshop';
    }

    /**
     * 获取货币符号 (默认 ¥)
     *
     * @return string
     */
    public function get_currency_symbol() {
        return '¥';
    }

    /**
     * 获取当前所有已启用并可用的启灵商城支付网关列表
     *
     * @return array<int, array{id:string, name:string, icon:string, icon_url:string, mode:string}>
     */
    public function get_available_gateways() {
        if ( ! $this->is_qilingshop_payment_active() ) {
            return array();
        }

        $img_base = defined( 'QIBBS_PLUGIN_URL' ) ? QIBBS_PLUGIN_URL . 'assets/images/payment/' : plugins_url( 'assets/images/payment/', dirname( __DIR__ ) );
        $icon_img_map = array(
            'alipay'      => $img_base . 'zfb.png',
            'alipay_qr'   => $img_base . 'zfb.png',
            'alipay_page' => $img_base . 'zfb.png',
            'wechat'      => $img_base . 'wx.png',
            'wxpay'       => $img_base . 'wx.png',
            'epay'        => $img_base . 'zfb.png',
            'xhpay'       => $img_base . 'wx.png',
            'paypal'      => $img_base . 'paypal.png',
            'stripe'      => $img_base . 'stripe.png',
        );

        $icon_map = array(
            'alipay'      => 'dashicons-money-alt',
            'alipay_qr'   => 'dashicons-money-alt',
            'alipay_page' => 'dashicons-money-alt',
            'wechat'      => 'dashicons-smartphone',
            'wxpay'       => 'dashicons-smartphone',
            'epay'        => 'dashicons-shield',
            'xhpay'       => 'dashicons-money-alt',
            'paypal'      => 'dashicons-admin-site-alt3',
            'stripe'      => 'dashicons-credit-card',
        );

        $shop_gateways = QilingShop_Payment::instance()->get_enabled_gateways();
        $list = array();
        foreach ( $shop_gateways as $id => $gw ) {
            $list[] = array(
                'id'       => $id,
                'name'     => $gw['name'] ?? $id,
                'icon'     => $icon_map[ $id ] ?? 'dashicons-cart',
                'icon_url' => $icon_img_map[ $id ] ?? ( $img_base . 'zfb.png' ),
                'mode'     => 'qilingshop',
            );
        }

        return (array) apply_filters( 'qibbs_payment_available_gateways', $list );
    }
}
