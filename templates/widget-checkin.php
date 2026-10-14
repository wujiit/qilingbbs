<?php
/**
 * 社区右侧栏 - 每日签到打卡卡片
 *
 * @package Qibbs_Community
 * @subpackage Templates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'qibbs_is_checkin_widget_enabled' ) || ! qibbs_is_checkin_widget_enabled() ) {
    return;
}

$current_user_id = get_current_user_id();
$status          = qibbs_get_user_checkin_status( $current_user_id );
$points_name     = $status['points_name'] ?? ( function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' ) );
$checked_in      = ! empty( $status['checked_in'] );
$consecutive     = (int) ( $status['consecutive_days'] ?? 0 );
$today_points    = (float) ( $status['today_points'] ?? 1 );
$tomorrow_points = (float) ( $status['tomorrow_points'] ?? 2 );
$ladder          = $status['ladder'] ?? array();
$checkin_cards   = (int) ( $status['checkin_cards'] ?? 0 );
$can_makeup      = ! empty( $status['can_makeup'] );
$games_shop_url  = function_exists( 'qibbs_get_game_shop_url' ) ? qibbs_get_game_shop_url( 'shop' ) : ( function_exists( 'qlg_get_shop_url' ) ? qlg_get_shop_url() : home_url( '/' ) );
$checkin_uid_suffix = ! empty( $checkin_is_mobile ) ? '-mobile' : '';
?>

<div class="qibbs-widget qibbs-checkin-widget" id="qibbs-checkin-widget<?php echo esc_attr( $checkin_uid_suffix ); ?>">
    <div class="qibbs-checkin-header">
        <div class="qibbs-checkin-title-wrap">
            <span class="qibbs-checkin-icon-badge" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                    <path d="m9 16 2 2 4-4"></path>
                </svg>
            </span>
            <div class="qibbs-checkin-title-box">
                <h4 class="qibbs-checkin-title"><?php esc_html_e( '每日打卡', 'qibbs-community' ); ?></h4>
                <span class="qibbs-checkin-sub"><?php echo esc_html( sprintf( __( '领%s 换极品靓号', 'qibbs-community' ), $points_name ) ); ?></span>
            </div>
        </div>
        <span class="qibbs-checkin-badge qibbs-checkin-streak-badge <?php echo $checked_in ? 'is-checked' : ''; ?>" id="qibbs-checkin-streak-badge<?php echo esc_attr( $checkin_uid_suffix ); ?>">
            <?php
            if ( ! is_user_logged_in() ) {
                esc_html_e( '未登录', 'qibbs-community' );
            } elseif ( $checked_in ) {
                echo esc_html( sprintf( __( '已连签 %d 天', 'qibbs-community' ), $consecutive ) );
            } else {
                echo esc_html( $consecutive > 0 ? sprintf( __( '已连签 %d 天', 'qibbs-community' ), $consecutive ) : __( '今日待打卡', 'qibbs-community' ) );
            }
            ?>
        </span>
    </div>

    <!-- 7天连签阶梯进度网格 -->
    <div class="qibbs-checkin-ladder" id="qibbs-checkin-ladder<?php echo esc_attr( $checkin_uid_suffix ); ?>">
        <?php foreach ( $ladder as $item ) : 
            $day_num = (int) $item['day'];
            $pts     = (float) $item['points'];
            
            // 状态计算：已完成(is-done) / 今日目标(is-today) / 未解锁(is-future)
            $is_done  = false;
            $is_today = false;

            if ( is_user_logged_in() ) {
                if ( $checked_in ) {
                    // 今日已签到：1到consecutive天为完成，且以7天为周期循环高亮
                    $effective_streak = ( $consecutive % 7 === 0 && $consecutive > 0 ) ? 7 : ( $consecutive % 7 );
                    if ( $day_num <= $effective_streak ) {
                        $is_done = true;
                    }
                } else {
                    // 今日未签到：过去的几天为完成，今日处于(consecutive + 1)
                    $today_target = ( ( $consecutive % 7 ) + 1 );
                    if ( $day_num < $today_target ) {
                        $is_done = true;
                    } elseif ( $day_num === $today_target ) {
                        $is_today = true;
                    }
                }
            } else {
                if ( 1 === $day_num ) {
                    $is_today = true;
                }
            }
            
            $classes = array( 'qibbs-ladder-step' );
            if ( $is_done ) {
                $classes[] = 'is-done';
            } elseif ( $is_today ) {
                $classes[] = 'is-today';
            } else {
                $classes[] = 'is-future';
            }
        ?>
            <div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-day="<?php echo esc_attr( $day_num ); ?>" title="<?php echo esc_attr( sprintf( __( '第%d天打卡奖励: +%s %s', 'qibbs-community' ), $day_num, $pts, $points_name ) ); ?>">
                <div class="qibbs-step-day"><?php echo esc_html( sprintf( __( '%d天', 'qibbs-community' ), $day_num ) ); ?></div>
                <div class="qibbs-step-icon">
                    <?php if ( $is_done ) : ?>
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="3">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    <?php else : ?>
                        <span class="qibbs-step-pts">+<?php echo esc_html( $pts ); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="qibbs-checkin-hint" id="qibbs-checkin-hint<?php echo esc_attr( $checkin_uid_suffix ); ?>">
        <?php
        if ( ! is_user_logged_in() ) {
            echo esc_html( sprintf( __( '登录后打卡每日免费领 %s，连续签到收益倍增！', 'qibbs-community' ), $points_name ) );
        } elseif ( $checked_in ) {
            echo esc_html( sprintf( __( '今日打卡已完成，明天签到可领 +%s %s！', 'qibbs-community' ), $tomorrow_points, $points_name ) );
        } else {
            echo esc_html( sprintf( __( '今日打卡预计可领 +%s %s，连续签到奖励倍增！', 'qibbs-community' ), $today_points, $points_name ) );
        }
        ?>
    </div>

    <div class="qibbs-checkin-action">
        <?php if ( ! is_user_logged_in() ) : ?>
            <a href="<?php echo esc_url( wp_login_url( qibbs_get_community_url() ) ); ?>" class="qibbs-btn qibbs-btn-primary qibbs-btn-block qibbs-checkin-btn" id="qibbs-checkin-btn<?php echo esc_attr( $checkin_uid_suffix ); ?>">
                <span><?php esc_html_e( '登录并签到', 'qibbs-community' ); ?></span>
            </a>
        <?php elseif ( $checked_in ) : ?>
            <button type="button" class="qibbs-btn qibbs-btn-block qibbs-checkin-btn is-completed" id="qibbs-checkin-btn<?php echo esc_attr( $checkin_uid_suffix ); ?>" disabled>
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                <span class="qibbs-checkin-btn-label"><?php esc_html_e( '今日已签到', 'qibbs-community' ); ?></span>
            </button>
        <?php else : ?>
            <button type="button" class="qibbs-btn qibbs-btn-primary qibbs-btn-block qibbs-checkin-btn" id="qibbs-checkin-btn<?php echo esc_attr( $checkin_uid_suffix ); ?>">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="9 11 12 14 22 4"></polyline>
                    <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                </svg>
                <span class="qibbs-checkin-btn-label"><?php echo esc_html( sprintf( __( '立即打卡领 +%s %s', 'qibbs-community' ), $today_points, $points_name ) ); ?></span>
                <span class="qibbs-btn-loading-spinner" style="display:none;"></span>
            </button>
        <?php endif; ?>
    </div>

    <?php if ( is_user_logged_in() ) : ?>
        <div class="qibbs-checkin-props-wrap" id="qibbs-checkin-props-wrap<?php echo esc_attr( $checkin_uid_suffix ); ?>">
            <?php if ( $can_makeup ) : ?>
                <div class="qibbs-checkin-makeup-bar" id="qibbs-checkin-makeup-bar<?php echo esc_attr( $checkin_uid_suffix ); ?>">
                    <div class="qibbs-makeup-desc-row">
                        <span class="qibbs-makeup-badge-label">
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                            <?php esc_html_e( '昨日漏签', 'qibbs-community' ); ?>
                        </span>
                        <span class="qibbs-makeup-cards-stat">
                            <?php printf( esc_html__( '持有补签卡 %s 张', 'qibbs-community' ), '<strong class="qibbs-user-cards-count" id="qibbs-user-cards-count' . esc_attr( $checkin_uid_suffix ) . '">' . $checkin_cards . '</strong>' ); ?>
                        </span>
                    </div>
                    <div class="qibbs-makeup-btn-row">
                        <?php if ( $checkin_cards > 0 ) : ?>
                            <button type="button" class="qibbs-btn qibbs-btn-makeup" id="qibbs-btn-makeup<?php echo esc_attr( $checkin_uid_suffix ); ?>">
                                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                                <span class="qibbs-makeup-btn-text"><?php esc_html_e( '消耗1张补签昨日', 'qibbs-community' ); ?></span>
                                <span class="qibbs-btn-loading-spinner" style="display:none;"></span>
                            </button>
                        <?php else : ?>
                            <a href="<?php echo esc_url( $games_shop_url ); ?>" class="qibbs-btn qibbs-btn-get-card" target="_blank">
                                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="9" cy="21" r="1"></circle>
                                    <circle cx="20" cy="21" r="1"></circle>
                                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                                </svg>
                                <span><?php esc_html_e( '去商城兑换补签卡', 'qibbs-community' ); ?></span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else : ?>
                <div class="qibbs-checkin-inventory-bar" id="qibbs-checkin-inventory-bar<?php echo esc_attr( $checkin_uid_suffix ); ?>">
                    <span class="qibbs-inventory-stat">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                        <?php printf( esc_html__( '持有补签卡: %s 张', 'qibbs-community' ), '<strong class="qibbs-user-cards-count" id="qibbs-user-cards-count' . esc_attr( $checkin_uid_suffix ) . '">' . $checkin_cards . '</strong>' ); ?>
                    </span>
                    <a href="<?php echo esc_url( $games_shop_url ); ?>" class="qibbs-inventory-link" target="_blank">
                        <?php esc_html_e( '游戏币商城兑换', 'qibbs-community' ); ?> &rarr;
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
