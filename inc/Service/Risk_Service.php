<?php
/**
 * 安全风控与防刷服务类
 *
 * 提供客户端真实 IP 探针、频次限流、自动封禁及风险评估
 *
 * @package Qibbs_Community
 * @subpackage Service
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qibbs_Risk_Service {

    /**
     * WP-Cron 异步清理过期风控日志钩子
     *
     * @var string
     */
    const CLEANUP_CRON_HOOK = 'qibbs_risk_cleanup_event';

    /**
     * 请求级风控数据缓存
     *
     * @var array<string,mixed>
     */
    private static $runtime_cache = array();

    /**
     * 计入用户风险评分的违规审核动作列表
     *
     * @return array
     */
    private static function get_risk_moderation_actions() {
        return array(
            'reject',
            'trash',
            'purge',
            'delete',
            'block',
            'moderator_delete_post',
            'moderator_block_post',
            'moderator_trash_post',
            'moderator_delete_comment',
            'moderator_block_comment',
            'moderator_blacklist_user',
            'moderator_set_post_cooldown',
        );
    }

    /**
     * 检查持久化对象缓存（Redis/Memcached）是否可用
     *
     * @return bool
     */
    private static function use_object_cache() {
        return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
    }

    /**
     * 构建安全风控缓存键名
     *
     * @param string $prefix  Cache prefix.
     * @param array  $payload Cache payload.
     * @return string
     */
    private static function build_cache_key( $prefix, $payload = array() ) {
        return 'qibbs_risk_' . sanitize_key( $prefix ) . '_' . md5( wp_json_encode( (array) $payload ) );
    }

    /**
     * 读取风控缓存数据
     *
     * @param string $key Cache key.
     * @return mixed|null
     */
    private static function get_cached_payload( $key ) {
        if ( array_key_exists( $key, self::$runtime_cache ) ) {
            return self::$runtime_cache[ $key ];
        }

        $cached = false;
        if ( self::use_object_cache() ) {
            $found = false;
            $cached = wp_cache_get( $key, 'qibbs_risk', false, $found );
            if ( ! $found ) {
                $cached = false;
            }
        } else {
            $cached = get_transient( $key );
        }

        if ( false === $cached ) {
            return null;
        }

        self::$runtime_cache[ $key ] = $cached;
        return $cached;
    }

    /**
     * 写入风控缓存数据
     *
     * @param string $key   Cache key.
     * @param mixed  $value Cached value.
     * @param int    $ttl   Cache TTL.
     * @return mixed
     */
    private static function set_cached_payload( $key, $value, $ttl ) {
        $ttl = max( 30, absint( $ttl ) );
        self::$runtime_cache[ $key ] = $value;

        if ( self::use_object_cache() ) {
            wp_cache_set( $key, $value, 'qibbs_risk', $ttl );
        } else {
            set_transient( $key, $value, $ttl );
        }

        return $value;
    }

    /**
     * 风控日志分页是否采用轻量估算机制
     *
     * @param int $page Current page.
     * @return bool
     */
    private static function should_use_lightweight_total( $page ) {
        return absint( $page ) > 1;
    }

    /**
     * 剔除 limit+1 分页探针溢出记录
     *
     * @param array $rows     Result rows.
     * @param int   $per_page Items per page.
     * @return array{rows:array,has_more:bool}
     */
    private static function trim_overflow_results( array $rows, $per_page ) {
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
     * @param int  $shown    Number of displayed rows.
     * @param bool $has_more Whether another page exists.
     * @return int
     */
    private static function build_lightweight_total( $page, $per_page, $shown, $has_more ) {
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
     * 获取访客真实 IP（穿透 CDN/反向代理）
     *
     * @return string
     */
    public static function get_client_ip() {
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $remote_ip = filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '';
        $mode = self::get_client_ip_mode();

        // 严格直接连接模式：仅信任 REMOTE_ADDR
        if ( 'remote_only' === $mode ) {
            return '' !== $remote_ip ? $remote_ip : '0.0.0.0';
        }

        if ( '' === $remote_ip && 'strict_proxy' === $mode ) {
            return '0.0.0.0';
        }

        // 来源非可信代理时强制使用直接连接 IP
        if ( '' !== $remote_ip && ! self::should_trust_forwarded_headers( $mode, $remote_ip ) ) {
            return $remote_ip;
        }

        $candidate_headers = array(
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED',
            'HTTP_CLIENT_IP',
        );

        $candidates = array();
        foreach ( $candidate_headers as $header ) {
            if ( empty( $_SERVER[ $header ] ) ) {
                continue;
            }
            $raw = wp_unslash( $_SERVER[ $header ] );
            $candidates = array_merge( $candidates, self::parse_ip_candidates( (string) $raw ) );
        }

        if ( '' !== $remote_ip ) {
            $candidates[] = $remote_ip;
        }

        $candidates = array_values( array_unique( array_filter( array_map( 'trim', $candidates ) ) ) );

        // 优先提取公网可路由 IP（代理背后的真实访客）
        foreach ( $candidates as $candidate ) {
            if ( self::is_public_ip( $candidate ) ) {
                return $candidate;
            }
        }

        // 降级：返回首个合规 IP 地址
        foreach ( $candidates as $candidate ) {
            if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                return $candidate;
            }
        }

        return '0.0.0.0';
    }

    /**
     * 获取客户端 IP 获取模式
     *
     * auto_proxy（默认）：配置受信任代理时仅信任指定代理，未配置时兼容常规 CDN 头
     * strict_proxy：仅在直接连接为配置的受信任代理时才解析转发头
     * cdn_compatible：始终信任通用 CDN 转发头
     * remote_only：始终强制使用 REMOTE_ADDR
     *
     * @return string
     */
    private static function get_client_ip_mode() {
        $mode = sanitize_key( (string) qibbs_get_option( 'risk_client_ip_mode', 'auto_proxy' ) );
        $mode = apply_filters( 'qibbs_risk_client_ip_mode', $mode );
        $allowed = array( 'auto_proxy', 'strict_proxy', 'cdn_compatible', 'remote_only' );
        return in_array( $mode, $allowed, true ) ? $mode : 'auto_proxy';
    }

    /**
     * 判断当前请求是否满足信任转发 IP 头的条件
     *
     * @param string $mode      IP mode.
     * @param string $remote_ip Remote address.
     * @return bool
     */
    private static function should_trust_forwarded_headers( $mode, $remote_ip ) {
        if ( 'remote_only' === $mode ) {
            return false;
        }

        if ( 'cdn_compatible' === $mode ) {
            return true;
        }

        $trusted_rules = self::get_trusted_proxy_rules();
        if ( ! empty( $trusted_rules ) ) {
            foreach ( $trusted_rules as $rule ) {
                if ( self::ip_matches_rule( $remote_ip, $rule ) ) {
                    return true;
                }
            }
            return false;
        }

        if ( 'strict_proxy' === $mode ) {
            return false;
        }

        // auto_proxy 未配置信任列表时保持常规 CDN 兼容性
        return true;
    }

    /**
     * 获取已配置的受信任反向代理 IP/CIDR 规则
     *
     * Source:
     * - option `risk_trusted_proxies` (comma-separated)
     * - constant `QIBBS_TRUSTED_PROXIES` (comma-separated)
     * - filter `qibbs_risk_trusted_proxies`
     *
     * @return array
     */
    private static function get_trusted_proxy_rules() {
        static $cached_rules = null;
        if ( is_array( $cached_rules ) ) {
            return $cached_rules;
        }

        $rules = array();

        if ( defined( 'QIBBS_TRUSTED_PROXIES' ) && is_string( QIBBS_TRUSTED_PROXIES ) ) {
            $rules = array_merge( $rules, preg_split( '/[\s,]+/', QIBBS_TRUSTED_PROXIES ) );
        }

        $option_rules = qibbs_get_option( 'risk_trusted_proxies', '' );
        if ( is_string( $option_rules ) && '' !== trim( $option_rules ) ) {
            $rules = array_merge( $rules, preg_split( '/[\s,]+/', $option_rules ) );
        }

        $filtered = apply_filters( 'qibbs_risk_trusted_proxies', $rules );
        if ( is_array( $filtered ) ) {
            $rules = $filtered;
        }

        $normalized = array();
        foreach ( (array) $rules as $rule ) {
            $rule = trim( (string) $rule );
            if ( '' === $rule ) {
                continue;
            }
            if ( filter_var( $rule, FILTER_VALIDATE_IP ) || self::is_valid_cidr_rule( $rule ) ) {
                $normalized[] = $rule;
            }
        }

        $cached_rules = array_values( array_unique( $normalized ) );
        return $cached_rules;
    }

    /**
     * 校验规则是否为合规的 CIDR 网段格式
     *
     * @param string $rule CIDR rule.
     * @return bool
     */
    private static function is_valid_cidr_rule( $rule ) {
        if ( false === strpos( $rule, '/' ) ) {
            return false;
        }

        list( $subnet, $mask ) = array_pad( explode( '/', $rule, 2 ), 2, '' );
        $subnet = trim( (string) $subnet );
        $mask = trim( (string) $mask );

        if ( ! filter_var( $subnet, FILTER_VALIDATE_IP ) || '' === $mask || ! is_numeric( $mask ) ) {
            return false;
        }

        $mask = (int) $mask;
        $max_bits = strpos( $subnet, ':' ) !== false ? 128 : 32;
        return $mask >= 0 && $mask <= $max_bits;
    }

    /**
     * 校验指定 IP 是否匹配单 IP 或 CIDR 网段规则
     *
     * @param string $ip   IP.
     * @param string $rule Rule.
     * @return bool
     */
    private static function ip_matches_rule( $ip, $rule ) {
        if ( $ip === $rule ) {
            return true;
        }

        if ( ! self::is_valid_cidr_rule( $rule ) ) {
            return false;
        }

        list( $subnet, $mask_bits ) = array_pad( explode( '/', $rule, 2 ), 2, '0' );
        $mask_bits = (int) $mask_bits;

        $ip_bin = @inet_pton( $ip );
        $subnet_bin = @inet_pton( $subnet );
        if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
            return false;
        }

        $full_bytes = intdiv( $mask_bits, 8 );
        $remaining_bits = $mask_bits % 8;

        if ( $full_bytes > 0 && substr( $ip_bin, 0, $full_bytes ) !== substr( $subnet_bin, 0, $full_bytes ) ) {
            return false;
        }

        if ( 0 === $remaining_bits ) {
            return true;
        }

        if ( $full_bytes >= strlen( $ip_bin ) ) {
            return false;
        }

        $mask = ( 0xFF << ( 8 - $remaining_bits ) ) & 0xFF;
        $ip_byte = ord( $ip_bin[ $full_bytes ] );
        $subnet_byte = ord( $subnet_bin[ $full_bytes ] );

        return ( $ip_byte & $mask ) === ( $subnet_byte & $mask );
    }

    /**
     * 检查 IP 地址是否属于私有内网或保留网段
     *
     * @param string $ip IP.
     * @return bool
     */
    private static function is_private_or_reserved_ip( $ip ) {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return true;
        }

        return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    /**
     * 校验 IP 是否为公网可路由地址（排除内网私有地址）
     *
     * @param string $ip IP.
     * @return bool
     */
    private static function is_public_ip( $ip ) {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    /**
     * 从转发头字符串中解析提取潜在 IP 候选列表
     *
     * Supports:
     * - X-Forwarded-For: "1.2.3.4, 10.0.0.1"
     * - Forwarded: "for=1.2.3.4;proto=https"
     * 解析带方括号与可选端口的 IPv6 格式
     *
     * @param string $raw_value Header value.
     * @return array
     */
    private static function parse_ip_candidates( $raw_value ) {
        $raw_value = trim( (string) $raw_value );
        if ( '' === $raw_value ) {
            return array();
        }

        $parts = preg_split( '/[,;]/', $raw_value );
        if ( ! is_array( $parts ) ) {
            $parts = array( $raw_value );
        }

        $ips = array();
        foreach ( $parts as $part ) {
            $token = trim( (string) $part );
            if ( '' === $token ) {
                continue;
            }

            if ( stripos( $token, 'for=' ) === 0 ) {
                $token = trim( substr( $token, 4 ) );
            } elseif ( strpos( $token, '=' ) !== false ) {
                continue;
            }

            $token = trim( $token, " \t\n\r\0\x0B\"'" );
            if ( '' === $token || strtolower( $token ) === 'unknown' ) {
                continue;
            }

            if ( preg_match( '/^\[([a-f0-9:.]+)\](?::\d+)?$/i', $token, $matches ) ) {
                $token = $matches[1];
            } elseif ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?$/', $token, $matches ) ) {
                $token = $matches[1];
            }

            if ( filter_var( $token, FILTER_VALIDATE_IP ) ) {
                $ips[] = $token;
            }
        }

        return array_values( array_unique( $ips ) );
    }

    /**
     * 构建请求路由端点标识字符串
     *
     * @return string
     */
    private static function get_endpoint() {
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $uri = sanitize_text_field( $uri );
        return mb_substr( $uri, 0, 255 );
    }

    /**
     * 检查安全风控模块是否启用
     *
     * @return bool
     */
    private static function is_enabled() {
        return (bool) qibbs_get_option( 'risk_enable_ip_limit', true );
    }

    /**
     * 检查高频违规自动封禁是否启用
     *
     * @return bool
     */
    private static function is_auto_ban_enabled() {
        return (bool) qibbs_get_option( 'risk_enable_auto_ban', true );
    }

    /**
     * 风控日志记录模式：仅拦截 | 全部记录
     *
     * @return string
     */
    private static function get_log_mode() {
        $mode = qibbs_get_option( 'risk_log_mode', 'blocked' );
        return in_array( $mode, array( 'blocked', 'all' ), true ) ? $mode : 'blocked';
    }

    /**
     * 检查正常放行请求是否满足日志记录条件
     *
     * @return bool
     */
    private static function should_log_allow() {
        return self::get_log_mode() === 'all';
    }

    /**
     * 检查内容敏感词与提交频次风控是否启用
     *
     * @return bool
     */
    private static function is_content_rules_enabled() {
        return (bool) qibbs_get_option( 'risk_enable_content_rules', false );
    }

    /**
     * 获取配置的敏感违规关键词库
     *
     * @return array
     */
    private static function get_content_keywords() {
        static $cached_keywords = null;
        if ( is_array( $cached_keywords ) ) {
            return $cached_keywords;
        }

        $raw = (string) qibbs_get_option( 'risk_content_keywords', '' );
        $parts = preg_split( '/[\r\n,]+/', $raw );
        if ( ! is_array( $parts ) ) {
            $parts = array();
        }

        $keywords = array();
        foreach ( $parts as $part ) {
            $keyword = trim( sanitize_text_field( (string) $part ) );
            if ( '' === $keyword ) {
                continue;
            }
            $keywords[] = $keyword;
        }

        $cached_keywords = array_values( array_unique( $keywords ) );
        return $cached_keywords;
    }

    /**
     * 在正文中匹配命中的敏感关键词
     *
     * @param string $content Content.
     * @return array
     */
    private static function get_matched_keywords( $content ) {
        $content = wp_strip_all_tags( (string) $content );
        if ( '' === trim( $content ) ) {
            return array();
        }

        $matches = array();
        foreach ( self::get_content_keywords() as $keyword ) {
            if ( mb_stripos( $content, $keyword ) !== false ) {
                $matches[] = $keyword;
            }
        }

        return array_values( array_unique( $matches ) );
    }

    /**
     * 日志入库前标准化元数据格式
     *
     * @param mixed $meta Meta.
     * @return array
     */
    private static function normalize_log_meta( $meta ) {
        if ( ! is_array( $meta ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $meta as $key => $value ) {
            $clean_key = sanitize_key( (string) $key );
            if ( '' === $clean_key ) {
                continue;
            }

            $normalized[ $clean_key ] = self::sanitize_log_meta_value( $value );
        }

        return $normalized;
    }

    /**
     * 递归净化单项日志元数据值
     *
     * @param mixed $value Meta value.
     * @return mixed
     */
    private static function sanitize_log_meta_value( $value ) {
        if ( is_array( $value ) ) {
            $normalized = array();
            foreach ( $value as $key => $item ) {
                if ( is_string( $key ) ) {
                    $clean_key = sanitize_key( $key );
                    if ( '' === $clean_key ) {
                        continue;
                    }
                    $normalized[ $clean_key ] = self::sanitize_log_meta_value( $item );
                } else {
                    $normalized[] = self::sanitize_log_meta_value( $item );
                }
            }
            return $normalized;
        }

        return sanitize_text_field( (string) $value );
    }

    /**
     * 记录并统计最近的内容提交频次
     *
     * @param int    $user_id User ID.
     * @param string $action Action key.
     * @param int    $window_minutes Window in minutes.
     * @return int
     */
    private static function bump_content_frequency_counter( $user_id, $action, $window_minutes ) {
        $user_id = absint( $user_id );
        $window_minutes = max( 1, min( 120, absint( $window_minutes ) ) );
        if ( ! $user_id ) {
            return 0;
        }

        $key = 'qibbs_risk_content_freq_' . $user_id . '_' . sanitize_key( $action );
        $timestamps = get_transient( $key );
        $timestamps = is_array( $timestamps ) ? $timestamps : array();

        $now = time();
        $cutoff = $now - ( $window_minutes * MINUTE_IN_SECONDS );
        $timestamps = array_values(
            array_filter(
                array_map( 'absint', $timestamps ),
                static function( $timestamp ) use ( $cutoff ) {
                    return $timestamp >= $cutoff;
                }
            )
        );

        $timestamps[] = $now;
        set_transient( $key, $timestamps, $window_minutes * MINUTE_IN_SECONDS );

        return count( $timestamps );
    }

    /**
     * 写入单条安全风控日志
     *
     * @param string   $action Action.
     * @param string   $status Status.
     * @param string   $reason Reason.
     * @param array    $meta Meta.
     * @param bool     $force Whether to force allow-log insert.
     * @param int|null $user_id User ID override.
     * @return void
     */
    private static function insert_log_row( $action, $status, $reason = '', $meta = array(), $force = false, $user_id = null ) {
        if ( 'allow' === $status && ! $force && ! self::should_log_allow() ) {
            return;
        }

        global $wpdb;
        $table = Qibbs_Database::get_table( 'risk_logs' );

        $status = in_array( $status, array( 'allow', 'blocked' ), true ) ? $status : 'allow';
        $action = sanitize_key( $action );
        $reason = sanitize_text_field( $reason );
        $user_id = null === $user_id ? get_current_user_id() : absint( $user_id );

        $wpdb->insert(
            $table,
            array(
                'ip'         => self::get_client_ip(),
                'user_id'    => $user_id ? (int) $user_id : 0,
                'action'     => mb_substr( $action, 0, 120 ),
                'endpoint'   => self::get_endpoint(),
                'status'     => $status,
                'reason'     => mb_substr( $reason, 0, 255 ),
                'meta'       => ! empty( $meta ) ? wp_json_encode( self::normalize_log_meta( $meta ) ) : null,
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * 对发布内容进行敏感词与频次双重安全评估
     *
     * @param int    $user_id User ID.
     * @param string $action Action key: post/comment/message/ticket.
     * @param string $content Content text.
     * @param array  $context Context meta.
     * @return true|WP_Error
     */
    public static function evaluate_content_submission( $user_id, $action, $content, $context = array() ) {
        $user_id = absint( $user_id );
        $action = sanitize_key( $action );
        $plain_content = trim( wp_strip_all_tags( (string) $content ) );

        if ( ! $user_id || '' === $action || '' === $plain_content || ! self::is_content_rules_enabled() ) {
            return true;
        }

        if ( user_can( $user_id, 'manage_options' ) ) {
            return true;
        }

        $matched_keywords = self::get_matched_keywords( $plain_content );
        if ( empty( $matched_keywords ) ) {
            return true;
        }

        $window_minutes = max( 1, min( 120, absint( qibbs_get_option( 'risk_content_frequency_window', 10 ) ) ) );
        $frequency_limit = max( 2, min( 50, absint( qibbs_get_option( 'risk_content_frequency_limit', 4 ) ) ) );
        $recent_action_count = self::bump_content_frequency_counter( $user_id, $action, $window_minutes );
        $recent_total_count = self::bump_content_frequency_counter( $user_id, 'all', $window_minutes );
        $recent_count = max( $recent_action_count, $recent_total_count );
        $meta = array(
            'matched_keywords' => $matched_keywords,
            'recent_count'     => $recent_count,
            'recent_action_count' => $recent_action_count,
            'recent_total_count'  => $recent_total_count,
            'window_minutes'   => $window_minutes,
            'content_action'   => $action,
            'context'          => self::normalize_log_meta( $context ),
        );

        if ( $recent_count >= $frequency_limit ) {
            self::insert_log_row(
                'content_' . $action,
                'blocked',
                __( '命中风险关键词并触发频率阈值', 'qibbs-community' ),
                $meta,
                true,
                $user_id
            );

            return new WP_Error(
                'qibbs_content_risk_blocked',
                __( '内容触发风控，请稍后再试或联系管理员。', 'qibbs-community' )
            );
        }

        self::insert_log_row(
            'content_' . $action,
            'allow',
            __( '命中风险关键词，已记录观察', 'qibbs-community' ),
            $meta,
            true,
            $user_id
        );

        return true;
    }

    /**
     * 检查指定 IP 是否已被封禁
     *
     * @param string $ip IP address.
     * @return bool
     */
    public static function is_ip_banned( $ip = '' ) {
        if ( ! self::is_enabled() ) {
            return false;
        }

        $ip = $ip ? $ip : self::get_client_ip();
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        $cache_key = 'qibbs_ip_banned_' . md5( $ip );
        $cached = get_transient( $cache_key );
        if ( $cached === '1' ) {
            return true;
        }
        if ( $cached === '0' ) {
            return false;
        }

        global $wpdb;
        $table = Qibbs_Database::get_table( 'ip_bans' );
        $now = current_time( 'mysql' );

        $ban = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, expires_at FROM {$table}
                 WHERE ip = %s AND is_active = 1
                 ORDER BY id DESC LIMIT 1",
                $ip
            )
        );

        if ( ! $ban ) {
            set_transient( $cache_key, '0', MINUTE_IN_SECONDS * 5 );
            return false;
        }

        $expires_at = isset( $ban->expires_at ) ? (string) $ban->expires_at : '';
        if ( ! empty( $expires_at ) && $expires_at !== '0000-00-00 00:00:00' && $expires_at <= $now ) {
            $wpdb->update(
                $table,
                array( 'is_active' => 0 ),
                array( 'id' => (int) $ban->id ),
                array( '%d' ),
                array( '%d' )
            );
            set_transient( $cache_key, '0', MINUTE_IN_SECONDS * 5 );
            return false;
        }

        set_transient( $cache_key, '1', MINUTE_IN_SECONDS );
        return true;
    }

    /**
     * 执行行为限流并对恶意 IP 实施自动封禁
     *
     * @param string $action Action key.
     * @param int    $limit Max hits in window.
     * @param int    $window Window seconds.
     * @return bool
     */
    public static function enforce_rate_limit( $action, $limit = 60, $window = 60 ) {
        $action = sanitize_key( $action );
        $limit = max( 1, absint( $limit ) );
        $window = max( 1, absint( $window ) );

        if ( ! self::is_enabled() ) {
            return true;
        }

        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            return true;
        }

        $ip = self::get_client_ip();

        if ( self::is_ip_banned( $ip ) ) {
            self::log_hit( $action, 'blocked', __( 'IP 已封禁', 'qibbs-community' ) );
            return false;
        }

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
        $fingerprint = md5( $ip . '|' . substr( $ua, 0, 120 ) );
        $allowed = function_exists( 'qibbs_consume_scoped_rate_limit' )
            ? qibbs_consume_scoped_rate_limit( 'risk_' . $action, $fingerprint, $limit, $window )
            : true;

        if ( $allowed ) {
            if ( self::should_log_allow() ) {
                self::log_hit( $action, 'allow', '' );
            }
            return true;
        }

        self::log_hit( $action, 'blocked', __( '命中限流阈值', 'qibbs-community' ) );
        self::maybe_auto_ban( $ip, $action );
        return false;
    }

    /**
     * 依据高频拦截违规记录自动封禁恶意 IP
     *
     * @param string $ip IP.
     * @param string $action Action key.
     * @return void
     */
    private static function maybe_auto_ban( $ip, $action ) {
        if ( ! self::is_auto_ban_enabled() ) {
            return;
        }

        global $wpdb;
        $logs_table = Qibbs_Database::get_table( 'risk_logs' );

        $threshold = max( 3, absint( qibbs_get_option( 'risk_auto_ban_threshold', 12 ) ) );
        $window_minutes = max( 1, absint( qibbs_get_option( 'risk_auto_ban_window', 10 ) ) );

        $start_dt = current_datetime();
        $start_dt->modify( '-' . $window_minutes . ' minutes' );
        $start_time = $start_dt->format( 'Y-m-d H:i:s' );

        $blocked_hits = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$logs_table}
                 WHERE ip = %s AND status = 'blocked' AND created_at >= %s",
                $ip,
                $start_time
            )
        );

        if ( $blocked_hits < $threshold ) {
            return;
        }

        if ( self::is_ip_banned( $ip ) ) {
            return;
        }

        $duration = max( 5, absint( qibbs_get_option( 'risk_auto_ban_duration', 60 ) ) );
        self::add_ip_ban(
            $ip,
            sprintf( __( '异常请求触发自动封禁（动作：%s）', 'qibbs-community' ), $action ),
            0,
            $duration
        );

        self::log_hit( 'auto_ban', 'blocked', __( '自动封禁已生效', 'qibbs-community' ), array( 'ip' => $ip ) );
    }

    /**
     * 记录单次风险拦截事件
     *
     * @param string $action Action key.
     * @param string $status allow|blocked.
     * @param string $reason Reason.
     * @param array  $meta Meta array.
     * @return void
     */
    public static function log_hit( $action, $status, $reason = '', $meta = array() ) {
        self::insert_log_row( $action, $status, $reason, $meta, false, null );
    }

    /**
     * 管理员手动添加 IP 封禁记录
     *
     * @param string $ip IP.
     * @param string $reason Reason.
     * @param int    $created_by Admin user ID.
     * @param int    $duration_minutes Ban duration in minutes (0 = permanent).
     * @return bool
     */
    public static function add_ip_ban( $ip, $reason = '', $created_by = 0, $duration_minutes = 0 ) {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        global $wpdb;
        $table = Qibbs_Database::get_table( 'ip_bans' );

        $expires_at = null;
        $duration_minutes = absint( $duration_minutes );
        if ( $duration_minutes > 0 ) {
            $expires_at_dt = current_datetime();
            $expires_at_dt->modify( '+' . $duration_minutes . ' minutes' );
            $expires_at = $expires_at_dt->format( 'Y-m-d H:i:s' );
        }

        $result = $wpdb->insert(
            $table,
            array(
                'ip'         => $ip,
                'reason'     => sanitize_text_field( $reason ),
                'created_by' => absint( $created_by ),
                'is_active'  => 1,
                'expires_at' => $expires_at,
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%d', '%d', '%s', '%s' )
        );

        delete_transient( 'qibbs_ip_banned_' . md5( $ip ) );

        return (bool) $result;
    }

    /**
     * 解封/失效指定 IP 封禁记录
     *
     * @param int $ban_id Ban ID.
     * @return bool
     */
    public static function remove_ip_ban( $ban_id ) {
        global $wpdb;
        $table = Qibbs_Database::get_table( 'ip_bans' );

        $ban = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, ip FROM {$table} WHERE id = %d",
                absint( $ban_id )
            )
        );

        if ( ! $ban ) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            array( 'is_active' => 0 ),
            array( 'id' => (int) $ban->id ),
            array( '%d' ),
            array( '%d' )
        );

        if ( ! empty( $ban->ip ) ) {
            delete_transient( 'qibbs_ip_banned_' . md5( (string) $ban->ip ) );
        }

        return $result !== false;
    }

    /**
     * 查询当前所有生效中的 IP 封禁列表
     *
     * @param int $limit Limit.
     * @return array
     */
    public static function get_active_bans( $limit = 200 ) {
        global $wpdb;
        $table = Qibbs_Database::get_table( 'ip_bans' );

        $limit = max( 1, min( 500, absint( $limit ) ) );

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE is_active = 1
                 ORDER BY id DESC
                 LIMIT %d",
                $limit
            )
        );
    }

    /**
     * 多维度筛选查询安全风控日志
     *
     * @param array $args Query args.
     * @return array
     */
    public static function get_logs( $args = array() ) {
        global $wpdb;
        $table = Qibbs_Database::get_table( 'risk_logs' );

        $defaults = array(
            'page'     => 1,
            'per_page' => 30,
            'status'   => '',
            'action'   => '',
            'ip'       => '',
        );
        $args = wp_parse_args( $args, $defaults );

        $page = max( 1, absint( $args['page'] ) );
        $per_page = max( 10, min( 200, absint( $args['per_page'] ) ) );
        $offset = ( $page - 1 ) * $per_page;

        $where = array( '1=1' );
        $params = array();

        if ( in_array( $args['status'], array( 'allow', 'blocked' ), true ) ) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }

        if ( ! empty( $args['action'] ) ) {
            $where[] = 'action = %s';
            $params[] = sanitize_key( $args['action'] );
        }

        if ( ! empty( $args['ip'] ) ) {
            $where[] = 'ip = %s';
            $params[] = sanitize_text_field( $args['ip'] );
        }

        $where_sql = implode( ' AND ', $where );
        $use_lightweight_total = self::should_use_lightweight_total( $page );
        $total = 0;

        $total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

        if ( ! $use_lightweight_total ) {
            if ( ! empty( $params ) ) {
                $total = (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) );
            } else {
                $total = (int) $wpdb->get_var( $total_sql );
            }
        }

        $list_params = $params;
        $list_params[] = $use_lightweight_total ? ( $per_page + 1 ) : $per_page;
        $list_params[] = $offset;

        $logs = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );
        $has_more = false;

        if ( $use_lightweight_total ) {
            $trimmed = self::trim_overflow_results( is_array( $logs ) ? $logs : array(), $per_page );
            $logs = $trimmed['rows'];
            $has_more = $trimmed['has_more'];
            $total = self::build_lightweight_total( $page, $per_page, count( $logs ), $has_more );
        } else {
            $has_more = $total > ( $offset + count( $logs ) );
        }

        return array(
            'items'      => $logs,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $per_page,
            'total_pages'=> max( 1, (int) ceil( $total / $per_page ) ),
            'has_more'   => $has_more,
        );
    }

    /**
     * 查询单用户的安全风险评估画像
     *
     * @param int $user_id User ID.
     * @param int $days Window days.
     * @return array|null
     */
    public static function get_user_risk_profile( $user_id, $days = 30 ) {
        $user_id = absint( $user_id );
        $days = max( 1, min( 365, absint( $days ) ) );
        if ( ! $user_id ) {
            return null;
        }

        $cache_key = self::build_cache_key(
            'user_profile',
            array(
                'user_id' => $user_id,
                'days'    => $days,
            )
        );
        $cached = self::get_cached_payload( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return null;
        }

        $logs_table = Qibbs_Database::get_table( 'risk_logs' );
        $reports_table = Qibbs_Database::get_table( 'reports' );
        $posts_table = Qibbs_Database::posts();
        $moderation_logs_table = Qibbs_Database::get_table( 'moderation_logs' );
        $bans_table = Qibbs_Database::get_table( 'ip_bans' );
        $risk_moderation_actions = self::get_risk_moderation_actions();
        $action_placeholders = implode( ',', array_fill( 0, count( $risk_moderation_actions ), '%s' ) );

        $since_dt = current_datetime();
        $since_dt->modify( '-' . $days . ' days' );
        $since = $since_dt->format( 'Y-m-d H:i:s' );

        $risk_logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, ip, action, status, reason, meta, created_at
                 FROM {$logs_table}
                 WHERE user_id = %d AND created_at >= %s AND ( status = 'blocked' OR action LIKE %s )
                 ORDER BY id DESC
                 LIMIT 15",
                $user_id,
                $since,
                'content_%'
            )
        );

        $blocked_count = 0;
        $content_flag_count = 0;
        $content_block_count = 0;
        $last_ip = '';
        $recent_events = array();

        foreach ( (array) $risk_logs as $log ) {
            if ( '' === $last_ip && ! empty( $log->ip ) ) {
                $last_ip = (string) $log->ip;
            }

            if ( 'blocked' === $log->status ) {
                $blocked_count++;
            }

            if ( strpos( (string) $log->action, 'content_' ) === 0 ) {
                if ( 'blocked' === $log->status ) {
                    $content_block_count++;
                } else {
                    $content_flag_count++;
                }
            }

            $recent_events[] = array(
                'time'   => (string) $log->created_at,
                'action' => (string) $log->action,
                'status' => (string) $log->status,
                'reason' => (string) $log->reason,
                'ip'     => (string) $log->ip,
            );
        }

        $report_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$reports_table} AS reports
                 INNER JOIN {$posts_table} AS posts ON posts.id = reports.post_id
                 WHERE posts.author_id = %d AND reports.created_at >= %s",
                $user_id,
                $since
            )
        );

        $moderation_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$moderation_logs_table}
                 WHERE target_user_id = %d AND created_at >= %s AND action IN ({$action_placeholders})",
                array_merge(
                    array( $user_id, $since ),
                    $risk_moderation_actions
                )
            )
        );

        $active_ban_count = 0;
        if ( '' !== $last_ip ) {
            $active_ban_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$bans_table}
                     WHERE ip = %s AND is_active = 1",
                    $last_ip
                )
            );
        }

        $score = self::calculate_user_risk_score(
            $blocked_count,
            $content_flag_count,
            $content_block_count,
            $report_count,
            $moderation_count,
            $active_ban_count
        );

        $profile = array(
            'user_id'       => $user_id,
            'display_name'  => $user->display_name,
            'avatar'        => get_avatar_url( $user_id, array( 'size' => 64 ) ),
            'score'         => $score,
            'level'         => self::get_risk_level( $score ),
            'last_ip'       => $last_ip,
            'active_bans'   => $active_ban_count,
            'metrics'       => array(
                'blocked_count'       => $blocked_count,
                'content_flag_count'  => $content_flag_count,
                'content_block_count' => $content_block_count,
                'report_count'        => $report_count,
                'moderation_count'    => $moderation_count,
            ),
            'recent_events' => $recent_events,
        );

        return self::set_cached_payload(
            $cache_key,
            $profile,
            (int) apply_filters( 'qibbs_risk_profile_cache_ttl', 120 )
        );
    }

    /**
     * 获取按风险评分排序的高危用户画像列表
     *
     * @param array $args Query args.
     * @return array
     */
    public static function get_user_risk_profiles( $args = array() ) {
        $args = wp_parse_args(
            $args,
            array(
                'days'  => 30,
                'limit' => 10,
            )
        );

        $days = max( 1, min( 365, absint( $args['days'] ) ) );
        $limit = max( 1, min( 50, absint( $args['limit'] ) ) );
        $cache_key = self::build_cache_key(
            'user_profiles',
            array(
                'days'  => $days,
                'limit' => $limit,
            )
        );
        $cached = self::get_cached_payload( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;
        $since_dt = current_datetime();
        $since_dt->modify( '-' . $days . ' days' );
        $since = $since_dt->format( 'Y-m-d H:i:s' );

        $logs_table = Qibbs_Database::get_table( 'risk_logs' );
        $moderation_logs_table = Qibbs_Database::get_table( 'moderation_logs' );
        $reports_table = Qibbs_Database::get_table( 'reports' );
        $posts_table = Qibbs_Database::posts();
        $risk_moderation_actions = self::get_risk_moderation_actions();
        $action_placeholders = implode( ',', array_fill( 0, count( $risk_moderation_actions ), '%s' ) );

        $candidate_ids = array();

        $risk_users = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id
                 FROM {$logs_table}
                 WHERE user_id > 0 AND created_at >= %s AND ( status = 'blocked' OR action LIKE %s )
                 GROUP BY user_id
                 ORDER BY MAX(id) DESC
                 LIMIT 100",
                $since,
                'content_%'
            )
        );
        $candidate_ids = array_merge( $candidate_ids, array_map( 'absint', (array) $risk_users ) );

        $moderated_users = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT target_user_id
                 FROM {$moderation_logs_table}
                 WHERE target_user_id > 0 AND created_at >= %s AND action IN ({$action_placeholders})
                 GROUP BY target_user_id
                 ORDER BY MAX(id) DESC
                 LIMIT 100",
                array_merge(
                    array( $since ),
                    $risk_moderation_actions
                )
            )
        );
        $candidate_ids = array_merge( $candidate_ids, array_map( 'absint', (array) $moderated_users ) );

        $reported_users = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT posts.author_id
                 FROM {$reports_table} AS reports
                 INNER JOIN {$posts_table} AS posts ON posts.id = reports.post_id
                 WHERE posts.author_id > 0 AND reports.created_at >= %s
                 GROUP BY posts.author_id
                 ORDER BY MAX(reports.id) DESC
                 LIMIT 100",
                $since
            )
        );
        $candidate_ids = array_merge( $candidate_ids, array_map( 'absint', (array) $reported_users ) );

        $candidate_ids = array_values( array_unique( array_filter( $candidate_ids ) ) );
        if ( empty( $candidate_ids ) ) {
            return array();
        }

        $profiles = array();
        foreach ( $candidate_ids as $candidate_id ) {
            $profile = self::get_user_risk_profile( $candidate_id, $days );
            if ( ! $profile || $profile['score'] <= 0 ) {
                continue;
            }
            $profiles[] = $profile;
        }

        usort(
            $profiles,
            static function( $left, $right ) {
                if ( (int) $left['score'] !== (int) $right['score'] ) {
                    return (int) $right['score'] <=> (int) $left['score'];
                }
                return (int) $left['user_id'] <=> (int) $right['user_id'];
            }
        );

        if ( count( $profiles ) > $limit ) {
            $profiles = array_slice( $profiles, 0, $limit );
        }

        return self::set_cached_payload(
            $cache_key,
            $profiles,
            (int) apply_filters( 'qibbs_risk_profiles_cache_ttl', 120 )
        );
    }

    /**
     * 获取风控后台看板的快速概览统计数据
     *
     * @return array
     */
    public static function get_dashboard_stats() {
        global $wpdb;

        $cache_key = self::build_cache_key( 'dashboard_stats' );
        $cached = self::get_cached_payload( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $table = Qibbs_Database::get_table( 'risk_logs' );
        $today_dt = current_datetime();
        $today_dt->setTime( 0, 0, 0 );
        $today = $today_dt->format( 'Y-m-d H:i:s' );

        $stats = array(
            'hits_today' => (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $today )
            ),
            'blocked_today' => (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'blocked' AND created_at >= %s", $today )
            ),
            'content_blocked_today' => (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE status = 'blocked' AND action LIKE %s AND created_at >= %s",
                    'content_%',
                    $today
                )
            ),
            'risky_users_30d' => count(
                self::get_user_risk_profiles(
                    array(
                        'days'  => 30,
                        'limit' => 50,
                    )
                )
            ),
        );

        return self::set_cached_payload(
            $cache_key,
            $stats,
            (int) apply_filters( 'qibbs_risk_dashboard_stats_cache_ttl', 60 )
        );
    }

    /**
     * 计算用户的综合安全风险得分
     *
     * @param int $blocked_count Blocked requests.
     * @param int $content_flag_count Allowed keyword flags.
     * @param int $content_block_count Blocked content events.
     * @param int $report_count Reports against content.
     * @param int $moderation_count Moderation actions.
     * @param int $active_ban_count Active ban count.
     * @return int
     */
    private static function calculate_user_risk_score( $blocked_count, $content_flag_count, $content_block_count, $report_count, $moderation_count, $active_ban_count ) {
        $score = 0;
        $score += absint( $blocked_count ) * 10;
        $score += absint( $content_flag_count ) * 6;
        $score += absint( $content_block_count ) * 18;
        $score += absint( $report_count ) * 8;
        $score += absint( $moderation_count ) * 12;
        $score += absint( $active_ban_count ) * 25;

        return max( 0, min( 100, $score ) );
    }

    /**
     * 根据风险分值映射风险等级标签（低危/中危/高危）
     *
     * @param int $score Risk score.
     * @return string
     */
    private static function get_risk_level( $score ) {
        $score = absint( $score );
        if ( $score >= 75 ) {
            return 'critical';
        }
        if ( $score >= 50 ) {
            return 'high';
        }
        if ( $score >= 25 ) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * 物理清理过期的历史风控日志
     *
     * @param int $days Keep days.
     * @return int|false
     */
    public static function cleanup_logs( $days = 30 ) {
        global $wpdb;
        $table = Qibbs_Database::get_table( 'risk_logs' );

        $days = max( 1, absint( $days ) );
        $threshold_dt = current_datetime();
        $threshold_dt->modify( '-' . $days . ' days' );
        $threshold = $threshold_dt->format( 'Y-m-d H:i:s' );

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                $threshold
            )
        );
    }

    /**
     * 前置拦截：对封禁 IP 阻断微社区 AJAX 请求
     *
     * @return void
     */
    public function maybe_block_ajax_requests() {
        if ( ! wp_doing_ajax() ) {
            return;
        }

        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            return;
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        if ( empty( $action ) || strpos( $action, 'qibbs_' ) !== 0 ) {
            return;
        }

        if ( self::is_ip_banned() ) {
            self::log_hit( $action, 'blocked', __( 'IP 已封禁', 'qibbs-community' ) );
            wp_send_json_error( array( 'message' => __( '请求被风控系统拦截', 'qibbs-community' ) ), 403 );
        }

        if ( self::should_log_allow() ) {
            self::log_hit( $action, 'allow', '' );
        }
    }

    /**
     * 前置拦截：对封禁 IP 阻断微社区 REST 路由访问
     *
     * @param mixed           $result Response.
     * @param WP_REST_Server  $server Server.
     * @param WP_REST_Request $request Request.
     * @return mixed
     */
    public function maybe_block_rest_requests( $result, $server, $request ) {
        if ( ! $request || ! method_exists( $request, 'get_route' ) ) {
            return $result;
        }

        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            return $result;
        }

        $route = (string) $request->get_route();
        if ( strpos( $route, '/qibbs/v1/' ) !== 0 ) {
            return $result;
        }

        if ( self::is_ip_banned() ) {
            self::log_hit( 'rest:' . sanitize_key( str_replace( '/', '_', trim( $route, '/' ) ) ), 'blocked', __( 'IP 已封禁', 'qibbs-community' ) );
            return new WP_Error( 'qibbs_risk_blocked', __( '请求被风控系统拦截', 'qibbs-community' ), array( 'status' => 403 ) );
        }

        if ( self::should_log_allow() ) {
            self::log_hit( 'rest:' . sanitize_key( str_replace( '/', '_', trim( $route, '/' ) ) ), 'allow', '' );
        }

        return $result;
    }

    /**
     * 日常机会式调度：清理过期风控日志
     *
     * @return void
     */
    public function maybe_cleanup_logs() {
        $day_key = current_datetime()->format( 'Ymd' );
        $transient_key = 'qibbs_risk_cleanup_' . $day_key;
        if ( get_transient( $transient_key ) ) {
            return;
        }

        set_transient( $transient_key, '1', DAY_IN_SECONDS );

        if ( ! wp_next_scheduled( self::CLEANUP_CRON_HOOK ) ) {
            wp_schedule_single_event( time() + 5, self::CLEANUP_CRON_HOOK );
        }
    }

    /**
     * 执行异步风控日志清理任务
     *
     * @return void
     */
    public function process_cleanup_logs() {
        $days = max( 1, absint( qibbs_get_option( 'risk_log_retention_days', 30 ) ) );
        self::cleanup_logs( $days );
    }
}
