<?php
/**
 * 内容审核与社区治理后台管理类
 *
 * @package Qibbs_Community
 * @subpackage Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Moderation_Admin {

    /**
     * 注册控制台仪表盘子菜单
     *
     * @return void
     */
    public function add_admin_menu() {
        $access = $this->get_access_context();
        if ( empty( $access['can_access'] ) ) {
            return;
        }

        $service = new Qibbs_Moderation_Service();
        $counts = $service->get_pending_counts( $access['allowed_forum_ids'] );
        $badge = $counts['total'] > 0 ? ' <span class="awaiting-mod">' . (int) $counts['total'] . '</span>' : '';

        if ( ! empty( $access['is_admin'] ) ) {
            add_submenu_page(
                'qibbs-settings',
                __( '审核队列', 'qibbs-community' ),
                __( '审核队列', 'qibbs-community' ) . $badge,
                'manage_options',
                'qibbs-review-queue',
                array( $this, 'render_page' )
            );
            return;
        }

        add_menu_page(
            __( '社区治理', 'qibbs-community' ),
            __( '社区治理', 'qibbs-community' ) . $badge,
            'read',
            'qibbs-review-queue',
            array( $this, 'render_page' ),
            'dashicons-shield-alt',
            31
        );
    }

    /**
     * 渲染审核中心综合治理页面
     *
     * @return void
     */
    public function render_page() {
        $access = $this->get_access_context();
        if ( empty( $access['can_access'] ) ) {
            wp_die( esc_html__( '权限不足', 'qibbs-community' ) );
        }

        $is_admin = ! empty( $access['is_admin'] );
        $allowed_forum_ids = $access['allowed_forum_ids'];
        $service = new Qibbs_Moderation_Service();
        $tabs = $this->get_tabs( $is_admin );
        $notices = array();

        $tab = isset( $_REQUEST['current_tab'] ) ? sanitize_key( wp_unslash( $_REQUEST['current_tab'] ) ) : '';
        if ( '' === $tab ) {
            $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'posts';
        }
        $tab = isset( $tabs[ $tab ] ) ? $tab : 'posts';

        if ( isset( $_POST['qibbs_moderation_submit'] ) && check_admin_referer( 'qibbs_moderation_queue' ) ) {
            $queue_type = isset( $_POST['queue_type'] ) ? sanitize_key( wp_unslash( $_POST['queue_type'] ) ) : 'posts';
            $action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
            $reason = isset( $_POST['review_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['review_reason'] ) ) : '';
            $ids = isset( $_POST['item_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['item_ids'] ) ) : array();
            $extra_args = array(
                'target_forum_id' => isset( $_POST['target_forum_id'] ) ? absint( wp_unslash( $_POST['target_forum_id'] ) ) : 0,
                'allowed_forum_ids' => $allowed_forum_ids,
            );

            $result = $service->process_bulk_action( $queue_type, $ids, $action, $reason, get_current_user_id(), $extra_args );
            $notices[] = array(
                'type'    => ! empty( $result['success'] ) ? 'success' : 'error',
                'message' => isset( $result['message'] ) ? $result['message'] : __( '操作失败', 'qibbs-community' ),
            );
            $tab = $queue_type;
        }

        if ( $is_admin && isset( $_POST['qibbs_user_governance_submit'] ) && check_admin_referer( 'qibbs_user_governance' ) ) {
            $user_id = isset( $_POST['governance_user_id'] ) ? absint( wp_unslash( $_POST['governance_user_id'] ) ) : 0;
            $action = isset( $_POST['user_action'] ) ? sanitize_key( wp_unslash( $_POST['user_action'] ) ) : '';
            $reason = isset( $_POST['user_action_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['user_action_reason'] ) ) : '';
            $duration_hours = isset( $_POST['cooldown_hours'] ) ? absint( wp_unslash( $_POST['cooldown_hours'] ) ) : 24;

            $result = $service->apply_user_governance_action(
                $user_id,
                $action,
                $reason,
                get_current_user_id(),
                array(
                    'duration_hours' => $duration_hours,
                )
            );

            $notices[] = array(
                'type'    => ! empty( $result['success'] ) ? 'success' : 'error',
                'message' => isset( $result['message'] ) ? $result['message'] : __( '用户治理操作失败', 'qibbs-community' ),
            );
            $tab = 'users';
        }

        if ( isset( $_POST['qibbs_forum_governance_submit'] ) && check_admin_referer( 'qibbs_forum_governance' ) ) {
            $forum_id = isset( $_POST['forum_id'] ) ? absint( wp_unslash( $_POST['forum_id'] ) ) : 0;
            $welcome_message = isset( $_POST['welcome_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['welcome_message'] ) ) : '';
            $rules_text = isset( $_POST['rules_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rules_text'] ) ) : '';

            $result = $service->save_forum_governance( $forum_id, $welcome_message, $rules_text, get_current_user_id(), $allowed_forum_ids );
            $notices[] = array(
                'type'    => ! empty( $result['success'] ) ? 'success' : 'error',
                'message' => isset( $result['message'] ) ? $result['message'] : __( '圈子治理配置保存失败', 'qibbs-community' ),
            );
            $tab = 'forums';
        }

        $summary = $service->get_governance_summary( $allowed_forum_ids );
        $logs = $is_admin ? $service->get_recent_logs( 15 ) : array();
        $paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page = 20;
        $data = array();
        $bulk_actions = array();

        if ( $this->is_table_tab( $tab ) ) {
            $data = $this->get_tab_data( $service, $tab, $paged, $per_page, $allowed_forum_ids );
            $bulk_actions = $service->get_allowed_bulk_actions( $tab );
        }

        $forums = $service->get_forum_governance_rows( $allowed_forum_ids );
        $selected_user_id = 0;
        $user_profile = null;
        if ( $is_admin ) {
            $selected_user_id = isset( $_REQUEST['governance_user_id'] ) ? absint( wp_unslash( $_REQUEST['governance_user_id'] ) ) : 0;
            if ( ! $selected_user_id && isset( $_GET['user_id'] ) ) {
                $selected_user_id = absint( wp_unslash( $_GET['user_id'] ) );
            }
            $user_profile = $selected_user_id ? $service->get_user_governance_profile( $selected_user_id, 30 ) : null;
        }
        ?>
        <div class="wrap qibbs-admin-page">
            <h1><?php esc_html_e( '审核与治理中心', 'qibbs-community' ); ?></h1>
            <p class="description"><?php esc_html_e( '统一处理待审内容、回收站、已发布内容治理、用户禁言/冷却，以及圈子欢迎语与圈规。', 'qibbs-community' ); ?></p>
            <?php if ( ! $is_admin ) : ?>
                <p class="description" style="margin-top:6px;"><?php esc_html_e( '当前账号只可处理自己负责圈子的内容与规则配置，跨圈用户治理和全站日志仍由管理员处理。', 'qibbs-community' ); ?></p>
            <?php endif; ?>

            <?php foreach ( $notices as $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
            <?php endforeach; ?>

            <?php $this->render_summary_cards( $summary, $is_admin ); ?>

            <nav class="nav-tab-wrapper" style="margin:18px 0 16px;">
                <?php foreach ( $tabs as $tab_key => $tab_config ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-review-queue&tab=' . $tab_key ) ); ?>" class="nav-tab <?php echo $tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $tab_config['label'] ); ?>
                        <span class="count" style="margin-left:6px;opacity:.75;">(<?php echo esc_html( $this->get_tab_count( $tab_key, $summary ) ); ?>)</span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <p class="description" style="margin-bottom:16px;"><?php echo esc_html( $tabs[ $tab ]['description'] ); ?></p>

            <?php
            if ( 'live_posts' === $tab ) {
                $this->render_live_posts_tab( $service, $data, $bulk_actions, $forums );
            } elseif ( 'users' === $tab ) {
                $this->render_user_controls_tab( $service, $user_profile );
            } elseif ( 'forums' === $tab ) {
                $this->render_forum_controls_tab( $forums );
            } else {
                $this->render_queue_table( $service, $tab, $tabs[ $tab ], $data, $bulk_actions );
            }
            ?>

            <?php if ( $is_admin ) : ?>
                <?php $this->render_logs( $service, $logs ); ?>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            document.querySelectorAll('[data-qibbs-select-target]').forEach(function(toggle) {
                toggle.addEventListener('change', function() {
                    var selector = toggle.getAttribute('data-qibbs-select-target');
                    document.querySelectorAll(selector).forEach(function(el) {
                        el.checked = toggle.checked;
                    });
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * 渲染审核概览统计卡片
     *
     * @param array $summary Summary data.
     * @return void
     */
    private function render_summary_cards( $summary, $is_admin = true ) {
        $cards = array(
            array(
                'label' => __( '待审动态', 'qibbs-community' ),
                'value' => isset( $summary['pending_posts'] ) ? (int) $summary['pending_posts'] : 0,
                'danger' => false,
            ),
            array(
                'label' => __( '待审评论', 'qibbs-community' ),
                'value' => isset( $summary['pending_comments'] ) ? (int) $summary['pending_comments'] : 0,
                'danger' => false,
            ),
            array(
                'label' => __( '已发布动态', 'qibbs-community' ),
                'value' => isset( $summary['live_posts'] ) ? (int) $summary['live_posts'] : 0,
                'danger' => false,
            ),
            array(
                'label' => __( '置顶动态', 'qibbs-community' ),
                'value' => isset( $summary['pinned_posts'] ) ? (int) $summary['pinned_posts'] : 0,
                'danger' => false,
            ),
            array(
                'label' => __( '精华动态', 'qibbs-community' ),
                'value' => isset( $summary['featured_posts'] ) ? (int) $summary['featured_posts'] : 0,
                'danger' => false,
            ),
            array(
                'label' => __( '锁帖', 'qibbs-community' ),
                'value' => isset( $summary['locked_posts'] ) ? (int) $summary['locked_posts'] : 0,
                'danger' => false,
            ),
            array(
                'label' => __( '待处理举报', 'qibbs-community' ),
                'value' => isset( $summary['pending_reports'] ) ? (int) $summary['pending_reports'] : 0,
                'danger' => true,
            ),
            array(
                'label' => __( '已配置圈规', 'qibbs-community' ),
                'value' => isset( $summary['forum_rules_count'] ) ? (int) $summary['forum_rules_count'] : 0,
                'danger' => false,
            ),
        );

        if ( $is_admin ) {
            $cards[] = array(
                'label' => __( '黑名单用户', 'qibbs-community' ),
                'value' => isset( $summary['blacklisted_users'] ) ? (int) $summary['blacklisted_users'] : 0,
                'danger' => false,
            );
            $cards[] = array(
                'label' => __( '发帖冷却中', 'qibbs-community' ),
                'value' => isset( $summary['active_post_cooldowns'] ) ? (int) $summary['active_post_cooldowns'] : 0,
                'danger' => false,
            );
        }
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:18px 0;">
            <?php foreach ( $cards as $card ) : ?>
                <div style="background:#fff;border:1px solid <?php echo $card['danger'] ? '#f0b3b8' : '#ccd0d4'; ?>;border-radius:8px;padding:14px;">
                    <div style="color:#646970;font-size:13px;"><?php echo esc_html( $card['label'] ); ?></div>
                    <div style="font-size:28px;font-weight:700;line-height:1.2;margin-top:6px;<?php echo $card['danger'] ? 'color:#b32d2e;' : ''; ?>"><?php echo esc_html( $card['value'] ); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * 渲染待审队列与分类表格 Tab
     *
     * @param Qibbs_Moderation_Service $service Service.
     * @param string                   $tab Current tab.
     * @param array                    $tab_config Tab config.
     * @param array                    $data Paged data.
     * @param array                    $bulk_actions Allowed actions.
     * @return void
     */
    private function render_queue_table( $service, $tab, $tab_config, $data, $bulk_actions ) {
        ?>
        <form method="post">
            <?php wp_nonce_field( 'qibbs_moderation_queue' ); ?>
            <input type="hidden" name="current_tab" value="<?php echo esc_attr( $tab ); ?>">
            <input type="hidden" name="queue_type" value="<?php echo esc_attr( $tab ); ?>">

            <div class="tablenav top" style="display:flex;align-items:center;gap:8px;justify-content:space-between;flex-wrap:wrap;">
                <div class="alignleft actions bulkactions" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <select name="bulk_action" required>
                        <option value=""><?php esc_html_e( '选择操作', 'qibbs-community' ); ?></option>
                        <?php foreach ( $bulk_actions as $bulk_action ) : ?>
                            <option value="<?php echo esc_attr( $bulk_action ); ?>"><?php echo esc_html( $service->get_action_label( $bulk_action ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button action" name="qibbs_moderation_submit"><?php esc_html_e( '执行', 'qibbs-community' ); ?></button>
                </div>
                <div style="display:flex;align-items:center;gap:8px;min-width:360px;flex:1 1 360px;">
                    <label for="qibbs-review-reason" style="white-space:nowrap;font-weight:600;"><?php esc_html_e( '回执原因', 'qibbs-community' ); ?></label>
                    <input type="text" id="qibbs-review-reason" name="review_reason" class="regular-text" style="width:100%;" placeholder="<?php esc_attr_e( '可选，建议填写', 'qibbs-community' ); ?>">
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="check-column"><input type="checkbox" data-qibbs-select-target="input[name='item_ids[]']"></td>
                        <th style="width:70px;">ID</th>
                        <th style="width:140px;"><?php esc_html_e( '作者', 'qibbs-community' ); ?></th>
                        <?php if ( $tab_config['is_comment'] ) : ?>
                            <th style="width:110px;"><?php esc_html_e( '动态ID', 'qibbs-community' ); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e( '内容', 'qibbs-community' ); ?></th>
                        <th style="width:120px;"><?php esc_html_e( '状态', 'qibbs-community' ); ?></th>
                        <th style="width:170px;"><?php esc_html_e( '时间', 'qibbs-community' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $data['items'] ) ) : ?>
                    <tr><td colspan="<?php echo $tab_config['is_comment'] ? 7 : 6; ?>" style="text-align:center;padding:20px;"><?php esc_html_e( '当前没有相关内容', 'qibbs-community' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $data['items'] as $item ) : ?>
                        <?php
                        $author_id = isset( $item->author_id ) ? (int) $item->author_id : (int) $item->user_id;
                        $author = get_userdata( $author_id );
                        $content = wp_strip_all_tags( (string) $item->content );
                        $updated_at = ! empty( $item->updated_at ) ? $item->updated_at : $item->created_at;
                        ?>
                        <tr>
                            <th class="check-column"><input type="checkbox" name="item_ids[]" value="<?php echo esc_attr( $item->id ); ?>"></th>
                            <td><?php echo esc_html( $item->id ); ?></td>
                            <td><?php echo $author ? esc_html( $author->display_name ) : esc_html__( '未知用户', 'qibbs-community' ); ?></td>
                            <?php if ( $tab_config['is_comment'] ) : ?>
                                <td><?php echo esc_html( $item->post_id ); ?></td>
                            <?php endif; ?>
                            <td><?php echo esc_html( mb_substr( $content, 0, 140 ) ); ?><?php echo mb_strlen( $content ) > 140 ? '...' : ''; ?></td>
                            <td><code><?php echo esc_html( $item->status ); ?></code></td>
                            <td><?php echo esc_html( $updated_at ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </form>

        <?php if ( $data['total_pages'] > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo wp_kses_post(
                        paginate_links(
                            array(
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'current'   => $data['page'],
                                'total'     => $data['total_pages'],
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            )
                        )
                    );
                    ?>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * 渲染已发布内容治理管理 Tab
     *
     * @param Qibbs_Moderation_Service $service Service.
     * @param array                    $data Paged data.
     * @param array                    $bulk_actions Allowed bulk actions.
     * @param array                    $forums Forum list.
     * @return void
     */
    private function render_live_posts_tab( $service, $data, $bulk_actions, $forums ) {
        $filters = $this->get_live_post_filters();
        ?>
        <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 14px;">
            <input type="hidden" name="page" value="qibbs-review-queue">
            <input type="hidden" name="tab" value="live_posts">
            <input type="search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( '搜索内容或作者', 'qibbs-community' ); ?>" style="min-width:220px;">
            <select name="forum_id">
                <option value="0"><?php esc_html_e( '全部圈子', 'qibbs-community' ); ?></option>
                <?php foreach ( (array) $forums as $forum ) : ?>
                    <option value="<?php echo esc_attr( $forum->id ); ?>" <?php selected( (int) $filters['forum_id'], (int) $forum->id ); ?>><?php echo esc_html( $forum->name ); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="discussion_status">
                <option value=""><?php esc_html_e( '全部讨论状态', 'qibbs-community' ); ?></option>
                <option value="discussion" <?php selected( $filters['discussion_status'], 'discussion' ); ?>><?php esc_html_e( '讨论中', 'qibbs-community' ); ?></option>
                <option value="resolved" <?php selected( $filters['discussion_status'], 'resolved' ); ?>><?php esc_html_e( '已解决', 'qibbs-community' ); ?></option>
                <option value="closed" <?php selected( $filters['discussion_status'], 'closed' ); ?>><?php esc_html_e( '已关闭', 'qibbs-community' ); ?></option>
            </select>
            <select name="featured">
                <option value=""><?php esc_html_e( '全部精华状态', 'qibbs-community' ); ?></option>
                <option value="only" <?php selected( $filters['featured'], 'only' ); ?>><?php esc_html_e( '只看精华', 'qibbs-community' ); ?></option>
                <option value="exclude" <?php selected( $filters['featured'], 'exclude' ); ?>><?php esc_html_e( '排除精华', 'qibbs-community' ); ?></option>
            </select>
            <select name="pinned">
                <option value=""><?php esc_html_e( '全部置顶状态', 'qibbs-community' ); ?></option>
                <option value="only" <?php selected( $filters['pinned'], 'only' ); ?>><?php esc_html_e( '只看置顶', 'qibbs-community' ); ?></option>
                <option value="exclude" <?php selected( $filters['pinned'], 'exclude' ); ?>><?php esc_html_e( '排除置顶', 'qibbs-community' ); ?></option>
            </select>
            <button type="submit" class="button"><?php esc_html_e( '筛选', 'qibbs-community' ); ?></button>
        </form>

        <form method="post">
            <?php wp_nonce_field( 'qibbs_moderation_queue' ); ?>
            <input type="hidden" name="current_tab" value="live_posts">
            <input type="hidden" name="queue_type" value="live_posts">

            <div class="tablenav top" style="display:flex;align-items:center;gap:8px;justify-content:space-between;flex-wrap:wrap;">
                <div class="alignleft actions bulkactions" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <select name="bulk_action" required>
                        <option value=""><?php esc_html_e( '选择操作', 'qibbs-community' ); ?></option>
                        <?php foreach ( $bulk_actions as $bulk_action ) : ?>
                            <option value="<?php echo esc_attr( $bulk_action ); ?>"><?php echo esc_html( $service->get_action_label( $bulk_action ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="target_forum_id">
                        <option value="0"><?php esc_html_e( '移动到圈子...', 'qibbs-community' ); ?></option>
                        <?php foreach ( (array) $forums as $forum ) : ?>
                            <option value="<?php echo esc_attr( $forum->id ); ?>"><?php echo esc_html( $forum->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button action" name="qibbs_moderation_submit"><?php esc_html_e( '执行', 'qibbs-community' ); ?></button>
                </div>
                <div style="display:flex;align-items:center;gap:8px;min-width:360px;flex:1 1 360px;">
                    <label for="qibbs-live-reason" style="white-space:nowrap;font-weight:600;"><?php esc_html_e( '治理备注', 'qibbs-community' ); ?></label>
                    <input type="text" id="qibbs-live-reason" name="review_reason" class="regular-text" style="width:100%;" placeholder="<?php esc_attr_e( '例如：活动置顶 / 误发转移 / 临时锁帖', 'qibbs-community' ); ?>">
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="check-column"><input type="checkbox" data-qibbs-select-target="input[name='item_ids[]']"></td>
                        <th style="width:70px;">ID</th>
                        <th style="width:140px;"><?php esc_html_e( '作者', 'qibbs-community' ); ?></th>
                        <th style="width:140px;"><?php esc_html_e( '圈子', 'qibbs-community' ); ?></th>
                        <th><?php esc_html_e( '内容', 'qibbs-community' ); ?></th>
                        <th style="width:180px;"><?php esc_html_e( '治理状态', 'qibbs-community' ); ?></th>
                        <th style="width:170px;"><?php esc_html_e( '发布时间', 'qibbs-community' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $data['items'] ) ) : ?>
                    <tr><td colspan="7" style="text-align:center;padding:20px;"><?php esc_html_e( '当前没有符合条件的已发布动态', 'qibbs-community' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $data['items'] as $item ) : ?>
                        <?php
                        $author = get_userdata( (int) $item->author_id );
                        $forum_name = $this->get_forum_name( (array) $forums, (int) $item->forum_id );
                        $content = wp_strip_all_tags( (string) $item->content );
                        $states = array();
                        if ( ! empty( $item->is_pinned ) ) {
                            $states[] = __( '置顶', 'qibbs-community' );
                        }
                        if ( ! empty( $item->is_featured ) ) {
                            $states[] = __( '精华', 'qibbs-community' );
                        }
                        $discussion_status = isset( $item->discussion_status ) ? (string) $item->discussion_status : 'discussion';
                        if ( 'closed' === $discussion_status ) {
                            $states[] = __( '已锁帖', 'qibbs-community' );
                        } elseif ( 'resolved' === $discussion_status ) {
                            $states[] = __( '已解决', 'qibbs-community' );
                        } else {
                            $states[] = __( '讨论中', 'qibbs-community' );
                        }
                        ?>
                        <tr>
                            <th class="check-column"><input type="checkbox" name="item_ids[]" value="<?php echo esc_attr( $item->id ); ?>"></th>
                            <td><?php echo esc_html( $item->id ); ?></td>
                            <td><?php echo $author ? esc_html( $author->display_name ) : esc_html__( '未知用户', 'qibbs-community' ); ?></td>
                            <td><?php echo esc_html( $forum_name ); ?></td>
                            <td><?php echo esc_html( mb_substr( $content, 0, 140 ) ); ?><?php echo mb_strlen( $content ) > 140 ? '...' : ''; ?></td>
                            <td><?php echo esc_html( implode( ' / ', $states ) ); ?></td>
                            <td><?php echo esc_html( $item->created_at ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </form>

        <?php if ( $data['total_pages'] > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo wp_kses_post(
                        paginate_links(
                            array(
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'current'   => $data['page'],
                                'total'     => $data['total_pages'],
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                            )
                        )
                    );
                    ?>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * 渲染用户治理与违规处罚 Tab
     *
     * @param Qibbs_Moderation_Service $service Service.
     * @param array|null               $user_profile Selected user profile.
     * @return void
     */
    private function render_user_controls_tab( $service, $user_profile ) {
        $blacklist_rows = $this->get_blacklist_rows( 12 );
        $cooldown_rows = $service->get_active_post_cooldowns( 12 );
        $all_user_ids = array();

        foreach ( (array) $blacklist_rows as $row ) {
            $all_user_ids[] = (int) $row->user_id;
            if ( ! empty( $row->admin_id ) ) {
                $all_user_ids[] = (int) $row->admin_id;
            }
        }

        foreach ( (array) $cooldown_rows as $row ) {
            $all_user_ids[] = (int) $row->user_id;
        }

        if ( ! empty( $user_profile['user_id'] ) ) {
            $all_user_ids[] = (int) $user_profile['user_id'];
        }

        $users_map = $this->get_users_map( $all_user_ids );
        ?>
        <div style="display:grid;grid-template-columns:360px 1fr;gap:16px;">
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                <h2 style="margin-top:0;"><?php esc_html_e( '快速治理用户', 'qibbs-community' ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'qibbs_user_governance' ); ?>
                    <input type="hidden" name="current_tab" value="users">
                    <p>
                        <label for="governance_user_id"><strong><?php esc_html_e( '用户 ID', 'qibbs-community' ); ?></strong></label><br>
                        <input type="number" id="governance_user_id" name="governance_user_id" min="1" class="regular-text" value="<?php echo ! empty( $user_profile['user_id'] ) ? esc_attr( $user_profile['user_id'] ) : ''; ?>" required>
                    </p>
                    <p>
                        <label for="user_action"><strong><?php esc_html_e( '治理动作', 'qibbs-community' ); ?></strong></label><br>
                        <select id="user_action" name="user_action" class="regular-text" required>
                            <option value="blacklist"><?php esc_html_e( '加入黑名单', 'qibbs-community' ); ?></option>
                            <option value="unblacklist"><?php esc_html_e( '移出黑名单', 'qibbs-community' ); ?></option>
                            <option value="set_post_cooldown"><?php esc_html_e( '设置发帖冷却', 'qibbs-community' ); ?></option>
                            <option value="clear_post_cooldown"><?php esc_html_e( '清除发帖冷却', 'qibbs-community' ); ?></option>
                        </select>
                    </p>
                    <p>
                        <label for="cooldown_hours"><strong><?php esc_html_e( '冷却时长（小时）', 'qibbs-community' ); ?></strong></label><br>
                        <input type="number" id="cooldown_hours" name="cooldown_hours" min="1" max="720" class="small-text" value="24">
                    </p>
                    <p>
                        <label for="user_action_reason"><strong><?php esc_html_e( '原因备注', 'qibbs-community' ); ?></strong></label><br>
                        <textarea id="user_action_reason" name="user_action_reason" rows="4" class="large-text" placeholder="<?php esc_attr_e( '建议填写原因，便于后续追溯', 'qibbs-community' ); ?>"></textarea>
                    </p>
                    <p><button type="submit" class="button button-primary" name="qibbs_user_governance_submit"><?php esc_html_e( '执行用户治理', 'qibbs-community' ); ?></button></p>
                </form>
            </div>

            <div style="display:grid;gap:16px;">
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                    <h2 style="margin-top:0;"><?php esc_html_e( '用户画像', 'qibbs-community' ); ?></h2>
                    <?php if ( empty( $user_profile ) ) : ?>
                        <p style="margin:0;color:#646970;"><?php esc_html_e( '输入用户 ID 后可查看黑名单状态、发帖冷却、近 30 天活跃和风险画像。', 'qibbs-community' ); ?></p>
                    <?php else : ?>
                        <div style="display:grid;grid-template-columns:88px 1fr;gap:16px;align-items:center;">
                            <img src="<?php echo esc_url( $user_profile['avatar'] ); ?>" alt="" width="72" height="72" style="border-radius:50%;background:#f6f7f7;">
                            <div>
                                <div style="font-size:20px;font-weight:700;"><?php echo esc_html( $user_profile['display_name'] ); ?></div>
                                <div style="color:#646970;"><?php echo esc_html( $user_profile['user_email'] ); ?> | ID: <?php echo esc_html( $user_profile['user_id'] ); ?></div>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-top:16px;">
                            <div style="border:1px solid #f0f0f1;border-radius:8px;padding:12px;"><strong><?php esc_html_e( '黑名单', 'qibbs-community' ); ?></strong><div style="margin-top:4px;"><?php echo ! empty( $user_profile['is_blacklisted'] ) ? esc_html__( '是', 'qibbs-community' ) : esc_html__( '否', 'qibbs-community' ); ?></div></div>
                            <div style="border:1px solid #f0f0f1;border-radius:8px;padding:12px;"><strong><?php esc_html_e( '发帖冷却', 'qibbs-community' ); ?></strong><div style="margin-top:4px;"><?php echo ! empty( $user_profile['post_cooldown_active'] ) ? esc_html( $user_profile['post_cooldown_until'] ) : esc_html__( '未启用', 'qibbs-community' ); ?></div></div>
                            <div style="border:1px solid #f0f0f1;border-radius:8px;padding:12px;"><strong><?php esc_html_e( '30 天动态', 'qibbs-community' ); ?></strong><div style="margin-top:4px;"><?php echo esc_html( $user_profile['post_count'] ); ?></div></div>
                            <div style="border:1px solid #f0f0f1;border-radius:8px;padding:12px;"><strong><?php esc_html_e( '30 天评论', 'qibbs-community' ); ?></strong><div style="margin-top:4px;"><?php echo esc_html( $user_profile['comment_count'] ); ?></div></div>
                        </div>
                        <?php if ( ! empty( $user_profile['risk_profile'] ) ) : ?>
                            <div style="margin-top:16px;padding:12px;border:1px solid #f0f0f1;border-radius:8px;">
                                <strong><?php esc_html_e( '风险画像', 'qibbs-community' ); ?></strong>
                                <div style="margin-top:8px;"><?php echo esc_html( sprintf( __( '风险分 %1$d / 等级 %2$s / 最近 IP %3$s', 'qibbs-community' ), $user_profile['risk_profile']['score'], $user_profile['risk_profile']['level'], $user_profile['risk_profile']['last_ip'] ? $user_profile['risk_profile']['last_ip'] : '-' ) ); ?></div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                    <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                        <h2 style="margin-top:0;"><?php esc_html_e( '黑名单速览', 'qibbs-community' ); ?></h2>
                        <table class="widefat striped" style="margin:0;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( '用户', 'qibbs-community' ); ?></th>
                                    <th><?php esc_html_e( '原因', 'qibbs-community' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( empty( $blacklist_rows ) ) : ?>
                                    <tr><td colspan="2"><?php esc_html_e( '黑名单为空', 'qibbs-community' ); ?></td></tr>
                                <?php else : ?>
                                    <?php foreach ( $blacklist_rows as $row ) : ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-review-queue&tab=users&user_id=' . (int) $row->user_id ) ); ?>">
                                                    <?php echo ! empty( $users_map[ (int) $row->user_id ] ) ? esc_html( $users_map[ (int) $row->user_id ] ) : 'ID:' . esc_html( $row->user_id ); ?>
                                                </a>
                                            </td>
                                            <td><?php echo esc_html( $row->reason ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                        <h2 style="margin-top:0;"><?php esc_html_e( '发帖冷却列表', 'qibbs-community' ); ?></h2>
                        <table class="widefat striped" style="margin:0;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( '用户', 'qibbs-community' ); ?></th>
                                    <th><?php esc_html_e( '到期时间', 'qibbs-community' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( empty( $cooldown_rows ) ) : ?>
                                    <tr><td colspan="2"><?php esc_html_e( '当前没有发帖冷却中的用户', 'qibbs-community' ); ?></td></tr>
                                <?php else : ?>
                                    <?php foreach ( $cooldown_rows as $row ) : ?>
                                        <tr>
                                            <td>
                                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-review-queue&tab=users&user_id=' . (int) $row->user_id ) ); ?>">
                                                    <?php echo ! empty( $users_map[ (int) $row->user_id ] ) ? esc_html( $users_map[ (int) $row->user_id ] ) : 'ID:' . esc_html( $row->user_id ); ?>
                                                </a>
                                            </td>
                                            <td><?php echo esc_html( $row->cooldown_until ); ?></td>
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
    }

    /**
     * 渲染版块运营与版务治理 Tab
     *
     * @param array $forums Forum rows.
     * @return void
     */
    private function render_forum_controls_tab( $forums ) {
        $selected_forum_id = isset( $_REQUEST['forum_id'] ) ? absint( wp_unslash( $_REQUEST['forum_id'] ) ) : 0;
        if ( ! $selected_forum_id && ! empty( $forums[0]->id ) ) {
            $selected_forum_id = (int) $forums[0]->id;
        }

        $selected_forum = null;
        foreach ( (array) $forums as $forum ) {
            if ( (int) $forum->id === $selected_forum_id ) {
                $selected_forum = $forum;
                break;
            }
        }
        ?>
        <div style="display:grid;grid-template-columns:380px 1fr;gap:16px;">
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                <h2 style="margin-top:0;"><?php esc_html_e( '圈子欢迎语与圈规', 'qibbs-community' ); ?></h2>
                <?php if ( empty( $selected_forum ) ) : ?>
                    <p style="margin:0;color:#646970;"><?php esc_html_e( '请先创建圈子。', 'qibbs-community' ); ?></p>
                <?php else : ?>
                    <form method="post">
                        <?php wp_nonce_field( 'qibbs_forum_governance' ); ?>
                        <input type="hidden" name="current_tab" value="forums">
                        <p>
                            <label for="forum_id"><strong><?php esc_html_e( '圈子', 'qibbs-community' ); ?></strong></label><br>
                            <select id="forum_id" name="forum_id" class="regular-text">
                                <?php foreach ( (array) $forums as $forum ) : ?>
                                    <option value="<?php echo esc_attr( $forum->id ); ?>" <?php selected( (int) $forum->id, $selected_forum_id ); ?>><?php echo esc_html( $forum->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label for="welcome_message"><strong><?php esc_html_e( '欢迎语', 'qibbs-community' ); ?></strong></label><br>
                            <textarea id="welcome_message" name="welcome_message" rows="4" class="large-text" placeholder="<?php esc_attr_e( '例如：发帖前先看置顶，新成员请先做自我介绍。', 'qibbs-community' ); ?>"><?php echo esc_textarea( $selected_forum->welcome_message ); ?></textarea>
                        </p>
                        <p>
                            <label for="rules_text"><strong><?php esc_html_e( '圈规说明', 'qibbs-community' ); ?></strong></label><br>
                            <textarea id="rules_text" name="rules_text" rows="7" class="large-text" placeholder="<?php esc_attr_e( '例如：禁止广告、引战、刷屏，提问请带上下文。', 'qibbs-community' ); ?>"><?php echo esc_textarea( $selected_forum->rules_text ); ?></textarea>
                        </p>
                        <p><button type="submit" class="button button-primary" name="qibbs_forum_governance_submit"><?php esc_html_e( '保存圈子治理配置', 'qibbs-community' ); ?></button></p>
                    </form>
                <?php endif; ?>
            </div>

            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                <h2 style="margin-top:0;"><?php esc_html_e( '圈子治理配置总览', 'qibbs-community' ); ?></h2>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( '圈子', 'qibbs-community' ); ?></th>
                            <th style="width:90px;"><?php esc_html_e( '动态数', 'qibbs-community' ); ?></th>
                            <th style="width:90px;"><?php esc_html_e( '成员数', 'qibbs-community' ); ?></th>
                            <th style="width:90px;"><?php esc_html_e( '版主数', 'qibbs-community' ); ?></th>
                            <th style="width:110px;"><?php esc_html_e( '欢迎语', 'qibbs-community' ); ?></th>
                            <th style="width:110px;"><?php esc_html_e( '圈规', 'qibbs-community' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $forums ) ) : ?>
                            <tr><td colspan="6" style="text-align:center;padding:20px;"><?php esc_html_e( '暂无圈子', 'qibbs-community' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( (array) $forums as $forum ) : ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-review-queue&tab=forums&forum_id=' . (int) $forum->id ) ); ?>">
                                            <?php echo esc_html( $forum->name ); ?>
                                        </a>
                                        <div style="color:#646970;font-size:12px;"><?php echo esc_html( $forum->slug ); ?></div>
                                    </td>
                                    <td><?php echo esc_html( (int) $forum->post_count ); ?></td>
                                    <td><?php echo esc_html( (int) $forum->member_count ); ?></td>
                                    <td><?php echo esc_html( (int) $forum->moderator_count ); ?></td>
                                    <td><?php echo ! empty( $forum->welcome_message ) ? esc_html__( '已配置', 'qibbs-community' ) : esc_html__( '未配置', 'qibbs-community' ); ?></td>
                                    <td><?php echo ! empty( $forum->rules_text ) ? esc_html__( '已配置', 'qibbs-community' ) : esc_html__( '未配置', 'qibbs-community' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染近期审核操作流水日志
     *
     * @param Qibbs_Moderation_Service $service Service.
     * @param array                    $logs Logs.
     * @return void
     */
    private function render_logs( $service, $logs ) {
        ?>
        <div style="margin-top:20px;background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:14px;max-width:980px;">
            <h2 style="margin-top:0;"><?php esc_html_e( '最近治理记录', 'qibbs-community' ); ?></h2>
            <table class="widefat striped" style="margin:0;">
                <thead>
                    <tr>
                        <th style="width:70px;">ID</th>
                        <th style="width:120px;"><?php esc_html_e( '类型', 'qibbs-community' ); ?></th>
                        <th style="width:90px;"><?php esc_html_e( '对象', 'qibbs-community' ); ?></th>
                        <th style="width:140px;"><?php esc_html_e( '动作', 'qibbs-community' ); ?></th>
                        <th><?php esc_html_e( '原因', 'qibbs-community' ); ?></th>
                        <th style="width:170px;"><?php esc_html_e( '时间', 'qibbs-community' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $logs ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( '暂无治理记录', 'qibbs-community' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $logs as $log ) : ?>
                        <tr>
                            <td><?php echo esc_html( $log->id ); ?></td>
                            <td><?php echo esc_html( $log->object_type ); ?></td>
                            <td>#<?php echo esc_html( $log->object_id ); ?></td>
                            <td><?php echo esc_html( $service->get_action_label( $log->action ) ); ?></td>
                            <td><?php echo esc_html( $log->reason ); ?></td>
                            <td><?php echo esc_html( $log->created_at ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * 获取治理中心各 Tab 配置项
     *
     * @return array
     */
    private function get_tabs( $is_admin = true ) {
        $tabs = array(
            'posts' => array(
                'label'       => __( '待审动态', 'qibbs-community' ),
                'description' => __( '统一处理待审动态，支持批量通过、拒绝和移入回收站。', 'qibbs-community' ),
                'is_comment'  => false,
            ),
            'comments' => array(
                'label'       => __( '待审评论', 'qibbs-community' ),
                'description' => __( '统一处理待审评论，支持批量通过、拒绝和移入回收站。', 'qibbs-community' ),
                'is_comment'  => true,
            ),
            'trash_posts' => array(
                'label'       => __( '动态回收站', 'qibbs-community' ),
                'description' => __( '误删内容会先进入回收站，可恢复到待审状态或彻底删除。', 'qibbs-community' ),
                'is_comment'  => false,
            ),
            'trash_comments' => array(
                'label'       => __( '评论回收站', 'qibbs-community' ),
                'description' => __( '误删评论会先进入回收站，可恢复到待审状态或彻底删除。', 'qibbs-community' ),
                'is_comment'  => true,
            ),
            'live_posts' => array(
                'label'       => __( '已发布治理', 'qibbs-community' ),
                'description' => __( '对已发布动态执行置顶、精华、锁帖、移动圈子和移入回收站等批量治理动作。', 'qibbs-community' ),
                'is_comment'  => false,
            ),
            'users' => array(
                'label'       => __( '用户治理', 'qibbs-community' ),
                'description' => __( '快速执行黑名单禁言和发帖冷却，并查看用户风险画像。', 'qibbs-community' ),
                'is_comment'  => false,
            ),
            'forums' => array(
                'label'       => __( '圈子规则', 'qibbs-community' ),
                'description' => __( '为圈子维护欢迎语和圈规，方便版主管理与新成员引导。', 'qibbs-community' ),
                'is_comment'  => false,
            ),
        );

        if ( ! $is_admin ) {
            unset( $tabs['users'] );
        }

        return $tabs;
    }

    /**
     * 检查当前 Tab 是否采用分页表格数据源
     *
     * @param string $tab Tab key.
     * @return bool
     */
    private function is_table_tab( $tab ) {
        return in_array( $tab, array( 'posts', 'comments', 'trash_posts', 'trash_comments', 'live_posts' ), true );
    }

    /**
     * 获取单个 Tab 的待处理红点计数
     *
     * @param string $tab Tab key.
     * @param array  $summary Summary data.
     * @return int
     */
    private function get_tab_count( $tab, $summary ) {
        $map = array(
            'posts'          => 'pending_posts',
            'comments'       => 'pending_comments',
            'trash_posts'    => 'trash_posts',
            'trash_comments' => 'trash_comments',
            'live_posts'     => 'live_posts',
            'users'          => 'blacklisted_users',
            'forums'         => 'forum_rules_count',
        );

        return isset( $map[ $tab ], $summary[ $map[ $tab ] ] ) ? (int) $summary[ $map[ $tab ] ] : 0;
    }

    /**
     * 获取对应 Tab 的数据列表
     *
     * @param Qibbs_Moderation_Service $service Service.
     * @param string                   $tab Tab key.
     * @param int                      $paged Page.
     * @param int                      $per_page Per page.
     * @return array
     */
    private function get_tab_data( $service, $tab, $paged, $per_page, $allowed_forum_ids = array() ) {
        switch ( $tab ) {
            case 'comments':
                return $service->get_pending_comments( $paged, $per_page, $allowed_forum_ids );
            case 'trash_posts':
                return $service->get_trashed_posts( $paged, $per_page, $allowed_forum_ids );
            case 'trash_comments':
                return $service->get_trashed_comments( $paged, $per_page, $allowed_forum_ids );
            case 'live_posts':
                return $service->get_live_posts( $paged, $per_page, $this->get_live_post_filters(), $allowed_forum_ids );
            case 'posts':
            default:
                return $service->get_pending_posts( $paged, $per_page, $allowed_forum_ids );
        }
    }

    /**
     * 获取当前管理员审核权限上下文
     *
     * @return array
     */
    private function get_access_context() {
        $user_id = get_current_user_id();
        $is_admin = current_user_can( 'manage_options' );
        $allowed_forum_ids = array();

        if ( ! $is_admin && $user_id ) {
            $moderator_service = new Qibbs_Forum_Moderator_Service();
            $allowed_forum_ids = $moderator_service->get_moderated_forum_ids( $user_id );
        }

        return array(
            'can_access'        => $is_admin || ! empty( $allowed_forum_ids ),
            'is_admin'          => $is_admin,
            'allowed_forum_ids' => $allowed_forum_ids,
        );
    }

    /**
     * 从请求中解析当前内容筛选过滤条件
     *
     * @return array
     */
    private function get_live_post_filters() {
        $featured = isset( $_GET['featured'] ) ? sanitize_key( wp_unslash( $_GET['featured'] ) ) : '';
        $pinned = isset( $_GET['pinned'] ) ? sanitize_key( wp_unslash( $_GET['pinned'] ) ) : '';

        return array(
            'forum_id'          => isset( $_GET['forum_id'] ) ? absint( wp_unslash( $_GET['forum_id'] ) ) : 0,
            'search'            => isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
            'discussion_status' => isset( $_GET['discussion_status'] ) ? sanitize_key( wp_unslash( $_GET['discussion_status'] ) ) : '',
            'featured'          => in_array( $featured, array( 'only', 'exclude' ), true ) ? $featured : '',
            'pinned'            => in_array( $pinned, array( 'only', 'exclude' ), true ) ? $pinned : '',
        );
    }

    /**
     * 查询最近被处罚的黑名单记录
     *
     * @param int $limit Limit.
     * @return array
     */
    private function get_blacklist_rows( $limit = 10 ) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, reason, admin_id, created_at
                 FROM " . Qibbs_Database::blacklist() . "
                 ORDER BY created_at DESC
                 LIMIT %d",
                max( 1, min( 100, absint( $limit ) ) )
            )
        );
    }

    /**
     * 批量获取用户昵称映射字典
     *
     * @param array $user_ids User IDs.
     * @return array
     */
    private function get_users_map( $user_ids ) {
        $user_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) );
        if ( empty( $user_ids ) ) {
            return array();
        }

        $users = get_users(
            array(
                'include' => $user_ids,
                'fields'  => array( 'ID', 'display_name' ),
            )
        );

        $map = array();
        foreach ( (array) $users as $user ) {
            $map[ (int) $user->ID ] = (string) $user->display_name;
        }

        return $map;
    }

    /**
     * 从记录行批量解析版块名称
     *
     * @param array $forums Forum rows.
     * @param int   $forum_id Forum ID.
     * @return string
     */
    private function get_forum_name( $forums, $forum_id ) {
        $forum_id = absint( $forum_id );
        foreach ( (array) $forums as $forum ) {
            if ( (int) $forum->id === $forum_id ) {
                return (string) $forum->name;
            }
        }

        return __( '未分区', 'qibbs-community' );
    }
}
