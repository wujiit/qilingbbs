<?php
/**
 * 视图模板：黑名单管理
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
        $table = Qibbs_Database::blacklist();

        // 处理加入黑名单提交
        if ( isset( $_POST['qibbs_add_blacklist'] ) && check_admin_referer( 'qibbs_blacklist_action' ) ) {
            $user_id = absint( $_POST['blacklist_user_id'] );
            $reason = isset( $_POST['blacklist_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['blacklist_reason'] ) ) : '';
            
            if ( $user_id > 0 ) {
                $user = get_userdata( $user_id );
                if ( $user ) {
                    // 检查用户是否已在黑名单中
                    $existing = $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM {$table} WHERE user_id = %d",
                        $user_id
                    ) );
                    
                    if ( ! $existing ) {
                        $wpdb->insert( $table, array(
                            'user_id'    => $user_id,
                            'reason'     => $reason,
                            'admin_id'   => get_current_user_id(),
                            'created_at' => current_time( 'mysql' ),
                        ), array( '%d', '%s', '%d', '%s' ) );
                        echo '<div class="notice notice-success"><p>' . sprintf( __( '用户 %s (ID: %d) 已加入黑名单', 'qibbs-community' ), esc_html( $user->display_name ), $user_id ) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-warning"><p>' . __( '该用户已在黑名单中', 'qibbs-community' ) . '</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error"><p>' . __( '用户不存在', 'qibbs-community' ) . '</p></div>';
                }
            }
        }

        // 处理移出黑名单提交
        if ( isset( $_GET['remove_blacklist'] ) && isset( $_GET['_wpnonce'] ) ) {
            $user_id = absint( $_GET['remove_blacklist'] );
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'remove_blacklist_' . $user_id ) ) {
                $wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
                echo '<div class="notice notice-success"><p>' . __( '已从黑名单移除', 'qibbs-community' ) . '</p></div>';
            }
        }

        // 从数据库读取黑名单用户列表
        $blacklist = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE 1 = %d ORDER BY created_at DESC", 1 ) );
        ?>
        <div class="wrap qibbs-admin-page">
            <h1><?php esc_html_e( '用户黑名单', 'qibbs-community' ); ?></h1>
            <p class="description"><?php esc_html_e( '黑名单用户将被禁止发帖、评论、发送私信；可在“黑名单访问模式”中设置为仅可浏览或完全禁止访问社区。', 'qibbs-community' ); ?></p>
            
            <div style="display: flex; gap: 30px; margin-top: 20px;">
                <!-- 添加黑名单表单 -->
                <div style="flex: 0 0 320px;">
                    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <h3 style="margin-top: 0;"><?php esc_html_e( '添加用户到黑名单', 'qibbs-community' ); ?></h3>
                        <form method="post" action="">
                            <?php wp_nonce_field( 'qibbs_blacklist_action' ); ?>
                            <p>
                                <label for="blacklist_user_id"><?php esc_html_e( '用户ID:', 'qibbs-community' ); ?></label><br>
                                <input type="number" name="blacklist_user_id" id="blacklist_user_id" min="1" required 
                                       style="width: 100%; margin-top: 5px;" placeholder="<?php esc_attr_e( '输入用户ID', 'qibbs-community' ); ?>">
                            </p>
                            <p>
                                <label for="blacklist_reason"><?php esc_html_e( '拉黑原因 (可选):', 'qibbs-community' ); ?></label><br>
                                <textarea name="blacklist_reason" id="blacklist_reason" rows="3" 
                                          style="width: 100%; margin-top: 5px;" placeholder="<?php esc_attr_e( '记录拉黑原因便于后期管理', 'qibbs-community' ); ?>"></textarea>
                            </p>
                            <p class="description">
                                <?php esc_html_e( '可在「用户」菜单中查看用户ID', 'qibbs-community' ); ?>
                            </p>
                            <p>
                                <button type="submit" name="qibbs_add_blacklist" class="button button-primary">
                                    <?php esc_html_e( '加入黑名单', 'qibbs-community' ); ?>
                                </button>
                            </p>
                        </form>
                    </div>
                </div>
                
                <!-- 黑名单列表表格 -->
                <div style="flex: 1;">
                    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <h3 style="margin-top: 0;"><?php esc_html_e( '黑名单用户', 'qibbs-community' ); ?> (<?php echo count( $blacklist ); ?>)</h3>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 60px;">ID</th>
                                    <th style="width: 120px;"><?php esc_html_e( '用户', 'qibbs-community' ); ?></th>
                                    <th><?php esc_html_e( '拉黑原因', 'qibbs-community' ); ?></th>
                                    <th style="width: 100px;"><?php esc_html_e( '操作人', 'qibbs-community' ); ?></th>
                                    <th style="width: 140px;"><?php esc_html_e( '拉黑时间', 'qibbs-community' ); ?></th>
                                    <th style="width: 80px;"><?php esc_html_e( '操作', 'qibbs-community' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( empty( $blacklist ) ) : ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 20px; color: #666;">
                                            <?php esc_html_e( '黑名单为空', 'qibbs-community' ); ?>
                                        </td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ( $blacklist as $item ) : ?>
                                        <?php 
                                        $user = get_userdata( $item->user_id );
                                        $admin = get_userdata( $item->admin_id );
                                        ?>
                                        <tr>
                                            <td><?php echo esc_html( $item->user_id ); ?></td>
                                            <td>
                                                <?php if ( $user ) : ?>
                                                    <strong><?php echo esc_html( $user->display_name ); ?></strong><br>
                                                    <span style="color: #666; font-size: 12px;"><?php echo esc_html( $user->user_email ); ?></span>
                                                <?php else : ?>
                                                    <em style="color: #999;"><?php esc_html_e( '已删除', 'qibbs-community' ); ?></em>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $item->reason ? esc_html( $item->reason ) : '<span style="color: #999;">-</span>'; ?></td>
                                            <td><?php echo $admin ? esc_html( $admin->display_name ) : '-'; ?></td>
                                            <td><?php echo esc_html( $item->created_at ); ?></td>
                                            <td>
                                                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'remove_blacklist', $item->user_id ), 'remove_blacklist_' . $item->user_id ) ); ?>" 
                                                   style="color: #00a32a;"
                                                   onclick="return confirm('<?php esc_attr_e( '确定要从黑名单移除此用户吗？', 'qibbs-community' ); ?>');">
                                                    <?php esc_html_e( '移除', 'qibbs-community' ); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
