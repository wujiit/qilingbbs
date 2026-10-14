<?php
/**
 * 工单详情页面模板
 *
 * @package Qibbs_Community
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 工单状态徽章渲染辅助函数
if ( ! function_exists( 'qibbs_get_ticket_status_badge' ) ) {
    function qibbs_get_ticket_status_badge( $status ) {
        $ticket_service = new Qibbs_Ticket_Service();
        $label = $ticket_service->get_status_label( $status );
        
        $classes = array(
            'pending'    => 'qibbs-status-pending',
            'processing' => 'qibbs-status-processing',
            'pending_customer' => 'qibbs-status-pending-customer',
            'reopened'   => 'qibbs-status-reopened',
            'resolved'   => 'qibbs-status-resolved',
            'closed'     => 'qibbs-status-closed',
        );
        
        $class = isset( $classes[ $status ] ) ? $classes[ $status ] : '';
        
        return sprintf( '<span class="qibbs-status-badge %s">%s</span>', esc_attr( $class ), esc_html( $label ) );
    }
}

// 校验用户登录态
if ( ! is_user_logged_in() ) {
    echo '<div class="qibbs-ticket-login-required">';
    echo '<p>' . __( '请先登录查看工单详情', 'qibbs-community' ) . '</p>';
    echo '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" class="qibbs-btn">' . __( '登录', 'qibbs-community' ) . '</a>';
    echo '</div>';
    return;
}

$ticket_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
$ticket_service = new Qibbs_Ticket_Service();
$category_service = new Qibbs_Ticket_Category_Service();

// 权限校验
if ( ! $ticket_service->can_access( $ticket_id, get_current_user_id() ) ) {
    echo '<div class="qibbs-ticket-error">';
    echo '<p>' . __( '您没有权限查看此工单', 'qibbs-community' ) . '</p>';
    echo '</div>';
    return;
}

$ticket = $ticket_service->get_ticket( $ticket_id );

if ( ! $ticket ) {
    echo '<div class="qibbs-ticket-error">';
    echo '<p>' . __( '工单不存在', 'qibbs-community' ) . '</p>';
    echo '</div>';
    return;
}

$replies = $ticket_service->get_replies( $ticket_id, false ); // 排除内部备注
$category = $category_service->get( $ticket->category_id );

$message = '';
$message_type = '';

// 处理回复提交
if (
    isset( $_POST['qibbs_ticket_reply'] ) &&
    isset( $_POST['qibbs_reply_nonce'] ) &&
    wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qibbs_reply_nonce'] ) ), 'qibbs_ticket_reply' )
) {
    $content = isset( $_POST['reply_content'] ) ? wp_kses_post( wp_unslash( $_POST['reply_content'] ) ) : '';
    
    if ( empty( $content ) ) {
        $message = __( '请输入回复内容', 'qibbs-community' );
        $message_type = 'error';
    } else {
        $reply_id = $ticket_service->add_reply( $ticket_id, get_current_user_id(), $content, false );
        
        if ( $reply_id ) {
            $message = __( '回复已发送', 'qibbs-community' );
            $message_type = 'success';
            $replies = $ticket_service->get_replies( $ticket_id, false );
            $_POST = array();
        } else {
            $message = __( '发送失败，请稍后重试', 'qibbs-community' );
            $message_type = 'error';
        }
    }
}

// 处理标记工单已解决
if (
    isset( $_POST['qibbs_mark_resolved'] ) &&
    isset( $_POST['qibbs_resolve_nonce'] ) &&
    wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qibbs_resolve_nonce'] ) ), 'qibbs_mark_resolved' )
) {
    if ( (int) $ticket->user_id === get_current_user_id() && $ticket->status !== 'resolved' && $ticket->status !== 'closed' ) {
        $result = $ticket_service->update_status( $ticket_id, 'resolved' );
        if ( $result ) {
            $message = __( '工单已标记为已解决', 'qibbs-community' );
            $message_type = 'success';
            $ticket = $ticket_service->get_ticket( $ticket_id ); // 刷新工单最新数据
        } else {
            $message = __( '操作失败，请稍后重试', 'qibbs-community' );
            $message_type = 'error';
        }
    }
}
?>

<div class="qibbs-ticket-detail">
    <div class="qibbs-ticket-header">
        <a href="<?php echo esc_url( remove_query_arg( array( 'action', 'id' ) ) ); ?>" class="qibbs-back-link">
            &larr; <?php _e( '返回工单列表', 'qibbs-community' ); ?>
        </a>
    </div>

    <?php if ( $message ) : ?>
        <div class="qibbs-ticket-message qibbs-ticket-message--<?php echo esc_attr( $message_type ); ?>">
            <?php echo esc_html( $message ); ?>
        </div>
    <?php endif; ?>
    
    <!-- 工单基础信息 -->
    <div class="qibbs-ticket-info">
        <div class="qibbs-ticket-info-header">
            <h2 class="qibbs-ticket-subject">
                <span class="qibbs-ticket-id">#<?php echo esc_html( $ticket->id ); ?></span>
                <?php echo esc_html( $ticket->subject ); ?>
            </h2>
            <?php echo qibbs_get_ticket_status_badge( $ticket->status ); ?>
        </div>
        
        <div class="qibbs-ticket-meta">
            <?php if ( $category ) : ?>
                <span class="qibbs-ticket-category-tag"><?php echo esc_html( $category->name ); ?></span>
            <?php endif; ?>
            <span class="qibbs-ticket-time">
                <?php printf( __( '提交于 %s', 'qibbs-community' ), esc_html( $ticket->created_at ) ); ?>
            </span>
        </div>
        
        <div class="qibbs-ticket-content">
            <?php echo wp_kses_post( $ticket->content ); ?>
        </div>
        
        <?php 
        // 渲染工单媒体附件
        if ( ! empty( $ticket->attachments ) ) :
            $attachment_ids = array_filter( array_map( 'absint', explode( ',', $ticket->attachments ) ) );
            if ( ! empty( $attachment_ids ) ) :
                // 从 qibbs_media 数据表查询附件
                global $wpdb;
                $media_table = Qibbs_Database::media();
                $ids_placeholder = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
                $media_items = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, file_url FROM {$media_table} WHERE user_id = %d AND id IN ({$ids_placeholder})",
                    array_merge( array( (int) $ticket->user_id ), $attachment_ids )
                ) );
        ?>
            <div class="qibbs-ticket-attachments">
                <div class="qibbs-ticket-attachments-title"><?php _e( '附件图片', 'qibbs-community' ); ?></div>
                <div class="qibbs-ticket-attachments-grid">
                    <?php foreach ( $media_items as $media ) : 
                        if ( ! empty( $media->file_url ) ) :
                    ?>
                        <a href="<?php echo esc_url( $media->file_url ); ?>" class="qibbs-ticket-attachment-item" target="_blank">
                            <img src="<?php echo esc_url( $media->file_url ); ?>" alt="">
                        </a>
                    <?php endif; endforeach; ?>
                </div>
            </div>
        <?php endif; endif; ?>
        
        <?php if ( $ticket->is_published ) : ?>
            <div class="qibbs-ticket-published-notice">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" style="vertical-align:-2px;margin-right:4px;"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/></svg><?php esc_html_e( '此工单已公开分享到社区', 'qibbs-community' ); ?>
            </div>
        <?php endif; ?>
        
        <?php if ( (int) $ticket->user_id === get_current_user_id() && $ticket->status !== 'resolved' && $ticket->status !== 'closed' ) : ?>
            <div class="qibbs-ticket-actions">
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field( 'qibbs_mark_resolved', 'qibbs_resolve_nonce' ); ?>
                    <button type="submit" name="qibbs_mark_resolved" class="qibbs-btn qibbs-btn-success qibbs-btn-sm">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" style="vertical-align:-2px;margin-right:4px;"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/></svg><?php esc_html_e( '标记为已解决', 'qibbs-community' ); ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Conversation -->
    <div class="qibbs-ticket-conversation">
        <h3 class="qibbs-section-title"><?php _e( '对话记录', 'qibbs-community' ); ?></h3>
        
        <?php if ( empty( $replies ) ) : ?>
            <div class="qibbs-ticket-no-replies">
                <p><?php _e( '暂无回复，请等待客服处理', 'qibbs-community' ); ?></p>
            </div>
        <?php else : ?>
            <div class="qibbs-ticket-replies">
                <?php foreach ( $replies as $reply ) : ?>
                    <div class="qibbs-ticket-reply <?php echo $reply->is_admin_reply ? 'qibbs-reply-admin' : 'qibbs-reply-user'; ?>">
                        <div class="qibbs-reply-avatar">
                            <?php echo get_avatar( $reply->user_id, 40 ); ?>
                        </div>
                        <div class="qibbs-reply-content">
                            <div class="qibbs-reply-header">
                                <span class="qibbs-reply-author">
                                    <?php echo esc_html( $reply->author ? $reply->author->display_name : __( '客服', 'qibbs-community' ) ); ?>
                                    <?php if ( $reply->is_admin_reply ) : ?>
                                        <span class="qibbs-admin-badge"><?php _e( '客服', 'qibbs-community' ); ?></span>
                                    <?php endif; ?>
                                </span>
                                <span class="qibbs-reply-time" title="<?php echo esc_attr( function_exists( 'qibbs_format_exact_datetime' ) ? qibbs_format_exact_datetime( $reply->created_at ) : (string) $reply->created_at ); ?>">
                                    <?php echo esc_html( qibbs_human_time_diff( $reply->created_at ) ); ?>
                                </span>
                            </div>
                            <div class="qibbs-reply-body">
                                <?php echo wp_kses_post( $reply->content ); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- 工单回复表单 -->
        <?php if ( $ticket->status !== 'closed' ) : ?>
            <div class="qibbs-ticket-reply-form">
                <form method="post">
                    <?php wp_nonce_field( 'qibbs_ticket_reply', 'qibbs_reply_nonce' ); ?>
                    
                    <div class="qibbs-form-group">
                        <textarea name="reply_content" rows="4" placeholder="<?php esc_attr_e( '输入您的回复...', 'qibbs-community' ); ?>" required></textarea>
                    </div>
                    
                    <div class="qibbs-form-actions">
                        <button type="submit" name="qibbs_ticket_reply" class="qibbs-btn qibbs-btn-primary">
                            <?php _e( '发送回复', 'qibbs-community' ); ?>
                        </button>
                    </div>
                </form>
            </div>
        <?php else : ?>
            <div class="qibbs-ticket-closed-notice">
                <p><?php _e( '此工单已关闭，无法继续回复', 'qibbs-community' ); ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>


