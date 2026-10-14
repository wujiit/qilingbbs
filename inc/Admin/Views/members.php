<?php
/**
 * 社区用户全维管理后台视图
 * 提供用户全局检索、UID/佩戴号调度、商城可提现余额人工调账、名下靓号资产管理及一键封禁治理
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( '您没有足够的权限访问此页面。', 'qibbs-community' ) );
}

global $wpdb;
$member_service = Qibbs_Member_Service::instance();
$pool_service   = Qibbs_Vanity_Pool_Service::instance();
$c2c_service    = Qibbs_C2C_Trade_Service::instance();

$notice      = '';
$notice_type = 'success';

// 1. 处理人工调账提交
if ( isset( $_POST['qibbs_member_adjust_balance'] ) && check_admin_referer( 'qibbs_member_adjust_action', 'qibbs_member_nonce' ) ) {
    $target_uid    = absint( $_POST['adjust_user_id'] ?? 0 );
    $adjust_type   = sanitize_key( $_POST['adjust_type'] ?? 'add' );
    $adjust_amount = (float) ( $_POST['adjust_amount'] ?? 0 );
    $adjust_note   = sanitize_text_field( wp_unslash( $_POST['adjust_note'] ?? '' ) );

    $res = $member_service->adjust_balance( $target_uid, $adjust_amount, $adjust_type, $adjust_note, get_current_user_id() );
    $notice      = $res['message'];
    $notice_type = $res['success'] ? 'success' : 'error';
}

// 2. 处理重置佩戴号为保底初始号
if ( isset( $_GET['reset_cuid_user_id'] ) && check_admin_referer( 'qibbs_reset_cuid_' . absint( $_GET['reset_cuid_user_id'] ) ) ) {
    $target_uid  = absint( $_GET['reset_cuid_user_id'] );
    $res         = $member_service->reset_to_default_cuid( $target_uid );
    $notice      = $res['message'];
    $notice_type = $res['success'] ? 'success' : 'error';
}

// 3. 处理管理员快捷切换用户佩戴号
if ( isset( $_POST['qibbs_member_equip_cuid'] ) && check_admin_referer( 'qibbs_member_equip_action', 'qibbs_member_nonce' ) ) {
    $target_uid  = absint( $_POST['equip_user_id'] ?? 0 );
    $target_cuid = absint( $_POST['equip_target_cuid'] ?? 0 );
    $res         = $member_service->equip_cuid( $target_uid, $target_cuid );
    $notice      = $res['message'];
    $notice_type = $res['success'] ? 'success' : 'error';
}

// 4. 处理黑名单封禁/解封
if ( isset( $_POST['qibbs_member_toggle_ban'] ) && check_admin_referer( 'qibbs_member_ban_action', 'qibbs_member_nonce' ) ) {
    $target_uid = absint( $_POST['ban_user_id'] ?? 0 );
    $ban_action = sanitize_key( $_POST['ban_action'] ?? 'ban' );
    $ban_reason = sanitize_textarea_field( wp_unslash( $_POST['ban_reason'] ?? '' ) );

    $res         = $member_service->toggle_blacklist( $target_uid, ( 'ban' === $ban_action ), $ban_reason, get_current_user_id() );
    $notice      = $res['message'];
    $notice_type = $res['success'] ? 'success' : 'error';
}

// 5. 处理强制收回用户靓号至官方池
if ( isset( $_GET['reclaim_cuid'] ) && check_admin_referer( 'qibbs_member_reclaim_' . absint( $_GET['reclaim_cuid'] ) ) ) {
    $reclaim_cuid = absint( $_GET['reclaim_cuid'] );
    $res          = $pool_service->reclaim_to_pool( $reclaim_cuid, 'in_pool' );
    $notice       = $res['message'];
    $notice_type  = $res['success'] ? 'success' : 'error';
}

// 6. 处理强制下架 C2C 寄售
if ( isset( $_GET['delist_cuid'] ) && isset( $_GET['seller_id'] ) && check_admin_referer( 'qibbs_member_delist_' . absint( $_GET['delist_cuid'] ) ) ) {
    $delist_cuid = absint( $_GET['delist_cuid'] );
    $seller_id   = absint( $_GET['seller_id'] );
    $res         = $c2c_service->delist_number( $delist_cuid, $seller_id, true );
    $notice      = $res['message'];
    $notice_type = $res['success'] ? 'success' : 'error';
}

// 7. 处理直接修改用户UID (保底UID及佩戴UID)
if ( isset( $_POST['qibbs_member_edit_cuid'] ) && check_admin_referer( 'qibbs_member_edit_cuid_action', 'qibbs_member_nonce' ) ) {
    $target_uid = absint( $_POST['edit_user_id'] ?? 0 );
    $new_cuid   = absint( $_POST['new_cuid'] ?? 0 );
    $set_active = ! empty( $_POST['set_active_also'] );

    $res         = $member_service->update_user_cuid( $target_uid, $new_cuid, $set_active );
    $notice      = $res['message'];
    $notice_type = $res['success'] ? 'success' : 'error';
}

// 8. 处理成长经验值调整 (惩罚扣除 / 特别奖励 / 重置)
if ( isset( $_POST['qibbs_member_adjust_exp'] ) && check_admin_referer( 'qibbs_member_adjust_exp_action', 'qibbs_member_nonce' ) ) {
    $target_uid    = absint( $_POST['adjust_exp_user_id'] ?? 0 );
    $adjust_type   = sanitize_key( $_POST['adjust_exp_type'] ?? 'deduct' );
    $adjust_amount = absint( $_POST['adjust_exp_amount'] ?? 0 );
    $adjust_note   = sanitize_text_field( wp_unslash( $_POST['adjust_exp_note'] ?? '' ) );

    if ( class_exists( 'Qibbs_Level_Service' ) ) {
        $res = Qibbs_Level_Service::get_instance()->admin_adjust_user_exp( $target_uid, $adjust_amount, $adjust_type, $adjust_note, get_current_user_id() );
        $notice      = $res['message'];
        $notice_type = $res['success'] ? 'success' : 'error';
    }
}

// 读取宏观指标数据
$summary_stats = $member_service->get_member_summary_stats();

// 查询筛选条件与分页
$filter       = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'all';
$search       = isset( $_GET['s'] ) ? sanitize_text_field( trim( wp_unslash( $_GET['s'] ) ) ) : '';
$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$per_page     = 20;
$offset       = ( $paged - 1 ) * $per_page;
$view_user_id = isset( $_GET['view_user_id'] ) ? absint( $_GET['view_user_id'] ) : 0;

// 表名映射
$members_table       = Qibbs_Database::members();
$stats_table         = Qibbs_Database::user_stats();
$black_table         = Qibbs_Database::blacklist();
$vanity_table        = Qibbs_Database::vanity_uids();
$forum_members_table = Qibbs_Database::forum_members();
$users_table         = $wpdb->users;

// 动态拼装 SQL
$where_clauses = array( '1 = 1' );
$where_args    = array();

if ( 'vanity' === $filter ) {
    $where_clauses[] = "u.ID IN (SELECT DISTINCT CASE WHEN c2c_seller_id > 0 THEN c2c_seller_id ELSE owner_id END FROM {$vanity_table} WHERE status IN ('owned', 'c2c_listed'))";
} elseif ( 'has_balance' === $filter ) {
    $shop_info_table = $wpdb->prefix . 'qilingshop_user_info';
    $has_shop_table  = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $shop_info_table ) ) === $shop_info_table );
    if ( $has_shop_table ) {
        $where_clauses[] = "u.ID IN (SELECT user_id FROM {$shop_info_table} WHERE withdrawable_balance > 0)";
    } else {
        $where_clauses[] = "1=0";
    }
} elseif ( 'banned' === $filter ) {
    $where_clauses[] = "b.id IS NOT NULL";
} elseif ( 'moderator' === $filter ) {
    $where_clauses[] = "u.ID IN (SELECT DISTINCT user_id FROM {$forum_members_table} WHERE role IN ('moderator', 'admin'))";
}

if ( ! empty( $search ) ) {
    if ( is_numeric( $search ) ) {
        $search_int   = absint( $search );
        $search_like  = '%' . $wpdb->esc_like( $search ) . '%';
        $where_clauses[] = "(u.ID = %d OR m.default_cuid = %d OR m.active_cuid = %d OR u.user_login LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)";
        array_push( $where_args, $search_int, $search_int, $search_int, $search_like, $search_like, $search_like );
    } else {
        $search_like  = '%' . $wpdb->esc_like( $search ) . '%';
        $where_clauses[] = "(u.user_login LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s)";
        array_push( $where_args, $search_like, $search_like, $search_like );
    }
}

$where_sql = implode( ' AND ', $where_clauses );

// 统计符合条件的总记录数
$count_sql = "SELECT COUNT(DISTINCT u.ID) 
              FROM {$users_table} u 
              LEFT JOIN {$members_table} m ON u.ID = m.user_id 
              LEFT JOIN {$black_table} b ON u.ID = b.user_id 
              WHERE {$where_sql}";

if ( ! empty( $where_args ) ) {
    $total_records = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where_args ) );
} else {
    $total_records = (int) $wpdb->get_var( $count_sql );
}

$total_pages = ceil( $total_records / $per_page );

// 分页查询列表
$query_sql = "SELECT 
                u.ID as user_id, 
                u.user_login, 
                u.display_name, 
                u.user_email, 
                u.user_registered, 
                m.default_cuid, 
                m.active_cuid, 
                m.balance, 
                s.post_count, 
                s.follower_count, 
                s.like_count, 
                b.reason as ban_reason, 
                b.created_at as ban_time,
                (SELECT COUNT(*) FROM {$vanity_table} v WHERE (v.owner_id = u.ID OR v.c2c_seller_id = u.ID) AND v.status IN ('owned', 'c2c_listed')) as vanity_count
              FROM {$users_table} u 
              LEFT JOIN {$members_table} m ON u.ID = m.user_id 
              LEFT JOIN {$stats_table} s ON u.ID = s.user_id 
              LEFT JOIN {$black_table} b ON u.ID = b.user_id 
              WHERE {$where_sql}
              ORDER BY u.ID DESC 
              LIMIT %d OFFSET %d";

$query_args = array_merge( $where_args, array( $per_page, $offset ) );
$member_rows = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_args ) );

// 若指定了查看单用户画像，读取 360° 档案
$view_user_profile = $view_user_id > 0 ? $member_service->get_user_360_profile( $view_user_id ) : null;
?>

<div class="wrap qibbs-admin-page qibbs-members-wrap">
    <h1><?php esc_html_e( '社区用户管理中心', 'qibbs-community' ); ?></h1>
    <p class="description">
        <?php esc_html_e( '全维聚合社区会员档案、保底号与佩戴靓号资产、启灵商城【可提现余额】与积分、发帖动态活跃度，支持一键人工调账、资产管理与风控封禁治理。', 'qibbs-community' ); ?>
    </p>

    <?php if ( ! empty( $notice ) ) : ?>
        <div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible" style="margin-top: 15px;">
            <p><strong><?php echo esc_html( $notice ); ?></strong></p>
        </div>
    <?php endif; ?>

    <!-- 1. 宏观统计概览卡片 -->
    <div class="qibbs-member-stat-grid">
        <div class="qibbs-mstat-card">
            <div class="qibbs-mstat-icon" style="background: rgba(34, 113, 177, 0.1); color: #2271b1;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            </div>
            <div class="qibbs-mstat-info">
                <span class="qibbs-mstat-label"><?php esc_html_e( '社区建档成员', 'qibbs-community' ); ?></span>
                <span class="qibbs-mstat-val"><?php echo number_format_i18n( $summary_stats['total_members'] ); ?></span>
            </div>
        </div>

        <div class="qibbs-mstat-card">
            <div class="qibbs-mstat-icon" style="background: rgba(245, 158, 11, 0.1); color: #d97706;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
            </div>
            <div class="qibbs-mstat-info">
                <span class="qibbs-mstat-label"><?php esc_html_e( '靓号持有玩家', 'qibbs-community' ); ?></span>
                <span class="qibbs-mstat-val"><?php echo number_format_i18n( $summary_stats['total_vanity_owners'] ); ?></span>
            </div>
        </div>

        <div class="qibbs-mstat-card">
            <div class="qibbs-mstat-icon" style="background: rgba(16, 185, 129, 0.1); color: #059669;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
            </div>
            <div class="qibbs-mstat-info">
                <span class="qibbs-mstat-label"><?php esc_html_e( '商城可提现沉淀资金', 'qibbs-community' ); ?></span>
                <span class="qibbs-mstat-val">¥ <?php echo number_format( $summary_stats['total_balance_pool'], 2 ); ?></span>
            </div>
        </div>

        <div class="qibbs-mstat-card">
            <div class="qibbs-mstat-icon" style="background: rgba(239, 68, 68, 0.1); color: #dc2626;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>
            </div>
            <div class="qibbs-mstat-info">
                <span class="qibbs-mstat-label"><?php esc_html_e( '黑名单受限用户', 'qibbs-community' ); ?></span>
                <span class="qibbs-mstat-val"><?php echo number_format_i18n( $summary_stats['total_banned'] ); ?></span>
            </div>
        </div>
    </div>

    <!-- 2. 筛选标签页与全局检索工具条 -->
    <div class="qibbs-member-toolbar">
        <ul class="subsubsub" style="margin: 0;">
            <li class="all">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-members&filter=all' ) ); ?>" class="<?php echo 'all' === $filter ? 'current' : ''; ?>">
                    <?php esc_html_e( '全部用户', 'qibbs-community' ); ?>
                </a> |
            </li>
            <li class="vanity">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-members&filter=vanity' ) ); ?>" class="<?php echo 'vanity' === $filter ? 'current' : ''; ?>">
                    <?php esc_html_e( '靓号玩家', 'qibbs-community' ); ?>
                </a> |
            </li>
            <li class="has_balance">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-members&filter=has_balance' ) ); ?>" class="<?php echo 'has_balance' === $filter ? 'current' : ''; ?>">
                    <?php esc_html_e( '商城有余额', 'qibbs-community' ); ?>
                </a> |
            </li>
            <li class="moderator">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-members&filter=moderator' ) ); ?>" class="<?php echo 'moderator' === $filter ? 'current' : ''; ?>">
                    <?php esc_html_e( '圈子版主', 'qibbs-community' ); ?>
                </a> |
            </li>
            <li class="banned">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-members&filter=banned' ) ); ?>" class="<?php echo 'banned' === $filter ? 'current' : ''; ?>">
                    <?php esc_html_e( '黑名单封禁', 'qibbs-community' ); ?>
                </a>
            </li>
        </ul>

        <form method="get" class="qibbs-member-search-form">
            <input type="hidden" name="page" value="qibbs-members">
            <input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
            <p class="search-box" style="position: static; margin: 0;">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( '搜索 UID / 用户名 / 昵称 / 邮箱...', 'qibbs-community' ); ?>">
                <button type="submit" class="button button-secondary"><?php esc_html_e( '搜索会员', 'qibbs-community' ); ?></button>
                <?php if ( ! empty( $search ) ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-members&filter=' . $filter ) ); ?>" class="button button-link"><?php esc_html_e( '清除', 'qibbs-community' ); ?></a>
                <?php endif; ?>
            </p>
        </form>
    </div>

    <!-- 3. 用户全维列表数据表格 -->
    <table class="wp-list-table widefat fixed striped qibbs-member-table" style="margin-top: 15px;">
        <thead>
            <tr>
                <th style="width: 210px;"><?php esc_html_e( '用户 / 基本信息', 'qibbs-community' ); ?></th>
                <th style="width: 180px;"><?php esc_html_e( '社区 UID 体系', 'qibbs-community' ); ?></th>
                <th style="width: 170px;"><?php esc_html_e( '商城资产 (现金/积分)', 'qibbs-community' ); ?></th>
                <th style="width: 180px;"><?php esc_html_e( '社区成长 / 活跃度', 'qibbs-community' ); ?></th>
                <th style="width: 100px;"><?php esc_html_e( '账号状态', 'qibbs-community' ); ?></th>
                <th style="width: 240px; text-align: right;"><?php esc_html_e( '管理操作', 'qibbs-community' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $member_rows ) ) : ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px; color: #64748b;">
                        <?php esc_html_e( '没有找到匹配的社区用户数据。', 'qibbs-community' ); ?>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ( $member_rows as $row ) :
                    $uid          = (int) $row->user_id;
                    $default_cuid = (int) $row->default_cuid;
                    $active_cuid  = (int) $row->active_cuid;
                    $balance      = function_exists( 'qibbs_get_user_withdrawable_balance' ) ? qibbs_get_user_withdrawable_balance( $uid ) : (float) $row->balance;
                    $vanity_cnt   = (int) $row->vanity_count;
                    $is_banned    = ! empty( $row->ban_reason ) || ! empty( $row->ban_time );
                    $is_wearing_vanity = ( $active_cuid > 0 && $active_cuid !== $default_cuid );
                    $points       = function_exists( 'qibbs_get_user_points_balance' ) ? qibbs_get_user_points_balance( $uid ) : 0;
                    $user_level_info = function_exists( 'qibbs_get_user_growth_level' ) ? qibbs_get_user_growth_level( $uid ) : null;
                    $user_exp        = function_exists( 'qibbs_get_user_exp' ) ? qibbs_get_user_exp( $uid ) : 0;
                    ?>
                    <tr>
                        <!-- 基本信息 -->
                        <td>
                            <div class="qibbs-user-cell">
                                <?php echo get_avatar( $uid, 40, '', '', array( 'class' => 'qibbs-user-avatar' ) ); ?>
                                <div class="qibbs-user-meta-wrap">
                                    <strong>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-members&view_user_id=' . $uid ) ); ?>" class="qibbs-view-user-btn" data-user-id="<?php echo esc_attr( $uid ); ?>">
                                            <?php echo esc_html( $row->display_name ?: $row->user_login ); ?>
                                        </a>
                                    </strong>
                                    <span class="qibbs-sub-meta">@<?php echo esc_html( $row->user_login ); ?> (ID: <?php echo esc_html( $uid ); ?>)</span>
                                    <span class="qibbs-sub-meta" style="color: #94a3b8;"><?php echo esc_html( $row->user_email ); ?></span>
                                </div>
                            </div>
                        </td>

                        <!-- UID体系 -->
                        <td>
                            <div class="qibbs-uid-cell">
                                <div class="qibbs-uid-row">
                                    <span class="qibbs-uid-tag <?php echo $is_wearing_vanity ? 'is-vanity' : 'is-default'; ?>">
                                        <?php echo $is_wearing_vanity ? esc_html__( '佩戴靓号', 'qibbs-community' ) : esc_html__( '佩戴中', 'qibbs-community' ); ?>
                                    </span>
                                    <strong style="font-size: 15px; font-family: monospace; color: <?php echo $is_wearing_vanity ? '#d97706' : '#1e293b'; ?>">
                                        <?php echo esc_html( $active_cuid ?: $default_cuid ?: '-' ); ?>
                                    </strong>
                                </div>
                                <div class="qibbs-sub-meta" style="margin-top: 4px;">
                                    <?php esc_html_e( '保底UID:', 'qibbs-community' ); ?> 
                                    <span style="font-family: monospace;"><?php echo esc_html( $default_cuid ?: '-' ); ?></span>
                                    <button type="button" class="button-link qibbs-open-edit-uid-btn" 
                                            style="color: #2563eb; margin-left: 5px; font-size: 11px; text-decoration: underline; cursor: pointer; border: none; background: none; padding: 0;"
                                            data-user-id="<?php echo esc_attr( $uid ); ?>"
                                            data-user-name="<?php echo esc_attr( $row->display_name ?: $row->user_login ); ?>"
                                            data-cuid="<?php echo esc_attr( $default_cuid ); ?>">
                                        <?php esc_html_e( '[修改UID]', 'qibbs-community' ); ?>
                                    </button>
                                    <?php if ( $is_wearing_vanity ) : ?>
                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=qibbs-members&reset_cuid_user_id=' . $uid ), 'qibbs_reset_cuid_' . $uid ) ); ?>" 
                                           onclick="return confirm('<?php echo esc_js( __( '确定将该用户当前佩戴号重置为系统保底号吗？', 'qibbs-community' ) ); ?>');"
                                           class="button-link" style="color: #dc2626; margin-left: 5px; font-size: 11px;">
                                            <?php esc_html_e( '[恢复保底]', 'qibbs-community' ); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>

                        <!-- 资产与财务 -->
                        <td>
                            <div class="qibbs-asset-cell">
                                <div>
                                    <?php esc_html_e( '商城可提现:', 'qibbs-community' ); ?> 
                                    <strong style="color: #059669; font-size: 14px;">¥ <?php echo number_format( $balance, 2 ); ?></strong>
                                </div>
                                <div class="qibbs-sub-meta" style="margin-top: 3px;">
                                    <?php printf( esc_html__( '商城%s:', 'qibbs-community' ), esc_html( function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' ) ) ); ?> 
                                    <strong><?php echo number_format_i18n( $points ); ?></strong>
                                </div>
                                <div class="qibbs-sub-meta">
                                    <?php esc_html_e( '持仓靓号:', 'qibbs-community' ); ?> 
                                    <span class="qibbs-badge-pill <?php echo $vanity_cnt > 0 ? 'is-gold' : ''; ?>">
                                        <?php echo sprintf( _n( '%d 个', '%d 个', $vanity_cnt, 'qibbs-community' ), $vanity_cnt ); ?>
                                    </span>
                                </div>
                            </div>
                        </td>

                        <!-- 成长等级与活跃度 -->
                        <td>
                            <div class="qibbs-level-cell" style="margin-bottom: 6px;">
                                <?php if ( $user_level_info ) : ?>
                                    <span class="qibbs-badge-pill" style="background: <?php echo esc_attr( $user_level_info['bg_color'] ?? '#eff6ff' ); ?>; color: <?php echo esc_attr( $user_level_info['color'] ?? '#2563eb' ); ?>; font-weight: 600; font-size: 11px;">
                                        <svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" style="vertical-align:-1px;margin-right:2px;" aria-hidden="true"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>Lv.<?php echo esc_html( $user_level_info['level'] ); ?> <?php echo esc_html( $user_level_info['name'] ); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="qibbs-badge-pill">Lv.1</span>
                                <?php endif; ?>
                                <div class="qibbs-sub-meta" style="margin-top: 3px;">
                                    <?php esc_html_e( '经验值:', 'qibbs-community' ); ?> <strong style="color: #4f46e5;"><?php echo number_format_i18n( $user_exp ); ?></strong>
                                </div>
                            </div>
                            <div class="qibbs-sub-meta" style="line-height: 1.5; border-top: 1px dashed #f1f5f9; padding-top: 4px;">
                                <div><?php esc_html_e( '动态发帖:', 'qibbs-community' ); ?> <strong><?php echo number_format_i18n( (int) $row->post_count ); ?></strong></div>
                                <div><?php esc_html_e( '粉丝数:', 'qibbs-community' ); ?> <strong><?php echo number_format_i18n( (int) $row->follower_count ); ?></strong></div>
                                <div><?php esc_html_e( '获得点赞:', 'qibbs-community' ); ?> <strong><?php echo number_format_i18n( (int) $row->like_count ); ?></strong></div>
                            </div>
                        </td>

                        <!-- 账号状态 -->
                        <td>
                            <?php if ( $is_banned ) : ?>
                                <span class="qibbs-status-badge is-banned" title="<?php echo esc_attr( $row->ban_reason ?: __( '违规受限', 'qibbs-community' ) ); ?>">
                                    <?php esc_html_e( '已封禁', 'qibbs-community' ); ?>
                                </span>
                            <?php else : ?>
                                <span class="qibbs-status-badge is-active">
                                    <?php esc_html_e( '正常', 'qibbs-community' ); ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- 管理操作 -->
                        <td style="text-align: right;">
                            <div class="qibbs-action-group">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-members&view_user_id=' . $uid ) ); ?>" class="button button-small">
                                    <?php esc_html_e( '全维档案', 'qibbs-community' ); ?>
                                </a>
                                <button type="button" class="button button-small qibbs-open-adjust-btn" 
                                        data-user-id="<?php echo esc_attr( $uid ); ?>"
                                        data-user-name="<?php echo esc_attr( $row->display_name ?: $row->user_login ); ?>"
                                        data-balance="<?php echo esc_attr( $balance ); ?>">
                                    <?php esc_html_e( '调账', 'qibbs-community' ); ?>
                                </button>
                                <button type="button" class="button button-small qibbs-open-exp-adjust-btn" 
                                        data-user-id="<?php echo esc_attr( $uid ); ?>"
                                        data-user-name="<?php echo esc_attr( $row->display_name ?: $row->user_login ); ?>"
                                        data-exp="<?php echo esc_attr( $user_exp ); ?>"
                                        data-level="<?php echo esc_attr( $user_level_info ? $user_level_info['level'] : 1 ); ?>"
                                        data-level-name="<?php echo esc_attr( $user_level_info ? $user_level_info['name'] : '见习萌新' ); ?>"
                                        style="color: #7c3aed; border-color: #ddd6fe;">
                                    <?php esc_html_e( '奖惩经验', 'qibbs-community' ); ?>
                                </button>
                                <button type="button" class="button button-small <?php echo $is_banned ? 'button-secondary' : 'button-link-delete'; ?> qibbs-open-ban-btn"
                                        data-user-id="<?php echo esc_attr( $uid ); ?>"
                                        data-user-name="<?php echo esc_attr( $row->display_name ?: $row->user_login ); ?>"
                                        data-is-banned="<?php echo $is_banned ? '1' : '0'; ?>"
                                        data-ban-reason="<?php echo esc_attr( $row->ban_reason ); ?>">
                                    <?php echo $is_banned ? esc_html__( '解封', 'qibbs-community' ) : esc_html__( '封禁', 'qibbs-community' ); ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- 分页条 -->
    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav bottom" style="margin-top: 15px;">
            <div class="tablenav-pages">
                <?php
                echo paginate_links( array(
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'prev_text' => __( '&laquo; 上一页', 'qibbs-community' ),
                    'next_text' => __( '下一页 &raquo;', 'qibbs-community' ),
                    'total'     => $total_pages,
                    'current'   => $paged,
                ) );
                ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- 4. 用户 360° 全维档案抽屉/模态卡片 (当选中用户时展示) -->
    <?php if ( $view_user_profile ) : ?>
        <div class="qibbs-user-drawer-backdrop" id="qibbsUserDrawer">
            <div class="qibbs-user-drawer-panel">
                <div class="qibbs-drawer-header">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <img src="<?php echo esc_url( $view_user_profile['avatar_url'] ); ?>" class="qibbs-drawer-avatar" alt="Avatar">
                        <div>
                            <h2 style="margin: 0; font-size: 18px;"><?php echo esc_html( $view_user_profile['display_name'] ); ?></h2>
                            <span style="font-size: 12px; color: #64748b;">@<?php echo esc_html( $view_user_profile['user_login'] ); ?> (ID: <?php echo esc_html( $view_user_profile['user_id'] ); ?>) · <?php echo esc_html( $view_user_profile['user_email'] ); ?></span>
                        </div>
                    </div>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=qibbs-members&filter=' . $filter . '&s=' . urlencode( $search ) ) ); ?>" class="qibbs-drawer-close">&times;</a>
                </div>

                <div class="qibbs-drawer-body">
                    <!-- 核心资金与身份 -->
                    <div class="qibbs-drawer-grid-summary">
                        <div class="qibbs-dgrid-item">
                            <span class="qibbs-dgrid-label"><?php esc_html_e( '当前激活佩戴号', 'qibbs-community' ); ?></span>
                            <span class="qibbs-dgrid-val" style="color: #2563eb; font-family: monospace; font-size: 18px;">
                                <?php echo esc_html( $view_user_profile['active_cuid'] ); ?>
                            </span>
                        </div>
                        <div class="qibbs-dgrid-item">
                            <span class="qibbs-dgrid-label"><?php esc_html_e( '系统初始保底号', 'qibbs-community' ); ?></span>
                            <span class="qibbs-dgrid-val" style="font-family: monospace; font-size: 18px; display: flex; align-items: center; justify-content: space-between;">
                                <span><?php echo esc_html( $view_user_profile['default_cuid'] ); ?></span>
                                <button type="button" class="button button-small qibbs-open-edit-uid-btn" style="font-size: 11px; padding: 0 8px; height: 24px; line-height: 22px;"
                                        data-user-id="<?php echo esc_attr( $view_user_id ); ?>"
                                        data-user-name="<?php echo esc_attr( $view_user_profile['display_name'] ); ?>"
                                        data-cuid="<?php echo esc_attr( $view_user_profile['default_cuid'] ); ?>">
                                    <?php esc_html_e( '修改UID', 'qibbs-community' ); ?>
                                </button>
                            </span>
                        </div>
                        <div class="qibbs-dgrid-item">
                            <span class="qibbs-dgrid-label"><?php esc_html_e( '商城可提现余额', 'qibbs-community' ); ?></span>
                            <span class="qibbs-dgrid-val" style="color: #059669; font-size: 18px;">
                                ¥ <?php echo number_format( $view_user_profile['balance'], 2 ); ?>
                            </span>
                        </div>
                        <div class="qibbs-dgrid-item">
                            <span class="qibbs-dgrid-label"><?php printf( esc_html__( '商城【%s】', 'qibbs-community' ), esc_html( function_exists( 'qibbs_get_points_name' ) ? qibbs_get_points_name() : __( '积分', 'qibbs-community' ) ) ); ?></span>
                            <span class="qibbs-dgrid-val" style="color: #d97706; font-size: 18px;">
                                <?php echo number_format_i18n( $view_user_profile['points'] ); ?>
                            </span>
                        </div>
                        <?php 
                        $drawer_level = function_exists( 'qibbs_get_user_growth_level' ) ? qibbs_get_user_growth_level( $view_user_id ) : null;
                        $drawer_exp   = function_exists( 'qibbs_get_user_exp' ) ? qibbs_get_user_exp( $view_user_id ) : 0;
                        ?>
                        <div class="qibbs-dgrid-item">
                            <span class="qibbs-dgrid-label"><?php esc_html_e( '社区成长等级', 'qibbs-community' ); ?></span>
                            <span class="qibbs-dgrid-val" style="color: #7c3aed; font-size: 15px; display: flex; align-items: center; justify-content: space-between;">
                                <span><svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" style="vertical-align:-1px;margin-right:2px;" aria-hidden="true"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>Lv.<?php echo esc_html( $drawer_level ? $drawer_level['level'] : 1 ); ?> <?php echo esc_html( $drawer_level ? $drawer_level['name'] : '见习萌新' ); ?></span>
                                <button type="button" class="button button-small qibbs-open-exp-adjust-btn" style="font-size: 11px; padding: 0 8px; height: 24px; line-height: 22px; color: #7c3aed; border-color: #ddd6fe;"
                                        data-user-id="<?php echo esc_attr( $view_user_id ); ?>"
                                        data-user-name="<?php echo esc_attr( $view_user_profile['display_name'] ); ?>"
                                        data-exp="<?php echo esc_attr( $drawer_exp ); ?>"
                                        data-level="<?php echo esc_attr( $drawer_level ? $drawer_level['level'] : 1 ); ?>"
                                        data-level-name="<?php echo esc_attr( $drawer_level ? $drawer_level['name'] : '见习萌新' ); ?>">
                                    <?php esc_html_e( '奖惩经验', 'qibbs-community' ); ?>
                                </button>
                            </span>
                        </div>
                        <div class="qibbs-dgrid-item">
                            <span class="qibbs-dgrid-label"><?php esc_html_e( '成长经验值', 'qibbs-community' ); ?></span>
                            <span class="qibbs-dgrid-val" style="color: #4f46e5; font-size: 18px;">
                                <?php echo number_format_i18n( $drawer_exp ); ?>
                            </span>
                        </div>
                    </div>

                    <!-- 靓号资产与佩戴管理 -->
                    <div class="qibbs-drawer-section">
                        <h3><?php esc_html_e( '持仓靓号资产管理', 'qibbs-community' ); ?></h3>
                        <?php if ( empty( $view_user_profile['backpack'] ) ) : ?>
                            <p style="color: #94a3b8;"><?php esc_html_e( '该用户暂未持有任何专属靓号。', 'qibbs-community' ); ?></p>
                        <?php else : ?>
                            <table class="widefat striped" style="margin-top: 8px;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( '号码 CUID', 'qibbs-community' ); ?></th>
                                        <th><?php esc_html_e( '品级', 'qibbs-community' ); ?></th>
                                        <th><?php esc_html_e( '状态', 'qibbs-community' ); ?></th>
                                        <th><?php esc_html_e( '寄售/原价', 'qibbs-community' ); ?></th>
                                        <th style="text-align: right;"><?php esc_html_e( '操作', 'qibbs-community' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $view_user_profile['backpack'] as $item ) : ?>
                                        <tr>
                                            <td style="font-family: monospace; font-size: 14px; font-weight: bold;">
                                                <?php echo esc_html( $item['cuid'] ); ?>
                                                <?php if ( $item['is_active'] ) : ?>
                                                    <span class="qibbs-badge-pill is-gold" style="font-size: 11px;"><?php esc_html_e( '佩戴中', 'qibbs-community' ); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html( $item['grade_label'] ); ?></td>
                                            <td>
                                                <?php if ( $item['is_c2c_listed'] ) : ?>
                                                    <span style="color: #d97706;"><?php esc_html_e( 'C2C 寄售中', 'qibbs-community' ); ?></span>
                                                <?php elseif ( $item['is_default'] ) : ?>
                                                    <span style="color: #64748b;"><?php esc_html_e( '系统保底号', 'qibbs-community' ); ?></span>
                                                <?php else : ?>
                                                    <span style="color: #059669;"><?php esc_html_e( '背包闲置', 'qibbs-community' ); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ( $item['is_c2c_listed'] ) : ?>
                                                    <?php echo $item['c2c_price_cash'] > 0 ? '¥' . number_format( $item['c2c_price_cash'], 2 ) : ''; ?>
                                                    <?php echo $item['c2c_price_points'] > 0 ? ( $item['c2c_price_cash'] > 0 ? ' / ' : '' ) . $item['c2c_price_points'] . __( '积分', 'qibbs-community' ) : ''; ?>
                                                <?php else : ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <?php if ( ! $item['is_active'] ) : ?>
                                                    <form method="post" style="display: inline-block;">
                                                        <?php wp_nonce_field( 'qibbs_member_equip_action', 'qibbs_member_nonce' ); ?>
                                                        <input type="hidden" name="equip_user_id" value="<?php echo esc_attr( $view_user_profile['user_id'] ); ?>">
                                                        <input type="hidden" name="equip_target_cuid" value="<?php echo esc_attr( $item['cuid'] ); ?>">
                                                        <button type="submit" name="qibbs_member_equip_cuid" class="button button-small">
                                                            <?php esc_html_e( '设为佩戴', 'qibbs-community' ); ?>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ( $item['is_c2c_listed'] ) : ?>
                                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=qibbs-members&view_user_id=' . $view_user_profile['user_id'] . '&delist_cuid=' . $item['cuid'] . '&seller_id=' . $view_user_profile['user_id'] ), 'qibbs_member_delist_' . $item['cuid'] ) ); ?>" 
                                                       onclick="return confirm('<?php echo esc_js( __( '确定强制将该号码从 C2C 寄售行下架吗？', 'qibbs-community' ) ); ?>');"
                                                       class="button button-small">
                                                        <?php esc_html_e( '强制下架', 'qibbs-community' ); ?>
                                                    </a>
                                                <?php endif; ?>

                                                <?php if ( $item['is_vanity'] ) : ?>
                                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=qibbs-members&view_user_id=' . $view_user_profile['user_id'] . '&reclaim_cuid=' . $item['cuid'] ), 'qibbs_member_reclaim_' . $item['cuid'] ) ); ?>" 
                                                       onclick="return confirm('<?php echo esc_js( __( '警告：确定强制收回该靓号至官方池吗？收回后用户将丧失该靓号所有权。', 'qibbs-community' ) ); ?>');"
                                                       class="button button-small button-link-delete" style="margin-left: 5px;">
                                                        <?php esc_html_e( '强制收回', 'qibbs-community' ); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <!-- 最近交易与流水 -->
                    <div class="qibbs-drawer-section">
                        <h3><?php esc_html_e( '近期交易订单记录', 'qibbs-community' ); ?></h3>
                        <?php if ( empty( $view_user_profile['recent_orders'] ) ) : ?>
                            <p style="color: #94a3b8;"><?php esc_html_e( '暂无原生商城/直购订单记录。', 'qibbs-community' ); ?></p>
                        <?php else : ?>
                            <table class="widefat striped" style="margin-top: 8px;">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( '订单号', 'qibbs-community' ); ?></th>
                                        <th><?php esc_html_e( '购买项目', 'qibbs-community' ); ?></th>
                                        <th><?php esc_html_e( '金额', 'qibbs-community' ); ?></th>
                                        <th><?php esc_html_e( '支付方式', 'qibbs-community' ); ?></th>
                                        <th><?php esc_html_e( '状态', 'qibbs-community' ); ?></th>
                                        <th><?php esc_html_e( '时间', 'qibbs-community' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $view_user_profile['recent_orders'] as $ord ) : ?>
                                        <tr>
                                            <td style="font-family: monospace; font-size: 12px;"><?php echo esc_html( $ord->order_sn ); ?></td>
                                            <td><?php echo esc_html( $ord->item_name ); ?></td>
                                            <td><strong>¥ <?php echo number_format( $ord->amount, 2 ); ?></strong></td>
                                            <td><?php echo esc_html( strtoupper( $ord->payment_method ) ); ?></td>
                                            <td>
                                                <span class="qibbs-status-badge <?php echo 'paid' === $ord->status ? 'is-active' : 'is-banned'; ?>">
                                                    <?php echo 'paid' === $ord->status ? esc_html__( '已支付', 'qibbs-community' ) : esc_html__( '未支付', 'qibbs-community' ); ?>
                                                </span>
                                            </td>
                                            <td style="color: #64748b; font-size: 12px;"><?php echo esc_html( $ord->created_at ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 5. 人工调账模态对话框 -->
    <div class="qibbs-modal-wrap" id="qibbsAdjustModal" style="display: none;">
        <div class="qibbs-modal-box">
            <div class="qibbs-modal-header">
                <h3><?php esc_html_e( '启灵商城【可提现余额】人工调账', 'qibbs-community' ); ?></h3>
                <span class="qibbs-modal-close" onclick="document.getElementById('qibbsAdjustModal').style.display='none'">&times;</span>
            </div>
            <form method="post" action="">
                <?php wp_nonce_field( 'qibbs_member_adjust_action', 'qibbs_member_nonce' ); ?>
                <input type="hidden" name="adjust_user_id" id="modalAdjustUserId" value="">
                
                <div class="qibbs-modal-body">
                    <p>
                        <label><?php esc_html_e( '目标用户：', 'qibbs-community' ); ?></label>
                        <strong id="modalAdjustUserName" style="font-size: 15px; color: #1e293b;">-</strong>
                    </p>
                    <p>
                        <label><?php esc_html_e( '当前商城可提现余额：', 'qibbs-community' ); ?></label>
                        <strong id="modalAdjustUserBalance" style="color: #059669; font-size: 15px;">¥ 0.00</strong>
                    </p>

                    <div style="margin: 15px 0;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( '调账类型：', 'qibbs-community' ); ?></label>
                        <label style="margin-right: 15px;">
                            <input type="radio" name="adjust_type" value="add" checked> <?php esc_html_e( '增加余额 (充值/补发)', 'qibbs-community' ); ?>
                        </label>
                        <label>
                            <input type="radio" name="adjust_type" value="deduct"> <?php esc_html_e( '扣除余额 (违规回收/扣款)', 'qibbs-community' ); ?>
                        </label>
                    </div>

                    <div style="margin: 15px 0;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( '变动金额 (元)：', 'qibbs-community' ); ?></label>
                        <input type="number" step="0.01" min="0.01" name="adjust_amount" required class="regular-text" style="width: 100%; font-size: 16px; padding: 6px;" placeholder="0.00">
                    </div>

                    <div style="margin: 15px 0;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( '操作备注 (将通过站内信同步告知用户)：', 'qibbs-community' ); ?></label>
                        <textarea name="adjust_note" rows="3" style="width: 100%;" placeholder="<?php esc_attr_e( '例如：社区活动获胜奖励 / 人工线下提现扣减', 'qibbs-community' ); ?>" required></textarea>
                    </div>
                </div>

                <div class="qibbs-modal-footer">
                    <button type="button" class="button" onclick="document.getElementById('qibbsAdjustModal').style.display='none'"><?php esc_html_e( '取消', 'qibbs-community' ); ?></button>
                    <button type="submit" name="qibbs_member_adjust_balance" class="button button-primary"><?php esc_html_e( '确认调账并记录流水', 'qibbs-community' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- 6. 快捷封禁/解封治理模态对话框 -->
    <div class="qibbs-modal-wrap" id="qibbsBanModal" style="display: none;">
        <div class="qibbs-modal-box">
            <div class="qibbs-modal-header">
                <h3 id="modalBanTitle"><?php esc_html_e( '账号黑名单治理', 'qibbs-community' ); ?></h3>
                <span class="qibbs-modal-close" onclick="document.getElementById('qibbsBanModal').style.display='none'">&times;</span>
            </div>
            <form method="post" action="">
                <?php wp_nonce_field( 'qibbs_member_ban_action', 'qibbs_member_nonce' ); ?>
                <input type="hidden" name="ban_user_id" id="modalBanUserId" value="">
                <input type="hidden" name="ban_action" id="modalBanAction" value="ban">

                <div class="qibbs-modal-body">
                    <p>
                        <label><?php esc_html_e( '目标用户：', 'qibbs-community' ); ?></label>
                        <strong id="modalBanUserName" style="font-size: 15px; color: #1e293b;">-</strong>
                    </p>

                    <div id="modalBanReasonBlock" style="margin: 15px 0;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( '封禁原因 (限制其发帖、评论、私信等交互权限)：', 'qibbs-community' ); ?></label>
                        <textarea name="ban_reason" id="modalBanReasonInput" rows="3" style="width: 100%;" placeholder="<?php esc_attr_e( '请填写拉黑封禁的详细原因，方便后续风控溯源', 'qibbs-community' ); ?>"></textarea>
                    </div>

                    <p id="modalUnbanTip" style="color: #059669; display: none;">
                        <?php esc_html_e( '解封后，该用户在社区的发帖、评论、私信与交易权限将全面恢复正常。', 'qibbs-community' ); ?>
                    </p>
                </div>

                <div class="qibbs-modal-footer">
                    <button type="button" class="button" onclick="document.getElementById('qibbsBanModal').style.display='none'"><?php esc_html_e( '取消', 'qibbs-community' ); ?></button>
                    <button type="submit" name="qibbs_member_toggle_ban" id="modalBanSubmitBtn" class="button button-primary"><?php esc_html_e( '确定', 'qibbs-community' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- 7. 修改用户UID模态对话框 -->
    <div class="qibbs-modal-wrap" id="qibbsEditUidModal" style="display: none;">
        <div class="qibbs-modal-box">
            <div class="qibbs-modal-header">
                <h3><?php esc_html_e( '人工指定修改用户社区 UID', 'qibbs-community' ); ?></h3>
                <span class="qibbs-modal-close" onclick="document.getElementById('qibbsEditUidModal').style.display='none'">&times;</span>
            </div>
            <form method="post" action="">
                <?php wp_nonce_field( 'qibbs_member_edit_cuid_action', 'qibbs_member_nonce' ); ?>
                <input type="hidden" name="edit_user_id" id="modalEditUidUserId" value="">

                <div class="qibbs-modal-body">
                    <p>
                        <label><?php esc_html_e( '目标用户：', 'qibbs-community' ); ?></label>
                        <strong id="modalEditUidUserName" style="font-size: 15px; color: #1e293b;">-</strong>
                    </p>
                    <p>
                        <label><?php esc_html_e( '当前保底 UID：', 'qibbs-community' ); ?></label>
                        <strong id="modalEditUidCurrentCuid" style="font-family: monospace; font-size: 15px; color: #64748b;">-</strong>
                    </p>

                    <div style="margin: 15px 0;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( '新分配 UID 号码：', 'qibbs-community' ); ?></label>
                        <input type="number" min="1" step="1" name="new_cuid" id="modalEditUidNewInput" required class="regular-text" style="width: 100%; font-size: 16px; padding: 6px; font-family: monospace;" placeholder="<?php esc_attr_e( '例如：100000 或 101', 'qibbs-community' ); ?>">
                        <span class="description" style="display: block; margin-top: 4px; color: #64748b;">
                            <?php esc_html_e( '提示：支持设置任意未被占用的数字号码（如创始管理员 100000 或 101 等）。', 'qibbs-community' ); ?>
                        </span>
                    </div>

                    <div style="margin: 15px 0;">
                        <label>
                            <input type="checkbox" name="set_active_also" value="1" checked> 
                            <?php esc_html_e( '同时将其设置为当前对外激活佩戴的社区显示 UID', 'qibbs-community' ); ?>
                        </label>
                    </div>
                </div>

                <div class="qibbs-modal-footer">
                    <button type="button" class="button" onclick="document.getElementById('qibbsEditUidModal').style.display='none'"><?php esc_html_e( '取消', 'qibbs-community' ); ?></button>
                    <button type="submit" name="qibbs_member_edit_cuid" class="button button-primary"><?php esc_html_e( '确认保存修改', 'qibbs-community' ); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- 8. 成长经验值奖惩调整模态对话框 -->
    <div class="qibbs-modal-wrap" id="qibbsExpAdjustModal" style="display: none;">
        <div class="qibbs-modal-box">
            <div class="qibbs-modal-header">
                <h3><?php esc_html_e( '成长经验值管理与奖惩调整', 'qibbs-community' ); ?></h3>
                <span class="qibbs-modal-close" onclick="document.getElementById('qibbsExpAdjustModal').style.display='none'">&times;</span>
            </div>
            <form method="post" action="">
                <?php wp_nonce_field( 'qibbs_member_adjust_exp_action', 'qibbs_member_nonce' ); ?>
                <input type="hidden" name="adjust_exp_user_id" id="modalAdjustExpUserId" value="">

                <div class="qibbs-modal-body">
                    <p>
                        <label><?php esc_html_e( '目标用户：', 'qibbs-community' ); ?></label>
                        <strong id="modalAdjustExpUserName" style="font-size: 15px; color: #1e293b;">-</strong>
                    </p>
                    <p>
                        <label><?php esc_html_e( '当前等级与经验：', 'qibbs-community' ); ?></label>
                        <strong id="modalAdjustExpCurrentInfo" style="color: #7c3aed; font-size: 15px;">Lv.1 (0 经验值)</strong>
                    </p>

                    <div style="margin: 15px 0;">
                        <label style="font-weight: 600; display: block; margin-bottom: 8px;"><?php esc_html_e( '操作类型：', 'qibbs-community' ); ?></label>
                        <label style="margin-right: 15px; color: #dc2626; font-weight: 600;">
                            <input type="radio" name="adjust_exp_type" value="deduct" checked> <?php esc_html_e( '惩罚扣除 (违规灌水/处罚降级)', 'qibbs-community' ); ?>
                        </label>
                        <label style="margin-right: 15px; color: #059669;">
                            <input type="radio" name="adjust_exp_type" value="add"> <?php esc_html_e( '特别奖励 (突出贡献补发)', 'qibbs-community' ); ?>
                        </label>
                        <label style="color: #2563eb;">
                            <input type="radio" name="adjust_exp_type" value="set"> <?php esc_html_e( '直接重置经验值', 'qibbs-community' ); ?>
                        </label>
                    </div>

                    <div style="margin: 15px 0;">
                        <label id="modalAdjustExpAmountLabel" style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( '惩罚扣除经验点数：', 'qibbs-community' ); ?></label>
                        <input type="number" min="0" step="1" name="adjust_exp_amount" id="modalAdjustExpAmount" required class="regular-text" style="width: 100%; font-size: 16px; padding: 6px;" placeholder="<?php esc_attr_e( '输入经验值点数，如 50', 'qibbs-community' ); ?>">
                        <span class="description" id="modalAdjustExpAmountDesc" style="display: block; margin-top: 4px; color: #64748b;">
                            <?php esc_html_e( '扣除后经验值最低截断为 0，若低于当前等级所需门槛将自动触发降级并更新全站标牌。', 'qibbs-community' ); ?>
                        </span>
                    </div>

                    <div style="margin: 15px 0;">
                        <label style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( '操作原因 / 惩处说明：', 'qibbs-community' ); ?></label>
                        <textarea name="adjust_exp_note" rows="3" style="width: 100%;" placeholder="<?php esc_attr_e( '例如：社区灌水违规处罚扣除 100 经验值 / 活跃答疑突出贡献奖励', 'qibbs-community' ); ?>" required></textarea>
                    </div>
                </div>

                <div class="qibbs-modal-footer">
                    <button type="button" class="button" onclick="document.getElementById('qibbsExpAdjustModal').style.display='none'"><?php esc_html_e( '取消', 'qibbs-community' ); ?></button>
                    <button type="submit" name="qibbs_member_adjust_exp" id="modalAdjustExpSubmitBtn" class="button button-primary button-link-delete"><?php esc_html_e( '确认扣除经验', 'qibbs-community' ); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.qibbs-members-wrap {
    margin-top: 15px;
}
.qibbs-member-stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin: 20px 0;
}
.qibbs-mstat-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}
.qibbs-mstat-icon {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.qibbs-mstat-info {
    display: flex;
    flex-direction: column;
}
.qibbs-mstat-label {
    font-size: 13px;
    color: #64748b;
    margin-bottom: 4px;
}
.qibbs-mstat-val {
    font-size: 20px;
    font-weight: 700;
    color: #0f172a;
}
.qibbs-member-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    background: #fff;
    padding: 10px 16px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
}
.qibbs-user-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}
.qibbs-user-avatar {
    border-radius: 50%;
    flex-shrink: 0;
}
.qibbs-user-meta-wrap {
    display: flex;
    flex-direction: column;
    line-height: 1.4;
}
.qibbs-sub-meta {
    font-size: 12px;
    color: #64748b;
}
.qibbs-uid-tag {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    margin-right: 4px;
}
.qibbs-uid-tag.is-default {
    background: #f1f5f9;
    color: #475569;
}
.qibbs-uid-tag.is-vanity {
    background: #fef3c7;
    color: #b45309;
    border: 1px solid #fde68a;
}
.qibbs-badge-pill {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 10px;
    font-size: 11px;
    background: #f1f5f9;
    color: #64748b;
}
.qibbs-badge-pill.is-gold {
    background: #fef3c7;
    color: #b45309;
    font-weight: bold;
}
.qibbs-status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 500;
}
.qibbs-status-badge.is-active {
    background: #dcfce7;
    color: #166534;
}
.qibbs-status-badge.is-banned {
    background: #fee2e2;
    color: #991b1b;
}
.qibbs-action-group {
    display: inline-flex;
    gap: 6px;
}

/* 抽屉样式 */
.qibbs-user-drawer-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.45);
    z-index: 99999;
    display: flex;
    justify-content: flex-end;
}
.qibbs-user-drawer-panel {
    width: 620px;
    max-width: 90vw;
    height: 100%;
    background: #fff;
    box-shadow: -4px 0 24px rgba(0,0,0,0.15);
    display: flex;
    flex-direction: column;
    animation: qibbsSlideIn 0.25s ease-out;
}
@keyframes qibbsSlideIn {
    from { transform: translateX(100%); }
    to { transform: translateX(0); }
}
.qibbs-drawer-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8fafc;
}
.qibbs-drawer-avatar {
    width: 52px;
    height: 52px;
    border-radius: 50%;
}
.qibbs-drawer-close {
    font-size: 26px;
    text-decoration: none;
    color: #64748b;
    line-height: 1;
}
.qibbs-drawer-close:hover {
    color: #0f172a;
}
.qibbs-drawer-body {
    padding: 24px;
    overflow-y: auto;
    flex: 1;
}
.qibbs-drawer-grid-summary {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 24px;
}
.qibbs-dgrid-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px 16px;
    display: flex;
    flex-direction: column;
}
.qibbs-dgrid-label {
    font-size: 12px;
    color: #64748b;
    margin-bottom: 4px;
}
.qibbs-dgrid-val {
    font-weight: 700;
}
.qibbs-drawer-section {
    margin-top: 24px;
}
.qibbs-drawer-section h3 {
    margin: 0 0 10px 0;
    font-size: 15px;
    color: #1e293b;
    border-left: 3px solid #2563eb;
    padding-left: 8px;
}

/* 模态弹窗 */
.qibbs-modal-wrap {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(15, 23, 42, 0.5);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.qibbs-modal-box {
    background: #fff;
    width: 480px;
    max-width: 90vw;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    overflow: hidden;
}
.qibbs-modal-header {
    padding: 16px 20px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.qibbs-modal-header h3 {
    margin: 0;
    font-size: 16px;
}
.qibbs-modal-close {
    font-size: 22px;
    cursor: pointer;
    color: #64748b;
    line-height: 1;
}
.qibbs-modal-body {
    padding: 20px;
}
.qibbs-modal-footer {
    padding: 12px 20px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 调账弹窗交互
    const adjustModal = document.getElementById('qibbsAdjustModal');
    const adjustBtns = document.querySelectorAll('.qibbs-open-adjust-btn');
    adjustBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const uid = this.dataset.userId;
            const name = this.dataset.userName;
            const bal = parseFloat(this.dataset.balance || 0).toFixed(2);

            document.getElementById('modalAdjustUserId').value = uid;
            document.getElementById('modalAdjustUserName').textContent = name + ' (ID: ' + uid + ')';
            document.getElementById('modalAdjustUserBalance').textContent = '¥ ' + bal;
            adjustModal.style.display = 'flex';
        });
    });

    // 封禁/解封弹窗交互
    const banModal = document.getElementById('qibbsBanModal');
    const banBtns = document.querySelectorAll('.qibbs-open-ban-btn');
    banBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const uid = this.dataset.userId;
            const name = this.dataset.userName;
            const isBanned = this.dataset.isBanned === '1';
            const reason = this.dataset.banReason || '';

            document.getElementById('modalBanUserId').value = uid;
            document.getElementById('modalBanUserName').textContent = name + ' (ID: ' + uid + ')';
            
            if (isBanned) {
                document.getElementById('modalBanTitle').textContent = '<?php echo esc_js( __( '解除用户封禁', 'qibbs-community' ) ); ?>';
                document.getElementById('modalBanAction').value = 'unban';
                document.getElementById('modalBanReasonBlock').style.display = 'none';
                document.getElementById('modalUnbanTip').style.display = 'block';
                document.getElementById('modalBanSubmitBtn').textContent = '<?php echo esc_js( __( '确认解除黑名单', 'qibbs-community' ) ); ?>';
                document.getElementById('modalBanSubmitBtn').className = 'button button-primary';
            } else {
                document.getElementById('modalBanTitle').textContent = '<?php echo esc_js( __( '加入黑名单封禁', 'qibbs-community' ) ); ?>';
                document.getElementById('modalBanAction').value = 'ban';
                document.getElementById('modalBanReasonBlock').style.display = 'block';
                document.getElementById('modalUnbanTip').style.display = 'none';
                document.getElementById('modalBanReasonInput').value = reason;
                document.getElementById('modalBanSubmitBtn').textContent = '<?php echo esc_js( __( '确认封禁该用户', 'qibbs-community' ) ); ?>';
                document.getElementById('modalBanSubmitBtn').className = 'button button-primary button-link-delete';
            }

            banModal.style.display = 'flex';
        });
    });

    // 修改UID弹窗交互
    const editUidModal = document.getElementById('qibbsEditUidModal');
    const editUidBtns = document.querySelectorAll('.qibbs-open-edit-uid-btn');
    editUidBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const uid = this.dataset.userId;
            const name = this.dataset.userName;
            const cuid = this.dataset.cuid || '';

            document.getElementById('modalEditUidUserId').value = uid;
            document.getElementById('modalEditUidUserName').textContent = name + ' (ID: ' + uid + ')';
            document.getElementById('modalEditUidCurrentCuid').textContent = cuid;
            document.getElementById('modalEditUidNewInput').value = cuid;
            editUidModal.style.display = 'flex';
        });
    });

    // 经验值奖惩调整弹窗交互
    const expModal = document.getElementById('qibbsExpAdjustModal');
    const expBtns = document.querySelectorAll('.qibbs-open-exp-adjust-btn');
    const expTypeRadios = document.querySelectorAll('input[name="adjust_exp_type"]');
    const expAmountLabel = document.getElementById('modalAdjustExpAmountLabel');
    const expAmountDesc = document.getElementById('modalAdjustExpAmountDesc');
    const expSubmitBtn = document.getElementById('modalAdjustExpSubmitBtn');

    function updateExpModalState(type) {
        if (!expAmountLabel || !expAmountDesc || !expSubmitBtn) return;
        if (type === 'deduct') {
            expAmountLabel.textContent = '<?php echo esc_js( __( '惩罚扣除经验点数：', 'qibbs-community' ) ); ?>';
            expAmountDesc.textContent = '<?php echo esc_js( __( '扣除后经验值最低截断为 0，若低于当前等级门槛将自动降级并重算标牌。', 'qibbs-community' ) ); ?>';
            expSubmitBtn.textContent = '<?php echo esc_js( __( '确认扣除经验', 'qibbs-community' ) ); ?>';
            expSubmitBtn.className = 'button button-primary button-link-delete';
        } else if (type === 'add') {
            expAmountLabel.textContent = '<?php echo esc_js( __( '特别奖励增加经验点数：', 'qibbs-community' ) ); ?>';
            expAmountDesc.textContent = '<?php echo esc_js( __( '增加后将自动计算是否满足升级条件，升级时触发全站广播与升级钩子。', 'qibbs-community' ) ); ?>';
            expSubmitBtn.textContent = '<?php echo esc_js( __( '确认奖励经验', 'qibbs-community' ) ); ?>';
            expSubmitBtn.className = 'button button-primary';
        } else if (type === 'set') {
            expAmountLabel.textContent = '<?php echo esc_js( __( '直接重置目标总经验点数：', 'qibbs-community' ) ); ?>';
            expAmountDesc.textContent = '<?php echo esc_js( __( '将该用户的总成长经验值直接设定为此数值，系统将重新校准其对应的等级。', 'qibbs-community' ) ); ?>';
            expSubmitBtn.textContent = '<?php echo esc_js( __( '确认重置设定', 'qibbs-community' ) ); ?>';
            expSubmitBtn.className = 'button button-primary';
        }
    }

    if (expTypeRadios.length) {
        expTypeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                updateExpModalState(this.value);
            });
        });
    }

    if (expBtns.length) {
        expBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const uid = this.dataset.userId;
                const name = this.dataset.userName;
                const exp = this.dataset.exp || '0';
                const lvl = this.dataset.level || '1';
                const lvlName = this.dataset.levelName || '见习萌新';

                document.getElementById('modalAdjustExpUserId').value = uid;
                document.getElementById('modalAdjustExpUserName').textContent = name + ' (ID: ' + uid + ')';
                document.getElementById('modalAdjustExpCurrentInfo').textContent = 'Lv.' + lvl + ' ' + lvlName + ' (' + Number(exp).toLocaleString() + ' <?php echo esc_js( __( '经验值', 'qibbs-community' ) ); ?>)';

                const deductRadio = document.querySelector('input[name="adjust_exp_type"][value="deduct"]');
                if (deductRadio) {
                    deductRadio.checked = true;
                    updateExpModalState('deduct');
                }
                document.getElementById('modalAdjustExpAmount').value = '';

                expModal.style.display = 'flex';
            });
        });
    }
});
</script>
