<?php
/**
 * 后台控制台面板管理类
 *
 * @package Qibbs_Community
 * @subpackage Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Dashboard_Admin {

    /**
     * 注册控制台仪表盘子菜单
     *
     * @return void
     */
    public function add_admin_menu() {
        add_submenu_page(
            'qibbs-settings',
            __( '社区看板', 'qibbs-community' ),
            __( '社区看板', 'qibbs-community' ),
            'manage_options',
            'qibbs-dashboard',
            array( $this, 'render_page' )
        );
    }

    /**
     * 渲染控制台仪表盘运营看板
     *
     * @return void
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '权限不足', 'qibbs-community' ) );
        }

        $dashboard_service = new Qibbs_Dashboard_Service();
        $overview = $dashboard_service->get_overview_metrics();
        $activity = $dashboard_service->get_activity_windows();
        $top_forums = $dashboard_service->get_top_forums( 8 );
        $top_authors = $dashboard_service->get_top_authors( 8 );
        $risk_profiles = class_exists( 'Qibbs_Risk_Service' ) ? Qibbs_Risk_Service::get_user_risk_profiles( array( 'days' => 30, 'limit' => 8 ) ) : array();
        $logs = $dashboard_service->get_recent_moderation_logs( 10 );
        $user_ids = array();

        foreach ( (array) $logs as $log ) {
            if ( ! empty( $log->admin_id ) ) {
                $user_ids[] = (int) $log->admin_id;
            }
            if ( ! empty( $log->target_user_id ) ) {
                $user_ids[] = (int) $log->target_user_id;
            }
        }

        $users_map = array();
        $user_ids = array_values( array_unique( array_filter( $user_ids ) ) );
        if ( ! empty( $user_ids ) ) {
            $users = get_users(
                array(
                    'include' => $user_ids,
                    'fields'  => array( 'ID', 'display_name' ),
                )
            );

            foreach ( (array) $users as $user ) {
                $users_map[ (int) $user->ID ] = (string) $user->display_name;
            }
        }
        ?>
        <div class="wrap qibbs-admin-page">
            <h1><?php esc_html_e( '社区看板', 'qibbs-community' ); ?></h1>
            <p class="description"><?php esc_html_e( '聚合社区内容活跃、治理压力、风控风险和工单运行情况，帮助运营快速判断当前健康度。', 'qibbs-community' ); ?></p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:18px 0;">
                <?php $this->render_metric_card( __( '已发布动态', 'qibbs-community' ), $overview['total_posts'] ); ?>
                <?php $this->render_metric_card( __( '已发布评论', 'qibbs-community' ), $overview['total_comments'] ); ?>
                <?php $this->render_metric_card( __( '7 日活跃用户', 'qibbs-community' ), $overview['active_users_7d'] ); ?>
                <?php $this->render_metric_card( __( '30 日活跃用户', 'qibbs-community' ), $overview['active_users_30d'] ); ?>
                <?php $this->render_metric_card( __( '问答解决率', 'qibbs-community' ), $overview['resolved_rate'] . '%' ); ?>
                <?php $this->render_metric_card( __( '待审内容', 'qibbs-community' ), $overview['pending_moderation'], true ); ?>
                <?php $this->render_metric_card( __( '待处理举报', 'qibbs-community' ), $overview['pending_reports'], true ); ?>
                <?php $this->render_metric_card( __( '风险观察用户', 'qibbs-community' ), $overview['risk_watch_count'], true ); ?>
                <?php $this->render_metric_card( __( '黑名单用户', 'qibbs-community' ), $overview['blacklisted_users'] ); ?>
                <?php $this->render_metric_card( __( '发帖冷却中', 'qibbs-community' ), $overview['active_cooldowns'] ); ?>
                <?php $this->render_metric_card( __( '开放工单', 'qibbs-community' ), $overview['open_tickets'] ); ?>
                <?php $this->render_metric_card( __( 'SLA 超时工单', 'qibbs-community' ), $overview['sla_breach_count'], true ); ?>
            </div>

            <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:16px;margin:0 0 16px;">
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                    <h2 style="margin:0 0 12px;"><?php esc_html_e( '活跃度窗口', 'qibbs-community' ); ?></h2>
                    <table class="widefat striped" style="margin:0;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( '窗口', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '动态', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '评论', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '活跃用户', 'qibbs-community' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $activity as $window_label => $window_data ) : ?>
                                <tr>
                                    <td><?php echo esc_html( strtoupper( $window_label ) ); ?></td>
                                    <td><?php echo esc_html( $window_data['posts'] ); ?></td>
                                    <td><?php echo esc_html( $window_data['comments'] ); ?></td>
                                    <td><?php echo esc_html( $window_data['active_users'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                    <h2 style="margin:0 0 12px;"><?php esc_html_e( '问答沉淀', 'qibbs-community' ); ?></h2>
                    <div style="display:grid;gap:12px;">
                        <div style="border:1px solid #f0f0f1;border-radius:8px;padding:12px;">
                            <div style="color:#646970;font-size:13px;"><?php esc_html_e( '问答总数', 'qibbs-community' ); ?></div>
                            <div style="font-size:24px;font-weight:700;margin-top:4px;"><?php echo esc_html( $overview['total_questions'] ); ?></div>
                        </div>
                        <div style="border:1px solid #f0f0f1;border-radius:8px;padding:12px;">
                            <div style="color:#646970;font-size:13px;"><?php esc_html_e( '已解决问答', 'qibbs-community' ); ?></div>
                            <div style="font-size:24px;font-weight:700;margin-top:4px;"><?php echo esc_html( $overview['resolved_questions'] ); ?></div>
                        </div>
                        <div style="border:1px solid #f0f0f1;border-radius:8px;padding:12px;">
                            <div style="color:#646970;font-size:13px;"><?php esc_html_e( '解决率', 'qibbs-community' ); ?></div>
                            <div style="font-size:24px;font-weight:700;margin-top:4px;"><?php echo esc_html( $overview['resolved_rate'] ); ?>%</div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:0 0 16px;">
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                    <h2 style="margin:0 0 12px;"><?php esc_html_e( '圈子活跃榜（30 天）', 'qibbs-community' ); ?></h2>
                    <table class="widefat striped" style="margin:0;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( '圈子', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '近 30 天发帖', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '累计动态', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '成员数', 'qibbs-community' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $top_forums ) ) : ?>
                                <tr><td colspan="4"><?php esc_html_e( '暂无圈子数据', 'qibbs-community' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $top_forums as $forum ) : ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html( $forum->name ); ?></strong>
                                            <div style="color:#646970;font-size:12px;"><?php echo esc_html( $forum->slug ); ?></div>
                                        </td>
                                        <td><?php echo esc_html( (int) $forum->recent_post_count ); ?></td>
                                        <td><?php echo esc_html( (int) $forum->post_count ); ?></td>
                                        <td><?php echo esc_html( (int) $forum->member_count ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                    <h2 style="margin:0 0 12px;"><?php esc_html_e( '作者活跃榜（30 天）', 'qibbs-community' ); ?></h2>
                    <table class="widefat striped" style="margin:0;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( '用户', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '动态', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '评论', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '综合分', 'qibbs-community' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $top_authors ) ) : ?>
                                <tr><td colspan="4"><?php esc_html_e( '暂无作者活跃数据', 'qibbs-community' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $top_authors as $author ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $author->display_name ); ?></td>
                                        <td><?php echo esc_html( (int) $author->post_count ); ?></td>
                                        <td><?php echo esc_html( (int) $author->comment_count ); ?></td>
                                        <td><?php echo esc_html( (int) $author->activity_score ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                    <h2 style="margin:0 0 12px;"><?php esc_html_e( '风险观察列表', 'qibbs-community' ); ?></h2>
                    <table class="widefat striped" style="margin:0;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( '用户', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '风险分', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '等级', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '最近事件', 'qibbs-community' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $risk_profiles ) ) : ?>
                                <tr><td colspan="4"><?php esc_html_e( '最近 30 天暂无高风险用户', 'qibbs-community' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $risk_profiles as $profile ) : ?>
                                    <?php $recent_reason = ! empty( $profile['recent_events'][0]['reason'] ) ? $profile['recent_events'][0]['reason'] : __( '无', 'qibbs-community' ); ?>
                                    <tr>
                                        <td><?php echo esc_html( $profile['display_name'] ); ?></td>
                                        <td><?php echo esc_html( $profile['score'] ); ?></td>
                                        <td><?php echo esc_html( $profile['level'] ); ?></td>
                                        <td><?php echo esc_html( mb_substr( (string) $recent_reason, 0, 40 ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                    <h2 style="margin:0 0 12px;"><?php esc_html_e( '最近治理动作', 'qibbs-community' ); ?></h2>
                    <table class="widefat striped" style="margin:0;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( '时间', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '动作', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '对象', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '操作人', 'qibbs-community' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( empty( $logs ) ) : ?>
                                <tr><td colspan="4"><?php esc_html_e( '暂无治理日志', 'qibbs-community' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $logs as $log ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $log->created_at ); ?></td>
                                        <td><?php echo esc_html( $this->get_log_action_label( $log->action ) ); ?></td>
                                        <td><?php echo esc_html( $log->object_type . '#' . (int) $log->object_id ); ?></td>
                                        <td><?php echo ! empty( $users_map[ (int) $log->admin_id ] ) ? esc_html( $users_map[ (int) $log->admin_id ] ) : '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * 渲染单个仪表盘核心指标卡片
     *
     * @param string $label Label.
     * @param mixed  $value Value.
     * @param bool   $danger Whether highlight as warning.
     * @return void
     */
    private function render_metric_card( $label, $value, $danger = false ) {
        ?>
        <div style="background:#fff;border:1px solid <?php echo $danger ? '#f0b3b8' : '#ccd0d4'; ?>;border-radius:8px;padding:14px;">
            <div style="color:#646970;font-size:13px;"><?php echo esc_html( $label ); ?></div>
            <div style="font-size:28px;font-weight:700;line-height:1.2;margin-top:6px;<?php echo $danger ? 'color:#b32d2e;' : ''; ?>"><?php echo esc_html( $value ); ?></div>
        </div>
        <?php
    }

    /**
     * 仪表盘日志动作中文文案映射
     *
     * @param string $action Action key.
     * @return string
     */
    private function get_log_action_label( $action ) {
        $map = array(
            'approve'                           => __( '审核通过', 'qibbs-community' ),
            'reject'                            => __( '审核拒绝', 'qibbs-community' ),
            'trash'                             => __( '移入回收站', 'qibbs-community' ),
            'restore'                           => __( '恢复', 'qibbs-community' ),
            'purge'                             => __( '彻底删除', 'qibbs-community' ),
            'forum_add_moderator'               => __( '新增圈子版主', 'qibbs-community' ),
            'forum_remove_moderator'            => __( '移除圈子版主', 'qibbs-community' ),
            'moderator_pin_post'                => __( '置顶动态', 'qibbs-community' ),
            'moderator_unpin_post'              => __( '取消置顶', 'qibbs-community' ),
            'moderator_feature_post'            => __( '设为精华', 'qibbs-community' ),
            'moderator_unfeature_post'          => __( '取消精华', 'qibbs-community' ),
            'moderator_lock_post'               => __( '锁帖', 'qibbs-community' ),
            'moderator_unlock_post'             => __( '解除锁帖', 'qibbs-community' ),
            'moderator_move_forum_post'         => __( '移动圈子', 'qibbs-community' ),
            'moderator_trash_post'              => __( '移入回收站', 'qibbs-community' ),
            'moderator_block_post'              => __( '屏蔽动态', 'qibbs-community' ),
            'moderator_delete_post'             => __( '删除动态', 'qibbs-community' ),
            'moderator_update_discussion_status' => __( '调整讨论状态', 'qibbs-community' ),
            'moderator_reopen_question'         => __( '重开讨论', 'qibbs-community' ),
            'moderator_set_best_answer'         => __( '设置最佳答案', 'qibbs-community' ),
            'moderator_clear_best_answer'       => __( '取消最佳答案', 'qibbs-community' ),
            'moderator_pin_quality_comment'     => __( '优质回复置顶', 'qibbs-community' ),
            'moderator_unpin_quality_comment'   => __( '取消优质置顶', 'qibbs-community' ),
            'moderator_delete_comment'          => __( '删除评论', 'qibbs-community' ),
            'moderator_block_comment'           => __( '屏蔽评论', 'qibbs-community' ),
            'moderator_blacklist_user'          => __( '加入黑名单', 'qibbs-community' ),
            'moderator_unblacklist_user'        => __( '移出黑名单', 'qibbs-community' ),
            'moderator_set_post_cooldown'       => __( '设置发帖冷却', 'qibbs-community' ),
            'moderator_clear_post_cooldown'     => __( '清除发帖冷却', 'qibbs-community' ),
            'moderator_update_forum_governance' => __( '更新圈子规则', 'qibbs-community' ),
        );

        $action = sanitize_key( (string) $action );
        return isset( $map[ $action ] ) ? $map[ $action ] : $action;
    }
}
