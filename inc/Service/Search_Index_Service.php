<?php
/**
 * 全文搜索索引服务
 *
 * 构建并维护独立搜索索引表（wp_qibbs_search_index），支持全文检索
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Search_Index_Service {

    /**
     * WP-Cron 异步处理队列中搜索索引更新的钩子
     *
     * @var string
     */
    const CRON_HOOK = 'qibbs_search_index_sync_event';

    /**
     * 待重建索引脏数据帖子的 Option 键名前缀
     *
     * @var string
     */
    const DIRTY_POST_OPTION_PREFIX = 'qibbs_search_index_dirty_post_';

    /**
     * 避免同请求重复检查任务调度的请求级标记
     *
     * @var bool
     */
    private static $queue_schedule_checked = false;

    /**
     * 搜索索引表存在状态缓存标记
     *
     * @var bool|null
     */
    private static $table_exists = null;

    /**
     * FULLTEXT 全文检索支持状态缓存标记
     *
     * @var bool|null
     */
    private static $fulltext_supported = null;

    /**
     * 同步全量重建微社区独立全文搜索索引
     *
     * 适用于数据集规模适中或管理员手动即时重建场景
     * 支持后台按需手动触发重建
     *
     * @return array<string,mixed>
     */
    public function rebuild_index() {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return array(
                'success' => false,
                'indexed' => 0,
                'message' => __( '搜索索引表不存在，请先重新启用插件。', 'qibbs-community' ),
            );
        }

        $search_table = Qibbs_Database::search_index();
        $posts_table  = Qibbs_Database::posts();

        $cleared = $wpdb->query( $wpdb->prepare( "DELETE FROM {$search_table} WHERE 1 = %d", 1 ) );
        if ( false === $cleared ) {
            return array(
                'success' => false,
                'indexed' => 0,
                'message' => __( '清空旧搜索索引失败，请稍后重试。', 'qibbs-community' ),
            );
        }

        $post_ids = $wpdb->get_col(
            "SELECT id FROM {$posts_table}
             WHERE status = 'publish'
             ORDER BY id ASC"
        );

        $indexed = 0;
        foreach ( (array) $post_ids as $post_id ) {
            if ( $this->sync_post( (int) $post_id ) ) {
                $indexed++;
            }
        }

        $this->clear_sync_queue();
        $this->clear_rebuild_state();

        return array(
            'success' => true,
            'indexed' => (int) $indexed,
            'message' => sprintf(
                /* translators: %d: indexed post count */
                __( '搜索索引已重建，共同步 %d 条已发布内容。', 'qibbs-community' ),
                (int) $indexed
            ),
        );
    }

    /**
     * 基于独立搜索索引执行社区内容全文检索
     *
     * 索引不可用时安全返回 null 并平滑降级为常规 SQL 模糊查询
     * 确保前台搜索功能高可用
     *
     * @param array $args Query arguments.
     * @return array|null
     */
    public function search_feed( $args = array() ) {
        global $wpdb;

        if ( ! $this->can_query_index() ) {
            return null;
        }

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
        if ( '' === $args['status'] ) {
            $args['status'] = 'publish';
        }

        $args['query']        = $this->normalize_search_text( $args['query'], true );
        $args['author_query'] = $this->normalize_search_text( $args['author_query'], true );
        $args['tag_query']    = $this->normalize_search_text( $args['tag_query'], true );
        $args['sort']         = $this->normalize_sort( $args['sort'] );
        $args['filter_type']  = sanitize_key( (string) $args['filter_type'] );
        $args['filter_id']    = absint( $args['filter_id'] );
        $args['featured_only'] = ! empty( $args['featured_only'] );

        $raw_post_type = sanitize_key( (string) $args['post_type'] );
        $args['post_type'] = ( '' === $raw_post_type || 'all' === $raw_post_type )
            ? ''
            : ( function_exists( 'qibbs_normalize_post_type' ) ? qibbs_normalize_post_type( $raw_post_type ) : 'discussion' );

        $raw_resolved = sanitize_key( (string) $args['resolved'] );
        $args['resolved'] = in_array( $raw_resolved, array( 'resolved', 'open' ), true ) ? $raw_resolved : '';

        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $query_limit = $args['per_page'] + 1;
        $table = Qibbs_Database::search_index();
        $post_tags_table = Qibbs_Database::post_tags();

        $where = array( 'si.status = %s' );
        $params = array( $args['status'] );

        if ( 'forum' === $args['filter_type'] && $args['filter_id'] ) {
            $where[]  = 'si.forum_id = %d';
            $params[] = $args['filter_id'];
        } elseif ( 'category' === $args['filter_type'] && $args['filter_id'] ) {
            $where[]  = 'si.source_category = %d';
            $params[] = $args['filter_id'];
        } elseif ( 'tag' === $args['filter_type'] && $args['filter_id'] ) {
            $where[]  = "EXISTS (SELECT 1 FROM {$post_tags_table} pt WHERE pt.post_id = si.post_id AND pt.tag_id = %d)";
            $params[] = $args['filter_id'];
        }

        if ( '' !== $args['post_type'] ) {
            $where[]  = 'si.post_type = %s';
            $params[] = $args['post_type'];
        }

        if ( 'resolved' === $args['resolved'] ) {
            if ( '' === $args['post_type'] ) {
                $where[]  = 'si.post_type = %s';
                $params[] = 'question';
            }
            $where[] = 'si.is_resolved = 1';
        } elseif ( 'open' === $args['resolved'] ) {
            if ( '' === $args['post_type'] ) {
                $where[]  = 'si.post_type = %s';
                $params[] = 'question';
            }
            $where[] = 'si.is_resolved = 0';
        }

        if ( $args['featured_only'] ) {
            $where[]  = '(si.is_featured = 1 AND (si.featured_until IS NULL OR si.featured_until > %s))';
            $params[] = current_time( 'mysql' );
        }

        if ( '' !== $args['author_query'] ) {
            $author_like = '%' . $wpdb->esc_like( $args['author_query'] ) . '%';
            $where[]     = '(si.author_name LIKE %s OR si.author_login LIKE %s OR si.author_nicename LIKE %s)';
            $params[]    = $author_like;
            $params[]    = $author_like;
            $params[]    = $author_like;
        }

        if ( '' !== $args['tag_query'] ) {
            $tag_like  = '%' . $wpdb->esc_like( $args['tag_query'] ) . '%';
            $where[]   = '(si.tag_names LIKE %s OR si.tag_slugs LIKE %s)';
            $params[]  = $tag_like;
            $params[]  = $tag_like;
        }

        if ( '' !== $args['query'] ) {
            $this->append_text_search_condition( 'si.search_text', $args['query'], $where, $params );
        }

        $sql = "SELECT si.post_id FROM {$table} si";
        $sql .= ' WHERE ' . implode( ' AND ', $where );
        $sql .= ' ORDER BY ' . $this->get_sort_sql( $args['sort'] ) . ' LIMIT %d OFFSET %d';

        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                $sql,
                array_merge( $params, array( $query_limit, $offset ) )
            )
        );

        list( $post_ids, $has_more ) = $this->trim_overflow_results( $post_ids, $args['per_page'] );
        $total = $this->build_lightweight_total( $args['page'], $args['per_page'], count( $post_ids ), $has_more );

        if ( empty( $post_ids ) && 1 === $args['page'] ) {
            $total = 0;
        }

        return array(
            'post_ids'    => array_map( 'absint', $post_ids ),
            'total'       => (int) $total,
            'page'        => $args['page'],
            'per_page'    => $args['per_page'],
            'total_pages' => $total > 0 ? (int) ceil( $total / $args['per_page'] ) : 0,
            'has_more'    => $has_more,
        );
    }

    /**
     * 基于搜索索引提取前台搜索补全建议
     *
     * 建议不可用时降级为传统查询模式
     *
     * @param string $query           Search query.
     * @param int    $display_limit   Result limit.
     * @param int    $candidate_limit Candidate limit before local ranking.
     * @return array|null
     */
    public function search_suggestions( $query, $display_limit = 10, $candidate_limit = 24 ) {
        global $wpdb;

        if ( ! $this->can_query_index() ) {
            return null;
        }

        $query = $this->normalize_search_text( $query, true );
        if ( mb_strlen( $query ) < 2 ) {
            return array();
        }

        $display_limit = max( 1, min( 20, absint( $display_limit ) ) );
        $candidate_limit = max( $display_limit, min( 100, absint( $candidate_limit ) ) );
        $table = Qibbs_Database::search_index();
        $query_like = '%' . $wpdb->esc_like( $query ) . '%';
        $boolean_query = $this->build_boolean_query( $query );

        $select = "SELECT si.post_id, si.author_id, si.forum_id, si.post_type, si.content_plain,
                          si.author_name, si.author_login, si.author_nicename,
                          si.forum_name, si.forum_slug, si.tag_names, si.tag_slugs,
                          si.is_pinned, si.created_at";
        $params = array();

        if ( $this->supports_fulltext() && '' !== $boolean_query ) {
            $select .= ', MATCH(search_text) AGAINST (%s IN BOOLEAN MODE) AS relevance';
            $params[] = $boolean_query;
            $where = " WHERE si.status = 'publish' AND (MATCH(search_text) AGAINST (%s IN BOOLEAN MODE) OR si.search_text LIKE %s)";
            $params[] = $boolean_query;
            $params[] = $query_like;
        } else {
            $select .= ', 0 AS relevance';
            $where = " WHERE si.status = 'publish' AND si.search_text LIKE %s";
            $params[] = $query_like;
        }

        $sql = "{$select} FROM {$table} si{$where}
                ORDER BY si.is_pinned DESC, relevance DESC, si.created_at DESC
                LIMIT %d";
        $params[] = $candidate_limit;
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        if ( empty( $rows ) ) {
            return array();
        }

        $scored_rows = array();
        foreach ( (array) $rows as $row ) {
            $tag_names = $this->explode_search_list( $row->tag_names );
            $tag_slugs = $this->explode_search_list( $row->tag_slugs );
            $match_types = array();
            $score = 0;

            $tag_score = $this->calculate_match_score( $query, array_merge( $tag_names, $tag_slugs ), 124, 140, 158 );
            if ( $tag_score > 0 ) {
                $score = max( $score, $tag_score );
                $match_types[] = 'tag';
            }

            $forum_score = $this->calculate_match_score( $query, array( $row->forum_name, $row->forum_slug ), 120, 136, 152 );
            if ( $forum_score > 0 ) {
                $score = max( $score, $forum_score );
                $match_types[] = 'forum';
            }

            $author_score = $this->calculate_match_score( $query, array( $row->author_name, $row->author_login, $row->author_nicename ), 112, 126, 144 );
            if ( $author_score > 0 ) {
                $score = max( $score, $author_score );
                $match_types[] = 'author';
            }

            $content_score = $this->calculate_match_score( $query, array( $row->content_plain ), 72, 88, 104 );
            if ( $content_score > 0 || empty( $match_types ) ) {
                $score = max( $score, $content_score );
                if ( empty( $match_types ) || $content_score > 0 ) {
                    $match_types[] = 'content';
                }
            }

            $scored_rows[] = array(
                'row'         => $row,
                'score'       => max( $score, (int) round( (float) $row->relevance * 100 ) ),
                'match_types' => array_values( array_unique( $match_types ) ),
            );
        }

        usort(
            $scored_rows,
            static function( $left, $right ) {
                if ( (int) $left['score'] !== (int) $right['score'] ) {
                    return (int) $right['score'] <=> (int) $left['score'];
                }

                $left_pinned = ! empty( $left['row']->is_pinned ) ? 1 : 0;
                $right_pinned = ! empty( $right['row']->is_pinned ) ? 1 : 0;
                if ( $left_pinned !== $right_pinned ) {
                    return $right_pinned <=> $left_pinned;
                }

                return strcmp( (string) $right['row']->created_at, (string) $left['row']->created_at );
            }
        );

        $results = array();
        foreach ( array_slice( $scored_rows, 0, $display_limit ) as $item ) {
            $row = $item['row'];
            $tag_names = array_slice( $this->explode_search_list( $row->tag_names ), 0, 3 );
            $results[] = array(
                'id'              => (int) $row->post_id,
                'author'          => esc_html( '' !== (string) $row->author_name ? (string) $row->author_name : __( '未知用户', 'qibbs-community' ) ),
                'author_id'       => (int) $row->author_id,
                'forum'           => esc_html( (string) $row->forum_name ),
                'forum_id'        => (int) $row->forum_id,
                'content'         => $this->build_excerpt( $row->content_plain, $query ),
                'link'            => qibbs_get_post_url( (int) $row->post_id ),
                'post_type'       => function_exists( 'qibbs_normalize_post_type' ) ? qibbs_normalize_post_type( $row->post_type ) : 'discussion',
                'post_type_label' => function_exists( 'qibbs_get_post_type_label' ) ? qibbs_get_post_type_label( $row->post_type ) : __( '普通帖', 'qibbs-community' ),
                'match_types'     => $item['match_types'],
                'match_label'     => esc_html( $this->get_match_label( $item['match_types'] ) ),
                'tags'            => array_map( 'esc_html', $tag_names ),
            );
        }

        return $results;
    }

    /**
     * 将单个帖子数据同步更新至搜索索引表
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public function sync_post( $post_id ) {
        global $wpdb;

        $post_id = absint( $post_id );
        if ( ! $post_id || ! $this->table_exists() ) {
            return false;
        }

        $posts_table = Qibbs_Database::posts();
        $post = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, author_id, forum_id, source_category, status, post_type, discussion_status,
                        is_resolved, is_featured, is_pinned, like_count, comment_count, view_count,
                        featured_until, content, created_at, updated_at
                 FROM {$posts_table}
                 WHERE id = %d
                 LIMIT 1",
                $post_id
            )
        );

        if ( ! $post || 'publish' !== (string) $post->status ) {
            return $this->delete_post( $post_id );
        }

        $author = get_userdata( (int) $post->author_id );
        $forum = null;
        if ( ! empty( $post->forum_id ) ) {
            $forum = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, name, slug FROM " . Qibbs_Database::forums() . ' WHERE id = %d LIMIT 1',
                    (int) $post->forum_id
                )
            );
        }

        $tag_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.name, t.slug
                 FROM " . Qibbs_Database::post_tags() . ' pt
                 INNER JOIN ' . Qibbs_Database::tags() . ' t ON t.id = pt.tag_id
                 WHERE pt.post_id = %d
                 ORDER BY t.post_count DESC, t.id DESC',
                $post_id
            )
        );

        $tag_names = array();
        $tag_slugs = array();
        foreach ( (array) $tag_rows as $tag_row ) {
            if ( '' !== (string) $tag_row->name ) {
                $tag_names[] = (string) $tag_row->name;
            }
            if ( '' !== (string) $tag_row->slug ) {
                $tag_slugs[] = (string) $tag_row->slug;
            }
        }

        $content_plain = $this->normalize_document_text( $post->content );
        $author_name = $author ? (string) $author->display_name : '';
        $author_login = $author ? (string) $author->user_login : '';
        $author_nicename = $author ? (string) $author->user_nicename : '';
        $forum_name = $forum ? (string) $forum->name : '';
        $forum_slug = $forum ? (string) $forum->slug : '';
        $tag_names_text = implode( "\n", array_values( array_unique( $tag_names ) ) );
        $tag_slugs_text = implode( "\n", array_values( array_unique( $tag_slugs ) ) );
        $search_text = $this->build_search_document(
            array(
                $content_plain,
                $author_name,
                $author_login,
                $author_nicename,
                $forum_name,
                $forum_slug,
                $tag_names_text,
                $tag_slugs_text,
            )
        );

        $result = $wpdb->replace(
            Qibbs_Database::search_index(),
            array(
                'post_id'           => (int) $post->id,
                'author_id'         => (int) $post->author_id,
                'forum_id'          => (int) $post->forum_id,
                'source_category'   => (int) $post->source_category,
                'status'            => (string) $post->status,
                'post_type'         => (string) $post->post_type,
                'discussion_status' => (string) $post->discussion_status,
                'is_resolved'       => (int) $post->is_resolved,
                'is_featured'       => (int) $post->is_featured,
                'is_pinned'         => (int) $post->is_pinned,
                'like_count'        => (int) $post->like_count,
                'comment_count'     => (int) $post->comment_count,
                'view_count'        => (int) $post->view_count,
                'featured_until'    => ! empty( $post->featured_until ) ? (string) $post->featured_until : null,
                'created_at'        => ! empty( $post->created_at ) ? (string) $post->created_at : current_time( 'mysql' ),
                'updated_at'        => ! empty( $post->updated_at ) ? (string) $post->updated_at : current_time( 'mysql' ),
                'author_name'       => $author_name,
                'author_login'      => $author_login,
                'author_nicename'   => $author_nicename,
                'forum_name'        => $forum_name,
                'forum_slug'        => $forum_slug,
                'tag_names'         => $tag_names_text,
                'tag_slugs'         => $tag_slugs_text,
                'content_plain'     => $content_plain,
                'search_text'       => $search_text,
            ),
            array(
                '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d',
                '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s',
            )
        );

        return false !== $result;
    }

    /**
     * 将单个帖子推入异步索引更新队列
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public function queue_post_sync( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id || ! $this->table_exists() ) {
            return false;
        }

        update_option(
            self::DIRTY_POST_OPTION_PREFIX . $post_id,
            (string) ( function_exists( 'qibbs_current_timestamp' ) ? qibbs_current_timestamp() : time() ),
            false
        );

        $this->schedule_sync_queue();
        return true;
    }

    /**
     * 从搜索索引表中彻底删除单个帖子记录
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public function delete_post( $post_id ) {
        global $wpdb;

        $post_id = absint( $post_id );
        if ( ! $post_id || ! $this->table_exists() ) {
            return false;
        }

        delete_option( self::DIRTY_POST_OPTION_PREFIX . $post_id );

        $deleted = $wpdb->delete(
            Qibbs_Database::search_index(),
            array( 'post_id' => $post_id ),
            array( '%d' )
        );

        return false !== $deleted;
    }

    /**
     * 帖子点赞数变动后同步搜索索引
     *
     * @param int  $user_id User ID.
     * @param int  $post_id Post ID.
     * @param bool $liked   Like state.
     * @return void
     */
    public function sync_post_from_like( $user_id, $post_id, $liked ) {
        $this->queue_post_sync( $post_id );
    }

    /**
     * 帖子评论数变动后同步搜索索引
     *
     * @param int $comment_id Comment ID.
     * @param int $user_id    User ID.
     * @param int $post_id    Post ID.
     * @return void
     */
    public function sync_post_from_comment( $comment_id, $user_id, $post_id ) {
        $this->queue_post_sync( $post_id );
    }

    /**
     * 最佳答案采纳后同步搜索索引
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function sync_post_from_best_answer( $post_id ) {
        $this->queue_post_sync( $post_id );
    }

    /**
     * 帖子加精状态变动后同步搜索索引
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function sync_post_from_feature( $post_id ) {
        $this->queue_post_sync( $post_id );
    }

    /**
     * 工单转公开贴后同步搜索索引
     *
     * @param int $ticket_id Ticket ID.
     * @param int $post_id   Post ID.
     * @param int $forum_id  Forum ID.
     * @return void
     */
    public function sync_post_from_ticket_publish( $ticket_id, $post_id, $forum_id ) {
        $this->queue_post_sync( $post_id );
    }

    /**
     * 帖子编辑变更后将索引同步推入队列
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function queue_post_sync_from_post_event( $post_id ) {
        $this->queue_post_sync( $post_id );
    }

    /**
     * 批量消费并处理待同步的搜索索引队列
     *
     * @return void
     */
    public function process_sync_queue() {
        global $wpdb;

        if ( ! $this->table_exists() ) {
            return;
        }

        $batch_size = max( 1, min( 100, absint( apply_filters( 'qibbs_search_index_sync_batch_size', 20 ) ) ) );
        $options_table = $wpdb->options;
        $like = $wpdb->esc_like( self::DIRTY_POST_OPTION_PREFIX ) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value
                 FROM {$options_table}
                 WHERE option_name LIKE %s
                 ORDER BY option_id ASC
                 LIMIT %d",
                $like,
                $batch_size
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            self::$queue_schedule_checked = false;
            return;
        }

        foreach ( (array) $rows as $row ) {
            $option_name = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
            $option_value = isset( $row['option_value'] ) ? (string) $row['option_value'] : '';
            $post_id = absint( str_replace( self::DIRTY_POST_OPTION_PREFIX, '', $option_name ) );

            if ( $post_id > 0 ) {
                $this->sync_post( $post_id );
            }

            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$options_table}
                     WHERE option_name = %s AND option_value = %s",
                    $option_name,
                    $option_value
                )
            );
        }

        self::$queue_schedule_checked = false;

        $remaining = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$options_table} WHERE option_name LIKE %s",
                $like
            )
        );

        if ( $remaining > 0 ) {
            $this->schedule_sync_queue( 5 );
        }
    }

    /**
     * 检查搜索索引数据表是否存在
     *
     * @return bool
     */
    private function table_exists() {
        if ( null !== self::$table_exists ) {
            return self::$table_exists;
        }

        global $wpdb;
        $table = Qibbs_Database::search_index();
        self::$table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
        return self::$table_exists;
    }

    /**
     * 检查搜索索引是否已就绪可供查询
     *
     * @return bool
     */
    private function can_query_index() {
        return $this->table_exists() && ! $this->is_rebuild_pending();
    }

    /**
     * 检查索引是否处于等待手动重建状态
     *
     * @return bool
     */
    private function is_rebuild_pending() {
        return ! empty( get_option( 'qibbs_search_index_rebuild_pending', 0 ) );
    }

    /**
     * 清除历史遗留的重建标记
     *
     * @return void
     */
    private function clear_rebuild_state() {
        delete_option( 'qibbs_search_index_rebuild_pending' );
        delete_option( 'qibbs_search_index_rebuild_cursor' );
    }

    /**
     * 触发队列消费定时任务调度
     *
     * @param int $delay Delay in seconds.
     * @return void
     */
    private function schedule_sync_queue( $delay = 5 ) {
        if ( self::$queue_schedule_checked || ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
            return;
        }

        self::$queue_schedule_checked = true;
        $delay = max( 1, absint( apply_filters( 'qibbs_search_index_sync_delay', $delay ) ) );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
        }
    }

    /**
     * 清除队列中脏数据标记
     *
     * @return void
     */
    private function clear_sync_queue() {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::DIRTY_POST_OPTION_PREFIX ) . '%'
            )
        );

        self::$queue_schedule_checked = false;
    }

    /**
     * 检查搜索索引表是否已成功建立 FULLTEXT 索引键
     *
     * @return bool
     */
    private function supports_fulltext() {
        if ( null !== self::$fulltext_supported ) {
            return self::$fulltext_supported;
        }

        if ( ! $this->table_exists() ) {
            self::$fulltext_supported = false;
            return false;
        }

        global $wpdb;
        $rows = $wpdb->get_results( 'SHOW INDEX FROM ' . Qibbs_Database::search_index(), ARRAY_A );
        foreach ( (array) $rows as $row ) {
            if ( isset( $row['Key_name'], $row['Index_type'] ) && 'ft_search_text' === (string) $row['Key_name'] && 'FULLTEXT' === strtoupper( (string) $row['Index_type'] ) ) {
                self::$fulltext_supported = true;
                return true;
            }
        }

        self::$fulltext_supported = false;
        return false;
    }

    /**
     * 针对聚合全文列拼接 MATCH AGAINST 全文检索 SQL
     *
     * @param string $column Column reference.
     * @param string $query  User query.
     * @param array  $where  WHERE clauses.
     * @param array  $params SQL params.
     * @return void
     */
    private function append_text_search_condition( $column, $query, array &$where, array &$params ) {
        global $wpdb;

        $query = $this->normalize_search_text( $query, true );
        if ( '' === $query ) {
            return;
        }

        $query_like = '%' . $wpdb->esc_like( $query ) . '%';
        $boolean_query = $this->build_boolean_query( $query );
        $match_column = preg_replace( '/^.+\./', '', (string) $column );

        if ( $this->supports_fulltext() && '' !== $boolean_query ) {
            $where[]  = "(MATCH({$match_column}) AGAINST (%s IN BOOLEAN MODE) OR {$column} LIKE %s)";
            $params[] = $boolean_query;
            $params[] = $query_like;
            return;
        }

        $where[]  = "{$column} LIKE %s";
        $params[] = $query_like;
    }

    /**
     * 标准化清洗检索文本
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
     * 将长文本字段纯文本化并剥离杂质
     *
     * @param string $value Raw value.
     * @return string
     */
    private function normalize_document_text( $value ) {
        return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) );
    }

    /**
     * 将正文、标签与摘要碎片合并为可检索的文本块
     *
     * @param array $parts Search fragments.
     * @return string
     */
    private function build_search_document( $parts ) {
        $normalized = array();
        foreach ( (array) $parts as $part ) {
            $part = $this->normalize_document_text( $part );
            if ( '' === $part ) {
                continue;
            }
            $normalized[ $part ] = $part;
        }

        return implode( "\n", $normalized );
    }

    /**
     * 标准化搜索排序键名
     *
     * @param string $sort Raw sort value.
     * @return string
     */
    private function normalize_sort( $sort ) {
        $sort = sanitize_key( (string) $sort );
        return in_array( $sort, array( 'latest', 'hot', 'comments', 'likes' ), true ) ? $sort : 'latest';
    }

    /**
     * 构建基于索引得分与时间的相关度排序 SQL
     *
     * @param string $sort Sort key.
     * @return string
     */
    private function get_sort_sql( $sort ) {
        switch ( $this->normalize_sort( $sort ) ) {
            case 'likes':
                return 'si.is_pinned DESC, si.like_count DESC, si.created_at DESC';
            case 'comments':
                return 'si.is_pinned DESC, si.comment_count DESC, si.created_at DESC';
            case 'hot':
                return 'si.is_pinned DESC, ((si.comment_count * 3) + (si.like_count * 2) + (LEAST(si.view_count, 200) / 20)) DESC, si.created_at DESC';
            case 'latest':
            default:
                return 'si.is_pinned DESC, si.created_at DESC';
        }
    }

    /**
     * 剔除 limit+1 分页探针溢出记录
     *
     * @param array $rows     Result rows.
     * @param int   $per_page Page size.
     * @return array{0:array,1:bool}
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
     * 构建轻量分页估算总数
     *
     * @param int  $page     Current page.
     * @param int  $per_page Page size.
     * @param int  $shown    Rows shown.
     * @param bool $has_more Whether another page exists.
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
     * 将用户输入的搜索词转换为 MySQL FULLTEXT 布尔模式检索串
     *
     * @param string $query User query.
     * @return string
     */
    private function build_boolean_query( $query ) {
        $query = $this->normalize_search_text( $query, true );
        if ( '' === $query ) {
            return '';
        }

        $tokens = preg_split( '/\s+/u', $query );
        $boolean_parts = array();

        foreach ( (array) $tokens as $token ) {
            $token = trim( preg_replace( '/[^\p{L}\p{N}_-]+/u', '', (string) $token ) );
            if ( '' === $token ) {
                continue;
            }

            $token_length = function_exists( 'mb_strlen' ) ? mb_strlen( $token, 'UTF-8' ) : strlen( $token );
            if ( $token_length < 2 ) {
                continue;
            }

            $boolean_parts[] = '+' . $token . '*';
        }

        if ( empty( $boolean_parts ) ) {
            return trim( preg_replace( '/\s+/u', ' ', $query ) );
        }

        return implode( ' ', array_slice( array_values( array_unique( $boolean_parts ) ), 0, 8 ) );
    }

    /**
     * 将换行分割的索引列反解析为数组结构
     *
     * @param string $value Stored value.
     * @return array
     */
    private function explode_search_list( $value ) {
        $parts = preg_split( '/[\r\n]+/', (string) $value );
        $parts = array_filter( array_map( 'trim', (array) $parts ) );
        return array_values( array_unique( $parts ) );
    }

    /**
     * 计算轻量关键词匹配相关度得分
     *
     * @param string $query          Search query.
     * @param array  $values         Candidate values.
     * @param int    $contains_score Contains score.
     * @param int    $prefix_score   Prefix score.
     * @param int    $exact_score    Exact score.
     * @return int
     */
    private function calculate_match_score( $query, $values, $contains_score, $prefix_score, $exact_score ) {
        $query = trim( (string) $query );
        if ( '' === $query ) {
            return 0;
        }

        $normalize = static function( $value ) {
            $value = trim( wp_strip_all_tags( (string) $value ) );
            if ( function_exists( 'mb_strtolower' ) ) {
                return mb_strtolower( $value, 'UTF-8' );
            }

            return strtolower( $value );
        };

        $normalized_query = $normalize( $query );
        $score = 0;

        foreach ( (array) $values as $value ) {
            $normalized_value = $normalize( $value );
            if ( '' === $normalized_value ) {
                continue;
            }

            if ( $normalized_value === $normalized_query ) {
                return (int) $exact_score;
            }

            if ( 0 === strpos( $normalized_value, $normalized_query ) ) {
                $score = max( $score, (int) $prefix_score );
                continue;
            }

            if ( false !== strpos( $normalized_value, $normalized_query ) ) {
                $score = max( $score, (int) $contains_score );
            }
        }

        return $score;
    }

    /**
     * 围绕命中的关键词截取紧凑摘要片段
     *
     * @param string $content Raw content.
     * @param string $query   Search query.
     * @param int    $length  Excerpt length.
     * @return string
     */
    private function build_excerpt( $content, $query, $length = 72 ) {
        $content = $this->normalize_document_text( $content );
        if ( '' === $content ) {
            return '';
        }

        if ( mb_strlen( $content ) <= $length ) {
            return esc_html( $content );
        }

        $position = function_exists( 'mb_stripos' ) ? mb_stripos( $content, $query, 0, 'UTF-8' ) : stripos( $content, $query );
        if ( false === $position ) {
            return esc_html( mb_substr( $content, 0, $length ) . '...' );
        }

        $start = max( 0, (int) $position - 12 );
        $excerpt = mb_substr( $content, $start, $length );

        if ( $start > 0 ) {
            $excerpt = '...' . $excerpt;
        }

        if ( $start + $length < mb_strlen( $content ) ) {
            $excerpt .= '...';
        }

        return esc_html( $excerpt );
    }

    /**
     * 将命中类型转换为用户可读的标签文案
     *
     * @param array $match_types Match types.
     * @return string
     */
    private function get_match_label( $match_types ) {
        $labels = array(
            'tag'     => __( '标签匹配', 'qibbs-community' ),
            'forum'   => __( '圈子匹配', 'qibbs-community' ),
            'author'  => __( '作者匹配', 'qibbs-community' ),
            'content' => __( '内容匹配', 'qibbs-community' ),
        );

        $parts = array();
        foreach ( array( 'tag', 'forum', 'author', 'content' ) as $type ) {
            if ( in_array( $type, (array) $match_types, true ) && isset( $labels[ $type ] ) ) {
                $parts[] = $labels[ $type ];
            }
        }

        return implode( ' / ', $parts );
    }
}
