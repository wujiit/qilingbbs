<?php
/**
 * 社区道具大厅与背包前台模板组件
 *
 * 提供纯积分商城道具兑换、个性昵称变色试穿预览、圈子动态置顶推广与用户道具背包管理
 *
 * @package Qibbs_Community
 * @subpackage Templates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$prop_service = class_exists( 'Qibbs_Prop_Service' ) ? Qibbs_Prop_Service::instance() : null;
if ( ! $prop_service || ! $prop_service->is_enabled() ) {
    return;
}

$current_user_id = get_current_user_id();
$points_name     = function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' );
$user_balance    = $current_user_id ? qibbs_get_user_points_balance( $current_user_id ) : null;
$active_color    = $current_user_id ? qibbs_get_user_name_color( $current_user_id ) : '';
$backpack        = $current_user_id ? $prop_service->get_user_backpack( $current_user_id ) : array( 'colors' => array(), 'promotions' => array() );
$all_props       = $prop_service->get_props( '', 'active' );

if ( ! isset( $is_prop_view ) ) {
    $current_view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
    $is_prop_view = ( 'prop-mall' === $current_view );
}
?>

<div class="qibbs-prop-mall-container" id="qibbs-prop-mall-container" style="<?php echo $is_prop_view ? 'display:block;' : 'display:none;'; ?>">

    <!-- 顶部 Hero 宣传与个人积分资产卡片 -->
    <div class="qibbs-prop-hero">
        <div class="qibbs-prop-hero-content">
            <div class="qibbs-prop-hero-badge">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
                </svg>
                <span><?php esc_html_e( '纯积分兑换 · 社区专属工具', 'qibbs-community' ); ?></span>
            </div>
            <h1 class="qibbs-prop-hero-title"><?php esc_html_e( '道具大厅', 'qibbs-community' ); ?></h1>
            <p class="qibbs-prop-hero-sub">
                <?php esc_html_e( '使用社区积分选购个性名字颜色与圈子置顶推广工具，提升辨识度与曝光率（仅限积分消耗，不支持提现与转让）', 'qibbs-community' ); ?>
            </p>
        </div>

        <?php if ( is_user_logged_in() ) : 
            $user_obj = wp_get_current_user();
        ?>
            <div class="qibbs-prop-user-summary">
                <div class="qibbs-prop-summary-card">
                    <div class="qibbs-prop-summary-item">
                        <span class="qibbs-prop-summary-label"><?php esc_html_e( '当前昵称颜色', 'qibbs-community' ); ?></span>
                        <div class="qibbs-prop-summary-val" id="qibbs-hero-active-color">
                            <span class="qibbs-color-dot" style="background:<?php echo $active_color ? esc_attr( $active_color ) : '#94a3b8'; ?>;"></span>
                            <span style="color:<?php echo $active_color ? esc_attr( $active_color ) : 'inherit'; ?>;font-weight:600;">
                                <?php echo $active_color ? esc_html__( '专属颜色中', 'qibbs-community' ) : esc_html__( '默认灰色', 'qibbs-community' ); ?>
                            </span>
                        </div>
                    </div>
                    <?php if ( null !== $user_balance ) : ?>
                        <div class="qibbs-prop-summary-divider"></div>
                        <div class="qibbs-prop-summary-item">
                            <span class="qibbs-prop-summary-label"><?php echo sprintf( esc_html__( '可用%s', 'qibbs-community' ), esc_html( $points_name ) ); ?></span>
                            <div class="qibbs-prop-summary-val qibbs-prop-points" id="qibbs-hero-prop-balance">
                                <span><?php echo esc_html( qibbs_format_points_amount( $user_balance ) ); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="qibbs-prop-summary-actions">
                    <button type="button" class="qibbs-btn qibbs-btn-primary qibbs-btn-sm" id="qibbs-tab-backpack-trigger">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor">
                            <path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/>
                        </svg>
                        <span><?php esc_html_e( '我的背包', 'qibbs-community' ); ?></span>
                    </button>
                </div>
            </div>
        <?php else : ?>
            <div class="qibbs-prop-user-summary qibbs-guest-summary">
                <p class="qibbs-guest-hint"><?php esc_html_e( '登录后可查看可用积分余额并兑换专属装扮与置顶推广', 'qibbs-community' ); ?></p>
                <?php echo Qibbs_Theme_Compatibility::get_login_button_html( 'qibbs-btn qibbs-btn-primary qibbs-btn-sm', __( '立即登录', 'qibbs-community' ) ); ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- 交互式试穿预览工作台 (Live Try-On Workbench) -->
    <?php if ( is_user_logged_in() ) : 
        $user_obj = wp_get_current_user();
    ?>
        <div class="qibbs-prop-tryon-card" id="qibbs-prop-tryon-card">
            <div class="qibbs-tryon-header">
                <div class="qibbs-tryon-title">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                    <span><?php esc_html_e( '名字颜色实时试穿预览', 'qibbs-community' ); ?></span>
                </div>
                <div class="qibbs-tryon-tip"><?php esc_html_e( '点击下方颜色道具卡片即可即时试穿，满意后再兑换', 'qibbs-community' ); ?></div>
            </div>
            <div class="qibbs-tryon-preview-box">
                <div class="qibbs-tryon-simulated-post">
                    <div class="qibbs-tryon-avatar">
                        <img src="<?php echo esc_url( get_avatar_url( $current_user_id, array( 'size' => 44 ) ) ); ?>" alt="">
                    </div>
                    <div class="qibbs-tryon-meta">
                        <div class="qibbs-tryon-author-line">
                            <span class="qibbs-tryon-name" id="qibbs-tryon-sample-name" style="color:<?php echo $active_color ? esc_attr( $active_color ) : 'inherit'; ?>;font-weight:600;">
                                <?php echo esc_html( $user_obj->display_name ); ?>
                            </span>
                            <?php if ( function_exists( 'qibbs_render_cuid_badge' ) ) : ?>
                                <?php echo qibbs_render_cuid_badge( $current_user_id ); ?>
                            <?php endif; ?>
                            <?php if ( function_exists( 'qibbs_render_user_level_badge' ) ) : ?>
                                <?php echo qibbs_render_user_level_badge( $current_user_id ); ?>
                            <?php endif; ?>
                        </div>
                        <div class="qibbs-tryon-subline">
                            <span class="qibbs-tryon-circle"># <?php esc_html_e( '官方交流圈', 'qibbs-community' ); ?></span>
                            <span>·</span>
                            <span><?php esc_html_e( '刚刚', 'qibbs-community' ); ?></span>
                            <span>·</span>
                            <span id="qibbs-tryon-color-label" style="color:var(--qibbs-text-muted);">
                                <?php echo $active_color ? sprintf( esc_html__( '已生效颜色：%s', 'qibbs-community' ), esc_html( $active_color ) ) : esc_html__( '当前默认颜色', 'qibbs-community' ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 导航切换 Tabs -->
    <div class="qibbs-prop-nav-bar">
        <div class="qibbs-prop-tabs">
            <button type="button" class="qibbs-prop-tab-btn is-active" data-tab="all"><?php esc_html_e( '全部道具', 'qibbs-community' ); ?></button>
            <button type="button" class="qibbs-prop-tab-btn" data-tab="name_color"><?php esc_html_e( '个性名字颜色', 'qibbs-community' ); ?></button>
            <button type="button" class="qibbs-prop-tab-btn" data-tab="post_sticky"><?php esc_html_e( '圈子置顶推广', 'qibbs-community' ); ?></button>
            <?php if ( is_user_logged_in() ) : ?>
                <button type="button" class="qibbs-prop-tab-btn" data-tab="backpack" id="qibbs-prop-backpack-btn">
                    <?php esc_html_e( '我的背包', 'qibbs-community' ); ?>
                    <span class="qibbs-backpack-count" id="qibbs-backpack-count-badge" style="<?php echo ( count( $backpack['colors'] ) + count( $backpack['promotions'] ) > 0 ) ? '' : 'display:none;'; ?>">
                        <?php echo esc_html( count( $backpack['colors'] ) + count( $backpack['promotions'] ) ); ?>
                    </span>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- 道具商城专柜列表 -->
    <div class="qibbs-prop-view-section" id="qibbs-prop-mall-list-section">
        <div class="qibbs-prop-grid" id="qibbs-prop-grid">
            <?php if ( empty( $all_props ) ) : ?>
                <div class="qibbs-prop-empty-box">
                    <p><?php esc_html_e( '道具大厅正在上新筹备中，敬请期待！', 'qibbs-community' ); ?></p>
                </div>
            <?php else : ?>
                <?php foreach ( $all_props as $prop ) : 
                    $is_color = ( 'name_color' === $prop->prop_type );
                    $color_hex = ( $is_color && ! empty( $prop->meta_data['color'] ) ) ? $prop->meta_data['color'] : '#ef4444';
                    $is_sold_out = ( $prop->stock >= 0 && $prop->stock <= 0 );
                ?>
                    <div class="qibbs-prop-card" data-prop-id="<?php echo esc_attr( $prop->id ); ?>" data-prop-type="<?php echo esc_attr( $prop->prop_type ); ?>" data-color="<?php echo esc_attr( $color_hex ); ?>" data-price="<?php echo esc_attr( $prop->price_points ); ?>">
                        <!-- 卡片头部徽标 -->
                        <div class="qibbs-prop-card-badge-row">
                            <?php if ( $is_color ) : ?>
                                <span class="qibbs-prop-type-pill qibbs-pill-color"><?php esc_html_e( '个性装扮', 'qibbs-community' ); ?></span>
                            <?php else : ?>
                                <span class="qibbs-prop-type-pill qibbs-pill-sticky"><?php esc_html_e( '置顶推广', 'qibbs-community' ); ?></span>
                            <?php endif; ?>

                            <?php if ( $prop->stock >= 0 ) : ?>
                                <span class="qibbs-prop-stock-pill <?php echo $is_sold_out ? 'is-out' : ''; ?>">
                                    <?php echo $is_sold_out ? esc_html__( '已售罄', 'qibbs-community' ) : sprintf( esc_html__( '限量余 %d', 'qibbs-community' ), (int) $prop->stock ); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- 视觉展示区 -->
                        <div class="qibbs-prop-card-visual">
                            <?php if ( $is_color ) : ?>
                                <div class="qibbs-color-preview-sphere" style="background:radial-gradient(circle at 35% 35%, #ffffff 0%, <?php echo esc_attr( $color_hex ); ?> 65%, #0f172a 100%);box-shadow:0 8px 20px -4px <?php echo esc_attr( $color_hex ); ?>80;">
                                    <span class="qibbs-color-inner-ring"></span>
                                </div>
                            <?php else : ?>
                                <div class="qibbs-sticky-preview-box">
                                    <svg viewBox="0 0 24 24" width="36" height="36" fill="currentColor">
                                        <path d="M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 10h2v4h-2zm0 5h2v2h-2z"/>
                                    </svg>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- 道具标题与说明 -->
                        <div class="qibbs-prop-card-body">
                            <h3 class="qibbs-prop-card-title"><?php echo esc_html( $prop->name ); ?></h3>
                            <p class="qibbs-prop-card-desc"><?php echo esc_html( $prop->description ? $prop->description : ( $is_color ? __( '全站动态、评论区昵称变色', 'qibbs-community' ) : __( '在所属圈子动态流置顶并标注「推广」', 'qibbs-community' ) ) ); ?></p>
                            
                            <div class="qibbs-prop-card-meta">
                                <span class="qibbs-prop-duration">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
                                    <?php echo (int) $prop->duration_days > 0 ? sprintf( esc_html__( '%d 天有效期', 'qibbs-community' ), (int) $prop->duration_days ) : esc_html__( '永久有效', 'qibbs-community' ); ?>
                                </span>
                            </div>
                        </div>

                        <!-- 价格与购买交互区 -->
                        <div class="qibbs-prop-card-footer">
                            <div class="qibbs-prop-price-row">
                                <span class="qibbs-prop-price-val"><?php echo esc_html( qibbs_format_points_amount( $prop->price_points ) ); ?></span>
                                <span class="qibbs-prop-price-unit"><?php echo esc_html( $points_name ); ?></span>
                            </div>

                            <div class="qibbs-prop-btn-row">
                                <?php if ( $is_color ) : ?>
                                    <button type="button" class="qibbs-btn qibbs-btn-outline qibbs-tryon-btn" data-color="<?php echo esc_attr( $color_hex ); ?>" data-name="<?php echo esc_attr( $prop->name ); ?>">
                                        <?php esc_html_e( '试穿', 'qibbs-community' ); ?>
                                    </button>
                                <?php endif; ?>

                                <?php if ( $is_sold_out ) : ?>
                                    <button type="button" class="qibbs-btn qibbs-btn-disabled" disabled>
                                        <?php esc_html_e( '已售罄', 'qibbs-community' ); ?>
                                    </button>
                                <?php else : ?>
                                    <button type="button" class="qibbs-btn qibbs-btn-primary qibbs-buy-prop-btn" data-prop-id="<?php echo esc_attr( $prop->id ); ?>" data-prop-type="<?php echo esc_attr( $prop->prop_type ); ?>" data-prop-name="<?php echo esc_attr( $prop->name ); ?>" data-price="<?php echo esc_attr( $prop->price_points ); ?>">
                                        <?php echo $is_color ? esc_html__( '立即兑换', 'qibbs-community' ) : esc_html__( '选用置顶', 'qibbs-community' ); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 用户背包管理面板 -->
    <div class="qibbs-prop-view-section" id="qibbs-prop-backpack-section" style="display:none;">
        <div class="qibbs-backpack-header-row">
            <div>
                <h2 class="qibbs-backpack-title"><?php esc_html_e( '我的道具背包', 'qibbs-community' ); ?></h2>
                <p class="qibbs-backpack-sub"><?php esc_html_e( '管理已兑换的昵称变色装扮与正在生效中的置顶推广动态', 'qibbs-community' ); ?></p>
            </div>
            <?php if ( $active_color ) : ?>
                <button type="button" class="qibbs-btn qibbs-btn-outline qibbs-btn-sm" id="qibbs-unequip-color-btn">
                    <?php esc_html_e( '卸下名字颜色', 'qibbs-community' ); ?>
                </button>
            <?php endif; ?>
        </div>

        <!-- 名字颜色分类栏 -->
        <div class="qibbs-backpack-group">
            <h3 class="qibbs-group-title">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 3c-4.97 0-9 4.03-9 9 0 2.12.74 4.07 1.97 5.61L4.35 19.4c-.39.39-.39 1.02 0 1.41.39.39 1.02.39 1.41 0l1.9-1.9C9.27 19.64 10.59 20 12 20c4.97 0 9-4.03 9-9s-4.03-9-9-9zm0 15c-1.15 0-2.22-.3-3.17-.82l-.4-.22-1.85 1.85 1.15-2.07-.22-.39C6.88 15.42 6.5 14.26 6.5 13c0-3.03 2.47-5.5 5.5-5.5s5.5 2.47 5.5 5.5-2.47 5.5-5.5 5.5z"/></svg>
                <span><?php esc_html_e( '已拥有的名字颜色', 'qibbs-community' ); ?></span>
            </h3>

            <div class="qibbs-backpack-grid" id="qibbs-backpack-colors-grid">
                <?php if ( empty( $backpack['colors'] ) ) : ?>
                    <div class="qibbs-backpack-empty-card">
                        <p><?php esc_html_e( '暂无已兑换的名字颜色，前往专柜挑选中意的专属颜色吧！', 'qibbs-community' ); ?></p>
                    </div>
                <?php else : ?>
                    <?php foreach ( $backpack['colors'] as $c_item ) : ?>
                        <div class="qibbs-backpack-item <?php echo ! empty( $c_item['is_equipped'] ) ? 'is-equipped' : ''; ?> <?php echo ! empty( $c_item['is_expired'] ) ? 'is-expired' : ''; ?>" data-user-prop-id="<?php echo esc_attr( $c_item['id'] ); ?>">
                            <div class="qibbs-backpack-visual">
                                <span class="qibbs-color-pill-sample" style="background:<?php echo esc_attr( $c_item['color'] ); ?>;"></span>
                            </div>
                            <div class="qibbs-backpack-meta">
                                <div class="qibbs-backpack-item-name" style="color:<?php echo esc_attr( $c_item['color'] ); ?>;font-weight:600;">
                                    <?php echo esc_html( $c_item['prop_name'] ); ?>
                                </div>
                                <div class="qibbs-backpack-item-tip">
                                    <?php echo esc_html( $c_item['expires_tip'] ); ?>
                                </div>
                            </div>
                            <div class="qibbs-backpack-action">
                                <?php if ( ! empty( $c_item['is_expired'] ) ) : ?>
                                    <span class="qibbs-badge-expired"><?php esc_html_e( '已到期', 'qibbs-community' ); ?></span>
                                <?php elseif ( ! empty( $c_item['is_equipped'] ) ) : ?>
                                    <span class="qibbs-badge-active-equipped"><?php esc_html_e( '佩戴中', 'qibbs-community' ); ?></span>
                                <?php else : ?>
                                    <button type="button" class="qibbs-btn qibbs-btn-primary qibbs-btn-sm qibbs-equip-color-btn" data-user-prop-id="<?php echo esc_attr( $c_item['id'] ); ?>" data-color="<?php echo esc_attr( $c_item['color'] ); ?>">
                                        <?php esc_html_e( '佩戴', 'qibbs-community' ); ?>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- 置顶推广记录分类栏 -->
        <div class="qibbs-backpack-group" style="margin-top:28px;">
            <h3 class="qibbs-group-title">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 10h2v4h-2zm0 5h2v2h-2z"/></svg>
                <span><?php esc_html_e( '我的置顶推广动态', 'qibbs-community' ); ?></span>
            </h3>

            <div class="qibbs-backpack-grid" id="qibbs-backpack-promotions-grid">
                <?php if ( empty( $backpack['promotions'] ) ) : ?>
                    <div class="qibbs-backpack-empty-card">
                        <p><?php esc_html_e( '暂无置顶推广记录，前往圈子发表动态并选购推广吧！', 'qibbs-community' ); ?></p>
                    </div>
                <?php else : ?>
                    <?php foreach ( $backpack['promotions'] as $pr_item ) : ?>
                        <div class="qibbs-backpack-item <?php echo ! empty( $pr_item['is_expired'] ) ? 'is-expired' : 'is-promoted'; ?>">
                            <div class="qibbs-backpack-visual">
                                <span class="qibbs-promo-flag">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 10h2v4h-2zm0 5h2v2h-2z"/></svg>
                                </span>
                            </div>
                            <div class="qibbs-backpack-meta">
                                <div class="qibbs-backpack-item-name">
                                    <?php echo sprintf( esc_html__( '动态 #%d 置顶推广', 'qibbs-community' ), (int) $pr_item['post_id'] ); ?>
                                </div>
                                <div class="qibbs-backpack-item-tip">
                                    <?php echo esc_html( $pr_item['expires_tip'] ); ?>
                                </div>
                            </div>
                            <div class="qibbs-backpack-action">
                                <?php if ( ! empty( $pr_item['post_id'] ) ) : ?>
                                    <a href="<?php echo esc_url( qibbs_get_post_url( $pr_item['post_id'] ) ); ?>" class="qibbs-btn qibbs-btn-outline qibbs-btn-sm" target="_blank">
                                        <?php esc_html_e( '查看动态', 'qibbs-community' ); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 购买颜色确认弹窗 -->
<div class="qibbs-modal-overlay" id="qibbs-buy-color-modal" style="display:none;">
    <div class="qibbs-modal-dialog">
        <div class="qibbs-modal-header">
            <h3 class="qibbs-modal-title"><?php esc_html_e( '确认选购名字变色', 'qibbs-community' ); ?></h3>
            <button type="button" class="qibbs-modal-close" data-modal-close>&times;</button>
        </div>
        <div class="qibbs-modal-body">
            <div class="qibbs-buy-color-summary">
                <div class="qibbs-modal-color-preview" id="qibbs-modal-color-sphere"></div>
                <div class="qibbs-modal-color-info">
                    <h4 id="qibbs-modal-prop-name" style="margin:0 0 4px;font-size:16px;"></h4>
                    <p style="margin:0;font-size:13px;color:var(--qibbs-text-muted);"><?php esc_html_e( '兑换后即刻自动佩戴生效，全站动态与评论均高亮显现', 'qibbs-community' ); ?></p>
                </div>
            </div>

            <div class="qibbs-modal-cost-box">
                <div class="qibbs-cost-row">
                    <span><?php echo sprintf( esc_html__( '需支付%s', 'qibbs-community' ), esc_html( $points_name ) ); ?></span>
                    <strong class="qibbs-cost-val" id="qibbs-modal-cost-val">0</strong>
                </div>
                <div class="qibbs-cost-row">
                    <span><?php esc_html_e( '当前可用余额', 'qibbs-community' ); ?></span>
                    <span id="qibbs-modal-user-balance"><?php echo esc_html( qibbs_format_points_amount( (float) $user_balance ) ); ?></span>
                </div>
            </div>
        </div>
        <div class="qibbs-modal-footer">
            <button type="button" class="qibbs-btn qibbs-btn-secondary" data-modal-close><?php esc_html_e( '取消', 'qibbs-community' ); ?></button>
            <button type="button" class="qibbs-btn qibbs-btn-primary" id="qibbs-confirm-buy-color-btn"><?php esc_html_e( '确认兑换并佩戴', 'qibbs-community' ); ?></button>
        </div>
    </div>
</div>

<!-- 置顶推广选帖弹窗 -->
<div class="qibbs-modal-overlay" id="qibbs-sticky-select-modal" style="display:none;">
    <div class="qibbs-modal-dialog qibbs-modal-dialog-lg">
        <div class="qibbs-modal-header">
            <h3 class="qibbs-modal-title"><?php esc_html_e( '选择要置顶推广的动态', 'qibbs-community' ); ?></h3>
            <button type="button" class="qibbs-modal-close" data-modal-close>&times;</button>
        </div>
        <div class="qibbs-modal-body">
            <div class="qibbs-sticky-prop-summary">
                <div class="qibbs-sticky-summary-badge">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 10h2v4h-2zm0 5h2v2h-2z"/></svg>
                </div>
                <div>
                    <h4 id="qibbs-sticky-modal-prop-name" style="margin:0 0 2px;"></h4>
                    <p style="margin:0;font-size:12px;color:var(--qibbs-text-muted);">
                        <?php echo sprintf( esc_html__( '需消耗 %s 积分，置顶期间在圈子顶部打上「推广」角标', 'qibbs-community' ), '<span id="qibbs-sticky-modal-cost" style="color:#d97706;font-weight:700;">0</span>' ); ?>
                    </p>
                </div>
            </div>

            <div class="qibbs-sticky-post-list-container">
                <label style="display:block;margin-bottom:8px;font-size:13px;font-weight:600;">
                    <?php esc_html_e( '选择你发表的动态（已自动排除草稿）：', 'qibbs-community' ); ?>
                </label>
                <div class="qibbs-sticky-post-list" id="qibbs-sticky-post-list">
                    <div class="qibbs-loading" style="padding:24px 0;"><?php esc_html_e( '正在读取你的动态...', 'qibbs-community' ); ?></div>
                </div>
            </div>
        </div>
        <div class="qibbs-modal-footer">
            <button type="button" class="qibbs-btn qibbs-btn-secondary" data-modal-close><?php esc_html_e( '取消', 'qibbs-community' ); ?></button>
            <button type="button" class="qibbs-btn qibbs-btn-primary" id="qibbs-confirm-sticky-btn" disabled><?php esc_html_e( '确认兑换并置顶', 'qibbs-community' ); ?></button>
        </div>
    </div>
</div>
