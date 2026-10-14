<?php
/**
 * 社区悬赏问答核心服务类
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Bounty_Service {

    /**
     * 单例实例
     *
     * @var Qibbs_Bounty_Service|null
     */
    private static $instance = null;

    /**
     * WP-Cron 悬赏超时检查定时钩子
     */
    const CRON_HOOK = 'qibbs_bounty_expiration_check';

    /**
     * 获取单例
     *
     * @return Qibbs_Bounty_Service
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
    private function __construct() {
        add_action( self::CRON_HOOK, array( $this, 'check_and_expire_bounties' ) );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
        }
    }

    /**
     * 悬赏功能是否启用
     *
     * @return bool
     */
    public function is_enabled() {
        if ( ! qibbs_is_points_integration_available() ) {
            return false;
        }
        return (bool) qibbs_get_option( 'enable_bounty_post', true );
    }

    /**
     * 创建悬赏记录并全额冻结积分
     *
     * @param int   $post_id   帖子 ID.
     * @param int   $author_id 发帖人 ID.
     * @param float $amount    悬赏积分数额.
     * @return array{success:bool,message:string,bounty_id?:int}
     */
    public function create_bounty( $post_id, $author_id, $amount ) {
        $post_id   = absint( $post_id );
        $author_id = absint( $author_id );
        $amount    = (float) $amount;

        if ( ! $this->is_enabled() ) {
            return array( 'success' => false, 'message' => __( '悬赏功能暂未开启', 'qibbs-community' ) );
        }

        if ( ! $post_id || ! $author_id || $amount <= 0 ) {
            return array( 'success' => false, 'message' => __( '悬赏参数无效', 'qibbs-community' ) );
        }

        // 校验等级门槛
        $min_level = absint( qibbs_get_option( 'bounty_min_level_to_post', 1 ) );
        if ( function_exists( 'qibbs_get_user_level' ) ) {
            $user_level = qibbs_get_user_level( $author_id );
            if ( $user_level < $min_level ) {
                return array(
                    'success' => false,
                    'message' => sprintf( __( '成长等级需达到 Lv.%d 才可发布悬赏贴', 'qibbs-community' ), $min_level ),
                );
            }
        }

        // 校验金额范围
        $min_amount = (float) qibbs_get_option( 'bounty_min_amount', 5 );
        $max_amount = (float) qibbs_get_option( 'bounty_max_amount', 1000 );
        $points_name = qibbs_get_points_name();

        if ( $amount < $min_amount ) {
            return array(
                'success' => false,
                'message' => sprintf( __( '单次悬赏不能低于 %s %s', 'qibbs-community' ), qibbs_format_points_amount( $min_amount ), $points_name ),
            );
        }

        if ( $max_amount > 0 && $amount > $max_amount ) {
            return array(
                'success' => false,
                'message' => sprintf( __( '单次悬赏不能高于 %s %s', 'qibbs-community' ), qibbs_format_points_amount( $max_amount ), $points_name ),
            );
        }

        // 检查可用余额
        $balance = qibbs_get_user_points_balance( $author_id );
        if ( null === $balance || $balance < $amount ) {
            return array(
                'success' => false,
                'message' => sprintf( __( '您的可用%s不足（当前余额：%s）', 'qibbs-community' ), $points_name, qibbs_format_points_amount( (float) $balance ) ),
            );
        }

        // 扣除/冻结积分
        $deducted = qibbs_deduct_user_points(
            $author_id,
            $amount,
            'community_bounty_freeze',
            sprintf( __( '发布悬赏动态 #%d 积分冻结', 'qibbs-community' ), $post_id ),
            $post_id
        );

        if ( ! $deducted ) {
            return array( 'success' => false, 'message' => sprintf( __( '扣除%s失败，请稍后重试', 'qibbs-community' ), $points_name ) );
        }

        global $wpdb;
        $table = Qibbs_Database::bounties();
        $days  = max( 1, absint( qibbs_get_option( 'bounty_expire_days', qibbs_get_option( 'bounty_decision_days', 7 ) ) ) );
        $deadline = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $days * DAY_IN_SECONDS ) );

        $inserted = $wpdb->insert(
            $table,
            array(
                'post_id'           => $post_id,
                'author_id'         => $author_id,
                'amount'            => $amount,
                'status'            => 'pending',
                'decision_deadline' => $deadline,
                'created_at'        => current_time( 'mysql' ),
                'updated_at'        => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%f', '%s', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            // 回滚积分
            qibbs_add_user_points(
                $author_id,
                $amount,
                'community_bounty_rollback',
                sprintf( __( '创建悬赏失败退还积分 #%d', 'qibbs-community' ), $post_id ),
                $post_id
            );
            return array( 'success' => false, 'message' => __( '创建悬赏记录失败', 'qibbs-community' ) );
        }

        do_action( 'qibbs_bounty_created', $post_id, $author_id, $amount );

        return array(
            'success'   => true,
            'message'   => __( '悬赏发布成功', 'qibbs-community' ),
            'bounty_id' => (int) $wpdb->insert_id,
        );
    }

    /**
     * 采纳最佳答案并结算悬赏积分
     *
     * @param int $post_id          帖子 ID.
     * @param int $comment_id       最佳评论 ID.
     * @param int $winner_user_id   中选作者 ID.
     * @param int $operator_user_id 操作人 ID（发帖人或管理员）.
     * @return array{success:bool,message:string}
     */
    public function award_bounty( $post_id, $comment_id, $winner_user_id, $operator_user_id = 0 ) {
        $post_id        = absint( $post_id );
        $comment_id     = absint( $comment_id );
        $winner_user_id = absint( $winner_user_id );

        if ( ! $post_id || ! $comment_id || ! $winner_user_id ) {
            return array( 'success' => false, 'message' => __( '结算参数无效', 'qibbs-community' ) );
        }

        global $wpdb;
        $table  = Qibbs_Database::bounties();
        $bounty = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", $post_id ) );

        if ( ! $bounty ) {
            // 该帖不是悬赏帖，正常跳过
            return array( 'success' => true, 'message' => '' );
        }

        if ( 'awarded' === (string) $bounty->status ) {
            return array( 'success' => false, 'message' => __( '该悬赏已结案发放，不可重复结算', 'qibbs-community' ) );
        }

        if ( 'refunded' === (string) $bounty->status ) {
            return array( 'success' => false, 'message' => __( '该悬赏已退还，无法采纳结算', 'qibbs-community' ) );
        }

        // 乐观锁原子更新状态为 awarded
        $now = current_time( 'mysql' );
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} 
             SET status = 'awarded', 
                 winner_user_id = %d, 
                 winner_comment_id = %d, 
                 arbitrated_by = %d, 
                 awarded_at = %s, 
                 updated_at = %s 
             WHERE post_id = %d AND status IN ('pending', 'pending_arbitration')",
            $winner_user_id,
            $comment_id,
            absint( $operator_user_id ),
            $now,
            $now,
            $post_id
        ) );

        if ( ! $updated ) {
            return array( 'success' => false, 'message' => __( '悬赏状态已变更，请刷新重试', 'qibbs-community' ) );
        }

        $amount = (float) $bounty->amount;
        $points_name = qibbs_get_points_name();

        // 将悬赏积分发放给最佳答主
        $awarded = qibbs_add_user_points(
            $winner_user_id,
            $amount,
            'community_bounty_reward',
            sprintf( __( '回答被采纳为最佳答案，获得悬赏奖励（动态 #%d）', 'qibbs-community' ), $post_id ),
            $post_id
        );

        // 发送站内通知给获赏者
        if ( class_exists( 'Qibbs_Notification_Service' ) ) {
            Qibbs_Notification_Service::instance()->send(
                $winner_user_id,
                'bounty_awarded',
                sprintf( __( '恭喜！您的回答在动态 #%d 中被采纳为最佳答案，成功获得 %s %s 悬赏！', 'qibbs-community' ), $post_id, qibbs_format_points_amount( $amount ), $points_name ),
                $post_id,
                'post'
            );
        }

        do_action( 'qibbs_bounty_awarded', $post_id, $winner_user_id, $amount, $operator_user_id );

        return array(
            'success' => true,
            'message' => sprintf( __( '已采纳最佳答案，%s %s 悬赏已派发给答主！', 'qibbs-community' ), qibbs_format_points_amount( $amount ), $points_name ),
        );
    }

    /**
     * 退还悬赏积分给发帖人
     *
     * @param int    $post_id          帖子 ID.
     * @param int    $operator_user_id 操作人 ID.
     * @param string $reason           退还原因说明.
     * @return array{success:bool,message:string}
     */
    public function refund_bounty( $post_id, $operator_user_id = 0, $reason = '' ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return array( 'success' => false, 'message' => __( '参数错误', 'qibbs-community' ) );
        }

        global $wpdb;
        $table  = Qibbs_Database::bounties();
        $bounty = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", $post_id ) );

        if ( ! $bounty || ! in_array( (string) $bounty->status, array( 'pending', 'pending_arbitration' ), true ) ) {
            return array( 'success' => false, 'message' => __( '该悬赏无法退还或已处理', 'qibbs-community' ) );
        }

        $now = current_time( 'mysql' );
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} 
             SET status = 'refunded', 
                 arbitrated_by = %d, 
                 refunded_at = %s, 
                 updated_at = %s 
             WHERE post_id = %d AND status IN ('pending', 'pending_arbitration')",
            absint( $operator_user_id ),
            $now,
            $now,
            $post_id
        ) );

        if ( ! $updated ) {
            return array( 'success' => false, 'message' => __( '悬赏状态已变更，请刷新重试', 'qibbs-community' ) );
        }

        $amount      = (float) $bounty->amount;
        $author_id   = (int) $bounty->author_id;
        $points_name = qibbs_get_points_name();

        // 原路退回积分
        qibbs_add_user_points(
            $author_id,
            $amount,
            'community_bounty_refund',
            sprintf( __( '悬赏退回（动态 #%d）: %s', 'qibbs-community' ), $post_id, $reason ? $reason : __( '到期或管理员处理', 'qibbs-community' ) ),
            $post_id
        );

        // 发送通知给楼主
        if ( class_exists( 'Qibbs_Notification_Service' ) ) {
            Qibbs_Notification_Service::instance()->send(
                $author_id,
                'bounty_refunded',
                sprintf( __( '您的悬赏动态 #%d 未产生采纳，悬赏金 %s %s 已全额原路退回至您的账户。', 'qibbs-community' ), $post_id, qibbs_format_points_amount( $amount ), $points_name ),
                $post_id,
                'post'
            );
        }

        do_action( 'qibbs_bounty_refunded', $post_id, $author_id, $amount, $operator_user_id, $reason );

        return array(
            'success' => true,
            'message' => sprintf( __( '悬赏金 %s %s 已成功退还给楼主', 'qibbs-community' ), qibbs_format_points_amount( $amount ), $points_name ),
        );
    }

    /**
     * 管理员执行仲裁裁决（代为采纳结赏 或 认定无有效解答退回）
     *
     * @param int    $post_id       帖子 ID.
     * @param string $decision      'award' 或 'refund'.
     * @param int    $comment_id    当 award 时中选的最佳评论 ID.
     * @param int    $admin_user_id 执行仲裁的管理员 ID.
     * @param string $reason        仲裁退还原因说明.
     * @return array{success:bool,message:string}
     */
    public function admin_arbitrate( $post_id, $decision, $comment_id = 0, $admin_user_id = 0, $reason = '' ) {
        $post_id       = absint( $post_id );
        $admin_user_id = absint( $admin_user_id ? $admin_user_id : get_current_user_id() );

        if ( ! $admin_user_id || ( ! user_can( $admin_user_id, 'manage_options' ) && ! user_can( $admin_user_id, 'moderate_comments' ) ) ) {
            return array( 'success' => false, 'message' => __( '无权执行悬赏仲裁', 'qibbs-community' ) );
        }

        if ( 'award' === $decision ) {
            $comment_id = absint( $comment_id );
            if ( ! $comment_id ) {
                return array( 'success' => false, 'message' => __( '请指定要采纳的最佳评论', 'qibbs-community' ) );
            }

            global $wpdb;
            $comments_table = Qibbs_Database::comments();
            $comment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$comments_table} WHERE id = %d AND post_id = %d LIMIT 1", $comment_id, $post_id ) );

            if ( ! $comment ) {
                return array( 'success' => false, 'message' => __( '指定的回复不存在', 'qibbs-community' ) );
            }

            // 更新帖子状态为已解决并关联 best_comment_id
            $posts_table = Qibbs_Database::posts();
            $wpdb->update(
                $posts_table,
                array(
                    'best_comment_id'   => $comment_id,
                    'best_answer_at'    => current_time( 'mysql' ),
                    'best_answer_by'    => $admin_user_id,
                    'discussion_status' => 'resolved',
                    'is_resolved'       => 1,
                    'updated_at'        => current_time( 'mysql' ),
                ),
                array( 'id' => $post_id ),
                array( '%d', '%s', '%d', '%s', '%d', '%s' ),
                array( '%d' )
            );

            return $this->award_bounty( $post_id, $comment_id, (int) $comment->user_id, $admin_user_id );

        } elseif ( 'refund' === $decision ) {
            $refund_reason = ! empty( $reason ) ? $reason : __( '管理员判定无有效解答并裁决退还', 'qibbs-community' );
            return $this->refund_bounty( $post_id, $admin_user_id, $refund_reason );
        }

        return array( 'success' => false, 'message' => __( '未知的仲裁指令', 'qibbs-community' ) );
    }

    /**
     * WP-Cron 定时检查悬赏到期状态
     */
    public function check_and_expire_bounties() {
        global $wpdb;
        $table = Qibbs_Database::bounties();
        $posts_table = Qibbs_Database::posts();
        $now = current_time( 'mysql' );

        // 查询所有处于 pending 且已超出自决期限的悬赏
        $expired_bounties = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, p.comment_count 
             FROM {$table} b 
             LEFT JOIN {$posts_table} p ON b.post_id = p.id 
             WHERE b.status = 'pending' AND b.decision_deadline <= %s 
             LIMIT 100",
            $now
        ) );

        if ( empty( $expired_bounties ) ) {
            return;
        }

        $auto_refund_zero = (bool) qibbs_get_option( 'bounty_auto_refund_on_zero_comments', qibbs_get_option( 'bounty_zero_replies_auto_refund', true ) );

        foreach ( $expired_bounties as $bounty ) {
            $post_id       = (int) $bounty->post_id;
            $comment_count = (int) $bounty->comment_count;

            if ( $comment_count <= 0 && $auto_refund_zero ) {
                // 零回复：无人解答，自动全额退款给楼主
                $this->refund_bounty( $post_id, 0, __( '到期无回复自动退还', 'qibbs-community' ) );
            } else {
                // 存在回复但楼主未采纳：转入待仲裁队列，由管理员裁决
                $wpdb->update(
                    $table,
                    array(
                        'status'     => 'pending_arbitration',
                        'updated_at' => $now,
                    ),
                    array( 'id' => (int) $bounty->id ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );

                do_action( 'qibbs_bounty_entered_arbitration', $post_id, (int) $bounty->author_id );
            }
        }
    }

    /**
     * 获取单个帖子的悬赏数据
     *
     * @param int $post_id Post ID.
     * @return object|null
     */
    public function get_bounty( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return null;
        }

        global $wpdb;
        $table = Qibbs_Database::bounties();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", $post_id ) );
    }

    /**
     * 批量查询帖子列表的悬赏数据（用于 Feed 流列表 O(1) 预加载，彻底杜绝 N+1 查询）
     *
     * @param array<int> $post_ids Post IDs.
     * @return array<int,object> Keyed by post_id.
     */
    public function get_bounties_map( $post_ids ) {
        $post_ids = array_filter( array_map( 'absint', (array) $post_ids ) );
        if ( empty( $post_ids ) ) {
            return array();
        }

        global $wpdb;
        $table = Qibbs_Database::bounties();
        $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id IN ({$placeholders})",
            $post_ids
        ) );

        $map = array();
        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $map[ (int) $row->post_id ] = $row;
            }
        }

        return $map;
    }

    /**
     * 帖子被删除时触发自动退还悬赏处理
     *
     * @param int $post_id Post ID.
     */
    public function on_post_deleted( $post_id ) {
        $this->refund_bounty( $post_id, 0, __( '帖子已被删除，悬赏积分原路退还', 'qibbs-community' ) );
    }
}
