<?php
/**
 * 视图模板：话题标签管理与热度统计
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
        $table = Qibbs_Database::tags();
        
        // 处理编辑表单提交
        if ( isset( $_POST['qibbs_edit_tag'] ) && check_admin_referer( 'qibbs_tag_action' ) ) {
            $edit_id = absint( $_POST['edit_id'] );
            $name = sanitize_text_field( wp_unslash( $_POST['tag_name'] ) );
            $slug = ! empty( $_POST['tag_slug'] ) ? sanitize_title( wp_unslash( $_POST['tag_slug'] ) ) : sanitize_title( $name );
            if ( ! empty( $name ) && $edit_id > 0 ) {
                $wpdb->update( $table, array(
                    'name' => $name,
                    'slug' => $slug,
                ), array( 'id' => $edit_id ), array( '%s', '%s' ), array( '%d' ) );
                echo '<div class="notice notice-success"><p>' . esc_html__( '标签已更新', 'qibbs-community' ) . '</p></div>';
            }
        }
        
        // 处理新增表单提交
        if ( isset( $_POST['qibbs_add_tag'] ) && check_admin_referer( 'qibbs_tag_action' ) ) {
            $name = sanitize_text_field( wp_unslash( $_POST['tag_name'] ) );
            $slug = ! empty( $_POST['tag_slug'] ) ? sanitize_title( wp_unslash( $_POST['tag_slug'] ) ) : sanitize_title( $name );
            if ( ! empty( $name ) ) {
                $wpdb->insert( $table, array(
                    'name'       => $name,
                    'slug'       => $slug,
                    'post_count' => 0,
                    'created_at' => current_time( 'mysql' ),
                ), array( '%s', '%s', '%d', '%s' ) );
                echo '<div class="notice notice-success"><p>' . esc_html__( '标签已添加', 'qibbs-community' ) . '</p></div>';
            }
        }
        
        if ( isset( $_GET['delete_tag'] ) && check_admin_referer( 'delete_tag_' . sanitize_text_field( wp_unslash( $_GET['delete_tag'] ) ) ) ) {
            $wpdb->delete( $table, array( 'id' => absint( $_GET['delete_tag'] ) ), array( '%d' ) );
            echo '<div class="notice notice-success"><p>' . esc_html__( '标签已删除', 'qibbs-community' ) . '</p></div>';
        }
        
        // 获取待编辑标签数据
        $edit_tag = null;
        if ( isset( $_GET['edit_tag'] ) ) {
            $edit_tag = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $_GET['edit_tag'] ) ) );
        }
        
        $tags = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY post_count DESC, id DESC LIMIT %d", 200 ) );
        ?>
        <div class="wrap qibbs-admin-page">
            <h1><?php esc_html_e( '话题标签管理', 'qibbs-community' ); ?></h1>
            
            <div style="display: flex; gap: 20px; margin-top: 20px;">
                <div style="flex: 1; background: #fff; padding: 20px; border: 1px solid #ccd0d4;">
                    <h2><?php echo $edit_tag ? esc_html__( '编辑标签', 'qibbs-community' ) : esc_html__( '添加新标签', 'qibbs-community' ); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field( 'qibbs_tag_action' ); ?>
                        <?php if ( $edit_tag ) : ?>
                            <input type="hidden" name="edit_id" value="<?php echo esc_attr( $edit_tag->id ); ?>">
                        <?php endif; ?>
                        <p>
                            <label><strong><?php esc_html_e( '标签名称', 'qibbs-community' ); ?></strong></label><br>
                            <input type="text" name="tag_name" class="regular-text" required value="<?php echo $edit_tag ? esc_attr( $edit_tag->name ) : ''; ?>">
                        </p>
                        <p>
                            <label><strong><?php esc_html_e( '别名 (可选，留空则自动生成)', 'qibbs-community' ); ?></strong></label><br>
                            <input type="text" name="tag_slug" class="regular-text" placeholder="<?php esc_attr_e( '例如: programming', 'qibbs-community' ); ?>" value="<?php echo $edit_tag ? esc_attr( $edit_tag->slug ) : ''; ?>">
                            <p class="description"><?php esc_html_e( '用户在前台发布内容时使用 #标签 创建标签时，别名将自动设置为标签名称', 'qibbs-community' ); ?></p>
                        </p>
                        <p>
                            <?php if ( $edit_tag ) : ?>
                                <button type="submit" name="qibbs_edit_tag" class="button button-primary"><?php esc_html_e( '更新标签', 'qibbs-community' ); ?></button>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-tags' ) ); ?>" class="button"><?php esc_html_e( '取消', 'qibbs-community' ); ?></a>
                            <?php else : ?>
                                <button type="submit" name="qibbs_add_tag" class="button button-primary"><?php esc_html_e( '添加标签', 'qibbs-community' ); ?></button>
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
                                <th><?php esc_html_e( '使用次数', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '操作', 'qibbs-community' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $tags ) ) : ?>
                                <tr><td colspan="5"><?php esc_html_e( '暂无标签', 'qibbs-community' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $tags as $tag ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $tag->id ); ?></td>
                                        <td><strong>#<?php echo esc_html( $tag->name ); ?></strong></td>
                                        <td><?php echo esc_html( $tag->slug ); ?></td>
                                        <td><?php echo esc_html( $tag->post_count ); ?></td>
                                        <td>
                                            <a href="<?php echo esc_url( add_query_arg( 'edit_tag', $tag->id ) ); ?>"><?php esc_html_e( '编辑', 'qibbs-community' ); ?></a> | 
                                            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'delete_tag', $tag->id ), 'delete_tag_' . $tag->id ) ); ?>" 
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
