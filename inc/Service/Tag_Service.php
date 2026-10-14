<?php
/**
 * 话题标签管理服务类
 *
 * 基于独立数据表（wp_qibbs_tags）维护标签与发帖计数
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Tag_Service {

    /**
     * 从发帖正文中解析提取 #话题标签
     *
     * @param string $content Post content.
     * @return array Array of tag names.
     */
    public function parse_hashtags( $content ) {
        $tags = array();
        
        // 匹配 #标签名 正则（支持中文、字母数字及下划线）
        if ( preg_match_all( '/#([\p{L}\p{N}_]+)/u', $content, $matches ) ) {
            $tags = array_unique( $matches[1] );
        }
        
        return $tags;
    }

    /**
     * 在自定义标签表中查询或创建话题标签
     *
     * @param string $name Tag name.
     * @return int|false Tag ID or false on failure.
     */
    public function create_or_get_tag( $name ) {
        global $wpdb;
        $table = Qibbs_Database::tags();
        
        $name = sanitize_text_field( $name );
        if ( empty( $name ) ) {
            return false;
        }

        // 检查标签是否已存在
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE name = %s OR slug = %s",
            $name, sanitize_title( $name )
        ) );
        
        if ( $existing ) {
            return (int) $existing->id;
        }

        // 创建新标签（将名称同步作为 slug 标识）
        $result = $wpdb->insert( $table, array(
            'name'       => $name,
            'slug'       => sanitize_title( $name ),
            'post_count' => 0,
            'created_at' => current_time( 'mysql' ),
        ), array( '%s', '%s', '%d', '%s' ) );

        if ( $result ) {
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * 将标签绑定到帖子并原子递增标签发帖数
     *
     * @param int   $post_id Qibbs post ID.
     * @param array $tag_names Array of tag names.
     * @return bool
     */
    public function attach_tags_to_post( $post_id, $tag_names ) {
        global $wpdb;
        $post_tags_table = Qibbs_Database::post_tags();
        
        // 获取帖子历史标签 ID 列表以递减计数
        $old_tag_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT tag_id FROM {$post_tags_table} WHERE post_id = %d",
            $post_id
        ) );
        
        // 清理帖子原有关联标签
        $wpdb->delete( $post_tags_table, array( 'post_id' => $post_id ), array( '%d' ) );
        
        // 绑定新的关联标签
        $new_tag_ids = array();
        foreach ( $tag_names as $name ) {
            $tag_id = $this->create_or_get_tag( $name );
            
            if ( $tag_id ) {
                $wpdb->insert(
                    $post_tags_table,
                    array(
                        'post_id' => $post_id,
                        'tag_id'  => $tag_id,
                    ),
                    array( '%d', '%d' )
                );
                $new_tag_ids[] = $tag_id;
            }
        }

        $affected_tag_ids = array_values( array_unique( array_filter( array_merge( (array) $old_tag_ids, (array) $new_tag_ids ) ) ) );
        if ( ! empty( $affected_tag_ids ) && function_exists( 'qibbs_rebuild_tag_post_counts' ) ) {
            qibbs_rebuild_tag_post_counts( $affected_tag_ids );
        }
        
        return true;
    }

    /**
     * 从自定义数据表查询帖子关联的所有标签
     *
     * @param int $post_id Post ID.
     * @return array
     */
    public function get_post_tags( $post_id ) {
        global $wpdb;
        $post_tags_table = Qibbs_Database::post_tags();
        $tags_table = Qibbs_Database::tags();
        
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT t.* FROM {$tags_table} t
             INNER JOIN {$post_tags_table} pt ON t.id = pt.tag_id
             WHERE pt.post_id = %d",
            $post_id
        ) );
    }

    /**
     * 处理正文并自动识别创建话题标签
     *
     * @param int    $post_id Post ID.
     * @param string $content Post content.
     * @return array Created/attached tag names.
     */
    public function process_content_tags( $post_id, $content ) {
        $tag_names = $this->parse_hashtags( $content );
        
        if ( ! empty( $tag_names ) ) {
            $this->attach_tags_to_post( $post_id, $tag_names );
        }
        
        return $tag_names;
    }

    /**
     * 将正文中的 #话题标签 替换为可点击的筛选链接
     *
     * @param string $content Content with hashtags.
     * @return string Content with linked hashtags.
     */
    public function linkify_hashtags( $content ) {
        global $wpdb;
        $tags_table = Qibbs_Database::tags();
        
        return preg_replace_callback(
            '/#([\p{L}\p{N}_]+)/u',
            function( $matches ) use ( $wpdb, $tags_table ) {
                $tag_name = $matches[1];
                // 从自定义表中查询标签 ID
                $tag = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id FROM {$tags_table} WHERE name = %s OR slug = %s",
                    $tag_name, sanitize_title( $tag_name )
                ) );
                $tag_id = $tag ? $tag->id : 0;
                return '<a href="#" class="qibbs-hashtag qibbs-tag-link" data-filter="' . esc_attr( $tag_id ) . '" data-filter-type="tag" data-filter-name="' . esc_attr( $tag_name ) . '">#' . esc_html( $tag_name ) . '</a>';
            },
            $content
        );
    }

    /**
     * 根据 ID 查询单条标签数据
     *
     * @param int $tag_id Tag ID.
     * @return object|null
     */
    public function get_tag( $tag_id ) {
        global $wpdb;
        $table = Qibbs_Database::tags();
        
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $tag_id
        ) );
    }

    /**
     * 按名称模糊搜索话题标签
     *
     * @param string $search Search term.
     * @param int    $limit  Max results.
     * @return array
     */
    public function search_tags( $search, $limit = 10 ) {
        global $wpdb;
        $table = Qibbs_Database::tags();
        
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE name LIKE %s ORDER BY post_count DESC LIMIT %d",
            '%' . $wpdb->esc_like( $search ) . '%',
            $limit
        ) );
        
        return array_map( function( $tag ) {
            return array(
                'id'    => $tag->id,
                'name'  => $tag->name,
                'slug'  => $tag->slug,
                'count' => $tag->post_count,
            );
        }, $results );
    }

    /**
     * AJAX 接口：话题标签联想搜索
     */
    public function ajax_search_tags() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( function_exists( 'qibbs_require_login' ) && qibbs_require_login() && ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( '请先登录', 'qibbs-community' ) ), 401 );
        }

        if ( ! is_user_logged_in() && function_exists( 'qibbs_rate_limit_by_ip' ) && ! qibbs_rate_limit_by_ip( 'ajax_search_tags', 60, 60 ) ) {
            wp_send_json_error( array( 'message' => __( '请求过于频繁，请稍后再试', 'qibbs-community' ) ), 429 );
        }
        
        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $tags = $this->search_tags( $search );
        
        wp_send_json_success( array( 'tags' => $tags ) );
    }
}
