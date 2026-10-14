<?php
/**
 * 社区成长等级与双轨激励核心服务
 *
 * 管理用户成长值(EXP)累积、等级晋级、双轨动作激励(EXP vs 商城积分)分发与防刷限额
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Level_Service {

    /**
     * 单例实例
     *
     * @var Qibbs_Level_Service|null
     */
    private static $instance = null;

    /**
     * 用户等级运行时内存缓存
     *
     * @var array<int,array<string,mixed>>
     */
    private $user_level_cache = array();

    /**
     * 获取单例实例
     *
     * @return Qibbs_Level_Service
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * 注册核心事件监听钩子
     *
     * @return void
     */
    private function init_hooks() {
        // 发帖通过事件
        add_action( 'qibbs_post_created', array( $this, 'on_post_created' ), 15, 2 );

        // 评论通过审核事件
        add_action( 'qibbs_comment_approved', array( $this, 'on_comment_approved' ), 15, 3 );
        add_action( 'qibbs_comment_added', array( $this, 'on_comment_added' ), 15, 3 );

        // 动态获赞事件
        add_action( 'qibbs_like_toggled', array( $this, 'on_like_toggled' ), 15, 3 );

        // 每日签到事件
        add_action( 'qibbs_user_checked_in', array( $this, 'on_user_checked_in' ), 15, 2 );

        // 采纳最佳答案事件
        add_action( 'qibbs_best_answer_set', array( $this, 'on_best_answer_set' ), 15, 4 );

        // 动态加精推荐事件
        add_action( 'qibbs_post_featured', array( $this, 'on_post_featured' ), 15, 2 );

        // 工单转公开帖事件
        add_action( 'qibbs_ticket_published', array( $this, 'on_ticket_published' ), 15, 3 );

        // 监听商城积分发放完成事件，同步成长中心每日积分计数
        add_action( 'qibbs_points_reward_granted', array( $this, 'on_points_reward_granted' ), 10, 3 );
    }

    /**
     * 查询指定用户的成长值 (EXP)
     *
     * @param int $user_id User ID.
     * @return int
     */
    public function get_user_exp( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return 0;
        }

        global $wpdb;
        $table = Qibbs_Database::user_stats();
        
        $exp = $wpdb->get_var(
            $wpdb->prepare( "SELECT exp FROM {$table} WHERE user_id = %d LIMIT 1", $user_id )
        );

        if ( null !== $exp ) {
            return absint( $exp );
        }

        // 降级兼容易步数据
        $meta_exp = get_user_meta( $user_id, '_qibbs_user_exp', true );
        return absint( $meta_exp );
    }

    /**
     * 计算指定成长值对应的等级详细信息
     *
     * @param int $exp User total EXP.
     * @return array<string,mixed>
     */
    public function calculate_level_info( $exp ) {
        $exp = absint( $exp );
        $levels = qibbs_get_growth_levels_config();
        
        $current_level = 1;
        $current_meta  = $levels[1] ?? array(
            'level' => 1,
            'name'  => __( '见习萌新', 'qibbs-community' ),
            'min_exp' => 0,
            'badge_class' => 'qibbs-level-1',
            'color' => '#64748b',
        );

        $next_meta = null;
        $level_keys = array_keys( $levels );

        foreach ( $levels as $lvl => $meta ) {
            if ( $exp >= (int) $meta['min_exp'] ) {
                $current_level = (int) $lvl;
                $current_meta  = $meta;
            } else {
                if ( null === $next_meta ) {
                    $next_meta = $meta;
                }
            }
        }

        $min_exp = (int) $current_meta['min_exp'];
        $is_max  = ( null === $next_meta );

        if ( $is_max ) {
            $next_exp         = $min_exp;
            $needed_exp       = 0;
            $current_tier_exp = $exp - $min_exp;
            $progress_percent = 100.0;
        } else {
            $next_exp         = (int) $next_meta['min_exp'];
            $tier_span        = max( 1, $next_exp - $min_exp );
            $current_tier_exp = max( 0, $exp - $min_exp );
            $needed_exp       = max( 0, $next_exp - $exp );
            $progress_percent = round( min( 100.0, max( 0.0, ( $current_tier_exp / $tier_span ) * 100.0 ) ), 1 );
        }

        return array(
            'enabled'              => qibbs_is_growth_levels_enabled(),
            'level'                => $current_level,
            'level_name'           => (string) $current_meta['name'],
            'exp'                  => $exp,
            'min_exp'              => $min_exp,
            'next_exp'             => $next_exp,
            'needed_exp'           => $needed_exp,
            'current_tier_exp'     => $current_tier_exp,
            'progress_percent'     => $progress_percent,
            'is_max_level'         => $is_max,
            'badge_class'          => (string) $current_meta['badge_class'],
            'color'                => (string) ( $current_meta['color'] ?? '#64748b' ),
            'privilege'            => (string) ( $current_meta['privilege'] ?? '' ),
            'post_hourly_limit'    => isset( $current_meta['post_hourly_limit'] ) ? (int) $current_meta['post_hourly_limit'] : 3,
            'comment_hourly_limit' => isset( $current_meta['comment_hourly_limit'] ) ? (int) $current_meta['comment_hourly_limit'] : 10,
        );
    }

    /**
     * 获取指定用户的完整等级信息
     *
     * @param int $user_id User ID.
     * @return array<string,mixed>
     */
    public function get_user_level_info( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return $this->calculate_level_info( 0 );
        }

        if ( isset( $this->user_level_cache[ $user_id ] ) ) {
            return $this->user_level_cache[ $user_id ];
        }

        $exp = $this->get_user_exp( $user_id );
        $info = $this->calculate_level_info( $exp );
        $this->user_level_cache[ $user_id ] = $info;

        return $info;
    }

    /**
     * 统一为用户增加成长值 (EXP)
     *
     * @param int    $user_id Target user ID.
     * @param int    $amount  Amount of EXP.
     * @param string $action  Action trigger.
     * @param string $desc    Description.
     * @return bool
     */
    public function add_user_exp( $user_id, $amount, $action = '', $desc = '' ) {
        $user_id = absint( $user_id );
        $amount  = absint( $amount );
        if ( ! $user_id || $amount <= 0 ) {
            return false;
        }

        global $wpdb;
        $table = Qibbs_Database::user_stats();

        $old_info = $this->get_user_level_info( $user_id );
        $old_exp  = $old_info['exp'];
        $old_lvl  = $old_info['level'];
        $new_exp  = $old_exp + $amount;

        $new_info = $this->calculate_level_info( $new_exp );
        $new_lvl  = $new_info['level'];

        // 保证用户在 user_stats 表中有记录
        $exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT user_id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id )
        );

        if ( $exists ) {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET exp = exp + %d, level = %d, updated_at = %s WHERE user_id = %d",
                    $amount,
                    $new_lvl,
                    current_time( 'mysql' ),
                    $user_id
                )
            );
        } else {
            $updated = $wpdb->insert(
                $table,
                array(
                    'user_id'    => $user_id,
                    'post_count' => 0,
                    'exp'        => $new_exp,
                    'level'      => $new_lvl,
                    'updated_at' => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%d', '%d', '%s' )
            );
        }

        // 同步更新 user_meta 备份
        update_user_meta( $user_id, '_qibbs_user_exp', $new_exp );
        update_user_meta( $user_id, '_qibbs_user_level', $new_lvl );

        // 刷新内存缓存
        unset( $this->user_level_cache[ $user_id ] );

        // 触发增加成长值钩子
        do_action( 'qibbs_user_exp_added', $user_id, $amount, $new_exp, $action, $desc );

        // 检查等级提升
        if ( $new_lvl > $old_lvl ) {
            do_action( 'qibbs_user_level_up', $user_id, $new_lvl, $old_lvl, $new_info );
        }

        return false !== $updated;
    }

    /**
     * 惩罚扣除或扣减用户的成长值
     *
     * @param int    $user_id User ID.
     * @param int    $amount  Amount of EXP to deduct.
     * @param string $action  Action trigger (e.g. penalty/violation/admin_deduct).
     * @param string $desc    Description/reason.
     * @return bool
     */
    public function deduct_user_exp( $user_id, $amount, $action = 'penalty', $desc = '' ) {
        $user_id = absint( $user_id );
        $amount  = absint( $amount );
        if ( ! $user_id || $amount <= 0 ) {
            return false;
        }

        global $wpdb;
        $table = Qibbs_Database::user_stats();

        $old_info = $this->get_user_level_info( $user_id );
        $old_exp  = $old_info['exp'];
        $old_lvl  = $old_info['level'];

        // 扣除后最低为 0
        $new_exp = max( 0, $old_exp - $amount );
        $actual_deducted = $old_exp - $new_exp;

        $new_info = $this->calculate_level_info( $new_exp );
        $new_lvl  = $new_info['level'];

        $exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT user_id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id )
        );

        if ( $exists ) {
            $updated = $wpdb->update(
                $table,
                array(
                    'exp'        => $new_exp,
                    'level'      => $new_lvl,
                    'updated_at' => current_time( 'mysql' ),
                ),
                array( 'user_id' => $user_id ),
                array( '%d', '%d', '%s' ),
                array( '%d' )
            );
        } else {
            $updated = $wpdb->insert(
                $table,
                array(
                    'user_id'    => $user_id,
                    'post_count' => 0,
                    'exp'        => $new_exp,
                    'level'      => $new_lvl,
                    'updated_at' => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%d', '%d', '%s' )
            );
        }

        // 同步更新 user_meta 备份
        update_user_meta( $user_id, '_qibbs_user_exp', $new_exp );
        update_user_meta( $user_id, '_qibbs_user_level', $new_lvl );

        // 刷新内存缓存
        unset( $this->user_level_cache[ $user_id ] );

        // 触发扣减成长值钩子
        do_action( 'qibbs_user_exp_deducted', $user_id, $actual_deducted, $new_exp, $action, $desc );

        // 检查等级降级
        if ( $new_lvl < $old_lvl ) {
            do_action( 'qibbs_user_level_down', $user_id, $new_lvl, $old_lvl, $new_info );
        }

        return false !== $updated;
    }

    /**
     * 直接重置指定用户的成长值为固定数值
     *
     * @param int    $user_id    User ID.
     * @param int    $target_exp Target EXP.
     * @param string $desc       Description.
     * @return bool
     */
    public function set_user_exp( $user_id, $target_exp, $desc = '' ) {
        $user_id    = absint( $user_id );
        $target_exp = max( 0, absint( $target_exp ) );
        if ( ! $user_id ) {
            return false;
        }

        global $wpdb;
        $table = Qibbs_Database::user_stats();

        $old_info = $this->get_user_level_info( $user_id );
        $old_exp  = $old_info['exp'];
        $old_lvl  = $old_info['level'];

        $new_info = $this->calculate_level_info( $target_exp );
        $new_lvl  = $new_info['level'];

        $exists = $wpdb->get_var(
            $wpdb->prepare( "SELECT user_id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id )
        );

        if ( $exists ) {
            $updated = $wpdb->update(
                $table,
                array(
                    'exp'        => $target_exp,
                    'level'      => $new_lvl,
                    'updated_at' => current_time( 'mysql' ),
                ),
                array( 'user_id' => $user_id ),
                array( '%d', '%d', '%s' ),
                array( '%d' )
            );
        } else {
            $updated = $wpdb->insert(
                $table,
                array(
                    'user_id'    => $user_id,
                    'post_count' => 0,
                    'exp'        => $target_exp,
                    'level'      => $new_lvl,
                    'updated_at' => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%d', '%d', '%s' )
            );
        }

        update_user_meta( $user_id, '_qibbs_user_exp', $target_exp );
        update_user_meta( $user_id, '_qibbs_user_level', $new_lvl );
        unset( $this->user_level_cache[ $user_id ] );

        do_action( 'qibbs_user_exp_set', $user_id, $target_exp, $old_exp, $desc );

        if ( $new_lvl > $old_lvl ) {
            do_action( 'qibbs_user_level_up', $user_id, $new_lvl, $old_lvl, $new_info );
        } elseif ( $new_lvl < $old_lvl ) {
            do_action( 'qibbs_user_level_down', $user_id, $new_lvl, $old_lvl, $new_info );
        }

        return false !== $updated;
    }

    /**
     * 管理员人工奖惩/调整用户成长值
     *
     * @param int    $target_uid  Target user ID.
     * @param int    $amount      EXP amount.
     * @param string $adjust_type Type: add / deduct / set.
     * @param string $note        Reason / note.
     * @param int    $admin_id    Operating admin ID.
     * @return array{success:bool,message:string,new_exp?:int,new_level?:int}
     */
    public function admin_adjust_user_exp( $target_uid, $amount, $adjust_type = 'deduct', $note = '', $admin_id = 0 ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return array(
                'success' => false,
                'message' => __( '无权执行成长值调账管理。', 'qibbs-community' ),
            );
        }

        $target_uid = absint( $target_uid );
        $user = get_userdata( $target_uid );
        if ( ! $user ) {
            return array(
                'success' => false,
                'message' => __( '指定调账的目标用户不存在。', 'qibbs-community' ),
            );
        }

        $amount      = absint( $amount );
        $adjust_type = in_array( $adjust_type, array( 'add', 'deduct', 'set' ), true ) ? $adjust_type : 'deduct';
        $note        = sanitize_text_field( trim( (string) $note ) );
        $admin_id    = $admin_id ? absint( $admin_id ) : get_current_user_id();

        $old_info = $this->get_user_level_info( $target_uid );
        $success  = false;

        if ( 'deduct' === $adjust_type ) {
            if ( $amount <= 0 ) {
                return array(
                    'success' => false,
                    'message' => __( '请输入有效的扣除成长值点数。', 'qibbs-community' ),
                );
            }
            $success = $this->deduct_user_exp( $target_uid, $amount, 'admin_penalty', $note );
        } elseif ( 'add' === $adjust_type ) {
            if ( $amount <= 0 ) {
                return array(
                    'success' => false,
                    'message' => __( '请输入有效的增加成长值点数。', 'qibbs-community' ),
                );
            }
            $success = $this->add_user_exp( $target_uid, $amount, 'admin_reward', $note );
        } elseif ( 'set' === $adjust_type ) {
            $success = $this->set_user_exp( $target_uid, $amount, $note );
        }

        if ( ! $success ) {
            return array(
                'success' => false,
                'message' => __( '成长值调账失败，数据库未能成功更新。', 'qibbs-community' ),
            );
        }

        $new_info = $this->get_user_level_info( $target_uid );

        return array(
            'success'   => true,
            'message'   => sprintf(
                __( '成功为用户 %1$s 执行成长值调整！操作前：%2$d 经验值 (Lv.%3$d)，调整后：%4$d 经验值 (Lv.%5$d)。', 'qibbs-community' ),
                $user->display_name,
                $old_info['exp'],
                $old_info['level'],
                $new_info['exp'],
                $new_info['level']
            ),
            'new_exp'   => $new_info['exp'],
            'new_level' => $new_info['level'],
        );
    }

    /**
     * 获取指定用户在当天已完成某动作的计数记录
     *
     * @param int    $user_id    User ID.
     * @param string $action_key Action identifier.
     * @return array{exp_count:int,pts_count:int}
     */
    public function get_daily_action_counts( $user_id, $action_key ) {
        $user_id = absint( $user_id );
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
     * 递增指定用户当天的动作完成计数
     *
     * @param int    $user_id    User ID.
     * @param string $action_key Action identifier.
     * @param bool   $increment_exp Whether EXP count incremented.
     * @param bool   $increment_pts Whether Points count incremented.
     * @return void
     */
    private function record_daily_action_count( $user_id, $action_key, $increment_exp = false, $increment_pts = false ) {
        $user_id = absint( $user_id );
        if ( ! $user_id || ( ! $increment_exp && ! $increment_pts ) ) {
            return;
        }

        $date_key = '_qibbs_daily_rewards_' . current_time( 'Ymd' );
        $counts = get_user_meta( $user_id, $date_key, true );
        if ( ! is_array( $counts ) ) {
            $counts = array();
        }

        $current = isset( $counts[ $action_key ] ) && is_array( $counts[ $action_key ] )
            ? $counts[ $action_key ]
            : array( 'exp_count' => 0, 'pts_count' => 0 );

        if ( $increment_exp ) {
            $current['exp_count'] = absint( $current['exp_count'] ?? 0 ) + 1;
        }
        if ( $increment_pts ) {
            $current['pts_count'] = absint( $current['pts_count'] ?? 0 ) + 1;
        }

        $counts[ $action_key ] = $current;
        update_user_meta( $user_id, $date_key, $counts );
    }

    /**
     * 核心动作双轨激励调度器 (Dual-Track Reward Dispatcher)
     *
     * 根据后台独立开关与分值设置，分别处理成长值(EXP)与商城积分(Points)：
     * - 可单边只增成长值（不发积分）
     * - 可单边只发商城积分（不增成长值）
     * - 可双轨同时增送
     * - 可双边完全关闭（不送）
     *
     * @param string $action_key Action identifier.
     * @param int    $user_id    User ID.
     * @param int    $related_id Related entity ID (post_id, comment_id, etc.).
     * @param array  $context    Runtime context.
     * @return array{exp_awarded:int,pts_awarded:float}
     */
    public function trigger_action( $action_key, $user_id, $related_id = 0, $context = array() ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return array( 'exp_awarded' => 0, 'pts_awarded' => 0.0 );
        }

        $settings = qibbs_get_dual_reward_settings( $action_key );
        if ( empty( $settings ) ) {
            return array( 'exp_awarded' => 0, 'pts_awarded' => 0.0 );
        }

        // 检查版主/管理员参与限制
        $allow_staff = (bool) qibbs_get_option( 'points_integration_allow_staff', true );
        if ( ! $allow_staff && user_can( $user_id, 'manage_options' ) ) {
            return array( 'exp_awarded' => 0, 'pts_awarded' => 0.0 );
        }

        $daily_counts = $this->get_daily_action_counts( $user_id, $action_key );
        $exp_awarded = 0;
        $pts_awarded = 0.0;
        $inc_exp = false;
        $inc_pts = false;

        // 1. 处理成长值 EXP 分发
        if ( ! empty( $settings['exp_enabled'] ) && ! empty( $settings['exp_amount'] ) ) {
            $exp_limit = (int) $settings['exp_daily_limit'];
            if ( 0 === $exp_limit || $daily_counts['exp_count'] < $exp_limit ) {
                $amount = absint( $settings['exp_amount'] );
                if ( $amount > 0 ) {
                    $desc = sprintf( __( '参与社区活动【%s】获得成长值', 'qibbs-community' ), $settings['label'] );
                    $ok = $this->add_user_exp( $user_id, $amount, $action_key, $desc );
                    if ( $ok ) {
                        $exp_awarded = $amount;
                        $inc_exp = true;
                    }
                }
            }
        }

        // 2. 处理商城积分 Points 分发
        // 核心防重：若 QilingShop_Points_Integration 兼容层已启用，对应事件由该兼容层统一处理（支持原子锁、幂等检查与日志映射）
        // 避免 Level_Service 双轨调度器与 Points_Integration 对同一事件重复发放双倍积分
        $handled_by_points_integration = class_exists( 'Qibbs_QilingShop_Points_Integration' )
            && (bool) qibbs_get_option( 'enable_points_integration', false )
            && in_array( $action_key, array( 'post_publish', 'comment_approved', 'best_answer', 'featured_post', 'ticket_published' ), true );

        if ( ! $handled_by_points_integration && ! empty( $settings['pts_enabled'] ) && ! empty( $settings['pts_amount'] ) && function_exists( 'qibbs_add_user_points' ) ) {
            $pts_limit = (int) $settings['pts_daily_limit'];
            if ( 0 === $pts_limit || $daily_counts['pts_count'] < $pts_limit ) {
                $pts_amount = (float) $settings['pts_amount'];
                if ( $pts_amount > 0 ) {
                    $pts_source = 'community_' . sanitize_key( $action_key );
                    $pts_desc   = sprintf( __( '社区互动【%s】奖励', 'qibbs-community' ), $settings['label'] );
                    $ok_pts = qibbs_add_user_points( $user_id, $pts_amount, $pts_source, $pts_desc, $related_id );
                    if ( $ok_pts ) {
                        $pts_awarded = $pts_amount;
                        $inc_pts = true;
                    }
                }
            }
        }

        if ( $inc_exp || $inc_pts ) {
            $this->record_daily_action_count( $user_id, $action_key, $inc_exp, $inc_pts );
        }

        return array(
            'exp_awarded' => $exp_awarded,
            'pts_awarded' => $pts_awarded,
        );
    }

    /**
     * 事件：发布动态
     */
    public function on_post_created( $post_id, $user_id ) {
        $this->trigger_action( 'post_publish', (int) $user_id, (int) $post_id );
    }

    /**
     * 事件：评论通过审核
     */
    public function on_comment_approved( $comment_id, $post_id, $user_id ) {
        $this->trigger_action( 'comment_approved', (int) $user_id, (int) $comment_id, array( 'post_id' => $post_id ) );
    }

    /**
     * 事件：发表新评论（若免审则直接记入）
     */
    public function on_comment_added( $comment_id, $post_id, $user_id ) {
        global $wpdb;
        $table = Qibbs_Database::comments();
        $status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d LIMIT 1", $comment_id ) );
        if ( 'approved' === (string) $status ) {
            $this->trigger_action( 'comment_approved', (int) $user_id, (int) $comment_id, array( 'post_id' => $post_id ) );
        }
    }

    /**
     * 事件：点赞/获赞（奖励给动态作者本人）
     */
    public function on_like_toggled( $liker_id, $post_id, $liked ) {
        if ( ! $liked ) {
            return;
        }

        global $wpdb;
        $posts_table = Qibbs_Database::posts();
        $author_id = $wpdb->get_var( $wpdb->prepare( "SELECT author_id FROM {$posts_table} WHERE id = %d LIMIT 1", $post_id ) );

        if ( $author_id && (int) $author_id !== (int) $liker_id ) {
            $this->trigger_action( 'post_liked', (int) $author_id, (int) $post_id, array( 'liker_id' => $liker_id ) );
        }
    }

    /**
     * 事件：每日打卡签到
     */
    public function on_user_checked_in( $user_id, $result = array() ) {
        $this->trigger_action( 'checkin', (int) $user_id, 0, (array) $result );
    }

    /**
     * 事件：评论被采纳为最佳答案
     */
    public function on_best_answer_set( $post_id, $comment_id, $comment_author_id, $setter_id ) {
        if ( $comment_author_id ) {
            $this->trigger_action( 'best_answer', (int) $comment_author_id, (int) $comment_id, array( 'post_id' => $post_id ) );
        }
    }

    /**
     * 事件：动态被设为精华/推荐
     */
    public function on_post_featured( $post_id, $author_id ) {
        if ( $author_id ) {
            $this->trigger_action( 'featured_post', (int) $author_id, (int) $post_id );
        }
    }

    /**
     * 事件：工单转为公开帖
     */
    public function on_ticket_published( $ticket_id, $post_id, $forum_id ) {
        global $wpdb;
        $tickets_table = Qibbs_Database::tickets();
        $user_id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$tickets_table} WHERE id = %d LIMIT 1", $ticket_id ) );
        if ( $user_id ) {
            $this->trigger_action( 'ticket_published', (int) $user_id, (int) $ticket_id, array( 'post_id' => $post_id ) );
        }
    }
    /**
     * 当商城积分集成层成功发放积分时，同步记录成长中心每日积分计数
     *
     * @param string $reward_key Reward key.
     * @param int    $user_id    User ID.
     * @param float  $amount     Points amount.
     * @return void
     */
    public function on_points_reward_granted( $reward_key, $user_id, $amount ) {
        if ( $user_id && $amount > 0 ) {
            $this->record_daily_action_count( (int) $user_id, (string) $reward_key, false, true );
        }
    }
}
