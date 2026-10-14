<?php
/**
 * 社区成长值与积分中心页面模板
 *
 * @package Qibbs_Community
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$current_user_id = get_current_user_id();
$is_logged_in    = is_user_logged_in();
$user_info       = $is_logged_in ? wp_get_current_user() : null;
$level_info      = $is_logged_in ? qibbs_get_user_level_info( $current_user_id ) : array(
    'level'            => 1,
    'level_name'       => __( '见习萌新', 'qibbs-community' ),
    'exp'              => 0,
    'needed_exp'       => 50,
    'next_exp'         => 50,
    'progress_percent' => 0.0,
    'is_max_level'     => false,
    'color'            => '#64748b',
    'badge_class'      => 'qibbs-level-1',
);

$points_enabled  = (bool) qibbs_get_option( 'enable_points_integration', false );
$points_balance  = ( $is_logged_in && $points_enabled ) ? qibbs_get_user_points_balance( $current_user_id ) : null;
$points_name     = qibbs_get_points_name();
$levels_config   = qibbs_get_growth_levels_config();
$dual_tasks      = qibbs_get_dual_reward_definitions();

$streak_days = 0;
$is_checked_in = false;
if ( $is_logged_in && function_exists( 'qibbs_get_user_checkin_status' ) ) {
    $checkin_status = qibbs_get_user_checkin_status( $current_user_id );
    $is_checked_in  = ! empty( $checkin_status['checked_in'] );
    $streak_days    = (int) ( $checkin_status['consecutive_days'] ?? 0 );
}

if ( ! isset( $is_growth_view ) ) {
    $current_view   = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
    $is_growth_view = ( 'growth' === $current_view && qibbs_is_growth_levels_enabled() );
}
?>

<div class="qibbs-growth-center" id="qibbs-growth-center" style="<?php echo $is_growth_view ? 'display:block;' : 'display:none;'; ?>">
    <!-- 顶部面包屑与返回导航 -->
    <div class="qibbs-growth-topbar">
        <a href="<?php echo esc_url( qibbs_get_community_url() ); ?>" class="qibbs-growth-back-btn" data-filter="global" data-filter-type="feed">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor">
                <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
            </svg>
            <span><?php esc_html_e( '返回社区主页', 'qibbs-community' ); ?></span>
        </a>
        <div class="qibbs-growth-topbar-title">
            <span class="qibbs-growth-title-pill"><?php esc_html_e( '成长体系', 'qibbs-community' ); ?></span>
            <span><?php esc_html_e( '社区成长与特权中心', 'qibbs-community' ); ?></span>
        </div>
    </div>

    <!-- 用户成长看板卡片 -->
    <div class="qibbs-growth-dashboard">
        <?php if ( $is_logged_in ) : ?>
            <div class="qibbs-growth-hero">
                <div class="qibbs-growth-hero-user">
                    <a href="<?php echo esc_url( qibbs_get_profile_url( $current_user_id ) ); ?>" class="qibbs-growth-avatar-wrap">
                        <img src="<?php echo esc_url( get_avatar_url( $current_user_id, array( 'size' => 120 ) ) ); ?>" alt="" class="qibbs-growth-avatar">
                        <span class="qibbs-growth-avatar-level" style="background-color: <?php echo esc_attr( $level_info['color'] ); ?>;">Lv.<?php echo (int) $level_info['level']; ?></span>
                    </a>
                    <div class="qibbs-growth-user-meta">
                        <div class="qibbs-growth-name-row">
                            <h2 class="qibbs-growth-username"><?php echo esc_html( $user_info->display_name ); ?></h2>
                            <?php if ( function_exists( 'qibbs_render_cuid_badge' ) ) : ?>
                                <?php echo qibbs_render_cuid_badge( $current_user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php endif; ?>
                            <?php echo qibbs_render_user_level_badge( $current_user_id, true, '', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                        <p class="qibbs-growth-subtitle">
                            <?php
                            if ( ! empty( $level_info['is_max_level'] ) ) {
                                esc_html_e( '已达成最高元老阶梯，受全社区敬仰！', 'qibbs-community' );
                            } else {
                                printf(
                                    /* translators: 1: Current EXP, 2: Next level number, 3: Needed EXP */
                                    esc_html__( '当前成长值 %1$s，距离晋升 Lv.%2$d 还差 %3$d 经验值', 'qibbs-community' ),
                                    '<strong>' . number_format_i18n( $level_info['exp'] ) . '</strong>',
                                    ( (int) $level_info['level'] + 1 ),
                                    (int) $level_info['needed_exp']
                                );
                            }
                            ?>
                        </p>
                    </div>
                </div>

                <!-- 经验进度条 -->
                <div class="qibbs-growth-progress-box">
                    <div class="qibbs-growth-progress-header">
                        <span class="qibbs-growth-progress-lvl-tag" style="color: <?php echo esc_attr( $level_info['color'] ); ?>;">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" style="vertical-align:-1px;margin-right:2px;"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>Lv.<?php echo (int) $level_info['level']; ?> <?php echo esc_html( $level_info['level_name'] ); ?>
                        </span>
                        <div class="qibbs-growth-exp-numbers">
                            <span class="qibbs-growth-current-exp"><?php echo esc_html( number_format_i18n( $level_info['exp'] ) ); ?></span>
                            <?php if ( empty( $level_info['is_max_level'] ) ) : ?>
                                <span class="qibbs-growth-exp-split">/</span>
                                <span class="qibbs-growth-max-exp"><?php echo esc_html( number_format_i18n( $level_info['next_exp'] ) ); ?></span>
                            <?php endif; ?>
                            <span class="qibbs-growth-unit"><?php esc_html_e( '经验值', 'qibbs-community' ); ?></span>
                        </div>
                    </div>
                    <div class="qibbs-growth-bar-track">
                        <div class="qibbs-growth-bar-fill <?php echo esc_attr( $level_info['badge_class'] ); ?>" style="width: <?php echo esc_attr( min( 100.0, max( 0.0, (float) $level_info['progress_percent'] ) ) ); ?>%; background-color: <?php echo esc_attr( $level_info['color'] ); ?>;"></div>
                    </div>
                    <div class="qibbs-growth-progress-footer">
                        <span class="qibbs-growth-tip">
                            <?php
                            echo ! empty( $level_info['is_max_level'] )
                                ? esc_html__( '荣耀之巅，已点亮全套成就特权', 'qibbs-community' )
                                : sprintf( esc_html__( '晋级完成度 %s%%', 'qibbs-community' ), round( (float) $level_info['progress_percent'], 1 ) );
                            ?>
                        </span>
                        <?php if ( empty( $level_info['is_max_level'] ) ) : ?>
                            <span class="qibbs-growth-remain">
                                <?php printf( esc_html__( '距下一级差 %d 经验值', 'qibbs-community' ), (int) $level_info['needed_exp'] ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 资产数据矩阵胶囊 -->
                <div class="qibbs-growth-stats-grid">
                    <div class="qibbs-growth-stat-card">
                        <div class="qibbs-growth-stat-icon qibbs-stat-icon-exp">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
                        </div>
                        <div class="qibbs-growth-stat-content">
                            <span class="qibbs-growth-stat-num"><?php echo esc_html( number_format_i18n( $level_info['exp'] ) ); ?></span>
                            <span class="qibbs-growth-stat-lbl"><?php esc_html_e( '累计成长值', 'qibbs-community' ); ?></span>
                            <span class="qibbs-growth-stat-sub"><?php esc_html_e( '决定等级 / 只增不减', 'qibbs-community' ); ?></span>
                        </div>
                    </div>

                    <div class="qibbs-growth-stat-card">
                        <div class="qibbs-growth-stat-icon qibbs-stat-icon-pts">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 14h-2v-2h2v2zm0-4h-2V7h2v5z"/></svg>
                        </div>
                        <div class="qibbs-growth-stat-content">
                            <span class="qibbs-growth-stat-num">
                                <?php echo ( null !== $points_balance ) ? esc_html( qibbs_format_points_amount( $points_balance ) ) : '--'; ?>
                            </span>
                            <span class="qibbs-growth-stat-lbl"><?php echo esc_html( sprintf( __( '商城可用%s', 'qibbs-community' ), $points_name ) ); ?></span>
                            <span class="qibbs-growth-stat-sub">
                                <?php echo $points_enabled ? esc_html__( '可兑换与消费', 'qibbs-community' ) : esc_html__( '商城联动未开启', 'qibbs-community' ); ?>
                            </span>
                        </div>
                    </div>

                    <div class="qibbs-growth-stat-card">
                        <div class="qibbs-growth-stat-icon qibbs-stat-icon-checkin">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>
                        </div>
                        <div class="qibbs-growth-stat-content">
                            <span class="qibbs-growth-stat-num">
                                <?php 
                                if ( $is_checked_in ) {
                                    echo esc_html( sprintf( __( '已签 %d 天', 'qibbs-community' ), max( 1, $streak_days ) ) );
                                } else {
                                    echo esc_html( $streak_days > 0 ? sprintf( __( '连签 %d 天', 'qibbs-community' ), $streak_days ) : __( '今日未签', 'qibbs-community' ) );
                                }
                                ?>
                            </span>
                            <span class="qibbs-growth-stat-lbl"><?php esc_html_e( '打卡签到记录', 'qibbs-community' ); ?></span>
                            <span class="qibbs-growth-stat-sub"><?php echo $is_checked_in ? esc_html__( '今日奖励已领取', 'qibbs-community' ) : esc_html__( '打卡可领双重奖励', 'qibbs-community' ); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php else : ?>
            <div class="qibbs-growth-guest-hero">
                <div class="qibbs-growth-guest-icon">
                    <svg viewBox="0 0 24 24" width="48" height="48" fill="currentColor"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z"/></svg>
                </div>
                <h2><?php esc_html_e( '欢迎来到启灵社区成长中心', 'qibbs-community' ); ?></h2>
                <p><?php esc_html_e( '登录后即可通过发帖、评论、打卡等互动累积成长值与商城积分，晋级解锁 Lv.1 ~ Lv.7 专属勋章与尊贵特权！', 'qibbs-community' ); ?></p>
                <div class="qibbs-growth-guest-actions">
                    <?php echo Qibbs_Theme_Compatibility::get_login_button_html( 'qibbs-btn qibbs-btn-primary', __( '立即登录开启成长', 'qibbs-community' ) ); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- 交互选项卡 -->
    <div class="qibbs-growth-nav-tabs">
        <button type="button" class="qibbs-growth-tab-btn is-active" data-growth-tab="tasks">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14l-5-5 1.41-1.41L12 14.17l7.59-7.59L21 8l-9 9z"/></svg>
            <span><?php esc_html_e( '成长任务与奖励', 'qibbs-community' ); ?></span>
        </button>
        <button type="button" class="qibbs-growth-tab-btn" data-growth-tab="ladders">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/></svg>
            <span><?php esc_html_e( '等级阶梯与特权', 'qibbs-community' ); ?></span>
        </button>
        <button type="button" class="qibbs-growth-tab-btn" data-growth-tab="faq">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 16h-2v-2h2v2zm1.07-7.75l-.9.92C12.45 11.9 12 12.5 12 14h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H7c0-2.76 2.24-5 5-5s5 2.24 5 5c0 1.04-.42 1.99-1.07 2.75z"/></svg>
            <span><?php esc_html_e( '规则与常见问答', 'qibbs-community' ); ?></span>
        </button>
    </div>

    <!-- 选项卡面板 1: 活跃任务与奖励 -->
    <div class="qibbs-growth-panel is-active" id="qibbs-growth-panel-tasks">
        <div class="qibbs-growth-panel-header">
            <h3><?php esc_html_e( '日常互动与双轨激励矩阵', 'qibbs-community' ); ?></h3>
            <p class="description"><?php esc_html_e( '完成以下互动动作即可获取成长经验值与商城积分，每日零点自动刷新上限计数。', 'qibbs-community' ); ?></p>
        </div>

        <div class="qibbs-growth-tasks-grid">
            <?php foreach ( $dual_tasks as $action_key => $task_def ) : 
                $settings = qibbs_get_dual_reward_settings( $action_key );
                $user_counts = $is_logged_in ? qibbs_get_user_daily_action_counts( $current_user_id, $action_key ) : array( 'exp_count' => 0, 'pts_count' => 0 );
                $exp_on = ! empty( $settings['exp_enabled'] );
                $pts_on = ! empty( $settings['pts_enabled'] );
                if ( ! $exp_on && ! $pts_on ) {
                    continue; // 站长若完全关闭该任务则前台隐藏
                }

                // 判断是否达到每日上限
                $is_checkin = ( 'checkin' === $action_key );
                $exp_limit = (int) $settings['exp_daily_limit'];
                $pts_limit = (int) $settings['pts_daily_limit'];
                $is_exp_capped = ( $exp_limit > 0 && $user_counts['exp_count'] >= $exp_limit );
                $is_pts_capped = ( $pts_limit > 0 && $user_counts['pts_count'] >= $pts_limit );
                $is_fully_capped = ( $is_checkin && $is_checked_in ) || ( ( ! $exp_on || $is_exp_capped ) && ( ! $pts_on || $is_pts_capped ) );
            ?>
                <div class="qibbs-task-card <?php echo $is_fully_capped ? 'is-capped' : ''; ?>">
                    <div class="qibbs-task-card-header">
                        <div class="qibbs-task-title-wrap">
                            <h4 class="qibbs-task-title"><?php echo esc_html( $task_def['label'] ); ?></h4>
                            <p class="qibbs-task-desc"><?php echo esc_html( $task_def['description'] ); ?></p>
                        </div>
                        <?php if ( $is_fully_capped ) : ?>
                            <span class="qibbs-task-capped-pill"><?php esc_html_e( '今日已达成', 'qibbs-community' ); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- 奖励内容展示 -->
                    <div class="qibbs-task-rewards-row">
                        <?php if ( $exp_on ) : ?>
                            <div class="qibbs-reward-pill qibbs-reward-exp">
                                <span class="qibbs-reward-icon"><svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></span>
                                <span class="qibbs-reward-num">+<?php echo (int) $settings['exp_amount']; ?></span>
                                <span class="qibbs-reward-unit"><?php esc_html_e( '经验值', 'qibbs-community' ); ?></span>
                                <?php if ( $exp_limit > 0 ) : ?>
                                    <span class="qibbs-reward-limit">(<?php printf( esc_html__( '日限 %d 次', 'qibbs-community' ), $exp_limit ); ?>)</span>
                                <?php else : ?>
                                    <span class="qibbs-reward-limit">(<?php esc_html_e( '不限次', 'qibbs-community' ); ?>)</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( $pts_on && $points_enabled ) : ?>
                            <div class="qibbs-reward-pill qibbs-reward-pts">
                                <span class="qibbs-reward-icon"><svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><path d="M12 2L2 9.21L6 21H18L22 9.21L12 2ZM12 5.56L17.24 9.35L15.34 19H8.66L6.76 9.35L12 5.56Z"/></svg></span>
                                <span class="qibbs-reward-num">+<?php echo esc_html( qibbs_format_points_amount( $settings['pts_amount'] ) ); ?></span>
                                <span class="qibbs-reward-unit"><?php echo esc_html( $points_name ); ?></span>
                                <?php if ( $pts_limit > 0 ) : ?>
                                    <span class="qibbs-reward-limit">(<?php printf( esc_html__( '日限 %d 次', 'qibbs-community' ), $pts_limit ); ?>)</span>
                                <?php else : ?>
                                    <span class="qibbs-reward-limit">(<?php esc_html_e( '不限次', 'qibbs-community' ); ?>)</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 今日进度与跳转按钮 -->
                    <div class="qibbs-task-footer">
                        <div class="qibbs-task-progress-stat">
                            <?php if ( $is_logged_in ) : ?>
                                <?php if ( $is_checkin ) : ?>
                                    <span><?php echo $is_checked_in ? esc_html__( '今日打卡已完成', 'qibbs-community' ) : esc_html__( '今日尚未打卡', 'qibbs-community' ); ?></span>
                                <?php elseif ( $exp_limit > 0 ) : ?>
                                    <span><?php printf( esc_html__( '今日经验: %1$d / %2$d 次', 'qibbs-community' ), min( $user_counts['exp_count'], $exp_limit ), $exp_limit ); ?></span>
                                <?php elseif ( $exp_on ) : ?>
                                    <span><?php printf( esc_html__( '今日已获经验 %d 次', 'qibbs-community' ), $user_counts['exp_count'] ); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span><?php esc_html_e( '登录后记录今日完成进度', 'qibbs-community' ); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="qibbs-task-action-wrap">
                            <?php if ( 'checkin' === $action_key ) : ?>
                                <button type="button" class="qibbs-btn qibbs-btn-sm qibbs-btn-primary qibbs-growth-goto" data-goto="checkin" <?php echo $is_checked_in ? 'disabled' : ''; ?>>
                                    <?php echo $is_checked_in ? esc_html__( '今日已签', 'qibbs-community' ) : esc_html__( '去签到', 'qibbs-community' ); ?>
                                </button>
                            <?php elseif ( 'post_publish' === $action_key ) : ?>
                                <a href="<?php echo esc_url( qibbs_get_community_url() ); ?>" class="qibbs-btn qibbs-btn-sm qibbs-btn-secondary qibbs-growth-goto" data-goto="compose">
                                    <?php esc_html_e( '去发帖', 'qibbs-community' ); ?>
                                </a>
                            <?php else : ?>
                                <a href="<?php echo esc_url( qibbs_get_community_url() ); ?>" class="qibbs-btn qibbs-btn-sm qibbs-btn-secondary qibbs-growth-goto" data-goto="feed">
                                    <?php esc_html_e( '去互动', 'qibbs-community' ); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 选项卡面板 2: 等级阶梯与特权路线图 -->
    <div class="qibbs-growth-panel" id="qibbs-growth-panel-ladders">
        <div class="qibbs-growth-panel-header">
            <h3><?php esc_html_e( '社区成长阶梯与特权一览', 'qibbs-community' ); ?></h3>
            <p class="description"><?php esc_html_e( '成长值只增不减，随着经验值的累积，您将自动晋升并解锁更高荣誉阶梯与专属特权。', 'qibbs-community' ); ?></p>
        </div>

        <div class="qibbs-growth-ladders-roadmap">
            <?php 
            $tier_privileges = array(
                1 => __( '拥有社区基础发言权限，可发布动态、评论、点赞与收藏。', 'qibbs-community' ),
                2 => __( '点亮社区新秀翠绿荣誉标牌，提升每日互动经验获取上限。', 'qibbs-community' ),
                3 => __( '解锁天蓝进阶会员标牌，个人主页展示专属荣誉标识。', 'qibbs-community' ),
                4 => __( '尊享深蓝老手地位象征，发布的优质动态将获得更多曝光。', 'qibbs-community' ),
                5 => __( '点亮紫罗兰社区达人铭牌，优先受邀参与官方专属活动。', 'qibbs-community' ),
                6 => __( '获得琥珀核心骨干勋章，享有社区建设提议与版主推荐权。', 'qibbs-community' ),
                7 => __( '荣登元老宗师至高殿堂，享有全站尊贵红名勋章与官方专属特权！', 'qibbs-community' ),
            );

            foreach ( $levels_config as $lvl => $meta ) :
                $lvl = (int) $lvl;
                $is_current = ( $is_logged_in && (int) $level_info['level'] === $lvl );
                $is_unlocked = ( $is_logged_in && (int) $level_info['level'] >= $lvl );
                $color = esc_attr( $meta['color'] );
            ?>
                <div class="qibbs-ladder-card <?php echo $is_current ? 'is-current-tier' : ( $is_unlocked ? 'is-unlocked' : '' ); ?>" style="--tier-color: <?php echo $color; ?>;">
                    <div class="qibbs-ladder-badge-col">
                        <div class="qibbs-ladder-badge-preview">
                            <span class="qibbs-level-badge qibbs-level-<?php echo $lvl; ?>" style="background-color: rgba(<?php echo esc_attr( hexdec( substr( $meta['color'], 1, 2 ) ) . ',' . hexdec( substr( $meta['color'], 3, 2 ) ) . ',' . hexdec( substr( $meta['color'], 5, 2 ) ) ); ?>, 0.1); color: <?php echo $color; ?>; border-color: <?php echo $color; ?>;">
                                <span class="qibbs-level-icon"><svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></span>
                                <span class="qibbs-level-text">Lv.<?php echo $lvl; ?> <?php echo esc_html( $meta['name'] ); ?></span>
                            </span>
                        </div>
                        <span class="qibbs-ladder-exp-req">
                            <?php echo 1 === $lvl ? esc_html__( '0 经验值 初始', 'qibbs-community' ) : sprintf( esc_html__( '≥ %s 经验值', 'qibbs-community' ), number_format_i18n( $meta['min_exp'] ) ); ?>
                        </span>
                    </div>

                    <div class="qibbs-ladder-info-col">
                        <div class="qibbs-ladder-title-row">
                            <h4 class="qibbs-ladder-title">
                                <span class="qibbs-ladder-lvl-num">Lv.<?php echo $lvl; ?></span>
                                <span class="qibbs-ladder-name"><?php echo esc_html( $meta['name'] ); ?></span>
                            </h4>
                            <?php if ( $is_current ) : ?>
                                <span class="qibbs-ladder-status-tag is-current"><?php esc_html_e( '当前等级', 'qibbs-community' ); ?></span>
                            <?php elseif ( $is_unlocked ) : ?>
                                <span class="qibbs-ladder-status-tag is-passed"><?php esc_html_e( '已解锁', 'qibbs-community' ); ?></span>
                            <?php else : ?>
                                <span class="qibbs-ladder-status-tag is-locked"><?php esc_html_e( '待解锁', 'qibbs-community' ); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="qibbs-ladder-privilege">
                            <?php 
                            $privilege_text = ! empty( $meta['privilege'] ) ? $meta['privilege'] : ( $tier_privileges[ $lvl ] ?? __( '拥有该等级专属荣誉徽章。', 'qibbs-community' ) );
                            echo esc_html( $privilege_text ); 
                            ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 选项卡面板 3: 规则与问答 -->
    <div class="qibbs-growth-panel" id="qibbs-growth-panel-faq">
        <div class="qibbs-growth-panel-header">
            <h3><?php esc_html_e( '社区成长与双轨激励规则说明', 'qibbs-community' ); ?></h3>
            <p class="description"><?php esc_html_e( '了解社区成长经验值与积分商城的运作规则，让互动更充实更有价值。', 'qibbs-community' ); ?></p>
        </div>

        <div class="qibbs-growth-faq-list">
            <div class="qibbs-faq-card">
                <h4><span class="qibbs-faq-q">Q</span> <?php esc_html_e( '“社区成长经验值”与“商城积分”有什么区别？', 'qibbs-community' ); ?></h4>
                <div class="qibbs-faq-answer">
                    <p><?php esc_html_e( '社区构建了完全解耦的双轨激励体系：', 'qibbs-community' ); ?></p>
                    <ul>
                        <li><strong><?php esc_html_e( '社区成长经验值', 'qibbs-community' ); ?></strong>：<?php esc_html_e( '代表您在启灵社区内部的威望与资历，只增不减（除非违规被处罚扣减）。累计经验值将直接决定您的论坛等级称号 (Lv.1~Lv.7) 与前台展示勋章。', 'qibbs-community' ); ?></li>
                        <li><strong><?php esc_html_e( '商城积分', 'qibbs-community' ); ?></strong>：<?php esc_html_e( '直接联动「启灵积分商城」的账户资产体系，属于可消费、可流动的代币，可用于在积分商城中兑换优质数字权益、实体周边或靓号服务。', 'qibbs-community' ); ?></li>
                    </ul>
                </div>
            </div>

            <div class="qibbs-faq-card">
                <h4><span class="qibbs-faq-q">Q</span> <?php esc_html_e( '成长值会随时间扣减或清零吗？', 'qibbs-community' ); ?></h4>
                <div class="qibbs-faq-answer">
                    <p><?php esc_html_e( '不会！成长值是永久有效的个人荣誉资产，除非存在违规灌水、严重作弊或被管理员执行惩治处罚，正常互动累积的成长值终身有效、永不清零。', 'qibbs-community' ); ?></p>
                </div>
            </div>

            <div class="qibbs-faq-card">
                <h4><span class="qibbs-faq-q">Q</span> <?php esc_html_e( '每天完成互动有次数限制吗？', 'qibbs-community' ); ?></h4>
                <div class="qibbs-faq-answer">
                    <p><?php esc_html_e( '为了营造健康纯净的交流氛围、防止脚本恶意刷量，系统对点赞、发帖、评论等高频互动设置了每日奖励保护上限。超出每日上限后的互动仍然正常展示在社区中，但不再计入当天的奖励分值；每日零点将自动重置次数。', 'qibbs-community' ); ?></p>
                </div>
            </div>

            <div class="qibbs-faq-card">
                <h4><span class="qibbs-faq-q">Q</span> <?php esc_html_e( '什么是官方专属身份勋章？如何获得？', 'qibbs-community' ); ?></h4>
                <div class="qibbs-faq-answer">
                    <p><?php esc_html_e( '除了等级阶梯外，社区还设有「官方认证」、「核心创作者」、「特邀嘉宾」、「先锋元老」、「荣誉顾问」、「签约达人」与「官方技术」等特殊身份勋章。这些勋章由社区运营团队依据用户的突出贡献手动颁发，佩戴后将在全站尊贵展示。', 'qibbs-community' ); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
