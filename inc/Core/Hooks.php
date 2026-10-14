<?php
/**
 * 钩子定义与扩展工具类
 *
 * 汇总维护微社区所有 Action 与 Filter 钩子，支持扩展开发
 *
 * @package Qibbs_Community
 * @subpackage Core
 * @since 1.1.0
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 钩子管理器
 */
class Qibbs_Hooks {

    /**
     * 获取所有可用的 Action 动作钩子列表
     *
     * @return array Array of action definitions with name, params, and description.
     */
    public static function get_actions() {
        return array(
            // 插件生命周期钩子
            'qibbs_loaded' => array(
                'params'      => array(),
                'description' => __( '插件完全加载后触发', 'qibbs-community' ),
            ),
            'qibbs_activated' => array(
                'params'      => array(),
                'description' => __( '插件激活时触发', 'qibbs-community' ),
            ),
            'qibbs_deactivated' => array(
                'params'      => array(),
                'description' => __( '插件停用时触发', 'qibbs-community' ),
            ),

            // 帖子生命周期与操作钩子
            'qibbs_post_created' => array(
                'params'      => array( '$post_id', '$user_id' ),
                'description' => __( '帖子创建成功后触发', 'qibbs-community' ),
            ),
            'qibbs_post_deleted' => array(
                'params'      => array( '$post_id', '$user_id' ),
                'description' => __( '帖子删除后触发', 'qibbs-community' ),
            ),
            'qibbs_post_blocked' => array(
                'params'      => array( '$post_id' ),
                'description' => __( '帖子被屏蔽后触发', 'qibbs-community' ),
            ),

            // 互动行为钩子（点赞、收藏、关注、评论）
            'qibbs_like_toggled' => array(
                'params'      => array( '$user_id', '$post_id', '$liked' ),
                'description' => __( '点赞状态切换后触发', 'qibbs-community' ),
            ),
            'qibbs_favorite_toggled' => array(
                'params'      => array( '$user_id', '$post_id', '$favorited' ),
                'description' => __( '收藏状态切换后触发', 'qibbs-community' ),
            ),
            'qibbs_follow_toggled' => array(
                'params'      => array( '$follower_id', '$following_id', '$following' ),
                'description' => __( '关注状态切换后触发', 'qibbs-community' ),
            ),
            'qibbs_comment_added' => array(
                'params'      => array( '$comment_id', '$user_id', '$post_id' ),
                'description' => __( '评论添加后触发', 'qibbs-community' ),
            ),
            'qibbs_comment_approved' => array(
                'params'      => array( '$comment_id', '$user_id', '$post_id' ),
                'description' => __( '评论审核通过后触发', 'qibbs-community' ),
            ),
            'qibbs_comment_deleted' => array(
                'params'      => array( '$comment_id', '$user_id', '$post_id' ),
                'description' => __( '评论删除后触发', 'qibbs-community' ),
            ),
            'qibbs_best_answer_set' => array(
                'params'      => array( '$post_id', '$comment_id', '$comment_author_id', '$actor_user_id' ),
                'description' => __( '评论被设为最佳答案后触发', 'qibbs-community' ),
            ),
            'qibbs_post_featured' => array(
                'params'      => array( '$post_id', '$author_id' ),
                'description' => __( '动态被设为精华/推荐后触发', 'qibbs-community' ),
            ),
            'qibbs_points_reward_granted' => array(
                'params'      => array( '$reward_key', '$user_id', '$amount', '$source', '$related_id', '$context' ),
                'description' => __( '社区积分奖励成功发放后触发', 'qibbs-community' ),
            ),
            'qibbs_message_sent' => array(
                'params'      => array( '$message_id', '$sender_id', '$receiver_id' ),
                'description' => __( '私信发送后触发', 'qibbs-community' ),
            ),

            // 布局与模板渲染钩子
            'qibbs_layout_render' => array(
                'params'      => array( '$layout_mode' ),
                'description' => __( '布局渲染后触发', 'qibbs-community' ),
            ),
            'qibbs_sidebar_content' => array(
                'params'      => array(),
                'description' => __( '侧边栏内容区域，用于添加自定义内容', 'qibbs-community' ),
            ),
            'qibbs_nav_content' => array(
                'params'      => array(),
                'description' => __( '导航栏内容区域，用于添加自定义内容', 'qibbs-community' ),
            ),
            'qibbs_main_content' => array(
                'params'      => array(),
                'description' => __( '主内容区域，用于添加自定义内容', 'qibbs-community' ),
            ),

            // 后台管理钩子
            'qibbs_admin_settings_sections' => array(
                'params'      => array(),
                'description' => __( '添加自定义设置区块', 'qibbs-community' ),
            ),
            'qibbs_admin_settings_fields' => array(
                'params'      => array( '$section' ),
                'description' => __( '添加自定义设置字段', 'qibbs-community' ),
            ),
            'qibbs_admin_settings_page_bottom' => array(
                'params'      => array(),
                'description' => __( '设置页面底部，用于添加自定义内容', 'qibbs-community' ),
            ),
        );
    }

    /**
     * 获取所有可用的 Filter 过滤器钩子列表
     *
     * @return array Array of filter definitions with name, params, return, and description.
     */
    public static function get_filters() {
        return array(
            // 权限校验过滤器
            'qibbs_can_create_post' => array(
                'params'      => array( '$can_post', '$user_id' ),
                'return'      => 'bool',
                'description' => __( '过滤用户是否可以发帖', 'qibbs-community' ),
            ),
            'qibbs_can_comment' => array(
                'params'      => array( '$can_comment', '$user_id', '$post_id' ),
                'return'      => 'bool',
                'description' => __( '过滤用户是否可以评论', 'qibbs-community' ),
            ),
            'qibbs_can_like' => array(
                'params'      => array( '$can_like', '$user_id', '$post_id' ),
                'return'      => 'bool',
                'description' => __( '过滤用户是否可以点赞', 'qibbs-community' ),
            ),
            'qibbs_can_favorite' => array(
                'params'      => array( '$can_favorite', '$user_id', '$post_id' ),
                'return'      => 'bool',
                'description' => __( '过滤用户是否可以收藏', 'qibbs-community' ),
            ),
            'qibbs_can_follow' => array(
                'params'      => array( '$can_follow', '$follower_id', '$following_id' ),
                'return'      => 'bool',
                'description' => __( '过滤用户是否可以关注', 'qibbs-community' ),
            ),
            'qibbs_can_send_message' => array(
                'params'      => array( '$can_send', '$sender_id', '$receiver_id' ),
                'return'      => 'bool',
                'description' => __( '过滤用户是否可以发私信', 'qibbs-community' ),
            ),

            // 内容安全过滤与富文本过滤器
            'qibbs_post_content' => array(
                'params'      => array( '$content', '$user_id' ),
                'return'      => 'string',
                'description' => __( '过滤帖子内容（发布前）', 'qibbs-community' ),
            ),
            'qibbs_comment_content' => array(
                'params'      => array( '$content', '$user_id', '$post_id' ),
                'return'      => 'string',
                'description' => __( '过滤评论内容（发布前）', 'qibbs-community' ),
            ),
            'qibbs_message_content' => array(
                'params'      => array( '$content', '$sender_id', '$receiver_id' ),
                'return'      => 'string',
                'description' => __( '过滤私信内容（发送前）', 'qibbs-community' ),
            ),

            // 数据查询与输出过滤器
            'qibbs_pre_insert_post' => array(
                'params'      => array( '$data', '$user_id' ),
                'return'      => 'array',
                'description' => __( '过滤即将插入数据库的帖子数据', 'qibbs-community' ),
            ),
            'qibbs_feed_query_args' => array(
                'params'      => array( '$args', '$feed_type' ),
                'return'      => 'array',
                'description' => __( '过滤 Feed 查询参数', 'qibbs-community' ),
            ),
            'qibbs_enriched_posts' => array(
                'params'      => array( '$posts' ),
                'return'      => 'array',
                'description' => __( '过滤富化后的帖子数据', 'qibbs-community' ),
            ),
            'qibbs_feed_card_template' => array(
                'params'      => array( '$template', '$post' ),
                'return'      => 'string',
                'description' => __( '过滤 Feed 卡片模板路径', 'qibbs-community' ),
            ),
            'qibbs_points_reward_definitions' => array(
                'params'      => array( '$definitions' ),
                'return'      => 'array',
                'description' => __( '过滤社区积分奖励定义', 'qibbs-community' ),
            ),
            'qibbs_points_reward_enabled' => array(
                'params'      => array( '$enabled', '$reward_key', '$user_id', '$context' ),
                'return'      => 'bool',
                'description' => __( '过滤某个社区积分奖励是否生效', 'qibbs-community' ),
            ),
            'qibbs_points_reward_amount' => array(
                'params'      => array( '$amount', '$reward_key', '$user_id', '$context' ),
                'return'      => 'float',
                'description' => __( '过滤某个社区积分奖励的积分值', 'qibbs-community' ),
            ),
            'qibbs_points_reward_daily_limit' => array(
                'params'      => array( '$limit', '$reward_key', '$user_id', '$context' ),
                'return'      => 'int',
                'description' => __( '过滤某个社区积分奖励的每日上限', 'qibbs-community' ),
            ),
            'qibbs_points_reward_source' => array(
                'params'      => array( '$source', '$reward_key', '$user_id', '$context' ),
                'return'      => 'string',
                'description' => __( '过滤社区积分流水来源 source', 'qibbs-community' ),
            ),
            'qibbs_points_reward_description' => array(
                'params'      => array( '$description', '$reward_key', '$user_id', '$context' ),
                'return'      => 'string',
                'description' => __( '过滤社区积分流水描述文案', 'qibbs-community' ),
            ),

            // 通知投递与偏好过滤器
            'qibbs_notification_types' => array(
                'params'      => array( '$types' ),
                'return'      => 'array',
                'description' => __( '注册自定义通知类型', 'qibbs-community' ),
            ),
            'qibbs_notification_recipients' => array(
                'params'      => array( '$recipients', '$type', '$object_id' ),
                'return'      => 'array',
                'description' => __( '过滤通知接收者列表', 'qibbs-community' ),
            ),
            'qibbs_should_send_notification' => array(
                'params'      => array( '$should_send', '$type', '$target_user', '$actor' ),
                'return'      => 'bool',
                'description' => __( '控制是否发送通知', 'qibbs-community' ),
            ),

            // 版块与标签过滤器
            'qibbs_post_type_args' => array(
                'params'      => array( '$args' ),
                'return'      => 'array',
                'description' => __( '过滤帖子类型注册参数', 'qibbs-community' ),
            ),
            'qibbs_forum_taxonomy_args' => array(
                'params'      => array( '$args' ),
                'return'      => 'array',
                'description' => __( '过滤圈子分类注册参数', 'qibbs-community' ),
            ),
            'qibbs_tag_taxonomy_args' => array(
                'params'      => array( '$args' ),
                'return'      => 'array',
                'description' => __( '过滤标签分类注册参数', 'qibbs-community' ),
            ),
        );
    }

    /**
     * 获取完整的钩子定义汇总
     *
     * @return array
     */
    public static function get_all_hooks() {
        return array(
            'actions' => self::get_actions(),
            'filters' => self::get_filters(),
        );
    }

    /**
     * 触发可扩展的自定义事件
     * 封装 do_action 并在调试模式下记录日志
     *
     * @param string $hook Hook name.
     * @param mixed  ...$args Hook arguments.
     */
    public static function trigger( $hook, ...$args ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( 'Qibbs Hook: %s triggered with %d args', $hook, count( $args ) ) );
        }
        do_action( $hook, ...$args );
    }

    /**
     * 应用可扩展的自定义过滤器
     * 封装 apply_filters 并在调试模式下记录日志
     *
     * @param string $hook  Hook name.
     * @param mixed  $value Value to filter.
     * @param mixed  ...$args Additional arguments.
     * @return mixed Filtered value.
     */
    public static function filter( $hook, $value, ...$args ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( 'Qibbs Filter: %s applied', $hook ) );
        }
        return apply_filters( $hook, $value, ...$args );
    }
}
