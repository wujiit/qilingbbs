<?php
/**
 * 社区会员核心身份与 UID 映射服务
 * 负责用户身份与社区 UID 双向映射、保底号自动分配、佩戴切换与高速缓存
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 社区会员与 UID 核心调度服务
 */
class Qibbs_Member_Service {

    /**
     * 单例实例
     *
     * @var Qibbs_Member_Service|null
     */
    private static $instance = null;

    /**
     * 运行期进程内缓存
     *
     * @var array
     */
    private static $runtime_user_cuid = array();
    private static $runtime_cuid_user = array();

    /**
     * 获取单例实例
     *
     * @return Qibbs_Member_Service
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 获取用户当前激活对外展示的社区 UID
     *
     * 若用户尚未初始化社区档案，则采用按需延迟机制（Lazy Init）为其自动分配保底号
     *
     * @param int $user_id WordPress User ID.
     * @return int Active Community UID.
     */
    public function get_user_cuid( $user_id ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return 0;
        }

        // 1. 检查运行时内存缓存
        if ( isset( self::$runtime_user_cuid[ $user_id ] ) ) {
            return self::$runtime_user_cuid[ $user_id ];
        }

        // 2. 检查对象缓存
        $cache_key = 'qibbs_uid_user_' . $user_id;
        $cached = wp_cache_get( $cache_key, 'qibbs_members' );
        if ( false !== $cached ) {
            $cuid = absint( $cached );
            self::$runtime_user_cuid[ $user_id ] = $cuid;
            return $cuid;
        }

        global $wpdb;
        $table = Qibbs_Database::members();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT default_cuid, active_cuid FROM {$table} WHERE user_id = %d LIMIT 1",
                $user_id
            )
        );

        if ( $row ) {
            $active_cuid = absint( $row->active_cuid ? $row->active_cuid : $row->default_cuid );
            $this->cache_mapping( $user_id, $active_cuid );
            return $active_cuid;
        }

        // 3. 首次访问未建档：惰性自动分配保底初始 UID
        $allocated_cuid = $this->allocate_default_cuid( $user_id );
        return $allocated_cuid;
    }

    /**
     * 根据社区 UID 反查对应用户 ID
     *
     * @param int $cuid Community UID.
     * @return int User ID or 0 if not found.
     */
    public function get_user_id_by_cuid( $cuid ) {
        $cuid = absint( $cuid );
        if ( $cuid <= 0 ) {
            return 0;
        }

        // 1. 检查运行时内存缓存
        if ( isset( self::$runtime_cuid_user[ $cuid ] ) ) {
            return self::$runtime_cuid_user[ $cuid ];
        }

        // 2. 检查对象缓存
        $cache_key = 'qibbs_uid_cuid_' . $cuid;
        $cached = wp_cache_get( $cache_key, 'qibbs_members' );
        if ( false !== $cached ) {
            $uid = absint( $cached );
            self::$runtime_cuid_user[ $cuid ] = $uid;
            return $uid;
        }

        global $wpdb;
        $members_table = Qibbs_Database::members();

        // 优先匹配当前佩戴该 UID 的用户
        $user_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$members_table} WHERE active_cuid = %d LIMIT 1",
                $cuid
            )
        );

        // 若佩戴未命中，尝试匹配保底号
        if ( ! $user_id ) {
            $user_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT user_id FROM {$members_table} WHERE default_cuid = %d LIMIT 1",
                    $cuid
                )
            );
        }

        $this->cache_mapping( $user_id, $cuid );
        return $user_id;
    }

    /**
     * 为新注册或首次进入社区的用户分配全局唯一的保底社区 UID
     *
     * @param int $user_id WordPress User ID.
     * @return int Assigned default Community UID.
     */
    public function allocate_default_cuid( $user_id ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return 0;
        }

        global $wpdb;
        $members_table = Qibbs_Database::members();
        $vanity_table  = Qibbs_Database::vanity_uids();

        // 再次防重检查
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT active_cuid FROM {$members_table} WHERE user_id = %d LIMIT 1",
                $user_id
            )
        );
        if ( $existing > 0 ) {
            $this->cache_mapping( $user_id, $existing );
            return $existing;
        }

        $rule_service = Qibbs_UID_Rule_Service::instance();
        $settings     = $rule_service->get_settings();
        $cursor_val   = get_option( 'qibbs_uid_free_cursor', null );

        if ( null === $cursor_val || (int) $cursor_val < $settings['free_start'] ) {
            $candidate = (int) $settings['free_start'];
        } else {
            $candidate = (int) $cursor_val + 1;
        }

        $max_attempts  = 10000;
        $attempt       = 0;
        $assigned_cuid = 0;

        while ( $attempt < $max_attempts ) {
            $attempt++;

            // 安全守卫1：检查是否为官方系统保留号
            if ( $rule_service->is_reserved( $candidate ) ) {
                $candidate++;
                continue;
            }

            // 安全守卫2：检查是否命中商业靓号规则
            if ( $rule_service->is_vanity( $candidate ) ) {
                $candidate++;
                continue;
            }

            // 安全守卫3：检查数据库唯一键冲突
            $occupied_member = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT user_id FROM {$members_table} WHERE default_cuid = %d OR active_cuid = %d LIMIT 1",
                    $candidate,
                    $candidate
                )
            );
            if ( $occupied_member > 0 ) {
                $candidate++;
                continue;
            }

            $occupied_vanity = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT cuid FROM {$vanity_table} WHERE cuid = %d LIMIT 1",
                    $candidate
                )
            );
            if ( $occupied_vanity > 0 ) {
                $candidate++;
                continue;
            }

            // 成功寻找到符合条件的合规普通号
            $assigned_cuid = $candidate;
            break;
        }

        // 极端防御保底：若探测耗尽仍未寻得，使用大基数哈希散列
        if ( $assigned_cuid <= 0 ) {
            $assigned_cuid = (int) ( $settings['free_start'] + $user_id + 88888 );
        }

        // 原子写入会员档案表
        $now = current_time( 'mysql' );
        $inserted = $wpdb->insert(
            $members_table,
            array(
                'user_id'      => $user_id,
                'default_cuid' => $assigned_cuid,
                'active_cuid'  => $assigned_cuid,
                'created_at'   => $now,
                'updated_at'   => $now,
            ),
            array( '%d', '%d', '%d', '%s', '%s' )
        );

        if ( false !== $inserted ) {
            update_option( 'qibbs_uid_free_cursor', $assigned_cuid );
            $this->cache_mapping( $user_id, $assigned_cuid );

            /**
             * 新用户成功分配初始社区 UID 动作钩子
             *
             * @param int $user_id       User ID.
             * @param int $assigned_cuid Assigned default Community UID.
             */
            do_action( 'qibbs_uid_assigned', $user_id, $assigned_cuid );
        }

        return $assigned_cuid;
    }

    /**
     * 切换当前激活佩戴的社区 UID
     *
     * @param int $user_id     User ID.
     * @param int $target_cuid Target CUID to equip.
     * @return array{success:bool, message:string}
     */
    public function equip_cuid( $user_id, $target_cuid ) {
        $user_id     = absint( $user_id );
        $target_cuid = absint( $target_cuid );

        if ( $user_id <= 0 || $target_cuid <= 0 ) {
            return array(
                'success' => false,
                'message' => __( '无效的用户或号码', 'qibbs-community' ),
            );
        }

        global $wpdb;
        $members_table = Qibbs_Database::members();
        $vanity_table  = Qibbs_Database::vanity_uids();

        // 获取用户档案
        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT default_cuid, active_cuid FROM {$members_table} WHERE user_id = %d LIMIT 1",
                $user_id
            )
        );

        if ( ! $member ) {
            return array(
                'success' => false,
                'message' => __( '会员档案不存在，请刷新重试', 'qibbs-community' ),
            );
        }

        $old_cuid = (int) $member->active_cuid;
        if ( $old_cuid === $target_cuid ) {
            return array(
                'success' => true,
                'message' => __( '当前已佩戴该号码', 'qibbs-community' ),
            );
        }

        // 权限与归属权校验：必须为该用户的保底号，或持有于其背包中的有效靓号
        $is_owner = false;
        if ( (int) $member->default_cuid === $target_cuid ) {
            $is_owner = true;
        } else {
            $vanity_owner = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT owner_id FROM {$vanity_table} WHERE cuid = %d AND status = 'owned' LIMIT 1",
                    $target_cuid
                )
            );
            if ( $vanity_owner === $user_id ) {
                $is_owner = true;
            }
        }

        if ( ! $is_owner ) {
            return array(
                'success' => false,
                'message' => __( '您未拥有该号码或号码已被锁定交易', 'qibbs-community' ),
            );
        }

        // 更新激活主号
        $updated = $wpdb->update(
            $members_table,
            array(
                'active_cuid' => $target_cuid,
                'updated_at'  => current_time( 'mysql' ),
            ),
            array( 'user_id' => $user_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return array(
                'success' => false,
                'message' => __( '佩戴失败，请稍后重试', 'qibbs-community' ),
            );
        }

        // 刷新高速缓存
        $this->invalidate_user_cache( $user_id, $old_cuid );
        $this->cache_mapping( $user_id, $target_cuid );

        /**
         * 用户佩戴社区 UID 变更动作钩子
         *
         * @param int $user_id     User ID.
         * @param int $target_cuid Newly equipped CUID.
         * @param int $old_cuid    Previously equipped CUID.
         */
        do_action( 'qibbs_uid_equipped', $user_id, $target_cuid, $old_cuid );

        return array(
            'success' => true,
            'message' => __( '号码佩戴成功！已设置为对外展示主号', 'qibbs-community' ),
        );
    }

    /**
     * 获取用户号码背包清单（包括保底号与持有的所有靓号）
     *
     * @param int $user_id User ID.
     * @return array
     */
    public function get_user_backpack( $user_id ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return array();
        }

        // 确保已建档
        $active_cuid = $this->get_user_cuid( $user_id );

        global $wpdb;
        $members_table = Qibbs_Database::members();
        $vanity_table  = Qibbs_Database::vanity_uids();

        $member = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT default_cuid, active_cuid FROM {$members_table} WHERE user_id = %d LIMIT 1",
                $user_id
            )
        );

        $default_cuid = $member ? (int) $member->default_cuid : $active_cuid;
        $active_cuid  = $member ? (int) $member->active_cuid : $active_cuid;

        // 获取用户持有的商业靓号
        $vanity_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cuid, grade, status, sale_price, price_cash, c2c_price_cash, c2c_price_points, c2c_listed_at, obtained_at, transfer_count
                 FROM {$vanity_table}
                 WHERE owner_id = %d OR c2c_seller_id = %d
                 ORDER BY grade DESC, cuid ASC",
                $user_id,
                $user_id
            )
        );

        $items = array();

        // 1. 保底系统号
        $items[] = array(
            'cuid'          => $default_cuid,
            'is_default'    => true,
            'is_vanity'     => false,
            'is_active'     => ( $default_cuid === $active_cuid ),
            'grade'         => 0,
            'grade_label'   => __( '系统保底号', 'qibbs-community' ),
            'status'        => 'owned',
            'can_sell'      => false,
            'is_c2c_listed' => false,
            'can_delist'    => false,
        );

        // 2. 靓号资产
        $grade_labels = Qibbs_UID_Rule_Service::instance()->get_grade_labels_map();

        foreach ( (array) $vanity_rows as $row ) {
            $cuid          = (int) $row->cuid;
            $is_c2c_listed = ( 'c2c_listed' === $row->status );
            $items[] = array(
                'cuid'             => $cuid,
                'is_default'       => false,
                'is_vanity'        => true,
                'is_active'        => ( $cuid === $active_cuid ),
                'grade'            => (int) $row->grade,
                'grade_label'      => isset( $grade_labels[ $row->grade ] ) ? $grade_labels[ $row->grade ] : __( '靓号', 'qibbs-community' ),
                'status'           => (string) $row->status,
                'sale_price'       => (int) $row->sale_price,
                'price_cash'       => (float) $row->price_cash,
                'c2c_price_cash'   => (float) $row->c2c_price_cash,
                'c2c_price_points' => (int) $row->c2c_price_points,
                'is_c2c_listed'    => $is_c2c_listed,
                'can_delist'       => $is_c2c_listed,
                'obtained_at'      => $row->obtained_at,
                'transfer_count'   => (int) $row->transfer_count,
                'can_sell'         => ( 'owned' === $row->status ),
            );
        }

        return $items;
    }

    /**
     * 写入双向缓存 ($O(1) 效率保证)
     *
     * @param int $user_id User ID.
     * @param int $cuid    Community UID.
     * @return void
     */
    public function cache_mapping( $user_id, $cuid ) {
        if ( $user_id > 0 && $cuid > 0 ) {
            self::$runtime_user_cuid[ $user_id ] = $cuid;
            self::$runtime_cuid_user[ $cuid ]    = $user_id;

            wp_cache_set( 'qibbs_uid_user_' . $user_id, $cuid, 'qibbs_members', 86400 );
            wp_cache_set( 'qibbs_uid_cuid_' . $cuid, $user_id, 'qibbs_members', 86400 );
        }
    }

    /**
     * 精准失效对应用户的缓存
     *
     * @param int $user_id User ID.
     * @param int $cuid    Community UID.
     * @return void
     */
    public function invalidate_user_cache( $user_id, $cuid = 0 ) {
        if ( $user_id > 0 ) {
            unset( self::$runtime_user_cuid[ $user_id ] );
            wp_cache_delete( 'qibbs_uid_user_' . $user_id, 'qibbs_members' );
        }

        if ( $cuid > 0 ) {
            unset( self::$runtime_cuid_user[ $cuid ] );
            wp_cache_delete( 'qibbs_uid_cuid_' . $cuid, 'qibbs_members' );
        }
    }

    /**
     * 获取社区会员宏观指标统计
     *
     * @return array{total_members:int, total_vanity_owners:int, total_balance_pool:float, total_banned:int}
     */
    public function get_member_summary_stats() {
        global $wpdb;
        $members_table   = Qibbs_Database::members();
        $vanity_table    = Qibbs_Database::vanity_uids();
        $black_table     = Qibbs_Database::blacklist();
        $shop_info_table = $wpdb->prefix . 'qilingshop_user_info';

        $total_members = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$members_table}" );
        $total_vanity_owners = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT CASE WHEN c2c_seller_id > 0 THEN c2c_seller_id ELSE owner_id END) 
             FROM {$vanity_table} 
             WHERE status IN ('owned', 'c2c_listed')"
        );

        $total_balance_pool = 0.00;
        $has_shop_table     = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $shop_info_table ) ) === $shop_info_table );
        if ( $has_shop_table ) {
            $total_balance_pool = (float) $wpdb->get_var( "SELECT SUM(withdrawable_balance) FROM {$shop_info_table}" );
        }

        $total_banned = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$black_table}" );

        return array(
            'total_members'       => $total_members,
            'total_vanity_owners' => $total_vanity_owners,
            'total_balance_pool'  => max( 0.0, $total_balance_pool ),
            'total_banned'        => $total_banned,
        );
    }

    /**
     * 管理员人工调账：直接调整用户在启灵商城的【可提现余额】
     *
     * @param int    $user_id  Target User ID.
     * @param float  $amount   Amount to adjust.
     * @param string $type     'add' or 'deduct'.
     * @param string $note     Admin remark.
     * @param int    $admin_id Admin User ID.
     * @return array{success:bool, message:string, new_balance:float}
     */
    public function adjust_balance( $user_id, $amount, $type = 'add', $note = '', $admin_id = 0 ) {
        $user_id  = absint( $user_id );
        $amount   = round( abs( (float) $amount ), 2 );
        $type     = 'deduct' === $type ? 'deduct' : 'add';
        $note     = sanitize_text_field( $note );
        $admin_id = absint( $admin_id );

        if ( $user_id <= 0 || $amount <= 0 ) {
            return array(
                'success'     => false,
                'message'     => __( '无效的用户或调整金额', 'qibbs-community' ),
                'new_balance' => 0.00,
            );
        }

        // 确保会员已在社区建档
        $this->get_user_cuid( $user_id );

        global $wpdb;
        $shop_info_table = $wpdb->prefix . 'qilingshop_user_info';
        $current_balance = function_exists( 'qibbs_get_user_withdrawable_balance' )
            ? qibbs_get_user_withdrawable_balance( $user_id )
            : 0.00;

        if ( 'deduct' === $type && $current_balance < $amount ) {
            return array(
                'success'     => false,
                'message'     => sprintf( __( '扣除失败：用户在启灵商城的【可提现余额】仅为 ¥%s，不足以扣除 ¥%s', 'qibbs-community' ), number_format( $current_balance, 2 ), number_format( $amount, 2 ) ),
                'new_balance' => $current_balance,
            );
        }

        $now = current_time( 'mysql' );

        if ( 'add' === $type ) {
            if ( class_exists( 'QilingShop_Points' ) ) {
                $desc = $note ? $note : __( '管理员人工调增可提现余额', 'qibbs-community' );
                QilingShop_Points::instance()->add_withdrawable_balance(
                    $user_id,
                    $amount,
                    'admin_adjust',
                    $desc,
                    $admin_id
                );
            }
        } else {
            // 扣减可提现余额
            $affected = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$shop_info_table} SET withdrawable_balance = withdrawable_balance - %f, updated_at = %s WHERE user_id = %d AND withdrawable_balance >= %f",
                    $amount,
                    $now,
                    $user_id,
                    $amount
                )
            );

            if ( false === $affected || (int) $affected < 1 ) {
                return array(
                    'success'     => false,
                    'message'     => __( '扣除失败：商城可提现余额不足或更新受阻', 'qibbs-community' ),
                    'new_balance' => $current_balance,
                );
            }

            // 写入商城流水
            $log_table = $wpdb->prefix . 'qilingshop_points_log';
            $after_balance = function_exists( 'qibbs_get_user_withdrawable_balance' )
                ? qibbs_get_user_withdrawable_balance( $user_id )
                : max( 0.0, $current_balance - $amount );

            $wpdb->insert(
                $log_table,
                array(
                    'user_id'       => $user_id,
                    'amount'        => -$amount,
                    'balance_after' => $after_balance,
                    'type'          => 'outcome',
                    'source'        => 'admin_deduct',
                    'description'   => $note ? $note : __( '管理员人工扣减可提现余额', 'qibbs-community' ),
                    'related_id'    => $admin_id,
                    'ip_address'    => function_exists( 'qilingshop_security' ) ? qilingshop_security()->get_client_ip() : '',
                    'created_at'    => $now,
                ),
                array( '%d', '%f', '%f', '%s', '%s', '%s', '%d', '%s', '%s' )
            );

            if ( class_exists( 'QilingShop_Points' ) ) {
                QilingShop_Points::instance()->clear_user_cache( $user_id );
            }
        }

        $new_balance = function_exists( 'qibbs_get_user_withdrawable_balance' )
            ? qibbs_get_user_withdrawable_balance( $user_id )
            : ( 'add' === $type ? $current_balance + $amount : max( 0.0, $current_balance - $amount ) );

        // 发送站内信通知
        $notif_content = sprintf(
            __( '系统管理员为您进行了启灵商城【可提现余额】调账：%s¥%s。备注：%s。当前最新余额：¥%s', 'qibbs-community' ),
            ( 'add' === $type ? '+' : '-' ),
            number_format( $amount, 2 ),
            $note ? $note : __( '管理员操作', 'qibbs-community' ),
            number_format( $new_balance, 2 )
        );
        $notif_table = Qibbs_Database::notifications();
        $wpdb->insert(
            $notif_table,
            array(
                'user_id'     => $user_id,
                'actor_id'    => $admin_id,
                'type'        => 'system',
                'object_type' => 'wallet',
                'object_id'   => $user_id,
                'content'     => $notif_content,
                'is_read'     => 0,
                'created_at'  => $now,
            ),
            array( '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s' )
        );
        if ( class_exists( 'Qibbs_Notification_Delivery' ) ) {
            Qibbs_Notification_Delivery::invalidate_unread_cache( $user_id );
        }

        do_action( 'qibbs_member_balance_adjusted', $user_id, $amount, $type, $note, $admin_id, $new_balance );

        return array(
            'success'     => true,
            'message'     => sprintf( __( '调账成功！用户在启灵商城的最新【可提现余额】为：¥%s', 'qibbs-community' ), number_format( $new_balance, 2 ) ),
            'new_balance' => $new_balance,
        );
    }

    /**
     * 快捷封禁或解封用户黑名单状态
     *
     * @param int    $user_id  Target User ID.
     * @param bool   $ban      True to ban, false to unban.
     * @param string $reason   Ban reason.
     * @param int    $admin_id Admin User ID.
     * @return array{success:bool, message:string}
     */
    public function toggle_blacklist( $user_id, $ban = true, $reason = '', $admin_id = 0 ) {
        $user_id  = absint( $user_id );
        $admin_id = absint( $admin_id );
        $reason   = sanitize_textarea_field( $reason );

        if ( $user_id <= 0 ) {
            return array( 'success' => false, 'message' => __( '无效的用户ID', 'qibbs-community' ) );
        }

        global $wpdb;
        $table = Qibbs_Database::blacklist();

        if ( $ban ) {
            $existing = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d LIMIT 1", $user_id )
            );
            if ( $existing > 0 ) {
                $wpdb->update(
                    $table,
                    array(
                        'reason'     => $reason,
                        'admin_id'   => $admin_id,
                        'created_at' => current_time( 'mysql' ),
                    ),
                    array( 'id' => $existing ),
                    array( '%s', '%d', '%s' ),
                    array( '%d' )
                );
            } else {
                $wpdb->insert(
                    $table,
                    array(
                        'user_id'    => $user_id,
                        'reason'     => $reason,
                        'admin_id'   => $admin_id,
                        'created_at' => current_time( 'mysql' ),
                    ),
                    array( '%d', '%s', '%d', '%s' )
                );
            }
            return array( 'success' => true, 'message' => __( '该用户已成功加入黑名单（限制发帖、评论及私信）', 'qibbs-community' ) );
        } else {
            $wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
            return array( 'success' => true, 'message' => __( '该用户已成功解除黑名单封禁', 'qibbs-community' ) );
        }
    }

    /**
     * 管理员强制重置用户的当前佩戴号为保底初始号
     *
     * @param int $user_id Target User ID.
     * @return array{success:bool, message:string, default_cuid:int}
     */
    public function reset_to_default_cuid( $user_id ) {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return array( 'success' => false, 'message' => __( '无效用户', 'qibbs-community' ), 'default_cuid' => 0 );
        }

        global $wpdb;
        $table = Qibbs_Database::members();
        $default_cuid = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT default_cuid FROM {$table} WHERE user_id = %d LIMIT 1", $user_id )
        );

        if ( ! $default_cuid ) {
            $default_cuid = $this->allocate_default_cuid( $user_id );
        }

        $wpdb->update(
            $table,
            array(
                'active_cuid' => $default_cuid,
                'updated_at'  => current_time( 'mysql' ),
            ),
            array( 'user_id' => $user_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        $this->invalidate_user_cache( $user_id );
        $this->cache_mapping( $user_id, $default_cuid );

        return array(
            'success'      => true,
            'message'      => sprintf( __( '已将用户佩戴号重置为保底初始号：%d', 'qibbs-community' ), $default_cuid ),
            'default_cuid' => $default_cuid,
        );
    }

    /**
     * 获取用户 360° 社区全维档案（用于后台用户画像抽屉）
     *
     * @param int $user_id Target User ID.
     * @return array|null
     */
    public function get_user_360_profile( $user_id ) {
        $user_id = absint( $user_id );
        $wp_user = get_userdata( $user_id );
        if ( ! $wp_user ) {
            return null;
        }

        global $wpdb;
        $members_table = Qibbs_Database::members();
        $stats_table   = Qibbs_Database::user_stats();
        $black_table   = Qibbs_Database::blacklist();
        $orders_table  = Qibbs_Database::orders();
        $c2c_table     = Qibbs_Database::c2c_trades();

        $member_row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$members_table} WHERE user_id = %d LIMIT 1", $user_id )
        );
        $default_cuid = $member_row ? (int) $member_row->default_cuid : $this->get_user_cuid( $user_id );
        $active_cuid  = $member_row ? (int) $member_row->active_cuid : $default_cuid;
        $balance      = function_exists( 'qibbs_get_user_withdrawable_balance' ) ? qibbs_get_user_withdrawable_balance( $user_id ) : ( $member_row ? (float) $member_row->balance : 0.00 );

        $stats_row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$stats_table} WHERE user_id = %d LIMIT 1", $user_id )
        );

        $black_row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$black_table} WHERE user_id = %d LIMIT 1", $user_id )
        );

        $backpack = $this->get_user_backpack( $user_id );
        $points   = function_exists( 'qibbs_get_user_points_balance' ) ? qibbs_get_user_points_balance( $user_id ) : 0;

        $recent_orders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$orders_table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 5",
                $user_id
            )
        );

        $recent_trades = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$c2c_table} WHERE seller_id = %d OR buyer_id = %d ORDER BY created_at DESC LIMIT 5",
                $user_id,
                $user_id
            )
        );

        $forum_roles = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT f.name as forum_name, fm.role 
                 FROM " . Qibbs_Database::forum_members() . " fm 
                 JOIN " . Qibbs_Database::forums() . " f ON fm.forum_id = f.id 
                 WHERE fm.user_id = %d",
                $user_id
            )
        );

        return array(
            'user_id'        => $user_id,
            'user_login'     => $wp_user->user_login,
            'display_name'   => $wp_user->display_name,
            'user_email'     => $wp_user->user_email,
            'avatar_url'     => get_avatar_url( $user_id, array( 'size' => 96 ) ),
            'registered'     => $wp_user->user_registered,
            'default_cuid'   => $default_cuid,
            'active_cuid'    => $active_cuid,
            'balance'        => $balance,
            'points'         => $points,
            'post_count'     => $stats_row ? (int) $stats_row->post_count : 0,
            'follower_count' => $stats_row ? (int) $stats_row->follower_count : 0,
            'like_count'     => $stats_row ? (int) $stats_row->like_count : 0,
            'is_banned'      => ! empty( $black_row ),
            'ban_reason'     => $black_row ? (string) $black_row->reason : '',
            'ban_time'       => $black_row ? $black_row->created_at : '',
            'backpack'       => $backpack,
            'recent_orders'  => $recent_orders,
            'recent_trades'  => $recent_trades,
            'forum_roles'    => $forum_roles,
        );
    }

    /**
     * 管理员人工重指派/修改用户的社区 UID（支持指定保底号与佩戴号）
     *
     * @param int  $user_id        Target User ID.
     * @param int  $new_cuid       New CUID.
     * @param bool $is_active_also Whether to also set as active CUID.
     * @return array{success:bool, message:string}
     */
    public function update_user_cuid( $user_id, $new_cuid, $is_active_also = true ) {
        $user_id  = absint( $user_id );
        $new_cuid = absint( $new_cuid );

        if ( $user_id <= 0 || $new_cuid <= 0 ) {
            return array( 'success' => false, 'message' => __( '无效的用户或号码', 'qibbs-community' ) );
        }

        global $wpdb;
        $members_table = Qibbs_Database::members();
        $vanity_table  = Qibbs_Database::vanity_uids();

        // 检查是否有其他用户占用了该号码
        $conflict_user = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM {$members_table} WHERE (default_cuid = %d OR active_cuid = %d) AND user_id != %d LIMIT 1",
                $new_cuid,
                $new_cuid,
                $user_id
            )
        );
        if ( $conflict_user > 0 ) {
            $other_user = get_userdata( $conflict_user );
            $other_name = $other_user ? $other_user->display_name : 'ID ' . $conflict_user;
            return array(
                'success' => false,
                'message' => sprintf( __( '号码 %d 已被用户 %s 占用，无法指派！', 'qibbs-community' ), $new_cuid, $other_name ),
            );
        }

        // 检查会员记录是否存在
        $existing = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$members_table} WHERE user_id = %d LIMIT 1", $user_id )
        );

        $now = current_time( 'mysql' );
        if ( ! $existing ) {
            $wpdb->insert(
                $members_table,
                array(
                    'user_id'      => $user_id,
                    'default_cuid' => $new_cuid,
                    'active_cuid'  => $new_cuid,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ),
                array( '%d', '%d', '%d', '%s', '%s' )
            );
        } else {
            $update_data = array(
                'default_cuid' => $new_cuid,
                'updated_at'   => $now,
            );
            $update_format = array( '%d', '%s' );
            if ( $is_active_also || (int) $existing->active_cuid === (int) $existing->default_cuid ) {
                $update_data['active_cuid'] = $new_cuid;
                $update_format[] = '%d';
            }

            $wpdb->update(
                $members_table,
                $update_data,
                array( 'user_id' => $user_id ),
                $update_format,
                array( '%d' )
            );
        }

        // 清理缓存
        $this->invalidate_user_cache( $user_id, $existing ? (int) $existing->default_cuid : 0 );
        $this->cache_mapping( $user_id, $new_cuid );

        return array(
            'success' => true,
            'message' => sprintf( __( '已成功将用户 (ID: %d) 的社区 UID 修改为：%d', 'qibbs-community' ), $user_id, $new_cuid ),
        );
    }

    /**
     * 一键按用户注册先后顺序重新对齐整顿所有用户的社区初始 UID
     *
     * @param int $override_start_cuid Optional explicit start cuid.
     * @return array{success:bool, message:string, count:int}
     */
    public function resequence_all_members_cuid( $override_start_cuid = 0 ) {
        global $wpdb;
        $members_table = Qibbs_Database::members();
        $users_table   = $wpdb->users;

        $rule_service = Qibbs_UID_Rule_Service::instance();
        $settings     = $rule_service->get_settings( true );
        $start_cuid   = $override_start_cuid > 0 ? (int) $override_start_cuid : (int) $settings['free_start'];

        // 获取按注册时间升序排列的所有用户
        $users = $wpdb->get_results(
            "SELECT u.ID FROM {$users_table} u ORDER BY u.user_registered ASC, u.ID ASC"
        );

        if ( empty( $users ) ) {
            return array( 'success' => false, 'message' => __( '没有找到任何用户', 'qibbs-community' ), 'count' => 0 );
        }

        $now = current_time( 'mysql' );
        $current_cuid = $start_cuid;
        $count = 0;

        foreach ( $users as $u ) {
            $uid = (int) $u->ID;
            
            // 探查下一个合规的普通号（跳过指定的保留号和靓号）
            while ( $rule_service->is_reserved( $current_cuid ) || $rule_service->is_vanity( $current_cuid ) ) {
                $current_cuid++;
            }

            $assigned = $current_cuid;

            $existing = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$members_table} WHERE user_id = %d LIMIT 1", $uid )
            );

            if ( $existing ) {
                $is_wearing_vanity = ( (int) $existing->active_cuid > 0 && (int) $existing->active_cuid !== (int) $existing->default_cuid );
                $update_data = array(
                    'default_cuid' => $assigned,
                    'updated_at'   => $now,
                );
                $update_format = array( '%d', '%s' );
                if ( ! $is_wearing_vanity ) {
                    $update_data['active_cuid'] = $assigned;
                    $update_format[] = '%d';
                }

                $wpdb->update(
                    $members_table,
                    $update_data,
                    array( 'user_id' => $uid ),
                    $update_format,
                    array( '%d' )
                );
            } else {
                $wpdb->insert(
                    $members_table,
                    array(
                        'user_id'      => $uid,
                        'default_cuid' => $assigned,
                        'active_cuid'  => $assigned,
                        'balance'      => 0.00,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ),
                    array( '%d', '%d', '%d', '%f', '%s', '%s' )
                );
            }

            $this->invalidate_user_cache( $uid, $existing ? (int) $existing->default_cuid : 0 );
            $this->cache_mapping( $uid, $assigned );

            $count++;
            $current_cuid++;
        }

        // 更新游标为最后分配的值
        update_option( 'qibbs_uid_free_cursor', $current_cuid - 1 );

        return array(
            'success' => true,
            'message' => sprintf( __( '已成功将全站 %d 名用户的社区初始 UID 按照注册顺序重新对齐！当前游标为：%d', 'qibbs-community' ), $count, $current_cuid - 1 ),
            'count'   => $count,
        );
    }
}

