<?php
/**
 * 社区动态卡片模板
 *
 * @package Qibbs_Community
 * @var object $post Post object
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$current_user_id = get_current_user_id();
$is_admin = current_user_can( 'manage_options' );
$is_owner = $current_user_id && intval( $current_user_id ) === intval( $post->author_id );

$post_forum_id = 0;
if ( isset( $post->forum_id ) ) {
    $post_forum_id = (int) $post->forum_id;
}
if ( ! $post_forum_id && ! empty( $post->forum ) ) {
    if ( isset( $post->forum->id ) ) {
        $post_forum_id = (int) $post->forum->id;
    } elseif ( isset( $post->forum->term_id ) ) {
        $post_forum_id = (int) $post->forum->term_id;
    }
}

$can_moderate_forum = $current_user_id && $post_forum_id > 0 && function_exists( 'qibbs_user_can_moderate_forum' )
    ? qibbs_user_can_moderate_forum( (int) $current_user_id, $post_forum_id )
    : false;
$can_manage = $is_admin || $is_owner || $can_moderate_forum;
$can_report = is_user_logged_in() && ! $is_owner && qibbs_get_option( 'enable_reporting', true );

$author_badges = function_exists( 'qibbs_get_user_badges' )
    ? qibbs_get_user_badges( (int) $post->author_id, $post_forum_id )
    : array();
$discussion_status_enabled = function_exists( 'qibbs_is_discussion_status_enabled_for_forum' )
    ? qibbs_is_discussion_status_enabled_for_forum( $post_forum_id )
    : false;
$owner_reopen_enabled = (bool) qibbs_get_option( 'enable_owner_reopen_question', false );
$owner_reopen_max_days = max( 1, min( 3650, absint( qibbs_get_option( 'owner_reopen_max_days', 30 ) ) ) );
$owner_reopen_within_window = true;
if ( $is_owner && ! $is_admin && ! $can_moderate_forum ) {
    $created_timestamp = ! empty( $post->created_at ) ? qibbs_datetime_to_timestamp( (string) $post->created_at ) : 0;
    $owner_reopen_within_window = $created_timestamp && ( qibbs_current_timestamp() <= ( $created_timestamp + ( DAY_IN_SECONDS * $owner_reopen_max_days ) ) );
}
$can_set_discussion_by_current_user = ( ! $is_owner ) || $is_admin || $can_moderate_forum || ( $owner_reopen_enabled && $owner_reopen_within_window );
$discussion_status = 'discussion';
$is_discussion_closed = false;
if ( $discussion_status_enabled ) {
    $discussion_status = function_exists( 'qibbs_normalize_discussion_status' )
        ? qibbs_normalize_discussion_status(
            isset( $post->discussion_status ) ? (string) $post->discussion_status : '',
            ! empty( $post->is_resolved ) ? 1 : 0
        )
        : ( ! empty( $post->is_resolved ) ? 'resolved' : 'discussion' );
    $is_discussion_closed = ( 'closed' === $discussion_status );
}
$card_reward_context = array(
    'post_id'        => (int) $post->id,
    'forum_id'       => $post_forum_id,
    'post_author_id' => isset( $post->author_id ) ? (int) $post->author_id : 0,
);
$card_comment_reward_hint = $current_user_id ? qibbs_get_points_reward_hint( 'comment_approved', __( '评论通过可得', 'qibbs-community' ), $current_user_id, $card_reward_context ) : '';
$card_bounty = ( isset( $post->bounty ) && is_object( $post->bounty ) ) ? $post->bounty : ( class_exists( 'Qibbs_Bounty_Service' ) ? Qibbs_Bounty_Service::instance()->get_bounty( $post->id ) : null );
$card_is_pending_bounty = ( $card_bounty && in_array( (string) $card_bounty->status, array( 'pending', 'pending_arbitration' ), true ) );
$card_best_answer_reward_hint = '';
if ( $card_is_pending_bounty && function_exists( 'qibbs_get_points_name' ) ) {
    $points_name = qibbs_get_points_name();
    $bounty_pts = isset( $card_bounty->amount ) ? (float) $card_bounty->amount : ( (float) ( $card_bounty->points ?? 0 ) );
    $bounty_display = function_exists( 'qibbs_format_points_amount' ) ? qibbs_format_points_amount( $bounty_pts ) : $bounty_pts;
    $sys_best_settings = function_exists( 'qibbs_get_points_reward_settings' ) ? qibbs_get_points_reward_settings( 'best_answer' ) : null;
    $sys_bonus_pts = ( ! empty( $sys_best_settings['enabled'] ) && ! empty( $sys_best_settings['amount'] ) ) ? (float) $sys_best_settings['amount'] : 0;
    $sys_bonus_display = function_exists( 'qibbs_format_points_amount' ) ? qibbs_format_points_amount( $sys_bonus_pts ) : $sys_bonus_pts;
    $can_earn_sys_bonus = $current_user_id && function_exists( 'qibbs_can_user_earn_points_reward' )
        ? qibbs_can_user_earn_points_reward( 'best_answer', $current_user_id, $card_reward_context )
        : ( $sys_bonus_pts > 0 );

    if ( $can_earn_sys_bonus && $sys_bonus_pts > 0 ) {
        $card_best_answer_reward_hint = sprintf(
            __( '最佳答案获悬赏 %s %s（系统额外 +%s）', 'qibbs-community' ),
            $bounty_display,
            $points_name,
            $sys_bonus_display
        );
    } else {
        $card_best_answer_reward_hint = sprintf( __( '最佳答案获悬赏 %s %s', 'qibbs-community' ), $bounty_display, $points_name );
    }
}

// 获取帖子关联标签
$post_tags = ( isset( $post->tags ) && is_array( $post->tags ) ) ? $post->tags : array();
if ( empty( $post_tags ) ) {
    $tag_service = new Qibbs_Tag_Service();
    $post_tags = $tag_service->get_post_tags( $post->id );
}

// 计算九宫格媒体展示数量
$media_count = ! empty( $post->media ) ? count( $post->media ) : 0;
$show_more_count = $media_count > 9 ? $media_count - 9 : 0;
$display_media = $media_count > 9 ? array_slice( $post->media, 0, 9 ) : $post->media;

// 检查是否为文章同步贴
$is_article = isset( $post->source_type ) && $post->source_type === 'article';
$article_link = $is_article && isset( $post->source_id ) ? get_permalink( $post->source_id ) : '';

// 检查是否可以编辑（仅作者本人且在时间限制内，且不是同步的文章）
$can_edit = false;
if ( $is_owner && ! $is_article ) {
    $edit_days = qibbs_get_option( 'post_edit_days', 0 );
    if ( $edit_days > 0 ) {
        $created_time = qibbs_datetime_to_timestamp( $post->created_at );
        $deadline = $created_time + ( $edit_days * DAY_IN_SECONDS );
        $can_edit = $created_time && qibbs_current_timestamp() <= $deadline;
    }
}
?>
<article class="qibbs-feed-card" data-post-id="<?php echo esc_attr( $post->id ); ?>">
    <header class="qibbs-card-header">
        <a href="<?php echo esc_url( $post->author['profile_url'] ); ?>" class="qibbs-avatar">
            <img src="<?php echo esc_url( $post->author['avatar'] ); ?>" alt="<?php echo esc_attr( $post->author['display_name'] ); ?>" loading="lazy">
        </a>
        <div class="qibbs-card-meta">
            <div class="qibbs-author-row">
                <a href="<?php echo esc_url( $post->author['profile_url'] ); ?>" class="qibbs-author-name"<?php echo ! empty( $post->author['name_color'] ) ? ' style="color: ' . esc_attr( $post->author['name_color'] ) . ' !important; font-weight: 600;"' : ''; ?>>
                    <?php echo esc_html( $post->author['display_name'] ); ?>
                </a>
                <?php if ( ! empty( $post->author['cuid_badge'] ) ) : ?>
                    <?php echo $post->author['cuid_badge']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php elseif ( function_exists( 'qibbs_render_cuid_badge' ) && ! empty( $post->author_id ) ) : ?>
                    <?php echo qibbs_render_cuid_badge( $post->author_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endif; ?>
                <?php if ( function_exists( 'qibbs_render_user_level_badge' ) && ! empty( $post->author_id ) ) : ?>
                    <?php echo qibbs_render_user_level_badge( $post->author_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endif; ?>
            </div>
            <?php if ( ! empty( $author_badges ) ) : ?>
                <div class="qibbs-author-badges">
                    <?php foreach ( $author_badges as $badge_item ) : ?>
                        <span class="qibbs-user-badge <?php echo esc_attr( $badge_item['class'] ); ?>">
                            <?php echo esc_html( $badge_item['label'] ); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="qibbs-card-info">
                <?php
                $forum_filter_id = $post_forum_id;
                if ( ! empty( $post->forum ) ) {
                    if ( ! $forum_filter_id && isset( $post->forum->id ) ) {
                        $forum_filter_id = (int) $post->forum->id;
                    } elseif ( ! $forum_filter_id && isset( $post->forum->term_id ) ) {
                        $forum_filter_id = (int) $post->forum->term_id;
                    }
                }
                ?>
                <?php if ( ! empty( $post->forum ) && $forum_filter_id > 0 && isset( $post->forum->name ) ) : ?>
                    <a href="#" class="qibbs-forum-link" data-filter="<?php echo esc_attr( $forum_filter_id ); ?>" data-filter-type="forum">
                        <?php echo esc_html( $post->forum->name ); ?>
                    </a>
                    <span class="qibbs-separator">·</span>
                <?php endif; ?>
                <time class="qibbs-time" datetime="<?php echo esc_attr( $post->created_at ); ?>" title="<?php echo esc_attr( function_exists( 'qibbs_format_exact_datetime' ) ? qibbs_format_exact_datetime( $post->created_at ) : (string) $post->created_at ); ?>">
                    <?php echo esc_html( $post->time_ago ); ?>
                </time>
                <?php if ( ! empty( $post->post_type_label ) && ! empty( $post->post_type ) && 'discussion' !== $post->post_type ) : ?>
                    <span class="qibbs-separator">·</span>
                    <span class="qibbs-post-type-badge qibbs-post-type-badge-<?php echo esc_attr( $post->post_type ); ?>">
                        <?php echo esc_html( $post->post_type_label ); ?>
                    </span>
                <?php endif; ?>
                <?php if ( ! empty( $post->is_active_promoted ) || ( ! empty( $post->is_promoted ) && ( empty( $post->promoted_until ) || $post->promoted_until > current_time( 'mysql' ) ) ) ) : ?>
                    <span class="qibbs-separator">·</span>
                    <span class="qibbs-promoted-badge" title="<?php esc_attr_e( '圈子置顶推广中', 'qibbs-community' ); ?>">
                        <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" style="vertical-align: -1.5px; margin-right: 2px;"><path d="M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 10h2v4h-2zm0 5h2v2h-2z"/></svg>
                        <?php esc_html_e( '推广', 'qibbs-community' ); ?>
                    </span>
                <?php endif; ?>
                <?php if ( ! empty( $post->is_featured ) ) : ?>
                    <span class="qibbs-separator">·</span>
                    <span class="qibbs-featured-badge" title="<?php esc_attr_e( '推荐动态', 'qibbs-community' ); ?>">
                        <svg viewBox="0 0 24 24" width="14" height="14" style="fill: #f59e0b; vertical-align: middle;"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                        <?php esc_html_e( '推荐', 'qibbs-community' ); ?>
                    </span>
                <?php endif; ?>
                <?php if ( ! empty( $post->poll_id ) ) : ?>
                    <span class="qibbs-separator">·</span>
                    <span class="qibbs-poll-badge" title="<?php esc_attr_e( '包含投票', 'qibbs-community' ); ?>">
                        <svg viewBox="0 0 24 24" width="14" height="14" style="fill: #667eea; vertical-align: middle;"><path d="M4 14h4v-4H4v4zm0 5h4v-4H4v4zM4 9h4V5H4v4zm5 5h12v-4H9v4zm0 5h12v-4H9v4zM9 5v4h12V5H9z"/></svg>
                        <?php esc_html_e( '投票', 'qibbs-community' ); ?>
                    </span>
                <?php endif; ?>
                <?php if ( $discussion_status_enabled ) : ?>
                    <span class="qibbs-separator">·</span>
                    <?php if ( 'resolved' === $discussion_status ) : ?>
                        <span class="qibbs-resolved-badge qibbs-discussion-badge" title="<?php esc_attr_e( '已解决', 'qibbs-community' ); ?>">
                            <svg viewBox="0 0 24 24" width="12" height="12"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                            <?php esc_html_e( '已解决', 'qibbs-community' ); ?>
                        </span>
                    <?php elseif ( 'closed' === $discussion_status ) : ?>
                        <span class="qibbs-closed-badge qibbs-discussion-badge" title="<?php esc_attr_e( '已关闭', 'qibbs-community' ); ?>">
                            <svg viewBox="0 0 24 24" width="12" height="12"><path d="M12 1a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5zm-3 8V6a3 3 0 0 1 6 0v3H9z"/></svg>
                            <?php esc_html_e( '已关闭', 'qibbs-community' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="qibbs-discussion-badge" title="<?php esc_attr_e( '讨论中', 'qibbs-community' ); ?>">
                            <?php esc_html_e( '讨论中', 'qibbs-community' ); ?>
                        </span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ( $is_article ) : ?>
                    <span class="qibbs-separator">·</span>
                    <span class="qibbs-article-badge"><?php esc_html_e( '文章', 'qibbs-community' ); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php 
        // 非作者用户的快捷操作按钮
        if ( is_user_logged_in() && ! $is_owner ) : 
            // 优先使用 Feed 服务预加载的关注状态，避免 N+1 查询
            $is_following = isset( $post->author_followed ) ? (bool) $post->author_followed : false;
            if ( ! isset( $post->author_followed ) ) {
                $follow_service = new Qibbs_Follow_Service();
                $is_following = $follow_service->is_following( $current_user_id, $post->author['id'] );
            }
        ?>
            <div class="qibbs-quick-actions">
                <button class="qibbs-quick-btn qibbs-follow-btn <?php echo $is_following ? 'active' : ''; ?>" 
                        data-user-id="<?php echo esc_attr( $post->author['id'] ); ?>" 
                        title="<?php echo $is_following ? esc_attr__( '取消关注', 'qibbs-community' ) : esc_attr__( '关注', 'qibbs-community' ); ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16">
                        <?php if ( $is_following ) : ?>
                            <path d="M9 16.2l-4.2-4.2-1.4 1.4 5.6 5.6 12-12-1.4-1.4z"/>
                        <?php else : ?>
                            <path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        <?php endif; ?>
                    </svg>
                    <span class="qibbs-quick-btn-text"><?php echo $is_following ? esc_html__( '已关注', 'qibbs-community' ) : esc_html__( '关注', 'qibbs-community' ); ?></span>
                </button>
                <a href="<?php echo esc_url( qibbs_get_messages_url( array( 'user' => $post->author['id'] ) ) ); ?>" 
                   class="qibbs-quick-btn qibbs-message-quick-btn" 
                   title="<?php esc_attr_e( '发私信', 'qibbs-community' ); ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16">
                        <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                    </svg>
                    <span class="qibbs-quick-btn-text"><?php esc_html_e( '私信', 'qibbs-community' ); ?></span>
                </a>
            </div>
        <?php endif; ?>
        <?php if ( $can_manage || $can_report ) : ?>
            <div class="qibbs-card-menu">
                <button class="qibbs-menu-toggle" aria-label="<?php esc_attr_e( '更多', 'qibbs-community' ); ?>">
                    <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
                </button>
                <div class="qibbs-menu-dropdown">
                    <?php if ( $can_manage ) : ?>
                        <?php
                        // 管理员加精/取消加精操作（如已启用加精功能）
                        if ( $is_admin && qibbs_get_option( 'enable_featured_posts', false ) ) :
                            $is_featured = ! empty( $post->is_featured );
                        ?>
                            <?php if ( $is_featured ) : ?>
                                <button type="button" class="qibbs-menu-item qibbs-unfeature-btn" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                                    <svg viewBox="0 0 24 24" width="16" height="16"><path d="M22 9.24l-7.19-.62L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.63-7.03L22 9.24zM12 15.4l-3.76 2.27 1-4.28-3.32-2.88 4.38-.38L12 6.1l1.71 4.04 4.38.38-3.32 2.88 1 4.28L12 15.4z"/></svg>
                                    <?php esc_html_e( '取消推荐', 'qibbs-community' ); ?>
                                </button>
                            <?php else : ?>
                                <button type="button" class="qibbs-menu-item qibbs-feature-btn" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                                    <svg viewBox="0 0 24 24" width="16" height="16"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                                    <?php esc_html_e( '推荐', 'qibbs-community' ); ?>
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ( ( $is_admin || $can_moderate_forum ) && ! $is_owner ) : ?>
                            <button type="button" class="qibbs-menu-item qibbs-block-btn" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                                <svg viewBox="0 0 24 24" width="16" height="16"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zM4 12c0-4.42 3.58-8 8-8 1.85 0 3.55.63 4.9 1.69L5.69 16.9C4.63 15.55 4 13.85 4 12zm8 8c-1.85 0-3.55-.63-4.9-1.69L18.31 7.1C19.37 8.45 20 10.15 20 12c0 4.42-3.58 8-8 8z"/></svg>
                                <?php esc_html_e( '屏蔽', 'qibbs-community' ); ?>
                            </button>
                        <?php endif; ?>
                        <?php if ( $can_edit ) : ?>
                            <button type="button" class="qibbs-menu-item qibbs-edit-btn" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                                <svg viewBox="0 0 24 24" width="16" height="16"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                <?php esc_html_e( '编辑', 'qibbs-community' ); ?>
                            </button>
                        <?php endif; ?>
                        <?php if ( $discussion_status_enabled ) : ?>
                            <div class="qibbs-menu-divider"></div>
                            <?php if ( $can_set_discussion_by_current_user ) : ?>
                                <button type="button"
                                        class="qibbs-menu-item qibbs-discussion-status-btn <?php echo 'discussion' === $discussion_status ? 'is-active' : ''; ?>"
                                        data-post-id="<?php echo esc_attr( $post->id ); ?>"
                                        data-status="discussion">
                                    <?php esc_html_e( '设为讨论中', 'qibbs-community' ); ?>
                                </button>
                            <?php endif; ?>
                            <button type="button"
                                    class="qibbs-menu-item qibbs-discussion-status-btn <?php echo 'resolved' === $discussion_status ? 'is-active' : ''; ?>"
                                    data-post-id="<?php echo esc_attr( $post->id ); ?>"
                                    data-status="resolved">
                                <?php esc_html_e( '标记已解决', 'qibbs-community' ); ?>
                            </button>
                            <button type="button"
                                    class="qibbs-menu-item qibbs-discussion-status-btn <?php echo 'closed' === $discussion_status ? 'is-active' : ''; ?>"
                                    data-post-id="<?php echo esc_attr( $post->id ); ?>"
                                    data-status="closed">
                                <?php esc_html_e( '关闭讨论', 'qibbs-community' ); ?>
                            </button>
                        <?php endif; ?>
                        <?php if ( $is_owner && function_exists( 'qibbs_is_prop_mall_enabled' ) && qibbs_is_prop_mall_enabled() ) : ?>
                            <button type="button" class="qibbs-menu-item qibbs-promote-post-btn" data-post-id="<?php echo esc_attr( $post->id ); ?>" data-forum-id="<?php echo esc_attr( $post_forum_id ); ?>">
                                <svg viewBox="0 0 24 24" width="16" height="16"><path d="M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 10h2v4h-2zm0 5h2v2h-2z"/></svg>
                                <?php esc_html_e( '置顶推广', 'qibbs-community' ); ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="qibbs-menu-item qibbs-delete-btn" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                            <svg viewBox="0 0 24 24" width="16" height="16"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                            <?php esc_html_e( '删除', 'qibbs-community' ); ?>
                        </button>
                    <?php endif; ?>
                    <?php if ( $can_report ) : ?>
                        <button type="button" class="qibbs-menu-item qibbs-report-btn" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                            <svg viewBox="0 0 24 24" width="16" height="16"><path d="M14 2H6c-1.1 0-2 .9-2 2v16l4-4h10c1.1 0 2-.9 2-2V8l-6-6zm1 9h-4V9h4v2zm0-4h-4V5h4v2z"/></svg>
                            <?php esc_html_e( '举报', 'qibbs-community' ); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </header>
    
    <div class="qibbs-card-content">
        <?php 
        $interactive_banner = QIBBS_PLUGIN_DIR . 'templates/post-interactive-banner.php';
        if ( file_exists( $interactive_banner ) ) {
            include $interactive_banner;
        }
        ?>

        <?php if ( $is_article && $article_link ) : ?>
            <a href="<?php echo esc_url( $article_link ); ?>" class="qibbs-article-link">
        <?php endif; ?>
        
        <div class="qibbs-content-text">
            <?php 
            // 将话题标签转为筛选链接
            $content = preg_replace_callback(
                '/#([\p{L}\p{N}_]+)/u',
                function( $matches ) {
                    $tag = $matches[1];
                    return '<a href="#" class="qibbs-hashtag" data-filter-type="tag" data-filter-name="' . esc_attr( $tag ) . '">#' . esc_html( $tag ) . '</a>';
                },
                $post->content
            );
            
            // 解析视频链接（MP4、B站等）
            if ( function_exists( 'qibbs_parse_video_links' ) ) {
                $content = qibbs_parse_video_links( $content );
            }
            
            // 使用允许 video 与 iframe 标签的安全过滤函数
            if ( function_exists( 'qibbs_kses_video_content' ) ) {
                echo qibbs_kses_video_content( nl2br( $content ) );
            } else {
                echo wp_kses_post( nl2br( $content ) );
            }
            ?>
        </div>
        
        <?php 
        // 若存在投票则输出投票容器占位符
        if ( ! empty( $post->poll_id ) ) : ?>
            <div class="qivot-poll-placeholder" data-poll-id="<?php echo esc_attr( $post->poll_id ); ?>"></div>
        <?php endif; ?>
        
        <?php if ( $is_article && $article_link ) : ?>
            <span class="qibbs-read-more"><?php esc_html_e( '阅读全文 →', 'qibbs-community' ); ?></span>
            </a>
        <?php endif; ?>
        
        <?php if ( ! empty( $display_media ) ) : ?>
            <div class="qibbs-media-grid qibbs-media-<?php echo min( $media_count, 9 ); ?>">
                <?php foreach ( $display_media as $index => $media ) : ?>
                    <?php if ( $media->file_type === 'image' ) : ?>
                        <figure class="qibbs-media-item <?php echo ( $index === 8 && $show_more_count > 0 ) ? 'qibbs-media-more' : ''; ?>">
                            <img src="<?php echo esc_url( $media->file_url ); ?>" alt="" loading="lazy" onclick="qibbsLightbox(this)">
                            <?php if ( $index === 8 && $show_more_count > 0 ) : ?>
                                <div class="qibbs-media-overlay">
                                    <span>+<?php echo esc_html( $show_more_count ); ?></span>
                                </div>
                            <?php endif; ?>
                        </figure>
                    <?php elseif ( $media->file_type === 'video' ) : ?>
                        <figure class="qibbs-media-item qibbs-video">
                            <video src="<?php echo esc_url( $media->file_url ); ?>" controls preload="metadata"></video>
                        </figure>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <footer class="qibbs-card-actions">
        <?php if ( qibbs_get_option( 'enable_likes', true ) ) : ?>
            <button class="qibbs-action-btn qibbs-like-btn <?php echo ! empty( $post->user_liked ) ? 'active' : ''; ?>" 
                    data-post-id="<?php echo esc_attr( $post->id ); ?>"
                    title="<?php esc_attr_e( '点赞', 'qibbs-community' ); ?>">
                <svg class="icon-outline" viewBox="0 0 24 24" width="20" height="20"><path d="M16.5 3c-1.74 0-3.41.81-4.5 2.09C10.91 3.81 9.24 3 7.5 3 4.42 3 2 5.42 2 8.5c0 3.78 3.4 6.86 8.55 11.54L12 21.35l1.45-1.32C18.6 15.36 22 12.28 22 8.5 22 5.42 19.58 3 16.5 3zm-4.4 15.55l-.1.1-.1-.1C7.14 14.24 4 11.39 4 8.5 4 6.5 5.5 5 7.5 5c1.54 0 3.04.99 3.57 2.36h1.87C13.46 5.99 14.96 5 16.5 5c2 0 3.5 1.5 3.5 3.5 0 2.89-3.14 5.74-7.9 10.05z"/></svg>
                <svg class="icon-filled" viewBox="0 0 24 24" width="20" height="20"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                <span class="count"><?php echo $post->like_count > 0 ? esc_html( $post->like_count ) : ''; ?></span>
            </button>
        <?php endif; ?>
        
        <?php if ( qibbs_get_option( 'enable_comments', true ) ) : ?>
            <button class="qibbs-action-btn qibbs-comment-btn" 
                    data-post-id="<?php echo esc_attr( $post->id ); ?>"
                    title="<?php esc_attr_e( '评论', 'qibbs-community' ); ?>">
                <svg viewBox="0 0 24 24" width="20" height="20"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
                <span class="count"><?php echo $post->comment_count > 0 ? esc_html( $post->comment_count ) : ''; ?></span>
            </button>
        <?php endif; ?>
        
        <?php if ( qibbs_get_option( 'enable_favorites', true ) ) : ?>
            <button class="qibbs-action-btn qibbs-favorite-btn <?php echo ! empty( $post->user_favorited ) ? 'active' : ''; ?>" 
                    data-post-id="<?php echo esc_attr( $post->id ); ?>"
                    title="<?php esc_attr_e( '收藏', 'qibbs-community' ); ?>">
                <svg class="icon-outline" viewBox="0 0 24 24" width="20" height="20"><path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2zm0 15l-5-2.18L7 18V5h10v13z"/></svg>
                <svg class="icon-filled" viewBox="0 0 24 24" width="20" height="20"><path d="M17 3H7c-1.1 0-1.99.9-1.99 2L5 21l7-3 7 3V5c0-1.1-.9-2-2-2z"/></svg>
            </button>
        <?php endif; ?>
        
        <button class="qibbs-action-btn qibbs-share-btn" 
                data-url="<?php echo esc_url( $post->permalink ); ?>"
                title="<?php esc_attr_e( '分享', 'qibbs-community' ); ?>">
            <svg viewBox="0 0 24 24" width="20" height="20"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92-1.31-2.92-2.92-2.92z"/></svg>
        </button>
        <?php
        if ( function_exists( 'developer_starter_get_option' ) && function_exists( 'developer_starter_get_community_ip_location' ) ) {
            $show_community_ip = developer_starter_get_option( 'community_ip_location_enable', '' );
            if ( $show_community_ip && ! empty( $post->author_id ) ) {
                $location = developer_starter_get_community_ip_location( $post->author_id );
                if ( $location ) {
                    ?>
                    <span class="qibbs-location-badge" style="font-size: 12px; color: #94a3b8; margin-left: 8px; display: inline-flex; align-items: center;">
                        <?php echo esc_html( $location ); ?>
                    </span>
                    <?php
                }
            }
        }
        ?>
    </footer>
    
    <?php if ( qibbs_get_option( 'enable_comments', true ) ) : ?>
        <div class="qibbs-comments-section" style="display:none;">
            <div class="qibbs-comments-list"></div>
            <?php if ( is_user_logged_in() && ! $is_discussion_closed ) : ?>
                <form class="qibbs-comment-form">
                    <input type="hidden" name="post_id" value="<?php echo esc_attr( $post->id ); ?>">
                    <img src="<?php echo esc_url( get_avatar_url( $current_user_id, array( 'size' => 32 ) ) ); ?>" class="qibbs-comment-avatar-small">
                    <div class="qibbs-comment-form-fields">
                        <div class="qibbs-comment-form-row">
                            <input type="text" name="content" placeholder="<?php esc_attr_e( '写下你的评论...', 'qibbs-community' ); ?>" autocomplete="off">
                            <button type="submit"><?php esc_html_e( '发送', 'qibbs-community' ); ?></button>
                        </div>
                        <?php 
                        $card_rp = isset( $post->redpacket ) ? $post->redpacket : null;
                        $card_rp_hint = '';
                        $card_rp_is_emphasis = true;
                        if ( $card_rp && 'active' === (string) $card_rp->status && (int) $card_rp->remaining_num > 0 && ( empty( $card_rp->expires_at ) || $card_rp->expires_at > current_time( 'mysql' ) ) ) {
                            $rp_allow_author = (bool) qibbs_get_option( 'redpacket_allow_author_claim', false );
                            $rp_is_owner = $current_user_id && (int) $current_user_id === (int) $post->author_id;
                            if ( $rp_is_owner && ! $rp_allow_author ) {
                                $card_rp_hint = __( '您是发帖人，回复不参与领红包', 'qibbs-community' );
                                $card_rp_is_emphasis = false;
                            } else {
                                $card_claimed = ( $current_user_id && function_exists( 'qibbs_has_user_claimed_redpacket' ) )
                                    ? qibbs_has_user_claimed_redpacket( $card_rp->id, $current_user_id )
                                    : false;
                                if ( $card_claimed ) {
                                    $card_rp_hint = __( '您已领过该红包（每人限领一份）', 'qibbs-community' );
                                    $card_rp_is_emphasis = false;
                                } else {
                                    $card_rp_hint = __( '回帖可抢红包', 'qibbs-community' );
                                    $card_rp_is_emphasis = true;
                                }
                            }
                        }
                        ?>
                        <?php if ( '' !== $card_comment_reward_hint || '' !== $card_best_answer_reward_hint || '' !== $card_rp_hint ) : ?>
                            <div class="qibbs-comment-form-hints">
                                <?php if ( '' !== $card_comment_reward_hint ) : ?>
                                    <span class="qibbs-points-hint"><?php echo esc_html( $card_comment_reward_hint ); ?></span>
                                <?php endif; ?>
                                <?php if ( '' !== $card_best_answer_reward_hint ) : ?>
                                    <span class="qibbs-points-hint is-emphasis"><?php echo esc_html( $card_best_answer_reward_hint ); ?></span>
                                <?php endif; ?>
                                <?php if ( '' !== $card_rp_hint ) : ?>
                                    <span class="qibbs-points-hint <?php echo $card_rp_is_emphasis ? 'is-emphasis' : ''; ?>"><?php echo esc_html( $card_rp_hint ); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            <?php elseif ( $is_discussion_closed ) : ?>
                <div class="qibbs-comments-closed-note"><?php esc_html_e( '该动态已关闭讨论，暂不支持新评论。', 'qibbs-community' ); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</article>
