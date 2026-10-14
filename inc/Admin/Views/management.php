<?php
/**
 * 视图模板：社区数据概览与管理中心
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'posts';
        ?>
        <div class="wrap qibbs-admin-page">
            <h1><?php _e( '社区圈子管理', 'qibbs-community' ); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=qibbs-management&tab=posts" class="nav-tab <?php echo $active_tab === 'posts' ? 'nav-tab-active' : ''; ?>">
                    <?php _e( '动态管理', 'qibbs-community' ); ?>
                </a>
                <a href="edit-tags.php?taxonomy=qibbs_forum&post_type=qibbs_post" class="nav-tab">
                    <?php _e( '圈子管理', 'qibbs-community' ); ?>
                </a>
                <a href="edit-tags.php?taxonomy=qibbs_tag&post_type=qibbs_post" class="nav-tab">
                    <?php _e( '标签管理', 'qibbs-community' ); ?>
                </a>
            </nav>
            
            <div class="tab-content" style="margin-top: 20px;">
                <div class="notice notice-warning" style="margin:0 0 16px;">
                    <p><?php esc_html_e( '旧版“动态管理”入口已切换为只读引导页。为了保证回收站、治理日志、通知回执、标签/圈子计数与看板数据一致，请改用新的“审核队列/社区治理”处理内容。', 'qibbs-community' ); ?></p>
                </div>

                <?php if ( 'posts' === $active_tab ) : ?>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-review-queue&tab=posts' ) ); ?>">
                            <?php esc_html_e( '进入待审动态', 'qibbs-community' ); ?>
                        </a>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-review-queue&tab=live_posts' ) ); ?>">
                            <?php esc_html_e( '进入已发布治理', 'qibbs-community' ); ?>
                        </a>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-review-queue&tab=posts_trash' ) ); ?>">
                            <?php esc_html_e( '进入动态回收站', 'qibbs-community' ); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
