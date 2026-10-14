<?php
/**
 * 帖子详情页面模板
 *
 * @package Qibbs_Community
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( function_exists( 'qibbs_require_login' ) && qibbs_require_login() && ! is_user_logged_in() ) {
    $access_template = QIBBS_PLUGIN_DIR . 'templates/access-restricted.php';
    if ( file_exists( $access_template ) ) {
        include $access_template;
        return;
    }
    wp_die( esc_html__( '当前功能仅限已登录用户访问。', 'qibbs-community' ) );
}

$post_id = get_query_var( 'qibbs_post_id' );

$post_service = new Qibbs_Post_Page_Service();
$post = $post_service->get_post( $post_id );

if ( ! $post ) {
    get_header();
    echo '<div class="qibbs-wrapper"><div class="qibbs-empty">动态不存在或已被删除</div></div>';
    get_footer();
    return;
}

// 获取标签服务用于解析话题标签
$tag_service = new Qibbs_Tag_Service();

$single_forum_id = 0;
if ( ! empty( $post->forum ) && isset( $post->forum->id ) ) {
    $single_forum_id = (int) $post->forum->id;
} elseif ( isset( $post->forum_id ) ) {
    $single_forum_id = (int) $post->forum_id;
}
$single_author_badges = function_exists( 'qibbs_get_user_badges' )
    ? qibbs_get_user_badges( (int) $post->author_id, $single_forum_id )
    : array();
$single_discussion_status_enabled = function_exists( 'qibbs_is_discussion_status_enabled_for_forum' )
    ? qibbs_is_discussion_status_enabled_for_forum( $single_forum_id )
    : false;
$single_discussion_status = 'discussion';
$single_discussion_closed = false;
if ( $single_discussion_status_enabled ) {
    $single_discussion_status = function_exists( 'qibbs_normalize_discussion_status' )
        ? qibbs_normalize_discussion_status(
            isset( $post->discussion_status ) ? (string) $post->discussion_status : '',
            ! empty( $post->is_resolved ) ? 1 : 0
        )
        : ( ! empty( $post->is_resolved ) ? 'resolved' : 'discussion' );
    $single_discussion_closed = ( 'closed' === $single_discussion_status );
}
$single_current_user_id = get_current_user_id();
$single_reward_context = array(
    'post_id'        => (int) $post->id,
    'forum_id'       => $single_forum_id,
    'post_author_id' => isset( $post->author_id ) ? (int) $post->author_id : 0,
);
$single_comment_reward_hint = $single_current_user_id ? qibbs_get_points_reward_hint( 'comment_approved', __( '评论通过可得', 'qibbs-community' ), $single_current_user_id, $single_reward_context ) : '';
$single_bounty = class_exists( 'Qibbs_Bounty_Service' ) ? Qibbs_Bounty_Service::instance()->get_bounty( $post->id ) : null;
$single_is_pending_bounty = ( $single_bounty && in_array( (string) $single_bounty->status, array( 'pending', 'pending_arbitration' ), true ) );
$single_best_answer_reward_hint = '';
if ( $single_is_pending_bounty && function_exists( 'qibbs_get_points_name' ) ) {
    $points_name = qibbs_get_points_name();
    $bounty_pts = isset( $single_bounty->amount ) ? (float) $single_bounty->amount : ( (float) ( $single_bounty->points ?? 0 ) );
    $bounty_display = function_exists( 'qibbs_format_points_amount' ) ? qibbs_format_points_amount( $bounty_pts ) : $bounty_pts;
    $sys_best_settings = function_exists( 'qibbs_get_points_reward_settings' ) ? qibbs_get_points_reward_settings( 'best_answer' ) : null;
    $sys_bonus_pts = ( ! empty( $sys_best_settings['enabled'] ) && ! empty( $sys_best_settings['amount'] ) ) ? (float) $sys_best_settings['amount'] : 0;
    $sys_bonus_display = function_exists( 'qibbs_format_points_amount' ) ? qibbs_format_points_amount( $sys_bonus_pts ) : $sys_bonus_pts;
    $can_earn_sys_bonus = $single_current_user_id && function_exists( 'qibbs_can_user_earn_points_reward' )
        ? qibbs_can_user_earn_points_reward( 'best_answer', $single_current_user_id, $single_reward_context )
        : ( $sys_bonus_pts > 0 );

    if ( $can_earn_sys_bonus && $sys_bonus_pts > 0 ) {
        $single_best_answer_reward_hint = sprintf(
            __( '最佳答案获悬赏 %s %s（系统额外 +%s）', 'qibbs-community' ),
            $bounty_display,
            $points_name,
            $sys_bonus_display
        );
    } else {
        $single_best_answer_reward_hint = sprintf( __( '最佳答案获悬赏 %s %s', 'qibbs-community' ), $bounty_display, $points_name );
    }
}

get_header();

// 获取主题预设风格属性
$layout_service = new Qibbs_Layout_Service();
$theme_attr = $layout_service->get_theme_attribute();
?>

<div class="qibbs-wrapper"<?php echo $theme_attr; ?>>
    <div class="qibbs-single-post">
        <div class="qibbs-single-post-card" data-post-id="<?php echo esc_attr( $post->id ); ?>">
            <div class="qibbs-card-header">
                <a href="<?php echo esc_url( $post->author['profile_url'] ); ?>" class="qibbs-avatar">
                    <img src="<?php echo esc_url( $post->author['avatar'] ); ?>" alt="<?php echo esc_attr( $post->author['display_name'] ); ?>">
                </a>
                <div class="qibbs-card-meta">
                    <div class="qibbs-author-row">
                        <a href="<?php echo esc_url( $post->author['profile_url'] ); ?>" class="qibbs-author-name"<?php echo ! empty( $post->author['name_color'] ) ? ' style="color: ' . esc_attr( $post->author['name_color'] ) . ' !important; font-weight: 600;"' : ''; ?>>
                            <?php echo esc_html( $post->author['display_name'] ); ?>
                        </a>
                        <?php if ( function_exists( 'qibbs_render_cuid_badge' ) && ! empty( $post->author_id ) ) : ?>
                            <?php echo qibbs_render_cuid_badge( $post->author_id ); ?>
                        <?php endif; ?>
                        <?php if ( function_exists( 'qibbs_render_user_level_badge' ) && ! empty( $post->author_id ) ) : ?>
                            <?php echo qibbs_render_user_level_badge( $post->author_id ); ?>
                        <?php endif; ?>
                    </div>
                    <?php if ( ! empty( $single_author_badges ) ) : ?>
                        <div class="qibbs-author-badges">
                            <?php foreach ( $single_author_badges as $badge_item ) : ?>
                                <span class="qibbs-user-badge <?php echo esc_attr( $badge_item['class'] ); ?>">
                                    <?php echo esc_html( $badge_item['label'] ); ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="qibbs-card-info">
                        <?php
                        $single_post_exact_time = function_exists( 'qibbs_format_exact_datetime' )
                            ? qibbs_format_exact_datetime( $post->created_at, 'Y-m-d H:i' )
                            : ( ! empty( $post->created_at ) ? substr( (string) $post->created_at, 0, 16 ) : '' );
                        $single_post_full_time = function_exists( 'qibbs_format_exact_datetime' )
                            ? qibbs_format_exact_datetime( $post->created_at, 'Y-m-d H:i:s' )
                            : (string) $post->created_at;
                        ?>
                        <time class="qibbs-time" datetime="<?php echo esc_attr( $post->created_at ); ?>" title="<?php echo esc_attr( $single_post_full_time ? sprintf( __( '发布于 %s', 'qibbs-community' ), $single_post_full_time ) : '' ); ?>">
                            <?php echo esc_html( $single_post_exact_time ? $single_post_exact_time : $post->time_ago ); ?>
                        </time>
                        <?php if ( ! empty( $post->forum ) ) : ?>
                            <span class="qibbs-separator">·</span>
                            <a href="<?php echo esc_url( qibbs_get_community_url( array( 'forum' => $post->forum->id ) ) ); ?>" class="qibbs-forum-link">
                                <?php echo esc_html( $post->forum->name ); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ( ! empty( $post->is_active_promoted ) || ( ! empty( $post->is_promoted ) && ( empty( $post->promoted_until ) || $post->promoted_until > current_time( 'mysql' ) ) ) ) : ?>
                            <span class="qibbs-separator">·</span>
                            <span class="qibbs-promoted-badge" title="<?php esc_attr_e( '圈子置顶推广中', 'qibbs-community' ); ?>">
                                <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" style="vertical-align: -1.5px; margin-right: 2px;"><path d="M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 10h2v4h-2zm0 5h2v2h-2z"/></svg>
                                <?php esc_html_e( '推广', 'qibbs-community' ); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( ! empty( $post->post_type_label ) && ! empty( $post->post_type ) && 'discussion' !== $post->post_type ) : ?>
                            <span class="qibbs-separator">·</span>
                            <span class="qibbs-post-type-badge qibbs-post-type-badge-<?php echo esc_attr( $post->post_type ); ?>">
                                <?php echo esc_html( $post->post_type_label ); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( $single_discussion_status_enabled ) : ?>
                            <span class="qibbs-separator">·</span>
                            <span class="qibbs-single-discussion-status" data-status="<?php echo esc_attr( $single_discussion_status ); ?>">
                                <?php if ( 'resolved' === $single_discussion_status ) : ?>
                                    <span class="qibbs-resolved-badge qibbs-discussion-badge"><?php esc_html_e( '已解决', 'qibbs-community' ); ?></span>
                                <?php elseif ( 'closed' === $single_discussion_status ) : ?>
                                    <span class="qibbs-closed-badge qibbs-discussion-badge"><?php esc_html_e( '已关闭', 'qibbs-community' ); ?></span>
                                <?php else : ?>
                                    <span class="qibbs-discussion-badge"><?php esc_html_e( '讨论中', 'qibbs-community' ); ?></span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="qibbs-card-content">
                <?php 
                $interactive_banner = QIBBS_PLUGIN_DIR . 'templates/post-interactive-banner.php';
                if ( file_exists( $interactive_banner ) ) {
                    include $interactive_banner;
                }
                ?>

                <div class="qibbs-content-text">
                    <?php 
                    $post_content = $tag_service->linkify_hashtags( $post->content );
                    // 解析视频链接（MP4、B站等）
                    if ( function_exists( 'qibbs_parse_video_links' ) ) {
                        $post_content = qibbs_parse_video_links( $post_content );
                    }
                    // 使用允许 video 与 iframe 标签的安全过滤函数
                    if ( function_exists( 'qibbs_kses_video_content' ) ) {
                        echo qibbs_kses_video_content( $post_content );
                    } else {
                        echo wp_kses_post( $post_content );
                    }
                    ?>
                </div>
                
                <?php if ( ! empty( $post->media ) ) : ?>
                    <div class="qibbs-media-grid qibbs-media-<?php echo count( $post->media ); ?>">
                        <?php foreach ( $post->media as $media ) : ?>
                            <figure class="qibbs-media-item">
                                <?php if ( strpos( $media->mime_type, 'video' ) !== false ) : ?>
                                    <video controls>
                                        <source src="<?php echo esc_url( $media->file_url ); ?>" type="<?php echo esc_attr( $media->mime_type ); ?>">
                                    </video>
                                <?php else : ?>
                                    <img src="<?php echo esc_url( $media->file_url ); ?>" alt="" loading="lazy">
                                <?php endif; ?>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="qibbs-card-actions">
                <button class="qibbs-action-btn qibbs-like-btn <?php echo $post->user_liked ? 'active' : ''; ?>" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                    <svg class="icon-outline" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                    <svg class="icon-filled" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
                    <span class="count"><?php echo $post->like_count > 0 ? esc_html( $post->like_count ) : ''; ?></span>
                </button>
                
                <button class="qibbs-action-btn qibbs-comment-toggle-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                    <span class="count"><?php echo $post->comment_count > 0 ? esc_html( $post->comment_count ) : ''; ?></span>
                </button>
                
                <button class="qibbs-action-btn qibbs-favorite-btn <?php echo $post->user_favorited ? 'active' : ''; ?>" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                    <svg class="icon-outline" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                    <svg class="icon-filled" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                </button>
                
                <button class="qibbs-action-btn qibbs-share-btn" data-url="<?php echo esc_url( qibbs_get_post_url( $post->id ) ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"></circle><circle cx="6" cy="12" r="3"></circle><circle cx="18" cy="19" r="3"></circle><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line></svg>
                </button>

                <?php if ( $single_is_owner && function_exists( 'qibbs_is_prop_mall_enabled' ) && qibbs_is_prop_mall_enabled() ) : ?>
                    <button class="qibbs-action-btn qibbs-promote-post-btn" data-post-id="<?php echo esc_attr( $post->id ); ?>" data-forum-id="<?php echo esc_attr( $single_forum_id ); ?>" title="<?php esc_attr_e( '置顶推广', 'qibbs-community' ); ?>">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 10h2v4h-2zm0 5h2v2h-2z"/></svg>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- 评论互动区域 -->
        <div class="qibbs-comments-section" id="qibbs-comments">
            <h3 class="qibbs-comments-title">
                <span class="qibbs-comments-heading">
                    <span><?php esc_html_e( '评论讨论', 'qibbs-community' ); ?></span>
                    <span class="qibbs-comments-subtitle"><?php esc_html_e( '优先展示最佳答案、优质回复和高质量互动', 'qibbs-community' ); ?></span>
                </span>
                <span class="qibbs-comments-count"><?php echo esc_html( absint( $post->comment_count ) ); ?></span>
            </h3>
            <div class="qibbs-comments-statusbar">
                <span class="qibbs-comments-status-note"><?php esc_html_e( '支持楼中楼回复、最佳答案、优质置顶和版主治理操作', 'qibbs-community' ); ?></span>
            </div>
            
            <?php if ( is_user_logged_in() && ! $single_discussion_closed ) : ?>
                <div class="qibbs-comment-form">
                    <img src="<?php echo esc_url( get_avatar_url( get_current_user_id(), array( 'size' => 48 ) ) ); ?>" alt="" class="qibbs-comment-avatar">
                    <div class="qibbs-comment-form-main">
                        <textarea id="qibbs-comment-input" placeholder="<?php esc_attr_e( '写下你的评论...', 'qibbs-community' ); ?>"></textarea>
                        <div class="qibbs-comment-form-footer">
                            <div class="qibbs-comment-form-hints">
                                <span class="qibbs-comment-form-tip"><?php esc_html_e( '理性交流，支持 Ctrl/Command + Enter 快速发送', 'qibbs-community' ); ?></span>
                                <?php if ( '' !== $single_comment_reward_hint ) : ?>
                                    <span class="qibbs-points-hint"><?php echo esc_html( $single_comment_reward_hint ); ?></span>
                                <?php endif; ?>
                                <?php if ( '' !== $single_best_answer_reward_hint ) : ?>
                                    <span class="qibbs-points-hint is-emphasis"><?php echo esc_html( $single_best_answer_reward_hint ); ?></span>
                                <?php endif; ?>
                                <?php 
                                $single_rp = isset( $post->redpacket ) ? $post->redpacket : ( function_exists( 'qibbs_get_redpacket' ) ? qibbs_get_redpacket( $post->id ) : null );
                                if ( $single_rp && 'active' === (string) $single_rp->status && (int) $single_rp->remaining_num > 0 && ( empty( $single_rp->expires_at ) || $single_rp->expires_at > current_time( 'mysql' ) ) ) :
                                    $rp_allow_author = (bool) qibbs_get_option( 'redpacket_allow_author_claim', false );
                                    $rp_is_owner = get_current_user_id() && (int) get_current_user_id() === (int) $post->author_id;
                                    if ( $rp_is_owner && ! $rp_allow_author ) : ?>
                                        <span class="qibbs-points-hint qibbs-redpacket-author-hint"><?php esc_html_e( '您是发帖人，回复不参与领红包', 'qibbs-community' ); ?></span>
                                    <?php else : 
                                        $has_claimed = function_exists( 'qibbs_has_user_claimed_redpacket' ) ? qibbs_has_user_claimed_redpacket( $single_rp->id ) : false;
                                        if ( $has_claimed ) : ?>
                                            <span class="qibbs-points-hint qibbs-redpacket-claimed-hint"><?php esc_html_e( '您已领过该红包（每人限领一份）', 'qibbs-community' ); ?></span>
                                        <?php else : ?>
                                            <span class="qibbs-points-hint is-emphasis qibbs-redpacket-claim-hint"><?php esc_html_e( '回帖可抢红包', 'qibbs-community' ); ?></span>
                                        <?php endif;
                                    endif;
                                endif;
                                ?>
                            </div>
                            <button class="qibbs-comment-submit" id="qibbs-comment-submit" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                                <span class="qibbs-comment-submit-label"><?php esc_html_e( '发布评论', 'qibbs-community' ); ?></span>
                            </button>
                        </div>
                    </div>
                </div>
            <?php elseif ( $single_discussion_status_enabled && $single_discussion_closed ) : ?>
                <p class="qibbs-comments-closed-note"><?php esc_html_e( '该动态已关闭讨论，暂不支持新评论。', 'qibbs-community' ); ?></p>
            <?php else : ?>
                <p class="qibbs-login-prompt">
                    <?php echo Qibbs_Theme_Compatibility::get_login_button_html( '', __( '登录后发表评论', 'qibbs-community' ) ); ?>
                </p>
            <?php endif; ?>
            
            <div class="qibbs-comments-list" id="qibbs-comments-list" data-post-id="<?php echo esc_attr( $post->id ); ?>">
                <div class="qibbs-loading"><?php esc_html_e( '加载评论中...', 'qibbs-community' ); ?></div>
            </div>
        </div>
        
        <a href="<?php echo esc_url( qibbs_get_community_url() ); ?>" class="qibbs-back-link">
            ← <?php esc_html_e( '返回社区', 'qibbs-community' ); ?>
        </a>
    </div>
</div>

<!-- Toast 浮层通知容器 -->
<div id="qibbs-toast" class="qibbs-toast"></div>

<style>
.qibbs-single-post {
    max-width: 680px;
    margin: 0 auto;
}
.qibbs-single-post-card {
    background: var(--qibbs-bg-card);
    border-radius: var(--qibbs-radius);
    padding: 24px;
    box-shadow: var(--qibbs-shadow);
    margin-bottom: 20px;
}
.qibbs-single-post .qibbs-content-text {
    font-size: 16px;
    line-height: 1.8;
}
.qibbs-comments-section {
    background: var(--qibbs-bg-card);
    border-radius: var(--qibbs-radius);
    padding: 24px;
    box-shadow: var(--qibbs-shadow);
    margin-bottom: 20px;
    overflow: hidden;
}
.qibbs-comments-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    font-size: 18px;
    margin: 0 0 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--qibbs-border);
}
.qibbs-comments-heading {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}
.qibbs-comments-subtitle {
    font-size: 12px;
    line-height: 1.5;
    color: var(--qibbs-text-muted);
    font-weight: 500;
}
.qibbs-comments-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    height: 30px;
    padding: 0 10px;
    border-radius: 999px;
    background: rgba(99, 102, 241, 0.08);
    color: var(--qibbs-primary);
    font-size: 13px;
    font-weight: 700;
}
.qibbs-comments-statusbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: -6px 0 18px;
    flex-wrap: wrap;
}
.qibbs-comments-status-note {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    border-radius: 999px;
    background: rgba(99, 102, 241, 0.06);
    border: 1px solid rgba(99, 102, 241, 0.12);
    color: var(--qibbs-text-secondary);
    font-size: 12px;
    line-height: 1.5;
}
.qibbs-comment-form {
    display: flex;
    gap: 14px;
    margin-bottom: 24px;
    align-items: flex-start;
    padding: 16px;
    border: 1px solid rgba(99, 102, 241, 0.12);
    border-radius: 20px;
    background: linear-gradient(180deg, rgba(99, 102, 241, 0.04), rgba(99, 102, 241, 0.01));
    box-sizing: border-box;
}
.qibbs-comment-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    flex-shrink: 0;
}
.qibbs-comment-form-main {
    flex: 1;
    min-width: 0;
}
.qibbs-comment-form textarea {
    display: block;
    width: 100%;
    border: 1px solid var(--qibbs-border);
    border-radius: 18px;
    padding: 14px 16px;
    font-size: 15px;
    resize: none;
    min-height: 96px;
    font-family: inherit;
    background: var(--qibbs-bg-card);
    color: var(--qibbs-text);
    line-height: 1.7;
    box-sizing: border-box;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    overflow: hidden;
}
.qibbs-comment-form textarea:focus {
    outline: none;
    border-color: var(--qibbs-primary);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.08);
}
.qibbs-comment-form-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 12px;
    flex-wrap: wrap;
}
.qibbs-comment-form-tip {
    font-size: 12px;
    color: var(--qibbs-text-muted);
    line-height: 1.6;
}
.qibbs-comment-form-hints {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    min-width: 0;
}
.qibbs-comment-submit {
    background: linear-gradient(135deg, var(--qibbs-primary), var(--qibbs-secondary));
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 999px;
    font-weight: 600;
    cursor: pointer;
    min-width: 116px;
    white-space: nowrap;
    box-shadow: 0 10px 24px -16px rgba(99, 102, 241, 0.8);
}
.qibbs-comment-submit:hover {
    opacity: 0.9;
}
.qibbs-comment-submit:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}
.qibbs-login-prompt {
    text-align: center;
    padding: 20px;
    color: var(--qibbs-text-muted);
}
.qibbs-comments-closed-note {
    margin: 0 0 20px;
    padding: 12px 14px;
    border-radius: 16px;
    border: 1px solid rgba(244, 63, 94, 0.14);
    background: rgba(244, 63, 94, 0.06);
    color: #be123c;
    font-size: 13px;
    line-height: 1.6;
}
.qibbs-login-prompt a {
    color: var(--qibbs-primary);
}
.qibbs-back-link {
    display: inline-block;
    color: var(--qibbs-text-secondary);
    text-decoration: none;
    padding: 10px 0;
}
.qibbs-back-link:hover {
    color: var(--qibbs-primary);
}
.qibbs-toast {
    position: fixed;
    bottom: 80px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--qibbs-text);
    color: var(--qibbs-bg-card);
    padding: 12px 24px;
    border-radius: var(--qibbs-radius);
    font-size: 14px;
    z-index: 9999;
    opacity: 0;
    transition: opacity 0.3s;
    pointer-events: none;
}
.qibbs-toast.show {
    opacity: 1;
}
.qibbs-comment-item {
    display: flex;
    gap: 14px;
    padding: 18px;
    border: 1px solid var(--qibbs-border-light);
    border-radius: 20px;
    background: linear-gradient(180deg, rgba(248, 250, 252, 0.92), rgba(255, 255, 255, 0.98));
    box-sizing: border-box;
    min-width: 0;
    overflow: hidden;
}
.qibbs-comments-list {
    display: grid;
    gap: 14px;
    min-width: 0;
}
.qibbs-comment-avatar-link .qibbs-comment-avatar {
    width: 36px;
    height: 36px;
}
.qibbs-comment-body {
    flex: 1;
    min-width: 0;
    overflow: hidden;
}
.qibbs-comment-header {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 8px;
    margin-bottom: 8px;
    min-width: 0;
}
.qibbs-comment-meta {
    flex: 1;
    min-width: 0;
}
.qibbs-comment-title-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    min-width: 0;
}
.qibbs-comment-subline {
    margin-top: 2px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.qibbs-comment-author {
    font-weight: 600;
    color: var(--qibbs-text);
    text-decoration: none;
    font-size: 15px;
}
.qibbs-comment-time {
    font-size: 12px;
    color: var(--qibbs-text-muted);
    white-space: nowrap;
}
.qibbs-comment-content {
    font-size: 14px;
    line-height: 1.75;
    color: var(--qibbs-text);
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: break-word;
}
.qibbs-comment-meta-pills {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 6px;
    flex-wrap: wrap;
}
.qibbs-comment-meta-pill {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    border-radius: 999px;
    background: rgba(148, 163, 184, 0.12);
    color: var(--qibbs-text-muted);
    font-size: 11px;
    line-height: 1.4;
}
.qibbs-comment-badges {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    min-width: 0;
}
.qibbs-comment-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    line-height: 1.4;
}
.qibbs-comment-badge-best {
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: #fff;
}
.qibbs-comment-badge-quality {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff;
}
.qibbs-comment-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    justify-content: flex-start;
    max-width: 100%;
}
.qibbs-comment-actions button {
    border: 1px solid rgba(148, 163, 184, 0.22);
    background: rgba(248, 250, 252, 0.92);
    color: var(--qibbs-text-muted);
    font-size: 11px;
    cursor: pointer;
    border-radius: 999px;
    padding: 4px 8px;
    line-height: 1.15;
    white-space: nowrap;
    box-sizing: border-box;
}
.qibbs-comment-actions button:hover {
    background: rgba(99, 102, 241, 0.08);
    color: var(--qibbs-text);
    border-color: rgba(99, 102, 241, 0.18);
}
.qibbs-single-set-best-answer,
.qibbs-single-clear-best-answer {
    color: #16a34a !important;
}
.qibbs-single-toggle-quality-pin {
    color: #d97706 !important;
}
.qibbs-single-comment-like {
    color: var(--qibbs-text-muted) !important;
}
.qibbs-single-comment-like.active {
    color: #e11d48 !important;
    background: rgba(225, 29, 72, 0.1);
    border-color: rgba(225, 29, 72, 0.18);
}
.qibbs-single-comment-like .qibbs-comment-like-count {
    margin-left: 4px;
    min-width: 10px;
    display: inline-block;
}
.qibbs-single-comments-toolbar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 10px;
}
.qibbs-comment-stream {
    display: grid;
    gap: 14px;
}
.qibbs-comment-replies {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px dashed rgba(148, 163, 184, 0.22);
}
.qibbs-comment-replies-list {
    display: grid;
    gap: 10px;
}
.qibbs-comment-reply-item {
    display: flex;
    gap: 10px;
    padding: 12px 14px;
    border: 1px solid rgba(148, 163, 184, 0.14);
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.86);
    min-width: 0;
    overflow: hidden;
}
.qibbs-comment-reply-avatar {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    flex-shrink: 0;
}
.qibbs-comment-reply-body {
    flex: 1;
    min-width: 0;
}
.qibbs-comment-reply-header {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 6px;
    margin-bottom: 6px;
}
.qibbs-comment-reply-meta {
    flex: 1;
    min-width: 0;
}
.qibbs-comment-reply-title {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.qibbs-comment-reply-author {
    color: var(--qibbs-text);
    font-weight: 600;
    text-decoration: none;
    font-size: 13px;
}
.qibbs-comment-reply-time {
    color: var(--qibbs-text-muted);
    font-size: 11px;
    white-space: nowrap;
}
.qibbs-comment-reply-content {
    color: var(--qibbs-text-secondary);
    font-size: 13px;
    line-height: 1.7;
    overflow-wrap: anywhere;
    word-break: break-word;
}
.qibbs-comment-reply-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    justify-content: flex-start;
}
.qibbs-comment-reply-actions button,
.qibbs-comment-secondary-btn,
.qibbs-comment-inline-reply .qibbs-comment-submit {
    border-radius: 999px;
}
.qibbs-comment-reply-actions button,
.qibbs-comment-secondary-btn {
    border: 1px solid rgba(148, 163, 184, 0.2);
    background: rgba(248, 250, 252, 0.92);
    color: var(--qibbs-text-muted);
    padding: 4px 8px;
    font-size: 11px;
    line-height: 1.15;
    cursor: pointer;
}
.qibbs-comment-reply-actions button:hover,
.qibbs-comment-secondary-btn:hover {
    background: rgba(99, 102, 241, 0.08);
    color: var(--qibbs-text);
    border-color: rgba(99, 102, 241, 0.18);
}
.qibbs-comment-inline-reply {
    margin-top: 12px;
    padding: 12px;
    border: 1px solid rgba(99, 102, 241, 0.14);
    border-radius: 16px;
    background: rgba(99, 102, 241, 0.03);
}
.qibbs-comment-inline-reply-host:empty {
    display: none;
}
.qibbs-comment-inline-reply textarea {
    display: block;
    width: 100%;
    min-height: 84px;
    resize: none;
    border: 1px solid var(--qibbs-border);
    border-radius: 14px;
    padding: 12px 14px;
    box-sizing: border-box;
    font-size: 14px;
    line-height: 1.7;
    font-family: inherit;
    color: var(--qibbs-text);
    background: var(--qibbs-bg-card);
}
.qibbs-comment-inline-reply textarea:focus {
    outline: none;
    border-color: var(--qibbs-primary);
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.08);
}
.qibbs-comment-inline-reply-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 10px;
    flex-wrap: wrap;
}
.qibbs-comment-inline-reply-hint {
    color: var(--qibbs-text-muted);
    font-size: 12px;
    line-height: 1.5;
}
.qibbs-comment-inline-reply-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}
.qibbs-comments-load-more-wrap,
.qibbs-comment-replies-more-wrap {
    display: flex;
    justify-content: center;
    margin-top: 12px;
}
.qibbs-comments-load-more,
.qibbs-comment-replies-more {
    border: 1px solid rgba(99, 102, 241, 0.16);
    background: rgba(99, 102, 241, 0.06);
    color: var(--qibbs-primary);
    font-weight: 600;
    padding: 10px 18px;
    border-radius: 999px;
    cursor: pointer;
}
.qibbs-comments-load-more:hover,
.qibbs-comment-replies-more:hover {
    background: rgba(99, 102, 241, 0.12);
}
.qibbs-comments-load-more:disabled,
.qibbs-comment-replies-more:disabled {
    opacity: 0.68;
    cursor: not-allowed;
}
.qibbs-comments-loading {
    display: grid;
    gap: 14px;
}
.qibbs-comment-loading-card {
    border: 1px solid var(--qibbs-border-light);
    border-radius: 20px;
    padding: 18px;
    background: linear-gradient(180deg, rgba(248, 250, 252, 0.92), rgba(255, 255, 255, 0.98));
}
.qibbs-comment-loading-row,
.qibbs-comment-loading-line {
    position: relative;
    overflow: hidden;
    background: rgba(148, 163, 184, 0.14);
    border-radius: 999px;
}
.qibbs-comment-loading-row::after,
.qibbs-comment-loading-line::after {
    content: "";
    position: absolute;
    inset: 0;
    transform: translateX(-100%);
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.9), transparent);
    animation: qibbsCommentShimmer 1.5s infinite;
}
.qibbs-comment-loading-row {
    width: 46%;
    height: 12px;
    margin-bottom: 12px;
}
.qibbs-comment-loading-line {
    height: 10px;
    margin-bottom: 10px;
}
.qibbs-comment-loading-line:last-child {
    width: 72%;
    margin-bottom: 0;
}
@keyframes qibbsCommentShimmer {
    100% {
        transform: translateX(100%);
    }
}
.qibbs-single-reopen-question-btn {
    padding: 6px 12px;
    border: 1px solid rgba(22, 163, 74, 0.35);
    border-radius: 999px;
    background: rgba(22, 163, 74, 0.08);
    color: #15803d;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}
.qibbs-single-reopen-question-btn:hover {
    background: rgba(22, 163, 74, 0.16);
}
.qibbs-empty-comments {
    text-align: center;
    color: var(--qibbs-text-muted);
    padding: 26px 18px;
    border: 1px dashed var(--qibbs-border);
    border-radius: 18px;
    background: rgba(248, 250, 252, 0.7);
}
@media (max-width: 767px) {
    .qibbs-single-post-card,
    .qibbs-comments-section {
        padding: 18px;
    }
    .qibbs-comments-title {
        align-items: flex-start;
    }
    .qibbs-comments-heading {
        flex: 1;
    }
    .qibbs-comments-statusbar {
        margin-top: -2px;
    }
    .qibbs-comment-form,
    .qibbs-comment-item {
        padding: 14px;
        border-radius: 18px;
    }
    .qibbs-comment-form {
        flex-direction: column;
    }
    .qibbs-comment-form .qibbs-comment-avatar {
        width: 36px;
        height: 36px;
    }
    .qibbs-comment-form-footer {
        align-items: stretch;
    }
    .qibbs-comment-submit {
        width: 100%;
    }
    .qibbs-comment-header {
        flex-direction: column;
    }
    .qibbs-comment-actions {
        width: 100%;
        justify-content: flex-start;
    }
    .qibbs-comment-actions button {
        flex: 0 1 auto;
    }
    .qibbs-comment-reply-item {
        padding: 12px;
    }
    .qibbs-comment-reply-header,
    .qibbs-comment-inline-reply-footer {
        flex-direction: column;
        align-items: stretch;
    }
    .qibbs-comment-reply-actions,
    .qibbs-comment-inline-reply-actions {
        justify-content: flex-start;
    }
}
</style>

<script>
// 若未注入 qibbsData 则提供内联脚本兜底定义
if (typeof qibbsData === 'undefined') {
    var qibbsData = <?php echo wp_json_encode( array(
        'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
        'restUrl'      => rest_url( 'qibbs/v1/' ),
        'nonce'        => wp_create_nonce( 'qibbs_nonce' ),
        'restNonce'    => wp_create_nonce( 'wp_rest' ),
        'isLoggedIn'   => is_user_logged_in(),
        'isAdmin'      => current_user_can( 'manage_options' ),
        'currentUser'  => get_current_user_id(),
        'currentUserAvatar' => is_user_logged_in() ? get_avatar_url( get_current_user_id(), array( 'size' => 48 ) ) : '',
    ) ); ?>;
}

// Toast 提示方法
function showToast(message) {
    var toast = document.getElementById('qibbs-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'qibbs-toast';
        toast.className = 'qibbs-toast';
        document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.classList.add('show');
    setTimeout(function() {
        toast.classList.remove('show');
    }, 2000);
}

jQuery(document).ready(function($) {
    var postId = <?php echo (int) $post->id; ?>;
    var postAuthorId = <?php echo (int) $post->author_id; ?>;
    var initialCommentCount = <?php echo (int) $post->comment_count; ?>;
    var currentDiscussionStatus = '<?php echo esc_js( $single_discussion_status ); ?>';
    var $commentsSection = $('#qibbs-comments');
    var $commentsList = $('#qibbs-comments-list');
    var $commentInput = $('#qibbs-comment-input');
    var $commentSubmit = $('#qibbs-comment-submit');
    var activeCommentsRequest = null;

    function safeUrl(url) {
        if (!url) {
            return '#';
        }
        try {
            var parsed = new URL(url, window.location.origin);
            if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
                return '#';
            }
            return $('<div>').text(parsed.href).html();
        } catch (e) {
            return '#';
        }
    }

    function escapeHtml(text) {
        return $('<div>').text(text == null ? '' : String(text)).html();
    }

    function escapeAttr(text) {
        return escapeHtml(text).replace(/"/g, '&quot;');
    }

    function getString(key, fallback) {
        return (qibbsData.strings && qibbsData.strings[key]) ? qibbsData.strings[key] : fallback;
    }

    function canReplyToComment() {
        return !!qibbsData.isLoggedIn && currentDiscussionStatus !== 'closed';
    }

    function updateCommentCounts(commentCount) {
        var normalizedCount = Math.max(0, parseInt(commentCount, 10) || 0);
        $('.qibbs-comments-count').text(normalizedCount);
        $('.qibbs-comment-toggle-btn .count').text(normalizedCount > 0 ? normalizedCount : '');
    }

    function updateStatusNote(status) {
        var $note = $commentsSection.find('.qibbs-comments-status-note');
        if (!$note.length) {
            return;
        }

        if (status === 'closed') {
            $note.text(getString('discussionClosedStatusHint', '当前动态已关闭讨论，仅保留浏览与治理操作。'));
            return;
        }

        if (status === 'resolved') {
            $note.text(getString('discussionResolvedStatusHint', '当前动态已标记为已解决，优先展示最佳答案和优质回复。'));
            return;
        }

        $note.text(getString('discussionOpenStatusHint', '支持楼中楼回复、最佳答案、优质置顶和版主治理操作'));
    }

    function toggleCommentFormByStatus(status) {
        var $form = $commentsSection.find('.qibbs-comment-form');
        var $closed = $commentsSection.find('.qibbs-comments-closed-note');

        currentDiscussionStatus = status || 'discussion';
        updateStatusNote(currentDiscussionStatus);

        if (currentDiscussionStatus === 'closed') {
            if ($form.length) {
                $form.hide();
            }
            if (!$closed.length) {
                $commentsSection.find('.qibbs-comments-title').after(
                    '<p class="qibbs-comments-closed-note">' +
                        escapeHtml(getString('discussionClosedNote', '该动态已关闭讨论，暂不支持新评论。')) +
                    '</p>'
                );
            }
            return;
        }

        if ($closed.length) {
            $closed.remove();
        }
        if ($form.length) {
            $form.show();
        }
    }

    function updateDiscussionBadge(status) {
        var $wrap = $('.qibbs-single-discussion-status');
        var normalizedStatus = status || 'discussion';

        if ($wrap.length) {
            var badgeHtml = '';
            var labelDiscussion = escapeHtml(getString('statusLabelDiscussion', '讨论中'));
            var labelResolved = escapeHtml(getString('statusLabelResolved', '已解决'));
            var labelClosed = escapeHtml(getString('statusLabelClosed', '已关闭'));

            if (normalizedStatus === 'resolved') {
                badgeHtml = '<span class="qibbs-resolved-badge qibbs-discussion-badge">' + labelResolved + '</span>';
            } else if (normalizedStatus === 'closed') {
                badgeHtml = '<span class="qibbs-closed-badge qibbs-discussion-badge">' + labelClosed + '</span>';
            } else {
                badgeHtml = '<span class="qibbs-discussion-badge">' + labelDiscussion + '</span>';
            }

            $wrap.attr('data-status', normalizedStatus).html(badgeHtml);
        }

        toggleCommentFormByStatus(normalizedStatus);
    }

    function renderCommentsLoading(count) {
        var total = Math.max(2, parseInt(count, 10) || 3);
        var cards = '';
        var i = 0;

        for (i = 0; i < total; i += 1) {
            cards += '<div class="qibbs-comment-loading-card">'
                + '<div class="qibbs-comment-loading-row"></div>'
                + '<div class="qibbs-comment-loading-line"></div>'
                + '<div class="qibbs-comment-loading-line"></div>'
                + '</div>';
        }

        return '<div class="qibbs-comments-loading">' + cards + '</div>';
    }

    function renderEmptyComments(message) {
        return '<div class="qibbs-empty-comments">' + escapeHtml(message) + '</div>';
    }

    function renderLoadMoreCommentsButton(nextPage) {
        return '<div class="qibbs-comments-load-more-wrap">'
            + '<button type="button" class="qibbs-comments-load-more" data-next-page="' + nextPage + '">'
            + escapeHtml(getString('loadMoreComments', '查看更多评论'))
            + '</button>'
            + '</div>';
    }

    function renderLoadMoreRepliesButton(commentId, nextPage, remainingCount) {
        var label = remainingCount > 0
            ? '查看更多回复（剩余 ' + remainingCount + ' 条）'
            : getString('loadMoreReplies', '查看更多回复');

        return '<div class="qibbs-comment-replies-more-wrap">'
            + '<button type="button" class="qibbs-comment-replies-more" data-comment-id="' + commentId + '" data-next-page="' + nextPage + '">'
            + escapeHtml(label)
            + '</button>'
            + '</div>';
    }

    function renderCommentMetaPills(comment, isReply) {
        var pills = [];
        var replyCount = parseInt(comment.reply_count, 10) || 0;
        var authorId = comment.author && comment.author.id ? parseInt(comment.author.id, 10) : 0;

        if (authorId === postAuthorId) {
            pills.push('<span class="qibbs-comment-meta-pill">' + escapeHtml(getString('postAuthorPill', '楼主')) + '</span>');
        }

        if (!isReply && replyCount > 0) {
            pills.push('<span class="qibbs-comment-meta-pill">' + escapeHtml(replyCount + ' 条回复') + '</span>');
        }

        return pills.length ? '<div class="qibbs-comment-meta-pills">' + pills.join('') + '</div>' : '';
    }

    function renderCommentBadges(comment) {
        var badges = '';

        if (comment.is_best) {
            badges += '<span class="qibbs-comment-badge qibbs-comment-badge-best">' + escapeHtml(getString('bestAnswerBadge', '最佳答案')) + '</span>';
        }
        if (comment.is_quality_pinned) {
            badges += '<span class="qibbs-comment-badge qibbs-comment-badge-quality">' + escapeHtml(getString('qualityPinnedBadge', '优质置顶')) + '</span>';
        }

        return badges ? '<span class="qibbs-comment-badges">' + badges + '</span>' : '';
    }

    function renderCommentLikeButton(comment) {
        var liked = !!comment.user_liked;
        var likeCount = parseInt(comment.like_count, 10) || 0;

        return '<button type="button" class="qibbs-single-comment-like ' + (liked ? 'active' : '') + '" data-id="' + comment.id + '">'
            + '<span class="qibbs-comment-like-label">'
            + escapeHtml(liked ? getString('likedComment', '已赞') : getString('likeComment', '赞'))
            + '</span>'
            + '<span class="qibbs-comment-like-count">' + (likeCount > 0 ? likeCount : '') + '</span>'
            + '</button>';
    }

    function renderCommentActions(comment, isReply) {
        var actions = [];
        var authorName = comment.author && comment.author.display_name ? comment.author.display_name : '';

        if (comment.can_like) {
            actions.push(renderCommentLikeButton(comment));
        }

        if (!isReply && canReplyToComment()) {
            actions.push(
                '<button type="button" class="qibbs-comment-reply-trigger" data-id="' + comment.id + '" data-author="' + escapeAttr(authorName) + '">'
                + escapeHtml(getString('replyComment', '回复'))
                + '</button>'
            );
        }

        if (!isReply && comment.can_set_best && !comment.is_best) {
            actions.push(
                '<button type="button" class="qibbs-single-set-best-answer" data-id="' + comment.id + '" data-post-id="' + postId + '">'
                + escapeHtml(getString('setBestAnswer', '采纳最佳'))
                + '</button>'
            );
        }

        if (!isReply && comment.can_pin_quality) {
            actions.push(
                '<button type="button" class="qibbs-single-toggle-quality-pin" data-id="' + comment.id + '" data-post-id="' + postId + '">'
                + escapeHtml(comment.is_quality_pinned ? getString('unpinQualityReply', '取消置顶') : getString('pinQualityReply', '置顶'))
                + '</button>'
            );
        }

        if (comment.can_delete) {
            actions.push(
                '<button type="button" class="qibbs-comment-delete-btn" data-id="' + comment.id + '">'
                + escapeHtml(getString('deleteComment', '删除'))
                + '</button>'
            );
        }

        if (comment.can_block) {
            actions.push(
                '<button type="button" class="qibbs-comment-block-btn" data-id="' + comment.id + '">'
                + escapeHtml(getString('blockComment', '屏蔽'))
                + '</button>'
            );
        }

        return actions.length ? '<div class="qibbs-comment-actions">' + actions.join('') + '</div>' : '';
    }

    function renderInlineReplyComposer(parentId, parentAuthor) {
        var placeholder = parentAuthor
            ? '回复 @' + parentAuthor + '...'
            : getString('replyPlaceholder', '继续回复...');

        return '<div class="qibbs-comment-inline-reply" data-parent-id="' + parentId + '">'
            + '<textarea placeholder="' + escapeAttr(placeholder) + '"></textarea>'
            + '<div class="qibbs-comment-inline-reply-footer">'
            + '<span class="qibbs-comment-inline-reply-hint">' + escapeHtml(getString('inlineReplyHint', '支持 Ctrl/Command + Enter 快速回复')) + '</span>'
            + '<div class="qibbs-comment-inline-reply-actions">'
            + '<button type="button" class="qibbs-comment-secondary-btn qibbs-comment-reply-cancel">' + escapeHtml(getString('cancelReply', '取消')) + '</button>'
            + '<button type="button" class="qibbs-comment-submit qibbs-comment-reply-submit"><span class="qibbs-comment-submit-label">' + escapeHtml(getString('sendReply', '发送回复')) + '</span></button>'
            + '</div>'
            + '</div>'
            + '</div>';
    }

    function formatCommentContent(content) {
        return escapeHtml(content || '').replace(/\n/g, '<br>');
    }

    function renderReply(reply) {
        var authorProfile = safeUrl(reply.author && reply.author.profile_url ? reply.author.profile_url : '#');
        var authorAvatar = safeUrl(reply.author && reply.author.avatar ? reply.author.avatar : '');
        var authorName = escapeHtml(reply.author && reply.author.display_name ? reply.author.display_name : '');
        var replyTime = escapeHtml(reply.time_ago || '');
        var replyFullTime = escapeHtml(reply.created_at_formatted || reply.created_at || '');
        var metaPills = renderCommentMetaPills(reply, true);
        var actions = renderCommentActions(reply, true);

        return '<div class="qibbs-comment-reply-item" id="comment-' + reply.id + '" data-id="' + reply.id + '">'
            + '<a href="' + authorProfile + '" class="qibbs-comment-avatar-link">'
            + '<img src="' + authorAvatar + '" alt="" class="qibbs-comment-reply-avatar">'
            + '</a>'
            + '<div class="qibbs-comment-reply-body">'
            + '<div class="qibbs-comment-reply-header">'
            + '<div class="qibbs-comment-reply-meta">'
            + '<div class="qibbs-comment-reply-title">'
            + '<a href="' + authorProfile + '" class="qibbs-comment-reply-author">' + authorName + '</a>'
            + (reply.author && reply.author.cuid_badge ? reply.author.cuid_badge : '')
            + (reply.author && reply.author.level_badge ? reply.author.level_badge : '')
            + '</div>'
            + '<div class="qibbs-comment-subline">'
            + '<span class="qibbs-comment-reply-time"' + (replyFullTime ? ' title="' + replyFullTime + '"' : '') + '>' + replyTime + '</span>'
            + '</div>'
            + metaPills
            + '</div>'
            + (actions ? '<div class="qibbs-comment-reply-actions">' + $(actions).html() + '</div>' : '')
            + '</div>'
            + '<div class="qibbs-comment-reply-content">' + formatCommentContent(reply.content) + '</div>'
            + '</div>'
            + '</div>';
    }

    function renderReplies(comment) {
        var replyCount = parseInt(comment.reply_count, 10) || 0;
        var replies = Array.isArray(comment.replies) ? comment.replies : [];
        var parts = [];
        var repliesHtml = '';

        if (replies.length) {
            replies.forEach(function(reply) {
                repliesHtml += renderReply(reply);
            });
            parts.push('<div class="qibbs-comment-replies-list">' + repliesHtml + '</div>');
        }

        if (replyCount > replies.length) {
            parts.push(renderLoadMoreRepliesButton(comment.id, 1, Math.max(replyCount - replies.length, 0)));
        }

        if (canReplyToComment()) {
            parts.push('<div class="qibbs-comment-inline-reply-host"></div>');
        }

        if (!parts.length) {
            return '';
        }

        return '<div class="qibbs-comment-replies" data-comment-id="' + comment.id + '">' + parts.join('') + '</div>';
    }

    function renderComment(comment) {
        var authorProfile = safeUrl(comment.author && comment.author.profile_url ? comment.author.profile_url : '#');
        var authorAvatar = safeUrl(comment.author && comment.author.avatar ? comment.author.avatar : '');
        var authorName = escapeHtml(comment.author && comment.author.display_name ? comment.author.display_name : '');
        var commentTime = escapeHtml(comment.time_ago || '');
        var commentFullTime = escapeHtml(comment.created_at_formatted || comment.created_at || '');
        var badges = renderCommentBadges(comment);
        var metaPills = renderCommentMetaPills(comment, false);
        var actions = renderCommentActions(comment, false);
        var replies = renderReplies(comment);

        return '<div class="qibbs-comment-item" id="comment-' + comment.id + '" data-id="' + comment.id + '">'
            + '<a href="' + authorProfile + '" class="qibbs-comment-avatar-link">'
            + '<img src="' + authorAvatar + '" alt="" class="qibbs-comment-avatar">'
            + '</a>'
            + '<div class="qibbs-comment-body">'
            + '<div class="qibbs-comment-header">'
            + '<div class="qibbs-comment-meta">'
            + '<div class="qibbs-comment-title-row">'
            + '<a href="' + authorProfile + '" class="qibbs-comment-author">' + authorName + '</a>'
            + (comment.author && comment.author.cuid_badge ? comment.author.cuid_badge : '')
            + (comment.author && comment.author.level_badge ? comment.author.level_badge : '')
            + badges
            + '</div>'
            + '<div class="qibbs-comment-subline">'
            + '<span class="qibbs-comment-time"' + (commentFullTime ? ' title="' + commentFullTime + '"' : '') + '>' + commentTime + '</span>'
            + '</div>'
            + metaPills
            + '</div>'
            + actions
            + '</div>'
            + '<div class="qibbs-comment-content">' + formatCommentContent(comment.content) + '</div>'
            + replies
            + '</div>'
            + '</div>';
    }

    function renderCommentsToolbar(payload) {
        if (!payload || payload.discussion_status === 'discussion' || !payload.can_reopen) {
            return '';
        }

        return '<div class="qibbs-single-comments-toolbar">'
            + '<button type="button" class="qibbs-single-reopen-question-btn" data-post-id="' + postId + '">'
            + escapeHtml(getString('reopenQuestion', '追问/重开'))
            + '</button>'
            + '</div>';
    }

    function renderCommentStream(comments) {
        var html = '';

        if (!Array.isArray(comments)) {
            return html;
        }

        comments.forEach(function(comment) {
            html += renderComment(comment);
        });

        return html;
    }

    function renderCommentsView(payload, append) {
        var comments = payload && Array.isArray(payload.comments) ? payload.comments : [];
        var commentsHtml = renderCommentStream(comments);
        var nextPage = (parseInt(payload.page, 10) || 1) + 1;

        if (!append) {
            var freshHtml = renderCommentsToolbar(payload);

            if (commentsHtml) {
                freshHtml += '<div class="qibbs-comment-stream">' + commentsHtml + '</div>';
            } else {
                freshHtml += renderEmptyComments(getString('emptyComments', '还没有评论，来抢沙发吧'));
            }

            if (payload && payload.has_more) {
                freshHtml += renderLoadMoreCommentsButton(nextPage);
            }

            $commentsList.html(freshHtml);
            return;
        }

        $commentsList.find('.qibbs-comments-load-more-wrap').remove();
        $commentsList.find('.qibbs-empty-comments').remove();

        if (commentsHtml) {
            if (!$commentsList.find('.qibbs-comment-stream').length) {
                $commentsList.prepend('<div class="qibbs-comment-stream"></div>');
            }
            $commentsList.find('.qibbs-comment-stream').append(commentsHtml);
        }

        if (payload && payload.has_more) {
            $commentsList.append(renderLoadMoreCommentsButton(nextPage));
        }
    }

    function loadComments(page, append) {
        var normalizedPage = Math.max(1, parseInt(page, 10) || 1);
        var shouldAppend = !!append;

        if (!shouldAppend && activeCommentsRequest && activeCommentsRequest.readyState < 4) {
            activeCommentsRequest.abort();
        }

        if (!shouldAppend) {
            $commentsList.html(renderCommentsLoading(3));
        } else {
            $commentsList.find('.qibbs-comments-load-more').prop('disabled', true).text(getString('loadingComments', '加载中...'));
        }

        activeCommentsRequest = $.ajax({
            url: qibbsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'qibbs_load_comments',
                nonce: qibbsData.nonce,
                post_id: postId,
                page: normalizedPage
            },
            success: function(response) {
                if (!response || !response.success || !response.data) {
                    if (!shouldAppend) {
                        $commentsList.html(renderEmptyComments(getString('commentLoadFailed', '评论加载失败，请稍后重试')));
                    } else {
                        $commentsList.find('.qibbs-comments-load-more').prop('disabled', false).text(getString('loadMoreComments', '查看更多评论'));
                    }
                    showToast((response && response.data && response.data.message) ? response.data.message : getString('commentLoadFailed', '评论加载失败，请稍后重试'));
                    return;
                }

                if (response.data.discussion_status) {
                    updateDiscussionBadge(response.data.discussion_status);
                }
                if (typeof response.data.comment_count !== 'undefined') {
                    updateCommentCounts(response.data.comment_count);
                }

                renderCommentsView(response.data, shouldAppend);
            },
            error: function(xhr, status) {
                if (status === 'abort') {
                    return;
                }

                if (!shouldAppend) {
                    $commentsList.html(renderEmptyComments(getString('commentLoadFailed', '评论加载失败，请稍后重试')));
                } else {
                    $commentsList.find('.qibbs-comments-load-more').prop('disabled', false).text(getString('loadMoreComments', '查看更多评论'));
                }

                showToast(getString('commentLoadFailed', '评论加载失败，请稍后重试'));
            }
        });
    }

    function resizeTextarea($textarea, minHeight) {
        var normalizedMinHeight = Math.max(60, parseInt(minHeight, 10) || 96);

        if (!$textarea || !$textarea.length) {
            return;
        }

        $textarea.css('height', 'auto');
        $textarea.css('height', Math.max($textarea[0].scrollHeight, normalizedMinHeight) + 'px');
    }

    function setButtonLoading($button, loadingLabel) {
        var $label = $button.find('.qibbs-comment-submit-label');
        var originalLabel = $label.length ? $label.text() : $button.text();

        $button.prop('disabled', true);
        if ($label.length) {
            $label.text(loadingLabel);
        } else {
            $button.text(loadingLabel);
        }

        return originalLabel;
    }

    function restoreButtonLabel($button, originalLabel) {
        var $label = $button.find('.qibbs-comment-submit-label');

        $button.prop('disabled', false);
        if ($label.length) {
            $label.text(originalLabel);
        } else {
            $button.text(originalLabel);
        }
    }

    function submitCommentRequest(content, parentId, $button, successMessage, done) {
        var originalLabel = setButtonLoading($button, getString('publishingComment', '发布中...'));

        $.ajax({
            url: qibbsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'qibbs_add_comment',
                nonce: qibbsData.nonce,
                post_id: postId,
                content: content,
                parent_id: parentId || 0
            },
            success: function(response) {
                if (response && response.success) {
                    loadComments(1, false);
                    var toastMsg = (response.data && response.data.message) ? response.data.message : successMessage;
                    if (response.data && response.data.redpacket) {
                        if (response.data.redpacket.claimed) {
                            toastMsg += ' · ' + (response.data.redpacket.message || ('抢到 ' + response.data.redpacket.amount + ' 积分红包！'));
                        } else if (response.data.redpacket.notice) {
                            toastMsg += ' · ' + response.data.redpacket.notice;
                        }
                    }
                    showToast(toastMsg);
                    if (typeof done === 'function') {
                        done(true, response);
                    }
                } else {
                    showToast((response && response.data && response.data.message) ? response.data.message : getString('commentSubmitFailed', '评论失败'));
                    if (typeof done === 'function') {
                        done(false, response);
                    }
                }

                restoreButtonLabel($button, originalLabel);
            },
            error: function() {
                restoreButtonLabel($button, originalLabel);
                showToast(getString('commentSubmitFailed', '评论失败'));
                if (typeof done === 'function') {
                    done(false, null);
                }
            }
        });
    }

    function submitCommentAction(data, fallbackMessage) {
        $.ajax({
            url: qibbsData.ajaxUrl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response && response.success) {
                    loadComments(1, false);
                    showToast((response.data && response.data.message) ? response.data.message : fallbackMessage);
                } else {
                    showToast((response && response.data && response.data.message) ? response.data.message : getString('commentActionFailed', '操作失败'));
                }
            },
            error: function() {
                showToast(getString('commentActionFailed', '操作失败'));
            }
        });
    }

    function submitTopLevelComment() {
        var content = $commentInput.val().trim();

        if (!$commentSubmit.length || $commentSubmit.prop('disabled')) {
            return;
        }

        if (!content) {
            showToast(getString('commentEmpty', '请先输入评论内容'));
            return;
        }

        submitCommentRequest(content, 0, $commentSubmit, getString('commentSuccess', '评论成功'), function(success) {
            if (!success) {
                return;
            }
            $commentInput.val('');
            resizeTextarea($commentInput, 96);
        });
    }

    function submitInlineReply($composer) {
        var parentId = parseInt($composer.data('parent-id'), 10) || 0;
        var $textarea = $composer.find('textarea');
        var $button = $composer.find('.qibbs-comment-reply-submit');
        var content = $textarea.val().trim();

        if (!parentId || !$button.length || $button.prop('disabled')) {
            return;
        }

        if (!content) {
            showToast(getString('replyEmpty', '请先输入回复内容'));
            return;
        }

        submitCommentRequest(content, parentId, $button, getString('replySuccess', '回复成功'), function(success) {
            if (success) {
                $composer.remove();
            }
        });
    }

    function scrollToComments(focusInput) {
        var commentsNode = $commentsSection.get(0);

        if (commentsNode && commentsNode.scrollIntoView) {
            commentsNode.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }

        if (focusInput && $commentInput.length && currentDiscussionStatus !== 'closed') {
            setTimeout(function() {
                $commentInput.trigger('focus');
            }, 260);
        }
    }

    function loadRepliesForComment($button) {
        var commentId = parseInt($button.data('comment-id'), 10) || 0;
        var nextPage = Math.max(1, parseInt($button.data('next-page'), 10) || 1);
        var $replySection = $('#comment-' + commentId).find('.qibbs-comment-replies');
        var $replyList = $replySection.find('.qibbs-comment-replies-list');

        if (!commentId || !$replySection.length) {
            return;
        }

        if (!$replyList.length) {
            $replySection.prepend('<div class="qibbs-comment-replies-list"></div>');
            $replyList = $replySection.find('.qibbs-comment-replies-list');
        }

        $button.prop('disabled', true).text(getString('loadingReplies', '加载中...'));

        $.ajax({
            url: qibbsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'qibbs_load_comment_replies',
                nonce: qibbsData.nonce,
                comment_id: commentId,
                page: nextPage
            },
            success: function(response) {
                var repliesHtml = '';
                var remainingCount = 0;

                if (!response || !response.success || !response.data) {
                    $button.prop('disabled', false).text(getString('loadMoreReplies', '查看更多回复'));
                    showToast((response && response.data && response.data.message) ? response.data.message : getString('commentLoadFailed', '评论加载失败，请稍后重试'));
                    return;
                }

                if (Array.isArray(response.data.replies)) {
                    response.data.replies.forEach(function(reply) {
                        repliesHtml += renderReply(reply);
                    });
                }

                if (nextPage === 1) {
                    $replyList.html(repliesHtml);
                } else {
                    $replyList.append(repliesHtml);
                }

                $button.closest('.qibbs-comment-replies-more-wrap').remove();

                if (response.data.has_more) {
                    remainingCount = Math.max((parseInt(response.data.total, 10) || 0) - ((parseInt(response.data.page, 10) || 1) * (parseInt(response.data.per_page, 10) || 10)), 0);
                    $replySection.append(
                        renderLoadMoreRepliesButton(
                            commentId,
                            (parseInt(response.data.page, 10) || 1) + 1,
                            remainingCount
                        )
                    );
                }
            },
            error: function() {
                $button.prop('disabled', false).text(getString('loadMoreReplies', '查看更多回复'));
                showToast(getString('commentLoadFailed', '评论加载失败，请稍后重试'));
            }
        });
    }

    $('.qibbs-share-btn').on('click', function(e) {
        e.preventDefault();
        var url = $(this).data('url') || window.location.href;

        if (!navigator.clipboard || !navigator.clipboard.writeText) {
            showToast(getString('copyLinkFailed', '复制失败，请手动复制'));
            return;
        }

        navigator.clipboard.writeText(url).then(function() {
            showToast(getString('copyLinkSuccess', '链接已复制'));
        }).catch(function() {
            showToast(getString('copyLinkFailed', '复制失败，请手动复制'));
        });
    });

    $('.qibbs-comment-toggle-btn').on('click', function(e) {
        e.preventDefault();
        scrollToComments(qibbsData.isLoggedIn);
    });

    $(document).on('click', '.qibbs-comments-load-more', function() {
        var nextPage = parseInt($(this).data('next-page'), 10) || 2;
        loadComments(nextPage, true);
    });

    $(document).on('click', '.qibbs-comment-replies-more', function() {
        loadRepliesForComment($(this));
    });

    $(document).on('click', '.qibbs-comment-reply-trigger', function() {
        var $trigger = $(this);
        var $item = $trigger.closest('.qibbs-comment-item');
        var $host = $item.find('.qibbs-comment-inline-reply-host');
        var commentId = parseInt($trigger.data('id'), 10) || 0;
        var authorName = $trigger.data('author') || '';

        if (!qibbsData.isLoggedIn) {
            showToast(getString('pleaseLogin', '请先登录'));
            return;
        }

        if (currentDiscussionStatus === 'closed') {
            showToast(getString('discussionClosedNote', '该动态已关闭讨论，暂不支持新评论。'));
            return;
        }

        if (!$host.length || !commentId) {
            return;
        }

        if ($host.children('.qibbs-comment-inline-reply').length) {
            $host.empty();
            return;
        }

        $('.qibbs-comment-inline-reply').remove();
        $host.html(renderInlineReplyComposer(commentId, authorName));

        var $textarea = $host.find('textarea');
        resizeTextarea($textarea, 84);
        $textarea.trigger('focus');
    });

    $(document).on('click', '.qibbs-comment-reply-cancel', function() {
        $(this).closest('.qibbs-comment-inline-reply').remove();
    });

    $(document).on('click', '.qibbs-comment-reply-submit', function() {
        submitInlineReply($(this).closest('.qibbs-comment-inline-reply'));
    });

    $(document).on('input', '.qibbs-comment-inline-reply textarea', function() {
        resizeTextarea($(this), 84);
    });

    $(document).on('keydown', '.qibbs-comment-inline-reply textarea', function(event) {
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
            event.preventDefault();
            submitInlineReply($(this).closest('.qibbs-comment-inline-reply'));
        }
    });

    $(document).on('click', '.qibbs-comment-delete-btn', function() {
        var commentId = parseInt($(this).data('id'), 10) || 0;

        if (!commentId) {
            return;
        }

        if (!window.confirm(getString('deleteCommentConfirm', '确定删除这条评论吗？'))) {
            return;
        }

        submitCommentAction({
            action: 'qibbs_delete_comment',
            nonce: qibbsData.nonce,
            comment_id: commentId
        }, getString('deleteCommentSuccess', '评论已删除'));
    });

    $(document).on('click', '.qibbs-comment-block-btn', function() {
        var commentId = parseInt($(this).data('id'), 10) || 0;

        if (!commentId) {
            return;
        }

        if (!window.confirm(getString('blockCommentConfirm', '确定屏蔽这条评论吗？'))) {
            return;
        }

        submitCommentAction({
            action: 'qibbs_block_comment',
            nonce: qibbsData.nonce,
            comment_id: commentId
        }, getString('blockCommentSuccess', '评论已屏蔽'));
    });

    $(document).on('click', '.qibbs-single-set-best-answer', function() {
        var commentId = parseInt($(this).data('id'), 10) || 0;

        if (!commentId) {
            return;
        }

        if (!window.confirm(getString('bestAnswerConfirm', '确定将该回复设为最佳答案吗？'))) {
            return;
        }

        submitCommentAction({
            action: 'qibbs_set_best_answer',
            nonce: qibbsData.nonce,
            post_id: postId,
            comment_id: commentId
        }, getString('setBestAnswerSuccess', '已设为最佳答案'));
    });

    $(document).on('click', '.qibbs-single-clear-best-answer', function() {
        if (!window.confirm(getString('clearBestAnswerConfirm', '确定取消当前最佳答案吗？'))) {
            return;
        }

        submitCommentAction({
            action: 'qibbs_clear_best_answer',
            nonce: qibbsData.nonce,
            post_id: postId
        }, getString('clearBestAnswerSuccess', '已取消最佳答案'));
    });

    $(document).on('click', '.qibbs-single-toggle-quality-pin', function() {
        var commentId = parseInt($(this).data('id'), 10) || 0;
        var isPinned = $(this).closest('.qibbs-comment-item').find('.qibbs-comment-badge-quality').length > 0;
        var confirmText = isPinned
            ? getString('unpinQualityConfirm', '确定取消该优质置顶吗？')
            : getString('pinQualityConfirm', '确定置顶该优质回复吗？');

        if (!commentId) {
            return;
        }

        if (!window.confirm(confirmText)) {
            return;
        }

        submitCommentAction({
            action: 'qibbs_toggle_quality_pin',
            nonce: qibbsData.nonce,
            comment_id: commentId
        }, getString('toggleQualityPinSuccess', '操作成功'));
    });

    $(document).on('click', '.qibbs-single-comment-like', function() {
        var $btn = $(this);
        var commentId = parseInt($btn.data('id'), 10) || 0;

        if (!commentId || $btn.prop('disabled')) {
            return;
        }

        $btn.prop('disabled', true);
        $.ajax({
            url: qibbsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'qibbs_toggle_comment_like',
                nonce: qibbsData.nonce,
                comment_id: commentId
            },
            success: function(response) {
                if (response && response.success && response.data) {
                    var liked = !!response.data.liked;
                    var likeCount = parseInt(response.data.like_count, 10) || 0;

                    $btn.toggleClass('active', liked);
                    $btn.find('.qibbs-comment-like-label').text(liked ? getString('likedComment', '已赞') : getString('likeComment', '赞'));
                    $btn.find('.qibbs-comment-like-count').text(likeCount > 0 ? likeCount : '');
                } else {
                    showToast((response && response.data && response.data.message) ? response.data.message : getString('commentActionFailed', '操作失败'));
                }

                $btn.prop('disabled', false);
            },
            error: function() {
                $btn.prop('disabled', false);
                showToast(getString('commentActionFailed', '操作失败'));
            }
        });
    });

    $(document).on('click', '.qibbs-single-reopen-question-btn', function() {
        if (!window.confirm(getString('reopenQuestionConfirm', '确定要重开该讨论吗？这会清除最佳答案并恢复到“讨论中”。'))) {
            return;
        }

        submitCommentAction({
            action: 'qibbs_reopen_post_question',
            nonce: qibbsData.nonce,
            post_id: postId
        }, getString('reopenQuestionSuccess', '已重开讨论'));
    });

    $commentInput.on('input', function() {
        resizeTextarea($commentInput, 96);
    });

    $commentInput.on('keydown', function(event) {
        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
            event.preventDefault();
            submitTopLevelComment();
        }
    });

    $commentSubmit.on('click', submitTopLevelComment);

    updateCommentCounts(initialCommentCount);
    updateDiscussionBadge(currentDiscussionStatus);
    resizeTextarea($commentInput, 96);
    loadComments(1, false);
});
</script>

<?php 
$interactive_modals = QIBBS_PLUGIN_DIR . 'templates/modals-interactive.php';
if ( file_exists( $interactive_modals ) ) {
    include $interactive_modals;
}
get_footer(); 
?>
