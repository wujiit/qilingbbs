<?php
/**
 * 帖子独立详情页服务类
 *
 * 负责帖子独立页面路由重写、伪静态解析及页面渲染
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Post_Page_Service {

    /**
     * 注册帖子独立详情页 URL 重写规则
     */
    public function add_rewrite_rules() {
        add_rewrite_rule( '^qibbs/post/([0-9]+)/?$', 'index.php?qibbs_post_id=$matches[1]', 'top' );
    }

    /**
     * 注册帖子路由自定义 Query Var
     *
     * @param array $vars Existing query vars.
     * @return array
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'qibbs_post_id';
        return $vars;
    }

    /**
     * 加载帖子详情页视图模板
     *
     * @param string $template Current template.
     * @return string
     */
    public function load_single_post_template( $template ) {
        $post_id = absint( get_query_var( 'qibbs_post_id' ) );

        if ( ! $post_id ) {
            $match = function_exists( 'qibbs_match_post_request_path' )
                ? qibbs_match_post_request_path()
                : array( 'post_id' => 0 );

            if ( ! empty( $match['post_id'] ) ) {
                $post_id = absint( $match['post_id'] );
                qibbs_set_virtual_query_var( 'qibbs_post_id', $post_id );
            }
        }
        
        if ( ! $post_id ) {
            return $template;
        }

        // 校验帖子实体是否存在
        global $wpdb;
        $table = Qibbs_Database::posts();
        $post = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND status = 'publish'",
            $post_id
        ) );

        if ( ! $post ) {
            return get_404_template();
        }

        qibbs_mark_virtual_request_as_resolved();

        // 检查主题级帖子模板覆盖
        $theme_template = locate_template( 'qibbs/single-post.php' );
        if ( $theme_template ) {
            return $theme_template;
        }

        $plugin_template = QIBBS_PLUGIN_DIR . 'templates/single-post.php';
        return file_exists( $plugin_template ) ? $plugin_template : $template;
    }

    /**
     * 查询帖子详情完整数据
     *
     * @param int $post_id Post ID.
     * @return object|null
     */
    public function get_post( $post_id ) {
        $feed_service = new Qibbs_Feed_Service();
        return $feed_service->get_post( $post_id );
    }

    /**
     * 禁用伪静态帖子详情 URL 的规范重定向
     *
     * @param string|false $redirect_url Target redirect URL.
     * @param string       $requested_url Requested URL.
     * @return string|false
     */
    public function disable_post_canonical( $redirect_url, $requested_url ) {
        unset( $requested_url );

        if ( is_admin() || wp_doing_ajax() ) {
            return $redirect_url;
        }

        if ( get_query_var( 'qibbs_post_id' ) ) {
            return false;
        }

        $match = function_exists( 'qibbs_match_post_request_path' )
            ? qibbs_match_post_request_path()
            : array( 'post_id' => 0 );

        if ( ! empty( $match['post_id'] ) ) {
            return false;
        }

        return $redirect_url;
    }
}
