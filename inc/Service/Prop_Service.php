<?php
/**
 * 社区道具大厅核心服务类
 *
 * 管理纯积分商城道具定义、选购结算、用户背包、昵称变色全站渲染及圈子付费置顶推广
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Prop_Service {

    /**
     * 单例实例
     *
     * @var Qibbs_Prop_Service|null
     */
    private static $instance = null;

    /**
     * 定时到期扫描钩子
     */
    const CRON_HOOK = 'qibbs_prop_expiration_check';

    /**
     * 获取单例
     *
     * @return Qibbs_Prop_Service
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct() {
        add_action( self::CRON_HOOK, array( $this, 'check_and_expire_props' ) );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
        }
    }

    /**
     * 道具大厅功能是否启用
     *
     * @return bool
     */
    public function is_enabled() {
        if ( ! function_exists( 'qibbs_is_points_integration_available' ) || ! qibbs_is_points_integration_available() ) {
            return false;
        }
        return (bool) qibbs_get_option( 'enable_prop_mall', true );
    }

    /**
     * 获取道具列表
     *
     * @param string $type   道具类型 ('name_color'|'post_sticky'|'' 全部).
     * @param string $status 状态 ('active'|'all'|'inactive').
     * @return array
     */
    public function get_props( $type = '', $status = 'active' ) {
        $this->ensure_tables_exist();
        $this->ensure_default_seed_props();

        global $wpdb;
        $table = Qibbs_Database::props();

        $where = array();
        $params = array();

        if ( 'all' !== $status ) {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        if ( ! empty( $type ) ) {
            $where[] = 'prop_type = %s';
            $params[] = sanitize_key( $type );
        }

        $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $sql = "SELECT * FROM {$table} {$where_sql} ORDER BY sort_order ASC, id ASC";

        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params );
        }

        $rows = $wpdb->get_results( $sql );
        if ( empty( $rows ) ) {
            return array();
        }

        foreach ( $rows as &$row ) {
            $row->meta_data = ! empty( $row->meta_data ) ? json_decode( $row->meta_data, true ) : array();
            $row->price_points = (float) $row->price_points;
            $row->duration_days = absint( $row->duration_days );
            $row->stock = (int) $row->stock;
            $row->sales_count = (int) $row->sales_count;
        }

        return $rows;
    }

    /**
     * 获取单个道具定义
     *
     * @param int $prop_id 道具 ID.
     * @return object|null
     */
    public function get_prop( $prop_id ) {
        $prop_id = absint( $prop_id );
        if ( ! $prop_id ) {
            return null;
        }

        global $wpdb;
        $table = Qibbs_Database::props();
        $prop = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $prop_id ) );
        if ( ! $prop ) {
            return null;
        }

        $prop->meta_data = ! empty( $prop->meta_data ) ? json_decode( $prop->meta_data, true ) : array();
        $prop->price_points = (float) $prop->price_points;
        $prop->duration_days = absint( $prop->duration_days );
        $prop->stock = (int) $prop->stock;
        $prop->sales_count = (int) $prop->sales_count;

        return $prop;
    }

    /**
     * 保存或更新道具（管理员专属）
     *
     * @param array $data    道具数据.
     * @param int   $prop_id 道具 ID (更新时传递).
     * @return array{success:bool,message:string,prop_id?:int}
     */
    public function save_prop( $data, $prop_id = 0 ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return array( 'success' => false, 'message' => __( '权限不足', 'qibbs-community' ) );
        }

        $name = isset( $data['name'] ) ? sanitize_text_field( wp_unslash( $data['name'] ) ) : '';
        $prop_type = isset( $data['prop_type'] ) ? sanitize_key( $data['prop_type'] ) : '';
        $price = isset( $data['price_points'] ) ? max( 0, round( (float) $data['price_points'], 2 ) ) : 0.0;
        $days = isset( $data['duration_days'] ) ? absint( $data['duration_days'] ) : 30;
        $stock = isset( $data['stock'] ) ? intval( $data['stock'] ) : -1;
        $status = isset( $data['status'] ) && in_array( $data['status'], array( 'active', 'inactive' ), true ) ? $data['status'] : 'active';
        $desc = isset( $data['description'] ) ? sanitize_textarea_field( wp_unslash( $data['description'] ) ) : '';
        $sort = isset( $data['sort_order'] ) ? intval( $data['sort_order'] ) : 0;
        $meta = isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ? $data['meta_data'] : array();

        if ( empty( $name ) || ! in_array( $prop_type, array( 'name_color', 'post_sticky' ), true ) ) {
            return array( 'success' => false, 'message' => __( '道具名称和类型不能为空', 'qibbs-community' ) );
        }

        if ( $price <= 0 ) {
            return array( 'success' => false, 'message' => __( '积分价格必须大于 0', 'qibbs-community' ) );
        }

        if ( 'name_color' === $prop_type && empty( $meta['color'] ) ) {
            return array( 'success' => false, 'message' => __( '请为名字颜色道具指定颜色值', 'qibbs-community' ) );
        }

        global $wpdb;
        $table = Qibbs_Database::props();
        $now = current_time( 'mysql' );

        $save_data = array(
            'name'          => $name,
            'prop_type'     => $prop_type,
            'description'   => $desc,
            'price_points'  => $price,
            'duration_days' => $days,
            'meta_data'     => wp_json_encode( $meta ),
            'stock'         => $stock,
            'status'        => $status,
            'sort_order'    => $sort,
            'updated_at'    => $now,
        );
        $formats = array( '%s', '%s', '%s', '%f', '%d', '%s', '%d', '%s', '%d', '%s' );

        $prop_id = absint( $prop_id );
        if ( $prop_id > 0 ) {
            $updated = $wpdb->update( $table, $save_data, array( 'id' => $prop_id ), $formats, array( '%d' ) );
            if ( false === $updated ) {
                return array( 'success' => false, 'message' => __( '更新道具失败', 'qibbs-community' ) );
            }
            return array( 'success' => true, 'message' => __( '道具更新成功', 'qibbs-community' ), 'prop_id' => $prop_id );
        }

        $save_data['sales_count'] = 0;
        $save_data['created_at']  = $now;
        $formats[] = '%d';
        $formats[] = '%s';

        $inserted = $wpdb->insert( $table, $save_data, $formats );
        if ( ! $inserted ) {
            return array( 'success' => false, 'message' => __( '添加道具失败', 'qibbs-community' ) );
        }

        return array( 'success' => true, 'message' => __( '道具添加成功', 'qibbs-community' ), 'prop_id' => (int) $wpdb->insert_id );
    }

    /**
     * 删除道具（管理员专属）
     *
     * @param int $prop_id 道具 ID.
     * @return array{success:bool,message:string}
     */
    public function delete_prop( $prop_id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return array( 'success' => false, 'message' => __( '权限不足', 'qibbs-community' ) );
        }

        $prop_id = absint( $prop_id );
        if ( ! $prop_id ) {
            return array( 'success' => false, 'message' => __( '参数错误', 'qibbs-community' ) );
        }

        global $wpdb;
        $table = Qibbs_Database::props();
        $deleted = $wpdb->delete( $table, array( 'id' => $prop_id ), array( '%d' ) );

        if ( ! $deleted ) {
            return array( 'success' => false, 'message' => __( '删除失败', 'qibbs-community' ) );
        }

        return array( 'success' => true, 'message' => __( '道具已删除', 'qibbs-community' ) );
    }

    /**
     * 用户选购道具并支付积分（原子操作）
     *
     * @param int $user_id        用户 ID.
     * @param int $prop_id        道具 ID.
     * @param int $target_post_id 目标帖子 ID (当类型为 post_sticky 时必须传递).
     * @return array{success:bool,message:string,user_prop_id?:int}
     */
    /**
     * 用户选购道具接口
     *
     * @param int $user_id        用户 ID.
     * @param int $prop_id        道具 ID.
     * @param int $target_post_id 可选：指定推广的动态 ID.
     * @return array{success:bool,message:string,user_prop_id?:int}
     */
    public function purchase_prop( $user_id, $prop_id, $target_post_id = 0 ) {
        $user_id = absint( $user_id );
        $prop_id = absint( $prop_id );

        if ( ! $this->is_enabled() ) {
            return array( 'success' => false, 'message' => __( '道具大厅暂未开启', 'qibbs-community' ) );
        }

        if ( ! $user_id || ! $prop_id ) {
            return array( 'success' => false, 'message' => __( '购买参数无效', 'qibbs-community' ) );
        }

        // 并发防重与高频连击拦截锁（4秒窗口，杜绝网络重发或连击多次扣款）
        $lock_key = 'qibbs_prop_lock_' . $user_id;
        if ( get_transient( $lock_key ) ) {
            return array( 'success' => false, 'message' => __( '系统正在处理您的上一笔兑换请求，请稍候勿重复提交', 'qibbs-community' ) );
        }
        set_transient( $lock_key, 1, 4 );

        try {
            return $this->do_purchase_prop( $user_id, $prop_id, $target_post_id );
        } finally {
            delete_transient( $lock_key );
        }
    }

    /**
     * 实际执行道具兑换业务（支持已有道具自动续期叠加，防重复冗余生成）
     *
     * @param int $user_id        用户 ID.
     * @param int $prop_id        道具 ID.
     * @param int $target_post_id 指定推广的动态 ID.
     * @return array
     */
    private function do_purchase_prop( $user_id, $prop_id, $target_post_id = 0 ) {
        $prop = $this->get_prop( $prop_id );
        if ( ! $prop || 'active' !== (string) $prop->status ) {
            return array( 'success' => false, 'message' => __( '该道具未上架或已下架', 'qibbs-community' ) );
        }

        // 检查库存
        if ( $prop->stock >= 0 && $prop->stock <= 0 ) {
            return array( 'success' => false, 'message' => __( '该道具全站限量已售罄，无法购买', 'qibbs-community' ) );
        }

        $price = (float) $prop->price_points;
        $points_name = qibbs_get_points_name();

        // 校验可用积分余额
        $balance = qibbs_get_user_points_balance( $user_id );
        if ( null === $balance || $balance < $price ) {
            return array(
                'success' => false,
                'message' => sprintf( __( '您的可用%s不足（当前余额：%s，需支付：%s）', 'qibbs-community' ), $points_name, qibbs_format_points_amount( (float) $balance ), qibbs_format_points_amount( $price ) ),
            );
        }

        // 针对置顶推广的前置校验
        $target_post = null;
        if ( 'post_sticky' === (string) $prop->prop_type ) {
            $target_post_id = absint( $target_post_id );
            if ( ! $target_post_id ) {
                return array( 'success' => false, 'message' => __( '请选择要置顶推广的帖子', 'qibbs-community' ) );
            }

            global $wpdb;
            $posts_table = Qibbs_Database::posts();
            $target_post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$posts_table} WHERE id = %d AND author_id = %d AND status = 'publish' LIMIT 1", $target_post_id, $user_id ) );

            if ( ! $target_post ) {
                return array( 'success' => false, 'message' => __( '指定的动态不存在或您不是该动态作者', 'qibbs-community' ) );
            }

            // 圈子最大置顶数量保护
            $forum_id = (int) $target_post->forum_id;
            if ( $forum_id > 0 ) {
                $max_promoted = max( 1, absint( qibbs_get_option( 'prop_max_promoted_per_forum', 5 ) ) );
                $now_str = current_time( 'mysql' );
                $current_promoted_count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$posts_table} 
                     WHERE forum_id = %d AND is_promoted = 1 AND (promoted_until IS NULL OR promoted_until > %s) AND id != %d",
                    $forum_id,
                    $now_str,
                    $target_post_id
                ) );

                if ( $current_promoted_count >= $max_promoted ) {
                    return array(
                        'success' => false,
                        'message' => sprintf( __( '当前圈子付费推广置顶位已满（最多 %d 篇），请稍后再试', 'qibbs-community' ), $max_promoted ),
                    );
                }
            }
        }

        global $wpdb;
        $props_table      = Qibbs_Database::props();
        $user_props_table = Qibbs_Database::user_props();
        $now              = current_time( 'mysql' );
        $now_time         = current_time( 'timestamp' );

        // 计算有效期天数
        $duration_days = absint( $prop->duration_days );

        // 确保数据表存在（自愈防御）
        $this->ensure_tables_exist();

        // 检查用户背包中是否已有同款活跃道具（实现无缝叠加续期，绝不冗余新增卡片）
        $existing_active_prop = null;
        if ( 'name_color' === (string) $prop->prop_type ) {
            $existing_active_prop = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$user_props_table} 
                 WHERE user_id = %d AND prop_id = %d AND prop_type = 'name_color' AND status = 'active' AND (expires_at IS NULL OR expires_at > %s) 
                 ORDER BY is_equipped DESC, id DESC LIMIT 1",
                $user_id,
                $prop_id,
                $now
            ) );
        } elseif ( 'post_sticky' === (string) $prop->prop_type ) {
            $existing_active_prop = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$user_props_table} 
                 WHERE user_id = %d AND prop_type = 'post_sticky' AND status = 'active' AND (expires_at IS NULL OR expires_at > %s) AND prop_data LIKE %s 
                 ORDER BY id DESC LIMIT 1",
                $user_id,
                $now,
                '%"post_id":' . $target_post_id . '%'
            ) );
        }

        // 扣除积分
        $deducted = qibbs_deduct_user_points(
            $user_id,
            $price,
            'community_prop_purchase',
            sprintf( __( '道具大厅购买「%s」', 'qibbs-community' ), $prop->name ),
            $prop_id
        );

        if ( ! $deducted ) {
            return array( 'success' => false, 'message' => sprintf( __( '扣除%s失败，请稍后重试', 'qibbs-community' ), $points_name ) );
        }

        // 组装道具私有数据
        $prop_data = array();
        if ( 'name_color' === (string) $prop->prop_type ) {
            $color = ! empty( $prop->meta_data['color'] ) ? sanitize_hex_color( $prop->meta_data['color'] ) : '#ef4444';
            $prop_data['color'] = $color;
            $prop_data['style'] = ! empty( $prop->meta_data['style'] ) ? sanitize_text_field( $prop->meta_data['style'] ) : 'solid';
        } elseif ( 'post_sticky' === (string) $prop->prop_type ) {
            $prop_data['post_id']  = $target_post_id;
            $prop_data['forum_id'] = (int) $target_post->forum_id;
        }

        $is_renew = ! empty( $existing_active_prop );

        if ( $is_renew ) {
            // 已有记录：叠加续期处理
            $user_prop_id = (int) $existing_active_prop->id;
            $current_expire = ! empty( $existing_active_prop->expires_at ) ? strtotime( $existing_active_prop->expires_at ) : $now_time;
            $start_from = ( $current_expire > $now_time ) ? $current_expire : $now_time;
            $new_expires_at = ( $duration_days > 0 ) ? date( 'Y-m-d H:i:s', $start_from + ( $duration_days * DAY_IN_SECONDS ) ) : null;

            $update_data = array(
                'updated_at'  => $now,
                'cost_points' => (float) $existing_active_prop->cost_points + $price,
            );
            if ( null !== $new_expires_at ) {
                $update_data['expires_at'] = $new_expires_at;
            }
            if ( 'name_color' === (string) $prop->prop_type ) {
                $update_data['is_equipped'] = 1;
            }

            $wpdb->update(
                $user_props_table,
                $update_data,
                array( 'id' => $user_prop_id )
            );
            $expires_at = $new_expires_at;
        } else {
            // 全新购买：新增记录
            $expires_at = null;
            if ( $duration_days > 0 ) {
                $expires_at = date( 'Y-m-d H:i:s', $now_time + ( $duration_days * DAY_IN_SECONDS ) );
            }

            $insert_data = array(
                'user_id'     => $user_id,
                'prop_id'     => $prop_id,
                'prop_type'   => (string) $prop->prop_type,
                'prop_name'   => $prop->name,
                'prop_data'   => wp_json_encode( $prop_data ),
                'is_equipped' => ( 'name_color' === (string) $prop->prop_type ) ? 1 : 0,
                'status'      => 'active',
                'cost_points' => $price,
                'created_at'  => $now,
                'updated_at'  => $now,
            );
            if ( null !== $expires_at ) {
                $insert_data['expires_at'] = $expires_at;
            }

            $inserted = $wpdb->insert( $user_props_table, $insert_data );

            if ( ! $inserted ) {
                // 回滚积分
                qibbs_add_user_points(
                    $user_id,
                    $price,
                    'community_prop_rollback',
                    sprintf( __( '购买道具写入失败退回积分 #%d', 'qibbs-community' ), $prop_id ),
                    $prop_id
                );
                return array( 'success' => false, 'message' => __( '写入用户道具记录失败，已原路退回积分', 'qibbs-community' ) );
            }

            $user_prop_id = (int) $wpdb->insert_id;
        }

        // 原子扣减库存并递增销量
        if ( $prop->stock >= 0 ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$props_table} SET stock = GREATEST(0, stock - 1), sales_count = sales_count + 1 WHERE id = %d",
                $prop_id
            ) );
        } else {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$props_table} SET sales_count = sales_count + 1 WHERE id = %d",
                $prop_id
            ) );
        }

        // 执行特定道具即刻生效逻辑
        if ( 'name_color' === (string) $prop->prop_type ) {
            // 卸下先前的其他颜色
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$user_props_table} SET is_equipped = 0 WHERE user_id = %d AND prop_type = 'name_color' AND id != %d",
                $user_id,
                $user_prop_id
            ) );
            // 写入用户元数据供全站快速读取
            update_user_meta( $user_id, 'qibbs_active_name_color', $prop_data['color'] );
            update_user_meta( $user_id, 'qibbs_name_color_expires', $expires_at ? strtotime( $expires_at ) : 0 );
        } elseif ( 'post_sticky' === (string) $prop->prop_type && $target_post ) {
            $posts_table = Qibbs_Database::posts();
            // 计算新的置顶到期时间（如果原本在置顶期内，则顺延叠加天数）
            $current_until = ! empty( $target_post->promoted_until ) ? strtotime( $target_post->promoted_until ) : 0;
            $start_from = ( $current_until > $now_time ) ? $current_until : $now_time;
            $new_promoted_until = date( 'Y-m-d H:i:s', $start_from + ( $duration_days * DAY_IN_SECONDS ) );

            $wpdb->update(
                $posts_table,
                array(
                    'is_promoted'    => 1,
                    'promoted_until' => $new_promoted_until,
                    'promoted_by'    => $user_id,
                    'updated_at'     => $now,
                ),
                array( 'id' => $target_post_id ),
                array( '%d', '%s', '%d', '%s' ),
                array( '%d' )
            );

            // 清除帖子与动态流缓存（安全容错，避免任何缓存失效异常阻塞购买流程）
            try {
                if ( class_exists( 'Qibbs_Cache_Service' ) ) {
                    if ( method_exists( 'Qibbs_Cache_Service', 'invalidate_post_cache' ) ) {
                        Qibbs_Cache_Service::invalidate_post_cache( $target_post_id );
                    }
                    if ( ! empty( $target_post->forum_id ) && method_exists( 'Qibbs_Cache_Service', 'invalidate_forum_feed_cache' ) ) {
                        Qibbs_Cache_Service::invalidate_forum_feed_cache( (int) $target_post->forum_id );
                    }
                    if ( method_exists( 'Qibbs_Cache_Service', 'invalidate_global_feed_cache' ) ) {
                        Qibbs_Cache_Service::invalidate_global_feed_cache();
                    }
                    if ( method_exists( 'Qibbs_Cache_Service', 'instance' ) ) {
                        $cache_inst = Qibbs_Cache_Service::instance();
                        if ( method_exists( $cache_inst, 'invalidate_post_edited_cache' ) ) {
                            $cache_inst->invalidate_post_edited_cache( $target_post_id, $user_id, array() );
                        }
                    }
                }
                do_action( 'qibbs_post_edited', $target_post_id, $user_id );
            } catch ( Throwable $e ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[Qibbs_Prop_Service] Invalidate post cache failed: ' . $e->getMessage() );
                }
            }
        }

        // 站内通知（安全容错包装，保证通知即便出现异常也绝不阻塞购买流程）
        if ( class_exists( 'Qibbs_Notification_Service' ) ) {
            try {
                $notice_body = ( 'name_color' === (string) $prop->prop_type )
                    ? ( $is_renew ? sprintf( __( '您已成功续期「%s」，有效期已顺延叠加！', 'qibbs-community' ), $prop->name ) : sprintf( __( '您已成功选购「%s」，用户名专属颜色已即刻佩戴生效！', 'qibbs-community' ), $prop->name ) )
                    : ( $is_renew ? sprintf( __( '您已成功为动态 #%d 续期置顶推广，置顶时长已顺延叠加！', 'qibbs-community' ), $target_post_id ) : sprintf( __( '您已成功选购「%s」，动态 #%d 已在所属圈子中置顶并打上「推广」标识！', 'qibbs-community' ), $prop->name, $target_post_id ) );

                if ( method_exists( 'Qibbs_Notification_Service', 'send' ) ) {
                    Qibbs_Notification_Service::send(
                        $user_id,
                        'prop_purchased',
                        $notice_body,
                        $user_prop_id,
                        'prop'
                    );
                } elseif ( method_exists( 'Qibbs_Notification_Service', 'instance' ) ) {
                    Qibbs_Notification_Service::instance()->dispatch_notification(
                        $user_id,
                        'prop_purchased',
                        $notice_body,
                        $user_prop_id,
                        'prop'
                    );
                }
            } catch ( Throwable $e ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[Qibbs_Prop_Service] Send notification failed: ' . $e->getMessage() );
                }
            }
        }

        do_action( 'qibbs_prop_purchased', $user_id, $prop_id, $user_prop_id, $prop->prop_type );

        if ( 'name_color' === (string) $prop->prop_type ) {
            $success_msg = $is_renew
                ? sprintf( __( '成功续期「%s」！有效期已叠加顺延 %d 天。', 'qibbs-community' ), $prop->name, $duration_days )
                : sprintf( __( '成功购买并已穿戴「%s」！全站昵称已变色。', 'qibbs-community' ), $prop->name );
        } else {
            $success_msg = $is_renew
                ? sprintf( __( '动态置顶推广续费成功！置顶时长已顺延叠加 %d 天。', 'qibbs-community' ), $duration_days )
                : sprintf( __( '成功购买「%s」！动态已成功置顶推广。', 'qibbs-community' ), $prop->name );
        }

        return array(
            'success'      => true,
            'message'      => $success_msg,
            'user_prop_id' => $user_prop_id,
        );
    }

    /**
     * 穿戴已拥有的名字颜色
     *
     * @param int $user_id      用户 ID.
     * @param int $user_prop_id 用户道具记录 ID.
     * @return array{success:bool,message:string}
     */
    public function equip_name_color( $user_id, $user_prop_id ) {
        $user_id      = absint( $user_id );
        $user_prop_id = absint( $user_prop_id );

        if ( ! $user_id || ! $user_prop_id ) {
            return array( 'success' => false, 'message' => __( '参数错误', 'qibbs-community' ) );
        }

        global $wpdb;
        $table = Qibbs_Database::user_props();
        $user_prop = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND user_id = %d AND prop_type = 'name_color' AND status = 'active' LIMIT 1",
            $user_prop_id,
            $user_id
        ) );

        if ( ! $user_prop ) {
            return array( 'success' => false, 'message' => __( '未找到该颜色道具或已失效', 'qibbs-community' ) );
        }

        $now = current_time( 'mysql' );
        if ( ! empty( $user_prop->expires_at ) && $user_prop->expires_at <= $now ) {
            $wpdb->update( $table, array( 'status' => 'expired', 'is_equipped' => 0 ), array( 'id' => $user_prop_id ) );
            return array( 'success' => false, 'message' => __( '该颜色道具已过有效期', 'qibbs-community' ) );
        }

        $prop_data = ! empty( $user_prop->prop_data ) ? json_decode( $user_prop->prop_data, true ) : array();
        $color = ! empty( $prop_data['color'] ) ? sanitize_hex_color( $prop_data['color'] ) : '#ef4444';

        // 卸下全部其他颜色，佩戴当前项
        $wpdb->update( $table, array( 'is_equipped' => 0 ), array( 'user_id' => $user_id, 'prop_type' => 'name_color' ) );
        $wpdb->update( $table, array( 'is_equipped' => 1 ), array( 'id' => $user_prop_id ) );

        update_user_meta( $user_id, 'qibbs_active_name_color', $color );
        update_user_meta( $user_id, 'qibbs_name_color_expires', ! empty( $user_prop->expires_at ) ? strtotime( $user_prop->expires_at ) : 0 );

        return array(
            'success' => true,
            'message' => sprintf( __( '已切换穿戴「%s」昵称颜色！', 'qibbs-community' ), $user_prop->prop_name ),
            'color'   => $color,
        );
    }

    /**
     * 卸下当前穿戴的名字颜色
     *
     * @param int $user_id 用户 ID.
     * @return array{success:bool,message:string}
     */
    public function unequip_name_color( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return array( 'success' => false, 'message' => __( '请先登录', 'qibbs-community' ) );
        }

        global $wpdb;
        $table = Qibbs_Database::user_props();
        $wpdb->update( $table, array( 'is_equipped' => 0 ), array( 'user_id' => $user_id, 'prop_type' => 'name_color' ) );

        delete_user_meta( $user_id, 'qibbs_active_name_color' );
        delete_user_meta( $user_id, 'qibbs_name_color_expires' );

        return array(
            'success' => true,
            'message' => __( '已卸下名字颜色，恢复默认昵称', 'qibbs-community' ),
        );
    }

    /**
     * 自动检测并合并历史重复的道具记录（自愈去重与天数无缝合并）
     *
     * @param int $user_id 用户 ID.
     * @return void
     */
    public function consolidate_duplicate_backpack_props( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return;
        }

        global $wpdb;
        $user_props_table = Qibbs_Database::user_props();
        $posts_table      = Qibbs_Database::posts();
        $now              = current_time( 'mysql' );
        $now_time         = current_time( 'timestamp' );

        // 1. 合并重复的 active 名字颜色（同 prop_id 或相同色值）
        $color_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$user_props_table} 
             WHERE user_id = %d AND prop_type = 'name_color' AND status = 'active' AND (expires_at IS NULL OR expires_at > %s) 
             ORDER BY is_equipped DESC, id ASC",
            $user_id,
            $now
        ) );

        if ( ! empty( $color_rows ) && count( $color_rows ) > 1 ) {
            $grouped = array();
            foreach ( $color_rows as $row ) {
                $data = ! empty( $row->prop_data ) ? json_decode( $row->prop_data, true ) : array();
                $key = ! empty( $data['color'] ) ? strtolower( $data['color'] ) : (string) $row->prop_id;
                $grouped[ $key ][] = $row;
            }

            foreach ( $grouped as $group ) {
                if ( count( $group ) <= 1 ) {
                    continue;
                }
                $primary = $group[0];
                $primary_expire = ! empty( $primary->expires_at ) ? strtotime( $primary->expires_at ) : $now_time;
                $extra_seconds = 0;
                $total_cost = (float) $primary->cost_points;

                for ( $i = 1; $i < count( $group ); $i++ ) {
                    $dup = $group[ $i ];
                    $dup_expire = ! empty( $dup->expires_at ) ? strtotime( $dup->expires_at ) : 0;
                    if ( $dup_expire > $now_time ) {
                        $extra_seconds += ( $dup_expire - $now_time );
                    }
                    $total_cost += (float) $dup->cost_points;

                    $wpdb->update(
                        $user_props_table,
                        array( 'status' => 'merged', 'is_equipped' => 0, 'updated_at' => $now ),
                        array( 'id' => (int) $dup->id )
                    );
                }

                if ( $extra_seconds > 0 ) {
                    $new_expire_time = max( $primary_expire, $now_time ) + $extra_seconds;
                    $new_expires_at  = date( 'Y-m-d H:i:s', $new_expire_time );

                    $wpdb->update(
                        $user_props_table,
                        array(
                            'expires_at'  => $new_expires_at,
                            'cost_points' => $total_cost,
                            'updated_at'  => $now,
                        ),
                        array( 'id' => (int) $primary->id )
                    );

                    if ( ! empty( $primary->is_equipped ) ) {
                        update_user_meta( $user_id, 'qibbs_name_color_expires', $new_expire_time );
                    }
                }
            }
        }

        // 2. 合并重复的 active 圈子置顶推广（同一 post_id）
        $promo_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$user_props_table} 
             WHERE user_id = %d AND prop_type = 'post_sticky' AND status = 'active' AND (expires_at IS NULL OR expires_at > %s) 
             ORDER BY id ASC",
            $user_id,
            $now
        ) );

        if ( ! empty( $promo_rows ) && count( $promo_rows ) > 1 ) {
            $grouped_posts = array();
            foreach ( $promo_rows as $row ) {
                $data = ! empty( $row->prop_data ) ? json_decode( $row->prop_data, true ) : array();
                $post_id = ! empty( $data['post_id'] ) ? (int) $data['post_id'] : 0;
                if ( $post_id > 0 ) {
                    $grouped_posts[ $post_id ][] = $row;
                }
            }

            foreach ( $grouped_posts as $post_id => $group ) {
                if ( count( $group ) <= 1 ) {
                    continue;
                }
                $primary = $group[0];
                $primary_expire = ! empty( $primary->expires_at ) ? strtotime( $primary->expires_at ) : $now_time;
                $extra_seconds = 0;
                $total_cost = (float) $primary->cost_points;

                for ( $i = 1; $i < count( $group ); $i++ ) {
                    $dup = $group[ $i ];
                    $dup_expire = ! empty( $dup->expires_at ) ? strtotime( $dup->expires_at ) : 0;
                    if ( $dup_expire > $now_time ) {
                        $extra_seconds += ( $dup_expire - $now_time );
                    }
                    $total_cost += (float) $dup->cost_points;

                    $wpdb->update(
                        $user_props_table,
                        array( 'status' => 'merged', 'updated_at' => $now ),
                        array( 'id' => (int) $dup->id )
                    );
                }

                if ( $extra_seconds > 0 ) {
                    $new_expire_time = max( $primary_expire, $now_time ) + $extra_seconds;
                    $new_expires_at  = date( 'Y-m-d H:i:s', $new_expire_time );

                    $wpdb->update(
                        $user_props_table,
                        array(
                            'expires_at'  => $new_expires_at,
                            'cost_points' => $total_cost,
                            'updated_at'  => $now,
                        ),
                        array( 'id' => (int) $primary->id )
                    );

                    // 同步延展 post 本身的 promoted_until
                    $wpdb->update(
                        $posts_table,
                        array(
                            'is_promoted'    => 1,
                            'promoted_until' => $new_expires_at,
                            'updated_at'     => $now,
                        ),
                        array( 'id' => $post_id )
                    );

                    // 刷新该贴缓存
                    if ( class_exists( 'Qibbs_Cache_Service' ) && method_exists( 'Qibbs_Cache_Service', 'invalidate_post_cache' ) ) {
                        Qibbs_Cache_Service::invalidate_post_cache( $post_id );
                    }
                }
            }
        }
    }

    /**
     * 获取用户道具背包（已拥有的颜色道具和置顶状态）
     *
     * @param int $user_id 用户 ID.
     * @return array
     */
    public function get_user_backpack( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return array( 'colors' => array(), 'promotions' => array() );
        }

        // 自愈去重：若历史原因存在多条相同活跃颜色或同一动态置顶，自动合并叠加天数并归档冗余记录
        $this->consolidate_duplicate_backpack_props( $user_id );

        global $wpdb;
        $table = Qibbs_Database::user_props();
        $now = current_time( 'mysql' );

        // 查询用户的所有道具
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d AND status != 'merged' ORDER BY is_equipped DESC, id DESC LIMIT 50",
            $user_id
        ) );

        $colors = array();
        $promotions = array();

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $prop_data = ! empty( $row->prop_data ) ? json_decode( $row->prop_data, true ) : array();
                $is_expired = ( ! empty( $row->expires_at ) && $row->expires_at <= $now ) || 'expired' === (string) $row->status;

                $item = array(
                    'id'          => (int) $row->id,
                    'prop_id'     => (int) $row->prop_id,
                    'prop_name'   => $row->prop_name,
                    'prop_type'   => $row->prop_type,
                    'prop_data'   => $prop_data,
                    'is_equipped' => ! empty( $row->is_equipped ) && ! $is_expired,
                    'is_expired'  => $is_expired,
                    'expires_at'  => $row->expires_at,
                    'expires_tip' => $this->format_expires_tip( $row->expires_at, $is_expired ),
                    'created_at'  => $row->created_at,
                );

                if ( 'name_color' === (string) $row->prop_type ) {
                    $item['color'] = ! empty( $prop_data['color'] ) ? $prop_data['color'] : '#ef4444';
                    $colors[] = $item;
                } elseif ( 'post_sticky' === (string) $row->prop_type ) {
                    $item['post_id'] = ! empty( $prop_data['post_id'] ) ? (int) $prop_data['post_id'] : 0;
                    $promotions[] = $item;
                }
            }
        }

        return array(
            'colors'     => $colors,
            'promotions' => $promotions,
        );
    }

    /**
     * 格式化到期提示文案
     *
     * @param string|null $expires_at 到期时间.
     * @param bool        $is_expired 是否已过期.
     * @return string
     */
    private function format_expires_tip( $expires_at, $is_expired ) {
        if ( empty( $expires_at ) ) {
            return __( '永久有效', 'qibbs-community' );
        }
        if ( $is_expired ) {
            return __( '已到期', 'qibbs-community' );
        }
        $diff = strtotime( $expires_at ) - time();
        if ( $diff <= 0 ) {
            return __( '即将到期', 'qibbs-community' );
        }
        $days = ceil( $diff / DAY_IN_SECONDS );
        return sprintf( __( '剩余 %d 天', 'qibbs-community' ), $days );
    }

    /**
     * 获取用户可申请置顶推广的动态列表
     *
     * @param int $user_id 用户 ID.
     * @param int $limit   查询上限.
     * @return array
     */
    public function get_user_promotable_posts( $user_id, $limit = 30 ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return array();
        }

        global $wpdb;
        $posts_table  = Qibbs_Database::posts();
        $forums_table = Qibbs_Database::forums();
        $limit = max( 1, min( 100, absint( $limit ) ) );

        $sql = $wpdb->prepare(
            "SELECT p.id, p.content, p.forum_id, p.is_promoted, p.promoted_until, p.created_at, f.name AS forum_name 
             FROM {$posts_table} p 
             LEFT JOIN {$forums_table} f ON p.forum_id = f.id 
             WHERE p.author_id = %d AND p.status = 'publish' 
             ORDER BY p.id DESC LIMIT %d",
            $user_id,
            $limit
        );

        $rows = $wpdb->get_results( $sql );
        $list = array();

        if ( ! empty( $rows ) ) {
            $now = current_time( 'mysql' );
            foreach ( $rows as $row ) {
                $is_active_promoted = ( ! empty( $row->is_promoted ) && ( empty( $row->promoted_until ) || $row->promoted_until > $now ) );
                $excerpt = wp_trim_words( wp_strip_all_tags( $row->content ), 20, '...' );
                $list[] = array(
                    'id'                 => (int) $row->id,
                    'excerpt'            => $excerpt ? $excerpt : sprintf( __( '动态 #%d', 'qibbs-community' ), (int) $row->id ),
                    'forum_id'           => (int) $row->forum_id,
                    'forum_name'         => $row->forum_name ? $row->forum_name : __( '默认圈子', 'qibbs-community' ),
                    'is_promoted'        => $is_active_promoted,
                    'promoted_until'     => $row->promoted_until,
                    'created_at'         => $row->created_at,
                );
            }
        }

        return $list;
    }

    /**
     * 批量查询多个用户的活跃名字颜色（O(1) Map 预加载，彻底杜绝 N+1 查询）
     *
     * @param array<int> $user_ids 用户 ID 数组.
     * @return array<int,string> 用户 ID 对应的颜色色值 Map (如 [1 => '#ef4444']).
     */
    public function get_users_name_color_map( $user_ids ) {
        $user_ids = array_filter( array_map( 'absint', (array) $user_ids ) );
        if ( empty( $user_ids ) ) {
            return array();
        }

        global $wpdb;
        $table = Qibbs_Database::user_props();
        $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
        $now = current_time( 'mysql' );

        $sql = "SELECT user_id, prop_data, expires_at 
                FROM {$table} 
                WHERE user_id IN ({$placeholders}) 
                  AND prop_type = 'name_color' 
                  AND is_equipped = 1 
                  AND status = 'active' 
                  AND (expires_at IS NULL OR expires_at > %s)";

        $args = $user_ids;
        $args[] = $now;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
        $map = array();

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $row ) {
                $data = ! empty( $row->prop_data ) ? json_decode( $row->prop_data, true ) : array();
                if ( ! empty( $data['color'] ) ) {
                    $map[ (int) $row->user_id ] = sanitize_hex_color( $data['color'] );
                }
            }
        }

        return $map;
    }

    /**
     * 获取单个用户当前穿戴的昵称颜色
     *
     * @param int $user_id 用户 ID.
     * @return string 色值代码或空字符串.
     */
    public function get_user_active_name_color( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return '';
        }

        $cached_color = get_user_meta( $user_id, 'qibbs_active_name_color', true );
        $expires = absint( get_user_meta( $user_id, 'qibbs_name_color_expires', true ) );

        if ( $cached_color ) {
            if ( $expires > 0 && $expires <= time() ) {
                // 已过期
                delete_user_meta( $user_id, 'qibbs_active_name_color' );
                delete_user_meta( $user_id, 'qibbs_name_color_expires' );
                return '';
            }
            return sanitize_hex_color( $cached_color );
        }

        return '';
    }

    /**
     * WP-Cron 定时检查并失效超期的道具
     */
    public function check_and_expire_props() {
        global $wpdb;
        $user_props_table = Qibbs_Database::user_props();
        $posts_table      = Qibbs_Database::posts();
        $now              = current_time( 'mysql' );

        // 1. 失效到期的名字颜色道具并清理 user_meta
        $expired_colors = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id FROM {$user_props_table} 
             WHERE prop_type = 'name_color' AND status = 'active' AND expires_at IS NOT NULL AND expires_at <= %s 
             LIMIT 100",
            $now
        ) );

        if ( ! empty( $expired_colors ) ) {
            foreach ( $expired_colors as $item ) {
                $wpdb->update(
                    $user_props_table,
                    array( 'status' => 'expired', 'is_equipped' => 0, 'updated_at' => $now ),
                    array( 'id' => (int) $item->id ),
                    array( '%s', '%d', '%s' ),
                    array( '%d' )
                );
                delete_user_meta( (int) $item->user_id, 'qibbs_active_name_color' );
                delete_user_meta( (int) $item->user_id, 'qibbs_name_color_expires' );
            }
        }

        // 2. 失效到期的圈子置顶推广动态
        $expired_promotions = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, author_id FROM {$posts_table} 
             WHERE is_promoted = 1 AND promoted_until IS NOT NULL AND promoted_until <= %s 
             LIMIT 100",
            $now
        ) );

        if ( ! empty( $expired_promotions ) ) {
            foreach ( $expired_promotions as $post ) {
                $wpdb->update(
                    $posts_table,
                    array( 'is_promoted' => 0, 'updated_at' => $now ),
                    array( 'id' => (int) $post->id ),
                    array( '%d', '%s' ),
                    array( '%d' )
                );

                if ( class_exists( 'Qibbs_Notification_Service' ) ) {
                    try {
                        Qibbs_Notification_Service::send(
                            (int) $post->author_id,
                            'promotion_expired',
                            sprintf( __( '您在动态 #%d 购买的圈子置顶推广已到期结束。', 'qibbs-community' ), (int) $post->id ),
                            (int) $post->id,
                            'post'
                        );
                    } catch ( Throwable $e ) {}
                }
            }
        }
    }

    /**
     * 自动检测并自愈道具相关数据表结构
     */
    public function ensure_tables_exist() {
        global $wpdb;
        $user_props_table = Qibbs_Database::user_props();
        $props_table      = Qibbs_Database::props();

        $user_props_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $user_props_table ) ) === $user_props_table );
        $props_exists      = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $props_table ) ) === $props_table );

        if ( ! $user_props_exists || ! $props_exists ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset_collate = $wpdb->get_charset_collate();
            $prefix = $wpdb->prefix . 'qibbs_';

            $sql_props = "CREATE TABLE {$prefix}props (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                prop_type VARCHAR(50) NOT NULL,
                description TEXT,
                icon VARCHAR(100) DEFAULT '',
                price_points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                duration_days INT DEFAULT 30,
                meta_data LONGTEXT,
                stock INT DEFAULT -1,
                sales_count INT DEFAULT 0,
                status VARCHAR(20) DEFAULT 'active',
                sort_order INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_type_status (prop_type, status),
                KEY idx_sort (sort_order ASC)
            ) $charset_collate;";
            dbDelta( $sql_props );

            $sql_user_props = "CREATE TABLE {$prefix}user_props (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                prop_id BIGINT UNSIGNED NOT NULL,
                prop_type VARCHAR(50) NOT NULL,
                prop_name VARCHAR(100) NOT NULL,
                prop_data LONGTEXT,
                is_equipped TINYINT(1) DEFAULT 0,
                expires_at DATETIME DEFAULT NULL,
                status VARCHAR(20) DEFAULT 'active',
                cost_points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_type_status (user_id, prop_type, status),
                KEY idx_equipped (user_id, is_equipped),
                KEY idx_expires (status, expires_at)
            ) $charset_collate;";
            dbDelta( $sql_user_props );
        }
    }

    /**
     * 自动预置开箱即用默认道具（如果表为空）
     */
    public function ensure_default_seed_props() {
        $this->ensure_tables_exist();
        global $wpdb;
        $table = Qibbs_Database::props();
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $count > 0 ) {
            return;
        }

        $now = current_time( 'mysql' );
        $seeds = array(
            array(
                'name'          => __( '烈焰中国红 (30天)', 'qibbs-community' ),
                'prop_type'     => 'name_color',
                'description'   => __( '让你的用户名换成醒目的中国红，全站动态与评论专属彰显。', 'qibbs-community' ),
                'price_points'  => 100.0,
                'duration_days' => 30,
                'meta_data'     => wp_json_encode( array( 'color' => '#ef4444', 'style' => 'solid' ) ),
                'stock'         => -1,
                'sort_order'    => 1,
            ),
            array(
                'name'          => __( '深海极客蓝 (30天)', 'qibbs-community' ),
                'prop_type'     => 'name_color',
                'description'   => __( '让你的用户名换成沉稳睿智的科技蓝，尊贵专属身份。', 'qibbs-community' ),
                'price_points'  => 100.0,
                'duration_days' => 30,
                'meta_data'     => wp_json_encode( array( 'color' => '#3b82f6', 'style' => 'solid' ) ),
                'stock'         => -1,
                'sort_order'    => 2,
            ),
            array(
                'name'          => __( '翡翠生机绿 (30天)', 'qibbs-community' ),
                'prop_type'     => 'name_color',
                'description'   => __( '让你的用户名换成清新灵动的翡翠绿，个性十足。', 'qibbs-community' ),
                'price_points'  => 100.0,
                'duration_days' => 30,
                'meta_data'     => wp_json_encode( array( 'color' => '#10b981', 'style' => 'solid' ) ),
                'stock'         => -1,
                'sort_order'    => 3,
            ),
            array(
                'name'          => __( '璀璨至尊金 (30天)', 'qibbs-community' ),
                'prop_type'     => 'name_color',
                'description'   => __( '让你的用户名换成耀眼夺目的琥珀金，全站焦点。', 'qibbs-community' ),
                'price_points'  => 150.0,
                'duration_days' => 30,
                'meta_data'     => wp_json_encode( array( 'color' => '#f59e0b', 'style' => 'solid' ) ),
                'stock'         => -1,
                'sort_order'    => 4,
            ),
            array(
                'name'          => __( '永久限量·幻夜星辰紫', 'qibbs-community' ),
                'prop_type'     => 'name_color',
                'description'   => __( '永久有效！全站限量发售 50 个，卖完不补，绝版典藏。', 'qibbs-community' ),
                'price_points'  => 999.0,
                'duration_days' => 0,
                'meta_data'     => wp_json_encode( array( 'color' => '#8b5cf6', 'style' => 'solid' ) ),
                'stock'         => 50,
                'sort_order'    => 5,
            ),
            array(
                'name'          => __( '圈子置顶推广卡 (3天)', 'qibbs-community' ),
                'prop_type'     => 'post_sticky',
                'description'   => __( '把自己的帖子在所属圈子置顶 3 天，标「推广」，曝光翻倍。', 'qibbs-community' ),
                'price_points'  => 150.0,
                'duration_days' => 3,
                'meta_data'     => wp_json_encode( array( 'scope' => 'forum' ) ),
                'stock'         => -1,
                'sort_order'    => 10,
            ),
            array(
                'name'          => __( '圈子置顶推广卡 (7天)', 'qibbs-community' ),
                'prop_type'     => 'post_sticky',
                'description'   => __( '把自己的帖子在所属圈子置顶 7 天，标「推广」，持续吸睛。', 'qibbs-community' ),
                'price_points'  => 300.0,
                'duration_days' => 7,
                'meta_data'     => wp_json_encode( array( 'scope' => 'forum' ) ),
                'stock'         => -1,
                'sort_order'    => 11,
            ),
            array(
                'name'          => __( '圈子置顶推广卡 (30天)', 'qibbs-community' ),
                'prop_type'     => 'post_sticky',
                'description'   => __( '把自己的帖子在所属圈子置顶 30 天，长效推广，超值特惠。', 'qibbs-community' ),
                'price_points'  => 1000.0,
                'duration_days' => 30,
                'meta_data'     => wp_json_encode( array( 'scope' => 'forum' ) ),
                'stock'         => -1,
                'sort_order'    => 12,
            ),
        );

        foreach ( $seeds as $seed ) {
            $wpdb->insert(
                $table,
                array(
                    'name'          => $seed['name'],
                    'prop_type'     => $seed['prop_type'],
                    'description'   => $seed['description'],
                    'price_points'  => $seed['price_points'],
                    'duration_days' => $seed['duration_days'],
                    'meta_data'     => $seed['meta_data'],
                    'stock'         => $seed['stock'],
                    'sales_count'   => 0,
                    'status'        => 'active',
                    'sort_order'    => $seed['sort_order'],
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ),
                array( '%s', '%s', '%s', '%f', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s' )
            );
        }
    }
}
