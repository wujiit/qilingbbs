<?php
/**
 * 视图模板：私信记录查看与管理
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
        $messages_table = Qibbs_Database::messages();
        
        $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        $messages = array();
        $user_info = null;
        
        if ( $user_id > 0 ) {
            $user_info = get_userdata( $user_id );
            
            if ( $user_info ) {
                // 查询指定用户的全部收发私信记录
                $messages = $wpdb->get_results( $wpdb->prepare(
                    "SELECT m.*, 
                            s.display_name as sender_name,
                            r.display_name as receiver_name
                     FROM {$messages_table} m
                     LEFT JOIN {$wpdb->users} s ON m.sender_id = s.ID
                     LEFT JOIN {$wpdb->users} r ON m.receiver_id = r.ID
                     WHERE m.sender_id = %d OR m.receiver_id = %d
                     ORDER BY m.created_at DESC
                     LIMIT 200",
                    $user_id, $user_id
                ) );
            }
        }
        ?>
        <div class="wrap qibbs-admin-page">
            <h1><?php esc_html_e( '私信管理', 'qibbs-community' ); ?></h1>
            
            <div class="qibbs-message-search" style="margin: 20px 0; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                <form method="get" action="">
                    <input type="hidden" name="page" value="qibbs-messages">
                    <label for="user_id" style="font-weight: 600;"><?php esc_html_e( '输入用户ID查看私信:', 'qibbs-community' ); ?></label>
                    <input type="number" name="user_id" id="user_id" value="<?php echo esc_attr( $user_id ); ?>" min="1" style="width: 100px; margin: 0 10px;" placeholder="用户ID">
                    <button type="submit" class="button button-primary"><?php esc_html_e( '查询', 'qibbs-community' ); ?></button>
                </form>
            </div>
            
            <?php if ( $user_id > 0 && ! $user_info ) : ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e( '用户不存在', 'qibbs-community' ); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if ( $user_info ) : ?>
                <div class="qibbs-user-info" style="margin: 20px 0; padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 4px;">
                    <strong><?php esc_html_e( '用户信息:', 'qibbs-community' ); ?></strong>
                    ID: <?php echo esc_html( $user_id ); ?> | 
                    <?php esc_html_e( '用户名:', 'qibbs-community' ); ?> <?php echo esc_html( $user_info->user_login ); ?> | 
                    <?php esc_html_e( '显示名:', 'qibbs-community' ); ?> <?php echo esc_html( $user_info->display_name ); ?> | 
                    <?php esc_html_e( '邮箱:', 'qibbs-community' ); ?> <?php echo esc_html( $user_info->user_email ); ?> |
                    <?php esc_html_e( '私信数量:', 'qibbs-community' ); ?> <?php echo count( $messages ); ?>
                </div>
                
                <?php if ( ! empty( $messages ) ) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th style="width:100px;"><?php esc_html_e( '方向', 'qibbs-community' ); ?></th>
                                <th style="width:150px;"><?php esc_html_e( '发送者', 'qibbs-community' ); ?></th>
                                <th style="width:150px;"><?php esc_html_e( '接收者', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '内容', 'qibbs-community' ); ?></th>
                                <th style="width:80px;"><?php esc_html_e( '已读', 'qibbs-community' ); ?></th>
                                <th style="width:160px;"><?php esc_html_e( '时间', 'qibbs-community' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $messages as $msg ) : 
                                $is_sender = ( $msg->sender_id == $user_id );
                            ?>
                                <tr>
                                    <td><?php echo esc_html( $msg->id ); ?></td>
                                    <td>
                                        <?php if ( $is_sender ) : ?>
                                            <span style="color: #2271b1; display: inline-flex; align-items: center; gap: 3px;"><span class="dashicons dashicons-upload" style="font-size:15px;width:15px;height:15px;"></span><?php esc_html_e( '发送', 'qibbs-community' ); ?></span>
                                        <?php else : ?>
                                            <span style="color: #00a32a; display: inline-flex; align-items: center; gap: 3px;"><span class="dashicons dashicons-download" style="font-size:15px;width:15px;height:15px;"></span><?php esc_html_e( '接收', 'qibbs-community' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url( add_query_arg( 'user_id', $msg->sender_id ) ); ?>">
                                            <?php echo esc_html( $msg->sender_name ?: 'ID:' . $msg->sender_id ); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url( add_query_arg( 'user_id', $msg->receiver_id ) ); ?>">
                                            <?php echo esc_html( $msg->receiver_name ?: 'ID:' . $msg->receiver_id ); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html( wp_trim_words( $msg->content, 30, '...' ) ); ?></td>
                                    <td>
                                        <?php if ( $msg->is_read ) : ?>
                                            <span class="dashicons dashicons-yes-alt" style="color: #00a32a; font-size:18px; width:18px; height:18px;"></span>
                                        <?php else : ?>
                                            <span style="color: #94a3b8; font-size: 13px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html( $msg->created_at ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div class="notice notice-warning">
                        <p><?php esc_html_e( '该用户没有私信记录', 'qibbs-community' ); ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
