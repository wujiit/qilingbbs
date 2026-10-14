<?php
/**
 * 缓存加速与失效策略服务类
 *
 * 统一管理热门流、版块计数及动态快照的高性能缓存与主动失效
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Cache_Service {

    /**
     * 单例实例
     *
     * @var Qibbs_Cache_Service|null
     */
    private static $instance = null;

    /**
     * 获取单例实例
     *
     * @return Qibbs_Cache_Service
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 检查持久化对象缓存（Redis/Memcached）是否可用
     *
     * @return bool
     */
    private static function use_object_cache() {
        return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
    }

    /**
     * 构建对象缓存分组名
     *
     * @param string $bucket Cache bucket.
     * @return string
     */
    private static function get_cache_group( $bucket = 'snapshots' ) {
        return 'qibbs_community_' . sanitize_key( $bucket );
    }

    /**
     * 从对象缓存或 Transient 读取缓存值
     *
     * @param string $key    Cache key.
     * @param string $bucket Cache bucket.
     * @return mixed
     */
    private static function cache_get( $key, $bucket = 'snapshots' ) {
        if ( self::use_object_cache() ) {
            $found = false;
            $value = wp_cache_get( $key, self::get_cache_group( $bucket ), false, $found );
            return $found ? $value : false;
        }

        return get_transient( $key );
    }

    /**
     * 写入对象缓存或 Transient 存储
     *
     * @param string $key    Cache key.
     * @param mixed  $value  Cached payload.
     * @param int    $ttl    Time to live in seconds.
     * @param string $bucket Cache bucket.
     * @return void
     */
    private static function cache_set( $key, $value, $ttl, $bucket = 'snapshots' ) {
        $ttl = max( 1, absint( $ttl ) );

        if ( self::use_object_cache() ) {
            wp_cache_set( $key, $value, self::get_cache_group( $bucket ), $ttl );
            return;
        }

        set_transient( $key, $value, $ttl );
    }

    /**
     * 构建动态流快照缓存键名
     *
     * @param string $prefix  Key prefix.
     * @param string $group   Version group.
     * @param array  $payload Cache payload.
     * @return string
     */
    private static function build_snapshot_key( $prefix, $group, $payload, $scope = 0 ) {
        return $prefix . self::get_version_token( $group, $scope ) . '_' . md5( wp_json_encode( (array) $payload ) );
    }

    /**
     * 标准化动态流快照数据结构
     *
     * @param mixed $cached Cached value.
     * @return array|null
     */
    private static function normalize_snapshot_data( $cached ) {
        if ( ! is_array( $cached ) ) {
            return null;
        }

        if ( ! isset( $cached['ids'] ) || ! is_array( $cached['ids'] ) || ! isset( $cached['total'] ) ) {
            return null;
        }

        return $cached;
    }

    /**
     * 动态列表快照默认缓存有效期（秒）
     *
     * @return int
     */
    private static function get_list_snapshot_ttl() {
        return max( 30, absint( qibbs_get_option( 'global_feed_cache_ttl', 90 ) ) );
    }

    /**
     * 侧边栏与控制台小工具缓存有效期（秒）
     *
     * @return int
     */
    private static function get_sidebar_cache_ttl() {
        $ttl = (int) apply_filters( 'qibbs_sidebar_cache_ttl', 180 );
        return max( 60, $ttl );
    }

    /**
     * 构建作用域级缓存版本存储键
     *
     * @param string $group Cache group.
     * @param int    $scope Scope identifier.
     * @return string
     */
    private static function get_scoped_version_key( $group, $scope ) {
        return 'qibbs_cache_ver_' . sanitize_key( $group ) . '_' . absint( $scope );
    }

    /**
     * 构建全局缓存版本存储键
     *
     * @param string $group Cache group.
     * @return string
     */
    private static function get_global_version_key( $group ) {
        return 'qibbs_cache_ver_' . sanitize_key( $group );
    }

    /**
     * 构建缓存键名所用的版本标识令牌
     *
     * @param string $group Cache group.
     * @param int    $scope Optional scoped identifier.
     * @return string
     */
    private static function get_version_token( $group, $scope = 0 ) {
        $token = (string) self::get_version( $group );

        if ( $scope > 0 ) {
            $token .= '_' . self::get_version( $group, $scope );
        }

        return $token;
    }

    /**
     * 将缓存版本号标准化为正整数字符串
     *
     * @param mixed $version Raw version value.
     * @return string
     */
    private static function normalize_version_value( $version ) {
        $version = preg_replace( '/[^0-9]/', '', (string) $version );
        if ( '' === $version ) {
            return '';
        }

        $version = ltrim( $version, '0' );
        return '' === $version ? '0' : $version;
    }

    /**
     * 比较两个版本号字符串的大小
     *
     * @param string $left  Left version value.
     * @param string $right Right version value.
     * @return int
     */
    private static function compare_version_values( $left, $right ) {
        $left = self::normalize_version_value( $left );
        $right = self::normalize_version_value( $right );

        if ( '' === $left ) {
            $left = '0';
        }

        if ( '' === $right ) {
            $right = '0';
        }

        if ( strlen( $left ) !== strlen( $right ) ) {
            return strlen( $left ) < strlen( $right ) ? -1 : 1;
        }

        return strcmp( $left, $right );
    }

    /**
     * 版本号自增 1
     *
     * @param string $version Current version.
     * @return string
     */
    private static function increment_version_value( $version ) {
        $version = self::normalize_version_value( $version );
        if ( '' === $version || '0' === $version ) {
            return '1';
        }

        $digits = str_split( $version );
        $carry = 1;

        for ( $index = count( $digits ) - 1; $index >= 0 && $carry; $index-- ) {
            $digit = (int) $digits[ $index ] + $carry;
            if ( $digit >= 10 ) {
                $digits[ $index ] = '0';
                $carry = 1;
            } else {
                $digits[ $index ] = (string) $digit;
                $carry = 0;
            }
        }

        if ( $carry ) {
            array_unshift( $digits, '1' );
        }

        return implode( '', $digits );
    }

    /**
     * 基于微秒时间戳生成高精度版本种子
     *
     * @return string
     */
    private static function generate_version_seed() {
        $microtime_parts = explode( ' ', microtime() );
        $seconds = isset( $microtime_parts[1] ) ? preg_replace( '/[^0-9]/', '', (string) $microtime_parts[1] ) : (string) time();
        $microseconds = isset( $microtime_parts[0] ) ? preg_replace( '/[^0-9]/', '', preg_replace( '/^0\./', '', (string) $microtime_parts[0] ) ) : '';
        $microseconds = str_pad( substr( $microseconds, 0, 6 ), 6, '0', STR_PAD_RIGHT );

        return self::normalize_version_value( $seconds . $microseconds );
    }

    /**
     * 生成单调递增的下一版本号令牌
     *
     * @param string $group Cache group.
     * @param int    $scope Optional scoped identifier.
     * @return string
     */
    private static function build_next_version( $group, $scope = 0 ) {
        $current = self::get_version( $group, $scope );
        $candidate = self::generate_version_seed();

        if ( self::compare_version_values( $candidate, $current ) > 0 ) {
            return $candidate;
        }

        return self::increment_version_value( $current );
    }

    /**
     * 获取指定分组的当前缓存版本号
     *
     * @param string $group Cache group.
     * @return string
     */
    private static function get_version( $group, $scope = 0 ) {
        $group = sanitize_key( $group );
        $scope = absint( $scope );

        if ( $scope > 0 ) {
            $version = self::normalize_version_value( self::cache_get( self::get_scoped_version_key( $group, $scope ), 'versions' ) );
            if ( '' !== $version && '0' !== $version ) {
                return $version;
            }

            $version = self::normalize_version_value( get_transient( self::get_scoped_version_key( $group, $scope ) ) );
            if ( '' !== $version && '0' !== $version && self::use_object_cache() ) {
                self::cache_set( self::get_scoped_version_key( $group, $scope ), $version, YEAR_IN_SECONDS, 'versions' );
            }
            return ( '' !== $version && '0' !== $version ) ? $version : '1';
        }

        $version = self::normalize_version_value( self::cache_get( self::get_global_version_key( $group ), 'versions' ) );
        if ( '' !== $version && '0' !== $version ) {
            return $version;
        }

        $version = self::normalize_version_value( get_option( self::get_global_version_key( $group ), '1' ) );
        if ( '' !== $version && '0' !== $version && self::use_object_cache() ) {
            self::cache_set( self::get_global_version_key( $group ), $version, YEAR_IN_SECONDS, 'versions' );
        }
        return ( '' !== $version && '0' !== $version ) ? $version : '1';
    }

    /**
     * 递增缓存版本号以使存量快照全局失效
     *
     * @param string $group Cache group.
     * @return void
     */
    private static function bump_version( $group, $scope = 0 ) {
        $group = sanitize_key( $group );
        $scope = absint( $scope );
        $version = self::build_next_version( $group, $scope );

        if ( $scope > 0 ) {
            self::cache_set( self::get_scoped_version_key( $group, $scope ), $version, YEAR_IN_SECONDS, 'versions' );
            return;
        }

        if ( self::use_object_cache() ) {
            self::cache_set( self::get_global_version_key( $group ), $version, YEAR_IN_SECONDS, 'versions' );
            return;
        }

        update_option( self::get_global_version_key( $group ), $version );
    }

    /**
     * 将 ID 列表标准化为去重的整型数组
     *
     * @param array $ids Source IDs.
     * @return array
     */
    private static function normalize_id_list( $ids ) {
        return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
    }

    /**
     * 获取用户个人动态流的缓存分组标识
     *
     * @param string $feed_key Feed key.
     * @return string
     */
    private static function get_user_feed_group( $feed_key = '' ) {
        $feed_key = sanitize_key( (string) $feed_key );
        return $feed_key ? 'user_feed_' . $feed_key : 'user_feed';
    }

    /**
     * 标准化帖子缓存上下文数据
     *
     * @param array $context Raw context.
     * @return array
     */
    private static function normalize_post_cache_context( $context ) {
        $context = is_array( $context ) ? $context : array();

        return array(
            'author_id'   => isset( $context['author_id'] ) ? absint( $context['author_id'] ) : 0,
            'forum_id'    => isset( $context['forum_id'] ) ? absint( $context['forum_id'] ) : 0,
            'category_id' => isset( $context['category_id'] ) ? absint( $context['category_id'] ) : ( isset( $context['source_category'] ) ? absint( $context['source_category'] ) : 0 ),
            'status'      => isset( $context['status'] ) ? sanitize_key( (string) $context['status'] ) : '',
            'tag_ids'     => self::normalize_id_list( $context['tag_ids'] ?? array() ),
        );
    }

    /**
     * 获取帖子当前的关联缓存上下文
     *
     * @param int $post_id Post ID.
     * @return array
     */
    private static function get_post_cache_context( $post_id ) {
        global $wpdb;

        $post_id = absint( $post_id );
        if ( ! $post_id || ! class_exists( 'Qibbs_Database' ) ) {
            return self::normalize_post_cache_context( array() );
        }

        $posts_table = Qibbs_Database::posts();
        $post_tags_table = Qibbs_Database::post_tags();

        $post = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT author_id, forum_id, source_category, status
                 FROM {$posts_table}
                 WHERE id = %d
                 LIMIT 1",
                $post_id
            ),
            ARRAY_A
        );

        if ( ! is_array( $post ) ) {
            return self::normalize_post_cache_context( array() );
        }

        $post['tag_ids'] = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT tag_id FROM {$post_tags_table} WHERE post_id = %d",
                $post_id
            )
        );

        return self::normalize_post_cache_context( $post );
    }

    /**
     * 查询关注了作者的全部粉丝 ID 列表
     *
     * @param int $author_id Author ID.
     * @return array
     */
    private static function get_follower_user_ids( $author_id ) {
        global $wpdb;

        $author_id = absint( $author_id );
        if ( ! $author_id || ! class_exists( 'Qibbs_Database' ) ) {
            return array();
        }

        $follows_table = Qibbs_Database::follows();

        return self::normalize_id_list(
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT follower_id FROM {$follows_table} WHERE following_id = %d",
                    $author_id
                )
            )
        );
    }

    /**
     * 查询与指定帖子有互动关联的用户列表
     *
     * @param int    $post_id   Post ID.
     * @param string $feed_key  Feed key.
     * @return array
     */
    private static function get_post_feed_user_ids( $post_id, $feed_key ) {
        global $wpdb;

        $post_id = absint( $post_id );
        $feed_key = sanitize_key( (string) $feed_key );
        if ( ! $post_id || ! class_exists( 'Qibbs_Database' ) ) {
            return array();
        }

        if ( 'favorites' === $feed_key ) {
            $table = Qibbs_Database::favorites();
            $column = 'user_id';
        } elseif ( 'likes' === $feed_key ) {
            $table = Qibbs_Database::likes();
            $column = 'user_id';
        } else {
            return array();
        }

        return self::normalize_id_list(
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT {$column} FROM {$table} WHERE post_id = %d",
                    $post_id
                )
            )
        );
    }

    /**
     * 批量失效指定用户的个人动态流缓存
     *
     * @param array  $user_ids User IDs.
     * @param string $feed_key Feed key.
     * @return void
     */
    private static function invalidate_user_feed_users( $user_ids, $feed_key ) {
        foreach ( self::normalize_id_list( $user_ids ) as $user_id ) {
            self::invalidate_user_feed_cache( $user_id, $feed_key );
        }
    }

    /**
     * 检查两个标签列表是否有差异
     *
     * @param array $old_ids Old tag IDs.
     * @param array $new_ids New tag IDs.
     * @return bool
     */
    private static function tag_membership_changed( $old_ids, $new_ids ) {
        $old_ids = self::normalize_id_list( $old_ids );
        $new_ids = self::normalize_id_list( $new_ids );
        sort( $old_ids );
        sort( $new_ids );

        return $old_ids !== $new_ids;
    }

    /**
     * 当帖子可见性变化时精准级联失效受影响的缓存
     *
     * @param int   $post_id  Post ID.
     * @param array $context  Optional current post context.
     * @return void
     */
    private static function invalidate_post_visibility_cache( $post_id, $context = array() ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return;
        }

        $context = ! empty( $context ) ? self::normalize_post_cache_context( $context ) : self::get_post_cache_context( $post_id );

        self::invalidate_global_feed_cache();
        self::invalidate_hot_feed_cache();
        self::invalidate_forum_count_cache();

        if ( ! empty( $context['forum_id'] ) ) {
            self::invalidate_forum_feed_cache( $context['forum_id'] );
        }

        if ( ! empty( $context['category_id'] ) ) {
            self::invalidate_category_feed_cache( $context['category_id'] );
        }

        foreach ( $context['tag_ids'] as $tag_id ) {
            self::invalidate_tag_feed_cache( $tag_id );
        }

        if ( ! empty( $context['author_id'] ) ) {
            self::invalidate_user_feed_users(
                self::get_follower_user_ids( $context['author_id'] ),
                'following'
            );
        }

        self::invalidate_user_feed_users(
            self::get_post_feed_user_ids( $post_id, 'favorites' ),
            'favorites'
        );
        self::invalidate_user_feed_users(
            self::get_post_feed_user_ids( $post_id, 'likes' ),
            'likes'
        );
    }

    /**
     * 帖子维度缓存失效对外公共接口
     *
     * @param int   $post_id Post ID.
     * @param array $context Optional post context.
     * @return void
     */
    public static function invalidate_post_cache( $post_id, $context = array() ) {
        self::invalidate_post_visibility_cache( $post_id, $context );
    }

    /**
     * 失效热门动态流缓存
     *
     * @return void
     */
    public static function invalidate_hot_feed_cache() {
        self::bump_version( 'hot_feed' );
    }

    /**
     * 失效全站最新动态流缓存
     *
     * @return void
     */
    public static function invalidate_global_feed_cache() {
        self::bump_version( 'global_feed' );
    }

    /**
     * 失效指定版块动态流缓存
     *
     * @param int $forum_id Optional forum ID.
     * @return void
     */
    public static function invalidate_forum_feed_cache( $forum_id = 0 ) {
        $forum_id = absint( $forum_id );
        if ( $forum_id > 0 ) {
            self::bump_version( 'forum_feed', $forum_id );
            return;
        }

        self::bump_version( 'forum_feed' );
    }

    /**
     * 失效指定标签动态流缓存
     *
     * @param int $tag_id Optional tag ID.
     * @return void
     */
    public static function invalidate_tag_feed_cache( $tag_id = 0 ) {
        $tag_id = absint( $tag_id );
        if ( $tag_id > 0 ) {
            self::bump_version( 'tag_feed', $tag_id );
            return;
        }

        self::bump_version( 'tag_feed' );
    }

    /**
     * 失效指定分类动态流缓存
     *
     * @param int $category_id Optional category ID.
     * @return void
     */
    public static function invalidate_category_feed_cache( $category_id = 0 ) {
        $category_id = absint( $category_id );
        if ( $category_id > 0 ) {
            self::bump_version( 'category_feed', $category_id );
            return;
        }

        self::bump_version( 'category_feed' );
    }

    /**
     * 失效单个或多个用户的动态流缓存
     *
     * @param int    $user_id  Optional user ID.
     * @param string $feed_key Optional feed key.
     * @return void
     */
    public static function invalidate_user_feed_cache( $user_id = 0, $feed_key = '' ) {
        $user_id = absint( $user_id );
        $feed_key = sanitize_key( (string) $feed_key );

        if ( $feed_key ) {
            $group = self::get_user_feed_group( $feed_key );
            if ( $user_id > 0 ) {
                self::bump_version( $group, $user_id );
                return;
            }

            self::bump_version( $group );
            return;
        }

        foreach ( array( 'following', 'favorites', 'likes' ) as $group_feed_key ) {
            self::invalidate_user_feed_cache( $user_id, $group_feed_key );
        }
    }

    /**
     * 失效版块发帖计数缓存
     *
     * @return void
     */
    public static function invalidate_forum_count_cache() {
        self::bump_version( 'forum_count' );
        self::invalidate_sidebar_cache();
    }

    /**
     * 失效侧边栏小工具缓存
     *
     * @return void
     */
    public static function invalidate_sidebar_cache() {
        self::bump_version( 'sidebar' );
    }

    /**
     * 失效全部动态流相关的聚合缓存
     *
     * @return void
     */
    public static function invalidate_all() {
        self::invalidate_global_feed_cache();
        self::invalidate_hot_feed_cache();
        self::invalidate_forum_count_cache();
        self::invalidate_forum_feed_cache();
        self::invalidate_tag_feed_cache();
        self::invalidate_category_feed_cache();
        self::invalidate_user_feed_cache();
    }

    /**
     * 钩子回调动作方法
     *
     * @return void
     */
    public function invalidate_hot_feed() {
        self::invalidate_hot_feed_cache();
    }

    /**
     * 钩子回调动作方法
     *
     * @return void
     */
    public function invalidate_global_feed() {
        self::invalidate_global_feed_cache();
    }

    /**
     * 钩子回调动作方法
     *
     * @return void
     */
    public function invalidate_user_feed() {
        self::invalidate_user_feed_cache();
    }

    /**
     * 钩子回调动作方法
     *
     * @return void
     */
    public function invalidate_forum_counts() {
        self::invalidate_forum_count_cache();
    }

    /**
     * 钩子回调动作方法
     *
     * @return void
     */
    public function invalidate_sidebar() {
        self::invalidate_sidebar_cache();
    }

    /**
     * 钩子回调动作方法
     *
     * @return void
     */
    public function invalidate_all_cache() {
        self::invalidate_all();
    }

    /**
     * 新帖发布后级联失效相关缓存
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function invalidate_post_created_cache( $post_id, $user_id = 0 ) {
        $context = self::get_post_cache_context( $post_id );
        if ( 'publish' !== ( $context['status'] ?? '' ) ) {
            return;
        }

        self::invalidate_post_visibility_cache( $post_id, $context );
    }

    /**
     * 帖子删除或屏蔽后级联失效相关缓存
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function invalidate_post_visibility_changed_cache( $post_id, $user_id = 0 ) {
        self::invalidate_post_visibility_cache( $post_id );
    }

    /**
     * 帖子编辑更改标签后级联失效相关缓存
     *
     * @param int   $post_id           Post ID.
     * @param int   $user_id           Actor ID.
     * @param array $previous_context  Previous post context.
     * @return void
     */
    public function invalidate_post_edited_cache( $post_id, $user_id = 0, $previous_context = array() ) {
        $has_previous_tags = is_array( $previous_context ) && array_key_exists( 'tag_ids', $previous_context );
        $has_previous_forum = is_array( $previous_context ) && array_key_exists( 'forum_id', $previous_context );
        $has_previous_category = is_array( $previous_context ) && ( array_key_exists( 'category_id', $previous_context ) || array_key_exists( 'source_category', $previous_context ) );
        $previous_context = self::normalize_post_cache_context( $previous_context );
        if ( ! $has_previous_tags && ! $has_previous_forum && ! $has_previous_category ) {
            return;
        }

        $current_context = self::get_post_cache_context( $post_id );

        if ( $has_previous_tags && self::tag_membership_changed( $previous_context['tag_ids'], $current_context['tag_ids'] ) ) {
            foreach ( self::normalize_id_list( array_merge( $previous_context['tag_ids'], $current_context['tag_ids'] ) ) as $tag_id ) {
                self::invalidate_tag_feed_cache( $tag_id );
            }
            self::invalidate_sidebar_cache();
        }

        if ( $has_previous_forum && $previous_context['forum_id'] !== $current_context['forum_id'] ) {
            foreach ( self::normalize_id_list( array( $previous_context['forum_id'], $current_context['forum_id'] ) ) as $forum_id ) {
                self::invalidate_forum_feed_cache( $forum_id );
            }
        }

        if ( $has_previous_category && $previous_context['category_id'] !== $current_context['category_id'] ) {
            foreach ( self::normalize_id_list( array( $previous_context['category_id'], $current_context['category_id'] ) ) as $category_id ) {
                self::invalidate_category_feed_cache( $category_id );
            }
        }
    }

    /**
     * 点赞状态变更后刷新对应缓存
     *
     * @param int $user_id User ID.
     * @return void
     */
    public function invalidate_like_toggle_cache( $user_id, $post_id = 0, $liked = false ) {
        self::invalidate_hot_feed_cache();
        self::invalidate_user_feed_cache( $user_id, 'likes' );
    }

    /**
     * 收藏状态变更后刷新对应缓存
     *
     * @param int $user_id User ID.
     * @return void
     */
    public function invalidate_favorite_toggle_cache( $user_id, $post_id = 0, $favorited = false ) {
        self::invalidate_user_feed_cache( $user_id, 'favorites' );
    }

    /**
     * 关注状态变更后刷新对应缓存
     *
     * @param int $follower_id Follower ID.
     * @return void
     */
    public function invalidate_follow_toggle_cache( $follower_id, $following_id = 0, $following = false ) {
        self::invalidate_user_feed_cache( $follower_id, 'following' );
    }

    /**
     * 获取全站最新动态流缓存快照
     *
     * @param array $args Feed args.
     * @return array|null
     */
    public static function get_global_feed_snapshot( $args ) {
        $payload = array(
            'page'     => max( 1, absint( $args['page'] ?? 1 ) ),
            'per_page' => max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) ),
            'status'   => sanitize_key( $args['status'] ?? 'publish' ),
            'orderby'  => sanitize_key( $args['orderby'] ?? 'created_at' ),
            'order'    => strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
        );

        $key = self::build_snapshot_key( 'qibbs_global_', 'global_feed', $payload );
        return self::normalize_snapshot_data( self::cache_get( $key ) );
    }

    /**
     * 写入全站最新动态流缓存快照
     *
     * @param array $args Feed args.
     * @param array $data Snapshot with ids/total.
     * @return void
     */
    public static function set_global_feed_snapshot( $args, $data ) {
        $payload = array(
            'page'     => max( 1, absint( $args['page'] ?? 1 ) ),
            'per_page' => max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) ),
            'status'   => sanitize_key( $args['status'] ?? 'publish' ),
            'orderby'  => sanitize_key( $args['orderby'] ?? 'created_at' ),
            'order'    => strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
        );

        $key = self::build_snapshot_key( 'qibbs_global_', 'global_feed', $payload );
        $ttl = self::get_list_snapshot_ttl();

        self::cache_set(
            $key,
            array(
                'ids'   => array_map( 'absint', (array) ( $data['ids'] ?? array() ) ),
                'total' => absint( $data['total'] ?? 0 ),
                'has_more' => isset( $data['has_more'] ) ? (bool) $data['has_more'] : null,
            ),
            $ttl
        );
    }

    /**
     * 获取版块动态流缓存快照
     *
     * @param int   $forum_id Forum ID.
     * @param array $args     Feed args.
     * @return array|null
     */
    public static function get_forum_feed_snapshot( $forum_id, $args ) {
        $payload = array(
            'forum_id' => absint( $forum_id ),
            'page'     => max( 1, absint( $args['page'] ?? 1 ) ),
            'per_page' => max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) ),
            'status'   => sanitize_key( $args['status'] ?? 'publish' ),
        );

        $key = self::build_snapshot_key( 'qibbs_forum_feed_', 'forum_feed', $payload, $forum_id );
        return self::normalize_snapshot_data( self::cache_get( $key ) );
    }

    /**
     * 写入版块动态流缓存快照
     *
     * @param int   $forum_id Forum ID.
     * @param array $args     Feed args.
     * @param array $data     Snapshot with ids/total.
     * @return void
     */
    public static function set_forum_feed_snapshot( $forum_id, $args, $data ) {
        $payload = array(
            'forum_id' => absint( $forum_id ),
            'page'     => max( 1, absint( $args['page'] ?? 1 ) ),
            'per_page' => max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) ),
            'status'   => sanitize_key( $args['status'] ?? 'publish' ),
        );

        $key = self::build_snapshot_key( 'qibbs_forum_feed_', 'forum_feed', $payload, $forum_id );
        self::cache_set(
            $key,
            array(
                'ids'   => array_map( 'absint', (array) ( $data['ids'] ?? array() ) ),
                'total' => absint( $data['total'] ?? 0 ),
                'has_more' => isset( $data['has_more'] ) ? (bool) $data['has_more'] : null,
            ),
            self::get_list_snapshot_ttl()
        );
    }

    /**
     * 获取标签动态流缓存快照
     *
     * @param int   $tag_id Tag ID.
     * @param array $args   Feed args.
     * @return array|null
     */
    public static function get_tag_feed_snapshot( $tag_id, $args ) {
        $payload = array(
            'tag_id'   => absint( $tag_id ),
            'page'     => max( 1, absint( $args['page'] ?? 1 ) ),
            'per_page' => max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) ),
            'status'   => sanitize_key( $args['status'] ?? 'publish' ),
        );

        $key = self::build_snapshot_key( 'qibbs_tag_feed_', 'tag_feed', $payload, $tag_id );
        return self::normalize_snapshot_data( self::cache_get( $key ) );
    }

    /**
     * 写入标签动态流缓存快照
     *
     * @param int   $tag_id Tag ID.
     * @param array $args   Feed args.
     * @param array $data   Snapshot with ids/total.
     * @return void
     */
    public static function set_tag_feed_snapshot( $tag_id, $args, $data ) {
        $payload = array(
            'tag_id'   => absint( $tag_id ),
            'page'     => max( 1, absint( $args['page'] ?? 1 ) ),
            'per_page' => max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) ),
            'status'   => sanitize_key( $args['status'] ?? 'publish' ),
        );

        $key = self::build_snapshot_key( 'qibbs_tag_feed_', 'tag_feed', $payload, $tag_id );
        self::cache_set(
            $key,
            array(
                'ids'   => array_map( 'absint', (array) ( $data['ids'] ?? array() ) ),
                'total' => absint( $data['total'] ?? 0 ),
                'has_more' => isset( $data['has_more'] ) ? (bool) $data['has_more'] : null,
            ),
            self::get_list_snapshot_ttl()
        );
    }

    /**
     * 获取分类动态流缓存快照
     *
     * @param int   $category_id Category ID.
     * @param array $args        Feed args.
     * @return array|null
     */
    public static function get_category_feed_snapshot( $category_id, $args ) {
        $payload = array(
            'category_id' => absint( $category_id ),
            'page'        => max( 1, absint( $args['page'] ?? 1 ) ),
            'per_page'    => max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) ),
            'status'      => sanitize_key( $args['status'] ?? 'publish' ),
        );

        $key = self::build_snapshot_key( 'qibbs_category_feed_', 'category_feed', $payload, $category_id );
        return self::normalize_snapshot_data( self::cache_get( $key ) );
    }

    /**
     * 写入分类动态流缓存快照
     *
     * @param int   $category_id Category ID.
     * @param array $args        Feed args.
     * @param array $data        Snapshot with ids/total.
     * @return void
     */
    public static function set_category_feed_snapshot( $category_id, $args, $data ) {
        $payload = array(
            'category_id' => absint( $category_id ),
            'page'        => max( 1, absint( $args['page'] ?? 1 ) ),
            'per_page'    => max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) ),
            'status'      => sanitize_key( $args['status'] ?? 'publish' ),
        );

        $key = self::build_snapshot_key( 'qibbs_category_feed_', 'category_feed', $payload, $category_id );
        self::cache_set(
            $key,
            array(
                'ids'   => array_map( 'absint', (array) ( $data['ids'] ?? array() ) ),
                'total' => absint( $data['total'] ?? 0 ),
                'has_more' => isset( $data['has_more'] ) ? (bool) $data['has_more'] : null,
            ),
            self::get_list_snapshot_ttl()
        );
    }

    /**
     * 获取用户个人动态流缓存快照
     *
     * @param string $feed_key Feed key.
     * @param int    $user_id  User ID.
     * @param array  $args     Feed args.
     * @return array|null
     */
    public static function get_user_feed_snapshot( $feed_key, $user_id, $args ) {
        $payload = array(
            'feed'     => sanitize_key( $feed_key ),
            'user_id'  => absint( $user_id ),
            'page'     => max( 1, absint( $args['page'] ?? 1 ) ),
            'per_page' => max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) ),
            'status'   => sanitize_key( $args['status'] ?? 'publish' ),
        );

        if ( empty( $payload['feed'] ) || empty( $payload['user_id'] ) ) {
            return null;
        }

        $key = self::build_snapshot_key( 'qibbs_user_feed_', self::get_user_feed_group( $feed_key ), $payload, $user_id );
        return self::normalize_snapshot_data( self::cache_get( $key ) );
    }

    /**
     * 写入用户个人动态流缓存快照
     *
     * @param string $feed_key Feed key.
     * @param int    $user_id  User ID.
     * @param array  $args     Feed args.
     * @param array  $data     Snapshot with ids/total.
     * @return void
     */
    public static function set_user_feed_snapshot( $feed_key, $user_id, $args, $data ) {
        $payload = array(
            'feed'     => sanitize_key( $feed_key ),
            'user_id'  => absint( $user_id ),
            'page'     => max( 1, absint( $args['page'] ?? 1 ) ),
            'per_page' => max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) ),
            'status'   => sanitize_key( $args['status'] ?? 'publish' ),
        );

        if ( empty( $payload['feed'] ) || empty( $payload['user_id'] ) ) {
            return;
        }

        $key = self::build_snapshot_key( 'qibbs_user_feed_', self::get_user_feed_group( $feed_key ), $payload, $user_id );
        $ttl = max( 20, absint( qibbs_get_option( 'user_feed_cache_ttl', 60 ) ) );

        self::cache_set(
            $key,
            array(
                'ids'   => array_map( 'absint', (array) ( $data['ids'] ?? array() ) ),
                'total' => absint( $data['total'] ?? 0 ),
                'has_more' => isset( $data['has_more'] ) ? (bool) $data['has_more'] : null,
            ),
            $ttl
        );
    }

    /**
     * 获取热门动态流缓存快照
     *
     * @param array $args Feed args.
     * @return array|null
     */
    public static function get_hot_feed_snapshot( $args ) {
        $payload = array(
            'page'     => max( 1, absint( $args['page'] ?? 1 ) ),
            'per_page' => max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) ),
            'status'   => sanitize_key( $args['status'] ?? 'publish' ),
            'hot_window' => absint( $args['hot_window'] ?? 0 ),
        );

        $key = 'qibbs_hot_' . self::get_version( 'hot_feed' ) . '_' . md5( wp_json_encode( $payload ) );
        $cached = self::cache_get( $key );

        if ( ! is_array( $cached ) ) {
            return null;
        }

        if ( ! isset( $cached['ids'] ) || ! is_array( $cached['ids'] ) || ! isset( $cached['total'] ) ) {
            return null;
        }

        return $cached;
    }

    /**
     * 写入热门动态流缓存快照
     *
     * @param array $args Feed args.
     * @param array $data Snapshot with ids/total.
     * @return void
     */
    public static function set_hot_feed_snapshot( $args, $data ) {
        $payload = array(
            'page'     => max( 1, absint( $args['page'] ?? 1 ) ),
            'per_page' => max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) ),
            'status'   => sanitize_key( $args['status'] ?? 'publish' ),
            'hot_window' => absint( $args['hot_window'] ?? 0 ),
        );

        $key = 'qibbs_hot_' . self::get_version( 'hot_feed' ) . '_' . md5( wp_json_encode( $payload ) );
        $ttl = max( 30, absint( qibbs_get_option( 'hot_feed_cache_ttl', 120 ) ) );

        self::cache_set( $key, array(
            'ids'   => array_map( 'absint', (array) ( $data['ids'] ?? array() ) ),
            'total' => absint( $data['total'] ?? 0 ),
            'has_more' => isset( $data['has_more'] ) ? (bool) $data['has_more'] : null,
        ), $ttl );
    }

    /**
     * 读取带缓存的版块发帖计数
     *
     * @param array $forum_ids Forum IDs.
     * @return array
     */
    public static function get_forum_post_counts( $forum_ids ) {
        global $wpdb;

        $forum_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $forum_ids ) ) ) );
        if ( empty( $forum_ids ) ) {
            return array();
        }

        sort( $forum_ids );
        $cache_key = 'qibbs_forum_cnt_' . self::get_version( 'forum_count' ) . '_' . md5( implode( ',', $forum_ids ) );
        $cached = self::cache_get( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $posts_table = Qibbs_Database::posts();
        $placeholders = implode( ',', array_fill( 0, count( $forum_ids ), '%d' ) );

        $count_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT forum_id, COUNT(*) AS total FROM {$posts_table}
                 WHERE status = 'publish' AND forum_id IN ({$placeholders})
                 GROUP BY forum_id",
                $forum_ids
            )
        );

        $result = array();
        foreach ( $forum_ids as $forum_id ) {
            $result[ $forum_id ] = 0;
        }

        foreach ( (array) $count_rows as $count_row ) {
            $result[ (int) $count_row->forum_id ] = (int) $count_row->total;
        }

        $ttl = max( 60, absint( qibbs_get_option( 'forum_count_cache_ttl', 300 ) ) );
        self::cache_set( $cache_key, $result, $ttl );

        return $result;
    }

    /**
     * 读取侧边栏版块列表缓存
     *
     * @param int $limit Number of forums.
     * @return array
     */
    public static function get_sidebar_forums( $limit = 50 ) {
        global $wpdb;

        $limit = max( 1, min( 100, absint( $limit ) ) );
        $key   = self::build_snapshot_key(
            'qibbs_sidebar_forums_',
            'sidebar',
            array( 'limit' => $limit )
        );
        $cached = self::cache_get( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $forums_table = Qibbs_Database::forums();
        $forums       = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, icon, sort_order
                 FROM {$forums_table}
                 ORDER BY sort_order ASC, id ASC
                 LIMIT %d",
                $limit
            )
        );

        $forums = is_array( $forums ) ? $forums : array();
        self::cache_set( $key, $forums, self::get_sidebar_cache_ttl() );

        return $forums;
    }

    /**
     * 读取侧边栏热门标签缓存
     *
     * @param int $limit Number of tags.
     * @return array
     */
    public static function get_hot_tags( $limit = 10 ) {
        global $wpdb;

        $limit = max( 1, min( 50, absint( $limit ) ) );
        $key   = self::build_snapshot_key(
            'qibbs_sidebar_tags_',
            'sidebar',
            array( 'limit' => $limit )
        );
        $cached = self::cache_get( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $tags_table = Qibbs_Database::tags();
        $tags       = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, slug, post_count
                 FROM {$tags_table}
                 ORDER BY post_count DESC, id DESC
                 LIMIT %d",
                $limit
            )
        );

        $tags = is_array( $tags ) ? $tags : array();
        self::cache_set( $key, $tags, self::get_sidebar_cache_ttl() );

        return $tags;
    }

    /**
     * 读取侧边栏社区统计数据缓存
     *
     * @return array
     */
    public static function get_community_stats() {
        global $wpdb;

        $key    = self::build_snapshot_key( 'qibbs_sidebar_stats_', 'sidebar', array( 'community' => 1 ) );
        $cached = self::cache_get( $key );
        if ( is_array( $cached ) && isset( $cached['total_posts'], $cached['total_users'], $cached['total_comments'] ) ) {
            return $cached;
        }

        $posts_table    = Qibbs_Database::posts();
        $comments_table = Qibbs_Database::comments();
        $stats          = array(
            'total_posts'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts_table} WHERE status = %s", 'publish' ) ),
            'total_users'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE %d = %d", 1, 1 ) ),
            'total_comments' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$comments_table} WHERE status = %s", 'approved' ) ),
        );

        self::cache_set( $key, $stats, self::get_sidebar_cache_ttl() );

        return $stats;
    }
}
