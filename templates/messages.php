<?php
/**
 * 私信消息页面模板
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

get_header();
$current_user_id = get_current_user_id();
$partner_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;

// 获取主题预设风格属性
$layout_service = new Qibbs_Layout_Service();
$theme_attr = $layout_service->get_theme_attribute();
?>

<div class="qibbs-wrapper qibbs-wrapper-messages"<?php echo $theme_attr; ?>>
    <div class="qibbs-layout qibbs-two-column-messages">
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
                <a href="<?php echo esc_url( qibbs_get_notifications_url() ); ?>" class="qibbs-nav-item">
                    <svg viewBox="0 0 24 24" width="24" height="24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
                    <span><?php esc_html_e( '通知', 'qibbs-community' ); ?></span>
                </a>
                <a href="<?php echo esc_url( qibbs_get_messages_url() ); ?>" class="qibbs-nav-item active">
                    <svg viewBox="0 0 24 24" width="24" height="24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                    <span><?php esc_html_e( '私信', 'qibbs-community' ); ?></span>
                </a>
                <a href="<?php echo esc_url( qibbs_get_profile_url( get_current_user_id() ) ); ?>" class="qibbs-nav-item">
                    <svg viewBox="0 0 24 24" width="24" height="24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                    <span><?php esc_html_e( '我的', 'qibbs-community' ); ?></span>
                </a>
            </div>
        </nav>

        <main class="qibbs-main qibbs-messages-page" id="qibbs-main">
            <div class="qibbs-messages-container">
                <!-- 会话列表 -->
                <div class="qibbs-conversations-panel">
                    <div class="qibbs-panel-header">
                        <h2><?php esc_html_e( '私信', 'qibbs-community' ); ?></h2>
                    </div>
                    <div class="qibbs-conversations-list" id="qibbs-conversations-list">
                        <div class="qibbs-loading"><?php esc_html_e( '加载中...', 'qibbs-community' ); ?></div>
                    </div>
                </div>
                
                <!-- 聊天对话面板 -->
                <div class="qibbs-chat-panel" id="qibbs-chat-panel" <?php echo $partner_id ? '' : 'style="display:none;"'; ?>>
                    <div class="qibbs-chat-header" id="qibbs-chat-header">
                        <?php if ( $partner_id ) :
                            $partner = get_userdata( $partner_id );
                            if ( $partner ) :
                        ?>
                            <img src="<?php echo esc_url( get_avatar_url( $partner_id, array( 'size' => 40 ) ) ); ?>" class="qibbs-chat-avatar">
                            <span class="qibbs-chat-name"><?php echo esc_html( $partner->display_name ); ?></span>
                        <?php endif; endif; ?>
                    </div>
                    <div class="qibbs-chat-messages" id="qibbs-chat-messages">
                        <div class="qibbs-loading"><?php esc_html_e( '加载中...', 'qibbs-community' ); ?></div>
                    </div>
                    <div class="qibbs-chat-input">
                        <form class="qibbs-message-form" id="qibbs-message-form">
                            <input type="hidden" name="receiver_id" id="qibbs-receiver-id" value="<?php echo esc_attr( $partner_id ); ?>">
                            <textarea name="content" id="qibbs-message-content" placeholder="<?php esc_attr_e( '输入消息...', 'qibbs-community' ); ?>" rows="2"></textarea>
                            <button type="submit" class="qibbs-btn qibbs-btn-primary">
                                <?php esc_html_e( '发送', 'qibbs-community' ); ?>
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- 空状态占位 -->
                <div class="qibbs-chat-empty" id="qibbs-chat-empty" <?php echo $partner_id ? 'style="display:none;"' : ''; ?>>
                    <svg viewBox="0 0 24 24" width="64" height="64"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                    <p><?php esc_html_e( '选择一个会话开始聊天', 'qibbs-community' ); ?></p>
                </div>
            </div>
        </main>
    </div>
</div>

<style>
/* 私信消息页面 - 双栏布局 */
.qibbs-wrapper-messages {
    max-width: 1400px;
}
.qibbs-two-column-messages {
    grid-template-columns: var(--qibbs-nav-width) 1fr !important;
}
.qibbs-two-column-messages .qibbs-main.qibbs-messages-page {
    max-width: none;
}
.qibbs-messages-container {
    display: grid;
    grid-template-columns: 320px 1fr;
    min-height: 650px;
    gap: 0;
    background: var(--qibbs-bg-card);
    border-radius: var(--qibbs-radius);
    overflow: hidden;
    box-shadow: var(--qibbs-shadow);
}
.qibbs-chat-panel {
    height: 650px;
}
@media (max-width: 1024px) {
    .qibbs-two-column-messages {
        grid-template-columns: 1fr !important;
    }
    .qibbs-two-column-messages .qibbs-nav-sidebar {
        display: none;
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
        ),
    ) ); ?>;
}
(function($) {
    var currentPartnerId = <?php echo $partner_id ? $partner_id : 0; ?>;
    var messagePollingInterval = null;
    var POLL_INTERVAL_MS = 10000;

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

    function stopPolling() {
        if (messagePollingInterval) {
            clearInterval(messagePollingInterval);
            messagePollingInterval = null;
        }
    }

    function startPolling() {
        if (!currentPartnerId || document.hidden || messagePollingInterval) {
            return;
        }
        messagePollingInterval = setInterval(function() {
            if (currentPartnerId && !document.hidden) {
                loadMessages(currentPartnerId);
            }
        }, POLL_INTERVAL_MS);
    }

    // 加载会话列表
    function loadConversations() {
        $.ajax({
            url: qibbsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'qibbs_get_conversations',
                nonce: qibbsData.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderConversations(response.data.conversations);
                }
            }
        });
    }

    function renderConversations(conversations) {
        var $list = $('#qibbs-conversations-list');
        $list.empty();

        if (conversations.length === 0) {
            $list.html('<div class="qibbs-empty"><?php esc_html_e( '暂无会话', 'qibbs-community' ); ?></div>');
            return;
        }

        conversations.forEach(function(conv) {
            var activeClass = conv.partner_id == currentPartnerId ? 'active' : '';
            var unreadClass = conv.unread_count > 0 ? 'has-unread' : '';
            var preview = conv.last_message ? conv.last_message.content.substring(0, 30) : '';
            if (preview.length >= 30) preview += '...';
            var partnerAvatar = safeUrl(conv.partner.avatar);
            var partnerName = escHtml(conv.partner.display_name);
            var safePreview = escHtml(preview);

            $list.append(
                '<div class="qibbs-conversation-item ' + activeClass + ' ' + unreadClass + '" data-partner-id="' + conv.partner_id + '">' +
                    '<img src="' + partnerAvatar + '" class="qibbs-conv-avatar" alt="">' +
                    '<div class="qibbs-conv-info">' +
                        '<span class="qibbs-conv-name">' + partnerName + '</span>' +
                        '<span class="qibbs-conv-preview">' + safePreview + '</span>' +
                    '</div>' +
                    (conv.unread_count > 0 ? '<span class="qibbs-unread-badge">' + conv.unread_count + '</span>' : '') +
                '</div>'
            );
        });
    }

    // 加载指定会话的消息记录
    function loadMessages(partnerId) {
        $.ajax({
            url: qibbsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'qibbs_load_messages',
                nonce: qibbsData.nonce,
                partner_id: partnerId
            },
            success: function(response) {
                if (response.success) {
                    renderMessages(response.data.messages);
                }
            }
        });
    }

    function renderMessages(messages) {
        var $container = $('#qibbs-chat-messages');
        $container.empty();

        if (messages.length === 0) {
            $container.html('<div class="qibbs-empty"><?php esc_html_e( '暂无消息', 'qibbs-community' ); ?></div>');
            return;
        }

        messages.forEach(function(msg) {
            var selfClass = msg.is_self ? 'qibbs-message-self' : 'qibbs-message-other';
            var safeContent = escHtml(msg.content).replace(/\n/g, '<br>');
            $container.append(
                '<div class="qibbs-message-item ' + selfClass + '">' +
                    '<div class="qibbs-message-bubble">' + safeContent + '</div>' +
                    '<span class="qibbs-message-time"' + ((msg.created_at_formatted || msg.created_at) ? ' title="' + escHtml(msg.created_at_formatted || msg.created_at) + '"' : '') + '>' + msg.time_ago + '</span>' +
                '</div>'
            );
        });

        // Scroll to bottom
        $container.scrollTop($container[0].scrollHeight);
    }

    // 打开会话聊天窗口
    $(document).on('click', '.qibbs-conversation-item', function() {
        var partnerId = $(this).data('partner-id');
        var partnerName = $(this).find('.qibbs-conv-name').text();
        var partnerAvatar = $(this).find('.qibbs-conv-avatar').attr('src');
        var safePartnerAvatar = safeUrl(partnerAvatar);
        var safePartnerName = escHtml(partnerName);

        currentPartnerId = partnerId;
        $('#qibbs-receiver-id').val(partnerId);

        $('.qibbs-conversation-item').removeClass('active');
        $(this).addClass('active').removeClass('has-unread').find('.qibbs-unread-badge').remove();

        $('#qibbs-chat-header').html(
            '<img src="' + safePartnerAvatar + '" class="qibbs-chat-avatar" alt="">' +
            '<span class="qibbs-chat-name">' + safePartnerName + '</span>'
        );

        $('#qibbs-chat-empty').hide();
        $('#qibbs-chat-panel').show();

        loadMessages(partnerId);
        startPolling();

        // 更新当前 URL
        var nextUrl = (qibbsData.urls && qibbsData.urls.messages)
            ? qibbsData.urls.messages + '?user=' + partnerId
            : '?user=' + partnerId;
        history.pushState(null, '', nextUrl);
    });

    // 发送私信消息
    $('#qibbs-message-form').on('submit', function(e) {
        e.preventDefault();
        
        var $content = $('#qibbs-message-content');
        var content = $content.val().trim();
        var receiverId = $('#qibbs-receiver-id').val();

        if (!content || !receiverId) return;

        $.ajax({
            url: qibbsData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'qibbs_send_message',
                nonce: qibbsData.nonce,
                receiver_id: receiverId,
                content: content
            },
            success: function(response) {
                if (response.success) {
                    $content.val('');
                    loadMessages(receiverId);
                    loadConversations();
                } else {
                    alert(response.data.message || qibbsData.strings.error);
                }
            }
        });
    });

    // 支持按回车发送（Shift+Enter 换行）
    $('#qibbs-message-content').on('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            $('#qibbs-message-form').submit();
        }
    });

    // 页面初始加载
    loadConversations();
    
    if (currentPartnerId) {
        loadMessages(currentPartnerId);
    }

    // 页面可见时每 10 秒轮询新消息
    startPolling();

    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopPolling();
            return;
        }

        if (currentPartnerId) {
            loadMessages(currentPartnerId);
        }
        startPolling();
    });

    $(window).on('beforeunload', function() {
        stopPolling();
    });

})(jQuery);
</script>

<?php get_footer(); ?>
