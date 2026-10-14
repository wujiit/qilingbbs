<?php
/**
 * 视图模板：社区版块管理
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
        $table = Qibbs_Database::forums();
        
        // 处理编辑表单提交
        if ( isset( $_POST['qibbs_edit_forum'] ) && check_admin_referer( 'qibbs_forum_action' ) ) {
            $edit_id = absint( $_POST['edit_id'] );
            $name = sanitize_text_field( wp_unslash( $_POST['forum_name'] ) );
            $slug = ! empty( $_POST['forum_slug'] ) ? sanitize_title( wp_unslash( $_POST['forum_slug'] ) ) : sanitize_title( $name );
            $description = sanitize_textarea_field( wp_unslash( $_POST['forum_description'] ) );
            if ( ! empty( $name ) && $edit_id > 0 ) {
                $wpdb->update( $table, array(
                    'name'        => $name,
                    'slug'        => $slug,
                    'description' => $description,
                ), array( 'id' => $edit_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
                if ( class_exists( 'Qibbs_Cache_Service' ) ) {
                    Qibbs_Cache_Service::invalidate_forum_count_cache();
                }
                echo '<div class="notice notice-success"><p>' . esc_html__( '圈子已更新', 'qibbs-community' ) . '</p></div>';
            }
        }
        
        // 处理新增表单提交
        if ( isset( $_POST['qibbs_add_forum'] ) && check_admin_referer( 'qibbs_forum_action' ) ) {
            $name = sanitize_text_field( wp_unslash( $_POST['forum_name'] ) );
            $slug = ! empty( $_POST['forum_slug'] ) ? sanitize_title( wp_unslash( $_POST['forum_slug'] ) ) : sanitize_title( $name );
            $description = sanitize_textarea_field( wp_unslash( $_POST['forum_description'] ) );
            if ( ! empty( $name ) ) {
                $wpdb->insert( $table, array(
                    'name'        => $name,
                    'slug'        => $slug,
                    'description' => $description,
                    'created_at'  => current_time( 'mysql' ),
                ), array( '%s', '%s', '%s', '%s' ) );
                if ( class_exists( 'Qibbs_Cache_Service' ) ) {
                    Qibbs_Cache_Service::invalidate_forum_count_cache();
                }
                echo '<div class="notice notice-success"><p>' . esc_html__( '圈子已添加', 'qibbs-community' ) . '</p></div>';
            }
        }
        
        if ( isset( $_GET['delete_forum'] ) && check_admin_referer( 'delete_forum_' . sanitize_text_field( wp_unslash( $_GET['delete_forum'] ) ) ) ) {
            $wpdb->delete( $table, array( 'id' => absint( $_GET['delete_forum'] ) ), array( '%d' ) );
            if ( class_exists( 'Qibbs_Cache_Service' ) ) {
                Qibbs_Cache_Service::invalidate_forum_count_cache();
            }
            echo '<div class="notice notice-success"><p>' . esc_html__( '圈子已删除', 'qibbs-community' ) . '</p></div>';
        }
        
        // 获取待编辑版块数据
        $edit_forum = null;
        if ( isset( $_GET['edit_forum'] ) ) {
            $edit_forum = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $_GET['edit_forum'] ) ) );
        }
        
        $forums = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE 1 = %d ORDER BY id DESC", 1 ) );
        ?>
        <div class="wrap qibbs-admin-page">
            <h1><?php esc_html_e( '圈子管理', 'qibbs-community' ); ?></h1>
            
            <div style="display: flex; gap: 20px; margin-top: 20px;">
                <div style="flex: 1; background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
                    <h2><?php echo $edit_forum ? esc_html__( '编辑圈子', 'qibbs-community' ) : esc_html__( '添加新圈子', 'qibbs-community' ); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field( 'qibbs_forum_action' ); ?>
                        <?php if ( $edit_forum ) : ?>
                            <input type="hidden" name="edit_id" value="<?php echo esc_attr( $edit_forum->id ); ?>">
                        <?php endif; ?>
                        <p>
                            <label><strong><?php esc_html_e( '名称', 'qibbs-community' ); ?></strong></label><br>
                            <input type="text" name="forum_name" class="regular-text" required value="<?php echo $edit_forum ? esc_attr( $edit_forum->name ) : ''; ?>">
                        </p>
                        <p>
                            <label><strong><?php esc_html_e( '别名 (可选，留空则自动生成)', 'qibbs-community' ); ?></strong></label><br>
                            <input type="text" name="forum_slug" class="regular-text" placeholder="<?php esc_attr_e( '例如: tech-news', 'qibbs-community' ); ?>" value="<?php echo $edit_forum ? esc_attr( $edit_forum->slug ) : ''; ?>">
                        </p>
                        <p>
                            <label><strong><?php esc_html_e( '描述', 'qibbs-community' ); ?></strong></label><br>
                            <textarea name="forum_description" rows="3" class="large-text"><?php echo $edit_forum ? esc_textarea( $edit_forum->description ) : ''; ?></textarea>
                        </p>
                        <p>
                            <?php if ( $edit_forum ) : ?>
                                <button type="submit" name="qibbs_edit_forum" class="button button-primary"><?php esc_html_e( '更新圈子', 'qibbs-community' ); ?></button>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-forums' ) ); ?>" class="button"><?php esc_html_e( '取消', 'qibbs-community' ); ?></a>
                            <?php else : ?>
                                <button type="submit" name="qibbs_add_forum" class="button button-primary"><?php esc_html_e( '添加圈子', 'qibbs-community' ); ?></button>
                            <?php endif; ?>
                        </p>
                    </form>
                </div>
                
                <div style="flex: 2;">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th><?php esc_html_e( '名称', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '别名', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '描述', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '操作', 'qibbs-community' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $forums ) ) : ?>
                                <tr><td colspan="5"><?php esc_html_e( '暂无圈子', 'qibbs-community' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $forums as $forum ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $forum->id ); ?></td>
                                        <td><strong><?php echo esc_html( $forum->name ); ?></strong></td>
                                        <td><?php echo esc_html( $forum->slug ); ?></td>
                                        <td><?php echo esc_html( wp_trim_words( $forum->description, 10 ) ); ?></td>
                                        <td>
                                            <a href="<?php echo esc_url( add_query_arg( 'edit_forum', $forum->id ) ); ?>"><?php esc_html_e( '编辑', 'qibbs-community' ); ?></a> | 
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-moderators&forum_id=' . (int) $forum->id ) ); ?>"><?php esc_html_e( '版主', 'qibbs-community' ); ?></a> |
                                            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'delete_forum', $forum->id ), 'delete_forum_' . $forum->id ) ); ?>" 
                                               onclick="return confirm('<?php esc_attr_e( '确定删除?', 'qibbs-community' ); ?>');" 
                                               style="color: #dc3232;"><?php esc_html_e( '删除', 'qibbs-community' ); ?></a>
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
