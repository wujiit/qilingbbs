<?php
/**
 * 社区双栏布局模板
 *
 * @package Qibbs_Community
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$current_qibbs_user = is_user_logged_in() ? wp_get_current_user() : null;
$composer_points_balance = is_user_logged_in() ? qibbs_get_user_points_balance( get_current_user_id() ) : null;
$composer_post_reward_hint = is_user_logged_in() ? qibbs_get_points_reward_hint( 'post_publish', __( '发帖通过可得', 'qibbs-community' ), get_current_user_id() ) : '';
$composer_points_name = qibbs_get_points_name();

$current_view   = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
$is_vanity_view = ( 'vanity-mall' === $current_view && qibbs_is_module_enabled( 'vanity_mall' ) );
$is_growth_view = ( 'growth' === $current_view && qibbs_is_growth_levels_enabled() );
$is_prop_view   = ( 'prop-mall' === $current_view && function_exists( 'qibbs_is_prop_mall_enabled' ) && qibbs_is_prop_mall_enabled() );

get_header();
?>

<div class="qibbs-wrapper">
    <div class="qibbs-layout qibbs-two-column">
        <main class="qibbs-main" id="qibbs-main">
            <?php 
            if ( qibbs_is_module_enabled( 'vanity_mall' ) ) {
                include QIBBS_PLUGIN_DIR . 'templates/vanity-mall.php';
            }
            if ( qibbs_is_growth_levels_enabled() ) {
                include QIBBS_PLUGIN_DIR . 'templates/growth-center.php';
            }
            if ( function_exists( 'qibbs_is_prop_mall_enabled' ) && qibbs_is_prop_mall_enabled() ) {
                include QIBBS_PLUGIN_DIR . 'templates/prop-mall.php';
            }
            ?>
            <?php if ( is_user_logged_in() ) : ?>
                <div class="qibbs-composer" style="<?php echo ( $is_vanity_view || $is_growth_view || $is_prop_view ) ? 'display:none;' : ''; ?>">
                    <form class="qibbs-post-form" id="qibbs-post-form">
                        <input type="hidden" name="nonce" id="qibbs-post-nonce" value="<?php echo esc_attr( wp_create_nonce( 'qibbs_nonce' ) ); ?>">
                        <div class="qibbs-composer-header">
                            <img src="<?php echo esc_url( get_avatar_url( get_current_user_id(), array( 'size' => 48 ) ) ); ?>" alt="" class="qibbs-composer-avatar">
                            <div class="qibbs-composer-user">
                                <span class="qibbs-composer-name"><?php echo esc_html( $current_qibbs_user->display_name ); ?></span>
                                <?php if ( null !== $composer_points_balance ) : ?>
                                    <span class="qibbs-composer-points"><?php echo esc_html( sprintf( __( '当前%s：%s', 'qibbs-community' ), $composer_points_name, qibbs_format_points_amount( $composer_points_balance ) ) ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="qibbs-composer-body">
                            <textarea name="content" id="qibbs-post-content" placeholder="<?php esc_attr_e( '分享新鲜事...', 'qibbs-community' ); ?>" rows="3"></textarea>
                            <div class="qibbs-media-preview" id="qibbs-media-preview"></div>
                            <?php 
                            $interactive_drawer = QIBBS_PLUGIN_DIR . 'templates/composer-interactive-fields.php';
                            if ( file_exists( $interactive_drawer ) ) {
                                include $interactive_drawer;
                            }
                            ?>
                        </div>
                        <div class="qibbs-composer-footer">
                            <div class="qibbs-composer-tools">
                                <label class="qibbs-upload-btn">
                                    <input type="file" name="media" accept="image/*,video/*" multiple hidden id="qibbs-media-input">
                                    <svg viewBox="0 0 24 24" width="24" height="24"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                                </label>
                                <?php if ( function_exists( 'qibbs_get_post_type_options' ) ) : ?>
                                    <select name="post_type" id="qibbs-post-type" class="qibbs-tool-select">
                                        <?php foreach ( qibbs_get_post_type_options() as $post_type_key => $post_type_config ) : ?>
                                            <option value="<?php echo esc_attr( $post_type_key ); ?>" <?php selected( 'discussion', $post_type_key ); ?>>
                                                <?php echo esc_html( $post_type_config['label'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                            <div class="qibbs-composer-meta">
                                <?php if ( '' !== $composer_post_reward_hint ) : ?>
                                    <span class="qibbs-points-hint qibbs-composer-points-hint"><?php echo esc_html( $composer_post_reward_hint ); ?></span>
                                <?php endif; ?>
                            </div>
                            <button type="submit" class="qibbs-submit-btn"><?php esc_html_e( '发布', 'qibbs-community' ); ?></button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <nav class="qibbs-feed-tabs" style="<?php echo ( $is_vanity_view || $is_growth_view || $is_prop_view ) ? 'display:none;' : ''; ?>">
                <button class="qibbs-tab <?php echo ( ! $is_vanity_view && ! $is_growth_view && ! $is_prop_view ) ? 'active' : ''; ?>" data-feed="global" data-filter="global" data-filter-type="feed"><?php esc_html_e( '最新', 'qibbs-community' ); ?></button>
                <?php if ( is_user_logged_in() && qibbs_get_option( 'show_nav_following', true ) ) : ?>
                    <button class="qibbs-tab" data-feed="following" data-filter="following" data-filter-type="feed"><?php esc_html_e( '关注', 'qibbs-community' ); ?></button>
                <?php endif; ?>
                <?php if ( qibbs_get_option( 'show_hot_posts', false ) ) : ?>
                    <button class="qibbs-tab" data-feed="hot" data-filter="hot" data-filter-type="feed"><?php esc_html_e( '热门', 'qibbs-community' ); ?></button>
                <?php endif; ?>
                <?php if ( qibbs_get_option( 'enable_featured_posts', false ) ) : ?>
                    <button class="qibbs-tab" data-feed="featured" data-filter="featured" data-filter-type="feed"><?php esc_html_e( '推荐', 'qibbs-community' ); ?></button>
                <?php endif; ?>
                <?php if ( qibbs_get_option( 'show_voting_posts', false ) ) : ?>
                    <button class="qibbs-tab" data-feed="voting" data-filter="voting" data-filter-type="feed"><?php esc_html_e( '投票', 'qibbs-community' ); ?></button>
                <?php endif; ?>
                <?php if ( qibbs_get_option( 'show_redpacket_posts', true ) ) : ?>
                    <button class="qibbs-tab" data-feed="redpacket" data-filter="redpacket" data-filter-type="feed"><?php esc_html_e( '红包', 'qibbs-community' ); ?></button>
                <?php endif; ?>
                <?php if ( qibbs_get_option( 'show_bounty_posts', true ) ) : ?>
                    <button class="qibbs-tab" data-feed="bounty" data-filter="bounty" data-filter-type="feed"><?php esc_html_e( '悬赏', 'qibbs-community' ); ?></button>
                <?php endif; ?>
                <?php if ( qibbs_is_growth_levels_enabled() && qibbs_get_option( 'show_nav_growth', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_growth_center_url() ); ?>" class="qibbs-tab <?php echo $is_growth_view ? 'active' : ''; ?>" style="text-decoration:none;display:inline-flex;align-items:center;"><?php esc_html_e( '成长中心', 'qibbs-community' ); ?></a>
                <?php endif; ?>
                <?php if ( qibbs_is_module_enabled( 'vanity_mall' ) && qibbs_get_option( 'show_nav_mall', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_vanity_mall_url() ); ?>" class="qibbs-tab <?php echo $is_vanity_view ? 'active' : ''; ?>" style="text-decoration:none;display:inline-flex;align-items:center;"><?php esc_html_e( '交易大厅', 'qibbs-community' ); ?></a>
                <?php endif; ?>
                <?php if ( function_exists( 'qibbs_is_prop_mall_enabled' ) && qibbs_is_prop_mall_enabled() && qibbs_get_option( 'show_nav_prop_mall', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_prop_mall_url() ); ?>" class="qibbs-tab <?php echo $is_prop_view ? 'active' : ''; ?>" style="text-decoration:none;display:inline-flex;align-items:center;"><?php esc_html_e( '道具大厅', 'qibbs-community' ); ?></a>
                <?php endif; ?>
            </nav>

            <div class="qibbs-feed" id="qibbs-feed" style="<?php echo ( $is_vanity_view || $is_growth_view || $is_prop_view ) ? 'display:none;' : ''; ?>">
                <div class="qibbs-loading"><?php esc_html_e( '加载中...', 'qibbs-community' ); ?></div>
            </div>

            <div class="qibbs-load-more" id="qibbs-load-more" style="display:none;">
                <button class="qibbs-load-more-btn"><?php esc_html_e( '加载更多', 'qibbs-community' ); ?></button>
            </div>
        </main>

        <aside class="qibbs-sidebar">
            <?php if ( is_user_logged_in() ) : ?>
                <?php
                $current_user = wp_get_current_user();
                $profile_service = new Qibbs_Profile_Service();
                $stats = $profile_service->get_user_stats( get_current_user_id() );
                ?>
                <div class="qibbs-widget qibbs-user-card">
                    <a href="<?php echo esc_url( qibbs_get_profile_url( get_current_user_id() ) ); ?>" class="qibbs-user-avatar">
                        <img src="<?php echo esc_url( get_avatar_url( get_current_user_id(), array( 'size' => 64 ) ) ); ?>" alt="">
                    </a>
                    <a href="<?php echo esc_url( qibbs_get_profile_url( get_current_user_id() ) ); ?>" class="qibbs-user-name">
                        <?php echo esc_html( $current_user->display_name ); ?>
                    </a>
                    <?php if ( function_exists( 'qibbs_render_cuid_badge' ) ) : ?>
                        <div class="qibbs-user-card-badge">
                            <?php echo qibbs_render_cuid_badge( get_current_user_id() ); ?>
                        </div>
                    <?php endif; ?>
                    <div class="qibbs-user-stats">
                        <div class="qibbs-stat">
                            <span class="qibbs-stat-value"><?php echo esc_html( $stats['posts'] ); ?></span>
                            <span class="qibbs-stat-label"><?php esc_html_e( '动态', 'qibbs-community' ); ?></span>
                        </div>
                        <div class="qibbs-stat">
                            <span class="qibbs-stat-value"><?php echo esc_html( $stats['followers'] ); ?></span>
                            <span class="qibbs-stat-label"><?php esc_html_e( '粉丝', 'qibbs-community' ); ?></span>
                        </div>
                        <div class="qibbs-stat">
                            <span class="qibbs-stat-value"><?php echo esc_html( $stats['following'] ); ?></span>
                            <span class="qibbs-stat-label"><?php esc_html_e( '关注', 'qibbs-community' ); ?></span>
                        </div>
                    </div>
                </div>
            <?php else : ?>
                <div class="qibbs-widget qibbs-login-cta">
                    <h3><?php esc_html_e( '加入社区', 'qibbs-community' ); ?></h3>
                    <p><?php esc_html_e( '登录后参与互动', 'qibbs-community' ); ?></p>
                    <?php echo Qibbs_Theme_Compatibility::get_login_button_html( 'qibbs-btn qibbs-btn-primary' ); ?>
                    <?php echo Qibbs_Theme_Compatibility::get_register_button_html( 'qibbs-btn qibbs-btn-secondary' ); ?>
                </div>
            <?php endif; ?>

            <div class="qibbs-widget qibbs-hot-topics">
                <h3 class="qibbs-widget-title"><?php esc_html_e( '热门话题', 'qibbs-community' ); ?></h3>
                <div class="qibbs-topics-list">
                    <?php
                    $forums = get_terms( array(
                        'taxonomy'   => 'qibbs_forum',
                        'hide_empty' => false,
                        'number'     => 5,
                    ) );
                    if ( ! is_wp_error( $forums ) && ! empty( $forums ) ) :
                        foreach ( $forums as $forum ) :
                            ?>
                            <a href="<?php echo esc_url( get_term_link( $forum ) ); ?>" class="qibbs-topic-item">
                                <span class="qibbs-topic-name"># <?php echo esc_html( $forum->name ); ?></span>
                            </a>
                            <?php
                        endforeach;
                    else :
                        ?>
                        <p class="qibbs-empty"><?php esc_html_e( '暂无话题', 'qibbs-community' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php do_action( 'qibbs_sidebar_content' ); ?>
        </aside>
    </div>
</div>

<?php 
$interactive_modals = QIBBS_PLUGIN_DIR . 'templates/modals-interactive.php';
if ( file_exists( $interactive_modals ) ) {
    include $interactive_modals;
}
get_footer(); 
?>
