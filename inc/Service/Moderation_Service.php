<?php
/**
 * 统一内容审核服务类
 *
 * 统一管理帖子与评论的待审队列、批量处置与审核留痕
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Moderation_Service {

    /**
     * 请求级审核数据缓存
     *
     * @var array<string,mixed>
     */
    private static $runtime_cache = array();

    /**
     * 检查持久化对象缓存（Redis/Memcached）是否可用
     *
     * @return bool
     */
    private static function use_object_cache() {
        return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
    }

    /**
     * 获取审核缓存版本键名
     *
     * @return string
     */
    private static function get_cache_version_key() {
        return 'qibbs_moderation_cache_version';
    }

    /**
     * 获取当前审核缓存版本号
     *
     * @return string
     */
    private static function get_cache_version() {
        if ( isset( self::$runtime_cache['__version'] ) ) {
            return (string) self::$runtime_cache['__version'];
        }

        $cache_key = self::get_cache_version_key();
        $version = false;

        if ( self::use_object_cache() ) {
            $found = false;
            $version = wp_cache_get( $cache_key, 'qibbs_moderation', false, $found );
            if ( ! $found ) {
                $version = false;
            }
        } else {
            $version = get_transient( $cache_key );
        }

        if ( false === $version || '' === $version ) {
            $version = (string) time();
            if ( self::use_object_cache() ) {
                wp_cache_set( $cache_key, $version, 'qibbs_moderation', DAY_IN_SECONDS );
            } else {
                set_transient( $cache_key, $version, DAY_IN_SECONDS );
            }
        }

        self::$runtime_cache['__version'] = (string) $version;
        return (string) $version;
    }

    /**
     * 写操作后递增审核缓存版本号
     *
     * @return void
     */
    private static function bump_cache_version() {
        $cache_key = self::get_cache_version_key();
        $version = (string) microtime( true );
        self::$runtime_cache = array(
            '__version' => $version,
        );

        if ( self::use_object_cache() ) {
            wp_cache_set( $cache_key, $version, 'qibbs_moderation', DAY_IN_SECONDS );
        } else {
            set_transient( $cache_key, $version, DAY_IN_SECONDS );
        }
    }

    /**
     * 构建审核缓存确定性键名
     *
     * @param string $prefix  Cache prefix.
     * @param array  $payload Cache payload.
     * @return string
     */
    private static function build_cache_key( $prefix, $payload = array() ) {
        return 'qibbs_moderation_' . sanitize_key( $prefix ) . '_' . md5(
            wp_json_encode(
                array(
                    'version' => self::get_cache_version(),
                    'payload' => (array) $payload,
                )
            )
        );
    }

    /**
     * 读取审核数据缓存
     *
     * @param string $key Cache key.
     * @return mixed|null
     */
    private static function get_cached_payload( $key ) {
        if ( array_key_exists( $key, self::$runtime_cache ) ) {
            return self::$runtime_cache[ $key ];
        }

        $cached = false;
        if ( self::use_object_cache() ) {
            $found = false;
            $cached = wp_cache_get( $key, 'qibbs_moderation', false, $found );
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
     * 写入审核数据缓存
     *
     * @param string $key   Cache key.
     * @param mixed  $value Cached value.
     * @param int    $ttl   Cache TTL.
     * @return mixed
     */
    private static function set_cached_payload( $key, $value, $ttl ) {
        $ttl = max( 15, absint( $ttl ) );
        self::$runtime_cache[ $key ] = $value;

        if ( self::use_object_cache() ) {
            wp_cache_set( $key, $value, 'qibbs_moderation', $ttl );
        } else {
            set_transient( $key, $value, $ttl );
        }

        return $value;
    }

    /**
     * 获取审核中心各队列待审计数
     *
     * @return array
     */
    public function get_pending_counts( $allowed_forum_ids = array() ) {
        global $wpdb;

        $posts_table = Qibbs_Database::posts();
        $comments_table = Qibbs_Database::comments();
        $allowed_forum_ids = $this->normalize_forum_ids( $allowed_forum_ids );
        $cache_key = self::build_cache_key(
            'pending_counts',
            array(
                'forums' => $allowed_forum_ids,
            )
        );
        $cached = self::get_cached_payload( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        if ( empty( $allowed_forum_ids ) ) {
            $post_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts_table} WHERE status = %s", 'qibbs_pending' ) );
            $comment_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$comments_table} WHERE status = %s", 'pending' ) );
            $trashed_posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts_table} WHERE status = %s", 'qibbs_trashed' ) );
            $trashed_comments = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$comments_table} WHERE status = %s", 'trashed' ) );
        } else {
            $placeholders = implode( ',', array_fill( 0, count( $allowed_forum_ids ), '%d' ) );
            $post_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$posts_table}
                     WHERE status = 'qibbs_pending' AND forum_id IN ({$placeholders})",
                    $allowed_forum_ids
                )
            );
            $trashed_posts = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$posts_table}
                     WHERE status = 'qibbs_trashed' AND forum_id IN ({$placeholders})",
                    $allowed_forum_ids
                )
            );
            $comment_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$comments_table} c
                     INNER JOIN {$posts_table} p ON p.id = c.post_id
                     WHERE c.status = 'pending' AND p.forum_id IN ({$placeholders})",
                    $allowed_forum_ids
                )
            );
            $trashed_comments = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$comments_table} c
                     INNER JOIN {$posts_table} p ON p.id = c.post_id
                     WHERE c.status = 'trashed' AND p.forum_id IN ({$placeholders})",
                    $allowed_forum_ids
                )
            );
        }

        return self::set_cached_payload(
            $cache_key,
            array(
                'posts'          => $post_count,
                'comments'       => $comment_count,
                'posts_trash'    => $trashed_posts,
                'comments_trash' => $trashed_comments,
                'total'          => $post_count + $comment_count,
            ),
            (int) apply_filters( 'qibbs_moderation_pending_counts_cache_ttl', 30 )
        );
    }

    /**
     * 获取治理中心高阶概览统计数据
     *
     * @return array
     */
    public function get_governance_summary( $allowed_forum_ids = array() ) {
        global $wpdb;

        $allowed_forum_ids = $this->normalize_forum_ids( $allowed_forum_ids );
        $cache_key = self::build_cache_key(
            'governance_summary',
            array(
                'forums' => $allowed_forum_ids,
            )
        );
        $cached = self::get_cached_payload( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $counts = $this->get_pending_counts( $allowed_forum_ids );
        $posts_table = Qibbs_Database::posts();
        $blacklist_table = Qibbs_Database::blacklist();
        $reports_table = Qibbs_Database::reports();
        $now = current_time( 'mysql' );
        $report_count = 0;

        $forum_settings = function_exists( 'qibbs_get_forum_governance_store' ) ? qibbs_get_forum_governance_store() : array();
        $forum_rules_count = 0;
        foreach ( (array) $forum_settings as $forum_setting ) {
            if ( ! is_array( $forum_setting ) ) {
                continue;
            }

            $has_welcome = ! empty( $forum_setting['welcome_message'] );
            $has_rules = ! empty( $forum_setting['rules_text'] );
            if ( $has_welcome || $has_rules ) {
                $forum_rules_count++;
            }
        }

        if ( ! empty( $allowed_forum_ids ) ) {
            $forum_rules_count = 0;
            foreach ( $allowed_forum_ids as $allowed_forum_id ) {
                $setting = isset( $forum_settings[ $allowed_forum_id ] ) && is_array( $forum_settings[ $allowed_forum_id ] )
                    ? $forum_settings[ $allowed_forum_id ]
                    : array();
                if ( ! empty( $setting['welcome_message'] ) || ! empty( $setting['rules_text'] ) ) {
                    $forum_rules_count++;
                }
            }
            $placeholders = implode( ',', array_fill( 0, count( $allowed_forum_ids ), '%d' ) );
            $live_posts = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$posts_table}
                     WHERE status = 'publish' AND forum_id IN ({$placeholders})",
                    $allowed_forum_ids
                )
            );
            $pinned_posts = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$posts_table}
                     WHERE status = 'publish' AND is_pinned = 1 AND forum_id IN ({$placeholders})",
                    $allowed_forum_ids
                )
            );
            $featured_posts = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$posts_table}
                     WHERE status = 'publish' AND is_featured = 1 AND (featured_until IS NULL OR featured_until > %s) AND forum_id IN ({$placeholders})",
                    array_merge( array( $now ), $allowed_forum_ids )
                )
            );
            $locked_posts = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$posts_table}
                     WHERE status = 'publish' AND discussion_status = 'closed' AND forum_id IN ({$placeholders})",
                    $allowed_forum_ids
                )
            );
            $report_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$reports_table} r
                     INNER JOIN {$posts_table} p ON p.id = r.post_id
                     WHERE r.status = 'pending' AND p.forum_id IN ({$placeholders})",
                    $allowed_forum_ids
                )
            );
        } else {
            $live_posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts_table} WHERE status = %s", 'publish' ) );
            $pinned_posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts_table} WHERE status = %s AND is_pinned = %d", 'publish', 1 ) );
            $featured_posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts_table} WHERE status = 'publish' AND is_featured = 1 AND (featured_until IS NULL OR featured_until > %s)", $now ) );
            $locked_posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts_table} WHERE status = %s AND discussion_status = %s", 'publish', 'closed' ) );
            $report_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$reports_table} WHERE status = %s", 'pending' ) );
        }

        return self::set_cached_payload(
            $cache_key,
            array(
                'pending_posts'         => (int) $counts['posts'],
                'pending_comments'      => (int) $counts['comments'],
                'trash_posts'           => (int) $counts['posts_trash'],
                'trash_comments'        => (int) $counts['comments_trash'],
                'live_posts'            => $live_posts,
                'pinned_posts'          => $pinned_posts,
                'featured_posts'        => $featured_posts,
                'locked_posts'          => $locked_posts,
                'blacklisted_users'     => empty( $allowed_forum_ids ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$blacklist_table} WHERE %d = %d", 1, 1 ) ) : 0,
                'pending_reports'       => $report_count,
                'active_post_cooldowns' => empty( $allowed_forum_ids ) ? $this->count_active_post_cooldowns() : 0,
                'forum_rules_count'     => $forum_rules_count,
            ),
            (int) apply_filters( 'qibbs_moderation_governance_summary_cache_ttl', 60 )
        );
    }

    /**
     * 查询待审核帖子列表
     *
     * @param int $page Page.
     * @param int $per_page Per page.
     * @return array
     */
    public function get_pending_posts( $page = 1, $per_page = 20, $allowed_forum_ids = array() ) {
        return $this->get_posts_by_status( 'qibbs_pending', $page, $per_page, $allowed_forum_ids );
    }

    /**
     * 查询待审核评论列表
     *
     * @param int $page Page.
     * @param int $per_page Per page.
     * @return array
     */
    public function get_pending_comments( $page = 1, $per_page = 20, $allowed_forum_ids = array() ) {
        return $this->get_comments_by_status( 'pending', $page, $per_page, $allowed_forum_ids );
    }

    /**
     * 查询回收站已删除帖子列表
     *
     * @param int $page Page.
     * @param int $per_page Per page.
     * @return array
     */
    public function get_trashed_posts( $page = 1, $per_page = 20, $allowed_forum_ids = array() ) {
        return $this->get_posts_by_status( 'qibbs_trashed', $page, $per_page, $allowed_forum_ids );
    }

    /**
     * 查询回收站已删除评论列表
     *
     * @param int $page Page.
     * @param int $per_page Per page.
     * @return array
     */
    public function get_trashed_comments( $page = 1, $per_page = 20, $allowed_forum_ids = array() ) {
        return $this->get_comments_by_status( 'trashed', $page, $per_page, $allowed_forum_ids );
    }

    /**
     * 查询已发布帖子供治理处置
     *
     * @param int   $page Page.
     * @param int   $per_page Per page.
     * @param array $filters Optional filters.
     * @return array
     */
    public function get_live_posts( $page = 1, $per_page = 20, $filters = array(), $allowed_forum_ids = array() ) {
        global $wpdb;

        $page = max( 1, absint( $page ) );
        $per_page = max( 1, min( 100, absint( $per_page ) ) );
        $offset = ( $page - 1 ) * $per_page;
        $posts_table = Qibbs_Database::posts();
        $allowed_forum_ids = $this->normalize_forum_ids( $allowed_forum_ids );

        $filters = wp_parse_args(
            (array) $filters,
            array(
                'forum_id'           => 0,
                'search'             => '',
                'discussion_status'  => '',
                'featured'           => '',
                'pinned'             => '',
            )
        );

        $where = array( "p.status = 'publish'" );
        $params = array();

        if ( ! empty( $filters['forum_id'] ) ) {
            if ( ! empty( $allowed_forum_ids ) && ! in_array( absint( $filters['forum_id'] ), $allowed_forum_ids, true ) ) {
                return array(
                    'items'       => array(),
                    'total'       => 0,
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total_pages' => 1,
                );
            }
            $where[] = 'p.forum_id = %d';
            $params[] = absint( $filters['forum_id'] );
        } elseif ( ! empty( $allowed_forum_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $allowed_forum_ids ), '%d' ) );
            $where[] = "p.forum_id IN ({$placeholders})";
            $params = array_merge( $params, $allowed_forum_ids );
        }

        if ( ! empty( $filters['search'] ) ) {
            $like = '%' . $wpdb->esc_like( sanitize_text_field( $filters['search'] ) ) . '%';
            $where[] = '(p.content LIKE %s OR u.display_name LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        if ( in_array( $filters['discussion_status'], array( 'discussion', 'resolved', 'closed' ), true ) ) {
            $where[] = 'p.discussion_status = %s';
            $params[] = sanitize_key( $filters['discussion_status'] );
        }

        if ( 'only' === $filters['featured'] ) {
            $where[] = 'p.is_featured = 1';
        } elseif ( 'exclude' === $filters['featured'] ) {
            $where[] = 'p.is_featured = 0';
        }

        if ( 'only' === $filters['pinned'] ) {
            $where[] = 'p.is_pinned = 1';
        } elseif ( 'exclude' === $filters['pinned'] ) {
            $where[] = 'p.is_pinned = 0';
        }

        $where_sql = implode( ' AND ', $where );
        $count_sql = "SELECT COUNT(*) FROM {$posts_table} p LEFT JOIN {$wpdb->users} u ON u.ID = p.author_id WHERE {$where_sql}";
        $list_sql = "SELECT p.* FROM {$posts_table} p
                     LEFT JOIN {$wpdb->users} u ON u.ID = p.author_id
                     WHERE {$where_sql}
                     ORDER BY p.is_pinned DESC, p.created_at DESC
                     LIMIT %d OFFSET %d";

        $total = empty( $params )
            ? (int) $wpdb->get_var( $count_sql )
            : (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

        $list_params = $params;
        $list_params[] = $per_page;
        $list_params[] = $offset;

        $items = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );

        return array(
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
        );
    }

    /**
     * 获取版块治理规则配置列表
     *
     * @return array
     */
    public function get_forum_governance_rows( $allowed_forum_ids = array() ) {
        global $wpdb;

        $forums_table = Qibbs_Database::forums();
        $members_table = Qibbs_Database::forum_members();
        $allowed_forum_ids = $this->normalize_forum_ids( $allowed_forum_ids );
        if ( empty( $allowed_forum_ids ) ) {
            $forums = $wpdb->get_results(
                "SELECT id, name, slug, post_count, member_count, is_private
                 FROM {$forums_table}
                 ORDER BY sort_order ASC, id DESC"
            );
        } else {
            $placeholders = implode( ',', array_fill( 0, count( $allowed_forum_ids ), '%d' ) );
            $forums = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, name, slug, post_count, member_count, is_private
                     FROM {$forums_table}
                     WHERE id IN ({$placeholders})
                     ORDER BY sort_order ASC, id DESC",
                    $allowed_forum_ids
                )
            );
        }

        $moderator_rows = $wpdb->get_results(
            "SELECT forum_id, COUNT(*) AS moderator_count
             FROM {$members_table}
             WHERE role IN ('moderator', 'owner')
             GROUP BY forum_id",
            ARRAY_A
        );

        $moderator_map = array();
        foreach ( (array) $moderator_rows as $moderator_row ) {
            $moderator_map[ (int) $moderator_row['forum_id'] ] = (int) $moderator_row['moderator_count'];
        }

        foreach ( (array) $forums as $forum ) {
            $settings = function_exists( 'qibbs_get_forum_governance_settings' )
                ? qibbs_get_forum_governance_settings( (int) $forum->id )
                : array( 'welcome_message' => '', 'rules_text' => '' );
            $forum->welcome_message = isset( $settings['welcome_message'] ) ? (string) $settings['welcome_message'] : '';
            $forum->rules_text = isset( $settings['rules_text'] ) ? (string) $settings['rules_text'] : '';
            $forum->moderator_count = isset( $moderator_map[ (int) $forum->id ] ) ? (int) $moderator_map[ (int) $forum->id ] : 0;
        }

        return $forums;
    }

    /**
     * 保存单个版块的治理规则配置
     *
     * @param int    $forum_id Forum ID.
     * @param string $welcome_message Welcome copy.
     * @param string $rules_text Rules copy.
     * @param int    $admin_id Operator ID.
     * @return array
     */
    public function save_forum_governance( $forum_id, $welcome_message, $rules_text, $admin_id = 0, $allowed_forum_ids = array() ) {
        global $wpdb;

        $forum_id = absint( $forum_id );
        $admin_id = absint( $admin_id );
        $welcome_message = sanitize_textarea_field( (string) $welcome_message );
        $rules_text = sanitize_textarea_field( (string) $rules_text );
        $allowed_forum_ids = $this->normalize_forum_ids( $allowed_forum_ids );

        if ( ! $forum_id ) {
            return array(
                'success' => false,
                'message' => __( '圈子参数错误', 'qibbs-community' ),
            );
        }

        if ( ! empty( $allowed_forum_ids ) && ! in_array( $forum_id, $allowed_forum_ids, true ) ) {
            return array(
                'success' => false,
                'message' => __( '你无权管理该圈子', 'qibbs-community' ),
            );
        }

        $forums_table = Qibbs_Database::forums();
        $forum = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, name FROM {$forums_table} WHERE id = %d LIMIT 1",
                $forum_id
            )
        );

        if ( ! $forum ) {
            return array(
                'success' => false,
                'message' => __( '圈子不存在', 'qibbs-community' ),
            );
        }

        if ( ! function_exists( 'qibbs_update_forum_governance_settings' ) || ! qibbs_update_forum_governance_settings(
            $forum_id,
            array(
                'welcome_message' => $welcome_message,
                'rules_text'      => $rules_text,
            )
        ) ) {
            return array(
                'success' => false,
                'message' => __( '保存失败，请稍后重试', 'qibbs-community' ),
            );
        }

        $this->log_action(
            'forum',
            $forum_id,
            0,
            $admin_id,
            'moderator_update_forum_governance',
            trim( sprintf( 'welcome:%s rules:%s', '' !== $welcome_message ? 'yes' : 'no', '' !== $rules_text ? 'yes' : 'no' ) )
        );

        return array(
            'success' => true,
            'message' => sprintf( __( '已更新圈子「%s」的治理配置', 'qibbs-community' ), $forum->name ),
        );
    }

    /**
     * 获取处于发帖冷却中的用户列表
     *
     * @param int $limit Limit.
     * @return array
     */
    public function get_active_post_cooldowns( $limit = 30 ) {
        global $wpdb;

        $limit = max( 1, min( 200, absint( $limit ) ) );
        $now = current_time( 'mysql' );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, meta_value AS cooldown_until
                 FROM {$wpdb->usermeta}
                 WHERE meta_key = %s AND meta_value > %s
                 ORDER BY meta_value ASC
                 LIMIT %d",
                '_qibbs_post_cooldown_until',
                $now,
                $limit
            )
        );
    }

    /**
     * 查询单用户的治理画像与违规记录
     *
     * @param int $user_id User ID.
     * @param int $days Window days.
     * @return array|null
     */
    public function get_user_governance_profile( $user_id, $days = 30 ) {
        global $wpdb;

        $user_id = absint( $user_id );
        $days = max( 1, min( 365, absint( $days ) ) );
        if ( ! $user_id ) {
            return null;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return null;
        }

        $posts_table = Qibbs_Database::posts();
        $comments_table = Qibbs_Database::comments();
        $since_dt = current_datetime();
        $since_dt->modify( '-' . $days . ' days' );
        $since = $since_dt->format( 'Y-m-d H:i:s' );

        $post_metrics = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS total_count,
                        SUM(CASE WHEN status = 'publish' THEN 1 ELSE 0 END) AS publish_count,
                        MAX(created_at) AS last_post_at
                 FROM {$posts_table}
                 WHERE author_id = %d AND created_at >= %s",
                $user_id,
                $since
            ),
            ARRAY_A
        );

        $comment_metrics = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS total_count, MAX(created_at) AS last_comment_at
                 FROM {$comments_table}
                 WHERE user_id = %d AND created_at >= %s",
                $user_id,
                $since
            ),
            ARRAY_A
        );

        return array(
            'user_id'               => $user_id,
            'display_name'          => $user->display_name,
            'user_email'            => $user->user_email,
            'avatar'                => get_avatar_url( $user_id, array( 'size' => 64 ) ),
            'is_blacklisted'        => function_exists( 'qibbs_is_user_blacklisted' ) ? qibbs_is_user_blacklisted( $user_id ) : false,
            'post_cooldown_until'   => function_exists( 'qibbs_get_user_post_cooldown_until' ) ? qibbs_get_user_post_cooldown_until( $user_id ) : '',
            'post_cooldown_active'  => function_exists( 'qibbs_user_has_post_cooldown' ) ? qibbs_user_has_post_cooldown( $user_id ) : false,
            'post_count'            => isset( $post_metrics['total_count'] ) ? (int) $post_metrics['total_count'] : 0,
            'published_post_count'  => isset( $post_metrics['publish_count'] ) ? (int) $post_metrics['publish_count'] : 0,
            'last_post_at'          => isset( $post_metrics['last_post_at'] ) ? (string) $post_metrics['last_post_at'] : '',
            'comment_count'         => isset( $comment_metrics['total_count'] ) ? (int) $comment_metrics['total_count'] : 0,
            'last_comment_at'       => isset( $comment_metrics['last_comment_at'] ) ? (string) $comment_metrics['last_comment_at'] : '',
            'risk_profile'          => class_exists( 'Qibbs_Risk_Service' ) ? Qibbs_Risk_Service::get_user_risk_profile( $user_id, $days ) : null,
        );
    }

    /**
     * 执行单项用户治理处罚动作
     *
     * @param int    $user_id User ID.
     * @param string $action Action key.
     * @param string $reason Reason text.
     * @param int    $admin_id Operator ID.
     * @param array  $args Extra args.
     * @return array
     */
    public function apply_user_governance_action( $user_id, $action, $reason = '', $admin_id = 0, $args = array() ) {
        global $wpdb;

        $user_id = absint( $user_id );
        $admin_id = absint( $admin_id );
        $action = sanitize_key( $action );
        $reason = sanitize_textarea_field( (string) $reason );
        $args = is_array( $args ) ? $args : array();

        $allowed_actions = array( 'blacklist', 'unblacklist', 'set_post_cooldown', 'clear_post_cooldown' );
        if ( ! $user_id || ! in_array( $action, $allowed_actions, true ) ) {
            return array(
                'success' => false,
                'message' => __( '参数错误', 'qibbs-community' ),
            );
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return array(
                'success' => false,
                'message' => __( '用户不存在', 'qibbs-community' ),
            );
        }

        if ( user_can( $user_id, 'manage_options' ) && ! in_array( $action, array( 'unblacklist', 'clear_post_cooldown' ), true ) ) {
            return array(
                'success' => false,
                'message' => __( '不能对管理员账号执行限制类治理动作', 'qibbs-community' ),
            );
        }

        $blacklist_table = Qibbs_Database::blacklist();
        $log_reason = $reason;
        $notification_service = new Qibbs_Notification_Service();

        switch ( $action ) {
            case 'blacklist':
                $exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$blacklist_table} WHERE user_id = %d LIMIT 1",
                        $user_id
                    )
                );

                if ( $exists ) {
                    $updated = $wpdb->update(
                        $blacklist_table,
                        array(
                            'reason'   => $reason,
                            'admin_id' => $admin_id,
                        ),
                        array( 'user_id' => $user_id ),
                        array( '%s', '%d' ),
                        array( '%d' )
                    );
                    if ( false === $updated ) {
                        return array(
                            'success' => false,
                            'message' => __( '加入黑名单失败，请稍后重试', 'qibbs-community' ),
                        );
                    }
                } else {
                    $inserted = $wpdb->insert(
                        $blacklist_table,
                        array(
                            'user_id'    => $user_id,
                            'reason'     => $reason,
                            'admin_id'   => $admin_id,
                            'created_at' => current_time( 'mysql' ),
                        ),
                        array( '%d', '%s', '%d', '%s' )
                    );
                    if ( ! $inserted ) {
                        return array(
                            'success' => false,
                            'message' => __( '加入黑名单失败，请稍后重试', 'qibbs-community' ),
                        );
                    }
                }
                $this->log_action( 'user', $user_id, $user_id, $admin_id, 'moderator_blacklist_user', $log_reason );
                $notification_service->create_moderation_notification( $user_id, 'user', $user_id, 'blacklist', $log_reason, $admin_id );
                break;

            case 'unblacklist':
                if ( function_exists( 'qibbs_is_user_blacklisted' ) && ! qibbs_is_user_blacklisted( $user_id ) ) {
                    return array(
                        'success' => false,
                        'message' => __( '该用户当前不在黑名单中', 'qibbs-community' ),
                    );
                }

                $deleted = $wpdb->delete( $blacklist_table, array( 'user_id' => $user_id ), array( '%d' ) );
                if ( false === $deleted ) {
                    return array(
                        'success' => false,
                        'message' => __( '移出黑名单失败，请稍后重试', 'qibbs-community' ),
                    );
                }
                $this->log_action( 'user', $user_id, $user_id, $admin_id, 'moderator_unblacklist_user', $log_reason );
                $notification_service->create_moderation_notification( $user_id, 'user', $user_id, 'unblacklist', $log_reason, $admin_id );
                break;

            case 'set_post_cooldown':
                $duration_hours = max( 1, min( 24 * 30, absint( $args['duration_hours'] ?? 24 ) ) );
                $cooldown_until = qibbs_format_datetime( 'Y-m-d H:i:s', qibbs_current_timestamp() + ( $duration_hours * HOUR_IN_SECONDS ) );
                if ( ! function_exists( 'qibbs_set_user_post_cooldown' ) || ! qibbs_set_user_post_cooldown( $user_id, $cooldown_until ) ) {
                    return array(
                        'success' => false,
                        'message' => __( '发帖冷却设置失败', 'qibbs-community' ),
                    );
                }
                $log_reason = trim( $reason . ' duration_hours=' . $duration_hours . ' until=' . $cooldown_until );
                $this->log_action( 'user', $user_id, $user_id, $admin_id, 'moderator_set_post_cooldown', $log_reason );
                $notification_service->create_moderation_notification( $user_id, 'user', $user_id, 'set_post_cooldown', $log_reason, $admin_id );
                break;

            case 'clear_post_cooldown':
                if ( function_exists( 'qibbs_user_has_post_cooldown' ) && ! qibbs_user_has_post_cooldown( $user_id ) ) {
                    return array(
                        'success' => false,
                        'message' => __( '该用户当前没有发帖冷却', 'qibbs-community' ),
                    );
                }
                if ( function_exists( 'qibbs_clear_user_post_cooldown' ) && ! qibbs_clear_user_post_cooldown( $user_id ) ) {
                    return array(
                        'success' => false,
                        'message' => __( '清除发帖冷却失败，请稍后重试', 'qibbs-community' ),
                    );
                }
                $this->log_action( 'user', $user_id, $user_id, $admin_id, 'moderator_clear_post_cooldown', $log_reason );
                $notification_service->create_moderation_notification( $user_id, 'user', $user_id, 'clear_post_cooldown', $log_reason, $admin_id );
                break;
        }

        return array(
            'success' => true,
            'message' => sprintf( __( '已对用户「%s」执行%s', 'qibbs-community' ), $user->display_name, $this->get_action_label( $action ) ),
        );
    }

    /**
     * 处理审核中心批量操作
     *
     * @param string $type posts|comments|trash_posts|trash_comments.
     * @param array  $ids IDs.
     * @param string $action Action key.
     * @param string $reason Review note.
     * @param int    $admin_id Admin user.
     * @return array
     */
    public function process_bulk_action( $type, $ids, $action, $reason = '', $admin_id = 0, $extra_args = array() ) {
        $type = sanitize_key( $type );
        $allowed_types = array( 'posts', 'comments', 'trash_posts', 'trash_comments', 'live_posts' );
        $type = in_array( $type, $allowed_types, true ) ? $type : 'posts';
        $action = sanitize_key( $action );
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
        $reason = sanitize_textarea_field( $reason );
        $admin_id = absint( $admin_id );
        $extra_args = is_array( $extra_args ) ? $extra_args : array();
        $allowed_forum_ids = isset( $extra_args['allowed_forum_ids'] ) ? $this->normalize_forum_ids( $extra_args['allowed_forum_ids'] ) : array();

        if ( empty( $action ) || empty( $ids ) || ! in_array( $action, $this->get_allowed_bulk_actions( $type ), true ) ) {
            return array(
                'success' => false,
                'message' => __( '参数错误', 'qibbs-community' ),
            );
        }

        if ( 'posts' === $type ) {
            return $this->process_post_actions( $ids, $action, $reason, $admin_id, 'qibbs_pending', $allowed_forum_ids );
        }

        if ( 'trash_posts' === $type ) {
            return $this->process_post_actions( $ids, $action, $reason, $admin_id, 'qibbs_trashed', $allowed_forum_ids );
        }

        if ( 'comments' === $type ) {
            return $this->process_comment_actions( $ids, $action, $reason, $admin_id, 'pending', $allowed_forum_ids );
        }

        if ( 'live_posts' === $type ) {
            return $this->process_live_post_actions( $ids, $action, $reason, $admin_id, $extra_args, $allowed_forum_ids );
        }

        return $this->process_comment_actions( $ids, $action, $reason, $admin_id, 'trashed', $allowed_forum_ids );
    }

    /**
     * 获取指定队列允许执行的批量操作指令
     *
     * @param string $type Queue type.
     * @return array
     */
    public function get_allowed_bulk_actions( $type ) {
        $map = array(
            'posts'          => array( 'approve', 'reject', 'trash' ),
            'comments'       => array( 'approve', 'reject', 'trash' ),
            'trash_posts'    => array( 'restore', 'purge' ),
            'trash_comments' => array( 'restore', 'purge' ),
            'live_posts'     => array( 'pin', 'unpin', 'feature', 'unfeature', 'lock', 'unlock', 'move_forum', 'trash', 'block' ),
        );

        return isset( $map[ $type ] ) ? $map[ $type ] : array();
    }

    /**
     * 按状态分页查询帖子列表
     *
     * @param string $status Status.
     * @param int    $page Page.
     * @param int    $per_page Per page.
     * @return array
     */
    private function get_posts_by_status( $status, $page = 1, $per_page = 20, $allowed_forum_ids = array() ) {
        global $wpdb;

        $page = max( 1, absint( $page ) );
        $per_page = max( 1, min( 100, absint( $per_page ) ) );
        $offset = ( $page - 1 ) * $per_page;
        $table = Qibbs_Database::posts();
        $status = sanitize_key( $status );
        $allowed_forum_ids = $this->normalize_forum_ids( $allowed_forum_ids );

        if ( empty( $allowed_forum_ids ) ) {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                    $status
                )
            );

            $items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE status = %s
                     ORDER BY updated_at DESC, created_at DESC
                     LIMIT %d OFFSET %d",
                    $status,
                    $per_page,
                    $offset
                )
            );
        } else {
            $placeholders = implode( ',', array_fill( 0, count( $allowed_forum_ids ), '%d' ) );
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE status = %s AND forum_id IN ({$placeholders})",
                    array_merge( array( $status ), $allowed_forum_ids )
                )
            );

            $items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE status = %s AND forum_id IN ({$placeholders})
                     ORDER BY updated_at DESC, created_at DESC
                     LIMIT %d OFFSET %d",
                    array_merge( array( $status ), $allowed_forum_ids, array( $per_page, $offset ) )
                )
            );
        }

        return array(
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
        );
    }

    /**
     * 按状态分页查询评论列表
     *
     * @param string $status Status.
     * @param int    $page Page.
     * @param int    $per_page Per page.
     * @return array
     */
    private function get_comments_by_status( $status, $page = 1, $per_page = 20, $allowed_forum_ids = array() ) {
        global $wpdb;

        $page = max( 1, absint( $page ) );
        $per_page = max( 1, min( 100, absint( $per_page ) ) );
        $offset = ( $page - 1 ) * $per_page;
        $table = Qibbs_Database::comments();
        $posts_table = Qibbs_Database::posts();
        $status = sanitize_key( $status );
        $allowed_forum_ids = $this->normalize_forum_ids( $allowed_forum_ids );

        if ( empty( $allowed_forum_ids ) ) {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                    $status
                )
            );

            $items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE status = %s
                     ORDER BY updated_at DESC, created_at DESC
                     LIMIT %d OFFSET %d",
                    $status,
                    $per_page,
                    $offset
                )
            );
        } else {
            $placeholders = implode( ',', array_fill( 0, count( $allowed_forum_ids ), '%d' ) );
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$table} c
                     INNER JOIN {$posts_table} p ON p.id = c.post_id
                     WHERE c.status = %s AND p.forum_id IN ({$placeholders})",
                    array_merge( array( $status ), $allowed_forum_ids )
                )
            );

            $items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT c.*
                     FROM {$table} c
                     INNER JOIN {$posts_table} p ON p.id = c.post_id
                     WHERE c.status = %s AND p.forum_id IN ({$placeholders})
                     ORDER BY c.updated_at DESC, c.created_at DESC
                     LIMIT %d OFFSET %d",
                    array_merge( array( $status ), $allowed_forum_ids, array( $per_page, $offset ) )
                )
            );
        }

        return array(
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
        );
    }

    /**
     * 处理帖子审核处置动作（通过/拒绝/删除/还原）
     *
     * @param array  $ids IDs.
     * @param string $action Action key.
     * @param string $reason Reason.
     * @param int    $admin_id Admin ID.
     * @param string $source_status Source status.
     * @return array
     */
    private function process_post_actions( $ids, $action, $reason, $admin_id, $source_status, $allowed_forum_ids = array() ) {
        global $wpdb;

        $posts_table = Qibbs_Database::posts();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $allowed_forum_ids = $this->normalize_forum_ids( $allowed_forum_ids );
        if ( empty( $allowed_forum_ids ) ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, author_id, content, media_ids, forum_id, source_type, source_id FROM {$posts_table}
                     WHERE id IN ({$placeholders}) AND status = %s",
                    array_merge( $ids, array( $source_status ) )
                )
            );
        } else {
            $forum_placeholders = implode( ',', array_fill( 0, count( $allowed_forum_ids ), '%d' ) );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, author_id, content, media_ids, forum_id, source_type, source_id FROM {$posts_table}
                     WHERE id IN ({$placeholders}) AND status = %s AND forum_id IN ({$forum_placeholders})",
                    array_merge( $ids, array( $source_status ), $allowed_forum_ids )
                )
            );
        }

        if ( empty( $rows ) ) {
            return array(
                'success' => false,
                'message' => ( 'qibbs_trashed' === $source_status )
                    ? __( '没有可处理的回收站动态', 'qibbs-community' )
                    : __( '没有可处理的待审动态', 'qibbs-community' ),
            );
        }

        $row_ids = array_map(
            static function( $row ) {
                return (int) $row->id;
            },
            $rows
        );
        $ids_sql = implode( ',', array_map( 'absint', $row_ids ) );
        $db_result = null;
        $forum_scope_sql = '';

        if ( ! empty( $allowed_forum_ids ) ) {
            $forum_scope_sql = ' AND forum_id IN (' . implode( ',', array_map( 'absint', $allowed_forum_ids ) ) . ')';
        }

        switch ( $action ) {
            case 'approve':
                $db_result = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$posts_table} SET status = 'publish' WHERE id IN ({$ids_sql}) AND status = %s{$forum_scope_sql}",
                        $source_status
                    )
                );
                break;
            case 'reject':
                $db_result = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$posts_table} SET status = 'qibbs_blocked' WHERE id IN ({$ids_sql}) AND status = %s{$forum_scope_sql}",
                        $source_status
                    )
                );
                break;
            case 'trash':
                $db_result = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$posts_table} SET status = 'qibbs_trashed' WHERE id IN ({$ids_sql}) AND status = %s{$forum_scope_sql}",
                        $source_status
                    )
                );
                break;
            case 'restore':
                $db_result = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$posts_table} SET status = 'qibbs_pending' WHERE id IN ({$ids_sql}) AND status = %s{$forum_scope_sql}",
                        $source_status
                    )
                );
                break;
            case 'purge':
                if ( class_exists( 'Qibbs_Cache_Service' ) ) {
                    foreach ( $rows as $row ) {
                        Qibbs_Cache_Service::invalidate_post_cache( (int) $row->id );
                    }
                }
                $this->purge_posts( $rows );
                break;
        }

        if ( 'purge' !== $action ) {
            if ( false === $db_result ) {
                return array(
                    'success' => false,
                    'message' => __( '动态处理失败，请稍后重试', 'qibbs-community' ),
                );
            }

            if ( 0 === (int) $db_result ) {
                return array(
                    'success' => false,
                    'message' => __( '没有成功处理任何动态，可能已被其他版主处理', 'qibbs-community' ),
                );
            }
        }

        if ( 'approve' === $action ) {
            foreach ( $rows as $approved_row ) {
                do_action( 'qibbs_post_created', (int) $approved_row->id, (int) $approved_row->author_id );
            }
        }

        $processed_count = 'purge' === $action ? count( $rows ) : max( 1, (int) $db_result );
        $notification_service = new Qibbs_Notification_Service();
        foreach ( $rows as $row ) {
            $message = $this->build_notification_message( $row->content, $reason );
            $notification_service->create_moderation_notification(
                (int) $row->author_id,
                'post',
                (int) $row->id,
                $action,
                $message,
                $admin_id
            );
            $this->log_action( 'post', (int) $row->id, (int) $row->author_id, $admin_id, $action, $reason );
        }

        if ( class_exists( 'Qibbs_Cache_Service' ) ) {
            if ( ! in_array( $action, array( 'approve', 'purge' ), true ) ) {
                foreach ( $rows as $row ) {
                    Qibbs_Cache_Service::invalidate_post_cache( (int) $row->id );
                }
            }
        }

        return array(
            'success' => true,
            'count'   => $processed_count,
            'message' => sprintf(
                __( '已%s %d 条动态', 'qibbs-community' ),
                $this->get_action_label( $action ),
                $processed_count
            ),
        );
    }

    /**
     * 处理已发布帖子的治理处置（屏蔽/加精/分类调整）
     *
     * @param array  $ids IDs.
     * @param string $action Action key.
     * @param string $reason Reason.
     * @param int    $admin_id Admin ID.
     * @param array  $extra_args Extra args.
     * @return array
     */
    private function process_live_post_actions( $ids, $action, $reason, $admin_id, $extra_args, $allowed_forum_ids = array() ) {
        global $wpdb;

        $posts_table = Qibbs_Database::posts();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $allowed_forum_ids = $this->normalize_forum_ids( $allowed_forum_ids );
        if ( empty( $allowed_forum_ids ) ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, author_id, content, forum_id, discussion_status, is_pinned, is_featured, best_comment_id
                     FROM {$posts_table}
                     WHERE id IN ({$placeholders}) AND status = 'publish'",
                    $ids
                )
            );
        } else {
            $forum_placeholders = implode( ',', array_fill( 0, count( $allowed_forum_ids ), '%d' ) );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, author_id, content, forum_id, discussion_status, is_pinned, is_featured, best_comment_id
                     FROM {$posts_table}
                     WHERE id IN ({$placeholders}) AND status = 'publish' AND forum_id IN ({$forum_placeholders})",
                    array_merge( $ids, $allowed_forum_ids )
                )
            );
        }

        $target_forum_id = 0;
        if ( 'move_forum' === $action ) {
            $target_forum_id = absint( $extra_args['target_forum_id'] ?? 0 );
        }

        $rows = array_values(
            array_filter(
                (array) $rows,
                static function( $row ) use ( $action, $target_forum_id ) {
                    switch ( $action ) {
                        case 'pin':
                            return empty( $row->is_pinned );
                        case 'unpin':
                            return ! empty( $row->is_pinned );
                        case 'feature':
                            return empty( $row->is_featured );
                        case 'unfeature':
                            return ! empty( $row->is_featured );
                        case 'lock':
                            return 'closed' !== (string) $row->discussion_status;
                        case 'unlock':
                            return 'discussion' !== (string) $row->discussion_status;
                        case 'move_forum':
                            return $target_forum_id > 0 && $target_forum_id !== (int) $row->forum_id;
                        default:
                            return true;
                    }
                }
            )
        );

        if ( empty( $rows ) ) {
            return array(
                'success' => false,
                'message' => __( '没有可处理的已发布动态', 'qibbs-community' ),
            );
        }

        $row_ids = array_map(
            static function( $row ) {
                return (int) $row->id;
            },
            $rows
        );
        $row_ids = array_values( array_unique( array_filter( array_map( 'absint', $row_ids ) ) ) );
        if ( empty( $row_ids ) ) {
            return array(
                'success' => false,
                'message' => __( '请选择要处理的动态', 'qibbs-community' ),
            );
        }

        $id_placeholders = implode( ',', array_fill( 0, count( $row_ids ), '%d' ) );
        $where_params    = $row_ids;
        $forum_clause    = '';
        if ( ! empty( $allowed_forum_ids ) ) {
            $forum_placeholders = implode( ',', array_fill( 0, count( $allowed_forum_ids ), '%d' ) );
            $forum_clause       = " AND forum_id IN ({$forum_placeholders})";
            $where_params       = array_merge( $where_params, array_map( 'absint', $allowed_forum_ids ) );
        }

        $affected_forum_ids = array();
        $notification_service = new Qibbs_Notification_Service();
        $db_result = null;

        $set_clause = '';
        switch ( $action ) {
            case 'pin':
                $set_clause = 'is_pinned = 1';
                break;

            case 'unpin':
                $set_clause = 'is_pinned = 0';
                break;

            case 'feature':
                $set_clause = 'is_featured = 1, featured_until = NULL';
                break;

            case 'unfeature':
                $set_clause = 'is_featured = 0, featured_until = NULL';
                break;

            case 'lock':
                $set_clause = "discussion_status = 'closed'";
                break;

            case 'unlock':
                $set_clause = "discussion_status = 'discussion', is_resolved = 0";
                break;

            case 'move_forum':
                if ( ! $target_forum_id ) {
                    return array(
                        'success' => false,
                        'message' => __( '请选择目标圈子', 'qibbs-community' ),
                    );
                }

                if ( ! empty( $allowed_forum_ids ) && ! in_array( $target_forum_id, $allowed_forum_ids, true ) ) {
                    return array(
                        'success' => false,
                        'message' => __( '你无权移动到目标圈子', 'qibbs-community' ),
                    );
                }

                $forums_table = Qibbs_Database::forums();
                $forum_exists = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$forums_table} WHERE id = %d LIMIT 1",
                        $target_forum_id
                    )
                );
                if ( ! $forum_exists ) {
                    return array(
                        'success' => false,
                        'message' => __( '目标圈子不存在', 'qibbs-community' ),
                    );
                }

                foreach ( (array) $rows as $row ) {
                    $affected_forum_ids[] = (int) $row->forum_id;
                }
                $affected_forum_ids[] = $target_forum_id;
                $db_result = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$posts_table} SET forum_id = %d WHERE id IN ({$id_placeholders}) AND status = 'publish'{$forum_clause}",
                        array_merge( array( $target_forum_id ), $where_params )
                    )
                );
                break;

            case 'trash':
                foreach ( (array) $rows as $row ) {
                    $affected_forum_ids[] = (int) $row->forum_id;
                }
                $set_clause = "status = 'qibbs_trashed'";
                break;

            case 'block':
                foreach ( (array) $rows as $row ) {
                    $affected_forum_ids[] = (int) $row->forum_id;
                }
                $set_clause = "status = 'qibbs_blocked'";
                break;
        }

        if ( ! empty( $set_clause ) ) {
            $db_result = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$posts_table} SET {$set_clause} WHERE id IN ({$id_placeholders}) AND status = 'publish'{$forum_clause}",
                    $where_params
                )
            );
        }

        if ( false === $db_result ) {
            return array(
                'success' => false,
                'message' => __( '动态治理失败，请稍后重试', 'qibbs-community' ),
            );
        }

        if ( 0 === (int) $db_result ) {
            return array(
                'success' => false,
                'message' => __( '没有成功处理任何动态，可能已被其他版主处理', 'qibbs-community' ),
            );
        }

        $processed_count = max( 1, (int) $db_result );

        foreach ( (array) $rows as $row ) {
            $log_reason = $reason;
            if ( 'move_forum' === $action ) {
                $target_forum_id = absint( $extra_args['target_forum_id'] ?? 0 );
                $log_reason = trim( $reason . ' forum:' . (int) $row->forum_id . '->' . $target_forum_id );
            }

            if ( 'block' === $action ) {
                $notification_service->create_admin_notification(
                    (int) $row->author_id,
                    'post_blocked',
                    (int) $row->id,
                    $admin_id,
                    $this->build_notification_message( $row->content, $reason )
                );
            } elseif ( in_array( $action, array( 'pin', 'unpin', 'feature', 'unfeature', 'lock', 'unlock', 'move_forum', 'trash' ), true ) ) {
                $message = $this->build_notification_message( $row->content, $reason );
                $notification_service->create_moderation_notification(
                    (int) $row->author_id,
                    'post',
                    (int) $row->id,
                    $action,
                    $message,
                    $admin_id
                );
            }

            $this->log_action( 'post', (int) $row->id, (int) $row->author_id, $admin_id, 'moderator_' . $action . '_post', $log_reason );

            if ( 'trash' === $action ) {
                do_action( 'qibbs_post_deleted', (int) $row->id, $admin_id );
            } elseif ( 'feature' === $action ) {
                do_action( 'qibbs_post_edited', (int) $row->id, $admin_id );
                do_action( 'qibbs_post_featured', (int) $row->id, (int) $row->author_id );
            } elseif ( 'block' === $action ) {
                do_action( 'qibbs_post_blocked', (int) $row->id );
            } else {
                do_action( 'qibbs_post_edited', (int) $row->id, $admin_id );
            }
        }

        if ( ! empty( $affected_forum_ids ) ) {
            $this->rebuild_forum_post_counts( $affected_forum_ids );
        }

        if ( class_exists( 'Qibbs_Cache_Service' ) ) {
            foreach ( (array) $rows as $row ) {
                if ( in_array( $action, array( 'trash', 'block' ), true ) ) {
                    continue;
                }
                Qibbs_Cache_Service::invalidate_post_cache( (int) $row->id );
            }

            if ( 'move_forum' === $action ) {
                foreach ( array_values( array_unique( array_filter( array_map( 'absint', $affected_forum_ids ) ) ) ) as $affected_forum_id ) {
                    Qibbs_Cache_Service::invalidate_forum_feed_cache( $affected_forum_id );
                }
            }
        }

        return array(
            'success'      => true,
            'count'        => $processed_count,
            'processed_ids' => $row_ids,
            'message'      => sprintf( __( '已%s %d 条动态', 'qibbs-community' ), $this->get_action_label( $action ), $processed_count ),
        );
    }

    /**
     * 处理评论审核处置动作
     *
     * @param array  $ids IDs.
     * @param string $action Action key.
     * @param string $reason Reason.
     * @param int    $admin_id Admin ID.
     * @param string $source_status Source status.
     * @return array
     */
    private function process_comment_actions( $ids, $action, $reason, $admin_id, $source_status, $allowed_forum_ids = array() ) {
        global $wpdb;

        $comments_table = Qibbs_Database::comments();
        $posts_table = Qibbs_Database::posts();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $allowed_forum_ids = $this->normalize_forum_ids( $allowed_forum_ids );
        if ( empty( $allowed_forum_ids ) ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, post_id, user_id, parent_id, content FROM {$comments_table}
                     WHERE id IN ({$placeholders}) AND status = %s",
                    array_merge( $ids, array( $source_status ) )
                )
            );
        } else {
            $forum_placeholders = implode( ',', array_fill( 0, count( $allowed_forum_ids ), '%d' ) );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT c.id, c.post_id, c.user_id, c.parent_id, c.content
                     FROM {$comments_table} c
                     INNER JOIN {$posts_table} p ON p.id = c.post_id
                     WHERE c.id IN ({$placeholders}) AND c.status = %s AND p.forum_id IN ({$forum_placeholders})",
                    array_merge( $ids, array( $source_status ), $allowed_forum_ids )
                )
            );
        }

        if ( empty( $rows ) ) {
            return array(
                'success' => false,
                'message' => ( 'trashed' === $source_status )
                    ? __( '没有可处理的回收站评论', 'qibbs-community' )
                    : __( '没有可处理的待审评论', 'qibbs-community' ),
            );
        }

        $row_ids = array_map(
            static function( $row ) {
                return (int) $row->id;
            },
            $rows
        );
        $ids_sql = implode( ',', array_map( 'absint', $row_ids ) );
        $db_result = null;
        $comment_scope_sql = '';
        $comments_update_table = $comments_table;
        $comments_status_column = 'status';
        $comments_id_column = 'id';

        if ( ! empty( $allowed_forum_ids ) ) {
            $comment_scope_sql = ' AND p.forum_id IN (' . implode( ',', array_map( 'absint', $allowed_forum_ids ) ) . ')';
            $comments_update_table = "{$comments_table} c INNER JOIN {$posts_table} p ON p.id = c.post_id";
            $comments_status_column = 'c.status';
            $comments_id_column = 'c.id';
        }

        switch ( $action ) {
            case 'approve':
                $db_result = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$comments_update_table} SET {$comments_status_column} = 'approved' WHERE {$comments_id_column} IN ({$ids_sql}) AND {$comments_status_column} = %s{$comment_scope_sql}",
                        $source_status
                    )
                );
                break;
            case 'reject':
                $db_result = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$comments_update_table} SET {$comments_status_column} = 'blocked' WHERE {$comments_id_column} IN ({$ids_sql}) AND {$comments_status_column} = %s{$comment_scope_sql}",
                        $source_status
                    )
                );
                break;
            case 'trash':
                $db_result = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$comments_update_table} SET {$comments_status_column} = 'trashed' WHERE {$comments_id_column} IN ({$ids_sql}) AND {$comments_status_column} = %s{$comment_scope_sql}",
                        $source_status
                    )
                );
                break;
            case 'restore':
                $db_result = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$comments_update_table} SET {$comments_status_column} = 'pending' WHERE {$comments_id_column} IN ({$ids_sql}) AND {$comments_status_column} = %s{$comment_scope_sql}",
                        $source_status
                    )
                );
                break;
            case 'purge':
                $this->purge_comments( $rows );
                break;
        }

        if ( 'purge' !== $action ) {
            if ( false === $db_result ) {
                return array(
                    'success' => false,
                    'message' => __( '评论处理失败，请稍后重试', 'qibbs-community' ),
                );
            }

            if ( 0 === (int) $db_result ) {
                return array(
                    'success' => false,
                    'message' => __( '没有成功处理任何评论，可能已被其他版主处理', 'qibbs-community' ),
                );
            }
        }

        if ( in_array( $action, array( 'approve', 'reject', 'trash', 'restore' ), true ) ) {
            $this->rebuild_comment_counts( $rows, $comments_table, $posts_table );
        }

        $processed_count = 'purge' === $action ? count( $rows ) : max( 1, (int) $db_result );
        $notification_service = new Qibbs_Notification_Service();
        $affected_post_ids = array();
        foreach ( $rows as $row ) {
            $affected_post_ids[] = (int) $row->post_id;
            $message = $this->build_notification_message( $row->content, $reason );
            $notification_service->create_moderation_notification(
                (int) $row->user_id,
                'comment',
                (int) $row->id,
                $action,
                $message,
                $admin_id
            );
            $this->log_action( 'comment', (int) $row->id, (int) $row->user_id, $admin_id, $action, $reason );

            if ( 'approve' === $action ) {
                do_action( 'qibbs_comment_approved', (int) $row->id, (int) $row->user_id, (int) $row->post_id );
            }
        }

        foreach ( array_values( array_unique( array_filter( array_map( 'absint', $affected_post_ids ) ) ) ) as $affected_post_id ) {
            do_action( 'qibbs_post_edited', $affected_post_id, $admin_id );
        }

        if ( class_exists( 'Qibbs_Cache_Service' ) ) {
            Qibbs_Cache_Service::invalidate_hot_feed_cache();
            Qibbs_Cache_Service::invalidate_sidebar_cache();
        }

        return array(
            'success' => true,
            'count'   => $processed_count,
            'message' => sprintf(
                __( '已%s %d 条评论', 'qibbs-community' ),
                $this->get_action_label( $action ),
                $processed_count
            ),
        );
    }

    /**
     * 批量重新统计并同步受影响帖子的评论数
     *
     * @param array  $rows Comment rows.
     * @param string $comments_table Comments table.
     * @param string $posts_table Posts table.
     * @return void
     */
    private function rebuild_comment_counts( $rows, $comments_table, $posts_table ) {
        global $wpdb;

        $post_ids = array();

        foreach ( (array) $rows as $row ) {
            $post_ids[] = (int) $row->post_id;
        }

        $post_ids = array_values( array_unique( array_filter( $post_ids ) ) );
        foreach ( $post_ids as $post_id ) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$comments_table} WHERE post_id = %d AND status = 'approved'",
                    (int) $post_id
                )
            );

            $wpdb->update(
                $posts_table,
                array( 'comment_count' => $count ),
                array( 'id' => (int) $post_id ),
                array( '%d' ),
                array( '%d' )
            );
        }
    }

    /**
     * 彻底清除帖子及其下属全部关联数据
     *
     * @param array $rows Post rows.
     * @return void
     */
    private function purge_posts( $rows ) {
        global $wpdb;

        $posts_table = Qibbs_Database::posts();
        $likes_table = Qibbs_Database::likes();
        $favorites_table = Qibbs_Database::favorites();
        $reports_table = Qibbs_Database::reports();
        $notifications_table = Qibbs_Database::notifications();
        $post_tags_table = Qibbs_Database::post_tags();
        $tickets_table = Qibbs_Database::tickets();
        $stats_table = Qibbs_Database::user_stats();

        $post_ids = array();
        $forum_ids = array();
        $tag_ids = array();
        $media_ids = array();
        $author_post_counts = array();

        foreach ( (array) $rows as $row ) {
            $post_ids[] = (int) $row->id;

            if ( ! empty( $row->forum_id ) ) {
                $forum_ids[] = (int) $row->forum_id;
            }

            $media_ids = array_merge( $media_ids, qibbs_parse_id_list( isset( $row->media_ids ) ? $row->media_ids : '' ) );

            $source_type = isset( $row->source_type ) ? sanitize_key( (string) $row->source_type ) : '';
            if ( '' === $source_type ) {
                $author_id = isset( $row->author_id ) ? (int) $row->author_id : 0;
                if ( $author_id > 0 ) {
                    if ( ! isset( $author_post_counts[ $author_id ] ) ) {
                        $author_post_counts[ $author_id ] = 0;
                    }
                    $author_post_counts[ $author_id ]++;
                }
            }
        }

        $post_ids = array_values( array_unique( array_filter( $post_ids ) ) );
        if ( empty( $post_ids ) ) {
            return;
        }

        $wpdb->query( 'START TRANSACTION' );
        try {
            $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
            $tag_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT tag_id FROM {$post_tags_table} WHERE post_id IN ({$placeholders})",
                    $post_ids
                )
            );

            $comment_rows = $this->get_comments_for_posts( $post_ids );
            $comment_ids = array_map(
                static function( $comment_row ) {
                    return (int) $comment_row->id;
                },
                $comment_rows
            );
            if ( ! empty( $comment_ids ) ) {
                $this->delete_comment_relations( $comment_ids );
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM " . Qibbs_Database::comments() . " WHERE post_id IN ({$placeholders})",
                        $post_ids
                    )
                );
            }

            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$likes_table} WHERE post_id IN ({$placeholders})",
                    $post_ids
                )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$favorites_table} WHERE post_id IN ({$placeholders})",
                    $post_ids
                )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$reports_table} WHERE post_id IN ({$placeholders})",
                    $post_ids
                )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$post_tags_table} WHERE post_id IN ({$placeholders})",
                    $post_ids
                )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$notifications_table} WHERE object_type = %s AND object_id IN ({$placeholders})",
                    array_merge( array( 'post' ), $post_ids )
                )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$tickets_table}
                     SET is_published = 0, published_post_id = 0, published_forum_id = 0
                     WHERE published_post_id IN ({$placeholders})",
                    $post_ids
                )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$posts_table} WHERE id IN ({$placeholders})",
                    $post_ids
                )
            );

            foreach ( $author_post_counts as $author_id => $post_count ) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$stats_table} SET post_count = GREATEST(post_count - %d, 0) WHERE user_id = %d",
                        (int) $post_count,
                        (int) $author_id
                    )
                );
            }

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'Qibbs purge_posts transaction error: ' . $e->getMessage() );
            return;
        }

        $this->rebuild_tag_counts( $tag_ids );
        $this->rebuild_forum_post_counts( $forum_ids );
        $this->cleanup_unused_media( $media_ids );
    }

    /**
     * 彻底清除评论及其全部下级回复
     *
     * @param array $rows Comment rows.
     * @return void
     */
    private function purge_comments( $rows ) {
        global $wpdb;

        $comments_table = Qibbs_Database::comments();
        $posts_table = Qibbs_Database::posts();
        $seed_ids = array();
        $seed_rows = array();

        foreach ( (array) $rows as $row ) {
            $seed_id = (int) $row->id;
            $seed_ids[] = $seed_id;
            $seed_rows[ $seed_id ] = $row;
        }

        $comment_rows = $this->expand_comment_rows_with_descendants( $seed_ids );
        if ( empty( $comment_rows ) ) {
            return;
        }

        $comment_ids = array_map(
            static function( $comment_row ) {
                return (int) $comment_row->id;
            },
            $comment_rows
        );

        $wpdb->query( 'START TRANSACTION' );
        try {
            $this->delete_comment_relations( $comment_ids );
            $this->clear_best_answers_for_deleted_comments( $comment_ids );

            $placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$comments_table} WHERE id IN ({$placeholders})",
                    $comment_ids
                )
            );

            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'Qibbs purge_comments transaction error: ' . $e->getMessage() );
            return;
        }

        $this->rebuild_comment_counts( $comment_rows, $comments_table, $posts_table );
    }

    /**
     * 查询指定帖子下属的所有评论
     *
     * @param array $post_ids Post IDs.
     * @return array
     */
    private function get_comments_for_posts( $post_ids ) {
        global $wpdb;

        $post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) );
        if ( empty( $post_ids ) ) {
            return array();
        }

        $comments_table = Qibbs_Database::comments();
        $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, post_id, user_id, parent_id FROM {$comments_table} WHERE post_id IN ({$placeholders})",
                $post_ids
            )
        );
    }

    /**
     * 递归展开评论的所有下级回复
     *
     * @param array $seed_ids Seed comment IDs.
     * @return array
     */
    private function expand_comment_rows_with_descendants( $seed_ids ) {
        global $wpdb;

        $comments_table = Qibbs_Database::comments();
        $queue = array_values( array_unique( array_filter( array_map( 'absint', (array) $seed_ids ) ) ) );
        $seen = array();
        $rows_by_id = array();

        while ( ! empty( $queue ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $queue ), '%d' ) );

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, post_id, user_id, parent_id FROM {$comments_table}
                     WHERE id IN ({$placeholders}) OR parent_id IN ({$placeholders})",
                    array_merge( $queue, $queue )
                )
            );

            $queue = array();
            foreach ( (array) $rows as $row ) {
                $comment_id = (int) $row->id;
                if ( isset( $seen[ $comment_id ] ) ) {
                    continue;
                }
                $seen[ $comment_id ] = true;
                $rows_by_id[ $comment_id ] = $row;
                $queue[] = $comment_id;
            }
        }

        return array_values( $rows_by_id );
    }

    /**
     * 清理评论的关联点赞与通知
     *
     * @param array $comment_ids Comment IDs.
     * @return void
     */
    private function delete_comment_relations( $comment_ids ) {
        global $wpdb;

        $comment_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $comment_ids ) ) ) );
        if ( empty( $comment_ids ) ) {
            return;
        }

        $comment_likes_table = Qibbs_Database::comment_likes();
        $notifications_table = Qibbs_Database::notifications();
        $placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$comment_likes_table} WHERE comment_id IN ({$placeholders})",
                $comment_ids
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$notifications_table} WHERE object_type = %s AND object_id IN ({$placeholders})",
                array_merge( array( 'comment' ), $comment_ids )
            )
        );
    }

    /**
     * 被删评论若为最佳答案则重置相关字段
     *
     * @param array $comment_ids Comment IDs.
     * @return void
     */
    private function clear_best_answers_for_deleted_comments( $comment_ids ) {
        global $wpdb;

        $comment_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $comment_ids ) ) ) );
        if ( empty( $comment_ids ) ) {
            return;
        }

        $posts_table = Qibbs_Database::posts();
        $placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, discussion_status FROM {$posts_table} WHERE best_comment_id IN ({$placeholders})",
                $comment_ids
            )
        );

        foreach ( (array) $posts as $post ) {
            $discussion_status = isset( $post->discussion_status ) ? (string) $post->discussion_status : 'discussion';
            $next_status = ( 'closed' === $discussion_status ) ? 'closed' : 'discussion';
            $legacy_resolved = in_array( $next_status, array( 'resolved', 'closed' ), true ) ? 1 : 0;

            $wpdb->update(
                $posts_table,
                array(
                    'best_comment_id'   => 0,
                    'best_answer_at'    => null,
                    'best_answer_by'    => 0,
                    'discussion_status' => $next_status,
                    'is_resolved'       => $legacy_resolved,
                    'updated_at'        => current_time( 'mysql' ),
                ),
                array( 'id' => (int) $post->id ),
                array( '%d', '%s', '%d', '%s', '%d', '%s' ),
                array( '%d' )
            );
        }
    }

    /**
     * 重新统计并同步受影响标签的发帖数
     *
     * @param array $tag_ids Tag IDs.
     * @return void
     */
    private function rebuild_tag_counts( $tag_ids ) {
        global $wpdb;

        $tag_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $tag_ids ) ) ) );
        if ( empty( $tag_ids ) ) {
            return;
        }

        $tags_table = Qibbs_Database::tags();
        $post_tags_table = Qibbs_Database::post_tags();
        $posts_table = Qibbs_Database::posts();

        foreach ( $tag_ids as $tag_id ) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$post_tags_table} pt
                     INNER JOIN {$posts_table} p ON p.id = pt.post_id
                     WHERE pt.tag_id = %d AND p.status = 'publish'",
                    $tag_id
                )
            );

            $wpdb->update(
                $tags_table,
                array( 'post_count' => $count ),
                array( 'id' => $tag_id ),
                array( '%d' ),
                array( '%d' )
            );
        }
    }

    /**
     * 重新统计并同步受影响版块的发帖数
     *
     * @param array $forum_ids Forum IDs.
     * @return void
     */
    private function rebuild_forum_post_counts( $forum_ids ) {
        global $wpdb;

        $forum_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $forum_ids ) ) ) );
        if ( empty( $forum_ids ) ) {
            return;
        }

        $posts_table = Qibbs_Database::posts();
        $forums_table = Qibbs_Database::forums();

        foreach ( $forum_ids as $forum_id ) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$posts_table} WHERE forum_id = %d AND status = 'publish'",
                    $forum_id
                )
            );

            $wpdb->update(
                $forums_table,
                array( 'post_count' => $count ),
                array( 'id' => $forum_id ),
                array( '%d' ),
                array( '%d' )
            );
        }
    }

    /**
     * 清理未被任何内容引用的孤立媒体文件
     *
     * @param array $media_ids Media IDs.
     * @return void
     */
    private function cleanup_unused_media( $media_ids ) {
        global $wpdb;

        $media_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $media_ids ) ) ) );
        if ( empty( $media_ids ) ) {
            return;
        }

        $media_table = Qibbs_Database::media();
        foreach ( $media_ids as $media_id ) {
            if ( qibbs_count_media_references( $media_id ) > 0 ) {
                continue;
            }

            $media = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, file_path FROM {$media_table} WHERE id = %d",
                    $media_id
                )
            );

            if ( ! $media ) {
                continue;
            }

            if ( ! empty( $media->file_path ) && file_exists( $media->file_path ) ) {
                @unlink( $media->file_path );
            }

            $wpdb->delete( $media_table, array( 'id' => $media_id ), array( '%d' ) );
        }
    }

    /**
     * 构建内容审核结果通知文案
     *
     * @param string $content Content preview source.
     * @param string $reason Reason.
     * @return string
     */
    private function build_notification_message( $content, $reason ) {
        $plain = wp_strip_all_tags( (string) $content );
        $preview = mb_substr( $plain, 0, 40 );
        if ( mb_strlen( $plain ) > 40 ) {
            $preview .= '...';
        }

        if ( '' !== $reason ) {
            $preview .= ' | ' . sprintf( __( '原因：%s', 'qibbs-community' ), $reason );
        }

        return $preview;
    }

    /**
     * 获取审核操作动作中文标签
     *
     * @param string $action Action.
     * @return string
     */
    public function get_action_label( $action ) {
        $map = array(
            'approve' => __( '通过', 'qibbs-community' ),
            'reject'  => __( '拒绝', 'qibbs-community' ),
            'trash'   => __( '移入回收站', 'qibbs-community' ),
            'restore' => __( '恢复', 'qibbs-community' ),
            'purge'   => __( '彻底删除', 'qibbs-community' ),
            'delete'  => __( '删除', 'qibbs-community' ),
            'block'   => __( '屏蔽', 'qibbs-community' ),
            'pin'     => __( '置顶', 'qibbs-community' ),
            'unpin'   => __( '取消置顶', 'qibbs-community' ),
            'feature' => __( '设为精华', 'qibbs-community' ),
            'unfeature' => __( '取消精华', 'qibbs-community' ),
            'lock'    => __( '锁帖', 'qibbs-community' ),
            'unlock'  => __( '解除锁帖', 'qibbs-community' ),
            'move_forum' => __( '移动圈子', 'qibbs-community' ),
            'blacklist' => __( '加入黑名单', 'qibbs-community' ),
            'unblacklist' => __( '移出黑名单', 'qibbs-community' ),
            'set_post_cooldown' => __( '设置发帖冷却', 'qibbs-community' ),
            'clear_post_cooldown' => __( '清除发帖冷却', 'qibbs-community' ),
            'forum_add_moderator' => __( '新增圈子版主', 'qibbs-community' ),
            'forum_remove_moderator' => __( '移除圈子版主', 'qibbs-community' ),
            'moderator_pin_post' => __( '置顶动态', 'qibbs-community' ),
            'moderator_unpin_post' => __( '取消置顶', 'qibbs-community' ),
            'moderator_feature_post' => __( '设为精华', 'qibbs-community' ),
            'moderator_unfeature_post' => __( '取消精华', 'qibbs-community' ),
            'moderator_lock_post' => __( '锁帖', 'qibbs-community' ),
            'moderator_unlock_post' => __( '解除锁帖', 'qibbs-community' ),
            'moderator_move_forum_post' => __( '移动圈子', 'qibbs-community' ),
            'moderator_trash_post' => __( '移入回收站', 'qibbs-community' ),
            'moderator_block_post' => __( '屏蔽动态', 'qibbs-community' ),
            'moderator_delete_post' => __( '删除动态', 'qibbs-community' ),
            'moderator_update_discussion_status' => __( '调整讨论状态', 'qibbs-community' ),
            'moderator_reopen_question' => __( '重开讨论', 'qibbs-community' ),
            'moderator_set_best_answer' => __( '设置最佳答案', 'qibbs-community' ),
            'moderator_clear_best_answer' => __( '取消最佳答案', 'qibbs-community' ),
            'moderator_pin_quality_comment' => __( '优质回复置顶', 'qibbs-community' ),
            'moderator_unpin_quality_comment' => __( '取消优质置顶', 'qibbs-community' ),
            'moderator_delete_comment' => __( '删除评论', 'qibbs-community' ),
            'moderator_block_comment' => __( '屏蔽评论', 'qibbs-community' ),
            'moderator_blacklist_user' => __( '加入黑名单', 'qibbs-community' ),
            'moderator_unblacklist_user' => __( '移出黑名单', 'qibbs-community' ),
            'moderator_set_post_cooldown' => __( '设置发帖冷却', 'qibbs-community' ),
            'moderator_clear_post_cooldown' => __( '清除发帖冷却', 'qibbs-community' ),
            'moderator_update_forum_governance' => __( '更新圈子规则', 'qibbs-community' ),
        );

        return isset( $map[ $action ] ) ? $map[ $action ] : $action;
    }

    /**
     * 写入审核操作日志审计流水
     *
     * @param string $object_type post|comment|user|forum.
     * @param int    $object_id Object ID.
     * @param int    $target_user_id Target user.
     * @param int    $admin_id Admin.
     * @param string $action Action.
     * @param string $reason Reason.
     * @return void
     */
    private function log_action( $object_type, $object_id, $target_user_id, $admin_id, $action, $reason ) {
        global $wpdb;

        $table = Qibbs_Database::get_table( 'moderation_logs' );

        $wpdb->insert(
            $table,
            array(
                'object_type'    => sanitize_key( $object_type ),
                'object_id'      => absint( $object_id ),
                'target_user_id' => absint( $target_user_id ),
                'admin_id'       => absint( $admin_id ),
                'action'         => sanitize_key( $action ),
                'reason'         => sanitize_textarea_field( $reason ),
                'created_at'     => current_time( 'mysql' ),
            ),
            array( '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
        );

        self::bump_cache_version();
    }

    /**
     * 查询审核操作审计日志
     *
     * @param int $limit Limit.
     * @return array
     */
    public function get_recent_logs( $limit = 50 ) {
        global $wpdb;

        $limit = max( 1, min( 500, absint( $limit ) ) );
        $cache_key = self::build_cache_key(
            'recent_logs',
            array(
                'limit' => $limit,
            )
        );
        $cached = self::get_cached_payload( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $table = Qibbs_Database::get_table( 'moderation_logs' );

        return self::set_cached_payload(
            $cache_key,
            $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
                    $limit
                )
            ),
            (int) apply_filters( 'qibbs_moderation_recent_logs_cache_ttl', 60 )
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
     * 标准化版块范围 ID 集合
     *
     * @param array $forum_ids Raw forum IDs.
     * @return array
     */
    private function normalize_forum_ids( $forum_ids ) {
        return array_values(
            array_unique(
                array_filter(
                    array_map( 'absint', (array) $forum_ids )
                )
            )
        );
    }
}
