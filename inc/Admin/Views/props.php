<?php
/**
 * 视图模板：道具大厅与置顶道具配置
 *
 * 社区道具大厅管理后台视图（优化版：全宽表格 + 原生居中模态弹窗编辑）
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

$prop_service = Qibbs_Prop_Service::instance();
$points_name  = qibbs_get_points_name();
$has_points   = function_exists( 'qibbs_is_points_integration_available' ) && qibbs_is_points_integration_available();

$notice_message = '';
$notice_type    = '';

// 处理表单提交（保存/编辑道具）
if ( isset( $_POST['qibbs_save_prop'] ) && check_admin_referer( 'qibbs_prop_action' ) ) {
    $prop_id   = isset( $_POST['prop_id'] ) ? absint( $_POST['prop_id'] ) : 0;
    $prop_type = isset( $_POST['prop_type'] ) ? sanitize_key( $_POST['prop_type'] ) : 'name_color';
    $name      = isset( $_POST['prop_name'] ) ? sanitize_text_field( wp_unslash( $_POST['prop_name'] ) ) : '';
    $price     = isset( $_POST['price_points'] ) ? max( 0.01, round( (float) $_POST['price_points'], 2 ) ) : 100.0;
    $days      = isset( $_POST['duration_days'] ) ? absint( $_POST['duration_days'] ) : 30;
    $stock     = isset( $_POST['stock'] ) ? intval( $_POST['stock'] ) : -1;
    $status    = isset( $_POST['status'] ) && in_array( $_POST['status'], array( 'active', 'inactive' ), true ) ? $_POST['status'] : 'active';
    $sort      = isset( $_POST['sort_order'] ) ? intval( $_POST['sort_order'] ) : 0;
    $desc      = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

    $meta_data = array();
    if ( 'name_color' === $prop_type ) {
        $meta_data['color'] = isset( $_POST['color_val'] ) ? sanitize_hex_color( $_POST['color_val'] ) : '#ef4444';
        $meta_data['style'] = isset( $_POST['color_style'] ) ? sanitize_text_field( $_POST['color_style'] ) : 'solid';
    }

    $save_res = $prop_service->save_prop(
        array(
            'name'          => $name,
            'prop_type'     => $prop_type,
            'description'   => $desc,
            'price_points'  => $price,
            'duration_days' => $days,
            'meta_data'     => $meta_data,
            'stock'         => $stock,
            'status'        => $status,
            'sort_order'    => $sort,
        ),
        $prop_id
    );

    if ( ! empty( $save_res['success'] ) ) {
        $notice_message = $save_res['message'];
        $notice_type    = 'success';
    } else {
        $notice_message = ! empty( $save_res['message'] ) ? $save_res['message'] : __( '保存失败', 'qibbs-community' );
        $notice_type    = 'error';
    }
}

// 处理删除道具
if ( isset( $_POST['qibbs_delete_prop'] ) && check_admin_referer( 'qibbs_prop_action' ) ) {
    $delete_id = isset( $_POST['delete_prop_id'] ) ? absint( $_POST['delete_prop_id'] ) : 0;
    if ( $delete_id > 0 ) {
        $del_res = $prop_service->delete_prop( $delete_id );
        if ( ! empty( $del_res['success'] ) ) {
            $notice_message = $del_res['message'];
            $notice_type    = 'success';
        } else {
            $notice_message = ! empty( $del_res['message'] ) ? $del_res['message'] : __( '删除失败', 'qibbs-community' );
            $notice_type    = 'error';
        }
    }
}

// 查询当前道具列表
$current_tab = isset( $_GET['prop_tab'] ) ? sanitize_key( $_GET['prop_tab'] ) : 'all';
$filter_type = in_array( $current_tab, array( 'name_color', 'post_sticky' ), true ) ? $current_tab : '';
$all_props   = $prop_service->get_props( $filter_type, 'all' );

// 统计指标
$total_count  = count( $all_props );
$active_count = 0;
$total_sales  = 0;
foreach ( $all_props as $p_item ) {
    if ( 'active' === $p_item->status ) {
        $active_count++;
    }
    $total_sales += (int) $p_item->sales_count;
}

// 编辑模式回显（直接打开弹窗用）
$edit_id   = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
$edit_item = $edit_id > 0 ? $prop_service->get_prop( $edit_id ) : null;
?>

<div class="wrap qibbs-admin-page">
    <div class="qibbs-admin-header-flex" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="margin:0 0 6px;"><?php esc_html_e( '道具大厅管理', 'qibbs-community' ); ?></h1>
            <p class="description" style="margin:0;">
                <?php esc_html_e( '管理微社区纯积分消耗道具（个性昵称变色、圈子付费置顶推广等）。道具仅支持积分结算，不支持提现回收与C2C转让。', 'qibbs-community' ); ?>
            </p>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
            <button type="button" class="button button-primary" id="qibbs-admin-add-prop-btn" style="display:inline-flex;align-items:center;gap:6px;font-weight:600;padding:4px 14px;">
                <span class="dashicons dashicons-plus-alt2" style="font-size:16px;line-height:1.2;width:16px;height:16px;"></span>
                <span><?php esc_html_e( '添加新道具', 'qibbs-community' ); ?></span>
            </button>
            <a href="<?php echo esc_url( qibbs_get_prop_mall_url() ); ?>" class="button button-secondary" target="_blank" style="display:inline-flex;align-items:center;gap:6px;">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/></svg>
                <span><?php esc_html_e( '前台道具大厅', 'qibbs-community' ); ?></span>
            </a>
        </div>
    </div>

    <?php if ( ! empty( $notice_message ) ) : ?>
        <div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible">
            <p><?php echo esc_html( $notice_message ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( ! $has_points ) : ?>
        <div class="notice notice-warning">
            <p><strong><?php esc_html_e( '注意：', 'qibbs-community' ); ?></strong><?php esc_html_e( '尚未检测到商城的可用积分接口（或积分联动开关未开启），道具大厅暂无法进行前台积分扣除。请在「基础设置 - 模块开关」中确认配置。', 'qibbs-community' ); ?></p>
        </div>
    <?php endif; ?>

    <!-- 概览指标卡片 -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:20px;">
        <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:14px 18px;">
            <div style="font-size:12px;color:#64748b;margin-bottom:4px;"><?php esc_html_e( '配置道具数', 'qibbs-community' ); ?></div>
            <div style="font-size:24px;font-weight:700;color:#0f172a;"><?php echo esc_html( $total_count ); ?></div>
        </div>
        <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:14px 18px;">
            <div style="font-size:12px;color:#64748b;margin-bottom:4px;"><?php esc_html_e( '上架生效中', 'qibbs-community' ); ?></div>
            <div style="font-size:24px;font-weight:700;color:#059669;"><?php echo esc_html( $active_count ); ?></div>
        </div>
        <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:14px 18px;">
            <div style="font-size:12px;color:#64748b;margin-bottom:4px;"><?php esc_html_e( '全站累计兑换量', 'qibbs-community' ); ?></div>
            <div style="font-size:24px;font-weight:700;color:#2563eb;"><?php echo esc_html( $total_sales ); ?></div>
        </div>
        <div style="background:#fff;border:1px solid #ccd0d4;border-radius:6px;padding:14px 18px;">
            <div style="font-size:12px;color:#64748b;margin-bottom:4px;"><?php esc_html_e( '结算币种体系', 'qibbs-community' ); ?></div>
            <div style="font-size:18px;font-weight:700;color:#d97706;"><?php echo esc_html( $points_name ); ?>（纯积分）</div>
        </div>
    </div>

    <!-- 道具分类过滤器 -->
    <ul class="subsubsub" style="margin-bottom:12px;">
        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-props' ) ); ?>" class="<?php echo 'all' === $current_tab ? 'current' : ''; ?>"><?php esc_html_e( '全部道具', 'qibbs-community' ); ?></a> |</li>
        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-props&prop_tab=name_color' ) ); ?>" class="<?php echo 'name_color' === $current_tab ? 'current' : ''; ?>"><?php esc_html_e( '名字变色', 'qibbs-community' ); ?></a> |</li>
        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-props&prop_tab=post_sticky' ) ); ?>" class="<?php echo 'post_sticky' === $current_tab ? 'current' : ''; ?>"><?php esc_html_e( '圈子置顶推广', 'qibbs-community' ); ?></a></li>
    </ul>

    <!-- 100% 全宽专业数据表格 -->
    <table class="wp-list-table widefat fixed striped" style="border:1px solid #ccd0d4;box-shadow:0 1px 1px rgba(0,0,0,0.04);border-radius:4px;margin-bottom:24px;">
        <thead>
            <tr>
                <th style="width:50px;">ID</th>
                <th style="width:200px;"><?php esc_html_e( '道具名称 / 类型', 'qibbs-community' ); ?></th>
                <th style="width:160px;"><?php esc_html_e( '视觉预览', 'qibbs-community' ); ?></th>
                <th style="width:120px;"><?php esc_html_e( '积分价格', 'qibbs-community' ); ?></th>
                <th style="width:100px;"><?php esc_html_e( '有效期', 'qibbs-community' ); ?></th>
                <th style="width:100px;"><?php esc_html_e( '库存 / 销量', 'qibbs-community' ); ?></th>
                <th style="width:80px;"><?php esc_html_e( '状态', 'qibbs-community' ); ?></th>
                <th style="width:60px;"><?php esc_html_e( '排序', 'qibbs-community' ); ?></th>
                <th style="width:130px;"><?php esc_html_e( '操作', 'qibbs-community' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $all_props ) ) : ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:32px 16px;color:#64748b;">
                        <p style="margin:0 0 10px;"><?php esc_html_e( '暂无道具，点击下方按钮添加第一个道具。', 'qibbs-community' ); ?></p>
                        <button type="button" class="button button-primary qibbs-admin-trigger-add">
                            <?php esc_html_e( '+ 添加新道具', 'qibbs-community' ); ?>
                        </button>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ( $all_props as $p ) : 
                    $p_json_data = array(
                        'id'            => (int) $p->id,
                        'name'          => (string) $p->name,
                        'prop_type'     => (string) $p->prop_type,
                        'description'   => (string) $p->description,
                        'price_points'  => (float) $p->price_points,
                        'duration_days' => (int) $p->duration_days,
                        'stock'         => (int) $p->stock,
                        'status'        => (string) $p->status,
                        'sort_order'    => (int) $p->sort_order,
                        'color'         => ! empty( $p->meta_data['color'] ) ? $p->meta_data['color'] : '#ef4444',
                        'style'         => ! empty( $p->meta_data['style'] ) ? $p->meta_data['style'] : 'solid',
                    );
                ?>
                    <tr id="qibbs-prop-row-<?php echo (int) $p->id; ?>">
                        <td><strong>#<?php echo (int) $p->id; ?></strong></td>
                        <td>
                            <strong style="font-size:13px;color:#1e293b;"><?php echo esc_html( $p->name ); ?></strong>
                            <div style="font-size:12px;margin-top:4px;">
                                <?php if ( 'name_color' === $p->prop_type ) : ?>
                                    <span style="display:inline-block;padding:2px 7px;border-radius:4px;background:#e0e7ff;color:#4338ca;font-size:11px;font-weight:600;"><?php esc_html_e( '昵称变色', 'qibbs-community' ); ?></span>
                                <?php elseif ( 'post_sticky' === $p->prop_type ) : ?>
                                    <span style="display:inline-block;padding:2px 7px;border-radius:4px;background:#fef3c7;color:#b45309;font-size:11px;font-weight:600;"><?php esc_html_e( '置顶推广', 'qibbs-community' ); ?></span>
                                <?php else : ?>
                                    <span style="display:inline-block;padding:2px 7px;border-radius:4px;background:#f1f5f9;color:#475569;font-size:11px;"><?php echo esc_html( $p->prop_type ); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ( 'name_color' === $p->prop_type ) : 
                                $color_hex = ! empty( $p->meta_data['color'] ) ? $p->meta_data['color'] : '#ef4444';
                            ?>
                                <div style="display:inline-flex;align-items:center;gap:8px;padding:3px 8px;border-radius:4px;background:#f8fafc;border:1px solid #e2e8f0;">
                                    <span style="display:inline-block;width:15px;height:15px;border-radius:50%;background:<?php echo esc_attr( $color_hex ); ?>;border:1px solid rgba(0,0,0,0.15);box-shadow:0 1px 3px rgba(0,0,0,0.1);"></span>
                                    <span style="font-weight:700;color:<?php echo esc_attr( $color_hex ); ?>;font-size:13px;"><?php esc_html_e( '示例用户昵称', 'qibbs-community' ); ?></span>
                                </div>
                            <?php elseif ( 'post_sticky' === $p->prop_type ) : ?>
                                <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 8px;background:rgba(217,119,6,0.12);color:#b45309;border-radius:4px;font-size:12px;font-weight:600;">
                                    <svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor"><path d="M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 10h2v4h-2zm0 5h2v2h-2z"/></svg>
                                    <?php esc_html_e( '圈子顶部推广', 'qibbs-community' ); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong style="color:#d97706;font-size:14px;"><?php echo esc_html( qibbs_format_points_amount( $p->price_points ) ); ?></strong>
                            <span style="font-size:12px;color:#64748b;"><?php echo esc_html( $points_name ); ?></span>
                        </td>
                        <td>
                            <?php echo (int) $p->duration_days > 0 ? sprintf( esc_html__( '%d 天', 'qibbs-community' ), (int) $p->duration_days ) : esc_html__( '永久', 'qibbs-community' ); ?>
                        </td>
                        <td>
                            <div style="font-size:12px;">
                                <?php echo (int) $p->stock < 0 ? esc_html__( '不限库存', 'qibbs-community' ) : sprintf( esc_html__( '余 %d 件', 'qibbs-community' ), (int) $p->stock ); ?>
                            </div>
                            <div style="font-size:11px;color:#64748b;margin-top:2px;"><?php echo sprintf( esc_html__( '已兑 %d 次', 'qibbs-community' ), (int) $p->sales_count ); ?></div>
                        </td>
                        <td>
                            <?php if ( 'active' === $p->status ) : ?>
                                <span style="color:#059669;font-weight:600;display:inline-flex;align-items:center;gap:3px;">
                                    <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#059669;"></span>
                                    <?php esc_html_e( '上架中', 'qibbs-community' ); ?>
                                </span>
                            <?php else : ?>
                                <span style="color:#94a3b8;display:inline-flex;align-items:center;gap:3px;">
                                    <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#94a3b8;"></span>
                                    <?php esc_html_e( '已下架', 'qibbs-community' ); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="color:#64748b;"><?php echo (int) $p->sort_order; ?></span>
                        </td>
                        <td>
                            <!-- 现代化编辑按钮：0ms 即刻弹出居中编辑弹窗，彻底杜绝无反应和排版遮挡 -->
                            <button type="button" class="button button-small button-primary qibbs-admin-edit-prop-btn" data-prop="<?php echo esc_attr( wp_json_encode( $p_json_data ) ); ?>" style="cursor:pointer;">
                                <?php esc_html_e( '编辑', 'qibbs-community' ); ?>
                            </button>
                            <form method="post" style="display:inline-block;margin-left:5px;" onsubmit="return confirm('<?php esc_attr_e( '确定要删除该道具吗？删除后用户已购买的仍可继续使用至到期。', 'qibbs-community' ); ?>');">
                                <?php wp_nonce_field( 'qibbs_prop_action' ); ?>
                                <input type="hidden" name="delete_prop_id" value="<?php echo (int) $p->id; ?>">
                                <button type="submit" name="qibbs_delete_prop" class="button button-small button-link-delete" style="color:#dc2626;border-color:transparent;background:none;padding:0 4px;cursor:pointer;">
                                    <?php esc_html_e( '删除', 'qibbs-community' ); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ==================== 原生居中模态弹窗（添加/编辑道具） ==================== -->
<div id="qibbs-admin-prop-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.65);z-index:999999;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px);">
    <div style="background:#fff;border-radius:10px;width:100%;max-width:540px;max-height:90vh;overflow-y:auto;box-shadow:0 25px 50px -12px rgba(0,0,0,0.3);position:relative;animation:qibbsAdminModalIn 0.2s cubic-bezier(0.16,1,0.3,1);">
        <!-- 弹窗头部 -->
        <div style="padding:16px 22px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
            <h2 id="qibbs-admin-modal-title" style="margin:0;font-size:17px;font-weight:700;color:#0f172a;">
                <?php esc_html_e( '添加新道具', 'qibbs-community' ); ?>
            </h2>
            <button type="button" id="qibbs-admin-modal-close-btn" style="border:none;background:none;font-size:24px;line-height:1;color:#64748b;cursor:pointer;padding:2px 6px;">&times;</button>
        </div>

        <!-- 表单区域 -->
        <form method="post" id="qibbs-admin-prop-form" style="padding:22px;">
            <?php wp_nonce_field( 'qibbs_prop_action' ); ?>
            <input type="hidden" id="qibbs-prop-id-input" name="prop_id" value="0">

            <div style="margin-bottom:16px;">
                <label for="qibbs-modal-name" style="display:block;font-weight:600;margin-bottom:6px;color:#1e293b;">
                    <?php esc_html_e( '道具名称', 'qibbs-community' ); ?> <span style="color:#dc2626;">*</span>
                </label>
                <input type="text" id="qibbs-modal-name" name="prop_name" class="widefat" required placeholder="<?php esc_attr_e( '例如：中国红 昵称变色 / 3天圈子置顶推广', 'qibbs-community' ); ?>" style="padding:6px 10px;border-radius:4px;">
            </div>

            <div style="margin-bottom:16px;">
                <label for="qibbs-modal-type" style="display:block;font-weight:600;margin-bottom:6px;color:#1e293b;">
                    <?php esc_html_e( '道具类型', 'qibbs-community' ); ?> <span style="color:#dc2626;">*</span>
                </label>
                <select id="qibbs-modal-type" name="prop_type" class="widefat" onchange="qibbsAdminModalToggleType(this.value)" style="padding:6px 10px;border-radius:4px;">
                    <option value="name_color"><?php esc_html_e( '名字变色（个性装扮）', 'qibbs-community' ); ?></option>
                    <option value="post_sticky"><?php esc_html_e( '圈子置顶推广（营销工具）', 'qibbs-community' ); ?></option>
                </select>
            </div>

            <!-- 名字颜色专有配置 -->
            <div id="qibbs-modal-color-wrap" style="margin-bottom:16px;background:#f8fafc;padding:12px;border-radius:6px;border:1px solid #e2e8f0;">
                <label for="qibbs-modal-color-val" style="display:block;font-weight:600;margin-bottom:6px;color:#1e293b;">
                    <?php esc_html_e( '变色色值 (HEX)', 'qibbs-community' ); ?> <span style="color:#dc2626;">*</span>
                </label>
                <div style="display:flex;gap:10px;align-items:center;">
                    <input type="color" id="qibbs-modal-color-picker" value="#ef4444" style="height:36px;width:44px;padding:0;cursor:pointer;border:1px solid #cbd5e1;border-radius:4px;">
                    <input type="text" id="qibbs-modal-color-val" name="color_val" value="#ef4444" class="widefat" style="flex:1;font-family:monospace;padding:6px 10px;border-radius:4px;">
                </div>
                <div style="margin-top:8px;font-size:12px;color:#64748b;">
                    <?php esc_html_e( '效果预览：', 'qibbs-community' ); ?>
                    <span id="qibbs-modal-color-preview-text" style="font-weight:700;color:#ef4444;"><?php esc_html_e( '示例用户昵称', 'qibbs-community' ); ?></span>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
                <div>
                    <label for="qibbs-modal-price" style="display:block;font-weight:600;margin-bottom:6px;color:#1e293b;">
                        <?php echo sprintf( esc_html__( '价格（%s）', 'qibbs-community' ), esc_html( $points_name ) ); ?> <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" step="0.01" min="0.01" id="qibbs-modal-price" name="price_points" value="100" class="widefat" required style="padding:6px 10px;border-radius:4px;">
                    <span class="description" style="font-size:11px;color:#64748b;"><?php esc_html_e( '纯积分结算，不支持现金', 'qibbs-community' ); ?></span>
                </div>
                <div>
                    <label for="qibbs-modal-duration" style="display:block;font-weight:600;margin-bottom:6px;color:#1e293b;">
                        <?php esc_html_e( '有效天数', 'qibbs-community' ); ?>
                    </label>
                    <input type="number" min="0" id="qibbs-modal-duration" name="duration_days" value="30" class="widefat" style="padding:6px 10px;border-radius:4px;">
                    <span class="description" style="font-size:11px;color:#64748b;"><?php esc_html_e( '0 表示永久，置顶建议 3/7 天', 'qibbs-community' ); ?></span>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px;">
                <div>
                    <label for="qibbs-modal-stock" style="display:block;font-weight:600;margin-bottom:6px;color:#1e293b;">
                        <?php esc_html_e( '全站限量库存', 'qibbs-community' ); ?>
                    </label>
                    <input type="number" min="-1" id="qibbs-modal-stock" name="stock" value="-1" class="widefat" style="padding:6px 10px;border-radius:4px;">
                    <span class="description" style="font-size:11px;color:#64748b;"><?php esc_html_e( '-1 表示不限量', 'qibbs-community' ); ?></span>
                </div>
                <div>
                    <label for="qibbs-modal-status" style="display:block;font-weight:600;margin-bottom:6px;color:#1e293b;">
                        <?php esc_html_e( '上架状态', 'qibbs-community' ); ?>
                    </label>
                    <select id="qibbs-modal-status" name="status" class="widefat" style="padding:6px 10px;border-radius:4px;">
                        <option value="active"><?php esc_html_e( '立即上架', 'qibbs-community' ); ?></option>
                        <option value="inactive"><?php esc_html_e( '下架隐藏', 'qibbs-community' ); ?></option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:16px;">
                <label for="qibbs-modal-sort" style="display:block;font-weight:600;margin-bottom:6px;color:#1e293b;">
                    <?php esc_html_e( '排序权重', 'qibbs-community' ); ?>
                </label>
                <input type="number" id="qibbs-modal-sort" name="sort_order" value="0" class="widefat" style="padding:6px 10px;border-radius:4px;">
                <span class="description" style="font-size:11px;color:#64748b;"><?php esc_html_e( '数字越小越靠前显示', 'qibbs-community' ); ?></span>
            </div>

            <div style="margin-bottom:20px;">
                <label for="qibbs-modal-desc" style="display:block;font-weight:600;margin-bottom:6px;color:#1e293b;">
                    <?php esc_html_e( '道具说明文案', 'qibbs-community' ); ?>
                </label>
                <textarea id="qibbs-modal-desc" name="description" rows="3" class="widefat" placeholder="<?php esc_attr_e( '展示在前台道具卡片下方说明...', 'qibbs-community' ); ?>" style="padding:6px 10px;border-radius:4px;"></textarea>
            </div>

            <!-- 弹窗底部操作按钮 -->
            <div style="display:flex;justify-content:flex-end;gap:10px;padding-top:16px;border-top:1px solid #f1f5f9;">
                <button type="button" id="qibbs-admin-modal-cancel-btn" class="button button-secondary" style="padding:4px 14px;">
                    <?php esc_html_e( '取消', 'qibbs-community' ); ?>
                </button>
                <button type="submit" name="qibbs_save_prop" id="qibbs-admin-modal-submit-btn" class="button button-primary" style="padding:4px 18px;font-weight:600;">
                    <?php esc_html_e( '保存道具', 'qibbs-community' ); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes qibbsAdminModalIn {
    from {
        opacity: 0;
        transform: scale(0.96) translateY(10px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}
</style>

<script>
(function() {
    var modal = document.getElementById('qibbs-admin-prop-modal');
    var modalTitle = document.getElementById('qibbs-admin-modal-title');
    var modalSubmitBtn = document.getElementById('qibbs-admin-modal-submit-btn');
    var propIdInput = document.getElementById('qibbs-prop-id-input');
    var nameInput = document.getElementById('qibbs-modal-name');
    var typeSelect = document.getElementById('qibbs-modal-type');
    var colorWrap = document.getElementById('qibbs-modal-color-wrap');
    var colorPicker = document.getElementById('qibbs-modal-color-picker');
    var colorVal = document.getElementById('qibbs-modal-color-val');
    var colorPreviewText = document.getElementById('qibbs-modal-color-preview-text');
    var priceInput = document.getElementById('qibbs-modal-price');
    var durationInput = document.getElementById('qibbs-modal-duration');
    var stockInput = document.getElementById('qibbs-modal-stock');
    var statusSelect = document.getElementById('qibbs-modal-status');
    var sortInput = document.getElementById('qibbs-modal-sort');
    var descTextarea = document.getElementById('qibbs-modal-desc');

    function syncColor(val) {
        if (!val) val = '#ef4444';
        if (colorPicker) colorPicker.value = val;
        if (colorVal) colorVal.value = val;
        if (colorPreviewText) colorPreviewText.style.color = val;
    }

    if (colorPicker) {
        colorPicker.addEventListener('input', function() {
            syncColor(this.value);
        });
    }
    if (colorVal) {
        colorVal.addEventListener('input', function() {
            syncColor(this.value);
        });
    }

    window.qibbsAdminModalToggleType = function(type) {
        if (colorWrap) {
            colorWrap.style.display = (type === 'name_color') ? 'block' : 'none';
        }
    };

    window.qibbsOpenPropModal = function(data) {
        if (!modal) return;
        if (data && data.id) {
            // 编辑模式
            modalTitle.textContent = '编辑道具 #' + data.id + ' (' + data.name + ')';
            modalSubmitBtn.textContent = '保存修改';
            propIdInput.value = data.id;
            nameInput.value = data.name || '';
            typeSelect.value = data.prop_type || 'name_color';
            syncColor(data.color || '#ef4444');
            priceInput.value = data.price_points || '100';
            durationInput.value = (typeof data.duration_days !== 'undefined') ? data.duration_days : '30';
            stockInput.value = (typeof data.stock !== 'undefined') ? data.stock : '-1';
            statusSelect.value = data.status || 'active';
            sortInput.value = (typeof data.sort_order !== 'undefined') ? data.sort_order : '0';
            descTextarea.value = data.description || '';
        } else {
            // 添加新道具模式
            modalTitle.textContent = '添加新道具';
            modalSubmitBtn.textContent = '立即添加道具';
            propIdInput.value = '0';
            nameInput.value = '';
            typeSelect.value = 'name_color';
            syncColor('#ef4444');
            priceInput.value = '100';
            durationInput.value = '30';
            stockInput.value = '-1';
            statusSelect.value = 'active';
            sortInput.value = '0';
            descTextarea.value = '';
        }
        window.qibbsAdminModalToggleType(typeSelect.value);
        modal.style.display = 'flex';
        nameInput.focus();
    };

    window.qibbsClosePropModal = function() {
        if (modal) {
            modal.style.display = 'none';
        }
    };

    // 绑定添加按钮
    var addBtn = document.getElementById('qibbs-admin-add-prop-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function(e) {
            e.preventDefault();
            window.qibbsOpenPropModal(null);
        });
    }
    var triggerAddButtons = document.querySelectorAll('.qibbs-admin-trigger-add');
    triggerAddButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            window.qibbsOpenPropModal(null);
        });
    });

    // 绑定表格内编辑按钮
    document.addEventListener('click', function(e) {
        var editBtn = e.target.closest('.qibbs-admin-edit-prop-btn');
        if (editBtn) {
            e.preventDefault();
            var raw = editBtn.getAttribute('data-prop');
            if (raw) {
                try {
                    var data = JSON.parse(raw);
                    window.qibbsOpenPropModal(data);
                } catch(err) {
                    console.error('解析道具数据失败', err);
                }
            }
        }
    });

    // 绑定关闭按钮
    var closeBtn = document.getElementById('qibbs-admin-modal-close-btn');
    if (closeBtn) closeBtn.addEventListener('click', window.qibbsClosePropModal);

    var cancelBtn = document.getElementById('qibbs-admin-modal-cancel-btn');
    if (cancelBtn) cancelBtn.addEventListener('click', window.qibbsClosePropModal);

    // 点击遮罩外部关闭
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                window.qibbsClosePropModal();
            }
        });
    }

    // 按下 ESC 键关闭
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal && modal.style.display === 'flex') {
            window.qibbsClosePropModal();
        }
    });

    // 如果 URL 携带了 edit 参数，页面载入后自动唤起弹窗
    <?php if ( $edit_item ) : ?>
    document.addEventListener('DOMContentLoaded', function() {
        var editItemData = <?php echo wp_json_encode( array(
            'id'            => (int) $edit_item->id,
            'name'          => (string) $edit_item->name,
            'prop_type'     => (string) $edit_item->prop_type,
            'description'   => (string) $edit_item->description,
            'price_points'  => (float) $edit_item->price_points,
            'duration_days' => (int) $edit_item->duration_days,
            'stock'         => (int) $edit_item->stock,
            'status'        => (string) $edit_item->status,
            'sort_order'    => (int) $edit_item->sort_order,
            'color'         => ! empty( $edit_item->meta_data['color'] ) ? $edit_item->meta_data['color'] : '#ef4444',
            'style'         => ! empty( $edit_item->meta_data['style'] ) ? $edit_item->meta_data['style'] : 'solid',
        ) ); ?>;
        window.qibbsOpenPropModal(editItemData);
    });
    <?php endif; ?>
})();
</script>
