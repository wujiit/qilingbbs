<?php
/**
 * REST API 接口控制器
 *
 * @package Qibbs_Community
 * @subpackage REST
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_REST_Controller {

    protected $namespace = 'qibbs/v1';

    public function register_routes() {
        // 动态流路由端点
        register_rest_route( $this->namespace, '/feed', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_feed' ),
            'permission_callback' => array( $this, 'check_public_access' ),
        ) );

        register_rest_route( $this->namespace, '/feed/following', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_following_feed' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ) );

        // 帖子操作路由端点
        register_rest_route( $this->namespace, '/post', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_post' ),
            'permission_callback' => array( $this, 'check_can_post' ),
        ) );

        register_rest_route( $this->namespace, '/post/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_post' ),
            'permission_callback' => array( $this, 'check_public_access' ),
        ) );

        // 点赞互动路由端点
        register_rest_route( $this->namespace, '/like', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'toggle_like' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ) );

        // 收藏互动路由端点
        register_rest_route( $this->namespace, '/favorite', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'toggle_favorite' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ) );

        // 关注互动路由端点
        register_rest_route( $this->namespace, '/follow', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'toggle_follow' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ) );

        // 私信接口路由端点
        register_rest_route( $this->namespace, '/message', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'send_message' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ) );

        register_rest_route( $this->namespace, '/messages/(?P<partner_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_messages' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ) );

        // 用户主页路由端点
        register_rest_route( $this->namespace, '/user/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_user' ),
            'permission_callback' => array( $this, 'check_public_access' ),
        ) );

        // 通知接口路由端点
        register_rest_route( $this->namespace, '/notifications', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_notifications' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ) );
        
        // 精华帖管理路由端点
        register_rest_route( $this->namespace, '/feed/featured', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_featured_feed' ),
            'permission_callback' => array( $this, 'check_public_access' ),
        ) );
        
        register_rest_route( $this->namespace, '/post/(?P<id>\d+)/feature', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'feature_post' ),
            'permission_callback' => array( $this, 'check_admin' ),
        ) );
        
        register_rest_route( $this->namespace, '/post/(?P<id>\d+)/feature', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'unfeature_post' ),
            'permission_callback' => array( $this, 'check_admin' ),
        ) );

        // 每日签到打卡路由端点
        register_rest_route( $this->namespace, '/user/checkin', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_checkin' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ) );

        register_rest_route( $this->namespace, '/user/checkin-makeup', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_checkin_makeup' ),
            'permission_callback' => array( $this, 'check_logged_in' ),
        ) );

        register_rest_route( $this->namespace, '/user/checkin-status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_checkin_status' ),
            'permission_callback' => array( $this, 'check_public_access' ),
        ) );
    }

    // REST 请求权限校验回调
    public function check_logged_in() {
        return is_user_logged_in();
    }

    /**
     * 游客可读模式下的公开接口权限校验
     *
     * @return bool
     */
    public function check_public_access() {
        if ( function_exists( 'qibbs_require_login' ) && qibbs_require_login() ) {
            return is_user_logged_in();
        }
        return true;
    }

    public function check_can_post() {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        return qibbs_user_can_post( get_current_user_id() );
    }

    // REST 标准响应构建辅助方法
    private function success( $data ) {
        return new WP_REST_Response( array( 'success' => true, 'data' => $data ), 200 );
    }

    private function error( $message, $code = 400 ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => $message ), $code );
    }

    /**
     * 标准化分页页码参数
     *
     * @param mixed $page Page value.
     * @return int
     */
    private function normalize_page( $page ) {
        $page = absint( $page );
        return $page > 0 ? $page : 1;
    }

    /**
     * 标准化并限定每页条数参数边界
     *
     * @param mixed $per_page Per-page value.
     * @param int   $default  Default value.
     * @param int   $max      Maximum allowed value.
     * @return int
     */
    private function normalize_per_page( $per_page, $default = 20, $max = 50 ) {
        $per_page = absint( $per_page );
        if ( $per_page <= 0 ) {
            $per_page = (int) $default;
        }
        if ( $per_page > $max ) {
            $per_page = (int) $max;
        }
        return $per_page;
    }

    // 动态流路由端点
    public function get_feed( $request ) {
        if ( ! is_user_logged_in() && function_exists( 'qibbs_rate_limit_by_ip' ) && ! qibbs_rate_limit_by_ip( 'rest_feed', 90, 60 ) ) {
            return $this->error( __( '请求过于频繁，请稍后再试', 'qibbs-community' ), 429 );
        }

        $feed_service = new Qibbs_Feed_Service();
        $page = $this->normalize_page( $request->get_param( 'page' ) );
        $per_page = $this->normalize_per_page( $request->get_param( 'per_page' ), 20, 50 );
        $query_args = array(
            'page'          => $page,
            'per_page'      => $per_page,
            'query'         => (string) $request->get_param( 'query' ),
            'author_query'  => (string) $request->get_param( 'author' ),
            'tag_query'     => (string) $request->get_param( 'tag' ),
            'post_type'     => (string) $request->get_param( 'post_type' ),
            'resolved'      => (string) $request->get_param( 'resolved' ),
            'sort'          => (string) $request->get_param( 'sort' ),
            'featured_only' => rest_sanitize_boolean( $request->get_param( 'featured_only' ) ),
        );

        $forum_id = absint( $request->get_param( 'forum_id' ) );
        $tag_id = absint( $request->get_param( 'tag_id' ) );
        $category_id = absint( $request->get_param( 'category_id' ) );

        if ( $forum_id ) {
            $query_args['filter_type'] = 'forum';
            $query_args['filter_id']   = $forum_id;
            $result = $feed_service->search_feed( $query_args );
        } elseif ( $tag_id ) {
            $query_args['filter_type'] = 'tag';
            $query_args['filter_id']   = $tag_id;
            $result = $feed_service->search_feed( $query_args );
        } elseif ( $category_id ) {
            $query_args['filter_type'] = 'category';
            $query_args['filter_id']   = $category_id;
            $result = $feed_service->search_feed( $query_args );
        } else {
            $result = $feed_service->get_global_feed( $query_args );
        }
        return $this->success( $result );
    }

    public function get_following_feed( $request ) {
        $feed_service = new Qibbs_Feed_Service();
        $page = $this->normalize_page( $request->get_param( 'page' ) );

        $result = $feed_service->get_following_feed( get_current_user_id(), array(
            'page' => $page,
        ) );
        return $this->success( $result );
    }

    // 帖子操作路由端点
    public function get_post( $request ) {
        if ( ! is_user_logged_in() && function_exists( 'qibbs_rate_limit_by_ip' ) && ! qibbs_rate_limit_by_ip( 'rest_post', 120, 60 ) ) {
            return $this->error( __( '请求过于频繁，请稍后再试', 'qibbs-community' ), 429 );
        }

        $feed_service = new Qibbs_Feed_Service();
        $post = $feed_service->get_post( $request['id'] );
        if ( ! $post ) {
            return $this->error( __( '帖子不存在', 'qibbs-community' ), 404 );
        }

        $current_user_id = get_current_user_id();
        $is_owner = $current_user_id && (int) $post->author_id === (int) $current_user_id;
        $is_admin = current_user_can( 'manage_options' );
        if ( 'publish' !== $post->status && ! $is_owner && ! $is_admin ) {
            return $this->error( __( '帖子不存在', 'qibbs-community' ), 404 );
        }

        return $this->success( $post );
    }

    public function create_post( $request ) {
        $ajax_handler = new Qibbs_Ajax_Handler();
        $result = $ajax_handler->create_post_from_payload( get_current_user_id(), array(
            'content'   => (string) $request->get_param( 'content' ),
            'forum_id'  => absint( $request->get_param( 'forum_id' ) ),
            'post_type' => sanitize_key( (string) $request->get_param( 'post_type' ) ),
            'media_ids' => sanitize_text_field( (string) $request->get_param( 'media_ids' ) ),
            'tags'      => $request->get_param( 'tags' ),
        ) );

        if ( ! $result['success'] ) {
            return $this->error( $result['message'], isset( $result['code'] ) ? (int) $result['code'] : 400 );
        }

        return $this->success( array(
            'id'      => $result['post_id'],
            'status'  => $result['status'],
            'message' => $result['message'],
        ) );
    }

    // 点赞/取消赞接口处理
    public function toggle_like( $request ) {
        if ( ! qibbs_is_module_enabled( 'likes' ) ) {
            return $this->error( qibbs_get_module_disabled_message( 'likes' ), 403 );
        }

        $user_id = get_current_user_id();
        $post_id = absint( $request->get_param( 'post_id' ) );

        $can_like = apply_filters( 'qibbs_can_like', true, $user_id, $post_id );
        if ( ! $can_like ) {
            return $this->error( __( '没有点赞权限', 'qibbs-community' ), 403 );
        }

        $like_service = new Qibbs_Like_Service();
        if ( ! $like_service->check_rate_limit( $user_id ) ) {
            return $this->error( __( '操作过于频繁，请稍后再试', 'qibbs-community' ), 429 );
        }

        $result = $like_service->toggle_like( $user_id, $post_id );
        return $result['success'] ? $this->success( $result ) : $this->error( $result['message'] );
    }

    // 收藏/取消收藏接口处理
    public function toggle_favorite( $request ) {
        if ( ! qibbs_is_module_enabled( 'favorites' ) ) {
            return $this->error( qibbs_get_module_disabled_message( 'favorites' ), 403 );
        }

        $favorite_service = new Qibbs_Favorite_Service();
        $result = $favorite_service->toggle_favorite( get_current_user_id(), absint( $request->get_param( 'post_id' ) ) );
        return $result['success'] ? $this->success( $result ) : $this->error( $result['message'] );
    }

    // 关注/取消关注接口处理
    public function toggle_follow( $request ) {
        if ( ! qibbs_is_module_enabled( 'follows' ) ) {
            return $this->error( qibbs_get_module_disabled_message( 'follows' ), 403 );
        }

        $follow_service = new Qibbs_Follow_Service();
        $result = $follow_service->toggle_follow( get_current_user_id(), absint( $request->get_param( 'user_id' ) ) );
        return $result['success'] ? $this->success( $result ) : $this->error( $result['message'] );
    }

    // 私信接口路由端点
    public function send_message( $request ) {
        if ( ! qibbs_is_module_enabled( 'messages' ) ) {
            return $this->error( qibbs_get_module_disabled_message( 'messages' ), 403 );
        }

        $message_service = new Qibbs_Message_Service();
        $result = $message_service->send_message(
            get_current_user_id(),
            absint( $request->get_param( 'receiver_id' ) ),
            sanitize_textarea_field( $request->get_param( 'content' ) )
        );
        return $result['success'] ? $this->success( $result ) : $this->error( $result['message'] );
    }

    public function get_messages( $request ) {
        if ( ! qibbs_is_module_enabled( 'messages' ) ) {
            return $this->error( qibbs_get_module_disabled_message( 'messages' ), 403 );
        }

        $message_service = new Qibbs_Message_Service();
        $messages = $message_service->get_messages( get_current_user_id(), $request['partner_id'] );
        return $this->success( array( 'messages' => $messages ) );
    }

    // 用户数据接口处理
    public function get_user( $request ) {
        $profile_service = new Qibbs_Profile_Service();
        $profile = $profile_service->get_profile( $request['id'] );
        return $profile ? $this->success( $profile ) : $this->error( __( '用户不存在', 'qibbs-community' ), 404 );
    }

    // 通知列表接口处理
    public function get_notifications( $request ) {
        if ( ! qibbs_is_module_enabled( 'notifications' ) ) {
            return $this->error( qibbs_get_module_disabled_message( 'notifications' ), 403 );
        }

        $notification_service = new Qibbs_Notification_Service();
        $notifications = $notification_service->get_notifications( get_current_user_id() );
        $unread = $notification_service->get_unread_count( get_current_user_id() );
        return $this->success( array( 'notifications' => $notifications, 'unread_count' => $unread ) );
    }
    
    /**
     * 检查管理员权限
     */
    public function check_admin() {
        return current_user_can( 'manage_options' );
    }
    
    /**
     * 获取推荐动态列表
     */
    public function get_featured_feed( $request ) {
        if ( ! is_user_logged_in() && function_exists( 'qibbs_rate_limit_by_ip' ) && ! qibbs_rate_limit_by_ip( 'rest_featured_feed', 90, 60 ) ) {
            return $this->error( __( '请求过于频繁，请稍后再试', 'qibbs-community' ), 429 );
        }

        $featured_service = new Qibbs_Featured_Service();
        $feed_service = new Qibbs_Feed_Service();
        
        $page = $this->normalize_page( $request->get_param( 'page' ) );
        $per_page = $this->normalize_per_page( $request->get_param( 'per_page' ), 20, 50 );
        
        $posts = $featured_service->get_featured_posts( $page, $per_page );
        $total = $featured_service->get_featured_count();
        
        // 格式化帖子数据
        $formatted_posts = array();
        foreach ( $posts as $post ) {
            $formatted = $feed_service->format_post( $post );
            if ( $formatted ) {
                $formatted_posts[] = $formatted;
            }
        }
        
        return $this->success( array(
            'posts'    => $formatted_posts,
            'page'     => $page,
            'has_more' => count( $posts ) >= $per_page,
            'total'    => $total,
        ) );
    }
    
    /**
     * 设置动态推荐
     */
    public function feature_post( $request ) {
        $post_id = absint( $request['id'] );
        $days = absint( $request->get_param( 'days' ) ) ?: 7;
        
        if ( ! $post_id ) {
            return $this->error( __( '动态ID无效', 'qibbs-community' ) );
        }
        
        $featured_service = new Qibbs_Featured_Service();
        $result = $featured_service->feature_post( $post_id, $days );
        
        if ( $result ) {
            return $this->success( array(
                'message' => sprintf( __( '已推荐%d天', 'qibbs-community' ), $days ),
                'days'    => $days,
            ) );
        }
        
        return $this->error( __( '推荐失败', 'qibbs-community' ) );
    }
    
    /**
     * 取消动态推荐
     */
    public function unfeature_post( $request ) {
        $post_id = absint( $request['id'] );
        
        if ( ! $post_id ) {
            return $this->error( __( '动态ID无效', 'qibbs-community' ) );
        }
        
        $featured_service = new Qibbs_Featured_Service();
        $result = $featured_service->unfeature_post( $post_id );
        
        if ( $result ) {
            return $this->success( array( 'message' => __( '已取消推荐', 'qibbs-community' ) ) );
        }
        
        return $this->error( __( '取消推荐失败', 'qibbs-community' ) );
    }

    /**
     * 执行每日签到打卡
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function handle_checkin( $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return $this->error( __( '请先登录', 'qibbs-community' ), 401 );
        }

        if ( ! function_exists( 'qibbs_execute_checkin' ) ) {
            return $this->error( __( '签到服务暂不可用', 'qibbs-community' ), 500 );
        }

        $result = qibbs_execute_checkin( $user_id );
        if ( ! empty( $result['success'] ) ) {
            return $this->success( $result );
        }

        return $this->error( ! empty( $result['message'] ) ? $result['message'] : __( '签到失败', 'qibbs-community' ) );
    }

    /**
     * 执行补签打卡（消耗补签卡）
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function handle_checkin_makeup( $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return $this->error( __( '请先登录', 'qibbs-community' ), 401 );
        }

        if ( ! function_exists( 'qibbs_execute_retroactive_checkin' ) ) {
            return $this->error( __( '补签服务暂不可用', 'qibbs-community' ), 500 );
        }

        $result = qibbs_execute_retroactive_checkin( $user_id );
        if ( ! empty( $result['success'] ) ) {
            return $this->success( $result );
        }

        return $this->error( ! empty( $result['message'] ) ? $result['message'] : __( '补签失败', 'qibbs-community' ) );
    }

    /**
     * 获取当前用户签到状态与连签信息
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_checkin_status( $request ) {
        $user_id = get_current_user_id();
        if ( ! function_exists( 'qibbs_get_user_checkin_status' ) ) {
            return $this->error( __( '签到服务暂不可用', 'qibbs-community' ), 500 );
        }

        $status = qibbs_get_user_checkin_status( $user_id );
        return $this->success( $status );
    }
}
