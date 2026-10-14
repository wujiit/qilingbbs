<?php
/**
 * 提交工单页面模板
 *
 * @package Qibbs_Community
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 校验用户登录态
if ( ! is_user_logged_in() ) {
    echo '<div class="qibbs-ticket-login-required">';
    echo '<p>' . __( '请先登录后再提交工单', 'qibbs-community' ) . '</p>';
    echo '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" class="qibbs-btn">' . __( '登录', 'qibbs-community' ) . '</a>';
    echo '</div>';
    return;
}

$category_service = new Qibbs_Ticket_Category_Service();
$categories = $category_service->get_categories( true );

$message = '';
$message_type = '';

// 处理表单提交
if (
    isset( $_POST['qibbs_ticket_action'] ) &&
    'submit' === sanitize_key( wp_unslash( $_POST['qibbs_ticket_action'] ) ) &&
    isset( $_POST['qibbs_ticket_nonce'] ) &&
    wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qibbs_ticket_nonce'] ) ), 'qibbs_submit_ticket' )
) {
    $ticket_service = new Qibbs_Ticket_Service();
    
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
        $ticket_id = $ticket_service->create_ticket(
            get_current_user_id(),
            $category_id,
            $subject,
            $content,
            $priority,
            $allow_publish,
            $attachments
        );
        
        if ( $ticket_id ) {
            // 提交成功后携带提示跳转至工单列表
            $redirect_url = remove_query_arg( 'action' );
            $redirect_url = add_query_arg( 'ticket_submitted', $ticket_id, $redirect_url );
            wp_safe_redirect( $redirect_url );
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

// 获取上传配置限制
$max_size_mb = qibbs_get_option( 'max_upload_size', 5 );
$allowed_formats = qibbs_get_option( 'allowed_image_formats', 'jpg,jpeg,png,webp,gif' );
?>

<div class="qibbs-ticket-submit">
    <a href="<?php echo esc_url( remove_query_arg( 'action' ) ); ?>" class="qibbs-back-link">
        &larr; <?php _e( '返回工单列表', 'qibbs-community' ); ?>
    </a>
    <h2 class="qibbs-ticket-title"><?php _e( '提交工单', 'qibbs-community' ); ?></h2>
    
    <?php if ( $message ) : ?>
        <div class="qibbs-ticket-message qibbs-ticket-message--<?php echo esc_attr( $message_type ); ?>">
            <?php echo esc_html( $message ); ?>
        </div>
    <?php endif; ?>
    
    <form method="post" class="qibbs-ticket-form" id="qibbs-ticket-form" enctype="multipart/form-data">
        <?php wp_nonce_field( 'qibbs_submit_ticket', 'qibbs_ticket_nonce' ); ?>
        <input type="hidden" name="qibbs_ticket_action" value="submit">
        <input type="hidden" name="ticket_attachments" id="ticket_attachments" value="">
        
        <div class="qibbs-form-group">
            <label for="ticket_category"><?php _e( '问题分类', 'qibbs-community' ); ?> <span class="required">*</span></label>
            <select name="ticket_category" id="ticket_category" required>
                <option value=""><?php _e( '-- 请选择分类 --', 'qibbs-community' ); ?></option>
                <?php foreach ( $categories as $cat ) : ?>
                    <option value="<?php echo esc_attr( $cat->id ); ?>" <?php selected( isset( $_POST['ticket_category'] ) ? $_POST['ticket_category'] : '', $cat->id ); ?>>
                        <?php echo esc_html( $cat->name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="qibbs-form-group">
            <label for="ticket_subject"><?php _e( '问题标题', 'qibbs-community' ); ?> <span class="required">*</span></label>
            <input type="text" name="ticket_subject" id="ticket_subject" 
                value="<?php echo isset( $_POST['ticket_subject'] ) ? esc_attr( $_POST['ticket_subject'] ) : ''; ?>"
                placeholder="<?php esc_attr_e( '简要描述您的问题', 'qibbs-community' ); ?>" required>
        </div>
        
        <div class="qibbs-form-group">
            <label for="ticket_content"><?php _e( '问题描述', 'qibbs-community' ); ?> <span class="required">*</span></label>
            <textarea name="ticket_content" id="ticket_content" rows="8" 
                placeholder="<?php esc_attr_e( '请详细描述您遇到的问题...', 'qibbs-community' ); ?>" required><?php echo isset( $_POST['ticket_content'] ) ? esc_textarea( $_POST['ticket_content'] ) : ''; ?></textarea>
        </div>
        
        <!-- 图片附件上传区域 -->
        <div class="qibbs-form-group">
            <label><?php _e( '上传截图', 'qibbs-community' ); ?> <span class="optional">(<?php _e( '可选', 'qibbs-community' ); ?>)</span></label>
            <div class="qibbs-ticket-upload-area">
                <input type="file" id="ticket_media_input" accept="image/*" multiple style="display: none;">
                <div class="qibbs-ticket-media-preview" id="ticket_media_preview"></div>
                <button type="button" class="qibbs-upload-btn" id="ticket_upload_btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/>
                    </svg>
                    <?php _e( '添加图片', 'qibbs-community' ); ?>
                </button>
                <p class="qibbs-upload-hint"><?php printf( __( '支持 %s 格式，单张最大 %sMB，最多9张', 'qibbs-community' ), esc_html( $allowed_formats ), esc_html( $max_size_mb ) ); ?></p>
            </div>
        </div>
        
        <div class="qibbs-form-group">
            <label for="ticket_priority"><?php _e( '优先级', 'qibbs-community' ); ?></label>
            <select name="ticket_priority" id="ticket_priority">
                <option value="normal"><?php _e( '普通', 'qibbs-community' ); ?></option>
                <option value="low"><?php _e( '低', 'qibbs-community' ); ?></option>
                <option value="high"><?php _e( '高', 'qibbs-community' ); ?></option>
                <option value="urgent"><?php _e( '紧急', 'qibbs-community' ); ?></option>
            </select>
        </div>
        
        <div class="qibbs-form-group qibbs-form-checkbox">
            <label>
                <input type="checkbox" name="allow_publish" value="1" <?php checked( isset( $_POST['allow_publish'] ) ); ?>>
                <span><?php _e( '我同意在问题解决后将此工单公开分享给社区，帮助其他用户', 'qibbs-community' ); ?></span>
            </label>
            <p class="qibbs-form-hint"><?php _e( '勾选后，管理员可能会将您的问题和解决方案分享到社区，您的个人信息将被保护', 'qibbs-community' ); ?></p>
        </div>
        
        <div class="qibbs-form-actions">
            <button type="submit" class="qibbs-btn qibbs-btn-primary" id="ticket_submit_btn">
                <?php _e( '提交工单', 'qibbs-community' ); ?>
            </button>
        </div>
    </form>
</div>
