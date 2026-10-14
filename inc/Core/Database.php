<?php
/**
 * 数据库工具类
 * 提供数据表名缓存获取、Schema 结构维护及索引同步工具
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 数据库结构与工具支持
 */
class Qibbs_Database {

    /**
     * 数据表名缓存列表
     *
     * @var array
     */
    private static $tables = array();

    /**
     * 获取带前缀的完整数据表名
     *
     * @param string $table Table name without prefix.
     * @return string Full table name.
     */
    public static function get_table( $table ) {
        if ( ! isset( self::$tables[ $table ] ) ) {
            global $wpdb;
            self::$tables[ $table ] = $wpdb->prefix . 'qibbs_' . $table;
        }
        return self::$tables[ $table ];
    }

    /**
     * 获取帖子表名
     *
     * @return string
     */
    public static function posts() {
        return self::get_table( 'posts' );
    }

    /**
     * 获取搜索索引表名
     *
     * @return string
     */
    public static function search_index() {
        return self::get_table( 'search_index' );
    }

    /**
     * 获取版块表名
     *
     * @return string
     */
    public static function forums() {
        return self::get_table( 'forums' );
    }

    /**
     * 获取版块成员表名
     *
     * @return string
     */
    public static function forum_members() {
        return self::get_table( 'forum_members' );
    }

    /**
     * 获取版块订阅表名
     *
     * @return string
     */
    public static function forum_subscriptions() {
        return self::get_table( 'forum_subscriptions' );
    }

    /**
     * 获取话题标签表名
     *
     * @return string
     */
    public static function tags() {
        return self::get_table( 'tags' );
    }

    /**
     * 获取帖子标签关联表名
     *
     * @return string
     */
    public static function post_tags() {
        return self::get_table( 'post_tags' );
    }

    /**
     * 获取点赞记录表名
     *
     * @return string
     */
    public static function likes() {
        return self::get_table( 'likes' );
    }

    /**
     * 获取收藏记录表名
     *
     * @return string
     */
    public static function favorites() {
        return self::get_table( 'favorites' );
    }

    /**
     * 获取评论表名
     *
     * @return string
     */
    public static function comments() {
        return self::get_table( 'comments' );
    }

    /**
     * 获取评论点赞表名
     *
     * @return string
     */
    public static function comment_likes() {
        return self::get_table( 'comment_likes' );
    }

    /**
     * 获取关注关系表名
     *
     * @return string
     */
    public static function follows() {
        return self::get_table( 'follows' );
    }

    /**
     * 获取私信消息表名
     *
     * @return string
     */
    public static function messages() {
        return self::get_table( 'messages' );
    }

    /**
     * 获取站内通知表名
     *
     * @return string
     */
    public static function notifications() {
        return self::get_table( 'notifications' );
    }

    /**
     * 获取用户扩展元数据表名
     *
     * @return string
     */
    public static function user_meta() {
        return self::get_table( 'user_meta' );
    }

    /**
     * 获取用户统计缓存表名
     *
     * @return string
     */
    public static function user_stats() {
        return self::get_table( 'user_stats' );
    }

    /**
     * 获取媒体附件表名
     *
     * @return string
     */
    public static function media() {
        return self::get_table( 'media' );
    }

    /**
     * 获取黑名单表名
     *
     * @return string
     */
    public static function blacklist() {
        return self::get_table( 'blacklist' );
    }

    /**
     * 获取内容举报表名
     *
     * @return string
     */
    public static function reports() {
        return self::get_table( 'reports' );
    }

    /**
     * 获取工单分类表名
     *
     * @return string
     */
    public static function ticket_categories() {
        return self::get_table( 'ticket_categories' );
    }

    /**
     * 获取工单主表名
     *
     * @return string
     */
    public static function tickets() {
        return self::get_table( 'tickets' );
    }

    /**
     * 获取工单回复表名
     *
     * @return string
     */
    public static function ticket_replies() {
        return self::get_table( 'ticket_replies' );
    }

    /**
     * 获取社区会员核心身份表名
     *
     * @return string
     */
    public static function members() {
        return self::get_table( 'members' );
    }

    /**
     * 获取社区靓号资产表名
     *
     * @return string
     */
    public static function vanity_uids() {
        return self::get_table( 'vanity_uids' );
    }

    /**
     * 获取社区内置交易订单表名
     *
     * @return string
     */
    public static function orders() {
        return self::get_table( 'orders' );
    }

    /**
     * 获取玩家C2C交易与官方回收流水表名
     *
     * @return string
     */
    public static function c2c_trades() {
        return self::get_table( 'c2c_trades' );
    }

    /**
     * 获取悬赏问答主表名
     *
     * @return string
     */
    public static function bounties() {
        return self::get_table( 'bounties' );
    }

    /**
     * 获取社区红包主表名
     *
     * @return string
     */
    public static function redpackets() {
        return self::get_table( 'redpackets' );
    }

    /**
     * 获取社区红包领取明细表名
     *
     * @return string
     */
    public static function redpacket_logs() {
        return self::get_table( 'redpacket_logs' );
    }

    /**
     * 获取系统道具定义表名
     *
     * @return string
     */
    public static function props() {
        return self::get_table( 'props' );
    }

    /**
     * 获取用户已购道具背包表名
     *
     * @return string
     */
    public static function user_props() {
        return self::get_table( 'user_props' );
    }

    /**
     * 批量检查数据表是否存在
     *
     * @return bool
     */
    public static function tables_exist() {
        global $wpdb;
        $table = self::posts();
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /**
     * 获取当前数据库结构版本号
     *
     * @return string
     */
    public static function get_db_version() {
        return get_option( 'qibbs_db_version', '0' );
    }

    /**
     * 执行安全数据库查询并捕获异常
     *
     * @param string $query SQL query.
     * @return mixed Query result or false on error.
     */
    public static function query( $query ) {
        global $wpdb;
        
        $result = $wpdb->query( $query );
        
        if ( $wpdb->last_error ) {
            error_log( 'Qibbs Database Error: ' . $wpdb->last_error );
            return false;
        }
        
        return $result;
    }

    /**
     * 插入单行数据记录
     *
     * @param string $table  Table name (without prefix).
     * @param array  $data   Data to insert.
     * @param array  $format Data format.
     * @return int|false Insert ID or false on failure.
     */
    public static function insert( $table, $data, $format = null ) {
        global $wpdb;
        
        $table_name = self::get_table( $table );
        $result = $wpdb->insert( $table_name, $data, $format );
        
        if ( $result === false ) {
            error_log( 'Qibbs Insert Error: ' . $wpdb->last_error );
            return false;
        }
        
        return $wpdb->insert_id;
    }

    /**
     * 执行数据更新操作
     *
     * @param string $table        Table name (without prefix).
     * @param array  $data         Data to update.
     * @param array  $where        Where conditions.
     * @param array  $format       Data format.
     * @param array  $where_format Where format.
     * @return int|false Number of rows updated or false on failure.
     */
    public static function update( $table, $data, $where, $format = null, $where_format = null ) {
        global $wpdb;
        
        $table_name = self::get_table( $table );
        $result = $wpdb->update( $table_name, $data, $where, $format, $where_format );
        
        if ( $result === false ) {
            error_log( 'Qibbs Update Error: ' . $wpdb->last_error );
            return false;
        }
        
        return $result;
    }

    /**
     * 执行数据删除操作
     *
     * @param string $table        Table name (without prefix).
     * @param array  $where        Where conditions.
     * @param array  $where_format Where format.
     * @return int|false Number of rows deleted or false on failure.
     */
    public static function delete( $table, $where, $where_format = null ) {
        global $wpdb;
        
        $table_name = self::get_table( $table );
        $result = $wpdb->delete( $table_name, $where, $where_format );
        
        if ( $result === false ) {
            error_log( 'Qibbs Delete Error: ' . $wpdb->last_error );
            return false;
        }
        
        return $result;
    }

    /**
     * 查询单行数据记录
     *
     * @param string $table Table name (without prefix).
     * @param array  $where Where conditions.
     * @return object|null
     */
    public static function get_row( $table, $where ) {
        global $wpdb;
        
        $table_name = self::get_table( $table );
        
        $conditions = array();
        $values = array();
        
        foreach ( $where as $column => $value ) {
            $conditions[] = "`$column` = %s";
            $values[] = $value;
        }
        
        $where_clause = implode( ' AND ', $conditions );
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE $where_clause LIMIT 1",
                $values
            )
        );
    }

    /**
     * 查询多行数据记录
     *
     * @param string $table   Table name (without prefix).
     * @param array  $where   Where conditions.
     * @param string $orderby Order by column.
     * @param string $order   Order direction.
     * @param int    $limit   Limit.
     * @param int    $offset  Offset.
     * @return array
     */
    public static function get_results( $table, $where = array(), $orderby = 'id', $order = 'DESC', $limit = 20, $offset = 0 ) {
        global $wpdb;
        
        $table_name = self::get_table( $table );
        
        $where_clause = '1=1';
        $values = array();
        
        if ( ! empty( $where ) ) {
            $conditions = array();
            foreach ( $where as $column => $value ) {
                $conditions[] = "`$column` = %s";
                $values[] = $value;
            }
            $where_clause = implode( ' AND ', $conditions );
        }
        
        $order = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
        $orderby = sanitize_sql_orderby( $orderby ) ?: 'id';
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY `$orderby` $order LIMIT %d OFFSET %d";
        $values[] = $limit;
        $values[] = $offset;
        
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $wpdb->prepare( $query, $values ) );
    }

    /**
     * 统计数据行数
     *
     * @param string $table Table name (without prefix).
     * @param array  $where Where conditions.
     * @return int
     */
    public static function count( $table, $where = array() ) {
        global $wpdb;
        
        $table_name = self::get_table( $table );
        
        $where_clause = '1=1';
        $values = array();
        
        if ( ! empty( $where ) ) {
            $conditions = array();
            foreach ( $where as $column => $value ) {
                $conditions[] = "`$column` = %s";
                $values[] = $value;
            }
            $where_clause = implode( ' AND ', $conditions );
        }
        
        if ( empty( $values ) ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE $where_clause AND %d = %d", 1, 1 ) );
        }
        
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE $where_clause", $values ) );
    }
}
