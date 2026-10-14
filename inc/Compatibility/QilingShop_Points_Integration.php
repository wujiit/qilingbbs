<?php
/**
 * 启灵微社区 - 启灵商城积分系统对接
 *
 * @package Qibbs_Community
 * @subpackage Compatibility
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_QilingShop_Points_Integration {

    /**
     * 单例实例
     *
     * @var Qibbs_QilingShop_Points_Integration|null
     */
    private static $instance = null;

    /**
     * 获取单例实例
     *
     * @return Qibbs_QilingShop_Points_Integration
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
        if ( ! class_exists( 'QilingShop_Points' ) ) {
            return;
        }

        $this->init_hooks();
    }

    /**
     * 注册积分联动事件钩子
     *
     * @return void
     */
    private function init_hooks() {
        add_action( 'qibbs_post_created', array( $this, 'handle_post_created' ), 20, 2 );
        add_action( 'qibbs_comment_added', array( $this, 'handle_comment_added' ), 20, 3 );
        add_action( 'qibbs_comment_approved', array( $this, 'handle_comment_approved' ), 20, 3 );
        add_action( 'qibbs_best_answer_set', array( $this, 'handle_best_answer_set' ), 20, 4 );
        add_action( 'qibbs_post_featured', array( $this, 'handle_post_featured' ), 20, 2 );
        add_action( 'qibbs_ticket_published', array( $this, 'handle_ticket_published' ), 20, 3 );
        add_filter( 'qilingshop_points_log_types', array( $this, 'register_points_log_types' ) );
    }

    /**
     * 注册社区业务在启灵商城积分流水中的中文名称映射
     *
     * @param array $types 现有类型映射.
     * @return array
     */
    public function register_points_log_types( $types ) {
        $community_types = array(
            'community_prop_purchase'        => __( '社区道具兑换', 'qibbs-community' ),
            'community_prop_rollback'        => __( '社区道具退还', 'qibbs-community' ),
            'community_bounty_publish'       => __( '社区发布悬赏', 'qibbs-community' ),
            'community_bounty_reward'        => __( '社区悬赏奖励', 'qibbs-community' ),
            'community_bounty_refund'        => __( '社区悬赏退还', 'qibbs-community' ),
            'community_bounty_rollback'      => __( '社区悬赏回滚', 'qibbs-community' ),
            'community_redpacket_publish'    => __( '社区塞红包', 'qibbs-community' ),
            'community_redpacket_claim'      => __( '社区抢红包', 'qibbs-community' ),
            'community_redpacket_refund'     => __( '社区红包退还', 'qibbs-community' ),
            'community_redpacket_rollback'   => __( '社区红包回滚', 'qibbs-community' ),
            'community_c2c_buy'              => __( '社区靓号购买', 'qibbs-community' ),
            'community_c2c_buy_rollback'     => __( '社区靓号购买回滚', 'qibbs-community' ),
            'community_c2c_seller_income'    => __( '社区靓号出售收益', 'qibbs-community' ),
            'community_vanity_draw'          => __( '社区抽取靓号', 'qibbs-community' ),
            'community_vanity_draw_rollback' => __( '社区靓号抽奖回滚', 'qibbs-community' ),
            'community_vanity_recycle'       => __( '社区靓号系统回收', 'qibbs-community' ),
            // 社区靓号核心业务变动映射
            'c2c_buy_vanity_uid'             => __( '交易行购买靓号', 'qibbs-community' ),
            'c2c_sale_vanity_uid'            => __( '交易行售出靓号', 'qibbs-community' ),
            'buy_vanity_uid'                 => __( '官方商城兑换靓号', 'qibbs-community' ),
            'recycle_vanity_uid'             => __( '官方回收靓号变现', 'qibbs-community' ),
            'buy_vanity_rollback'            => __( '购买靓号退款', 'qibbs-community' ),
            'c2c_buy_rollback'               => __( '交易行购买退款', 'qibbs-community' ),
            'community_level_upgrade_reward' => __( '社区等级升级奖励', 'qibbs-community' ),
            'qibbs_post_publish'             => __( '社区发帖奖励', 'qibbs-community' ),
            'qibbs_comment_approved'         => __( '社区评论奖励', 'qibbs-community' ),
            'qibbs_best_answer'              => __( '社区采纳答案奖励', 'qibbs-community' ),
            'qibbs_featured_post'            => __( '社区精华推荐奖励', 'qibbs-community' ),
            'qibbs_ticket_published'         => __( '社区工单公开奖励', 'qibbs-community' ),
            'community_consume'              => __( '社区积分消费', 'qibbs-community' ),
            'community_reward'               => __( '社区积分奖励', 'qibbs-community' ),
        );

        return array_merge( (array) $types, $community_types );
    }

    /**
     * 检查商城积分联动是否已启用且环境可用
     *
     * @return bool
     */
    private function is_enabled() {
        return class_exists( 'QilingShop_Points' ) && (bool) qibbs_get_option( 'enable_points_integration', false );
    }

    /**
     * 获取商城积分管理器实例
     *
     * @return QilingShop_Points|null
     */
    private function get_points_manager() {
        if ( ! class_exists( 'QilingShop_Points' ) ) {
            return null;
        }

        return QilingShop_Points::instance();
    }

    /**
     * 帖子发布积分奖励处理
     *
     * @param int $post_id Post ID.
     * @param int $user_id User ID.
     * @return void
     */
    public function handle_post_created( $post_id, $user_id ) {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $post = $this->get_post_row( $post_id );
        if ( ! $post || 'publish' !== (string) $post->status ) {
            return;
        }

        $source_type = $this->normalize_source_type( $post->source_type ?? '' );
        if ( '' !== $source_type ) {
            return;
        }

        $this->maybe_grant_reward(
            'post_publish',
            (int) $post->author_id,
            (int) $post->id,
            __( '社区发帖审核通过奖励', 'qibbs-community' ),
            array(
                'post_id'     => (int) $post->id,
                'forum_id'    => (int) $post->forum_id,
                'source_type' => $source_type,
            )
        );
    }

    /**
     * 免审评论即时奖励处理
     *
     * @param int $comment_id Comment ID.
     * @param int $user_id    User ID.
     * @param int $post_id    Post ID.
     * @return void
     */
    public function handle_comment_added( $comment_id, $user_id, $post_id ) {
        unset( $user_id, $post_id );
        $this->maybe_reward_comment( $comment_id );
    }

    /**
     * 人工审核通过后评论奖励发放
     *
     * @param int $comment_id Comment ID.
     * @param int $user_id    User ID.
     * @param int $post_id    Post ID.
     * @return void
     */
    public function handle_comment_approved( $comment_id, $user_id, $post_id ) {
        unset( $user_id, $post_id );
        $this->maybe_reward_comment( $comment_id );
    }

    /**
     * 采纳最佳答案积分奖励处理
     *
     * @param int $post_id            Post ID.
     * @param int $comment_id         Comment ID.
     * @param int $comment_author_id  Best answer author ID.
     * @param int $actor_user_id      Actor user ID.
     * @return void
     */
    public function handle_best_answer_set( $post_id, $comment_id, $comment_author_id, $actor_user_id ) {
        unset( $actor_user_id );

        if ( ! $this->is_enabled() ) {
            return;
        }

        $post = $this->get_post_row( $post_id );
        if ( ! $post || 'publish' !== (string) $post->status ) {
            return;
        }

        if ( (int) $post->author_id === (int) $comment_author_id ) {
            return;
        }

        // 允许通过过滤器配置是否排除系统额外最佳答案奖励（默认支持保留系统奖励与悬赏金叠加发放）
        $exclude_bounty = (bool) apply_filters( 'qibbs_points_reward_bounty_exclude_system_points', false, $post_id, $comment_id );
        if ( $exclude_bounty && class_exists( 'Qibbs_Bounty_Service' ) ) {
            $bounty = Qibbs_Bounty_Service::instance()->get_bounty( $post_id );
            if ( $bounty && in_array( (string) $bounty->status, array( 'pending', 'pending_arbitration', 'awarded' ), true ) ) {
                return;
            }
        }

        $this->maybe_grant_reward(
            'best_answer',
            (int) $comment_author_id,
            (int) $comment_id,
            __( '评论被设为最佳答案奖励', 'qibbs-community' ),
            array(
                'post_id'     => (int) $post->id,
                'post_author_id' => (int) $post->author_id,
                'forum_id'    => (int) $post->forum_id,
                'comment_id'  => (int) $comment_id,
                'source_type' => $this->normalize_source_type( $post->source_type ?? '' ),
            )
        );
    }

    /**
     * 帖子加精/推荐积分奖励处理
     *
     * @param int $post_id    Post ID.
     * @param int $author_id  Author ID.
     * @return void
     */
    public function handle_post_featured( $post_id, $author_id ) {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $post = $this->get_post_row( $post_id );
        if ( ! $post || 'publish' !== (string) $post->status ) {
            return;
        }

        $source_type = $this->normalize_source_type( $post->source_type ?? '' );
        if ( 'article' === $source_type ) {
            return;
        }

        $this->maybe_grant_reward(
            'featured_post',
            (int) $author_id,
            (int) $post->id,
            __( '动态被设为精华/推荐奖励', 'qibbs-community' ),
            array(
                'post_id'     => (int) $post->id,
                'forum_id'    => (int) $post->forum_id,
                'source_type' => $source_type,
            )
        );
    }

    /**
     * 工单转公开贴积分奖励处理
     *
     * @param int $ticket_id Ticket ID.
     * @param int $post_id   Post ID.
     * @param int $forum_id  Forum ID.
     * @return void
     */
    public function handle_ticket_published( $ticket_id, $post_id, $forum_id ) {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $ticket = $this->get_ticket_row( $ticket_id );
        if ( ! $ticket ) {
            return;
        }

        $this->maybe_grant_reward(
            'ticket_published',
            (int) $ticket->user_id,
            (int) $ticket_id,
            __( '工单转公开帖奖励', 'qibbs-community' ),
            array(
                'ticket_id'   => (int) $ticket_id,
                'post_id'     => (int) $post_id,
                'forum_id'    => (int) $forum_id,
                'source_type' => 'ticket',
            )
        );
    }

    /**
     * 校验评论审核状态并发放奖励
     *
     * @param int $comment_id Comment ID.
     * @return void
     */
    private function maybe_reward_comment( $comment_id ) {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $comment = $this->get_comment_row( $comment_id );
        if ( ! $comment || 'approved' !== (string) $comment->status ) {
            return;
        }

        $this->maybe_grant_reward(
            'comment_approved',
            (int) $comment->user_id,
            (int) $comment->id,
            __( '社区评论审核通过奖励', 'qibbs-community' ),
            array(
                'comment_id'  => (int) $comment->id,
                'post_id'     => (int) $comment->post_id,
                'forum_id'    => (int) $comment->forum_id,
                'source_type' => $this->normalize_source_type( $comment->source_type ?? '' ),
            )
        );
    }

    /**
     * 执行单笔积分奖励发放流程
     *
     * @param string $reward_key  Reward key.
     * @param int    $user_id     Target user ID.
     * @param int    $related_id  Related object ID.
     * @param string $description Log description.
     * @param array  $context     Runtime context.
     * @return bool
     */
    private function maybe_grant_reward( $reward_key, $user_id, $related_id, $description, $context = array() ) {
        $user_id = absint( $user_id );
        $related_id = absint( $related_id );
        $context = (array) $context;

        if ( ! $this->is_enabled() || ! $user_id || ! $related_id ) {
            return false;
        }

        $settings = qibbs_get_points_reward_settings( $reward_key );
        if ( empty( $settings ) || ! is_array( $settings ) ) {
            return false;
        }

        $enabled = (bool) apply_filters( 'qibbs_points_reward_enabled', ! empty( $settings['enabled'] ), $reward_key, $user_id, $context );
        if ( ! $enabled ) {
            return false;
        }

        if ( ! $this->is_user_eligible( $user_id, $context ) ) {
            return false;
        }

        if ( $this->is_reward_self_award_blocked( $reward_key, $user_id, $context ) ) {
            return false;
        }

        $amount = round( (float) apply_filters( 'qibbs_points_reward_amount', (float) $settings['amount'], $reward_key, $user_id, $context ), 2 );
        if ( $amount <= 0 ) {
            return false;
        }

        $daily_limit = max( 0, (int) apply_filters( 'qibbs_points_reward_daily_limit', (int) $settings['daily_limit'], $reward_key, $user_id, $context ) );
        $source = (string) apply_filters( 'qibbs_points_reward_source', (string) $settings['source'], $reward_key, $user_id, $context );
        $description = (string) apply_filters( 'qibbs_points_reward_description', $description, $reward_key, $user_id, $context );

        if ( '' === $source || '' === $description ) {
            return false;
        }

        $lock_name = $this->acquire_reward_lock( $user_id, $source, $related_id );
        if ( '' === $lock_name ) {
            return false;
        }

        try {
            if ( $daily_limit > 0 && $this->count_reward_logs_today( $user_id, $source ) >= $daily_limit ) {
                return false;
            }

            $points_manager = $this->get_points_manager();
            if ( ! $points_manager ) {
                return false;
            }

            if ( method_exists( $points_manager, 'get_user_info' ) ) {
                $points_manager->get_user_info( $user_id );
            } elseif ( method_exists( $points_manager, 'init_user_account' ) ) {
                $points_manager->init_user_account( $user_id );
            }

            if ( $points_manager->has_points_log( $user_id, $source, $related_id ) ) {
                return false;
            }

            $granted = $points_manager->add_points( $user_id, $amount, $source, $description, $related_id );
            if ( $granted ) {
                do_action( 'qibbs_points_reward_granted', $reward_key, $user_id, $amount, $source, $related_id, $context );
            }

            return (bool) $granted;
        } finally {
            $this->release_reward_lock( $lock_name );
        }
    }

    /**
     * 校验用户是否满足积分获取资质
     *
     * @param string $reward_key Reward key.
     * @param int    $user_id    User ID.
     * @param array  $context    Optional runtime context.
     * @return bool
     */
    public function can_user_earn_reward( $reward_key, $user_id, $context = array() ) {
        $user_id = absint( $user_id );
        $context = (array) $context;

        if ( ! $this->is_enabled() || ! $user_id ) {
            return false;
        }

        $settings = qibbs_get_points_reward_settings( $reward_key );
        if ( empty( $settings ) || ! is_array( $settings ) ) {
            return false;
        }

        $enabled = (bool) apply_filters( 'qibbs_points_reward_enabled', ! empty( $settings['enabled'] ), $reward_key, $user_id, $context );
        if ( ! $enabled ) {
            return false;
        }

        if ( ! $this->is_user_eligible( $user_id, $context ) || $this->is_reward_self_award_blocked( $reward_key, $user_id, $context ) ) {
            return false;
        }

        $amount = round( (float) apply_filters( 'qibbs_points_reward_amount', (float) $settings['amount'], $reward_key, $user_id, $context ), 2 );
        if ( $amount <= 0 ) {
            return false;
        }

        $daily_limit = max( 0, (int) apply_filters( 'qibbs_points_reward_daily_limit', (int) $settings['daily_limit'], $reward_key, $user_id, $context ) );
        $source = (string) apply_filters( 'qibbs_points_reward_source', (string) $settings['source'], $reward_key, $user_id, $context );
        if ( '' === $source ) {
            return false;
        }

        if ( $daily_limit > 0 && $this->count_reward_logs_today( $user_id, $source ) >= $daily_limit ) {
            return false;
        }

        return true;
    }

    /**
     * 校验目标用户是否具备受奖资格
     *
     * @param int   $user_id User ID.
     * @param array $context Reward context.
     * @return bool
     */
    private function is_user_eligible( $user_id, $context ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }

        if ( function_exists( 'qibbs_is_user_blacklisted' ) && qibbs_is_user_blacklisted( $user_id ) ) {
            return false;
        }

        if ( (bool) qibbs_get_option( 'points_integration_allow_staff', false ) ) {
            return true;
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            return false;
        }

        $post_id = ! empty( $context['post_id'] ) ? absint( $context['post_id'] ) : 0;
        if ( $post_id && function_exists( 'qibbs_user_can_moderate_post' ) && qibbs_user_can_moderate_post( $user_id, $post_id ) ) {
            return false;
        }

        $forum_id = ! empty( $context['forum_id'] ) ? absint( $context['forum_id'] ) : 0;
        if ( $forum_id && function_exists( 'qibbs_user_can_moderate_forum' ) && qibbs_user_can_moderate_forum( $user_id, $forum_id ) ) {
            return false;
        }

        return true;
    }

    /**
     * 防刷：拦截自我奖励与作弊逻辑
     *
     * @param string $reward_key Reward key.
     * @param int    $user_id    Candidate reward user.
     * @param array  $context    Runtime context.
     * @return bool
     */
    private function is_reward_self_award_blocked( $reward_key, $user_id, $context ) {
        if ( 'best_answer' !== (string) $reward_key ) {
            return false;
        }

        $post_author_id = ! empty( $context['post_author_id'] ) ? absint( $context['post_author_id'] ) : 0;
        return $post_author_id > 0 && $post_author_id === absint( $user_id );
    }

    /**
     * 获取积分发放防重并发锁
     *
     * @param int    $user_id    User ID.
     * @param string $source     Reward source.
     * @param int    $related_id Related object ID.
     * @return string
     */
    private function acquire_reward_lock( $user_id, $source, $related_id ) {
        global $wpdb;

        $lock_name = 'qibbs_pts_' . substr( sha1( absint( $user_id ) . '|' . sanitize_key( $source ) . '|' . absint( $related_id ) ), 0, 40 );
        $acquired = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT GET_LOCK(%s, %d)',
                $lock_name,
                5
            )
        );

        return ( '1' === (string) $acquired || 1 === (int) $acquired ) ? $lock_name : '';
    }

    /**
     * 释放积分发放防重并发锁
     *
     * @param string $lock_name Lock name.
     * @return void
     */
    private function release_reward_lock( $lock_name ) {
        global $wpdb;

        $lock_name = (string) $lock_name;
        if ( '' === $lock_name ) {
            return;
        }

        $wpdb->get_var(
            $wpdb->prepare(
                'SELECT RELEASE_LOCK(%s)',
                $lock_name
            )
        );
    }

    /**
     * 查询用户今日该类型奖励已获得次数（频次限制）
     *
     * @param int    $user_id User ID.
     * @param string $source  Log source.
     * @return int
     */
    private function count_reward_logs_today( $user_id, $source ) {
        global $wpdb;

        $table = $this->get_points_log_table();
        if ( '' === $table ) {
            return 0;
        }

        try {
            $start = new DateTimeImmutable( 'today', qibbs_wp_timezone() );
        } catch ( Exception $e ) {
            $start = new DateTimeImmutable( 'today' );
        }
        $end = $start->modify( '+1 day' );

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE user_id = %d
                   AND type = 'income'
                   AND source = %s
                   AND created_at >= %s
                   AND created_at < %s",
                absint( $user_id ),
                sanitize_text_field( $source ),
                $start->format( 'Y-m-d H:i:s' ),
                $end->format( 'Y-m-d H:i:s' )
            )
        );
    }

    /**
     * 获取积分日志数据表名
     *
     * @return string
     */
    private function get_points_log_table() {
        global $wpdb;

        if ( isset( $wpdb->qilingshop_points_log ) && is_string( $wpdb->qilingshop_points_log ) && '' !== $wpdb->qilingshop_points_log ) {
            return $wpdb->qilingshop_points_log;
        }

        if ( defined( 'QILINGSHOP_TABLE_PREFIX' ) ) {
            return $wpdb->prefix . QILINGSHOP_TABLE_PREFIX . 'points_log';
        }

        return $wpdb->prefix . 'qilingshop_points_log';
    }

    /**
     * 查询单条帖子记录
     *
     * @param int $post_id Post ID.
     * @return object|null
     */
    private function get_post_row( $post_id ) {
        global $wpdb;

        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return null;
        }

        $table = Qibbs_Database::posts();
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, author_id, status, forum_id, source_type FROM {$table} WHERE id = %d LIMIT 1",
                $post_id
            )
        );
    }

    /**
     * 查询单条评论记录及关联帖子上下文
     *
     * @param int $comment_id Comment ID.
     * @return object|null
     */
    private function get_comment_row( $comment_id ) {
        global $wpdb;

        $comment_id = absint( $comment_id );
        if ( ! $comment_id ) {
            return null;
        }

        $comments_table = Qibbs_Database::comments();
        $posts_table = Qibbs_Database::posts();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.id, c.user_id, c.post_id, c.status, p.forum_id, p.source_type
                 FROM {$comments_table} c
                 LEFT JOIN {$posts_table} p ON p.id = c.post_id
                 WHERE c.id = %d
                 LIMIT 1",
                $comment_id
            )
        );
    }

    /**
     * 查询单条工单记录
     *
     * @param int $ticket_id Ticket ID.
     * @return object|null
     */
    private function get_ticket_row( $ticket_id ) {
        global $wpdb;

        $ticket_id = absint( $ticket_id );
        if ( ! $ticket_id ) {
            return null;
        }

        $table = Qibbs_Database::tickets();
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, user_id FROM {$table} WHERE id = %d LIMIT 1",
                $ticket_id
            )
        );
    }

    /**
     * 标准化奖励源类型标识
     *
     * @param string $source_type Source type.
     * @return string
     */
    private function normalize_source_type( $source_type ) {
        return sanitize_key( (string) $source_type );
    }
}

Qibbs_QilingShop_Points_Integration::get_instance();
