<?php
/**
 * 社区成长等级与双轨激励辅助函数库
 *
 * @package Qibbs_Community
 * @subpackage Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 检查社区成长等级体系是否全局启用
 *
 * @return bool
 */
function qibbs_is_growth_levels_enabled() {
    return (bool) qibbs_get_option( 'enable_growth_levels', true );
}

/**
 * 获取前台社区成长值积分中心页面链接
 *
 * @param array $args Optional query parameters.
 * @return string
 */
function qibbs_get_growth_center_url( $args = array() ) {
    $params = array_merge( array( 'view' => 'growth' ), $args );
    return qibbs_get_community_url( $params );
}

/**
 * 查询指定用户在当天某动作已完成的计数记录（成长值与积分）
 *
 * @param int    $user_id    User ID.
 * @param string $action_key Action key.
 * @return array{exp_count:int,pts_count:int}
 */
function qibbs_get_user_daily_action_counts( $user_id, $action_key ) {
    $user_id = absint( $user_id );
    if ( ! $user_id ) {
        return array( 'exp_count' => 0, 'pts_count' => 0 );
    }

    if ( class_exists( 'Qibbs_Level_Service' ) ) {
        return Qibbs_Level_Service::get_instance()->get_daily_action_counts( $user_id, $action_key );
    }

    $date_key = '_qibbs_daily_rewards_' . current_time( 'Ymd' );
    $counts = get_user_meta( $user_id, $date_key, true );
    if ( ! is_array( $counts ) ) {
        $counts = array();
    }

    $action_counts = isset( $counts[ $action_key ] ) && is_array( $counts[ $action_key ] )
        ? $counts[ $action_key ]
        : array( 'exp_count' => 0, 'pts_count' => 0 );

    return array(
        'exp_count' => absint( $action_counts['exp_count'] ?? 0 ),
        'pts_count' => absint( $action_counts['pts_count'] ?? 0 ),
    );
}

/**
 * 获取默认社区成长等级配置阶梯
 *
 * @return array<int,array<string,mixed>>
 */
function qibbs_get_default_growth_levels() {
    return array(
        1 => array(
            'level'                => 1,
            'name'                 => __( '见习萌新', 'qibbs-community' ),
            'min_exp'              => 0,
            'color'                => '#64748b',
            'badge_class'          => 'qibbs-level-1',
            'privilege'            => __( '拥有社区基础发言权限，可发布动态、评论、点赞与收藏。', 'qibbs-community' ),
            'post_hourly_limit'    => 3,
            'comment_hourly_limit' => 10,
        ),
        2 => array(
            'level'                => 2,
            'name'                 => __( '社区新秀', 'qibbs-community' ),
            'min_exp'              => 50,
            'color'                => '#10b981',
            'badge_class'          => 'qibbs-level-2',
            'privilege'            => __( '点亮社区新秀翠绿荣誉标牌，提升发帖与回帖频控限额。', 'qibbs-community' ),
            'post_hourly_limit'    => 6,
            'comment_hourly_limit' => 20,
        ),
        3 => array(
            'level'                => 3,
            'name'                 => __( '进阶会员', 'qibbs-community' ),
            'min_exp'              => 200,
            'color'                => '#0ea5e9',
            'badge_class'          => 'qibbs-level-3',
            'privilege'            => __( '解锁天蓝进阶会员标牌，个人主页展示专属荣誉标识，发言频度翻倍。', 'qibbs-community' ),
            'post_hourly_limit'    => 12,
            'comment_hourly_limit' => 40,
        ),
        4 => array(
            'level'                => 4,
            'name'                 => __( '论坛老手', 'qibbs-community' ),
            'min_exp'              => 500,
            'color'                => '#6366f1',
            'badge_class'          => 'qibbs-level-4',
            'privilege'            => __( '尊享深蓝老手地位象征，发布的优质动态将获得更多曝光与更高发布额度。', 'qibbs-community' ),
            'post_hourly_limit'    => 25,
            'comment_hourly_limit' => 80,
        ),
        5 => array(
            'level'                => 5,
            'name'                 => __( '社区达人', 'qibbs-community' ),
            'min_exp'              => 1200,
            'color'                => '#8b5cf6',
            'badge_class'          => 'qibbs-level-5',
            'privilege'            => __( '点亮紫罗兰社区达人铭牌，尊享高频互动额度与专属活动邀请。', 'qibbs-community' ),
            'post_hourly_limit'    => 50,
            'comment_hourly_limit' => 150,
        ),
        6 => array(
            'level'                => 6,
            'name'                 => __( '核心骨干', 'qibbs-community' ),
            'min_exp'              => 3000,
            'color'                => '#f59e0b',
            'badge_class'          => 'qibbs-level-6',
            'privilege'            => __( '获得琥珀核心骨干勋章，享有社区建设提议与超高发言自由度。', 'qibbs-community' ),
            'post_hourly_limit'    => 100,
            'comment_hourly_limit' => 300,
        ),
        7 => array(
            'level'                => 7,
            'name'                 => __( '元老宗师', 'qibbs-community' ),
            'min_exp'              => 8000,
            'color'                => '#f43f5e',
            'badge_class'          => 'qibbs-level-7',
            'privilege'            => __( '荣登元老宗师至高殿堂，全站尊贵红名勋章，尊享发帖与回帖无限制特权！', 'qibbs-community' ),
            'post_hourly_limit'    => 0,
            'comment_hourly_limit' => 0,
        ),
    );
}

/**
 * 获取系统当前生效的成长等级阶梯配置（绝不写死，优先读取后台自定义）
 *
 * @return array<int,array<string,mixed>>
 */
function qibbs_get_growth_levels_config() {
    $defaults = qibbs_get_default_growth_levels();
    $saved = qibbs_get_option( 'growth_levels_config', null );

    if ( ! is_array( $saved ) || empty( $saved ) ) {
        return (array) apply_filters( 'qibbs_growth_levels_config', $defaults );
    }

    $normalized = array();
    foreach ( $defaults as $lvl => $default_meta ) {
        $meta = isset( $saved[ $lvl ] ) && is_array( $saved[ $lvl ] ) ? $saved[ $lvl ] : array();
        $name = isset( $meta['name'] ) && '' !== trim( (string) $meta['name'] )
            ? sanitize_text_field( trim( (string) $meta['name'] ) )
            : $default_meta['name'];
        $min_exp = isset( $meta['min_exp'] )
            ? absint( $meta['min_exp'] )
            : (int) $default_meta['min_exp'];
        $privilege = isset( $meta['privilege'] ) && '' !== trim( (string) $meta['privilege'] )
            ? sanitize_textarea_field( trim( (string) $meta['privilege'] ) )
            : ( $default_meta['privilege'] ?? '' );

        $normalized[ $lvl ] = array(
            'level'                => (int) $lvl,
            'name'                 => $name,
            'min_exp'              => 1 === $lvl ? 0 : $min_exp,
            'color'                => isset( $meta['color'] ) && '' !== trim( (string) $meta['color'] ) ? sanitize_hex_color( $meta['color'] ) : $default_meta['color'],
            'badge_class'          => isset( $meta['badge_class'] ) && '' !== trim( (string) $meta['badge_class'] ) ? sanitize_html_class( $meta['badge_class'] ) : $default_meta['badge_class'],
            'privilege'            => $privilege,
            'post_hourly_limit'    => isset( $meta['post_hourly_limit'] ) ? max( 0, absint( $meta['post_hourly_limit'] ) ) : (int) ( $default_meta['post_hourly_limit'] ?? 3 ),
            'comment_hourly_limit' => isset( $meta['comment_hourly_limit'] ) ? max( 0, absint( $meta['comment_hourly_limit'] ) ) : (int) ( $default_meta['comment_hourly_limit'] ?? 10 ),
        );
    }

    // 确保按升级所需经验升序排列
    uasort( $normalized, function( $a, $b ) {
        return $a['min_exp'] <=> $b['min_exp'];
    } );

    return (array) apply_filters( 'qibbs_growth_levels_config', $normalized );
}

/**
 * 获取双轨行为激励动作完整定义（成长值 EXP vs 商城积分 Points）
 *
 * 每个行为均支持独立自定义：
 * - 是否奖励成长值、数值、每日上限
 * - 是否赠送商城积分、数值、每日上限
 * 支持双边赠送、单边赠送（只涨成长值或只送积分）、完全不送！
 *
 * @return array<string,array<string,mixed>>
 */
function qibbs_get_dual_reward_definitions() {
    $definitions = array(
        'post_publish' => array(
            'label'                  => __( '发布动态', 'qibbs-community' ),
            'description'            => __( '用户成功发布社区动态时触发。仅对原生动态生效。', 'qibbs-community' ),
            'default_exp_enabled'    => true,
            'default_exp_amount'     => 10,
            'default_exp_daily'      => 5,
            'default_points_enabled' => true,
            'default_points_amount'  => 2.0,
            'default_points_daily'   => 3,
        ),
        'comment_approved' => array(
            'label'                  => __( '发表评论/回复', 'qibbs-community' ),
            'description'            => __( '发表评论并成功审核通过时触发。鼓励日常互动交流。', 'qibbs-community' ),
            'default_exp_enabled'    => true,
            'default_exp_amount'     => 2,
            'default_exp_daily'      => 10,
            'default_points_enabled' => true,
            'default_points_amount'  => 1.0,
            'default_points_daily'   => 5,
        ),
        'post_liked' => array(
            'label'                  => __( '动态获得点赞', 'qibbs-community' ),
            'description'            => __( '作者的动态收到他人点赞时奖励给作者。推荐单边增加成长值防刷币。', 'qibbs-community' ),
            'default_exp_enabled'    => true,
            'default_exp_amount'     => 2,
            'default_exp_daily'      => 15,
            'default_points_enabled' => false,
            'default_points_amount'  => 0,
            'default_points_daily'   => 0,
        ),
        'checkin' => array(
            'label'                  => __( '每日打卡签到', 'qibbs-community' ),
            'description'            => __( '用户每日完成打卡签到时额外奖励成长值（商城积分由签到梯子主控）。', 'qibbs-community' ),
            'default_exp_enabled'    => true,
            'default_exp_amount'     => 5,
            'default_exp_daily'      => 1,
            'default_points_enabled' => false,
            'default_points_amount'  => 0,
            'default_points_daily'   => 0,
        ),
        'best_answer' => array(
            'label'                  => __( '被采纳为最佳答案', 'qibbs-community' ),
            'description'            => __( '悬赏回复被采纳为最佳答案时触发，奖励专业互助者成长值（积分由商城积分中枢统一主控）。', 'qibbs-community' ),
            'default_exp_enabled'    => true,
            'default_exp_amount'     => 30,
            'default_exp_daily'      => 0,
            'default_points_enabled' => true,
            'default_points_amount'  => 15.0,
            'default_points_daily'   => 0,
        ),
        'featured_post' => array(
            'label'                  => __( '动态被设为精华/推荐', 'qibbs-community' ),
            'description'            => __( '优质动态被管理员加精推荐时触发，荣誉与丰厚双轨激励。', 'qibbs-community' ),
            'default_exp_enabled'    => true,
            'default_exp_amount'     => 50,
            'default_exp_daily'      => 0,
            'default_points_enabled' => true,
            'default_points_amount'  => 20.0,
            'default_points_daily'   => 0,
        ),
        'ticket_published' => array(
            'label'                  => __( '工单转为公开帖', 'qibbs-community' ),
            'description'            => __( '有价值的技术工单成功转为社区公开帖时触发，沉淀知识库。', 'qibbs-community' ),
            'default_exp_enabled'    => true,
            'default_exp_amount'     => 20,
            'default_exp_daily'      => 0,
            'default_points_enabled' => true,
            'default_points_amount'  => 10.0,
            'default_points_daily'   => 0,
        ),
    );

    return (array) apply_filters( 'qibbs_dual_reward_definitions', $definitions );
}

/**
 * 获取指定行为的当前生效双轨配置（绝不写死，优先读取后台设置）
 *
 * @param string $action_key Action identifier.
 * @return array<string,mixed>
 */
function qibbs_get_dual_reward_settings( $action_key ) {
    $definitions = qibbs_get_dual_reward_definitions();
    $action_key = sanitize_key( (string) $action_key );
    if ( ! isset( $definitions[ $action_key ] ) ) {
        return array();
    }

    $def = $definitions[ $action_key ];

    // 成长值配置键名
    $exp_enabled_key = 'reward_' . $action_key . '_exp_enabled';
    $exp_amount_key  = 'reward_' . $action_key . '_exp_amount';
    $exp_daily_key   = 'reward_' . $action_key . '_exp_daily_limit';

    // 商城积分配置键名（向后兼容旧版 points_reward_xxx 键名）
    $pts_enabled_key = 'points_reward_' . $action_key . '_enabled';
    $pts_amount_key  = 'points_reward_' . $action_key . '_amount';
    $pts_daily_key   = 'points_reward_' . $action_key . '_daily_limit';

    return array(
        'label'          => $def['label'],
        'description'    => $def['description'],
        'exp_enabled'    => (bool) qibbs_get_option( $exp_enabled_key, ! empty( $def['default_exp_enabled'] ) ),
        'exp_amount'     => absint( qibbs_get_option( $exp_amount_key, $def['default_exp_amount'] ) ),
        'exp_daily_limit'=> absint( qibbs_get_option( $exp_daily_key, $def['default_exp_daily'] ) ),
        'pts_enabled'    => (bool) qibbs_get_option( $pts_enabled_key, ! empty( $def['default_points_enabled'] ) ),
        'pts_amount'     => (float) qibbs_get_option( $pts_amount_key, $def['default_points_amount'] ),
        'pts_daily_limit'=> absint( qibbs_get_option( $pts_daily_key, $def['default_points_daily'] ) ),
    );
}

/**
 * 查询指定用户的当前累计成长值 (EXP)
 *
 * @param int $user_id User ID.
 * @return int
 */
function qibbs_get_user_exp( $user_id ) {
    $user_id = absint( $user_id );
    if ( ! $user_id ) {
        return 0;
    }

    if ( class_exists( 'Qibbs_Level_Service' ) ) {
        return Qibbs_Level_Service::get_instance()->get_user_exp( $user_id );
    }

    global $wpdb;
    $stats_table = Qibbs_Database::user_stats();
    $exp = $wpdb->get_var( $wpdb->prepare( "SELECT exp FROM {$stats_table} WHERE user_id = %d LIMIT 1", $user_id ) );
    return ( null !== $exp ) ? absint( $exp ) : (int) get_user_meta( $user_id, '_qibbs_user_exp', true );
}

/**
 * 获取指定用户的完整成长等级与经验进度信息
 *
 * @param int $user_id User ID.
 * @return array<string,mixed>
 */
function qibbs_get_user_level_info( $user_id ) {
    $user_id = absint( $user_id );
    if ( class_exists( 'Qibbs_Level_Service' ) ) {
        return Qibbs_Level_Service::get_instance()->get_user_level_info( $user_id );
    }

    return array(
        'enabled'          => qibbs_is_growth_levels_enabled(),
        'level'            => 1,
        'level_name'       => __( '见习萌新', 'qibbs-community' ),
        'exp'              => 0,
        'current_tier_exp' => 0,
        'needed_exp'       => 50,
        'next_exp'         => 50,
        'prev_exp'         => 0,
        'progress_percent' => 0.0,
        'is_max_level'     => false,
        'badge_class'      => 'qibbs-level-1',
        'color'            => '#64748b',
    );
}

/**
 * 获取指定用户的成长等级数字（Lv.N）
 *
 * @param int $user_id User ID.
 * @return int Level number (1-N).
 */
function qibbs_get_user_level( $user_id ) {
    $info = qibbs_get_user_level_info( $user_id );
    return isset( $info['level'] ) ? (int) $info['level'] : 1;
}

/**
 * 渲染用户成长等级标牌 HTML
 *
 * @param int    $user_id     User ID.
 * @param bool   $show_text   Whether to display rank name alongside Lv number.
 * @param string $extra_class Extra CSS class.
 * @param bool   $as_link     Whether to wrap with link to growth center.
 * @return string HTML badge.
 */
function qibbs_render_user_level_badge( $user_id, $show_text = true, $extra_class = '', $as_link = true ) {
    if ( ! qibbs_is_growth_levels_enabled() ) {
        return '';
    }

    $info = qibbs_get_user_level_info( $user_id );
    if ( empty( $info['enabled'] ) ) {
        return '';
    }

    $class = 'qibbs-level-badge ' . esc_attr( $info['badge_class'] );
    if ( '' !== trim( $extra_class ) ) {
        $class .= ' ' . esc_attr( trim( $extra_class ) );
    }

    $title = sprintf(
        /* translators: 1: Current EXP, 2: Next level EXP */
        esc_attr__( '成长值: %1$d (距升级差 %2$d 经验值) - 点击进入成长中心', 'qibbs-community' ),
        $info['exp'],
        $info['needed_exp']
    );

    if ( ! empty( $info['is_max_level'] ) ) {
        $title = sprintf( esc_attr__( '成长值: %d (已达最高等级) - 点击进入成长中心', 'qibbs-community' ), $info['exp'] );
    }

    $label = 'Lv.' . (int) $info['level'];
    if ( $show_text && ! empty( $info['level_name'] ) ) {
        $label .= ' ' . esc_html( $info['level_name'] );
    }

    $inner = sprintf(
        '<span class="qibbs-level-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></span><span class="qibbs-level-text">%s</span>',
        $label
    );

    if ( $as_link ) {
        $center_url = qibbs_get_growth_center_url();
        return sprintf(
            '<a href="%s" class="%s" title="%s" data-user-exp="%d" data-user-level="%d">%s</a>',
            esc_url( $center_url ),
            $class,
            $title,
            (int) $info['exp'],
            (int) $info['level'],
            $inner
        );
    }

    return sprintf(
        '<span class="%s" title="%s" data-user-exp="%d" data-user-level="%d">%s</span>',
        $class,
        $title,
        (int) $info['exp'],
        (int) $info['level'],
        $inner
    );
}

/**
 * 渲染侧边栏用户卡片或主页的精美等级经验进度条
 *
 * @param int  $user_id    User ID.
 * @param bool $compact    Compact mode.
 * @param bool $show_badge Whether to display level badge inside progress box.
 * @return string HTML progress widget.
 */
function qibbs_render_user_level_progress( $user_id, $compact = false, $show_badge = true ) {
    if ( ! qibbs_is_growth_levels_enabled() ) {
        return '';
    }

    $info = qibbs_get_user_level_info( $user_id );
    if ( empty( $info['enabled'] ) ) {
        return '';
    }

    $compact_class = $compact ? 'qibbs-level-progress-compact' : '';
    $percent = min( 100.0, max( 0.0, (float) $info['progress_percent'] ) );
    $center_url = qibbs_get_growth_center_url();

    $needed_text = $info['is_max_level']
        ? __( '已达成顶级荣耀', 'qibbs-community' )
        : sprintf( __( '距 Lv.%1$d 还差 %2$d 经验值', 'qibbs-community' ), ( $info['level'] + 1 ), $info['needed_exp'] );

    ob_start();
    ?>
    <a href="<?php echo esc_url( $center_url ); ?>" class="qibbs-level-progress-wrap <?php echo esc_attr( $compact_class ); ?>" title="<?php esc_attr_e( '点击查看社区成长中心与特权', 'qibbs-community' ); ?>">
        <div class="qibbs-level-progress-meta">
            <?php if ( $show_badge ) : ?>
                <div class="qibbs-level-current-box">
                    <?php echo qibbs_render_user_level_badge( $user_id, true, '', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            <?php else : ?>
                <div class="qibbs-level-current-title">
                    <span class="qibbs-level-current-dot" aria-hidden="true" style="color:<?php echo esc_attr( $info['color'] ); ?>;"><svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></span>
                    <span class="qibbs-level-current-text"><?php esc_html_e( '成长等级进度', 'qibbs-community' ); ?></span>
                </div>
            <?php endif; ?>
            <div class="qibbs-level-exp-stats">
                <span class="qibbs-level-exp-num"><?php echo esc_html( number_format_i18n( $info['exp'] ) ); ?></span>
                <?php if ( ! $info['is_max_level'] ) : ?>
                    <span class="qibbs-level-exp-divider">/</span>
                    <span class="qibbs-level-exp-max"><?php echo esc_html( number_format_i18n( $info['next_exp'] ) ); ?></span>
                <?php endif; ?>
                <span class="qibbs-level-exp-unit"><?php esc_html_e( '经验值', 'qibbs-community' ); ?></span>
            </div>
        </div>
        <div class="qibbs-level-bar-track" role="progressbar" aria-valuenow="<?php echo esc_attr( (int) round( $percent ) ); ?>" aria-valuemin="0" aria-valuemax="100" title="<?php echo esc_attr( round( $percent, 1 ) . '%' ); ?>">
            <div class="qibbs-level-bar-fill <?php echo esc_attr( $info['badge_class'] ); ?>" style="width: <?php echo esc_attr( $percent ); ?>%; background-color: <?php echo esc_attr( $info['color'] ); ?>;"></div>
        </div>
        <div class="qibbs-level-progress-footer">
            <span class="qibbs-level-progress-tip"><?php echo esc_html( $needed_text ); ?></span>
            <span class="qibbs-level-progress-pct"><?php echo esc_html( round( $percent, 1 ) ); ?>%</span>
        </div>
    </a>
    <?php
    return ob_get_clean();
}

/**
 * 统一给用户增加成长值
 *
 * @param int    $user_id User ID.
 * @param int    $amount  EXP amount.
 * @param string $action  Action key.
 * @param string $desc    Description.
 * @return bool
 */
function qibbs_add_user_exp( $user_id, $amount, $action = '', $desc = '' ) {
    $user_id = absint( $user_id );
    $amount  = absint( $amount );
    if ( ! $user_id || $amount <= 0 ) {
        return false;
    }

    if ( class_exists( 'Qibbs_Level_Service' ) ) {
        return Qibbs_Level_Service::get_instance()->add_user_exp( $user_id, $amount, $action, $desc );
    }

    return false;
}

/**
 * 统一惩罚扣除或调整用户成长值
 *
 * @param int    $user_id User ID.
 * @param int    $amount  Deduct amount.
 * @param string $action  Action key (penalty/admin_adjust).
 * @param string $desc    Description.
 * @return bool
 */
function qibbs_deduct_user_exp( $user_id, $amount, $action = 'penalty', $desc = '' ) {
    $user_id = absint( $user_id );
    $amount  = absint( $amount );
    if ( ! $user_id || $amount <= 0 ) {
        return false;
    }

    if ( class_exists( 'Qibbs_Level_Service' ) ) {
        return Qibbs_Level_Service::get_instance()->deduct_user_exp( $user_id, $amount, $action, $desc );
    }

    return false;
}

/**
 * 检查是否开启了基于成长等级的发帖/回帖安全频控
 *
 * @return bool
 */
function qibbs_is_level_rate_limits_enabled() {
    return (bool) qibbs_get_option( 'enable_level_rate_limits', true );
}

/**
 * 校验用户的发帖/发动态频控限制（支持成长等级安全频控联动）
 *
 * @param int $user_id User ID.
 * @return array{allowed: bool, message: string, limit: int}
 */
function qibbs_check_user_post_rate_limit( $user_id ) {
    $user_id = absint( $user_id );
    if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
        return array( 'allowed' => true, 'message' => '', 'limit' => 0 );
    }

    // 1. 分钟级硬频控（防止瞬时高并发灌水）
    $minute_limit = absint( qibbs_get_option( 'post_rate_limit_minute', 2 ) );
    if ( $minute_limit > 0 && function_exists( 'qibbs_consume_scoped_rate_limit' ) ) {
        if ( ! qibbs_consume_scoped_rate_limit( 'post_submit_minute', $user_id, $minute_limit, MINUTE_IN_SECONDS ) ) {
            return array(
                'allowed' => false,
                'message' => __( '发布动态过于频繁，请等待 1 分钟后再试', 'qibbs-community' ),
                'limit'   => $minute_limit,
            );
        }
    }

    // 2. 小时级安全频控（基于成长等级梯度或全局兜底）
    $enable_level_limits = qibbs_is_level_rate_limits_enabled();
    $hourly_limit        = 0;
    $level_info          = null;

    if ( $enable_level_limits && function_exists( 'qibbs_get_user_level_info' ) ) {
        $level_info   = qibbs_get_user_level_info( $user_id );
        $hourly_limit = isset( $level_info['post_hourly_limit'] ) ? (int) $level_info['post_hourly_limit'] : 0;
    } else {
        $hourly_limit = max( 1, absint( qibbs_get_option( 'post_rate_limit', 5 ) ) );
    }

    // 0 表示当前等级不设限制（如高等级特权豁免）
    if ( $hourly_limit <= 0 ) {
        return array( 'allowed' => true, 'message' => '', 'limit' => 0 );
    }

    if ( function_exists( 'qibbs_consume_scoped_rate_limit' ) ) {
        if ( ! qibbs_consume_scoped_rate_limit( 'post_submit_hour', $user_id, $hourly_limit, HOUR_IN_SECONDS ) ) {
            $level_name = $level_info ? ( 'Lv.' . $level_info['level'] . ' ' . $level_info['name'] ) : __( '当前等级', 'qibbs-community' );
            $msg = sprintf(
                __( '您目前为【%1$s】，1 小时内最多发布 %2$d 条动态。提升社区成长等级可解锁更高发布额度！', 'qibbs-community' ),
                $level_name,
                $hourly_limit
            );
            return array(
                'allowed' => false,
                'message' => $msg,
                'limit'   => $hourly_limit,
            );
        }
    }

    return array( 'allowed' => true, 'message' => '', 'limit' => $hourly_limit );
}

/**
 * 校验用户的评论/回帖频控限制（支持成长等级安全频控联动）
 *
 * @param int $user_id User ID.
 * @return array{allowed: bool, message: string, limit: int}
 */
function qibbs_check_user_comment_rate_limit( $user_id ) {
    $user_id = absint( $user_id );
    if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
        return array( 'allowed' => true, 'message' => '', 'limit' => 0 );
    }

    // 1. 分钟级硬频控
    $minute_limit = absint( qibbs_get_option( 'comment_rate_limit_minute', 6 ) );
    if ( $minute_limit > 0 && function_exists( 'qibbs_consume_scoped_rate_limit' ) ) {
        if ( ! qibbs_consume_scoped_rate_limit( 'comment_submit_minute', $user_id, $minute_limit, MINUTE_IN_SECONDS ) ) {
            return array(
                'allowed' => false,
                'message' => __( '回复过于频繁，请稍候再试', 'qibbs-community' ),
                'limit'   => $minute_limit,
            );
        }
    }

    // 2. 小时级安全频控（基于成长等级梯度或全局兜底）
    $enable_level_limits = qibbs_is_level_rate_limits_enabled();
    $hourly_limit        = 0;
    $level_info          = null;

    if ( $enable_level_limits && function_exists( 'qibbs_get_user_level_info' ) ) {
        $level_info   = qibbs_get_user_level_info( $user_id );
        $hourly_limit = isset( $level_info['comment_hourly_limit'] ) ? (int) $level_info['comment_hourly_limit'] : 0;
    } else {
        $hourly_limit = max( 1, absint( qibbs_get_option( 'comment_rate_limit', 20 ) ) );
    }

    // 0 表示当前等级不设限制
    if ( $hourly_limit <= 0 ) {
        return array( 'allowed' => true, 'message' => '', 'limit' => 0 );
    }

    if ( function_exists( 'qibbs_consume_scoped_rate_limit' ) ) {
        if ( ! qibbs_consume_scoped_rate_limit( 'comment_submit_hour', $user_id, $hourly_limit, HOUR_IN_SECONDS ) ) {
            $level_name = $level_info ? ( 'Lv.' . $level_info['level'] . ' ' . $level_info['name'] ) : __( '当前等级', 'qibbs-community' );
            $msg = sprintf(
                __( '您目前为【%1$s】，1 小时内最多回复 %2$d 条内容。提升社区成长等级可解锁更高发言额度！', 'qibbs-community' ),
                $level_name,
                $hourly_limit
            );
            return array(
                'allowed' => false,
                'message' => $msg,
                'limit'   => $hourly_limit,
            );
        }
    }

    return array( 'allowed' => true, 'message' => '', 'limit' => $hourly_limit );
}
