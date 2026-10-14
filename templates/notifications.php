<?php
/**
 * 消息通知页面模板
 *
 * @package Qibbs_Community
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 需登录访问
if ( ! is_user_logged_in() ) {
    $access_template = QIBBS_PLUGIN_DIR . 'templates/access-restricted.php';
    if ( file_exists( $access_template ) ) {
        include $access_template;
        return;
    }
    wp_die( esc_html__( '当前功能仅限已登录用户访问。', 'qibbs-community' ) );
}

if ( ! qibbs_is_module_enabled( 'notifications' ) ) {
    $access_template = QIBBS_PLUGIN_DIR . 'templates/access-restricted.php';
    if ( file_exists( $access_template ) ) {
        $GLOBALS['qibbs_access_restricted_context'] = array(
            'type'       => 'guest',
            'title'      => qibbs_get_module_disabled_message( 'notifications' ),
            'text'       => __( '站点当前已关闭通知功能。', 'qibbs-community' ),
            'show_login' => false,
        );
        include $access_template;
        unset( $GLOBALS['qibbs_access_restricted_context'] );
        return;
    }

    wp_die( esc_html( qibbs_get_module_disabled_message( 'notifications' ) ) );
}

get_header();

// 获取主题预设风格属性
$layout_service = new Qibbs_Layout_Service();
$theme_attr = $layout_service->get_theme_attribute();
?>

<div class="qibbs-wrapper"<?php echo $theme_attr; ?>>
    <div class="qibbs-layout qibbs-three-column">
        <nav class="qibbs-nav-sidebar">
            <div class="qibbs-nav-menu">
                <a href="<?php echo esc_url( qibbs_get_community_url() ); ?>" class="qibbs-nav-item">
                    <svg viewBox="0 0 24 24" width="24" height="24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                    <span><?php esc_html_e( '首页', 'qibbs-community' ); ?></span>
                </a>
                <?php if ( qibbs_is_growth_levels_enabled() ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_growth_center_url() ); ?>" class="qibbs-nav-item">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                            <path d="M19 5h-2V3H7v2H5c-1.1 0-2 .9-2 2v1c0 2.55 1.92 4.63 4.39 4.94A5.01 5.01 0 0 0 11 15.9V19H7v2h10v-2h-4v-3.1c2.3-.41 4.09-2.19 4.61-4.96C20.08 10.63 22 8.55 22 8V7c0-1.1-.9-2-2-2zM5 8V7h2v3.82C5.84 10.4 5 9.3 5 8zm14 0c0 1.3-.84 2.4-2 2.82V7h2v1z"/>
                        </svg>
                        <span><?php esc_html_e( '成长中心', 'qibbs-community' ); ?></span>
                    </a>
                <?php endif; ?>
                <?php if ( qibbs_is_module_enabled( 'vanity_mall' ) ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_vanity_mall_url() ); ?>" class="qibbs-nav-item">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                            <path d="M12 2L2 9.21L6 21H18L22 9.21L12 2ZM12 5.56L17.24 9.35L15.34 19H8.66L6.76 9.35L12 5.56Z"/>
                        </svg>
                        <span><?php esc_html_e( '交易大厅', 'qibbs-community' ); ?></span>
                    </a>
                <?php endif; ?>
                <?php if ( function_exists( 'qibbs_is_prop_mall_enabled' ) && qibbs_is_prop_mall_enabled() ) : ?>
                    <a href="<?php echo esc_url( qibbs_get_prop_mall_url() ); ?>" class="qibbs-nav-item">
                        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                            <path d="M7.5 5.6L5 7l1.4-2.5L5 2l2.5 1.4L10 2 8.6 4.5 10 7 7.5 5.6zm12 9.8L17 14l1.4 2.5L17 19l2.5-1.4L22 19l-1.4-2.5L22 14l-2.5 1.4zM22 2l-2.5 1.4L17 2l1.4 2.5L17 7l2.5-1.4L22 7l-1.4-2.5L22 2zm-7.63 5.29a.996.996 0 0 0-1.41 0L1.29 18.96a.996.996 0 0 0 0 1.41l2.34 2.34c.39.39 1.02.39 1.41 0L16.7 11.05a.996.996 0 0 0 0-1.41l-2.33-2.35zm-1.03 5.49l-2.12-2.12 2.44-2.44 2.12 2.12-2.44 2.44z"/>
                        </svg>
                        <span><?php esc_html_e( '道具大厅', 'qibbs-community' ); ?></span>
                    </a>
                <?php endif; ?>
                <a href="<?php echo esc_url( qibbs_get_notifications_url() ); ?>" class="qibbs-nav-item active">
                    <svg viewBox="0 0 24 24" width="24" height="24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
                    <span><?php esc_html_e( '通知', 'qibbs-community' ); ?></span>
                </a>
                <a href="<?php echo esc_url( qibbs_get_messages_url() ); ?>" class="qibbs-nav-item">
                    <svg viewBox="0 0 24 24" width="24" height="24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                    <span><?php esc_html_e( '私信', 'qibbs-community' ); ?></span>
                </a>
                <a href="<?php echo esc_url( qibbs_get_profile_url( get_current_user_id() ) ); ?>" class="qibbs-nav-item">
                    <svg viewBox="0 0 24 24" width="24" height="24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                    <span><?php esc_html_e( '我的', 'qibbs-community' ); ?></span>
                </a>
            </div>
        </nav>

        <main class="qibbs-main" id="qibbs-main">
            <div class="qibbs-page-header">
                <h1 class="qibbs-page-title"><?php esc_html_e( '通知', 'qibbs-community' ); ?></h1>
                <button class="qibbs-btn qibbs-btn-text" id="qibbs-mark-all-read">
                    <?php esc_html_e( '全部标记为已读', 'qibbs-community' ); ?>
                </button>
            </div>

            <div class="qibbs-notifications-list" id="qibbs-notifications-list">
                <div class="qibbs-loading"><?php esc_html_e( '加载中...', 'qibbs-community' ); ?></div>
            </div>

            <div class="qibbs-load-more" id="qibbs-load-more" style="display:none;">
                <button class="qibbs-load-more-btn"><?php esc_html_e( '加载更多', 'qibbs-community' ); ?></button>
            </div>
        </main>

        <aside class="qibbs-sidebar">
            <?php
            $current_user = wp_get_current_user();
            $profile_service = new Qibbs_Profile_Service();
            $stats = $profile_service->get_user_stats( get_current_user_id() );
            $notification_service = new Qibbs_Notification_Service();
            $preference_items = $notification_service->get_user_preferences( get_current_user_id() );
            ?>
            <div class="qibbs-widget qibbs-user-card">
                <a href="<?php echo esc_url( qibbs_get_profile_url( get_current_user_id() ) ); ?>" class="qibbs-user-avatar">
                    <img src="<?php echo esc_url( get_avatar_url( get_current_user_id(), array( 'size' => 64 ) ) ); ?>" alt="">
                </a>
                <a href="<?php echo esc_url( qibbs_get_profile_url( get_current_user_id() ) ); ?>" class="qibbs-user-name">
                    <?php echo esc_html( $current_user->display_name ); ?>
                </a>
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

            <div class="qibbs-widget qibbs-notification-preferences">
                <h3 class="qibbs-widget-title"><?php esc_html_e( '通知偏好中心', 'qibbs-community' ); ?></h3>
                <p class="qibbs-notification-pref-desc"><?php esc_html_e( '关闭某类提醒后，将不再收到对应通知。', 'qibbs-community' ); ?></p>
                <div class="qibbs-notification-preference-list" id="qibbs-notification-preference-list">
                    <?php foreach ( $preference_items as $item ) : ?>
                        <label class="qibbs-notification-pref-item">
                            <span class="qibbs-notification-pref-label"><?php echo esc_html( $item['label'] ); ?></span>
                            <span class="qibbs-notification-pref-switch">
                                <input
                                    type="checkbox"
                                    class="qibbs-pref-toggle"
                                    data-key="<?php echo esc_attr( $item['key'] ); ?>"
                                    <?php checked( ! empty( $item['enabled'] ) ); ?>
                                >
                                <span class="qibbs-switch-slider" aria-hidden="true"></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="qibbs-notification-pref-actions">
                    <button class="qibbs-btn qibbs-btn-primary qibbs-notification-pref-save" id="qibbs-save-notification-preferences"><?php esc_html_e( '保存偏好', 'qibbs-community' ); ?></button>
                    <span class="qibbs-notification-pref-status" id="qibbs-notification-pref-status" aria-live="polite"></span>
                </div>
            </div>
        </aside>
    </div>
</div>

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
        'urls'         => array(
            'community'     => qibbs_get_community_url(),
            'notifications' => qibbs_get_notifications_url(),
            'messages'      => qibbs_get_messages_url(),
            'profile'       => qibbs_get_profile_url( get_current_user_id() ),
        ),
        'strings'      => array(
            'loading'       => __( '加载中...', 'qibbs-community' ),
            'loadMore'      => __( '加载更多', 'qibbs-community' ),
            'noMore'        => __( '没有更多了', 'qibbs-community' ),
            'error'         => __( '操作失败', 'qibbs-community' ),
            'loginRequired' => __( '请先登录', 'qibbs-community' ),
            'save'          => __( '保存偏好', 'qibbs-community' ),
            'saving'        => __( '保存中...', 'qibbs-community' ),
            'saveSuccess'   => __( '通知偏好已保存', 'qibbs-community' ),
            'saveFailed'    => __( '保存失败，请稍后重试', 'qibbs-community' ),
        ),
    ) ); ?>;
}
(function($) {
    var page = 1;
    var loading = false;
    var hasMore = true;

    function escHtml(text) {
        return $('<div>').text(text == null ? '' : String(text)).html();
    }

    function safeUrl(url) {
        if (!url) {
            return '#';
        }
        try {
            var parsed = new URL(url, window.location.origin);
            if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
                return '#';
            }
            return escHtml(parsed.href);
        } catch (e) {
            return '#';
        }
    }

    function collectNotificationPreferences() {
        var prefs = {};
        $('#qibbs-notification-preference-list .qibbs-pref-toggle').each(function() {
            var $input = $(this);
            var key = $input.data('key');
            if (!key) {
                return;
            }
            prefs[key] = $input.is(':checked') ? '1' : '0';
        });
        return prefs;
    }

    function setNotificationPrefStatus(message, isError) {
        var $status = $('#qibbs-notification-pref-status');
        $status.text(message || '');
        $status.toggleClass('is-error', !!isError);
        $status.toggleClass('is-success', !isError && !!message);
    }

    function loadNotifications() {
        if (loading || !hasMore) return;
        loading = true;

        $.ajax({
            url: qibbsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'qibbs_get_notifications',
                nonce: qibbsData.nonce,
                page: page
            },
            success: function(response) {
                if (response.success) {
                    var notifications = response.data.notifications;
                    var $list = $('#qibbs-notifications-list');
                    
                    if (page === 1) {
                        $list.empty();
                    }

                    if (notifications.length === 0 && page === 1) {
                        $list.html('<div class="qibbs-empty"><?php esc_html_e( '暂无通知', 'qibbs-community' ); ?></div>');
                        hasMore = false;
                    } else {
                        notifications.forEach(function(notif) {
                            $list.append(renderNotification(notif));
                        });
                        
                        if (notifications.length < 20) {
                            hasMore = false;
                            $('#qibbs-load-more').hide();
                        } else {
                            $('#qibbs-load-more').show();
                        }
                        page++;
                    }
                }
                loading = false;
            },
            error: function(xhr, status, error) {
                loading = false;
                if (page === 1) {
                    $('#qibbs-notifications-list').html('<div class="qibbs-empty qibbs-empty-error"><?php esc_html_e( '加载失败，请刷新页面重试', 'qibbs-community' ); ?></div>');
                }
                console.error('Qibbs notifications load error:', status, error);
            }
        });
    }

    function renderNotification(notif) {
        var unreadClass = notif.is_read == '0' ? 'qibbs-notification-unread' : '';
        var statusBadge = notif.is_read == '0' 
            ? '<span class="qibbs-notification-badge qibbs-badge-unread">未读</span>' 
            : '<span class="qibbs-notification-badge qibbs-badge-read">已读</span>';
        
        // 生成内容预览片段
        var contentPreview = '';
        if (notif.content && notif.content.length > 0) {
            var previewText = notif.content.length > 20 ? notif.content.substring(0, 20) + '...' : notif.content;
            contentPreview = '<div class="qibbs-notification-preview">"' + escHtml(previewText) + '"</div>';
        }

        var actorId = notif.actor && notif.actor.id ? notif.actor.id : 0;
        var actorName = escHtml(notif.actor && notif.actor.display_name ? notif.actor.display_name : '');
        var actorAvatar = safeUrl(notif.actor && notif.actor.avatar ? notif.actor.avatar : '');
        var actorProfile = safeUrl(notif.actor && notif.actor.profile_url ? notif.actor.profile_url : '#');
        var notifLink = safeUrl(notif.link || '#');
        var typeLabel = escHtml(notif.type_label || '');
        var timeAgo = escHtml(notif.time_ago || '');
        
        return '<div class="qibbs-notification-item ' + unreadClass + '" data-id="' + notif.id + '">' +
            '<a href="' + actorProfile + '" class="qibbs-notification-avatar">' +
                '<img src="' + actorAvatar + '" alt="">' +
            '</a>' +
            '<div class="qibbs-notification-content">' +
                '<div class="qibbs-notification-header">' +
                    '<a href="' + notifLink + '" class="qibbs-notification-text">' +
                        '<strong>' + actorName + '</strong> ' + typeLabel +
                    '</a>' +
                    statusBadge +
                '</div>' +
                contentPreview +
                '<span class="qibbs-notification-time"' + ((notif.created_at_formatted || notif.created_at) ? ' title="' + escHtml(notif.created_at_formatted || notif.created_at) + '"' : '') + '>' + timeAgo + '</span>' +
            '</div>' +
        '</div>';
    }

    // 全部标记为已读
    $('#qibbs-mark-all-read').on('click', function() {
        $.ajax({
            url: qibbsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'qibbs_mark_notification_read',
                nonce: qibbsData.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('.qibbs-notification-unread').removeClass('qibbs-notification-unread');
                }
            }
        });
    });

    // 点击单条通知标记为已读
    $(document).on('click', '.qibbs-notification-item', function() {
        var $this = $(this);
        var id = $this.data('id');
        
        if ($this.hasClass('qibbs-notification-unread')) {
            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_mark_notification_read',
                    nonce: qibbsData.nonce,
                    notification_id: id
                }
            });
            $this.removeClass('qibbs-notification-unread');
            // 更新未读红点角标
            $this.find('.qibbs-badge-unread')
                .removeClass('qibbs-badge-unread')
                .addClass('qibbs-badge-read')
                .text('已读');
        }
    });

    // 加载更多通知
    $('#qibbs-load-more .qibbs-load-more-btn').on('click', function() {
        loadNotifications();
    });

    $('#qibbs-save-notification-preferences').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var prefs = collectNotificationPreferences();

        $btn.prop('disabled', true).text((qibbsData.strings && qibbsData.strings.saving) ? qibbsData.strings.saving : '保存中...');
        setNotificationPrefStatus('', false);

        $.ajax({
            url: qibbsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'qibbs_save_notification_preferences',
                nonce: qibbsData.nonce,
                preferences: prefs
            },
            success: function(response) {
                if (response && response.success && response.data && response.data.items) {
                    response.data.items.forEach(function(item) {
                        var $input = $('#qibbs-notification-preference-list .qibbs-pref-toggle[data-key="' + item.key + '"]');
                        if ($input.length) {
                            $input.prop('checked', !!item.enabled);
                        }
                    });
                    setNotificationPrefStatus(response.data.message || ((qibbsData.strings && qibbsData.strings.saveSuccess) ? qibbsData.strings.saveSuccess : '保存成功'), false);
                } else {
                    var msg = (response && response.data && response.data.message) ? response.data.message : ((qibbsData.strings && qibbsData.strings.saveFailed) ? qibbsData.strings.saveFailed : '保存失败');
                    setNotificationPrefStatus(msg, true);
                }
            },
            error: function() {
                setNotificationPrefStatus((qibbsData.strings && qibbsData.strings.saveFailed) ? qibbsData.strings.saveFailed : '保存失败', true);
            },
            complete: function() {
                $btn.prop('disabled', false).text((qibbsData.strings && qibbsData.strings.save) ? qibbsData.strings.save : '保存偏好');
            }
        });
    });

    // 页面初始加载
    loadNotifications();

})(jQuery);
</script>

<?php get_footer(); ?>
