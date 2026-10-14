<?php
/**
 * 工单后台管理类
 *
 * 负责工单在后台的管理与流转流
 *
 * @package Qibbs_Community
 * @subpackage Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Ticket_Admin {

    /**
     * 注册工单后台专用钩子
     */
    public static function init() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_qibbs_ticket_action', array( __CLASS__, 'handle_ajax_action' ) );
    }

    /**
     * 加载工单后台样式与脚本
     */
    public static function enqueue_scripts( $hook ) {
        if ( strpos( $hook, 'qibbs-tickets' ) === false && strpos( $hook, 'qibbs-ticket-categories' ) === false ) {
            return;
        }

        wp_enqueue_style( 'qibbs-ticket-admin', QIBBS_PLUGIN_URL . 'assets/css/admin-ticket.css', array(), QIBBS_VERSION );
        wp_enqueue_script( 'qibbs-ticket-admin', QIBBS_PLUGIN_URL . 'assets/js/admin-ticket.js', array( 'jquery' ), QIBBS_VERSION, true );
        wp_localize_script( 'qibbs-ticket-admin', 'qibbsTicketAdmin', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'qibbs_ticket_admin' ),
            'i18n'    => array(
                'confirm_delete'  => __( '确定要删除吗？', 'qibbs-community' ),
                'confirm_publish' => __( '确定要将此工单公开到社区吗？', 'qibbs-community' ),
                'processing'      => __( '处理中...', 'qibbs-community' ),
                'success'         => __( '操作成功', 'qibbs-community' ),
                'error'           => __( '操作失败', 'qibbs-community' ),
            ),
        ) );
    }

    /**
     * 渲染工单综合管理面板
     */
    public static function render_tickets_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '权限不足', 'qibbs-community' ) );
        }

        $ticket_service = new Qibbs_Ticket_Service();
        $category_service = new Qibbs_Ticket_Category_Service();
        $notices = array();

        // 解析列表筛选条件
        $status = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
        $category_id = isset( $_GET['category_id'] ) ? absint( $_GET['category_id'] ) : 0;
        $priority = isset( $_GET['priority'] ) ? sanitize_text_field( wp_unslash( $_GET['priority'] ) ) : '';
        $search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
        $assignee_id_raw = isset( $_GET['assignee_id'] ) ? sanitize_text_field( wp_unslash( $_GET['assignee_id'] ) ) : '';
        $assignee_id = ( '' === $assignee_id_raw ) ? '' : (int) $assignee_id_raw;
        $paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

        // 检查是否正在查看单张工单详情
        $ticket_id = isset( $_GET['ticket_id'] ) ? absint( $_GET['ticket_id'] ) : 0;
        if ( $ticket_id ) {
            self::render_ticket_detail( $ticket_id );
            return;
        }

        if ( isset( $_POST['qibbs_ticket_bulk_submit'] ) && check_admin_referer( 'qibbs_ticket_bulk_action' ) ) {
            $bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
            $bulk_assignee_id = isset( $_POST['bulk_assignee_id'] ) ? absint( wp_unslash( $_POST['bulk_assignee_id'] ) ) : 0;
            $bulk_ticket_ids = isset( $_POST['ticket_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ticket_ids'] ) ) : array();

            $bulk_result = $ticket_service->process_bulk_action(
                $bulk_ticket_ids,
                $bulk_action,
                $bulk_assignee_id,
                get_current_user_id()
            );

            $notices[] = array(
                'type'    => ! empty( $bulk_result['success'] ) ? 'success' : 'error',
                'message' => isset( $bulk_result['message'] ) ? $bulk_result['message'] : __( '批量操作失败', 'qibbs-community' ),
            );
        }

        $summary_metrics = $ticket_service->get_summary_metrics();
        $sla_dashboard = $ticket_service->get_sla_dashboard( 12 );

        // 查询工单记录列表
        $result = $ticket_service->get_tickets( array(
            'status'      => $status,
            'category_id' => $category_id,
            'priority'    => $priority,
            'search'      => $search,
            'assignee_id' => $assignee_id,
            'page'        => $paged,
            'per_page'    => 20,
        ) );

        $tickets = $result['tickets'];
        $has_more = ! empty( $result['has_more'] );

        // 获取用于筛选的工单分类字典
        $categories = $category_service->get_categories( false );
        $agents = self::get_ticket_agents();

        ?>
        <div class="wrap qibbs-admin-page qibbs-tickets-admin">
            <h1 class="wp-heading-inline"><?php _e( '工单管理', 'qibbs-community' ); ?></h1>
            
            <hr class="wp-header-end">

            <?php foreach ( $notices as $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
            <?php endforeach; ?>

            <div class="qibbs-ticket-overview" style="margin: 0 0 16px;">
                <div class="qibbs-ticket-overview-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
                    <div class="qibbs-ticket-overview-card">
                        <div class="qibbs-ticket-overview-label"><?php _e( '工单总数', 'qibbs-community' ); ?></div>
                        <div class="qibbs-ticket-overview-value"><?php echo esc_html( $summary_metrics['total_count'] ); ?></div>
                    </div>
                    <div class="qibbs-ticket-overview-card">
                        <div class="qibbs-ticket-overview-label"><?php _e( '处理中（含待反馈/重开）', 'qibbs-community' ); ?></div>
                        <div class="qibbs-ticket-overview-value"><?php echo esc_html( $summary_metrics['open_count'] ); ?></div>
                    </div>
                    <div class="qibbs-ticket-overview-card">
                        <div class="qibbs-ticket-overview-label"><?php _e( '已解决', 'qibbs-community' ); ?></div>
                        <div class="qibbs-ticket-overview-value"><?php echo esc_html( $summary_metrics['resolved_count'] ); ?></div>
                    </div>
                    <div class="qibbs-ticket-overview-card is-danger">
                        <div class="qibbs-ticket-overview-label"><?php _e( 'SLA 超时', 'qibbs-community' ); ?></div>
                        <div class="qibbs-ticket-overview-value"><?php echo esc_html( $summary_metrics['sla_breach_count'] ); ?></div>
                    </div>
                    <div class="qibbs-ticket-overview-card">
                        <div class="qibbs-ticket-overview-label"><?php _e( '首响即将到期', 'qibbs-community' ); ?></div>
                        <div class="qibbs-ticket-overview-value"><?php echo esc_html( $sla_dashboard['first_response_due_soon_count'] ); ?></div>
                    </div>
                    <div class="qibbs-ticket-overview-card is-danger">
                        <div class="qibbs-ticket-overview-label"><?php _e( '首响已超时', 'qibbs-community' ); ?></div>
                        <div class="qibbs-ticket-overview-value"><?php echo esc_html( $sla_dashboard['first_response_overdue_count'] ); ?></div>
                    </div>
                    <div class="qibbs-ticket-overview-card">
                        <div class="qibbs-ticket-overview-label"><?php _e( '解决即将到期', 'qibbs-community' ); ?></div>
                        <div class="qibbs-ticket-overview-value"><?php echo esc_html( $sla_dashboard['resolution_due_soon_count'] ); ?></div>
                    </div>
                    <div class="qibbs-ticket-overview-card is-danger">
                        <div class="qibbs-ticket-overview-label"><?php _e( '未分配开放工单', 'qibbs-community' ); ?></div>
                        <div class="qibbs-ticket-overview-value"><?php echo esc_html( $sla_dashboard['unassigned_open_count'] ); ?></div>
                    </div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin:0 0 18px;">
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                    <h2 style="margin:0 0 12px;"><?php esc_html_e( 'SLA 预警看板', 'qibbs-community' ); ?></h2>
                    <table class="widefat striped" style="margin:0;">
                        <thead>
                            <tr>
                                <th style="width:72px;">ID</th>
                                <th><?php esc_html_e( '标题', 'qibbs-community' ); ?></th>
                                <th style="width:120px;"><?php esc_html_e( '风险', 'qibbs-community' ); ?></th>
                                <th style="width:110px;"><?php esc_html_e( '负责人', 'qibbs-community' ); ?></th>
                                <th style="width:150px;"><?php esc_html_e( '到期/状态', 'qibbs-community' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $sla_dashboard['watchlist'] ) ) : ?>
                            <tr><td colspan="5"><?php esc_html_e( '当前没有需要优先跟进的工单。', 'qibbs-community' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $sla_dashboard['watchlist'] as $watch_item ) : ?>
                                <?php $watch_assignee = ! empty( $watch_item['assignee_id'] ) ? get_userdata( $watch_item['assignee_id'] ) : null; ?>
                                <tr>
                                    <td>#<?php echo esc_html( $watch_item['ticket_id'] ); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-tickets&ticket_id=' . (int) $watch_item['ticket_id'] ) ); ?>">
                                            <?php echo esc_html( $watch_item['subject'] ); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html( $watch_item['risk_label'] ); ?></td>
                                    <td><?php echo $watch_assignee ? esc_html( $watch_assignee->display_name ) : esc_html__( '未分配', 'qibbs-community' ); ?></td>
                                    <td>
                                        <?php if ( ! empty( $watch_item['deadline_at'] ) ) : ?>
                                            <?php echo esc_html( $watch_item['deadline_at'] ); ?>
                                        <?php else : ?>
                                            <?php esc_html_e( '待分配', 'qibbs-community' ); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                    <h2 style="margin:0 0 12px;"><?php esc_html_e( 'SLA 均值', 'qibbs-community' ); ?></h2>
                    <div style="display:grid;gap:12px;">
                        <div style="border:1px solid #f0f0f1;border-radius:8px;padding:12px;">
                            <strong><?php esc_html_e( '平均首响', 'qibbs-community' ); ?></strong>
                            <div style="font-size:22px;margin-top:6px;"><?php echo esc_html( $sla_dashboard['avg_first_response_minutes'] ); ?> <?php esc_html_e( '分钟', 'qibbs-community' ); ?></div>
                        </div>
                        <div style="border:1px solid #f0f0f1;border-radius:8px;padding:12px;">
                            <strong><?php esc_html_e( '平均解决', 'qibbs-community' ); ?></strong>
                            <div style="font-size:22px;margin-top:6px;"><?php echo esc_html( $sla_dashboard['avg_resolution_minutes'] ); ?> <?php esc_html_e( '分钟', 'qibbs-community' ); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="qibbs-ticket-filters" style="margin: 15px 0;">
                <form method="get" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                    <input type="hidden" name="page" value="qibbs-tickets">
                    
                    <select name="status">
                        <option value=""><?php _e( '全部状态', 'qibbs-community' ); ?></option>
                        <option value="pending" <?php selected( $status, 'pending' ); ?>><?php _e( '待处理', 'qibbs-community' ); ?></option>
                        <option value="processing" <?php selected( $status, 'processing' ); ?>><?php _e( '处理中', 'qibbs-community' ); ?></option>
                        <option value="pending_customer" <?php selected( $status, 'pending_customer' ); ?>><?php _e( '待用户反馈', 'qibbs-community' ); ?></option>
                        <option value="reopened" <?php selected( $status, 'reopened' ); ?>><?php _e( '已重开', 'qibbs-community' ); ?></option>
                        <option value="resolved" <?php selected( $status, 'resolved' ); ?>><?php _e( '已解决', 'qibbs-community' ); ?></option>
                        <option value="closed" <?php selected( $status, 'closed' ); ?>><?php _e( '已关闭', 'qibbs-community' ); ?></option>
                    </select>

                    <select name="category_id">
                        <option value=""><?php _e( '全部分类', 'qibbs-community' ); ?></option>
                        <?php foreach ( $categories as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat->id ); ?>" <?php selected( $category_id, $cat->id ); ?>>
                                <?php echo esc_html( $cat->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="priority">
                        <option value=""><?php _e( '全部优先级', 'qibbs-community' ); ?></option>
                        <option value="urgent" <?php selected( $priority, 'urgent' ); ?>><?php _e( '紧急', 'qibbs-community' ); ?></option>
                        <option value="high" <?php selected( $priority, 'high' ); ?>><?php _e( '高', 'qibbs-community' ); ?></option>
                        <option value="normal" <?php selected( $priority, 'normal' ); ?>><?php _e( '普通', 'qibbs-community' ); ?></option>
                        <option value="low" <?php selected( $priority, 'low' ); ?>><?php _e( '低', 'qibbs-community' ); ?></option>
                    </select>

                    <select name="assignee_id">
                        <option value=""><?php _e( '全部负责人', 'qibbs-community' ); ?></option>
                        <option value="-1" <?php selected( $assignee_id, -1 ); ?>><?php _e( '未分配', 'qibbs-community' ); ?></option>
                        <?php foreach ( $agents as $agent ) : ?>
                            <option value="<?php echo esc_attr( $agent->ID ); ?>" <?php selected( $assignee_id, $agent->ID ); ?>>
                                <?php echo esc_html( $agent->display_name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="search" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( '按标题/内容搜索', 'qibbs-community' ); ?>" style="min-width: 220px;">

                    <button type="submit" class="button"><?php _e( '筛选', 'qibbs-community' ); ?></button>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-tickets' ) ); ?>" class="button button-secondary"><?php _e( '重置', 'qibbs-community' ); ?></a>
                </form>
            </div>

            <form method="post">
                <?php wp_nonce_field( 'qibbs_ticket_bulk_action' ); ?>

                <div class="tablenav top" style="display:flex;align-items:center;gap:8px;justify-content:space-between;flex-wrap:wrap;margin-bottom:8px;">
                    <div class="alignleft actions bulkactions" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <select name="bulk_action" required>
                            <option value=""><?php _e( '批量操作', 'qibbs-community' ); ?></option>
                            <option value="assign"><?php _e( '批量分配', 'qibbs-community' ); ?></option>
                            <option value="unassign"><?php _e( '批量取消分配', 'qibbs-community' ); ?></option>
                            <option value="set_pending"><?php _e( '设为待处理', 'qibbs-community' ); ?></option>
                            <option value="set_processing"><?php _e( '设为处理中', 'qibbs-community' ); ?></option>
                            <option value="set_pending_customer"><?php _e( '设为待用户反馈', 'qibbs-community' ); ?></option>
                            <option value="set_resolved"><?php _e( '设为已解决', 'qibbs-community' ); ?></option>
                            <option value="set_closed"><?php _e( '设为已关闭', 'qibbs-community' ); ?></option>
                        </select>
                        <select name="bulk_assignee_id">
                            <option value="0"><?php _e( '选择负责人（分配时生效）', 'qibbs-community' ); ?></option>
                            <?php foreach ( $agents as $agent ) : ?>
                                <option value="<?php echo esc_attr( $agent->ID ); ?>"><?php echo esc_html( $agent->display_name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button action" name="qibbs_ticket_bulk_submit"><?php _e( '执行', 'qibbs-community' ); ?></button>
                    </div>
                </div>

                <!-- 工单列表表格 -->
                <div class="qibbs-admin-table-scroll qibbs-ticket-list-scroll">
                    <table class="wp-list-table widefat fixed striped qibbs-ticket-list-table">
                        <thead>
                            <tr>
                                <td class="check-column"><input type="checkbox" id="qibbs-ticket-select-all"></td>
                                <th class="qibbs-ticket-col-id"><?php _e( 'ID', 'qibbs-community' ); ?></th>
                                <th class="qibbs-ticket-col-title"><?php _e( '标题', 'qibbs-community' ); ?></th>
                                <th class="qibbs-ticket-col-category"><?php _e( '分类', 'qibbs-community' ); ?></th>
                                <th class="qibbs-ticket-col-priority"><?php _e( '优先级', 'qibbs-community' ); ?></th>
                                <th class="qibbs-ticket-col-user"><?php _e( '用户', 'qibbs-community' ); ?></th>
                                <th class="qibbs-ticket-col-assignee"><?php _e( '负责人', 'qibbs-community' ); ?></th>
                                <th class="qibbs-ticket-col-status"><?php _e( '状态', 'qibbs-community' ); ?></th>
                                <th class="qibbs-ticket-col-sla"><?php _e( 'SLA', 'qibbs-community' ); ?></th>
                                <th class="qibbs-ticket-col-publish"><?php _e( '公开授权', 'qibbs-community' ); ?></th>
                                <th class="qibbs-ticket-col-created"><?php _e( '创建时间', 'qibbs-community' ); ?></th>
                                <th class="qibbs-ticket-col-actions"><?php _e( '操作', 'qibbs-community' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $tickets ) ) : ?>
                                <tr>
                                    <td colspan="12" style="text-align: center; padding: 20px;">
                                        <?php _e( '暂无工单', 'qibbs-community' ); ?>
                                    </td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ( $tickets as $ticket ) : ?>
                                    <?php
                                    $author = get_userdata( $ticket->user_id );
                                    $assignee = ! empty( $ticket->assignee_id ) ? get_userdata( $ticket->assignee_id ) : null;
                                    $category = $category_service->get( $ticket->category_id );
                                    $sla_state = $ticket_service->get_sla_state( $ticket );
                                    ?>
                                    <tr>
                                        <th class="check-column"><input type="checkbox" name="ticket_ids[]" value="<?php echo esc_attr( $ticket->id ); ?>"></th>
                                        <td class="qibbs-ticket-col-id"><?php echo esc_html( $ticket->id ); ?></td>
                                        <td class="qibbs-ticket-title-cell">
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-tickets&ticket_id=' . $ticket->id ) ); ?>">
                                                <strong><?php echo esc_html( $ticket->subject ); ?></strong>
                                            </a>
                                        </td>
                                        <td class="qibbs-ticket-col-category">
                                            <?php if ( $category ) : ?>
                                                <span class="qibbs-category-badge"><?php echo esc_html( $category->name ); ?></span>
                                            <?php else : ?>
                                                <span style="color: #999;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="qibbs-ticket-col-priority"><?php echo esc_html( $ticket_service->get_priority_label( $ticket->priority ) ); ?></td>
                                        <td class="qibbs-ticket-col-user"><?php echo $author ? esc_html( $author->display_name ) : __( '未知用户', 'qibbs-community' ); ?></td>
                                        <td class="qibbs-ticket-col-assignee"><?php echo $assignee ? esc_html( $assignee->display_name ) : '<span style="color:#999;">' . esc_html__( '未分配', 'qibbs-community' ) . '</span>'; ?></td>
                                        <td class="qibbs-ticket-col-status"><?php echo self::get_status_badge( $ticket->status ); ?></td>
                                        <td class="qibbs-ticket-col-sla"><?php echo self::get_sla_badge( $sla_state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                        <td class="qibbs-ticket-col-publish">
                                            <?php if ( $ticket->allow_publish ) : ?>
                                                <span style="color: #46b450; display: inline-flex; align-items: center; gap: 4px;"><span class="dashicons dashicons-yes-alt" style="font-size:16px;width:16px;height:16px;"></span><?php _e( '已授权', 'qibbs-community' ); ?></span>
                                            <?php else : ?>
                                                <span style="color: #999; display: inline-flex; align-items: center; gap: 4px;"><span class="dashicons dashicons-dismiss" style="font-size:16px;width:16px;height:16px;"></span><?php _e( '未授权', 'qibbs-community' ); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="qibbs-ticket-col-created"><?php echo esc_html( $ticket->created_at ); ?></td>
                                        <td class="qibbs-ticket-col-actions">
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-tickets&ticket_id=' . $ticket->id ) ); ?>" class="button button-small">
                                                <?php _e( '查看', 'qibbs-community' ); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>

            <!-- Pagination -->
            <?php if ( $paged > 1 || $has_more ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages" style="display:flex;align-items:center;gap:8px;">
                        <?php if ( $paged > 1 ) : ?>
                            <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">
                                <?php esc_html_e( '上一页', 'qibbs-community' ); ?>
                            </a>
                        <?php endif; ?>
                        <span class="displaying-num"><?php printf( esc_html__( '第 %d 页', 'qibbs-community' ), (int) $paged ); ?></span>
                        <?php if ( $has_more ) : ?>
                            <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>">
                                <?php esc_html_e( '下一页', 'qibbs-community' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <script>
            (function() {
                var all = document.getElementById('qibbs-ticket-select-all');
                if (!all) { return; }
                all.addEventListener('change', function() {
                    document.querySelectorAll('input[name="ticket_ids[]"]').forEach(function(el) {
                        el.checked = all.checked;
                    });
                });
            })();
            </script>
        </div>
        <?php
    }

    /**
     * 渲染单张工单详情与沟通流页面
     */
    private static function render_ticket_detail( $ticket_id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '权限不足', 'qibbs-community' ) );
        }

        $ticket_service = new Qibbs_Ticket_Service();
        $category_service = new Qibbs_Ticket_Category_Service();
        
        $ticket = $ticket_service->get_ticket( $ticket_id );
        
        if ( ! $ticket ) {
            echo '<div class="wrap qibbs-admin-page qibbs-tickets-admin"><div class="notice notice-error"><p>' . __( '工单不存在', 'qibbs-community' ) . '</p></div></div>';
            return;
        }

        $replies = $ticket_service->get_replies( $ticket_id, true ); // 包含后台专属内部备注
        $categories = $category_service->get_categories( true );
        $agents = self::get_ticket_agents();
        $sla_state = $ticket_service->get_sla_state( $ticket );
        
        // 获取可转帖子的版块下拉列表
        global $wpdb;
        $forums = $wpdb->get_results( $wpdb->prepare( "SELECT id, name FROM " . Qibbs_Database::forums() . " WHERE 1 = %d ORDER BY sort_order ASC, name ASC", 1 ) );

        // 处理后台表单提交
        if ( isset( $_POST['qibbs_ticket_action'] ) && check_admin_referer( 'qibbs_ticket_detail' ) ) {
            $action = sanitize_text_field( wp_unslash( $_POST['qibbs_ticket_action'] ) );
            
            switch ( $action ) {
                case 'add_reply':
                    $content = isset( $_POST['reply_content'] ) ? wp_kses_post( wp_unslash( $_POST['reply_content'] ) ) : '';
                    $is_internal = ! empty( $_POST['is_internal'] );
                    $set_pending_customer = ! empty( $_POST['set_pending_customer'] );
                    if ( ! empty( $content ) ) {
                        $reply_id = $ticket_service->add_reply( $ticket_id, get_current_user_id(), $content, $is_internal );
                        if ( $reply_id ) {
                            if ( ! $is_internal && $set_pending_customer ) {
                                $ticket_service->update_status( $ticket_id, Qibbs_Ticket_Service::STATUS_PENDING_CUSTOMER );
                            }
                            echo '<div class="notice notice-success"><p>' . __( '回复已添加', 'qibbs-community' ) . '</p></div>';
                        } else {
                            echo '<div class="notice notice-error"><p>' . __( '回复发送失败，请稍后重试。', 'qibbs-community' ) . '</p></div>';
                        }
                        $ticket = $ticket_service->get_ticket( $ticket_id );
                        $sla_state = $ticket_service->get_sla_state( $ticket );
                        $replies = $ticket_service->get_replies( $ticket_id, true );
                    }
                    break;

                case 'update_status':
                    $new_status = isset( $_POST['ticket_status'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_status'] ) ) : '';
                    $ticket_service->update_status( $ticket_id, $new_status );
                    $ticket = $ticket_service->get_ticket( $ticket_id );
                    $sla_state = $ticket_service->get_sla_state( $ticket );
                    echo '<div class="notice notice-success"><p>' . __( '状态已更新', 'qibbs-community' ) . '</p></div>';
                    break;

                case 'assign_ticket':
                    $assignee_id = isset( $_POST['assignee_id'] ) ? absint( wp_unslash( $_POST['assignee_id'] ) ) : 0;
                    $ticket_service->assign_ticket( $ticket_id, $assignee_id );
                    $ticket = $ticket_service->get_ticket( $ticket_id );
                    $sla_state = $ticket_service->get_sla_state( $ticket );
                    echo '<div class="notice notice-success"><p>' . __( '负责人已更新', 'qibbs-community' ) . '</p></div>';
                    break;

                case 'publish':
                    $forum_id = isset( $_POST['publish_forum_id'] ) ? absint( wp_unslash( $_POST['publish_forum_id'] ) ) : 0;
                    $edited_content = isset( $_POST['publish_content'] ) ? wp_kses_post( wp_unslash( $_POST['publish_content'] ) ) : '';
                    $include_replies = isset( $_POST['include_replies'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['include_replies'] ) ) : array();
                    $include_attachments = isset( $_POST['include_attachments'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['include_attachments'] ) ) : array();
                    
                    $result = $ticket_service->publish_as_post( $ticket_id, $forum_id, array(
                        'content'             => $edited_content,
                        'include_replies'     => $include_replies,
                        'include_attachments' => $include_attachments,
                    ) );
                    
                    if ( is_wp_error( $result ) ) {
                        echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-success"><p>' . __( '工单已成功发布到社区', 'qibbs-community' ) . '</p></div>';
                        $ticket = $ticket_service->get_ticket( $ticket_id );
                        $sla_state = $ticket_service->get_sla_state( $ticket );
                    }
                    break;
            }
        }

        ?>
        <div class="wrap qibbs-admin-page qibbs-tickets-admin">
            <h1 class="wp-heading-inline">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-tickets' ) ); ?>">&larr;</a>
                <?php _e( '工单详情', 'qibbs-community' ); ?> #<?php echo esc_html( $ticket->id ); ?>
            </h1>
            
            <hr class="wp-header-end">

            <div style="display: flex; gap: 20px; margin-top: 20px;">
                <!-- 工单主内容 -->
                <div style="flex: 2;">
                    <!-- 工单基础信息 -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2><?php echo esc_html( $ticket->subject ); ?></h2>
                        </div>
                        <div class="inside">
                            <div style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
                                <?php echo wp_kses_post( $ticket->content ); ?>
                            </div>
                            <?php 
                            // 渲染工单媒体附件
                            if ( ! empty( $ticket->attachments ) ) :
                                $attachment_ids = array_filter( array_map( 'absint', explode( ',', $ticket->attachments ) ) );
                                if ( ! empty( $attachment_ids ) ) :
                                    $media_table = Qibbs_Database::media();
                                    $ids_placeholder = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
                                    $media_items = $wpdb->get_results( $wpdb->prepare(
                                        "SELECT id, file_url FROM {$media_table} WHERE user_id = %d AND id IN ({$ids_placeholder})",
                                        array_merge( array( (int) $ticket->user_id ), $attachment_ids )
                                    ) );
                                    if ( ! empty( $media_items ) ) :
                            ?>
                                <div style="margin-bottom: 15px;">
                                    <strong><?php _e( '附件图片', 'qibbs-community' ); ?>：</strong>
                                    <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px;">
                                        <?php foreach ( $media_items as $media ) : ?>
                                            <div class="qibbs-attachment-item" data-media-id="<?php echo esc_attr( $media->id ); ?>" style="position: relative;">
                                                <a href="<?php echo esc_url( $media->file_url ); ?>" target="_blank" style="display: block;">
                                                    <img src="<?php echo esc_url( $media->file_url ); ?>" alt="" style="max-width: 150px; max-height: 150px; border-radius: 4px; border: 1px solid #ddd;">
                                                </a>
                                                <button type="button" class="qibbs-delete-attachment" 
                                                    data-media-id="<?php echo esc_attr( $media->id ); ?>" 
                                                    data-ticket-id="<?php echo esc_attr( $ticket_id ); ?>"
                                                    style="position: absolute; top: -8px; right: -8px; width: 24px; height: 24px; border-radius: 50%; background: #d63638; color: #fff; border: none; cursor: pointer; font-size: 14px; line-height: 1;" 
                                                    title="<?php esc_attr_e( '删除此图片', 'qibbs-community' ); ?>">×</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; endif; endif; ?>
                            <p style="color: #666; font-size: 12px;">
                                <?php 
                                $author_name = $ticket->author ? $ticket->author->display_name : __( '未知用户', 'qibbs-community' );
                                printf( __( '由 %s 于 %s 提交', 'qibbs-community' ), '<strong>' . esc_html( $author_name ) . '</strong>', esc_html( $ticket->created_at ) );
                                ?>
                            </p>
                        </div>
                    </div>

                    <!-- Replies -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2><?php _e( '对话记录', 'qibbs-community' ); ?></h2>
                        </div>
                        <div class="inside">
                            <?php if ( empty( $replies ) ) : ?>
                                <p style="color: #666;"><?php _e( '暂无回复', 'qibbs-community' ); ?></p>
                            <?php else : ?>
                                <?php foreach ( $replies as $reply ) : ?>
                                    <div style="padding: 15px; margin-bottom: 10px; border-radius: 4px; <?php echo $reply->is_admin_reply ? 'background: #e7f3ff; border-left: 3px solid #2271b1;' : 'background: #f9f9f9;'; ?>">
                                        <?php if ( $reply->is_internal ) : ?>
                                            <span style="background: #d63638; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-right: 8px;">
                                                <?php _e( '内部备注', 'qibbs-community' ); ?>
                                            </span>
                                        <?php endif; ?>
                                        <div style="margin-top: 8px;">
                                            <?php echo wp_kses_post( $reply->content ); ?>
                                        </div>
                                        <p style="color: #666; font-size: 12px; margin-top: 10px; margin-bottom: 0;">
                                            <?php 
                                            $reply_author = $reply->author ? $reply->author->display_name : __( '未知用户', 'qibbs-community' );
                                            echo esc_html( $reply_author ) . ' · ' . esc_html( $reply->created_at );
                                            ?>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <!-- 添加回复表单 -->
                            <form method="post" style="margin-top: 20px;">
                                <?php wp_nonce_field( 'qibbs_ticket_detail' ); ?>
                                <input type="hidden" name="qibbs_ticket_action" value="add_reply">
                                
                                <textarea name="reply_content" rows="4" style="width: 100%;" placeholder="<?php esc_attr_e( '输入回复内容...', 'qibbs-community' ); ?>"></textarea>
                                
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                                    <div style="display:flex; align-items:center; gap:14px;">
                                        <label>
                                            <input type="checkbox" name="is_internal" value="1">
                                            <?php _e( '内部备注（用户不可见）', 'qibbs-community' ); ?>
                                        </label>
                                        <label>
                                            <input type="checkbox" name="set_pending_customer" value="1">
                                            <?php _e( '回复后设为待用户反馈', 'qibbs-community' ); ?>
                                        </label>
                                    </div>
                                    <button type="submit" class="button button-primary"><?php _e( '发送回复', 'qibbs-community' ); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- 转公开贴操作区 -->
                    <?php if ( ! $ticket->is_published && $ticket->status === 'resolved' ) : ?>
                        <div class="postbox">
                            <div class="postbox-header">
                                <h2><?php _e( '转为公开帖子', 'qibbs-community' ); ?></h2>
                            </div>
                            <div class="inside">
                                <?php if ( ! $ticket->allow_publish ) : ?>
                                    <div class="notice notice-warning" style="margin: 0 0 15px;">
                                        <p><span class="dashicons dashicons-warning" style="color: #dba617; vertical-align: text-bottom; margin-right: 4px;"></span><?php _e( '用户未授权公开此工单，请谨慎操作', 'qibbs-community' ); ?></p>
                                    </div>
                                <?php endif; ?>
                                
                                <form method="post">
                                    <?php wp_nonce_field( 'qibbs_ticket_detail' ); ?>
                                    <input type="hidden" name="qibbs_ticket_action" value="publish">
                                    
                                    <p>
                                        <label><strong><?php _e( '选择目标圈子', 'qibbs-community' ); ?></strong></label>
                                        <select name="publish_forum_id" required style="width: 100%; margin-top: 5px;">
                                            <option value=""><?php _e( '-- 选择圈子 --', 'qibbs-community' ); ?></option>
                                            <?php foreach ( $forums as $forum ) : ?>
                                                <option value="<?php echo esc_attr( $forum->id ); ?>">
                                                    <?php echo esc_html( $forum->name ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </p>
                                    
                                    <p>
                                        <label><strong><?php _e( '编辑公开内容', 'qibbs-community' ); ?></strong></label>
                                        <textarea name="publish_content" rows="8" style="width: 100%; margin-top: 5px;"><?php echo esc_textarea( $ticket->content ); ?></textarea>
                                        <span class="description"><?php _e( '可在此编辑内容，删除敏感信息后再发布', 'qibbs-community' ); ?></span>
                                    </p>
                                    
                                    <?php 
                                    // 获取工单关联的附件列表
                                    $publish_media_items = array();
                                    if ( ! empty( $ticket->attachments ) ) :
                                        $pub_attachment_ids = array_filter( array_map( 'absint', explode( ',', $ticket->attachments ) ) );
                                        if ( ! empty( $pub_attachment_ids ) ) :
                                            $pub_ids_placeholder = implode( ',', array_fill( 0, count( $pub_attachment_ids ), '%d' ) );
                                            $publish_media_items = $wpdb->get_results( $wpdb->prepare(
                                                "SELECT id, file_url FROM " . Qibbs_Database::media() . " WHERE user_id = %d AND id IN ({$pub_ids_placeholder})",
                                                array_merge( array( (int) $ticket->user_id ), $pub_attachment_ids )
                                            ) );
                                        endif;
                                    endif;
                                    
                                    if ( ! empty( $publish_media_items ) ) : ?>
                                        <p>
                                            <label><strong><?php _e( '选择要包含的图片', 'qibbs-community' ); ?></strong></label>
                                            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 8px;">
                                                <?php foreach ( $publish_media_items as $pub_media ) : ?>
                                                    <label style="position: relative; cursor: pointer;">
                                                        <input type="checkbox" name="include_attachments[]" value="<?php echo esc_attr( $pub_media->id ); ?>" checked style="position: absolute; top: 5px; left: 5px; z-index: 1;">
                                                        <img src="<?php echo esc_url( $pub_media->file_url ); ?>" alt="" style="width: 80px; height: 80px; object-fit: cover; border-radius: 4px; border: 2px solid #2271b1;">
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                            <span class="description"><?php _e( '取消勾选以排除某些图片', 'qibbs-community' ); ?></span>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <?php if ( ! empty( $replies ) ) : ?>
                                        <p>
                                            <label><strong><?php _e( '选择要包含的回复', 'qibbs-community' ); ?></strong></label>
                                            <?php foreach ( $replies as $reply ) : ?>
                                                <?php if ( ! $reply->is_internal ) : ?>
                                                    <div style="margin: 5px 0;">
                                                        <label>
                                                            <input type="checkbox" name="include_replies[]" value="<?php echo esc_attr( $reply->id ); ?>">
                                                            <?php echo esc_html( mb_substr( wp_strip_all_tags( $reply->content ), 0, 50 ) ); ?>...
                                                        </label>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <p>
                                        <button type="submit" class="button button-primary" onclick="return confirm('<?php esc_attr_e( '确定要将此工单公开到社区吗？', 'qibbs-community' ); ?>');">
                                            <?php _e( '确认发布到社区', 'qibbs-community' ); ?>
                                        </button>
                                    </p>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ( $ticket->is_published ) : ?>
                        <div class="notice notice-success" style="margin: 10px 0;">
                            <p>
                                <span class="dashicons dashicons-yes-alt" style="color: #46b450; vertical-align: text-bottom; margin-right: 4px;"></span><?php _e( '此工单已发布到社区', 'qibbs-community' ); ?>
                                (<?php printf( __( '帖子 ID: %d', 'qibbs-community' ), $ticket->published_post_id ); ?>)
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sidebar -->
                <div style="flex: 1; min-width: 280px;">
                    <!-- 工单状态卡片 -->
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2><?php _e( '工单信息', 'qibbs-community' ); ?></h2>
                        </div>
                        <div class="inside">
                            <table class="form-table" style="margin: 0;">
                                <tr>
                                    <th style="padding: 8px 0;"><?php _e( '状态', 'qibbs-community' ); ?></th>
                                    <td style="padding: 8px 0;"><?php echo self::get_status_badge( $ticket->status ); ?></td>
                                </tr>
                                <tr>
                                    <th style="padding: 8px 0;"><?php _e( '分类', 'qibbs-community' ); ?></th>
                                    <td style="padding: 8px 0;"><?php echo $ticket->category ? esc_html( $ticket->category->name ) : '-'; ?></td>
                                </tr>
                                <tr>
                                    <th style="padding: 8px 0;"><?php _e( '优先级', 'qibbs-community' ); ?></th>
                                    <td style="padding: 8px 0;"><?php echo esc_html( $ticket_service->get_priority_label( $ticket->priority ) ); ?></td>
                                </tr>
                                <tr>
                                    <th style="padding: 8px 0;"><?php _e( '负责人', 'qibbs-community' ); ?></th>
                                    <td style="padding: 8px 0;">
                                        <?php
                                        if ( ! empty( $ticket->assignee_id ) ) {
                                            $assignee = get_userdata( $ticket->assignee_id );
                                            echo $assignee ? esc_html( $assignee->display_name ) : '<span style="color:#999;">' . esc_html__( '未分配', 'qibbs-community' ) . '</span>';
                                        } else {
                                            echo '<span style="color:#999;">' . esc_html__( '未分配', 'qibbs-community' ) . '</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th style="padding: 8px 0;"><?php _e( 'SLA状态', 'qibbs-community' ); ?></th>
                                    <td style="padding: 8px 0;"><?php echo self::get_sla_badge( $sla_state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                                </tr>
                                <tr>
                                    <th style="padding: 8px 0;"><?php _e( '首响截止', 'qibbs-community' ); ?></th>
                                    <td style="padding: 8px 0;"><?php echo ! empty( $ticket->first_response_deadline ) ? esc_html( $ticket->first_response_deadline ) : '-'; ?></td>
                                </tr>
                                <tr>
                                    <th style="padding: 8px 0;"><?php _e( '解决截止', 'qibbs-community' ); ?></th>
                                    <td style="padding: 8px 0;"><?php echo ! empty( $ticket->resolution_deadline ) ? esc_html( $ticket->resolution_deadline ) : '-'; ?></td>
                                </tr>
                                <tr>
                                    <th style="padding: 8px 0;"><?php _e( '公开授权', 'qibbs-community' ); ?></th>
                                    <td style="padding: 8px 0;">
                                        <?php if ( $ticket->allow_publish ) : ?>
                                            <span style="color: #46b450; display: inline-flex; align-items: center; gap: 4px;"><span class="dashicons dashicons-yes-alt" style="font-size:16px;width:16px;height:16px;"></span><?php _e( '已授权', 'qibbs-community' ); ?></span>
                                        <?php else : ?>
                                            <span style="color: #999; display: inline-flex; align-items: center; gap: 4px;"><span class="dashicons dashicons-dismiss" style="font-size:16px;width:16px;height:16px;"></span><?php _e( '未授权', 'qibbs-community' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>

                            <!-- 更新工单状态表单 -->
                            <form method="post" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                                <?php wp_nonce_field( 'qibbs_ticket_detail' ); ?>
                                <input type="hidden" name="qibbs_ticket_action" value="update_status">
                                
                                <label><strong><?php _e( '更改状态', 'qibbs-community' ); ?></strong></label>
                                <select name="ticket_status" style="width: 100%; margin: 8px 0;">
                                    <?php foreach ( $ticket_service->get_status_labels() as $status_key => $status_label ) : ?>
                                        <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $ticket->status, $status_key ); ?>>
                                            <?php echo esc_html( $status_label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="button" style="width: 100%;"><?php _e( '更新状态', 'qibbs-community' ); ?></button>
                            </form>

                            <!-- 工单指派表单 -->
                            <form method="post" style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #ddd;">
                                <?php wp_nonce_field( 'qibbs_ticket_detail' ); ?>
                                <input type="hidden" name="qibbs_ticket_action" value="assign_ticket">
                                <label><strong><?php _e( '分配负责人', 'qibbs-community' ); ?></strong></label>
                                <select name="assignee_id" style="width: 100%; margin: 8px 0;">
                                    <option value="0"><?php _e( '-- 未分配 --', 'qibbs-community' ); ?></option>
                                    <?php foreach ( $agents as $agent ) : ?>
                                        <option value="<?php echo esc_attr( $agent->ID ); ?>" <?php selected( (int) $ticket->assignee_id, (int) $agent->ID ); ?>>
                                            <?php echo esc_html( $agent->display_name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="button" style="width: 100%;"><?php _e( '更新负责人', 'qibbs-community' ); ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染工单分类管理页面
     */
    public static function render_categories_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '权限不足', 'qibbs-community' ) );
        }

        $category_service = new Qibbs_Ticket_Category_Service();

        // 处理后台表单提交
        if ( isset( $_POST['qibbs_category_action'] ) && check_admin_referer( 'qibbs_category_action' ) ) {
            $action = sanitize_text_field( wp_unslash( $_POST['qibbs_category_action'] ) );
            
            switch ( $action ) {
                case 'add':
                    $name = isset( $_POST['category_name'] ) ? sanitize_text_field( wp_unslash( $_POST['category_name'] ) ) : '';
                    $description = isset( $_POST['category_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['category_description'] ) ) : '';
                    $sla_first_response = isset( $_POST['category_sla_first_response_hours'] ) ? absint( wp_unslash( $_POST['category_sla_first_response_hours'] ) ) : 4;
                    $sla_resolution = isset( $_POST['category_sla_resolution_hours'] ) ? absint( wp_unslash( $_POST['category_sla_resolution_hours'] ) ) : 48;
                    if ( ! empty( $name ) ) {
                        $category_service->create( $name, '', $description, 0, $sla_first_response, $sla_resolution );
                        echo '<div class="notice notice-success"><p>' . __( '分类已创建', 'qibbs-community' ) . '</p></div>';
                    }
                    break;

                case 'edit':
                    $id = isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) ) : 0;
                    $name = isset( $_POST['category_name'] ) ? sanitize_text_field( wp_unslash( $_POST['category_name'] ) ) : '';
                    $description = isset( $_POST['category_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['category_description'] ) ) : '';
                    $is_active = ! empty( $_POST['category_active'] );
                    $sla_first_response = isset( $_POST['category_sla_first_response_hours'] ) ? absint( wp_unslash( $_POST['category_sla_first_response_hours'] ) ) : 4;
                    $sla_resolution = isset( $_POST['category_sla_resolution_hours'] ) ? absint( wp_unslash( $_POST['category_sla_resolution_hours'] ) ) : 48;
                    $category_service->update( $id, array(
                        'name'        => $name,
                        'description' => $description,
                        'is_active'   => $is_active ? 1 : 0,
                        'sla_first_response_hours' => $sla_first_response,
                        'sla_resolution_hours' => $sla_resolution,
                    ) );
                    echo '<div class="notice notice-success"><p>' . __( '分类已更新', 'qibbs-community' ) . '</p></div>';
                    break;

                case 'delete':
                    $id = isset( $_POST['category_id'] ) ? absint( wp_unslash( $_POST['category_id'] ) ) : 0;
                    $result = $category_service->delete( $id );
                    if ( is_wp_error( $result ) ) {
                        echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-success"><p>' . __( '分类已删除', 'qibbs-community' ) . '</p></div>';
                    }
                    break;
            }
        }

        $categories = $category_service->get_categories( false );
        ?>
        <div class="wrap qibbs-admin-page qibbs-tickets-admin">
            <h1 class="wp-heading-inline"><?php _e( '工单分类管理', 'qibbs-community' ); ?></h1>
            
            <hr class="wp-header-end">

            <div style="display: flex; gap: 20px; margin-top: 20px;">
                <!-- 工单分类列表 -->
                <div style="flex: 2;">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 50px;"><?php _e( 'ID', 'qibbs-community' ); ?></th>
                                <th><?php _e( '名称', 'qibbs-community' ); ?></th>
                                <th><?php _e( '标识', 'qibbs-community' ); ?></th>
                                <th style="width: 180px;"><?php _e( 'SLA（首响/解决）', 'qibbs-community' ); ?></th>
                                <th style="width: 80px;"><?php _e( '状态', 'qibbs-community' ); ?></th>
                                <th style="width: 80px;"><?php _e( '排序', 'qibbs-community' ); ?></th>
                                <th style="width: 150px;"><?php _e( '操作', 'qibbs-community' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $categories ) ) : ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 20px;">
                                        <?php _e( '暂无分类', 'qibbs-community' ); ?>
                                    </td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ( $categories as $cat ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $cat->id ); ?></td>
                                        <td><strong><?php echo esc_html( $cat->name ); ?></strong></td>
                                        <td><code><?php echo esc_html( $cat->slug ); ?></code></td>
                                        <td>
                                            <?php
                                            $sla_first_response = ! empty( $cat->sla_first_response_hours ) ? absint( $cat->sla_first_response_hours ) : 4;
                                            $sla_resolution = ! empty( $cat->sla_resolution_hours ) ? absint( $cat->sla_resolution_hours ) : 48;
                                            echo esc_html( sprintf( '%d小时 / %d小时', $sla_first_response, $sla_resolution ) );
                                            ?>
                                        </td>
                                        <td>
                                            <?php if ( $cat->is_active ) : ?>
                                                <span style="color: #46b450; display: inline-flex; align-items: center; gap: 4px;"><span class="dashicons dashicons-yes-alt" style="font-size:16px;width:16px;height:16px;"></span><?php _e( '启用', 'qibbs-community' ); ?></span>
                                            <?php else : ?>
                                                <span style="color: #999; display: inline-flex; align-items: center; gap: 4px;"><span class="dashicons dashicons-dismiss" style="font-size:16px;width:16px;height:16px;"></span><?php _e( '禁用', 'qibbs-community' ); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( $cat->sort_order ); ?></td>
                                        <td>
                                            <button type="button" class="button button-small qibbs-edit-category" 
                                                data-id="<?php echo esc_attr( $cat->id ); ?>"
                                                data-name="<?php echo esc_attr( $cat->name ); ?>"
                                                data-description="<?php echo esc_attr( $cat->description ); ?>"
                                                data-active="<?php echo esc_attr( $cat->is_active ); ?>"
                                                data-sla-first-response="<?php echo esc_attr( ! empty( $cat->sla_first_response_hours ) ? absint( $cat->sla_first_response_hours ) : 4 ); ?>"
                                                data-sla-resolution="<?php echo esc_attr( ! empty( $cat->sla_resolution_hours ) ? absint( $cat->sla_resolution_hours ) : 48 ); ?>">
                                                <?php _e( '编辑', 'qibbs-community' ); ?>
                                            </button>
                                            <form method="post" style="display: inline;">
                                                <?php wp_nonce_field( 'qibbs_category_action' ); ?>
                                                <input type="hidden" name="qibbs_category_action" value="delete">
                                                <input type="hidden" name="category_id" value="<?php echo esc_attr( $cat->id ); ?>">
                                                <button type="submit" class="button button-small" style="color: #b32d2e;" 
                                                    onclick="return confirm('<?php esc_attr_e( '确定要删除吗？', 'qibbs-community' ); ?>');">
                                                    <?php _e( '删除', 'qibbs-community' ); ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 新增/编辑分类表单 -->
                <div style="flex: 1; min-width: 300px;">
                    <div class="postbox">
                        <div class="postbox-header">
                            <h2 id="qibbs-category-form-title"><?php _e( '添加分类', 'qibbs-community' ); ?></h2>
                        </div>
                        <div class="inside">
                            <form method="post" id="qibbs-category-form">
                                <?php wp_nonce_field( 'qibbs_category_action' ); ?>
                                <input type="hidden" name="qibbs_category_action" id="qibbs_category_action" value="add">
                                <input type="hidden" name="category_id" id="category_id" value="">
                                
                                <p>
                                    <label><strong><?php _e( '分类名称', 'qibbs-community' ); ?></strong></label>
                                    <input type="text" name="category_name" id="category_name" class="widefat" required>
                                </p>
                                
                                <p>
                                    <label><strong><?php _e( '描述', 'qibbs-community' ); ?></strong></label>
                                    <textarea name="category_description" id="category_description" class="widefat" rows="3"></textarea>
                                </p>

                                <p>
                                    <label><strong><?php _e( '首响 SLA（小时）', 'qibbs-community' ); ?></strong></label>
                                    <input type="number" name="category_sla_first_response_hours" id="category_sla_first_response_hours" class="widefat" min="1" max="720" value="4">
                                    <span class="description"><?php _e( '客服首次响应时限，默认 4 小时。', 'qibbs-community' ); ?></span>
                                </p>

                                <p>
                                    <label><strong><?php _e( '解决 SLA（小时）', 'qibbs-community' ); ?></strong></label>
                                    <input type="number" name="category_sla_resolution_hours" id="category_sla_resolution_hours" class="widefat" min="1" max="2160" value="48">
                                    <span class="description"><?php _e( '工单整体解决时限，默认 48 小时。', 'qibbs-community' ); ?></span>
                                </p>
                                
                                <p id="qibbs-category-active-field" style="display: none;">
                                    <label>
                                        <input type="checkbox" name="category_active" id="category_active" value="1" checked>
                                        <?php _e( '启用此分类', 'qibbs-community' ); ?>
                                    </label>
                                </p>
                                
                                <p>
                                    <button type="submit" class="button button-primary"><?php _e( '保存', 'qibbs-community' ); ?></button>
                                    <button type="button" class="button" id="qibbs-cancel-edit" style="display: none;"><?php _e( '取消', 'qibbs-community' ); ?></button>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            // 编辑工单分类
            $('.qibbs-edit-category').on('click', function() {
                var btn = $(this);
                $('#qibbs-category-form-title').text('<?php _e( '编辑分类', 'qibbs-community' ); ?>');
                $('#qibbs_category_action').val('edit');
                $('#category_id').val(btn.data('id'));
                $('#category_name').val(btn.data('name'));
                $('#category_description').val(btn.data('description'));
                $('#category_sla_first_response_hours').val(btn.data('sla-first-response') || 4);
                $('#category_sla_resolution_hours').val(btn.data('sla-resolution') || 48);
                $('#category_active').prop('checked', btn.data('active') == 1);
                $('#qibbs-category-active-field').show();
                $('#qibbs-cancel-edit').show();
            });

            // 取消分类编辑
            $('#qibbs-cancel-edit').on('click', function() {
                $('#qibbs-category-form-title').text('<?php _e( '添加分类', 'qibbs-community' ); ?>');
                $('#qibbs_category_action').val('add');
                $('#category_id').val('');
                $('#category_name').val('');
                $('#category_description').val('');
                $('#category_sla_first_response_hours').val('4');
                $('#category_sla_resolution_hours').val('48');
                $('#qibbs-category-active-field').hide();
                $(this).hide();
            });
        });
        </script>
        <?php
    }

    /**
     * 获取工单状态彩色徽章 HTML
     */
    private static function get_status_badge( $status ) {
        $ticket_service = new Qibbs_Ticket_Service();
        $label = $ticket_service->get_status_label( $status );

        $classes = array(
            'pending'          => 'qibbs-admin-status-pending',
            'processing'       => 'qibbs-admin-status-processing',
            'pending_customer' => 'qibbs-admin-status-pending-customer',
            'reopened'         => 'qibbs-admin-status-reopened',
            'resolved'         => 'qibbs-admin-status-resolved',
            'closed'           => 'qibbs-admin-status-closed',
        );

        $class_name = isset( $classes[ $status ] ) ? $classes[ $status ] : 'qibbs-admin-status-default';

        return sprintf(
            '<span class="qibbs-admin-status-badge %s">%s</span>',
            esc_attr( $class_name ),
            esc_html( $label )
        );
    }

    /**
     * 获取可指派的客服管理员列表
     *
     * @return array
     */
    private static function get_ticket_agents() {
        $users = get_users( array(
            'fields' => array( 'ID', 'display_name' ),
            'orderby' => 'display_name',
            'order' => 'ASC',
            'number' => 500,
        ) );

        if ( empty( $users ) ) {
            return array();
        }

        return array_values( array_filter( $users, function( $user ) {
            $user_id = isset( $user->ID ) ? (int) $user->ID : 0;
            return $user_id > 0 && user_can( $user_id, 'manage_options' );
        } ) );
    }

    /**
     * 根据 SLA 履约状态渲染倒计时徽章
     *
     * @param array $sla_state SLA state from service.
     * @return string
     */
    private static function get_sla_badge( $sla_state ) {
        $sla_state = is_array( $sla_state ) ? $sla_state : array();
        $first_due = ! empty( $sla_state['first_response_due'] );
        $resolution_due = ! empty( $sla_state['resolution_due'] );

        if ( $first_due || $resolution_due ) {
            $reasons = array();
            if ( $first_due ) {
                $reasons[] = __( '首响超时', 'qibbs-community' );
            }
            if ( $resolution_due ) {
                $reasons[] = __( '解决超时', 'qibbs-community' );
            }
            return '<span class="qibbs-sla-badge is-danger">' . esc_html( implode( ' / ', $reasons ) ) . '</span>';
        }

        return '<span class="qibbs-sla-badge is-ok">' . esc_html__( 'SLA 正常', 'qibbs-community' ) . '</span>';
    }

    /**
     * 处理工单后台异步 AJAX 操作
     */
    public static function handle_ajax_action() {
        check_ajax_referer( 'qibbs_ticket_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'qibbs-community' ) ) );
        }

        $action = isset( $_POST['ticket_action'] ) ? sanitize_text_field( $_POST['ticket_action'] ) : '';
        $ticket_id = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;

        $ticket_service = new Qibbs_Ticket_Service();

        switch ( $action ) {
            case 'update_status':
                $status = isset( $_POST['status'] ) ? sanitize_text_field( $_POST['status'] ) : '';
                $result = $ticket_service->update_status( $ticket_id, $status );
                break;

            case 'delete_attachment':
                $media_id = isset( $_POST['media_id'] ) ? absint( $_POST['media_id'] ) : 0;
                if ( ! $media_id || ! $ticket_id ) {
                    wp_send_json_error( array( 'message' => __( '参数错误', 'qibbs-community' ) ) );
                }

                global $wpdb;
                $media_table = Qibbs_Database::media();
                $tickets_table = Qibbs_Database::tickets();

                // 更新工单媒体附件关联字段
                $ticket = $wpdb->get_row( $wpdb->prepare(
                    "SELECT user_id, attachments FROM {$tickets_table} WHERE id = %d",
                    $ticket_id
                ) );

                if ( ! $ticket ) {
                    wp_send_json_error( array( 'message' => __( '工单不存在', 'qibbs-community' ) ) );
                }

                $attachment_ids = qibbs_parse_id_list( $ticket->attachments );
                if ( ! in_array( $media_id, $attachment_ids, true ) ) {
                    wp_send_json_error( array( 'message' => __( '该附件不属于当前工单', 'qibbs-community' ) ) );
                }

                $attachment_ids = array_values( array_diff( $attachment_ids, array( $media_id ) ) );
                $updated = $wpdb->update(
                    $tickets_table,
                    array( 'attachments' => qibbs_media_ids_to_csv( $attachment_ids ) ),
                    array( 'id' => $ticket_id ),
                    array( '%s' ),
                    array( '%d' )
                );

                if ( false === $updated ) {
                    wp_send_json_error( array( 'message' => __( '更新附件失败，请稍后重试', 'qibbs-community' ) ) );
                }

                $remaining_refs = qibbs_count_media_references(
                    $media_id,
                    array( 'ticket_id' => $ticket_id )
                );
                if ( $remaining_refs > 0 ) {
                    wp_send_json_success( array( 'message' => __( '附件已从当前工单移除，原文件仍被其他内容使用', 'qibbs-community' ) ) );
                }

                $media = $wpdb->get_row( $wpdb->prepare(
                    "SELECT file_path, user_id FROM {$media_table} WHERE id = %d",
                    $media_id
                ) );

                if ( ! $media || (int) $media->user_id !== (int) $ticket->user_id ) {
                    wp_send_json_success( array( 'message' => __( '附件已移除，原媒体记录已保留', 'qibbs-community' ) ) );
                }

                if ( ! empty( $media->file_path ) && file_exists( $media->file_path ) ) {
                    @unlink( $media->file_path );
                }

                $deleted = $wpdb->delete( $media_table, array( 'id' => $media_id ), array( '%d' ) );
                if ( false === $deleted ) {
                    wp_send_json_error( array( 'message' => __( '媒体记录删除失败，请稍后重试', 'qibbs-community' ) ) );
                }

                wp_send_json_success( array( 'message' => __( '附件已安全删除', 'qibbs-community' ) ) );
                break;

            default:
                wp_send_json_error( array( 'message' => __( '未知操作', 'qibbs-community' ) ) );
        }

        if ( $result ) {
            wp_send_json_success( array( 'message' => __( '操作成功', 'qibbs-community' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( '操作失败', 'qibbs-community' ) ) );
        }
    }
}

// 初始化工单管理后台控制器
Qibbs_Ticket_Admin::init();
