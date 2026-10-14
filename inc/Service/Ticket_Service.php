<?php
/**
 * 工单核心服务类
 *
 * 负责工单创建、沟通回复、SLA 履约流转及一键转公开贴
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Ticket_Service {

    /**
     * WP-Cron 异步扫描工单 SLA 履约监控钩子
     *
     * @var string
     */
    const SLA_SCAN_CRON_HOOK = 'qibbs_ticket_sla_scan_event';

    /**
     * 工单状态枚举常量
     */
    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_PENDING_CUSTOMER = 'pending_customer';
    const STATUS_REOPENED   = 'reopened';
    const STATUS_RESOLVED   = 'resolved';
    const STATUS_CLOSED     = 'closed';

    /**
     * 工单优先级枚举常量
     */
    const PRIORITY_LOW    = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH   = 'high';
    const PRIORITY_URGENT = 'urgent';

    /**
     * 最后一次工单创建错误信息
     *
     * @var WP_Error|null
     */
    private $last_error = null;

    /**
     * 工单创建流程最近获取的 MySQL 分布式锁名称
     *
     * @var string
     */
    private $active_create_lock_name = '';

    /**
     * 请求级工单后台指标统计缓存
     *
     * @var array<string,mixed>
     */
    private static $admin_metrics_runtime_cache = array();

    /**
     * 获取最近一次工单创建失败的原因
     *
     * @return WP_Error|null
     */
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * 记录工单创建异常并返回 false
     *
     * @param string $code    Error code.
     * @param string $message Error message.
     * @return false
     */
    private function fail_create( $code, $message ) {
        $this->last_error = new WP_Error( $code, $message );
        return false;
    }

    /**
     * 根据防刷策略校验用户是否具备工单提交资质
     *
     * @param int $user_id User ID.
     * @return bool
     */
    private function can_create_ticket( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return $this->fail_create( 'ticket_invalid_user', __( '请先登录后再提交工单。', 'qibbs-community' ) );
        }

        // 允许具备管理权限的用户跳过工单频率限制
        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        if ( qibbs_get_option( 'ticket_block_if_open', false ) && $this->get_open_ticket_count( $user_id ) > 0 ) {
            return $this->fail_create( 'ticket_open_exists', __( '你有未关闭工单，请先等待处理后再提交新的工单。', 'qibbs-community' ) );
        }

        return true;
    }

    /**
     * 提交工单前消耗频率配额
     *
     * @param int $user_id User ID.
     * @return bool
     */
    private function consume_ticket_submission_rate_limit( $user_id ) {
        $user_id = absint( $user_id );
        if ( ! $user_id || user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        $minute_limit = absint( qibbs_get_option( 'ticket_rate_limit_minute', 1 ) );
        if ( $minute_limit > 0 && function_exists( 'qibbs_consume_scoped_rate_limit' ) ) {
            if ( ! qibbs_consume_scoped_rate_limit( 'ticket_submit_minute', $user_id, $minute_limit, MINUTE_IN_SECONDS ) ) {
                return $this->fail_create( 'ticket_rate_minute', __( '提交过于频繁，请稍后再试。', 'qibbs-community' ) );
            }
        }

        $hour_limit = absint( qibbs_get_option( 'ticket_rate_limit', 3 ) );
        if ( $hour_limit > 0 && function_exists( 'qibbs_consume_scoped_rate_limit' ) ) {
            if ( ! qibbs_consume_scoped_rate_limit( 'ticket_submit_hour', $user_id, $hour_limit, HOUR_IN_SECONDS ) ) {
                return $this->fail_create( 'ticket_rate_hour', __( '你提交工单过于频繁，请稍后再试。', 'qibbs-community' ) );
            }
        }

        return true;
    }

    /**
     * 构建单用户工单提交并发锁键名
     *
     * @param int $user_id User ID.
     * @return string
     */
    private function get_ticket_create_lock_name( $user_id ) {
        return substr( 'qibbs_ticket_create_' . absint( $user_id ), 0, 64 );
    }

    /**
     * 获取用户工单提交锁以防止并发重复提交
     *
     * @param int $user_id User ID.
     * @param int $timeout Lock wait timeout in seconds.
     * @return bool
     */
    private function acquire_ticket_create_lock( $user_id, $timeout = 2 ) {
        global $wpdb;
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return false;
        }

        $lock_name = $this->get_ticket_create_lock_name( $user_id );
        $timeout = max( 0, absint( $timeout ) );
        $result = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT GET_LOCK(%s, %d)',
                $lock_name,
                $timeout
            )
        );

        if ( 1 === (int) $result ) {
            $this->active_create_lock_name = $lock_name;
            return true;
        }

        // 数据库环境不支持建议锁时的降级方案
        if ( null === $result ) {
            $this->active_create_lock_name = '';
            return true;
        }

        return false;
    }

    /**
     * 释放工单提交事务锁
     *
     * @return void
     */
    private function release_ticket_create_lock() {
        global $wpdb;
        if ( '' === $this->active_create_lock_name ) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                'SELECT RELEASE_LOCK(%s)',
                $this->active_create_lock_name
            )
        );
        $this->active_create_lock_name = '';
    }

    /**
     * 获取工单指标缓存版本号
     *
     * @return int
     */
    private static function get_admin_metrics_cache_version() {
        $version = (int) get_option( 'qibbs_ticket_admin_metrics_cache_version', 1 );
        return max( 1, $version );
    }

    /**
     * 使用户维度的工单指标缓存失效
     *
     * @return void
     */
    public static function bump_admin_metrics_cache_version() {
        update_option(
            'qibbs_ticket_admin_metrics_cache_version',
            self::get_admin_metrics_cache_version() + 1,
            false
        );

        self::$admin_metrics_runtime_cache = array();
    }

    /**
     * 构建带版本控制的指标统计缓存键名
     *
     * @param string $prefix  Cache namespace.
     * @param array  $payload Optional payload for scoped keys.
     * @return string
     */
    private function build_admin_metrics_cache_key( $prefix, $payload = array() ) {
        $payload_hash = empty( $payload ) ? 'base' : md5( wp_json_encode( $payload ) );

        return 'qibbs_ticket_' . sanitize_key( $prefix ) . '_' . self::get_admin_metrics_cache_version() . '_' . $payload_hash;
    }

    /**
     * 从运行时缓存或 Transient 中读取工单统计数据
     *
     * @param string $key Cache key.
     * @return mixed|null
     */
    private function get_cached_admin_payload( $key ) {
        if ( array_key_exists( $key, self::$admin_metrics_runtime_cache ) ) {
            return self::$admin_metrics_runtime_cache[ $key ];
        }

        $cached = get_transient( $key );
        if ( false === $cached ) {
            return null;
        }

        self::$admin_metrics_runtime_cache[ $key ] = $cached;
        return $cached;
    }

    /**
     * 写入工单后台统计缓存数据
     *
     * @param string $key   Cache key.
     * @param mixed  $value Cached value.
     * @param int    $ttl   Cache TTL in seconds.
     * @return mixed
     */
    private function set_cached_admin_payload( $key, $value, $ttl ) {
        $ttl = max( 30, absint( $ttl ) );

        self::$admin_metrics_runtime_cache[ $key ] = $value;
        set_transient( $key, $value, $ttl );

        return $value;
    }

    /**
     * 后台工单列表默认采用轻量级总数机制
     *
     * @param int $page Current page number.
     * @return bool
     */
    private function should_use_lightweight_total( $page ) {
        return true;
    }

    /**
     * 剔除 limit+1 分页探针溢出记录
     *
     * @param array $rows     Result rows.
     * @param int   $per_page Items per page.
     * @return array{rows:array,has_more:bool}
     */
    private function trim_overflow_results( array $rows, $per_page ) {
        $per_page = max( 1, absint( $per_page ) );
        $has_more = count( $rows ) > $per_page;

        if ( $has_more ) {
            $rows = array_slice( $rows, 0, $per_page );
        }

        return array(
            'rows'     => $rows,
            'has_more' => $has_more,
        );
    }

    /**
     * 构建轻量分页估算总数
     *
     * @param int  $page     Current page.
     * @param int  $per_page Items per page.
     * @param int  $shown    Number of displayed rows on the current page.
     * @param bool $has_more Whether another page exists.
     * @return int
     */
    private function build_lightweight_total( $page, $per_page, $shown, $has_more ) {
        $page = max( 1, absint( $page ) );
        $per_page = max( 1, absint( $per_page ) );
        $shown = max( 0, absint( $shown ) );
        $base_total = ( ( $page - 1 ) * $per_page ) + $shown;

        if ( $has_more ) {
            $base_total = max( $base_total, ( $page * $per_page ) + 1 );
        }

        return $base_total;
    }

    /**
     * 统计用户当前未结单的工单总数
     *
     * @param int $user_id User ID.
     * @return int
     */
    private function get_open_ticket_count( $user_id ) {
        global $wpdb;
        $table = Qibbs_Database::tickets();
        $user_id = absint( $user_id );
        if ( ! $user_id ) {
            return 0;
        }

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status IN ( %s, %s, %s, %s )",
                $user_id,
                self::STATUS_PENDING,
                self::STATUS_PROCESSING,
                self::STATUS_PENDING_CUSTOMER,
                self::STATUS_REOPENED
            )
        );

        return (int) $count;
    }

    /**
     * 创建新工单记录
     *
     * @param int    $user_id       User ID.
     * @param int    $category_id   Category ID.
     * @param string $subject       Ticket subject.
     * @param string $content       Ticket content.
     * @param string $priority      Priority level.
     * @param bool   $allow_publish Whether user allows publishing.
     * @param string $attachments   Comma-separated media IDs.
     * @return int|false Ticket ID or false on failure.
     */
    public function create_ticket( $user_id, $category_id, $subject, $content, $priority = 'normal', $allow_publish = false, $attachments = '' ) {
        global $wpdb;
        $table = Qibbs_Database::tickets();
        $this->last_error = null;

        $user_id = absint( $user_id );
        $subject = sanitize_text_field( $subject );
        $content = wp_kses_post( $content );
        $attachments = sanitize_text_field( $attachments );

        if ( ! $user_id || empty( $subject ) || empty( $content ) ) {
            return $this->fail_create( 'ticket_required_fields', __( '请填写完整的工单信息。', 'qibbs-community' ) );
        }

        if ( class_exists( 'Qibbs_Risk_Service' ) ) {
            $risk_check = Qibbs_Risk_Service::evaluate_content_submission(
                $user_id,
                'ticket',
                $subject . "\n" . wp_strip_all_tags( $content ),
                array(
                    'category_id' => absint( $category_id ),
                    'priority'    => $priority,
                )
            );
            if ( is_wp_error( $risk_check ) ) {
                return $this->fail_create( $risk_check->get_error_code(), $risk_check->get_error_message() );
            }
        }

        $validated_attachments = qibbs_validate_media_ids_for_user(
            $user_id,
            $attachments,
            array( 'strict' => true )
        );
        if ( is_wp_error( $validated_attachments ) ) {
            return $this->fail_create( 'ticket_invalid_attachments', $validated_attachments->get_error_message() );
        }
        $attachments = qibbs_media_ids_to_csv( $validated_attachments );

        if ( ! $this->acquire_ticket_create_lock( $user_id, 2 ) ) {
            return $this->fail_create( 'ticket_busy', __( '提交请求过于集中，请稍后重试。', 'qibbs-community' ) );
        }

        try {
            if ( ! $this->can_create_ticket( $user_id ) ) {
                return false;
            }

            if ( ! $this->consume_ticket_submission_rate_limit( $user_id ) ) {
                return false;
            }

            $sla_hours = $this->get_category_sla_hours( $category_id );
            $now_ts = qibbs_current_timestamp();
            $first_response_deadline = qibbs_format_datetime( 'Y-m-d H:i:s', $now_ts + (int) $sla_hours['first_response'] * HOUR_IN_SECONDS );
            $resolution_deadline = qibbs_format_datetime( 'Y-m-d H:i:s', $now_ts + (int) $sla_hours['resolution'] * HOUR_IN_SECONDS );

            $result = $wpdb->insert(
                $table,
                array(
                    'user_id'       => absint( $user_id ),
                    'category_id'   => absint( $category_id ),
                    'subject'       => $subject,
                    'content'       => $content,
                    'status'        => self::STATUS_PENDING,
                    'priority'      => $this->validate_priority( $priority ),
                    'allow_publish' => $allow_publish ? 1 : 0,
                    'attachments'   => $attachments,
                    'first_response_deadline' => $first_response_deadline,
                    'resolution_deadline' => $resolution_deadline,
                    'created_at'    => current_time( 'mysql' ),
                    'updated_at'    => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
            );

            if ( $result ) {
                $ticket_id = $wpdb->insert_id;
                self::bump_admin_metrics_cache_version();

                /**
                 * 工单创建成功后触发动作钩子
                 *
                 * @param int $ticket_id The ticket ID.
                 * @param int $user_id   The user ID.
                 */
                do_action( 'qibbs_ticket_created', $ticket_id, $user_id );

                return $ticket_id;
            }

            return $this->fail_create( 'ticket_create_failed', __( '提交失败，请稍后重试。', 'qibbs-community' ) );
        } finally {
            $this->release_ticket_create_lock();
        }
    }

    /**
     * 获取指定工单分类配置的 SLA 履约保障时限（小时）
     *
     * @param int $category_id Category ID.
     * @return array{first_response:int,resolution:int}
     */
    private function get_category_sla_hours( $category_id ) {
        $defaults = array(
            'first_response' => 4,
            'resolution'     => 48,
        );

        $category_id = absint( $category_id );
        if ( ! $category_id ) {
            return $defaults;
        }

        $category_service = new Qibbs_Ticket_Category_Service();
        $category = $category_service->get( $category_id );
        if ( ! $category ) {
            return $defaults;
        }

        $first_response = isset( $category->sla_first_response_hours ) ? absint( $category->sla_first_response_hours ) : $defaults['first_response'];
        $resolution = isset( $category->sla_resolution_hours ) ? absint( $category->sla_resolution_hours ) : $defaults['resolution'];

        return array(
            'first_response' => max( 1, min( 720, $first_response ) ),
            'resolution'     => max( 1, min( 2160, $resolution ) ),
        );
    }

    /**
     * 查询单条工单详情数据
     *
     * @param int $ticket_id Ticket ID.
     * @return object|null
     */
    public function get_ticket( $ticket_id ) {
        global $wpdb;
        $table = Qibbs_Database::tickets();

        $ticket = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $ticket_id
        ) );

        if ( $ticket ) {
            $ticket->replies = $this->get_replies( $ticket_id );
            $ticket->author = get_userdata( $ticket->user_id );
            
            // 装配工单分类信息
            if ( $ticket->category_id ) {
                $category_service = new Qibbs_Ticket_Category_Service();
                $ticket->category = $category_service->get( $ticket->category_id );
            }
        }

        return $ticket;
    }

    /**
     * 查询指定用户的工单列表
     *
     * @param int   $user_id User ID.
     * @param array $args    Query arguments.
     * @return array
     */
    public function get_user_tickets( $user_id, $args = array() ) {
        global $wpdb;
        $table = Qibbs_Database::tickets();

        $defaults = array(
            'status'   => '',
            'per_page' => 20,
            'page'     => 1,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        );
        $args = wp_parse_args( $args, $defaults );

        $where = array( 'user_id = %d' );
        $values = array( $user_id );

        if ( ! empty( $args['status'] ) ) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        $where_clause = implode( ' AND ', $where );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $orderby = sanitize_sql_orderby( $args['orderby'] ) ?: 'created_at';
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $values[] = $args['per_page'];
        $values[] = $offset;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
            $values
        ) );
    }

    /**
     * 查询全量工单列表（后台管理端）
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_tickets( $args = array() ) {
        global $wpdb;
        $table = Qibbs_Database::tickets();

        $defaults = array(
            'status'      => '',
            'category_id' => 0,
            'priority'    => '',
            'assignee_id' => '',
            'per_page'    => 20,
            'page'        => 1,
            'orderby'     => 'created_at',
            'order'       => 'DESC',
            'search'      => '',
        );
        $args = wp_parse_args( $args, $defaults );
        $page = max( 1, absint( $args['page'] ) );
        $per_page = max( 1, min( 100, absint( $args['per_page'] ) ) );

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['status'] ) ) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['category_id'] ) ) {
            $where[] = 'category_id = %d';
            $values[] = absint( $args['category_id'] );
        }

        if ( ! empty( $args['priority'] ) ) {
            $where[] = 'priority = %s';
            $values[] = $args['priority'];
        }

        if ( '' !== $args['assignee_id'] && null !== $args['assignee_id'] ) {
            $assignee_id = (int) $args['assignee_id'];
            if ( $assignee_id < 0 ) {
                $where[] = 'assignee_id = 0';
            } else {
                $where[] = 'assignee_id = %d';
                $values[] = absint( $assignee_id );
            }
        }

        if ( ! empty( $args['search'] ) ) {
            $where[] = '(subject LIKE %s OR content LIKE %s)';
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[] = $search_term;
            $values[] = $search_term;
        }

        $where_clause = implode( ' AND ', $where );
        $offset = ( $page - 1 ) * $per_page;
        $orderby = sanitize_sql_orderby( $args['orderby'] ) ?: 'created_at';
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $use_lightweight_total = $this->should_use_lightweight_total( $page );
        $total = 0;

        if ( ! $use_lightweight_total ) {
            if ( empty( $values ) ) {
                $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_clause} AND %d = %d", 1, 1 ) );
            } else {
                $total = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}",
                        $values
                    )
                );
            }
        }

        $values[] = $use_lightweight_total ? ( $per_page + 1 ) : $per_page;
        $values[] = $offset;

        $tickets = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                $values
            )
        );
        $has_more = false;

        if ( $use_lightweight_total ) {
            $trimmed = $this->trim_overflow_results( is_array( $tickets ) ? $tickets : array(), $per_page );
            $tickets = $trimmed['rows'];
            $has_more = $trimmed['has_more'];
            $total = $this->build_lightweight_total( $page, $per_page, count( $tickets ), $has_more );
        } else {
            $has_more = $total > ( $offset + count( $tickets ) );
        }

        return array(
            'tickets' => $tickets,
            'total'   => (int) $total,
            'pages'   => (int) ceil( $total / $per_page ),
            'has_more' => $has_more,
        );
    }

    /**
     * 查询工单所有沟通回复记录
     *
     * @param int  $ticket_id     Ticket ID.
     * @param bool $include_internal Include internal notes (admin only).
     * @return array
     */
    public function get_replies( $ticket_id, $include_internal = false ) {
        global $wpdb;
        $table = Qibbs_Database::ticket_replies();

        $where = 'ticket_id = %d';
        if ( ! $include_internal ) {
            $where .= ' AND is_internal = 0';
        }

        $replies = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at ASC",
            $ticket_id
        ) );

        // 装配回复者的头像与角色标识
        foreach ( $replies as $reply ) {
            $reply->author = get_userdata( $reply->user_id );
        }

        return $replies;
    }

    /**
     * Add a reply to a ticket
     *
     * @param int    $ticket_id   Ticket ID.
     * @param int    $user_id     User ID.
     * @param string $content     Reply content.
     * @param bool   $is_internal Whether this is an internal note.
     * @return int|false Reply ID or false on failure.
     */
    public function add_reply( $ticket_id, $user_id, $content, $is_internal = false ) {
        global $wpdb;
        $table = Qibbs_Database::ticket_replies();

        $ticket_id = absint( $ticket_id );
        $user_id = absint( $user_id );
        if ( ! $ticket_id || ! $user_id ) {
            return false;
        }

        $ticket = $this->get_ticket( $ticket_id );
        if ( ! $ticket ) {
            return false;
        }

        // 服务层安全守卫：调用方必须具备目标工单的访问权限
        if ( ! $this->can_access( $ticket_id, $user_id ) ) {
            return false;
        }

        $content = wp_kses_post( $content );
        if ( empty( $content ) ) {
            return false;
        }

        $is_admin = user_can( $user_id, 'manage_options' );
        // 内部备注严格限定仅管理员可见及发送
        $is_internal = ( $is_internal && $is_admin ) ? 1 : 0;

        $result = $wpdb->insert(
            $table,
            array(
                'ticket_id'      => $ticket_id,
                'user_id'        => $user_id,
                'content'        => $content,
                'is_admin_reply' => $is_admin ? 1 : 0,
                'is_internal'    => $is_internal,
                'created_at'     => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%d', '%d', '%s' )
        );

        if ( $result ) {
            $reply_id = $wpdb->insert_id;

            // 更新工单生命周期与最后沟通时间
            $ticket_updates = array(
                'updated_at' => current_time( 'mysql' ),
            );

            if ( $is_admin && ! $is_internal ) {
                if ( empty( $ticket->first_response_at ) ) {
                    $ticket_updates['first_response_at'] = current_time( 'mysql' );
                }
                if ( in_array( $ticket->status, array( self::STATUS_PENDING, self::STATUS_REOPENED ), true ) ) {
                    $ticket_updates['status'] = self::STATUS_PROCESSING;
                }
            } elseif ( ! $is_admin && in_array( $ticket->status, array( self::STATUS_PENDING_CUSTOMER, self::STATUS_RESOLVED ), true ) ) {
                $ticket_updates['status'] = self::STATUS_REOPENED;
            }

            $this->update_ticket( $ticket_id, $ticket_updates );

            /**
             * 工单回复添加成功后触发动作钩子
             *
             * @param int  $reply_id    The reply ID.
             * @param int  $ticket_id   The ticket ID.
             * @param int  $user_id     The user ID.
             * @param bool $is_internal Whether it's an internal note.
             */
            do_action( 'qibbs_ticket_reply_added', $reply_id, $ticket_id, $user_id, $is_internal );

            // 管理员回复非内部备注时，向用户投递站内通知
            if ( $is_admin && ! $is_internal ) {
                $this->send_reply_notification( $ticket_id, $reply_id );
            }

            return $reply_id;
        }

        return false;
    }

    /**
     * 流转更新工单状态
     *
     * @param int    $ticket_id Ticket ID.
     * @param string $status    New status.
     * @return bool
     */
    public function update_status( $ticket_id, $status ) {
        $status = $this->validate_status( $status );
        $ticket = $this->get_ticket( $ticket_id );
        if ( ! $ticket ) {
            return false;
        }

        $updates = array(
            'status' => $status,
        );

        if ( in_array( $status, array( self::STATUS_RESOLVED, self::STATUS_CLOSED ), true ) && empty( $ticket->resolved_at ) ) {
            $updates['resolved_at'] = current_time( 'mysql' );
        }

        $result = $this->update_ticket( $ticket_id, $updates );

        if ( $result ) {
            /**
             * 工单状态流转后触发动作钩子
             *
             * @param int    $ticket_id The ticket ID.
             * @param string $status    The new status.
             */
            do_action( 'qibbs_ticket_status_changed', $ticket_id, $status );

            // 工单被标记已解决时投递通知
            if ( $status === self::STATUS_RESOLVED ) {
                $this->send_resolved_notification( $ticket_id );
            }
        }

        return $result;
    }

    /**
     * 指派工单归属客服/管理员
     *
     * @param int $ticket_id   Ticket ID.
     * @param int $assignee_id Admin user ID.
     * @return bool
     */
    public function assign_ticket( $ticket_id, $assignee_id ) {
        $assignee_id = absint( $assignee_id );
        $update = array(
            'assignee_id' => $assignee_id,
        );
        if ( $assignee_id > 0 ) {
            $update['status'] = self::STATUS_PROCESSING;
        }
        return $this->update_ticket( $ticket_id, $update );
    }

    /**
     * 批量处理工单操作指令
     *
     * @param array  $ticket_ids Ticket IDs.
     * @param string $action Action key.
     * @param int    $assignee_id Assignee ID for assign action.
     * @param int    $actor_id Acting admin ID.
     * @return array
     */
    public function process_bulk_action( $ticket_ids, $action, $assignee_id = 0, $actor_id = 0 ) {
        $ticket_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ticket_ids ) ) ) );
        $action = sanitize_key( $action );
        $assignee_id = absint( $assignee_id );
        $actor_id = absint( $actor_id );

        if ( empty( $ticket_ids ) ) {
            return array(
                'success' => false,
                'message' => __( '请选择要处理的工单', 'qibbs-community' ),
            );
        }

        $status_actions = array(
            'set_pending'          => self::STATUS_PENDING,
            'set_processing'       => self::STATUS_PROCESSING,
            'set_pending_customer' => self::STATUS_PENDING_CUSTOMER,
            'set_resolved'         => self::STATUS_RESOLVED,
            'set_closed'           => self::STATUS_CLOSED,
        );

        if ( 'assign' === $action && $assignee_id <= 0 ) {
            return array(
                'success' => false,
                'message' => __( '批量分配时请选择负责人', 'qibbs-community' ),
            );
        }

        if ( 'assign' !== $action && 'unassign' !== $action && ! isset( $status_actions[ $action ] ) ) {
            return array(
                'success' => false,
                'message' => __( '不支持的批量动作', 'qibbs-community' ),
            );
        }

        global $wpdb;
        $table = Qibbs_Database::tickets();
        $placeholders = implode( ',', array_fill( 0, count( $ticket_ids ), '%d' ) );
        $tickets = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, subject FROM {$table} WHERE id IN ({$placeholders})",
                $ticket_ids
            )
        );

        if ( empty( $tickets ) ) {
            return array(
                'success' => false,
                'message' => __( '未找到可处理的工单', 'qibbs-community' ),
            );
        }

        $success_count = 0;
        foreach ( $tickets as $ticket ) {
            $ticket_id = (int) $ticket->id;
            $result = false;

            if ( 'assign' === $action ) {
                $result = $this->assign_ticket( $ticket_id, $assignee_id );
            } elseif ( 'unassign' === $action ) {
                $result = $this->assign_ticket( $ticket_id, 0 );
            } else {
                $result = $this->update_status( $ticket_id, $status_actions[ $action ] );
            }

            if ( $result ) {
                $success_count++;
                do_action( 'qibbs_ticket_bulk_processed', $ticket_id, $action, $actor_id );
            }
        }

        if ( $success_count <= 0 ) {
            return array(
                'success' => false,
                'message' => __( '没有工单处理成功，请重试', 'qibbs-community' ),
            );
        }

        return array(
            'success' => true,
            'count'   => $success_count,
            'message' => sprintf( __( '已批量处理 %d 条工单', 'qibbs-community' ), $success_count ),
        );
    }

    /**
     * 更新工单记录基础信息
     *
     * @param int   $ticket_id Ticket ID.
     * @param array $data      Data to update.
     * @return bool
     */
    public function update_ticket( $ticket_id, $data ) {
        global $wpdb;
        $table = Qibbs_Database::tickets();

        $allowed_fields = array(
            'category_id', 'subject', 'content', 'status', 'priority',
            'allow_publish', 'assignee_id', 'is_published', 'published_post_id',
            'published_forum_id', 'updated_at',
            'first_response_deadline', 'first_response_at',
            'resolution_deadline', 'resolved_at'
        );

        $update_data = array();
        $format = array();

        foreach ( $data as $key => $value ) {
            if ( in_array( $key, $allowed_fields, true ) ) {
                if ( in_array( $key, array( 'subject', 'status', 'priority' ), true ) ) {
                    $update_data[ $key ] = sanitize_text_field( $value );
                    $format[] = '%s';
                } elseif ( $key === 'content' ) {
                    $update_data[ $key ] = wp_kses_post( $value );
                    $format[] = '%s';
                } elseif ( in_array( $key, array( 'updated_at', 'first_response_deadline', 'first_response_at', 'resolution_deadline', 'resolved_at' ), true ) ) {
                    $update_data[ $key ] = $value;
                    $format[] = '%s';
                } else {
                    $update_data[ $key ] = absint( $value );
                    $format[] = '%d';
                }
            }
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        // 始终更新修改时间戳
        if ( ! isset( $update_data['updated_at'] ) ) {
            $update_data['updated_at'] = current_time( 'mysql' );
            $format[] = '%s';
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            array( 'id' => $ticket_id ),
            $format,
            array( '%d' )
        );

        if ( $result > 0 ) {
            self::bump_admin_metrics_cache_version();
        }

        return $result !== false;
    }

    /**
     * 将工单脱敏并发布为社区公开帖子
     *
     * @param int   $ticket_id Ticket ID.
     * @param int   $forum_id  Target forum ID.
     * @param array $options   Publishing options.
     * @return int|WP_Error Post ID or error.
     */
    public function publish_as_post( $ticket_id, $forum_id, $options = array() ) {
        global $wpdb;

        $ticket = $this->get_ticket( $ticket_id );

        if ( ! $ticket ) {
            return new WP_Error( 'not_found', __( '工单不存在', 'qibbs-community' ) );
        }

        if ( $ticket->is_published ) {
            return new WP_Error( 'already_published', __( '该工单已经发布过', 'qibbs-community' ) );
        }

        $defaults = array(
            'title'               => $ticket->subject,
            'content'             => $ticket->content,
            'include_replies'     => array(), // 选定要随同公开的回复 ID 集合
            'include_attachments' => array(), // 选定要公开展示的附件 ID 集合
        );
        $options = wp_parse_args( $options, $defaults );

        // 整合拼装帖子正文内容
        $post_content = wp_kses_post( $options['content'] );

        // 追加选定的沟通回复记录（排除内部备注）
        $replies = $this->get_replies( $ticket_id, false ); // 仅拉取公开回复（剔除内部备注）
        
        if ( ! empty( $replies ) ) {
            // 未指定时默认包含全部公开回复
            $replies_to_include = $options['include_replies'];
            if ( empty( $replies_to_include ) ) {
                $replies_to_include = array_map( function( $r ) { return $r->id; }, $replies );
            }
            
            foreach ( $replies as $reply ) {
                if ( in_array( $reply->id, $replies_to_include, true ) ) {
                    $author_name = $reply->author ? $reply->author->display_name : __( '管理员', 'qibbs-community' );
                    $is_admin = $reply->is_admin_reply ? '【客服】' : '';
                    $post_content .= "\n\n---\n\n";
                    $post_content .= "**" . $is_admin . esc_html( $author_name ) . "**：\n\n";
                    $post_content .= $reply->content;
                }
            }
        }

        // 提取选定附件生成 media_ids 关联字符串
        $ticket_attachment_ids = qibbs_validate_media_ids_for_user(
            (int) $ticket->user_id,
            isset( $ticket->attachments ) ? $ticket->attachments : '',
            array(
                'strict'      => false,
                'allow_admin' => false,
            )
        );
        $ticket_attachment_ids = is_array( $ticket_attachment_ids ) ? $ticket_attachment_ids : array();

        $selected_attachment_ids = qibbs_parse_id_list( $options['include_attachments'] );
        $selected_attachment_ids = array_values( array_intersect( $selected_attachment_ids, $ticket_attachment_ids ) );
        $media_ids = qibbs_media_ids_to_csv( $selected_attachment_ids );

        $discussion_status_enabled = function_exists( 'qibbs_is_discussion_status_enabled_for_forum' )
            ? qibbs_is_discussion_status_enabled_for_forum( (int) $forum_id )
            : false;
        $post_discussion_status = $discussion_status_enabled ? 'resolved' : 'discussion';
        $legacy_is_resolved = $discussion_status_enabled ? 1 : 0;

        // 向微社区数据表写入公开帖子
        $posts_table = Qibbs_Database::posts();
        $result = $wpdb->insert(
            $posts_table,
            array(
                'author_id'          => $ticket->user_id,
                'content'            => $post_content,
                'status'             => 'publish',
                'discussion_status'  => $post_discussion_status,
                'forum_id'           => absint( $forum_id ),
                'media_ids'          => $media_ids,
                'source_type'        => 'ticket',
                'source_id'          => absint( $ticket_id ),
                'is_resolved'        => $legacy_is_resolved, // 兼容历史数据结构
                'created_at'         => current_time( 'mysql' ),
                'updated_at'         => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
        );

        if ( ! $result ) {
            return new WP_Error( 'insert_failed', __( '帖子创建失败', 'qibbs-community' ) );
        }

        $post_id = $wpdb->insert_id;

        // 更新工单为已公开状态并记录对应帖子 ID
        $this->update_ticket( $ticket_id, array(
            'is_published'      => 1,
            'published_post_id' => $post_id,
            'published_forum_id' => $forum_id,
            'status'            => self::STATUS_CLOSED,
        ) );

        // 同步更新目标版块发帖数
        $forums_table = Qibbs_Database::forums();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$forums_table} SET post_count = post_count + 1 WHERE id = %d",
            $forum_id
        ) );

        /**
         * 工单发布为公开帖后触发动作钩子
         *
         * @param int $ticket_id The ticket ID.
         * @param int $post_id   The new post ID.
         * @param int $forum_id  The target forum ID.
         */
        do_action( 'qibbs_ticket_published', $ticket_id, $post_id, $forum_id );

        // 向工单提交人投递已公开通知
        $this->send_published_notification( $ticket_id, $post_id );

        return $post_id;
    }

    /**
     * 获取待处理工单总数
     *
     * @return int
     */
    public function get_pending_count() {
        global $wpdb;
        $table = Qibbs_Database::tickets();
        $cache_key = $this->build_admin_metrics_cache_key( 'pending_count' );
        $cached = $this->get_cached_admin_payload( $cache_key );

        if ( null !== $cached ) {
            return (int) $cached;
        }

        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status IN ('" . self::STATUS_PENDING . "','" . self::STATUS_REOPENED . "')"
        );

        return (int) $this->set_cached_admin_payload( $cache_key, $count, MINUTE_IN_SECONDS );
    }

    /**
     * 查询控制台仪表盘工单汇总概览指标
     *
     * @return array<string,int>
     */
    public function get_summary_metrics() {
        global $wpdb;
        $table = Qibbs_Database::tickets();
        $now = current_time( 'mysql' );
        $cache_key = $this->build_admin_metrics_cache_key( 'summary_metrics' );
        $cached = $this->get_cached_admin_payload( $cache_key );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $metrics = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS total_count,
                    COUNT(CASE WHEN status = %s THEN 1 END) AS pending_count,
                    COUNT(CASE WHEN status = %s THEN 1 END) AS processing_count,
                    COUNT(CASE WHEN status = %s THEN 1 END) AS pending_customer_count,
                    COUNT(CASE WHEN status = %s THEN 1 END) AS reopened_count,
                    COUNT(CASE WHEN status = %s THEN 1 END) AS resolved_count,
                    COUNT(CASE WHEN status = %s THEN 1 END) AS closed_count,
                    COUNT(
                        CASE WHEN
                            (
                                first_response_deadline IS NOT NULL
                                AND (
                                    ( first_response_at IS NULL AND first_response_deadline < %s )
                                    OR
                                    ( first_response_at IS NOT NULL AND first_response_at > first_response_deadline )
                                )
                            )
                            OR
                            (
                                resolution_deadline IS NOT NULL
                                AND status IN ( %s, %s, %s, %s )
                                AND resolution_deadline < %s
                            )
                        THEN 1 END
                    ) AS sla_breach_count
                FROM {$table}",
                self::STATUS_PENDING,
                self::STATUS_PROCESSING,
                self::STATUS_PENDING_CUSTOMER,
                self::STATUS_REOPENED,
                self::STATUS_RESOLVED,
                self::STATUS_CLOSED,
                $now,
                self::STATUS_PENDING,
                self::STATUS_PROCESSING,
                self::STATUS_PENDING_CUSTOMER,
                self::STATUS_REOPENED,
                $now
            ),
            ARRAY_A
        );

        $metrics = is_array( $metrics ) ? $metrics : array();

        $summary = array(
            'total_count'            => isset( $metrics['total_count'] ) ? (int) $metrics['total_count'] : 0,
            'pending_count'          => isset( $metrics['pending_count'] ) ? (int) $metrics['pending_count'] : 0,
            'processing_count'       => isset( $metrics['processing_count'] ) ? (int) $metrics['processing_count'] : 0,
            'pending_customer_count' => isset( $metrics['pending_customer_count'] ) ? (int) $metrics['pending_customer_count'] : 0,
            'reopened_count'         => isset( $metrics['reopened_count'] ) ? (int) $metrics['reopened_count'] : 0,
            'resolved_count'         => isset( $metrics['resolved_count'] ) ? (int) $metrics['resolved_count'] : 0,
            'closed_count'           => isset( $metrics['closed_count'] ) ? (int) $metrics['closed_count'] : 0,
            'sla_breach_count'       => isset( $metrics['sla_breach_count'] ) ? (int) $metrics['sla_breach_count'] : 0,
            'open_count'             => ( isset( $metrics['pending_count'] ) ? (int) $metrics['pending_count'] : 0 )
                                    + ( isset( $metrics['processing_count'] ) ? (int) $metrics['processing_count'] : 0 )
                                    + ( isset( $metrics['pending_customer_count'] ) ? (int) $metrics['pending_customer_count'] : 0 )
                                    + ( isset( $metrics['reopened_count'] ) ? (int) $metrics['reopened_count'] : 0 ),
        );

        return $this->set_cached_admin_payload( $cache_key, $summary, MINUTE_IN_SECONDS );
    }

    /**
     * 获取 SLA 履约概览数据与超时预警监控清单
     *
     * @param int $watchlist_limit Watchlist size.
     * @return array
     */
    public function get_sla_dashboard( $watchlist_limit = 10 ) {
        global $wpdb;
        $table = Qibbs_Database::tickets();
        $watchlist_limit = max( 3, min( 50, absint( $watchlist_limit ) ) );
        $due_window = max( 900, (int) apply_filters( 'qibbs_ticket_sla_dashboard_due_window', 4 * HOUR_IN_SECONDS ) );
        $open_statuses = $this->get_open_statuses();
        $now_ts = qibbs_current_timestamp();
        $cache_key = $this->build_admin_metrics_cache_key(
            'sla_dashboard',
            array(
                'watchlist_limit' => $watchlist_limit,
                'due_window'      => $due_window,
            )
        );
        $cached = $this->get_cached_admin_payload( $cache_key );

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $tickets = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_id, assignee_id, subject, status, priority, created_at, first_response_deadline, first_response_at, resolution_deadline, resolved_at
                 FROM {$table}
                 WHERE status IN ( %s, %s, %s, %s )
                 ORDER BY created_at ASC
                 LIMIT %d",
                $open_statuses[0],
                $open_statuses[1],
                $open_statuses[2],
                $open_statuses[3],
                2000
            )
        );

        $dashboard = array(
            'first_response_due_soon_count' => 0,
            'first_response_overdue_count'  => 0,
            'resolution_due_soon_count'     => 0,
            'resolution_overdue_count'      => 0,
            'unassigned_open_count'         => 0,
            'avg_first_response_minutes'    => 0,
            'avg_resolution_minutes'        => 0,
            'watchlist'                     => array(),
        );

        foreach ( (array) $tickets as $ticket ) {
            $is_open = in_array( (string) $ticket->status, $open_statuses, true );
            if ( $is_open && empty( $ticket->assignee_id ) ) {
                $dashboard['unassigned_open_count']++;
                $dashboard['watchlist'][] = $this->build_watchlist_item( $ticket, 'unassigned', 0 );
            }

            $first_response_deadline_ts = ! empty( $ticket->first_response_deadline ) ? strtotime( (string) $ticket->first_response_deadline ) : 0;
            $first_response_at_ts = ! empty( $ticket->first_response_at ) ? strtotime( (string) $ticket->first_response_at ) : 0;
            if ( $first_response_deadline_ts > 0 ) {
                if ( $first_response_at_ts <= 0 && $first_response_deadline_ts > $now_ts && ( $first_response_deadline_ts - $now_ts ) <= $due_window ) {
                    $dashboard['first_response_due_soon_count']++;
                    $dashboard['watchlist'][] = $this->build_watchlist_item( $ticket, 'first_response_due_soon', $first_response_deadline_ts );
                } elseif (
                    ( $first_response_at_ts <= 0 && $first_response_deadline_ts <= $now_ts )
                    || ( $first_response_at_ts > 0 && $first_response_at_ts > $first_response_deadline_ts )
                ) {
                    $dashboard['first_response_overdue_count']++;
                    $dashboard['watchlist'][] = $this->build_watchlist_item( $ticket, 'first_response_overdue', $first_response_deadline_ts );
                }
            }

            $resolution_deadline_ts = ! empty( $ticket->resolution_deadline ) ? strtotime( (string) $ticket->resolution_deadline ) : 0;
            if ( $resolution_deadline_ts > 0 ) {
                if ( $resolution_deadline_ts > $now_ts && ( $resolution_deadline_ts - $now_ts ) <= $due_window ) {
                    $dashboard['resolution_due_soon_count']++;
                    $dashboard['watchlist'][] = $this->build_watchlist_item( $ticket, 'resolution_due_soon', $resolution_deadline_ts );
                } elseif ( $resolution_deadline_ts <= $now_ts ) {
                    $dashboard['resolution_overdue_count']++;
                    $dashboard['watchlist'][] = $this->build_watchlist_item( $ticket, 'resolution_overdue', $resolution_deadline_ts );
                }
            }
        }

        $dashboard['watchlist'] = $this->sort_watchlist_items( $dashboard['watchlist'] );
        if ( count( $dashboard['watchlist'] ) > $watchlist_limit ) {
            $dashboard['watchlist'] = array_slice( $dashboard['watchlist'], 0, $watchlist_limit );
        }

        $averages = $wpdb->get_row(
            "SELECT
                AVG(CASE WHEN first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, first_response_at) END) AS avg_first_response_minutes,
                AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) END) AS avg_resolution_minutes
             FROM {$table}",
            ARRAY_A
        );

        if ( is_array( $averages ) ) {
            $dashboard['avg_first_response_minutes'] = isset( $averages['avg_first_response_minutes'] ) ? (int) round( (float) $averages['avg_first_response_minutes'] ) : 0;
            $dashboard['avg_resolution_minutes'] = isset( $averages['avg_resolution_minutes'] ) ? (int) round( (float) $averages['avg_resolution_minutes'] ) : 0;
        }

        return $this->set_cached_admin_payload( $cache_key, $dashboard, 2 * MINUTE_IN_SECONDS );
    }

    /**
     * 机会式触发工单 SLA 超时履约提醒扫描
     *
     * 利用短暂瞬态锁确保每个周期仅调度一次异步扫描
     *
     * @return void
     */
    public function maybe_dispatch_sla_notifications() {
        if ( ! qibbs_get_option( 'enable_notifications', true ) ) {
            return;
        }

        $scan_interval = max( 300, (int) apply_filters( 'qibbs_ticket_sla_scan_interval', 15 * MINUTE_IN_SECONDS ) );
        $scan_bucket = (int) floor( time() / $scan_interval );
        $scan_lock_key = 'qibbs_ticket_sla_scan_' . $scan_bucket;
        if ( get_transient( $scan_lock_key ) ) {
            return;
        }
        set_transient( $scan_lock_key, '1', $scan_interval );

        if ( ! wp_next_scheduled( self::SLA_SCAN_CRON_HOOK ) ) {
            wp_schedule_single_event( time() + 5, self::SLA_SCAN_CRON_HOOK );
        }
    }

    /**
     * 执行异步 SLA 履约提醒扫描任务
     *
     * @return void
     */
    public function process_sla_notifications() {
        if ( ! qibbs_get_option( 'enable_notifications', true ) ) {
            return;
        }

        $due_window_seconds = max( 1800, (int) apply_filters( 'qibbs_ticket_sla_due_window', 6 * HOUR_IN_SECONDS ) );
        $now_ts = qibbs_current_timestamp();
        $scan_limit = max( 50, min( 5000, (int) apply_filters( 'qibbs_ticket_sla_scan_limit', 1000 ) ) );
        $open_statuses = array(
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_PENDING_CUSTOMER,
            self::STATUS_REOPENED,
        );
        $cursor_option = 'qibbs_ticket_sla_scan_cursor';
        $cursor = max( 0, absint( get_option( $cursor_option, 0 ) ) );

        global $wpdb;
        $table = Qibbs_Database::tickets();
        $tickets = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_id, subject, status, first_response_deadline, first_response_at, resolution_deadline
                 FROM {$table}
                 WHERE id > %d
                   AND status IN ( %s, %s, %s, %s )
                   AND (
                       (
                           first_response_deadline IS NOT NULL
                           AND ( first_response_at IS NULL OR first_response_at > first_response_deadline )
                       )
                       OR resolution_deadline IS NOT NULL
                   )
                 ORDER BY id ASC
                 LIMIT %d",
                $cursor,
                $open_statuses[0],
                $open_statuses[1],
                $open_statuses[2],
                $open_statuses[3],
                $scan_limit
            )
        );

        if ( empty( $tickets ) && $cursor > 0 ) {
            update_option( $cursor_option, 0, false );
            $cursor = 0;
            $tickets = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, user_id, subject, status, first_response_deadline, first_response_at, resolution_deadline
                     FROM {$table}
                     WHERE id > %d
                       AND status IN ( %s, %s, %s, %s )
                       AND (
                           (
                               first_response_deadline IS NOT NULL
                               AND ( first_response_at IS NULL OR first_response_at > first_response_deadline )
                           )
                           OR resolution_deadline IS NOT NULL
                       )
                     ORDER BY id ASC
                     LIMIT %d",
                    $cursor,
                    $open_statuses[0],
                    $open_statuses[1],
                    $open_statuses[2],
                    $open_statuses[3],
                    $scan_limit
                )
            );
        }

        if ( empty( $tickets ) ) {
            return;
        }

        $ticket_count = count( $tickets );
        $last_ticket = end( $tickets );
        $next_cursor = ( $ticket_count >= $scan_limit && ! empty( $last_ticket->id ) ) ? absint( $last_ticket->id ) : 0;
        reset( $tickets );

        $sla_pref_cache = array();

        foreach ( $tickets as $ticket ) {
            $ticket_id = isset( $ticket->id ) ? absint( $ticket->id ) : 0;
            $user_id = isset( $ticket->user_id ) ? absint( $ticket->user_id ) : 0;
            if ( ! $ticket_id || ! $user_id ) {
                continue;
            }

            if ( ! array_key_exists( $user_id, $sla_pref_cache ) ) {
                $sla_pref_cache[ $user_id ] = function_exists( 'qibbs_can_send_notification_type' )
                    ? (bool) qibbs_can_send_notification_type( $user_id, 'ticket_sla' )
                    : true;
            }

            if ( ! $sla_pref_cache[ $user_id ] ) {
                continue;
            }

            $subject = isset( $ticket->subject ) ? sanitize_text_field( (string) $ticket->subject ) : '';
            if ( '' === $subject ) {
                $subject = '#' . $ticket_id;
            }

            $first_response_deadline = isset( $ticket->first_response_deadline ) ? (string) $ticket->first_response_deadline : '';
            $first_response_deadline_ts = '' !== $first_response_deadline ? strtotime( $first_response_deadline ) : 0;
            $first_response_at_ts = ! empty( $ticket->first_response_at ) ? strtotime( (string) $ticket->first_response_at ) : 0;
            if ( $first_response_deadline_ts > 0 ) {
                if ( $first_response_at_ts <= 0 && $first_response_deadline_ts > $now_ts && ( $first_response_deadline_ts - $now_ts ) <= $due_window_seconds ) {
                    $this->insert_sla_notification(
                        $user_id,
                        $ticket_id,
                        sprintf( __( '你的工单「%s」首响 SLA 即将到期，请关注处理进度。', 'qibbs-community' ), $subject ),
                        'ticket_sla:first_response:due:' . $ticket_id . ':' . $first_response_deadline
                    );
                }

                if (
                    ( $first_response_at_ts <= 0 && $first_response_deadline_ts <= $now_ts )
                    || ( $first_response_at_ts > 0 && $first_response_at_ts > $first_response_deadline_ts )
                ) {
                    $this->insert_sla_notification(
                        $user_id,
                        $ticket_id,
                        sprintf( __( '你的工单「%s」首响 SLA 已超时，你可以继续补充信息催办。', 'qibbs-community' ), $subject ),
                        'ticket_sla:first_response:overdue:' . $ticket_id . ':' . $first_response_deadline
                    );
                }
            }

            $resolution_deadline = isset( $ticket->resolution_deadline ) ? (string) $ticket->resolution_deadline : '';
            $resolution_deadline_ts = '' !== $resolution_deadline ? strtotime( $resolution_deadline ) : 0;
            if ( $resolution_deadline_ts > 0 ) {
                if ( $resolution_deadline_ts > $now_ts && ( $resolution_deadline_ts - $now_ts ) <= $due_window_seconds ) {
                    $this->insert_sla_notification(
                        $user_id,
                        $ticket_id,
                        sprintf( __( '你的工单「%s」解决 SLA 即将到期，我们正在加速处理中。', 'qibbs-community' ), $subject ),
                        'ticket_sla:resolution:due:' . $ticket_id . ':' . $resolution_deadline
                    );
                }

                if ( $resolution_deadline_ts <= $now_ts ) {
                    $this->insert_sla_notification(
                        $user_id,
                        $ticket_id,
                        sprintf( __( '你的工单「%s」解决 SLA 已超时，我们会继续跟进处理。', 'qibbs-community' ), $subject ),
                        'ticket_sla:resolution:overdue:' . $ticket_id . ':' . $resolution_deadline
                    );
                }
            }
        }

        update_option( $cursor_option, $next_cursor, false );
    }

    /**
     * 写入单条 SLA 预警通知并实施防重去重
     *
     * @param int    $user_id    Target user ID.
     * @param int    $ticket_id  Ticket ID.
     * @param string $content    Notification content.
     * @param string $dedupe_key Dedupe key input.
     * @return void
     */
    private function insert_sla_notification( $user_id, $ticket_id, $content, $dedupe_key ) {
        Qibbs_Notification_Delivery::insert( array(
            'user_id'     => absint( $user_id ),
            'type'        => 'ticket_sla',
            'actor_id'    => 0,
            'object_type' => 'ticket',
            'object_id'   => absint( $ticket_id ),
            'content'     => sanitize_text_field( $content ),
            'is_read'     => 0,
            'created_at'  => current_time( 'mysql' ),
            'dedupe_key'  => sanitize_text_field( $dedupe_key ),
        ) );
    }

    /**
     * 获取工单状态中文标签
     *
     * @param string $status Status key.
     * @return string
     */
    public function get_status_label( $status ) {
        $labels = $this->get_status_labels();

        return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
    }

    /**
     * 获取工单状态全量中文映射字典
     *
     * @return array<string,string>
     */
    public function get_status_labels() {
        return array(
            self::STATUS_PENDING          => __( '待处理', 'qibbs-community' ),
            self::STATUS_PROCESSING       => __( '处理中', 'qibbs-community' ),
            self::STATUS_PENDING_CUSTOMER => __( '待用户反馈', 'qibbs-community' ),
            self::STATUS_REOPENED         => __( '已重开', 'qibbs-community' ),
            self::STATUS_RESOLVED         => __( '已解决', 'qibbs-community' ),
            self::STATUS_CLOSED           => __( '已关闭', 'qibbs-community' ),
        );
    }

    /**
     * 计算工单当前的 SLA 履约健康状态
     *
     * @param object $ticket Ticket row.
     * @return array{first_response_due:bool,resolution_due:bool,has_breach:bool}
     */
    public function get_sla_state( $ticket ) {
        $now_ts = qibbs_current_timestamp();
        $first_response_deadline = ! empty( $ticket->first_response_deadline ) ? strtotime( (string) $ticket->first_response_deadline ) : 0;
        $resolution_deadline = ! empty( $ticket->resolution_deadline ) ? strtotime( (string) $ticket->resolution_deadline ) : 0;
        $first_response_at = ! empty( $ticket->first_response_at ) ? strtotime( (string) $ticket->first_response_at ) : 0;
        $resolved_at = ! empty( $ticket->resolved_at ) ? strtotime( (string) $ticket->resolved_at ) : 0;

        $first_response_due = false;
        if ( $first_response_deadline > 0 ) {
            if ( $first_response_at <= 0 ) {
                $first_response_due = $now_ts > $first_response_deadline;
            } else {
                $first_response_due = $first_response_at > $first_response_deadline;
            }
        }

        $resolution_due = false;
        $open_statuses = array(
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_PENDING_CUSTOMER,
            self::STATUS_REOPENED,
        );
        if ( $resolution_deadline > 0 && in_array( (string) $ticket->status, $open_statuses, true ) ) {
            $resolution_due = $now_ts > $resolution_deadline;
        } elseif ( $resolution_deadline > 0 && $resolved_at > 0 ) {
            $resolution_due = $resolved_at > $resolution_deadline;
        }

        return array(
            'first_response_due' => $first_response_due,
            'resolution_due'     => $resolution_due,
            'has_breach'         => ( $first_response_due || $resolution_due ),
        );
    }

    /**
     * 获取纳入 SLA 履约监控周期的未结单状态集合
     *
     * @return array
     */
    private function get_open_statuses() {
        return array(
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_PENDING_CUSTOMER,
            self::STATUS_REOPENED,
        );
    }

    /**
     * 组装单条 SLA 监控预警清单项
     *
     * @param object $ticket Ticket row.
     * @param string $risk_type Risk type.
     * @param int    $deadline_ts Deadline timestamp.
     * @return array
     */
    private function build_watchlist_item( $ticket, $risk_type, $deadline_ts ) {
        return array(
            'ticket_id'        => (int) $ticket->id,
            'subject'          => (string) $ticket->subject,
            'status'           => (string) $ticket->status,
            'priority'         => (string) $ticket->priority,
            'user_id'          => (int) $ticket->user_id,
            'assignee_id'      => (int) $ticket->assignee_id,
            'risk_type'        => $risk_type,
            'risk_label'       => $this->get_watchlist_risk_label( $risk_type ),
            'deadline_at'      => $deadline_ts > 0 ? qibbs_format_datetime( 'Y-m-d H:i:s', $deadline_ts ) : '',
            'deadline_ts'      => $deadline_ts,
            'minutes_to_due'   => $deadline_ts > 0 ? (int) floor( ( $deadline_ts - qibbs_current_timestamp() ) / MINUTE_IN_SECONDS ) : 0,
        );
    }

    /**
     * 按严重程度与剩余超时时限进行复合排序
     *
     * @param array $items Watchlist items.
     * @return array
     */
    private function sort_watchlist_items( $items ) {
        $severity = array(
            'resolution_overdue'    => 1,
            'first_response_overdue'=> 2,
            'unassigned'            => 3,
            'resolution_due_soon'   => 4,
            'first_response_due_soon' => 5,
        );

        usort(
            $items,
            static function( $left, $right ) use ( $severity ) {
                $left_weight = isset( $severity[ $left['risk_type'] ] ) ? $severity[ $left['risk_type'] ] : 99;
                $right_weight = isset( $severity[ $right['risk_type'] ] ) ? $severity[ $right['risk_type'] ] : 99;
                if ( $left_weight !== $right_weight ) {
                    return $left_weight <=> $right_weight;
                }

                $left_deadline = ! empty( $left['deadline_ts'] ) ? (int) $left['deadline_ts'] : PHP_INT_MAX;
                $right_deadline = ! empty( $right['deadline_ts'] ) ? (int) $right['deadline_ts'] : PHP_INT_MAX;
                if ( $left_deadline !== $right_deadline ) {
                    return $left_deadline <=> $right_deadline;
                }

                return (int) $left['ticket_id'] <=> (int) $right['ticket_id'];
            }
        );

        $deduped = array();
        $seen_ticket_ids = array();
        foreach ( $items as $item ) {
            $ticket_id = isset( $item['ticket_id'] ) ? (int) $item['ticket_id'] : 0;
            if ( $ticket_id > 0 && isset( $seen_ticket_ids[ $ticket_id ] ) ) {
                continue;
            }
            if ( $ticket_id > 0 ) {
                $seen_ticket_ids[ $ticket_id ] = true;
            }
            $deduped[] = $item;
        }

        return $deduped;
    }

    /**
     * 获取 SLA 预警风险类型中文标签
     *
     * @param string $risk_type Risk type.
     * @return string
     */
    private function get_watchlist_risk_label( $risk_type ) {
        $labels = array(
            'first_response_due_soon' => __( '首响即将到期', 'qibbs-community' ),
            'first_response_overdue'  => __( '首响已超时', 'qibbs-community' ),
            'resolution_due_soon'     => __( '解决即将到期', 'qibbs-community' ),
            'resolution_overdue'      => __( '解决已超时', 'qibbs-community' ),
            'unassigned'              => __( '未分配负责人', 'qibbs-community' ),
        );

        return isset( $labels[ $risk_type ] ) ? $labels[ $risk_type ] : $risk_type;
    }

    /**
     * 获取优先级中文标签
     *
     * @param string $priority Priority key.
     * @return string
     */
    public function get_priority_label( $priority ) {
        $labels = array(
            self::PRIORITY_LOW    => __( '低', 'qibbs-community' ),
            self::PRIORITY_NORMAL => __( '普通', 'qibbs-community' ),
            self::PRIORITY_HIGH   => __( '高', 'qibbs-community' ),
            self::PRIORITY_URGENT => __( '紧急', 'qibbs-community' ),
        );

        return isset( $labels[ $priority ] ) ? $labels[ $priority ] : $priority;
    }

    /**
     * 校验工单状态值合法性
     *
     * @param string $status Status to validate.
     * @return string
     */
    private function validate_status( $status ) {
        $valid = array(
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_PENDING_CUSTOMER,
            self::STATUS_REOPENED,
            self::STATUS_RESOLVED,
            self::STATUS_CLOSED,
        );
        return in_array( $status, $valid, true ) ? $status : self::STATUS_PENDING;
    }

    /**
     * 校验优先级数值合法性
     *
     * @param string $priority Priority to validate.
     * @return string
     */
    private function validate_priority( $priority ) {
        $valid = array( self::PRIORITY_LOW, self::PRIORITY_NORMAL, self::PRIORITY_HIGH, self::PRIORITY_URGENT );
        return in_array( $priority, $valid, true ) ? $priority : self::PRIORITY_NORMAL;
    }

    /**
     * 投递工单新回复通知
     *
     * @param int $ticket_id Ticket ID.
     * @param int $reply_id  Reply ID.
     */
    private function send_reply_notification( $ticket_id, $reply_id ) {
        $ticket = $this->get_ticket( $ticket_id );
        if ( ! $ticket ) {
            return;
        }

        if ( function_exists( 'qibbs_can_send_notification_type' ) && ! qibbs_can_send_notification_type( (int) $ticket->user_id, 'ticket_reply' ) ) {
            return;
        }

        Qibbs_Notification_Delivery::insert( array(
            'user_id'     => $ticket->user_id,
            'type'        => 'ticket_reply',
            'actor_id'    => get_current_user_id(),
            'object_type' => 'ticket',
            'object_id'   => $ticket_id,
            'content'     => sprintf( __( '您的工单「%s」有新回复', 'qibbs-community' ), $ticket->subject ),
            'is_read'     => 0,
            'created_at'  => current_time( 'mysql' ),
        ) );
    }

    /**
     * 投递工单已结案通知
     *
     * @param int $ticket_id Ticket ID.
     */
    private function send_resolved_notification( $ticket_id ) {
        $ticket = $this->get_ticket( $ticket_id );
        if ( ! $ticket ) {
            return;
        }

        if ( function_exists( 'qibbs_can_send_notification_type' ) && ! qibbs_can_send_notification_type( (int) $ticket->user_id, 'ticket_resolved' ) ) {
            return;
        }

        Qibbs_Notification_Delivery::insert( array(
            'user_id'     => $ticket->user_id,
            'type'        => 'ticket_resolved',
            'actor_id'    => get_current_user_id(),
            'object_type' => 'ticket',
            'object_id'   => $ticket_id,
            'content'     => sprintf( __( '您的工单「%s」已解决', 'qibbs-community' ), $ticket->subject ),
            'is_read'     => 0,
            'created_at'  => current_time( 'mysql' ),
        ) );
    }

    /**
     * 投递工单转公开贴通知
     *
     * @param int $ticket_id Ticket ID.
     * @param int $post_id   Post ID.
     */
    private function send_published_notification( $ticket_id, $post_id ) {
        $ticket = $this->get_ticket( $ticket_id );
        if ( ! $ticket ) {
            return;
        }

        if ( function_exists( 'qibbs_can_send_notification_type' ) && ! qibbs_can_send_notification_type( (int) $ticket->user_id, 'ticket_published' ) ) {
            return;
        }

        Qibbs_Notification_Delivery::insert( array(
            'user_id'     => $ticket->user_id,
            'type'        => 'ticket_published',
            'actor_id'    => get_current_user_id(),
            'object_type' => 'post',
            'object_id'   => $post_id,
            'content'     => sprintf( __( '您的工单「%s」已公开分享到社区', 'qibbs-community' ), $ticket->subject ),
            'is_read'     => 0,
            'created_at'  => current_time( 'mysql' ),
        ) );
    }

    /**
     * 校验用户对该工单的查阅与操作权限
     *
     * @param int $ticket_id Ticket ID.
     * @param int $user_id   User ID.
     * @return bool
     */
    public function can_access( $ticket_id, $user_id ) {
        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        $ticket = $this->get_ticket( $ticket_id );
        return $ticket && (int) $ticket->user_id === (int) $user_id;
    }
}
