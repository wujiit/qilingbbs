<?php
/**
 * 社区 UID、号段规则、C2C 寄售交易与系统回收管理后台视图
 *
 * @package Qibbs_Community
 * @subpackage Admin_Views
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( '您没有足够的权限访问此页面。', 'qibbs-community' ) );
}

$rule_service = Qibbs_UID_Rule_Service::instance();
$pool_service = Qibbs_Vanity_Pool_Service::instance();
$c2c_service  = Qibbs_C2C_Trade_Service::instance();
$notice       = '';
$notice_type  = 'success';
$current_tab  = isset( $_GET['tab'] ) && 'c2c' === $_GET['tab'] ? 'c2c' : 'rules';

// 1. 处理号段规则保存
if ( isset( $_POST['qibbs_uid_save_settings'] ) && check_admin_referer( 'qibbs_uid_settings_action', 'qibbs_uid_nonce' ) ) {
    $options = function_exists( 'qibbs_get_options' ) ? qibbs_get_options() : ( function_exists( 'qibbs_get_options_store' ) ? qibbs_get_options_store() : get_option( 'qibbs_options', array() ) );

    $new_free_start                  = max( 1, absint( $_POST['uid_free_start'] ?? 10000 ) );
    $options['uid_free_start']       = $new_free_start;
    $options['uid_reserved_ranges']  = sanitize_textarea_field( wp_unslash( $_POST['uid_reserved_ranges'] ?? '' ) );
    $options['uid_reserved_singles'] = sanitize_text_field( wp_unslash( $_POST['uid_reserved_singles'] ?? '' ) );
    $options['uid_vanity_ranges']    = sanitize_textarea_field( wp_unslash( $_POST['uid_vanity_ranges'] ?? '' ) );

    if ( isset( $_POST['uid_grade_definitions'] ) && is_array( $_POST['uid_grade_definitions'] ) ) {
        $clean_grades = array();
        foreach ( $_POST['uid_grade_definitions'] as $gid => $gdef ) {
            $gid = absint( $gid );
            if ( $gid > 0 ) {
                $clean_grades[ $gid ] = array(
                    'name'   => sanitize_text_field( wp_unslash( $gdef['name'] ?? '' ) ),
                    'points' => max( 0, absint( $gdef['points'] ?? 0 ) ),
                    'cash'   => max( 0.0, (float) ( $gdef['cash'] ?? 0 ) ),
                );
            }
        }
        $options['uid_grade_definitions'] = $clean_grades;
    }

    unset( $options['uid_exclude_digits'], $options['uid_skip_excluded_digits'], $options['uid_divert_excluded_to_free'] );

    if ( function_exists( 'qibbs_update_options' ) ) {
        qibbs_update_options( $options );
    } else {
        update_option( 'qibbs_options', $options );
        if ( function_exists( 'qibbs_get_options_store' ) ) {
            qibbs_get_options_store( $options, true );
        }
    }

    // 若勾选了同步重置发号游标
    if ( ! empty( $_POST['sync_cursor_with_start'] ) ) {
        update_option( 'qibbs_uid_free_cursor', $new_free_start );
    }

    // 若勾选了同时按新起始值重排已有用户 UID
    if ( ! empty( $_POST['resequence_existing_members'] ) ) {
        $member_service = Qibbs_Member_Service::instance();
        $re_res         = $member_service->resequence_all_members_cuid( $new_free_start );
        $notice         = sprintf( __( 'UID 号段规则已成功保存！同时已自动将全站已有用户按注册顺序重新分配（首位站长已自动更新为 %d）。', 'qibbs-community' ), $new_free_start );
    } else {
        $notice = __( 'UID 号段规则已成功保存！', 'qibbs-community' );
    }
}

// 1.1 处理管理员手动重置发号游标
if ( isset( $_POST['qibbs_uid_set_cursor'] ) && check_admin_referer( 'qibbs_uid_set_cursor_action', 'qibbs_uid_nonce' ) ) {
    $new_cursor = absint( $_POST['manual_cursor_val'] ?? 0 );
    if ( $new_cursor > 0 ) {
        update_option( 'qibbs_uid_free_cursor', $new_cursor );
        $notice = sprintf( __( '已成功将普通发号器游标设定为：%d', 'qibbs-community' ), $new_cursor );
    }
}

// 1.2 处理全站用户初始 UID 按注册顺序一键重排对齐
if ( isset( $_POST['qibbs_uid_resequence_all'] ) && check_admin_referer( 'qibbs_uid_resequence_action', 'qibbs_uid_nonce' ) ) {
    $member_service = Qibbs_Member_Service::instance();
    $res            = $member_service->resequence_all_members_cuid();
    $notice         = $res['message'];
    $notice_type    = $res['success'] ? 'success' : 'error';
}

// 2. 处理单号手动录入
if ( isset( $_POST['qibbs_uid_add_vanity'] ) && check_admin_referer( 'qibbs_uid_add_vanity_action', 'qibbs_uid_nonce' ) ) {
    $target_cuid = absint( $_POST['vanity_cuid'] ?? 0 );
    $grade       = absint( $_POST['vanity_grade'] ?? 0 );
    $price       = absint( $_POST['vanity_price'] ?? 0 );
    $price_cash  = (float) ( $_POST['vanity_price_cash'] ?? 0 );

    $result      = $pool_service->add_vanity_number( $target_cuid, $grade, $price, $price_cash );
    $notice      = $result['message'];
    $notice_type = $result['success'] ? 'success' : 'error';
}

// 3. 处理号码直接指派给用户
if ( isset( $_POST['qibbs_uid_assign_user'] ) && check_admin_referer( 'qibbs_uid_assign_action', 'qibbs_uid_nonce' ) ) {
    $target_cuid = absint( $_POST['assign_cuid'] ?? 0 );
    $target_uid  = absint( $_POST['assign_user_id'] ?? 0 );

    $result      = $pool_service->assign_to_user( $target_cuid, $target_uid );
    $notice      = $result['message'];
    $notice_type = $result['success'] ? 'success' : 'error';
}

// 3.1 处理管理员编辑在售靓号
if ( isset( $_POST['qibbs_uid_edit_vanity'] ) && check_admin_referer( 'qibbs_uid_edit_vanity_action', 'qibbs_uid_nonce' ) ) {
    $edit_cuid       = absint( $_POST['edit_vanity_cuid'] ?? 0 );
    $edit_grade      = absint( $_POST['edit_vanity_grade'] ?? 0 );
    $edit_price      = absint( $_POST['edit_vanity_price'] ?? 0 );
    $edit_price_cash = (float) ( $_POST['edit_vanity_price_cash'] ?? 0 );

    $result      = $pool_service->update_vanity_number( $edit_cuid, $edit_grade, $edit_price, $edit_price_cash );
    $notice      = $result['message'];
    $notice_type = $result['success'] ? 'success' : 'error';
}

// 3.2 处理管理员从号池彻底删除未售靓号
if ( isset( $_GET['delete_vanity_cuid'] ) && check_admin_referer( 'qibbs_delete_cuid_' . absint( $_GET['delete_vanity_cuid'] ) ) ) {
    $del_cuid    = absint( $_GET['delete_vanity_cuid'] );
    $result      = $pool_service->delete_vanity_number( $del_cuid );
    $notice      = $result['message'];
    $notice_type = $result['success'] ? 'success' : 'error';
}

// 4. 处理收回号码操作
if ( isset( $_GET['reclaim_cuid'] ) && check_admin_referer( 'qibbs_reclaim_cuid_' . absint( $_GET['reclaim_cuid'] ) ) ) {
    $reclaim_cuid = absint( $_GET['reclaim_cuid'] );
    $result       = $pool_service->reclaim_to_pool( $reclaim_cuid, 'in_pool' );
    $notice       = $result['message'];
    $notice_type  = $result['success'] ? 'success' : 'error';
}

// 5. 处理 C2C 寄售与官方回收全局配置保存
if ( isset( $_POST['qibbs_c2c_save_settings'] ) && check_admin_referer( 'qibbs_c2c_settings_action', 'qibbs_c2c_nonce' ) ) {
    $options = function_exists( 'qibbs_get_options' ) ? qibbs_get_options() : ( function_exists( 'qibbs_get_options_store' ) ? qibbs_get_options_store() : get_option( 'qibbs_options', array() ) );
    $options['enable_c2c_trading']      = ! empty( $_POST['enable_c2c_trading'] );
    $options['c2c_fee_rate']            = max( 0.0, min( 100.0, (float) ( $_POST['c2c_fee_rate'] ?? 5.0 ) ) );
    $options['enable_uid_recycle']      = ! empty( $_POST['enable_uid_recycle'] );
    $options['uid_recycle_rebate_rate'] = max( 1.0, min( 100.0, (float) ( $_POST['uid_recycle_rebate_rate'] ?? 50.0 ) ) );
    $options['uid_recycle_payout_type'] = sanitize_key( (string) ( $_POST['uid_recycle_payout_type'] ?? 'balance' ) );

    if ( function_exists( 'qibbs_update_options' ) ) {
        qibbs_update_options( $options );
    } else {
        update_option( 'qibbs_options', $options );
    }
    $notice = __( 'C2C 交易行与系统回收配置已成功保存！', 'qibbs-community' );
}

// 6. 管理员强制下架 C2C 违规寄售号码
if ( isset( $_GET['delist_cuid'] ) && check_admin_referer( 'qibbs_delist_cuid_' . absint( $_GET['delist_cuid'] ) ) ) {
    $delist_cuid = absint( $_GET['delist_cuid'] );
    $result      = $c2c_service->delist_number( 0, $delist_cuid, true );
    $notice      = $result['message'];
    $notice_type = $result['success'] ? 'success' : 'error';
}

$settings = $rule_service->get_settings( true );
global $wpdb;
$vanity_table  = Qibbs_Database::vanity_uids();
$members_table = Qibbs_Database::members();
$trades_table  = Qibbs_Database::c2c_trades();

$total_vanity_count     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vanity_table}" );
$total_assigned_members = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$members_table}" );
$total_c2c_listed       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vanity_table} WHERE status = 'c2c_listed'" );

// C2C 交易统计
$total_trade_cash = (float) $wpdb->get_var( "SELECT SUM(amount_cash) FROM {$trades_table} WHERE trade_type = 'c2c' AND status = 'completed'" );
$total_fee_cash   = (float) $wpdb->get_var( "SELECT SUM(platform_fee_cash) FROM {$trades_table} WHERE trade_type = 'c2c' AND status = 'completed'" );
?>

<div class="wrap qibbs-admin-page">
    <h1><?php esc_html_e( '社区 UID 号段规则与交易行管理', 'qibbs-community' ); ?></h1>
    <p class="description">
        <?php esc_html_e( '全自主控盘的社区数字资产体系：支持官方发号规则、吉祥号/商业靓号分流、玩家间自由寄售过户（C2C）与官方回购变现。', 'qibbs-community' ); ?>
    </p>

    <!-- 顶部 Tab 切换导航 -->
    <h2 class="nav-tab-wrapper" style="margin-top: 15px; margin-bottom: 20px;">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-uid-settings&tab=rules' ) ); ?>" class="nav-tab <?php echo 'rules' === $current_tab ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-admin-settings" style="vertical-align: text-bottom; margin-right: 4px;"></span>
            <?php esc_html_e( '官方发号与号段规则', 'qibbs-community' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-uid-settings&tab=c2c' ) ); ?>" class="nav-tab <?php echo 'c2c' === $current_tab ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-cart" style="vertical-align: text-bottom; margin-right: 4px;"></span>
            <?php esc_html_e( 'C2C 交易行与系统回收', 'qibbs-community' ); ?>
            <?php if ( $total_c2c_listed > 0 ) : ?>
                <span class="update-plugins count-<?php echo esc_attr( $total_c2c_listed ); ?>"><span class="plugin-count"><?php echo esc_html( $total_c2c_listed ); ?></span></span>
            <?php endif; ?>
        </a>
    </h2>

    <?php if ( ! empty( $notice ) ) : ?>
        <div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible">
            <p><?php echo esc_html( $notice ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( 'rules' === $current_tab ) : ?>
        <!-- 状态统计卡片 -->
        <div style="display: flex; gap: 20px; margin: 20px 0;">
            <div style="flex: 1; background: #fff; border: 1px solid #ccd0d4; padding: 16px 20px; border-radius: 6px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <div style="font-size: 13px; color: #646970; font-weight: 500;"><?php esc_html_e( '已分配 UID 会员数', 'qibbs-community' ); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #1d2327; margin-top: 4px;"><?php echo esc_html( number_format_i18n( $total_assigned_members ) ); ?></div>
            </div>
            <div style="flex: 1; background: #fff; border: 1px solid #ccd0d4; padding: 16px 20px; border-radius: 6px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <div style="font-size: 13px; color: #646970; font-weight: 500;"><?php esc_html_e( '官方靓号资产总库', 'qibbs-community' ); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #2271b1; margin-top: 4px;"><?php echo esc_html( number_format_i18n( $total_vanity_count ) ); ?></div>
            </div>
            <div style="flex: 1; background: #fff; border: 1px solid #ccd0d4; padding: 16px 20px; border-radius: 6px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <div style="font-size: 13px; color: #646970; font-weight: 500;"><?php esc_html_e( '普通发号器当前游标', 'qibbs-community' ); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #00a32a; margin-top: 4px;">
                    <?php echo esc_html( number_format_i18n( (int) get_option( 'qibbs_uid_free_cursor', $settings['free_start'] ) ) ); ?>
                </div>
                <form method="post" style="margin-top: 8px; display: flex; gap: 6px; align-items: center;">
                    <?php wp_nonce_field( 'qibbs_uid_set_cursor_action', 'qibbs_uid_nonce' ); ?>
                    <input type="number" name="manual_cursor_val" value="<?php echo esc_attr( (int) get_option( 'qibbs_uid_free_cursor', $settings['free_start'] ) ); ?>" min="1" style="width: 100px; height: 28px; font-size: 12px;">
                    <button type="submit" name="qibbs_uid_set_cursor" class="button button-small" onclick="return confirm('<?php echo esc_js( __( '确定手动调整发号器当前游标数值吗？后续注册的普通用户将从该数值之后开始发号。', 'qibbs-community' ) ); ?>');">
                        <?php esc_html_e( '重设游标', 'qibbs-community' ); ?>
                    </button>
                </form>
                <form method="post" style="margin-top: 8px;">
                    <?php wp_nonce_field( 'qibbs_uid_resequence_action', 'qibbs_uid_nonce' ); ?>
                    <button type="submit" name="qibbs_uid_resequence_all" class="button button-secondary button-small" style="color: #b32d2e; width: 100%; text-align: center; display: inline-flex; align-items: center; justify-content: center; gap: 4px;" onclick="return confirm('<?php echo esc_js( __( '确定将全站已有用户按注册先后顺序，重新整顿分配为从起始值开始的连续 UID 吗？（站长将获得首位起始号，如 100000 或 101）', 'qibbs-community' ) ); ?>');">
                        <span class="dashicons dashicons-update" style="font-size:15px;width:15px;height:15px;"></span><?php esc_html_e( '按注册顺序一键重排全员UID', 'qibbs-community' ); ?>
                    </button>
                </form>
            </div>
        </div>

        <div style="display: flex; gap: 24px; align-items: flex-start;">
            <!-- 左侧：号段规则主表单 -->
            <div style="flex: 3; background: #fff; padding: 24px; border: 1px solid #ccd0d4; border-radius: 6px;">
                <h2 style="margin-top: 0; padding-bottom: 12px; border-bottom: 1px solid #f0f0f1;">
                    <?php esc_html_e( '号段与智能分流配置', 'qibbs-community' ); ?>
                </h2>

                <form method="post">
                    <?php wp_nonce_field( 'qibbs_uid_settings_action', 'qibbs_uid_nonce' ); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="uid_free_start"><?php esc_html_e( '免费普通号起始值', 'qibbs-community' ); ?></label></th>
                            <td>
                                <input type="number" id="uid_free_start" name="uid_free_start" value="<?php echo esc_attr( $settings['free_start'] ); ?>" min="1" class="regular-text">
                                <label style="display: block; margin-top: 6px;">
                                    <input type="checkbox" name="sync_cursor_with_start" value="1" checked>
                                    <span style="color: #2271b1; font-weight: 500;"><?php esc_html_e( '保存时同步重置发号游标为此数值', 'qibbs-community' ); ?></span>
                                </label>
                                <label style="display: block; margin-top: 6px;">
                                    <input type="checkbox" name="resequence_existing_members" value="1" checked>
                                    <strong style="color: #d63638;"><?php esc_html_e( '保存时立即将全站已有用户按注册顺序重新对齐（首位站长将直接自动变为该起始号，如 101 或 10000，无需手动逐个改！）', 'qibbs-community' ); ?></strong>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( '新用户注册时分配普通保底号的起始基数（插件默认 10000，支持改为 101、10000 等任意数字）。勾选上方复选框后点击保存，旧号码（如 100004）将立即全自动重排对齐归位！', 'qibbs-community' ); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="uid_reserved_ranges"><?php esc_html_e( '官方保留号段区间', 'qibbs-community' ); ?></label></th>
                            <td>
                                <textarea id="uid_reserved_ranges" name="uid_reserved_ranges" rows="3" class="large-text code"><?php echo esc_textarea( $settings['reserved_ranges'] ); ?></textarea>
                                <p class="description"><?php esc_html_e( '每行一条区间（例如：1-100）。系统发号器永远不会自动发放这些保留号码，仅供官方后台手动指派。', 'qibbs-community' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="uid_reserved_singles"><?php esc_html_e( '官方保留指定单号', 'qibbs-community' ); ?></label></th>
                            <td>
                                <input type="text" id="uid_reserved_singles" name="uid_reserved_singles" value="<?php echo esc_attr( $settings['reserved_singles'] ); ?>" class="large-text">
                                <p class="description"><?php esc_html_e( '指定不公开分配的特殊号码，用逗号分隔（例如：10086, 666, 888, 999, 520, 1314）。发号器将自动避让跳过。', 'qibbs-community' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="uid_vanity_ranges"><?php esc_html_e( '商业销售号段与基准定价', 'qibbs-community' ); ?></label></th>
                            <td>
                                <textarea id="uid_vanity_ranges" name="uid_vanity_ranges" rows="3" class="large-text code" placeholder="<?php esc_attr_e( '默认留空。格式如：88800-88899:1000（表示该指定区间作为付费靓号出售）。普通号码无需填写。', 'qibbs-community' ); ?>"><?php echo esc_textarea( $settings['vanity_ranges'] ); ?></textarea>
                                <p class="description"><?php esc_html_e( '指定特定的付费商业靓号号段（格式：起始-结束:积分标价）。普通递增号码无需填入。默认保持留空即可。', 'qibbs-community' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label><?php esc_html_e( '靓号品相等级与基准定价自定义', 'qibbs-community' ); ?></label></th>
                            <td>
                                <p class="description" style="margin-bottom: 10px;">
                                    <?php esc_html_e( '自定义各品相等级的显示名称与默认基准定价（全站发号、靓号商城、用户背包将实时联动应用自定义名称与价格，拒绝写死）：', 'qibbs-community' ); ?>
                                </p>
                                <table class="widefat striped" style="max-width: 650px; margin-bottom: 8px;">
                                    <thead>
                                        <tr>
                                            <th style="width: 60px;"><?php esc_html_e( '级别', 'qibbs-community' ); ?></th>
                                            <th><?php esc_html_e( '等级自定义名称', 'qibbs-community' ); ?></th>
                                            <th style="width: 130px;"><?php esc_html_e( '基准积分', 'qibbs-community' ); ?></th>
                                            <th style="width: 140px;"><?php esc_html_e( '基准现金 (元)', 'qibbs-community' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $current_grade_defs = $rule_service->get_grade_definitions();
                                        foreach ( $current_grade_defs as $gid => $ginfo ) : ?>
                                            <tr>
                                                <td><strong><?php echo esc_html( $gid ); ?> 级</strong></td>
                                                <td>
                                                    <input type="text" name="uid_grade_definitions[<?php echo esc_attr( $gid ); ?>][name]" value="<?php echo esc_attr( $ginfo['name'] ); ?>" class="regular-text" style="width: 100%;">
                                                </td>
                                                <td>
                                                    <input type="number" name="uid_grade_definitions[<?php echo esc_attr( $gid ); ?>][points]" value="<?php echo esc_attr( $ginfo['points'] ); ?>" min="0" step="1" style="width: 100%;">
                                                </td>
                                                <td>
                                                    <input type="number" name="uid_grade_definitions[<?php echo esc_attr( $gid ); ?>][cash]" value="<?php echo esc_attr( number_format( (float) $ginfo['cash'], 2, '.', '' ) ); ?>" min="0" step="0.01" style="width: 100%;">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="qibbs_uid_save_settings" class="button button-primary" value="<?php esc_attr_e( '保存号段规则与品相配置', 'qibbs-community' ); ?>">
                    </p>
                </form>
            </div>

            <!-- 右侧：快捷操作侧栏 -->
            <div style="flex: 2; display: flex; flex-direction: column; gap: 20px;">
                <!-- 录入单个靓号 -->
                <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 6px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e( '录入号码进官方池', 'qibbs-community' ); ?></h3>
                    <form method="post">
                        <?php wp_nonce_field( 'qibbs_uid_add_vanity_action', 'qibbs_uid_nonce' ); ?>
                        <p>
                            <label><strong><?php esc_html_e( '靓号号码', 'qibbs-community' ); ?></strong></label><br>
                            <input type="number" name="vanity_cuid" required class="widefat" placeholder="如 88888 或 99">
                        </p>
                        <p>
                            <label><strong><?php esc_html_e( '品相等级', 'qibbs-community' ); ?></strong></label><br>
                            <select name="vanity_grade" class="widefat">
                                <option value="0"><?php esc_html_e( '自动检测（推荐）', 'qibbs-community' ); ?></option>
                                <?php foreach ( $current_grade_defs as $gid => $ginfo ) : ?>
                                    <option value="<?php echo esc_attr( $gid ); ?>">
                                        <?php echo esc_html( sprintf( '%d级 - %s (基准 ¥%s / %d积分)', $gid, $ginfo['name'], number_format( (float) $ginfo['cash'], 2 ), $ginfo['points'] ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <label><strong><?php esc_html_e( '标价积分 (0为自适应)', 'qibbs-community' ); ?></strong></label><br>
                            <input type="number" name="vanity_price" value="0" min="0" class="widefat">
                        </p>
                        <p>
                            <label><strong><?php esc_html_e( '现金标价/元 (0为等额)', 'qibbs-community' ); ?></strong></label><br>
                            <input type="number" step="0.01" name="vanity_price_cash" value="0.00" min="0" class="widefat">
                        </p>
                        <p>
                            <input type="submit" name="qibbs_uid_add_vanity" class="button" value="<?php esc_attr_e( '录入资产池', 'qibbs-community' ); ?>">
                        </p>
                    </form>
                </div>

                <!-- 直接指派号码给用户 -->
                <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 6px;">
                    <h3 style="margin-top: 0; color: #1d2327; display: flex; align-items: center; gap: 6px;"><span class="dashicons dashicons-admin-users" style="font-size:20px;width:20px;height:20px;color:#2271b1;"></span><?php esc_html_e( '直接指派号码给用户', 'qibbs-community' ); ?></h3>
                    <p class="description" style="margin-bottom: 12px;">
                        <?php esc_html_e( '管理员拥有最高发号权：输入任意号码（包括保留段如 1、666 等）与用户 ID，立即将该用户的全站社区 UID 变更为此号码！', 'qibbs-community' ); ?>
                    </p>
                    <form method="post">
                        <?php wp_nonce_field( 'qibbs_uid_assign_action', 'qibbs_uid_nonce' ); ?>
                        <p>
                            <label><strong><?php esc_html_e( '分配号码', 'qibbs-community' ); ?></strong></label><br>
                            <input type="number" name="assign_cuid" required class="widefat" placeholder="如 1 或 666">
                        </p>
                        <p>
                            <label><strong><?php esc_html_e( '目标用户 ID (WordPress ID)', 'qibbs-community' ); ?></strong></label><br>
                            <input type="number" name="assign_user_id" required class="widefat" placeholder="如 1">
                        </p>
                        <p>
                            <input type="submit" name="qibbs_uid_assign_user" class="button button-primary" style="width: 100%; text-align: center;" value="<?php esc_attr_e( '立即指派并设为该用户 UID', 'qibbs-community' ); ?>" onclick="return confirm('<?php echo esc_js( __( '确定将该号码直接指定为该用户的社区 UID 吗？操作后该用户的 UID 将立即变更为此号码并在全站生效！', 'qibbs-community' ) ); ?>');">
                        </p>
                    </form>
                </div>
            </div>
        </div>

        <!-- 官方池号码列表预览 -->
        <?php
        $vanity_items = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$vanity_table} WHERE status != 'c2c_listed' ORDER BY grade DESC, cuid ASC LIMIT %d", 50 )
        );
        ?>
        <div style="background: #fff; padding: 24px; border: 1px solid #ccd0d4; border-radius: 6px; margin-top: 24px;">
            <h2 style="margin-top: 0;"><?php esc_html_e( '官方靓号资产库预览 (最新50条)', 'qibbs-community' ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 140px;"><?php esc_html_e( '号码', 'qibbs-community' ); ?></th>
                        <th style="width: 120px;"><?php esc_html_e( '品相等级', 'qibbs-community' ); ?></th>
                        <th style="width: 120px;"><?php esc_html_e( '当前状态', 'qibbs-community' ); ?></th>
                        <th style="width: 140px;"><?php esc_html_e( '当前持有人', 'qibbs-community' ); ?></th>
                        <th style="width: 90px;"><?php esc_html_e( '标价积分', 'qibbs-community' ); ?></th>
                        <th style="width: 100px;"><?php esc_html_e( '现金标价', 'qibbs-community' ); ?></th>
                        <th style="width: 90px;"><?php esc_html_e( '流转次数', 'qibbs-community' ); ?></th>
                        <th style="width: 120px;"><?php esc_html_e( '管理操作', 'qibbs-community' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $vanity_items ) ) : ?>
                        <tr><td colspan="8"><?php esc_html_e( '官方池暂无号码', 'qibbs-community' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $vanity_items as $item ) :
                            $owner_user = $item->owner_id > 0 ? get_userdata( $item->owner_id ) : null;
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html( $item->cuid ); ?></strong></td>
                                <td><?php echo esc_html( $rule_service->get_grade_label( $item->grade ) ); ?> (<?php echo esc_html( $item->grade ); ?>级)</td>
                                <td><?php echo 'in_pool' === $item->status ? '<span style="color:#2271b1;font-weight:600;">待售中</span>' : '<span style="color:#00a32a;font-weight:600;">已被持有</span>'; ?></td>
                                <td>
                                    <?php if ( $owner_user ) : ?>
                                        <a href="<?php echo esc_url( get_edit_user_link( $owner_user->ID ) ); ?>" target="_blank">
                                            <?php echo esc_html( $owner_user->display_name ); ?> (ID: <?php echo esc_html( $owner_user->ID ); ?>)
                                        </a>
                                    <?php else : ?>
                                        <span style="color:#999;"><?php esc_html_e( '官方自营', 'qibbs-community' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $item->sale_price ); ?></td>
                                <td><strong style="color:#d63638;">¥<?php echo number_format( (float) ( ( $item->price_cash ?? 0 ) > 0 ? $item->price_cash : $item->sale_price ), 2 ); ?></strong></td>
                                <td><?php echo esc_html( $item->transfer_count ); ?></td>
                                <td>
                                    <?php if ( $item->owner_id > 0 ) : ?>
                                        <?php $reclaim_url = wp_nonce_url( admin_url( 'admin.php?page=qibbs-uid-settings&tab=rules&reclaim_cuid=' . $item->cuid ), 'qibbs_reclaim_cuid_' . $item->cuid ); ?>
                                        <a href="<?php echo esc_url( $reclaim_url ); ?>" class="button button-small" onclick="return confirm('确定强制收回该号码回归官方池吗？');">
                                            <?php esc_html_e( '收回官方池', 'qibbs-community' ); ?>
                                        </a>
                                    <?php else : ?>
                                        <div style="display:flex;gap:4px;align-items:center;">
                                            <button type="button" class="button button-small qibbs-open-edit-vanity"
                                                data-cuid="<?php echo esc_attr( $item->cuid ); ?>"
                                                data-grade="<?php echo esc_attr( $item->grade ); ?>"
                                                data-price="<?php echo esc_attr( $item->sale_price ); ?>"
                                                data-cash="<?php echo esc_attr( (float) ( ( $item->price_cash ?? 0 ) > 0 ? $item->price_cash : $item->sale_price ) ); ?>">
                                                <?php esc_html_e( '编辑', 'qibbs-community' ); ?>
                                            </button>
                                            <button type="button" class="button button-small qibbs-open-assign-vanity"
                                                data-cuid="<?php echo esc_attr( $item->cuid ); ?>">
                                                <?php esc_html_e( '指派', 'qibbs-community' ); ?>
                                            </button>
                                            <?php $del_url = wp_nonce_url( admin_url( 'admin.php?page=qibbs-uid-settings&tab=rules&delete_vanity_cuid=' . $item->cuid ), 'qibbs_delete_cuid_' . $item->cuid ); ?>
                                            <a href="<?php echo esc_url( $del_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('确定从官方资产库彻底删除号码 #<?php echo esc_js( $item->cuid ); ?> 吗？');" style="color:#b32d2e;">
                                                <?php esc_html_e( '删除', 'qibbs-community' ); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php else : ?>
        <!-- C2C 寄售交易行与系统回收专区 -->
        <div style="display: flex; gap: 20px; margin: 20px 0;">
            <div style="flex: 1; background: #fff; border: 1px solid #ccd0d4; padding: 16px 20px; border-radius: 6px;">
                <div style="font-size: 13px; color: #646970; font-weight: 500;"><?php esc_html_e( '当前玩家挂牌在售号码', 'qibbs-community' ); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #2271b1; margin-top: 4px;"><?php echo esc_html( number_format_i18n( $total_c2c_listed ) ); ?></div>
            </div>
            <div style="flex: 1; background: #fff; border: 1px solid #ccd0d4; padding: 16px 20px; border-radius: 6px;">
                <div style="font-size: 13px; color: #646970; font-weight: 500;"><?php esc_html_e( 'C2C 累计成交现金流水', 'qibbs-community' ); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #d63638; margin-top: 4px;">¥<?php echo number_format( $total_trade_cash, 2 ); ?></div>
            </div>
            <div style="flex: 1; background: #fff; border: 1px solid #ccd0d4; padding: 16px 20px; border-radius: 6px;">
                <div style="font-size: 13px; color: #646970; font-weight: 500;"><?php esc_html_e( '平台累计提取服务费 (纯利润)', 'qibbs-community' ); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #00a32a; margin-top: 4px;">¥<?php echo number_format( $total_fee_cash, 2 ); ?></div>
            </div>
        </div>

        <div style="background: #fff; padding: 24px; border: 1px solid #ccd0d4; border-radius: 6px; margin-bottom: 24px;">
            <h2 style="margin-top: 0; padding-bottom: 12px; border-bottom: 1px solid #f0f0f1;">
                <?php esc_html_e( 'C2C 自由交易与官方回收核心参数', 'qibbs-community' ); ?>
            </h2>

            <form method="post">
                <?php wp_nonce_field( 'qibbs_c2c_settings_action', 'qibbs_c2c_nonce' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( '玩家间自由交易行', 'qibbs-community' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_c2c_trading" value="1" <?php checked( qibbs_get_option( 'enable_c2c_trading', true ) ); ?>>
                                <strong><?php esc_html_e( '开启 C2C 寄售大厅（允许玩家在背包中上架号码出售给其他玩家）', 'qibbs-community' ); ?></strong>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="c2c_fee_rate"><?php esc_html_e( '平台交易服务费抽成', 'qibbs-community' ); ?></label></th>
                        <td>
                            <input type="number" step="0.1" min="0" max="100" id="c2c_fee_rate" name="c2c_fee_rate" value="<?php echo esc_attr( qibbs_get_option( 'c2c_fee_rate', 5.0 ) ); ?>" class="small-text"> %
                            <p class="description"><?php esc_html_e( '每笔 C2C 交易成交后，社区扣取的交易服务费比例（默认 5.0%）。扣除后的净收益自动结算到卖家的启灵商城【可提现余额】中（后续提现时另行遵循商城提现规则）。', 'qibbs-community' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( '官方折价回收变现', 'qibbs-community' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_uid_recycle" value="1" <?php checked( qibbs_get_option( 'enable_uid_recycle', true ) ); ?>>
                                <strong><?php esc_html_e( '允许玩家将名下闲置靓号回售给系统池（官方回收变现）', 'qibbs-community' ); ?></strong>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="uid_recycle_rebate_rate"><?php esc_html_e( '官方回收折价率', 'qibbs-community' ); ?></label></th>
                        <td>
                            <input type="number" step="1" min="1" max="100" id="uid_recycle_rebate_rate" name="uid_recycle_rebate_rate" value="<?php echo esc_attr( qibbs_get_option( 'uid_recycle_rebate_rate', 50.0 ) ); ?>" class="small-text"> %
                            <p class="description"><?php esc_html_e( '回售给系统时，按原售价或基准定价折算的退款比例（默认 50%，即半价回购）。', 'qibbs-community' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="uid_recycle_payout_type"><?php esc_html_e( '官方回收结算币种', 'qibbs-community' ); ?></label></th>
                        <td>
                            <select id="uid_recycle_payout_type" name="uid_recycle_payout_type">
                                <option value="balance" <?php selected( qibbs_get_option( 'uid_recycle_payout_type', 'balance' ), 'balance' ); ?>><?php esc_html_e( '启灵商城【可提现余额】（人民币现金，支持在商城个人中心申请提现）', 'qibbs-community' ); ?></option>
                                <option value="points" <?php selected( qibbs_get_option( 'uid_recycle_payout_type', 'balance' ), 'points' ); ?>><?php printf( esc_html__( '启灵商城【%s】（商城通用积分）', 'qibbs-community' ), esc_html( function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' ) ) ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( '社区不设立独立资金池与独立钱包，全部资金与积分直接接入启灵商城统一结算。选择现金时，系统回购资金将实时入账至玩家的启灵商城【可提现余额】中。', 'qibbs-community' ); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="qibbs_c2c_save_settings" class="button button-primary" value="<?php esc_attr_e( '保存 C2C 与回收配置', 'qibbs-community' ); ?>">
                </p>
            </form>
        </div>

        <!-- 正在挂牌的 C2C 号码管理列表 -->
        <?php
        $c2c_listings = $wpdb->get_results(
            "SELECT cuid, owner_id, c2c_seller_id, grade, c2c_price_cash, c2c_price_points, c2c_listed_at 
             FROM {$vanity_table} 
             WHERE status = 'c2c_listed' 
             ORDER BY c2c_listed_at DESC LIMIT 50"
        );
        ?>
        <div style="background: #fff; padding: 24px; border: 1px solid #ccd0d4; border-radius: 6px; margin-bottom: 24px;">
            <h2 style="margin-top: 0;"><?php esc_html_e( '玩家 C2C 寄售大厅监控 (正在挂牌)', 'qibbs-community' ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 140px;"><?php esc_html_e( '挂牌号码', 'qibbs-community' ); ?></th>
                        <th style="width: 100px;"><?php esc_html_e( '品相等级', 'qibbs-community' ); ?></th>
                        <th style="width: 160px;"><?php esc_html_e( '寄售卖家', 'qibbs-community' ); ?></th>
                        <th style="width: 120px;"><?php esc_html_e( '现金售价', 'qibbs-community' ); ?></th>
                        <th style="width: 120px;"><?php esc_html_e( '积分售价', 'qibbs-community' ); ?></th>
                        <th style="width: 160px;"><?php esc_html_e( '上架时间', 'qibbs-community' ); ?></th>
                        <th style="width: 120px;"><?php esc_html_e( '管理操作', 'qibbs-community' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $c2c_listings ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( '当前暂无玩家挂牌寄售的靓号。', 'qibbs-community' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $c2c_listings as $item ) :
                            $seller = get_userdata( $item->c2c_seller_id ?: $item->owner_id );
                        ?>
                            <tr>
                                <td><strong style="color: #2271b1; font-size: 15px;"><?php echo esc_html( $item->cuid ); ?></strong></td>
                                <td><?php echo esc_html( $item->grade ); ?> 级</td>
                                <td>
                                    <?php if ( $seller ) : ?>
                                        <a href="<?php echo esc_url( get_edit_user_link( $seller->ID ) ); ?>" target="_blank">
                                            <?php echo esc_html( $seller->display_name ); ?> (ID: <?php echo esc_html( $seller->ID ); ?>)
                                        </a>
                                    <?php else : ?>
                                        <span style="color:#999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong style="color:#d63638;">¥<?php echo number_format( (float) $item->c2c_price_cash, 2 ); ?></strong></td>
                                <td><?php echo esc_html( $item->c2c_price_points ); ?> <?php esc_html_e( '积分', 'qibbs-community' ); ?></td>
                                <td><?php echo esc_html( $item->c2c_listed_at ); ?></td>
                                <td>
                                    <?php $delist_url = wp_nonce_url( admin_url( 'admin.php?page=qibbs-uid-settings&tab=c2c&delist_cuid=' . $item->cuid ), 'qibbs_delist_cuid_' . $item->cuid ); ?>
                                    <a href="<?php echo esc_url( $delist_url ); ?>" class="button button-small" onclick="return confirm('确定强制下架该号码吗？下架后号码将退回卖家背包。');">
                                        <?php esc_html_e( '违规强制下架', 'qibbs-community' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 最新 20 笔 C2C 交易与回收流水明细表 -->
        <?php
        $recent_trades = $wpdb->get_results(
            "SELECT * FROM {$trades_table} ORDER BY created_at DESC LIMIT 20"
        );
        ?>
        <div style="background: #fff; padding: 24px; border: 1px solid #ccd0d4; border-radius: 6px;">
            <h2 style="margin-top: 0;"><?php esc_html_e( '最近 20 笔 C2C 成交与官方回收流水', 'qibbs-community' ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 140px;"><?php esc_html_e( '流水号', 'qibbs-community' ); ?></th>
                        <th style="width: 90px;"><?php esc_html_e( '业务类型', 'qibbs-community' ); ?></th>
                        <th style="width: 100px;"><?php esc_html_e( '涉及号码', 'qibbs-community' ); ?></th>
                        <th style="width: 130px;"><?php esc_html_e( '买家/受让方', 'qibbs-community' ); ?></th>
                        <th style="width: 130px;"><?php esc_html_e( '卖家/转让方', 'qibbs-community' ); ?></th>
                        <th style="width: 100px;"><?php esc_html_e( '成交总额', 'qibbs-community' ); ?></th>
                        <th style="width: 90px;"><?php esc_html_e( '平台抽成', 'qibbs-community' ); ?></th>
                        <th style="width: 100px;"><?php esc_html_e( '卖家净收益', 'qibbs-community' ); ?></th>
                        <th style="width: 140px;"><?php esc_html_e( '完成时间', 'qibbs-community' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $recent_trades ) ) : ?>
                        <tr><td colspan="9"><?php esc_html_e( '暂无交易成交记录。', 'qibbs-community' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $recent_trades as $t ) :
                            $b_user = $t->buyer_id > 0 ? get_userdata( $t->buyer_id ) : null;
                            $s_user = $t->seller_id > 0 ? get_userdata( $t->seller_id ) : null;
                            $is_recycle = 'recycle' === $t->trade_type;
                        ?>
                            <tr>
                                <td><code><?php echo esc_html( $t->trade_sn ); ?></code></td>
                                <td>
                                    <?php if ( $is_recycle ) : ?>
                                        <span class="badge" style="background:#f0f6fc;color:#0969da;padding:2px 6px;border-radius:4px;font-size:12px;"><?php esc_html_e( '官方回收', 'qibbs-community' ); ?></span>
                                    <?php else : ?>
                                        <span class="badge" style="background:#dafbe1;color:#1a7f37;padding:2px 6px;border-radius:4px;font-size:12px;"><?php esc_html_e( 'C2C 交易', 'qibbs-community' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><strong style="color:#2271b1;"><?php echo esc_html( $t->cuid ); ?></strong></td>
                                <td><?php echo $b_user ? esc_html( $b_user->display_name ) : ( $is_recycle ? '<em>' . esc_html__( '系统官方池', 'qibbs-community' ) . '</em>' : '-' ); ?></td>
                                <td><?php echo $s_user ? esc_html( $s_user->display_name ) : '-'; ?></td>
                                <td>
                                    <?php if ( 'cash' === $t->payment_type || (float) $t->amount_cash > 0 ) : ?>
                                        ¥<?php echo number_format( (float) $t->amount_cash, 2 ); ?>
                                    <?php else : ?>
                                        <?php echo esc_html( $t->amount_points ); ?> <?php esc_html_e( '积分', 'qibbs-community' ); ?>
                                    <?php endif; ?>
                                </td>
                                <td style="color:#646970;">
                                    <?php if ( 'cash' === $t->payment_type || (float) $t->platform_fee_cash > 0 ) : ?>
                                        ¥<?php echo number_format( (float) $t->platform_fee_cash, 2 ); ?>
                                    <?php else : ?>
                                        <?php echo esc_html( $t->platform_fee_points ); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong style="color:#00a32a;">
                                        <?php if ( 'cash' === $t->payment_type || (float) $t->seller_earnings_cash > 0 ) : ?>
                                            ¥<?php echo number_format( (float) $t->seller_earnings_cash, 2 ); ?>
                                        <?php else : ?>
                                            <?php echo esc_html( $t->seller_earnings_points ); ?>
                                        <?php endif; ?>
                                    </strong>
                                </td>
                                <td><?php echo esc_html( $t->created_at ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ==================== 管理员编辑在售靓号弹窗 ==================== -->
<div id="qibbs-admin-edit-vanity-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.6);z-index:99999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;width:100%;max-width:440px;box-shadow:0 10px 30px rgba(0,0,0,0.3);overflow:hidden;padding:0;">
        <div style="padding:16px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;background:#f6f7f7;">
            <h3 style="margin:0;font-size:16px;font-weight:600;"><?php esc_html_e( '编辑官方在售靓号', 'qibbs-community' ); ?></h3>
            <button type="button" class="qibbs-close-admin-modal" style="background:none;border:none;font-size:20px;cursor:pointer;color:#666;">&times;</button>
        </div>
        <form method="post" action="" style="padding:20px;">
            <?php wp_nonce_field( 'qibbs_uid_edit_vanity_action', 'qibbs_uid_nonce' ); ?>
            <input type="hidden" name="edit_vanity_cuid" id="qibbs-edit-vanity-cuid-input" value="">

            <div style="text-align:center;margin-bottom:18px;background:#f0f6fc;padding:12px;border-radius:6px;">
                <div style="font-size:12px;color:#646970;"><?php esc_html_e( '正在编辑号码', 'qibbs-community' ); ?></div>
                <div id="qibbs-edit-vanity-cuid-display" style="font-size:26px;font-weight:700;color:#2271b1;font-family:monospace;margin-top:4px;">#00000</div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:600;margin-bottom:5px;font-size:13px;"><?php esc_html_e( '品相等级', 'qibbs-community' ); ?></label>
                <select name="edit_vanity_grade" id="qibbs-edit-vanity-grade-select" style="width:100%;max-width:100%;height:36px;">
                    <?php if ( ! empty( $settings['grade_definitions'] ) ) : ?>
                        <?php foreach ( $settings['grade_definitions'] as $gid => $gdef ) : ?>
                            <option value="<?php echo esc_attr( $gid ); ?>">
                                <?php echo esc_html( $gdef['name'] ); ?> (<?php echo esc_html( $gid ); ?> 级)
                            </option>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <option value="1"><?php esc_html_e( '普通优选 (1级)', 'qibbs-community' ); ?></option>
                        <option value="2"><?php esc_html_e( '精品靓号 (2级)', 'qibbs-community' ); ?></option>
                        <option value="3"><?php esc_html_e( '稀世极品 (3级)', 'qibbs-community' ); ?></option>
                        <option value="4"><?php esc_html_e( '天尊御用 (4级)', 'qibbs-community' ); ?></option>
                    <?php endif; ?>
                </select>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block;font-weight:600;margin-bottom:5px;font-size:13px;"><?php esc_html_e( '积分标价', 'qibbs-community' ); ?></label>
                <input type="number" name="edit_vanity_price" id="qibbs-edit-vanity-price-input" min="0" step="10" class="regular-text" style="width:100%;height:36px;" required>
            </div>

            <div style="margin-bottom:18px;">
                <label style="display:block;font-weight:600;margin-bottom:5px;font-size:13px;"><?php esc_html_e( '现金直购标价（元）', 'qibbs-community' ); ?></label>
                <input type="number" name="edit_vanity_price_cash" id="qibbs-edit-vanity-cash-input" min="0" step="0.01" class="regular-text" style="width:100%;height:36px;" required>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:14px;border-top:1px solid #eee;">
                <button type="button" class="button qibbs-close-admin-modal"><?php esc_html_e( '取消', 'qibbs-community' ); ?></button>
                <button type="submit" name="qibbs_uid_edit_vanity" class="button button-primary"><?php esc_html_e( '保存修改', 'qibbs-community' ); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== 管理员快捷指派弹窗 ==================== -->
<div id="qibbs-admin-assign-vanity-modal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.6);z-index:99999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:8px;width:100%;max-width:420px;box-shadow:0 10px 30px rgba(0,0,0,0.3);overflow:hidden;padding:0;">
        <div style="padding:16px 20px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;background:#f6f7f7;">
            <h3 style="margin:0;font-size:16px;font-weight:600;"><?php esc_html_e( '直接指派号码给用户', 'qibbs-community' ); ?></h3>
            <button type="button" class="qibbs-close-admin-modal" style="background:none;border:none;font-size:20px;cursor:pointer;color:#666;">&times;</button>
        </div>
        <form method="post" action="" style="padding:20px;">
            <?php wp_nonce_field( 'qibbs_uid_assign_action', 'qibbs_uid_nonce' ); ?>
            <input type="hidden" name="assign_cuid" id="qibbs-assign-vanity-cuid-input" value="">

            <div style="text-align:center;margin-bottom:18px;background:#f0f6fc;padding:12px;border-radius:6px;">
                <div style="font-size:12px;color:#646970;"><?php esc_html_e( '指派靓号', 'qibbs-community' ); ?></div>
                <div id="qibbs-assign-vanity-cuid-display" style="font-size:26px;font-weight:700;color:#2271b1;font-family:monospace;margin-top:4px;">#00000</div>
            </div>

            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:600;margin-bottom:5px;font-size:13px;"><?php esc_html_e( '目标用户 ID (User ID)', 'qibbs-community' ); ?></label>
                <input type="number" name="assign_user_id" id="qibbs-assign-user-id-input" min="1" class="regular-text" style="width:100%;height:36px;" placeholder="<?php esc_attr_e( '输入用户 ID（如 1）', 'qibbs-community' ); ?>" required>
                <p class="description" style="font-size:12px;color:#646970;margin-top:6px;">
                    <?php esc_html_e( '提示：指派后系统会自动将该用户的社区保底 UID 及当前佩戴 UID 均变更为此号码，并完成资产所有权登记。', 'qibbs-community' ); ?>
                </p>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;padding-top:14px;border-top:1px solid #eee;">
                <button type="button" class="button qibbs-close-admin-modal"><?php esc_html_e( '取消', 'qibbs-community' ); ?></button>
                <button type="submit" name="qibbs_uid_assign_user" class="button button-primary"><?php esc_html_e( '确认指派', 'qibbs-community' ); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // 打开编辑弹窗
    $('.qibbs-open-edit-vanity').on('click', function(e) {
        e.preventDefault();
        var cuid = $(this).data('cuid');
        var grade = $(this).data('grade');
        var price = $(this).data('price');
        var cash = $(this).data('cash');

        $('#qibbs-edit-vanity-cuid-input').val(cuid);
        $('#qibbs-edit-vanity-cuid-display').text('#' + cuid);
        $('#qibbs-edit-vanity-grade-select').val(grade);
        $('#qibbs-edit-vanity-price-input').val(price);
        $('#qibbs-edit-vanity-cash-input').val(cash);

        $('#qibbs-admin-edit-vanity-modal').css('display', 'flex');
    });

    // 打开指派弹窗
    $('.qibbs-open-assign-vanity').on('click', function(e) {
        e.preventDefault();
        var cuid = $(this).data('cuid');
        $('#qibbs-assign-vanity-cuid-input').val(cuid);
        $('#qibbs-assign-vanity-cuid-display').text('#' + cuid);
        $('#qibbs-assign-user-id-input').val('');
        $('#qibbs-admin-assign-vanity-modal').css('display', 'flex');
    });

    // 关闭弹窗
    $('.qibbs-close-admin-modal').on('click', function() {
        $('#qibbs-admin-edit-vanity-modal, #qibbs-admin-assign-vanity-modal').hide();
    });

    // 点击背景遮罩关闭
    $('#qibbs-admin-edit-vanity-modal, #qibbs-admin-assign-vanity-modal').on('click', function(e) {
        if ($(e.target).is('#qibbs-admin-edit-vanity-modal, #qibbs-admin-assign-vanity-modal')) {
            $(this).hide();
        }
    });
});
</script>
