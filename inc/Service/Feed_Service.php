<?php
/**
 * 社区动态流检索与聚合服务类
 *
 * 负责最新、热门、关注、版块及搜索动态流的高性能查询与拼装
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 动态流服务核心类
 *
 * 管理社区动态流检索与卡片渲染
 */
class Qibbs_Feed_Service {

    /**
     * 查询全站最新动态流
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_global_feed( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => qibbs_get_option( 'posts_per_page', 20 ),
            'page'     => 1,
            'status'   => 'publish',
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        if ( $this->has_advanced_filters( $args ) ) {
            return $this->search_feed( $args );
        }

        /**
         * 动态流查询参数过滤器
         *
         * @since 1.1.0
         * @param array  $args      Query arguments.
         * @param string $feed_type Feed type identifier.
         */
        $args = apply_filters( 'qibbs_feed_query_args', $args, 'global' );

        $args['page'] = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );

        $allowed_orderby = array( 'created_at', 'comment_count', 'like_count', 'view_count', 'id' );
        if ( ! in_array( $args['orderby'], $allowed_orderby, true ) ) {
            $args['orderby'] = 'created_at';
        }
        $args['order'] = ( strtoupper( $args['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';

        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $use_lightweight_total = $this->should_use_lightweight_total( $args['page'] );

        $table = Qibbs_Database::posts();
        $posts = array();
        $total = 0;
        $has_more = false;
        $snapshot = null;

        $can_use_snapshot = class_exists( 'Qibbs_Cache_Service' )
            && 'publish' === $args['status']
            && 'created_at' === $args['orderby']
            && 'DESC' === $args['order'];

        if ( $can_use_snapshot ) {
            $snapshot = Qibbs_Cache_Service::get_global_feed_snapshot( $args );
        }

        if ( is_array( $snapshot ) ) {
            $post_ids = array_values( array_filter( array_map( 'absint', (array) $snapshot['ids'] ) ) );
            $total = (int) $snapshot['total'];
            $posts = $this->get_posts_by_ids( $post_ids );
            $has_more = $this->resolve_has_more( $snapshot, $args['page'], $args['per_page'], $total );
        } else {
            // 构建 SQL 查询语句
            $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
            $query = $wpdb->prepare(
                "SELECT * FROM {$table} 
                 WHERE status = %s 
                 ORDER BY is_pinned DESC, {$args['orderby']} {$args['order']}
                 LIMIT %d OFFSET %d",
                $args['status'],
                $query_limit,
                $offset
            );

            $posts = $wpdb->get_results( $query );

            if ( $use_lightweight_total ) {
                list( $posts, $has_more ) = $this->trim_overflow_results( $posts, $args['per_page'] );
                $total = $this->build_lightweight_total( $args['page'], $args['per_page'], count( $posts ), $has_more );
            } else {
                // 首页面精准保持总数一致性
                $total = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                        $args['status']
                    )
                );
                $has_more = ( $args['page'] * $args['per_page'] ) < $total;
            }

            if ( $can_use_snapshot ) {
                Qibbs_Cache_Service::set_global_feed_snapshot(
                    $args,
                    array(
                        'ids'   => wp_list_pluck( $posts, 'id' ),
                        'total' => $total,
                        'has_more' => $has_more,
                    )
                );
            }
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 查询热门动态流（按互动热度与评论数排序）
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_hot_feed( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => qibbs_get_option( 'posts_per_page', 20 ),
            'page'     => 1,
            'status'   => 'publish',
            'hot_window' => absint( qibbs_get_option( 'hot_default_window', 7 ) ),
        );

        $args = wp_parse_args( $args, $defaults );

        if ( $this->has_advanced_filters( $args ) ) {
            return $this->search_feed( $args );
        }
        $args['page'] = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );
        $args['status'] = sanitize_key( (string) $args['status'] );
        if ( empty( $args['status'] ) ) {
            $args['status'] = 'publish';
        }
        $hot_window = absint( $args['hot_window'] );
        if ( ! in_array( $hot_window, array( 0, 1, 7, 30 ), true ) ) {
            $hot_window = 7;
        }
        $args['hot_window'] = $hot_window;
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $use_lightweight_total = $this->should_use_lightweight_total( $args['page'] );

        $table = Qibbs_Database::posts();
        $posts = array();
        $total = 0;
        $has_more = false;

        $snapshot = null;
        if ( class_exists( 'Qibbs_Cache_Service' ) ) {
            $snapshot = Qibbs_Cache_Service::get_hot_feed_snapshot( $args );
        }

        if ( is_array( $snapshot ) ) {
            $post_ids = array_values( array_filter( array_map( 'absint', (array) $snapshot['ids'] ) ) );
            $total = (int) $snapshot['total'];
            $posts = $this->get_posts_by_ids( $post_ids );
            $has_more = $this->resolve_has_more( $snapshot, $args['page'], $args['per_page'], $total );
        } else {
            $where_clause = 'status = %s';
            $query_params = array( $args['status'] );
            if ( $hot_window > 0 ) {
                $from_dt = current_datetime();
                $from_dt = $from_dt->modify( '-' . $hot_window . ' days' );
                $from_time = $from_dt ? $from_dt->format( 'Y-m-d H:i:s' ) : current_time( 'mysql' );
                $where_clause .= ' AND created_at >= %s';
                $query_params[] = $from_time;
            }
            $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
            $query_params[] = $query_limit;
            $query_params[] = $offset;

            // 构建热门 SQL 查询（按 comment_count 降序）
            $query = $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE {$where_clause}
                 ORDER BY comment_count DESC, like_count DESC, created_at DESC
                 LIMIT %d OFFSET %d",
                $query_params
            );

            $posts = $wpdb->get_results( $query );

            if ( $use_lightweight_total ) {
                list( $posts, $has_more ) = $this->trim_overflow_results( $posts, $args['per_page'] );
                $total = $this->build_lightweight_total( $args['page'], $args['per_page'], count( $posts ), $has_more );
            } else {
                // 首页面精准保持总数一致性
                if ( $hot_window > 0 ) {
                    $total = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$table} WHERE status = %s AND created_at >= %s",
                            $args['status'],
                            $from_time
                        )
                    );
                } else {
                    $total = (int) $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                            $args['status']
                        )
                    );
                }
                $has_more = ( $args['page'] * $args['per_page'] ) < $total;
            }

            if ( class_exists( 'Qibbs_Cache_Service' ) ) {
                Qibbs_Cache_Service::set_hot_feed_snapshot(
                    $args,
                    array(
                        'ids'   => wp_list_pluck( $posts, 'id' ),
                        'total' => $total,
                        'has_more' => $has_more,
                    )
                );
            }
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 查询精华推荐动态流
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_featured_feed( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => qibbs_get_option( 'posts_per_page', 20 ),
            'page'     => 1,
            'status'   => 'publish',
        );

        $args = wp_parse_args( $args, $defaults );
        $args['page'] = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $use_lightweight_total = $this->should_use_lightweight_total( $args['page'] );
        $has_more = false;

        // 查询前自动将过期的加精帖子标记失效
        $featured_service = new Qibbs_Featured_Service();
        $featured_service->maybe_clear_expired_features();

        $table = Qibbs_Database::posts();
        $now_mysql = current_time( 'mysql' );

        // 仅拉取处于加精有效期内的帖子
        $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
        $query = $wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE status = %s AND is_featured = 1
             AND (featured_until IS NULL OR featured_until > %s)
             ORDER BY featured_until IS NULL DESC, featured_until DESC, created_at DESC
             LIMIT %d OFFSET %d",
            $args['status'],
            $now_mysql,
            $query_limit,
            $offset
        );

        $posts = $wpdb->get_results( $query );

        if ( $use_lightweight_total ) {
            list( $posts, $has_more ) = $this->trim_overflow_results( $posts, $args['per_page'] );
            $total = $this->build_lightweight_total( $args['page'], $args['per_page'], count( $posts ), $has_more );
        } else {
            // 首页面精准保持总数一致性
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE status = %s AND is_featured = 1
                     AND (featured_until IS NULL OR featured_until > %s)",
                    $args['status'],
                    $now_mysql
                )
            );
            $has_more = ( $args['page'] * $args['per_page'] ) < $total;
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 查询投票专属动态流
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_voting_feed( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => qibbs_get_option( 'posts_per_page', 20 ),
            'page'     => 1,
            'status'   => 'publish',
        );

        $args = wp_parse_args( $args, $defaults );
        $args['page'] = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $use_lightweight_total = $this->should_use_lightweight_total( $args['page'] );
        $has_more = false;

        $posts_table = Qibbs_Database::posts();
        
        // 检查 Qivoting 插件是否激活
        if ( ! class_exists( 'Qivot_Database' ) ) {
            return array(
                'posts'       => array(),
                'total'       => 0,
                'page'        => $args['page'],
                'per_page'    => $args['per_page'],
                'total_pages' => 0,
                'has_more'    => false,
            );
        }

        $polls_table = Qivot_Database::polls();

        // 查询关联了投票的动态（通过 Qivoting polls 表的 post_id 字段关联 qibbs_posts.id）
        $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
        $query = $wpdb->prepare(
            "SELECT DISTINCT p.* FROM {$posts_table} p
             INNER JOIN {$polls_table} poll ON p.id = poll.post_id
             WHERE p.status = %s
             ORDER BY p.created_at DESC
             LIMIT %d OFFSET %d",
            $args['status'],
            $query_limit,
            $offset
        );

        $posts = $wpdb->get_results( $query );

        if ( $use_lightweight_total ) {
            list( $posts, $has_more ) = $this->trim_overflow_results( $posts, $args['per_page'] );
            $total = $this->build_lightweight_total( $args['page'], $args['per_page'], count( $posts ), $has_more );
        } else {
            // 首页面精准保持总数一致性
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT p.id) FROM {$posts_table} p
                     INNER JOIN {$polls_table} poll ON p.id = poll.post_id
                     WHERE p.status = %s",
                    $args['status']
                )
            );
            $has_more = ( $args['page'] * $args['per_page'] ) < $total;
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 查询红包专属动态流
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_redpacket_feed( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => qibbs_get_option( 'posts_per_page', 20 ),
            'page'     => 1,
            'status'   => 'publish',
        );

        $args = wp_parse_args( $args, $defaults );
        $args['page'] = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $use_lightweight_total = $this->should_use_lightweight_total( $args['page'] );
        $has_more = false;

        $posts_table = Qibbs_Database::posts();
        $redpackets_table = Qibbs_Database::redpackets();
        $now_mysql = current_time( 'mysql' );

        $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
        $query = $wpdb->prepare(
            "SELECT DISTINCT p.* FROM {$posts_table} p
             INNER JOIN {$redpackets_table} rp ON p.id = rp.post_id
             WHERE p.status = %s
             ORDER BY (CASE WHEN rp.status = 'active' AND rp.remaining_num > 0 AND (rp.expires_at IS NULL OR rp.expires_at > %s) THEN 1 ELSE 0 END) DESC, p.created_at DESC
             LIMIT %d OFFSET %d",
            $args['status'],
            $now_mysql,
            $query_limit,
            $offset
        );

        $posts = $wpdb->get_results( $query );

        if ( $use_lightweight_total ) {
            list( $posts, $has_more ) = $this->trim_overflow_results( $posts, $args['per_page'] );
            $total = $this->build_lightweight_total( $args['page'], $args['per_page'], count( $posts ), $has_more );
        } else {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT p.id) FROM {$posts_table} p
                     INNER JOIN {$redpackets_table} rp ON p.id = rp.post_id
                     WHERE p.status = %s",
                    $args['status']
                )
            );
            $has_more = ( $args['page'] * $args['per_page'] ) < $total;
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 查询悬赏专属动态流
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_bounty_feed( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => qibbs_get_option( 'posts_per_page', 20 ),
            'page'     => 1,
            'status'   => 'publish',
        );

        $args = wp_parse_args( $args, $defaults );
        $args['page'] = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $use_lightweight_total = $this->should_use_lightweight_total( $args['page'] );
        $has_more = false;

        $posts_table = Qibbs_Database::posts();
        $bounties_table = Qibbs_Database::bounties();

        $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
        $query = $wpdb->prepare(
            "SELECT DISTINCT p.* FROM {$posts_table} p
             INNER JOIN {$bounties_table} b ON p.id = b.post_id
             WHERE p.status = %s
             ORDER BY (CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) DESC, p.created_at DESC
             LIMIT %d OFFSET %d",
            $args['status'],
            $query_limit,
            $offset
        );

        $posts = $wpdb->get_results( $query );

        if ( $use_lightweight_total ) {
            list( $posts, $has_more ) = $this->trim_overflow_results( $posts, $args['per_page'] );
            $total = $this->build_lightweight_total( $args['page'], $args['per_page'], count( $posts ), $has_more );
        } else {
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT p.id) FROM {$posts_table} p
                     INNER JOIN {$bounties_table} b ON p.id = b.post_id
                     WHERE p.status = %s",
                    $args['status']
                )
            );
            $has_more = ( $args['page'] * $args['per_page'] ) < $total;
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 查询关注动态流（所关注用户的最新帖子）
     *
     * @param int   $user_id User ID.
     * @param array $args    Query arguments.
     * @return array
     */
    public function get_following_feed( $user_id, $args = array() ) {
        global $wpdb;

        if ( ! $user_id ) {
            return array(
                'posts'       => array(),
                'total'       => 0,
                'page'        => 1,
                'per_page'    => 20,
                'total_pages' => 0,
                'has_more'    => false,
            );
        }

        $defaults = array(
            'per_page' => qibbs_get_option( 'posts_per_page', 20 ),
            'page'     => 1,
            'status'   => 'publish',
        );

        $args = wp_parse_args( $args, $defaults );
        $args['page'] = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );
        $args['status'] = sanitize_key( (string) $args['status'] );
        if ( empty( $args['status'] ) ) {
            $args['status'] = 'publish';
        }
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $use_lightweight_total = $args['page'] > 1;

        $posts_table = Qibbs_Database::posts();
        $follows_table = Qibbs_Database::follows();
        $posts = array();
        $total = 0;
        $has_more = false;
        $can_use_snapshot = class_exists( 'Qibbs_Cache_Service' ) && 'publish' === $args['status'];
        $snapshot = null;

        if ( $can_use_snapshot ) {
            $snapshot = Qibbs_Cache_Service::get_user_feed_snapshot( 'following', $user_id, $args );
        }

        if ( is_array( $snapshot ) ) {
            $post_ids = array_values( array_filter( array_map( 'absint', (array) $snapshot['ids'] ) ) );
            $total = (int) $snapshot['total'];
            $posts = $this->get_posts_by_ids( $post_ids );
            if ( isset( $snapshot['has_more'] ) && null !== $snapshot['has_more'] ) {
                $has_more = (bool) $snapshot['has_more'];
            } else {
                $total_pages = $args['per_page'] > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;
                $has_more = $args['page'] < $total_pages;
            }
        } else {
            $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
            // 筛选关注用户发布的帖子
            $query = $wpdb->prepare(
                "SELECT p.* FROM {$posts_table} p
                 INNER JOIN {$follows_table} f ON p.author_id = f.following_id
                 WHERE f.follower_id = %d AND p.status = %s
                 ORDER BY p.created_at DESC
                 LIMIT %d OFFSET %d",
                $user_id,
                $args['status'],
                $query_limit,
                $offset
            );

            $posts = $wpdb->get_results( $query );

            if ( $use_lightweight_total ) {
                $has_more = count( $posts ) > $args['per_page'];
                if ( $has_more ) {
                    $posts = array_slice( $posts, 0, $args['per_page'] );
                }
                $shown_count = count( $posts );
                $total = $has_more
                    ? ( $args['page'] * $args['per_page'] + 1 )
                    : ( ( $args['page'] - 1 ) * $args['per_page'] + $shown_count );
            } else {
                // 首页面精准保持总数一致性
                $total = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$posts_table} p
                         INNER JOIN {$follows_table} f ON p.author_id = f.following_id
                         WHERE f.follower_id = %d AND p.status = %s",
                        $user_id,
                        $args['status']
                    )
                );
                $has_more = ( $args['page'] * $args['per_page'] ) < $total;
            }

            if ( $can_use_snapshot ) {
                Qibbs_Cache_Service::set_user_feed_snapshot(
                    'following',
                    $user_id,
                    $args,
                    array(
                        'ids'   => wp_list_pluck( $posts, 'id' ),
                        'total' => $total,
                        'has_more' => $has_more,
                    )
                );
            }
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 查询指定版块动态流
     *
     * @param int   $forum_id Forum ID.
     * @param array $args     Query arguments.
     * @return array
     */
    public function get_forum_feed( $forum_id, $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => qibbs_get_option( 'posts_per_page', 20 ),
            'page'     => 1,
            'status'   => 'publish',
        );

        $args = wp_parse_args( $args, $defaults );
        if ( $this->has_advanced_filters( $args ) ) {
            $args['filter_type'] = 'forum';
            $args['filter_id']   = absint( $forum_id );
            return $this->search_feed( $args );
        }
        $args['page'] = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );
        $args['status'] = sanitize_key( (string) $args['status'] );
        if ( empty( $args['status'] ) ) {
            $args['status'] = 'publish';
        }
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $use_lightweight_total = $this->should_use_lightweight_total( $args['page'] );

        $table = Qibbs_Database::posts();
        $posts = array();
        $total = 0;
        $has_more = false;
        $can_use_snapshot = class_exists( 'Qibbs_Cache_Service' ) && 'publish' === $args['status'];
        $snapshot = null;

        if ( $can_use_snapshot ) {
            $snapshot = Qibbs_Cache_Service::get_forum_feed_snapshot( $forum_id, $args );
        }

        if ( is_array( $snapshot ) ) {
            $post_ids = array_values( array_filter( array_map( 'absint', (array) $snapshot['ids'] ) ) );
            $total = (int) $snapshot['total'];
            $posts = $this->get_posts_by_ids( $post_ids );
            $has_more = $this->resolve_has_more( $snapshot, $args['page'], $args['per_page'], $total );
        } else {
            $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
            $query = $wpdb->prepare(
                "SELECT * FROM {$table} 
                 WHERE forum_id = %d AND status = %s
                 ORDER BY is_pinned DESC, (CASE WHEN is_promoted = 1 AND (promoted_until IS NULL OR promoted_until > NOW()) THEN 1 ELSE 0 END) DESC, created_at DESC
                 LIMIT %d OFFSET %d",
                $forum_id,
                $args['status'],
                $query_limit,
                $offset
            );

            $posts = $wpdb->get_results( $query );

            if ( $use_lightweight_total ) {
                list( $posts, $has_more ) = $this->trim_overflow_results( $posts, $args['per_page'] );
                $total = $this->build_lightweight_total( $args['page'], $args['per_page'], count( $posts ), $has_more );
            } else {
                // 首页面精准保持总数一致性
                $total = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE forum_id = %d AND status = %s",
                        $forum_id,
                        $args['status']
                    )
                );
                $has_more = ( $args['page'] * $args['per_page'] ) < $total;
            }

            if ( $can_use_snapshot ) {
                Qibbs_Cache_Service::set_forum_feed_snapshot(
                    $forum_id,
                    $args,
                    array(
                        'ids'   => wp_list_pluck( $posts, 'id' ),
                        'total' => $total,
                        'has_more' => $has_more,
                    )
                );
            }
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 查询指定用户的个人发帖动态流
     *
     * @param int   $user_id User ID.
     * @param array $args    Query arguments.
     * @return array
     */
    public function get_user_feed( $user_id, $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => qibbs_get_option( 'posts_per_page', 20 ),
            'page'     => 1,
            'status'   => 'publish',
        );

        $args = wp_parse_args( $args, $defaults );
        $args['page'] = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $use_lightweight_total = $this->should_use_lightweight_total( $args['page'] );
        $has_more = false;

        $table = Qibbs_Database::posts();

        // 若为本人查阅个人主页，则包含待审核等状态
        $current_user_id = get_current_user_id();
        if ( $current_user_id === $user_id ) {
            $status_clause = "status IN ('publish', 'draft', 'qibbs_pending')";
        } else {
            $status_clause = "status = 'publish'";
        }

        $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
        $query = $wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE author_id = %d AND {$status_clause}
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $user_id,
            $query_limit,
            $offset
        );

        $posts = $wpdb->get_results( $query );

        if ( $use_lightweight_total ) {
            list( $posts, $has_more ) = $this->trim_overflow_results( $posts, $args['per_page'] );
            $total = $this->build_lightweight_total( $args['page'], $args['per_page'], count( $posts ), $has_more );
        } else {
            // 首页面精准保持总数一致性
            $total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE author_id = %d AND {$status_clause}",
                    $user_id
                )
            );
            $has_more = ( $args['page'] * $args['per_page'] ) < $total;
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 查询话题标签动态流
     *
     * @param int   $tag_id Tag ID.
     * @param array $args   Query arguments.
     * @return array
     */
    public function get_tag_feed( $tag_id, $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => qibbs_get_option( 'posts_per_page', 20 ),
            'page'     => 1,
            'status'   => 'publish',
        );

        $args = wp_parse_args( $args, $defaults );
        if ( $this->has_advanced_filters( $args ) ) {
            $args['filter_type'] = 'tag';
            $args['filter_id']   = absint( $tag_id );
            return $this->search_feed( $args );
        }
        $args['page'] = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );
        $args['status'] = sanitize_key( (string) $args['status'] );
        if ( empty( $args['status'] ) ) {
            $args['status'] = 'publish';
        }
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $use_lightweight_total = $this->should_use_lightweight_total( $args['page'] );

        $posts_table = Qibbs_Database::posts();
        $post_tags_table = Qibbs_Database::post_tags();
        $posts = array();
        $total = 0;
        $has_more = false;
        $can_use_snapshot = class_exists( 'Qibbs_Cache_Service' ) && 'publish' === $args['status'];
        $snapshot = null;

        if ( $can_use_snapshot ) {
            $snapshot = Qibbs_Cache_Service::get_tag_feed_snapshot( $tag_id, $args );
        }

        if ( is_array( $snapshot ) ) {
            $post_ids = array_values( array_filter( array_map( 'absint', (array) $snapshot['ids'] ) ) );
            $total = (int) $snapshot['total'];
            $posts = $this->get_posts_by_ids( $post_ids );
            $has_more = $this->resolve_has_more( $snapshot, $args['page'], $args['per_page'], $total );
        } else {
            $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
            $query = $wpdb->prepare(
                "SELECT p.* FROM {$posts_table} p
                 INNER JOIN {$post_tags_table} pt ON p.id = pt.post_id
                 WHERE pt.tag_id = %d AND p.status = %s
                 ORDER BY p.created_at DESC
                 LIMIT %d OFFSET %d",
                $tag_id,
                $args['status'],
                $query_limit,
                $offset
            );

            $posts = $wpdb->get_results( $query );

            if ( $use_lightweight_total ) {
                list( $posts, $has_more ) = $this->trim_overflow_results( $posts, $args['per_page'] );
                $total = $this->build_lightweight_total( $args['page'], $args['per_page'], count( $posts ), $has_more );
            } else {
                // 首页面精准保持总数一致性
                $total = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$posts_table} p
                         INNER JOIN {$post_tags_table} pt ON p.id = pt.post_id
                         WHERE pt.tag_id = %d AND p.status = %s",
                        $tag_id,
                        $args['status']
                    )
                );
                $has_more = ( $args['page'] * $args['per_page'] ) < $total;
            }

            if ( $can_use_snapshot ) {
                Qibbs_Cache_Service::set_tag_feed_snapshot(
                    $tag_id,
                    $args,
                    array(
                        'ids'   => wp_list_pluck( $posts, 'id' ),
                        'total' => $total,
                        'has_more' => $has_more,
                    )
                );
            }
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 查询文章分类同步动态流
     *
     * @param int   $category_id Category ID.
     * @param array $args        Query arguments.
     * @return array
     */
    public function get_category_feed( $category_id, $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => qibbs_get_option( 'posts_per_page', 20 ),
            'page'     => 1,
            'status'   => 'publish',
        );

        $args = wp_parse_args( $args, $defaults );
        if ( $this->has_advanced_filters( $args ) ) {
            $args['filter_type'] = 'category';
            $args['filter_id']   = absint( $category_id );
            return $this->search_feed( $args );
        }
        $args['page'] = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );
        $args['status'] = sanitize_key( (string) $args['status'] );
        if ( empty( $args['status'] ) ) {
            $args['status'] = 'publish';
        }
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $use_lightweight_total = $this->should_use_lightweight_total( $args['page'] );

        $table = Qibbs_Database::posts();
        $posts = array();
        $total = 0;
        $has_more = false;
        $can_use_snapshot = class_exists( 'Qibbs_Cache_Service' ) && 'publish' === $args['status'];
        $snapshot = null;

        if ( $can_use_snapshot ) {
            $snapshot = Qibbs_Cache_Service::get_category_feed_snapshot( $category_id, $args );
        }

        if ( is_array( $snapshot ) ) {
            $post_ids = array_values( array_filter( array_map( 'absint', (array) $snapshot['ids'] ) ) );
            $total = (int) $snapshot['total'];
            $posts = $this->get_posts_by_ids( $post_ids );
            $has_more = $this->resolve_has_more( $snapshot, $args['page'], $args['per_page'], $total );
        } else {
            $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
            $query = $wpdb->prepare(
                "SELECT * FROM {$table} 
                 WHERE source_category = %d AND status = %s
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d",
                $category_id,
                $args['status'],
                $query_limit,
                $offset
            );

            $posts = $wpdb->get_results( $query );

            if ( $use_lightweight_total ) {
                list( $posts, $has_more ) = $this->trim_overflow_results( $posts, $args['per_page'] );
                $total = $this->build_lightweight_total( $args['page'], $args['per_page'], count( $posts ), $has_more );
            } else {
                // 首页面精准保持总数一致性
                $total = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE source_category = %d AND status = %s",
                        $category_id,
                        $args['status']
                    )
                );
                $has_more = ( $args['page'] * $args['per_page'] ) < $total;
            }

            if ( $can_use_snapshot ) {
                Qibbs_Cache_Service::set_category_feed_snapshot(
                    $category_id,
                    $args,
                    array(
                        'ids'   => wp_list_pluck( $posts, 'id' ),
                        'total' => $total,
                        'has_more' => $has_more,
                    )
                );
            }
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 查询用户收藏的帖子动态流
     *
     * @param int   $user_id User ID.
     * @param array $args    Query arguments.
     * @return array
     */
    public function get_user_favorites( $user_id, $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => qibbs_get_option( 'posts_per_page', 20 ),
            'page'     => 1,
            'status'   => 'publish',
        );

        $args = wp_parse_args( $args, $defaults );
        $args['page'] = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );
        $args['status'] = sanitize_key( (string) $args['status'] );
        if ( empty( $args['status'] ) ) {
            $args['status'] = 'publish';
        }
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $use_lightweight_total = $args['page'] > 1;

        $posts_table = Qibbs_Database::posts();
        $favorites_table = Qibbs_Database::favorites();
        $posts = array();
        $total = 0;
        $has_more = false;
        $can_use_snapshot = class_exists( 'Qibbs_Cache_Service' ) && 'publish' === $args['status'];
        $snapshot = null;

        if ( $can_use_snapshot ) {
            $snapshot = Qibbs_Cache_Service::get_user_feed_snapshot( 'favorites', $user_id, $args );
        }

        if ( is_array( $snapshot ) ) {
            $post_ids = array_values( array_filter( array_map( 'absint', (array) $snapshot['ids'] ) ) );
            $total = (int) $snapshot['total'];
            $posts = $this->get_posts_by_ids( $post_ids );
            if ( isset( $snapshot['has_more'] ) && null !== $snapshot['has_more'] ) {
                $has_more = (bool) $snapshot['has_more'];
            } else {
                $total_pages = $args['per_page'] > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;
                $has_more = $args['page'] < $total_pages;
            }
        } else {
            $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
            $query = $wpdb->prepare(
                "SELECT p.* FROM {$posts_table} p
                 INNER JOIN {$favorites_table} f ON p.id = f.post_id
                 WHERE f.user_id = %d AND p.status = %s
                 ORDER BY f.created_at DESC
                 LIMIT %d OFFSET %d",
                $user_id,
                $args['status'],
                $query_limit,
                $offset
            );

            $posts = $wpdb->get_results( $query );

            if ( $use_lightweight_total ) {
                $has_more = count( $posts ) > $args['per_page'];
                if ( $has_more ) {
                    $posts = array_slice( $posts, 0, $args['per_page'] );
                }
                $shown_count = count( $posts );
                $total = $has_more
                    ? ( $args['page'] * $args['per_page'] + 1 )
                    : ( ( $args['page'] - 1 ) * $args['per_page'] + $shown_count );
            } else {
                // 首页面精准保持总数一致性
                $total = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$posts_table} p
                         INNER JOIN {$favorites_table} f ON p.id = f.post_id
                         WHERE f.user_id = %d AND p.status = %s",
                        $user_id,
                        $args['status']
                    )
                );
                $has_more = ( $args['page'] * $args['per_page'] ) < $total;
            }

            if ( $can_use_snapshot ) {
                Qibbs_Cache_Service::set_user_feed_snapshot(
                    'favorites',
                    $user_id,
                    $args,
                    array(
                        'ids'   => wp_list_pluck( $posts, 'id' ),
                        'total' => $total,
                        'has_more' => $has_more,
                    )
                );
            }
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 查询用户点赞的帖子动态流
     *
     * @param int   $user_id User ID.
     * @param array $args    Query arguments.
     * @return array
     */
    public function get_user_likes( $user_id, $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => qibbs_get_option( 'posts_per_page', 20 ),
            'page'     => 1,
            'status'   => 'publish',
        );

        $args = wp_parse_args( $args, $defaults );
        $args['page'] = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );
        $args['status'] = sanitize_key( (string) $args['status'] );
        if ( empty( $args['status'] ) ) {
            $args['status'] = 'publish';
        }
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $use_lightweight_total = $args['page'] > 1;

        $posts_table = Qibbs_Database::posts();
        $likes_table = Qibbs_Database::likes();
        $posts = array();
        $total = 0;
        $has_more = false;
        $can_use_snapshot = class_exists( 'Qibbs_Cache_Service' ) && 'publish' === $args['status'];
        $snapshot = null;

        if ( $can_use_snapshot ) {
            $snapshot = Qibbs_Cache_Service::get_user_feed_snapshot( 'likes', $user_id, $args );
        }

        if ( is_array( $snapshot ) ) {
            $post_ids = array_values( array_filter( array_map( 'absint', (array) $snapshot['ids'] ) ) );
            $total = (int) $snapshot['total'];
            $posts = $this->get_posts_by_ids( $post_ids );
            if ( isset( $snapshot['has_more'] ) && null !== $snapshot['has_more'] ) {
                $has_more = (bool) $snapshot['has_more'];
            } else {
                $total_pages = $args['per_page'] > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;
                $has_more = $args['page'] < $total_pages;
            }
        } else {
            $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
            $query = $wpdb->prepare(
                "SELECT p.* FROM {$posts_table} p
                 INNER JOIN {$likes_table} l ON p.id = l.post_id
                 WHERE l.user_id = %d AND p.status = %s
                 ORDER BY l.created_at DESC
                 LIMIT %d OFFSET %d",
                $user_id,
                $args['status'],
                $query_limit,
                $offset
            );

            $posts = $wpdb->get_results( $query );

            if ( $use_lightweight_total ) {
                $has_more = count( $posts ) > $args['per_page'];
                if ( $has_more ) {
                    $posts = array_slice( $posts, 0, $args['per_page'] );
                }
                $shown_count = count( $posts );
                $total = $has_more
                    ? ( $args['page'] * $args['per_page'] + 1 )
                    : ( ( $args['page'] - 1 ) * $args['per_page'] + $shown_count );
            } else {
                // 首页面精准保持总数一致性
                $total = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$posts_table} p
                         INNER JOIN {$likes_table} l ON p.id = l.post_id
                         WHERE l.user_id = %d AND p.status = %s",
                        $user_id,
                        $args['status']
                    )
                );
                $has_more = ( $args['page'] * $args['per_page'] ) < $total;
            }

            if ( $can_use_snapshot ) {
                Qibbs_Cache_Service::set_user_feed_snapshot(
                    'likes',
                    $user_id,
                    $args,
                    array(
                        'ids'   => wp_list_pluck( $posts, 'id' ),
                        'total' => $total,
                        'has_more' => $has_more,
                    )
                );
            }
        }

        $total_pages = $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0;

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 检查动态请求是否包含高级检索筛选条件
     *
     * @param array $args Query arguments.
     * @return bool
     */
    private function has_advanced_filters( $args ) {
        $text_keys = array( 'query', 'author_query', 'tag_query' );
        foreach ( $text_keys as $key ) {
            if ( ! empty( $args[ $key ] ) ) {
                return true;
            }
        }

        if ( ! empty( $args['featured_only'] ) ) {
            return true;
        }

        if ( ! empty( $args['sort'] ) && 'latest' !== sanitize_key( (string) $args['sort'] ) ) {
            return true;
        }

        if ( ! empty( $args['resolved'] ) && in_array( sanitize_key( (string) $args['resolved'] ), array( 'resolved', 'open' ), true ) ) {
            return true;
        }

        return ! empty( $args['post_type'] ) && 'all' !== sanitize_key( (string) $args['post_type'] );
    }

    /**
     * 标准化自由文本搜索词
     *
     * @param string $value        Raw text.
     * @param bool   $strip_prefix Whether to strip leading #/@ prefixes.
     * @return string
     */
    private function normalize_search_text( $value, $strip_prefix = false ) {
        $value = sanitize_text_field( (string) $value );
        $value = trim( preg_replace( '/\s+/u', ' ', $value ) );

        if ( $strip_prefix ) {
            $value = trim( preg_replace( '/^[#＃@＠]+/u', '', $value ) );
        }

        return $value;
    }

    /**
     * 标准化搜索排序字段与升降序规则
     *
     * @param string $sort Raw sort.
     * @return string
     */
    private function normalize_search_sort( $sort ) {
        $sort = sanitize_key( (string) $sort );
        return in_array( $sort, array( 'latest', 'hot', 'comments', 'likes' ), true ) ? $sort : 'latest';
    }

    /**
     * 构建搜索查询的 ORDER BY SQL 子句
     *
     * @param string $sort Sort key.
     * @return string
     */
    private function get_search_sort_sql( $sort ) {
        switch ( $this->normalize_search_sort( $sort ) ) {
            case 'likes':
                return 'MAX(p.is_pinned) DESC, MAX(p.like_count) DESC, MAX(p.created_at) DESC';
            case 'comments':
                return 'MAX(p.is_pinned) DESC, MAX(p.comment_count) DESC, MAX(p.created_at) DESC';
            case 'hot':
                return 'MAX(p.is_pinned) DESC, MAX((p.comment_count * 3) + (p.like_count * 2) + (LEAST(p.view_count, 200) / 20)) DESC, MAX(p.created_at) DESC';
            case 'latest':
            default:
                return 'MAX(p.is_pinned) DESC, MAX(p.created_at) DESC';
        }
    }

    /**
     * 执行微社区综合检索与高级筛选
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function search_feed( $args = array() ) {
        $defaults = array(
            'per_page'      => qibbs_get_option( 'posts_per_page', 20 ),
            'page'          => 1,
            'status'        => 'publish',
            'query'         => '',
            'author_query'  => '',
            'tag_query'     => '',
            'post_type'     => '',
            'resolved'      => '',
            'sort'          => 'latest',
            'featured_only' => false,
            'filter_type'   => '',
            'filter_id'     => 0,
        );

        $args = wp_parse_args( $args, $defaults );

        $args['page']     = max( 1, absint( $args['page'] ) );
        $args['per_page'] = max( 1, min( 100, absint( $args['per_page'] ) ) );
        $args['status']   = sanitize_key( (string) $args['status'] );
        if ( empty( $args['status'] ) ) {
            $args['status'] = 'publish';
        }

        $args['query']        = $this->normalize_search_text( $args['query'], true );
        $args['author_query'] = $this->normalize_search_text( $args['author_query'], true );
        $args['tag_query']    = $this->normalize_search_text( $args['tag_query'], true );
        $args['sort']         = $this->normalize_search_sort( $args['sort'] );
        $args['filter_type']  = sanitize_key( (string) $args['filter_type'] );
        $args['filter_id']    = absint( $args['filter_id'] );

        $raw_post_type = sanitize_key( (string) $args['post_type'] );
        $args['post_type'] = ( '' === $raw_post_type || 'all' === $raw_post_type )
            ? ''
            : ( function_exists( 'qibbs_normalize_post_type' ) ? qibbs_normalize_post_type( $raw_post_type ) : 'discussion' );

        $raw_resolved = sanitize_key( (string) $args['resolved'] );
        $args['resolved'] = in_array( $raw_resolved, array( 'resolved', 'open' ), true ) ? $raw_resolved : '';
        $args['featured_only'] = ! empty( $args['featured_only'] );

        if ( class_exists( 'Qibbs_Search_Index_Service' ) ) {
            $search_index = new Qibbs_Search_Index_Service();
            $indexed_result = $search_index->search_feed( $args );

            if ( is_array( $indexed_result ) ) {
                $posts = $this->get_posts_by_ids( $indexed_result['post_ids'] );

                return array(
                    'posts'       => $this->enrich_posts( $posts ),
                    'total'       => isset( $indexed_result['total'] ) ? (int) $indexed_result['total'] : 0,
                    'page'        => isset( $indexed_result['page'] ) ? (int) $indexed_result['page'] : $args['page'],
                    'per_page'    => isset( $indexed_result['per_page'] ) ? (int) $indexed_result['per_page'] : $args['per_page'],
                    'total_pages' => isset( $indexed_result['total_pages'] ) ? (int) $indexed_result['total_pages'] : 0,
                    'has_more'    => ! empty( $indexed_result['has_more'] ),
                );
            }
        }

        global $wpdb;

        $offset          = ( $args['page'] - 1 ) * $args['per_page'];
        $posts_table     = Qibbs_Database::posts();
        $forums_table    = Qibbs_Database::forums();
        $post_tags_table = Qibbs_Database::post_tags();
        $tags_table      = Qibbs_Database::tags();

        $joins  = array();
        $where  = array( 'p.status = %s' );
        $params = array( $args['status'] );

        $needs_user_join = ( '' !== $args['query'] || '' !== $args['author_query'] );
        $needs_forum_join = ( '' !== $args['query'] );
        $needs_tag_join = ( '' !== $args['query'] || '' !== $args['tag_query'] || ( 'tag' === $args['filter_type'] && $args['filter_id'] ) );

        if ( $needs_user_join ) {
            $joins['users'] = "LEFT JOIN {$wpdb->users} u ON u.ID = p.author_id";
        }

        if ( $needs_forum_join ) {
            $joins['forums'] = "LEFT JOIN {$forums_table} f ON f.id = p.forum_id";
        }

        if ( $needs_tag_join ) {
            $joins['post_tags'] = "LEFT JOIN {$post_tags_table} pt ON pt.post_id = p.id";
            $joins['tags']      = "LEFT JOIN {$tags_table} t ON t.id = pt.tag_id";
        }

        if ( 'forum' === $args['filter_type'] && $args['filter_id'] ) {
            $where[]  = 'p.forum_id = %d';
            $params[] = $args['filter_id'];
        } elseif ( 'category' === $args['filter_type'] && $args['filter_id'] ) {
            $where[]  = 'p.source_category = %d';
            $params[] = $args['filter_id'];
        } elseif ( 'tag' === $args['filter_type'] && $args['filter_id'] ) {
            $where[]  = 't.id = %d';
            $params[] = $args['filter_id'];
        }

        if ( '' !== $args['post_type'] ) {
            $where[]  = 'p.post_type = %s';
            $params[] = $args['post_type'];
        }

        if ( 'resolved' === $args['resolved'] ) {
            if ( '' === $args['post_type'] ) {
                $where[]  = 'p.post_type = %s';
                $params[] = 'question';
            }
            $where[] = 'p.is_resolved = 1';
        } elseif ( 'open' === $args['resolved'] ) {
            if ( '' === $args['post_type'] ) {
                $where[]  = 'p.post_type = %s';
                $params[] = 'question';
            }
            $where[] = 'p.is_resolved = 0';
        }

        if ( $args['featured_only'] ) {
            $where[]  = '(p.is_featured = 1 AND (p.featured_until IS NULL OR p.featured_until > %s))';
            $params[] = current_time( 'mysql' );
        }

        if ( '' !== $args['author_query'] ) {
            $author_like = '%' . $wpdb->esc_like( $args['author_query'] ) . '%';
            $where[]     = '(u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_nicename LIKE %s)';
            $params[]    = $author_like;
            $params[]    = $author_like;
            $params[]    = $author_like;
        }

        if ( '' !== $args['tag_query'] ) {
            $tag_like  = '%' . $wpdb->esc_like( $args['tag_query'] ) . '%';
            $where[]   = '(t.name LIKE %s OR t.slug LIKE %s)';
            $params[]  = $tag_like;
            $params[]  = $tag_like;
        }

        if ( '' !== $args['query'] ) {
            $query_like = '%' . $wpdb->esc_like( $args['query'] ) . '%';
            $where[]    = '(p.content LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_nicename LIKE %s OR f.name LIKE %s OR f.slug LIKE %s OR t.name LIKE %s OR t.slug LIKE %s)';
            $params[]   = $query_like;
            $params[]   = $query_like;
            $params[]   = $query_like;
            $params[]   = $query_like;
            $params[]   = $query_like;
            $params[]   = $query_like;
            $params[]   = $query_like;
            $params[]   = $query_like;
        }

        $from_sql  = " FROM {$posts_table} p";
        $from_sql .= ! empty( $joins ) ? ' ' . implode( ' ', $joins ) : '';
        $where_sql = ! empty( $where ) ? ' WHERE ' . implode( ' AND ', $where ) : '';
        $use_lightweight_total = $this->should_use_lightweight_total( $args['page'] );
        $has_more = false;

        if ( ! $use_lightweight_total ) {
            $count_sql = "SELECT COUNT(DISTINCT p.id) {$from_sql}{$where_sql}";
            $total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

            if ( $total <= 0 ) {
                return array(
                    'posts'       => array(),
                    'total'       => 0,
                    'page'        => $args['page'],
                    'per_page'    => $args['per_page'],
                    'total_pages' => 0,
                    'has_more'    => false,
                );
            }
        } else {
            $total = 0;
        }

        $query_limit = $use_lightweight_total ? $args['per_page'] + 1 : $args['per_page'];
        $ids_sql  = "SELECT p.id {$from_sql}{$where_sql} GROUP BY p.id ORDER BY " . $this->get_search_sort_sql( $args['sort'] ) . ' LIMIT %d OFFSET %d';
        $post_ids = $wpdb->get_col( $wpdb->prepare( $ids_sql, array_merge( $params, array( $query_limit, $offset ) ) ) );

        if ( $use_lightweight_total ) {
            list( $post_ids, $has_more ) = $this->trim_overflow_results( $post_ids, $args['per_page'] );
            $total = $this->build_lightweight_total( $args['page'], $args['per_page'], count( $post_ids ), $has_more );
        } else {
            $total_pages = (int) ceil( $total / $args['per_page'] );
            $has_more = $args['page'] < $total_pages;
        }

        $posts    = $this->get_posts_by_ids( $post_ids );

        $total_pages = (int) ceil( $total / $args['per_page'] );

        return array(
            'posts'       => $this->enrich_posts( $posts ),
            'total'       => $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total_pages,
            'has_more'    => $has_more,
        );
    }

    /**
     * 前台动态流统一采用轻量分页探测机制
     *
     * @param int $page Current page.
     * @return bool
     */
    private function should_use_lightweight_total( $page ) {
        return true;
    }

    /**
     * 剔除用于探针检测 has_more 的多余单行记录
     *
     * @param array $rows     Result rows.
     * @param int   $per_page Page size.
     * @return array{0: array, 1: bool}
     */
    private function trim_overflow_results( $rows, $per_page ) {
        $rows = is_array( $rows ) ? array_values( $rows ) : array();
        $per_page = max( 1, absint( $per_page ) );
        $has_more = count( $rows ) > $per_page;

        if ( $has_more ) {
            $rows = array_slice( $rows, 0, $per_page );
        }

        return array( $rows, $has_more );
    }

    /**
     * 深分页时采用估算总数避免深度 COUNT 全表扫描
     *
     * @param int  $page       Current page.
     * @param int  $per_page   Page size.
     * @param int  $shown      Rows shown on the current page.
     * @param bool $has_more   Whether another page exists.
     * @return int
     */
    private function build_lightweight_total( $page, $per_page, $shown, $has_more ) {
        $page = max( 1, absint( $page ) );
        $per_page = max( 1, absint( $per_page ) );
        $shown = max( 0, absint( $shown ) );

        if ( $has_more ) {
            return ( $page * $per_page ) + 1;
        }

        return ( ( $page - 1 ) * $per_page ) + $shown;
    }

    /**
     * 从缓存快照中解析 has_more 状态
     *
     * @param array $snapshot Cached snapshot.
     * @param int   $page Current page.
     * @param int   $per_page Page size.
     * @param int   $total Total rows.
     * @return bool
     */
    private function resolve_has_more( $snapshot, $page, $per_page, $total ) {
        if ( isset( $snapshot['has_more'] ) && null !== $snapshot['has_more'] ) {
            return (bool) $snapshot['has_more'];
        }

        $per_page = max( 1, absint( $per_page ) );
        $total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
        return absint( $page ) < $total_pages;
    }

    /**
     * 查询单条帖子记录
     *
     * @param int $post_id Post ID.
     * @return object|null
     */
    public function get_post( $post_id ) {
        global $wpdb;

        $table = Qibbs_Database::posts();

        $post = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $post_id
            )
        );

        if ( ! $post ) {
            return null;
        }

        $enriched = $this->enrich_posts( array( $post ) );
        return $enriched[0] ?? null;
    }

    /**
     * 格式化单个帖子对象为丰富的数据载体
     *
     * @param object $post Raw post row.
     * @return object|null
     */
    public function format_post( $post ) {
        if ( ! $post ) {
            return null;
        }
        $enriched = $this->enrich_posts( array( $post ) );
        return $enriched[0] ?? null;
    }

    /**
     * 按 ID 列表批量拉取帖子并保持原有顺序
     *
     * @param array $post_ids Post IDs.
     * @return array
     */
    private function get_posts_by_ids( $post_ids ) {
        global $wpdb;

        $post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
        if ( empty( $post_ids ) ) {
            return array();
        }

        $table = Qibbs_Database::posts();
        $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
        $ids_sql = implode( ',', $post_ids );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE id IN ({$placeholders})
                 ORDER BY FIELD(id, {$ids_sql})",
                $post_ids
            )
        );
    }

    /**
     * 批量装配帖子的互动状态、作者、版块与媒体数据
     *
     * @param array $posts Posts array.
     * @return array
     */
    private function enrich_posts( $posts ) {
        if ( empty( $posts ) ) {
            return array();
        }

        global $wpdb;
        $current_user_id = get_current_user_id();

        $post_ids = array();
        $author_ids = array();
        $forum_ids = array();
        $all_media_ids = array();
        $post_media_map = array();

        foreach ( $posts as $post ) {
            $post_id = (int) $post->id;
            $author_id = (int) $post->author_id;
            $forum_id = (int) $post->forum_id;

            $post_ids[] = $post_id;
            $author_ids[] = $author_id;

            if ( $forum_id > 0 ) {
                $forum_ids[] = $forum_id;
            }

            $media_ids = $this->parse_media_ids( $post->media_ids );
            $post_media_map[ $post_id ] = $media_ids;
            foreach ( $media_ids as $media_id ) {
                $all_media_ids[] = $media_id;
            }
        }

        $post_ids = array_values( array_unique( $post_ids ) );
        $author_ids = array_values( array_unique( $author_ids ) );
        $forum_ids = array_values( array_unique( $forum_ids ) );
        $all_media_ids = array_values( array_unique( $all_media_ids ) );

        $authors_map = array();
        if ( ! empty( $author_ids ) ) {
            $users = get_users( array(
                'include' => $author_ids,
                'fields'  => array( 'ID', 'display_name' ),
                'count_total' => false,
            ) );
            foreach ( $users as $user ) {
                $authors_map[ (int) $user->ID ] = $user;
            }
        }

        $avatar_map = array();
        $profile_url_map = array();
        foreach ( $author_ids as $author_id ) {
            $avatar_map[ $author_id ] = get_avatar_url( $author_id, array( 'size' => 96 ) );
            $profile_url_map[ $author_id ] = $this->get_profile_url( $author_id );
        }

        $forums_map = array();
        if ( ! empty( $forum_ids ) ) {
            $forum_placeholders = implode( ',', array_fill( 0, count( $forum_ids ), '%d' ) );
            $forums = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, name, slug, icon FROM " . Qibbs_Database::forums() . " WHERE id IN ({$forum_placeholders})",
                    $forum_ids
                )
            );
            foreach ( $forums as $forum ) {
                $forums_map[ (int) $forum->id ] = $forum;
            }
        }

        $media_by_id = array();
        if ( ! empty( $all_media_ids ) ) {
            $media_placeholders = implode( ',', array_fill( 0, count( $all_media_ids ), '%d' ) );
            $media_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM " . Qibbs_Database::media() . " WHERE id IN ({$media_placeholders})",
                    $all_media_ids
                )
            );
            foreach ( $media_rows as $media_item ) {
                $media_by_id[ (int) $media_item->id ] = $media_item;
            }
        }

        $liked_map = array();
        $favorited_map = array();
        if ( $current_user_id && ! empty( $post_ids ) ) {
            $post_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

            $like_rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_id FROM " . Qibbs_Database::likes() . " WHERE user_id = %d AND post_id IN ({$post_placeholders})",
                    array_merge( array( $current_user_id ), $post_ids )
                )
            );
            foreach ( $like_rows as $liked_post_id ) {
                $liked_map[ (int) $liked_post_id ] = true;
            }

            $favorite_rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_id FROM " . Qibbs_Database::favorites() . " WHERE user_id = %d AND post_id IN ({$post_placeholders})",
                    array_merge( array( $current_user_id ), $post_ids )
                )
            );
            foreach ( $favorite_rows as $favorited_post_id ) {
                $favorited_map[ (int) $favorited_post_id ] = true;
            }
        }

        $followed_authors = array();
        if ( $current_user_id && ! empty( $author_ids ) ) {
            $author_placeholders = implode( ',', array_fill( 0, count( $author_ids ), '%d' ) );
            $follow_rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT following_id FROM " . Qibbs_Database::follows() . " WHERE follower_id = %d AND following_id IN ({$author_placeholders})",
                    array_merge( array( $current_user_id ), $author_ids )
                )
            );
            foreach ( $follow_rows as $following_id ) {
                $followed_authors[ (int) $following_id ] = true;
            }
        }

        $tags_map = array();
        if ( ! empty( $post_ids ) ) {
            $post_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
            $tag_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pt.post_id, t.id, t.name, t.slug, t.post_count
                     FROM " . Qibbs_Database::post_tags() . " pt
                     INNER JOIN " . Qibbs_Database::tags() . " t ON t.id = pt.tag_id
                     WHERE pt.post_id IN ({$post_placeholders})
                     ORDER BY t.post_count DESC, t.name ASC",
                    $post_ids
                )
            );

            foreach ( $tag_rows as $tag_row ) {
                $tag_post_id = (int) $tag_row->post_id;
                if ( ! isset( $tags_map[ $tag_post_id ] ) ) {
                    $tags_map[ $tag_post_id ] = array();
                }
                $tags_map[ $tag_post_id ][] = (object) array(
                    'id'         => (int) $tag_row->id,
                    'name'       => $tag_row->name,
                    'slug'       => $tag_row->slug,
                    'post_count' => (int) $tag_row->post_count,
                );
            }
        }

        // 批量预加载悬赏与红包数据（O(1) Map，彻底杜绝 N+1 查询）
        $bounties_map = array();
        if ( class_exists( 'Qibbs_Bounty_Service' ) && ! empty( $post_ids ) ) {
            $bounties_map = Qibbs_Bounty_Service::instance()->get_bounties_map( $post_ids );
        }

        $redpackets_map = array();
        if ( class_exists( 'Qibbs_Redpacket_Service' ) && ! empty( $post_ids ) ) {
            $redpackets_map = Qibbs_Redpacket_Service::instance()->get_redpackets_map( $post_ids );
        }

        // 批量预加载用户昵称变色道具（O(1) Map，彻底杜绝 N+1 查询）
        $name_colors_map = array();
        if ( class_exists( 'Qibbs_Prop_Service' ) && ! empty( $author_ids ) ) {
            $name_colors_map = Qibbs_Prop_Service::instance()->get_users_name_color_map( $author_ids );
        }

        $now_str = current_time( 'mysql' );

        foreach ( $posts as &$post ) {
            $post_id = (int) $post->id;
            $author_id = (int) $post->author_id;
            $author = isset( $authors_map[ $author_id ] ) ? $authors_map[ $author_id ] : null;
            $post->post_type = function_exists( 'qibbs_normalize_post_type' )
                ? qibbs_normalize_post_type( isset( $post->post_type ) ? (string) $post->post_type : '' )
                : 'discussion';
            $post->post_type_label = function_exists( 'qibbs_get_post_type_label' )
                ? qibbs_get_post_type_label( $post->post_type )
                : __( '普通帖', 'qibbs-community' );
            $post->discussion_status = function_exists( 'qibbs_normalize_discussion_status' )
                ? qibbs_normalize_discussion_status(
                    isset( $post->discussion_status ) ? (string) $post->discussion_status : '',
                    ! empty( $post->is_resolved ) ? 1 : 0
                )
                : ( ! empty( $post->is_resolved ) ? 'resolved' : 'discussion' );

            $post->is_active_promoted = ( ! empty( $post->is_promoted ) && ( empty( $post->promoted_until ) || $post->promoted_until > $now_str ) );

            $post->author = array(
                'id'           => $author_id,
                'cuid'         => function_exists( 'qibbs_get_user_cuid' ) ? qibbs_get_user_cuid( $author_id ) : $author_id,
                'cuid_badge'   => function_exists( 'qibbs_render_cuid_badge' ) ? qibbs_render_cuid_badge( $author_id ) : '',
                'display_name' => $author ? $author->display_name : __( '未知用户', 'qibbs-community' ),
                'name_color'   => isset( $name_colors_map[ $author_id ] ) ? $name_colors_map[ $author_id ] : '',
                'avatar'       => isset( $avatar_map[ $author_id ] ) ? $avatar_map[ $author_id ] : get_avatar_url( $author_id, array( 'size' => 96 ) ),
                'profile_url'  => isset( $profile_url_map[ $author_id ] ) ? $profile_url_map[ $author_id ] : $this->get_profile_url( $author_id ),
            );

            $post_media_ids = isset( $post_media_map[ $post_id ] ) ? $post_media_map[ $post_id ] : array();
            $post_media = array();
            foreach ( $post_media_ids as $media_id ) {
                if ( isset( $media_by_id[ $media_id ] ) ) {
                    $media_item = $media_by_id[ $media_id ];
                    // 若富文本正文中已嵌入该媒体 URL，则不在底部独立媒体网格中重复渲染
                    if ( ! empty( $media_item->file_url ) && ! empty( $post->content ) && false !== strpos( $post->content, $media_item->file_url ) ) {
                        continue;
                    }
                    $post_media[] = $media_item;
                }
            }
            $post->media = $post_media;

            // 读取所属版块详细信息
            if ( (int) $post->forum_id > 0 && isset( $forums_map[ (int) $post->forum_id ] ) ) {
                $post->forum = $forums_map[ (int) $post->forum_id ];
            } else {
                $post->forum = null;
            }

            // 计算人类可读的相对时间与精准时间
            $post->time_ago = qibbs_human_time_diff( $post->created_at );
            $post->created_at_formatted = function_exists( 'qibbs_format_exact_datetime' ) ? qibbs_format_exact_datetime( $post->created_at ) : (string) $post->created_at;

            // 生成内容摘要片段
            $post->excerpt = $this->get_excerpt( $post->content, 200 );

            // 批量校验当前用户的点赞与收藏状态
            if ( $current_user_id ) {
                $post->user_liked = isset( $liked_map[ $post_id ] );
                $post->user_favorited = isset( $favorited_map[ $post_id ] );
            } else {
                $post->user_liked = false;
                $post->user_favorited = false;
            }

            $post->author_followed = $current_user_id ? isset( $followed_authors[ $author_id ] ) : false;
            $post->tags = isset( $tags_map[ $post_id ] ) ? $tags_map[ $post_id ] : array();
            $post->bounty = isset( $bounties_map[ $post_id ] ) ? $bounties_map[ $post_id ] : null;
            $post->redpacket = isset( $redpackets_map[ $post_id ] ) ? $redpackets_map[ $post_id ] : null;

            // 生成帖子前台永久链接
            $post->permalink = $this->get_post_permalink( $post_id );
        }

        /**
         * 丰富后的帖子数据过滤器
         *
         * @since 1.0.0
         * @param array $posts Enriched posts.
         */
        return apply_filters( 'qibbs_enriched_posts', $posts );
    }

    /**
     * 解析逗号分隔的媒体附件 ID 列表
     *
     * @param string $media_ids Media IDs.
     * @return array
     */
    private function parse_media_ids( $media_ids ) {
        if ( empty( $media_ids ) ) {
            return array();
        }

        $ids = array_map( 'absint', explode( ',', (string) $media_ids ) );
        $ids = array_filter( $ids );
        return array_values( array_unique( $ids ) );
    }

    /**
     * 查询帖子关联的媒体附件列表
     *
     * @param int    $post_id   Post ID.
     * @param string $media_ids Comma-separated media IDs.
     * @return array
     */
    private function get_post_media( $post_id, $media_ids ) {
        if ( empty( $media_ids ) ) {
            return array();
        }

        global $wpdb;
        $table = Qibbs_Database::media();

        $ids = array_map( 'intval', explode( ',', $media_ids ) );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $media = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id IN ({$placeholders})",
                $ids
            )
        );

        return $media;
    }

    /**
     * 读取版块基础信息
     *
     * @param int $forum_id Forum ID.
     * @return object|null
     */
    private function get_forum_info( $forum_id ) {
        global $wpdb;
        $table = Qibbs_Database::forums();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, name, slug, icon FROM {$table} WHERE id = %d",
                $forum_id
            )
        );
    }

    /**
     * 提取正文字符串摘要
     *
     * @param string $content  Content.
     * @param int    $length   Max length.
     * @return string
     */
    private function get_excerpt( $content, $length = 200 ) {
        $content = wp_strip_all_tags( $content );
        if ( mb_strlen( $content ) <= $length ) {
            return $content;
        }
        return mb_substr( $content, 0, $length ) . '...';
    }

    /**
     * 检查单用户是否已点赞帖子
     *
     * @param int $user_id User ID.
     * @param int $post_id Post ID.
     * @return bool
     */
    private function user_has_liked( $user_id, $post_id ) {
        global $wpdb;
        $table = Qibbs_Database::likes();

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d AND post_id = %d",
                $user_id,
                $post_id
            )
        );
    }

    /**
     * 检查单用户是否已收藏帖子
     *
     * @param int $user_id User ID.
     * @param int $post_id Post ID.
     * @return bool
     */
    private function user_has_favorited( $user_id, $post_id ) {
        global $wpdb;
        $table = Qibbs_Database::favorites();

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE user_id = %d AND post_id = %d",
                $user_id,
                $post_id
            )
        );
    }

    /**
     * 获取用户个人主页 URL
     *
     * @param int $user_id User ID.
     * @return string
     */
    private function get_profile_url( $user_id ) {
        return qibbs_get_profile_url( $user_id );
    }

    /**
     * 获取帖子详情永久链接
     *
     * @param int $post_id Post ID.
     * @return string
     */
    private function get_post_permalink( $post_id ) {
        return qibbs_get_post_url( $post_id );
    }

    /**
     * AJAX 接口：分页加载动态流
     */
    public function ajax_load_feed() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( function_exists( 'qibbs_require_login' ) && qibbs_require_login() && ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        if ( ! is_user_logged_in() && function_exists( 'qibbs_rate_limit_by_ip' ) && ! qibbs_rate_limit_by_ip( 'ajax_load_feed', 90, 60 ) ) {
            wp_send_json_error( array( 'message' => __( '请求过于频繁，请稍后再试', 'qibbs-community' ) ), 429 );
        }

        $feed_type = isset( $_POST['feed_type'] ) ? sanitize_text_field( wp_unslash( $_POST['feed_type'] ) ) : 'global';
        $page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $filter_type = isset( $_POST['filter_type'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_type'] ) ) : '';
        $filter_id = isset( $_POST['filter_id'] ) ? absint( $_POST['filter_id'] ) : 0;
        $filter_name = isset( $_POST['filter_name'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_name'] ) ) : '';
        $profile_user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        $hot_window = isset( $_POST['hot_window'] ) ? absint( $_POST['hot_window'] ) : absint( qibbs_get_option( 'hot_default_window', 7 ) );
        if ( ! in_array( $hot_window, array( 0, 1, 7, 30 ), true ) ) {
            $hot_window = 7;
        }

        if ( 'tag' === $filter_type && ! $filter_id && $filter_name ) {
            global $wpdb;
            $tag_cache_key = 'qibbs_tag_name_id_' . md5( strtolower( $filter_name ) );
            $cached_tag_id = get_transient( $tag_cache_key );

            if ( false !== $cached_tag_id ) {
                $filter_id = absint( $cached_tag_id );
            } else {
                $tags_table = Qibbs_Database::tags();
                $tag = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id FROM {$tags_table} WHERE name = %s",
                    $filter_name
                ) );
                $filter_id = $tag ? absint( $tag->id ) : 0;
                set_transient( $tag_cache_key, $filter_id, 5 * MINUTE_IN_SECONDS );
            }
        }

        $args = array(
            'page'          => $page,
            'query'         => isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '',
            'author_query'  => isset( $_POST['author_query'] ) ? sanitize_text_field( wp_unslash( $_POST['author_query'] ) ) : '',
            'tag_query'     => isset( $_POST['tag_query'] ) ? sanitize_text_field( wp_unslash( $_POST['tag_query'] ) ) : '',
            'post_type'     => isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '',
            'resolved'      => isset( $_POST['resolved'] ) ? sanitize_key( wp_unslash( $_POST['resolved'] ) ) : '',
            'sort'          => isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( $_POST['sort'] ) ) : '',
            'featured_only' => ! empty( $_POST['featured_only'] ),
        );

        // 个人主页动态流：user_id 优先
        if ( $profile_user_id ) {
            switch ( $feed_type ) {
                case 'favorites':
                    $result = $this->get_user_favorites( $profile_user_id, $args );
                    break;
                case 'likes':
                    $result = $this->get_user_likes( $profile_user_id, $args );
                    break;
                default: // 'posts'
                    $result = $this->get_user_feed( $profile_user_id, $args );
                    break;
            }
        } else {
            // 社区标准动态流类型分发
            switch ( $feed_type ) {
                case 'following':
                    $user_id = get_current_user_id();
                    if ( ! $user_id ) {
                        wp_send_json_error( array(
                            'message' => __( '请先登录', 'qibbs-community' ),
                        ) );
                    }
                    $result = $this->get_following_feed( $user_id, $args );
                    break;

                case 'hot':
                    $args['hot_window'] = $hot_window;
                    $result = $this->get_hot_feed( $args );
                    break;

                case 'featured':
                    $result = $this->get_featured_feed( $args );
                    break;

                case 'voting':
                    $result = $this->get_voting_feed( $args );
                    break;

                case 'redpacket':
                    $result = $this->get_redpacket_feed( $args );
                    break;

                case 'bounty':
                    $result = $this->get_bounty_feed( $args );
                    break;

                case 'search':
                    $args['filter_type'] = $filter_type;
                    $args['filter_id']   = $filter_id;
                    $result = $this->search_feed( $args );
                    break;

                case 'filter':
                    // 处理各类筛选模式
                    if ( $filter_type === 'forum' && $filter_id ) {
                        $result = $this->get_forum_feed( $filter_id, $args );
                    } elseif ( $filter_type === 'tag' ) {
                        $tag_id = $filter_id;

                        if ( $tag_id ) {
                            $result = $this->get_tag_feed( $tag_id, $args );
                        } else {
                            $result = $this->get_global_feed( $args );
                        }
                    } elseif ( $filter_type === 'category' && $filter_id ) {
                        $result = $this->get_category_feed( $filter_id, $args );
                    } else {
                        $result = $this->get_global_feed( $args );
                    }
                    break;

                default:
                    $result = $this->get_global_feed( $args );
                    break;
            }
        }

        // 渲染卡片 HTML 输出
        ob_start();
        foreach ( $result['posts'] as $post ) {
            $this->render_feed_card( $post );
        }
        $html = ob_get_clean();
        $has_more = isset( $result['has_more'] )
            ? (bool) $result['has_more']
            : ( (int) $result['page'] < (int) $result['total_pages'] );

        wp_send_json_success( array(
            'html'        => $html,
            'total'       => $result['total'],
            'page'        => $result['page'],
            'total_pages' => $result['total_pages'],
            'has_more'    => $has_more,
        ) );
    }

    /**
     * 渲染单个动态卡片视图
     *
     * @param object $post Post object.
     */
    public function render_feed_card( $post ) {
        $template = QIBBS_PLUGIN_DIR . 'templates/feed-card.php';

        if ( file_exists( $template ) ) {
            include $template;
        } else {
            // 兜底内联模板渲染
            ?>
            <div class="qibbs-feed-card" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                <div class="qibbs-card-header">
                    <a href="<?php echo esc_url( $post->author['profile_url'] ); ?>" class="qibbs-avatar">
                        <img src="<?php echo esc_url( $post->author['avatar'] ); ?>" alt="<?php echo esc_attr( $post->author['display_name'] ); ?>">
                    </a>
                    <div class="qibbs-card-meta">
                        <div class="qibbs-author-row">
                            <a href="<?php echo esc_url( $post->author['profile_url'] ); ?>" class="qibbs-author-name">
                                <?php echo esc_html( $post->author['display_name'] ); ?>
                            </a>
                            <?php if ( ! empty( $post->author['cuid_badge'] ) ) : ?>
                                <?php echo $post->author['cuid_badge']; ?>
                            <?php endif; ?>
                        </div>
                        <time class="qibbs-time" datetime="<?php echo esc_attr( $post->created_at ); ?>" title="<?php echo esc_attr( function_exists( 'qibbs_format_exact_datetime' ) ? qibbs_format_exact_datetime( $post->created_at ) : (string) $post->created_at ); ?>">
                            <?php echo esc_html( $post->time_ago ); ?>
                        </time>
                    </div>
                </div>
                
                <div class="qibbs-card-content">
                    <p><?php echo wp_kses_post( $post->content ); ?></p>
                    
                    <?php if ( ! empty( $post->media ) ) : ?>
                        <div class="qibbs-media-grid">
                            <?php foreach ( $post->media as $media ) : ?>
                                <img src="<?php echo esc_url( $media->file_url ); ?>" alt="">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="qibbs-card-actions">
                    <button class="qibbs-action-btn qibbs-like-btn <?php echo $post->user_liked ? 'active' : ''; ?>" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                        <span class="icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg></span>
                        <span class="count"><?php echo esc_html( $post->like_count ); ?></span>
                    </button>
                    <button class="qibbs-action-btn qibbs-comment-btn" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                        <span class="icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg></span>
                        <span class="count"><?php echo esc_html( $post->comment_count ); ?></span>
                    </button>
                    <button class="qibbs-action-btn qibbs-favorite-btn <?php echo $post->user_favorited ? 'active' : ''; ?>" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                        <span class="icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg></span>
                    </button>
                    <a href="<?php echo esc_url( $post->permalink ); ?>" class="qibbs-action-btn qibbs-view-btn">
                        <span class="icon"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg></span>
                    </a>
                </div>
            </div>
            <?php
        }
    }
}
