<?php
/**
 * 视图模板：社区帖子与动态管理
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
        $table = Qibbs_Database::posts();
        
        $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
        $paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        $per_page = 20;
        $offset = ( $paged - 1 ) * $per_page;
        
        // 处理后台提交的操作动作
        if ( isset( $_POST['action'] ) && isset( $_POST['post_ids'] ) && check_admin_referer( 'qibbs_bulk_action' ) ) {
            $action = sanitize_text_field( wp_unslash( $_POST['action'] ) );
            $post_ids = array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) );
            
            if ( ! empty( $post_ids ) ) {
                $approved_pending_rows = array();
                $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
                switch ( $action ) {
                    case 'approve':
                        $approved_pending_rows = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT id, author_id FROM {$table} WHERE status = 'qibbs_pending' AND id IN ({$placeholders})",
                                $post_ids
                            )
                        );
                        $wpdb->query(
                            $wpdb->prepare(
                                "UPDATE {$table} SET status = 'publish' WHERE id IN ({$placeholders})",
                                $post_ids
                            )
                        );
                        break;
                    case 'block':
                        $wpdb->query(
                            $wpdb->prepare(
                                "UPDATE {$table} SET status = 'qibbs_blocked' WHERE id IN ({$placeholders})",
                                $post_ids
                            )
                        );
                        break;
                    case 'unblock':
                        $wpdb->query(
                            $wpdb->prepare(
                                "UPDATE {$table} SET status = 'publish' WHERE id IN ({$placeholders})",
                                $post_ids
                            )
                        );
                        break;
                    case 'delete':
                        if ( class_exists( 'Qibbs_Moderation_Service' ) ) {
                            $moderation_service = new Qibbs_Moderation_Service();
                            $moderation_service->delete_posts( $post_ids );
                        } else {
                            if ( class_exists( 'Qibbs_Cache_Service' ) ) {
                                foreach ( $post_ids as $delete_cache_post_id ) {
                                    Qibbs_Cache_Service::invalidate_post_cache( (int) $delete_cache_post_id );
                                }
                            }
                            $wpdb->query(
                                $wpdb->prepare(
                                    "DELETE FROM {$table} WHERE id IN ({$placeholders})",
                                    $post_ids
                                )
                            );
                        }
                        break;
                }

                if ( 'approve' === $action && ! empty( $approved_pending_rows ) ) {
                    foreach ( $approved_pending_rows as $approved_row ) {
                        do_action( 'qibbs_post_created', (int) $approved_row->id, (int) $approved_row->author_id );
                    }
                }

                if ( class_exists( 'Qibbs_Cache_Service' ) ) {
                    if ( in_array( $action, array( 'block', 'unblock' ), true ) ) {
                        foreach ( $post_ids as $post_id ) {
                            Qibbs_Cache_Service::invalidate_post_cache( (int) $post_id );
                        }
                    }
                }
                echo '<div class="notice notice-success"><p>' . __( '操作成功', 'qibbs-community' ) . '</p></div>';
            }
        }
        
        if ( ! empty( $status_filter ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status_filter ) );
            $posts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d", $status_filter, $per_page, $offset ) );
        } else {
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE 1 = %d", 1 ) );
            $posts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE 1 = %d ORDER BY created_at DESC LIMIT %d OFFSET %d", 1, $per_page, $offset ) );
        }
        
        $total_pages = ceil( $total / $per_page );
        $all_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE 1 = %d", 1 ) );
        ?>
        <div class="qibbs-posts-filter" style="margin-bottom:15px;">
            <a href="?page=qibbs-management&tab=posts" class="<?php echo empty( $status_filter ) ? 'current' : ''; ?>"><?php _e( '全部', 'qibbs-community' ); ?> (<?php echo esc_html( (string) $all_count ); ?>)</a> |
            <a href="?page=qibbs-management&tab=posts&status=publish" class="<?php echo $status_filter === 'publish' ? 'current' : ''; ?>"><?php _e( '已发布', 'qibbs-community' ); ?></a> |
            <a href="?page=qibbs-management&tab=posts&status=qibbs_pending" class="<?php echo $status_filter === 'qibbs_pending' ? 'current' : ''; ?>"><?php _e( '待审核', 'qibbs-community' ); ?></a> |
            <a href="?page=qibbs-management&tab=posts&status=qibbs_blocked" class="<?php echo $status_filter === 'qibbs_blocked' ? 'current' : ''; ?>"><?php _e( '已屏蔽', 'qibbs-community' ); ?></a>
        </div>
        
        <form method="post">
            <?php wp_nonce_field( 'qibbs_bulk_action' ); ?>
            
            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <select name="action">
                        <option value=""><?php _e( '批量操作', 'qibbs-community' ); ?></option>
                        <option value="approve"><?php _e( '通过审核', 'qibbs-community' ); ?></option>
                        <option value="block"><?php _e( '屏蔽', 'qibbs-community' ); ?></option>
                        <option value="unblock"><?php _e( '取消屏蔽', 'qibbs-community' ); ?></option>
                        <option value="delete"><?php _e( '删除', 'qibbs-community' ); ?></option>
                    </select>
                    <input type="submit" class="button action" value="<?php esc_attr_e( '应用', 'qibbs-community' ); ?>">
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="check-column"><input type="checkbox" class="qibbs-select-all"></td>
                        <th style="width:50px;"><?php _e( 'ID', 'qibbs-community' ); ?></th>
                        <th style="width:120px;"><?php _e( '作者', 'qibbs-community' ); ?></th>
                        <th><?php _e( '内容', 'qibbs-community' ); ?></th>
                        <th style="width:80px;"><?php _e( '状态', 'qibbs-community' ); ?></th>
                        <th style="width:150px;"><?php _e( '发布时间', 'qibbs-community' ); ?></th>
                        <th style="width:150px;"><?php _e( '操作', 'qibbs-community' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $posts ) ) : ?>
                        <tr><td colspan="7" style="text-align:center;padding:20px;"><?php _e( '暂无数据', 'qibbs-community' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $posts as $post ) : ?>
                            <?php $author = get_userdata( $post->author_id ); ?>
                            <tr>
                                <td><input type="checkbox" name="post_ids[]" value="<?php echo esc_attr( $post->id ); ?>"></td>
                                <td><?php echo esc_html( $post->id ); ?></td>
                                <td><?php echo $author ? esc_html( $author->display_name ) : __( '未知', 'qibbs-community' ); ?></td>
                                <td><?php echo esc_html( mb_substr( wp_strip_all_tags( $post->content ), 0, 80 ) ); ?><?php echo mb_strlen( $post->content ) > 80 ? '...' : ''; ?></td>
                                <td>
                                    <?php
                                    $status_labels = array(
                                        'publish' => '<span style="color:green;">' . __( '已发布', 'qibbs-community' ) . '</span>',
                                        'qibbs_pending' => '<span style="color:orange;">' . __( '待审核', 'qibbs-community' ) . '</span>',
                                        'qibbs_blocked' => '<span style="color:red;">' . __( '已屏蔽', 'qibbs-community' ) . '</span>',
                                        'draft' => '<span style="color:gray;">' . __( '草稿', 'qibbs-community' ) . '</span>',
                                    );
                                    echo $status_labels[ $post->status ] ?? esc_html( $post->status );
                                    ?>
                                </td>
                                <td><?php echo esc_html( $post->created_at ); ?></td>
                                <td>
                                    <div class="row-actions">
                                        <?php if ( $post->status !== 'publish' ) : ?>
                                            <span class="approve"><a href="#" onclick="qibbsAdminAction(<?php echo $post->id; ?>,'approve');return false;"><?php _e( '通过', 'qibbs-community' ); ?></a> | </span>
                                        <?php endif; ?>
                                        <?php if ( $post->status !== 'qibbs_blocked' ) : ?>
                                            <span class="block"><a href="#" onclick="qibbsAdminAction(<?php echo $post->id; ?>,'block');return false;"><?php _e( '屏蔽', 'qibbs-community' ); ?></a> | </span>
                                        <?php endif; ?>
                                        <?php if ( $post->status === 'qibbs_blocked' ) : ?>
                                            <span class="unblock"><a href="#" onclick="qibbsAdminAction(<?php echo $post->id; ?>,'unblock');return false;" style="color:#00a32a;"><?php _e( '取消屏蔽', 'qibbs-community' ); ?></a> | </span>
                                        <?php endif; ?>
                                        <span class="delete"><a href="#" onclick="qibbsAdminAction(<?php echo $post->id; ?>,'delete');return false;" style="color:#b32d2e;"><?php _e( '删除', 'qibbs-community' ); ?></a></span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </form>
        
        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'prev_text' => __( '&laquo;' ),
                        'next_text' => __( '&raquo;' ),
                        'total'     => $total_pages,
                        'current'   => $paged,
                    ) );
                    ?>
                </div>
            </div>
        <?php endif; ?>
