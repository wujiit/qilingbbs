<?php
/**
 * 社区靓号商城与交易大厅前台模板组件
 *
 * @package Qibbs_Community
 * @subpackage Templates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$rule_service = class_exists( 'Qibbs_UID_Rule_Service' ) ? Qibbs_UID_Rule_Service::instance() : null;
$grade_definitions = $rule_service ? $rule_service->get_grade_definitions() : array();
$points_name = function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' );
$has_shop_points = function_exists( 'qibbs_is_points_integration_available' ) && qibbs_is_points_integration_available();
$current_user_id = get_current_user_id();
$current_cuid = $current_user_id ? qibbs_get_user_cuid( $current_user_id ) : 0;
$c2c_enabled = (bool) qibbs_get_option( 'enable_c2c_trading', true );
$recycle_enabled = (bool) qibbs_get_option( 'enable_uid_recycle', true ) && $has_shop_points;
$c2c_fee_rate = (float) qibbs_get_option( 'c2c_fee_rate', 5.0 );
$payment_enabled = class_exists( 'Qibbs_Payment_Manager' ) && Qibbs_Payment_Manager::instance()->is_enabled();
$user_points_balance = ( $current_user_id && $has_shop_points && function_exists( 'qibbs_get_user_points_balance' ) ) ? qibbs_get_user_points_balance( $current_user_id ) : null;

// 计算各大厅与背包实时资产统计数量，确保首屏渲染零延迟展示真实角标
global $wpdb;
$vanity_table         = class_exists( 'Qibbs_Database' ) ? Qibbs_Database::vanity_uids() : $wpdb->prefix . 'qibbs_vanity_uids';
$official_total_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vanity_table} WHERE status = 'in_pool'" );
$c2c_total_count      = $c2c_enabled ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vanity_table} WHERE status = 'c2c_listed'" ) : 0;
$backpack_total_count = 0;
if ( $current_user_id > 0 && function_exists( 'qibbs_get_user_backpack' ) ) {
    $backpack_items       = qibbs_get_user_backpack( $current_user_id );
    $backpack_total_count = is_array( $backpack_items ) ? count( $backpack_items ) : 0;
}

if ( ! isset( $is_vanity_view ) ) {
    $current_view   = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
    $is_vanity_view = ( 'vanity-mall' === $current_view && qibbs_is_module_enabled( 'vanity_mall' ) );
}
?>

<div class="qibbs-vanity-mall-container" id="qibbs-vanity-mall-container" style="<?php echo $is_vanity_view ? 'display:block;' : 'display:none;'; ?>">

    <!-- 顶部 Hero 宣传与个人资产面板 -->
    <div class="qibbs-vanity-hero">
        <div class="qibbs-vanity-hero-content">
            <div class="qibbs-vanity-hero-badge">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                    <path d="M12 2L2 9.21L6 21H18L22 9.21L12 2ZM12 5.56L17.24 9.35L15.34 19H8.66L6.76 9.35L12 5.56Z"/>
                </svg>
                <span><?php esc_html_e( '尊贵数字身份 · 官方认证', 'qibbs-community' ); ?></span>
            </div>
            <h1 class="qibbs-vanity-hero-title"><?php esc_html_e( '靓号交易大厅', 'qibbs-community' ); ?></h1>
            <p class="qibbs-vanity-hero-sub"><?php esc_html_e( '汇聚官方限量极品靓号与玩家自由寄售市场，支持一键佩戴生效与官方保底回购变现', 'qibbs-community' ); ?></p>
        </div>

        <?php if ( is_user_logged_in() ) : ?>
            <div class="qibbs-vanity-user-summary">
                <div class="qibbs-user-summary-card">
                    <div class="qibbs-summary-item">
                        <span class="qibbs-summary-label"><?php esc_html_e( '当前佩戴 UID', 'qibbs-community' ); ?></span>
                        <div class="qibbs-summary-val qibbs-summary-cuid" id="qibbs-hero-active-cuid">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="#f59e0b">
                                <path d="M5 16L3 5l5.5 5L12 4l3.5 6L21 5l-2 11H5m14 3c0 .6-.4 1-1 1H6c-.6 0-1-.4-1-1v-1h14v1z"/>
                            </svg>
                            <span><?php echo esc_html( $current_cuid ); ?></span>
                        </div>
                    </div>
                    <?php if ( null !== $user_points_balance ) : ?>
                        <div class="qibbs-summary-divider"></div>
                        <div class="qibbs-summary-item">
                            <span class="qibbs-summary-label"><?php echo esc_html( $points_name ); ?></span>
                            <div class="qibbs-summary-val qibbs-summary-points" id="qibbs-hero-user-points">
                                <span><?php echo esc_html( qibbs_format_points_amount( $user_points_balance ) ); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php
                    $user_withdrawable = function_exists( 'qibbs_get_user_withdrawable_balance' ) ? qibbs_get_user_withdrawable_balance( $current_user_id ) : 0.00;
                    $withdraw_url      = function_exists( 'qibbs_get_withdraw_url' ) ? qibbs_get_withdraw_url() : home_url( '/account/?tab=qls-withdraw' );
                    ?>
                    <div class="qibbs-summary-divider"></div>
                    <div class="qibbs-summary-item">
                        <span class="qibbs-summary-label"><?php esc_html_e( '可提现余额', 'qibbs-community' ); ?></span>
                        <div class="qibbs-summary-val qibbs-summary-cash" id="qibbs-hero-user-balance" style="color:#059669;font-weight:700;">
                            <span>¥<?php echo esc_html( number_format( $user_withdrawable, 2 ) ); ?></span>
                        </div>
                    </div>
                </div>
                <div class="qibbs-user-summary-actions" style="display:flex;gap:8px;align-items:center;">
                    <a href="<?php echo esc_url( $withdraw_url ); ?>" class="qibbs-btn qibbs-btn-success qibbs-btn-sm" style="text-decoration:none;display:inline-flex;align-items:center;gap:4px;" title="<?php esc_attr_e( '前往商城个人中心提现', 'qibbs-community' ); ?>" target="_blank">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor">
                            <path d="M19 14V6c0-1.1-.9-2-2-2H3c-1.1 0-2 .9-2 2v8c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zm-2 0H3V6h14v8zm-7-7c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3zm13 4v6c0 1.1-.9 2-2 2H4v-2h17v-6h2z"/>
                        </svg>
                        <span><?php esc_html_e( '申请提现', 'qibbs-community' ); ?></span>
                    </a>
                    <button type="button" class="qibbs-btn qibbs-btn-primary qibbs-btn-sm" id="qibbs-jump-backpack-btn">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor">
                            <path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/>
                        </svg>
                        <span><?php esc_html_e( '号码背包', 'qibbs-community' ); ?></span>
                    </button>
                </div>
            </div>
        <?php else : ?>
            <div class="qibbs-vanity-user-summary qibbs-guest-summary">
                <p class="qibbs-guest-hint"><?php esc_html_e( '登录后可参与靓号兑换、C2C自由寄售及号码背包佩戴', 'qibbs-community' ); ?></p>
                <?php echo Qibbs_Theme_Compatibility::get_login_button_html( 'qibbs-btn qibbs-btn-primary qibbs-btn-sm', __( '立即登录', 'qibbs-community' ) ); ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ( ! class_exists( 'QilingShop_Payment' ) && current_user_can( 'manage_options' ) ) : ?>
        <div class="qibbs-mall-notice-banner" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin:0 0 20px 0;color:#92400e;display:flex;align-items:center;gap:10px;font-size:13px;">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="#d97706" style="flex-shrink:0;"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
            <span><?php esc_html_e( '管理员提示：当前站点尚未开启支付网关，在线现金交易与提现功能暂不可用。', 'qibbs-community' ); ?></span>
        </div>
    <?php endif; ?>

    <!-- 交易大厅主 Tab 导航 -->
    <div class="qibbs-vanity-tabs-wrapper">
        <div class="qibbs-vanity-tabs" role="tablist">
            <button type="button" class="qibbs-vanity-tab active" data-vanity-tab="official" role="tab" aria-selected="true">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                </svg>
                <span><?php esc_html_e( '官方自营商城', 'qibbs-community' ); ?></span>
                <span class="qibbs-tab-badge" id="qibbs-official-tab-count"><?php echo esc_html( $official_total_count ); ?></span>
            </button>
            <?php if ( $c2c_enabled ) : ?>
                <button type="button" class="qibbs-vanity-tab" data-vanity-tab="c2c" role="tab" aria-selected="false">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                        <path d="M16 17.01V10h-2v7.01h-3L15 21l4-3.99h-3zM9 3L5 6.99h3V14h2V6.99h3L9 3z"/>
                    </svg>
                    <span><?php esc_html_e( 'C2C 寄售大厅', 'qibbs-community' ); ?></span>
                    <span class="qibbs-tab-badge" id="qibbs-c2c-tab-count"><?php echo esc_html( $c2c_total_count ); ?></span>
                </button>
            <?php endif; ?>
            <button type="button" class="qibbs-vanity-tab" data-vanity-tab="backpack" role="tab" aria-selected="false">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                    <path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/>
                </svg>
                <span><?php esc_html_e( '我的号码背包', 'qibbs-community' ); ?></span>
                <span class="qibbs-tab-badge" id="qibbs-backpack-tab-count"><?php echo esc_html( $backpack_total_count ); ?></span>
            </button>
            <button type="button" class="qibbs-vanity-tab" data-vanity-tab="records" role="tab" aria-selected="false">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                    <path d="M13 3c-4.97 0-9 4.03-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42C8.27 19.99 10.51 21 13 21c4.97 0 9-4.03 9-9s-4.03-9-9-9zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/>
                </svg>
                <span><?php esc_html_e( '成交动态', 'qibbs-community' ); ?></span>
                <span class="qibbs-tab-badge" id="qibbs-records-tab-count" style="display:none;">0</span>
            </button>
        </div>
    </div>

    <!-- ==================== TAB 1: 官方自营商城 ==================== -->
    <div class="qibbs-vanity-pane is-active" id="qibbs-pane-official" role="tabpanel">
        <!-- 筛选与控制栏 -->
        <div class="qibbs-vanity-toolbar">
            <div class="qibbs-grade-pills" id="qibbs-official-grade-filter">
                <button type="button" class="qibbs-grade-pill active" data-grade="0">
                    <?php esc_html_e( '全部品相', 'qibbs-community' ); ?>
                </button>
                <?php if ( ! empty( $grade_definitions ) ) : ?>
                    <?php foreach ( $grade_definitions as $g_id => $g_def ) : ?>
                        <button type="button" class="qibbs-grade-pill" data-grade="<?php echo esc_attr( $g_id ); ?>" data-grade-level="<?php echo esc_attr( $g_id ); ?>">
                            <span class="qibbs-pill-dot qibbs-grade-dot-<?php echo esc_attr( $g_id ); ?>"></span>
                            <?php echo esc_html( $g_def['name'] ); ?>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="qibbs-toolbar-controls">
                <div class="qibbs-search-control">
                    <input type="text" id="qibbs-official-search" placeholder="<?php esc_attr_e( '搜索指定号码，如 888、666...', 'qibbs-community' ); ?>" autocomplete="off">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="m21 21-4.3-4.3"></path>
                    </svg>
                </div>
                <div class="qibbs-sort-control">
                    <select id="qibbs-official-sort">
                        <option value="sale_price-asc"><?php esc_html_e( '价格由低到高', 'qibbs-community' ); ?></option>
                        <option value="sale_price-desc"><?php esc_html_e( '价格由高到低', 'qibbs-community' ); ?></option>
                        <option value="created_at-desc"><?php esc_html_e( '最新上架', 'qibbs-community' ); ?></option>
                        <option value="cuid-asc"><?php esc_html_e( '号码由小到大', 'qibbs-community' ); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <!-- 货架网格容器 -->
        <div class="qibbs-vanity-grid" id="qibbs-official-grid">
            <!-- 动态渲染号码卡片 -->
        </div>

        <!-- 加载动画 -->
        <div class="qibbs-vanity-loading" id="qibbs-official-loading">
            <div class="qibbs-spinner"></div>
            <span><?php esc_html_e( '正在检索官方靓号货架...', 'qibbs-community' ); ?></span>
        </div>

        <!-- 空数据提示 -->
        <div class="qibbs-vanity-empty" id="qibbs-official-empty" style="display:none;">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <p><?php esc_html_e( '当前暂无符合条件的在售靓号', 'qibbs-community' ); ?></p>
            <button type="button" class="qibbs-btn qibbs-btn-sm qibbs-btn-default" id="qibbs-official-reset-btn">
                <?php esc_html_e( '重置所有筛选', 'qibbs-community' ); ?>
            </button>
        </div>

        <!-- 分页控制器 -->
        <div class="qibbs-vanity-pagination" id="qibbs-official-pagination" style="display:none;">
            <button type="button" class="qibbs-page-prev" id="qibbs-official-prev-btn"><?php esc_html_e( '上一页', 'qibbs-community' ); ?></button>
            <span class="qibbs-page-info" id="qibbs-official-page-info">1 / 1</span>
            <button type="button" class="qibbs-page-next" id="qibbs-official-next-btn"><?php esc_html_e( '下一页', 'qibbs-community' ); ?></button>
        </div>
    </div>

    <?php if ( $c2c_enabled ) : ?>
    <!-- ==================== TAB 2: C2C 寄售大厅 ==================== -->
    <div class="qibbs-vanity-pane" id="qibbs-pane-c2c" role="tabpanel">
        <!-- 筛选与控制栏 -->
        <div class="qibbs-vanity-toolbar">
            <div class="qibbs-grade-pills" id="qibbs-c2c-grade-filter">
                <button type="button" class="qibbs-grade-pill active" data-grade="0">
                    <?php esc_html_e( '全部品相', 'qibbs-community' ); ?>
                </button>
                <?php if ( ! empty( $grade_definitions ) ) : ?>
                    <?php foreach ( $grade_definitions as $g_id => $g_def ) : ?>
                        <button type="button" class="qibbs-grade-pill" data-grade="<?php echo esc_attr( $g_id ); ?>" data-grade-level="<?php echo esc_attr( $g_id ); ?>">
                            <span class="qibbs-pill-dot qibbs-grade-dot-<?php echo esc_attr( $g_id ); ?>"></span>
                            <?php echo esc_html( $g_def['name'] ); ?>
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="qibbs-toolbar-controls">
                <div class="qibbs-search-control">
                    <input type="text" id="qibbs-c2c-search" placeholder="<?php esc_attr_e( '搜索寄售号码...', 'qibbs-community' ); ?>" autocomplete="off">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="m21 21-4.3-4.3"></path>
                    </svg>
                </div>
                <?php if ( $payment_enabled ) : ?>
                    <div class="qibbs-currency-control">
                        <select id="qibbs-c2c-currency">
                            <option value="all"><?php esc_html_e( '全部支付方式', 'qibbs-community' ); ?></option>
                            <option value="points"><?php echo esc_html( sprintf( __( '仅%s兑换', 'qibbs-community' ), $points_name ) ); ?></option>
                            <option value="cash"><?php esc_html_e( '支持现金交易', 'qibbs-community' ); ?></option>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="qibbs-sort-control">
                    <select id="qibbs-c2c-sort">
                        <option value="c2c_listed_at-desc"><?php esc_html_e( '最新挂牌', 'qibbs-community' ); ?></option>
                        <option value="c2c_price_points-asc"><?php echo esc_html( sprintf( __( '%s由低到高', 'qibbs-community' ), $points_name ) ); ?></option>
                        <option value="c2c_price_points-desc"><?php echo esc_html( sprintf( __( '%s由高到低', 'qibbs-community' ), $points_name ) ); ?></option>
                        <option value="cuid-asc"><?php esc_html_e( '号码由小到大', 'qibbs-community' ); ?></option>
                    </select>
                </div>
                <?php if ( is_user_logged_in() ) : ?>
                    <button type="button" class="qibbs-btn qibbs-btn-success qibbs-btn-sm" id="qibbs-go-list-c2c-btn">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor">
                            <path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/>
                        </svg>
                        <span><?php esc_html_e( '挂牌我的号码', 'qibbs-community' ); ?></span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- 平台担保提示栏 -->
        <div class="qibbs-c2c-guarantee-bar">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="#10b981">
                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/>
            </svg>
            <span><?php echo $c2c_fee_rate > 0 ? esc_html( sprintf( __( '平台托管担保交易 · 购买后即时过户至买家背包 · 平台收取成交价 %s%% 服务费', 'qibbs-community' ), $c2c_fee_rate ) ) : esc_html__( '平台托管担保交易 · 购买后即时过户至买家背包 · 免交易手续费全额到账', 'qibbs-community' ); ?></span>
        </div>

        <!-- C2C 寄售货架网格 -->
        <div class="qibbs-vanity-grid" id="qibbs-c2c-grid">
            <!-- 动态渲染 C2C 卡片 -->
        </div>

        <!-- 加载动画 -->
        <div class="qibbs-vanity-loading" id="qibbs-c2c-loading" style="display:none;">
            <div class="qibbs-spinner"></div>
            <span><?php esc_html_e( '正在检索 C2C 寄售大厅...', 'qibbs-community' ); ?></span>
        </div>

        <!-- 空数据提示 -->
        <div class="qibbs-vanity-empty" id="qibbs-c2c-empty" style="display:none;">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
            </svg>
            <p><?php esc_html_e( '当前暂无玩家挂牌在售号码', 'qibbs-community' ); ?></p>
            <?php if ( is_user_logged_in() ) : ?>
                <button type="button" class="qibbs-btn qibbs-btn-sm qibbs-btn-primary" id="qibbs-c2c-first-list-btn">
                    <?php esc_html_e( '前往我的背包挂牌', 'qibbs-community' ); ?>
                </button>
            <?php endif; ?>
        </div>

        <!-- 分页控制器 -->
        <div class="qibbs-vanity-pagination" id="qibbs-c2c-pagination" style="display:none;">
            <button type="button" class="qibbs-page-prev" id="qibbs-c2c-prev-btn"><?php esc_html_e( '上一页', 'qibbs-community' ); ?></button>
            <span class="qibbs-page-info" id="qibbs-c2c-page-info">1 / 1</span>
            <button type="button" class="qibbs-page-next" id="qibbs-c2c-next-btn"><?php esc_html_e( '下一页', 'qibbs-community' ); ?></button>
        </div>
    </div>
    <?php endif; ?>

    <!-- ==================== TAB 3: 我的号码背包 ==================== -->
    <div class="qibbs-vanity-pane" id="qibbs-pane-backpack" role="tabpanel">
        <?php if ( ! is_user_logged_in() ) : ?>
            <div class="qibbs-vanity-auth-gate">
                <div class="qibbs-auth-gate-card">
                    <svg viewBox="0 0 24 24" width="56" height="56" fill="var(--qibbs-primary)">
                        <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                    </svg>
                    <h3><?php esc_html_e( '查看与管理您的号码背包', 'qibbs-community' ); ?></h3>
                    <p><?php esc_html_e( '登录后可查看您拥有的所有专属数字身份，随心切换佩戴、挂牌寄售或回购变现。', 'qibbs-community' ); ?></p>
                    <div class="qibbs-auth-gate-actions">
                        <?php echo Qibbs_Theme_Compatibility::get_login_button_html( 'qibbs-btn qibbs-btn-primary', __( '立即登录', 'qibbs-community' ) ); ?>
                    </div>
                </div>
            </div>
        <?php else : ?>
            <!-- 背包头部说明与资产总览 -->
            <div class="qibbs-backpack-header">
                <div class="qibbs-backpack-header-info">
                    <h3><?php esc_html_e( '我的数字资产背包', 'qibbs-community' ); ?></h3>
                    <p><?php esc_html_e( '您持有的全部号码资产汇聚于此。保底注册号永久属于您，商业靓号支持佩戴生效、C2C寄售或官方回收。', 'qibbs-community' ); ?></p>
                </div>
                <div class="qibbs-backpack-header-stats">
                    <span class="qibbs-bp-stat-pill">
                        <strong id="qibbs-bp-total-count"><?php echo esc_html( $backpack_total_count ); ?></strong> <?php esc_html_e( '个已持有号码', 'qibbs-community' ); ?>
                    </span>
                    <button type="button" class="qibbs-btn qibbs-btn-sm qibbs-btn-default" id="qibbs-bp-refresh-btn" title="<?php esc_attr_e( '刷新背包', 'qibbs-community' ); ?>">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
                        </svg>
                        <span><?php esc_html_e( '刷新', 'qibbs-community' ); ?></span>
                    </button>
                </div>
            </div>

            <!-- 背包号码网格 -->
            <div class="qibbs-backpack-grid" id="qibbs-backpack-grid">
                <!-- 动态渲染背包号码卡片 -->
            </div>

            <!-- 加载动画 -->
            <div class="qibbs-vanity-loading" id="qibbs-backpack-loading" style="display:none;">
                <div class="qibbs-spinner"></div>
                <span><?php esc_html_e( '正在同步您的号码背包...', 'qibbs-community' ); ?></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==================== TAB 4: 成交动态与记录 ==================== -->
    <div class="qibbs-vanity-pane" id="qibbs-pane-records" role="tabpanel">
        <div class="qibbs-vanity-toolbar">
            <div class="qibbs-grade-pills" id="qibbs-records-tab-filter">
                <button type="button" class="qibbs-grade-pill active" data-records-tab="market">
                    <?php esc_html_e( '全网成交动态', 'qibbs-community' ); ?>
                </button>
                <?php if ( is_user_logged_in() ) : ?>
                    <button type="button" class="qibbs-grade-pill" data-records-tab="mine">
                        <?php esc_html_e( '我的交易记录', 'qibbs-community' ); ?>
                    </button>
                <?php endif; ?>
            </div>

            <div class="qibbs-toolbar-controls">
                <div class="qibbs-c2c-type-filter" id="qibbs-records-type-filter">
                    <button type="button" class="qibbs-type-filter-btn active" data-trade-type="all"><?php esc_html_e( '全部类型', 'qibbs-community' ); ?></button>
                    <button type="button" class="qibbs-type-filter-btn" data-trade-type="official"><?php esc_html_e( '官方发售', 'qibbs-community' ); ?></button>
                    <button type="button" class="qibbs-type-filter-btn" data-trade-type="c2c"><?php esc_html_e( '玩家寄售', 'qibbs-community' ); ?></button>
                    <button type="button" class="qibbs-type-filter-btn" data-trade-type="recycle"><?php esc_html_e( '官方回收', 'qibbs-community' ); ?></button>
                </div>
            </div>
        </div>

        <!-- 交易流水列表 -->
        <div class="qibbs-records-list" id="qibbs-records-list">
            <!-- 动态渲染成交卡片 -->
        </div>

        <!-- 空态与加载中 -->
        <div class="qibbs-vanity-empty" id="qibbs-records-empty" style="display:none;">
            <svg viewBox="0 0 24 24" width="48" height="48" fill="#9ca3af">
                <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
            </svg>
            <p><?php esc_html_e( '暂无相关成交记录', 'qibbs-community' ); ?></p>
        </div>

        <div class="qibbs-vanity-loading" id="qibbs-records-loading" style="display:none;">
            <div class="qibbs-spinner"></div>
            <span><?php esc_html_e( '正在载入成交记录...', 'qibbs-community' ); ?></span>
        </div>

        <!-- 分页导航 -->
        <div class="qibbs-vanity-pagination" id="qibbs-records-pagination" style="display:none;">
            <button type="button" class="qibbs-btn qibbs-btn-sm" id="qibbs-records-prev-btn"><?php esc_html_e( '上一页', 'qibbs-community' ); ?></button>
            <span class="qibbs-page-info" id="qibbs-records-page-info">1 / 1</span>
            <button type="button" class="qibbs-btn qibbs-btn-sm" id="qibbs-records-next-btn"><?php esc_html_e( '下一页', 'qibbs-community' ); ?></button>
        </div>
    </div>

</div>

<!-- ==================== 交互弹窗：官方商城购买确认 ==================== -->
<div class="qibbs-vanity-modal-backdrop" id="qibbs-buy-modal" style="display:none;">
    <div class="qibbs-vanity-modal">
        <div class="qibbs-vanity-modal-header">
            <h3><?php esc_html_e( '确认兑换靓号', 'qibbs-community' ); ?></h3>
            <button type="button" class="qibbs-modal-close" data-modal-close>&times;</button>
        </div>
        <div class="qibbs-vanity-modal-body">
            <div class="qibbs-modal-cuid-card">
                <div class="qibbs-modal-grade-tag" id="qibbs-buy-grade-tag"></div>
                <div class="qibbs-modal-cuid-num" id="qibbs-buy-cuid-num">00000</div>
                <div class="qibbs-modal-cuid-hint"><?php esc_html_e( '购买后号码将永久归属于您的背包，并可随时一键佩戴生效', 'qibbs-community' ); ?></div>
            </div>

            <div class="qibbs-modal-cost-breakdown">
                <div class="qibbs-cost-row" id="qibbs-buy-points-price-row">
                    <span><?php echo esc_html( $points_name ); ?><?php esc_html_e( '价格', 'qibbs-community' ); ?></span>
                    <strong class="qibbs-cost-price" id="qibbs-buy-price-display">0 <?php echo esc_html( $points_name ); ?></strong>
                </div>
                <div class="qibbs-cost-row" id="qibbs-buy-cash-price-row" style="display:none;">
                    <span><?php esc_html_e( '现金直购价', 'qibbs-community' ); ?></span>
                    <strong class="qibbs-cost-cash" id="qibbs-buy-cash-price-display" style="color:#ef4444;font-size:16px;">¥0.00</strong>
                </div>
                <div class="qibbs-cost-row" id="qibbs-buy-user-balance-row">
                    <span><?php esc_html_e( '您的当前', 'qibbs-community' ); ?><?php echo esc_html( $points_name ); ?></span>
                    <span id="qibbs-buy-balance-display">0 <?php echo esc_html( $points_name ); ?></span>
                </div>
                <div class="qibbs-cost-row qibbs-cost-after" id="qibbs-buy-remain-row">
                    <span><?php echo esc_html( $points_name ); ?><?php esc_html_e( '支付后剩余', 'qibbs-community' ); ?></span>
                    <span id="qibbs-buy-remain-display">0 <?php echo esc_html( $points_name ); ?></span>
                </div>
            </div>

            <div class="qibbs-buy-equip-toggle-box">
                <label class="qibbs-buy-equip-label" for="qibbs-buy-auto-equip-toggle">
                    <input type="checkbox" id="qibbs-buy-auto-equip-toggle" value="1">
                    <span><?php esc_html_e( '兑换后立即佩戴生效', 'qibbs-community' ); ?></span>
                </label>
                <div class="qibbs-buy-equip-tip">
                    <?php esc_html_e( '默认不勾选：仅购买存入背包，不影响您当前正在使用的号码。', 'qibbs-community' ); ?>
                </div>
            </div>

            <div class="qibbs-modal-alert qibbs-alert-danger" id="qibbs-buy-insufficient-alert" style="display:none;">
                <?php printf( esc_html__( '您的账户%s不足，您可选择现金直接购买或充值后兑换。', 'qibbs-community' ), esc_html( $points_name ) ); ?>
            </div>
        </div>
        <div class="qibbs-vanity-modal-footer">
            <button type="button" class="qibbs-btn qibbs-btn-default" data-modal-close><?php esc_html_e( '取消', 'qibbs-community' ); ?></button>
            <button type="button" class="qibbs-btn qibbs-btn-success" id="qibbs-cash-buy-direct-btn" style="display:none;">
                <span class="qibbs-btn-label"><?php esc_html_e( '现金支付', 'qibbs-community' ); ?></span>
            </button>
            <button type="button" class="qibbs-btn qibbs-btn-primary" id="qibbs-confirm-buy-btn">
                <span class="qibbs-btn-label"><?php echo esc_html( $points_name ); ?><?php esc_html_e( '兑换（存入背包）', 'qibbs-community' ); ?></span>
                <span class="qibbs-btn-loading-spinner" style="display:none;"></span>
            </button>
        </div>
    </div>
</div>

<!-- ==================== 交互弹窗：玩家 C2C 寄售购买确认 ==================== -->
<div class="qibbs-vanity-modal-backdrop" id="qibbs-c2c-buy-modal" style="display:none;">
    <div class="qibbs-vanity-modal">
        <div class="qibbs-vanity-modal-header">
            <h3><?php esc_html_e( '选购玩家寄售靓号', 'qibbs-community' ); ?></h3>
            <button type="button" class="qibbs-modal-close" data-modal-close>&times;</button>
        </div>
        <div class="qibbs-vanity-modal-body">
            <!-- 寄售卖家专属信息卡 -->
            <div class="qibbs-c2c-modal-seller-card">
                <img id="qibbs-c2c-buy-seller-avatar" src="" alt="" class="qibbs-seller-avatar">
                <div class="qibbs-c2c-modal-seller-meta">
                    <div class="qibbs-c2c-modal-seller-row">
                        <span class="qibbs-c2c-modal-seller-label"><?php esc_html_e( '寄售卖家', 'qibbs-community' ); ?></span>
                        <strong id="qibbs-c2c-buy-seller-name" class="qibbs-c2c-modal-seller-name"></strong>
                        <span class="qibbs-card-c2c-tag"><?php esc_html_e( '玩家寄售', 'qibbs-community' ); ?></span>
                    </div>
                    <div class="qibbs-c2c-modal-seller-time" id="qibbs-c2c-buy-seller-time"></div>
                </div>
            </div>

            <!-- 号码卡片 -->
            <div class="qibbs-modal-cuid-card">
                <div class="qibbs-modal-grade-tag" id="qibbs-c2c-buy-grade-tag"></div>
                <div class="qibbs-modal-cuid-num" id="qibbs-c2c-buy-cuid-num">00000</div>
                <div class="qibbs-modal-cuid-hint"><?php esc_html_e( '购买后号码将立即过户至您的背包，可在号码背包随时一键佩戴', 'qibbs-community' ); ?></div>
            </div>

            <!-- 价格明细 -->
            <div class="qibbs-modal-cost-breakdown">
                <div class="qibbs-cost-row" id="qibbs-c2c-buy-points-price-row">
                    <span><?php echo esc_html( $points_name ); ?><?php esc_html_e( '售价', 'qibbs-community' ); ?></span>
                    <strong class="qibbs-cost-price" id="qibbs-c2c-buy-price-display">0 <?php echo esc_html( $points_name ); ?></strong>
                </div>
                <div class="qibbs-cost-row" id="qibbs-c2c-buy-cash-price-row" style="display:none;">
                    <span><?php esc_html_e( '现金直购价', 'qibbs-community' ); ?></span>
                    <strong class="qibbs-cost-cash" id="qibbs-c2c-buy-cash-price-display" style="color:#ef4444;font-size:16px;">¥0.00</strong>
                </div>
                <div class="qibbs-cost-row" id="qibbs-c2c-buy-user-balance-row">
                    <span><?php esc_html_e( '您的当前', 'qibbs-community' ); ?><?php echo esc_html( $points_name ); ?></span>
                    <span id="qibbs-c2c-buy-balance-display">0 <?php echo esc_html( $points_name ); ?></span>
                </div>
                <div class="qibbs-cost-row qibbs-cost-after" id="qibbs-c2c-buy-remain-row">
                    <span><?php echo esc_html( $points_name ); ?><?php esc_html_e( '支付后剩余', 'qibbs-community' ); ?></span>
                    <span id="qibbs-c2c-buy-remain-display">0 <?php echo esc_html( $points_name ); ?></span>
                </div>
            </div>

            <!-- 余额不足警示 / 提示 -->
            <div class="qibbs-modal-alert qibbs-alert-danger" id="qibbs-c2c-buy-insufficient-alert" style="display:none;"></div>
        </div>
        <div class="qibbs-vanity-modal-footer">
            <button type="button" class="qibbs-btn qibbs-btn-default" data-modal-close><?php esc_html_e( '取消', 'qibbs-community' ); ?></button>
            <button type="button" class="qibbs-btn qibbs-btn-success" id="qibbs-c2c-cash-buy-direct-btn" style="display:none;">
                <span class="qibbs-btn-label"><?php esc_html_e( '现金支付', 'qibbs-community' ); ?></span>
            </button>
            <button type="button" class="qibbs-btn qibbs-btn-primary" id="qibbs-c2c-confirm-buy-btn">
                <span class="qibbs-btn-label"><?php echo esc_html( $points_name ); ?><?php esc_html_e( '购买（存入背包）', 'qibbs-community' ); ?></span>
                <span class="qibbs-btn-loading-spinner" style="display:none;"></span>
            </button>
        </div>
    </div>
</div>

<!-- ==================== 交互弹窗：玩家 C2C 挂牌出售 ==================== -->
<div class="qibbs-vanity-modal-backdrop" id="qibbs-c2c-list-modal" style="display:none;">
    <div class="qibbs-vanity-modal">
        <div class="qibbs-vanity-modal-header">
            <h3><?php esc_html_e( '挂牌寄售靓号', 'qibbs-community' ); ?></h3>
            <button type="button" class="qibbs-modal-close" data-modal-close>&times;</button>
        </div>
        <div class="qibbs-vanity-modal-body">
            <div class="qibbs-modal-cuid-card">
                <div class="qibbs-modal-grade-tag" id="qibbs-list-grade-tag"></div>
                <div class="qibbs-modal-cuid-num" id="qibbs-list-cuid-num">00000</div>
                <input type="hidden" id="qibbs-list-target-cuid" value="">
            </div>

            <form class="qibbs-c2c-list-form" id="qibbs-c2c-list-form" onsubmit="return false;">
                <?php if ( $has_shop_points ) : ?>
                <div class="qibbs-form-group" id="qibbs-list-points-group">
                    <label for="qibbs-list-price-points"><?php echo esc_html( sprintf( __( '%s标价', 'qibbs-community' ), $points_name ) ); ?></label>
                    <input type="number" id="qibbs-list-price-points" min="0" step="10" placeholder="<?php echo esc_attr( sprintf( __( '输入买家需支付的%s数额，不设留空或为0', 'qibbs-community' ), $points_name ) ); ?>">
                </div>
                <?php endif; ?>
                <?php if ( $payment_enabled ) : ?>
                <div class="qibbs-form-group">
                    <label for="qibbs-list-price-cash"><?php esc_html_e( '现金直购标价（元）', 'qibbs-community' ); ?></label>
                    <input type="number" id="qibbs-list-price-cash" min="0" step="0.01" placeholder="<?php esc_attr_e( '输入现金直购人民币价格，不设留空或为0', 'qibbs-community' ); ?>">
                </div>
                <?php endif; ?>

                <div class="qibbs-list-fee-hint">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
                    </svg>
                    <span><?php echo $c2c_fee_rate > 0 ? esc_html( sprintf( __( '平台将收取 %s%% 成交手续费。号码上架后暂不可佩戴，您可以随时免费撤回下架。', 'qibbs-community' ), $c2c_fee_rate ) ) : esc_html__( '成交免收交易手续费，现金净收入全额打入商城可提现余额。号码上架后暂不可佩戴，您可以随时免费撤回下架。', 'qibbs-community' ); ?></span>
                </div>
            </form>
        </div>
        <div class="qibbs-vanity-modal-footer">
            <button type="button" class="qibbs-btn qibbs-btn-default" data-modal-close><?php esc_html_e( '取消', 'qibbs-community' ); ?></button>
            <button type="button" class="qibbs-btn qibbs-btn-success" id="qibbs-confirm-list-btn">
                <span class="qibbs-btn-label"><?php esc_html_e( '确认上架寄售', 'qibbs-community' ); ?></span>
                <span class="qibbs-btn-loading-spinner" style="display:none;"></span>
            </button>
        </div>
    </div>
</div>

<!-- ==================== 交互弹窗：官方折价回售变现 ==================== -->
<div class="qibbs-vanity-modal-backdrop" id="qibbs-recycle-modal" style="display:none;">
    <div class="qibbs-vanity-modal">
        <div class="qibbs-vanity-modal-header">
            <h3><?php esc_html_e( '官方折价回售', 'qibbs-community' ); ?></h3>
            <button type="button" class="qibbs-modal-close" data-modal-close>&times;</button>
        </div>
        <div class="qibbs-vanity-modal-body">
            <div class="qibbs-modal-cuid-card">
                <div class="qibbs-modal-grade-tag" id="qibbs-recycle-grade-tag"></div>
                <div class="qibbs-modal-cuid-num" id="qibbs-recycle-cuid-num">00000</div>
                <input type="hidden" id="qibbs-recycle-target-cuid" value="">
            </div>

            <div class="qibbs-recycle-quote-box" id="qibbs-recycle-quote-box">
                <div class="qibbs-recycle-row">
                    <span><?php esc_html_e( '回售保障折率', 'qibbs-community' ); ?></span>
                    <strong id="qibbs-recycle-rate-display">50%</strong>
                </div>
                <div class="qibbs-recycle-row">
                    <span><?php esc_html_e( '退回发放方式', 'qibbs-community' ); ?></span>
                    <strong id="qibbs-recycle-type-display"><?php esc_html_e( '商城可提现现金', 'qibbs-community' ); ?></strong>
                </div>
                <div class="qibbs-recycle-row qibbs-recycle-total">
                    <span><?php esc_html_e( '系统即时返还', 'qibbs-community' ); ?></span>
                    <strong class="qibbs-recycle-amount" id="qibbs-recycle-amount-display">0</strong>
                </div>
            </div>

            <div class="qibbs-modal-alert qibbs-alert-warning">
                <?php esc_html_e( '回售后，该号码将收回系统公共池并立即恢复在售状态，您将失去该号码所有权。变现款项将即时发放至您的启灵商城【可提现余额】或商城积分中。系统保底号不受任何影响。', 'qibbs-community' ); ?>
            </div>
        </div>
        <div class="qibbs-vanity-modal-footer">
            <button type="button" class="qibbs-btn qibbs-btn-default" data-modal-close><?php esc_html_e( '考虑一下', 'qibbs-community' ); ?></button>
            <button type="button" class="qibbs-btn qibbs-btn-danger" id="qibbs-confirm-recycle-btn">
                <span class="qibbs-btn-label"><?php esc_html_e( '确认回售变现', 'qibbs-community' ); ?></span>
                <span class="qibbs-btn-loading-spinner" style="display:none;"></span>
            </button>
        </div>
    </div>
</div>
