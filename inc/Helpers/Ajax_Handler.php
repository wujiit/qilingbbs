<?php
/**
 * 前台 AJAX 异步交互请求处理类
 *
 * @package Qibbs_Community
 * @subpackage Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Ajax_Handler {

    public function create_post() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        $payload = array(
            'content'              => isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '',
            'forum_id'             => isset( $_POST['forum_id'] ) ? absint( $_POST['forum_id'] ) : 0,
            'post_type'            => isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '',
            'media_ids'            => isset( $_POST['media_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['media_ids'] ) ) : '',
            'tags'                 => isset( $_POST['tags'] ) ? (array) wp_unslash( $_POST['tags'] ) : array(),
            'bounty_amount'        => isset( $_POST['bounty_amount'] ) ? floatval( wp_unslash( $_POST['bounty_amount'] ) ) : 0.0,
            'redpacket_amount'     => isset( $_POST['redpacket_amount'] ) ? floatval( wp_unslash( $_POST['redpacket_amount'] ) ) : 0.0,
            'redpacket_num'        => isset( $_POST['redpacket_num'] ) ? absint( wp_unslash( $_POST['redpacket_num'] ) ) : 0,
            'redpacket_draw_type'  => isset( $_POST['redpacket_draw_type'] ) ? sanitize_key( wp_unslash( $_POST['redpacket_draw_type'] ) ) : 'instant',
            'redpacket_alloc_type' => isset( $_POST['redpacket_alloc_type'] ) ? sanitize_key( wp_unslash( $_POST['redpacket_alloc_type'] ) ) : 'random',
        );

        $result = $this->create_post_from_payload( $user_id, $payload );
        if ( $result['success'] ) {
            wp_send_json_success( array(
                'post_id' => $result['post_id'],
                'message' => $result['message'],
            ) );
        }

        wp_send_json_error( array( 'message' => $result['message'] ) );
    }

    /**
     * 基于标准化数据创建社区帖子
     * 供 AJAX 与 REST 接口公用以防止业务逻辑漂移
     *
     * @param int   $user_id User ID.
     * @param array $payload Payload with content/forum_id/post_type/media_ids/tags.
     * @return array
     */
    public function create_post_from_payload( $user_id, $payload = array() ) {
        if ( ! $user_id ) {
            return $this->error_result( __( '请先登录', 'qibbs-community' ), 401 );
        }

        if ( ! $this->can_user_create_post( $user_id ) ) {
            return $this->error_result( __( '没有发帖权限', 'qibbs-community' ), 403 );
        }

        if ( function_exists( 'qibbs_check_user_post_rate_limit' ) ) {
            $rate_check = qibbs_check_user_post_rate_limit( $user_id );
            if ( ! $rate_check['allowed'] ) {
                return $this->error_result( $rate_check['message'], 429 );
            }
        } elseif ( ! $this->check_post_rate_limit( $user_id ) ) {
            return $this->error_result( __( '发帖过于频繁，请稍后再试', 'qibbs-community' ), 429 );
        }

        $content = isset( $payload['content'] ) ? qibbs_sanitize_content( (string) $payload['content'] ) : '';
        if ( empty( $content ) ) {
            return $this->error_result( __( '内容不能为空', 'qibbs-community' ), 400 );
        }

        $content = apply_filters( 'qibbs_post_content', $content, $user_id );

        $max_length = qibbs_get_option( 'max_post_length', 5000 );
        if ( mb_strlen( $content ) > $max_length ) {
            return $this->error_result( sprintf( __( '内容不能超过 %d 字符', 'qibbs-community' ), $max_length ), 400 );
        }

        list( $contains_banned_word, $banned_word ) = $this->detect_banned_word( $content );
        if ( $contains_banned_word ) {
            return $this->error_result( sprintf( __( '内容包含违规词：%s', 'qibbs-community' ), $banned_word ), 422 );
        }

        if ( class_exists( 'Qibbs_Risk_Service' ) ) {
            $risk_check = Qibbs_Risk_Service::evaluate_content_submission(
                $user_id,
                'post',
                $content,
                array(
                    'forum_id' => isset( $payload['forum_id'] ) ? absint( $payload['forum_id'] ) : 0,
                )
            );
            if ( is_wp_error( $risk_check ) ) {
                return $this->error_result( $risk_check->get_error_message(), 429 );
            }
        }

        global $wpdb;
        $table = Qibbs_Database::posts();
        $post_tags_table = Qibbs_Database::post_tags();
        $post_type = function_exists( 'qibbs_normalize_post_type' )
            ? qibbs_normalize_post_type( isset( $payload['post_type'] ) ? $payload['post_type'] : '' )
            : 'discussion';
        $validated_media_ids = qibbs_validate_media_ids_for_user(
            $user_id,
            isset( $payload['media_ids'] ) ? $payload['media_ids'] : '',
            array( 'strict' => true )
        );
        if ( is_wp_error( $validated_media_ids ) ) {
            return $this->error_result( $validated_media_ids->get_error_message(), 400 );
        }
        $media_ids = qibbs_media_ids_to_csv( $validated_media_ids );

        $status = qibbs_get_option( 'auto_approve_posts', true ) ? 'publish' : 'qibbs_pending';
        $post_data = array(
            'author_id'  => $user_id,
            'content'    => $content,
            'status'     => $status,
            'post_type'  => $post_type,
            'forum_id'   => isset( $payload['forum_id'] ) ? absint( $payload['forum_id'] ) : 0,
            'media_ids'  => $media_ids,
            'created_at' => current_time( 'mysql' ),
        );
        $post_data = apply_filters( 'qibbs_pre_insert_post', $post_data, $user_id );

        $result = $wpdb->insert( $table, $post_data, array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' ) );
        if ( ! $result ) {
            return $this->error_result( __( '发布失败', 'qibbs-community' ), 500 );
        }

        $post_id = (int) $wpdb->insert_id;

        // 如果是悬赏帖子，联动创建悬赏记录并冻结积分
        if ( 'bounty' === $post_type ) {
            if ( ! class_exists( 'Qibbs_Bounty_Service' ) || ! Qibbs_Bounty_Service::instance()->is_enabled() ) {
                $wpdb->delete( $table, array( 'id' => $post_id ), array( '%d' ) );
                return $this->error_result( __( '悬赏功能暂未开启', 'qibbs-community' ), 400 );
            }
            $bounty_amount = isset( $payload['bounty_amount'] ) ? floatval( $payload['bounty_amount'] ) : 0.0;
            $bounty_result = Qibbs_Bounty_Service::instance()->create_bounty( $post_id, $user_id, $bounty_amount );
            if ( empty( $bounty_result['success'] ) ) {
                $wpdb->delete( $table, array( 'id' => $post_id ), array( '%d' ) );
                return $this->error_result( $bounty_result['message'], 400 );
            }
        }

        // 如果是红包帖子，联动创建红包记录并冻结积分
        if ( 'redpacket' === $post_type ) {
            if ( ! class_exists( 'Qibbs_Redpacket_Service' ) || ! Qibbs_Redpacket_Service::instance()->is_enabled() ) {
                $wpdb->delete( $table, array( 'id' => $post_id ), array( '%d' ) );
                return $this->error_result( __( '红包功能暂未开启', 'qibbs-community' ), 400 );
            }
            $redpacket_amount = isset( $payload['redpacket_amount'] ) ? floatval( $payload['redpacket_amount'] ) : 0.0;
            $redpacket_num    = isset( $payload['redpacket_num'] ) ? absint( $payload['redpacket_num'] ) : 0;
            $draw_type        = isset( $payload['redpacket_draw_type'] ) ? sanitize_key( $payload['redpacket_draw_type'] ) : 'instant';
            $alloc_type       = isset( $payload['redpacket_alloc_type'] ) ? sanitize_key( $payload['redpacket_alloc_type'] ) : 'random';
            $packet_result    = Qibbs_Redpacket_Service::instance()->create_redpacket( $post_id, $user_id, $redpacket_amount, $redpacket_num, $draw_type, $alloc_type );
            if ( empty( $packet_result['success'] ) ) {
                $wpdb->delete( $table, array( 'id' => $post_id ), array( '%d' ) );
                return $this->error_result( $packet_result['message'], 400 );
            }
        }

        $tag_service = new Qibbs_Tag_Service();
        $tag_service->process_content_tags( $post_id, $content );

        $raw_tags = isset( $payload['tags'] ) ? $payload['tags'] : array();
        if ( is_string( $raw_tags ) ) {
            $raw_tags = explode( ',', $raw_tags );
        }
        $explicit_tags = array_filter( array_map( 'sanitize_text_field', (array) $raw_tags ) );
        if ( ! empty( $explicit_tags ) ) {
            $tag_service->attach_tags_to_post( $post_id, $explicit_tags );
        }

        $stats_table = Qibbs_Database::user_stats();
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$stats_table} (user_id, post_count) VALUES (%d, 1) 
             ON DUPLICATE KEY UPDATE post_count = post_count + 1",
            $user_id
        ) );

        do_action( 'qibbs_post_created', $post_id, $user_id );

        return array(
            'success' => true,
            'post_id' => $post_id,
            'status'  => $status,
            'message' => ( 'publish' === $status ) ? __( '发布成功', 'qibbs-community' ) : __( '已提交，等待审核', 'qibbs-community' ),
        );
    }

    /**
     * 校验用户是否具备发帖权限
     *
     * @param int $user_id User ID.
     * @return bool
     */
    private function can_user_create_post( $user_id ) {
        if ( ! qibbs_user_can_post( $user_id ) ) {
            return false;
        }

        if (
            function_exists( 'qibbs_is_user_blacklisted' ) &&
            qibbs_is_user_blacklisted( $user_id ) &&
            ! user_can( $user_id, 'manage_options' )
        ) {
            return false;
        }

        return (bool) apply_filters( 'qibbs_can_create_post', true, $user_id );
    }

    /**
     * 敏感词检测与内容安全阻断
     *
     * @param string $content Content.
     * @return array [bool $has_banned_word, string $word]
     */
    private function detect_banned_word( $content ) {
        if ( ! qibbs_get_option( 'enable_banned_words', false ) ) {
            return array( false, '' );
        }

        $banned_words_str = qibbs_get_option( 'banned_words', '' );
        if ( empty( $banned_words_str ) ) {
            return array( false, '' );
        }

        $banned_words = array_filter( array_map( 'trim', explode( ',', $banned_words_str ) ) );
        foreach ( $banned_words as $word ) {
            if ( mb_stripos( $content, $word ) !== false ) {
                return array( true, $word );
            }
        }

        return array( false, '' );
    }

    /**
     * 构建统一规范的错误响应
     *
     * @param string $message Error message.
     * @param int    $code    HTTP status code.
     * @return array
     */
    private function error_result( $message, $code = 400 ) {
        return array(
            'success' => false,
            'message' => $message,
            'code'    => (int) $code,
        );
    }

    public function delete_post() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        if ( ! $user_id || ! $post_id ) {
            wp_send_json_error( array( 'message' => __( '参数错误', 'qibbs-community' ) ) );
        }

        global $wpdb;
        $table = Qibbs_Database::posts();

        // 验证作者权限并生成删除前的内容快照
        $post = $wpdb->get_row( $wpdb->prepare(
            "SELECT author_id, content, forum_id, status FROM {$table} WHERE id = %d",
            $post_id
        ) );

        if ( ! $post ) {
            wp_send_json_error( array( 'message' => __( '帖子不存在', 'qibbs-community' ) ) );
        }

        $is_forum_moderator = (int) $post->forum_id > 0 && function_exists( 'qibbs_user_can_moderate_forum' )
            ? qibbs_user_can_moderate_forum( $user_id, (int) $post->forum_id )
            : false;

        if ( $post->author_id != $user_id && ! current_user_can( 'manage_options' ) && ! $is_forum_moderator ) {
            wp_send_json_error( array( 'message' => __( '没有删除权限', 'qibbs-community' ) ) );
        }
        $is_moderation_action = ( $post->author_id != $user_id && ( current_user_can( 'manage_options' ) || $is_forum_moderator ) );
        
        // 删除前备份正文摘要供通知使用
        $content_preview = '';
        if ( $is_moderation_action ) {
            $content_preview = mb_substr( wp_strip_all_tags( $post->content ), 0, 30 );
            if ( mb_strlen( wp_strip_all_tags( $post->content ) ) > 30 ) {
                $content_preview .= '...';
            }
        }
        
        if ( 'qibbs_trashed' === (string) $post->status ) {
            wp_send_json_success( array( 'message' => __( '该动态已在回收站', 'qibbs-community' ) ) );
        }

        $result = $wpdb->update(
            $table,
            array( 'status' => 'qibbs_trashed' ),
            array( 'id' => $post_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( false !== $result ) {
            // 管理员/版主删帖时向原作者投递通知
            if ( $is_moderation_action ) {
                $notification_service = new Qibbs_Notification_Service();
                $notification_service->create_admin_notification( $post->author_id, 'post_deleted', $post_id, $user_id, $content_preview );
                if ( function_exists( 'qibbs_log_moderator_action' ) ) {
                    qibbs_log_moderator_action(
                        'moderator_delete_post',
                        'post',
                        $post_id,
                        (int) $post->author_id,
                        $content_preview
                    );
                }
            }
            
            do_action( 'qibbs_post_deleted', $post_id, $user_id );
            wp_send_json_success( array( 'message' => __( '已移入回收站', 'qibbs-community' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( '删除失败', 'qibbs-community' ) ) );
        }
    }

    /**
     * 屏蔽/隐藏帖子（管理员或版主权限）
     */
    public function block_post() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => __( '参数错误', 'qibbs-community' ) ) );
        }

        global $wpdb;
        $table = Qibbs_Database::posts();

        // 屏蔽前拉取帖子基础信息
        $post = $wpdb->get_row( $wpdb->prepare(
            "SELECT author_id, content, forum_id FROM {$table} WHERE id = %d",
            $post_id
        ) );

        if ( ! $post ) {
            wp_send_json_error( array( 'message' => __( '帖子不存在', 'qibbs-community' ) ) );
        }

        $is_admin = current_user_can( 'manage_options' );
        $is_forum_moderator = (int) $post->forum_id > 0 && function_exists( 'qibbs_user_can_moderate_forum' )
            ? qibbs_user_can_moderate_forum( $user_id, (int) $post->forum_id )
            : false;

        if ( ! $is_admin && ! $is_forum_moderator ) {
            wp_send_json_error( array( 'message' => __( '没有权限', 'qibbs-community' ) ) );
        }

        $result = $wpdb->update(
            $table,
            array( 'status' => 'qibbs_blocked' ),
            array( 'id' => $post_id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( $result !== false ) {
            // 向被屏蔽帖子的作者推送通知及正文快照
            $content_preview = mb_substr( wp_strip_all_tags( $post->content ), 0, 30 );
            if ( mb_strlen( wp_strip_all_tags( $post->content ) ) > 30 ) {
                $content_preview .= '...';
            }
            
            if ( (int) $post->author_id !== (int) $user_id ) {
                $notification_service = new Qibbs_Notification_Service();
                $notification_service->create_admin_notification( $post->author_id, 'post_blocked', $post_id, $user_id, $content_preview );
            }
            if ( function_exists( 'qibbs_log_moderator_action' ) ) {
                qibbs_log_moderator_action(
                    'moderator_block_post',
                    'post',
                    $post_id,
                    (int) $post->author_id,
                    $content_preview
                );
            }
            
            do_action( 'qibbs_post_blocked', $post_id );
            wp_send_json_success( array( 'message' => __( '已屏蔽', 'qibbs-community' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( '操作失败', 'qibbs-community' ) ) );
        }
    }

    /**
     * 更新帖子讨论状态（讨论中/已结贴/已关闭）
     */
    public function update_post_discussion_status() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $new_status = isset( $_POST['discussion_status'] ) ? sanitize_key( wp_unslash( $_POST['discussion_status'] ) ) : '';

        if ( ! $user_id || ! $post_id ) {
            wp_send_json_error( array( 'message' => __( '参数错误', 'qibbs-community' ) ) );
        }

        $allowed_statuses = array( 'discussion', 'resolved', 'closed' );
        if ( ! in_array( $new_status, $allowed_statuses, true ) ) {
            wp_send_json_error( array( 'message' => __( '无效的讨论状态', 'qibbs-community' ) ) );
        }

        global $wpdb;
        $posts_table = Qibbs_Database::posts();
        $post = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, author_id, forum_id, status, discussion_status, is_resolved, best_comment_id, created_at
                 FROM {$posts_table}
                 WHERE id = %d LIMIT 1",
                $post_id
            )
        );

        if ( ! $post ) {
            wp_send_json_error( array( 'message' => __( '动态不存在', 'qibbs-community' ) ) );
        }

        if ( 'publish' !== (string) $post->status ) {
            wp_send_json_error( array( 'message' => __( '该动态当前不可调整讨论状态', 'qibbs-community' ) ) );
        }

        if (
            ! function_exists( 'qibbs_is_discussion_status_enabled_for_forum' ) ||
            ! qibbs_is_discussion_status_enabled_for_forum( (int) $post->forum_id )
        ) {
            wp_send_json_error( array( 'message' => __( '该圈子未启用讨论状态体系', 'qibbs-community' ) ) );
        }

        $is_admin = current_user_can( 'manage_options' );
        $is_owner = ( (int) $post->author_id === (int) $user_id );
        $is_forum_moderator = (int) $post->forum_id > 0 && function_exists( 'qibbs_user_can_moderate_forum' )
            ? qibbs_user_can_moderate_forum( $user_id, (int) $post->forum_id )
            : false;

        if ( ! $is_admin && ! $is_owner && ! $is_forum_moderator ) {
            wp_send_json_error( array( 'message' => __( '没有权限', 'qibbs-community' ) ) );
        }

        $old_status = function_exists( 'qibbs_normalize_discussion_status' )
            ? qibbs_normalize_discussion_status(
                isset( $post->discussion_status ) ? (string) $post->discussion_status : '',
                ! empty( $post->is_resolved ) ? 1 : 0
            )
            : ( ! empty( $post->is_resolved ) ? 'resolved' : 'discussion' );

        $is_owner_reopen_action = ( 'discussion' === $new_status && 'discussion' !== $old_status );
        if ( 'discussion' === $new_status && 'closed' === $old_status && $is_owner && ! $is_admin && ! $is_forum_moderator ) {
            wp_send_json_error( array( 'message' => __( '该动态已关闭，需管理员或版主重开', 'qibbs-community' ) ) );
        }

        if ( $is_owner_reopen_action && $is_owner && ! $is_admin && ! $is_forum_moderator && ! qibbs_get_option( 'enable_owner_reopen_question', false ) ) {
            wp_send_json_error( array( 'message' => __( '当前未启用楼主追问/重开功能', 'qibbs-community' ) ) );
        }
        if ( $is_owner_reopen_action && $is_owner && ! $is_admin && ! $is_forum_moderator ) {
            $owner_reopen_max_days = max( 1, min( 3650, absint( qibbs_get_option( 'owner_reopen_max_days', 30 ) ) ) );
            $created_timestamp = ! empty( $post->created_at ) ? qibbs_datetime_to_timestamp( (string) $post->created_at ) : 0;
            $within_window = $created_timestamp && ( qibbs_current_timestamp() <= ( $created_timestamp + ( DAY_IN_SECONDS * $owner_reopen_max_days ) ) );
            if ( ! $within_window ) {
                wp_send_json_error( array( 'message' => sprintf( __( '仅允许在发布后 %d 天内重开，已超出时限', 'qibbs-community' ), $owner_reopen_max_days ) ) );
            }
        }

        if ( $old_status === $new_status ) {
            wp_send_json_success( array(
                'message'           => __( '讨论状态未变化', 'qibbs-community' ),
                'discussion_status' => $new_status,
            ) );
        }

        // 保持向后兼容：同步维护旧版 is_resolved 字段
        $legacy_resolved = in_array( $new_status, array( 'resolved', 'closed' ), true ) ? 1 : 0;
        $update_data = array(
            'discussion_status' => $new_status,
            'is_resolved'       => $legacy_resolved,
            'updated_at'        => current_time( 'mysql' ),
        );
        $update_format = array( '%s', '%d', '%s' );

        // 状态联动：重置回讨论中状态时清空最佳答案字段
        if ( 'discussion' === $new_status && ! empty( $post->best_comment_id ) ) {
            $update_data['best_comment_id'] = 0;
            $update_data['best_answer_at']  = null;
            $update_data['best_answer_by']  = 0;
            $update_format[] = '%d';
            $update_format[] = '%s';
            $update_format[] = '%d';
        }

        $updated = $wpdb->update(
            $posts_table,
            $update_data,
            array( 'id' => $post_id ),
            $update_format,
            array( '%d' )
        );

        if ( false === $updated ) {
            wp_send_json_error( array( 'message' => __( '更新失败，请稍后重试', 'qibbs-community' ) ) );
        }

        if ( ( $is_admin || $is_forum_moderator ) && ! $is_owner && function_exists( 'qibbs_log_moderator_action' ) ) {
            $log_action = ( 'discussion' === $new_status && 'discussion' !== $old_status )
                ? 'moderator_reopen_question'
                : 'moderator_update_discussion_status';
            qibbs_log_moderator_action(
                $log_action,
                'post',
                $post_id,
                (int) $post->author_id,
                $old_status . ' -> ' . $new_status
            );
        }

        do_action( 'qibbs_post_edited', $post_id, $user_id );

        $status_labels = array(
            'discussion' => __( '讨论中', 'qibbs-community' ),
            'resolved'   => __( '已解决', 'qibbs-community' ),
            'closed'     => __( '已关闭', 'qibbs-community' ),
        );

        wp_send_json_success( array(
            'message'           => sprintf( __( '讨论状态已更新为：%s', 'qibbs-community' ), $status_labels[ $new_status ] ),
            'discussion_status' => $new_status,
            'label'             => $status_labels[ $new_status ],
        ) );
    }

    public function upload_media() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ) );
        }

        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( array( 'message' => __( '没有文件上传', 'qibbs-community' ) ) );
        }

        $file = $_FILES['file'];

        // 1. 验证文件大小
        $max_size_mb = qibbs_get_option( 'max_upload_size', 5 );
        $max_size_bytes = $max_size_mb * 1024 * 1024;
        if ( $file['size'] > $max_size_bytes ) {
            wp_send_json_error( array( 
                'message' => sprintf( __( '文件大小不能超过 %dMB', 'qibbs-community' ), $max_size_mb ) 
            ) );
        }

        // 2. 验证文件扩展名
        $filename = strtolower( $file['name'] );
        $ext = pathinfo( $filename, PATHINFO_EXTENSION );
        // 防御性补全：剪贴板粘贴的临时文件可能无扩展名或名为 blob，自动从真实 MIME 中智能推导
        if ( empty( $ext ) && ! empty( $file['type'] ) ) {
            $fallback_ext_map = array(
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
            );
            if ( isset( $fallback_ext_map[ $file['type'] ] ) ) {
                $ext = $fallback_ext_map[ $file['type'] ];
                $file['name'] = ( empty( $filename ) || 'blob' === $filename ? 'paste-' . time() : $filename ) . '.' . $ext;
            }
        }
        $allowed_formats_str = qibbs_get_option( 'allowed_image_formats', 'jpg,jpeg,png,webp,gif' );
        $allowed_formats = array_map( 'trim', explode( ',', strtolower( $allowed_formats_str ) ) );
        $allowed_formats = array_filter( $allowed_formats ); // 清除空数据项

        $image_mime_map = array(
            'jpg'  => array( 'image/jpeg' ),
            'jpeg' => array( 'image/jpeg' ),
            'png'  => array( 'image/png' ),
            'gif'  => array( 'image/gif' ),
            'webp' => array( 'image/webp' ),
            'bmp'  => array( 'image/bmp' ),
            'ico'  => array( 'image/x-icon', 'image/vnd.microsoft.icon' ),
            'svg'  => array( 'image/svg+xml' ),
        );
        $video_mime_map = array(
            'mp4'  => 'video/mp4',
            'webm' => 'video/webm',
            'mov'  => 'video/quicktime',
            'avi'  => 'video/x-msvideo',
            'mkv'  => 'video/x-matroska',
        );

        // 检查上传文件是否属于视频格式
        $video_formats = array( 'mp4', 'webm', 'mov', 'avi', 'mkv' );
        $is_video = in_array( $ext, $video_formats, true );
        $is_allowed_image = in_array( $ext, $allowed_formats, true );

        if ( ! $is_video && ! $is_allowed_image ) {
            wp_send_json_error( array( 
                'message' => sprintf( 
                    __( '不支持的文件格式。允许的图片格式：%s', 'qibbs-community' ), 
                    $allowed_formats_str 
                ) 
            ) );
        }

        // 构建允许上传的 MIME 类型字典
        $allowed_upload_mimes = array();
        foreach ( $allowed_formats as $format ) {
            if ( isset( $image_mime_map[ $format ] ) ) {
                $allowed_upload_mimes[ $format ] = $image_mime_map[ $format ][0];
            }
        }
        foreach ( $video_mime_map as $video_ext => $video_mime ) {
            $allowed_upload_mimes[ $video_ext ] = $video_mime;
        }

        // 3. 验证真实 MIME 类型 (防止伪造文件扩展名)
        $checked_file = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
        $detected_ext = strtolower( (string) $checked_file['ext'] );
        $real_mime = strtolower( (string) $checked_file['type'] );
        if ( empty( $real_mime ) ) {
            $real_mime = $this->detect_real_mime_type( $file['tmp_name'] );
        }

        if ( $is_video ) {
            $allowed_video_mimes = array_values( $video_mime_map );
            if ( ! in_array( $real_mime, $allowed_video_mimes, true ) ) {
                wp_send_json_error( array(
                    'message' => __( '文件类型验证失败，请上传真实的视频文件', 'qibbs-community' ),
                ) );
            }

            if ( ! empty( $detected_ext ) && ! in_array( $detected_ext, $video_formats, true ) ) {
                wp_send_json_error( array(
                    'message' => __( '视频扩展名与内容不匹配，请重新上传', 'qibbs-community' ),
                ) );
            }
        }

        if ( ! $is_video ) {
            // 构建允许的 MIME 类型列表
            $allowed_mimes = array();
            foreach ( $allowed_formats as $format ) {
                if ( isset( $image_mime_map[ $format ] ) ) {
                    $allowed_mimes = array_merge( $allowed_mimes, $image_mime_map[ $format ] );
                }
            }
            $allowed_mimes = array_unique( $allowed_mimes );

            if ( ! in_array( $real_mime, $allowed_mimes ) ) {
                wp_send_json_error( array( 
                    'message' => __( '文件类型验证失败，请上传真实的图片文件', 'qibbs-community' ) 
                ) );
            }

            // 额外验证：使用 getimagesize 检查是否为有效图片
            $image_info = @getimagesize( $file['tmp_name'] );
            if ( $image_info === false ) {
                wp_send_json_error( array( 
                    'message' => __( '无效的图片文件', 'qibbs-community' ) 
                ) );
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $upload = wp_handle_upload( $file, array(
            'test_form' => false,
        ) );

        if ( isset( $upload['error'] ) ) {
            wp_send_json_error( array( 'message' => $upload['error'] ) );
        }

        $file_type = wp_check_filetype_and_ext( $upload['file'], wp_basename( $upload['file'] ) );
        $detected_type = ! empty( $file_type['type'] ) ? $file_type['type'] : '';
        if ( empty( $detected_type ) ) {
            $fallback_type = wp_check_filetype( $upload['file'] );
            $detected_type = ! empty( $fallback_type['type'] ) ? $fallback_type['type'] : '';
        }
        if ( empty( $detected_type ) ) {
            $detected_type = ! empty( $real_mime ) ? $real_mime : ( $is_video ? 'video/mp4' : 'image/jpeg' );
        }
        $type = ( strpos( (string) $detected_type, 'image/' ) === 0 ) ? 'image' : 'video';

        // 1. 同步注册到 WordPress 原生核心媒体库（wp-admin/upload.php），保证站长后台可见
        $attachment_id = 0;
        $sync_to_wp_media = (bool) qibbs_get_option( 'sync_to_wp_media_library', true );
        if ( $sync_to_wp_media ) {
            $attachment = array(
                'guid'           => $upload['url'],
                'post_mime_type' => $detected_type,
                'post_title'     => preg_replace( '/\.[^.]+$/', '', wp_basename( $upload['file'] ) ),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_author'    => $user_id,
            );

            $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
            if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
                $attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
                if ( ! empty( $attach_data ) && ! is_wp_error( $attach_data ) ) {
                    wp_update_attachment_metadata( $attachment_id, $attach_data );
                }
            }
        }

        // 获取最终生效的文件物理路径与访问 URL（自动联动主题原生 WebP 转换与 qilingstorage 对象存储 CDN 重写）
        $final_file = $upload['file'];
        $final_url  = $upload['url'];
        if ( $attachment_id > 0 ) {
            $attached_file = get_attached_file( $attachment_id );
            if ( ! empty( $attached_file ) && file_exists( $attached_file ) ) {
                $final_file = $attached_file;
            }
            $attachment_url = wp_get_attachment_url( $attachment_id );
            if ( ! empty( $attachment_url ) ) {
                $final_url = $attachment_url;
            }
        }

        // 2. 写入社区媒体附件数据表（wp_qibbs_media），保证社区动态与九宫格原生查询兼容
        global $wpdb;
        $table = Qibbs_Database::media();

        $wpdb->insert( $table, array(
            'user_id'   => $user_id,
            'file_path' => $final_file,
            'file_url'  => $final_url,
            'file_type' => $type,
            'file_size' => file_exists( $final_file ) ? filesize( $final_file ) : 0,
            'mime_type' => $detected_type,
            'created_at'=> current_time( 'mysql' ),
        ) );
        $media_id = (int) $wpdb->insert_id;

        if ( $attachment_id > 0 && $media_id > 0 ) {
            update_post_meta( $attachment_id, '_qibbs_media_id', $media_id );
        }

        wp_send_json_success( array(
            'id'            => $media_id,
            'attachment_id' => $attachment_id,
            'url'           => $final_url,
        ) );
    }

    /**
     * 探针检测文件真实 MIME 类型
     *
     * @param string $tmp_path Temporary file path.
     * @return string
     */
    private function detect_real_mime_type( $tmp_path ) {
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $mime = finfo_file( $finfo, $tmp_path );
                finfo_close( $finfo );
                return strtolower( (string) $mime );
            }
        }

        return '';
    }

    private function check_post_rate_limit( $user_id ) {
        if ( function_exists( 'qibbs_check_user_post_rate_limit' ) ) {
            $check = qibbs_check_user_post_rate_limit( $user_id );
            return (bool) $check['allowed'];
        }

        $minute_limit = absint( qibbs_get_option( 'post_rate_limit_minute', 2 ) );
        if ( $minute_limit > 0 && function_exists( 'qibbs_consume_scoped_rate_limit' ) ) {
            if ( ! qibbs_consume_scoped_rate_limit( 'post_submit_minute', $user_id, $minute_limit, MINUTE_IN_SECONDS ) ) {
                return false;
            }
        }

        $hour_limit = max( 1, absint( qibbs_get_option( 'post_rate_limit', 5 ) ) );
        if ( ! function_exists( 'qibbs_consume_scoped_rate_limit' ) ) {
            return true;
        }

        if ( ! qibbs_consume_scoped_rate_limit( 'post_submit_hour', $user_id, $hour_limit, HOUR_IN_SECONDS ) ) {
            return false;
        }

        return true;
    }

    /**
     * @提及搜索接口单用户频率限流校验
     *
     * @param int $user_id User ID.
     * @return bool
     */
    private function check_search_user_rate_limit( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }

        return function_exists( 'qibbs_consume_scoped_rate_limit' )
            ? qibbs_consume_scoped_rate_limit( 'search_users_minute', $user_id, 120, MINUTE_IN_SECONDS )
            : true;
    }

    /**
     * 计算检索关键词匹配相关度权重得分
     *
     * @param string $query          Search query.
     * @param array  $values         Candidate values.
     * @param int    $contains_score Contains score.
     * @param int    $prefix_score   Prefix match score.
     * @param int    $exact_score    Exact match score.
     * @return int
     */
    private function calculate_search_match_score( $query, $values, $contains_score, $prefix_score, $exact_score ) {
        $query = trim( (string) $query );
        if ( '' === $query ) {
            return 0;
        }

        $normalize = function( $value ) {
            $value = trim( wp_strip_all_tags( (string) $value ) );
            if ( function_exists( 'mb_strtolower' ) ) {
                return mb_strtolower( $value, 'UTF-8' );
            }

            return strtolower( $value );
        };

        $normalized_query = $normalize( $query );
        $score            = 0;

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
     * 追踪候选帖子的最高得分与命中原因
     *
     * @param array  $score_map  Score map.
     * @param array  $reason_map Match reason map.
     * @param int    $post_id    Post ID.
     * @param int    $score      Relevance score.
     * @param string $reason     Match reason.
     * @return void
     */
    private function add_search_post_candidate( &$score_map, &$reason_map, $post_id, $score, $reason ) {
        $post_id = absint( $post_id );
        $score   = absint( $score );

        if ( ! $post_id || ! $score ) {
            return;
        }

        if ( ! isset( $score_map[ $post_id ] ) || $score > $score_map[ $post_id ] ) {
            $score_map[ $post_id ] = $score;
        }

        if ( ! isset( $reason_map[ $post_id ] ) ) {
            $reason_map[ $post_id ] = array();
        }

        if ( ! in_array( $reason, $reason_map[ $post_id ], true ) ) {
            $reason_map[ $post_id ][] = $reason;
        }
    }

    /**
     * 围绕命中关键词截取紧凑摘要片段
     *
     * @param string $content Full content.
     * @param string $query   Search query.
     * @param int    $length  Excerpt length.
     * @return string
     */
    private function build_search_excerpt( $content, $query, $length = 72 ) {
        $content = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $content ) ) );
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

        $start   = max( 0, (int) $position - 12 );
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
     * 将命中原因标识转化为友好中文标签
     *
     * @param array $match_types Match types.
     * @return string
     */
    private function get_search_match_label( $match_types ) {
        $labels = array(
            'tag'     => __( '标签匹配', 'qibbs-community' ),
            'forum'   => __( '圈子匹配', 'qibbs-community' ),
            'author'  => __( '作者匹配', 'qibbs-community' ),
            'content' => __( '内容匹配', 'qibbs-community' ),
        );

        $ordered = array( 'tag', 'forum', 'author', 'content' );
        $parts   = array();

        foreach ( $ordered as $type ) {
            if ( in_array( $type, (array) $match_types, true ) && isset( $labels[ $type ] ) ) {
                $parts[] = $labels[ $type ];
            }
        }

        return implode( ' / ', $parts );
    }

    /**
     * 综合检索帖子：涵盖正文、标签、作者与所属版块
     */
    public function search_posts() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( function_exists( 'qibbs_require_login' ) && qibbs_require_login() && ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        if ( ! is_user_logged_in() && function_exists( 'qibbs_rate_limit_by_ip' ) && ! qibbs_rate_limit_by_ip( 'ajax_search_posts', 60, 60 ) ) {
            wp_send_json_error( array( 'message' => __( '请求过于频繁，请稍后再试', 'qibbs-community' ) ), 429 );
        }

        $query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
        $query = trim( preg_replace( '/\s+/u', ' ', $query ) );

        if ( mb_strlen( $query ) < 2 ) {
            wp_send_json_success( array( 'posts' => array() ) );
        }

        $lookup_query = trim( preg_replace( '/^[#＃@＠]+/u', '', $query ) );
        if ( '' === $lookup_query ) {
            $lookup_query = $query;
        }

        if ( class_exists( 'Qibbs_Search_Index_Service' ) ) {
            $search_index = new Qibbs_Search_Index_Service();
            $indexed_posts = $search_index->search_suggestions( $lookup_query, 10, 24 );
            if ( is_array( $indexed_posts ) ) {
                wp_send_json_success( array( 'posts' => $indexed_posts ) );
            }
        }

        global $wpdb;
        $posts_table     = Qibbs_Database::posts();
        $forums_table    = Qibbs_Database::forums();
        $tags_table      = Qibbs_Database::tags();
        $post_tags_table = Qibbs_Database::post_tags();
        $query_like      = '%' . $wpdb->esc_like( $lookup_query ) . '%';
        $candidate_limit = 24;
        $display_limit   = 10;
        $score_map       = array();
        $reason_map      = array();

        $forum_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, slug, post_count
                 FROM {$forums_table}
                 WHERE name LIKE %s OR slug LIKE %s
                 ORDER BY post_count DESC, id DESC
                 LIMIT 6",
                $query_like,
                $query_like
            )
        );

        $forum_scores = array();
        foreach ( (array) $forum_rows as $forum_row ) {
            $forum_scores[ (int) $forum_row->id ] = $this->calculate_search_match_score(
                $lookup_query,
                array( $forum_row->name, $forum_row->slug ),
                120,
                136,
                152
            );
        }

        if ( ! empty( $forum_scores ) ) {
            $forum_placeholders = implode( ',', array_fill( 0, count( $forum_scores ), '%d' ) );
            $forum_posts        = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, forum_id
                     FROM {$posts_table}
                     WHERE status = 'publish' AND forum_id IN ({$forum_placeholders})
                     ORDER BY is_pinned DESC, created_at DESC
                     LIMIT %d",
                    array_merge( array_keys( $forum_scores ), array( $candidate_limit ) )
                )
            );

            foreach ( (array) $forum_posts as $forum_post ) {
                $forum_id = (int) $forum_post->forum_id;
                $this->add_search_post_candidate( $score_map, $reason_map, $forum_post->id, isset( $forum_scores[ $forum_id ] ) ? $forum_scores[ $forum_id ] : 120, 'forum' );
            }
        }

        $tag_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, slug, post_count
                 FROM {$tags_table}
                 WHERE name LIKE %s OR slug LIKE %s
                 ORDER BY post_count DESC, id DESC
                 LIMIT 8",
                $query_like,
                $query_like
            )
        );

        $tag_scores = array();
        foreach ( (array) $tag_rows as $tag_row ) {
            $tag_scores[ (int) $tag_row->id ] = $this->calculate_search_match_score(
                $lookup_query,
                array( $tag_row->name, $tag_row->slug ),
                124,
                140,
                158
            );
        }

        if ( ! empty( $tag_scores ) ) {
            $tag_placeholders = implode( ',', array_fill( 0, count( $tag_scores ), '%d' ) );
            $tag_posts        = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pt.post_id, pt.tag_id
                     FROM {$post_tags_table} pt
                     INNER JOIN {$posts_table} p ON p.id = pt.post_id
                     WHERE pt.tag_id IN ({$tag_placeholders}) AND p.status = 'publish'
                     ORDER BY p.is_pinned DESC, p.created_at DESC
                     LIMIT %d",
                    array_merge( array_keys( $tag_scores ), array( $candidate_limit * 2 ) )
                )
            );

            foreach ( (array) $tag_posts as $tag_post ) {
                $tag_id = (int) $tag_post->tag_id;
                $this->add_search_post_candidate( $score_map, $reason_map, $tag_post->post_id, isset( $tag_scores[ $tag_id ] ) ? $tag_scores[ $tag_id ] : 124, 'tag' );
            }
        }

        $user_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, user_nicename, display_name
                 FROM {$wpdb->users}
                 WHERE user_nicename LIKE %s OR display_name LIKE %s
                 ORDER BY ID DESC
                 LIMIT 6",
                $query_like,
                $query_like
            )
        );

        $author_scores = array();
        foreach ( (array) $user_rows as $user_row ) {
            $author_scores[ (int) $user_row->ID ] = $this->calculate_search_match_score(
                $lookup_query,
                array( $user_row->user_nicename, $user_row->display_name ),
                112,
                126,
                144
            );
        }

        if ( ! empty( $author_scores ) ) {
            $author_placeholders = implode( ',', array_fill( 0, count( $author_scores ), '%d' ) );
            $author_posts        = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, author_id
                     FROM {$posts_table}
                     WHERE status = 'publish' AND author_id IN ({$author_placeholders})
                     ORDER BY is_pinned DESC, created_at DESC
                     LIMIT %d",
                    array_merge( array_keys( $author_scores ), array( $candidate_limit ) )
                )
            );

            foreach ( (array) $author_posts as $author_post ) {
                $author_id = (int) $author_post->author_id;
                $this->add_search_post_candidate( $score_map, $reason_map, $author_post->id, isset( $author_scores[ $author_id ] ) ? $author_scores[ $author_id ] : 112, 'author' );
            }
        }

        if ( count( $score_map ) < $display_limit ) {
            $content_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, content
                     FROM {$posts_table}
                     WHERE status = 'publish' AND content LIKE %s
                     ORDER BY is_pinned DESC, created_at DESC
                     LIMIT %d",
                    $query_like,
                    $candidate_limit
                )
            );

            foreach ( (array) $content_rows as $content_row ) {
                $content_score = $this->calculate_search_match_score(
                    $lookup_query,
                    array( wp_strip_all_tags( $content_row->content ) ),
                    72,
                    88,
                    104
                );
                $this->add_search_post_candidate( $score_map, $reason_map, $content_row->id, $content_score, 'content' );
            }
        }

        if ( empty( $score_map ) ) {
            wp_send_json_success( array( 'posts' => array() ) );
        }

        $candidate_ids     = array_map( 'absint', array_keys( $score_map ) );
        $post_placeholders = implode( ',', array_fill( 0, count( $candidate_ids ), '%d' ) );
        $detail_rows       = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.id, p.author_id, p.forum_id, p.post_type, p.content, p.created_at, p.is_pinned,
                        u.display_name,
                        f.name AS forum_name
                 FROM {$posts_table} p
                 LEFT JOIN {$wpdb->users} u ON u.ID = p.author_id
                 LEFT JOIN {$forums_table} f ON f.id = p.forum_id
                 WHERE p.status = 'publish' AND p.id IN ({$post_placeholders})",
                $candidate_ids
            )
        );

        if ( empty( $detail_rows ) ) {
            wp_send_json_success( array( 'posts' => array() ) );
        }

        $detail_map = array();
        foreach ( (array) $detail_rows as $detail_row ) {
            $detail_map[ (int) $detail_row->id ] = $detail_row;
        }

        $tag_detail_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pt.post_id, t.name
                 FROM {$post_tags_table} pt
                 INNER JOIN {$tags_table} t ON t.id = pt.tag_id
                 WHERE pt.post_id IN ({$post_placeholders})
                 ORDER BY t.post_count DESC, t.id DESC",
                $candidate_ids
            )
        );

        $tags_by_post = array();
        foreach ( (array) $tag_detail_rows as $tag_detail_row ) {
            $post_id = (int) $tag_detail_row->post_id;
            if ( ! isset( $tags_by_post[ $post_id ] ) ) {
                $tags_by_post[ $post_id ] = array();
            }
            if ( ! in_array( $tag_detail_row->name, $tags_by_post[ $post_id ], true ) ) {
                $tags_by_post[ $post_id ][] = $tag_detail_row->name;
            }
        }

        usort(
            $candidate_ids,
            function( $left_id, $right_id ) use ( $score_map, $detail_map ) {
                $left_score  = isset( $score_map[ $left_id ] ) ? (int) $score_map[ $left_id ] : 0;
                $right_score = isset( $score_map[ $right_id ] ) ? (int) $score_map[ $right_id ] : 0;
                if ( $left_score !== $right_score ) {
                    return $right_score <=> $left_score;
                }

                $left_pinned  = ( isset( $detail_map[ $left_id ] ) && ! empty( $detail_map[ $left_id ]->is_pinned ) ) ? 1 : 0;
                $right_pinned = ( isset( $detail_map[ $right_id ] ) && ! empty( $detail_map[ $right_id ]->is_pinned ) ) ? 1 : 0;
                if ( $left_pinned !== $right_pinned ) {
                    return $right_pinned <=> $left_pinned;
                }

                $left_created  = isset( $detail_map[ $left_id ]->created_at ) ? (string) $detail_map[ $left_id ]->created_at : '';
                $right_created = isset( $detail_map[ $right_id ]->created_at ) ? (string) $detail_map[ $right_id ]->created_at : '';
                return strcmp( $right_created, $left_created );
            }
        );

        $posts = array();
        foreach ( array_slice( $candidate_ids, 0, $display_limit ) as $post_id ) {
            if ( ! isset( $detail_map[ $post_id ] ) ) {
                continue;
            }

            $post        = $detail_map[ $post_id ];
            $match_types = isset( $reason_map[ $post_id ] ) ? array_values( $reason_map[ $post_id ] ) : array();
            $tag_names   = isset( $tags_by_post[ $post_id ] ) ? array_slice( $tags_by_post[ $post_id ], 0, 3 ) : array();

            $posts[] = array(
                'id'              => (int) $post->id,
                'author'          => esc_html( $post->display_name ? $post->display_name : __( '未知用户', 'qibbs-community' ) ),
                'author_id'       => (int) $post->author_id,
                'forum'           => esc_html( (string) $post->forum_name ),
                'forum_id'        => (int) $post->forum_id,
                'content'         => $this->build_search_excerpt( $post->content, $lookup_query ),
                'link'            => qibbs_get_post_url( $post->id ),
                'post_type'       => function_exists( 'qibbs_normalize_post_type' ) ? qibbs_normalize_post_type( $post->post_type ) : 'discussion',
                'post_type_label' => function_exists( 'qibbs_get_post_type_label' ) ? qibbs_get_post_type_label( $post->post_type ) : __( '普通帖', 'qibbs-community' ),
                'match_types'     => $match_types,
                'match_label'     => esc_html( $this->get_search_match_label( $match_types ) ),
                'tags'            => array_map( 'esc_html', $tag_names ),
            );
        }

        wp_send_json_success( array( 'posts' => $posts ) );
    }

    /**
     * 编辑动态
     */
    public function edit_post() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        if ( ! $user_id || ! $post_id ) {
            wp_send_json_error( array( 'message' => __( '参数错误', 'qibbs-community' ) ) );
        }

        global $wpdb;
        $table = Qibbs_Database::posts();
        $post_tags_table = Qibbs_Database::post_tags();

        // 获取原帖信息
        $post = $wpdb->get_row( $wpdb->prepare(
            "SELECT author_id, created_at, post_type FROM {$table} WHERE id = %d",
            $post_id
        ) );

        if ( ! $post ) {
            wp_send_json_error( array( 'message' => __( '动态不存在', 'qibbs-community' ) ) );
        }

        // 检查是否为作者本人
        if ( $post->author_id != $user_id ) {
            wp_send_json_error( array( 'message' => __( '没有编辑权限', 'qibbs-community' ) ) );
        }

        // 检查编辑时间限制
        $edit_days = qibbs_get_option( 'post_edit_days', 0 );
        if ( $edit_days <= 0 ) {
            wp_send_json_error( array( 'message' => __( '编辑功能已关闭', 'qibbs-community' ) ) );
        }

        $created_time = strtotime( $post->created_at );
        $deadline = $created_time + ( $edit_days * DAY_IN_SECONDS );
        if ( time() > $deadline ) {
            wp_send_json_error( array( 
                'message' => sprintf( __( '该动态已超过 %d 天，无法编辑', 'qibbs-community' ), $edit_days ) 
            ) );
        }

        $old_tag_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT tag_id FROM {$post_tags_table} WHERE post_id = %d",
                $post_id
            )
        );

        // 获取并验证新内容
        $content = isset( $_POST['content'] ) ? qibbs_sanitize_content( wp_unslash( $_POST['content'] ) ) : '';
        if ( empty( $content ) ) {
            wp_send_json_error( array( 'message' => __( '内容不能为空', 'qibbs-community' ) ) );
        }

        // 检查最大长度
        $max_length = qibbs_get_option( 'max_post_length', 5000 );
        if ( mb_strlen( $content ) > $max_length ) {
            wp_send_json_error( array( 'message' => sprintf( __( '内容不能超过 %d 字符', 'qibbs-community' ), $max_length ) ) );
        }

        // 检查违规词
        if ( qibbs_get_option( 'enable_banned_words', false ) ) {
            $banned_words_str = qibbs_get_option( 'banned_words', '' );
            if ( ! empty( $banned_words_str ) ) {
                $banned_words = array_map( 'trim', explode( ',', $banned_words_str ) );
                $banned_words = array_filter( $banned_words );
                
                foreach ( $banned_words as $word ) {
                    if ( mb_stripos( $content, $word ) !== false ) {
                        wp_send_json_error( array( 'message' => sprintf( __( '内容包含违规词：%s', 'qibbs-community' ), $word ) ) );
                    }
                }
            }
        }

        $post_type = function_exists( 'qibbs_normalize_post_type' )
            ? qibbs_normalize_post_type( isset( $_POST['post_type'] ) ? wp_unslash( $_POST['post_type'] ) : $post->post_type )
            : 'discussion';

        // 处理图片 media_ids
        $validated_media_ids = qibbs_validate_media_ids_for_user(
            $user_id,
            isset( $_POST['media_ids'] ) ? wp_unslash( $_POST['media_ids'] ) : '',
            array( 'strict' => true )
        );
        if ( is_wp_error( $validated_media_ids ) ) {
            wp_send_json_error( array( 'message' => $validated_media_ids->get_error_message() ) );
        }
        $media_ids = qibbs_media_ids_to_csv( $validated_media_ids );

        // 更新动态
        $result = $wpdb->update(
            $table,
            array( 
                'content' => $content,
                'post_type' => $post_type,
                'media_ids' => $media_ids
            ),
            array( 'id' => $post_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( $result !== false ) {
            // 重新处理标签
            $tag_service = new Qibbs_Tag_Service();
            $tag_service->process_content_tags( $post_id, $content );

            do_action(
                'qibbs_post_edited',
                $post_id,
                $user_id,
                array(
                    'tag_ids' => $old_tag_ids,
                )
            );

            wp_send_json_success( array( 'message' => __( '编辑成功', 'qibbs-community' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( '编辑失败', 'qibbs-community' ) ) );
        }
    }

    /**
     * 获取动态内容（用于编辑）
     */
    public function get_post_content() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        if ( ! $user_id || ! $post_id ) {
            wp_send_json_error( array( 'message' => __( '参数错误', 'qibbs-community' ) ) );
        }

        global $wpdb;
        $table = Qibbs_Database::posts();

        $post = $wpdb->get_row( $wpdb->prepare(
            "SELECT author_id, content, post_type, media_ids, created_at FROM {$table} WHERE id = %d",
            $post_id
        ) );

        if ( ! $post ) {
            wp_send_json_error( array( 'message' => __( '动态不存在', 'qibbs-community' ) ) );
        }

        if ( $post->author_id != $user_id ) {
            wp_send_json_error( array( 'message' => __( '没有权限', 'qibbs-community' ) ) );
        }

        // 获取媒体信息
        $media = array();
        if ( ! empty( $post->media_ids ) ) {
            $media_table = Qibbs_Database::media();
            $media_ids_arr = array_filter( array_map( 'absint', explode( ',', $post->media_ids ) ) );
            if ( ! empty( $media_ids_arr ) ) {
                $ids_placeholder = implode( ',', array_fill( 0, count( $media_ids_arr ), '%d' ) );
                $media = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, file_url, file_type FROM {$media_table} WHERE user_id = %d AND id IN ({$ids_placeholder})",
                    array_merge( array( $user_id ), $media_ids_arr )
                ) );
            }
        }

        wp_send_json_success( array( 
            'content' => $post->content,
            'post_type' => function_exists( 'qibbs_normalize_post_type' ) ? qibbs_normalize_post_type( $post->post_type ) : 'discussion',
            'media_ids' => qibbs_media_ids_to_csv( wp_list_pluck( $media, 'id' ) ),
            'media' => $media
        ) );
    }

    /**
     * 检索用户列表用于 @提及自动补全
     */
    public function search_users() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        if ( function_exists( 'qibbs_rate_limit_by_ip' ) && ! qibbs_rate_limit_by_ip( 'ajax_search_users', 120, 60 ) ) {
            wp_send_json_error( array( 'message' => __( '请求过于频繁，请稍后再试', 'qibbs-community' ) ), 429 );
        }

        if ( ! $this->check_search_user_rate_limit( $user_id ) ) {
            wp_send_json_error( array( 'message' => __( '请求过于频繁，请稍后再试', 'qibbs-community' ) ), 429 );
        }

        if ( ! qibbs_get_option( 'enable_mentions', true ) ) {
            wp_send_json_success( array( 'users' => array() ) );
        }

        $query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
        if ( mb_strlen( $query ) < 1 ) {
            wp_send_json_success( array( 'users' => array() ) );
        }

        global $wpdb;
        $limit = absint( qibbs_get_option( 'mention_suggest_limit', 8 ) );
        $limit = max( 3, min( 20, $limit ) );
        $like = '%' . $wpdb->esc_like( $query ) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, user_nicename, display_name
                 FROM {$wpdb->users}
                 WHERE display_name LIKE %s
                    OR user_nicename LIKE %s
                 ORDER BY ID DESC
                 LIMIT %d",
                $like,
                $like,
                $limit
            )
        );

        $users = array();
        foreach ( (array) $rows as $row ) {
            $user_id = (int) $row->ID;
            $mention_name = sanitize_user( (string) $row->user_nicename, true );
            if ( '' === $mention_name ) {
                $mention_name = 'user' . $user_id;
            }
            $users[] = array(
                'id'           => $user_id,
                'username'     => $mention_name,
                'display_name' => $row->display_name,
                'avatar'       => get_avatar_url( $user_id, array( 'size' => 48 ) ),
                'profile_url'  => qibbs_get_profile_url( $user_id ),
            );
        }

        wp_send_json_success( array( 'users' => $users ) );
    }

    /**
     * 举报违规帖子或动态内容
     */
    public function report_post() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        if ( ! qibbs_get_option( 'enable_reporting', true ) ) {
            wp_send_json_error( array( 'message' => __( '举报功能已关闭', 'qibbs-community' ) ), 403 );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => __( '参数错误', 'qibbs-community' ) ) );
        }

        $reason = trim( $reason );
        if ( mb_strlen( $reason ) < 2 ) {
            wp_send_json_error( array( 'message' => __( '请填写举报原因', 'qibbs-community' ) ) );
        }
        if ( mb_strlen( $reason ) > 500 ) {
            wp_send_json_error( array( 'message' => __( '举报原因过长', 'qibbs-community' ) ) );
        }

        global $wpdb;
        $posts_table = Qibbs_Database::posts();
        $reports_table = Qibbs_Database::reports();
        $post = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, author_id, status FROM {$posts_table} WHERE id = %d",
                $post_id
            )
        );

        if ( ! $post ) {
            wp_send_json_error( array( 'message' => __( '动态不存在', 'qibbs-community' ) ) );
        }
        if ( 'publish' !== $post->status ) {
            wp_send_json_error( array( 'message' => __( '该动态当前不可举报', 'qibbs-community' ) ) );
        }
        if ( (int) $post->author_id === (int) $user_id ) {
            wp_send_json_error( array( 'message' => __( '不能举报自己的动态', 'qibbs-community' ) ) );
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$reports_table} WHERE post_id = %d AND reporter_id = %d LIMIT 1",
                $post_id,
                $user_id
            )
        );
        if ( $existing ) {
            wp_send_json_error( array( 'message' => __( '你已举报过该动态，请勿重复提交', 'qibbs-community' ) ) );
        }

        $inserted = $wpdb->insert(
            $reports_table,
            array(
                'post_id'      => $post_id,
                'reporter_id'  => $user_id,
                'reason'       => $reason,
                'status'       => 'pending',
                'created_at'   => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            wp_send_json_error( array( 'message' => __( '提交失败，请稍后重试', 'qibbs-community' ) ) );
        }

        $report_id = absint( $wpdb->insert_id );
        if ( $report_id && class_exists( 'Qibbs_Notification_Service' ) ) {
            $notification_service = new Qibbs_Notification_Service();
            $notification_service->create_report_notification_for_admins( $report_id, $post_id, $user_id, $reason );
        }

        $pending_reports = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$reports_table} WHERE post_id = %d AND status = 'pending'",
                $post_id
            )
        );

        $threshold = absint( qibbs_get_option( 'report_auto_hide_threshold', 3 ) );
        $auto_hidden = false;
        if ( $threshold > 0 && $pending_reports >= $threshold && 'publish' === $post->status ) {
            $updated = $wpdb->update(
                $posts_table,
                array( 'status' => 'qibbs_pending' ),
                array( 'id' => $post_id ),
                array( '%s' ),
                array( '%d' )
            );
            $auto_hidden = ( false !== $updated );
            if ( $auto_hidden && class_exists( 'Qibbs_Cache_Service' ) ) {
                Qibbs_Cache_Service::invalidate_post_cache( $post_id );
            }
        }

        wp_send_json_success( array(
            'message'       => __( '举报已提交，管理员将尽快处理', 'qibbs-community' ),
            'pending_count' => $pending_reports,
            'auto_hidden'   => $auto_hidden,
        ) );
    }

    /**
     * 管理员仲裁悬赏贴接口
     */
    public function ajax_arbitrate_bounty() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '只有系统管理员可执行悬赏仲裁', 'qibbs-community' ) ), 403 );
        }

        $post_id            = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $arbitration_action = isset( $_POST['arbitration_action'] ) ? sanitize_key( $_POST['arbitration_action'] ) : '';
        $winner_comment_id  = isset( $_POST['winner_comment_id'] ) ? absint( $_POST['winner_comment_id'] ) : 0;
        $reason             = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

        if ( ! $post_id || ! in_array( $arbitration_action, array( 'award', 'refund' ), true ) ) {
            wp_send_json_error( array( 'message' => __( '仲裁参数无效', 'qibbs-community' ) ) );
        }

        if ( ! class_exists( 'Qibbs_Bounty_Service' ) ) {
            wp_send_json_error( array( 'message' => __( '悬赏服务未就绪', 'qibbs-community' ) ) );
        }

        $result = Qibbs_Bounty_Service::instance()->admin_arbitrate(
            $post_id,
            $arbitration_action,
            $winner_comment_id,
            get_current_user_id(),
            $reason
        );

        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( $result );
        }

        wp_send_json_error( $result );
    }

    /**
     * 手动开奖抽奖红包接口
     */
    public function ajax_draw_redpacket() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( array( 'message' => __( '参数错误', 'qibbs-community' ) ) );
        }

        if ( ! class_exists( 'Qibbs_Redpacket_Service' ) ) {
            wp_send_json_error( array( 'message' => __( '红包服务未就绪', 'qibbs-community' ) ) );
        }

        $result = Qibbs_Redpacket_Service::instance()->draw_lottery_packet( $post_id, $user_id );
        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( $result );
        }

        wp_send_json_error( $result );
    }

    /**
     * 获取红包领取明细（手气榜）接口
     */
    public function ajax_get_redpacket_logs() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $post_id      = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        $redpacket_id = isset( $_POST['redpacket_id'] ) ? absint( $_POST['redpacket_id'] ) : 0;

        if ( ! class_exists( 'Qibbs_Redpacket_Service' ) ) {
            wp_send_json_error( array( 'message' => __( '红包服务未就绪', 'qibbs-community' ) ) );
        }

        $service = Qibbs_Redpacket_Service::instance();
        if ( ! $redpacket_id && $post_id ) {
            $packet = $service->get_redpacket( $post_id );
            if ( $packet ) {
                $redpacket_id = (int) $packet->id;
            }
        }

        if ( ! $redpacket_id ) {
            wp_send_json_error( array( 'message' => __( '未找到该红包记录', 'qibbs-community' ) ) );
        }

        $logs = $service->get_logs( $redpacket_id );
        wp_send_json_success( array(
            'logs'  => $logs,
            'total' => count( $logs ),
        ) );
    }

    /**
     * 用户积分选购道具接口
     */
    public function ajax_purchase_prop() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        $prop_id        = isset( $_POST['prop_id'] ) ? absint( $_POST['prop_id'] ) : 0;
        $target_post_id = isset( $_POST['target_post_id'] ) ? absint( $_POST['target_post_id'] ) : 0;

        if ( ! $prop_id ) {
            wp_send_json_error( array( 'message' => __( '道具参数无效', 'qibbs-community' ) ) );
        }

        if ( ! class_exists( 'Qibbs_Prop_Service' ) ) {
            wp_send_json_error( array( 'message' => __( '道具服务未就绪', 'qibbs-community' ) ) );
        }

        try {
            $service = Qibbs_Prop_Service::instance();
            $result  = $service->purchase_prop( $user_id, $prop_id, $target_post_id );

            if ( ! empty( $result['success'] ) ) {
                $new_balance = qibbs_get_user_points_balance( $user_id );
                $result['points_balance']   = $new_balance;
                $result['points_formatted'] = qibbs_format_points_amount( (float) $new_balance );
                $result['backpack']         = $service->get_user_backpack( $user_id );
                $result['active_color']     = qibbs_get_user_name_color( $user_id );
                wp_send_json_success( $result );
            }

            wp_send_json_error( $result );
        } catch ( Throwable $e ) {
            wp_send_json_error( array( 'message' => sprintf( __( '兑换处理异常：%s', 'qibbs-community' ), $e->getMessage() ) ) );
        }
    }

    /**
     * 穿戴名字颜色道具接口
     */
    public function ajax_equip_name_color() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        $user_prop_id = isset( $_POST['user_prop_id'] ) ? absint( $_POST['user_prop_id'] ) : 0;
        if ( ! $user_prop_id ) {
            wp_send_json_error( array( 'message' => __( '道具记录无效', 'qibbs-community' ) ) );
        }

        if ( ! class_exists( 'Qibbs_Prop_Service' ) ) {
            wp_send_json_error( array( 'message' => __( '道具服务未就绪', 'qibbs-community' ) ) );
        }

        $service = Qibbs_Prop_Service::instance();
        $result  = $service->equip_name_color( $user_id, $user_prop_id );

        if ( ! empty( $result['success'] ) ) {
            $result['backpack']     = $service->get_user_backpack( $user_id );
            $result['active_color'] = qibbs_get_user_name_color( $user_id );
            wp_send_json_success( $result );
        }

        wp_send_json_error( $result );
    }

    /**
     * 卸下名字颜色道具接口
     */
    public function ajax_unequip_name_color() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        if ( ! class_exists( 'Qibbs_Prop_Service' ) ) {
            wp_send_json_error( array( 'message' => __( '道具服务未就绪', 'qibbs-community' ) ) );
        }

        $service = Qibbs_Prop_Service::instance();
        $result  = $service->unequip_name_color( $user_id );

        if ( ! empty( $result['success'] ) ) {
            $result['backpack']     = $service->get_user_backpack( $user_id );
            $result['active_color'] = '';
            wp_send_json_success( $result );
        }

        wp_send_json_error( $result );
    }

    /**
     * 查询用户道具背包接口
     */
    public function ajax_get_user_backpack() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        if ( ! class_exists( 'Qibbs_Prop_Service' ) ) {
            wp_send_json_error( array( 'message' => __( '道具服务未就绪', 'qibbs-community' ) ) );
        }

        $service  = Qibbs_Prop_Service::instance();
        $backpack = $service->get_user_backpack( $user_id );

        wp_send_json_success( array(
            'backpack'     => $backpack,
            'active_color' => qibbs_get_user_name_color( $user_id ),
            'points'       => qibbs_get_user_points_balance( $user_id ),
        ) );
    }

    /**
     * 查询用户可置顶动态列表接口
     */
    public function ajax_get_user_promotable_posts() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        if ( ! class_exists( 'Qibbs_Prop_Service' ) ) {
            wp_send_json_error( array( 'message' => __( '道具服务未就绪', 'qibbs-community' ) ) );
        }

        $service = Qibbs_Prop_Service::instance();
        $posts   = $service->get_user_promotable_posts( $user_id, 50 );

        wp_send_json_success( array(
            'posts' => $posts,
            'total' => count( $posts ),
        ) );
    }
}

