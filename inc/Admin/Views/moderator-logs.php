<?php
/**
 * 视图模板：版主巡查与管理日志
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '权限不足', 'qibbs-community' ) );
        }

        $moderator_service = new Qibbs_Forum_Moderator_Service();
        $logs = $moderator_service->get_moderator_logs( 300 );

        $admin_ids = array();
        $target_user_ids = array();
        foreach ( (array) $logs as $log_item ) {
            if ( ! empty( $log_item->admin_id ) ) {
                $admin_ids[] = (int) $log_item->admin_id;
            }
            if ( ! empty( $log_item->target_user_id ) ) {
                $target_user_ids[] = (int) $log_item->target_user_id;
            }
        }
        $all_user_ids = array_values( array_unique( array_merge( $admin_ids, $target_user_ids ) ) );
        $users_map = array();
        if ( ! empty( $all_user_ids ) ) {
            $users = get_users(
                array(
                    'include' => $all_user_ids,
                    'fields'  => array( 'ID', 'display_name' ),
                )
            );
            foreach ( (array) $users as $user_item ) {
                $users_map[ (int) $user_item->ID ] = $user_item->display_name;
            }
        }
        ?>
        <div class="wrap qibbs-admin-page">
            <h1><?php esc_html_e( '版主日志', 'qibbs-community' ); ?></h1>
            <p class="description"><?php esc_html_e( '记录圈子版主相关操作，便于追溯管理行为。', 'qibbs-community' ); ?></p>

            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width:160px;"><?php esc_html_e( '时间', 'qibbs-community' ); ?></th>
                        <th style="width:180px;"><?php esc_html_e( '操作类型', 'qibbs-community' ); ?></th>
                        <th style="width:150px;"><?php esc_html_e( '对象', 'qibbs-community' ); ?></th>
                        <th style="width:160px;"><?php esc_html_e( '目标用户', 'qibbs-community' ); ?></th>
                        <th style="width:160px;"><?php esc_html_e( '操作人', 'qibbs-community' ); ?></th>
                        <th><?php esc_html_e( '备注', 'qibbs-community' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr><td colspan="6" style="text-align:center;padding:24px;"><?php esc_html_e( '暂无日志记录', 'qibbs-community' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $log_item ) : ?>
                            <?php
                            $target_user_name = isset( $users_map[ (int) $log_item->target_user_id ] ) ? $users_map[ (int) $log_item->target_user_id ] : '';
                            $admin_user_name  = isset( $users_map[ (int) $log_item->admin_id ] ) ? $users_map[ (int) $log_item->admin_id ] : '';
                            ?>
                            <tr>
                                <td><?php echo esc_html( $log_item->created_at ); ?></td>
                                <td><?php echo esc_html( $this->get_moderator_action_label( $log_item->action ) ); ?></td>
                                <td><?php echo esc_html( $log_item->object_type . '#' . (int) $log_item->object_id ); ?></td>
                                <td>
                                    <?php if ( ! empty( $target_user_name ) ) : ?>
                                        <?php echo esc_html( $target_user_name ); ?> (ID: <?php echo (int) $log_item->target_user_id; ?>)
                                    <?php elseif ( ! empty( $log_item->target_user_id ) ) : ?>
                                        ID: <?php echo (int) $log_item->target_user_id; ?>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( ! empty( $admin_user_name ) ) : ?>
                                        <?php echo esc_html( $admin_user_name ); ?> (ID: <?php echo (int) $log_item->admin_id; ?>)
                                    <?php elseif ( ! empty( $log_item->admin_id ) ) : ?>
                                        ID: <?php echo (int) $log_item->admin_id; ?>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo ! empty( $log_item->reason ) ? esc_html( $log_item->reason ) : '<span style="color:#8c8f94;">-</span>'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
