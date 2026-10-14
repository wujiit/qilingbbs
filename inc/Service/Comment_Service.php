<?php
/**
 * 评论与二级回复服务类
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Comment_Service {

    /**
     * 父级评论下方内联展示的二级回复预览条数
     *
     * @var int
     */
    const DEFAULT_REPLY_PREVIEW_LIMIT = 5;

    /**
     * 评论功能全局开关状态
     *
     * @return bool
     */
    private function is_comments_enabled() {
        return qibbs_is_module_enabled( 'comments' );
    }

    /**
     * 构建评论模块已关闭的标准返回
     *
     * @return array
     */
    private function comments_disabled_result() {
        return array(
            'success' => false,
            'message' => qibbs_get_module_disabled_message( 'comments' ),
        );
    }

    public function add_comment( $user_id, $post_id, $content, $parent_id = 0 ) {
        if ( ! $this->is_comments_enabled() ) {
            return $this->comments_disabled_result();
        }

        if ( ! $user_id || ! $post_id || empty( $content ) ) {
            return array( 'success' => false, 'message' => __( '参数错误', 'qibbs-community' ) );
        }

        if (
            function_exists( 'qibbs_is_user_blacklisted' ) &&
            qibbs_is_user_blacklisted( $user_id ) &&
            ! user_can( $user_id, 'manage_options' )
        ) {
            return array( 'success' => false, 'message' => __( '你的账号已被限制，暂时无法发布评论', 'qibbs-community' ) );
        }

        /**
         * 用户发评权限校验过滤器
         *
         * @since 1.1.0
         * @param bool $can_comment Whether the user can comment.
         * @param int  $user_id     User ID.
         * @param int  $post_id     Post ID.
         */
        $can_comment = apply_filters( 'qibbs_can_comment', true, $user_id, $post_id );
        if ( ! $can_comment ) {
            return array( 'success' => false, 'message' => __( '没有评论权限', 'qibbs-community' ) );
        }

        global $wpdb;
        $posts_table = Qibbs_Database::posts();
        $post = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, status, forum_id, discussion_status, is_resolved FROM {$posts_table} WHERE id = %d LIMIT 1",
                $post_id
            )
        );
        if ( ! $post ) {
            return array( 'success' => false, 'message' => __( '动态不存在', 'qibbs-community' ) );
        }
        if ( 'publish' !== (string) $post->status ) {
            return array( 'success' => false, 'message' => __( '该动态当前不可评论', 'qibbs-community' ) );
        }

        $discussion_status = $this->normalize_post_discussion_status( $post );
        $discussion_status_enabled = function_exists( 'qibbs_is_discussion_status_enabled_for_forum' )
            ? qibbs_is_discussion_status_enabled_for_forum( (int) $post->forum_id )
            : false;
        if ( $discussion_status_enabled && 'closed' === $discussion_status ) {
            return array( 'success' => false, 'message' => __( '该动态已关闭讨论，暂不支持新评论', 'qibbs-community' ) );
        }

        /**
         * 评论正文入库前内容过滤
         *
         * @since 1.1.0
         * @param string $content Comment content.
         * @param int    $user_id User ID.
         * @param int    $post_id Post ID.
         */
        $content = apply_filters( 'qibbs_comment_content', $content, $user_id, $post_id );

        if ( class_exists( 'Qibbs_Risk_Service' ) ) {
            $risk_check = Qibbs_Risk_Service::evaluate_content_submission(
                $user_id,
                'comment',
                $content,
                array(
                    'post_id'   => $post_id,
                    'parent_id' => $parent_id,
                )
            );
            if ( is_wp_error( $risk_check ) ) {
                return array( 'success' => false, 'message' => $risk_check->get_error_message() );
            }
        }

        $table = Qibbs_Database::comments();
        $comment_status = qibbs_get_option( 'auto_approve_posts', true ) ? 'approved' : 'pending';

        $result = $wpdb->insert(
            $table,
            array(
                'post_id'    => $post_id,
                'user_id'    => $user_id,
                'parent_id'  => $parent_id,
                'content'    => qibbs_sanitize_content( $content ),
                'status'     => $comment_status,
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%d', '%s', '%s', '%s' )
        );

        if ( ! $result ) {
            return array( 'success' => false, 'message' => __( '评论失败', 'qibbs-community' ) );
        }

        $comment_id = (int) $wpdb->insert_id;

        if ( 'approved' === $comment_status ) {
            $this->sync_post_comment_count( $post_id );
        }

        $this->create_comment_notification( $user_id, $post_id, $comment_id, $parent_id );

        // 联动回帖即领红包（仅一级评论且审核通过时触发）
        $redpacket_claim_data = null;
        if ( 0 === $parent_id && 'approved' === $comment_status && class_exists( 'Qibbs_Redpacket_Service' ) ) {
            $claim_res = Qibbs_Redpacket_Service::instance()->claim_instant_packet( $post_id, $user_id, $comment_id );
            if ( ! empty( $claim_res['claimed'] ) ) {
                $redpacket_claim_data = array(
                    'claimed'       => true,
                    'amount'        => (float) $claim_res['amount'],
                    'remaining_num' => isset( $claim_res['remaining_num'] ) ? (int) $claim_res['remaining_num'] : 0,
                    'message'       => $claim_res['message'],
                );
            } elseif ( ! empty( $claim_res['message'] ) ) {
                $redpacket_claim_data = array(
                    'claimed' => false,
                    'reason'  => ! empty( $claim_res['reason'] ) ? $claim_res['reason'] : '',
                    'notice'  => $claim_res['message'],
                );
            }
        }

        do_action( 'qibbs_comment_added', $comment_id, $user_id, $post_id );

        return array(
            'success'    => true,
            'comment_id' => $comment_id,
            'message'    => __( '评论成功', 'qibbs-community' ),
            'redpacket'  => $redpacket_claim_data,
        );
    }

    public function get_comments( $post_id, $limit = 10, $offset = 0 ) {
        if ( ! $this->is_comments_enabled() ) {
            return array(
                'comments'          => array(),
                'total'             => 0,
                'per_page'          => max( 1, absint( $limit ) ),
                'total_pages'       => 0,
                'discussion_status' => 'discussion',
                'discussion_label'  => $this->get_discussion_status_label( 'discussion' ),
                'best_comment_id'   => 0,
                'can_reopen'        => false,
            );
        }

        global $wpdb;

        $post_id = absint( $post_id );
        $limit = max( 1, absint( $limit ) );
        $offset = max( 0, absint( $offset ) );

        $post_context = $this->fetch_post_context( $post_id );
        if ( ! $post_context ) {
            return array(
                'comments'          => array(),
                'total'             => 0,
                'per_page'          => $limit,
                'total_pages'       => 0,
                'discussion_status' => 'discussion',
                'discussion_label'  => $this->get_discussion_status_label( 'discussion' ),
                'best_comment_id'   => 0,
                'can_reopen'        => false,
            );
        }

        $table = Qibbs_Database::comments();
        $best_comment_id = absint( $post_context->best_comment_id );
        $discussion_status = $this->normalize_post_discussion_status( $post_context );
        $can_reopen = $this->can_user_reopen_post( get_current_user_id(), $post_context, $discussion_status );

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND status = 'approved' AND parent_id = 0",
                $post_id
            )
        );

        $comments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE post_id = %d AND status = 'approved' AND parent_id = 0
                 ORDER BY
                    CASE
                        WHEN id = %d THEN 0
                        WHEN is_quality_pinned = 1 THEN 1
                        ELSE 2
                    END ASC,
                    CASE
                        WHEN is_quality_pinned = 1 THEN IFNULL(quality_pinned_at, created_at)
                        ELSE NULL
                    END DESC,
                    CASE
                        WHEN id = %d OR is_quality_pinned = 1 THEN NULL
                        ELSE like_count
                    END DESC
                    ,
                    created_at DESC
                 LIMIT %d OFFSET %d",
                $post_id,
                $best_comment_id,
                $best_comment_id,
                $limit,
                $offset
            )
        );

        $posts_map = array( $post_id => $post_context );

        // 批量丰富评论元数据并预加载下级回复
        $enriched = $this->enrich_comments( $comments, $posts_map );
        if ( empty( $enriched ) ) {
            return array(
                'comments'          => array(),
                'total'             => $total,
                'per_page'          => $limit,
                'total_pages'       => (int) ceil( $total / $limit ),
                'discussion_status' => $this->normalize_post_discussion_status( $post_context ),
                'discussion_label'  => $this->get_discussion_status_label( $this->normalize_post_discussion_status( $post_context ) ),
                'best_comment_id'   => $best_comment_id,
                'can_reopen'        => $can_reopen,
            );
        }

        $comment_ids = array();
        foreach ( $enriched as $comment_item ) {
            $comment_ids[] = (int) $comment_item->id;
        }

        $reply_count_map = array();
        $replies_grouped = array();
        $reply_preview_limit = $this->get_reply_preview_limit( $post_id );

        if ( ! empty( $comment_ids ) ) {
            $comment_placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );

            $reply_count_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT parent_id, COUNT(*) AS total
                     FROM {$table}
                     WHERE parent_id IN ({$comment_placeholders}) AND status = 'approved'
                     GROUP BY parent_id",
                    $comment_ids
                )
            );

            foreach ( $reply_count_rows as $reply_count_row ) {
                $reply_count_map[ (int) $reply_count_row->parent_id ] = (int) $reply_count_row->total;
            }

            $replies_grouped = $this->get_reply_previews_grouped( $comment_ids, $posts_map, $reply_preview_limit );
        }

        foreach ( $enriched as &$comment ) {
            $comment_id = (int) $comment->id;
            $comment->replies = isset( $replies_grouped[ $comment_id ] ) ? $replies_grouped[ $comment_id ] : array();
            $comment->reply_count = isset( $reply_count_map[ $comment_id ] ) ? $reply_count_map[ $comment_id ] : 0;
        }

        return array(
            'comments'          => $enriched,
            'total'             => $total,
            'per_page'          => $limit,
            'total_pages'       => (int) ceil( $total / $limit ),
            'comment_count'     => isset( $post_context->comment_count ) ? (int) $post_context->comment_count : $total,
            'discussion_status' => $discussion_status,
            'discussion_label'  => $this->get_discussion_status_label( $discussion_status ),
            'best_comment_id'   => $best_comment_id,
            'can_reopen'        => $can_reopen,
        );
    }

    /**
     * 获取评论列表单父级回复预览限制
     *
     * @param int $post_id Post ID.
     * @return int
     */
    private function get_reply_preview_limit( $post_id ) {
        $limit = apply_filters(
            'qibbs_comment_reply_preview_limit',
            self::DEFAULT_REPLY_PREVIEW_LIMIT,
            absint( $post_id )
        );

        return max( 1, absint( $limit ) );
    }

    /**
     * 批量聚合查询当前页父级评论关联的最新二级回复
     *
     * @param array $comment_ids Parent comment IDs.
     * @param array $posts_map   Post context map.
     * @param int   $preview_limit Replies to preload for each parent comment.
     * @return array
     */
    private function get_reply_previews_grouped( $comment_ids, $posts_map, $preview_limit ) {
        global $wpdb;

        $comment_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $comment_ids ) ) ) );
        $preview_limit = max( 1, absint( $preview_limit ) );

        if ( empty( $comment_ids ) ) {
            return array();
        }

        $table = Qibbs_Database::comments();

        if ( 1 === count( $comment_ids ) ) {
            $reply_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE parent_id = %d AND status = 'approved'
                     ORDER BY created_at ASC, id ASC
                     LIMIT %d",
                    $comment_ids[0],
                    $preview_limit
                )
            );
        } else {
            $reply_query_parts = array();
            foreach ( $comment_ids as $comment_id ) {
                $reply_query_parts[] = $wpdb->prepare(
                    "(SELECT * FROM {$table}
                      WHERE parent_id = %d AND status = 'approved'
                      ORDER BY created_at ASC, id ASC
                      LIMIT %d)",
                    $comment_id,
                    $preview_limit
                );
            }

            $reply_sql = sprintf(
                'SELECT * FROM (%s) AS qibbs_reply_preview ORDER BY parent_id ASC, created_at ASC, id ASC',
                implode( ' UNION ALL ', $reply_query_parts )
            );

            $reply_rows = $wpdb->get_results( $reply_sql );
        }

        if ( empty( $reply_rows ) ) {
            return array();
        }

        $grouped = array();
        $enriched_replies = $this->enrich_comments( $reply_rows, $posts_map );

        foreach ( $enriched_replies as $reply_item ) {
            $parent_id = (int) $reply_item->parent_id;
            if ( ! isset( $grouped[ $parent_id ] ) ) {
                $grouped[ $parent_id ] = array();
            }

            $grouped[ $parent_id ][] = $reply_item;
        }

        return $grouped;
    }

    public function get_replies( $comment_id, $limit = 10, $offset = 0 ) {
        global $wpdb;

        $comment_id = absint( $comment_id );
        $limit = max( 1, absint( $limit ) );
        $offset = max( 0, absint( $offset ) );

        $table = Qibbs_Database::comments();
        $parent = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, post_id FROM {$table} WHERE id = %d LIMIT 1",
                $comment_id
            )
        );

        if ( ! $parent ) {
            return array(
                'replies'     => array(),
                'total'       => 0,
                'per_page'    => $limit,
                'total_pages' => 0,
                'has_more'    => false,
            );
        }

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE parent_id = %d AND status = 'approved'",
                $comment_id
            )
        );

        $replies = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE parent_id = %d AND status = 'approved'
                 ORDER BY created_at ASC LIMIT %d OFFSET %d",
                $comment_id,
                $limit,
                $offset
            )
        );

        $posts_map = $this->fetch_posts_context_map( array( (int) $parent->post_id ) );
        $enriched = $this->enrich_comments( $replies, $posts_map );

        return array(
            'replies'     => $enriched,
            'total'       => $total,
            'per_page'    => $limit,
            'total_pages' => (int) ceil( $total / $limit ),
            'has_more'    => ( $offset + $limit ) < $total,
        );
    }

    private function enrich_comments( $comments, $posts_map = array() ) {
        if ( empty( $comments ) ) {
            return array();
        }

        global $wpdb;
        $current_user_id = get_current_user_id();
        $is_admin = current_user_can( 'manage_options' );

        $user_ids = array();
        $post_ids = array();

        foreach ( $comments as $comment_item ) {
            $user_ids[] = (int) $comment_item->user_id;
            $post_ids[] = (int) $comment_item->post_id;
        }

        $user_ids = array_values( array_unique( array_filter( $user_ids ) ) );
        $post_ids = array_values( array_unique( array_filter( $post_ids ) ) );

        if ( empty( $posts_map ) && ! empty( $post_ids ) ) {
            $posts_map = $this->fetch_posts_context_map( $post_ids );
        }

        $users_map = array();
        if ( ! empty( $user_ids ) ) {
            $users = get_users(
                array(
                    'include' => $user_ids,
                    'fields'  => array( 'ID', 'display_name' ),
                )
            );
            foreach ( $users as $user_item ) {
                $users_map[ (int) $user_item->ID ] = $user_item;
            }
        }

        $liked_comment_ids = array();
        if ( $current_user_id > 0 ) {
            $comment_ids = array();
            foreach ( $comments as $comment_item ) {
                $comment_ids[] = (int) $comment_item->id;
            }
            $comment_ids = array_values( array_unique( array_filter( $comment_ids ) ) );

            if ( ! empty( $comment_ids ) ) {
                $comment_likes_table = Qibbs_Database::comment_likes();
                $placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );
                $query_params = array_merge( array( $current_user_id ), $comment_ids );
                $liked_rows = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT comment_id
                         FROM {$comment_likes_table}
                         WHERE user_id = %d
                           AND comment_id IN ({$placeholders})",
                        $query_params
                    )
                );
                if ( is_array( $liked_rows ) ) {
                    foreach ( $liked_rows as $liked_comment_id ) {
                        $liked_comment_ids[ (int) $liked_comment_id ] = true;
                    }
                }
            }
        }

        $name_colors_map = array();
        if ( class_exists( 'Qibbs_Prop_Service' ) && ! empty( $user_ids ) ) {
            $name_colors_map = Qibbs_Prop_Service::instance()->get_users_name_color_map( $user_ids );
        }

        foreach ( $comments as &$comment ) {
            $comment_id = (int) $comment->id;
            $comment_user_id = (int) $comment->user_id;
            $comment_post_id = (int) $comment->post_id;
            $is_top_level = (int) $comment->parent_id === 0;

            $user = isset( $users_map[ $comment_user_id ] ) ? $users_map[ $comment_user_id ] : null;
            $post_context = isset( $posts_map[ $comment_post_id ] ) ? $posts_map[ $comment_post_id ] : null;
            $best_comment_id = $post_context ? absint( $post_context->best_comment_id ) : 0;

            $comment->author = array(
                'id'           => $comment_user_id,
                'display_name' => $user ? $user->display_name : __( '未知用户', 'qibbs-community' ),
                'name_color'   => isset( $name_colors_map[ $comment_user_id ] ) ? $name_colors_map[ $comment_user_id ] : '',
                'avatar'       => get_avatar_url( $comment_user_id, array( 'size' => 48 ) ),
                'profile_url'  => qibbs_get_profile_url( $comment_user_id ),
                'cuid'         => function_exists( 'qibbs_get_user_cuid' ) ? qibbs_get_user_cuid( $comment_user_id ) : 0,
                'cuid_badge'   => function_exists( 'qibbs_render_cuid_badge' ) ? qibbs_render_cuid_badge( $comment_user_id ) : '',
                'level_badge'  => function_exists( 'qibbs_render_user_level_badge' ) ? qibbs_render_user_level_badge( $comment_user_id ) : '',
            );
            $comment->time_ago = qibbs_human_time_diff( $comment->created_at );
            $comment->created_at_formatted = function_exists( 'qibbs_format_exact_datetime' ) ? qibbs_format_exact_datetime( $comment->created_at ) : (string) $comment->created_at;
            $comment->is_owner = $current_user_id === $comment_user_id;

            $can_moderate = $current_user_id && function_exists( 'qibbs_user_can_moderate_post' )
                ? qibbs_user_can_moderate_post( $current_user_id, $comment_post_id )
                : false;

            $can_manage_answer = $this->user_can_manage_answer( $current_user_id, $post_context );

            $is_bounty_post = false;
            $bounty_status  = '';
            if ( class_exists( 'Qibbs_Bounty_Service' ) ) {
                $bounty_obj = Qibbs_Bounty_Service::instance()->get_bounty( $comment_post_id );
                if ( $bounty_obj ) {
                    $is_bounty_post = true;
                    $bounty_status  = (string) $bounty_obj->status;
                }
            }

            // 仅悬赏动态且处于待结案状态时允许采纳最佳答案；普通动态不设最佳答案；已结赏不可逆
            $can_set_best = false;
            if ( $is_top_level && $is_bounty_post && in_array( $bounty_status, array( 'pending', 'pending_arbitration' ), true ) ) {
                $can_set_best = (bool) $can_manage_answer;
            }

            $comment->is_quality_pinned = ! empty( $comment->is_quality_pinned );
            $comment->is_best = (bool) ( $is_top_level && $best_comment_id > 0 && $comment_id === $best_comment_id );
            $comment->can_set_best = $can_set_best;
            $comment->can_pin_quality = (bool) ( $is_top_level && $can_manage_answer );
            $comment->can_like = $current_user_id > 0;
            $comment->user_liked = isset( $liked_comment_ids[ $comment_id ] );
            $comment->like_count = isset( $comment->like_count ) ? (int) $comment->like_count : 0;

            $comment->can_moderate = (bool) $can_moderate;
            $comment->can_delete = (bool) ( $comment->is_owner || $is_admin || $can_moderate );
            $comment->can_block = (bool) ( ! $comment->is_owner && ( $is_admin || $can_moderate ) );
        }

        return $comments;
    }

    public function set_best_answer( $post_id, $comment_id, $user_id ) {
        if ( ! $this->is_comments_enabled() ) {
            return $this->comments_disabled_result();
        }

        $post_id = absint( $post_id );
        $comment_id = absint( $comment_id );
        $user_id = absint( $user_id );

        if ( ! $post_id || ! $comment_id || ! $user_id ) {
            return array( 'success' => false, 'message' => __( '参数错误', 'qibbs-community' ) );
        }

        $post = $this->fetch_post_context( $post_id );
        if ( ! $post || 'publish' !== (string) $post->status ) {
            return array( 'success' => false, 'message' => __( '动态不存在或不可操作', 'qibbs-community' ) );
        }

        if ( ! $this->user_can_manage_answer( $user_id, $post ) ) {
            return array( 'success' => false, 'message' => __( '没有权限', 'qibbs-community' ) );
        }

        global $wpdb;
        $comments_table = Qibbs_Database::comments();
        $posts_table = Qibbs_Database::posts();

        $comment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, post_id, user_id, parent_id, status FROM {$comments_table} WHERE id = %d LIMIT 1",
                $comment_id
            )
        );

        if ( ! $comment || (int) $comment->post_id !== $post_id ) {
            return array( 'success' => false, 'message' => __( '评论不存在', 'qibbs-community' ) );
        }

        if ( (int) $comment->parent_id !== 0 ) {
            return array( 'success' => false, 'message' => __( '仅支持将一级评论设为最佳答案', 'qibbs-community' ) );
        }

        if ( 'approved' !== (string) $comment->status ) {
            return array( 'success' => false, 'message' => __( '该评论当前不可设为最佳答案', 'qibbs-community' ) );
        }

        // 严格限制：仅悬赏动态支持采纳最佳答案
        if ( ! class_exists( 'Qibbs_Bounty_Service' ) ) {
            return array( 'success' => false, 'message' => __( '仅悬赏动态支持采纳最佳答案', 'qibbs-community' ) );
        }

        $bounty_service = Qibbs_Bounty_Service::instance();
        $bounty = $bounty_service->get_bounty( $post_id );
        if ( ! $bounty ) {
            return array( 'success' => false, 'message' => __( '仅悬赏动态支持采纳最佳答案', 'qibbs-community' ) );
        }

        if ( 'awarded' === (string) $bounty->status ) {
            return array( 'success' => false, 'message' => __( '该悬赏已结案发放，不可重复采纳', 'qibbs-community' ) );
        }

        if ( ! in_array( (string) $bounty->status, array( 'pending', 'pending_arbitration' ), true ) ) {
            return array( 'success' => false, 'message' => __( '该悬赏当前状态不可采纳', 'qibbs-community' ) );
        }

        if ( (int) $comment->user_id === (int) $bounty->author_id ) {
            return array( 'success' => false, 'message' => __( '不能将悬赏采纳给自己的回答', 'qibbs-community' ) );
        }

        $updated = $wpdb->update(
            $posts_table,
            array(
                'best_comment_id'  => $comment_id,
                'best_answer_at'   => current_time( 'mysql' ),
                'best_answer_by'   => $user_id,
                'discussion_status'=> 'resolved',
                'is_resolved'      => 1,
                'updated_at'       => current_time( 'mysql' ),
            ),
            array( 'id' => $post_id ),
            array( '%d', '%s', '%d', '%s', '%d', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return array( 'success' => false, 'message' => __( '操作失败，请稍后重试', 'qibbs-community' ) );
        }

        $this->create_best_answer_notification( $user_id, $post_id, $comment );
        $this->maybe_log_answer_moderator_action( 'moderator_set_best_answer', $user_id, $post, (int) $comment->user_id, 'best=' . $comment_id );

        // 联动悬赏结算
        $bounty_msg_suffix = '';
        if ( class_exists( 'Qibbs_Bounty_Service' ) ) {
            $bounty_service = Qibbs_Bounty_Service::instance();
            $bounty = $bounty_service->get_bounty( $post_id );
            if ( $bounty && in_array( (string) $bounty->status, array( 'pending', 'pending_arbitration' ), true ) ) {
                $bounty_award_res = $bounty_service->award_bounty( $post_id, $comment_id, (int) $comment->user_id, $user_id );
                if ( ! empty( $bounty_award_res['success'] ) ) {
                    $bounty_msg_suffix = '，' . $bounty_award_res['message'];
                }
            }
        }

        do_action( 'qibbs_best_answer_set', $post_id, $comment_id, (int) $comment->user_id, $user_id );
        do_action( 'qibbs_post_edited', $post_id, $user_id );

        return array(
            'success'          => true,
            'message'          => __( '已设为最佳答案', 'qibbs-community' ) . $bounty_msg_suffix,
            'discussion_status'=> 'resolved',
            'label'            => $this->get_discussion_status_label( 'resolved' ),
            'best_comment_id'  => $comment_id,
        );
    }

    public function clear_best_answer( $post_id, $user_id ) {
        unset( $post_id, $user_id );
        return array( 'success' => false, 'message' => __( '悬赏已完成结案发放，不可取消或变更最佳答案', 'qibbs-community' ) );
    }

    public function toggle_quality_pin( $comment_id, $user_id ) {
        if ( ! $this->is_comments_enabled() ) {
            return $this->comments_disabled_result();
        }

        $comment_id = absint( $comment_id );
        $user_id = absint( $user_id );

        if ( ! $comment_id || ! $user_id ) {
            return array( 'success' => false, 'message' => __( '参数错误', 'qibbs-community' ) );
        }

        global $wpdb;
        $comments_table = Qibbs_Database::comments();

        $comment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, post_id, user_id, parent_id, status, is_quality_pinned
                 FROM {$comments_table}
                 WHERE id = %d
                 LIMIT 1",
                $comment_id
            )
        );

        if ( ! $comment ) {
            return array( 'success' => false, 'message' => __( '评论不存在', 'qibbs-community' ) );
        }

        if ( (int) $comment->parent_id !== 0 ) {
            return array( 'success' => false, 'message' => __( '仅支持置顶一级评论', 'qibbs-community' ) );
        }

        if ( 'approved' !== (string) $comment->status ) {
            return array( 'success' => false, 'message' => __( '该评论当前不可置顶', 'qibbs-community' ) );
        }

        $post = $this->fetch_post_context( (int) $comment->post_id );
        if ( ! $post || 'publish' !== (string) $post->status ) {
            return array( 'success' => false, 'message' => __( '动态不存在或不可操作', 'qibbs-community' ) );
        }

        if ( ! $this->user_can_manage_answer( $user_id, $post ) ) {
            return array( 'success' => false, 'message' => __( '没有权限', 'qibbs-community' ) );
        }

        $is_pinned = ! empty( $comment->is_quality_pinned );
        $next_pinned = ! $is_pinned;

        $update_data = array(
            'is_quality_pinned' => $next_pinned ? 1 : 0,
            'quality_pinned_at' => $next_pinned ? current_time( 'mysql' ) : null,
            'quality_pinned_by' => $next_pinned ? $user_id : 0,
        );

        $updated = $wpdb->update(
            $comments_table,
            $update_data,
            array( 'id' => $comment_id ),
            array( '%d', '%s', '%d' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return array( 'success' => false, 'message' => __( '操作失败，请稍后重试', 'qibbs-community' ) );
        }

        $action = $next_pinned ? 'moderator_pin_quality_comment' : 'moderator_unpin_quality_comment';
        $this->maybe_log_answer_moderator_action( $action, $user_id, $post, (int) $comment->user_id, 'comment=' . $comment_id );

        do_action( 'qibbs_post_edited', (int) $comment->post_id, $user_id );

        return array(
            'success' => true,
            'pinned'  => $next_pinned,
            'message' => $next_pinned ? __( '已置顶为优质回复', 'qibbs-community' ) : __( '已取消优质置顶', 'qibbs-community' ),
        );
    }

    public function toggle_comment_like( $user_id, $comment_id ) {
        if ( ! $this->is_comments_enabled() ) {
            return $this->comments_disabled_result();
        }

        $user_id = absint( $user_id );
        $comment_id = absint( $comment_id );

        if ( ! $user_id || ! $comment_id ) {
            return array( 'success' => false, 'message' => __( '参数错误', 'qibbs-community' ) );
        }

        global $wpdb;
        $comments_table = Qibbs_Database::comments();
        $comment_likes_table = Qibbs_Database::comment_likes();

        $comment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, post_id, user_id, content, status, like_count
                 FROM {$comments_table}
                 WHERE id = %d
                 LIMIT 1",
                $comment_id
            )
        );

        if ( ! $comment ) {
            return array( 'success' => false, 'message' => __( '评论不存在', 'qibbs-community' ) );
        }

        if ( 'approved' !== (string) $comment->status ) {
            return array( 'success' => false, 'message' => __( '该评论当前不可点赞', 'qibbs-community' ) );
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$comment_likes_table} WHERE user_id = %d AND comment_id = %d LIMIT 1",
                $user_id,
                $comment_id
            )
        );

        if ( $existing ) {
            $deleted = $wpdb->delete(
                $comment_likes_table,
                array(
                    'user_id'    => $user_id,
                    'comment_id' => $comment_id,
                ),
                array( '%d', '%d' )
            );

            if ( false === $deleted ) {
                return array( 'success' => false, 'message' => __( '取消点赞失败，请稍后重试', 'qibbs-community' ) );
            }

            if ( $deleted > 0 ) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$comments_table}
                         SET like_count = GREATEST(like_count - 1, 0)
                         WHERE id = %d",
                        $comment_id
                    )
                );
            }

            $liked = false;
        } else {
            $inserted = $wpdb->insert(
                $comment_likes_table,
                array(
                    'user_id'    => $user_id,
                    'comment_id' => $comment_id,
                    'created_at' => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%s' )
            );

            if ( false === $inserted ) {
                if ( false === stripos( (string) $wpdb->last_error, 'Duplicate entry' ) ) {
                    return array( 'success' => false, 'message' => __( '操作失败，请稍后重试', 'qibbs-community' ) );
                }
            } else {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$comments_table}
                         SET like_count = like_count + 1
                         WHERE id = %d",
                        $comment_id
                    )
                );

                $this->create_comment_like_notification( $user_id, $comment );
            }

            $liked = true;
        }

        $like_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT like_count FROM {$comments_table} WHERE id = %d LIMIT 1",
                $comment_id
            )
        );

        do_action( 'qibbs_comment_like_toggled', $user_id, $comment_id, $liked, (int) $comment->post_id );

        return array(
            'success'    => true,
            'liked'      => $liked,
            'like_count' => $like_count,
            'comment_id' => $comment_id,
            'post_id'    => (int) $comment->post_id,
        );
    }

    public function reopen_post_question( $post_id, $user_id ) {
        $post_id = absint( $post_id );
        $user_id = absint( $user_id );

        if ( ! $post_id || ! $user_id ) {
            return array( 'success' => false, 'message' => __( '参数错误', 'qibbs-community' ) );
        }

        $post = $this->fetch_post_context( $post_id );
        if ( ! $post || 'publish' !== (string) $post->status ) {
            return array( 'success' => false, 'message' => __( '动态不存在或不可操作', 'qibbs-community' ) );
        }

        if (
            ! function_exists( 'qibbs_is_discussion_status_enabled_for_forum' ) ||
            ! qibbs_is_discussion_status_enabled_for_forum( (int) $post->forum_id )
        ) {
            return array( 'success' => false, 'message' => __( '该圈子未启用讨论状态体系', 'qibbs-community' ) );
        }

        $current_status = $this->normalize_post_discussion_status( $post );
        if ( 'discussion' === $current_status ) {
            return array(
                'success'          => true,
                'message'          => __( '当前已是讨论中', 'qibbs-community' ),
                'discussion_status'=> 'discussion',
                'label'            => $this->get_discussion_status_label( 'discussion' ),
                'best_comment_id'  => absint( $post->best_comment_id ),
            );
        }

        if ( class_exists( 'Qibbs_Bounty_Service' ) ) {
            $bounty = Qibbs_Bounty_Service::instance()->get_bounty( $post_id );
            if ( $bounty ) {
                return array( 'success' => false, 'message' => __( '悬赏已完成结案发放，不可重开或变更最佳答案', 'qibbs-community' ) );
            }
        }

        $is_admin = user_can( $user_id, 'manage_options' );
        $is_owner = (int) $post->author_id === $user_id;
        $is_forum_moderator = (int) $post->forum_id > 0 && function_exists( 'qibbs_user_can_moderate_forum' )
            ? (bool) qibbs_user_can_moderate_forum( $user_id, (int) $post->forum_id )
            : false;

        if ( ! $is_admin && ! $is_owner && ! $is_forum_moderator ) {
            return array( 'success' => false, 'message' => __( '没有权限', 'qibbs-community' ) );
        }

        if ( 'closed' === $current_status && $is_owner && ! $is_admin && ! $is_forum_moderator ) {
            return array( 'success' => false, 'message' => __( '该动态已关闭，需管理员或版主重开', 'qibbs-community' ) );
        }

        if ( $is_owner && ! $is_admin && ! $is_forum_moderator && ! $this->is_owner_reopen_enabled() ) {
            return array( 'success' => false, 'message' => __( '当前未启用楼主追问/重开功能', 'qibbs-community' ) );
        }

        if ( $is_owner && ! $is_admin && ! $is_forum_moderator && ! $this->is_owner_reopen_within_days( $post ) ) {
            return array( 'success' => false, 'message' => $this->get_owner_reopen_expired_message() );
        }

        global $wpdb;
        $posts_table = Qibbs_Database::posts();

        $updated = $wpdb->update(
            $posts_table,
            array(
                'discussion_status' => 'discussion',
                'is_resolved'       => 0,
                'best_comment_id'   => 0,
                'best_answer_at'    => null,
                'best_answer_by'    => 0,
                'updated_at'        => current_time( 'mysql' ),
            ),
            array( 'id' => $post_id ),
            array( '%s', '%d', '%d', '%s', '%d', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return array( 'success' => false, 'message' => __( '操作失败，请稍后重试', 'qibbs-community' ) );
        }

        $this->maybe_log_answer_moderator_action( 'moderator_reopen_question', $user_id, $post, 0, $current_status . ' -> discussion' );
        do_action( 'qibbs_post_edited', $post_id, $user_id );

        return array(
            'success'          => true,
            'message'          => __( '已重开讨论', 'qibbs-community' ),
            'discussion_status'=> 'discussion',
            'label'            => $this->get_discussion_status_label( 'discussion' ),
            'best_comment_id'  => 0,
        );
    }

    public function delete_comment( $comment_id, $user_id ) {
        if ( ! $this->is_comments_enabled() ) {
            return $this->comments_disabled_result();
        }

        global $wpdb;
        $table = Qibbs_Database::comments();
        $comment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $comment_id
            )
        );

        if ( ! $comment ) {
            return array( 'success' => false, 'message' => __( '评论不存在', 'qibbs-community' ) );
        }

        $is_forum_moderator = function_exists( 'qibbs_user_can_moderate_post' )
            ? qibbs_user_can_moderate_post( $user_id, (int) $comment->post_id )
            : false;

        // 权限校验：作者、管理员或版主权限
        if ( $comment->user_id != $user_id && ! current_user_can( 'manage_options' ) && ! $is_forum_moderator ) {
            return array( 'success' => false, 'message' => __( '没有删除权限', 'qibbs-community' ) );
        }

        $is_moderation_action = ( $comment->user_id != $user_id ) && ( current_user_can( 'manage_options' ) || $is_forum_moderator );

        if ( 'trashed' === (string) $comment->status ) {
            return array( 'success' => true, 'message' => __( '该评论已在回收站', 'qibbs-community' ) );
        }

        $result = $wpdb->update(
            $table,
            array( 'status' => 'trashed' ),
            array( 'id' => $comment_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( false !== $result ) {
            $this->sync_post_comment_count( (int) $comment->post_id );

            $post = $this->fetch_post_context( (int) $comment->post_id );
            if ( $post ) {
                $this->clear_best_answer_for_post( $post, $user_id, (int) $comment_id, false );
            }

            /**
             * 评论删除完成动作钩子
             *
             * @since 1.1.0
             * @param int $comment_id Comment ID.
             * @param int $user_id    User ID who deleted.
             */
            do_action( 'qibbs_comment_deleted', $comment_id, $user_id, (int) $comment->post_id );

            if ( $is_moderation_action && function_exists( 'qibbs_log_moderator_action' ) ) {
                $content_preview = mb_substr( wp_strip_all_tags( (string) $comment->content ), 0, 40 );
                if ( mb_strlen( wp_strip_all_tags( (string) $comment->content ) ) > 40 ) {
                    $content_preview .= '...';
                }
                qibbs_log_moderator_action(
                    'moderator_delete_comment',
                    'comment',
                    $comment_id,
                    (int) $comment->user_id,
                    $content_preview
                );
            }

            return array( 'success' => true, 'message' => __( '已移入回收站', 'qibbs-community' ) );
        }

        return array( 'success' => false, 'message' => __( '删除失败', 'qibbs-community' ) );
    }

    public function block_comment( $comment_id ) {
        if ( ! $this->is_comments_enabled() ) {
            return $this->comments_disabled_result();
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return array( 'success' => false, 'message' => __( '请先登录', 'qibbs-community' ) );
        }

        global $wpdb;
        $table = Qibbs_Database::comments();
        $comment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, post_id, user_id, content, status FROM {$table} WHERE id = %d LIMIT 1",
                $comment_id
            )
        );
        if ( ! $comment ) {
            return array( 'success' => false, 'message' => __( '评论不存在', 'qibbs-community' ) );
        }

        $is_admin = current_user_can( 'manage_options' );
        $is_forum_moderator = function_exists( 'qibbs_user_can_moderate_post' )
            ? qibbs_user_can_moderate_post( $user_id, (int) $comment->post_id )
            : false;

        if ( ! $is_admin && ! $is_forum_moderator ) {
            return array( 'success' => false, 'message' => __( '没有权限', 'qibbs-community' ) );
        }

        if ( 'blocked' === (string) $comment->status ) {
            return array( 'success' => true, 'message' => __( '该评论已被屏蔽', 'qibbs-community' ) );
        }

        $result = $wpdb->update(
            $table,
            array( 'status' => 'blocked' ),
            array( 'id' => $comment_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( $result !== false ) {
            $this->sync_post_comment_count( (int) $comment->post_id );

            $post = $this->fetch_post_context( (int) $comment->post_id );
            if ( $post ) {
                $this->clear_best_answer_for_post( $post, $user_id, (int) $comment_id, false );
            }

            if ( 'approved' === (string) $comment->status ) {
                do_action( 'qibbs_comment_deleted', $comment_id, $user_id, (int) $comment->post_id );
            }

            if ( function_exists( 'qibbs_log_moderator_action' ) ) {
                $content_preview = mb_substr( wp_strip_all_tags( (string) $comment->content ), 0, 40 );
                if ( mb_strlen( wp_strip_all_tags( (string) $comment->content ) ) > 40 ) {
                    $content_preview .= '...';
                }
                qibbs_log_moderator_action(
                    'moderator_block_comment',
                    'comment',
                    $comment_id,
                    (int) $comment->user_id,
                    $content_preview
                );
            }
            return array( 'success' => true, 'message' => __( '已屏蔽', 'qibbs-community' ) );
        }

        return array( 'success' => false, 'message' => __( '操作失败', 'qibbs-community' ) );
    }

    private function create_comment_notification( $user_id, $post_id, $comment_id, $parent_id ) {
        global $wpdb;
        $posts_table = Qibbs_Database::posts();
        $comments_table = Qibbs_Database::comments();

        // 向帖子作者投递新评论通知
        $post = $wpdb->get_row( $wpdb->prepare( "SELECT author_id FROM {$posts_table} WHERE id = %d", $post_id ) );
        if ( $post && $post->author_id != $user_id ) {
            if ( ! function_exists( 'qibbs_can_send_notification_type' ) || qibbs_can_send_notification_type( (int) $post->author_id, 'comment' ) ) {
                Qibbs_Notification_Delivery::insert(
                    array(
                        'user_id'     => $post->author_id,
                        'type'        => 'comment',
                        'actor_id'    => $user_id,
                        'object_type' => 'post',
                        'object_id'   => $post_id,
                        'created_at'  => current_time( 'mysql' ),
                    )
                );
            }
        }

        // 二级回复时向被回复评论作者投递通知
        if ( $parent_id ) {
            $parent = $wpdb->get_row( $wpdb->prepare( "SELECT user_id FROM {$comments_table} WHERE id = %d", $parent_id ) );
            if ( $parent && $parent->user_id != $user_id ) {
                if ( ! function_exists( 'qibbs_can_send_notification_type' ) || qibbs_can_send_notification_type( (int) $parent->user_id, 'reply' ) ) {
                    Qibbs_Notification_Delivery::insert(
                        array(
                            'user_id'     => $parent->user_id,
                            'type'        => 'reply',
                            'actor_id'    => $user_id,
                            'object_type' => 'comment',
                            'object_id'   => $comment_id,
                            'created_at'  => current_time( 'mysql' ),
                        )
                    );
                }
            }
        }
    }

    private function create_best_answer_notification( $actor_id, $post_id, $comment ) {
        $target_user_id = isset( $comment->user_id ) ? absint( $comment->user_id ) : 0;
        if ( ! $target_user_id || $target_user_id === absint( $actor_id ) ) {
            return;
        }

        if ( function_exists( 'qibbs_can_send_notification_type' ) && ! qibbs_can_send_notification_type( $target_user_id, 'best_answer' ) ) {
            return;
        }

        Qibbs_Notification_Delivery::insert(
            array(
                'user_id'     => $target_user_id,
                'type'        => 'best_answer',
                'actor_id'    => absint( $actor_id ),
                'object_type' => 'post',
                'object_id'   => absint( $post_id ),
                'created_at'  => current_time( 'mysql' ),
            )
        );
    }

    private function create_comment_like_notification( $actor_id, $comment ) {
        $actor_id = absint( $actor_id );
        if ( ! $actor_id || ! $comment ) {
            return;
        }

        $target_user_id = isset( $comment->user_id ) ? absint( $comment->user_id ) : 0;
        $comment_id = isset( $comment->id ) ? absint( $comment->id ) : 0;
        if ( ! $target_user_id || ! $comment_id || $target_user_id === $actor_id ) {
            return;
        }

        if ( function_exists( 'qibbs_can_send_notification_type' ) && ! qibbs_can_send_notification_type( $target_user_id, 'comment_like' ) ) {
            return;
        }

        global $wpdb;
        $notifications_table = Qibbs_Database::notifications();
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$notifications_table}
                 WHERE user_id = %d
                   AND actor_id = %d
                   AND type = %s
                   AND object_type = %s
                   AND object_id = %d
                 LIMIT 1",
                $target_user_id,
                $actor_id,
                'comment_like',
                'comment',
                $comment_id
            )
        );
        if ( $existing > 0 ) {
            return;
        }

        $content_preview = '';
        if ( isset( $comment->content ) ) {
            $raw_content = wp_strip_all_tags( (string) $comment->content );
            if ( '' !== $raw_content ) {
                $content_preview = mb_substr( $raw_content, 0, 30 );
                if ( mb_strlen( $raw_content ) > 30 ) {
                    $content_preview .= '...';
                }
            }
        }

        $aggregate_window = (int) apply_filters( 'qibbs_notification_interaction_aggregate_window', 300 );
        $aggregate_window = max( 0, $aggregate_window );
        if ( $aggregate_window > 0 ) {
            $cutoff = qibbs_format_datetime( 'Y-m-d H:i:s', qibbs_current_timestamp() - $aggregate_window );
            $aggregated_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id
                     FROM {$notifications_table}
                     WHERE user_id = %d
                       AND type = %s
                       AND object_type = %s
                       AND object_id = %d
                       AND is_read = 0
                       AND created_at >= %s
                     ORDER BY id DESC
                     LIMIT 1",
                    $target_user_id,
                    'comment_like',
                    'comment',
                    $comment_id,
                    $cutoff
                )
            );

            if ( $aggregated_id > 0 ) {
                $wpdb->update(
                    $notifications_table,
                    array(
                        'actor_id'   => $actor_id,
                        'content'    => $content_preview,
                        'created_at' => current_time( 'mysql' ),
                        'is_read'    => 0,
                    ),
                    array(
                        'id'      => $aggregated_id,
                        'user_id' => $target_user_id,
                    ),
                    array( '%d', '%s', '%s', '%d' ),
                    array( '%d', '%d' )
                );
                return;
            }
        }

        $dedupe_key = sprintf(
            'comment_like:%d:%d:%d',
            $target_user_id,
            $actor_id,
            $comment_id
        );

        Qibbs_Notification_Delivery::insert(
            array(
                'user_id'     => $target_user_id,
                'type'        => 'comment_like',
                'actor_id'    => $actor_id,
                'object_type' => 'comment',
                'object_id'   => $comment_id,
                'content'     => $content_preview,
                'dedupe_key'  => $dedupe_key,
                'created_at'  => current_time( 'mysql' ),
            )
        );
    }

    /**
     * 统计帖子在前台正常展示的评论总数
     *
     * @param int $post_id Post ID.
     * @return int
     */
    private function get_visible_comment_count( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return 0;
        }

        global $wpdb;
        $comments_table = Qibbs_Database::comments();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$comments_table} WHERE post_id = %d AND status = 'approved'",
                $post_id
            )
        );
    }

    /**
     * 基于有效评论重新计算并同步帖子 comment_count 字段
     *
     * @param int $post_id Post ID.
     * @return int
     */
    private function sync_post_comment_count( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return 0;
        }

        global $wpdb;
        $posts_table = Qibbs_Database::posts();
        $comment_count = $this->get_visible_comment_count( $post_id );

        $wpdb->update(
            $posts_table,
            array( 'comment_count' => $comment_count ),
            array( 'id' => $post_id ),
            array( '%d' ),
            array( '%d' )
        );

        return $comment_count;
    }

    private function fetch_post_context( $post_id ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return null;
        }

        global $wpdb;
        $posts_table = Qibbs_Database::posts();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, author_id, forum_id, status, discussion_status, is_resolved, best_comment_id, comment_count, created_at
                 FROM {$posts_table}
                 WHERE id = %d
                 LIMIT 1",
                $post_id
            )
        );
    }

    private function fetch_posts_context_map( $post_ids ) {
        $post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
        if ( empty( $post_ids ) ) {
            return array();
        }

        global $wpdb;
        $posts_table = Qibbs_Database::posts();
        $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, author_id, forum_id, status, discussion_status, is_resolved, best_comment_id, comment_count, created_at
                 FROM {$posts_table}
                 WHERE id IN ({$placeholders})",
                $post_ids
            )
        );

        $map = array();
        foreach ( $rows as $row ) {
            $map[ (int) $row->id ] = $row;
        }

        return $map;
    }

    private function user_can_manage_answer( $user_id, $post ) {
        $user_id = absint( $user_id );
        if ( ! $user_id || ! $post ) {
            return false;
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        if ( isset( $post->author_id ) && (int) $post->author_id === $user_id ) {
            return true;
        }

        if ( isset( $post->forum_id ) && (int) $post->forum_id > 0 && function_exists( 'qibbs_user_can_moderate_forum' ) ) {
            return (bool) qibbs_user_can_moderate_forum( $user_id, (int) $post->forum_id );
        }

        return false;
    }

    private function is_owner_reopen_enabled() {
        return (bool) qibbs_get_option( 'enable_owner_reopen_question', false );
    }

    private function get_owner_reopen_max_days() {
        $max_days = absint( qibbs_get_option( 'owner_reopen_max_days', 30 ) );
        return max( 1, min( 3650, $max_days ) );
    }

    private function is_owner_reopen_within_days( $post ) {
        if ( ! $post || empty( $post->created_at ) ) {
            return false;
        }

        $created_timestamp = qibbs_datetime_to_timestamp( (string) $post->created_at );
        if ( ! $created_timestamp ) {
            return false;
        }

        $max_days = $this->get_owner_reopen_max_days();
        $deadline = $created_timestamp + ( DAY_IN_SECONDS * $max_days );

        return qibbs_current_timestamp() <= $deadline;
    }

    private function get_owner_reopen_expired_message() {
        return sprintf(
            __( '仅允许在发布后 %d 天内重开，已超出时限', 'qibbs-community' ),
            $this->get_owner_reopen_max_days()
        );
    }

    private function can_user_reopen_post( $user_id, $post, $discussion_status = '' ) {
        $user_id = absint( $user_id );
        if ( ! $user_id || ! $post ) {
            return false;
        }

        $discussion_status = $discussion_status ? (string) $discussion_status : $this->normalize_post_discussion_status( $post );
        if ( 'discussion' === $discussion_status ) {
            return false;
        }

        if ( class_exists( 'Qibbs_Bounty_Service' ) ) {
            $bounty = Qibbs_Bounty_Service::instance()->get_bounty( (int) $post->id );
            if ( $bounty ) {
                return false;
            }
        }

        $is_admin = user_can( $user_id, 'manage_options' );
        $is_owner = isset( $post->author_id ) && (int) $post->author_id === $user_id;
        $is_forum_moderator = isset( $post->forum_id ) && (int) $post->forum_id > 0 && function_exists( 'qibbs_user_can_moderate_forum' )
            ? (bool) qibbs_user_can_moderate_forum( $user_id, (int) $post->forum_id )
            : false;

        if ( ! $is_admin && ! $is_owner && ! $is_forum_moderator ) {
            return false;
        }

        if ( $is_owner && ! $is_admin && ! $is_forum_moderator && ! $this->is_owner_reopen_enabled() ) {
            return false;
        }

        if ( $is_owner && ! $is_admin && ! $is_forum_moderator && ! $this->is_owner_reopen_within_days( $post ) ) {
            return false;
        }

        if ( 'closed' === $discussion_status && $is_owner && ! $is_admin && ! $is_forum_moderator ) {
            return false;
        }

        return true;
    }

    private function normalize_post_discussion_status( $post ) {
        if ( function_exists( 'qibbs_normalize_discussion_status' ) ) {
            return qibbs_normalize_discussion_status(
                isset( $post->discussion_status ) ? (string) $post->discussion_status : '',
                ! empty( $post->is_resolved ) ? 1 : 0
            );
        }

        return ! empty( $post->is_resolved ) ? 'resolved' : 'discussion';
    }

    private function get_discussion_status_label( $status ) {
        $labels = array(
            'discussion' => __( '讨论中', 'qibbs-community' ),
            'resolved'   => __( '已解决', 'qibbs-community' ),
            'closed'     => __( '已关闭', 'qibbs-community' ),
        );

        return isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['discussion'];
    }

    private function clear_best_answer_for_post( $post, $user_id, $expected_comment_id = 0, $enforce_permission = true ) {
        if ( ! $post ) {
            return array( 'success' => false, 'message' => __( '动态不存在', 'qibbs-community' ) );
        }

        $post_id = (int) $post->id;
        $best_comment_id = absint( $post->best_comment_id );
        $current_status = $this->normalize_post_discussion_status( $post );

        if ( $enforce_permission && ! $this->user_can_manage_answer( $user_id, $post ) ) {
            return array( 'success' => false, 'message' => __( '没有权限', 'qibbs-community' ) );
        }

        if ( $best_comment_id <= 0 ) {
            return array(
                'success'           => true,
                'changed'           => false,
                'discussion_status' => $current_status,
            );
        }

        if ( $expected_comment_id > 0 && $best_comment_id !== absint( $expected_comment_id ) ) {
            return array(
                'success'           => true,
                'changed'           => false,
                'discussion_status' => $current_status,
            );
        }

        $next_status = ( 'closed' === $current_status ) ? 'closed' : 'discussion';
        if ( 'resolved' === $current_status ) {
            $is_admin = user_can( absint( $user_id ), 'manage_options' );
            $is_owner = isset( $post->author_id ) && (int) $post->author_id === absint( $user_id );
            $is_forum_moderator = isset( $post->forum_id ) && (int) $post->forum_id > 0 && function_exists( 'qibbs_user_can_moderate_forum' )
                ? (bool) qibbs_user_can_moderate_forum( absint( $user_id ), (int) $post->forum_id )
                : false;
            if ( $is_owner && ! $is_admin && ! $is_forum_moderator && ( ! $this->is_owner_reopen_enabled() || ! $this->is_owner_reopen_within_days( $post ) ) ) {
                $next_status = 'resolved';
            }
        }
        $legacy_resolved = in_array( $next_status, array( 'resolved', 'closed' ), true ) ? 1 : 0;

        global $wpdb;
        $posts_table = Qibbs_Database::posts();

        $updated = $wpdb->update(
            $posts_table,
            array(
                'best_comment_id'   => 0,
                'best_answer_at'    => null,
                'best_answer_by'    => 0,
                'discussion_status' => $next_status,
                'is_resolved'       => $legacy_resolved,
                'updated_at'        => current_time( 'mysql' ),
            ),
            array( 'id' => $post_id ),
            array( '%d', '%s', '%d', '%s', '%d', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return array( 'success' => false, 'message' => __( '操作失败，请稍后重试', 'qibbs-community' ) );
        }

        do_action( 'qibbs_post_edited', $post_id, absint( $user_id ) );

        return array(
            'success'           => true,
            'changed'           => true,
            'discussion_status' => $next_status,
        );
    }

    private function maybe_log_answer_moderator_action( $action, $actor_user_id, $post, $target_user_id = 0, $reason = '' ) {
        if ( ! function_exists( 'qibbs_log_moderator_action' ) || ! $post ) {
            return;
        }

        $actor_user_id = absint( $actor_user_id );
        $target_user_id = absint( $target_user_id );
        $post_author_id = isset( $post->author_id ) ? absint( $post->author_id ) : 0;

        $is_owner = $post_author_id > 0 && $post_author_id === $actor_user_id;
        $is_admin = user_can( $actor_user_id, 'manage_options' );
        $is_forum_moderator = isset( $post->forum_id ) && (int) $post->forum_id > 0 && function_exists( 'qibbs_user_can_moderate_forum' )
            ? qibbs_user_can_moderate_forum( $actor_user_id, (int) $post->forum_id )
            : false;

        if ( ! $is_owner && ( $is_admin || $is_forum_moderator ) ) {
            qibbs_log_moderator_action(
                $action,
                'post',
                (int) $post->id,
                $target_user_id,
                $reason
            );
        }
    }

    public function ajax_add_comment() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! $this->is_comments_enabled() ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'comments' ) ), 403 );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $content = isset( $_POST['content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['content'] ) ) : '';
        $parent_id = isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0;

        if ( function_exists( 'qibbs_check_user_comment_rate_limit' ) ) {
            $rate_check = qibbs_check_user_comment_rate_limit( $user_id );
            if ( ! $rate_check['allowed'] ) {
                wp_send_json_error( array( 'message' => $rate_check['message'] ) );
            }
        } elseif ( ! $this->check_comment_rate_limit( $user_id ) ) {
            wp_send_json_error( array( 'message' => __( '评论过于频繁，请稍后再试', 'qibbs-community' ) ) );
        }

        $result = $this->add_comment( $user_id, $post_id, $content, $parent_id );
        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    public function ajax_load_comments() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! $this->is_comments_enabled() ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'comments' ) ), 403 );
        }

        if ( function_exists( 'qibbs_require_login' ) && qibbs_require_login() && ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        if ( ! is_user_logged_in() && function_exists( 'qibbs_rate_limit_by_ip' ) && ! qibbs_rate_limit_by_ip( 'ajax_load_comments', 120, 60 ) ) {
            wp_send_json_error( array( 'message' => __( '请求过于频繁，请稍后再试', 'qibbs-community' ) ), 429 );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $per_page = 10;
        $offset = ( $page - 1 ) * $per_page;

        $result = $this->get_comments( $post_id, $per_page, $offset );

        wp_send_json_success(
            array(
                'comments'          => $result['comments'],
                'total'             => $result['total'],
                'comment_count'     => isset( $result['comment_count'] ) ? (int) $result['comment_count'] : $result['total'],
                'page'              => $page,
                'per_page'          => $per_page,
                'total_pages'       => $result['total_pages'],
                'has_more'          => $page < $result['total_pages'],
                'discussion_status' => isset( $result['discussion_status'] ) ? $result['discussion_status'] : 'discussion',
                'discussion_label'  => isset( $result['discussion_label'] ) ? $result['discussion_label'] : $this->get_discussion_status_label( 'discussion' ),
                'best_comment_id'   => isset( $result['best_comment_id'] ) ? (int) $result['best_comment_id'] : 0,
                'can_reopen'        => ! empty( $result['can_reopen'] ),
            )
        );
    }

    public function ajax_load_comment_replies() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! $this->is_comments_enabled() ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'comments' ) ), 403 );
        }

        if ( function_exists( 'qibbs_require_login' ) && qibbs_require_login() && ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        if ( ! is_user_logged_in() && function_exists( 'qibbs_rate_limit_by_ip' ) && ! qibbs_rate_limit_by_ip( 'ajax_load_comment_replies', 120, 60 ) ) {
            wp_send_json_error( array( 'message' => __( '请求过于频繁，请稍后再试', 'qibbs-community' ) ), 429 );
        }

        $comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
        $page = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 1;
        $per_page = 10;
        $offset = ( $page - 1 ) * $per_page;

        $result = $this->get_replies( $comment_id, $per_page, $offset );

        wp_send_json_success(
            array(
                'replies'     => isset( $result['replies'] ) ? $result['replies'] : array(),
                'total'       => isset( $result['total'] ) ? (int) $result['total'] : 0,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => isset( $result['total_pages'] ) ? (int) $result['total_pages'] : 0,
                'has_more'    => ! empty( $result['has_more'] ),
            )
        );
    }

    public function ajax_delete_comment() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! $this->is_comments_enabled() ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'comments' ) ), 403 );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
        $result = $this->delete_comment( $comment_id, $user_id );

        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    public function ajax_block_comment() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! $this->is_comments_enabled() ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'comments' ) ), 403 );
        }

        $comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
        $result = $this->block_comment( $comment_id );

        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    public function ajax_set_best_answer() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! $this->is_comments_enabled() ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'comments' ) ), 403 );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;

        $result = $this->set_best_answer( $post_id, $comment_id, $user_id );
        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    public function ajax_clear_best_answer() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! $this->is_comments_enabled() ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'comments' ) ), 403 );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $result = $this->clear_best_answer( $post_id, $user_id );

        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    public function ajax_toggle_quality_pin() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! $this->is_comments_enabled() ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'comments' ) ), 403 );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
        $result = $this->toggle_quality_pin( $comment_id, $user_id );

        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    public function ajax_toggle_comment_like() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! $this->is_comments_enabled() ) {
            wp_send_json_error( array( 'message' => qibbs_get_module_disabled_message( 'comments' ) ), 403 );
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
        if ( ! $comment_id ) {
            wp_send_json_error( array( 'message' => __( '评论不存在', 'qibbs-community' ) ) );
        }

        $can_like = apply_filters( 'qibbs_can_like_comment', true, $user_id, $comment_id );
        if ( ! $can_like ) {
            wp_send_json_error( array( 'message' => __( '没有点赞权限', 'qibbs-community' ) ) );
        }

        if ( ! $this->check_comment_like_rate_limit( $user_id ) ) {
            wp_send_json_error( array( 'message' => __( '操作过于频繁，请稍后再试', 'qibbs-community' ) ) );
        }

        $result = $this->toggle_comment_like( $user_id, $comment_id );
        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    public function ajax_reopen_post_question() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $result = $this->reopen_post_question( $post_id, $user_id );
        $result['success'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    /**
     * 评论发布频率限流检查
     *
     * @param int $user_id User ID.
     * @return bool
     */
    private function check_comment_rate_limit( $user_id ) {
        if ( function_exists( 'qibbs_check_user_comment_rate_limit' ) ) {
            $check = qibbs_check_user_comment_rate_limit( $user_id );
            return (bool) $check['allowed'];
        }

        $minute_limit = absint( qibbs_get_option( 'comment_rate_limit_minute', 6 ) );
        if ( $minute_limit > 0 && function_exists( 'qibbs_consume_scoped_rate_limit' ) ) {
            if ( ! qibbs_consume_scoped_rate_limit( 'comment_submit_minute', $user_id, $minute_limit, MINUTE_IN_SECONDS ) ) {
                return false;
            }
        }

        $hour_limit = max( 1, absint( qibbs_get_option( 'comment_rate_limit', 20 ) ) );
        if ( ! function_exists( 'qibbs_consume_scoped_rate_limit' ) ) {
            return true;
        }

        if ( ! qibbs_consume_scoped_rate_limit( 'comment_submit_hour', $user_id, $hour_limit, HOUR_IN_SECONDS ) ) {
            return false;
        }

        return true;
    }

    private function check_comment_like_rate_limit( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }

        $limit = max( 1, absint( qibbs_get_option( 'like_rate_limit', 100 ) ) );

        return function_exists( 'qibbs_consume_scoped_rate_limit' )
            ? qibbs_consume_scoped_rate_limit( 'comment_like_hour', $user_id, $limit, HOUR_IN_SECONDS )
            : true;
    }
}
