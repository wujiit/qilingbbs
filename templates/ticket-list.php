<?php
/**
 * 工单列表页面模板
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
    echo '<p>' . __( '请先登录查看您的工单', 'qibbs-community' ) . '</p>';
    echo '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" class="qibbs-btn">' . __( '登录', 'qibbs-community' ) . '</a>';
    echo '</div>';
    return;
}

$ticket_service = new Qibbs_Ticket_Service();
$category_service = new Qibbs_Ticket_Category_Service();

$status = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

$tickets = $ticket_service->get_user_tickets( get_current_user_id(), array(
    'status'   => $status,
    'page'     => $paged,
    'per_page' => 10,
) );
?>

<div class="qibbs-ticket-list">
    <?php if ( isset( $_GET['ticket_submitted'] ) ) : ?>
        <div class="qibbs-ticket-message qibbs-ticket-message--success">
            <?php printf( __( '工单 #%d 提交成功！我们会尽快处理您的问题。', 'qibbs-community' ), absint( $_GET['ticket_submitted'] ) ); ?>
        </div>
    <?php endif; ?>
    
    <div class="qibbs-ticket-header">
        <h2 class="qibbs-ticket-title"><?php _e( '我的工单', 'qibbs-community' ); ?></h2>
        <a href="<?php echo esc_url( add_query_arg( 'action', 'submit' ) ); ?>" class="qibbs-btn qibbs-btn-primary qibbs-btn-sm">
            <?php _e( '+ 提交新工单', 'qibbs-community' ); ?>
        </a>
    </div>
    
    <!-- 状态筛选 -->
    <div class="qibbs-ticket-filters">
        <a href="<?php echo esc_url( remove_query_arg( 'status' ) ); ?>" 
           class="qibbs-filter-btn <?php echo empty( $status ) ? 'active' : ''; ?>">
            <?php _e( '全部', 'qibbs-community' ); ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg( 'status', 'pending' ) ); ?>" 
           class="qibbs-filter-btn <?php echo $status === 'pending' ? 'active' : ''; ?>">
            <?php _e( '待处理', 'qibbs-community' ); ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg( 'status', 'processing' ) ); ?>" 
           class="qibbs-filter-btn <?php echo $status === 'processing' ? 'active' : ''; ?>">
            <?php _e( '处理中', 'qibbs-community' ); ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg( 'status', 'pending_customer' ) ); ?>" 
           class="qibbs-filter-btn <?php echo $status === 'pending_customer' ? 'active' : ''; ?>">
            <?php _e( '待用户反馈', 'qibbs-community' ); ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg( 'status', 'reopened' ) ); ?>" 
           class="qibbs-filter-btn <?php echo $status === 'reopened' ? 'active' : ''; ?>">
            <?php _e( '已重开', 'qibbs-community' ); ?>
        </a>
        <a href="<?php echo esc_url( add_query_arg( 'status', 'resolved' ) ); ?>" 
           class="qibbs-filter-btn <?php echo $status === 'resolved' ? 'active' : ''; ?>">
            <?php _e( '已解决', 'qibbs-community' ); ?>
        </a>
    </div>
    
    <!-- Tickets -->
    <?php if ( empty( $tickets ) ) : ?>
        <div class="qibbs-ticket-empty">
            <p><?php _e( '暂无工单记录', 'qibbs-community' ); ?></p>
        </div>
    <?php else : ?>
        <div class="qibbs-ticket-items">
            <?php foreach ( $tickets as $ticket ) : ?>
                <?php $category = $category_service->get( $ticket->category_id ); ?>
                <div class="qibbs-ticket-item">
                    <div class="qibbs-ticket-item-main">
                        <a href="<?php echo esc_url( add_query_arg( array( 'action' => 'view', 'id' => $ticket->id ) ) ); ?>" class="qibbs-ticket-item-title">
                            <span class="qibbs-ticket-id">#<?php echo esc_html( $ticket->id ); ?></span>
                            <?php echo esc_html( $ticket->subject ); ?>
                        </a>
                        <div class="qibbs-ticket-item-meta">
                            <?php if ( $category ) : ?>
                                <span class="qibbs-ticket-category"><?php echo esc_html( $category->name ); ?></span>
                            <?php endif; ?>
                            <span class="qibbs-ticket-time" title="<?php echo esc_attr( function_exists( 'qibbs_format_exact_datetime' ) ? qibbs_format_exact_datetime( $ticket->created_at ) : (string) $ticket->created_at ); ?>"><?php echo esc_html( qibbs_human_time_diff( $ticket->created_at ) ); ?></span>
                        </div>
                    </div>
                    <div class="qibbs-ticket-item-status">
                        <?php echo qibbs_get_ticket_status_badge( $ticket->status ); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>


