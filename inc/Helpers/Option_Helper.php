<?php
/**
 * 全局配置与治理选项辅助函数
 *
 * @package Qibbs_Community
 * @subpackage Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 获取插件全局配置项
 *
 * 基于请求级内存快照加速，避免在单次请求中重复查询数据库配置
 * 优化高频读取多项配置时的性能
 *
 * @param array|null $new_options Optional new option payload for cache sync.
 * @param bool       $refresh     Whether to force-refresh from the database.
 * @return array
 */
function qibbs_get_options_store( $new_options = null, $refresh = false ) {
    static $options = null;

    if ( is_array( $new_options ) ) {
        $options = $new_options;
        return $options;
    }

    if ( $refresh || null === $options ) {
        $fetched = get_option( 'qibbs_options', array() );
        $options = is_array( $fetched ) ? $fetched : array();
    }

    return $options;
}

/**
 * 当全局配置被修改时同步刷新内存快照
 *
 * @param string $option    Option name.
 * @param mixed  $old_value Previous value.
 * @param mixed  $value     New value.
 * @return void
 */
function qibbs_sync_options_store_on_update( $option, $old_value = null, $value = null ) {
    if ( 'qibbs_options' !== $option ) {
        return;
    }

    qibbs_get_options_store( is_array( $value ) ? $value : array() );
}
add_action( 'updated_option', 'qibbs_sync_options_store_on_update', 10, 3 );

/**
 * 首次新增配置项时初始化内存快照
 *
 * @param string $option Option name.
 * @param mixed  $value  Stored value.
 * @return void
 */
function qibbs_sync_options_store_on_add( $option, $value ) {
    if ( 'qibbs_options' !== $option ) {
        return;
    }

    qibbs_get_options_store( is_array( $value ) ? $value : array() );
}
add_action( 'added_option', 'qibbs_sync_options_store_on_add', 10, 2 );

/**
 * 配置项删除时重置内存快照
 *
 * @param string $option Option name.
 * @return void
 */
function qibbs_sync_options_store_on_delete( $option ) {
    if ( 'qibbs_options' !== $option ) {
        return;
    }

    qibbs_get_options_store( array() );
}
add_action( 'deleted_option', 'qibbs_sync_options_store_on_delete', 10, 1 );

/**
 * 获取插件全局配置项
 *
 * @param string $key     Option key.
 * @param mixed  $default Default value.
 * @return mixed
 */
function qibbs_get_option( $key, $default = '' ) {
    $options = qibbs_get_options_store();
    return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
}

/**
 * 获取插件全部全局配置数组
 *
 * @return array
 */
function qibbs_get_options() {
    return qibbs_get_options_store();
}

/**
 * 更新微社区指定配置项
 *
 * @param string $key   Option key.
 * @param mixed  $value Option value.
 * @return bool
 */
function qibbs_update_option( $key, $value ) {
    $options = qibbs_get_options_store( null, true );
    $options[ $key ] = $value;
    $res = update_option( 'qibbs_options', $options );
    qibbs_get_options_store( null, true );
    return $res;
}

/**
 * 批量更新微社区全局配置数组（同步刷新内存快照）
 *
 * @param array $new_options Updated options array.
 * @return bool
 */
function qibbs_update_options( array $new_options ) {
    $existing = qibbs_get_options_store( null, true );
    $merged   = array_merge( $existing, $new_options );
    $res      = update_option( 'qibbs_options', $merged );
    qibbs_get_options_store( null, true );
    return $res;
}

/**
 * 读取版块治理规则全局存储
 *
 * @param array|null $new_store Optional replacement payload for cache sync.
 * @param bool       $refresh   Whether to force-refresh from the database.
 * @return array
 */
function qibbs_get_forum_governance_store( $new_store = null, $refresh = false ) {
    static $store = null;

    if ( is_array( $new_store ) ) {
        $store = $new_store;
        return $store;
    }

    if ( $refresh || null === $store ) {
        $fetched = get_option( 'qibbs_forum_governance_settings', array() );
        $store = is_array( $fetched ) ? $fetched : array();
    }

    return $store;
}

/**
 * 读取指定版块的治理规则配置
 *
 * @param int $forum_id Forum ID.
 * @return array
 */
function qibbs_get_forum_governance_settings( $forum_id ) {
    $forum_id = absint( $forum_id );
    $defaults = array(
        'welcome_message' => '',
        'rules_text'      => '',
    );

    if ( ! $forum_id ) {
        return $defaults;
    }

    $store = qibbs_get_forum_governance_store();
    $forum_settings = isset( $store[ $forum_id ] ) && is_array( $store[ $forum_id ] ) ? $store[ $forum_id ] : array();

    return wp_parse_args( $forum_settings, $defaults );
}

/**
 * 更新指定版块的治理规则配置
 *
 * @param int   $forum_id  Forum ID.
 * @param array $settings  Governance settings.
 * @return bool
 */
function qibbs_update_forum_governance_settings( $forum_id, $settings ) {
    $forum_id = absint( $forum_id );
    if ( ! $forum_id ) {
        return false;
    }

    $store = qibbs_get_forum_governance_store();
    $original_store = $store;
    $current = qibbs_get_forum_governance_settings( $forum_id );
    $next = array(
        'welcome_message' => isset( $settings['welcome_message'] ) ? sanitize_textarea_field( $settings['welcome_message'] ) : $current['welcome_message'],
        'rules_text'      => isset( $settings['rules_text'] ) ? sanitize_textarea_field( $settings['rules_text'] ) : $current['rules_text'],
    );

    if ( '' === $next['welcome_message'] && '' === $next['rules_text'] ) {
        unset( $store[ $forum_id ] );
    } else {
        $store[ $forum_id ] = $next;
    }

    if ( $store === $original_store ) {
        return true;
    }

    qibbs_get_forum_governance_store( $store );
    return update_option( 'qibbs_forum_governance_settings', $store, false );
}

/**
 * 检查指定微社区功能模块是否开启
 *
 * @param string $module Module key.
 * @return bool
 */
function qibbs_is_module_enabled( $module ) {
    $module_map = array(
        'likes'         => array( 'option' => 'enable_likes', 'default' => true ),
        'favorites'     => array( 'option' => 'enable_favorites', 'default' => true ),
        'comments'      => array( 'option' => 'enable_comments', 'default' => true ),
        'follows'       => array( 'option' => 'enable_follows', 'default' => true ),
        'messages'      => array( 'option' => 'enable_messages', 'default' => true ),
        'notifications' => array( 'option' => 'enable_notifications', 'default' => true ),
        'tickets'       => array( 'option' => 'enable_tickets', 'default' => false ),
        'vanity_mall'   => array( 'option' => 'enable_vanity_mall', 'default' => true ),
    );

    $module = sanitize_key( (string) $module );
    if ( ! isset( $module_map[ $module ] ) ) {
        return true;
    }

    return (bool) qibbs_get_option(
        $module_map[ $module ]['option'],
        $module_map[ $module ]['default']
    );
}

/**
 * 获取模块未开启时的用户友好提示文案
 *
 * @param string $module Module key.
 * @return string
 */
function qibbs_get_module_disabled_message( $module ) {
    $module = sanitize_key( (string) $module );

    $messages = array(
        'likes'         => __( '点赞功能已关闭', 'qibbs-community' ),
        'favorites'     => __( '收藏功能已关闭', 'qibbs-community' ),
        'comments'      => __( '评论功能已关闭', 'qibbs-community' ),
        'follows'       => __( '关注功能已关闭', 'qibbs-community' ),
        'messages'      => __( '私信功能已关闭', 'qibbs-community' ),
        'notifications' => __( '通知功能已关闭', 'qibbs-community' ),
        'tickets'       => __( '工单功能已关闭', 'qibbs-community' ),
        'vanity_mall'   => __( '靓号交易大厅已关闭', 'qibbs-community' ),
    );

    return isset( $messages[ $module ] )
        ? $messages[ $module ]
        : __( '当前功能已关闭', 'qibbs-community' );
}

/**
 * 标准化社区帖子讨论状态（讨论中、已结贴、已关闭）
 *
 * 保持向后兼容：同步维护旧版 is_resolved 标识
 *
 * @param string   $discussion_status Discussion status candidate.
 * @param int|bool $is_resolved       Legacy resolved flag.
 * @return string One of: discussion|resolved|closed.
 */
function qibbs_normalize_discussion_status( $discussion_status, $is_resolved = 0 ) {
    $discussion_status = sanitize_key( (string) $discussion_status );
    if ( in_array( $discussion_status, array( 'discussion', 'resolved', 'closed' ), true ) ) {
        return $discussion_status;
    }

    return ! empty( $is_resolved ) ? 'resolved' : 'discussion';
}

/**
 * 获取启用了结贴状态流转的版块 ID 列表
 *
 * @return int[]
 */
function qibbs_get_discussion_status_forum_ids() {
    $raw_forums = qibbs_get_option( 'discussion_status_forums', array() );
    if ( ! is_array( $raw_forums ) ) {
        return array();
    }

    $forum_ids = array_map( 'absint', $raw_forums );
    $forum_ids = array_values( array_unique( array_filter( $forum_ids ) ) );

    return $forum_ids;
}

/**
 * 检查结贴讨论状态功能是否全局启用
 *
 * @return bool
 */
function qibbs_is_discussion_status_system_enabled() {
    return (bool) qibbs_get_option( 'enable_discussion_status_system', false );
}

/**
 * 检查指定版块是否支持结贴/讨论状态流转
 *
 * @param int $forum_id Forum ID.
 * @return bool
 */
function qibbs_is_discussion_status_enabled_for_forum( $forum_id ) {
    $forum_id = absint( $forum_id );
    if ( ! $forum_id || ! qibbs_is_discussion_status_system_enabled() ) {
        return false;
    }

    $enabled_forums = qibbs_get_discussion_status_forum_ids();
    if ( empty( $enabled_forums ) ) {
        return false;
    }

    return in_array( $forum_id, $enabled_forums, true );
}

/**
 * 检查指定帖子是否支持修改讨论状态
 *
 * @param object|array $post Post object or array.
 * @return bool
 */
function qibbs_is_discussion_status_enabled_for_post( $post ) {
    $forum_id = 0;

    if ( is_object( $post ) ) {
        if ( isset( $post->forum_id ) ) {
            $forum_id = (int) $post->forum_id;
        } elseif ( isset( $post->forum ) && is_object( $post->forum ) ) {
            if ( isset( $post->forum->id ) ) {
                $forum_id = (int) $post->forum->id;
            } elseif ( isset( $post->forum->term_id ) ) {
                $forum_id = (int) $post->forum->term_id;
            }
        }
    } elseif ( is_array( $post ) ) {
        if ( isset( $post['forum_id'] ) ) {
            $forum_id = (int) $post['forum_id'];
        } elseif ( isset( $post['forum'] ) && is_array( $post['forum'] ) ) {
            if ( isset( $post['forum']['id'] ) ) {
                $forum_id = (int) $post['forum']['id'];
            } elseif ( isset( $post['forum']['term_id'] ) ) {
                $forum_id = (int) $post['forum']['term_id'];
            }
        }
    }

    return qibbs_is_discussion_status_enabled_for_forum( $forum_id );
}
