<?php
/**
 * 视图模板：社区版主指派与权限管理
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

        global $wpdb;
        $forums_table = Qibbs_Database::forums();
        $moderator_service = new Qibbs_Forum_Moderator_Service();

        $selected_forum_id = isset( $_GET['forum_id'] ) ? absint( $_GET['forum_id'] ) : 0;
        $notice_message = '';
        $notice_type = '';

        if ( isset( $_POST['qibbs_save_forum_moderators'] ) && check_admin_referer( 'qibbs_forum_moderators_action' ) ) {
            $selected_forum_id = absint( $_POST['forum_id'] ?? 0 );
            $raw_user_ids = isset( $_POST['moderator_user_ids'] ) ? sanitize_textarea_field( wp_unslash( $_POST['moderator_user_ids'] ) ) : '';
            $user_ids = $this->parse_user_ids_input( $raw_user_ids );

            $result = $moderator_service->sync_forum_moderators( $selected_forum_id, $user_ids, get_current_user_id() );
            if ( ! empty( $result['success'] ) ) {
                $notice_message = sprintf(
                    /* translators: 1: added count, 2: removed count */
                    __( '圈子版主已更新：新增 %1$d 人，移除 %2$d 人。', 'qibbs-community' ),
                    count( $result['added'] ),
                    count( $result['removed'] )
                );
                $notice_type = 'success';
            } else {
                $notice_message = ! empty( $result['message'] ) ? $result['message'] : __( '保存失败，请稍后重试。', 'qibbs-community' );
                $notice_type = 'error';
            }
        }

        if ( isset( $_GET['remove_forum_moderator'], $_GET['forum_id'], $_GET['_wpnonce'] ) ) {
            $remove_forum_id = absint( $_GET['forum_id'] );
            $remove_user_id = absint( $_GET['remove_forum_moderator'] );
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'remove_forum_moderator_' . $remove_forum_id . '_' . $remove_user_id ) ) {
                $rows = $moderator_service->get_forum_moderators( $remove_forum_id );
                $keep_ids = array();
                foreach ( (array) $rows as $row ) {
                    $uid = (int) $row->user_id;
                    $role = isset( $row->role ) ? (string) $row->role : '';
                    if ( $uid > 0 && 'moderator' === $role && $uid !== $remove_user_id ) {
                        $keep_ids[] = $uid;
                    }
                }
                $remove_result = $moderator_service->sync_forum_moderators( $remove_forum_id, $keep_ids, get_current_user_id() );
                $selected_forum_id = $remove_forum_id;
                if ( ! empty( $remove_result['success'] ) ) {
                    $notice_message = __( '版主已移除。', 'qibbs-community' );
                    $notice_type = 'success';
                } else {
                    $notice_message = ! empty( $remove_result['message'] ) ? $remove_result['message'] : __( '移除失败。', 'qibbs-community' );
                    $notice_type = 'error';
                }
            }
        }

        $forums = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, slug, post_count FROM {$forums_table} WHERE 1 = %d ORDER BY sort_order ASC, id DESC", 1 ) );
        if ( ! empty( $forums ) ) {
            $forum_ids = array_map(
                function( $forum_item ) {
                    return (int) $forum_item->id;
                },
                (array) $forums
            );
            if ( ! $selected_forum_id || ! in_array( $selected_forum_id, $forum_ids, true ) ) {
                $selected_forum_id = (int) $forums[0]->id;
            }
        }

        $moderators = $selected_forum_id ? $moderator_service->get_forum_moderators( $selected_forum_id ) : array();
        $moderator_user_ids = array();
        foreach ( (array) $moderators as $item ) {
            if ( isset( $item->role ) && (string) $item->role === 'moderator' ) {
                $moderator_user_ids[] = (int) $item->user_id;
            }
        }
        $moderator_user_ids = array_values( array_unique( array_filter( $moderator_user_ids ) ) );

        $users_map = array();
        if ( ! empty( $moderator_user_ids ) ) {
            $users = get_users(
                array(
                    'include' => $moderator_user_ids,
                    'fields'  => array( 'ID', 'display_name', 'user_email' ),
                )
            );
            foreach ( (array) $users as $user_item ) {
                $users_map[ (int) $user_item->ID ] = $user_item;
            }
        }
        ?>
        <div class="wrap qibbs-admin-page">
            <h1><?php esc_html_e( '圈子版主', 'qibbs-community' ); ?></h1>
            <p class="description"><?php esc_html_e( '可为每个圈子配置专属版主。版主可执行本圈删帖、屏蔽动态、删评、屏蔽评论等管理动作。', 'qibbs-community' ); ?></p>

            <?php if ( ! empty( $notice_message ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible"><p><?php echo esc_html( $notice_message ); ?></p></div>
            <?php endif; ?>

            <form method="get" style="margin: 16px 0;">
                <input type="hidden" name="page" value="qibbs-moderators">
                <label for="qibbs-forum-selector"><strong><?php esc_html_e( '选择圈子', 'qibbs-community' ); ?></strong></label>
                <select id="qibbs-forum-selector" name="forum_id" style="min-width: 280px; margin-left: 8px;">
                    <?php foreach ( (array) $forums as $forum_item ) : ?>
                        <option value="<?php echo esc_attr( $forum_item->id ); ?>" <?php selected( (int) $forum_item->id, $selected_forum_id ); ?>>
                            <?php echo esc_html( $forum_item->name ); ?> (#<?php echo (int) $forum_item->id; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e( '切换', 'qibbs-community' ); ?></button>
            </form>

            <?php if ( ! empty( $selected_forum_id ) ) : ?>
                <div style="display:flex;gap:20px;align-items:flex-start;">
                    <div style="flex:1;min-width:340px;background:#fff;border:1px solid #ccd0d4;padding:16px;">
                        <h2 style="margin-top:0;"><?php esc_html_e( '批量设置版主', 'qibbs-community' ); ?></h2>
                        <form method="post">
                            <?php wp_nonce_field( 'qibbs_forum_moderators_action' ); ?>
                            <input type="hidden" name="forum_id" value="<?php echo esc_attr( $selected_forum_id ); ?>">
                            <p><?php esc_html_e( '输入用户 ID，多个用英文逗号或换行分隔。保存后会覆盖当前圈子版主名单。', 'qibbs-community' ); ?></p>
                            <textarea name="moderator_user_ids" rows="6" class="large-text" placeholder="<?php esc_attr_e( '例如: 12,36,88', 'qibbs-community' ); ?>"><?php echo esc_textarea( implode( ',', $moderator_user_ids ) ); ?></textarea>
                            <p>
                                <button type="submit" name="qibbs_save_forum_moderators" class="button button-primary"><?php esc_html_e( '保存版主名单', 'qibbs-community' ); ?></button>
                            </p>
                        </form>
                    </div>
                    <div style="flex:2;background:#fff;border:1px solid #ccd0d4;padding:16px;">
                        <h2 style="margin-top:0;"><?php esc_html_e( '当前版主', 'qibbs-community' ); ?></h2>
                        <table class="wp-list-table widefat striped">
                            <thead>
                                <tr>
                                    <th style="width:90px;"><?php esc_html_e( '用户ID', 'qibbs-community' ); ?></th>
                                    <th><?php esc_html_e( '用户', 'qibbs-community' ); ?></th>
                                    <th style="width:120px;"><?php esc_html_e( '角色', 'qibbs-community' ); ?></th>
                                    <th style="width:180px;"><?php esc_html_e( '加入时间', 'qibbs-community' ); ?></th>
                                    <th style="width:100px;"><?php esc_html_e( '操作', 'qibbs-community' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( empty( $moderators ) ) : ?>
                                    <tr><td colspan="5" style="text-align:center;padding:20px;"><?php esc_html_e( '当前圈子暂无版主', 'qibbs-community' ); ?></td></tr>
                                <?php else : ?>
                                    <?php foreach ( $moderators as $item ) : ?>
                                        <?php
                                        $uid = (int) $item->user_id;
                                        $role = isset( $item->role ) ? (string) $item->role : 'moderator';
                                        $user = isset( $users_map[ $uid ] ) ? $users_map[ $uid ] : get_userdata( $uid );
                                        ?>
                                        <tr>
                                            <td><?php echo esc_html( $uid ); ?></td>
                                            <td>
                                                <?php if ( $user ) : ?>
                                                    <strong><?php echo esc_html( $user->display_name ); ?></strong>
                                                    <?php if ( ! empty( $user->user_email ) ) : ?>
                                                        <div style="color:#646970;font-size:12px;"><?php echo esc_html( $user->user_email ); ?></div>
                                                    <?php endif; ?>
                                                <?php else : ?>
                                                    <em><?php esc_html_e( '用户不存在', 'qibbs-community' ); ?></em>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo 'owner' === $role ? esc_html__( '圈主', 'qibbs-community' ) : esc_html__( '版主', 'qibbs-community' ); ?>
                                            </td>
                                            <td><?php echo esc_html( $item->created_at ); ?></td>
                                            <td>
                                                <?php if ( 'moderator' === $role ) : ?>
                                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=qibbs-moderators&forum_id=' . $selected_forum_id . '&remove_forum_moderator=' . $uid ), 'remove_forum_moderator_' . $selected_forum_id . '_' . $uid ) ); ?>" onclick="return confirm('<?php echo esc_attr__( '确认移除该版主？', 'qibbs-community' ); ?>');"><?php esc_html_e( '移除', 'qibbs-community' ); ?></a>
                                                <?php else : ?>
                                                    <span style="color:#8c8f94;">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else : ?>
                <div class="notice notice-warning"><p><?php esc_html_e( '请先创建圈子。', 'qibbs-community' ); ?></p></div>
            <?php endif; ?>
        </div>
        <?php
