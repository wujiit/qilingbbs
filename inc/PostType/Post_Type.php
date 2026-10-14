<?php
/**
 * 自定义文章类型注册
 *
 * 注册 qibbs_post 自定义文章类型
 * 说明：底层全量采用独立数据表，注册 CPT 仅为兼容 WordPress 原生后台管理生态
 *
 * @package Qibbs_Community
 * @subpackage PostType
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 自定义实体类型管理器
 *
 * 管理自定义文章类型及状态流转
 */
class Qibbs_Post_Type {

    /**
     * 自定义文章类型标识
     *
     * @var string
     */
    const POST_TYPE = 'qibbs_post';

    /**
     * 向系统注册自定义文章类型
     */
    public function register() {
        $labels = array(
            'name'                  => _x( '社区动态', 'Post type general name', 'qibbs-community' ),
            'singular_name'         => _x( '动态', 'Post type singular name', 'qibbs-community' ),
            'menu_name'             => _x( 'Qibbs 社区', 'Admin Menu text', 'qibbs-community' ),
            'name_admin_bar'        => _x( '动态', 'Add New on Toolbar', 'qibbs-community' ),
            'add_new'               => __( '发布动态', 'qibbs-community' ),
            'add_new_item'          => __( '发布新动态', 'qibbs-community' ),
            'new_item'              => __( '新动态', 'qibbs-community' ),
            'edit_item'             => __( '编辑动态', 'qibbs-community' ),
            'view_item'             => __( '查看动态', 'qibbs-community' ),
            'all_items'             => __( '所有动态', 'qibbs-community' ),
            'search_items'          => __( '搜索动态', 'qibbs-community' ),
            'parent_item_colon'     => __( '父级动态:', 'qibbs-community' ),
            'not_found'             => __( '未找到动态', 'qibbs-community' ),
            'not_found_in_trash'    => __( '回收站中未找到动态', 'qibbs-community' ),
            'featured_image'        => _x( '封面图片', 'Overrides the "Featured Image" phrase', 'qibbs-community' ),
            'set_featured_image'    => _x( '设置封面图片', 'Overrides the "Set featured image" phrase', 'qibbs-community' ),
            'remove_featured_image' => _x( '移除封面图片', 'Overrides the "Remove featured image" phrase', 'qibbs-community' ),
            'use_featured_image'    => _x( '用作封面图片', 'Overrides the "Use as featured image" phrase', 'qibbs-community' ),
            'archives'              => _x( '动态存档', 'The post type archive label', 'qibbs-community' ),
            'insert_into_item'      => _x( '插入到动态', 'Overrides the "Insert into post" phrase', 'qibbs-community' ),
            'uploaded_to_this_item' => _x( '上传到此动态', 'Overrides the "Uploaded to this post" phrase', 'qibbs-community' ),
            'filter_items_list'     => _x( '筛选动态列表', 'Screen reader text', 'qibbs-community' ),
            'items_list_navigation' => _x( '动态列表导航', 'Screen reader text', 'qibbs-community' ),
            'items_list'            => _x( '动态列表', 'Screen reader text', 'qibbs-community' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => false,
            'show_in_menu'       => false,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'qibbs' ),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 5,
            'menu_icon'          => 'dashicons-groups',
            'supports'           => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments' ),
            'show_in_rest'       => true,
            'rest_base'          => 'qibbs-posts',
        );

        /**
         * 实体类型注册参数过滤器
         *
         * @since 1.0.0
         * @param array $args Post type arguments.
         */
        $args = apply_filters( 'qibbs_post_type_args', $args );

        register_post_type( self::POST_TYPE, $args );

        // 注册帖子状态流转
        $this->register_post_statuses();
    }

    /**
     * 注册自定义状态（待审、已举报等）
     */
    private function register_post_statuses() {
        // 待审核状态
        register_post_status( 'qibbs_pending', array(
            'label'                     => _x( '待审核', 'post status', 'qibbs-community' ),
            'public'                    => false,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            // translators: %s: Number of posts
            'label_count'               => _n_noop(
                '待审核 <span class="count">(%s)</span>',
                '待审核 <span class="count">(%s)</span>',
                'qibbs-community'
            ),
        ) );

        // 被举报状态
        register_post_status( 'qibbs_reported', array(
            'label'                     => _x( '被举报', 'post status', 'qibbs-community' ),
            'public'                    => false,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            // translators: %s: Number of posts
            'label_count'               => _n_noop(
                '被举报 <span class="count">(%s)</span>',
                '被举报 <span class="count">(%s)</span>',
                'qibbs-community'
            ),
        ) );
    }

    /**
     * 获取文章类型标识名称
     *
     * @return string
     */
    public static function get_post_type() {
        return self::POST_TYPE;
    }
}
