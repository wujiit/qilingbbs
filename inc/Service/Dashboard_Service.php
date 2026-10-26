<?php
/**
 * 控制台运营数据统计服务类
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Dashboard_Service {

    /**
     * 请求级控制台仪表盘数据缓存
     *
     * @var array<string,mixed>
     */
    private static $runtime_cache = array();

    /**
     * 检查持久化对象缓存（Redis/Memcached）是否可用
     *
     * @return bool
     */
    private function use_object_cache() {
        return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
    }

    /**
     * 构建控制台缓存确定性键名
     *
     * @param string $prefix  Cache prefix.
     * @param array  $payload Cache payload.
     * @return string
     */
    private function build_cache_key( $prefix, $payload = array() ) {
        return 'qibbs_dashboard_' . sanitize_key( $prefix ) . '_' . md5( wp_json_encode( (array) $payload ) );
    }

    /**
     * 读取控制台聚合缓存
     *
     * @param string $key Cache key.
     * @return mixed|null
     */
    private function get_cached_payload( $key ) {
        if ( array_key_exists( $key, self::$runtime_cache ) ) {
            return self::$runtime_cache[ $key ];
        }

        $cached = false;
        if ( $this->use_object_cache() ) {
            $found = false;
            $cached = wp_cache_get( $key, 'qibbs_dashboard', false, $found );
            if ( ! $found ) {
                $cached = false;
            }
        } else {
            $cached = get_transient( $key );
        }

        if ( false === $cached ) {
            return null;
        }

        self::$runtime_cache[ $key ] = $cached;
        return $cached;
    }

    /**
     * 写入控制台聚合缓存
     *
     * @param string $key   Cache key.
     * @param mixed  $value Value.
     * @param int    $ttl   Cache TTL.
     * @return mixed
     */
    private function set_cached_payload( $key, $value, $ttl ) {
        $ttl = max( 30, absint( $ttl ) );
        self::$runtime_cache[ $key ] = $value;

        if ( $this->use_object_cache() ) {
            wp_cache_set( $key, $value, 'qibbs_dashboard', $ttl );
        } else {
            set_transient( $key, $value, $ttl );
        }

        return $value;
    }

    /**
     * 查询微社区运营看板基础概览指标
     *
     * @return array
     */
    public function get_overview_metrics() {
        $cache_key = $this->build_cache_key( 'overview_metrics' );
        $cached = $this->get_cached_payload( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;

        $posts_table = Qibbs_Database::posts();
        $comments_table = Qibbs_Database::comments();
        $reports_table = Qibbs_Database::reports();
        $blacklist_table = Qibbs_Database::blacklist();
        $moderation_service = new Qibbs_Moderation_Service();
        $ticket_service = new Qibbs_Ticket_Service();
        $moderation_counts = $moderation_service->get_pending_counts();
        $ticket_metrics = $ticket_service->get_summary_metrics();

        $question_metrics = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total_questions,
                SUM(CASE WHEN discussion_status = 'resolved' OR is_resolved = 1 THEN 1 ELSE 0 END) AS resolved_questions
             FROM {$posts_table}
             WHERE status = 'publish' AND post_type = 'question'",
            ARRAY_A
        );

        $total_questions = isset( $question_metrics['total_questions'] ) ? (int) $question_metrics['total_questions'] : 0;
        $resolved_questions = isset( $question_metrics['resolved_questions'] ) ? (int) $question_metrics['resolved_questions'] : 0;

        $metrics = array(
            'total_posts'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts_table} WHERE status = %s", 'publish' ) ),
            'total_comments'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$comments_table} WHERE status = %s", 'approved' ) ),
            'active_users_7d'     => $this->count_active_users_since( '-7 days' ),
            'active_users_30d'    => $this->count_active_users_since( '-30 days' ),
            'total_questions'     => $total_questions,
            'resolved_questions'  => $resolved_questions,
            'resolved_rate'       => $total_questions > 0 ? round( ( $resolved_questions / $total_questions ) * 100, 1 ) : 0,
            'pending_moderation'  => (int) $moderation_counts['total'],
            'pending_reports'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reports_table} WHERE status = %s", 'pending' ) ),
            'blacklisted_users'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$blacklist_table} WHERE %d = %d", 1, 1 ) ),
            'active_cooldowns'    => $this->count_active_post_cooldowns(),
            'open_tickets'        => isset( $ticket_metrics['open_count'] ) ? (int) $ticket_metrics['open_count'] : 0,
            'sla_breach_count'    => isset( $ticket_metrics['sla_breach_count'] ) ? (int) $ticket_metrics['sla_breach_count'] : 0,
            'risk_watch_count'    => $this->count_risk_watch_users( 30 ),
        );

        return $this->set_cached_payload(
            $cache_key,
            $metrics,
            (int) apply_filters( 'qibbs_dashboard_overview_cache_ttl', 60 )
        );
    }

    /**
     * 按固定时间窗口统计互动活跃度趋势
     *
     * @return array
     */
    public function get_activity_windows() {
        $cache_key = $this->build_cache_key( 'activity_windows' );
        $cached = $this->get_cached_payload( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $windows = array(
            '24h' => '-1 day',
            '7d'  => '-7 days',
            '30d' => '-30 days',
        );

        $data = array();
        foreach ( $windows as $label => $modifier ) {
            $data[ $label ] = array(
                'posts'        => $this->count_posts_since( $modifier ),
                'comments'     => $this->count_comments_since( $modifier ),
                'active_users' => $this->count_active_users_since( $modifier ),
            );
        }

        return $this->set_cached_payload(
            $cache_key,
            $data,
            (int) apply_filters( 'qibbs_dashboard_activity_cache_ttl', 120 )
        );
    }

    /**
     * 查询活跃度最高的版块排行榜
     *
     * @param int $limit Limit.
     * @return array
     */
    public function get_top_forums( $limit = 8 ) {
        $limit = max( 1, min( 20, absint( $limit ) ) );
        $cache_key = $this->build_cache_key( 'top_forums', array( 'limit' => $limit ) );
        $cached = $this->get_cached_payload( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;
        $forums_table = Qibbs_Database::forums();
        $posts_table = Qibbs_Database::posts();
        $since = $this->get_since_datetime( '-30 days' );

        $forums = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT f.id, f.name, f.slug, f.post_count, f.member_count,
                        SUM(CASE WHEN p.created_at >= %s THEN 1 ELSE 0 END) AS recent_post_count,
                        MAX(p.created_at) AS last_post_at
                 FROM {$forums_table} f
                 LEFT JOIN {$posts_table} p ON p.forum_id = f.id AND p.status = 'publish'
                 GROUP BY f.id, f.name, f.slug, f.post_count, f.member_count
                 ORDER BY recent_post_count DESC, f.post_count DESC, f.id DESC
                 LIMIT %d",
                $since,
                $limit
            )
        );

        return $this->set_cached_payload(
            $cache_key,
            is_array( $forums ) ? $forums : array(),
            (int) apply_filters( 'qibbs_dashboard_top_forums_cache_ttl', 180 )
        );
    }

    /**
     * 查询发帖最活跃的作者排行榜
     *
     * @param int $limit Limit.
     * @return array
     */
    public function get_top_authors( $limit = 8 ) {
        $limit = max( 1, min( 20, absint( $limit ) ) );
        $cache_key = $this->build_cache_key( 'top_authors', array( 'limit' => $limit ) );
        $cached = $this->get_cached_payload( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;
        $posts_table = Qibbs_Database::posts();
        $comments_table = Qibbs_Database::comments();
        $since = $this->get_since_datetime( '-30 days' );

        $authors = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.ID AS user_id, u.display_name,
                        COALESCE(posts.post_count, 0) AS post_count,
                        COALESCE(posts.like_count, 0) AS like_count,
                        COALESCE(comments.comment_count, 0) AS comment_count,
                        ( COALESCE(posts.post_count, 0) * 3 + COALESCE(comments.comment_count, 0) + COALESCE(posts.like_count, 0) ) AS activity_score
                 FROM {$wpdb->users} u
                 INNER JOIN (
                    SELECT DISTINCT user_id FROM (
                        SELECT author_id AS user_id
                        FROM {$posts_table}
                        WHERE status = 'publish' AND created_at >= %s
                        UNION ALL
                        SELECT user_id
                        FROM {$comments_table}
                        WHERE status = 'approved' AND created_at >= %s
                    ) active_users
                 ) active ON active.user_id = u.ID
                 LEFT JOIN (
                    SELECT author_id, COUNT(*) AS post_count, SUM(like_count) AS like_count
                    FROM {$posts_table}
                    WHERE status = 'publish' AND created_at >= %s
                    GROUP BY author_id
                 ) posts ON posts.author_id = u.ID
                 LEFT JOIN (
                    SELECT user_id, COUNT(*) AS comment_count
                    FROM {$comments_table}
                    WHERE status = 'approved' AND created_at >= %s
                    GROUP BY user_id
                 ) comments ON comments.user_id = u.ID
                 ORDER BY activity_score DESC, post_count DESC, comment_count DESC, u.ID DESC
                 LIMIT %d",
                $since,
                $since,
                $since,
                $since,
                $limit
            )
        );

        return $this->set_cached_payload(
            $cache_key,
            is_array( $authors ) ? $authors : array(),
            (int) apply_filters( 'qibbs_dashboard_top_authors_cache_ttl', 180 )
        );
    }

    /**
     * 查询仪表盘展示的最新审核操作日志
     *
     * @param int $limit Limit.
     * @return array
     */
    public function get_recent_moderation_logs( $limit = 12 ) {
        $limit = max( 1, min( 50, absint( $limit ) ) );
        $cache_key = $this->build_cache_key( 'recent_moderation_logs', array( 'limit' => $limit ) );
        $cached = $this->get_cached_payload( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $service = new Qibbs_Moderation_Service();
        $logs = $service->get_recent_logs( $limit );

        return $this->set_cached_payload(
            $cache_key,
            is_array( $logs ) ? $logs : array(),
            (int) apply_filters( 'qibbs_dashboard_recent_logs_cache_ttl', 60 )
        );
    }

    /**
     * 统计指定相对时间以来发布的有效帖子数
     *
     * @param string $modifier Relative datetime modifier.
     * @return int
     */
    private function count_posts_since( $modifier ) {
        global $wpdb;

        $posts_table = Qibbs_Database::posts();
        $since = $this->get_since_datetime( $modifier );

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$posts_table} WHERE status = 'publish' AND created_at >= %s",
                $since
            )
        );
    }

    /**
     * 统计指定相对时间以来审核通过的评论数
     *
     * @param string $modifier Relative datetime modifier.
     * @return int
     */
    private function count_comments_since( $modifier ) {
        global $wpdb;

        $comments_table = Qibbs_Database::comments();
        $since = $this->get_since_datetime( $modifier );

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$comments_table} WHERE status = 'approved' AND created_at >= %s",
                $since
            )
        );
    }

    /**
     * 统计指定相对时间以来的去重活跃用户数
     *
     * @param string $modifier Relative datetime modifier.
     * @return int
     */
    private function count_active_users_since( $modifier ) {
        global $wpdb;

        $since = $this->get_since_datetime( $modifier );
        $posts_table = Qibbs_Database::posts();
        $comments_table = Qibbs_Database::comments();
        $messages_table = Qibbs_Database::messages();
        $tickets_table = Qibbs_Database::tickets();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM (
                    SELECT author_id AS user_id FROM {$posts_table} WHERE author_id > 0 AND status = 'publish' AND created_at >= %s
                    UNION ALL
                    SELECT user_id FROM {$comments_table} WHERE user_id > 0 AND status = 'approved' AND created_at >= %s
                    UNION ALL
                    SELECT sender_id AS user_id FROM {$messages_table} WHERE sender_id > 0 AND created_at >= %s
                    UNION ALL
                    SELECT user_id FROM {$tickets_table} WHERE user_id > 0 AND created_at >= %s
                 ) activity_users",
                $since,
                $since,
                $since,
                $since
            )
        );
    }

    /**
     * 统计处于发帖冷却期的活跃限制数
     *
     * @return int
     */
    private function count_active_post_cooldowns() {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$wpdb->usermeta}
                 WHERE meta_key = %s AND meta_value > %s",
                '_qibbs_post_cooldown_until',
                current_time( 'mysql' )
            )
        );
    }

    /**
     * 统计触发风控高危预警的用户总数
     *
     * @param int $days Window days.
     * @return int
     */
    private function count_risk_watch_users( $days = 30 ) {
        global $wpdb;

        $days = max( 1, min( 365, absint( $days ) ) );
        $since = $this->get_since_datetime( '-' . $days . ' days' );
        $logs_table = Qibbs_Database::get_table( 'risk_logs' );
        $moderation_logs_table = Qibbs_Database::get_table( 'moderation_logs' );
        $reports_table = Qibbs_Database::reports();
        $posts_table = Qibbs_Database::posts();
        $risk_actions = array(
            'reject',
            'trash',
            'purge',
            'delete',
            'block',
            'moderator_delete_post',
            'moderator_block_post',
            'moderator_trash_post',
            'moderator_delete_comment',
            'moderator_block_comment',
            'moderator_blacklist_user',
            'moderator_set_post_cooldown',
        );
        $action_placeholders = implode( ',', array_fill( 0, count( $risk_actions ), '%s' ) );

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT user_id) FROM (
                    SELECT user_id
                    FROM {$logs_table}
                    WHERE user_id > 0 AND created_at >= %s AND ( status = 'blocked' OR action LIKE %s )
                    UNION ALL
                    SELECT target_user_id AS user_id
                    FROM {$moderation_logs_table}
                    WHERE target_user_id > 0 AND created_at >= %s AND action IN ({$action_placeholders})
                    UNION ALL
                    SELECT posts.author_id AS user_id
                    FROM {$reports_table} reports
                    INNER JOIN {$posts_table} posts ON posts.id = reports.post_id
                    WHERE posts.author_id > 0 AND reports.created_at >= %s
                ) risk_users",
                array_merge(
                    array( $since, 'content_%', $since ),
                    $risk_actions,
                    array( $since )
                )
            )
        );
    }

    /**
     * 根据相对修饰符生成本地时区时间字符串
     *
     * @param string $modifier Relative datetime modifier.
     * @return string
     */
    private function get_since_datetime( $modifier ) {
        $date = current_datetime();
        $date = $date->modify( (string) $modifier );
        return $date ? $date->format( 'Y-m-d H:i:s' ) : current_time( 'mysql' );
    }
}
