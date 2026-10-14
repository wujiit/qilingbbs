<?php
/**
 * 社区 UID 全局辅助函数库
 * 提供对外模板标签、CUID 双向解析门面与靓号徽章渲染工具
 *
 * @package Qibbs_Community
 * @subpackage Helpers
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 获取指定用户的当前激活社区 UID
 *
 * @param int $user_id Optional. User ID. Defaults to current logged-in user.
 * @return int Community UID.
 */
function qibbs_get_user_cuid( $user_id = 0 ) {
    $user_id = absint( $user_id );
    if ( $user_id <= 0 ) {
        $user_id = get_current_user_id();
    }
    if ( $user_id <= 0 ) {
        return 0;
    }

    return Qibbs_Member_Service::instance()->get_user_cuid( $user_id );
}

/**
 * 根据社区 UID 反查用户 ID
 *
 * @param int $cuid Community UID.
 * @return int User ID or 0 if not found.
 */
function qibbs_get_user_id_by_cuid( $cuid ) {
    return Qibbs_Member_Service::instance()->get_user_id_by_cuid( $cuid );
}

/**
 * 获取指定用户的号码背包列表
 *
 * @param int $user_id Optional. User ID. Defaults to current user.
 * @return array
 */
function qibbs_get_user_backpack( $user_id = 0 ) {
    $user_id = absint( $user_id );
    if ( $user_id <= 0 ) {
        $user_id = get_current_user_id();
    }
    if ( $user_id <= 0 ) {
        return array();
    }

    return Qibbs_Member_Service::instance()->get_user_backpack( $user_id );
}

/**
 * 佩戴生效指定 UID
 *
 * @param int $cuid    Target CUID.
 * @param int $user_id Optional. Defaults to current user.
 * @return array{success:bool, message:string}
 */
function qibbs_equip_cuid( $cuid, $user_id = 0 ) {
    $user_id = absint( $user_id );
    if ( $user_id <= 0 ) {
        $user_id = get_current_user_id();
    }
    if ( $user_id <= 0 ) {
        return array( 'success' => false, 'message' => __( '请先登录', 'qibbs-community' ) );
    }

    return Qibbs_Member_Service::instance()->equip_cuid( $user_id, $cuid );
}

/**
 * 检查号码是否为靓号
 *
 * @param int $cuid Community UID.
 * @return bool
 */
function qibbs_is_vanity_uid( $cuid ) {
    return Qibbs_UID_Rule_Service::instance()->is_vanity( $cuid );
}

/**
 * 渲染用户社区 UID 徽章 HTML
 *
 * @param int  $user_id    User ID.
 * @param bool $show_label Whether to show 'UID' label.
 * @return string Safe HTML string.
 */
function qibbs_render_cuid_badge( $user_id = 0, $show_label = true ) {
    $user_id = absint( $user_id );
    if ( $user_id <= 0 ) {
        return '';
    }

    $cuid = qibbs_get_user_cuid( $user_id );
    if ( $cuid <= 0 ) {
        return '';
    }

    $rule_service = Qibbs_UID_Rule_Service::instance();
    $grade        = $rule_service->detect_pattern_grade( $cuid );
    $is_vanity    = ( $grade > 0 ) || $rule_service->is_vanity( $cuid );

    $grade_class = 'qibbs-uid-default';
    $grade_title = __( '社区号', 'qibbs-community' ) . ': ' . $cuid;

    if ( $is_vanity ) {
        $grade_name  = $rule_service->get_grade_label( $grade );
        $grade_title = sprintf( __( '社区靓号: %1$d (%2$s)', 'qibbs-community' ), $cuid, $grade_name );
        switch ( $grade ) {
            case 4:
                $grade_class = 'qibbs-uid-supreme';
                break;
            case 3:
                $grade_class = 'qibbs-uid-legend';
                break;
            case 2:
                $grade_class = 'qibbs-uid-gold';
                break;
            default:
                $grade_class = 'qibbs-uid-vanity';
                break;
        }
    }

    $is_me = ( $user_id === get_current_user_id() && $user_id > 0 );
    if ( $is_me ) {
        $grade_class .= ' qibbs-my-cuid-trigger';
        $grade_title .= __( ' (点击管理号码背包)', 'qibbs-community' );
    }

    if ( $is_vanity ) {
        // 炫酷靓号胶囊（完全对标图2红框：高饱和红橙渐变 + 靓⭐ + 纯白靓号数字，紧凑零距离贴合）
        $star_svg  = '<svg class="qibbs-cuid-star" viewBox="2 2 20 20" width="10" height="10" fill="#ffd700" stroke="#f59e0b" stroke-width="0.8" aria-hidden="true"><polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26"/></svg>';
        $icon_char = '靓';

        $html = sprintf(
            '<span class="qibbs-cuid-badge qibbs-uid-tag %s" title="%s"><span class="qibbs-cuid-icon-wrap"><span class="qibbs-cuid-icon-text">%s</span>%s</span><span class="qibbs-cuid-num qibbs-uid-num qibbs-current-cuid-val">%d</span></span>',
            esc_attr( $grade_class ),
            esc_attr( $grade_title ),
            esc_html( $icon_char ),
            $star_svg,
            absint( $cuid )
        );
    } else {
        // 普通社区号胶囊：按用户要求直接显示数字（不显示多余生硬的“UID”英文与“#”前缀，干净利落）
        $html = sprintf(
            '<span class="qibbs-cuid-badge qibbs-uid-tag %s" title="%s"><span class="qibbs-cuid-num qibbs-uid-num qibbs-current-cuid-val">%d</span></span>',
            esc_attr( $grade_class ),
            esc_attr( $grade_title ),
            absint( $cuid )
        );
    }

    return (string) apply_filters( 'qibbs_uid_badge_html', $html, $cuid, $grade, $user_id );
}

/**
 * 渲染社区靓号商城货架短代码
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML output.
 */
function qibbs_render_vanity_mall( $atts = array() ) {
    $atts = shortcode_atts( array(
        'per_page' => 24,
        'grade'    => 0,
        'default'  => 'official',
    ), $atts, 'qibbs_vanity_mall' );

    $per_page = min( 60, max( 6, absint( $atts['per_page'] ) ) );
    $grade    = absint( $atts['grade'] );

    $mall_data = Qibbs_Vanity_Pool_Service::instance()->get_mall_items( array(
        'per_page' => $per_page,
        'grade'    => $grade,
        'order_by' => 'sale_price',
        'order'    => 'ASC',
    ) );

    $c2c_enabled = (bool) qibbs_get_option( 'enable_c2c_trading', true );
    $c2c_data    = array( 'items' => array(), 'total' => 0 );
    if ( $c2c_enabled && class_exists( 'Qibbs_C2C_Trade_Service' ) ) {
        $c2c_data = Qibbs_C2C_Trade_Service::instance()->get_market_items( array(
            'per_page' => $per_page,
            'grade'    => $grade,
        ) );
    }

    $currency_symbol = class_exists( 'Qibbs_Payment_Manager' ) ? Qibbs_Payment_Manager::instance()->get_currency_symbol() : '¥';

    ob_start();
    ?>
    <div class="qibbs-vanity-mall-wrap">
        <div class="qibbs-mall-header">
            <h2 class="qibbs-mall-title"><?php esc_html_e( '社区靓号交易大厅', 'qibbs-community' ); ?></h2>
            <p class="qibbs-mall-desc"><?php esc_html_e( '精选吉祥靓号直购，支持微信、支付宝扫码付款与积分兑换，亦可体验玩家自由跳蚤集市。', 'qibbs-community' ); ?></p>
        </div>

        <div class="qibbs-mall-tabs-nav">
            <button type="button" class="qibbs-mall-tab-btn <?php echo 'official' === $atts['default'] || ! $c2c_enabled ? 'is-active' : ''; ?>" data-target="#qibbs-tab-official">
                <?php esc_html_e( '官方自营专区', 'qibbs-community' ); ?> <span class="qibbs-tab-count">(<?php echo esc_html( $mall_data['total'] ); ?>)</span>
            </button>
            <?php if ( $c2c_enabled ) : ?>
                <button type="button" class="qibbs-mall-tab-btn <?php echo 'c2c' === $atts['default'] ? 'is-active' : ''; ?>" data-target="#qibbs-tab-c2c">
                    <?php esc_html_e( '玩家寄售集市', 'qibbs-community' ); ?> <span class="qibbs-tab-count">(<?php echo esc_html( $c2c_data['total'] ); ?>)</span>
                </button>
            <?php endif; ?>
            <?php if ( is_user_logged_in() ) : ?>
                <button type="button" class="qibbs-mall-tab-btn qibbs-open-backpack-btn" style="margin-left:auto;">
                    <span class="dashicons dashicons-id-alt" style="font-size:16px;line-height:1.2;vertical-align:text-bottom;"></span>
                    <?php esc_html_e( '我的号码背包', 'qibbs-community' ); ?>
                </button>
            <?php endif; ?>
        </div>

        <!-- Tab 1: 官方自营货架 -->
        <div id="qibbs-tab-official" class="qibbs-mall-tab-pane <?php echo 'official' === $atts['default'] || ! $c2c_enabled ? 'is-active' : ''; ?>">
            <?php if ( empty( $mall_data['items'] ) ) : ?>
                <div class="qibbs-mall-empty" style="text-align:center;padding:40px 20px;color:#64748b;">
                    <p><?php esc_html_e( '官方货架暂无在售靓号，敬请期待下一期上架。', 'qibbs-community' ); ?></p>
                </div>
            <?php else : ?>
                <div class="qibbs-mall-grid">
                    <?php foreach ( $mall_data['items'] as $item ) : ?>
                        <?php
                        $badge_class = 'qibbs-uid-vanity';
                        if ( 4 === $item['grade'] ) {
                            $badge_class = 'qibbs-uid-supreme';
                        } elseif ( 3 === $item['grade'] ) {
                            $badge_class = 'qibbs-uid-legend';
                        } elseif ( 2 === $item['grade'] ) {
                            $badge_class = 'qibbs-uid-gold';
                        }
                        ?>
                        <div class="qibbs-mall-card">
                            <div class="qibbs-mall-card-top">
                                <span class="qibbs-uid-tag <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $item['grade_label'] ); ?></span>
                                <span class="qibbs-mall-cuid-num"><?php echo esc_html( $item['cuid'] ); ?></span>
                            </div>
                            <div class="qibbs-mall-card-price">
                                <div class="qibbs-mall-cash-price"><?php echo esc_html( $currency_symbol ); ?><strong><?php echo esc_html( number_format( (float) $item['price_cash'], 2 ) ); ?></strong></div>
                                <?php if ( (int) $item['price_points'] > 0 ) : ?>
                                    <div class="qibbs-mall-points-price"><?php printf( esc_html__( '或 %d 积分', 'qibbs-community' ), (int) $item['price_points'] ); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="qibbs-mall-card-action">
                                <button type="button" class="qibbs-btn qibbs-btn-primary qibbs-btn-block qibbs-buy-vanity-btn"
                                    data-cuid="<?php echo esc_attr( $item['cuid'] ); ?>"
                                    data-price-cash="<?php echo esc_attr( $item['price_cash'] ); ?>"
                                    data-price-points="<?php echo esc_attr( $item['price_points'] ); ?>"
                                    data-grade-label="<?php echo esc_attr( $item['grade_label'] ); ?>"
                                    data-item-type="vanity_uid">
                                    <?php esc_html_e( '立即抢购', 'qibbs-community' ); ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab 2: 玩家 C2C 寄售大厅 -->
        <?php if ( $c2c_enabled ) : ?>
            <div id="qibbs-tab-c2c" class="qibbs-mall-tab-pane <?php echo 'c2c' === $atts['default'] ? 'is-active' : ''; ?>">
                <?php if ( empty( $c2c_data['items'] ) ) : ?>
                    <div class="qibbs-mall-empty" style="text-align:center;padding:40px 20px;color:#64748b;">
                        <p><?php esc_html_e( '玩家寄售集市暂无挂牌号码，快去号码背包上架您的闲置号码吧！', 'qibbs-community' ); ?></p>
                    </div>
                <?php else : ?>
                    <div class="qibbs-mall-grid">
                        <?php foreach ( $c2c_data['items'] as $c2c_item ) : ?>
                            <?php
                            $badge_class = 'qibbs-uid-vanity';
                            if ( 4 === $c2c_item['grade'] ) {
                                $badge_class = 'qibbs-uid-supreme';
                            } elseif ( 3 === $c2c_item['grade'] ) {
                                $badge_class = 'qibbs-uid-legend';
                            } elseif ( 2 === $c2c_item['grade'] ) {
                                $badge_class = 'qibbs-uid-gold';
                            }
                            ?>
                            <div class="qibbs-mall-card qibbs-c2c-card">
                                <div class="qibbs-mall-card-top">
                                    <span class="qibbs-uid-tag <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $c2c_item['grade_label'] ); ?></span>
                                    <span class="qibbs-mall-cuid-num"><?php echo esc_html( $c2c_item['cuid'] ); ?></span>
                                </div>
                                <div class="qibbs-c2c-seller-bar" style="display:flex;align-items:center;gap:6px;font-size:12px;color:#64748b;margin:6px 0;">
                                    <img src="<?php echo esc_url( $c2c_item['seller_avatar'] ); ?>" style="width:20px;height:20px;border-radius:50%;" alt="">
                                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $c2c_item['seller_name'] ); ?></span>
                                </div>
                                <div class="qibbs-mall-card-price">
                                    <?php if ( (float) $c2c_item['price_cash'] > 0 ) : ?>
                                        <div class="qibbs-mall-cash-price"><?php echo esc_html( $currency_symbol ); ?><strong><?php echo esc_html( number_format( (float) $c2c_item['price_cash'], 2 ) ); ?></strong></div>
                                    <?php endif; ?>
                                    <?php if ( (int) $c2c_item['price_points'] > 0 ) : ?>
                                        <div class="qibbs-mall-points-price"><?php printf( esc_html__( '%d 积分', 'qibbs-community' ), (int) $c2c_item['price_points'] ); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="qibbs-mall-card-action">
                                    <button type="button" class="qibbs-btn qibbs-btn-primary qibbs-btn-block qibbs-buy-vanity-btn"
                                        data-cuid="<?php echo esc_attr( $c2c_item['cuid'] ); ?>"
                                        data-price-cash="<?php echo esc_attr( $c2c_item['price_cash'] ); ?>"
                                        data-price-points="<?php echo esc_attr( $c2c_item['price_points'] ); ?>"
                                        data-grade-label="<?php echo esc_attr( $c2c_item['grade_label'] ); ?>"
                                        data-item-type="c2c_vanity">
                                        <?php esc_html_e( '抢购该号', 'qibbs-community' ); ?>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'qibbs_vanity_mall', 'qibbs_render_vanity_mall' );
add_shortcode( 'qibbs_c2c_market', 'qibbs_render_vanity_mall' );


