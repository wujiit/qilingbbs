<?php
/**
 * 视图模板：内容举报与审核处理
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
        $reports_table = Qibbs_Database::reports();
        $posts_table = Qibbs_Database::posts();
        $moderation_service = new Qibbs_Moderation_Service();
        $report_notice = null;

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $reports_table ) );
        if ( $table_exists !== $reports_table ) {
            echo '<div class="wrap qibbs-admin-page"><h1>' . esc_html__( '举报管理', 'qibbs-community' ) . '</h1>';
            echo '<div class="notice notice-warning"><p>' . esc_html__( '举报数据表尚未创建，请停用后重新启用插件。', 'qibbs-community' ) . '</p></div></div>';
            return;
        }

        if ( isset( $_POST['qibbs_report_submit'] ) && check_admin_referer( 'qibbs_report_action' ) ) {
            $action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
            $report_ids = isset( $_POST['report_ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['report_ids'] ) ) ) : array();
            $admin_note = isset( $_POST['admin_note'] ) ? sanitize_text_field( wp_unslash( $_POST['admin_note'] ) ) : '';
            $admin_id = get_current_user_id();

            if ( ! empty( $report_ids ) && in_array( $action, array( 'resolve', 'ignore', 'block', 'trash', 'blacklist_author' ), true ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $report_ids ), '%d' ) );
                $selected_reports = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT r.id, r.post_id, p.author_id AS post_author_id, p.content AS post_content, p.status AS post_status
                         FROM {$reports_table} r
                         LEFT JOIN {$posts_table} p ON p.id = r.post_id
                         WHERE r.id IN ({$placeholders})",
                        $report_ids
                    )
                );

                $selected_report_ids = array();
                $post_ids = array();
                $author_ids = array();
                foreach ( (array) $selected_reports as $selected_report ) {
                    $selected_report_ids[] = (int) $selected_report->id;
                    if ( ! empty( $selected_report->post_id ) ) {
                        $post_ids[] = (int) $selected_report->post_id;
                    }
                    if ( ! empty( $selected_report->post_author_id ) ) {
                        $author_ids[] = (int) $selected_report->post_author_id;
                    }
                }

                $post_ids = array_values( array_unique( array_filter( $post_ids ) ) );
                $author_ids = array_values( array_unique( array_filter( $author_ids ) ) );
                $status = ( 'ignore' === $action ) ? 'ignored' : 'resolved';
                $now = current_time( 'mysql' );
                $handled_report_ids = array();
                $handled_post_ids = array();
                $handled_author_ids = array();
                $action_result = array(
                    'success' => true,
                    'message' => __( '举报处理完成', 'qibbs-community' ),
                );

                if ( empty( $selected_report_ids ) ) {
                    $action_result = array(
                        'success' => false,
                        'message' => __( '未找到可处理的举报', 'qibbs-community' ),
                    );
                } elseif ( in_array( $action, array( 'resolve', 'ignore' ), true ) ) {
                    $handled_report_ids = $selected_report_ids;
                } elseif ( 'block' === $action ) {
                    if ( empty( $post_ids ) ) {
                        $action_result = array(
                            'success' => false,
                            'message' => __( '未找到可屏蔽的动态', 'qibbs-community' ),
                        );
                    } else {
                        $action_result = $moderation_service->process_bulk_action( 'live_posts', $post_ids, 'block', $admin_note, $admin_id );
                        $handled_post_ids = ! empty( $action_result['processed_ids'] )
                            ? array_values( array_unique( array_filter( array_map( 'absint', (array) $action_result['processed_ids'] ) ) ) )
                            : array();
                        if ( ! empty( $action_result['success'] ) && ! empty( $handled_post_ids ) ) {
                            $action_result['message'] = __( '已屏蔽被举报动态并完成结案', 'qibbs-community' );
                        } elseif ( ! empty( $action_result['success'] ) ) {
                            $action_result = array(
                                'success' => false,
                                'message' => __( '没有成功屏蔽任何被举报动态', 'qibbs-community' ),
                            );
                        }
                    }
                } elseif ( 'trash' === $action ) {
                    if ( empty( $post_ids ) ) {
                        $action_result = array(
                            'success' => false,
                            'message' => __( '未找到可移入回收站的动态', 'qibbs-community' ),
                        );
                    } else {
                        $action_result = $moderation_service->process_bulk_action( 'live_posts', $post_ids, 'trash', $admin_note, $admin_id );
                        $handled_post_ids = ! empty( $action_result['processed_ids'] )
                            ? array_values( array_unique( array_filter( array_map( 'absint', (array) $action_result['processed_ids'] ) ) ) )
                            : array();
                        if ( ! empty( $action_result['success'] ) && ! empty( $handled_post_ids ) ) {
                            $action_result['message'] = __( '已将被举报动态移入回收站并完成结案', 'qibbs-community' );
                        } elseif ( ! empty( $action_result['success'] ) ) {
                            $action_result = array(
                                'success' => false,
                                'message' => __( '没有成功处理任何被举报动态', 'qibbs-community' ),
                            );
                        }
                    }
                } elseif ( 'blacklist_author' === $action ) {
                    if ( empty( $author_ids ) ) {
                        $action_result = array(
                            'success' => false,
                            'message' => __( '未找到可处理的动态作者', 'qibbs-community' ),
                        );
                    } else {
                        $handled_count = 0;
                        foreach ( $author_ids as $author_id ) {
                            $result = $moderation_service->apply_user_governance_action( $author_id, 'blacklist', $admin_note, $admin_id );
                            if ( ! empty( $result['success'] ) ) {
                                $handled_count++;
                                $handled_author_ids[] = (int) $author_id;
                            }
                        }

                        if ( $handled_count <= 0 ) {
                            $action_result = array(
                                'success' => false,
                                'message' => __( '作者加入黑名单失败，请稍后重试', 'qibbs-community' ),
                            );
                        } else {
                            $action_result['message'] = sprintf( __( '已将 %d 位被举报作者加入黑名单并完成结案', 'qibbs-community' ), $handled_count );
                        }
                    }
                }

                if ( empty( $handled_report_ids ) && ( ! empty( $handled_post_ids ) || ! empty( $handled_author_ids ) ) ) {
                    foreach ( (array) $selected_reports as $selected_report ) {
                        $selected_post_id = isset( $selected_report->post_id ) ? (int) $selected_report->post_id : 0;
                        $selected_author_id = isset( $selected_report->post_author_id ) ? (int) $selected_report->post_author_id : 0;
                        if (
                            ( $selected_post_id && in_array( $selected_post_id, $handled_post_ids, true ) ) ||
                            ( $selected_author_id && in_array( $selected_author_id, $handled_author_ids, true ) )
                        ) {
                            $handled_report_ids[] = (int) $selected_report->id;
                        }
                    }
                    $handled_report_ids = array_values( array_unique( array_filter( $handled_report_ids ) ) );
                }

                if ( ! empty( $action_result['success'] ) && empty( $handled_report_ids ) ) {
                    $action_result = array(
                        'success' => false,
                        'message' => __( '没有可结案的举报记录', 'qibbs-community' ),
                    );
                }

                if ( ! empty( $action_result['success'] ) ) {
                    $handled_placeholders = implode( ',', array_fill( 0, count( $handled_report_ids ), '%d' ) );
                    $updated_reports = $wpdb->query(
                        $wpdb->prepare(
                            "UPDATE {$reports_table}
                             SET status = %s, admin_id = %d, admin_note = %s, handled_at = %s
                             WHERE id IN ({$handled_placeholders})",
                            array_merge( array( $status, $admin_id, $admin_note, $now ), $handled_report_ids )
                        )
                    );

                    if ( false === $updated_reports ) {
                        $action_result = array(
                            'success' => false,
                            'message' => __( '治理动作已执行，但举报结案状态保存失败，请重试', 'qibbs-community' ),
                        );
                    } else {
                        if ( class_exists( 'Qibbs_Cache_Service' ) ) {
                            foreach ( array_values( array_unique( array_filter( array_map( 'absint', $handled_post_ids ) ) ) ) as $handled_post_id ) {
                                Qibbs_Cache_Service::invalidate_post_cache( $handled_post_id );
                            }
                        }

                        $remaining_reports = max( 0, count( $selected_report_ids ) - count( $handled_report_ids ) );
                        if ( $remaining_reports > 0 ) {
                            $action_result['message'] .= sprintf( __( '，另有 %d 条保持待处理', 'qibbs-community' ), $remaining_reports );
                        }
                    }
                }

                $report_notice = array(
                    'type'    => ! empty( $action_result['success'] ) ? 'success' : 'error',
                    'message' => isset( $action_result['message'] ) ? $action_result['message'] : __( '举报处理失败', 'qibbs-community' ),
                );
            }
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pending';
        $allowed_tabs = array( 'pending', 'resolved', 'ignored' );
        if ( ! in_array( $tab, $allowed_tabs, true ) ) {
            $tab = 'pending';
        }

        $paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page = 20;
        $offset = ( $paged - 1 ) * $per_page;

        $total = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$reports_table} r WHERE r.status = %s", $tab )
        );
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, p.content AS post_content, p.status AS post_status, p.author_id AS post_author_id,
                        u.display_name AS reporter_name
                 FROM {$reports_table} r
                 LEFT JOIN {$posts_table} p ON p.id = r.post_id
                 LEFT JOIN {$wpdb->users} u ON u.ID = r.reporter_id
                 WHERE r.status = %s
                 ORDER BY r.created_at DESC
                 LIMIT %d OFFSET %d",
                $tab,
                $per_page,
                $offset
            )
        );
        ?>
        <div class="wrap qibbs-admin-page">
            <h1><?php esc_html_e( '举报管理', 'qibbs-community' ); ?></h1>

            <?php if ( ! empty( $report_notice ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $report_notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $report_notice['message'] ); ?></p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-reports&tab=pending' ) ); ?>" class="nav-tab <?php echo ( 'pending' === $tab ) ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '待处理', 'qibbs-community' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-reports&tab=resolved' ) ); ?>" class="nav-tab <?php echo ( 'resolved' === $tab ) ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '已处理', 'qibbs-community' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-reports&tab=ignored' ) ); ?>" class="nav-tab <?php echo ( 'ignored' === $tab ) ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '已忽略', 'qibbs-community' ); ?>
                </a>
            </nav>

            <form method="post" style="margin-top: 16px;">
                <?php wp_nonce_field( 'qibbs_report_action' ); ?>
                <input type="hidden" name="qibbs_report_submit" value="1">
                <?php if ( 'pending' === $tab ) : ?>
                    <div class="tablenav top">
                        <div class="alignleft actions bulkactions">
                            <select name="bulk_action">
                                <option value=""><?php esc_html_e( '批量操作', 'qibbs-community' ); ?></option>
                                <option value="resolve"><?php esc_html_e( '标记已处理', 'qibbs-community' ); ?></option>
                                <option value="ignore"><?php esc_html_e( '忽略举报', 'qibbs-community' ); ?></option>
                                <option value="block"><?php esc_html_e( '屏蔽动态并结案', 'qibbs-community' ); ?></option>
                                <option value="trash"><?php esc_html_e( '移入回收站并结案', 'qibbs-community' ); ?></option>
                                <option value="blacklist_author"><?php esc_html_e( '作者拉黑并结案', 'qibbs-community' ); ?></option>
                            </select>
                            <input type="text" name="admin_note" placeholder="<?php esc_attr_e( '处理备注(可选)', 'qibbs-community' ); ?>" style="min-width: 220px;">
                            <button type="submit" class="button action"><?php esc_html_e( '应用', 'qibbs-community' ); ?></button>
                        </div>
                    </div>
                <?php endif; ?>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="check-column"><input type="checkbox" class="qibbs-select-all-reports"></td>
                            <th style="width:70px;">ID</th>
                            <th style="width:80px;"><?php esc_html_e( '动态ID', 'qibbs-community' ); ?></th>
                            <th><?php esc_html_e( '动态内容', 'qibbs-community' ); ?></th>
                            <th style="width:130px;"><?php esc_html_e( '举报用户', 'qibbs-community' ); ?></th>
                            <th style="width:240px;"><?php esc_html_e( '举报原因', 'qibbs-community' ); ?></th>
                            <th style="width:130px;"><?php esc_html_e( '时间', 'qibbs-community' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rows ) ) : ?>
                            <tr><td colspan="7" style="text-align:center;padding:24px;"><?php esc_html_e( '暂无记录', 'qibbs-community' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $rows as $row ) : ?>
                                <?php
                                $excerpt = wp_strip_all_tags( (string) $row->post_content );
                                if ( mb_strlen( $excerpt ) > 60 ) {
                                    $excerpt = mb_substr( $excerpt, 0, 60 ) . '...';
                                }
                                ?>
                                <tr>
                                    <td><input type="checkbox" name="report_ids[]" value="<?php echo esc_attr( $row->id ); ?>"></td>
                                    <td><?php echo esc_html( $row->id ); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url( qibbs_get_post_url( absint( $row->post_id ) ) ); ?>" target="_blank" rel="noopener">
                                            #<?php echo esc_html( $row->post_id ); ?>
                                        </a>
                                    </td>
                                    <td><?php echo esc_html( $excerpt ); ?></td>
                                    <td><?php echo esc_html( $row->reporter_name ? $row->reporter_name : ( 'ID:' . absint( $row->reporter_id ) ) ); ?></td>
                                    <td><?php echo esc_html( $row->reason ); ?></td>
                                    <td><?php echo esc_html( $row->created_at ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom" style="margin-top:10px;">
                    <div class="tablenav-pages">
                        <?php
                        echo wp_kses_post(
                            paginate_links( array(
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'prev_text' => __( '&laquo;', 'qibbs-community' ),
                                'next_text' => __( '&raquo;', 'qibbs-community' ),
                                'total'     => $total_pages,
                                'current'   => $paged,
                            ) )
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <script>
        (function() {
            var checker = document.querySelector('.qibbs-select-all-reports');
            if (!checker) return;
            checker.addEventListener('change', function() {
                document.querySelectorAll('input[name="report_ids[]"]').forEach(function(el) {
                    el.checked = checker.checked;
                });
            });
        })();
        </script>
        <?php
