<?php
/**
 * 版主委任与版务权限服务
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Forum_Moderator_Service {
    /**
     * 版块维度版主校验请求级缓存
     *
     * @var array
     */
    private $forum_moderator_cache = array();

    /**
     * 全局是否为任意版主校验请求级缓存
     *
     * @var array
     */
    private $any_forum_moderator_cache = array();

    /**
     * 查询版块委任的版主用户列表
     *
     * @param int $forum_id Forum ID.
     * @return array
     */
    public function get_forum_moderators( $forum_id ) {
        $forum_id = absint( $forum_id );
        if ( ! $forum_id ) {
            return array();
        }

        global $wpdb;
        $members_table = Qibbs_Database::forum_members();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT forum_id, user_id, role, created_at
                 FROM {$members_table}
                 WHERE forum_id = %d AND role IN ('moderator','owner')
                 ORDER BY role DESC, user_id ASC",
                $forum_id
            )
        );
    }

    /**
     * 校验用户是否为指定版块的版主
     *
     * @param int $user_id  User ID.
     * @param int $forum_id Forum ID.
     * @return bool
     */
    public function is_forum_moderator( $user_id, $forum_id ) {
        $user_id  = absint( $user_id );
        $forum_id = absint( $forum_id );
        if ( ! $user_id || ! $forum_id ) {
            return false;
        }

        $cache_key = $user_id . ':' . $forum_id;
        if ( array_key_exists( $cache_key, $this->forum_moderator_cache ) ) {
            return (bool) $this->forum_moderator_cache[ $cache_key ];
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            $this->forum_moderator_cache[ $cache_key ] = true;
            return true;
        }

        global $wpdb;
        $members_table = Qibbs_Database::forum_members();
        $row = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$members_table}
                 WHERE forum_id = %d AND user_id = %d AND role IN ('moderator','owner')
                 LIMIT 1",
                $forum_id,
                $user_id
            )
        );

        $is_moderator = ! empty( $row );
        $this->forum_moderator_cache[ $cache_key ] = $is_moderator;
        return $is_moderator;
    }

    /**
     * 校验用户是否在任何版块拥有版主权限
     *
     * @param int $user_id User ID.
     * @return bool
     */
    public function user_moderates_any_forum( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }

        if ( array_key_exists( $user_id, $this->any_forum_moderator_cache ) ) {
            return (bool) $this->any_forum_moderator_cache[ $user_id ];
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            $this->any_forum_moderator_cache[ $user_id ] = true;
            return true;
        }

        global $wpdb;
        $members_table = Qibbs_Database::forum_members();
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$members_table}
                 WHERE user_id = %d AND role IN ('moderator','owner')
                 LIMIT 1",
                $user_id
            )
        );

        $is_moderator = ! empty( $exists );
        $this->any_forum_moderator_cache[ $user_id ] = $is_moderator;
        return $is_moderator;
    }

    /**
     * 查询用户担任版主的所有版块 ID 集合
     *
     * @param int $user_id User ID.
     * @return array
     */
    public function get_moderated_forum_ids( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return array();
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            global $wpdb;
            $forums_table = Qibbs_Database::forums();

            return array_map(
                'absint',
                (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$forums_table} WHERE 1 = %d", 1 ) )
            );
        }

        global $wpdb;
        $members_table = Qibbs_Database::forum_members();

        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        'absint',
                        (array) $wpdb->get_col(
                            $wpdb->prepare(
                                "SELECT forum_id
                                 FROM {$members_table}
                                 WHERE user_id = %d AND role IN ('moderator','owner')",
                                $user_id
                            )
                        )
                    )
                )
            )
        );
    }

    /**
     * 同步并批量保存版块的委任版主列表
     *
     * @param int   $forum_id  Forum ID.
     * @param array $user_ids  User IDs.
     * @param int   $actor_id  Operator user ID.
     * @return array
     */
    public function sync_forum_moderators( $forum_id, $user_ids, $actor_id = 0 ) {
        $forum_id = absint( $forum_id );
        $actor_id = absint( $actor_id );
        $user_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $user_ids ) ) ) );

        if ( ! $forum_id ) {
            return array(
                'success' => false,
                'message' => __( '圈子参数错误', 'qibbs-community' ),
                'added'   => array(),
                'removed' => array(),
            );
        }

        global $wpdb;
        $forums_table = Qibbs_Database::forums();
        $members_table = Qibbs_Database::forum_members();
        $subscriptions_table = Qibbs_Database::forum_subscriptions();

        $forum_exists = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$forums_table} WHERE id = %d", $forum_id )
        );
        if ( ! $forum_exists ) {
            return array(
                'success' => false,
                'message' => __( '圈子不存在', 'qibbs-community' ),
                'added'   => array(),
                'removed' => array(),
            );
        }

        $existing_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, role FROM {$members_table}
                 WHERE forum_id = %d AND role IN ('moderator','owner')",
                $forum_id
            )
        );

        $existing_mods = array();
        foreach ( (array) $existing_rows as $row ) {
            $role = isset( $row->role ) ? (string) $row->role : 'moderator';
            if ( 'owner' === $role ) {
                continue;
            }
            $existing_mods[] = (int) $row->user_id;
        }

        $to_add    = array_values( array_diff( $user_ids, $existing_mods ) );
        $to_remove = array_values( array_diff( $existing_mods, $user_ids ) );

        foreach ( $to_remove as $remove_user_id ) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$subscriptions_table} (forum_id, user_id, created_at)
                     VALUES (%d, %d, %s)",
                    $forum_id,
                    $remove_user_id,
                    current_time( 'mysql' )
                )
            );

            $wpdb->update(
                $members_table,
                array( 'role' => 'member' ),
                array(
                    'forum_id' => $forum_id,
                    'user_id'  => $remove_user_id,
                    'role'     => 'moderator',
                ),
                array( '%s' ),
                array( '%d', '%d', '%s' )
            );
            $this->log_action( 'forum', $forum_id, $remove_user_id, $actor_id, 'forum_remove_moderator', '' );
        }

        foreach ( $to_add as $add_user_id ) {
            $user = get_userdata( $add_user_id );
            if ( ! $user ) {
                continue;
            }

            $existing_role = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT role FROM {$members_table} WHERE forum_id = %d AND user_id = %d LIMIT 1",
                    $forum_id,
                    $add_user_id
                )
            );

            if ( null === $existing_role ) {
                $wpdb->insert(
                    $members_table,
                    array(
                        'forum_id'   => $forum_id,
                        'user_id'    => $add_user_id,
                        'role'       => 'moderator',
                        'created_at' => current_time( 'mysql' ),
                    ),
                    array( '%d', '%d', '%s', '%s' )
                );
            } elseif ( 'moderator' !== $existing_role && 'owner' !== $existing_role ) {
                $wpdb->update(
                    $members_table,
                    array( 'role' => 'moderator' ),
                    array(
                        'forum_id' => $forum_id,
                        'user_id'  => $add_user_id,
                    ),
                    array( '%s' ),
                    array( '%d', '%d' )
                );
            }

            $this->log_action( 'forum', $forum_id, $add_user_id, $actor_id, 'forum_add_moderator', '' );
        }

        // 成员变更后清空请求级缓存以保证读一致性
        $this->forum_moderator_cache = array();
        $this->any_forum_moderator_cache = array();

        return array(
            'success' => true,
            'message' => __( '圈子版主已更新', 'qibbs-community' ),
            'added'   => $to_add,
            'removed' => $to_remove,
        );
    }

    /**
     * 校验用户是否对指定帖子具备版务治理权限
     *
     * @param int        $user_id User ID.
     * @param int|object $post    Post ID or post object.
     * @return bool
     */
    public function user_can_moderate_post( $user_id, $post ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        $forum_id = 0;
        if ( is_object( $post ) && isset( $post->forum_id ) ) {
            $forum_id = absint( $post->forum_id );
        } else {
            $post_id = absint( $post );
            if ( $post_id > 0 ) {
                global $wpdb;
                $posts_table = Qibbs_Database::posts();
                $forum_id = (int) $wpdb->get_var(
                    $wpdb->prepare( "SELECT forum_id FROM {$posts_table} WHERE id = %d", $post_id )
                );
            }
        }

        return $forum_id > 0 && $this->is_forum_moderator( $user_id, $forum_id );
    }

    /**
     * 校验用户是否对指定评论具备版务治理权限
     *
     * @param int $user_id    User ID.
     * @param int $comment_id Comment ID.
     * @return bool
     */
    public function user_can_moderate_comment( $user_id, $comment_id ) {
        $user_id    = absint( $user_id );
        $comment_id = absint( $comment_id );
        if ( ! $user_id || ! $comment_id ) {
            return false;
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        global $wpdb;
        $comments_table = Qibbs_Database::comments();
        $posts_table    = Qibbs_Database::posts();

        $forum_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT p.forum_id
                 FROM {$comments_table} c
                 INNER JOIN {$posts_table} p ON p.id = c.post_id
                 WHERE c.id = %d
                 LIMIT 1",
                $comment_id
            )
        );

        return $forum_id > 0 && $this->is_forum_moderator( $user_id, $forum_id );
    }

    /**
     * 写入版务管理操作流水日志
     *
     * @param string $object_type Object type.
     * @param int    $object_id Object ID.
     * @param int    $target_user_id Target user ID.
     * @param int    $admin_id Admin/operator ID.
     * @param string $action Action key.
     * @param string $reason Reason text.
     * @return bool
     */
    public function log_action( $object_type, $object_id, $target_user_id, $admin_id, $action, $reason = '' ) {
        global $wpdb;
        $table = Qibbs_Database::get_table( 'moderation_logs' );

        $inserted = $wpdb->insert(
            $table,
            array(
                'object_type'    => sanitize_key( $object_type ),
                'object_id'      => absint( $object_id ),
                'target_user_id' => absint( $target_user_id ),
                'admin_id'       => absint( $admin_id ),
                'action'         => sanitize_key( $action ),
                'reason'         => sanitize_textarea_field( (string) $reason ),
                'created_at'     => current_time( 'mysql' ),
            ),
            array( '%s', '%d', '%d', '%d', '%s', '%s', '%s' )
        );

        return false !== $inserted;
    }

    /**
     * 查询版务操作日志流水
     *
     * @param int $limit Limit.
     * @return array
     */
    public function get_moderator_logs( $limit = 200 ) {
        global $wpdb;

        $table = Qibbs_Database::get_table( 'moderation_logs' );
        $limit = max( 1, min( 500, absint( $limit ) ) );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                 FROM {$table}
                 WHERE action LIKE %s OR action LIKE %s
                 ORDER BY id DESC
                 LIMIT %d",
                'forum_%',
                'moderator_%',
                $limit
            )
        );
    }
}
