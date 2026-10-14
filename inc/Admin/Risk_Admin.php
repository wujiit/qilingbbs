<?php
/**
 * 安全风控中心后台管理类
 *
 * @package Qibbs_Community
 * @subpackage Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Risk_Admin {

    /**
     * 注册安全风控后台子菜单
     *
     * @return void
     */
    public function add_admin_menu() {
        add_submenu_page(
            'qibbs-settings',
            __( '风控中心', 'qibbs-community' ),
            __( '风控中心', 'qibbs-community' ),
            'manage_options',
            'qibbs-risk',
            array( $this, 'render_page' )
        );
    }

    /**
     * 渲染安全风控中心管理页面
     *
     * @return void
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( '权限不足', 'qibbs-community' ) );
        }

        $notices = array();

        if ( isset( $_GET['remove_ban'] ) && isset( $_GET['_wpnonce'] ) ) {
            $ban_id = absint( $_GET['remove_ban'] );
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'qibbs_remove_ban_' . $ban_id ) ) {
                Qibbs_Risk_Service::remove_ip_ban( $ban_id );
                $redirect_url = remove_query_arg( array( 'remove_ban', '_wpnonce' ) );
                $redirect_url = add_query_arg( 'risk_notice', 'ban_removed', $redirect_url );
                wp_safe_redirect( $redirect_url );
                exit;
            }
        }

        if ( isset( $_GET['risk_notice'] ) && sanitize_key( wp_unslash( $_GET['risk_notice'] ) ) === 'ban_removed' ) {
            $notices[] = array( 'type' => 'success', 'message' => __( '已解除该封禁记录', 'qibbs-community' ) );
        }

        if ( isset( $_POST['qibbs_save_risk_settings'] ) && check_admin_referer( 'qibbs_risk_settings' ) ) {
            $this->save_settings();
            $notices[] = array( 'type' => 'success', 'message' => __( '风控设置已保存', 'qibbs-community' ) );
        }

        if ( isset( $_POST['qibbs_add_ip_ban'] ) && check_admin_referer( 'qibbs_add_ip_ban' ) ) {
            $ip = isset( $_POST['ban_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ban_ip'] ) ) : '';
            $reason = isset( $_POST['ban_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['ban_reason'] ) ) : '';
            $minutes = isset( $_POST['ban_minutes'] ) ? absint( $_POST['ban_minutes'] ) : 0;

            if ( Qibbs_Risk_Service::add_ip_ban( $ip, $reason, get_current_user_id(), $minutes ) ) {
                $notices[] = array( 'type' => 'success', 'message' => __( 'IP 已封禁', 'qibbs-community' ) );
            } else {
                $notices[] = array( 'type' => 'error', 'message' => __( '封禁失败，请检查 IP 格式', 'qibbs-community' ) );
            }
        }

        if ( isset( $_POST['qibbs_cleanup_risk_logs'] ) && check_admin_referer( 'qibbs_cleanup_risk_logs' ) ) {
            $days = max( 1, absint( qibbs_get_option( 'risk_log_retention_days', 30 ) ) );
            $deleted = Qibbs_Risk_Service::cleanup_logs( $days );
            $notices[] = array(
                'type'    => 'success',
                'message' => sprintf( __( '日志清理完成，删除 %d 条历史记录', 'qibbs-community' ), max( 0, (int) $deleted ) ),
            );
        }

        $page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $filters = array(
            'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
            'action' => isset( $_GET['risk_action'] ) ? sanitize_key( wp_unslash( $_GET['risk_action'] ) ) : '',
            'ip'     => isset( $_GET['risk_ip'] ) ? sanitize_text_field( wp_unslash( $_GET['risk_ip'] ) ) : '',
            'page'   => $page,
        );

        $logs_data = Qibbs_Risk_Service::get_logs( $filters );
        $bans = Qibbs_Risk_Service::get_active_bans( 200 );
        $risk_profiles = Qibbs_Risk_Service::get_user_risk_profiles(
            array(
                'days'  => 30,
                'limit' => 10,
            )
        );
        $selected_risk_user_id = isset( $_GET['risk_user_id'] ) ? absint( wp_unslash( $_GET['risk_user_id'] ) ) : 0;
        if ( ! $selected_risk_user_id && ! empty( $risk_profiles ) ) {
            $selected_risk_user_id = (int) $risk_profiles[0]['user_id'];
        }
        $selected_profile = $selected_risk_user_id ? Qibbs_Risk_Service::get_user_risk_profile( $selected_risk_user_id, 30 ) : null;
        $stats = $this->get_stats();
        ?>
        <div class="wrap qibbs-admin-page">
            <h1><?php esc_html_e( '风控中心', 'qibbs-community' ); ?></h1>
            <p class="description"><?php esc_html_e( '控制 IP 频率、自动封禁异常请求，并查看内容关键词/频率联动风控与用户风险画像。', 'qibbs-community' ); ?></p>

            <?php foreach ( $notices as $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
            <?php endforeach; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:18px 0;max-width:1200px;">
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px;">
                    <strong><?php esc_html_e( '今日拦截', 'qibbs-community' ); ?></strong>
                    <div style="font-size:22px;margin-top:6px;"><?php echo esc_html( $stats['blocked_today'] ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px;">
                    <strong><?php esc_html_e( '今日总命中', 'qibbs-community' ); ?></strong>
                    <div style="font-size:22px;margin-top:6px;"><?php echo esc_html( $stats['hits_today'] ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px;">
                    <strong><?php esc_html_e( '当前封禁 IP', 'qibbs-community' ); ?></strong>
                    <div style="font-size:22px;margin-top:6px;"><?php echo esc_html( count( $bans ) ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px;">
                    <strong><?php esc_html_e( '今日内容拦截', 'qibbs-community' ); ?></strong>
                    <div style="font-size:22px;margin-top:6px;"><?php echo esc_html( $stats['content_blocked_today'] ); ?></div>
                </div>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px;">
                    <strong><?php esc_html_e( '30 天风险用户', 'qibbs-community' ); ?></strong>
                    <div style="font-size:22px;margin-top:6px;"><?php echo esc_html( $stats['risky_users_30d'] ); ?></div>
                </div>
            </div>

            <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
                <div style="flex:1 1 460px;min-width:420px;background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                    <h2 style="margin-top:0;"><?php esc_html_e( '风控规则', 'qibbs-community' ); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field( 'qibbs_risk_settings' ); ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th><?php esc_html_e( '启用 IP 限流', 'qibbs-community' ); ?></th>
                                <td><label><input type="checkbox" name="risk_enable_ip_limit" value="1" <?php checked( qibbs_get_option( 'risk_enable_ip_limit', true ) ); ?>> <?php esc_html_e( '开启后所有 qibbs 公开接口执行限流与封禁检测', 'qibbs-community' ); ?></label></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( '日志模式', 'qibbs-community' ); ?></th>
                                <td>
                                    <?php $mode = qibbs_get_option( 'risk_log_mode', 'blocked' ); ?>
                                    <select name="risk_log_mode">
                                        <option value="blocked" <?php selected( $mode, 'blocked' ); ?>><?php esc_html_e( '仅记录拦截', 'qibbs-community' ); ?></option>
                                        <option value="all" <?php selected( $mode, 'all' ); ?>><?php esc_html_e( '记录全部命中', 'qibbs-community' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( '启用自动封禁', 'qibbs-community' ); ?></th>
                                <td><label><input type="checkbox" name="risk_enable_auto_ban" value="1" <?php checked( qibbs_get_option( 'risk_enable_auto_ban', true ) ); ?>> <?php esc_html_e( '异常请求触发后自动加入封禁', 'qibbs-community' ); ?></label></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( '触发阈值', 'qibbs-community' ); ?></th>
                                <td><input type="number" min="3" max="200" name="risk_auto_ban_threshold" value="<?php echo esc_attr( qibbs_get_option( 'risk_auto_ban_threshold', 12 ) ); ?>"> <?php esc_html_e( '次拦截', 'qibbs-community' ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( '统计窗口', 'qibbs-community' ); ?></th>
                                <td><input type="number" min="1" max="180" name="risk_auto_ban_window" value="<?php echo esc_attr( qibbs_get_option( 'risk_auto_ban_window', 10 ) ); ?>"> <?php esc_html_e( '分钟', 'qibbs-community' ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( '封禁时长', 'qibbs-community' ); ?></th>
                                <td><input type="number" min="0" max="43200" name="risk_auto_ban_duration" value="<?php echo esc_attr( qibbs_get_option( 'risk_auto_ban_duration', 60 ) ); ?>"> <?php esc_html_e( '分钟（0 表示永久）', 'qibbs-community' ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( '日志保留', 'qibbs-community' ); ?></th>
                                <td><input type="number" min="1" max="365" name="risk_log_retention_days" value="<?php echo esc_attr( qibbs_get_option( 'risk_log_retention_days', 30 ) ); ?>"> <?php esc_html_e( '天', 'qibbs-community' ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( '客户端 IP 识别模式', 'qibbs-community' ); ?></th>
                                <td>
                                    <?php $ip_mode = sanitize_key( (string) qibbs_get_option( 'risk_client_ip_mode', 'auto_proxy' ) ); ?>
                                    <select name="risk_client_ip_mode">
                                        <option value="auto_proxy" <?php selected( $ip_mode, 'auto_proxy' ); ?>><?php esc_html_e( '自动代理模式（推荐）', 'qibbs-community' ); ?></option>
                                        <option value="strict_proxy" <?php selected( $ip_mode, 'strict_proxy' ); ?>><?php esc_html_e( '严格代理模式', 'qibbs-community' ); ?></option>
                                        <option value="cdn_compatible" <?php selected( $ip_mode, 'cdn_compatible' ); ?>><?php esc_html_e( 'CDN 兼容模式', 'qibbs-community' ); ?></option>
                                        <option value="remote_only" <?php selected( $ip_mode, 'remote_only' ); ?>><?php esc_html_e( '仅 REMOTE_ADDR', 'qibbs-community' ); ?></option>
                                    </select>
                                    <p class="description">
                                        <?php esc_html_e( '自动代理模式：若配置了可信代理网段则仅信任这些代理；未配置时按 CDN 兼容逻辑读取转发头，保障普通站点可直接获取真实访客 IP。', 'qibbs-community' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( '可信代理网段', 'qibbs-community' ); ?></th>
                                <td>
                                    <textarea name="risk_trusted_proxies" rows="4" class="large-text code" placeholder="173.245.48.0/20&#10;103.21.244.0/22"><?php echo esc_textarea( (string) qibbs_get_option( 'risk_trusted_proxies', '' ) ); ?></textarea>
                                    <p class="description">
                                        <?php esc_html_e( '可填代理/CDN 回源 IP 或 CIDR，支持逗号、空格或换行分隔。留空时自动代理模式会保持 CDN 兼容。', 'qibbs-community' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( '启用内容联动风控', 'qibbs-community' ); ?></th>
                                <td><label><input type="checkbox" name="risk_enable_content_rules" value="1" <?php checked( qibbs_get_option( 'risk_enable_content_rules', false ) ); ?>> <?php esc_html_e( '对发帖、评论、私信、工单启用“关键词 + 频率”联合判断', 'qibbs-community' ); ?></label></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( '风险关键词', 'qibbs-community' ); ?></th>
                                <td>
                                    <textarea name="risk_content_keywords" rows="4" class="large-text" placeholder="<?php esc_attr_e( '每行一个，或用逗号分隔', 'qibbs-community' ); ?>"><?php echo esc_textarea( (string) qibbs_get_option( 'risk_content_keywords', '' ) ); ?></textarea>
                                    <p class="description"><?php esc_html_e( '仅在内容命中关键词时，才会继续叠加频率阈值判断。', 'qibbs-community' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( '频率阈值', 'qibbs-community' ); ?></th>
                                <td><input type="number" min="2" max="50" name="risk_content_frequency_limit" value="<?php echo esc_attr( qibbs_get_option( 'risk_content_frequency_limit', 4 ) ); ?>"> <?php esc_html_e( '次提交', 'qibbs-community' ); ?></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e( '频率窗口', 'qibbs-community' ); ?></th>
                                <td><input type="number" min="1" max="120" name="risk_content_frequency_window" value="<?php echo esc_attr( qibbs_get_option( 'risk_content_frequency_window', 10 ) ); ?>"> <?php esc_html_e( '分钟', 'qibbs-community' ); ?></td>
                            </tr>
                        </table>
                        <p>
                            <button type="submit" name="qibbs_save_risk_settings" class="button button-primary"><?php esc_html_e( '保存风控设置', 'qibbs-community' ); ?></button>
                        </p>
                    </form>
                </div>

                <div style="flex:1 1 360px;min-width:320px;background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                    <h2 style="margin-top:0;"><?php esc_html_e( '手动封禁 IP', 'qibbs-community' ); ?></h2>
                    <form method="post">
                        <?php wp_nonce_field( 'qibbs_add_ip_ban' ); ?>
                        <p>
                            <label for="qibbs-ban-ip"><?php esc_html_e( 'IP 地址', 'qibbs-community' ); ?></label>
                            <input type="text" id="qibbs-ban-ip" name="ban_ip" class="regular-text" required placeholder="例如 127.0.0.1">
                        </p>
                        <p>
                            <label for="qibbs-ban-reason"><?php esc_html_e( '封禁原因', 'qibbs-community' ); ?></label>
                            <input type="text" id="qibbs-ban-reason" name="ban_reason" class="regular-text" placeholder="可选">
                        </p>
                        <p>
                            <label for="qibbs-ban-minutes"><?php esc_html_e( '封禁时长', 'qibbs-community' ); ?></label>
                            <input type="number" id="qibbs-ban-minutes" name="ban_minutes" min="0" max="43200" value="0"> <?php esc_html_e( '分钟（0 为永久）', 'qibbs-community' ); ?>
                        </p>
                        <p><button type="submit" name="qibbs_add_ip_ban" class="button button-secondary"><?php esc_html_e( '加入封禁', 'qibbs-community' ); ?></button></p>
                    </form>

                    <hr>

                    <h3><?php esc_html_e( '已封禁 IP', 'qibbs-community' ); ?></h3>
                    <div style="max-height:260px;overflow:auto;border:1px solid #eee;border-radius:6px;">
                        <table class="widefat striped" style="margin:0;border:0;">
                            <thead><tr><th><?php esc_html_e( 'IP', 'qibbs-community' ); ?></th><th><?php esc_html_e( '到期', 'qibbs-community' ); ?></th><th><?php esc_html_e( '操作', 'qibbs-community' ); ?></th></tr></thead>
                            <tbody>
                            <?php if ( empty( $bans ) ) : ?>
                                <tr><td colspan="3"><?php esc_html_e( '暂无封禁记录', 'qibbs-community' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $bans as $ban ) : ?>
                                    <tr>
                                        <td>
                                            <code><?php echo esc_html( $ban->ip ); ?></code>
                                            <?php if ( ! empty( $ban->reason ) ) : ?>
                                                <div style="color:#646970;font-size:12px;"><?php echo esc_html( $ban->reason ); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo ! empty( $ban->expires_at ) && $ban->expires_at !== '0000-00-00 00:00:00' ? esc_html( $ban->expires_at ) : esc_html__( '永久', 'qibbs-community' ); ?></td>
                                        <td>
                                            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'qibbs-risk', 'remove_ban' => $ban->id ) ), 'qibbs_remove_ban_' . $ban->id ) ); ?>" onclick="return confirm('<?php esc_attr_e( '确认解除该 IP 封禁？', 'qibbs-community' ); ?>');"><?php esc_html_e( '解除', 'qibbs-community' ); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div style="margin-top:20px;display:grid;grid-template-columns:minmax(360px,1fr) minmax(420px,1.2fr);gap:16px;align-items:flex-start;">
                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                    <h2 style="margin-top:0;"><?php esc_html_e( '用户风险画像', 'qibbs-community' ); ?></h2>
                    <p class="description"><?php esc_html_e( '综合风控日志、被举报次数、审核动作和封禁状态，帮助版主/客服快速识别高风险账号。', 'qibbs-community' ); ?></p>
                    <table class="widefat striped" style="margin:0;">
                        <thead>
                        <tr>
                            <th><?php esc_html_e( '用户', 'qibbs-community' ); ?></th>
                            <th style="width:90px;"><?php esc_html_e( '评分', 'qibbs-community' ); ?></th>
                            <th style="width:90px;"><?php esc_html_e( '等级', 'qibbs-community' ); ?></th>
                            <th style="width:90px;"><?php esc_html_e( '操作', 'qibbs-community' ); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ( empty( $risk_profiles ) ) : ?>
                            <tr><td colspan="4"><?php esc_html_e( '最近 30 天暂无明显风险用户。', 'qibbs-community' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $risk_profiles as $profile ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $profile['display_name'] ); ?></td>
                                    <td><?php echo esc_html( $profile['score'] ); ?></td>
                                    <td><code><?php echo esc_html( $profile['level'] ); ?></code></td>
                                    <td>
                                        <a href="<?php echo esc_url( add_query_arg( 'risk_user_id', (int) $profile['user_id'] ) ); ?>" class="button button-small"><?php esc_html_e( '查看', 'qibbs-community' ); ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                    <h2 style="margin-top:0;"><?php esc_html_e( '画像详情', 'qibbs-community' ); ?></h2>
                    <?php if ( ! $selected_profile ) : ?>
                        <p><?php esc_html_e( '当前没有可展示的画像。', 'qibbs-community' ); ?></p>
                    <?php else : ?>
                        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                            <img src="<?php echo esc_url( $selected_profile['avatar'] ); ?>" alt="" style="width:48px;height:48px;border-radius:50%;">
                            <div>
                                <div style="font-size:18px;font-weight:600;"><?php echo esc_html( $selected_profile['display_name'] ); ?></div>
                                <div style="color:#646970;"><?php printf( esc_html__( '风险评分 %d / 等级 %s', 'qibbs-community' ), (int) $selected_profile['score'], esc_html( $selected_profile['level'] ) ); ?></div>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:16px;">
                            <div style="border:1px solid #f0f0f1;border-radius:8px;padding:10px;"><strong><?php esc_html_e( '拦截请求', 'qibbs-community' ); ?></strong><div style="font-size:20px;margin-top:6px;"><?php echo esc_html( $selected_profile['metrics']['blocked_count'] ); ?></div></div>
                            <div style="border:1px solid #f0f0f1;border-radius:8px;padding:10px;"><strong><?php esc_html_e( '关键词观察', 'qibbs-community' ); ?></strong><div style="font-size:20px;margin-top:6px;"><?php echo esc_html( $selected_profile['metrics']['content_flag_count'] ); ?></div></div>
                            <div style="border:1px solid #f0f0f1;border-radius:8px;padding:10px;"><strong><?php esc_html_e( '内容拦截', 'qibbs-community' ); ?></strong><div style="font-size:20px;margin-top:6px;"><?php echo esc_html( $selected_profile['metrics']['content_block_count'] ); ?></div></div>
                            <div style="border:1px solid #f0f0f1;border-radius:8px;padding:10px;"><strong><?php esc_html_e( '被举报', 'qibbs-community' ); ?></strong><div style="font-size:20px;margin-top:6px;"><?php echo esc_html( $selected_profile['metrics']['report_count'] ); ?></div></div>
                            <div style="border:1px solid #f0f0f1;border-radius:8px;padding:10px;"><strong><?php esc_html_e( '审核动作', 'qibbs-community' ); ?></strong><div style="font-size:20px;margin-top:6px;"><?php echo esc_html( $selected_profile['metrics']['moderation_count'] ); ?></div></div>
                            <div style="border:1px solid #f0f0f1;border-radius:8px;padding:10px;"><strong><?php esc_html_e( '活跃封禁', 'qibbs-community' ); ?></strong><div style="font-size:20px;margin-top:6px;"><?php echo esc_html( $selected_profile['active_bans'] ); ?></div></div>
                        </div>

                        <p><strong><?php esc_html_e( '最近 IP', 'qibbs-community' ); ?>：</strong> <code><?php echo esc_html( $selected_profile['last_ip'] ? $selected_profile['last_ip'] : __( '暂无', 'qibbs-community' ) ); ?></code></p>

                        <h3 style="margin:16px 0 8px;"><?php esc_html_e( '最近事件', 'qibbs-community' ); ?></h3>
                        <table class="widefat striped" style="margin:0;">
                            <thead>
                            <tr>
                                <th style="width:170px;"><?php esc_html_e( '时间', 'qibbs-community' ); ?></th>
                                <th style="width:150px;"><?php esc_html_e( '动作', 'qibbs-community' ); ?></th>
                                <th style="width:90px;"><?php esc_html_e( '状态', 'qibbs-community' ); ?></th>
                                <th><?php esc_html_e( '原因', 'qibbs-community' ); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ( empty( $selected_profile['recent_events'] ) ) : ?>
                                <tr><td colspan="4"><?php esc_html_e( '暂无事件记录。', 'qibbs-community' ); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ( $selected_profile['recent_events'] as $event ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $event['time'] ); ?></td>
                                        <td><code><?php echo esc_html( $event['action'] ); ?></code></td>
                                        <td><?php echo esc_html( $event['status'] ); ?></td>
                                        <td><?php echo esc_html( $event['reason'] ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top:20px;background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:16px;">
                <h2 style="margin-top:0;"><?php esc_html_e( '接口命中日志', 'qibbs-community' ); ?></h2>

                <form method="get" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                    <input type="hidden" name="page" value="qibbs-risk">
                    <div>
                        <label><?php esc_html_e( '状态', 'qibbs-community' ); ?></label><br>
                        <select name="status">
                            <option value=""><?php esc_html_e( '全部', 'qibbs-community' ); ?></option>
                            <option value="allow" <?php selected( $filters['status'], 'allow' ); ?>>allow</option>
                            <option value="blocked" <?php selected( $filters['status'], 'blocked' ); ?>>blocked</option>
                        </select>
                    </div>
                    <div>
                        <label><?php esc_html_e( '动作', 'qibbs-community' ); ?></label><br>
                        <input type="text" name="risk_action" value="<?php echo esc_attr( $filters['action'] ); ?>" placeholder="例如 ajax_load_feed">
                    </div>
                    <div>
                        <label><?php esc_html_e( 'IP', 'qibbs-community' ); ?></label><br>
                        <input type="text" name="risk_ip" value="<?php echo esc_attr( $filters['ip'] ); ?>" placeholder="例如 127.0.0.1">
                    </div>
                    <div>
                        <button type="submit" class="button"><?php esc_html_e( '筛选', 'qibbs-community' ); ?></button>
                    </div>
                </form>

                <form method="post" style="margin-bottom:12px;">
                    <?php wp_nonce_field( 'qibbs_cleanup_risk_logs' ); ?>
                    <button type="submit" name="qibbs_cleanup_risk_logs" class="button button-secondary"><?php esc_html_e( '按保留天数清理历史日志', 'qibbs-community' ); ?></button>
                </form>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                    <tr>
                        <th style="width:70px;">ID</th>
                        <th style="width:120px;">IP</th>
                        <th style="width:90px;"><?php esc_html_e( '状态', 'qibbs-community' ); ?></th>
                        <th style="width:160px;"><?php esc_html_e( '动作', 'qibbs-community' ); ?></th>
                        <th><?php esc_html_e( '接口', 'qibbs-community' ); ?></th>
                        <th><?php esc_html_e( '原因', 'qibbs-community' ); ?></th>
                        <th style="width:170px;"><?php esc_html_e( '时间', 'qibbs-community' ); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $logs_data['items'] ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( '暂无日志', 'qibbs-community' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $logs_data['items'] as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( $log->id ); ?></td>
                                <td><code><?php echo esc_html( $log->ip ); ?></code></td>
                                <td>
                                    <?php if ( $log->status === 'blocked' ) : ?>
                                        <span style="color:#d63638;font-weight:600;">blocked</span>
                                    <?php else : ?>
                                        <span style="color:#00a32a;font-weight:600;">allow</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html( $log->action ); ?></code></td>
                                <td style="word-break:break-all;"><?php echo esc_html( $log->endpoint ); ?></td>
                                <td><?php echo esc_html( $log->reason ); ?></td>
                                <td><?php echo esc_html( $log->created_at ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php if ( $logs_data['total_pages'] > 1 ) : ?>
                    <div class="tablenav bottom"><div class="tablenav-pages">
                        <?php
                        $base_url = remove_query_arg( array( 'paged', 'risk_notice' ) );
                        echo wp_kses_post(
                            paginate_links(
                                array(
                                    'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                                    'format'    => '',
                                    'current'   => $logs_data['page'],
                                    'total'     => $logs_data['total_pages'],
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                )
                            )
                        );
                        ?>
                    </div></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * 保存安全风控配置参数
     *
     * @return void
     */
    private function save_settings() {
        $options = get_option( 'qibbs_options', array() );

        $options['risk_enable_ip_limit'] = ! empty( $_POST['risk_enable_ip_limit'] );
        $options['risk_enable_auto_ban'] = ! empty( $_POST['risk_enable_auto_ban'] );

        $log_mode = isset( $_POST['risk_log_mode'] ) ? sanitize_key( wp_unslash( $_POST['risk_log_mode'] ) ) : 'blocked';
        $options['risk_log_mode'] = in_array( $log_mode, array( 'blocked', 'all' ), true ) ? $log_mode : 'blocked';
        $ip_mode = isset( $_POST['risk_client_ip_mode'] ) ? sanitize_key( wp_unslash( $_POST['risk_client_ip_mode'] ) ) : 'auto_proxy';
        $options['risk_client_ip_mode'] = in_array( $ip_mode, array( 'auto_proxy', 'strict_proxy', 'cdn_compatible', 'remote_only' ), true ) ? $ip_mode : 'auto_proxy';

        $options['risk_auto_ban_threshold'] = max( 3, min( 200, absint( $_POST['risk_auto_ban_threshold'] ?? 12 ) ) );
        $options['risk_auto_ban_window'] = max( 1, min( 180, absint( $_POST['risk_auto_ban_window'] ?? 10 ) ) );
        $options['risk_auto_ban_duration'] = max( 0, min( 43200, absint( $_POST['risk_auto_ban_duration'] ?? 60 ) ) );
        $options['risk_log_retention_days'] = max( 1, min( 365, absint( $_POST['risk_log_retention_days'] ?? 30 ) ) );
        $proxy_rules = isset( $_POST['risk_trusted_proxies'] ) ? sanitize_textarea_field( wp_unslash( $_POST['risk_trusted_proxies'] ) ) : '';
        $options['risk_trusted_proxies'] = implode( ',', $this->normalize_proxy_rules( $proxy_rules ) );
        $options['risk_enable_content_rules'] = ! empty( $_POST['risk_enable_content_rules'] );
        $options['risk_content_keywords'] = isset( $_POST['risk_content_keywords'] ) ? sanitize_textarea_field( wp_unslash( $_POST['risk_content_keywords'] ) ) : '';
        $options['risk_content_frequency_limit'] = max( 2, min( 50, absint( $_POST['risk_content_frequency_limit'] ?? 4 ) ) );
        if ( function_exists( 'qibbs_update_options' ) ) {
            qibbs_update_options( $options );
        } else {
            update_option( 'qibbs_options', $options );
        }
    }

    /**
     * 标准化反向代理可信 IP 校验规则
     *
     * @param string $raw Raw rules.
     * @return array
     */
    private function normalize_proxy_rules( $raw ) {
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return array();
        }

        $parts = preg_split( '/[\s,]+/', $raw );
        if ( ! is_array( $parts ) ) {
            return array();
        }

        $rules = array();
        foreach ( $parts as $part ) {
            $rule = trim( (string) $part );
            if ( '' === $rule ) {
                continue;
            }

            if ( filter_var( $rule, FILTER_VALIDATE_IP ) ) {
                $rules[] = $rule;
                continue;
            }

            if ( false === strpos( $rule, '/' ) ) {
                continue;
            }

            list( $subnet, $mask ) = array_pad( explode( '/', $rule, 2 ), 2, '' );
            $subnet = trim( (string) $subnet );
            $mask = trim( (string) $mask );
            if ( ! filter_var( $subnet, FILTER_VALIDATE_IP ) || '' === $mask || ! ctype_digit( $mask ) ) {
                continue;
            }

            $mask_bits = (int) $mask;
            $max_bits = strpos( $subnet, ':' ) !== false ? 128 : 32;
            if ( $mask_bits < 0 || $mask_bits > $max_bits ) {
                continue;
            }

            $rules[] = $subnet . '/' . $mask_bits;
        }

        return array_values( array_unique( $rules ) );
    }

    /**
     * 获取风控拦截快速统计数据
     *
     * @return array
     */
    private function get_stats() {
        return Qibbs_Risk_Service::get_dashboard_stats();
    }
}
