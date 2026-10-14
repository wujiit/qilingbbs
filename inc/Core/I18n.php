<?php
/**
 * 国际化多语言加载类
 * 加载并定义插件的本地化语言包
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 多语言管理
 */
class Qibbs_I18n {

    /**
     * 加载插件语言包 Text Domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'qibbs-community',
            false,
            dirname( QIBBS_PLUGIN_BASENAME ) . '/languages/'
        );
    }
}
