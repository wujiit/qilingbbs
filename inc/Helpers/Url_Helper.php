<?php
/**
 * 前端路由、URL 生成与多语言路径匹配辅助函数
 *
 * @package Qibbs_Community
 * @subpackage Helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 将前台内部 URL 转换为当前多语言 URL
 *
 * @param string $url Original internal URL.
 * @return string
 */
function qibbs_translate_frontend_internal_url( $url ) {
    $url = (string) $url;

    if ( '' === $url ) {
        return '';
    }

    if (
        function_exists( 'developer_starter_translate_internal_url_for_frontend_lang' )
        && ( ! is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) )
    ) {
        return (string) developer_starter_translate_internal_url_for_frontend_lang( $url );
    }

    return $url;
}

/**
 * 构建前台原始内部 URL
 *
 * @param string $path Internal path.
 * @return string
 */
function qibbs_build_internal_url( $path ) {
    $path = trim( (string) $path );

    if ( function_exists( 'developer_starter_build_raw_home_url' ) ) {
        return (string) developer_starter_build_raw_home_url( $path );
    }

    return home_url( $path );
}

/**
 * 获取已配置的社区核心页面 ID（支持别名回退查找）
 *
 * @param string $option_name   Stored option name.
 * @param string $fallback_slug Default page slug.
 * @return int
 */
function qibbs_get_configured_page_id( $option_name, $fallback_slug = '' ) {
    $page_id = absint( get_option( (string) $option_name, 0 ) );
    if ( $page_id > 0 ) {
        $page = get_post( $page_id );
        if ( $page instanceof WP_Post && 'trash' !== $page->post_status ) {
            return (int) $page_id;
        }
    }

    $fallback_slug = trim( (string) $fallback_slug, '/' );
    if ( '' !== $fallback_slug ) {
        $page = get_page_by_path( $fallback_slug );
        if ( $page instanceof WP_Post ) {
            return (int) $page->ID;
        }
    }

    return 0;
}

/**
 * 从页面配置项生成前台访问 URL
 *
 * @param string $option_name   Stored option name.
 * @param string $fallback_slug Default page slug.
 * @param array  $query_args    Optional query args.
 * @return string
 */
function qibbs_get_page_url_by_option( $option_name, $fallback_slug = '', $query_args = array() ) {
    $page_id = qibbs_get_configured_page_id( $option_name, $fallback_slug );
    if ( $page_id > 0 ) {
        $url = get_permalink( $page_id );
    } else {
        $path = trim( (string) $fallback_slug, '/' );
        $url  = '' === $path ? home_url( '/' ) : home_url( user_trailingslashit( $path ) );
    }

    if ( ! empty( $query_args ) ) {
        $url = add_query_arg( $query_args, $url );
    }

    return qibbs_translate_frontend_internal_url( $url );
}

/**
 * 获取社区主页 URL
 *
 * @param array $query_args Optional query args.
 * @return string
 */
function qibbs_get_community_url( $query_args = array() ) {
    return qibbs_get_page_url_by_option( 'qibbs_community_page_id', 'community', $query_args );
}

/**
 * 获取靓号交易大厅与商城页面 URL
 *
 * @param array $query_args Optional query args.
 * @return string
 */
function qibbs_get_vanity_mall_url( $query_args = array() ) {
    $default_args = array( 'view' => 'vanity-mall' );
    $args = array_merge( $default_args, $query_args );
    return qibbs_get_community_url( $args );
}

/**
 * 获取道具大厅与背包页面 URL
 *
 * @param array $query_args Optional query args.
 * @return string
 */
function qibbs_get_prop_mall_url( $query_args = array() ) {
    $default_args = array( 'view' => 'prop-mall' );
    $args = array_merge( $default_args, $query_args );
    return qibbs_get_community_url( $args );
}

/**
 * 获取私信消息页面 URL
 *
 * @param array $query_args Optional query args.
 * @return string
 */
function qibbs_get_messages_url( $query_args = array() ) {
    return qibbs_get_page_url_by_option( 'qibbs_messages_page_id', 'messages', $query_args );
}

/**
 * 获取站内通知页面 URL
 *
 * @param array $query_args Optional query args.
 * @return string
 */
function qibbs_get_notifications_url( $query_args = array() ) {
    return qibbs_get_page_url_by_option( 'qibbs_notifications_page_id', 'notifications', $query_args );
}

/**
 * 获取用户个人主页 URL
 *
 * @param int    $user_id User ID.
 * @param string $tab     Optional profile tab.
 * @return string
 */
function qibbs_get_profile_url( $user_id, $tab = '' ) {
    $user_id = absint( $user_id );
    if ( $user_id <= 0 ) {
        return qibbs_get_community_url();
    }

    $tab = sanitize_title( (string) $tab );

    // 优先使用用户的社区激活靓号/UID，若无则使用 WordPress 原生 user_id
    $cuid       = function_exists( 'qibbs_get_user_cuid' ) ? qibbs_get_user_cuid( $user_id ) : 0;
    $identifier = $cuid > 0 ? $cuid : $user_id;

    if ( function_exists( 'developer_starter_get_frontend_route_url' ) ) {
        $route_url = developer_starter_get_frontend_route_url(
            'qibbs.profile',
            array(
                'member_id'  => $identifier,
                'user_id'    => $user_id,
                'member_tab' => $tab,
            )
        );
        if ( '' !== $route_url ) {
            return (string) $route_url;
        }
    }

    $path = 'member/' . $identifier;
    if ( '' !== $tab && 'posts' !== $tab ) {
        $path .= '/' . $tab;
    }

    return qibbs_translate_frontend_internal_url( qibbs_build_internal_url( user_trailingslashit( $path ) ) );
}

/**
 * 获取帖子详情页访问 URL
 *
 * @param int    $post_id   Post ID.
 * @param string $fragment  Optional fragment without leading hash.
 * @return string
 */
function qibbs_get_post_url( $post_id, $fragment = '' ) {
    $post_id = absint( $post_id );
    if ( $post_id <= 0 ) {
        return qibbs_get_community_url();
    }

    if ( function_exists( 'developer_starter_get_frontend_route_url' ) ) {
        $route_url = developer_starter_get_frontend_route_url(
            'qibbs.post',
            array(
                'post_id' => $post_id,
            )
        );
        $url = '' !== $route_url
            ? (string) $route_url
            : qibbs_translate_frontend_internal_url(
                qibbs_build_internal_url( user_trailingslashit( 'qibbs/post/' . $post_id ) )
            );
    } else {
        $url = qibbs_translate_frontend_internal_url(
            qibbs_build_internal_url( user_trailingslashit( 'qibbs/post/' . $post_id ) )
        );
    }

    $fragment = ltrim( sanitize_title_with_dashes( (string) $fragment ), '#' );
    if ( '' !== $fragment ) {
        $url .= '#' . $fragment;
    }

    return $url;
}

/**
 * 注册微社区多语言虚拟前台路由
 *
 * @return void
 */
function qibbs_register_multilingual_routes() {
    if ( ! function_exists( 'developer_starter_register_frontend_route' ) ) {
        return;
    }

    developer_starter_register_frontend_route(
        'qibbs.profile',
        array(
            'match' => function( $path ) {
                $match = function_exists( 'qibbs_match_profile_request_path' )
                    ? qibbs_match_profile_request_path( $path )
                    : array(
                        'member_id'  => 0,
                        'raw_id'     => 0,
                        'member_tab' => '',
                    );

                $resolved_user_id = absint( $match['member_id'] ?? 0 );
                if ( $resolved_user_id <= 0 ) {
                    return array();
                }

                $member_tab = sanitize_title( (string) ( $match['member_tab'] ?? '' ) );
                $cuid       = function_exists( 'qibbs_get_user_cuid' ) ? qibbs_get_user_cuid( $resolved_user_id ) : 0;
                $identifier = $cuid > 0 ? $cuid : $resolved_user_id;

                $route_path = 'member/' . $identifier;
                if ( '' !== $member_tab && 'posts' !== $member_tab ) {
                    $route_path .= '/' . $member_tab;
                }

                return array(
                    'path'       => $route_path,
                    'params'     => array(
                        'member_id'  => $identifier,
                        'user_id'    => $resolved_user_id,
                        'member_tab' => $member_tab,
                    ),
                    'query_vars' => array_filter(
                        array(
                            'qibbs_member_id'  => $resolved_user_id,
                            'qibbs_member_tab' => $member_tab,
                        ),
                        static function( $value ) {
                            return '' !== (string) $value && null !== $value;
                        }
                    ),
                );
            },
            'url'   => function( $context ) {
                $params     = isset( $context['params'] ) && is_array( $context['params'] ) ? $context['params'] : array();
                $raw_id     = absint( $params['member_id'] ?? 0 );
                $member_tab = sanitize_title( (string) ( $params['member_tab'] ?? '' ) );

                if ( $raw_id <= 0 && ! empty( $params['user_id'] ) ) {
                    $raw_id = absint( $params['user_id'] );
                }

                if ( $raw_id <= 0 ) {
                    return '';
                }

                $identifier = $raw_id;
                if ( ! empty( $params['user_id'] ) ) {
                    $cuid = function_exists( 'qibbs_get_user_cuid' ) ? qibbs_get_user_cuid( absint( $params['user_id'] ) ) : 0;
                    $identifier = $cuid > 0 ? $cuid : absint( $params['user_id'] );
                } else {
                    $user_by_cuid = function_exists( 'qibbs_get_user_id_by_cuid' ) ? qibbs_get_user_id_by_cuid( $raw_id ) : 0;
                    if ( $user_by_cuid <= 0 ) {
                        $cuid = function_exists( 'qibbs_get_user_cuid' ) ? qibbs_get_user_cuid( $raw_id ) : 0;
                        if ( $cuid > 0 ) {
                            $identifier = $cuid;
                        }
                    }
                }

                $path = 'member/' . $identifier;
                if ( '' !== $member_tab && 'posts' !== $member_tab ) {
                    $path .= '/' . $member_tab;
                }

                return qibbs_build_internal_url( user_trailingslashit( $path ) );
            },
        )
    );

    developer_starter_register_frontend_route(
        'qibbs.post',
        array(
            'match' => function( $path ) {
                $match = function_exists( 'qibbs_match_post_request_path' )
                    ? qibbs_match_post_request_path( $path )
                    : array( 'post_id' => 0 );

                $post_id = absint( $match['post_id'] ?? 0 );
                if ( $post_id <= 0 ) {
                    return array();
                }

                return array(
                    'path'       => 'qibbs/post/' . $post_id,
                    'params'     => array(
                        'post_id' => $post_id,
                    ),
                    'query_vars' => array(
                        'qibbs_post_id' => $post_id,
                    ),
                );
            },
            'url'   => function( $context ) {
                $params  = isset( $context['params'] ) && is_array( $context['params'] ) ? $context['params'] : array();
                $post_id = absint( $params['post_id'] ?? 0 );

                if ( $post_id <= 0 ) {
                    return '';
                }

                return qibbs_build_internal_url( user_trailingslashit( 'qibbs/post/' . $post_id ) );
            },
        )
    );
}
add_action( 'after_setup_theme', 'qibbs_register_multilingual_routes', 20 );

/**
 * 标准化请求路径（剔除子目录与多语言语言前缀）
 *
 * @param string $path Request path.
 * @return string
 */
function qibbs_normalize_request_path( $path ) {
    $path = trim( (string) $path, '/' );
    if ( '' === $path ) {
        return '';
    }

    $home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
    $home_path = trim( $home_path, '/' );
    if ( '' !== $home_path ) {
        if ( $path === $home_path ) {
            $path = '';
        } elseif ( 0 === strpos( $path, $home_path . '/' ) ) {
            $path = substr( $path, strlen( $home_path ) + 1 );
        }
    }

    if ( '' === $path ) {
        return '';
    }

    if ( function_exists( 'developer_starter_strip_multilingual_prefix' ) ) {
        $path = (string) developer_starter_strip_multilingual_prefix( $path );
    } elseif ( function_exists( 'developer_starter_get_multilingual_languages' ) ) {
        $codes = wp_list_pluck( (array) developer_starter_get_multilingual_languages(), 'code' );
        $codes = array_filter( array_map( 'sanitize_title', (array) $codes ) );
        if ( ! empty( $codes ) ) {
            $segments      = explode( '/', $path );
            $first_segment = isset( $segments[0] ) ? sanitize_title( (string) $segments[0] ) : '';
            if ( '' !== $first_segment && in_array( $first_segment, $codes, true ) ) {
                array_shift( $segments );
                $path = implode( '/', $segments );
            }
        }
    }

    return trim( (string) $path, '/' );
}

/**
 * 获取当前请求的标准规范路径
 *
 * @return string
 */
function qibbs_get_current_request_path() {
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
    if ( '' === $request_uri ) {
        return '';
    }

    $path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
    return qibbs_normalize_request_path( $path );
}

/**
 * 匹配个人主页虚拟路由
 *
 * @param string $path Optional request path.
 * @return array{member_id:int,member_tab:string}
 */
function qibbs_match_profile_request_path( $path = '' ) {
    $path = '' !== $path ? qibbs_normalize_request_path( $path ) : qibbs_get_current_request_path();
    if ( '' === $path ) {
        return array(
            'member_id'  => 0,
            'raw_id'     => 0,
            'member_tab' => '',
        );
    }

    if ( preg_match( '#^member/([0-9]+)(?:/([a-z0-9_-]+))?/?$#i', $path, $matches ) ) {
        $raw_id = absint( $matches[1] );
        // 优先按社区 CUID 反查对应 WordPress 用户 ID
        $resolved_user_id = function_exists( 'qibbs_get_user_id_by_cuid' ) ? qibbs_get_user_id_by_cuid( $raw_id ) : 0;
        if ( $resolved_user_id <= 0 ) {
            // 保底兼容：若不是已知 CUID，检查是否直接是已存在的 WordPress 用户 ID
            $user = get_userdata( $raw_id );
            if ( $user ) {
                $resolved_user_id = $raw_id;
            }
        }

        return array(
            'member_id'  => $resolved_user_id,
            'raw_id'     => $raw_id,
            'member_tab' => isset( $matches[2] ) ? sanitize_title( (string) $matches[2] ) : '',
        );
    }

    return array(
        'member_id'  => 0,
        'raw_id'     => 0,
        'member_tab' => '',
    );
}

/**
 * 匹配帖子详情页虚拟路由
 *
 * @param string $path Optional request path.
 * @return array{post_id:int}
 */
function qibbs_match_post_request_path( $path = '' ) {
    $path = '' !== $path ? qibbs_normalize_request_path( $path ) : qibbs_get_current_request_path();
    if ( '' === $path ) {
        return array( 'post_id' => 0 );
    }

    if ( preg_match( '#^qibbs/post/([0-9]+)/?$#i', $path, $matches ) ) {
        return array( 'post_id' => absint( $matches[1] ) );
    }

    return array( 'post_id' => 0 );
}

/**
 * 为当前请求设置虚拟 Query Var 参数
 *
 * @param string $key   Query var key.
 * @param mixed  $value Query var value.
 * @return void
 */
function qibbs_set_virtual_query_var( $key, $value ) {
    global $wp_query;

    set_query_var( $key, $value );

    if ( isset( $wp_query ) && $wp_query instanceof WP_Query ) {
        $wp_query->query_vars[ $key ] = $value;
    }
}

/**
 * 标记当前虚拟路由已命中解析
 *
 * @return void
 */
function qibbs_mark_virtual_request_as_resolved() {
    global $wp_query;

    if ( isset( $wp_query ) && $wp_query instanceof WP_Query ) {
        $wp_query->is_404 = false;
    }

    status_header( 200 );
}

/**
 * 动态获取启灵游戏中心/道具商城页面 URL（短代码自适应动态寻址）
 *
 * 优先调用启灵游戏插件导出的 qlg_get_shop_url() 函数；
 * 若未导出或插件尚未加载，则通过短代码 [qiling_games] 自动识别目标页面并提取固定链接，
 * 允许管理员任意自定义页面 Slug（如 /aaa-games），彻底杜绝硬编码。
 *
 * @param string $section 可选锚点或区域，默认 'shop'.
 * @return string
 */
function qibbs_get_game_shop_url( $section = 'shop' ) {
    if ( function_exists( 'qlg_get_shop_url' ) ) {
        $url = (string) qlg_get_shop_url();
        if ( ! empty( $url ) ) {
            return $url;
        }
    }

    global $wpdb;
    $page_id = 0;

    // 1. 尝试从 qlg_options 读取已绑定的页面 ID
    $qlg_opts = get_option( 'qlg_options', array() );
    if ( is_array( $qlg_opts ) && ! empty( $qlg_opts['page_lobby_id'] ) ) {
        $pid = absint( $qlg_opts['page_lobby_id'] );
        if ( $pid > 0 && 'trash' !== get_post_status( $pid ) ) {
            $page_id = $pid;
        }
    }

    // 2. 若未绑定或已失效，全库按短代码特征动态检索（以短代码为唯一指纹）
    if ( $page_id <= 0 ) {
        $sql = "SELECT ID FROM {$wpdb->posts} 
                WHERE post_type = 'page' 
                  AND post_status IN ('publish', 'private', 'draft') 
                  AND (post_content LIKE '%[qiling_games%' OR post_content LIKE '%[qiling_games_lobby%') 
                ORDER BY CASE WHEN post_status = 'publish' THEN 1 ELSE 2 END, ID ASC 
                LIMIT 1";
        $found_id = $wpdb->get_var( $sql );
        if ( $found_id ) {
            $page_id = absint( $found_id );
        }
    }

    if ( $page_id > 0 ) {
        $base_url = get_permalink( $page_id );
    } else {
        $base_url = home_url( '/' );
    }

    if ( 'shop' === $section ) {
        return add_query_arg( 'qlg_section', 'shop', $base_url ) . '#qlg-game-shop-section';
    }

    return $base_url;
}
