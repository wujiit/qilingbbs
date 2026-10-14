<?php
/**
 * 视图模板：社区基础综合设置
 * 社区基础设置面板（现代化 Tab 标签页分流架构）
 *
 * @package Qibbs_Community
 * @subpackage Admin/Views
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$search_index_table = Qibbs_Database::search_index();
$search_index_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $search_index_table ) ) === $search_index_table );
$search_index_count = $search_index_exists ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$search_index_table} WHERE 1 = %d", 1 ) ) : 0;
$search_rebuild_pending = ! empty( get_option( 'qibbs_search_index_rebuild_pending', 0 ) );
?>
<div class="wrap qibbs-admin-page">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <!-- 顶部 Tab 标签导航 -->
    <nav class="nav-tab-wrapper qibbs-settings-tabs" style="margin: 16px 0 20px;">
        <a href="#tab-general" class="nav-tab nav-tab-active" data-tab="general">
            <span class="dashicons dashicons-admin-generic" style="margin-right: 4px; vertical-align: text-top;"></span><?php esc_html_e( '常规与模块', 'qibbs-community' ); ?>
        </a>
        <a href="#tab-appearance" class="nav-tab" data-tab="appearance">
            <span class="dashicons dashicons-admin-appearance" style="margin-right: 4px; vertical-align: text-top;"></span><?php esc_html_e( '外观与布局', 'qibbs-community' ); ?>
        </a>
        <a href="#tab-permissions" class="nav-tab" data-tab="permissions">
            <span class="dashicons dashicons-shield" style="margin-right: 4px; vertical-align: text-top;"></span><?php esc_html_e( '权限与安全', 'qibbs-community' ); ?>
        </a>
        <a href="#tab-moderation" class="nav-tab" data-tab="moderation">
            <span class="dashicons dashicons-filter" style="margin-right: 4px; vertical-align: text-top;"></span><?php esc_html_e( '内容过滤', 'qibbs-community' ); ?>
        </a>
        <a href="#tab-media" class="nav-tab" data-tab="media">
            <span class="dashicons dashicons-admin-media" style="margin-right: 4px; vertical-align: text-top;"></span><?php esc_html_e( '媒体与同步', 'qibbs-community' ); ?>
        </a>
        <a href="#tab-tools" class="nav-tab" data-tab="tools">
            <span class="dashicons dashicons-admin-tools" style="margin-right: 4px; vertical-align: text-top;"></span><?php esc_html_e( '维护与工具', 'qibbs-community' ); ?>
        </a>
    </nav>

    <form method="post" action="options.php" id="qibbs-settings-form">
        <?php settings_fields( 'qibbs_settings_group' ); ?>

        <!-- Tab 1: 常规与模块 -->
        <div class="qibbs-tab-pane" id="pane-general" style="display: block;">
            <?php do_settings_sections( 'qibbs-settings-general' ); ?>
        </div>

        <!-- Tab 2: 外观与布局 -->
        <div class="qibbs-tab-pane" id="pane-appearance" style="display: none;">
            <?php do_settings_sections( 'qibbs-settings-appearance' ); ?>
        </div>

        <!-- Tab 3: 权限与安全 -->
        <div class="qibbs-tab-pane" id="pane-permissions" style="display: none;">
            <?php do_settings_sections( 'qibbs-settings-permissions' ); ?>
        </div>

        <!-- Tab 4: 内容过滤 -->
        <div class="qibbs-tab-pane" id="pane-moderation" style="display: none;">
            <?php do_settings_sections( 'qibbs-settings-moderation' ); ?>
        </div>

        <!-- Tab 5: 媒体与同步 -->
        <div class="qibbs-tab-pane" id="pane-media" style="display: none;">
            <?php do_settings_sections( 'qibbs-settings-media' ); ?>
        </div>

        <!-- Tab 6: 维护与工具 -->
        <div class="qibbs-tab-pane" id="pane-tools" style="display: none;">
            <div class="qibbs-tool-card" style="background: #fff; border: 1px solid #d8deea; border-radius: 12px; padding: 18px 24px; margin-bottom: 20px; box-shadow: 0 4px 14px rgba(15,23,42,0.04);">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-update" style="color: #1f6feb;"></span>
                    <?php esc_html_e( '前端资源缓存刷新', 'qibbs-community' ); ?>
                </h3>
                <p class="description" style="margin-bottom: 14px;"><?php esc_html_e( '刷新前端资源时间戳缓存 (CSS/JS)，强制所有用户浏览器即时加载最新样式与交互脚本。', 'qibbs-community' ); ?></p>
                <p style="margin: 0 0 10px;">
                    <button type="button" class="button button-primary" id="qibbs-refresh-cache">
                        <?php esc_html_e( '立即刷新前端资源缓存', 'qibbs-community' ); ?>
                    </button>
                    <span id="qibbs-cache-status" style="margin-left: 12px; font-weight: 500;"></span>
                </p>
                <p class="description" style="margin: 0;">
                    <?php 
                    $current_version = get_option( 'qibbs_cache_version', QIBBS_VERSION );
                    printf( esc_html__( '当前静态缓存版本号: %s', 'qibbs-community' ), '<code>' . esc_html( $current_version ) . '</code>' ); 
                    ?>
                </p>
            </div>

            <div class="qibbs-tool-card" style="background: #fff; border: 1px solid #d8deea; border-radius: 12px; padding: 18px 24px; margin-bottom: 20px; box-shadow: 0 4px 14px rgba(15,23,42,0.04);">
                <h3 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-search" style="color: #1f6feb;"></span>
                    <?php esc_html_e( '独立搜索索引维护', 'qibbs-community' ); ?>
                </h3>
                <p class="description" style="margin-bottom: 14px;"><?php esc_html_e( '手动批量重建微社区独立搜索倒排索引。发帖、评论和编辑通常会自动实时同步；若发现历史内容搜索不全，可手动重建。', 'qibbs-community' ); ?></p>
                <p style="margin: 0 0 10px;">
                    <button type="button" class="button button-secondary" id="qibbs-rebuild-search-index">
                        <?php esc_html_e( '重新构建搜索索引', 'qibbs-community' ); ?>
                    </button>
                    <span id="qibbs-search-index-status" style="margin-left: 12px; font-weight: 500;"></span>
                </p>
                <p class="description" style="margin: 0;">
                    <?php
                    printf(
                        esc_html__( '当前独立搜索索引条数: %d 条', 'qibbs-community' ),
                        (int) $search_index_count
                    );
                    ?>
                    <?php if ( $search_rebuild_pending ) : ?>
                        <br><span style="color: #d97706; display: inline-flex; align-items: center; gap: 4px; margin-top: 6px;"><span class="dashicons dashicons-warning" style="font-size: 16px; width: 16px; height: 16px;"></span><?php esc_html_e( '检测到旧数据尚未完全写入独立索引，建议点击“重新构建搜索索引”进行同步。', 'qibbs-community' ); ?></span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="qibbs-settings-submit-bar" id="qibbs-submit-bar" style="margin-top: 24px;">
            <?php submit_button( __( '保存基础设置', 'qibbs-community' ) ); ?>
        </div>
    </form>
</div>

<script>
(function() {
    // Tab 标签页切换逻辑
    var tabs = document.querySelectorAll('.qibbs-settings-tabs .nav-tab');
    var panes = document.querySelectorAll('.qibbs-tab-pane');
    var submitBar = document.getElementById('qibbs-submit-bar');

    function activateTab(tabKey) {
        if (!tabKey) tabKey = 'general';
        var matched = false;

        tabs.forEach(function(tab) {
            var key = tab.getAttribute('data-tab');
            if (key === tabKey) {
                tab.classList.add('nav-tab-active');
                matched = true;
            } else {
                tab.classList.remove('nav-tab-active');
            }
        });

        if (!matched && tabs.length > 0) {
            tabKey = tabs[0].getAttribute('data-tab');
            tabs[0].classList.add('nav-tab-active');
        }

        panes.forEach(function(pane) {
            var paneId = 'pane-' + tabKey;
            if (pane.id === paneId) {
                pane.style.display = 'block';
            } else {
                pane.style.display = 'none';
            }
        });

        if (submitBar) {
            submitBar.style.display = (tabKey === 'tools') ? 'none' : 'block';
        }

        try {
            sessionStorage.setItem('qibbs_active_settings_tab', tabKey);
        } catch (e) {}
    }

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            var tabKey = this.getAttribute('data-tab');
            activateTab(tabKey);
            if (history.replaceState) {
                history.replaceState(null, null, '#tab-' + tabKey);
            }
        });
    });

    // 初始化激活 Tab
    var initialTab = 'general';
    if (window.location.hash && window.location.hash.indexOf('#tab-') === 0) {
        initialTab = window.location.hash.replace('#tab-', '');
    } else {
        var storedTab = '';
        try {
            storedTab = sessionStorage.getItem('qibbs_active_settings_tab');
        } catch (e) {}
        if (storedTab) {
            initialTab = storedTab;
        }
    }
    activateTab(initialTab);

    // 缓存刷新按钮
    var refreshBtn = document.getElementById('qibbs-refresh-cache');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            var btn = this;
            var status = document.getElementById('qibbs-cache-status');
            btn.disabled = true;
            status.textContent = '<?php esc_html_e( '刷新中...', 'qibbs-community' ); ?>';
            status.style.color = '#64748b';

            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=qibbs_refresh_cache&nonce=<?php echo wp_create_nonce( 'qibbs_nonce' ); ?>'
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    status.textContent = '<?php esc_html_e( '缓存已刷新！', 'qibbs-community' ); ?>' + ' v' + data.data.version;
                    status.style.color = '#16a34a';
                } else {
                    status.textContent = '<?php esc_html_e( '刷新失败', 'qibbs-community' ); ?>';
                    status.style.color = '#dc2626';
                }
                btn.disabled = false;
            })
            .catch(function() {
                status.textContent = '<?php esc_html_e( '请求失败', 'qibbs-community' ); ?>';
                status.style.color = '#dc2626';
                btn.disabled = false;
            });
        });
    }

    // 搜索索引重建按钮
    var rebuildBtn = document.getElementById('qibbs-rebuild-search-index');
    if (rebuildBtn) {
        rebuildBtn.addEventListener('click', function() {
            var btn = this;
            var status = document.getElementById('qibbs-search-index-status');
            btn.disabled = true;
            status.textContent = '<?php esc_html_e( '重建中，请稍候...', 'qibbs-community' ); ?>';
            status.style.color = '#64748b';

            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=qibbs_rebuild_search_index&nonce=<?php echo wp_create_nonce( 'qibbs_nonce' ); ?>'
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    status.textContent = (data.data.message || '<?php esc_html_e( '搜索索引已重建成功！', 'qibbs-community' ); ?>');
                    status.style.color = '#16a34a';
                } else {
                    status.textContent = ((data.data && data.data.message) || '<?php esc_html_e( '重建失败', 'qibbs-community' ); ?>');
                    status.style.color = '#dc2626';
                }
                btn.disabled = false;
            })
            .catch(function() {
                status.textContent = '<?php esc_html_e( '请求失败', 'qibbs-community' ); ?>';
                status.style.color = '#dc2626';
                btn.disabled = false;
            });
        });
    }

    // 一键同步历史文章操作按钮
    var syncBtn = document.getElementById('qibbs-sync-historical-btn');
    if (syncBtn) {
        syncBtn.addEventListener('click', function() {
            var btn = this;
            var status = document.getElementById('qibbs-sync-status');
            btn.disabled = true;
            status.textContent = '<?php esc_html_e( '同步中，请稍候...', 'qibbs-community' ); ?>';
            status.style.color = '#64748b';
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=qibbs_sync_historical_articles&nonce=<?php echo wp_create_nonce( 'qibbs_nonce' ); ?>'
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    status.textContent = data.data.message;
                    status.style.color = '#16a34a';
                    if (data.data.synced > 0) {
                        btn.style.display = 'none';
                    }
                } else {
                    status.textContent = (data.data.message || '<?php esc_html_e( '同步失败', 'qibbs-community' ); ?>');
                    status.style.color = '#dc2626';
                }
                btn.disabled = false;
            })
            .catch(function() {
                status.textContent = '<?php esc_html_e( '请求失败', 'qibbs-community' ); ?>';
                status.style.color = '#dc2626';
                btn.disabled = false;
            });
        });
    }
})();
</script>

<?php
/**
 * 微社区后台设置页底部扩展动作钩子
 *
 * @since 1.1.0
 */
do_action( 'qibbs_admin_settings_page_bottom' );
?>
