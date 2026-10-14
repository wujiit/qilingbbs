<?php
/**
 * 个人中心工单管理模板
 * 集成于启灵主题个人中心工单模块
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 校验用户登录态
if ( ! is_user_logged_in() ) {
    return;
}

$user_id = get_current_user_id();
$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
$ticket_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

// 获取导航基础 URL
$base_url = remove_query_arg( array( 'action', 'id', 'ticket_submitted' ) );
$list_url = add_query_arg( 'tab', 'tickets', $base_url );
$submit_url = add_query_arg( array( 'tab' => 'tickets', 'action' => 'submit' ), $base_url );

// 初始化所需服务
$ticket_service = new Qibbs_Ticket_Service();
$category_service = new Qibbs_Ticket_Category_Service();

// 工单状态徽章渲染辅助函数
if ( ! function_exists( 'qibbs_account_get_status_badge' ) ) {
    function qibbs_account_get_status_badge( $status ) {
        $ticket_service = new Qibbs_Ticket_Service();
        $label = $ticket_service->get_status_label( $status );
        
        $classes = array(
            'pending'    => 'qibbs-acc-status-pending',
            'processing' => 'qibbs-acc-status-processing',
            'pending_customer' => 'qibbs-acc-status-pending-customer',
            'reopened'   => 'qibbs-acc-status-reopened',
            'resolved'   => 'qibbs-acc-status-resolved',
            'closed'     => 'qibbs-acc-status-closed',
        );
        
        $class = isset( $classes[ $status ] ) ? $classes[ $status ] : '';
        
        return sprintf( '<span class="qibbs-acc-status-badge %s">%s</span>', esc_attr( $class ), esc_html( $label ) );
    }
}

// 提示消息处理
$message = '';
$message_type = '';

// 处理表单提交
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    // 处理新工单提交
    $ticket_submit_action = isset( $_POST['qibbs_ticket_action'] ) ? sanitize_key( wp_unslash( $_POST['qibbs_ticket_action'] ) ) : '';
    $ticket_submit_nonce = isset( $_POST['qibbs_ticket_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['qibbs_ticket_nonce'] ) ) : '';
    if ( 'submit' === $ticket_submit_action && wp_verify_nonce( $ticket_submit_nonce, 'qibbs_submit_ticket' ) ) {
        $subject = isset( $_POST['ticket_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_subject'] ) ) : '';
        $content = isset( $_POST['ticket_content'] ) ? wp_kses_post( wp_unslash( $_POST['ticket_content'] ) ) : '';
        $category_id = isset( $_POST['ticket_category'] ) ? absint( wp_unslash( $_POST['ticket_category'] ) ) : 0;
        $priority = isset( $_POST['ticket_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_priority'] ) ) : 'normal';
        $allow_publish = ! empty( $_POST['allow_publish'] );
        $attachments = isset( $_POST['ticket_attachments'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_attachments'] ) ) : '';
        
        if ( empty( $subject ) || empty( $content ) || empty( $category_id ) ) {
            $message = __( '请填写完整的工单信息', 'qibbs-community' );
            $message_type = 'error';
        } else {
            $new_ticket_id = $ticket_service->create_ticket(
                $user_id,
                $category_id,
                $subject,
                $content,
                $priority,
                $allow_publish,
                $attachments
            );
            
            if ( $new_ticket_id ) {
                wp_safe_redirect( add_query_arg( 'ticket_submitted', $new_ticket_id, $list_url ) );
                exit;
            } else {
                $error = $ticket_service->get_last_error();
                if ( is_wp_error( $error ) ) {
                    $message = $error->get_error_message();
                } else {
                    $message = __( '提交失败，请稍后重试', 'qibbs-community' );
                }
                $message_type = 'error';
            }
        }
    }
    
    // 处理工单回复提交
    $ticket_reply_nonce = isset( $_POST['qibbs_reply_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['qibbs_reply_nonce'] ) ) : '';
    if ( isset( $_POST['qibbs_ticket_reply'] ) && wp_verify_nonce( $ticket_reply_nonce, 'qibbs_ticket_reply' ) ) {
        if ( ! $ticket_service->can_access( $ticket_id, $user_id ) ) {
            $message = __( '您没有权限回复此工单', 'qibbs-community' );
            $message_type = 'error';
        } else {
            $content = isset( $_POST['reply_content'] ) ? wp_kses_post( wp_unslash( $_POST['reply_content'] ) ) : '';
        
            if ( empty( $content ) ) {
                $message = __( '请输入回复内容', 'qibbs-community' );
                $message_type = 'error';
            } else {
                $reply_id = $ticket_service->add_reply( $ticket_id, $user_id, $content, false );
                
                if ( $reply_id ) {
                    $message = __( '回复已发送', 'qibbs-community' );
                    $message_type = 'success';
                } else {
                    $message = __( '发送失败，请稍后重试', 'qibbs-community' );
                    $message_type = 'error';
                }
            }
        }
    }
    
    // 处理标记工单已解决
    $ticket_resolve_nonce = isset( $_POST['qibbs_resolve_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['qibbs_resolve_nonce'] ) ) : '';
    if ( isset( $_POST['qibbs_mark_resolved'] ) && wp_verify_nonce( $ticket_resolve_nonce, 'qibbs_mark_resolved' ) ) {
        $ticket = $ticket_service->get_ticket( $ticket_id );
        if ( $ticket && (int) $ticket->user_id === $user_id && $ticket->status !== 'resolved' && $ticket->status !== 'closed' ) {
            $result = $ticket_service->update_status( $ticket_id, 'resolved' );
            if ( $result ) {
                $message = __( '工单已标记为已解决', 'qibbs-community' );
                $message_type = 'success';
            } else {
                $message = __( '操作失败，请稍后重试', 'qibbs-community' );
                $message_type = 'error';
            }
        }
    }
}

// 校验跳转后的成功提示消息
if ( isset( $_GET['ticket_submitted'] ) ) {
    $message = sprintf( __( '工单 #%d 提交成功！我们会尽快处理您的问题。', 'qibbs-community' ), absint( $_GET['ticket_submitted'] ) );
    $message_type = 'success';
}

?>

<div class="qibbs-account-tickets">
    <?php if ( $message ) : ?>
        <div class="account-message <?php echo esc_attr( $message_type ); ?>">
            <?php echo esc_html( $message ); ?>
        </div>
    <?php endif; ?>

    <?php if ( $action === 'list' ) : ?>
        <!-- 工单列表视图 -->
        <div class="account-section">
            <div class="qibbs-acc-header">
                <h3 class="section-title"><?php _e( '我的工单', 'qibbs-community' ); ?></h3>
                <a href="<?php echo esc_url( $submit_url ); ?>" class="btn-primary btn-sm">
                    + <?php _e( '提交新工单', 'qibbs-community' ); ?>
                </a>
            </div>
            
            <?php
            $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
            $paged = isset( $_GET['tpaged'] ) ? absint( $_GET['tpaged'] ) : 1;
            
            $tickets = $ticket_service->get_user_tickets( $user_id, array(
                'status'   => $status_filter,
                'page'     => $paged,
                'per_page' => 10,
            ) );
            ?>
            
            <!-- 状态筛选 -->
            <div class="qibbs-acc-filters">
                <a href="<?php echo esc_url( remove_query_arg( 'status', $list_url ) ); ?>" 
                   class="qibbs-acc-filter-btn <?php echo empty( $status_filter ) ? 'active' : ''; ?>">
                    <?php _e( '全部', 'qibbs-community' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'status', 'pending', $list_url ) ); ?>" 
                   class="qibbs-acc-filter-btn <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                    <?php _e( '待处理', 'qibbs-community' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'status', 'processing', $list_url ) ); ?>" 
                   class="qibbs-acc-filter-btn <?php echo $status_filter === 'processing' ? 'active' : ''; ?>">
                    <?php _e( '处理中', 'qibbs-community' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'status', 'pending_customer', $list_url ) ); ?>" 
                   class="qibbs-acc-filter-btn <?php echo $status_filter === 'pending_customer' ? 'active' : ''; ?>">
                    <?php _e( '待用户反馈', 'qibbs-community' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'status', 'reopened', $list_url ) ); ?>" 
                   class="qibbs-acc-filter-btn <?php echo $status_filter === 'reopened' ? 'active' : ''; ?>">
                    <?php _e( '已重开', 'qibbs-community' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'status', 'resolved', $list_url ) ); ?>" 
                   class="qibbs-acc-filter-btn <?php echo $status_filter === 'resolved' ? 'active' : ''; ?>">
                    <?php _e( '已解决', 'qibbs-community' ); ?>
                </a>
            </div>
            
            <?php if ( empty( $tickets ) ) : ?>
                <div class="qibbs-acc-empty">
                    <div class="qibbs-acc-empty-icon">
                        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="12" y1="11" x2="12" y2="17"></line>
                            <line x1="9" y1="14" x2="15" y2="14"></line>
                        </svg>
                    </div>
                    <p><?php _e( '暂无工单记录', 'qibbs-community' ); ?></p>
                    <a href="<?php echo esc_url( $submit_url ); ?>" class="btn-primary">
                        <?php _e( '提交新工单', 'qibbs-community' ); ?>
                    </a>
                </div>
            <?php else : ?>
                <div class="qibbs-acc-ticket-list">
                    <?php foreach ( $tickets as $ticket ) : ?>
                        <?php $category = $category_service->get( $ticket->category_id ); ?>
                        <div class="qibbs-acc-ticket-item">
                            <div class="qibbs-acc-ticket-main">
                                <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'tickets', 'action' => 'view', 'id' => $ticket->id ), $base_url ) ); ?>" class="qibbs-acc-ticket-title">
                                    <span class="qibbs-acc-ticket-id">#<?php echo esc_html( $ticket->id ); ?></span>
                                    <?php echo esc_html( $ticket->subject ); ?>
                                </a>
                                <div class="qibbs-acc-ticket-meta">
                                    <?php if ( $category ) : ?>
                                        <span class="qibbs-acc-ticket-category"><?php echo esc_html( $category->name ); ?></span>
                                    <?php endif; ?>
                                    <span class="qibbs-acc-ticket-time" title="<?php echo esc_attr( function_exists( 'qibbs_format_exact_datetime' ) ? qibbs_format_exact_datetime( $ticket->created_at ) : (string) $ticket->created_at ); ?>">
                                        <?php echo esc_html( qibbs_human_time_diff( $ticket->created_at ) ); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="qibbs-acc-ticket-status">
                                <?php echo qibbs_account_get_status_badge( $ticket->status ); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ( $action === 'submit' ) : ?>
        <!-- 提交工单视图 -->
        <div class="account-section">
            <div class="qibbs-acc-header">
                <a href="<?php echo esc_url( $list_url ); ?>" class="qibbs-acc-back-link">
                    &larr; <?php _e( '返回工单列表', 'qibbs-community' ); ?>
                </a>
            </div>
            
            <h3 class="section-title"><?php _e( '提交新工单', 'qibbs-community' ); ?></h3>
            
            <?php
            $categories = $category_service->get_categories( true );
            $max_size_mb = qibbs_get_option( 'max_upload_size', 5 );
            $allowed_formats = qibbs_get_option( 'allowed_image_formats', 'jpg,jpeg,png,webp,gif' );
            ?>
            
            <form method="post" class="account-form qibbs-acc-ticket-form" id="qibbs-ticket-form">
                <?php wp_nonce_field( 'qibbs_submit_ticket', 'qibbs_ticket_nonce' ); ?>
                <input type="hidden" name="qibbs_ticket_action" value="submit">
                <input type="hidden" name="ticket_attachments" id="ticket_attachments" value="">
                
                <div class="form-group">
                    <label for="ticket_category"><?php _e( '问题分类', 'qibbs-community' ); ?> <span class="required">*</span></label>
                    <select name="ticket_category" id="ticket_category" required>
                        <option value=""><?php _e( '-- 请选择分类 --', 'qibbs-community' ); ?></option>
                        <?php foreach ( $categories as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat->id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="ticket_subject"><?php _e( '问题标题', 'qibbs-community' ); ?> <span class="required">*</span></label>
                    <input type="text" name="ticket_subject" id="ticket_subject" placeholder="<?php esc_attr_e( '简要描述您的问题', 'qibbs-community' ); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="ticket_content"><?php _e( '问题描述', 'qibbs-community' ); ?> <span class="required">*</span></label>
                    <textarea name="ticket_content" id="ticket_content" rows="6" placeholder="<?php esc_attr_e( '请详细描述您遇到的问题...', 'qibbs-community' ); ?>" required></textarea>
                </div>
                
                <!-- 图片附件上传区域 -->
                <div class="form-group">
                    <label><?php _e( '上传截图', 'qibbs-community' ); ?> <span class="optional">(<?php _e( '可选', 'qibbs-community' ); ?>)</span></label>
                    <div class="qibbs-acc-upload-area">
                        <input type="file" id="ticket_media_input" accept="image/*" multiple style="display: none;">
                        <div class="qibbs-acc-media-preview" id="ticket_media_preview"></div>
                        <button type="button" class="qibbs-acc-upload-btn" id="ticket_upload_btn">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/>
                            </svg>
                            <?php _e( '添加图片', 'qibbs-community' ); ?>
                        </button>
                        <p class="qibbs-acc-upload-hint"><?php printf( __( '支持 %s 格式，单张最大 %sMB，最多9张', 'qibbs-community' ), esc_html( $allowed_formats ), esc_html( $max_size_mb ) ); ?></p>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="ticket_priority"><?php _e( '优先级', 'qibbs-community' ); ?></label>
                    <select name="ticket_priority" id="ticket_priority">
                        <option value="normal"><?php _e( '普通', 'qibbs-community' ); ?></option>
                        <option value="low"><?php _e( '低', 'qibbs-community' ); ?></option>
                        <option value="high"><?php _e( '高', 'qibbs-community' ); ?></option>
                        <option value="urgent"><?php _e( '紧急', 'qibbs-community' ); ?></option>
                    </select>
                </div>
                
                <div class="form-group qibbs-acc-checkbox">
                    <label>
                        <input type="checkbox" name="allow_publish" value="1">
                        <span><?php _e( '我同意在问题解决后将此工单公开分享给社区，帮助其他用户', 'qibbs-community' ); ?></span>
                    </label>
                    <p class="form-hint"><?php _e( '勾选后，管理员可能会将您的问题和解决方案分享到社区，您的个人信息将被保护', 'qibbs-community' ); ?></p>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn-primary" id="ticket_submit_btn">
                        <?php _e( '提交工单', 'qibbs-community' ); ?>
                    </button>
                </div>
            </form>
        </div>

    <?php elseif ( $action === 'view' && $ticket_id ) : ?>
        <!-- 工单详情视图 -->
        <?php
        // 权限校验
        if ( ! $ticket_service->can_access( $ticket_id, $user_id ) ) {
            echo '<div class="account-message error">' . __( '您没有权限查看此工单', 'qibbs-community' ) . '</div>';
            return;
        }
        
        $ticket = $ticket_service->get_ticket( $ticket_id );
        
        if ( ! $ticket ) {
            echo '<div class="account-message error">' . __( '工单不存在', 'qibbs-community' ) . '</div>';
            return;
        }
        
        $replies = $ticket_service->get_replies( $ticket_id, false );
        $category = $category_service->get( $ticket->category_id );
        ?>
        
        <div class="account-section">
            <div class="qibbs-acc-header">
                <a href="<?php echo esc_url( $list_url ); ?>" class="qibbs-acc-back-link">
                    &larr; <?php _e( '返回工单列表', 'qibbs-community' ); ?>
                </a>
            </div>
            
            <!-- 工单基础信息 -->
            <div class="qibbs-acc-ticket-info">
                <div class="qibbs-acc-ticket-info-header">
                    <h3 class="qibbs-acc-ticket-subject">
                        <span class="qibbs-acc-ticket-id">#<?php echo esc_html( $ticket->id ); ?></span>
                        <?php echo esc_html( $ticket->subject ); ?>
                    </h3>
                    <?php echo qibbs_account_get_status_badge( $ticket->status ); ?>
                </div>
                
                <div class="qibbs-acc-ticket-detail-meta">
                    <?php if ( $category ) : ?>
                        <span class="qibbs-acc-category-tag"><?php echo esc_html( $category->name ); ?></span>
                    <?php endif; ?>
                    <span class="qibbs-acc-ticket-time">
                        <?php printf( __( '提交于 %s', 'qibbs-community' ), esc_html( $ticket->created_at ) ); ?>
                    </span>
                </div>
                
                <div class="qibbs-acc-ticket-content">
                    <?php echo wp_kses_post( $ticket->content ); ?>
                </div>
                
                <?php 
                // 渲染工单媒体附件
                if ( ! empty( $ticket->attachments ) ) :
                    $attachment_ids = array_filter( array_map( 'absint', explode( ',', $ticket->attachments ) ) );
                    if ( ! empty( $attachment_ids ) ) :
                        global $wpdb;
                        $media_table = Qibbs_Database::media();
                        $ids_placeholder = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
                        $media_items = $wpdb->get_results( $wpdb->prepare(
                            "SELECT id, file_url FROM {$media_table} WHERE user_id = %d AND id IN ({$ids_placeholder})",
                            array_merge( array( (int) $ticket->user_id ), $attachment_ids )
                        ) );
                ?>
                    <div class="qibbs-acc-attachments">
                        <div class="qibbs-acc-attachments-title"><?php _e( '附件图片', 'qibbs-community' ); ?></div>
                        <div class="qibbs-acc-attachments-grid">
                            <?php foreach ( $media_items as $media ) : 
                                if ( ! empty( $media->file_url ) ) :
                            ?>
                                <a href="<?php echo esc_url( $media->file_url ); ?>" class="qibbs-acc-attachment-item" target="_blank">
                                    <img src="<?php echo esc_url( $media->file_url ); ?>" alt="">
                                </a>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                <?php endif; endif; ?>
                
                <?php if ( $ticket->is_published ) : ?>
                    <div class="qibbs-acc-published-notice">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" style="vertical-align:-2px;margin-right:4px;"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/></svg><?php esc_html_e( '此工单已公开分享到社区', 'qibbs-community' ); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ( (int) $ticket->user_id === $user_id && $ticket->status !== 'resolved' && $ticket->status !== 'closed' ) : ?>
                    <div class="qibbs-acc-ticket-actions">
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field( 'qibbs_mark_resolved', 'qibbs_resolve_nonce' ); ?>
                            <button type="submit" name="qibbs_mark_resolved" class="btn-success btn-sm">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" style="vertical-align:-2px;margin-right:4px;"><path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2z"/></svg><?php esc_html_e( '标记为已解决', 'qibbs-community' ); ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Conversation -->
        <div class="account-section">
            <h3 class="section-title"><?php _e( '对话记录', 'qibbs-community' ); ?></h3>
            
            <?php if ( empty( $replies ) ) : ?>
                <div class="qibbs-acc-no-replies">
                    <p><?php _e( '暂无回复，请等待客服处理', 'qibbs-community' ); ?></p>
                </div>
            <?php else : ?>
                <div class="qibbs-acc-replies">
                    <?php foreach ( $replies as $reply ) : ?>
                        <div class="qibbs-acc-reply <?php echo $reply->is_admin_reply ? 'qibbs-acc-reply-admin' : 'qibbs-acc-reply-user'; ?>">
                            <div class="qibbs-acc-reply-avatar">
                                <?php echo get_avatar( $reply->user_id, 40 ); ?>
                            </div>
                            <div class="qibbs-acc-reply-content">
                                <div class="qibbs-acc-reply-header">
                                    <span class="qibbs-acc-reply-author">
                                        <?php echo esc_html( $reply->author ? $reply->author->display_name : __( '客服', 'qibbs-community' ) ); ?>
                                        <?php if ( $reply->is_admin_reply ) : ?>
                                            <span class="qibbs-acc-admin-badge"><?php _e( '客服', 'qibbs-community' ); ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="qibbs-acc-reply-time" title="<?php echo esc_attr( function_exists( 'qibbs_format_exact_datetime' ) ? qibbs_format_exact_datetime( $reply->created_at ) : (string) $reply->created_at ); ?>">
                                        <?php echo esc_html( qibbs_human_time_diff( $reply->created_at ) ); ?>
                                    </span>
                                </div>
                                <div class="qibbs-acc-reply-body">
                                    <?php echo wp_kses_post( $reply->content ); ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- 工单回复表单 -->
            <?php if ( $ticket->status !== 'closed' ) : ?>
                <div class="qibbs-acc-reply-form">
                    <form method="post" class="account-form">
                        <?php wp_nonce_field( 'qibbs_ticket_reply', 'qibbs_reply_nonce' ); ?>
                        
                        <div class="form-group">
                            <textarea name="reply_content" rows="4" placeholder="<?php esc_attr_e( '输入您的回复...', 'qibbs-community' ); ?>" required></textarea>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="qibbs_ticket_reply" class="btn-primary">
                                <?php _e( '发送回复', 'qibbs-community' ); ?>
                            </button>
                        </div>
                    </form>
                </div>
            <?php else : ?>
                <div class="qibbs-acc-closed-notice">
                    <p><?php _e( '此工单已关闭，无法继续回复', 'qibbs-community' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>
