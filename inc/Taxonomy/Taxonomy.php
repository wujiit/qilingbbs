<?php
/**
 * 自定义分类法注册
 *
 * 注册微社区版块（qibbs_forum）与标签（qibbs_tag）分类法
 *
 * @package Qibbs_Community
 * @subpackage Taxonomy
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 分类法注册管理器
 *
 * 管理版块与话题标签自定义分类法
 */
class Qibbs_Taxonomy {

    /**
     * 版块分类法标识
     *
     * @var string
     */
    const FORUM_TAXONOMY = 'qibbs_forum';

    /**
     * 话题标签分类法标识
     *
     * @var string
     */
    const TAG_TAXONOMY = 'qibbs_tag';

    /**
     * 注册微社区自定义分类法
     */
    public function register() {
        $this->register_forum_taxonomy();
        $this->register_tag_taxonomy();
    }

    /**
     * 注册版块/圈子层级分类法
     */
    private function register_forum_taxonomy() {
        $labels = array(
            'name'                       => _x( '圈子', 'taxonomy general name', 'qibbs-community' ),
            'singular_name'              => _x( '圈子', 'taxonomy singular name', 'qibbs-community' ),
            'search_items'               => __( '搜索圈子', 'qibbs-community' ),
            'popular_items'              => __( '热门圈子', 'qibbs-community' ),
            'all_items'                  => __( '所有圈子', 'qibbs-community' ),
            'parent_item'                => __( '父级圈子', 'qibbs-community' ),
            'parent_item_colon'          => __( '父级圈子:', 'qibbs-community' ),
            'edit_item'                  => __( '编辑圈子', 'qibbs-community' ),
            'view_item'                  => __( '查看圈子', 'qibbs-community' ),
            'update_item'                => __( '更新圈子', 'qibbs-community' ),
            'add_new_item'               => __( '添加新圈子', 'qibbs-community' ),
            'new_item_name'              => __( '新圈子名称', 'qibbs-community' ),
            'separate_items_with_commas' => __( '用逗号分隔圈子', 'qibbs-community' ),
            'add_or_remove_items'        => __( '添加或删除圈子', 'qibbs-community' ),
            'choose_from_most_used'      => __( '选择常用圈子', 'qibbs-community' ),
            'not_found'                  => __( '未找到圈子', 'qibbs-community' ),
            'no_terms'                   => __( '没有圈子', 'qibbs-community' ),
            'items_list_navigation'      => __( '圈子列表导航', 'qibbs-community' ),
            'items_list'                 => __( '圈子列表', 'qibbs-community' ),
            'menu_name'                  => __( '圈子', 'qibbs-community' ),
            'back_to_items'              => __( '← 返回圈子', 'qibbs-community' ),
        );

        $args = array(
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_in_menu'      => 'qibbs-settings',
            'show_admin_column' => true,
            'show_in_nav_menus' => true,
            'show_tagcloud'     => false,
            'show_in_rest'      => true,
            'rest_base'         => 'qibbs-forums',
            'rewrite'           => array(
                'slug'         => 'forum',
                'with_front'   => false,
                'hierarchical' => true,
            ),
            'query_var'         => true,
        );

        /**
         * 版块分类法注册参数过滤器
         *
         * @since 1.0.0
         * @param array $args Taxonomy arguments.
         */
        $args = apply_filters( 'qibbs_forum_taxonomy_args', $args );

        register_taxonomy( self::FORUM_TAXONOMY, Qibbs_Post_Type::POST_TYPE, $args );

        // 为版块注册图标与封面 Meta 字段
        $this->register_forum_meta();
    }

    /**
     * 注册扁平话题标签分类法
     */
    private function register_tag_taxonomy() {
        $labels = array(
            'name'                       => _x( '话题标签', 'taxonomy general name', 'qibbs-community' ),
            'singular_name'              => _x( '话题标签', 'taxonomy singular name', 'qibbs-community' ),
            'search_items'               => __( '搜索标签', 'qibbs-community' ),
            'popular_items'              => __( '热门标签', 'qibbs-community' ),
            'all_items'                  => __( '所有标签', 'qibbs-community' ),
            'edit_item'                  => __( '编辑标签', 'qibbs-community' ),
            'view_item'                  => __( '查看标签', 'qibbs-community' ),
            'update_item'                => __( '更新标签', 'qibbs-community' ),
            'add_new_item'               => __( '添加新标签', 'qibbs-community' ),
            'new_item_name'              => __( '新标签名称', 'qibbs-community' ),
            'separate_items_with_commas' => __( '用逗号分隔标签', 'qibbs-community' ),
            'add_or_remove_items'        => __( '添加或删除标签', 'qibbs-community' ),
            'choose_from_most_used'      => __( '选择常用标签', 'qibbs-community' ),
            'not_found'                  => __( '未找到标签', 'qibbs-community' ),
            'no_terms'                   => __( '没有标签', 'qibbs-community' ),
            'items_list_navigation'      => __( '标签列表导航', 'qibbs-community' ),
            'items_list'                 => __( '标签列表', 'qibbs-community' ),
            'menu_name'                  => __( '话题标签', 'qibbs-community' ),
            'back_to_items'              => __( '← 返回标签', 'qibbs-community' ),
        );

        $args = array(
            'labels'            => $labels,
            'hierarchical'      => false,
            'public'            => true,
            'show_ui'           => true,
            'show_in_menu'      => 'qibbs-settings',
            'show_admin_column' => true,
            'show_in_nav_menus' => true,
            'show_tagcloud'     => true,
            'show_in_rest'      => true,
            'rest_base'         => 'qibbs-tags',
            'rewrite'           => array(
                'slug'       => 'topic',
                'with_front' => false,
            ),
            'query_var'         => true,
        );

        /**
         * 标签分类法注册参数过滤器
         *
         * @since 1.0.0
         * @param array $args Taxonomy arguments.
         */
        $args = apply_filters( 'qibbs_tag_taxonomy_args', $args );

        register_taxonomy( self::TAG_TAXONOMY, Qibbs_Post_Type::POST_TYPE, $args );
    }

    /**
     * 批量注册版块 Meta 元数据字段
     */
    private function register_forum_meta() {
        // 版块图标元数据
        register_term_meta( self::FORUM_TAXONOMY, '_qibbs_icon', array(
            'type'              => 'string',
            'description'       => __( '圈子图标', 'qibbs-community' ),
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => 'esc_url_raw',
        ) );

        // 版块封面大图元数据
        register_term_meta( self::FORUM_TAXONOMY, '_qibbs_cover', array(
            'type'              => 'string',
            'description'       => __( '圈子封面', 'qibbs-community' ),
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => 'esc_url_raw',
        ) );

        // 版块专属强调色元数据
        register_term_meta( self::FORUM_TAXONOMY, '_qibbs_color', array(
            'type'              => 'string',
            'description'       => __( '圈子主题色', 'qibbs-community' ),
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => 'sanitize_hex_color',
        ) );

        // 版块私密性设置
        register_term_meta( self::FORUM_TAXONOMY, '_qibbs_private', array(
            'type'              => 'boolean',
            'description'       => __( '是否私密圈子', 'qibbs-community' ),
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ) );

        // 仅允许成员发帖权限配置
        register_term_meta( self::FORUM_TAXONOMY, '_qibbs_member_only', array(
            'type'              => 'boolean',
            'description'       => __( '仅成员可发帖', 'qibbs-community' ),
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
        ) );

        // 排序权重
        register_term_meta( self::FORUM_TAXONOMY, '_qibbs_sort_order', array(
            'type'              => 'integer',
            'description'       => __( '排序顺序', 'qibbs-community' ),
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => 'absint',
        ) );
    }

    /**
     * 获取版块分类法标识名称
     *
     * @return string
     */
    public static function get_forum_taxonomy() {
        return self::FORUM_TAXONOMY;
    }

    /**
     * 获取标签分类法标识名称
     *
     * @return string
     */
    public static function get_tag_taxonomy() {
        return self::TAG_TAXONOMY;
    }
}
