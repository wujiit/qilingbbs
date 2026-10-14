<?php
/**
 * 个人主页服务类
 *
 * @package Qibbs_Community
 * @subpackage Profile
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Profile_Service {

    public function add_rewrite_rules() {
        add_rewrite_rule( '^member/([0-9]+)/?$', 'index.php?qibbs_member_id=$matches[1]', 'top' );
        add_rewrite_rule( '^member/([0-9]+)/([a-z0-9_-]+)/?$', 'index.php?qibbs_member_id=$matches[1]&qibbs_member_tab=$matches[2]', 'top' );
    }

    public function add_query_vars( $vars ) {
        $vars[] = 'qibbs_member_id';
        $vars[] = 'qibbs_member_tab';
        return $vars;
    }

    public function load_profile_template( $template ) {
        $raw_id = absint( get_query_var( 'qibbs_member_id' ) );
        $tab    = sanitize_title( (string) get_query_var( 'qibbs_member_tab', '' ) );

        if ( ! $raw_id ) {
            $match = function_exists( 'qibbs_match_profile_request_path' )
                ? qibbs_match_profile_request_path()
                : array(
                    'member_id'  => 0,
                    'raw_id'     => 0,
                    'member_tab' => '',
                );

            if ( ! empty( $match['member_id'] ) ) {
                $raw_id = absint( $match['raw_id'] ?? $match['member_id'] );
            }
            if ( ! empty( $match['member_tab'] ) ) {
                $tab = sanitize_title( (string) $match['member_tab'] );
                qibbs_set_virtual_query_var( 'qibbs_member_tab', $tab );
            }
        }

        if ( ! $raw_id ) {
            return $template;
        }

        // 解析 raw_id 为 WordPress 真实 user_id
        // 1. 优先按社区 CUID 反查
        $resolved_user_id = function_exists( 'qibbs_get_user_id_by_cuid' ) ? qibbs_get_user_id_by_cuid( $raw_id ) : 0;

        // 2. 如果未找到对应 CUID 用户，检查 raw_id 是否就是 WordPress 原生 user_id
        if ( $resolved_user_id <= 0 ) {
            $user_check = get_userdata( $raw_id );
            if ( $user_check ) {
                $resolved_user_id = $raw_id;
            }
        }

        if ( $resolved_user_id <= 0 ) {
            return get_404_template();
        }

        $member_id = $resolved_user_id;
        qibbs_set_virtual_query_var( 'qibbs_member_id', $member_id );

        $user = get_userdata( $member_id );
        if ( ! $user ) {
            return get_404_template();
        }

        // 规范化重定向：若访问的是原生 user_id 链接，而用户拥有激活的社区 CUID，则 301 重定向至规范 CUID 链接
        $cuid = function_exists( 'qibbs_get_user_cuid' ) ? qibbs_get_user_cuid( $member_id ) : 0;
        if ( $cuid > 0 && $raw_id !== $cuid && ! is_admin() && ! wp_doing_ajax() ) {
            $canonical_url = qibbs_get_profile_url( $member_id, $tab );
            if ( $canonical_url && ! empty( $_SERVER['REQUEST_METHOD'] ) && 'GET' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
                wp_safe_redirect( $canonical_url, 301 );
                exit;
            }
        }

        qibbs_mark_virtual_request_as_resolved();

        // 检查主题级个人主页模板覆盖
        $theme_template = locate_template( 'qibbs/profile.php' );
        if ( $theme_template ) {
            return $theme_template;
        }

        $plugin_template = QIBBS_PLUGIN_DIR . 'templates/profile.php';
        return file_exists( $plugin_template ) ? $plugin_template : $template;
    }

    /**
     * 禁用伪静态个人主页 URL 的规范重定向
     *
     * @param string|false $redirect_url Target redirect URL.
     * @param string       $requested_url Requested URL.
     * @return string|false
     */
    public function disable_profile_canonical( $redirect_url, $requested_url ) {
        unset( $requested_url );

        if ( is_admin() || wp_doing_ajax() ) {
            return $redirect_url;
        }

        if ( get_query_var( 'qibbs_member_id' ) ) {
            return false;
        }

        $match = function_exists( 'qibbs_match_profile_request_path' )
            ? qibbs_match_profile_request_path()
            : array( 'member_id' => 0 );

        if ( ! empty( $match['member_id'] ) ) {
            return false;
        }

        return $redirect_url;
    }

    public function get_profile( $user_id ) {
        $raw_id = absint( $user_id );
        if ( $raw_id <= 0 ) {
            return null;
        }

        // 支持传入 CUID 自动反查真实 user_id
        $resolved_id = function_exists( 'qibbs_get_user_id_by_cuid' ) ? qibbs_get_user_id_by_cuid( $raw_id ) : 0;
        if ( $resolved_id > 0 ) {
            $user_id = $resolved_id;
        } else {
            $user_id = $raw_id;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return null;
        }

        $follow_service  = new Qibbs_Follow_Service();
        $current_user_id = get_current_user_id();
        $cuid            = function_exists( 'qibbs_get_user_cuid' ) ? qibbs_get_user_cuid( $user_id ) : 0;

        return array(
            'id'              => $user_id,
            'cuid'            => $cuid > 0 ? $cuid : $user_id,
            'cuid_badge'      => function_exists( 'qibbs_render_cuid_badge' ) ? qibbs_render_cuid_badge( $user_id ) : '',
            'profile_url'     => function_exists( 'qibbs_get_profile_url' ) ? qibbs_get_profile_url( $user_id ) : '',
            'display_name'    => $user->display_name,
            'avatar'          => get_avatar_url( $user_id, array( 'size' => 150 ) ),
            'bio'             => $this->get_user_bio( $user_id ),
            'cover'           => $this->get_user_cover( $user_id ),
            'stats'           => $this->get_user_stats( $user_id ),
            'is_following'    => $current_user_id ? $follow_service->is_following( $current_user_id, $user_id ) : false,
            'is_self'         => $current_user_id === $user_id,
            'registered'      => $user->user_registered,
            'website'         => $user->user_url,
        );
    }

    public function get_user_stats( $user_id ) {
        global $wpdb;
        $stats_table = Qibbs_Database::user_stats();
        $posts_table = Qibbs_Database::posts();

        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$stats_table} WHERE user_id = %d",
            $user_id
        ) );

        // 无缓存统计时直接从数据库统计发帖数
        $post_count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$posts_table} WHERE author_id = %d AND status = 'publish'",
            $user_id
        ) );

        $follow_service = new Qibbs_Follow_Service();

        return array(
            'posts'     => (int) $post_count,
            'followers' => $stats ? (int) $stats->follower_count : $follow_service->get_follower_count( $user_id ),
            'following' => $stats ? (int) $stats->following_count : $follow_service->get_following_count( $user_id ),
        );
    }

    public function get_user_bio( $user_id ) {
        $bio = get_user_meta( $user_id, '_qibbs_bio', true );
        return $bio ?: get_user_meta( $user_id, 'description', true );
    }

    public function update_user_bio( $user_id, $bio ) {
        return update_user_meta( $user_id, '_qibbs_bio', sanitize_textarea_field( $bio ) );
    }

    public function get_user_cover( $user_id ) {
        return get_user_meta( $user_id, '_qibbs_cover', true );
    }

    public function update_user_cover( $user_id, $cover_url ) {
        return update_user_meta( $user_id, '_qibbs_cover', esc_url_raw( $cover_url ) );
    }

    public function get_user_posts( $user_id, $limit = 20, $offset = 0 ) {
        $feed_service = new Qibbs_Feed_Service();
        return $feed_service->get_user_feed( $user_id, array(
            'per_page' => $limit,
            'page'     => ( $offset / $limit ) + 1,
        ) );
    }
}
