<?php
/**
 * 用户身份徽章与荣誉称号服务
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Badge_Service {

    /**
     * 运行时用户统计数据缓存
     *
     * @var array
     */
    private $stats_cache = array();

    /**
     * 获取徽章元数据配置列表
     *
     * @return array
     */
    public function get_definitions() {
        return array(
            'admin' => array(
                'label' => __( '管理员', 'qibbs-community' ),
                'class' => 'qibbs-user-badge-admin',
                'type'  => 'identity',
            ),
            'forum_moderator' => array(
                'label' => __( '圈子版主', 'qibbs-community' ),
                'class' => 'qibbs-user-badge-moderator',
                'type'  => 'identity',
            ),
            'verified_author' => array(
                'label' => __( '官方认证', 'qibbs-community' ),
                'class' => 'qibbs-user-badge-verified',
                'type'  => 'identity',
            ),
            'core_creator' => array(
                'label' => __( '核心创作者', 'qibbs-community' ),
                'class' => 'qibbs-user-badge-core',
                'type'  => 'identity',
            ),
            'exclusive_guest' => array(
                'label' => __( '特邀嘉宾', 'qibbs-community' ),
                'class' => 'qibbs-user-badge-guest',
                'type'  => 'identity',
            ),
            'pioneer_user' => array(
                'label' => __( '先锋元老', 'qibbs-community' ),
                'class' => 'qibbs-user-badge-pioneer',
                'type'  => 'identity',
            ),
            'senior_elder' => array(
                'label' => __( '荣誉顾问', 'qibbs-community' ),
                'class' => 'qibbs-user-badge-elder',
                'type'  => 'identity',
            ),
            'contract_author' => array(
                'label' => __( '签约达人', 'qibbs-community' ),
                'class' => 'qibbs-user-badge-contract',
                'type'  => 'identity',
            ),
            'tech_expert' => array(
                'label' => __( '官方技术', 'qibbs-community' ),
                'class' => 'qibbs-user-badge-tech',
                'type'  => 'identity',
            ),
            'first_post' => array(
                'label' => __( '新锐作者', 'qibbs-community' ),
                'class' => 'qibbs-user-badge-first-post',
                'type'  => 'achievement',
            ),
            'active_creator' => array(
                'label' => __( '活跃创作者', 'qibbs-community' ),
                'class' => 'qibbs-user-badge-active',
                'type'  => 'achievement',
            ),
            'popular_creator' => array(
                'label' => __( '人气作者', 'qibbs-community' ),
                'class' => 'qibbs-user-badge-popular',
                'type'  => 'achievement',
            ),
        );
    }

    /**
     * 获取指定用户的全部有效徽章
     *
     * @param int $user_id  User ID.
     * @param int $forum_id Optional forum ID for scoped moderator badge.
     * @return array
     */
    public function get_user_badges( $user_id, $forum_id = 0 ) {
        $user_id  = absint( $user_id );
        $forum_id = absint( $forum_id );
        if ( ! $user_id ) {
            return array();
        }

        $definitions = $this->get_definitions();
        $badges = array();

        if ( user_can( $user_id, 'manage_options' ) ) {
            $badges['admin'] = $definitions['admin'];
        }

        $moderator_service = new Qibbs_Forum_Moderator_Service();
        $is_moderator = $forum_id > 0
            ? $moderator_service->is_forum_moderator( $user_id, $forum_id )
            : $moderator_service->user_moderates_any_forum( $user_id );
        if ( $is_moderator ) {
            $badges['forum_moderator'] = $definitions['forum_moderator'];
        }

        $manual_badges = $this->get_user_manual_badges( $user_id );
        foreach ( $manual_badges as $badge_key ) {
            if ( isset( $definitions[ $badge_key ] ) ) {
                $badges[ $badge_key ] = $definitions[ $badge_key ];
            }
        }

        // 仅在成长等级关闭时作为后备成就徽章展示，开启时由 Lv.X 成长体系接管，避免视觉冗余
        if ( ! function_exists( 'qibbs_is_growth_levels_enabled' ) || ! qibbs_is_growth_levels_enabled() ) {
            $stats = $this->get_user_stats( $user_id );
            if ( $stats['post_count'] >= 1 ) {
                $badges['first_post'] = $definitions['first_post'];
            }
            if ( $stats['post_count'] >= 20 ) {
                $badges['active_creator'] = $definitions['active_creator'];
            }
            if ( $stats['like_count'] >= 100 ) {
                $badges['popular_creator'] = $definitions['popular_creator'];
            }
        }

        $normalized = array();
        foreach ( $badges as $key => $meta ) {
            $normalized[] = array(
                'key'   => $key,
                'label' => isset( $meta['label'] ) ? (string) $meta['label'] : $key,
                'class' => isset( $meta['class'] ) ? (string) $meta['class'] : 'qibbs-user-badge-default',
                'type'  => isset( $meta['type'] ) ? (string) $meta['type'] : 'identity',
            );
        }

        return $normalized;
    }

    /**
     * 获取管理员为用户手动颁发的徽章标识
     *
     * @param int $user_id User ID.
     * @return array
     */
    public function get_user_manual_badges( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return array();
        }

        $saved = get_user_meta( $user_id, '_qibbs_manual_badges', true );
        if ( ! is_array( $saved ) ) {
            return array();
        }

        $definitions = $this->get_definitions();
        $allowed_identity = array();
        foreach ( $definitions as $key => $meta ) {
            if ( isset( $meta['type'] ) && $meta['type'] === 'identity' && ! in_array( $key, array( 'admin', 'forum_moderator' ), true ) ) {
                $allowed_identity[] = $key;
            }
        }

        $normalized = array_values(
            array_unique(
                array_filter(
                    array_map( 'sanitize_key', $saved ),
                    function( $key ) use ( $allowed_identity ) {
                        return in_array( $key, $allowed_identity, true );
                    }
                )
            )
        );

        return $normalized;
    }

    /**
     * 更新用户手动颁发的徽章列表
     *
     * @param int   $user_id User ID.
     * @param array $badge_keys Badge keys.
     * @return bool
     */
    public function update_user_manual_badges( $user_id, $badge_keys ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }

        $badge_keys = is_array( $badge_keys ) ? $badge_keys : array();
        $normalized = array_values( array_unique( array_map( 'sanitize_key', $badge_keys ) ) );
        $valid = $this->get_user_manual_badges_allowlist();
        $normalized = array_values(
            array_filter(
                $normalized,
                function( $key ) use ( $valid ) {
                    return in_array( $key, $valid, true );
                }
            )
        );

        $current = get_user_meta( $user_id, '_qibbs_manual_badges', true );
        $current = is_array( $current ) ? array_values( array_unique( array_map( 'sanitize_key', $current ) ) ) : array();
        sort( $current );
        $compare = $normalized;
        sort( $compare );
        if ( $current === $compare ) {
            return true;
        }

        return false !== update_user_meta( $user_id, '_qibbs_manual_badges', $normalized );
    }

    /**
     * 查询所有拥有手动徽章的用户列表
     *
     * @param int $limit Limit.
     * @return array
     */
    public function get_users_with_manual_badges( $limit = 100 ) {
        $limit = max( 1, min( 500, absint( $limit ) ) );
        $users = get_users(
            array(
                'meta_key'     => '_qibbs_manual_badges',
                'number'       => $limit,
                'orderby'      => 'ID',
                'order'        => 'DESC',
                'fields'       => array( 'ID', 'display_name', 'user_email' ),
                'meta_compare' => 'EXISTS',
            )
        );

        return is_array( $users ) ? $users : array();
    }

    /**
     * 查询单条用户统计数据行
     *
     * @param int $user_id User ID.
     * @return array
     */
    private function get_user_stats( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return array(
                'post_count' => 0,
                'like_count' => 0,
            );
        }

        if ( isset( $this->stats_cache[ $user_id ] ) ) {
            return $this->stats_cache[ $user_id ];
        }

        global $wpdb;
        $stats_table = Qibbs_Database::user_stats();
        $posts_table = Qibbs_Database::posts();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT like_count FROM {$stats_table} WHERE user_id = %d LIMIT 1",
                $user_id
            )
        );
        $post_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$posts_table}
                 WHERE author_id = %d AND status = 'publish' AND ( source_type = '' OR source_type IS NULL )",
                $user_id
            )
        );

        $stats = array(
            'post_count' => $post_count,
            'like_count' => $row ? (int) $row->like_count : 0,
        );

        $this->stats_cache[ $user_id ] = $stats;
        return $stats;
    }

    /**
     * 可供后台手动颁发的徽章白名单
     *
     * @return array
     */
    private function get_user_manual_badges_allowlist() {
        return array(
            'verified_author',
            'core_creator',
            'exclusive_guest',
            'pioneer_user',
            'senior_elder',
            'contract_author',
            'tech_expert',
        );
    }
}
