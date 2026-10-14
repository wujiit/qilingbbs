<?php
/**
 * 插件激活管理类
 * 负责插件激活、数据表创建、升级检查与默认数据初始化
 */

// 禁止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 激活执行器
 */
class Qibbs_Activator {

    /**
     * 执行插件激活初始化逻辑
     */
    public static function activate() {
        $current_version = get_option( 'qibbs_db_version', '0' );
        self::create_tables();
        self::ensure_user_stats_level_columns();
        self::set_default_options();
        self::maybe_migrate_search_index( (string) $current_version );
        self::create_pages();
        
        // 刷新重写规则缓存
        flush_rewrite_rules();

        // 记录插件激活时间戳
        update_option( 'qibbs_activated', time() );
        update_option( 'qibbs_db_version', QIBBS_DB_VERSION );

        /**
         * 插件激活完成动作钩子
         *
         * @since 1.0.0
         */
        do_action( 'qibbs_activated' );
    }

    /**
     * 执行增量数据库结构升级
     *
     * @return void
     */
    public static function maybe_upgrade() {
        $current_version = get_option( 'qibbs_db_version', '0' );
        if ( version_compare( (string) $current_version, (string) QIBBS_DB_VERSION, '>=' ) ) {
            return;
        }

        self::create_tables();
        self::ensure_user_stats_level_columns();
        self::migrate_post_discussion_status( (string) $current_version );
        self::migrate_post_types( (string) $current_version );
        self::set_default_options();

        if ( ! self::migrate_forum_subscriptions( (string) $current_version ) ) {
            return;
        }

        self::maybe_migrate_search_index( (string) $current_version );

        update_option( 'qibbs_db_version', QIBBS_DB_VERSION );
    }

    /**
     * 创建插件所需的全部自定义数据表
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'qibbs_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 帖子主表（wp_qibbs_posts）
        $sql_posts = "CREATE TABLE {$prefix}posts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            author_id BIGINT UNSIGNED NOT NULL,
            content LONGTEXT,
            status VARCHAR(20) DEFAULT 'publish',
            post_type VARCHAR(32) DEFAULT 'discussion',
            discussion_status VARCHAR(20) DEFAULT 'discussion',
            forum_id BIGINT UNSIGNED DEFAULT 0,
            media_ids TEXT,
            like_count INT DEFAULT 0,
            comment_count INT DEFAULT 0,
            favorite_count INT DEFAULT 0,
            view_count INT DEFAULT 0,
            is_pinned TINYINT(1) DEFAULT 0,
            is_featured TINYINT(1) DEFAULT 0,
            featured_until DATETIME DEFAULT NULL,
            is_promoted TINYINT(1) DEFAULT 0,
            promoted_until DATETIME DEFAULT NULL,
            promoted_by BIGINT UNSIGNED DEFAULT 0,
            source_type VARCHAR(50) DEFAULT NULL,
            source_id BIGINT UNSIGNED DEFAULT 0,
            source_category BIGINT UNSIGNED DEFAULT 0,
            is_resolved TINYINT(1) DEFAULT 0,
            best_comment_id BIGINT UNSIGNED DEFAULT 0,
            best_answer_at DATETIME DEFAULT NULL,
            best_answer_by BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_author (author_id),
            KEY idx_status (status),
            KEY idx_post_type (post_type),
            KEY idx_discussion_status (discussion_status),
            KEY idx_best_comment (best_comment_id),
            KEY idx_forum (forum_id),
            KEY idx_created (created_at),
            KEY idx_pinned (is_pinned, created_at),
            KEY idx_featured (is_featured, featured_until),
            KEY idx_promoted (is_promoted, promoted_until),
            KEY idx_source (source_type, source_id),
            KEY idx_status_pinned_created (status, is_pinned, created_at),
            KEY idx_status_post_type_created (status, post_type, created_at),
            KEY idx_status_hot (status, comment_count, like_count, created_at),
            KEY idx_forum_status_pinned_created (forum_id, status, is_pinned, created_at),
            KEY idx_forum_status_post_type_created (forum_id, status, post_type, created_at),
            KEY idx_author_status_created (author_id, status, created_at),
            KEY idx_source_category_status_created (source_category, status, created_at)
        ) $charset_collate;";
        dbDelta( $sql_posts );

        $sql_search_index = "CREATE TABLE {$prefix}search_index (
            post_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            forum_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source_category BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) DEFAULT 'publish',
            post_type VARCHAR(32) DEFAULT 'discussion',
            discussion_status VARCHAR(20) DEFAULT 'discussion',
            is_resolved TINYINT(1) DEFAULT 0,
            is_featured TINYINT(1) DEFAULT 0,
            is_pinned TINYINT(1) DEFAULT 0,
            like_count INT DEFAULT 0,
            comment_count INT DEFAULT 0,
            view_count INT DEFAULT 0,
            featured_until DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            author_name VARCHAR(200) DEFAULT '',
            author_login VARCHAR(200) DEFAULT '',
            author_nicename VARCHAR(200) DEFAULT '',
            forum_name VARCHAR(200) DEFAULT '',
            forum_slug VARCHAR(200) DEFAULT '',
            tag_names TEXT,
            tag_slugs TEXT,
            content_plain LONGTEXT,
            search_text LONGTEXT,
            PRIMARY KEY (post_id),
            KEY idx_status_created (status, created_at),
            KEY idx_status_pinned_created (status, is_pinned, created_at),
            KEY idx_forum_status_created (forum_id, status, created_at),
            KEY idx_author_status_created (author_id, status, created_at),
            KEY idx_post_type_status_created (post_type, status, created_at),
            KEY idx_featured_status_created (is_featured, status, created_at),
            KEY idx_resolved_status_created (is_resolved, status, created_at),
            KEY idx_source_category_status_created (source_category, status, created_at),
            KEY idx_status_hot (status, is_pinned, comment_count, like_count, created_at),
            FULLTEXT KEY ft_search_text (search_text)
        ) $charset_collate;";
        dbDelta( $sql_search_index );
        self::ensure_search_index_fulltext_key();

        // 版块表（wp_qibbs_forums）
        $sql_forums = "CREATE TABLE {$prefix}forums (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL,
            slug VARCHAR(200) NOT NULL,
            description TEXT,
            parent_id BIGINT UNSIGNED DEFAULT 0,
            icon VARCHAR(255),
            cover_image VARCHAR(255),
            post_count INT DEFAULT 0,
            member_count INT DEFAULT 0,
            sort_order INT DEFAULT 0,
            is_private TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_slug (slug),
            KEY idx_parent (parent_id),
            KEY idx_sort (sort_order)
        ) $charset_collate;";
        dbDelta( $sql_forums );

        // 版块成员角色表（wp_qibbs_forum_members）
        $sql_forum_members = "CREATE TABLE {$prefix}forum_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            forum_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(50) DEFAULT 'member',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_membership (forum_id, user_id),
            KEY idx_user (user_id)
        ) $charset_collate;";
        dbDelta( $sql_forum_members );

        $sql_forum_subscriptions = "CREATE TABLE {$prefix}forum_subscriptions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            forum_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_subscription (forum_id, user_id),
            KEY idx_user (user_id),
            KEY idx_forum (forum_id)
        ) $charset_collate;";
        dbDelta( $sql_forum_subscriptions );

        // 话题标签表（wp_qibbs_tags）
        $sql_tags = "CREATE TABLE {$prefix}tags (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200) NOT NULL,
            slug VARCHAR(200) NOT NULL,
            post_count INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_slug (slug),
            KEY idx_name (name(191))
        ) $charset_collate;";
        dbDelta( $sql_tags );

        // 帖子标签关联表（wp_qibbs_post_tags）
        $sql_post_tags = "CREATE TABLE {$prefix}post_tags (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            tag_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_post_tag (post_id, tag_id),
            KEY idx_tag (tag_id),
            KEY idx_tag_post (tag_id, post_id)
        ) $charset_collate;";
        dbDelta( $sql_post_tags );

        // 帖子点赞记录表（wp_qibbs_likes）
        $sql_likes = "CREATE TABLE {$prefix}likes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_like (user_id, post_id),
            KEY idx_post (post_id),
            KEY idx_user (user_id),
            KEY idx_user_created_post (user_id, created_at, post_id)
        ) $charset_collate;";
        dbDelta( $sql_likes );

        // 评论点赞记录表（wp_qibbs_comment_likes）
        $sql_comment_likes = "CREATE TABLE {$prefix}comment_likes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            comment_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_comment_like (user_id, comment_id),
            KEY idx_comment (comment_id),
            KEY idx_user (user_id),
            KEY idx_user_created_comment (user_id, created_at, comment_id)
        ) $charset_collate;";
        dbDelta( $sql_comment_likes );

        // 帖子收藏记录表（wp_qibbs_favorites）
        $sql_favorites = "CREATE TABLE {$prefix}favorites (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_favorite (user_id, post_id),
            KEY idx_user (user_id),
            KEY idx_post (post_id),
            KEY idx_user_created_post (user_id, created_at, post_id)
        ) $charset_collate;";
        dbDelta( $sql_favorites );

        // 评论与二级回复表（wp_qibbs_comments）
        $sql_comments = "CREATE TABLE {$prefix}comments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED DEFAULT 0,
            content TEXT NOT NULL,
            like_count INT DEFAULT 0,
            is_quality_pinned TINYINT(1) DEFAULT 0,
            quality_pinned_at DATETIME DEFAULT NULL,
            quality_pinned_by BIGINT UNSIGNED DEFAULT 0,
            status VARCHAR(20) DEFAULT 'approved',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_post (post_id),
            KEY idx_user (user_id),
            KEY idx_parent (parent_id),
            KEY idx_status (status),
            KEY idx_post_parent_status_created (post_id, parent_id, status, created_at),
            KEY idx_parent_status_created (parent_id, status, created_at),
            KEY idx_post_parent_status_quality_created (post_id, parent_id, status, is_quality_pinned, created_at),
            KEY idx_post_best_sort (post_id, status, parent_id, is_quality_pinned, quality_pinned_at, created_at),
            KEY idx_post_hot_sort (post_id, parent_id, status, like_count, created_at)
        ) $charset_collate;";
        dbDelta( $sql_comments );

        // 用户关注关系表（wp_qibbs_follows）
        $sql_follows = "CREATE TABLE {$prefix}follows (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            follower_id BIGINT UNSIGNED NOT NULL,
            following_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_follow (follower_id, following_id),
            KEY idx_follower (follower_id),
            KEY idx_following (following_id),
            KEY idx_follower_created (follower_id, created_at),
            KEY idx_following_created (following_id, created_at)
        ) $charset_collate;";
        dbDelta( $sql_follows );

        // 站内私信表（wp_qibbs_messages）
        $sql_messages = "CREATE TABLE {$prefix}messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sender_id BIGINT UNSIGNED NOT NULL,
            receiver_id BIGINT UNSIGNED NOT NULL,
            content TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_sender (sender_id),
            KEY idx_receiver (receiver_id),
            KEY idx_conversation (sender_id, receiver_id),
            KEY idx_unread (receiver_id, is_read),
            KEY idx_sender_receiver_created (sender_id, receiver_id, created_at),
            KEY idx_receiver_sender_created (receiver_id, sender_id, created_at),
            KEY idx_receiver_read_sender (receiver_id, is_read, sender_id, created_at)
        ) $charset_collate;";
        dbDelta( $sql_messages );

        // 站内消息通知表（wp_qibbs_notifications）
        $sql_notifications = "CREATE TABLE {$prefix}notifications (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(50) NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            object_type VARCHAR(50) DEFAULT 'post',
            object_id BIGINT UNSIGNED DEFAULT 0,
            content TEXT,
            is_read TINYINT(1) DEFAULT 0,
            dedupe_key VARCHAR(64) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dedupe_key (dedupe_key),
            KEY idx_user (user_id),
            KEY idx_unread (user_id, is_read),
            KEY idx_user_unread_created (user_id, is_read, created_at),
            KEY idx_user_created (user_id, created_at),
            KEY idx_user_type_object (user_id, type, object_type, object_id),
            KEY idx_type_object_user (type, object_type, object_id, user_id),
            KEY idx_type (type),
            KEY idx_created (created_at)
        ) $charset_collate;";
        dbDelta( $sql_notifications );

        // 用户扩展属性表（wp_qibbs_user_meta）
        $sql_user_meta = "CREATE TABLE {$prefix}user_meta (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(255) NOT NULL,
            meta_value LONGTEXT,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_key (meta_key(191))
        ) $charset_collate;";
        dbDelta( $sql_user_meta );

        // 用户统计计数缓存表（wp_qibbs_user_stats）
        $sql_user_stats = "CREATE TABLE {$prefix}user_stats (
            user_id BIGINT UNSIGNED NOT NULL,
            post_count INT DEFAULT 0,
            follower_count INT DEFAULT 0,
            following_count INT DEFAULT 0,
            like_count INT DEFAULT 0,
            exp INT UNSIGNED DEFAULT 0,
            level TINYINT UNSIGNED DEFAULT 1,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id)
        ) $charset_collate;";
        dbDelta( $sql_user_stats );

        // 媒体附件表（wp_qibbs_media）
        $sql_media = "CREATE TABLE {$prefix}media (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            post_id BIGINT UNSIGNED DEFAULT 0,
            file_path VARCHAR(255) NOT NULL,
            file_url VARCHAR(255) NOT NULL,
            file_type VARCHAR(50) NOT NULL,
            file_size BIGINT UNSIGNED DEFAULT 0,
            mime_type VARCHAR(100),
            width INT DEFAULT 0,
            height INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_post (post_id)
        ) $charset_collate;";
        dbDelta( $sql_media );

        // 用户黑名单限制表（wp_qibbs_blacklist）
        $sql_blacklist = "CREATE TABLE {$prefix}blacklist (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            reason TEXT,
            admin_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_user (user_id),
            KEY idx_admin (admin_id)
        ) $charset_collate;";
        dbDelta( $sql_blacklist );

        // 内容举报记录表（wp_qibbs_reports）
        $sql_reports = "CREATE TABLE {$prefix}reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            reporter_id BIGINT UNSIGNED NOT NULL,
            reason TEXT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            admin_id BIGINT UNSIGNED DEFAULT 0,
            admin_note VARCHAR(255) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            handled_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_post_reporter (post_id, reporter_id),
            KEY idx_post_status (post_id, status),
            KEY idx_reporter (reporter_id),
            KEY idx_status_created (status, created_at),
            KEY idx_handled (handled_at)
        ) $charset_collate;";
        dbDelta( $sql_reports );

        // 工单分类配置表（wp_qibbs_ticket_categories）
        $sql_ticket_categories = "CREATE TABLE {$prefix}ticket_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            description TEXT,
            sort_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            sla_first_response_hours INT UNSIGNED DEFAULT 4,
            sla_resolution_hours INT UNSIGNED DEFAULT 48,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_slug (slug),
            KEY idx_sort (sort_order),
            KEY idx_active (is_active),
            KEY idx_sla_response (sla_first_response_hours),
            KEY idx_sla_resolution (sla_resolution_hours)
        ) $charset_collate;";
        dbDelta( $sql_ticket_categories );

        // 工单主记录表（wp_qibbs_tickets）
        $sql_tickets = "CREATE TABLE {$prefix}tickets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED DEFAULT 0,
            subject VARCHAR(255) NOT NULL,
            content LONGTEXT,
            status VARCHAR(20) DEFAULT 'pending',
            priority VARCHAR(20) DEFAULT 'normal',
            allow_publish TINYINT(1) DEFAULT 0,
            attachments TEXT,
            assignee_id BIGINT UNSIGNED DEFAULT 0,
            first_response_deadline DATETIME DEFAULT NULL,
            first_response_at DATETIME DEFAULT NULL,
            resolution_deadline DATETIME DEFAULT NULL,
            resolved_at DATETIME DEFAULT NULL,
            is_published TINYINT(1) DEFAULT 0,
            published_post_id BIGINT UNSIGNED DEFAULT 0,
            published_forum_id BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_category (category_id),
            KEY idx_status (status),
            KEY idx_priority (priority),
            KEY idx_assignee (assignee_id),
            KEY idx_published (is_published),
            KEY idx_created (created_at),
            KEY idx_first_response_deadline (first_response_deadline),
            KEY idx_resolution_deadline (resolution_deadline),
            KEY idx_assignee_status_updated (assignee_id, status, updated_at)
        ) $charset_collate;";
        dbDelta( $sql_tickets );

        // 工单沟通流回复表（wp_qibbs_ticket_replies）
        $sql_ticket_replies = "CREATE TABLE {$prefix}ticket_replies (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            content LONGTEXT,
            is_admin_reply TINYINT(1) DEFAULT 0,
            is_internal TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ticket (ticket_id),
            KEY idx_user (user_id),
            KEY idx_admin (is_admin_reply),
            KEY idx_internal (is_internal)
        ) $charset_collate;";
        dbDelta( $sql_ticket_replies );

        // 安全风控日志表（wp_qibbs_risk_logs）
        $sql_risk_logs = "CREATE TABLE {$prefix}risk_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            action VARCHAR(120) NOT NULL,
            endpoint VARCHAR(255) DEFAULT '',
            status VARCHAR(20) DEFAULT 'allow',
            reason VARCHAR(255) DEFAULT '',
            meta LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ip (ip),
            KEY idx_user (user_id),
            KEY idx_action (action),
            KEY idx_status (status),
            KEY idx_created (created_at)
        ) $charset_collate;";
        dbDelta( $sql_risk_logs );

        // 封禁 IP 记录表（wp_qibbs_ip_bans）
        $sql_ip_bans = "CREATE TABLE {$prefix}ip_bans (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip VARCHAR(45) NOT NULL,
            reason VARCHAR(255) DEFAULT '',
            created_by BIGINT UNSIGNED DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ip_active (ip, is_active),
            KEY idx_expires (expires_at),
            KEY idx_created (created_at)
        ) $charset_collate;";
        dbDelta( $sql_ip_bans );

        // 内容审核操作日志表（wp_qibbs_moderation_logs）
        $sql_moderation_logs = "CREATE TABLE {$prefix}moderation_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(20) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            target_user_id BIGINT UNSIGNED DEFAULT 0,
            admin_id BIGINT UNSIGNED DEFAULT 0,
            action VARCHAR(20) NOT NULL,
            reason TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_object (object_type, object_id),
            KEY idx_target_user (target_user_id),
            KEY idx_admin (admin_id),
            KEY idx_action (action),
            KEY idx_created (created_at)
        ) $charset_collate;";
        dbDelta( $sql_moderation_logs );

        // 社区会员身份与 UID 映射主表（wp_qibbs_members）
        $sql_members = "CREATE TABLE {$prefix}members (
            user_id BIGINT UNSIGNED NOT NULL,
            default_cuid BIGINT UNSIGNED NOT NULL,
            active_cuid BIGINT UNSIGNED NOT NULL,
            balance DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            UNIQUE KEY unique_default_cuid (default_cuid),
            KEY idx_active_cuid (active_cuid)
        ) $charset_collate;";
        dbDelta( $sql_members );

        // 社区靓号资产表（wp_qibbs_vanity_uids）
        $sql_vanity_uids = "CREATE TABLE {$prefix}vanity_uids (
            cuid BIGINT UNSIGNED NOT NULL,
            owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            grade TINYINT UNSIGNED NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'in_pool',
            sale_price INT UNSIGNED NOT NULL DEFAULT 0,
            price_cash DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            c2c_price_cash DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            c2c_price_points INT UNSIGNED NOT NULL DEFAULT 0,
            c2c_listed_at DATETIME DEFAULT NULL,
            c2c_seller_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            obtained_at DATETIME DEFAULT NULL,
            transfer_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (cuid),
            KEY idx_owner_status (owner_id, status),
            KEY idx_grade_status (grade, status),
            KEY idx_c2c_seller (c2c_seller_id, status)
        ) $charset_collate;";
        dbDelta( $sql_vanity_uids );

        // 社区原生交易订单表（wp_qibbs_orders）
        $sql_orders = "CREATE TABLE {$prefix}orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_sn VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            item_type VARCHAR(32) NOT NULL DEFAULT 'vanity_uid',
            item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            item_name VARCHAR(255) NOT NULL DEFAULT '',
            amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            payment_method VARCHAR(32) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            trade_no VARCHAR(128) NOT NULL DEFAULT '',
            client_ip VARCHAR(45) NOT NULL DEFAULT '',
            paid_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_order_sn (order_sn),
            KEY idx_user_status (user_id, status),
            KEY idx_item (item_type, item_id),
            KEY idx_created_at (created_at)
        ) $charset_collate;";
        dbDelta( $sql_orders );

        // 社区 C2C 寄售交易与系统回收流水表（wp_qibbs_c2c_trades）
        $sql_c2c_trades = "CREATE TABLE {$prefix}c2c_trades (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            trade_sn VARCHAR(64) NOT NULL,
            order_sn VARCHAR(64) NOT NULL DEFAULT '',
            cuid BIGINT UNSIGNED NOT NULL,
            seller_id BIGINT UNSIGNED NOT NULL,
            buyer_id BIGINT UNSIGNED NOT NULL,
            payment_type VARCHAR(20) NOT NULL DEFAULT 'cash',
            amount_cash DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            amount_points INT UNSIGNED NOT NULL DEFAULT 0,
            fee_rate DECIMAL(5,2) NOT NULL DEFAULT '0.00',
            platform_fee_cash DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            platform_fee_points INT UNSIGNED NOT NULL DEFAULT 0,
            seller_earnings_cash DECIMAL(10,2) NOT NULL DEFAULT '0.00',
            seller_earnings_points INT UNSIGNED NOT NULL DEFAULT 0,
            trade_type VARCHAR(20) NOT NULL DEFAULT 'c2c',
            status VARCHAR(20) NOT NULL DEFAULT 'completed',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_trade_sn (trade_sn),
            KEY idx_cuid (cuid),
            KEY idx_seller (seller_id),
            KEY idx_buyer (buyer_id),
            KEY idx_trade_type (trade_type),
            KEY idx_created_at (created_at)
        ) $charset_collate;";
        dbDelta( $sql_c2c_trades );

        // 悬赏问答主表（wp_qibbs_bounties）
        $sql_bounties = "CREATE TABLE {$prefix}bounties (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NOT NULL,
            amount INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            winner_user_id BIGINT UNSIGNED DEFAULT 0,
            winner_comment_id BIGINT UNSIGNED DEFAULT 0,
            arbitrated_by BIGINT UNSIGNED DEFAULT 0,
            decision_deadline DATETIME DEFAULT NULL,
            awarded_at DATETIME DEFAULT NULL,
            refunded_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_post_id (post_id),
            KEY idx_author (author_id),
            KEY idx_status (status),
            KEY idx_deadline_status (decision_deadline, status)
        ) $charset_collate;";
        dbDelta( $sql_bounties );

        // 社区红包主表（wp_qibbs_redpackets）
        $sql_redpackets = "CREATE TABLE {$prefix}redpackets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            author_id BIGINT UNSIGNED NOT NULL,
            total_amount INT UNSIGNED NOT NULL DEFAULT 0,
            total_num INT UNSIGNED NOT NULL DEFAULT 0,
            remaining_amount INT UNSIGNED NOT NULL DEFAULT 0,
            remaining_num INT UNSIGNED NOT NULL DEFAULT 0,
            draw_type VARCHAR(20) NOT NULL DEFAULT 'instant',
            alloc_type VARCHAR(20) NOT NULL DEFAULT 'random',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            expires_at DATETIME DEFAULT NULL,
            refunded_at DATETIME DEFAULT NULL,
            refunded_amount INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_post_id (post_id),
            KEY idx_author (author_id),
            KEY idx_status (status),
            KEY idx_expires_status (expires_at, status)
        ) $charset_collate;";
        dbDelta( $sql_redpackets );

        // 社区红包领取记录明细表（wp_qibbs_redpacket_logs）
        $sql_redpacket_logs = "CREATE TABLE {$prefix}redpacket_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            redpacket_id BIGINT UNSIGNED NOT NULL,
            post_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            comment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            amount INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_packet_user (redpacket_id, user_id),
            KEY idx_post (post_id),
            KEY idx_user (user_id)
        ) $charset_collate;";
        dbDelta( $sql_redpacket_logs );

        // 系统道具定义表（wp_qibbs_props）
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

        // 用户已购道具背包表（wp_qibbs_user_props）
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

    /**
     * 初始化插件默认全局配置
     */
    private static function set_default_options() {
        $default_options = array(
            // 布局排版配置
            'layout_mode'           => 'two-column',
            'posts_per_page'        => 20,
            'infinite_scroll'       => true,
            
            // 道具大厅配置
            'enable_prop_mall'            => true,
            'prop_max_promoted_per_forum' => 5,
            
            // 悬赏与红包互动配置
            'enable_bounty_post'            => true,
            'bounty_min_amount'             => 5,
            'bounty_max_amount'             => 1000,
            'bounty_expire_days'            => 7,
            'bounty_decision_days'          => 7,
            'bounty_auto_refund_on_zero_comments' => true,
            'bounty_zero_replies_auto_refund' => true,
            'bounty_require_admin_arbitration' => true,
            'bounty_min_level_to_post'      => 1,
            'bounty_min_level_to_reply'     => 1,
            'enable_redpacket_post'         => true,
            'redpacket_min_total_amount'    => 10,
            'redpacket_max_total_amount'    => 10000,
            'redpacket_min_count'           => 5,
            'redpacket_max_count'           => 100,
            'redpacket_min_single_amount'   => 1,
            'redpacket_min_per_packet'      => 1,
            'redpacket_expire_hours'        => 24,
            'redpacket_expiry_hours'        => 24,
            'redpacket_allow_author_claim'  => false,
            'redpacket_min_level_to_claim'  => 1,
            
            // 功能模块开关
            'enable_likes'          => true,
            'enable_favorites'      => true,
            'enable_comments'       => true,
            'enable_follows'        => true,
            'enable_messages'       => true,
            'enable_notifications'  => true,
            'enable_forum_subscription_notifications' => true,
            'enable_discussion_status_system' => false,
            'discussion_status_forums' => array(),
            'enable_owner_reopen_question' => false,
            'owner_reopen_max_days' => 30,
            'enable_points_integration' => false,
            'points_integration_allow_staff' => false,
            'points_reward_post_publish_enabled' => true,
            'points_reward_post_publish_amount' => 2,
            'points_reward_post_publish_daily_limit' => 3,
            'points_reward_comment_approved_enabled' => true,
            'points_reward_comment_approved_amount' => 1,
            'points_reward_comment_approved_daily_limit' => 5,
            'points_reward_best_answer_enabled' => true,
            'points_reward_best_answer_amount' => 15,
            'points_reward_best_answer_daily_limit' => 0,
            'points_reward_featured_post_enabled' => true,
            'points_reward_featured_post_amount' => 20,
            'points_reward_featured_post_daily_limit' => 0,
            'points_reward_ticket_published_enabled' => true,
            'points_reward_ticket_published_amount' => 10,
            'points_reward_ticket_published_daily_limit' => 0,
            'enable_tickets'        => false,
            'enable_vanity_mall'    => true,
            'ticket_page'           => 0,
            'enable_ticket_push_notifications' => false,
            'ticket_push_channels'  => array(),
            'ticket_push_title_prefix' => '新工单通知',
            
            // 发帖与访问权限配置
            'who_can_post'          => 'logged_in',
            'allow_guest_access'    => false,
            'guest_access_notice_title' => '当前功能仅限已登录用户访问',
            'guest_access_notice_text'  => '请先登录后继续访问社区功能。',
            'blacklist_access_mode' => 'read_only',
            'blacklist_notice_title' => '你的账号已被限制访问社区',
            'blacklist_notice_text'  => '如有疑问请联系管理员。',
            'enable_moderation'     => false,
            'auto_approve_posts'    => true,
            
            // 行为频率限流配置
            'post_rate_limit_minute'=> 2,  // 每分钟发帖上限
            'post_rate_limit'       => 5,  // 每小时发帖上限
            'comment_rate_limit_minute' => 6, // 每分钟评论上限
            'comment_rate_limit'    => 20, // 每小时评论上限
            'like_rate_limit'       => 100, // 每小时点赞上限
            'ticket_rate_limit_minute' => 1, // 每分钟工单提交上限
            'ticket_rate_limit'     => 3, // 每小时工单提交上限
            'ticket_block_if_open'  => false,
            
            // 媒体附件配置
            'max_image_size'        => 5,  // MB
            'max_video_size'        => 50, // MB
            'allowed_image_types'   => array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ),
            'allowed_video_types'   => array( 'mp4', 'webm' ),
            'max_images_per_post'   => 9,
            
            // 高级功能配置
            'enable_mentions'       => true,
            'mention_suggest_limit' => 8,
            'enable_hashtags'       => true,
            'enable_emoji'          => true,
            'enable_draft_autosave' => true,
            'enable_reporting'      => true,
            'report_auto_hide_threshold' => 3,
            'hot_default_window'    => 7,
            'max_post_length'       => 5000,
            
            // 缓存机制配置
            'enable_cache'          => true,
            'cache_duration'        => 3600, // 1 hour

            // 动态流及版块缓存层
            'hot_feed_cache_ttl'    => 120,
            'forum_count_cache_ttl' => 300,

            // 安全风控默认配置
            'risk_enable_ip_limit'      => true,
            'risk_log_mode'             => 'blocked',
            'risk_enable_auto_ban'      => true,
            'risk_auto_ban_threshold'   => 12,
            'risk_auto_ban_window'      => 10,
            'risk_auto_ban_duration'    => 60,
            'risk_log_retention_days'   => 30,
            'risk_enable_content_rules' => false,
            'risk_content_keywords'     => '',
            'risk_content_frequency_limit' => 4,
            'risk_content_frequency_window' => 10,

            // 媒体上传与处理默认参数（由主题与对象存储插件原生接管处理，社区直通媒体库）
            'sync_to_wp_media_library'  => true,

            // 社区 UID 与号段规则默认配置
            'uid_free_start'            => 10000,
            'uid_reserved_ranges'       => '1-100',
            'uid_reserved_singles'      => '10086,110,119,120,999',
            'uid_vanity_ranges'         => '',

            // 社区内置支付网关默认配置
            'enable_builtin_payments'   => true,
            'payment_currency_symbol'   => '¥',
            'epay_enabled'              => false,
            'epay_api_url'              => '',
            'epay_pid'                  => '',
            'epay_key'                  => '',
            'epay_default_type'         => 'alipay',
            'alipay_enabled'            => false,
            'alipay_app_id'             => '',
            'alipay_private_key'        => '',
            'alipay_public_key'         => '',
            'alipay_f2fpay'             => true,
            'alipay_h5'                 => false,
            'wechat_enabled'            => false,
            'wechat_appid'              => '',
            'wechat_mchid'              => '',
            'wechat_key'                => '',
            'order_expire_minutes'      => 15,

            // C2C 寄售交易与系统回收默认配置
            'enable_c2c_trading'        => true,
            'c2c_fee_rate'              => 5.0,
            'enable_uid_recycle'        => true,
            'uid_recycle_rebate_rate'   => 50.0,
            'uid_recycle_payout_type'   => 'balance',
        );

        $options = get_option( 'qibbs_options', null );
        if ( ! is_array( $options ) ) {
            add_option( 'qibbs_options', $default_options );
            return;
        }

        // 增量补充新增默认配置（保留站长已有配置）
        $merged = wp_parse_args( $options, $default_options );
        if ( $merged !== $options ) {
            update_option( 'qibbs_options', $merged );
        }
    }

    /**
     * 历史数据兼容：补充帖子讨论/结贴状态
     *
     * @param string $current_version Current DB version before upgrade.
     * @return void
     */
    private static function migrate_post_discussion_status( $current_version ) {
        if ( version_compare( $current_version, '1.2.2', '>=' ) ) {
            return;
        }

        global $wpdb;
        $posts_table = Qibbs_Database::posts();
        $has_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$posts_table} LIKE %s", 'discussion_status' ) );
        if ( empty( $has_column ) ) {
            return;
        }

        $wpdb->query(
            "UPDATE {$posts_table}
             SET discussion_status = 'resolved'
             WHERE is_resolved = 1
               AND (discussion_status IS NULL OR discussion_status = '' OR discussion_status = 'discussion')"
        );
    }

    /**
     * 历史数据兼容：回填帖子实体类型
     *
     * 将历史问答类内容归类为 question，其余归类为 discussion 讨论类型
     *
     * @param string $current_version Current DB version before upgrade.
     * @return void
     */
    private static function migrate_post_types( $current_version ) {
        if ( version_compare( $current_version, '1.3.2', '>=' ) ) {
            return;
        }

        global $wpdb;
        $posts_table = Qibbs_Database::posts();
        $has_column  = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$posts_table} LIKE %s", 'post_type' ) );
        if ( empty( $has_column ) ) {
            return;
        }

        $wpdb->query(
            "UPDATE {$posts_table}
             SET post_type = 'discussion'
             WHERE post_type IS NULL OR post_type = ''"
        );

        $conditions = array(
            'is_resolved = 1',
            'best_comment_id > 0',
            "discussion_status IN ('resolved', 'closed')",
            "source_type = 'ticket'",
        );

        $question_forums = qibbs_get_discussion_status_forum_ids();
        if ( ! empty( $question_forums ) ) {
            $forum_ids = implode( ',', array_map( 'absint', $question_forums ) );
            $conditions[] = "forum_id IN ({$forum_ids})";
        }

        $wpdb->query(
            "UPDATE {$posts_table}
             SET post_type = 'question'
             WHERE (post_type IS NULL OR post_type = '' OR post_type = 'discussion')
               AND (" . implode( ' OR ', $conditions ) . ')'
        );
    }

    /**
     * 将旧版成员表中记录的订阅状态平滑迁移至独立订阅表，并将成员角色标准化
     *
     * @param string $current_version 升级前的当前数据库版本号
     * @return bool
     */
    private static function migrate_forum_subscriptions( $current_version ) {
        if ( version_compare( $current_version, '1.3.0', '>=' ) ) {
            return true;
        }

        global $wpdb;

        $members_table = Qibbs_Database::forum_members();
        $subscriptions_table = Qibbs_Database::forum_subscriptions();

        $members_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $members_table ) );
        $subscriptions_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $subscriptions_table ) );
        if ( $members_exists !== $members_table || $subscriptions_exists !== $subscriptions_table ) {
            return false;
        }

        $copied = $wpdb->query(
            "INSERT IGNORE INTO {$subscriptions_table} (forum_id, user_id, created_at)
             SELECT forum_id, user_id, created_at
             FROM {$members_table}
             WHERE forum_id > 0 AND user_id > 0"
        );

        if ( false === $copied ) {
            return false;
        }

        $normalized = $wpdb->query(
            "UPDATE {$members_table}
             SET role = 'member'
             WHERE role = 'subscriber'"
        );

        if ( false === $normalized ) {
            return false;
        }

        return true;
    }

    /**
     * 标记搜索索引待手动重建状态
     *
     * @return void
     */
    private static function mark_search_index_rebuild_needed() {
        update_option( 'qibbs_search_index_rebuild_pending', 1, false );
        update_option( 'qibbs_search_index_rebuild_cursor', 0, false );
    }

    /**
     * 重置历史索引重建标记
     *
     * @return void
     */
    private static function clear_search_index_rebuild_state() {
        delete_option( 'qibbs_search_index_rebuild_pending' );
        delete_option( 'qibbs_search_index_rebuild_cursor' );
    }

    /**
     * 检查帖子表中是否已有存量内容
     *
     * @return bool
     */
    private static function has_existing_posts() {
        global $wpdb;

        $posts_table = Qibbs_Database::posts();
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $posts_table ) );
        if ( $table_exists !== $posts_table ) {
            return false;
        }

        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts_table} WHERE %d = %d", 1, 1 ) ) > 0;
    }

    /**
     * 检查旧版本是否需触发全文搜索索引重建
     *
     * @param string $current_version Current DB version before upgrade.
     * @return void
     */
    private static function maybe_migrate_search_index( $current_version ) {
        if ( '0' === (string) $current_version || version_compare( $current_version, '1.3.3', '>=' ) ) {
            self::clear_search_index_rebuild_state();
            return;
        }

        if ( self::has_existing_posts() ) {
            self::mark_search_index_rebuild_needed();
            return;
        }

        self::clear_search_index_rebuild_state();
    }

    /**
     * 确保搜索索引表具备必要的 FULLTEXT 全文检索索引键
     *
     * @return void
     */
    private static function ensure_search_index_fulltext_key() {
        global $wpdb;

        $table = Qibbs_Database::search_index();
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return;
        }

        $has_index = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW INDEX FROM {$table} WHERE Key_name = %s",
                'ft_search_text'
            )
        );

        if ( ! empty( $has_index ) ) {
            return;
        }

        $wpdb->query( "ALTER TABLE {$table} ADD FULLTEXT KEY ft_search_text (search_text)" );
    }

    /**
     * 自动创建微社区前台核心页面
     */
    private static function create_pages() {
        self::ensure_shortcode_page( 'qibbs_community_page_id', 'qibbs_community', __( '社区', 'qibbs-community' ), 'community' );
        self::ensure_shortcode_page( 'qibbs_messages_page_id', 'qibbs_messages', __( '私信', 'qibbs-community' ), 'messages' );
        self::ensure_shortcode_page( 'qibbs_notifications_page_id', 'qibbs_notifications', __( '通知', 'qibbs-community' ), 'notifications' );
    }

    /**
     * 确保指定短代码对应的前台页面存在
     *
     * @param string $option_name Option storing the page ID.
     * @param string $shortcode_tag Shortcode tag without brackets.
     * @param string $title Page title for new pages.
     * @param string $slug Page slug for new pages.
     * @return int
     */
    private static function ensure_shortcode_page( $option_name, $shortcode_tag, $title, $slug ) {
        $page_id = self::get_valid_shortcode_page_id( absint( get_option( $option_name, 0 ) ), $shortcode_tag );
        if ( $page_id <= 0 ) {
            $page_id = self::find_page_id_by_shortcode( $shortcode_tag );
        }

        if ( $page_id <= 0 ) {
            $page_id = wp_insert_post(
                array(
                    'post_title'   => $title,
                    'post_content' => '[' . $shortcode_tag . ']',
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                    'post_name'    => $slug,
                ),
                true
            );
        }

        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( $option_name, absint( $page_id ) );
            return absint( $page_id );
        }

        return 0;
    }

    /**
     * 验证已配置的短代码页面有效性
     *
     * @param int    $page_id Page ID.
     * @param string $shortcode_tag Shortcode tag without brackets.
     * @return int
     */
    private static function get_valid_shortcode_page_id( $page_id, $shortcode_tag ) {
        $page_id = absint( $page_id );
        if ( $page_id <= 0 ) {
            return 0;
        }

        $post = get_post( $page_id );
        if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || in_array( (string) $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
            return 0;
        }

        return self::content_has_shortcode( (string) $post->post_content, $shortcode_tag ) ? $page_id : 0;
    }

    /**
     * 根据短代码检索已有的前台页面
     *
     * @param string $shortcode_tag Shortcode tag without brackets.
     * @return int
     */
    private static function find_page_id_by_shortcode( $shortcode_tag ) {
        global $wpdb;

        $shortcode_tag = trim( (string) $shortcode_tag );
        if ( '' === $shortcode_tag || ! preg_match( '/^[A-Za-z0-9_-]+$/', $shortcode_tag ) ) {
            return 0;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content
                 FROM {$wpdb->posts}
                 WHERE post_type = %s
                   AND post_status NOT IN ('trash', 'auto-draft', 'inherit')
                   AND post_content LIKE %s
                 ORDER BY ID ASC",
                'page',
                '%' . $wpdb->esc_like( '[' . $shortcode_tag ) . '%'
            ),
            ARRAY_A
        );

        foreach ( (array) $rows as $row ) {
            if ( self::content_has_shortcode( (string) ( $row['post_content'] ?? '' ), $shortcode_tag ) ) {
                return absint( $row['ID'] ?? 0 );
            }
        }

        return 0;
    }

    /**
     * 检测页面内容中是否包含指定短代码
     *
     * @param string $content Content.
     * @param string $shortcode_tag Shortcode tag without brackets.
     * @return bool
     */
    private static function content_has_shortcode( $content, $shortcode_tag ) {
        $content       = (string) $content;
        $shortcode_tag = trim( (string) $shortcode_tag );
        if ( '' === $content || '' === $shortcode_tag || ! preg_match( '/^[A-Za-z0-9_-]+$/', $shortcode_tag ) ) {
            return false;
        }

        if ( function_exists( 'get_shortcode_regex' ) ) {
            $pattern = get_shortcode_regex( array( $shortcode_tag ) );
            if ( preg_match( '/' . $pattern . '/s', $content, $matches ) ) {
                return isset( $matches[2] ) && $matches[2] === $shortcode_tag;
            }
        }

        return (bool) preg_match( '/\[(?!\/)' . preg_quote( $shortcode_tag, '/' ) . '(?:[\s\]\/])/', $content );
    }

    /**
     * 确保 wp_qibbs_user_stats 表包含 exp 与 level 字段
     *
     * @return void
     */
    public static function ensure_user_stats_level_columns() {
        global $wpdb;
        $table = Qibbs_Database::user_stats();
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return;
        }

        $row_exp = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'exp'" );
        if ( ! $row_exp ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN exp INT UNSIGNED DEFAULT 0 AFTER like_count" );
        }

        $row_level = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'level'" );
        if ( ! $row_level ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN level TINYINT UNSIGNED DEFAULT 1 AFTER exp" );
        }
    }
}
