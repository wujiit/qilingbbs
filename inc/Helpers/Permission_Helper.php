<?php
/**
 * 权限控制、黑名单隔离与冷却时间辅助函数
 *
 * @package Qibbs_Community
 * @subpackage Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 将发帖权限设置项映射为 WordPress 用户权限 Capability
 *
 * @param string $permission Stored permission key.
 * @return string Empty string means any logged-in user.
 */
function qibbs_get_post_permission_capability( $permission ) {
    $permission_map = array(
        'logged_in'   => '',
        'subscriber'  => 'read',
        'contributor' => 'edit_posts',
        'author'      => 'publish_posts',
    );

    $permission = sanitize_key( (string) $permission );
    return isset( $permission_map[ $permission ] ) ? $permission_map[ $permission ] : '';
}

/**
 * 校验用户是否具备发帖权限
 *
 * @param int $user_id User ID.
 * @return bool
 */
function qibbs_user_can_post( $user_id = 0 ) {
    $user_id = absint( $user_id );
    if ( $user_id <= 0 ) {
        $user_id = get_current_user_id();
    }

    if ( $user_id <= 0 ) {
        return false;
    }

    $capability = qibbs_get_post_permission_capability( qibbs_get_option( 'who_can_post', 'logged_in' ) );
    if ( '' === $capability ) {
        return true;
    }

    return user_can( $user_id, $capability );
}

/**
 * 获取微社区支持的帖子类型列表
 *
 * @return array
 */
function qibbs_get_post_type_options() {
    $types = array(
        'discussion'   => array(
            'label' => __( '普通帖', 'qibbs-community' ),
        ),
        'bounty'       => array(
            'label' => __( '悬赏帖', 'qibbs-community' ),
        ),
        'redpacket'    => array(
            'label' => __( '红包帖', 'qibbs-community' ),
        ),
        'question'     => array(
            'label' => __( '提问帖', 'qibbs-community' ),
        ),
        'experience'   => array(
            'label' => __( '经验帖', 'qibbs-community' ),
        ),
        'announcement' => array(
            'label' => __( '公告帖', 'qibbs-community' ),
        ),
        'activity'     => array(
            'label' => __( '活动帖', 'qibbs-community' ),
        ),
    );

    if ( ! qibbs_is_bounty_enabled() ) {
        unset( $types['bounty'] );
    }
    if ( ! qibbs_is_redpacket_enabled() ) {
        unset( $types['redpacket'] );
    }

    return (array) apply_filters( 'qibbs_post_type_options', $types );
}

/**
 * 检查悬赏功能是否开启
 *
 * @return bool
 */
function qibbs_is_bounty_enabled() {
    return class_exists( 'Qibbs_Bounty_Service' ) && Qibbs_Bounty_Service::instance()->is_enabled();
}

/**
 * 检查红包功能是否开启
 *
 * @return bool
 */
function qibbs_is_redpacket_enabled() {
    return class_exists( 'Qibbs_Redpacket_Service' ) && Qibbs_Redpacket_Service::instance()->is_enabled();
}

/**
 * 检查道具大厅功能是否开启
 *
 * @return bool
 */
function qibbs_is_prop_mall_enabled() {
    return class_exists( 'Qibbs_Prop_Service' ) && Qibbs_Prop_Service::instance()->is_enabled();
}

/**
 * 获取指定用户的当前有效昵称颜色（带过期判断与自动清理）
 *
 * @param int $user_id User ID.
 * @return string Hex color string or empty string.
 */
function qibbs_get_user_name_color( $user_id ) {
    $user_id = absint( $user_id );
    if ( ! $user_id ) {
        return '';
    }

    $expires = (int) get_user_meta( $user_id, 'qibbs_name_color_expires', true );
    if ( $expires > 0 && $expires < time() ) {
        delete_user_meta( $user_id, 'qibbs_active_name_color' );
        delete_user_meta( $user_id, 'qibbs_name_color_expires' );
        return '';
    }

    $color = get_user_meta( $user_id, 'qibbs_active_name_color', true );
    return $color ? sanitize_hex_color( $color ) : '';
}

/**
 * 获取指定帖子的悬赏数据
 *
 * @param int $post_id Post ID.
 * @return object|null
 */
function qibbs_get_bounty( $post_id ) {
    return class_exists( 'Qibbs_Bounty_Service' ) ? Qibbs_Bounty_Service::instance()->get_bounty( $post_id ) : null;
}

/**
 * 获取指定帖子的红包数据
 *
 * @param int $post_id Post ID.
 * @return object|null
 */
function qibbs_get_redpacket( $post_id ) {
    return class_exists( 'Qibbs_Redpacket_Service' ) ? Qibbs_Redpacket_Service::instance()->get_redpacket( $post_id ) : null;
}

/**
 * 检查用户是否已领取过指定红包
 *
 * @param int $redpacket_id Redpacket ID.
 * @param int $user_id      User ID (0 for current user).
 * @return bool
 */
function qibbs_has_user_claimed_redpacket( $redpacket_id, $user_id = 0 ) {
    $user_id = $user_id ? absint( $user_id ) : get_current_user_id();
    if ( ! $user_id || ! class_exists( 'Qibbs_Redpacket_Service' ) ) {
        return false;
    }
    return Qibbs_Redpacket_Service::instance()->has_user_claimed( $redpacket_id, $user_id );
}

/**
 * 标准化帖子类型标识
 *
 * @param string $post_type Raw post type.
 * @return string
 */
function qibbs_normalize_post_type( $post_type ) {
    $post_type = sanitize_key( (string) $post_type );
    $types     = qibbs_get_post_type_options();

    if ( isset( $types[ $post_type ] ) ) {
        return $post_type;
    }

    return 'discussion';
}

/**
 * 获取帖子类型的中文显示名称
 *
 * @param string $post_type Post type key.
 * @return string
 */
function qibbs_get_post_type_label( $post_type ) {
    $post_type = qibbs_normalize_post_type( $post_type );
    $types     = qibbs_get_post_type_options();

    if ( isset( $types[ $post_type ]['label'] ) ) {
        return (string) $types[ $post_type ]['label'];
    }

    return (string) __( '普通帖', 'qibbs-community' );
}

/**
 * 获取当前登录用户 ID
 *
 * @return int User ID or 0 if not logged in.
 */
function qibbs_get_current_user_id() {
    return get_current_user_id();
}

/**
 * 检查当前访问用户是否已登录
 *
 * @return bool
 */
function qibbs_is_user_logged_in() {
    return is_user_logged_in();
}

/**
 * 检查是否允许游客免登录浏览社区内容
 *
 * 默认要求登录访问，支持通过过滤器覆写
 *
 * @return bool
 */
function qibbs_allow_guest_access() {
    $allow = (bool) qibbs_get_option( 'allow_guest_access', false );
    return (bool) apply_filters( 'qibbs_allow_guest_access', $allow );
}

/**
 * 检查社区前台与接口是否强制要求登录态
 *
 * @return bool
 */
function qibbs_require_login() {
    return ! qibbs_allow_guest_access();
}

/**
 * 获取黑名单用户限制模式（只读浏览或彻底禁止访问）
 *
 * Modes:
 * read_only：黑名单用户可只读浏览，但禁止发帖/评论/私信
 * deny_all：彻底禁止黑名单用户访问社区页面与接口
 *
 * @return string
 */
function qibbs_get_blacklist_access_mode() {
    $mode = qibbs_get_option( 'blacklist_access_mode', 'read_only' );
    return in_array( $mode, array( 'read_only', 'deny_all' ), true ) ? $mode : 'read_only';
}

/**
 * 检查用户是否被列入微社区黑名单
 *
 * @param int $user_id User ID, defaults to current user.
 * @return bool
 */
function qibbs_is_user_blacklisted( $user_id = 0 ) {
    $user_id = absint( $user_id );
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    if ( ! $user_id ) {
        return false;
    }

    static $cache = array();
    if ( isset( $cache[ $user_id ] ) ) {
        return (bool) $cache[ $user_id ];
    }

    global $wpdb;
    if ( ! class_exists( 'Qibbs_Database' ) ) {
        $cache[ $user_id ] = false;
        return false;
    }

    $blacklist_table = Qibbs_Database::blacklist();
    $exists = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$blacklist_table} WHERE user_id = %d LIMIT 1",
            $user_id
        )
    );

    $cache[ $user_id ] = $exists > 0;
    return (bool) $cache[ $user_id ];
}

/**
 * 检查是否开启了黑名单彻底阻断访问模式
 *
 * @param int $user_id User ID, defaults to current user.
 * @return bool
 */
function qibbs_blacklist_denies_access( $user_id = 0 ) {
    return qibbs_is_user_blacklisted( $user_id ) && 'deny_all' === qibbs_get_blacklist_access_mode();
}

/**
 * 彻底阻断模式下拦截黑名单用户的 AJAX 请求
 */
function qibbs_maybe_block_blacklisted_ajax() {
    if ( ! function_exists( 'wp_doing_ajax' ) || ! wp_doing_ajax() ) {
        return;
    }

    if ( ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( ! qibbs_blacklist_denies_access( get_current_user_id() ) ) {
        return;
    }

    $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
    if ( '' === $action || 0 !== strpos( $action, 'qibbs_' ) ) {
        return;
    }

    wp_send_json_error(
        array(
            'message' => __( '你的账号已被限制访问社区功能', 'qibbs-community' ),
        ),
        403
    );
}
add_action( 'init', 'qibbs_maybe_block_blacklisted_ajax', 0 );

/**
 * 彻底阻断模式下拦截黑名单用户的 REST 接口请求
 *
 * @param mixed           $result  Existing result.
 * @param WP_REST_Server  $server  Server instance.
 * @param WP_REST_Request $request Request instance.
 * @return mixed
 */
function qibbs_maybe_block_blacklisted_rest( $result, $server, $request ) {
    if ( is_wp_error( $result ) || ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
        return $result;
    }

    if ( ! qibbs_blacklist_denies_access( get_current_user_id() ) ) {
        return $result;
    }

    $route = $request instanceof WP_REST_Request ? $request->get_route() : '';
    if ( ! is_string( $route ) || 0 !== strpos( $route, '/qibbs/v1/' ) ) {
        return $result;
    }

    return new WP_Error(
        'qibbs_blacklisted_forbidden',
        __( '你的账号已被限制访问社区功能', 'qibbs-community' ),
        array( 'status' => 403 )
    );
}
add_filter( 'rest_pre_dispatch', 'qibbs_maybe_block_blacklisted_rest', 8, 3 );

/**
 * 拦截黑名单用户的发帖权限
 *
 * @param bool $can_post Existing permission.
 * @param int  $user_id  User ID.
 * @return bool
 */
function qibbs_filter_can_create_post_for_blacklist( $can_post, $user_id ) {
    if ( ! $can_post ) {
        return false;
    }

    $user_id = absint( $user_id );
    if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
        return $can_post;
    }

    return ! qibbs_is_user_blacklisted( $user_id );
}
add_filter( 'qibbs_can_create_post', 'qibbs_filter_can_create_post_for_blacklist', 10, 2 );

/**
 * 获取用户发帖冷却倒计时截止时间
 *
 * @param int $user_id User ID.
 * @return string
 */
function qibbs_get_user_post_cooldown_until( $user_id ) {
    $user_id = absint( $user_id );
    if ( ! $user_id ) {
        return '';
    }

    $value = get_user_meta( $user_id, '_qibbs_post_cooldown_until', true );
    return is_string( $value ) ? trim( $value ) : '';
}

/**
 * 检查用户当前是否仍处于发帖冷却限制中
 *
 * @param int $user_id User ID.
 * @return bool
 */
function qibbs_user_has_post_cooldown( $user_id ) {
    $cooldown_until = qibbs_get_user_post_cooldown_until( $user_id );
    if ( '' === $cooldown_until ) {
        return false;
    }

    $expires_at = qibbs_datetime_to_timestamp( $cooldown_until );
    return $expires_at && $expires_at > qibbs_current_timestamp();
}

/**
 * 设置用户发帖频率冷却限制
 *
 * @param int          $user_id User ID.
 * @param string|int   $until   Expiry datetime or timestamp.
 * @return bool
 */
function qibbs_set_user_post_cooldown( $user_id, $until ) {
    $user_id = absint( $user_id );
    if ( ! $user_id ) {
        return false;
    }

    if ( is_numeric( $until ) ) {
        $until = qibbs_format_datetime( 'Y-m-d H:i:s', (int) $until );
    } else {
        $until = sanitize_text_field( (string) $until );
    }

    if ( '' === $until || ! qibbs_datetime_to_timestamp( $until ) ) {
        return false;
    }

    return false !== update_user_meta( $user_id, '_qibbs_post_cooldown_until', $until );
}

/**
 * 解除用户的发帖冷却限制
 *
 * @param int $user_id User ID.
 * @return bool
 */
function qibbs_clear_user_post_cooldown( $user_id ) {
    $user_id = absint( $user_id );
    if ( ! $user_id ) {
        return false;
    }

    return (bool) delete_user_meta( $user_id, '_qibbs_post_cooldown_until' );
}

/**
 * 拦截处于发帖冷却期用户的发帖请求
 *
 * @param bool $can_post Existing permission.
 * @param int  $user_id  User ID.
 * @return bool
 */
function qibbs_filter_can_create_post_for_cooldown( $can_post, $user_id ) {
    if ( ! $can_post ) {
        return false;
    }

    $user_id = absint( $user_id );
    if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
        return $can_post;
    }

    return ! qibbs_user_has_post_cooldown( $user_id );
}
add_filter( 'qibbs_can_create_post', 'qibbs_filter_can_create_post_for_cooldown', 20, 2 );

/**
 * 拦截黑名单用户的评论权限
 *
 * @param bool $can_comment Existing permission.
 * @param int  $user_id     User ID.
 * @param int  $post_id     Post ID.
 * @return bool
 */
function qibbs_filter_can_comment_for_blacklist( $can_comment, $user_id, $post_id ) {
    if ( ! $can_comment ) {
        return false;
    }

    $user_id = absint( $user_id );
    if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
        return $can_comment;
    }

    return ! qibbs_is_user_blacklisted( $user_id );
}
add_filter( 'qibbs_can_comment', 'qibbs_filter_can_comment_for_blacklist', 10, 3 );

/**
 * 拦截黑名单用户的私信权限
 *
 * @param bool $can_send    Existing permission.
 * @param int  $sender_id   Sender ID.
 * @param int  $receiver_id Receiver ID.
 * @return bool
 */
function qibbs_filter_can_send_message_for_blacklist( $can_send, $sender_id, $receiver_id ) {
    if ( ! $can_send ) {
        return false;
    }

    $sender_id = absint( $sender_id );
    if ( ! $sender_id || user_can( $sender_id, 'manage_options' ) ) {
        return $can_send;
    }

    return ! qibbs_is_user_blacklisted( $sender_id );
}
add_filter( 'qibbs_can_send_message', 'qibbs_filter_can_send_message_for_blacklist', 10, 3 );

/**
 * 校验用户是否具备指定版块的版务管理权限
 *
 * @param int $user_id  User ID.
 * @param int $forum_id Forum ID.
 * @return bool
 */
function qibbs_user_can_moderate_forum( $user_id, $forum_id ) {
    $user_id  = absint( $user_id );
    $forum_id = absint( $forum_id );
    if ( ! $user_id || ! $forum_id || ! class_exists( 'Qibbs_Forum_Moderator_Service' ) ) {
        return false;
    }

    static $service = null;
    if ( ! ( $service instanceof Qibbs_Forum_Moderator_Service ) ) {
        $service = new Qibbs_Forum_Moderator_Service();
    }
    return $service->is_forum_moderator( $user_id, $forum_id );
}

/**
 * 校验用户是否具备管理指定帖子的权限
 *
 * @param int        $user_id User ID.
 * @param int|object $post    Post ID or post object.
 * @return bool
 */
function qibbs_user_can_moderate_post( $user_id, $post ) {
    $user_id = absint( $user_id );
    if ( ! $user_id || ! class_exists( 'Qibbs_Forum_Moderator_Service' ) ) {
        return false;
    }

    static $service = null;
    if ( ! ( $service instanceof Qibbs_Forum_Moderator_Service ) ) {
        $service = new Qibbs_Forum_Moderator_Service();
    }
    return $service->user_can_moderate_post( $user_id, $post );
}

/**
 * 写入版务管理操作流水审计日志
 *
 * @param string $action         Action key.
 * @param string $object_type    Object type.
 * @param int    $object_id      Object ID.
 * @param int    $target_user_id Target user ID.
 * @param string $reason         Reason.
 * @return bool
 */
function qibbs_log_moderator_action( $action, $object_type, $object_id, $target_user_id = 0, $reason = '' ) {
    if ( ! class_exists( 'Qibbs_Forum_Moderator_Service' ) ) {
        return false;
    }

    static $service = null;
    if ( ! ( $service instanceof Qibbs_Forum_Moderator_Service ) ) {
        $service = new Qibbs_Forum_Moderator_Service();
    }
    return $service->log_action(
        $object_type,
        absint( $object_id ),
        absint( $target_user_id ),
        get_current_user_id(),
        $action,
        $reason
    );
}

/**
 * 获取用户当前佩戴的荣誉身份徽章
 *
 * @param int $user_id  User ID.
 * @param int $forum_id Optional forum ID.
 * @return array
 */
function qibbs_get_user_badges( $user_id, $forum_id = 0 ) {
    $user_id  = absint( $user_id );
    $forum_id = absint( $forum_id );
    if ( ! $user_id || ! class_exists( 'Qibbs_Badge_Service' ) ) {
        return array();
    }

    static $service = null;
    if ( ! ( $service instanceof Qibbs_Badge_Service ) ) {
        $service = new Qibbs_Badge_Service();
    }
    return $service->get_user_badges( $user_id, $forum_id );
}

/**
 * 更新管理员为用户手动颁发的专属徽章
 *
 * @param int   $user_id    User ID.
 * @param array $badge_keys Badge keys.
 * @return bool
 */
function qibbs_update_user_manual_badges( $user_id, $badge_keys = array() ) {
    $user_id = absint( $user_id );
    if ( ! $user_id || ! class_exists( 'Qibbs_Badge_Service' ) ) {
        return false;
    }

    static $service = null;
    if ( ! ( $service instanceof Qibbs_Badge_Service ) ) {
        $service = new Qibbs_Badge_Service();
    }
    return $service->update_user_manual_badges( $user_id, (array) $badge_keys );
}
