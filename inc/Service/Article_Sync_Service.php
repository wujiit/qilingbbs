<?php
/**
 * 文章同步服务类
 *
 * 专职负责将 WordPress 原生文章手动同步至微社区
 * 绝不挂绝 publish_post 等原生发布钩子，杜绝核心业务干扰
 * 确保发帖与核心发布解耦隔离
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Article_Sync_Service {

    /**
     * 初始化服务：仅注册异步 AJAX 分发接口
     * 绝不注入干扰原生文章发布的底层钩子
     */
    public function init() {
        // 仅注册用于历史文章同步的手动操作接口
        // 无任何原生文章发布或状态流转入侵钩子
        add_action( 'wp_ajax_qibbs_sync_historical_articles', array( $this, 'ajax_sync_historical_articles' ) );
    }

    /**
     * 手动触发单篇文章同步至社区
     *
     * @param int $post_id WordPress post ID.
     * @return bool True on success, false on failure.
     */
    public function sync_single_article( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'post' || $post->post_status !== 'publish' ) {
            return false;
        }

        // 校验是否已完成同步
        if ( get_post_meta( $post_id, '_qibbs_synced', true ) ) {
            return true; // 已同步则跳过
        }

        // 校验分类筛选配置
        $sync_categories = qibbs_get_option( 'sync_categories', array() );
        if ( ! empty( $sync_categories ) && is_array( $sync_categories ) ) {
            $post_categories = wp_get_post_categories( $post_id );
            if ( empty( array_intersect( $sync_categories, $post_categories ) ) ) {
                return false; // 不在目标同步分类中
            }
        }

        // 提取文章正文摘要
        $excerpt_length = max( 20, (int) qibbs_get_option( 'sync_excerpt_length', 80 ) );
        $content = $this->get_excerpt( $post->post_content, $excerpt_length );

        // 提取文章配图附件
        $media_ids = '';
        if ( qibbs_get_option( 'sync_images', true ) ) {
            $media_ids = $this->get_post_images( $post_id, $post->post_content, $post->post_author );
        }

        // 获取文章所属分类
        $categories = wp_get_post_categories( $post_id );
        $source_category = ! empty( $categories ) ? (int) $categories[0] : 0;

        // 写入微社区帖子数据表（wp_qibbs_posts）
        global $wpdb;
        $table = Qibbs_Database::posts();

        $result = $wpdb->insert(
            $table,
            array(
                'author_id'       => (int) $post->post_author,
                'content'         => $content,
                'status'          => 'publish',
                'forum_id'        => 0,
                'media_ids'       => $media_ids,
                'source_type'     => 'article',
                'source_id'       => (int) $post_id,
                'source_category' => $source_category,
                'created_at'      => $post->post_date,
            ),
            array( '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' )
        );

        if ( $result ) {
            update_post_meta( $post_id, '_qibbs_synced', 1 );
            update_post_meta( $post_id, '_qibbs_post_id', $wpdb->insert_id );
            return true;
        }

        return false;
    }

    /**
     * 从文章正文中提取纯文本摘要
     * 进行深层安全净化
     */
    private function get_excerpt( $content, $length = 80 ) {
        // 优先移除代码块与预格式化文本
        $content = preg_replace( '/<pre\b[^>]*>(.*?)<\/pre>/is', '', $content );
        $content = preg_replace( '/<code\b[^>]*>(.*?)<\/code>/is', '', $content );
        
        // 移除 script 和 style 标签
        $content = preg_replace( '/<script\b[^>]*>(.*?)<\/script>/is', '', $content );
        $content = preg_replace( '/<style\b[^>]*>(.*?)<\/style>/is', '', $content );
        
        // 移除短代码
        $content = strip_shortcodes( $content );
        
        // 剥离全部 HTML 标签
        $content = wp_strip_all_tags( $content );
        
        // 标准化多余空白符与换行
        $content = preg_replace( '/\s+/', ' ', $content );
        $content = trim( $content );
        
        // 截取指定长度摘要
        if ( mb_strlen( $content ) > $length ) {
            $content = mb_substr( $content, 0, $length ) . '...';
        }
        
        // 最终实体安全转义处理
        $content = esc_html( $content );
        
        return $content;
    }

    /**
     * 提取文章内嵌图片
     */
    private function get_post_images( $post_id, $content, $author_id ) {
        $media_ids = array();
        
        // 优先提取特色封面图
        $thumbnail_id = get_post_thumbnail_id( $post_id );
        if ( $thumbnail_id ) {
            $url = wp_get_attachment_url( $thumbnail_id );
            if ( $url ) {
                $id = $this->save_image( $url, $author_id );
                if ( $id ) $media_ids[] = $id;
            }
        }
        
        // 提取正文配图（最多 9 张）
        if ( count( $media_ids ) < 9 ) {
            preg_match_all( '/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', $content, $matches );
            if ( ! empty( $matches[1] ) ) {
                $thumbnail_url = $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : '';
                foreach ( $matches[1] as $url ) {
                    if ( count( $media_ids ) >= 9 ) break;
                    if ( $url === $thumbnail_url ) continue;
                    $id = $this->save_image( $url, $author_id );
                    if ( $id ) $media_ids[] = $id;
                }
            }
        }
        
        return implode( ',', $media_ids );
    }

    /**
     * 将图片信息写入媒体表
     */
    private function save_image( $url, $user_id ) {
        global $wpdb;
        $table = Qibbs_Database::media();
        
        // 检查记录是否已存在
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE file_url = %s", $url
        ) );
        if ( $existing ) return $existing;
        
        // 写入新媒体记录
        $result = $wpdb->insert( $table, array(
            'user_id'    => (int) $user_id,
            'file_path'  => '',
            'file_url'   => $url,
            'file_type'  => 'image',
            'file_size'  => 0,
            'mime_type'  => 'image/jpeg',
            'created_at' => current_time( 'mysql' ),
        ), array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' ) );
        
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * 批量同步历史文章核心方法
     */
    public function sync_historical_articles( $limit = 50 ) {
        if ( ! qibbs_get_option( 'enable_article_sync', false ) ) {
            return array(
                'success' => false,
                'message' => '请先在设置中启用文章同步功能',
                'synced'  => 0,
            );
        }

        $sync_categories = qibbs_get_option( 'sync_categories', array() );
        
        $args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $limit > 0 ? $limit : 50,
            'meta_query'     => array(
                array(
                    'key'     => '_qibbs_synced',
                    'compare' => 'NOT EXISTS',
                ),
            ),
            'orderby' => 'date',
            'order'   => 'DESC',
        );

        if ( ! empty( $sync_categories ) && is_array( $sync_categories ) ) {
            $args['category__in'] = $sync_categories;
        }

        $posts = get_posts( $args );
        
        if ( empty( $posts ) ) {
            return array(
                'success' => true,
                'message' => '没有需要同步的文章',
                'synced'  => 0,
            );
        }
        
        $synced = 0;
        foreach ( $posts as $post ) {
            if ( $this->sync_single_article( $post->ID ) ) {
                $synced++;
            }
        }

        return array(
            'success' => true,
            'message' => sprintf( '成功同步 %d 篇文章', $synced ),
            'synced'  => $synced,
            'total'   => count( $posts ),
        );
    }

    /**
     * 异步 AJAX 接口分发
     */
    public function ajax_sync_historical_articles() {
        check_ajax_referer( 'qibbs_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '权限不足' ) );
        }

        $result = $this->sync_historical_articles( 50 );
        
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * 获取待同步的历史文章总数
     */
    public function get_unsynced_count() {
        $sync_categories = qibbs_get_option( 'sync_categories', array() );
        
        $args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_qibbs_synced',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        );

        if ( ! empty( $sync_categories ) && is_array( $sync_categories ) ) {
            $args['category__in'] = $sync_categories;
        }

        return count( get_posts( $args ) );
    }
}
