<?php
/**
 * 启灵商城积分联动辅助函数
 *
 * @package Qibbs_Community
 * @subpackage Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 获取社区对接启灵商城的积分奖励规则定义
 *
 * @return array<string,array<string,mixed>>
 */
function qibbs_get_points_reward_definitions() {
    $definitions = array(
        'post_publish' => array(
            'label'               => __( '发帖通过奖励', 'qibbs-community' ),
            'description'         => __( '仅对真正发布成功的社区原生动态发分；文章同步内容不会计入。', 'qibbs-community' ),
            'source'              => 'qibbs_post_publish',
            'default_enabled'     => true,
            'default_amount'      => 2,
            'default_daily_limit' => 3,
        ),
        'comment_approved' => array(
            'label'               => __( '评论通过奖励', 'qibbs-community' ),
            'description'         => __( '仅对真正审核通过的评论发分；待审评论不会提前奖励。', 'qibbs-community' ),
            'source'              => 'qibbs_comment_approved',
            'default_enabled'     => true,
            'default_amount'      => 1,
            'default_daily_limit' => 5,
        ),
        'best_answer' => array(
            'label'               => __( '最佳答案奖励', 'qibbs-community' ),
            'description'         => __( '当悬赏回复被采纳为最佳答案时，由平台额外补贴奖励给该答主（与楼主悬赏金叠加）。若不需要额外补贴，可关闭此开关或将奖励积分设为 0。', 'qibbs-community' ),
            'source'              => 'qibbs_best_answer',
            'default_enabled'     => true,
            'default_amount'      => 15,
            'default_daily_limit' => 0,
        ),
        'featured_post' => array(
            'label'               => __( '精华/推荐奖励', 'qibbs-community' ),
            'description'         => __( '当动态被设为精华/推荐时，奖励给动态作者。', 'qibbs-community' ),
            'source'              => 'qibbs_featured_post',
            'default_enabled'     => true,
            'default_amount'      => 20,
            'default_daily_limit' => 0,
        ),
        'ticket_published' => array(
            'label'               => __( '工单转公开帖奖励', 'qibbs-community' ),
            'description'         => __( '当工单成功转为社区公开帖时，奖励给提单用户。', 'qibbs-community' ),
            'source'              => 'qibbs_ticket_published',
            'default_enabled'     => true,
            'default_amount'      => 10,
            'default_daily_limit' => 0,
        ),
    );

    return (array) apply_filters( 'qibbs_points_reward_definitions', $definitions );
}

/**
 * 构建积分奖励配置项 Option 键名
 *
 * @param string $reward_key Reward key.
 * @param string $field      Setting field name.
 * @return string
 */
function qibbs_get_points_reward_option_key( $reward_key, $field ) {
    return 'points_reward_' . sanitize_key( $reward_key ) . '_' . sanitize_key( $field );
}

/**
 * 读取单项或合并后的完整积分奖励配置
 *
 * @param string      $reward_key Reward key.
 * @param string|null $field      Optional field to fetch.
 * @return mixed
 */
function qibbs_get_points_reward_settings( $reward_key, $field = null ) {
    $definitions = qibbs_get_points_reward_definitions();
    if ( ! isset( $definitions[ $reward_key ] ) || ! is_array( $definitions[ $reward_key ] ) ) {
        return null;
    }

    $definition = $definitions[ $reward_key ];
    $settings = array(
        'label'       => $definition['label'],
        'description' => isset( $definition['description'] ) ? $definition['description'] : '',
        'source'      => isset( $definition['source'] ) ? (string) $definition['source'] : '',
        'enabled'     => (bool) qibbs_get_option(
            qibbs_get_points_reward_option_key( $reward_key, 'enabled' ),
            ! empty( $definition['default_enabled'] )
        ),
        'amount'      => (float) qibbs_get_option(
            qibbs_get_points_reward_option_key( $reward_key, 'amount' ),
            isset( $definition['default_amount'] ) ? (float) $definition['default_amount'] : 0
        ),
        'daily_limit' => (int) qibbs_get_option(
            qibbs_get_points_reward_option_key( $reward_key, 'daily_limit' ),
            isset( $definition['default_daily_limit'] ) ? (int) $definition['default_daily_limit'] : 0
        ),
    );

    if ( null === $field ) {
        return $settings;
    }

    return array_key_exists( $field, $settings ) ? $settings[ $field ] : null;
}

/**
 * 检查当前环境商城积分系统是否可用
 *
 * @return bool
 */
function qibbs_is_points_integration_available() {
    return (bool) qibbs_get_option( 'enable_points_integration', false ) && class_exists( 'QilingShop_Points' );
}

/**
 * 获取当前积分单位文案
 *
 * @return string
 */
function qibbs_get_points_name() {
    if ( function_exists( 'qilingshop_get_points_name' ) ) {
        $name = (string) qilingshop_get_points_name();
        if ( '' !== trim( $name ) ) {
            return $name;
        }
    }

    $fallback = get_option( 'qilingshop_points_name', __( '积分', 'qibbs-community' ) );
    $fallback = is_string( $fallback ) ? trim( $fallback ) : '';

    return '' !== $fallback ? $fallback : __( '积分', 'qibbs-community' );
}

/**
 * 获取启灵商城数据表名
 *
 * @param string $table_key Logical table key.
 * @return string
 */
function qibbs_get_qilingshop_table_name( $table_key ) {
    global $wpdb;

    $table_key = sanitize_key( (string) $table_key );
    if ( '' === $table_key ) {
        return '';
    }

    $property = 'qilingshop_' . $table_key;
    if ( isset( $wpdb->{$property} ) && is_string( $wpdb->{$property} ) && '' !== $wpdb->{$property} ) {
        return $wpdb->{$property};
    }

    if ( defined( 'QILINGSHOP_TABLE_PREFIX' ) ) {
        return $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . $table_key;
    }

    return $wpdb->prefix . 'qilingshop_' . $table_key;
}

/**
 * 格式化积分数值用于前台 UI 展示
 *
 * @param float|int $amount Raw amount.
 * @return string
 */
function qibbs_format_points_amount( $amount ) {
    $amount = (float) $amount;
    if ( abs( $amount - round( $amount ) ) < 0.00001 ) {
        return number_format_i18n( (int) round( $amount ) );
    }

    return number_format_i18n( $amount, 2 );
}

/**
 * 获取指定动作已启用的积分奖励分值
 *
 * @param string $reward_key Reward key.
 * @return float
 */
function qibbs_get_points_reward_amount( $reward_key ) {
    if ( ! qibbs_is_points_integration_available() ) {
        return 0;
    }

    $settings = qibbs_get_points_reward_settings( $reward_key );
    if ( empty( $settings ) || ! is_array( $settings ) || empty( $settings['enabled'] ) ) {
        return 0;
    }

    return max( 0, (float) $settings['amount'] );
}

/**
 * 检查当前用户是否满足展示积分奖励提示的条件
 *
 * @param string $reward_key Reward key.
 * @param int    $user_id    User ID.
 * @param array  $context    Optional runtime context.
 * @return bool
 */
function qibbs_can_user_earn_points_reward( $reward_key, $user_id = 0, $context = array() ) {
    if ( ! qibbs_is_points_integration_available() ) {
        return false;
    }

    $settings = qibbs_get_points_reward_settings( $reward_key );
    if ( empty( $settings ) || ! is_array( $settings ) || empty( $settings['enabled'] ) || (float) $settings['amount'] <= 0 ) {
        return false;
    }

    $user_id = absint( $user_id );
    if ( $user_id <= 0 ) {
        return false;
    }

    if ( class_exists( 'Qibbs_QilingShop_Points_Integration' ) ) {
        return Qibbs_QilingShop_Points_Integration::get_instance()->can_user_earn_reward( $reward_key, $user_id, (array) $context );
    }

    return true;
}

/**
 * 构建前台发帖/发评表单展示的积分提示文案
 *
 * @param string $reward_key Reward key.
 * @param string $label      Leading copy.
 * @param int    $user_id    Optional user ID for eligibility checks.
 * @param array  $context    Optional runtime context.
 * @return string
 */
function qibbs_get_points_reward_hint( $reward_key, $label, $user_id = 0, $context = array() ) {
    // 默认关闭前台输入框/评论区激励标签展示，保持界面纯净清爽
    if ( ! (bool) qibbs_get_option( 'show_form_reward_hints', false ) ) {
        return '';
    }

    $user_id = absint( $user_id );
    if ( $user_id > 0 && ! qibbs_can_user_earn_points_reward( $reward_key, $user_id, (array) $context ) ) {
        return '';
    }

    $amount = qibbs_get_points_reward_amount( $reward_key );
    if ( $amount <= 0 ) {
        return '';
    }

    $label = trim( (string) $label );
    $text = '+' . qibbs_format_points_amount( $amount ) . ' ' . qibbs_get_points_name();

    return '' !== $label ? $label . ' ' . $text : $text;
}

/**
 * 查询当前用户的可用积分账户余额（统一依赖启灵商城积分系统）
 *
 * @param int $user_id User ID.
 * @return float|null 返回 null 表示未安装/未启用启灵商城积分系统
 */
function qibbs_get_user_points_balance( $user_id ) {
    $user_id = absint( $user_id );
    if ( ! $user_id ) {
        return null;
    }

    if ( ! qibbs_is_points_integration_available() || ! class_exists( 'QilingShop_Points' ) ) {
        return null;
    }

    static $balance_cache = array();
    if ( array_key_exists( $user_id, $balance_cache ) ) {
        return $balance_cache[ $user_id ];
    }

    global $wpdb;
    $table = qibbs_get_qilingshop_table_name( 'user_info' );
    if ( '' !== $table ) {
        $balance = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT points_balance FROM {$table} WHERE user_id = %d LIMIT 1",
                $user_id
            )
        );
        $balance_cache[ $user_id ] = ( null === $balance ) ? 0.0 : (float) $balance;
        return $balance_cache[ $user_id ];
    }

    return null;
}

/**
 * 统一扣减用户积分（直接调用启灵商城积分流水接口）
 *
 * @param int    $user_id User ID.
 * @param float  $amount  Points amount.
 * @param string $action  Action type identifier.
 * @param string $desc    Human-readable description.
 * @param int    $rel_id  Related object ID.
 * @return bool
 */
function qibbs_deduct_user_points( $user_id, $amount, $action = 'community_consume', $desc = '', $rel_id = 0 ) {
    $user_id = absint( $user_id );
    $amount  = (float) $amount;
    if ( $user_id <= 0 || $amount <= 0 ) {
        return false;
    }

    if ( ! qibbs_is_points_integration_available() || ! class_exists( 'QilingShop_Points' ) ) {
        return false;
    }

    $points_instance = QilingShop_Points::instance();
    if ( method_exists( $points_instance, 'get_user_info' ) ) {
        $points_instance->get_user_info( $user_id );
    } elseif ( method_exists( $points_instance, 'init_user_account' ) ) {
        $points_instance->init_user_account( $user_id );
    }

    return (bool) $points_instance->deduct_points(
        $user_id,
        $amount,
        $action,
        $desc,
        $rel_id
    );
}

/**
 * 统一增加用户积分（直接调用启灵商城积分奖励发放接口）
 *
 * @param int    $user_id User ID.
 * @param float  $amount  Points amount.
 * @param string $action  Action type identifier.
 * @param string $desc    Human-readable description.
 * @param int    $rel_id  Related object ID.
 * @return bool
 */
function qibbs_add_user_points( $user_id, $amount, $action = 'community_reward', $desc = '', $rel_id = 0 ) {
    $user_id = absint( $user_id );
    $amount  = (float) $amount;
    if ( $user_id <= 0 || $amount <= 0 ) {
        return false;
    }

    if ( ! qibbs_is_points_integration_available() || ! class_exists( 'QilingShop_Points' ) ) {
        return false;
    }

    $points_instance = QilingShop_Points::instance();
    if ( method_exists( $points_instance, 'get_user_info' ) ) {
        $points_instance->get_user_info( $user_id );
    } elseif ( method_exists( $points_instance, 'init_user_account' ) ) {
        $points_instance->init_user_account( $user_id );
    }

    return (bool) $points_instance->add_points(
        $user_id,
        $amount,
        $action,
        $desc,
        $rel_id
    );
}

/**
 * 获取用户的启灵商城可提现现金余额
 *
 * @param int $user_id User ID.
 * @return float
 */
function qibbs_get_user_withdrawable_balance( $user_id = 0 ) {
    $user_id = absint( $user_id );
    if ( $user_id <= 0 ) {
        $user_id = get_current_user_id();
    }
    if ( $user_id <= 0 || ! class_exists( 'QilingShop_Points' ) ) {
        return 0.00;
    }

    $info = QilingShop_Points::instance()->get_user_info( $user_id );
    return $info && isset( $info->withdrawable_balance ) ? (float) $info->withdrawable_balance : 0.00;
}

/**
 * 获取启灵个人中心提现页面链接
 *
 * @return string
 */
function qibbs_get_withdraw_url() {
    if ( function_exists( 'developer_starter_get_frontend_account_tab_url' ) ) {
        return (string) developer_starter_get_frontend_account_tab_url( 'qls-withdraw' );
    }

    $pages = get_pages( array(
        'meta_key'   => '_wp_page_template',
        'meta_value' => 'templates/template-account.php',
    ) );
    if ( ! empty( $pages ) ) {
        return add_query_arg( 'tab', 'qls-withdraw', get_permalink( $pages[0]->ID ) );
    }

    return home_url( '/account/?tab=qls-withdraw' );
}

/**
 * 检查当前是否可显示每日签到小工具
 *
 * @return bool
 */
function qibbs_is_checkin_widget_enabled() {
    if ( ! qibbs_is_points_integration_available() ) {
        return false;
    }
    if ( ! (bool) get_option( 'qilingshop_checkin_enabled', true ) ) {
        return false;
    }
    return (bool) qibbs_get_option( 'show_checkin_widget', true );
}

/**
 * 获取指定用户的签到状态与连签阶梯信息
 *
 * @param int $user_id User ID.
 * @return array
 */
function qibbs_get_user_checkin_status( $user_id = 0 ) {
    $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
    $points_name = qibbs_get_points_name();
    $enabled = qibbs_is_checkin_widget_enabled();

    if ( ! $enabled ) {
        return array(
            'enabled' => false,
        );
    }

    $base_points       = (float) get_option( 'qilingshop_checkin_base_points', 1 );
    $consecutive_bonus = (bool) get_option( 'qilingshop_checkin_consecutive_bonus', true );
    $max_consecutive   = max( 1, (int) get_option( 'qilingshop_checkin_max_consecutive_bonus', 7 ) );

    if ( ! $user_id || ! class_exists( 'QilingShop_Points' ) ) {
        $ladder = array();
        for ( $day = 1; $day <= 7; $day++ ) {
            $multiplier = $consecutive_bonus ? min( $day, $max_consecutive ) : 1;
            $ladder[] = array(
                'day'    => $day,
                'points' => $base_points * $multiplier,
            );
        }

        return array(
            'enabled'           => true,
            'is_logged_in'      => false,
            'checked_in'        => false,
            'consecutive_days'  => 0,
            'today_points'      => $base_points,
            'tomorrow_points'   => $base_points * ( $consecutive_bonus ? min( 2, $max_consecutive ) : 1 ),
            'base_points'       => $base_points,
            'max_consecutive'   => $max_consecutive,
            'points_name'       => $points_name,
            'ladder'            => $ladder,
        );
    }

    $points_instance = QilingShop_Points::instance();
    $checked_in = $points_instance->has_checked_in_today( $user_id );
    $user_info  = $points_instance->get_user_info( $user_id );

    $consecutive_days = 0;
    if ( $user_info ) {
        $today     = current_time( 'Y-m-d' );
        $yesterday = date( 'Y-m-d', strtotime( '-1 day', current_time( 'timestamp' ) ) );
        if ( ! empty( $user_info->last_checkin_date ) && $user_info->last_checkin_date === $today ) {
            $consecutive_days = (int) $user_info->consecutive_checkins;
        } elseif ( ! empty( $user_info->last_checkin_date ) && $user_info->last_checkin_date === $yesterday ) {
            $consecutive_days = (int) $user_info->consecutive_checkins;
        } else {
            $consecutive_days = 0;
        }
    }

    if ( $checked_in ) {
        $today_multiplier = $consecutive_bonus && $consecutive_days > 1 ? min( $consecutive_days, $max_consecutive ) : 1;
        $today_points     = $base_points * $today_multiplier;

        $next_streak      = $consecutive_days + 1;
        $next_multiplier  = $consecutive_bonus ? min( $next_streak, $max_consecutive ) : 1;
        $tomorrow_points  = $base_points * $next_multiplier;
    } else {
        $today_streak     = $consecutive_days + 1;
        $today_multiplier = $consecutive_bonus && $today_streak > 1 ? min( $today_streak, $max_consecutive ) : 1;
        $today_points     = $base_points * $today_multiplier;

        $next_streak      = $today_streak + 1;
        $next_multiplier  = $consecutive_bonus ? min( $next_streak, $max_consecutive ) : 1;
        $tomorrow_points  = $base_points * $next_multiplier;
    }

    $ladder = array();
    for ( $day = 1; $day <= 7; $day++ ) {
        $multiplier = $consecutive_bonus ? min( $day, $max_consecutive ) : 1;
        $ladder[] = array(
            'day'    => $day,
            'points' => $base_points * $multiplier,
        );
    }

    $cards = (int) get_user_meta( $user_id, '_qibbs_checkin_cards_count', true );
    $yesterday_signed = false;
    if ( $user_info && ! empty( $user_info->last_checkin_date ) ) {
        if ( $user_info->last_checkin_date === $today || $user_info->last_checkin_date === $yesterday ) {
            $yesterday_signed = true;
        }
    }
    $can_makeup = ( ! $yesterday_signed );

    return array(
        'enabled'           => true,
        'is_logged_in'      => true,
        'checked_in'        => $checked_in,
        'consecutive_days'  => $consecutive_days,
        'today_points'      => $today_points,
        'tomorrow_points'   => $tomorrow_points,
        'base_points'       => $base_points,
        'max_consecutive'   => $max_consecutive,
        'points_name'       => $points_name,
        'ladder'            => $ladder,
        'checkin_cards'     => $cards,
        'can_makeup'        => $can_makeup,
    );
}

/**
 * 执行补签昨日打卡（消耗 1 张社区补签卡）
 *
 * @param int $user_id User ID.
 * @return array
 */
function qibbs_execute_retroactive_checkin( $user_id ) {
    $user_id = absint( $user_id );
    if ( ! $user_id ) {
        return array(
            'success' => false,
            'message' => __( '请先登录后再进行补签', 'qibbs-community' ),
        );
    }

    if ( ! qibbs_is_points_integration_available() || ! class_exists( 'QilingShop_Points' ) ) {
        return array(
            'success' => false,
            'message' => __( '站点尚未启用积分系统，暂无法补签', 'qibbs-community' ),
        );
    }

    $cards = (int) get_user_meta( $user_id, '_qibbs_checkin_cards_count', true );
    if ( $cards <= 0 ) {
        return array(
            'success'  => false,
            'message'  => __( '您当前暂无可用签到补签卡，请前往【游戏中心道具商城】使用游戏币兑换！', 'qibbs-community' ),
            'no_cards' => true,
        );
    }

    $points_instance = QilingShop_Points::instance();
    if ( ! method_exists( $points_instance, 'retroactive_checkin' ) ) {
        return array(
            'success' => false,
            'message' => __( '商城底层未就绪补签功能', 'qibbs-community' ),
        );
    }

    $result = $points_instance->retroactive_checkin( $user_id );
    if ( ! empty( $result['success'] ) ) {
        // 扣减 1 张补签卡
        $new_cards = max( 0, $cards - 1 );
        update_user_meta( $user_id, '_qibbs_checkin_cards_count', $new_cards );

        // 同步更新背包表中 1 条补签卡记录为已消耗
        global $wpdb;
        $user_props_table = $wpdb->prefix . 'qibbs_user_props';
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $user_props_table ) );
        if ( $table_exists === $user_props_table ) {
            $prop_row = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM `{$user_props_table}` WHERE user_id = %d AND prop_type = 'checkin_card' AND status = 'active' LIMIT 1",
                    $user_id
                )
            );
            if ( $prop_row ) {
                $wpdb->update(
                    $user_props_table,
                    array( 'status' => 'consumed', 'updated_at' => current_time( 'mysql' ) ),
                    array( 'id' => (int) $prop_row )
                );
            }
        }

        do_action( 'qibbs_user_retroactive_checked_in', $user_id, $result );

        $points_name = qibbs_get_points_name();
        $balance     = qibbs_get_user_points_balance( $user_id );
        $new_status  = qibbs_get_user_checkin_status( $user_id );

        return array(
            'success'          => true,
            'message'          => sprintf( __( '补签成功！已消耗 1 张补签卡，连续打卡已续上，获得 +%s %s', 'qibbs-community' ), $result['points'], $points_name ),
            'points'           => (float) $result['points'],
            'consecutive_days' => (int) $result['consecutive_days'],
            'new_balance'      => $balance,
            'new_balance_text' => qibbs_format_points_amount( (float) $balance ),
            'remaining_cards'  => $new_cards,
            'status'           => $new_status,
        );
    }

    return array(
        'success' => false,
        'message' => ! empty( $result['message'] ) ? $result['message'] : __( '补签失败，请稍后重试', 'qibbs-community' ),
    );
}

/**
 * 执行社区签到
 *
 * @param int $user_id User ID.
 * @return array
 */
function qibbs_execute_checkin( $user_id ) {
    if ( ! $user_id ) {
        return array(
            'success' => false,
            'message' => __( '请先登录后再进行签到', 'qibbs-community' ),
        );
    }

    if ( ! qibbs_is_points_integration_available() || ! class_exists( 'QilingShop_Points' ) ) {
        return array(
            'success' => false,
            'message' => __( '站点尚未启用积分系统，暂无法签到', 'qibbs-community' ),
        );
    }

    $result = QilingShop_Points::instance()->checkin( $user_id );
    if ( ! empty( $result['success'] ) ) {
        do_action( 'qibbs_user_checked_in', $user_id, $result );

        $points_name = qibbs_get_points_name();
        $balance = qibbs_get_user_points_balance( $user_id );
        $new_status = qibbs_get_user_checkin_status( $user_id );

        return array(
            'success'          => true,
            'message'          => sprintf( __( '签到成功！获得 %s %s', 'qibbs-community' ), $result['points'], $points_name ),
            'points'           => (float) $result['points'],
            'consecutive_days' => (int) $result['consecutive_days'],
            'new_balance'      => $balance,
            'new_balance_text' => qibbs_format_points_amount( (float) $balance ),
            'status'           => $new_status,
        );
    }

    return array(
        'success' => false,
        'message' => ! empty( $result['message'] ) ? $result['message'] : __( '签到失败，请稍后重试', 'qibbs-community' ),
    );
}
