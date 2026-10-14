<?php
/**
 * 用户个人主页模板
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

$raw_member_id = get_query_var( 'qibbs_member_id' );
$tab           = get_query_var( 'qibbs_member_tab', 'posts' );

// 确保若传入的是 CUID，解析得到真实的 WordPress user_id
$resolved_user_id = function_exists( 'qibbs_get_user_id_by_cuid' ) ? qibbs_get_user_id_by_cuid( $raw_member_id ) : 0;
$member_id        = $resolved_user_id > 0 ? $resolved_user_id : absint( $raw_member_id );

$profile_service = new Qibbs_Profile_Service();
$profile = $profile_service->get_profile( $member_id );

if ( ! $profile ) {
    get_header();
    echo '<div class="qibbs-wrapper"><div class="qibbs-empty">用户不存在</div></div>';
    get_footer();
    return;
}

get_header();

// 获取主题预设风格属性
$layout_service = new Qibbs_Layout_Service();
$theme_attr = $layout_service->get_theme_attribute();
?>

<div class="qibbs-wrapper"<?php echo $theme_attr; ?>>
    <div class="qibbs-profile">
        <div class="qibbs-profile-header">
            <?php if ( $profile['cover'] ) : ?>
                <div class="qibbs-profile-cover" style="background-image: url(<?php echo esc_url( $profile['cover'] ); ?>);"></div>
            <?php else : ?>
                <div class="qibbs-profile-cover qibbs-cover-default"></div>
            <?php endif; ?>
            
            <div class="qibbs-profile-body">
                <div class="qibbs-profile-nav-row">
                    <div class="qibbs-profile-avatar-wrap">
                        <img src="<?php echo esc_url( $profile['avatar'] ); ?>" alt="<?php echo esc_attr( $profile['display_name'] ); ?>" class="qibbs-profile-avatar">
                    </div>
                    
                    <div class="qibbs-profile-actions">
                        <?php if ( ! $profile['is_self'] && is_user_logged_in() ) : ?>
                            <button class="qibbs-profile-btn qibbs-follow-btn <?php echo $profile['is_following'] ? 'active' : ''; ?>" data-user-id="<?php echo esc_attr( $member_id ); ?>">
                                <?php echo $profile['is_following'] ? esc_html__( '已关注', 'qibbs-community' ) : esc_html__( '关注', 'qibbs-community' ); ?>
                            </button>
                            <a href="<?php echo esc_url( qibbs_get_messages_url( array( 'user' => $member_id ) ) ); ?>" class="qibbs-profile-btn qibbs-profile-btn-secondary">
                                <?php esc_html_e( '私信', 'qibbs-community' ); ?>
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url( qibbs_get_community_url() ); ?>" class="qibbs-profile-btn qibbs-profile-btn-secondary">
                            <?php esc_html_e( '返回社区', 'qibbs-community' ); ?>
                        </a>
                    </div>
                </div>
                
                <div class="qibbs-profile-user-details">
                    <h1 class="qibbs-profile-name">
                        <span class="qibbs-profile-display-name"><?php echo esc_html( $profile['display_name'] ); ?></span>
                        <?php if ( ! empty( $profile['cuid_badge'] ) ) : ?>
                            <?php echo $profile['cuid_badge']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php elseif ( function_exists( 'qibbs_render_cuid_badge' ) ) : ?>
                            <?php echo qibbs_render_cuid_badge( $member_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php endif; ?>
                        <?php if ( function_exists( 'qibbs_render_user_level_badge' ) ) : ?>
                            <?php echo qibbs_render_user_level_badge( $member_id, true, 'qibbs-profile-level-badge' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php endif; ?>
                    </h1>
                    <?php if ( ! empty( $profile['bio'] ) ) : ?>
                        <p class="qibbs-profile-bio"><?php echo esc_html( $profile['bio'] ); ?></p>
                    <?php else : ?>
                        <p class="qibbs-profile-bio qibbs-profile-bio-empty"><?php esc_html_e( '这个人很神秘，什么都没有写~', 'qibbs-community' ); ?></p>
                    <?php endif; ?>
                </div>
                
                <?php if ( function_exists( 'qibbs_is_growth_levels_enabled' ) && qibbs_is_growth_levels_enabled() && function_exists( 'qibbs_get_user_level_info' ) ) :
                    $lvl_info = qibbs_get_user_level_info( $member_id );
                    if ( ! empty( $lvl_info['enabled'] ) ) :
                        $pct = min( 100.0, max( 0.0, (float) $lvl_info['progress_percent'] ) );
                        $center_url = function_exists( 'qibbs_get_growth_center_url' ) ? qibbs_get_growth_center_url() : '#';
                        $needed_str = ! empty( $lvl_info['is_max_level'] )
                            ? __( '已达成顶级荣耀', 'qibbs-community' )
                            : sprintf( __( '距 Lv.%1$d 还差 %2$s 经验值', 'qibbs-community' ), ( $lvl_info['level'] + 1 ), number_format_i18n( $lvl_info['needed_exp'] ) );
                ?>
                    <a href="<?php echo esc_url( $center_url ); ?>" class="qibbs-profile-growth-banner" title="<?php esc_attr_e( '点击前往社区成长中心查看等级特权与规则', 'qibbs-community' ); ?>">
                        <div class="qibbs-growth-banner-left">
                            <span class="qibbs-growth-star-icon" aria-hidden="true" style="color:<?php echo esc_attr( $lvl_info['color'] ); ?>;"><svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg></span>
                            <span class="qibbs-growth-lvl-title" style="color:<?php echo esc_attr( $lvl_info['color'] ); ?>;">Lv.<?php echo (int) $lvl_info['level']; ?> <?php echo esc_html( $lvl_info['level_name'] ); ?></span>
                            <span class="qibbs-growth-sep-dot">·</span>
                            <span class="qibbs-growth-tip-text"><?php echo esc_html( $needed_str ); ?></span>
                        </div>
                        <div class="qibbs-growth-banner-right">
                            <div class="qibbs-growth-progress-track" role="progressbar" aria-valuenow="<?php echo esc_attr( (int) round( $pct ) ); ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="qibbs-growth-progress-fill <?php echo esc_attr( $lvl_info['badge_class'] ); ?>" style="width: <?php echo esc_attr( $pct ); ?>%; background-color: <?php echo esc_attr( $lvl_info['color'] ); ?>;"></div>
                            </div>
                            <div class="qibbs-growth-stats-label">
                                <span class="qibbs-growth-exp-val"><?php echo number_format_i18n( $lvl_info['exp'] ); ?></span>
                                <?php if ( empty( $lvl_info['is_max_level'] ) ) : ?>
                                    <span class="qibbs-growth-exp-sep">/</span>
                                    <span class="qibbs-growth-exp-max"><?php echo number_format_i18n( $lvl_info['next_exp'] ); ?></span>
                                <?php endif; ?>
                                <span class="qibbs-growth-exp-unit"><?php esc_html_e( '经验值', 'qibbs-community' ); ?></span>
                                <span class="qibbs-growth-pct">(<?php echo esc_html( round( $pct, 1 ) ); ?>%)</span>
                            </div>
                            <span class="qibbs-growth-link-arrow"><?php esc_html_e( '成长特权 ›', 'qibbs-community' ); ?></span>
                        </div>
                    </a>
                <?php endif; endif; ?>
            </div>
        </div>
        
        <div class="qibbs-profile-stats">
            <div class="qibbs-stat">
                <span class="qibbs-stat-value"><?php echo esc_html( $profile['stats']['posts'] ); ?></span>
                <span class="qibbs-stat-label"><?php esc_html_e( '动态', 'qibbs-community' ); ?></span>
            </div>
            <a href="<?php echo esc_url( qibbs_get_profile_url( $member_id, 'followers' ) ); ?>" class="qibbs-stat">
                <span class="qibbs-stat-value"><?php echo esc_html( $profile['stats']['followers'] ); ?></span>
                <span class="qibbs-stat-label"><?php esc_html_e( '粉丝', 'qibbs-community' ); ?></span>
            </a>
            <a href="<?php echo esc_url( qibbs_get_profile_url( $member_id, 'following' ) ); ?>" class="qibbs-stat">
                <span class="qibbs-stat-value"><?php echo esc_html( $profile['stats']['following'] ); ?></span>
                <span class="qibbs-stat-label"><?php esc_html_e( '关注', 'qibbs-community' ); ?></span>
            </a>
        </div>
        
        <nav class="qibbs-profile-tabs">
            <a href="<?php echo esc_url( qibbs_get_profile_url( $member_id ) ); ?>" class="qibbs-tab <?php echo $tab === 'posts' ? 'active' : ''; ?>">
                <?php esc_html_e( '动态', 'qibbs-community' ); ?>
            </a>
            <?php if ( $profile['is_self'] ) : ?>
                <a href="<?php echo esc_url( qibbs_get_profile_url( $member_id, 'favorites' ) ); ?>" class="qibbs-tab <?php echo $tab === 'favorites' ? 'active' : ''; ?>">
                    <?php esc_html_e( '收藏', 'qibbs-community' ); ?>
                </a>
                <a href="<?php echo esc_url( qibbs_get_profile_url( $member_id, 'likes' ) ); ?>" class="qibbs-tab <?php echo $tab === 'likes' ? 'active' : ''; ?>">
                    <?php esc_html_e( '赞过', 'qibbs-community' ); ?>
                </a>
            <?php endif; ?>
        </nav>
        
        <div class="qibbs-profile-content">
            <div class="qibbs-feed" id="qibbs-feed" data-user-id="<?php echo esc_attr( $member_id ); ?>" data-tab="<?php echo esc_attr( $tab ); ?>">
                <div class="qibbs-loading"><?php esc_html_e( '加载中...', 'qibbs-community' ); ?></div>
            </div>
        </div>
    </div>
</div>

<style>
.qibbs-profile {
    max-width: 900px;
    margin: 0 auto;
    padding: 0 16px;
}
.qibbs-profile-header {
    position: relative;
    margin-bottom: 20px;
    background: var(--qibbs-bg-card);
    border-radius: var(--qibbs-radius);
    overflow: hidden;
    box-shadow: var(--qibbs-shadow);
    border: 1px solid var(--qibbs-border);
}
.qibbs-profile-cover {
    height: 190px;
    background-size: cover;
    background-position: center;
    position: relative;
}
.qibbs-profile-cover::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 70px;
    background: linear-gradient(to top, rgba(0,0,0,0.25), transparent);
}
.qibbs-cover-default {
    background: linear-gradient(135deg, var(--qibbs-primary) 0%, #6366f1 50%, var(--qibbs-secondary) 100%);
}
.qibbs-profile-body {
    padding: 0 28px 24px;
    background: var(--qibbs-bg-card);
    position: relative;
}
.qibbs-profile-nav-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    min-height: 58px;
    position: relative;
}
.qibbs-profile-avatar-wrap {
    flex-shrink: 0;
    position: relative;
    z-index: 2;
}
.qibbs-profile-avatar {
    width: 110px;
    height: 110px;
    border-radius: 50%;
    border: 4px solid var(--qibbs-bg-card);
    object-fit: cover;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.12);
    margin-top: -55px;
    background: var(--qibbs-bg-card);
    display: block;
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.qibbs-profile-avatar:hover {
    transform: scale(1.04);
}
.qibbs-profile-actions {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-top: 14px;
    margin-bottom: 4px;
    flex-wrap: wrap;
}
.qibbs-profile-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 18px;
    height: 38px;
    font-size: 14px;
    font-weight: 600;
    border: 1px solid transparent;
    border-radius: var(--qibbs-radius-sm);
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    text-decoration: none;
    box-sizing: border-box;
    white-space: nowrap;
}
.qibbs-profile-btn.qibbs-follow-btn {
    background: linear-gradient(135deg, var(--qibbs-primary), var(--qibbs-secondary));
    color: #ffffff;
    box-shadow: 0 3px 10px rgba(99, 102, 241, 0.25);
    border: none;
}
.qibbs-profile-btn.qibbs-follow-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 5px 15px rgba(99, 102, 241, 0.35);
}
.qibbs-profile-btn.qibbs-follow-btn.active {
    background: var(--qibbs-bg);
    color: var(--qibbs-text-secondary);
    border: 1px solid var(--qibbs-border);
    box-shadow: none;
}
.qibbs-profile-btn.qibbs-profile-btn-secondary {
    background: var(--qibbs-bg);
    color: var(--qibbs-text);
    border: 1px solid var(--qibbs-border);
}
.qibbs-profile-btn.qibbs-profile-btn-secondary:hover {
    background: var(--qibbs-bg-hover);
    border-color: var(--qibbs-primary);
    color: var(--qibbs-primary);
    transform: translateY(-1px);
}
.qibbs-profile-user-details {
    margin-top: 12px;
}
.qibbs-profile-name {
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 6px 0;
    color: var(--qibbs-text);
    line-height: 1.3;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.qibbs-profile-display-name {
    font-weight: 700;
}
.qibbs-profile-name .qibbs-cuid-badge,
.qibbs-profile-name .qibbs-uid-tag,
.qibbs-profile-name .qibbs-level-badge {
    height: 24px !important;
    line-height: 22px !important;
    padding: 0 10px !important;
    font-size: 12px !important;
    vertical-align: middle !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    box-sizing: border-box !important;
    border-radius: 9999px !important;
}
.qibbs-profile-bio {
    color: var(--qibbs-text-secondary);
    margin: 0;
    font-size: 14px;
    line-height: 1.6;
}
.qibbs-profile-bio-empty {
    color: var(--qibbs-text-muted);
    font-style: italic;
    opacity: 0.8;
}
.qibbs-profile-growth-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-top: 16px;
    padding: 10px 18px;
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.04) 0%, rgba(14, 165, 233, 0.04) 100%);
    border: 1px solid rgba(99, 102, 241, 0.12);
    border-radius: 12px;
    text-decoration: none;
    color: inherit;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.qibbs-profile-growth-banner:hover {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.08) 0%, rgba(14, 165, 233, 0.08) 100%);
    border-color: rgba(99, 102, 241, 0.28);
    transform: translateY(-1px);
    box-shadow: 0 4px 16px rgba(99, 102, 241, 0.08);
}
.qibbs-growth-banner-left {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 13px;
}
.qibbs-growth-star-icon {
    font-size: 13px;
    display: inline-flex;
    line-height: 1;
}
.qibbs-growth-lvl-title {
    font-weight: 700;
    font-size: 13px;
}
.qibbs-growth-sep-dot {
    color: var(--qibbs-text-muted);
    opacity: 0.6;
}
.qibbs-growth-tip-text {
    color: var(--qibbs-text-secondary);
    font-size: 13px;
}
.qibbs-growth-banner-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}
.qibbs-growth-progress-track {
    width: 130px;
    height: 6px;
    border-radius: 9999px;
    background: rgba(0, 0, 0, 0.06);
    overflow: hidden;
    position: relative;
}
.qibbs-growth-progress-fill {
    height: 100%;
    border-radius: 9999px;
    transition: width 0.4s ease;
}
.qibbs-growth-stats-label {
    display: flex;
    align-items: center;
    gap: 3px;
    font-size: 12px;
    color: var(--qibbs-text-secondary);
    white-space: nowrap;
}
.qibbs-growth-exp-val {
    font-weight: 700;
    color: var(--qibbs-text);
}
.qibbs-growth-exp-sep {
    color: var(--qibbs-text-muted);
}
.qibbs-growth-exp-max {
    color: var(--qibbs-text-secondary);
}
.qibbs-growth-exp-unit {
    color: var(--qibbs-text-muted);
}
.qibbs-growth-pct {
    font-weight: 600;
    color: var(--qibbs-primary);
    margin-left: 2px;
}
.qibbs-growth-link-arrow {
    font-size: 12px;
    font-weight: 600;
    color: var(--qibbs-primary);
    white-space: nowrap;
    display: flex;
    align-items: center;
    transition: transform 0.2s;
}
.qibbs-profile-growth-banner:hover .qibbs-growth-link-arrow {
    transform: translateX(2px);
}
.qibbs-profile-stats {
    display: flex;
    justify-content: center;
    gap: 48px;
    padding: 20px;
    background: var(--qibbs-bg-card);
    border-radius: var(--qibbs-radius);
    margin-bottom: 20px;
    box-shadow: var(--qibbs-shadow);
    border: 1px solid var(--qibbs-border);
}
.qibbs-profile-stats .qibbs-stat {
    text-align: center;
    text-decoration: none;
    color: inherit;
    transition: transform 0.2s;
}
.qibbs-profile-stats .qibbs-stat:hover {
    transform: scale(1.05);
}
.qibbs-profile-stats .qibbs-stat-value {
    display: block;
    font-size: 26px;
    font-weight: 700;
    color: var(--qibbs-text);
    line-height: 1.2;
}
.qibbs-profile-stats .qibbs-stat-label {
    display: block;
    font-size: 13px;
    color: var(--qibbs-text-muted);
    margin-top: 4px;
}
.qibbs-profile-tabs {
    display: flex;
    gap: 4px;
    background: var(--qibbs-bg-card);
    padding: 6px;
    border-radius: var(--qibbs-radius);
    margin-bottom: 20px;
    box-shadow: var(--qibbs-shadow);
    border: 1px solid var(--qibbs-border);
}
.qibbs-profile-tabs .qibbs-tab {
    flex: 1;
    text-align: center;
    padding: 12px 16px;
    text-decoration: none;
    color: var(--qibbs-text-secondary);
    border-radius: calc(var(--qibbs-radius) - 4px);
    font-weight: 500;
    transition: all 0.2s;
}
.qibbs-profile-tabs .qibbs-tab:hover {
    background: var(--qibbs-bg-hover);
}
.qibbs-profile-tabs .qibbs-tab.active {
    background: linear-gradient(135deg, var(--qibbs-primary), var(--qibbs-secondary));
    color: white;
    font-weight: 600;
}
body.qibbs-dark-mode .qibbs-profile-growth-banner,
html.dark-mode .qibbs-profile-growth-banner,
html[data-theme="dark"] .qibbs-profile-growth-banner,
[data-theme="dark"] .qibbs-profile-growth-banner {
    background: rgba(30, 41, 59, 0.6);
    border-color: rgba(255, 255, 255, 0.08);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}
body.qibbs-dark-mode .qibbs-profile-growth-banner:hover,
html.dark-mode .qibbs-profile-growth-banner:hover,
html[data-theme="dark"] .qibbs-profile-growth-banner:hover,
[data-theme="dark"] .qibbs-profile-growth-banner:hover {
    background: rgba(30, 41, 59, 0.85);
    border-color: rgba(99, 102, 241, 0.35);
}
body.qibbs-dark-mode .qibbs-growth-progress-track,
html.dark-mode .qibbs-growth-progress-track,
html[data-theme="dark"] .qibbs-growth-progress-track,
[data-theme="dark"] .qibbs-growth-progress-track {
    background: rgba(255, 255, 255, 0.1);
}
@media (max-width: 768px) {
    .qibbs-profile {
        padding: 0 12px;
    }
    .qibbs-profile-cover {
        height: 140px;
    }
    .qibbs-profile-body {
        padding: 0 16px 20px;
    }
    .qibbs-profile-nav-row {
        flex-direction: column;
        align-items: center;
        text-align: center;
        min-height: auto;
    }
    .qibbs-profile-avatar {
        width: 90px;
        height: 90px;
        margin-top: -45px;
    }
    .qibbs-profile-actions {
        margin-top: 12px;
        margin-bottom: 0;
        justify-content: center;
        width: 100%;
    }
    .qibbs-profile-btn {
        flex: 1;
        max-width: 140px;
        height: 36px;
        font-size: 13px;
        padding: 0 12px;
    }
    .qibbs-profile-user-details {
        text-align: center;
        margin-top: 14px;
    }
    .qibbs-profile-name {
        font-size: 20px;
        justify-content: center;
    }
    .qibbs-profile-growth-banner {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
        padding: 12px 14px;
    }
    .qibbs-growth-banner-left {
        justify-content: center;
        text-align: center;
    }
    .qibbs-growth-banner-right {
        flex-direction: column;
        width: 100%;
        gap: 8px;
    }
    .qibbs-growth-progress-track {
        width: 100%;
    }
    .qibbs-growth-stats-label {
        justify-content: center;
    }
    .qibbs-growth-link-arrow {
        justify-content: center;
    }
    .qibbs-profile-stats {
        gap: 24px;
        padding: 16px;
    }
    .qibbs-profile-stats .qibbs-stat-value {
        font-size: 20px;
    }
}
</style>

<?php get_footer(); ?>
