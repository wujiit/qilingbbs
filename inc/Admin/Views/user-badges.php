<?php
/**
 * 视图模板：用户荣誉徽章管理
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

        $badge_service = new Qibbs_Badge_Service();
        $manual_badge_options = array(
            'verified_author' => __( '官方认证', 'qibbs-community' ),
            'core_creator'    => __( '核心创作者', 'qibbs-community' ),
            'exclusive_guest' => __( '特邀嘉宾', 'qibbs-community' ),
            'pioneer_user'    => __( '先锋元老', 'qibbs-community' ),
            'senior_elder'    => __( '荣誉顾问', 'qibbs-community' ),
            'contract_author' => __( '签约达人', 'qibbs-community' ),
            'tech_expert'     => __( '官方技术', 'qibbs-community' ),
        );

        $notice_message = '';
        $notice_type = '';
        $selected_user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

        if ( isset( $_POST['qibbs_save_user_badges'] ) && check_admin_referer( 'qibbs_user_badges_action' ) ) {
            $selected_user_id = absint( $_POST['badge_user_id'] ?? 0 );
            $badge_keys = isset( $_POST['manual_badges'] ) ? (array) $_POST['manual_badges'] : array();
            $badge_keys = array_map( 'sanitize_key', $badge_keys );
            $badge_keys = array_values(
                array_filter(
                    $badge_keys,
                    function( $key ) use ( $manual_badge_options ) {
                        return isset( $manual_badge_options[ $key ] );
                    }
                )
            );

            if ( $selected_user_id <= 0 || ! get_userdata( $selected_user_id ) ) {
                $notice_message = __( '用户不存在，请检查用户 ID。', 'qibbs-community' );
                $notice_type = 'error';
            } else {
                $saved = $badge_service->update_user_manual_badges( $selected_user_id, $badge_keys );
                if ( $saved ) {
                    $notice_message = __( '用户徽章已保存。', 'qibbs-community' );
                    $notice_type = 'success';
                } else {
                    $notice_message = __( '保存失败，请稍后重试。', 'qibbs-community' );
                    $notice_type = 'error';
                }
            }
        }

        $selected_user = $selected_user_id ? get_userdata( $selected_user_id ) : null;
        $selected_manual_badges = $selected_user_id ? $badge_service->get_user_manual_badges( $selected_user_id ) : array();
        $selected_all_badges = $selected_user_id ? $badge_service->get_user_badges( $selected_user_id ) : array();
        $users_with_badges = $badge_service->get_users_with_manual_badges( 200 );
        $users_with_badges = array_values(
            array_filter(
                (array) $users_with_badges,
                function( $user_item ) use ( $badge_service ) {
                    return ! empty( $badge_service->get_user_manual_badges( (int) $user_item->ID ) );
                }
            )
        );
        ?>
        <div class="wrap qibbs-admin-page">
            <h1><?php esc_html_e( '用户徽章', 'qibbs-community' ); ?></h1>
            <p class="description"><?php esc_html_e( '支持自动徽章（管理员/版主/活跃度）和手动徽章（认证作者/先锋用户）。', 'qibbs-community' ); ?></p>

            <?php if ( ! empty( $notice_message ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible"><p><?php echo esc_html( $notice_message ); ?></p></div>
            <?php endif; ?>

            <div style="display:flex;gap:20px;align-items:flex-start;">
                <div style="flex:1;min-width:360px;background:#fff;border:1px solid #ccd0d4;padding:16px;">
                    <h2 style="margin-top:0;"><?php esc_html_e( '设置手动徽章', 'qibbs-community' ); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field( 'qibbs_user_badges_action' ); ?>
                        <p>
                            <label for="qibbs-badge-user-id"><strong><?php esc_html_e( '用户 ID', 'qibbs-community' ); ?></strong></label><br>
                            <input id="qibbs-badge-user-id" type="number" min="1" name="badge_user_id" value="<?php echo esc_attr( $selected_user_id ); ?>" class="regular-text" required>
                        </p>
                        <div style="margin:12px 0;">
                            <?php foreach ( $manual_badge_options as $badge_key => $badge_label ) : ?>
                                <label style="display:block;margin-bottom:8px;">
                                    <input type="checkbox" name="manual_badges[]" value="<?php echo esc_attr( $badge_key ); ?>" <?php checked( in_array( $badge_key, $selected_manual_badges, true ) ); ?>>
                                    <?php echo esc_html( $badge_label ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p><button type="submit" name="qibbs_save_user_badges" class="button button-primary"><?php esc_html_e( '保存徽章', 'qibbs-community' ); ?></button></p>
                    </form>

                    <?php if ( $selected_user ) : ?>
                        <hr>
                        <h3><?php esc_html_e( '当前用户预览', 'qibbs-community' ); ?></h3>
                        <p><strong><?php echo esc_html( $selected_user->display_name ); ?></strong> (ID: <?php echo (int) $selected_user->ID; ?>)</p>
                        <?php if ( ! empty( $selected_all_badges ) ) : ?>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                <?php foreach ( $selected_all_badges as $badge_item ) : ?>
                                    <span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;background:#f2f4ff;color:#2f3b8f;font-size:12px;">
                                        <?php echo esc_html( $badge_item['label'] ); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <p style="color:#646970;"><?php esc_html_e( '该用户暂未命中任何徽章。', 'qibbs-community' ); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div style="flex:2;background:#fff;border:1px solid #ccd0d4;padding:16px;">
                    <h2 style="margin-top:0;"><?php esc_html_e( '已设置手动徽章的用户', 'qibbs-community' ); ?></h2>
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <th style="width:80px;"><?php esc_html_e( 'ID', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '用户', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '手动徽章', 'qibbs-community' ); ?></th>
                                <th style="width:120px;"><?php esc_html_e( '操作', 'qibbs-community' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $users_with_badges ) ) : ?>
                                <tr><td colspan="4" style="text-align:center;padding:20px;"><?php esc_html_e( '暂无数据', 'qibbs-community' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $users_with_badges as $user_item ) : ?>
                                    <?php
                                    $manual_badges = $badge_service->get_user_manual_badges( (int) $user_item->ID );
                                    ?>
                                    <tr>
                                        <td><?php echo (int) $user_item->ID; ?></td>
                                        <td>
                                            <strong><?php echo esc_html( $user_item->display_name ); ?></strong>
                                            <div style="color:#646970;font-size:12px;"><?php echo esc_html( $user_item->user_email ); ?></div>
                                        </td>
                                        <td>
                                            <?php
                                            $labels = array();
                                            foreach ( $manual_badges as $badge_key ) {
                                                if ( isset( $manual_badge_options[ $badge_key ] ) ) {
                                                    $labels[] = $manual_badge_options[ $badge_key ];
                                                }
                                            }
                                            echo esc_html( implode( '、', $labels ) );
                                            ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-user-badges&user_id=' . (int) $user_item->ID ) ); ?>"><?php esc_html_e( '编辑', 'qibbs-community' ); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
