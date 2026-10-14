<?php
/**
 * 社区三栏主布局模板
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

get_header();

// 从数据表获取版块列表
global $wpdb;
$forums_table = Qibbs_Database::forums();
$forums = class_exists( 'Qibbs_Cache_Service' )
    ? Qibbs_Cache_Service::get_sidebar_forums( 50 )
    : $wpdb->get_results( $wpdb->prepare( "SELECT id, name, icon, sort_order FROM {$forums_table} ORDER BY sort_order ASC, id ASC LIMIT %d", 50 ) );

// 获取主题预设风格属性
$layout_service = new Qibbs_Layout_Service();
$theme_attr = $layout_service->get_theme_attribute();
?>

<div class="qibbs-wrapper"<?php echo $theme_attr; ?>>
    <div class="qibbs-layout qibbs-three-column">
        <nav class="qibbs-nav-sidebar">
            <div class="qibbs-nav-menu">
                <?php 
                $current_view   = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
                $is_vanity_view = ( 'vanity-mall' === $current_view && qibbs_is_module_enabled( 'vanity_mall' ) );
                $is_growth_view = ( 'growth' === $current_view && qibbs_is_growth_levels_enabled() );
                $is_prop_view   = ( 'prop-mall' === $current_view && function_exists( 'qibbs_is_prop_mall_enabled' ) && qibbs_is_prop_mall_enabled() );
                ?>
                <a href="<?php echo esc_url( qibbs_get_community_url() ); ?>" class="qibbs-nav-item <?php echo ( ! $is_vanity_view && ! $is_growth_view && ! $is_prop_view ) ? 'active' : ''; ?>" data-filter="global" data-filter-type="feed">
                    <svg viewBox="0 0 24 24" width="24" height="24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                    <span><?php esc_html_e( '首页', 'qibbs-community' ); ?></span>
                </a>
                <?php if ( qibbs_is_growth_levels_enabled() && qibbs_get_option( 'show_nav_growth', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_growth_center_url() ); ?>" class="qibbs-nav-item qibbs-nav-growth <?php echo $is_growth_view ? 'active' : ''; ?>" data-filter="growth-center" data-filter-type="view" id="qibbs-nav-growth-center">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                            <path d="M19 5h-2V3H7v2H5c-1.1 0-2 .9-2 2v1c0 2.55 1.92 4.63 4.39 4.94A5.01 5.01 0 0 0 11 15.9V19H7v2h10v-2h-4v-3.1c2.3-.41 4.09-2.19 4.61-4.96C20.08 10.63 22 8.55 22 8V7c0-1.1-.9-2-2-2zM5 8V7h2v3.82C5.84 10.4 5 9.3 5 8zm14 0c0 1.3-.84 2.4-2 2.82V7h2v1z"/>
                        </svg>
                        <span><?php esc_html_e( '成长中心', 'qibbs-community' ); ?></span>
                    </a>
                <?php endif; ?>
                <?php if ( qibbs_is_module_enabled( 'vanity_mall' ) && qibbs_get_option( 'show_nav_mall', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_vanity_mall_url() ); ?>" class="qibbs-nav-item qibbs-nav-mall <?php echo $is_vanity_view ? 'active' : ''; ?>" data-filter="vanity-mall" data-filter-type="view" id="qibbs-nav-vanity-mall">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                            <path d="M12 2L2 9.21L6 21H18L22 9.21L12 2ZM12 5.56L17.24 9.35L15.34 19H8.66L6.76 9.35L12 5.56Z"/>
                        </svg>
                        <span><?php esc_html_e( '交易大厅', 'qibbs-community' ); ?></span>
                    </a>
                <?php endif; ?>
                <?php if ( function_exists( 'qibbs_is_prop_mall_enabled' ) && qibbs_is_prop_mall_enabled() && qibbs_get_option( 'show_nav_prop_mall', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_prop_mall_url() ); ?>" class="qibbs-nav-item qibbs-nav-props <?php echo $is_prop_view ? 'active' : ''; ?>" data-filter="prop-mall" data-filter-type="view" id="qibbs-nav-prop-mall">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                            <path d="M7.5 5.6L5 7l1.4-2.5L5 2l2.5 1.4L10 2 8.6 4.5 10 7 7.5 5.6zm12 9.8L17 14l1.4 2.5L17 19l2.5-1.4L22 19l-1.4-2.5L22 14l-2.5 1.4zM22 2l-2.5 1.4L17 2l1.4 2.5L17 7l2.5-1.4L22 7l-1.4-2.5L22 2zm-7.63 5.29a.996.996 0 0 0-1.41 0L1.29 18.96a.996.996 0 0 0 0 1.41l2.34 2.34c.39.39 1.02.39 1.41 0L16.7 11.05a.996.996 0 0 0 0-1.41l-2.33-2.35zm-1.03 5.49l-2.12-2.12 2.44-2.44 2.12 2.12-2.44 2.44z"/>
                        </svg>
                        <span><?php esc_html_e( '道具大厅', 'qibbs-community' ); ?></span>
                    </a>
                <?php endif; ?>
                <?php if ( is_user_logged_in() ) : ?>
                    <?php if ( qibbs_get_option( 'show_nav_following', true ) ) : ?>
                    <a href="#" class="qibbs-nav-item" data-filter="following" data-filter-type="feed">
                        <svg viewBox="0 0 24 24" width="24" height="24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                        <span><?php esc_html_e( '关注', 'qibbs-community' ); ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if ( qibbs_get_option( 'show_nav_notifications', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_notifications_url() ); ?>" class="qibbs-nav-item">
                        <svg viewBox="0 0 24 24" width="24" height="24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
                        <span><?php esc_html_e( '通知', 'qibbs-community' ); ?></span>
                        <span class="qibbs-badge" id="qibbs-notification-badge"></span>
                    </a>
                    <?php endif; ?>
                    <?php if ( qibbs_get_option( 'show_nav_messages', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_messages_url() ); ?>" class="qibbs-nav-item">
                        <svg viewBox="0 0 24 24" width="24" height="24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                        <span><?php esc_html_e( '私信', 'qibbs-community' ); ?></span>
                        <span class="qibbs-badge" id="qibbs-message-badge"></span>
                    </a>
                    <?php endif; ?>
                    <?php 
                    // 工单系统入口（若启用）
                    if ( qibbs_get_option( 'enable_tickets', false ) && qibbs_get_option( 'show_nav_tickets', true ) ) :
                        // 若可用则使用主题集成类（跳转至个人中心工单）
                        if ( class_exists( 'Qibbs_Qiling_Integration' ) ) {
                            $ticket_url = Qibbs_Qiling_Integration::get_ticket_url();
                        } else {
                            $ticket_page_id = qibbs_get_option( 'ticket_page', 0 );
                            $ticket_url = $ticket_page_id ? qibbs_translate_frontend_internal_url( get_permalink( $ticket_page_id ) ) : '#';
                        }
                    ?>
                    <a href="<?php echo esc_url( $ticket_url ); ?>" class="qibbs-nav-item">
                        <svg viewBox="0 0 24 24" width="24" height="24"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                        <span><?php esc_html_e( '工单', 'qibbs-community' ); ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if ( qibbs_get_option( 'show_nav_profile', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_profile_url( get_current_user_id() ) ); ?>" class="qibbs-nav-item">
                        <svg viewBox="0 0 24 24" width="24" height="24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                        <span><?php esc_html_e( '我的', 'qibbs-community' ); ?></span>
                    </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="qibbs-nav-forums">
                <h3 class="qibbs-nav-title"><?php esc_html_e( '热门圈子', 'qibbs-community' ); ?></h3>
                <?php
                // 热门动态入口（若启用）
                if ( qibbs_get_option( 'show_hot_posts', false ) ) :
                    $hot_default_window = absint( qibbs_get_option( 'hot_default_window', 7 ) );
                    if ( ! in_array( $hot_default_window, array( 1, 7, 30 ), true ) ) {
                        $hot_default_window = 7;
                    }
                ?>
                    <a href="#" class="qibbs-nav-item qibbs-filter-hot" data-filter="hot" data-filter-type="feed">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                            <path d="M17.66 11.2C17.43 10.9 17.15 10.64 16.89 10.38C16.22 9.78 15.46 9.35 14.82 8.72C13.33 7.26 13 4.85 13.95 3C13 3.23 12.17 3.75 11.46 4.32C8.87 6.4 7.85 10.07 9.07 13.22C9.11 13.32 9.15 13.42 9.15 13.55C9.15 13.77 9 13.97 8.8 14.05C8.57 14.15 8.33 14.09 8.14 13.93C8.08 13.88 8.04 13.83 8 13.76C6.87 12.33 6.69 10.28 7.45 8.64C5.78 10 4.87 12.3 5 14.47C5.06 14.97 5.12 15.47 5.29 15.97C5.43 16.57 5.7 17.17 6 17.7C7.08 19.43 8.95 20.67 10.96 20.92C13.1 21.19 15.39 20.8 17.03 19.32C18.86 17.66 19.5 15 18.56 12.72L18.43 12.46C18.22 12 17.66 11.2 17.66 11.2Z"/>
                        </svg>
                        <span><?php esc_html_e( '热门动态', 'qibbs-community' ); ?></span>
                    </a>
                    <div class="qibbs-hot-window-switch" id="qibbs-hot-window-switch" style="display:none;">
                        <button type="button" class="qibbs-hot-window-btn <?php echo ( 1 === $hot_default_window ) ? 'active' : ''; ?>" data-hot-window="1"><?php esc_html_e( '24小时', 'qibbs-community' ); ?></button>
                        <button type="button" class="qibbs-hot-window-btn <?php echo ( 7 === $hot_default_window ) ? 'active' : ''; ?>" data-hot-window="7"><?php esc_html_e( '7天', 'qibbs-community' ); ?></button>
                        <button type="button" class="qibbs-hot-window-btn <?php echo ( 30 === $hot_default_window ) ? 'active' : ''; ?>" data-hot-window="30"><?php esc_html_e( '30天', 'qibbs-community' ); ?></button>
                    </div>
                <?php endif; ?>
                <?php
                // 精华动态入口（若启用）
                if ( qibbs_get_option( 'enable_featured_posts', false ) ) :
                ?>
                    <a href="#" class="qibbs-nav-item qibbs-filter-featured" data-filter="featured" data-filter-type="feed">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                            <path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>
                        </svg>
                        <span><?php esc_html_e( '推荐动态', 'qibbs-community' ); ?></span>
                    </a>
                <?php endif; ?>
                <?php
                // 投票动态入口（若启用）
                if ( qibbs_get_option( 'show_voting_posts', false ) ) :
                ?>
                    <a href="#" class="qibbs-nav-item qibbs-filter-voting" data-filter="voting" data-filter-type="feed">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                            <path d="M4 14h4v-4H4v4zm0 5h4v-4H4v4zM4 9h4V5H4v4zm5 5h12v-4H9v4zm0 5h12v-4H9v4zM9 5v4h12V5H9z"/>
                        </svg>
                        <span><?php esc_html_e( '投票动态', 'qibbs-community' ); ?></span>
                    </a>
                <?php endif; ?>
                <?php
                // 红包动态入口（若启用）
                if ( qibbs_get_option( 'show_redpacket_posts', true ) ) :
                ?>
                    <a href="#" class="qibbs-nav-item qibbs-filter-redpacket" data-filter="redpacket" data-filter-type="feed">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                            <path d="M20 4H4C2.9 4 2 4.9 2 6V18C2 19.1 2.9 20 4 20H20C21.1 20 22 19.1 22 18V6C22 4.9 21.1 4 20 4ZM20 6.5C18.2 7.7 15.3 8.5 12 8.5C8.7 8.5 5.8 7.7 4 6.5V6H20V6.5ZM12 14.5C10.6 14.5 9.5 13.4 9.5 12C9.5 11.4 9.7 10.8 10.1 10.4C10.7 10.45 11.3 10.5 12 10.5C12.7 10.5 13.3 10.45 13.9 10.4C14.3 10.8 14.5 11.4 14.5 12C14.5 13.4 13.4 14.5 12 14.5ZM4 18V8.6C5.9 9.8 8.7 10.5 12 10.5C15.3 10.5 18.1 9.8 20 8.6V18H4Z"/>
                        </svg>
                        <span><?php esc_html_e( '红包动态', 'qibbs-community' ); ?></span>
                    </a>
                <?php endif; ?>
                <?php
                // 悬赏动态入口（若启用）
                if ( qibbs_get_option( 'show_bounty_posts', true ) ) :
                ?>
                    <a href="#" class="qibbs-nav-item qibbs-filter-bounty" data-filter="bounty" data-filter-type="feed">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm0-14c-3.31 0-6 2.69-6 6s2.69 6 6 6 6-2.69 6-6-2.69-6-6-6zm0 10c-2.21 0-4-1.79-4-4s1.79-4 4-4 4 1.79 4 4-1.79 4-4 4zm0-6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/>
                        </svg>
                        <span><?php esc_html_e( '悬赏动态', 'qibbs-community' ); ?></span>
                    </a>
                <?php endif; ?>
                <?php
                if ( ! empty( $forums ) ) :
                    $forum_ids = array();
                    $forum_subscription_enabled = (bool) qibbs_get_option( 'enable_forum_subscription_notifications', true );
                    $forum_subscription_map = array();

                    foreach ( $forums as $forum_item ) {
                        $forum_ids[] = (int) $forum_item->id;
                    }

                    $forum_ids = array_values( array_unique( array_filter( $forum_ids ) ) );
                    if ( class_exists( 'Qibbs_Cache_Service' ) ) {
                        $forum_counts = Qibbs_Cache_Service::get_forum_post_counts( $forum_ids );
                    } else {
                        $forum_counts = array();
                        $posts_table = Qibbs_Database::posts();
                        if ( ! empty( $forum_ids ) ) {
                            $placeholders = implode( ',', array_fill( 0, count( $forum_ids ), '%d' ) );
                            $count_rows = $wpdb->get_results(
                                $wpdb->prepare(
                                    "SELECT forum_id, COUNT(*) AS total FROM {$posts_table}
                                     WHERE status = 'publish' AND forum_id IN ({$placeholders})
                                     GROUP BY forum_id",
                                    $forum_ids
                                )
                            );
                            foreach ( (array) $count_rows as $count_row ) {
                                $forum_counts[ (int) $count_row->forum_id ] = (int) $count_row->total;
                            }
                        }
                    }

                    if ( $forum_subscription_enabled && is_user_logged_in() && class_exists( 'Qibbs_Forum_Subscription_Service' ) ) {
                        $forum_subscription_service = new Qibbs_Forum_Subscription_Service();
                        $forum_subscription_map = $forum_subscription_service->get_user_subscriptions_for_forums( get_current_user_id(), $forum_ids );
                    }

                    foreach ( $forums as $forum ) :
                        $forum_count = isset( $forum_counts[ (int) $forum->id ] ) ? (int) $forum_counts[ (int) $forum->id ] : 0;
                        $forum_id = (int) $forum->id;
                        $is_subscribed = ! empty( $forum_subscription_map[ $forum_id ] );
                        ?>
                        <div class="qibbs-forum-row">
                            <a href="#" class="qibbs-nav-item qibbs-filter-forum" data-filter="<?php echo esc_attr( $forum_id ); ?>" data-filter-type="forum" data-filter-name="<?php echo esc_attr( $forum->name ); ?>">
                                <?php if ( ! empty( $forum->icon ) ) : ?>
                                    <img src="<?php echo esc_url( $forum->icon ); ?>" alt="" class="qibbs-forum-icon">
                                <?php else : ?>
                                    <span class="qibbs-forum-icon-placeholder"><?php echo mb_substr( $forum->name, 0, 1 ); ?></span>
                                <?php endif; ?>
                                <span><?php echo esc_html( $forum->name ); ?></span>
                                <span class="qibbs-count"><?php echo esc_html( $forum_count ); ?></span>
                            </a>
                            <?php if ( $forum_subscription_enabled && is_user_logged_in() ) : ?>
                                <button
                                    type="button"
                                    class="qibbs-forum-subscribe-btn<?php echo $is_subscribed ? ' is-subscribed' : ''; ?>"
                                    data-forum-id="<?php echo esc_attr( $forum_id ); ?>"
                                >
                                    <?php echo $is_subscribed ? esc_html__( '已订阅', 'qibbs-community' ) : esc_html__( '订阅', 'qibbs-community' ); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php
                    endforeach;
                endif;
                ?>
            </div>

            <?php
            // 若启用文章同步且开启显示分类，左侧栏展示文章分类
            if ( qibbs_get_option( 'enable_article_sync', false ) && qibbs_get_option( 'show_sidebar_categories', true ) ) :
                $sync_categories = qibbs_get_option( 'sync_categories', array() );
                $cat_args = array( 'hide_empty' => false );
                if ( ! empty( $sync_categories ) ) {
                    $cat_args['include'] = $sync_categories;
                }
                $article_cats = get_categories( $cat_args );
                if ( ! empty( $article_cats ) ) :
            ?>
            <div class="qibbs-nav-categories">
                <h3 class="qibbs-nav-title"><?php esc_html_e( '文章分类', 'qibbs-community' ); ?></h3>
                <?php foreach ( $article_cats as $cat ) : ?>
                    <a href="#" class="qibbs-nav-item qibbs-filter-category" data-filter="<?php echo esc_attr( $cat->term_id ); ?>" data-filter-type="category" data-filter-name="<?php echo esc_attr( $cat->name ); ?>">
                        <span class="qibbs-forum-icon-placeholder" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <?php echo mb_substr( $cat->name, 0, 1 ); ?>
                        </span>
                        <span><?php echo esc_html( $cat->name ); ?></span>
                        <span class="qibbs-count"><?php echo esc_html( $cat->count ); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php 
                endif;
            endif; 
            ?>

            <?php do_action( 'qibbs_nav_content' ); ?>
        </nav>

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
                <div class="qibbs-composer" id="qibbs-composer" style="<?php echo ( $is_vanity_view || $is_growth_view || $is_prop_view ) ? 'display:none;' : ''; ?>">
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
                            <button type="button" class="qibbs-editor-toggle" id="qibbs-editor-toggle" title="<?php esc_attr_e( '切换富文本编辑器', 'qibbs-community' ); ?>">
                                <svg class="icon-rich" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M5 17v2h14v-2H5zm4.5-4.2h5l.9 2.2h2.1L12.75 4h-1.5L6.5 15h2.1l.9-2.2zM12 5.98L13.87 11h-3.74L12 5.98z"/></svg>
                                <svg class="icon-plain" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" style="display:none;"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                            </button>
                        </div>
                        <div class="qibbs-composer-body">
                            <textarea name="content" id="qibbs-post-content" placeholder="<?php esc_attr_e( '分享你的想法... 使用 #标签 添加话题', 'qibbs-community' ); ?>" rows="3"></textarea>
                            <div class="qibbs-mention-box" id="qibbs-mention-box" style="display:none;"></div>
                            <div id="qibbs-rich-editor-container" style="display:none;">
                                <?php 
                                wp_editor( '', 'qibbs_rich_content', array(
                                    'media_buttons' => false,
                                    'textarea_rows' => 6,
                                    'teeny'         => true,
                                    'quicktags'     => false,
                                    'tinymce'       => array(
                                        'toolbar1'          => 'bold,italic,underline,strikethrough,|,bullist,numlist,|,link,unlink,|,removeformat',
                                        'toolbar2'          => '',
                                        'content_css'       => '',
                                        'paste_data_images' => true,
                                    ),
                                ) );
                                ?>
                            </div>
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
                                <label class="qibbs-tool-btn" title="<?php esc_attr_e( '添加图片/视频', 'qibbs-community' ); ?>">
                                    <input type="file" name="media" accept="image/*,video/*" multiple hidden id="qibbs-media-input">
                                    <svg viewBox="0 0 24 24" width="22" height="22"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                                </label>
                                <select name="post_type" id="qibbs-post-type" class="qibbs-tool-select">
                                    <?php foreach ( qibbs_get_post_type_options() as $post_type_key => $post_type_config ) : ?>
                                        <option value="<?php echo esc_attr( $post_type_key ); ?>" <?php selected( 'discussion', $post_type_key ); ?>>
                                            <?php echo esc_html( $post_type_config['label'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="forum_id" id="qibbs-forum-select" class="qibbs-tool-select">
                                    <option value="0"><?php esc_html_e( '选择圈子', 'qibbs-community' ); ?></option>
                                    <?php
                                    if ( ! empty( $forums ) ) :
                                        foreach ( $forums as $forum ) :
                                            ?>
                                            <option value="<?php echo esc_attr( $forum->id ); ?>"><?php echo esc_html( $forum->name ); ?></option>
                                            <?php
                                        endforeach;
                                    endif;
                                    ?>
                                </select>
                            </div>
                            <div class="qibbs-composer-meta">
                                <?php if ( '' !== $composer_post_reward_hint ) : ?>
                                    <span class="qibbs-points-hint qibbs-composer-points-hint"><?php echo esc_html( $composer_post_reward_hint ); ?></span>
                                <?php endif; ?>
                                <span class="qibbs-draft-status" id="qibbs-draft-status"></span>
                                <button type="button" class="qibbs-draft-clear" id="qibbs-draft-clear"><?php esc_html_e( '清除草稿', 'qibbs-community' ); ?></button>
                            </div>
                            <button type="submit" class="qibbs-submit-btn"><?php esc_html_e( '发布', 'qibbs-community' ); ?></button>
                        </div>
                    </form>
                </div>
                
                <style>
                .qibbs-editor-toggle {
                    margin-left: auto;
                    background: var(--qibbs-bg-hover);
                    border: none;
                    border-radius: var(--qibbs-radius-sm);
                    padding: 8px 12px;
                    cursor: pointer;
                    color: var(--qibbs-text-secondary);
                    transition: var(--qibbs-transition);
                }
                .qibbs-editor-toggle:hover {
                    background: var(--qibbs-primary-light);
                    color: var(--qibbs-primary);
                }
                .qibbs-editor-toggle.active {
                    background: var(--qibbs-primary);
                    color: white;
                }
                #qibbs-rich-editor-container .wp-editor-wrap {
                    border: 1px solid var(--qibbs-border);
                    border-radius: var(--qibbs-radius-sm);
                    overflow: hidden;
                }
                #qibbs-rich-editor-container .mce-toolbar-grp {
                    background: var(--qibbs-bg) !important;
                    border-bottom: 1px solid var(--qibbs-border) !important;
                }
                #qibbs-rich-editor-container iframe {
                    background: var(--qibbs-bg-card) !important;
                }
                .qibbs-editor-image {
                    max-width: 100%;
                    height: auto;
                    border-radius: var(--qibbs-radius-sm);
                    margin: 8px 0;
                    display: block;
                }
                .qibbs-image-uploading {
                    opacity: 0.6 !important;
                    filter: blur(1px) !important;
                    animation: qibbsPulse 1.5s infinite ease-in-out !important;
                    cursor: wait !important;
                }
                @keyframes qibbsPulse {
                    0%, 100% { opacity: 0.5; }
                    50% { opacity: 0.85; }
                }
                </style>
                
                <script>
                jQuery(document).ready(function($) {
                    var useRichEditor = false;
                    window.qibbsRichMediaIds = window.qibbsRichMediaIds || [];
                    window.qibbsEditorUploadingCount = window.qibbsEditorUploadingCount || 0;

                    // 辅助函数：将 Base64 转换为 Blob
                    function b64ToBlob(base64Data, contentType) {
                        contentType = contentType || '';
                        var sliceSize = 1024;
                        var byteCharacters = atob(base64Data);
                        var byteArrays = [];
                        for (var offset = 0; offset < byteCharacters.length; offset += sliceSize) {
                            var slice = byteCharacters.slice(offset, offset + sliceSize);
                            var byteNumbers = new Array(slice.length);
                            for (var i = 0; i < slice.length; i++) {
                                byteNumbers[i] = slice.charCodeAt(i);
                            }
                            byteArrays.push(new Uint8Array(byteNumbers));
                        }
                        return new Blob(byteArrays, { type: contentType });
                    }

                    // 辅助函数：从剪贴板或拖拽事件提取图片文件列表
                    function extractImagesFromEvent(e) {
                        var cd = e.clipboardData || (e.originalEvent && e.originalEvent.clipboardData) || e.dataTransfer || (e.originalEvent && e.originalEvent.dataTransfer) || window.clipboardData;
                        if (!cd) return [];
                        var images = [];

                        if (cd.items && cd.items.length > 0) {
                            for (var i = 0; i < cd.items.length; i++) {
                                var item = cd.items[i];
                                if (item.type && item.type.indexOf('image/') === 0) {
                                    var f = item.getAsFile();
                                    if (f) images.push(f);
                                }
                            }
                        }

                        if (images.length === 0 && cd.files && cd.files.length > 0) {
                            for (var j = 0; j < cd.files.length; j++) {
                                var file = cd.files[j];
                                if (file.type && file.type.indexOf('image/') === 0) {
                                    images.push(file);
                                }
                            }
                        }

                        // 若剪贴板包含 HTML 且内嵌了 Base64 图片
                        if (images.length === 0 && cd.getData) {
                            var htmlText = cd.getData('text/html');
                            if (htmlText && htmlText.indexOf('data:image/') !== -1) {
                                var matches = htmlText.match(/src=["'](data:image\/(png|jpe?g|gif|webp);base64,([^"']+))["']/gi);
                                if (matches) {
                                    matches.forEach(function(m) {
                                        var parts = m.match(/src=["']data:image\/([^;]+);base64,([^"']+)["']/i);
                                        if (parts && parts[2]) {
                                            var mime = 'image/' + parts[1];
                                            var blob = b64ToBlob(parts[2], mime);
                                            blob.name = 'paste-' + Date.now() + '.' + (parts[1] === 'jpeg' ? 'jpg' : parts[1]);
                                            images.push(blob);
                                        }
                                    });
                                }
                            }
                        }

                        return images;
                    }

                    // 上传图片并插入 TinyMCE 富文本编辑器
                    function uploadImageToEditor(editor, file) {
                        if (!file) return;

                        // 1. 本地校验文件体积与格式
                        var maxSizeMB = (typeof qibbsData !== 'undefined' && qibbsData.maxUploadSize) ? qibbsData.maxUploadSize : 5;
                        var maxSizeBytes = maxSizeMB * 1024 * 1024;
                        if (file.size > maxSizeBytes) {
                            var sizeMsg = '图片大小超过 ' + maxSizeMB + 'MB 限制';
                            if (typeof Qibbs !== 'undefined' && Qibbs.showToast) {
                                Qibbs.showToast(sizeMsg, 'error');
                            } else {
                                alert(sizeMsg);
                            }
                            return;
                        }

                        var allowedFormats = (typeof qibbsData !== 'undefined' && qibbsData.allowedImageFormats)
                            ? qibbsData.allowedImageFormats.split(',').map(function(s){ return s.trim().toLowerCase(); })
                            : ['jpg', 'jpeg', 'png', 'webp', 'gif'];

                        var ext = '';
                        if (file.name && file.name.indexOf('.') !== -1) {
                            ext = file.name.split('.').pop().toLowerCase();
                        } else if (file.type) {
                            ext = file.type.replace('image/', '').toLowerCase();
                            if (ext === 'jpeg') ext = 'jpg';
                        }
                        if (!ext) ext = 'png';

                        if (allowedFormats.indexOf(ext) === -1) {
                            var formatMsg = '不支持该图片格式（允许：' + allowedFormats.join(', ') + '）';
                            if (typeof Qibbs !== 'undefined' && Qibbs.showToast) {
                                Qibbs.showToast(formatMsg, 'error');
                            } else {
                                alert(formatMsg);
                            }
                            return;
                        }

                        // 2. 插入优雅的富文本上传占位卡片（使用 data-upload-id 定位，彻底杜绝 blob 跨沙箱破裂图标与 id 被清洗问题）
                        var uploadId = 'qibbs-upload-' + Date.now() + '-' + Math.floor(Math.random() * 10000);
                        var placeholderHtml = '<p class="qibbs-editor-upload-card" data-upload-id="' + uploadId + '" contenteditable="false" style="padding:10px 14px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;display:inline-flex;align-items:center;gap:10px;color:#64748b;font-size:13px;margin:8px 0;user-select:none;">' +
                            '<span class="qibbs-spinner" style="width:14px;height:14px;border:2px solid #cbd5e1;border-top-color:#6366f1;border-radius:50%;display:inline-block;animation:spin 0.8s linear infinite;"></span>' +
                            '<span>正在上传图片...</span>' +
                            '</p><p><br></p>';
                        editor.insertContent(placeholderHtml);

                        // 3. 锁定提交按钮，增加上传中计数
                        window.qibbsEditorUploadingCount = (window.qibbsEditorUploadingCount || 0) + 1;
                        var $submitBtn = $('#qibbs-post-form .qibbs-submit-btn');
                        $submitBtn.prop('disabled', true).html('<span class="qibbs-spinner"></span><span class="qibbs-btn-text">图片上传中...</span>');

                        // 4. 发起 AJAX 请求上传至服务端媒体库
                        var formData = new FormData();
                        formData.append('action', 'qibbs_upload_media');
                        var nonceVal = (typeof qibbsData !== 'undefined' && qibbsData.nonce) ? qibbsData.nonce : ($('#qibbs-post-nonce').val() || '');
                        formData.append('nonce', nonceVal);
                        var safeFileName = file.name || '';
                        if (!safeFileName || safeFileName.indexOf('.') === -1 || safeFileName === 'blob' || safeFileName === 'image') {
                            safeFileName = 'paste-' + Date.now() + '.' + ext;
                        }
                        formData.append('file', file, safeFileName);

                        $.ajax({
                            url: (typeof qibbsData !== 'undefined' && qibbsData.ajaxUrl) ? qibbsData.ajaxUrl : '/wp-admin/admin-ajax.php',
                            type: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            success: function(response) {
                                var targetEl = editor.dom ? editor.dom.select('[data-upload-id="' + uploadId + '"]')[0] : null;
                                if (response.success && response.data && response.data.url) {
                                    var realUrl = response.data.url;
                                    var mediaId = response.data.id;
                                    var imgHtml = '<p><img src="' + realUrl + '" class="qibbs-editor-image" alt="" style="max-width:100%;height:auto;border-radius:8px;margin:8px 0;" data-media-id="' + mediaId + '" /></p>';

                                    if (targetEl && editor.dom) {
                                        editor.dom.setOuterHTML(targetEl, imgHtml);
                                        editor.nodeChanged();
                                    } else {
                                        editor.insertContent(imgHtml);
                                    }

                                    if (mediaId && window.qibbsRichMediaIds.indexOf(mediaId) === -1) {
                                        window.qibbsRichMediaIds.push(mediaId);
                                    }

                                    if (typeof Qibbs !== 'undefined' && Qibbs.showToast) {
                                        Qibbs.showToast('图片已上传并插入正文', 'success');
                                    }
                                } else {
                                    if (targetEl && editor.dom) {
                                        editor.dom.remove(targetEl);
                                        editor.nodeChanged();
                                    }
                                    var errorMsg = (response.data && response.data.message) ? response.data.message : '图片上传失败';
                                    if (typeof Qibbs !== 'undefined' && Qibbs.showToast) {
                                        Qibbs.showToast(errorMsg, 'error');
                                    } else {
                                        alert(errorMsg);
                                    }
                                }
                            },
                            error: function() {
                                var targetEl = editor.dom ? editor.dom.select('[data-upload-id="' + uploadId + '"]')[0] : null;
                                if (targetEl && editor.dom) {
                                    editor.dom.remove(targetEl);
                                    editor.nodeChanged();
                                }
                                if (typeof Qibbs !== 'undefined' && Qibbs.showToast) {
                                    Qibbs.showToast('网络异常，图片上传失败', 'error');
                                }
                            },
                            complete: function() {
                                window.qibbsEditorUploadingCount = Math.max(0, (window.qibbsEditorUploadingCount || 1) - 1);
                                if (window.qibbsEditorUploadingCount === 0) {
                                    $submitBtn.prop('disabled', false).text('发布');
                                }
                            }
                        });
                    }

                    // 绑定 TinyMCE 粘贴与拖拽事件
                    function bindRichEditorEvents(editor) {
                        if (!editor || editor._qibbsEventsBound) return;
                        editor._qibbsEventsBound = true;

                        // 监听粘贴事件 (Ctrl+V)
                        editor.on('paste', function(e) {
                            var images = extractImagesFromEvent(e);
                            if (images && images.length > 0) {
                                e.preventDefault();
                                e.stopPropagation();
                                images.forEach(function(img) {
                                    uploadImageToEditor(editor, img);
                                });
                            }
                        });

                        // 监听拖拽放入事件 (Drag & Drop)
                        editor.on('drop', function(e) {
                            var images = extractImagesFromEvent(e);
                            if (images && images.length > 0) {
                                e.preventDefault();
                                e.stopPropagation();
                                images.forEach(function(img) {
                                    uploadImageToEditor(editor, img);
                                });
                            }
                        });

                        // 兜底拦截 PastePreProcess 中任何可能逃逸的 Base64 图片
                        editor.on('PastePreProcess', function(e) {
                            if (e.content && e.content.indexOf('data:image/') !== -1) {
                                e.content = e.content.replace(/<img[^>]+src=["'](data:image\/[^;]+;base64,[^"']+)["'][^>]*>/gi, function(match, dataUri) {
                                    setTimeout(function() {
                                        var parts = dataUri.match(/data:image\/([^;]+);base64,(.+)/);
                                        if (parts && parts[2]) {
                                            var mime = 'image/' + parts[1];
                                            var blob = b64ToBlob(parts[2], mime);
                                            blob.name = 'paste-' + Date.now() + '.' + (parts[1] === 'jpeg' ? 'jpg' : parts[1]);
                                            uploadImageToEditor(editor, blob);
                                        }
                                    }, 60);
                                    return '<p class="qibbs-pasted-placeholder"><span class="qibbs-spinner"></span> 正在上传粘贴图片...</p>';
                                });
                            }
                        });
                    }

                    // 初始化 TinyMCE 实例监听
                    function setupRichEditor() {
                        if (typeof tinymce === 'undefined') return;
                        var ed = tinymce.get('qibbs_rich_content');
                        if (ed) {
                            if (ed.initialized) {
                                bindRichEditorEvents(ed);
                            } else {
                                ed.on('init', function() {
                                    bindRichEditorEvents(ed);
                                });
                            }
                        }
                    }

                    if (typeof tinymce !== 'undefined') {
                        tinymce.on('AddEditor', function(e) {
                            if (e.editor && e.editor.id === 'qibbs_rich_content') {
                                e.editor.on('init', function() {
                                    bindRichEditorEvents(e.editor);
                                });
                            }
                        });
                    }
                    setupRichEditor();
                    
                    $('#qibbs-editor-toggle').on('click', function() {
                        useRichEditor = !useRichEditor;
                        $(this).toggleClass('active', useRichEditor);
                        
                        if (useRichEditor) {
                            // 切换到富文本编辑器
                            var plainContent = $('#qibbs-post-content').val();
                            $('#qibbs-post-content').hide();
                            $('#qibbs-rich-editor-container').show();
                            setupRichEditor();
                            
                            // 初始化 TinyMCE 编辑器内容
                            if (typeof tinymce !== 'undefined' && tinymce.get('qibbs_rich_content')) {
                                tinymce.get('qibbs_rich_content').setContent(plainContent.replace(/\n/g, '<br>'));
                            }
                            
                            $(this).find('.icon-rich').hide();
                            $(this).find('.icon-plain').show();
                        } else {
                            // 切换回纯文本编辑器
                            var richContent = '';
                            if (typeof tinymce !== 'undefined' && tinymce.get('qibbs_rich_content')) {
                                richContent = tinymce.get('qibbs_rich_content').getContent();
                            }
                            $('#qibbs-rich-editor-container').hide();
                            $('#qibbs-post-content').show().val($('<div>').html(richContent).text());
                            
                            $(this).find('.icon-plain').hide();
                            $(this).find('.icon-rich').show();
                        }
                    });
                    
                    // 表单提交时从当前激活的编辑器同步内容并防御校验
                    var originalSubmit = Qibbs.handlePostSubmit;
                    if (typeof Qibbs !== 'undefined' && Qibbs.handlePostSubmit) {
                        Qibbs.handlePostSubmit = function(e) {
                            if (useRichEditor && typeof tinymce !== 'undefined' && tinymce.get('qibbs_rich_content')) {
                                // 检查是否有仍在上传的图片
                                if (window.qibbsEditorUploadingCount && window.qibbsEditorUploadingCount > 0) {
                                    if (typeof Qibbs !== 'undefined' && Qibbs.showToast) {
                                        Qibbs.showToast('图片正在上传中，请稍候...', 'warning');
                                    }
                                    if (e && e.preventDefault) e.preventDefault();
                                    return false;
                                }

                                var richContent = tinymce.get('qibbs_rich_content').getContent();

                                // 严格防呆：检查是否残留未转换成功的 Base64 图片
                                if (richContent.indexOf('data:image/') !== -1) {
                                    if (typeof Qibbs !== 'undefined' && Qibbs.showToast) {
                                        Qibbs.showToast('检测到未完成上传的粘贴图片，请稍候或重新粘贴', 'warning');
                                    }
                                    if (e && e.preventDefault) e.preventDefault();
                                    return false;
                                }

                                $('#qibbs-post-content').val(richContent);
                            }
                            return originalSubmit.call(Qibbs, e);
                        };
                    }
                    
                    // 兜底原生表单提交事件
                    $('#qibbs-post-form').on('submit', function(e) {
                        if (useRichEditor && typeof tinymce !== 'undefined' && tinymce.get('qibbs_rich_content')) {
                            if (window.qibbsEditorUploadingCount && window.qibbsEditorUploadingCount > 0) {
                                if (typeof Qibbs !== 'undefined' && Qibbs.showToast) {
                                    Qibbs.showToast('图片正在上传中，请稍候...', 'warning');
                                }
                                e.preventDefault();
                                return false;
                            }
                            var richContent = tinymce.get('qibbs_rich_content').getContent();
                            if (richContent.indexOf('data:image/') !== -1) {
                                if (typeof Qibbs !== 'undefined' && Qibbs.showToast) {
                                    Qibbs.showToast('检测到未完成上传的粘贴图片，请稍候或重新粘贴', 'warning');
                                }
                                e.preventDefault();
                                return false;
                            }
                            $('#qibbs-post-content').val(richContent);
                        }
                    });

                    // 监听发帖成功事件：清空 TinyMCE 富文本内容与 mediaIds 临时状态
                    document.addEventListener('qibbs:post:created', function() {
                        if (typeof tinymce !== 'undefined' && tinymce.get('qibbs_rich_content')) {
                            tinymce.get('qibbs_rich_content').setContent('');
                        }
                        window.qibbsRichMediaIds = [];
                    });

                    // 清除草稿时同步清空富文本编辑器
                    $('#qibbs-draft-clear').on('click', function() {
                        if (typeof tinymce !== 'undefined' && tinymce.get('qibbs_rich_content')) {
                            tinymce.get('qibbs_rich_content').setContent('');
                        }
                        window.qibbsRichMediaIds = [];
                    });
                });
                </script>
            <?php endif; ?>

            <div class="qibbs-filter-bar" id="qibbs-filter-bar" style="display:none;">
                <span class="qibbs-filter-label"></span>
                <button class="qibbs-filter-clear" id="qibbs-filter-clear">&times; <?php esc_html_e( '清除筛选', 'qibbs-community' ); ?></button>
            </div>

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
                    <div class="qibbs-user-cover"></div>
                    <a href="<?php echo esc_url( qibbs_get_profile_url( get_current_user_id() ) ); ?>" class="qibbs-user-avatar">
                        <img src="<?php echo esc_url( get_avatar_url( get_current_user_id(), array( 'size' => 80 ) ) ); ?>" alt="">
                    </a>
                    <a href="<?php echo esc_url( qibbs_get_profile_url( get_current_user_id() ) ); ?>" class="qibbs-user-name">
                        <?php echo esc_html( $current_user->display_name ); ?>
                    </a>
                    <div class="qibbs-user-card-badges-row">
                        <?php if ( function_exists( 'qibbs_render_cuid_badge' ) ) : ?>
                            <?php echo qibbs_render_cuid_badge( get_current_user_id() ); ?>
                        <?php endif; ?>
                        <?php if ( function_exists( 'qibbs_render_user_level_badge' ) ) : ?>
                            <?php echo qibbs_render_user_level_badge( get_current_user_id() ); ?>
                        <?php endif; ?>
                    </div>
                    <?php if ( function_exists( 'qibbs_render_user_level_progress' ) && function_exists( 'qibbs_is_growth_levels_enabled' ) && qibbs_is_growth_levels_enabled() ) : ?>
                        <div class="qibbs-user-card-progress-wrap">
                            <?php echo qibbs_render_user_level_progress( get_current_user_id(), false, false ); ?>
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
                    <div class="qibbs-login-icon">
                        <svg viewBox="0 0 24 24" width="48" height="48"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>
                    </div>
                    <h3><?php esc_html_e( '加入社区', 'qibbs-community' ); ?></h3>
                    <p><?php esc_html_e( '登录后参与互动分享', 'qibbs-community' ); ?></p>
                    <?php echo Qibbs_Theme_Compatibility::get_login_button_html( 'qibbs-btn qibbs-btn-primary qibbs-btn-block' ); ?>
                </div>
            <?php endif; ?>

            <?php
            // 每日签到打卡小工具（直接调用启灵商城底层能力）
            if ( function_exists( 'qibbs_is_checkin_widget_enabled' ) && qibbs_is_checkin_widget_enabled() ) {
                $checkin_is_mobile = false;
                include QIBBS_PLUGIN_DIR . 'templates/widget-checkin.php';
            }
            ?>

            <div class="qibbs-widget qibbs-style-switch-widget">
                <div class="qibbs-style-switch-wrap">
                    <button type="button" class="qibbs-style-switch-toggle" id="qibbs-theme-style-toggle">
                        <span><?php esc_html_e( '界面风格', 'qibbs-community' ); ?></span>
                        <strong id="qibbs-theme-style-current"><?php esc_html_e( '默认', 'qibbs-community' ); ?></strong>
                        <svg viewBox="0 0 24 24" width="16" height="16"><path d="M7 10l5 5 5-5z"/></svg>
                    </button>
                    <div class="qibbs-style-switch-panel" id="qibbs-theme-style-panel">
                        <button type="button" class="qibbs-style-option" data-theme-style="default"><span class="qibbs-style-dot qibbs-style-dot-default" aria-hidden="true"></span><?php esc_html_e( '默认风格', 'qibbs-community' ); ?></button>
                        <button type="button" class="qibbs-style-option" data-theme-style="fresh"><span class="qibbs-style-dot qibbs-style-dot-fresh" aria-hidden="true"></span><?php esc_html_e( '小清新', 'qibbs-community' ); ?></button>
                        <button type="button" class="qibbs-style-option" data-theme-style="tech"><span class="qibbs-style-dot qibbs-style-dot-tech" aria-hidden="true"></span><?php esc_html_e( '科技', 'qibbs-community' ); ?></button>
                        <button type="button" class="qibbs-style-option" data-theme-style="gaming"><span class="qibbs-style-dot qibbs-style-dot-gaming" aria-hidden="true"></span><?php esc_html_e( '游戏', 'qibbs-community' ); ?></button>
                    </div>
                </div>
            </div>

            <?php 
            // 搜索框（若启用）
            if ( qibbs_get_option( 'enable_search', false ) ) : 
            ?>
            <div class="qibbs-widget qibbs-search-widget">
                <div class="qibbs-search-box">
                    <input type="text" id="qibbs-search-input" placeholder="<?php esc_attr_e( '搜索动态...', 'qibbs-community' ); ?>" autocomplete="off">
                    <button type="button" id="qibbs-search-btn">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
                    </button>
                </div>
                <div class="qibbs-search-results" id="qibbs-search-results" style="display:none;"></div>
                <form class="qibbs-advanced-search-form" id="qibbs-advanced-search-form">
                    <div class="qibbs-advanced-search-header">
                        <span><?php esc_html_e( '高级筛选', 'qibbs-community' ); ?></span>
                    </div>
                    <div class="qibbs-advanced-search-grid">
                        <input type="text" id="qibbs-search-author" placeholder="<?php esc_attr_e( '作者昵称', 'qibbs-community' ); ?>">
                        <input type="text" id="qibbs-search-tag" placeholder="<?php esc_attr_e( '标签名', 'qibbs-community' ); ?>">
                        <select id="qibbs-search-post-type">
                            <option value="all"><?php esc_html_e( '全部类型', 'qibbs-community' ); ?></option>
                            <?php foreach ( qibbs_get_post_type_options() as $post_type_key => $post_type_config ) : ?>
                                <option value="<?php echo esc_attr( $post_type_key ); ?>"><?php echo esc_html( $post_type_config['label'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="qibbs-search-resolved">
                            <option value="all"><?php esc_html_e( '全部问答状态', 'qibbs-community' ); ?></option>
                            <option value="open"><?php esc_html_e( '待解决', 'qibbs-community' ); ?></option>
                            <option value="resolved"><?php esc_html_e( '已解决', 'qibbs-community' ); ?></option>
                        </select>
                        <select id="qibbs-search-sort">
                            <option value="latest"><?php esc_html_e( '最新发布', 'qibbs-community' ); ?></option>
                            <option value="hot"><?php esc_html_e( '综合热度', 'qibbs-community' ); ?></option>
                            <option value="comments"><?php esc_html_e( '评论最多', 'qibbs-community' ); ?></option>
                            <option value="likes"><?php esc_html_e( '点赞最多', 'qibbs-community' ); ?></option>
                        </select>
                        <?php if ( qibbs_get_option( 'enable_featured_posts', false ) ) : ?>
                            <label class="qibbs-advanced-search-check">
                                <input type="checkbox" id="qibbs-search-featured-only" value="1">
                                <span><?php esc_html_e( '只看推荐', 'qibbs-community' ); ?></span>
                            </label>
                        <?php endif; ?>
                    </div>
                    <div class="qibbs-advanced-search-actions">
                        <button type="submit" class="qibbs-advanced-search-submit"><?php esc_html_e( '应用筛选', 'qibbs-community' ); ?></button>
                        <button type="button" class="qibbs-advanced-search-reset" id="qibbs-advanced-search-reset"><?php esc_html_e( '重置', 'qibbs-community' ); ?></button>
                    </div>
                </form>
            </div>
            <style>
            .qibbs-search-widget {
                padding: 16px;
            }
            .qibbs-search-box {
                display: flex;
                gap: 8px;
            }
            .qibbs-search-box input {
                flex: 1;
                padding: 10px 14px;
                border: 1px solid var(--qibbs-border);
                border-radius: var(--qibbs-radius-sm);
                font-size: 14px;
                background: var(--qibbs-bg);
                color: var(--qibbs-text);
                transition: border-color 0.2s;
            }
            .qibbs-search-box input:focus {
                outline: none;
                border-color: var(--qibbs-primary);
            }
            .qibbs-search-box button {
                padding: 10px 14px;
                background: linear-gradient(135deg, var(--qibbs-primary), var(--qibbs-secondary));
                border: none;
                border-radius: var(--qibbs-radius-sm);
                color: white;
                cursor: pointer;
                transition: opacity 0.2s;
            }
            .qibbs-search-box button:hover {
                opacity: 0.9;
            }
            .qibbs-search-results {
                margin-top: 12px;
                max-height: 300px;
                overflow-y: auto;
            }
            .qibbs-advanced-search-form {
                margin-top: 14px;
                padding-top: 14px;
                border-top: 1px solid var(--qibbs-border-light);
            }
            .qibbs-advanced-search-header {
                margin-bottom: 10px;
                font-size: 13px;
                font-weight: 600;
                color: var(--qibbs-text-secondary);
            }
            .qibbs-advanced-search-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }
            .qibbs-advanced-search-grid input,
            .qibbs-advanced-search-grid select {
                width: 100%;
                padding: 10px 12px;
                border: 1px solid var(--qibbs-border);
                border-radius: var(--qibbs-radius-sm);
                font-size: 13px;
                background: var(--qibbs-bg);
                color: var(--qibbs-text);
            }
            .qibbs-advanced-search-check {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 10px 12px;
                border: 1px solid var(--qibbs-border);
                border-radius: var(--qibbs-radius-sm);
                font-size: 13px;
                color: var(--qibbs-text-secondary);
                background: var(--qibbs-bg);
            }
            .qibbs-advanced-search-actions {
                display: flex;
                gap: 10px;
                margin-top: 12px;
            }
            .qibbs-advanced-search-submit,
            .qibbs-advanced-search-reset {
                flex: 1;
                border: none;
                border-radius: var(--qibbs-radius-sm);
                padding: 10px 14px;
                font-size: 13px;
                cursor: pointer;
            }
            .qibbs-advanced-search-submit {
                background: linear-gradient(135deg, var(--qibbs-primary), var(--qibbs-secondary));
                color: #fff;
            }
            .qibbs-advanced-search-reset {
                background: var(--qibbs-bg-hover);
                color: var(--qibbs-text-secondary);
            }
            .qibbs-search-result-item {
                display: block;
                padding: 12px;
                border-bottom: 1px solid var(--qibbs-border-light);
                text-decoration: none;
                color: var(--qibbs-text);
                transition: background 0.2s;
            }
            .qibbs-search-result-item:hover {
                background: var(--qibbs-bg-hover);
            }
            .qibbs-search-result-item:last-child {
                border-bottom: none;
            }
            .qibbs-search-result-author {
                font-weight: 600;
                font-size: 13px;
                color: var(--qibbs-primary);
            }
            .qibbs-search-result-content {
                font-size: 13px;
                color: var(--qibbs-text-secondary);
                margin-top: 4px;
                line-height: 1.4;
            }
            .qibbs-search-result-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                margin-top: 6px;
            }
            .qibbs-search-result-badge {
                display: inline-flex;
                align-items: center;
                padding: 2px 8px;
                border-radius: 999px;
                background: var(--qibbs-bg-hover);
                color: var(--qibbs-text-muted);
                font-size: 12px;
                line-height: 1.5;
            }
            .qibbs-search-empty {
                padding: 16px;
                text-align: center;
                color: var(--qibbs-text-muted);
                font-size: 13px;
            }
            @media (max-width: 767px) {
                .qibbs-advanced-search-grid {
                    grid-template-columns: 1fr;
                }
            }
            </style>
            <?php endif; ?>


            <?php 
            // 热门话题（若启用）
            if ( qibbs_get_option( 'show_hot_topics', true ) ) : 
                $hot_topic_count = qibbs_get_option( 'hot_topic_count', 10 );
            ?>
            <div class="qibbs-widget qibbs-hot-tags">
                <h3 class="qibbs-widget-title">
                    <svg viewBox="0 0 24 24" width="18" height="18"><path d="M17.66 11.2C17.43 10.9 17.15 10.64 16.89 10.38C16.22 9.78 15.46 9.35 14.82 8.72C13.33 7.26 13 4.85 13.95 3C13 3.23 12.17 3.75 11.46 4.32C8.87 6.4 7.85 10.07 9.07 13.22C9.11 13.32 9.15 13.42 9.15 13.55C9.15 13.77 9 13.97 8.8 14.05C8.57 14.15 8.33 14.09 8.14 13.93C8.08 13.88 8.04 13.83 8 13.76C6.87 12.33 6.69 10.28 7.45 8.64C5.78 10 4.87 12.3 5 14.47C5.06 14.97 5.12 15.47 5.29 15.97C5.43 16.57 5.7 17.17 6 17.7C7.08 19.43 8.95 20.67 10.96 20.92C13.1 21.19 15.39 20.8 17.03 19.32C18.86 17.66 19.5 15 18.56 12.72L18.43 12.46C18.22 12 17.66 11.2 17.66 11.2Z"/></svg>
                    <?php esc_html_e( '热门话题', 'qibbs-community' ); ?>
                </h3>
                <div class="qibbs-tags-cloud" id="qibbs-hot-tags">
                    <?php
                    // 从数据表获取热门标签
                    $tags_table = Qibbs_Database::tags();
                    $hot_tags = class_exists( 'Qibbs_Cache_Service' )
                        ? Qibbs_Cache_Service::get_hot_tags( $hot_topic_count )
                        : $wpdb->get_results( $wpdb->prepare(
                            "SELECT id, name, slug, post_count FROM {$tags_table} ORDER BY post_count DESC, id DESC LIMIT %d",
                            $hot_topic_count
                        ) );
                    
                    if ( ! empty( $hot_tags ) ) :
                        foreach ( $hot_tags as $tag ) :
                            ?>
                            <a href="#" class="qibbs-tag-item" data-filter="<?php echo esc_attr( $tag->id ); ?>" data-filter-type="tag" data-filter-name="<?php echo esc_attr( $tag->name ); ?>">
                                <?php echo esc_html( $tag->name ); ?>
                            </a>
                            <?php
                        endforeach;
                    else:
                        echo '<p class="qibbs-empty-small">' . esc_html__( '暂无热门话题', 'qibbs-community' ) . '</p>';
                    endif;
                    ?>
                </div>
            </div>
            <?php endif; ?>

            <?php 
            // 社区统计数据（若启用）
            if ( qibbs_get_option( 'show_community_stats', true ) ) : 
                global $wpdb;
                if ( class_exists( 'Qibbs_Cache_Service' ) ) {
                    $community_stats = Qibbs_Cache_Service::get_community_stats();
                    $total_posts = isset( $community_stats['total_posts'] ) ? (int) $community_stats['total_posts'] : 0;
                    $total_users = isset( $community_stats['total_users'] ) ? (int) $community_stats['total_users'] : 0;
                    $total_comments = isset( $community_stats['total_comments'] ) ? (int) $community_stats['total_comments'] : 0;
                } else {
                    $posts_table = Qibbs_Database::posts();
                    $comments_table = Qibbs_Database::comments();
                    $total_posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts_table} WHERE status = %s", 'publish' ) );
                    $total_users = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE %d = %d", 1, 1 ) );
                    $total_comments = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$comments_table} WHERE status = %s", 'approved' ) );
                }
            ?>
            <div class="qibbs-widget qibbs-community-stats">
                <h3 class="qibbs-widget-title">
                    <svg viewBox="0 0 24 24" width="18" height="18"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>
                    <?php esc_html_e( '社区统计', 'qibbs-community' ); ?>
                </h3>
                <div class="qibbs-stats-grid">
                    <div class="qibbs-stats-item">
                        <span class="qibbs-stats-value"><?php echo number_format( $total_posts ); ?></span>
                        <span class="qibbs-stats-label"><?php esc_html_e( '动态', 'qibbs-community' ); ?></span>
                    </div>
                    <div class="qibbs-stats-item">
                        <span class="qibbs-stats-value"><?php echo number_format( $total_users ); ?></span>
                        <span class="qibbs-stats-label"><?php esc_html_e( '用户', 'qibbs-community' ); ?></span>
                    </div>
                    <div class="qibbs-stats-item">
                        <span class="qibbs-stats-value"><?php echo number_format( $total_comments ); ?></span>
                        <span class="qibbs-stats-label"><?php esc_html_e( '评论', 'qibbs-community' ); ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php 
            // 渲染右侧栏自定义小工具区域
            if ( is_active_sidebar( 'qibbs-sidebar' ) ) :
            ?>
            <div class="qibbs-widget qibbs-custom-widgets">
                <?php dynamic_sidebar( 'qibbs-sidebar' ); ?>
            </div>
            <?php endif; ?>

            <?php do_action( 'qibbs_sidebar_content' ); ?>
        </aside>
    </div>
</div>

<!-- 移动端底部操作栏 -->
<?php
$mobile_checkin_enabled = function_exists( 'qibbs_is_checkin_widget_enabled' ) && qibbs_is_checkin_widget_enabled();
$mobile_needs_checkin   = false;
if ( $mobile_checkin_enabled && is_user_logged_in() && function_exists( 'qibbs_get_user_checkin_status' ) ) {
    $mobile_checkin_status = qibbs_get_user_checkin_status( get_current_user_id() );
    $mobile_needs_checkin  = empty( $mobile_checkin_status['checked_in'] );
}
?>
<nav class="qibbs-mobile-nav qibbs-mobile-action-bar" aria-label="<?php esc_attr_e( '移动端操作栏', 'qibbs-community' ); ?>">
    <a href="#" class="qibbs-mobile-nav-item active" data-filter="global" data-filter-type="feed">
        <svg viewBox="0 0 24 24" width="24" height="24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
        <span><?php esc_html_e( '首页', 'qibbs-community' ); ?></span>
    </a>

    <button type="button" class="qibbs-mobile-nav-item" data-mobile-action="open-sheet">
        <svg viewBox="0 0 24 24" width="24" height="24"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg>
        <span><?php esc_html_e( '功能', 'qibbs-community' ); ?></span>
        <?php if ( $mobile_needs_checkin ) : ?>
            <span class="qibbs-mobile-checkin-dot" aria-label="<?php esc_attr_e( '今日待打卡', 'qibbs-community' ); ?>"></span>
        <?php endif; ?>
    </button>

    <button type="button" class="qibbs-mobile-nav-item qibbs-mobile-nav-item-primary" data-mobile-action="compose">
        <svg viewBox="0 0 24 24" width="26" height="26"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
        <span><?php esc_html_e( '发布', 'qibbs-community' ); ?></span>
    </button>

    <?php if ( is_user_logged_in() ) : ?>
    <a href="<?php echo esc_url( qibbs_get_messages_url() ); ?>" class="qibbs-mobile-nav-item">
        <svg viewBox="0 0 24 24" width="24" height="24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
        <span><?php esc_html_e( '私信', 'qibbs-community' ); ?></span>
        <span class="qibbs-badge qibbs-mobile-badge" id="qibbs-mobile-message-badge"></span>
    </a>
    <?php else : ?>
    <button type="button"
            class="qibbs-mobile-nav-item qibbs-login-modal-trigger"
            data-fallback-url="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
        <svg viewBox="0 0 24 24" width="24" height="24"><path d="M10 17l5-5-5-5v3H1v4h9v3zm10-14H8a2 2 0 0 0-2 2v4h2V5h12v14H8v-4H6v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/></svg>
        <span><?php esc_html_e( '登录', 'qibbs-community' ); ?></span>
    </button>
    <?php endif; ?>

    <button type="button" class="qibbs-mobile-nav-item" data-mobile-action="open-style">
        <svg viewBox="0 0 24 24" width="24" height="24"><path d="M12 3a9 9 0 1 0 9 9A9.01 9.01 0 0 0 12 3zm0 2a7 7 0 0 1 6.93 6H12zm-7 7a7 7 0 0 1 7-7v14a7 7 0 0 1-7-7zm9 6.93V12h4.93A7 7 0 0 1 14 18.93z"/></svg>
        <span><?php esc_html_e( '风格', 'qibbs-community' ); ?></span>
    </button>
</nav>

<div class="qibbs-mobile-sheet-backdrop" id="qibbs-mobile-sheet-backdrop" hidden></div>
<section class="qibbs-mobile-sheet" id="qibbs-mobile-sheet" aria-hidden="true">
    <div class="qibbs-mobile-sheet-header">
        <h3><?php esc_html_e( '功能中心', 'qibbs-community' ); ?></h3>
        <button type="button" class="qibbs-mobile-sheet-close" data-mobile-action="close-sheet" aria-label="<?php esc_attr_e( '关闭', 'qibbs-community' ); ?>">&times;</button>
    </div>

    <div class="qibbs-mobile-sheet-body">
        <?php if ( $mobile_checkin_enabled ) : ?>
        <div class="qibbs-mobile-sheet-group qibbs-mobile-checkin-group">
            <?php
            $checkin_is_mobile = true;
            include QIBBS_PLUGIN_DIR . 'templates/widget-checkin.php';
            ?>
        </div>
        <?php endif; ?>
        <div class="qibbs-mobile-sheet-group" id="qibbs-mobile-style-group">
            <h4><?php esc_html_e( '界面风格', 'qibbs-community' ); ?></h4>
            <div class="qibbs-mobile-sheet-grid">
                <button type="button" class="qibbs-mobile-sheet-btn qibbs-style-option" data-theme-style="default"><span class="qibbs-style-dot qibbs-style-dot-default" aria-hidden="true"></span><?php esc_html_e( '默认风格', 'qibbs-community' ); ?></button>
                <button type="button" class="qibbs-mobile-sheet-btn qibbs-style-option" data-theme-style="fresh"><span class="qibbs-style-dot qibbs-style-dot-fresh" aria-hidden="true"></span><?php esc_html_e( '小清新', 'qibbs-community' ); ?></button>
                <button type="button" class="qibbs-mobile-sheet-btn qibbs-style-option" data-theme-style="tech"><span class="qibbs-style-dot qibbs-style-dot-tech" aria-hidden="true"></span><?php esc_html_e( '科技', 'qibbs-community' ); ?></button>
                <button type="button" class="qibbs-mobile-sheet-btn qibbs-style-option" data-theme-style="gaming"><span class="qibbs-style-dot qibbs-style-dot-gaming" aria-hidden="true"></span><?php esc_html_e( '游戏', 'qibbs-community' ); ?></button>
            </div>
        </div>

        <div class="qibbs-mobile-sheet-group">
            <h4><?php esc_html_e( '系统入口', 'qibbs-community' ); ?></h4>
            <div class="qibbs-mobile-sheet-grid">
                <?php if ( qibbs_is_growth_levels_enabled() && qibbs_get_option( 'show_nav_growth', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_growth_center_url() ); ?>" class="qibbs-mobile-sheet-btn" data-filter="growth-center" data-filter-type="view" data-mobile-close-sheet="1">
                        <?php esc_html_e( '成长中心', 'qibbs-community' ); ?>
                    </a>
                <?php endif; ?>
                <?php if ( qibbs_is_module_enabled( 'vanity_mall' ) && qibbs_get_option( 'show_nav_mall', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_vanity_mall_url() ); ?>" class="qibbs-mobile-sheet-btn" data-filter="vanity-mall" data-filter-type="view" data-mobile-close-sheet="1">
                        <?php esc_html_e( '交易大厅', 'qibbs-community' ); ?>
                    </a>
                <?php endif; ?>
                <?php if ( function_exists( 'qibbs_is_prop_mall_enabled' ) && qibbs_is_prop_mall_enabled() && qibbs_get_option( 'show_nav_prop_mall', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_prop_mall_url() ); ?>" class="qibbs-mobile-sheet-btn" data-filter="prop-mall" data-filter-type="view" data-mobile-close-sheet="1">
                        <?php esc_html_e( '道具大厅', 'qibbs-community' ); ?>
                    </a>
                <?php endif; ?>
                <?php if ( is_user_logged_in() ) : ?>
                    <button type="button" class="qibbs-mobile-sheet-btn qibbs-mobile-sheet-btn-primary" data-mobile-action="compose">
                        <?php esc_html_e( '发布动态', 'qibbs-community' ); ?>
                    </button>
                    <?php if ( qibbs_get_option( 'show_nav_notifications', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_notifications_url() ); ?>" class="qibbs-mobile-sheet-btn">
                        <?php esc_html_e( '通知', 'qibbs-community' ); ?>
                        <span class="qibbs-badge qibbs-mobile-badge" id="qibbs-mobile-notification-badge"></span>
                    </a>
                    <?php endif; ?>
                    <?php if ( qibbs_get_option( 'show_nav_messages', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_messages_url() ); ?>" class="qibbs-mobile-sheet-btn"><?php esc_html_e( '私信', 'qibbs-community' ); ?></a>
                    <?php endif; ?>
                    <?php if ( qibbs_get_option( 'show_nav_profile', true ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_profile_url( get_current_user_id() ) ); ?>" class="qibbs-mobile-sheet-btn"><?php esc_html_e( '我的主页', 'qibbs-community' ); ?></a>
                    <?php endif; ?>
                <?php else : ?>
                    <?php echo Qibbs_Theme_Compatibility::get_login_button_html( 'qibbs-mobile-sheet-btn qibbs-mobile-sheet-btn-primary', __( '登录后互动', 'qibbs-community' ) ); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="qibbs-mobile-sheet-group">
            <h4><?php esc_html_e( '内容筛选', 'qibbs-community' ); ?></h4>
            <div class="qibbs-mobile-sheet-grid">
                <button type="button" class="qibbs-mobile-sheet-btn" data-filter="global" data-filter-type="feed" data-mobile-close-sheet="1"><?php esc_html_e( '最新动态', 'qibbs-community' ); ?></button>
                <?php if ( is_user_logged_in() && qibbs_get_option( 'show_nav_following', true ) ) : ?>
                    <button type="button" class="qibbs-mobile-sheet-btn" data-filter="following" data-filter-type="feed" data-mobile-close-sheet="1"><?php esc_html_e( '关注动态', 'qibbs-community' ); ?></button>
                <?php endif; ?>
                <?php if ( qibbs_get_option( 'show_hot_posts', false ) ) : ?>
                    <button type="button" class="qibbs-mobile-sheet-btn" data-filter="hot" data-filter-type="feed" data-mobile-close-sheet="1"><?php esc_html_e( '热门动态', 'qibbs-community' ); ?></button>
                <?php endif; ?>
                <?php if ( qibbs_get_option( 'enable_featured_posts', false ) ) : ?>
                    <button type="button" class="qibbs-mobile-sheet-btn" data-filter="featured" data-filter-type="feed" data-mobile-close-sheet="1"><?php esc_html_e( '推荐动态', 'qibbs-community' ); ?></button>
                <?php endif; ?>
                <?php if ( qibbs_get_option( 'show_voting_posts', false ) ) : ?>
                    <button type="button" class="qibbs-mobile-sheet-btn" data-filter="voting" data-filter-type="feed" data-mobile-close-sheet="1"><?php esc_html_e( '投票动态', 'qibbs-community' ); ?></button>
                <?php endif; ?>
                <?php if ( qibbs_get_option( 'show_redpacket_posts', true ) ) : ?>
                    <button type="button" class="qibbs-mobile-sheet-btn" data-filter="redpacket" data-filter-type="feed" data-mobile-close-sheet="1"><?php esc_html_e( '红包动态', 'qibbs-community' ); ?></button>
                <?php endif; ?>
                <?php if ( qibbs_get_option( 'show_bounty_posts', true ) ) : ?>
                    <button type="button" class="qibbs-mobile-sheet-btn" data-filter="bounty" data-filter-type="feed" data-mobile-close-sheet="1"><?php esc_html_e( '悬赏动态', 'qibbs-community' ); ?></button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ( ! empty( $forums ) ) : ?>
        <div class="qibbs-mobile-sheet-group">
            <h4><?php esc_html_e( '圈子速达', 'qibbs-community' ); ?></h4>
            <div class="qibbs-mobile-sheet-grid qibbs-mobile-sheet-grid-forums">
                <?php foreach ( array_slice( $forums, 0, 12 ) as $forum ) : ?>
                    <button type="button"
                            class="qibbs-mobile-sheet-btn"
                            data-filter="<?php echo esc_attr( (int) $forum->id ); ?>"
                            data-filter-type="forum"
                            data-filter-name="<?php echo esc_attr( $forum->name ); ?>"
                            data-mobile-close-sheet="1">
                        <?php echo esc_html( $forum->name ); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php 
$interactive_modals = QIBBS_PLUGIN_DIR . 'templates/modals-interactive.php';
if ( file_exists( $interactive_modals ) ) {
    include $interactive_modals;
}
get_footer(); 
?>
