<?php
/**
 * 社区回帖红包核心服务类
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Redpacket_Service {

    /**
     * 单例实例
     *
     * @var Qibbs_Redpacket_Service|null
     */
    private static $instance = null;

    /**
     * WP-Cron 红包到期退还检查定时钩子
     */
    const CRON_HOOK = 'qibbs_redpacket_expiration_check';

    /**
     * 获取单例
     *
     * @return Qibbs_Redpacket_Service
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
        add_action( self::CRON_HOOK, array( $this, 'refund_expired_redpackets' ) );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
        }
    }

    /**
     * 红包功能是否启用
     *
     * @return bool
     */
    public function is_enabled() {
        if ( ! qibbs_is_points_integration_available() ) {
            return false;
        }
        return (bool) qibbs_get_option( 'enable_redpacket_post', true );
    }

    /**
     * 创建红包并全额冻结积分
     *
     * @param int    $post_id      帖子 ID.
     * @param int    $author_id    发帖人 ID.
     * @param float  $total_amount 红包总额.
     * @param int    $total_num    总份数.
     * @param string $draw_type    发放方式: 'instant' 回帖即领 | 'lottery' 随机抽取.
     * @param string $alloc_type   金额分配: 'random' 拼手气 | 'equal' 平均等额.
     * @return array{success:bool,message:string,redpacket_id?:int}
     */
    public function create_redpacket( $post_id, $author_id, $total_amount, $total_num, $draw_type = 'instant', $alloc_type = 'random' ) {
        $post_id      = absint( $post_id );
        $author_id    = absint( $author_id );
        $total_amount = (float) $total_amount;
        $total_num    = absint( $total_num );
        $draw_type    = in_array( $draw_type, array( 'instant', 'lottery' ), true ) ? $draw_type : 'instant';
        $alloc_type   = in_array( $alloc_type, array( 'random', 'equal' ), true ) ? $alloc_type : 'random';

        if ( ! $this->is_enabled() ) {
            return array( 'success' => false, 'message' => __( '红包功能暂未开启', 'qibbs-community' ) );
        }

        if ( ! $post_id || ! $author_id || $total_amount <= 0 || $total_num <= 0 ) {
            return array( 'success' => false, 'message' => __( '红包参数无效', 'qibbs-community' ) );
        }

        $points_name = qibbs_get_points_name();
        $min_total   = (float) qibbs_get_option( 'redpacket_min_total_amount', 10 );
        $max_total   = (float) qibbs_get_option( 'redpacket_max_total_amount', 10000 );
        $min_num     = max( 1, absint( qibbs_get_option( 'redpacket_min_count', 5 ) ) );
        $max_num     = max( $min_num, absint( qibbs_get_option( 'redpacket_max_count', 100 ) ) );

        if ( $total_amount < $min_total ) {
            return array(
                'success' => false,
                'message' => sprintf( __( '红包总额不能低于 %s %s', 'qibbs-community' ), qibbs_format_points_amount( $min_total ), $points_name ),
            );
        }

        if ( $max_total > 0 && $total_amount > $max_total ) {
            return array(
                'success' => false,
                'message' => sprintf( __( '红包总额不能高于 %s %s', 'qibbs-community' ), qibbs_format_points_amount( $max_total ), $points_name ),
            );
        }

        if ( $total_num < $min_num || $total_num > $max_num ) {
            return array(
                'success' => false,
                'message' => sprintf( __( '红包份数须在 %d ~ %d 份之间', 'qibbs-community' ), $min_num, $max_num ),
            );
        }

        // 保证每份至少达到单份最低限制
        $min_per_packet = max( 0.01, (float) qibbs_get_option( 'redpacket_min_single_amount', qibbs_get_option( 'redpacket_min_per_packet', 1 ) ) );
        if ( $total_amount < ( $total_num * $min_per_packet ) ) {
            return array(
                'success' => false,
                'message' => sprintf( __( '总额不足分配，每份至少需要 %s %s', 'qibbs-community' ), qibbs_format_points_amount( $min_per_packet ), $points_name ),
            );
        }

        // 平均等额时，总额必须能被份数整除
        if ( 'equal' === $alloc_type && fmod( $total_amount, $total_num ) != 0 ) {
            return array(
                'success' => false,
                'message' => __( '平均分配时总额必须能被份数整除，不然发不出去', 'qibbs-community' ),
            );
        }

        // 检查可用余额
        $balance = qibbs_get_user_points_balance( $author_id );
        if ( null === $balance || $balance < $total_amount ) {
            return array(
                'success' => false,
                'message' => sprintf( __( '您的可用%s不足（当前余额：%s）', 'qibbs-community' ), $points_name, qibbs_format_points_amount( (float) $balance ) ),
            );
        }

        // 扣除/冻结红包积分
        $deducted = qibbs_deduct_user_points(
            $author_id,
            $total_amount,
            'community_redpacket_freeze',
            sprintf( __( '发布回帖红包 #%d 积分冻结', 'qibbs-community' ), $post_id ),
            $post_id
        );

        if ( ! $deducted ) {
            return array( 'success' => false, 'message' => sprintf( __( '扣除%s失败，请稍后重试', 'qibbs-community' ), $points_name ) );
        }

        global $wpdb;
        $table = Qibbs_Database::redpackets();
        $hours = max( 1, absint( qibbs_get_option( 'redpacket_expire_hours', qibbs_get_option( 'redpacket_expiry_hours', 24 ) ) ) );
        // 统一采用本地时间戳计算，与 current_time( 'mysql' ) 严格保持时区一致（避免 UTC 与本地时间偏差导致创建即失效）
        $expires_at = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $hours * HOUR_IN_SECONDS ) );

        $inserted = $wpdb->insert(
            $table,
            array(
                'post_id'          => $post_id,
                'author_id'        => $author_id,
                'total_amount'     => $total_amount,
                'total_num'        => $total_num,
                'remaining_amount' => $total_amount,
                'remaining_num'    => $total_num,
                'draw_type'        => $draw_type,
                'alloc_type'       => $alloc_type,
                'status'           => 'active',
                'expires_at'       => $expires_at,
                'created_at'       => current_time( 'mysql' ),
                'updated_at'       => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%f', '%d', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            // 回滚积分
            qibbs_add_user_points(
                $author_id,
                $total_amount,
                'community_redpacket_rollback',
                sprintf( __( '创建红包失败退还积分 #%d', 'qibbs-community' ), $post_id ),
                $post_id
            );
            return array( 'success' => false, 'message' => __( '创建红包记录失败', 'qibbs-community' ) );
        }

        do_action( 'qibbs_redpacket_created', $post_id, $author_id, $total_amount, $total_num );

        return array(
            'success'      => true,
            'message'      => __( '红包发布成功', 'qibbs-community' ),
            'redpacket_id' => (int) $wpdb->insert_id,
        );
    }

    /**
     * 回帖即领模式：用户发表评论后自动领取一份红包
     *
     * @param int $post_id    帖子 ID.
     * @param int $user_id    领红包用户 ID.
     * @param int $comment_id 触发领取的评论 ID.
     * @return array{success:bool,claimed:bool,amount?:float,message:string}
     */
    public function claim_instant_packet( $post_id, $user_id, $comment_id ) {
        $post_id    = absint( $post_id );
        $user_id    = absint( $user_id );
        $comment_id = absint( $comment_id );

        if ( ! $post_id || ! $user_id ) {
            return array( 'success' => false, 'claimed' => false, 'message' => __( '参数错误', 'qibbs-community' ) );
        }

        global $wpdb;
        $table = Qibbs_Database::redpackets();
        $packet = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", $post_id ) );

        // 状态自愈：若尚有剩余份数与金额却被误标为 finished（且尚未到期），自动恢复为 active
        if ( $packet && 'finished' === (string) $packet->status && (int) $packet->remaining_num > 0 && (float) $packet->remaining_amount > 0.001 ) {
            $now = current_time( 'mysql' );
            if ( empty( $packet->expires_at ) || $packet->expires_at > $now ) {
                $wpdb->update(
                    $table,
                    array( 'status' => 'active', 'updated_at' => $now ),
                    array( 'id' => (int) $packet->id )
                );
                $packet->status = 'active';
            }
        }

        if ( ! $packet || 'active' !== (string) $packet->status || 'instant' !== (string) $packet->draw_type ) {
            return array( 'success' => true, 'claimed' => false, 'message' => '' );
        }

        // 检查发帖人自领限制
        $allow_author = (bool) qibbs_get_option( 'redpacket_allow_author_claim', false );
        if ( ! $allow_author && (int) $packet->author_id === $user_id ) {
            return array(
                'success' => true,
                'claimed' => false,
                'reason'  => 'author_self',
                'message' => __( '您是发帖人，回复不参与领红包', 'qibbs-community' ),
            );
        }

        // 检查等级门槛
        $min_level = absint( qibbs_get_option( 'redpacket_min_level_to_claim', 1 ) );
        if ( function_exists( 'qibbs_get_user_level' ) ) {
            $user_level = qibbs_get_user_level( $user_id );
            if ( $user_level < $min_level ) {
                return array(
                    'success' => true,
                    'claimed' => false,
                    'reason'  => 'level_not_met',
                    'message' => sprintf( __( '等级需达到 Lv.%d 才可抢红包', 'qibbs-community' ), $min_level ),
                );
            }
        }

        // 检查是否已抢过
        $logs_table = Qibbs_Database::redpacket_logs();
        $already_claimed = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$logs_table} WHERE redpacket_id = %d AND user_id = %d LIMIT 1",
            (int) $packet->id,
            $user_id
        ) );

        if ( $already_claimed ) {
            return array(
                'success' => true,
                'claimed' => false,
                'reason'  => 'already_claimed',
                'message' => __( '您已领过该红包，每人限领一份', 'qibbs-community' ),
            );
        }

        // 检查是否过期（过期即刻结算退还发帖人并阻断领取）
        $now = current_time( 'mysql' );
        if ( ! empty( $packet->expires_at ) && $packet->expires_at <= $now ) {
            $this->refund_single_packet( $packet );
            return array(
                'success' => true,
                'claimed' => false,
                'reason'  => 'expired',
                'message' => __( '红包已过有效期限已结束', 'qibbs-community' ),
            );
        }

        // 检查是否领完
        if ( (int) $packet->remaining_num <= 0 || (float) $packet->remaining_amount <= 0 ) {
            return array(
                'success' => true,
                'claimed' => false,
                'reason'  => 'empty',
                'message' => __( '手慢了，红包已被领完', 'qibbs-community' ),
            );
        }

        $rem_num    = (int) $packet->remaining_num;
        $rem_amount = (float) $packet->remaining_amount;
        $alloc_type = (string) $packet->alloc_type;

        $min_single = max( 0.01, (float) qibbs_get_option( 'redpacket_min_single_amount', qibbs_get_option( 'redpacket_min_per_packet', 1 ) ) );

        // 计算当前抢到的金额
        if ( $rem_num <= 1 ) {
            $claim_amount = $rem_amount;
        } elseif ( 'equal' === $alloc_type ) {
            $claim_amount = round( $rem_amount / $rem_num, 2 );
        } else {
            // 拼手气二倍均值算法
            $min_val = $min_single;
            $max_val = floor( ( $rem_amount / $rem_num ) * 2 );
            $max_val = min( $max_val, floor( $rem_amount - ( ( $rem_num - 1 ) * $min_val ) ) );
            $max_val = max( $min_val, $max_val );
            $claim_amount = (float) mt_rand( (int) $min_val, (int) $max_val );
        }

        if ( $rem_num > 1 ) {
            $claim_amount = max( $min_single, min( $claim_amount, round( $rem_amount - ( ( $rem_num - 1 ) * $min_single ), 2 ) ) );
        } else {
            $claim_amount = $rem_amount;
        }
        $claim_amount = round( min( $claim_amount, $rem_amount ), 2 );

        // 乐观锁原子更新红包表（必须将 status 判定放在首位，避免 remaining_num 先减 1 导致误判提前结束）
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} 
             SET status = CASE 
                     WHEN (remaining_num <= 1 OR (remaining_amount - %f) <= 0.001) THEN 'finished' 
                     ELSE 'active' 
                 END,
                 remaining_num = GREATEST(0, remaining_num - 1), 
                 remaining_amount = GREATEST(0.00, remaining_amount - %f), 
                 updated_at = %s 
             WHERE id = %d AND remaining_num > 0 AND remaining_amount >= %f",
            $claim_amount,
            $claim_amount,
            $now,
            (int) $packet->id,
            $claim_amount
        ) );

        if ( ! $updated ) {
            return array( 'success' => true, 'claimed' => false, 'reason' => 'empty', 'message' => __( '手慢了，红包已被抢完', 'qibbs-community' ) );
        }

        // 写入领取明细（唯一约束防重）
        $wpdb->insert(
            $logs_table,
            array(
                'redpacket_id' => (int) $packet->id,
                'post_id'      => $post_id,
                'user_id'      => $user_id,
                'comment_id'   => $comment_id,
                'amount'       => $claim_amount,
                'created_at'   => $now,
            ),
            array( '%d', '%d', '%d', '%d', '%f', '%s' )
        );

        $points_name = qibbs_get_points_name();

        // 增加用户积分
        qibbs_add_user_points(
            $user_id,
            $claim_amount,
            'community_redpacket_claim',
            sprintf( __( '回帖领得红包（动态 #%d）', 'qibbs-community' ), $post_id ),
            $post_id
        );

        do_action( 'qibbs_redpacket_claimed', (int) $packet->id, $post_id, $user_id, $claim_amount );

        return array(
            'success'       => true,
            'claimed'       => true,
            'amount'        => $claim_amount,
            'points_name'   => $points_name,
            'remaining_num' => $rem_num - 1,
            'message'       => sprintf( __( '恭喜获得回帖红包 %s %s！', 'qibbs-community' ), qibbs_format_points_amount( $claim_amount ), $points_name ),
        );
    }

    /**
     * 随机抽取模式：作者手动开奖或定时器自动开奖
     *
     * @param int $post_id          帖子 ID.
     * @param int $operator_user_id 操作人 ID.
     * @return array{success:bool,message:string}
     */
    public function draw_lottery_packet( $post_id, $operator_user_id = 0 ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return array( 'success' => false, 'message' => __( '参数错误', 'qibbs-community' ) );
        }

        global $wpdb;
        $table  = Qibbs_Database::redpackets();
        $packet = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", $post_id ) );

        if ( ! $packet || 'active' !== (string) $packet->status || 'lottery' !== (string) $packet->draw_type ) {
            return array( 'success' => false, 'message' => __( '红包当前不可开奖', 'qibbs-community' ) );
        }

        // 权限校验：发帖人或管理员
        if ( $operator_user_id > 0 && (int) $packet->author_id !== $operator_user_id && ! user_can( $operator_user_id, 'manage_options' ) ) {
            return array( 'success' => false, 'message' => __( '无权操作开奖', 'qibbs-community' ) );
        }

        $comments_table = Qibbs_Database::comments();
        $allow_author = (bool) qibbs_get_option( 'redpacket_allow_author_claim', false );
        $author_sql = ( ! $allow_author ) ? $wpdb->prepare( "AND user_id != %d", (int) $packet->author_id ) : "";

        // 提取所有参与回帖的有效用户列表
        $candidate_users = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$comments_table} 
             WHERE post_id = %d AND status = 'approved' {$author_sql}",
            $post_id
        ) );

        if ( empty( $candidate_users ) ) {
            return array( 'success' => false, 'message' => __( '暂无回帖参与者，无法开奖', 'qibbs-community' ) );
        }

        $now = current_time( 'mysql' );
        $total_num    = (int) $packet->total_num;
        $total_amount = (float) $packet->remaining_amount;
        $alloc_type   = (string) $packet->alloc_type;

        // 随机打乱抽取中奖者
        shuffle( $candidate_users );
        $winner_ids = array_slice( $candidate_users, 0, $total_num );
        $winner_count = count( $winner_ids );

        if ( $winner_count === 0 ) {
            return array( 'success' => false, 'message' => __( '没有符合条件的中奖者', 'qibbs-community' ) );
        }

        // 原子乐观锁：先锁定状态为 drawing，杜绝重复并发开奖
        $locked = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'drawing', updated_at = %s WHERE id = %d AND status = 'active'",
            $now,
            (int) $packet->id
        ) );

        if ( ! $locked ) {
            return array( 'success' => false, 'message' => __( '红包正在开奖中或已完成，请勿重复操作', 'qibbs-community' ) );
        }

        // 预生成所有份额的红包金额池，严格保证池内金额总和精确等于 $total_amount
        $packets_pool    = array();
        $rem_pool_amount = $total_amount;
        $min_single      = max( 0.01, (float) qibbs_get_option( 'redpacket_min_single_amount', qibbs_get_option( 'redpacket_min_per_packet', 1 ) ) );

        for ( $i = 0; $i < $total_num; $i++ ) {
            $shares_left = $total_num - $i;
            if ( $shares_left === 1 ) {
                $packets_pool[] = round( $rem_pool_amount, 2 );
            } elseif ( 'equal' === $alloc_type ) {
                $amt = round( $total_amount / $total_num, 2 );
                $amt = min( $amt, round( $rem_pool_amount - ( ( $shares_left - 1 ) * $min_single ), 2 ) );
                $amt = max( $min_single, $amt );
                $packets_pool[] = $amt;
                $rem_pool_amount = round( $rem_pool_amount - $amt, 2 );
            } else {
                // 拼手气二倍均值算法
                $min_val = $min_single;
                $max_val = floor( ( $rem_pool_amount / $shares_left ) * 2 );
                $max_val = min( $max_val, floor( $rem_pool_amount - ( ( $shares_left - 1 ) * $min_val ) ) );
                $max_val = max( $min_val, $max_val );
                $amt     = (float) mt_rand( (int) $min_val, (int) $max_val );
                $amt     = max( $min_val, min( $amt, round( $rem_pool_amount - ( ( $shares_left - 1 ) * $min_val ), 2 ) ) );
                $packets_pool[] = $amt;
                $rem_pool_amount = round( $rem_pool_amount - $amt, 2 );
            }
        }

        // 分配积分
        $logs_table     = Qibbs_Database::redpacket_logs();
        $assigned_total = 0.0;
        $points_name    = qibbs_get_points_name();

        foreach ( $winner_ids as $idx => $uid ) {
            $reward = isset( $packets_pool[ $idx ] ) ? $packets_pool[ $idx ] : 0.0;
            if ( $reward <= 0 ) {
                continue;
            }

            $assigned_total += $reward;

            // 写入明细
            $wpdb->insert(
                $logs_table,
                array(
                    'redpacket_id' => (int) $packet->id,
                    'post_id'      => $post_id,
                    'user_id'      => (int) $uid,
                    'comment_id'   => 0,
                    'amount'       => $reward,
                    'created_at'   => $now,
                ),
                array( '%d', '%d', '%d', '%d', '%f', '%s' )
            );

            // 发放积分
            qibbs_add_user_points(
                (int) $uid,
                $reward,
                'community_redpacket_lottery',
                sprintf( __( '回帖抽奖红包中奖（动态 #%d）', 'qibbs-community' ), $post_id ),
                $post_id
            );

            // 站内通知
            if ( class_exists( 'Qibbs_Notification_Service' ) ) {
                Qibbs_Notification_Service::instance()->send(
                    (int) $uid,
                    'redpacket_won',
                    sprintf( __( '恭喜！您在动态 #%d 的回帖抽奖中幸运中奖，获得 %s %s 红包！', 'qibbs-community' ), $post_id, qibbs_format_points_amount( $reward ), $points_name ),
                    $post_id,
                    'post'
                );
            }
        }

        // 结案并退还未分完差额（若参与人数少于总份数）
        $unclaimed_refund = round( max( 0.0, $total_amount - $assigned_total ), 2 );
        if ( $unclaimed_refund > 0 ) {
            qibbs_add_user_points(
                (int) $packet->author_id,
                $unclaimed_refund,
                'community_redpacket_refund',
                sprintf( __( '回帖抽奖人数不足未发完部分退回（动态 #%d）', 'qibbs-community' ), $post_id ),
                $post_id
            );
        }

        $wpdb->update(
            $table,
            array(
                'status'           => 'finished',
                'remaining_amount' => 0,
                'remaining_num'    => 0,
                'refunded_amount'  => $unclaimed_refund,
                'updated_at'       => $now,
            ),
            array( 'id' => (int) $packet->id ),
            array( '%s', '%f', '%d', '%f', '%s' ),
            array( '%d' )
        );

        do_action( 'qibbs_redpacket_lottery_drawn', (int) $packet->id, $post_id, count( $winner_ids ) );

        return array(
            'success' => true,
            'message' => sprintf( __( '开奖完成，共 %d 位回帖者获得红包！', 'qibbs-community' ), count( $winner_ids ) ),
        );
    }

    /**
     * 单个红包超时未领完自动结算退款（幂等原子更新）
     *
     * @param object $packet 红包数据库行对象.
     * @return bool 是否成功结算退回.
     */
    public function refund_single_packet( $packet ) {
        if ( ! $packet || 'active' !== (string) $packet->status ) {
            return false;
        }

        global $wpdb;
        $table         = Qibbs_Database::redpackets();
        $now           = current_time( 'mysql' );
        $post_id       = (int) $packet->post_id;
        $author_id     = (int) $packet->author_id;
        $refund_amount = (float) $packet->remaining_amount;

        // 抽奖模式且到期：若未开奖，优先尝试自动系统开奖
        if ( 'lottery' === (string) $packet->draw_type ) {
            $draw_result = $this->draw_lottery_packet( $post_id, 0 );
            if ( ! empty( $draw_result['success'] ) ) {
                return true;
            }
        }

        if ( $refund_amount <= 0 ) {
            $wpdb->update(
                $table,
                array( 'status' => 'finished', 'updated_at' => $now ),
                array( 'id' => (int) $packet->id )
            );
            $packet->status = 'finished';
            return true;
        }

        // 原子更新为 expired_refunded
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} 
             SET status = 'expired_refunded', 
                 remaining_amount = 0, 
                 remaining_num = 0, 
                 refunded_amount = %f, 
                 refunded_at = %s, 
                 updated_at = %s 
             WHERE id = %d AND status = 'active'",
            $refund_amount,
            $now,
            $now,
            (int) $packet->id
        ) );

        if ( ! $updated ) {
            return false;
        }

        $points_name = qibbs_get_points_name();

        // 退还剩余积分给楼主
        qibbs_add_user_points(
            $author_id,
            $refund_amount,
            'community_redpacket_refund',
            sprintf( __( '回帖红包超时未领完退回（动态 #%d）', 'qibbs-community' ), $post_id ),
            $post_id
        );

        // 发送站内通知
        if ( class_exists( 'Qibbs_Notification_Service' ) ) {
            Qibbs_Notification_Service::instance()->send(
                $author_id,
                'redpacket_refunded',
                sprintf( __( '您的动态 #%d 红包已超时结束，未领取的 %s %s 积分已全额退还至您的账户。', 'qibbs-community' ), $post_id, qibbs_format_points_amount( $refund_amount ), $points_name ),
                $post_id,
                'post'
            );
        }

        do_action( 'qibbs_redpacket_expired_refunded', (int) $packet->id, $post_id, $refund_amount );

        // 同步修改内存对象
        $packet->status           = 'expired_refunded';
        $packet->remaining_amount = 0;
        $packet->remaining_num    = 0;
        $packet->refunded_amount  = $refund_amount;
        $packet->refunded_at      = $now;

        return true;
    }

    /**
     * WP-Cron 定时检查并退还超时未领完的红包剩余积分
     */
    public function refund_expired_redpackets() {
        global $wpdb;
        $table = Qibbs_Database::redpackets();
        $now   = current_time( 'mysql' );

        $expired_packets = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE status = 'active' AND expires_at <= %s AND remaining_amount > 0 
             LIMIT 100",
            $now
        ) );

        if ( empty( $expired_packets ) ) {
            return;
        }

        foreach ( $expired_packets as $packet ) {
            $this->refund_single_packet( $packet );
        }
    }

    /**
     * 获取单个帖子的红包数据
     *
     * @param int $post_id Post ID.
     * @return object|null
     */
    public function get_redpacket( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return null;
        }

        global $wpdb;
        $table  = Qibbs_Database::redpackets();
        $packet = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", $post_id ) );

        // 访问时若发现已超时且处于 active 状态，即时执行自动结算退回，确保数据强一致性
        if ( $packet && 'active' === (string) $packet->status && ! empty( $packet->expires_at ) && $packet->expires_at <= current_time( 'mysql' ) ) {
            $this->refund_single_packet( $packet );
        }

        // 状态自愈：若尚有剩余份数和金额却被误标记为 finished（且尚未到期），自动修复恢复为 active 状态
        if ( $packet && 'finished' === (string) $packet->status && (int) $packet->remaining_num > 0 && (float) $packet->remaining_amount > 0.001 ) {
            $now = current_time( 'mysql' );
            if ( empty( $packet->expires_at ) || $packet->expires_at > $now ) {
                $wpdb->update(
                    $table,
                    array( 'status' => 'active', 'updated_at' => $now ),
                    array( 'id' => (int) $packet->id )
                );
                $packet->status = 'active';
            } else {
                $packet->status = 'active';
                $this->refund_single_packet( $packet );
            }
        }

        return $packet;
    }

    /**
     * 批量查询帖子列表的红包数据（Feed 流 O(1) 预加载，彻底杜绝 N+1 查询）
     *
     * @param array<int> $post_ids Post IDs.
     * @return array<int,object> Keyed by post_id.
     */
    public function get_redpackets_map( $post_ids ) {
        $post_ids = array_filter( array_map( 'absint', (array) $post_ids ) );
        if ( empty( $post_ids ) ) {
            return array();
        }

        global $wpdb;
        $table = Qibbs_Database::redpackets();
        $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id IN ({$placeholders})",
            $post_ids
        ) );

        $map = array();
        if ( ! empty( $rows ) ) {
            $now = current_time( 'mysql' );
            foreach ( $rows as $row ) {
                if ( 'active' === (string) $row->status && ! empty( $row->expires_at ) && $row->expires_at <= $now ) {
                    $this->refund_single_packet( $row );
                } elseif ( 'finished' === (string) $row->status && (int) $row->remaining_num > 0 && (float) $row->remaining_amount > 0.001 ) {
                    if ( empty( $row->expires_at ) || $row->expires_at > $now ) {
                        $wpdb->update(
                            $table,
                            array( 'status' => 'active', 'updated_at' => $now ),
                            array( 'id' => (int) $row->id )
                        );
                        $row->status = 'active';
                    } else {
                        $row->status = 'active';
                        $this->refund_single_packet( $row );
                    }
                }
                $map[ (int) $row->post_id ] = $row;
            }
        }

        return $map;
    }

    /**
     * 检查指定用户是否已领取过该红包
     *
     * @param int $redpacket_id Redpacket ID.
     * @param int $user_id      User ID.
     * @return bool
     */
    public function has_user_claimed( $redpacket_id, $user_id ) {
        $redpacket_id = absint( $redpacket_id );
        $user_id      = absint( $user_id );
        if ( ! $redpacket_id || ! $user_id ) {
            return false;
        }

        global $wpdb;
        $table = Qibbs_Database::redpacket_logs();
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE redpacket_id = %d AND user_id = %d LIMIT 1",
            $redpacket_id,
            $user_id
        ) );
    }

    /**
     * 获取红包领取记录明细（手气榜）
     *
     * @param int $redpacket_id Redpacket ID.
     * @param int $limit        Limit.
     * @return array
     */
    public function get_logs( $redpacket_id, $limit = 50 ) {
        $redpacket_id = absint( $redpacket_id );
        if ( ! $redpacket_id ) {
            return array();
        }

        global $wpdb;
        $table = Qibbs_Database::redpacket_logs();
        $limit = max( 1, min( 100, absint( $limit ) ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE redpacket_id = %d ORDER BY id ASC LIMIT %d",
            $redpacket_id,
            $limit
        ) );

        $list = array();
        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $user = get_userdata( (int) $row->user_id );
                $list[] = array(
                    'user_id'      => (int) $row->user_id,
                    'display_name' => $user ? $user->display_name : __( '匿名用户', 'qibbs-community' ),
                    'avatar'       => get_avatar_url( (int) $row->user_id, array( 'size' => 36 ) ),
                    'amount'       => (float) $row->amount,
                    'created_at'   => $row->created_at,
                );
            }
        }

        return $list;
    }

    /**
     * 帖子被删除时触发自动退还剩余红包积分
     *
     * @param int $post_id Post ID.
     */
    public function on_post_deleted( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        global $wpdb;
        $table  = Qibbs_Database::redpackets();
        $packet = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d AND status = 'active' AND remaining_amount > 0 LIMIT 1", $post_id ) );

        if ( ! $packet ) {
            return;
        }

        $now = current_time( 'mysql' );
        $refund_amount = (float) $packet->remaining_amount;
        $author_id     = (int) $packet->author_id;

        // 原子更新为 refunded
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} 
             SET status = 'refunded', 
                 remaining_amount = 0, 
                 remaining_num = 0, 
                 refunded_amount = %f, 
                 updated_at = %s 
             WHERE id = %d AND status = 'active'",
            $refund_amount,
            $now,
            (int) $packet->id
        ) );

        if ( ! $updated ) {
            return;
        }

        $points_name = qibbs_get_points_name();
        qibbs_add_user_points(
            $author_id,
            $refund_amount,
            'community_redpacket_refund',
            sprintf( __( '帖子已被删除，未领完的红包积分退回（动态 #%d）', 'qibbs-community' ), $post_id ),
            $post_id
        );

        if ( class_exists( 'Qibbs_Notification_Service' ) ) {
            Qibbs_Notification_Service::instance()->send(
                $author_id,
                'redpacket_refunded',
                sprintf( __( '您的动态 #%d 已被删除，未领取的红包剩余 %s %s 积分已全额退还至您的账户。', 'qibbs-community' ), $post_id, qibbs_format_points_amount( $refund_amount ), $points_name ),
                $post_id,
                'post'
            );
        }

        do_action( 'qibbs_redpacket_deleted_refunded', (int) $packet->id, $post_id, $refund_amount );
    }
}
