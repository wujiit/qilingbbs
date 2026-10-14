<?php
/**
 * 启灵投票系统联动集成类
 *
 * @package Qibbs_Community
 * @subpackage Compatibility
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Qivoting_Integration {

    public function init() {
        // 投票前端资源入队
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // 发帖联动保存投票关联关系
        add_action( 'qibbs_post_created', array( $this, 'handle_post_created' ), 10, 2 );

        // 为帖子批量补充投票数据
        add_filter( 'qibbs_enriched_posts', array( $this, 'enrich_posts' ) );
        
        // AJAX 异步加载投票组件卡片
        add_action( 'wp_ajax_qibbs_get_poll_html', array( $this, 'ajax_get_poll_html' ) );
        if ( function_exists( 'qibbs_allow_guest_access' ) && qibbs_allow_guest_access() ) {
            add_action( 'wp_ajax_nopriv_qibbs_get_poll_html', array( $this, 'ajax_get_poll_html' ) );
        }

        // AJAX 异步创建发帖投票
        add_action( 'wp_ajax_qibbs_create_poll', array( $this, 'ajax_create_poll' ) );
    }

    public function ajax_create_poll() {
        check_ajax_referer( 'qibbs_voting_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( __( '请先登录', 'qibbs-community' ) );
        }

        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $options = isset( $_POST['options'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['options'] ) ) : array();
        $type = isset( $_POST['type'] ) && in_array( wp_unslash( $_POST['type'] ), array( 'single', 'multiple' ), true ) ? wp_unslash( $_POST['type'] ) : 'single';
        $days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 7;

        if ( empty( $title ) || count( $options ) < 2 ) {
            wp_send_json_error( __( '参数错误', 'qibbs-community' ) );
        }

        $now = current_time( 'mysql' );
        $end_time = date( 'Y-m-d H:i:s', strtotime( "+{$days} days", strtotime( $now ) ) );

        $poll_data = array(
            'title'         => $title,
            'options'       => array_values( $options ),
            'type'          => $type,
            'require_login' => 1,
            'max_choices'   => $type === 'multiple' ? count( $options ) : 1,
            'start_time'    => $now,
            'end_time'      => $end_time,
            'status'        => 'active',
            'post_id'       => 0, // 帖子正式发布时绑定关联
        );

        $poll_service = new Qivot_Poll_Service();
        $poll_id = $poll_service->create_poll( $poll_data );

        if ( is_wp_error( $poll_id ) ) {
            wp_send_json_error( $poll_id->get_error_message() );
        }

        wp_send_json_success( array( 'poll_id' => $poll_id ) );
    }

    public function enqueue_assets() {
        if ( is_page_template( 'template-community.php' ) || $this->is_community_page() ) {
            $version = get_option( 'qibbs_cache_version', QIBBS_VERSION );

            // 加载投票系统核心前端依赖
            if ( function_exists( 'qivot_enqueue_frontend_assets' ) ) {
                qivot_enqueue_frontend_assets();
            } else {
                // 兼容旧版本投票系统的降级资源加载
                wp_enqueue_script( 'qivot-plugin-frontend-script' );
                wp_enqueue_style( 'qivot-plugin-frontend-style' );
            }

            wp_enqueue_style( 'qibbs-voting', QIBBS_PLUGIN_URL . 'assets/css/qibbs-voting.css', array( 'qivot-plugin-frontend-style' ), $version );
            wp_enqueue_script( 'qibbs-voting', QIBBS_PLUGIN_URL . 'assets/js/qibbs-voting.js', array( 'jquery', 'qibbs-community', 'qivot-plugin-frontend-script' ), $version, true );
            
            wp_localize_script( 'qibbs-voting', 'qibbsVoting', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'qibbs_voting_nonce' ),
                'i18n'    => array(
                    'createPoll' => __( '发起投票', 'qibbs-community' ),
                    'pollTitle' => __( '投票标题', 'qibbs-community' ),
                    'options' => __( '选项', 'qibbs-community' ),
                    'addOption' => __( '添加选项', 'qibbs-community' ),
                    'create' => __( '创建', 'qibbs-community' ),
                    'cancel' => __( '取消', 'qibbs-community' ),
                    'single' => __( '单选', 'qibbs-community' ),
                    'multiple' => __( '多选', 'qibbs-community' ),
                    'days' => __( '天后结束', 'qibbs-community' ),
                    'pollCreated' => __( '投票已创建', 'qibbs-community' ),
                ),
            ) );
        }
    }

    /**
     * 检查当前请求是否落在社区页面
     */
    private function is_community_page() {
        if ( is_page( 'community' ) ) {
            return true;
        }
        if ( get_query_var( 'qibbs_post_id' ) ) {
            return true;
        }
        if ( is_tax( 'qibbs_topic' ) ) {
            return true;
        }
        return false;
    }

    public function handle_post_created( $post_id, $user_id ) {
        if ( ! isset( $_POST['qibbs_poll_id'] ) || empty( $_POST['qibbs_poll_id'] ) ) {
            return;
        }

        $poll_id = absint( wp_unslash( $_POST['qibbs_poll_id'] ) );
        
        // 安全校验：验证投票存在且归属当前用户
        $poll_service = new Qivot_Poll_Service();
        $poll = $poll_service->get_poll( $poll_id );
        
        if ( $poll && (int)$poll['created_by'] === (int)$user_id ) {
            // 更新投票记录绑定的帖子 ID
            // 微社区使用独立数据表，将微社区帖子 ID 关联写入投票表 post_id 字段
            global $wpdb;
            $polls_table = Qivot_Database::polls();
            $wpdb->update( 
                $polls_table, 
                array( 'post_id' => $post_id ), 
                array( 'id' => $poll_id ), 
                array( '%d' ), 
                array( '%d' ) 
            );
        }
    }

    public function enrich_posts( $posts ) {
        if ( empty( $posts ) ) {
            return $posts;
        }

        if ( ! class_exists( 'Qivot_Database' ) ) {
            return $posts;
        }

        $post_ids = array();
        foreach ( (array) $posts as $post_item ) {
            if ( is_object( $post_item ) && ! empty( $post_item->id ) ) {
                $post_ids[] = absint( $post_item->id );
            }
        }
        $post_ids = array_values( array_unique( array_filter( $post_ids ) ) );
        if ( empty( $post_ids ) ) {
            return $posts;
        }

        global $wpdb;
        $polls_table = Qivot_Database::polls();
        $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
        $poll_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, post_id FROM {$polls_table} WHERE post_id IN ({$placeholders}) ORDER BY id DESC",
                $post_ids
            )
        );

        $poll_map = array();
        foreach ( (array) $poll_rows as $poll_row ) {
            $post_id = (int) $poll_row->post_id;
            // 若存在重复记录，保留最新的投票数据
            if ( $post_id > 0 && ! isset( $poll_map[ $post_id ] ) ) {
                $poll_map[ $post_id ] = (int) $poll_row->id;
            }
        }

        foreach ( $posts as &$post ) {
            $post_id = ( is_object( $post ) && isset( $post->id ) ) ? (int) $post->id : 0;
            if ( $post_id > 0 && isset( $poll_map[ $post_id ] ) ) {
                $post->poll_id = $poll_map[ $post_id ];
            }
        }

        return $posts;
    }

    public function ajax_get_poll_html() {
        check_ajax_referer( 'qibbs_voting_nonce', 'nonce' ); // 使用专用安全 Nonce 校验请求

        if ( function_exists( 'qibbs_require_login' ) && qibbs_require_login() && ! is_user_logged_in() ) {
            wp_send_json_error( __( '请先登录', 'qibbs-community' ), 401 );
        }

        if ( ! is_user_logged_in() && function_exists( 'qibbs_rate_limit_by_ip' ) && ! qibbs_rate_limit_by_ip( 'ajax_get_poll_html', 240, 60 ) ) {
            wp_send_json_error( __( '请求过于频繁，请稍后再试', 'qibbs-community' ), 429 );
        }

        $poll_id = isset( $_POST['poll_id'] ) ? absint( wp_unslash( $_POST['poll_id'] ) ) : 0;
        if ( ! $poll_id ) {
            wp_send_json_error( 'Invalid Poll ID' );
        }

        // 解析并渲染投票短代码 [qivot_poll]
        
        $html = do_shortcode( '[qivot_poll id="' . $poll_id . '"]' );
        
        wp_send_json_success( array( 'html' => $html ) );
    }
}
