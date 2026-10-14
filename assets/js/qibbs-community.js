/**
 * 启灵微社区主交互脚本
 * 负责社区动态流、发帖、互动、工单联动及全局交互
 */

(function ($) {
    'use strict';

    window.Qibbs = {
        feed: {
            type: 'global',
            filterType: null,
            filterId: null,
            filterName: null,
            hotWindow: (window.qibbsData && qibbsData.hotDefaultWindow) ? parseInt(qibbsData.hotDefaultWindow, 10) : 7,
            searchQuery: '',
            authorQuery: '',
            tagQuery: '',
            postType: 'all',
            resolved: 'all',
            sort: 'latest',
            featuredOnly: false,
            page: 1,
            loading: false,
            hasMore: true
        },
        mediaQueue: [],
        draftStorageKey: '',
        draftSaveTimer: null,
        mentionState: {
            active: false,
            query: '',
            start: 0,
            end: 0,
            index: 0,
            users: []
        },
        mentionSearchTimer: null,
        searchSuggestTimer: null,

        /**
         * 触发自定义事件，供第三方或扩展模块监听
         * @param {string} eventName - 事件名称（如 'post:created'）
         * @param {object} detail - 事件附加数据
         */
        triggerEvent: function (eventName, detail) {
            var event = new CustomEvent('qibbs:' + eventName, {
                detail: detail || {},
                bubbles: true,
                cancelable: true
            });
            document.dispatchEvent(event);

            // 调试模式下在控制台输出日志
            if (window.qibbsDebug) {
                console.log('Qibbs Event: qibbs:' + eventName, detail);
            }
        },

        init: function () {
            this.bindEvents();
            var urlParams = new URLSearchParams(window.location.search);
            var isVanityView = (urlParams.get('view') === 'vanity-mall' || window.location.hash === '#vanity-mall') && $('#qibbs-vanity-mall-container').length > 0;
            var isGrowthView = (urlParams.get('view') === 'growth' || window.location.hash === '#growth') && $('#qibbs-growth-center').length > 0;
            var isPropView = (urlParams.get('view') === 'prop-mall' || window.location.hash === '#prop-mall') && $('#qibbs-prop-mall-container').length > 0;

            if (isVanityView) {
                $('#qibbs-growth-center, #qibbs-prop-mall-container').hide();
                $('#qibbs-composer, .qibbs-composer, #qibbs-feed, #qibbs-load-more, #qibbs-filter-bar').hide();
                $('#qibbs-vanity-mall-container').show();
                $('[data-filter-type]').removeClass('active');
                $('#qibbs-nav-vanity-mall, [data-filter="vanity-mall"]').addClass('active');
            } else if (isGrowthView) {
                $('#qibbs-vanity-mall-container, #qibbs-prop-mall-container').hide();
                $('#qibbs-composer, .qibbs-composer, #qibbs-feed, #qibbs-load-more, #qibbs-filter-bar').hide();
                $('#qibbs-growth-center').show();
                $('[data-filter-type]').removeClass('active');
                $('#qibbs-nav-growth-center, [data-filter="growth-center"]').addClass('active');
            } else if (isPropView) {
                $('#qibbs-vanity-mall-container, #qibbs-growth-center').hide();
                $('#qibbs-composer, .qibbs-composer, #qibbs-feed, #qibbs-load-more, #qibbs-filter-bar').hide();
                $('#qibbs-prop-mall-container').show();
                $('[data-filter-type]').removeClass('active');
                $('#qibbs-nav-prop-mall, [data-filter="prop-mall"]').addClass('active');
            } else {
                $('#qibbs-vanity-mall-container, #qibbs-growth-center, #qibbs-prop-mall-container').hide();
                var initialFilter = urlParams.get('filter') || urlParams.get('feed');
                if (initialFilter) {
                    var $initialTarget = $('[data-filter="' + initialFilter + '"][data-filter-type="feed"]').first();
                    if ($initialTarget.length) {
                        this.handleFilterClick($initialTarget);
                    } else {
                        this.loadFeed();
                    }
                } else {
                    this.loadFeed();
                }
            }
            this.initInfiniteScroll();
            this.loadUnreadCounts();
            this.initDarkMode();
            this.initThemeStyleSwitcher();
            this.initCheckinWidget();
            this.initPropMall();
            this.initDraftAutosave();
            this.syncHotWindowUI();
            this.initMentionAutocomplete();
            this.syncMobileNavState();
            this.refreshMobileBottomOffset();
            this.scheduleMobileBottomOffsetRefresh();
            setTimeout(this.refreshMobileBottomOffset.bind(this), 800);
            setTimeout(this.refreshMobileBottomOffset.bind(this), 2200);

            // 触发初始化完成事件
            this.triggerEvent('init', { timestamp: Date.now() });
        },

        bindEvents: function () {
            var self = this;

            // 导航筛选（版块、标签、动态流类型）
            $(document).on('click', '[data-filter-type]', function (e) {
                e.preventDefault();
                self.handleFilterClick($(this));
            });
            $(document).on('click', '.qibbs-hot-window-btn', this.handleHotWindowSwitch.bind(this));
            $(document).on('click', '[data-mobile-action="open-sheet"]', this.openMobileSheet.bind(this));
            $(document).on('click', '[data-mobile-action="close-sheet"]', this.closeMobileSheet.bind(this));
            $(document).on('click', '#qibbs-mobile-sheet-backdrop', this.closeMobileSheet.bind(this));
            $(document).on('click', '[data-mobile-action="compose"]', this.handleMobileCompose.bind(this));
            $(document).on('click', '[data-mobile-action="open-style"]', this.openMobileStylePicker.bind(this));
            $(document).on('click', '[data-mobile-close-sheet="1"]', this.closeMobileSheet.bind(this));
            $(document).on('click', '#qibbs-theme-style-toggle', this.toggleThemeStylePanel.bind(this));
            $(document).on('click', '[data-theme-style]', this.handleThemeStyleChange.bind(this));
            $(window).on('resize.qibbsMobile orientationchange.qibbsMobile', this.scheduleMobileBottomOffsetRefresh.bind(this));
            $(window).on('load.qibbsMobile', this.scheduleMobileBottomOffsetRefresh.bind(this));
            $(document).on('click', '#qibbs-filter-clear', function (e) {
                e.preventDefault();
                self.clearFilter();
            });

            // 搜索与高级筛选
            $(document).on('input', '#qibbs-search-input', this.handleSearchInput.bind(this));
            $(document).on('keydown', '#qibbs-search-input', this.handleSearchKeydown.bind(this));
            $(document).on('click', '#qibbs-search-btn', this.handleSearchTrigger.bind(this));
            $(document).on('submit', '#qibbs-advanced-search-form', this.handleAdvancedSearchSubmit.bind(this));
            $(document).on('click', '#qibbs-advanced-search-reset', this.handleAdvancedSearchReset.bind(this));

            // 发帖与媒体
            $(document).on('submit', '#qibbs-post-form', this.handlePostSubmit.bind(this));
            $(document).on('change', '#qibbs-post-type', this.handlePostTypeChange.bind(this));
            $(document).on('change', '#qibbs-media-input', this.handleMediaSelect.bind(this));
            $(document).on('paste', '#qibbs-post-content', this.handlePostContentPaste.bind(this));
            $(document).on('click', '#qibbs-media-preview .remove', this.handleMediaRemove.bind(this));
            $(document).on('click', '.qibbs-content-text img', function () {
                if (typeof window.qibbsLightbox === 'function') {
                    window.qibbsLightbox(this);
                }
            });

            // 悬赏与红包互动
            $(document).on('click', '.qibbs-btn-view-packet-logs', this.handleViewPacketLogs.bind(this));
            $(document).on('click', '.qibbs-btn-draw-packet-trigger', this.handleDrawPacketLottery.bind(this));
            $(document).on('click', '.qibbs-btn-arbitrate-trigger', this.handleArbitrateTrigger.bind(this));
            $(document).on('change', 'input[name="arbitration_action"]', this.handleArbitrationActionChange.bind(this));
            $(document).on('submit', '#qibbs-arbitration-form', this.handleArbitrationSubmit.bind(this));
            $(document).on('click', '.qibbs-modal-close, .qibbs-modal-cancel-btn', this.handleModalClose.bind(this));
            $(document).on('click', '.qibbs-modal-overlay', function (e) {
                if ($(e.target).hasClass('qibbs-modal-overlay')) {
                    $(this).fadeOut(150);
                }
            });

            // 互动操作（点赞/收藏/关注/订阅）
            $(document).on('click', '.qibbs-like-btn', this.handleLikeClick.bind(this));
            $(document).on('click', '.qibbs-favorite-btn', this.handleFavoriteClick.bind(this));
            $(document).on('click', '.qibbs-comment-btn', this.handleCommentToggle.bind(this));
            $(document).on('click', '.qibbs-follow-btn', this.handleFollowClick.bind(this));
            $(document).on('click', '.qibbs-forum-subscribe-btn', this.handleForumSubscribeClick.bind(this));

            // 评论交互
            $(document).on('submit', '.qibbs-comment-form', this.handleCommentSubmit.bind(this));
            $(document).on('submit', '.qibbs-reply-form', this.handleReplySubmit.bind(this));
            $(document).on('click', '.qibbs-delete-comment', this.handleDeleteComment.bind(this));
            $(document).on('click', '.qibbs-block-comment', this.handleBlockComment.bind(this));
            $(document).on('click', '.qibbs-comment-like-btn', this.handleCommentLikeClick.bind(this));
            $(document).on('click', '.qibbs-set-best-answer', this.handleSetBestAnswer.bind(this));
            $(document).on('click', '.qibbs-clear-best-answer', this.handleClearBestAnswer.bind(this));
            $(document).on('click', '.qibbs-toggle-quality-pin', this.handleToggleQualityPin.bind(this));
            $(document).on('click', '.qibbs-reopen-question-btn', this.handleReopenQuestionClick.bind(this));
            $(document).on('click', '.qibbs-reply-btn', this.handleReplyClick.bind(this));
            $(document).on('click', '.qibbs-page-btn', this.handleCommentPagination.bind(this));

            // 帖子管理与状态
            $(document).on('click', '.qibbs-delete-btn', this.handleDeletePost.bind(this));
            $(document).on('click', '.qibbs-block-btn', this.handleBlockPost.bind(this));
            $(document).on('click', '.qibbs-feature-btn', this.handleFeaturePost.bind(this));
            $(document).on('click', '.qibbs-unfeature-btn', this.handleUnfeaturePost.bind(this));
            $(document).on('click', '.qibbs-discussion-status-btn', this.handleDiscussionStatusUpdate.bind(this));

            // 菜单与流加载
            $(document).on('click', '.qibbs-menu-toggle', this.handleMenuToggle.bind(this));
            $(document).on('click', '.qibbs-load-more-btn', this.loadMore.bind(this));
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.qibbs-card-menu').length) {
                    $('.qibbs-menu-dropdown').removeClass('show');
                }
                if (!$(e.target).closest('.qibbs-style-switch-wrap').length) {
                    $('#qibbs-theme-style-panel').removeClass('is-open');
                }
                if (!$(e.target).closest('.qibbs-search-widget').length) {
                    $('#qibbs-search-results').hide();
                }
                if (!$(e.target).closest('#qibbs-post-content, #qibbs-mention-box').length) {
                    self.hideMentionBox();
                }
            });

            // 辅助功能与编辑弹窗
            $(document).on('click', '.qibbs-share-btn', this.handleShare.bind(this));
            $(document).on('click', '.qibbs-report-btn', this.handleReportPost.bind(this));
            $(document).on('click', '#qibbs-draft-clear', this.clearDraft.bind(this));
            $(document).on('click', '.qibbs-edit-btn', this.handleEditPost.bind(this));
            $(document).on('click', '#qibbs-edit-submit', this.handleEditSubmit.bind(this));
            $(document).on('click', '#qibbs-edit-cancel, .qibbs-edit-modal-overlay', this.closeEditModal.bind(this));
            $(document).on('click', '.qibbs-edit-modal', function (e) { e.stopPropagation(); });
            $(document).on('click', '.qibbs-edit-media-remove', this.handleEditMediaRemove.bind(this));
            $(document).on('change', '#qibbs-edit-media-input', this.handleEditMediaSelect.bind(this));

            // @提及用户自动补全
            $(document).on('input click', '#qibbs-post-content', this.handleMentionInput.bind(this));
            $(document).on('keydown', '#qibbs-post-content', this.handleMentionKeydown.bind(this));
            $(document).on('mousedown', '.qibbs-mention-item', this.handleMentionSelect.bind(this));

            // 成长中心选项卡切换
            $(document).on('click', '.qibbs-growth-tab-btn', function (e) {
                e.preventDefault();
                var tab = $(this).data('growth-tab');
                $('.qibbs-growth-tab-btn').removeClass('is-active');
                $(this).addClass('is-active');
                $('.qibbs-growth-panel').removeClass('is-active').hide();
                $('#qibbs-growth-panel-' + tab).addClass('is-active').fadeIn(150);
            });

            // 成长中心快捷动作按钮
            $(document).on('click', '.qibbs-growth-goto', function (e) {
                var gotoAction = $(this).data('goto');
                if (gotoAction === 'checkin') {
                    e.preventDefault();
                    var $checkinBtn = $('#qibbs-checkin-btn, .qibbs-checkin-btn').first();
                    if ($checkinBtn.length) {
                        $('html, body').animate({ scrollTop: $checkinBtn.offset().top - 120 }, 300);
                        $checkinBtn.focus();
                        if (!$checkinBtn.prop('disabled') && !$checkinBtn.hasClass('is-checked')) {
                            $checkinBtn.trigger('click');
                        }
                    } else {
                        self.showToast('请在右侧侧边栏中点击打卡签到', 'info');
                    }
                } else if (gotoAction === 'compose') {
                    e.preventDefault();
                    self.clearFilter();
                    var $comp = $('#qibbs-composer');
                    if ($comp.length) {
                        $('html, body').animate({ scrollTop: $comp.offset().top - 80 }, 300);
                        $('#qibbs-post-content').focus();
                    }
                } else if (gotoAction === 'feed') {
                    e.preventDefault();
                    self.clearFilter();
                }
            });

            // 标牌与导航快捷直达成长中心
            $(document).on('click', '#qibbs-nav-growth-center, [data-filter="growth-center"]', function (e) {
                if ($('#qibbs-growth-center').length) {
                    e.preventDefault();
                    self.showGrowthCenterView();
                }
            });

            // 标牌与导航快捷直达靓号交易大厅
            $(document).on('click', '#qibbs-nav-vanity-mall, [data-filter="vanity-mall"]', function (e) {
                if ($('#qibbs-vanity-mall-container').length) {
                    e.preventDefault();
                    self.showVanityMallView();
                }
            });

            // 标牌与导航快捷直达道具大厅
            $(document).on('click', '#qibbs-nav-prop-mall, [data-filter="prop-mall"]', function (e) {
                if ($('#qibbs-prop-mall-container').length) {
                    e.preventDefault();
                    self.showPropMallView();
                }
            });

            // 浏览器前进/后退 popstate 历史监听（保障成长中心/交易大厅/道具大厅/动态流互斥同步）
            window.addEventListener('popstate', function () {
                var params = new URLSearchParams(window.location.search);
                var view = params.get('view');
                if (view === 'growth' && $('#qibbs-growth-center').length > 0) {
                    self.showGrowthCenterView(false);
                } else if (view === 'vanity-mall' && $('#qibbs-vanity-mall-container').length > 0) {
                    self.showVanityMallView(false);
                } else if (view === 'prop-mall' && $('#qibbs-prop-mall-container').length > 0) {
                    self.showPropMallView(false);
                } else if (!view) {
                    $('#qibbs-vanity-mall-container, #qibbs-growth-center, #qibbs-prop-mall-container').hide();
                    $('#qibbs-nav-vanity-mall, [data-filter="vanity-mall"]').removeClass('active');
                    $('#qibbs-nav-growth-center, [data-filter="growth-center"]').removeClass('active');
                    $('#qibbs-nav-prop-mall, [data-filter="prop-mall"]').removeClass('active');
                    $('#qibbs-composer, .qibbs-composer, #qibbs-feed').show();
                    $('[data-filter-type]').removeClass('active');
                    $('[data-filter="global"]').addClass('active');
                    if (!$('#qibbs-feed .qibbs-post-card').length) {
                        self.loadFeed();
                    }
                }
            });
        },

        showGrowthCenterView: function (pushHistory) {
            $('#qibbs-vanity-mall-container, #qibbs-prop-mall-container').hide();
            if (window.QibbsVanityMall && typeof window.QibbsVanityMall.hideVanityMall === 'function') {
                window.QibbsVanityMall.hideVanityMall(false);
            }
            $('#qibbs-composer, .qibbs-composer, #qibbs-feed, #qibbs-load-more, #qibbs-filter-bar').hide();
            $('#qibbs-growth-center').fadeIn(200);
            $('[data-filter-type]').removeClass('active');
            $('#qibbs-nav-growth-center, [data-filter="growth-center"]').addClass('active');
            $('#qibbs-nav-prop-mall, [data-filter="prop-mall"]').removeClass('active');
            if (pushHistory !== false && window.history && window.history.pushState) {
                var url = new URL(window.location.href);
                url.searchParams.set('view', 'growth');
                window.history.pushState({ view: 'growth' }, '', url.toString());
            }
            this.syncMobileNavState();
        },

        showVanityMallView: function (pushHistory) {
            $('#qibbs-growth-center, #qibbs-prop-mall-container').hide();
            $('#qibbs-composer, .qibbs-composer, #qibbs-feed, #qibbs-load-more, #qibbs-filter-bar').hide();
            $('#qibbs-vanity-mall-container').fadeIn(200);
            $('[data-filter-type]').removeClass('active');
            $('#qibbs-nav-vanity-mall, [data-filter="vanity-mall"]').addClass('active');
            $('#qibbs-nav-prop-mall, [data-filter="prop-mall"]').removeClass('active');
            if (pushHistory !== false && window.history && window.history.pushState) {
                var url = new URL(window.location.href);
                url.searchParams.set('view', 'vanity-mall');
                window.history.pushState({ view: 'vanity-mall' }, '', url.toString());
            }
            if (window.QibbsVanityMall && typeof window.QibbsVanityMall.showVanityMall === 'function') {
                window.QibbsVanityMall.showVanityMall(false);
            }
            this.syncMobileNavState();
        },

        showPropMallView: function (pushHistory) {
            $('#qibbs-growth-center, #qibbs-vanity-mall-container').hide();
            if (window.QibbsVanityMall && typeof window.QibbsVanityMall.hideVanityMall === 'function') {
                window.QibbsVanityMall.hideVanityMall(false);
            }
            $('#qibbs-composer, .qibbs-composer, #qibbs-feed, #qibbs-load-more, #qibbs-filter-bar').hide();
            $('#qibbs-prop-mall-container').fadeIn(200);
            $('[data-filter-type]').removeClass('active');
            $('#qibbs-nav-prop-mall, [data-filter="prop-mall"]').addClass('active');
            $('#qibbs-nav-growth-center, [data-filter="growth-center"]').removeClass('active');
            $('#qibbs-nav-vanity-mall, [data-filter="vanity-mall"]').removeClass('active');
            if (pushHistory !== false && window.history && window.history.pushState) {
                var url = new URL(window.location.href);
                url.searchParams.set('view', 'prop-mall');
                window.history.pushState({ view: 'prop-mall' }, '', url.toString());
            }
            this.syncMobileNavState();
        },

        handleFilterClick: function ($el) {
            var filterType = $el.data('filter-type');
            if (filterType === 'view') {
                var filterVal = $el.data('filter');
                if (filterVal === 'growth-center') {
                    this.showGrowthCenterView();
                } else if (filterVal === 'vanity-mall') {
                    this.showVanityMallView();
                } else if (filterVal === 'prop-mall') {
                    this.showPropMallView();
                }
                return;
            }

            // 切换回动态流或圈子时，确保交易大厅、成长中心与道具大厅隐藏并恢复动态流
            $('#qibbs-vanity-mall-container, #qibbs-growth-center, #qibbs-prop-mall-container').hide();
            $('#qibbs-composer, .qibbs-composer, #qibbs-feed').show();
            $('#qibbs-nav-vanity-mall, [data-filter="vanity-mall"]').removeClass('active');
            $('#qibbs-nav-growth-center, [data-filter="growth-center"]').removeClass('active');
            $('#qibbs-nav-prop-mall, [data-filter="prop-mall"]').removeClass('active');

            if (window.history && window.history.pushState) {
                var currentUrl = new URL(window.location.href);
                if (currentUrl.searchParams.has('view')) {
                    currentUrl.searchParams.delete('view');
                    window.history.pushState({}, '', currentUrl.toString());
                }
            }

            var filterId = $el.data('filter');
            // 优先从 data 属性获取筛选名称，否则提取主文本节点
            var filterName = $el.data('filter-name');
            if (!filterName) {
                // 提取包含名称的文本节点（排除计数与图标）
                var $nameSpan = $el.find('span').not('.qibbs-count').not('.qibbs-forum-icon-placeholder').first();
                filterName = $nameSpan.length ? $nameSpan.text().trim() : $el.text().trim();
            }

            // 更新激活状态
            $('[data-filter-type]').removeClass('active');
            $el.addClass('active');

            // 设置筛选参数
            if (filterType === 'feed') {
                if (filterId !== 'global' && this.hasActiveAdvancedSearch()) {
                    this.resetAdvancedSearchState();
                }
                this.feed.type = (filterId === 'global' && this.hasActiveAdvancedSearch()) ? 'search' : filterId;
                this.feed.filterType = null;
                this.feed.filterId = null;
                this.syncHotWindowUI();
            } else {
                this.feed.type = this.hasActiveAdvancedSearch() ? 'search' : 'filter';
                this.feed.filterType = filterType;
                this.feed.filterId = filterId;
                this.feed.filterName = filterName;
                this.syncHotWindowUI();
            }

            this.syncMobileNavState();
            this.updateFilterBar();

            // 重置分页并重新加载
            this.feed.page = 1;
            this.feed.hasMore = true;
            $('#qibbs-feed').html('<div class="qibbs-loading">' + qibbsData.strings.loading + '</div>');
            this.loadFeed();
        },

        clearFilter: function () {
            $('#qibbs-vanity-mall-container, #qibbs-growth-center, #qibbs-prop-mall-container').hide();
            $('#qibbs-composer, .qibbs-composer, #qibbs-feed').show();
            $('#qibbs-nav-vanity-mall, [data-filter="vanity-mall"]').removeClass('active');
            $('#qibbs-nav-growth-center, [data-filter="growth-center"]').removeClass('active');
            $('#qibbs-nav-prop-mall, [data-filter="prop-mall"]').removeClass('active');

            if (window.history && window.history.pushState) {
                var currentUrl = new URL(window.location.href);
                if (currentUrl.searchParams.has('view')) {
                    currentUrl.searchParams.delete('view');
                    window.history.pushState({}, '', currentUrl.toString());
                }
            }

            this.feed.type = 'global';
            this.feed.filterType = null;
            this.feed.filterId = null;
            this.feed.filterName = null;
            this.feed.page = 1;
            this.feed.hasMore = true;
            this.resetAdvancedSearchState();

            $('[data-filter-type]').removeClass('active');
            $('[data-filter="global"]').addClass('active');
            this.syncHotWindowUI();
            this.syncMobileNavState();
            this.updateFilterBar();

            $('#qibbs-feed').html('<div class="qibbs-loading">' + qibbsData.strings.loading + '</div>');
            this.loadFeed();
        },

        handleHotWindowSwitch: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var windowDays = parseInt($btn.data('hot-window'), 10);
            if ([1, 7, 30].indexOf(windowDays) === -1) {
                return;
            }

            if (this.hasActiveAdvancedSearch()) {
                this.resetAdvancedSearchState();
            }
            this.feed.hotWindow = windowDays;
            this.syncHotWindowUI();

            this.feed.type = 'hot';
            this.feed.filterType = null;
            this.feed.filterId = null;
            this.feed.page = 1;
            this.feed.hasMore = true;

            $('[data-filter-type]').removeClass('active');
            $('[data-filter-type="feed"][data-filter="hot"]').addClass('active');
            this.syncMobileNavState();
            this.updateFilterBar();
            $('#qibbs-feed').html('<div class="qibbs-loading">' + qibbsData.strings.loading + '</div>');
            this.loadFeed();
        },

        syncHotWindowUI: function () {
            var windowDays = parseInt(this.feed.hotWindow, 10);
            if ([1, 7, 30].indexOf(windowDays) === -1) {
                windowDays = 7;
            }
            this.feed.hotWindow = windowDays;
            $('.qibbs-hot-window-btn').removeClass('active');
            $('.qibbs-hot-window-btn[data-hot-window="' + windowDays + '"]').addClass('active');
            if (this.feed.type === 'hot') {
                $('#qibbs-hot-window-switch').show();
            } else {
                $('#qibbs-hot-window-switch').hide();
            }
        },

        hasActiveAdvancedSearch: function () {
            return !!(
                this.feed.searchQuery ||
                this.feed.authorQuery ||
                this.feed.tagQuery ||
                (this.feed.postType && this.feed.postType !== 'all') ||
                (this.feed.resolved && this.feed.resolved !== 'all') ||
                (this.feed.sort && this.feed.sort !== 'latest') ||
                this.feed.featuredOnly
            );
        },

        readAdvancedSearchStateFromUI: function () {
            return {
                searchQuery: ($('#qibbs-search-input').val() || '').trim(),
                authorQuery: ($('#qibbs-search-author').val() || '').trim(),
                tagQuery: ($('#qibbs-search-tag').val() || '').trim(),
                postType: $('#qibbs-search-post-type').val() || 'all',
                resolved: $('#qibbs-search-resolved').val() || 'all',
                sort: $('#qibbs-search-sort').val() || 'latest',
                featuredOnly: $('#qibbs-search-featured-only').is(':checked')
            };
        },

        writeAdvancedSearchStateToUI: function () {
            $('#qibbs-search-input').val(this.feed.searchQuery || '');
            $('#qibbs-search-author').val(this.feed.authorQuery || '');
            $('#qibbs-search-tag').val(this.feed.tagQuery || '');
            $('#qibbs-search-post-type').val(this.feed.postType || 'all');
            $('#qibbs-search-resolved').val(this.feed.resolved || 'all');
            $('#qibbs-search-sort').val(this.feed.sort || 'latest');
            $('#qibbs-search-featured-only').prop('checked', !!this.feed.featuredOnly);
        },

        resetAdvancedSearchState: function () {
            this.feed.searchQuery = '';
            this.feed.authorQuery = '';
            this.feed.tagQuery = '';
            this.feed.postType = 'all';
            this.feed.resolved = 'all';
            this.feed.sort = 'latest';
            this.feed.featuredOnly = false;
            this.writeAdvancedSearchStateToUI();
            $('#qibbs-search-results').hide().empty();
        },

        updateFilterBar: function () {
            var parts = [];
            if (this.feed.filterType && this.feed.filterName) {
                var cleanName = String(this.feed.filterName).replace(/^#/, '');
                if (this.feed.filterType === 'forum') {
                    parts.push('圈子: ' + cleanName);
                } else if (this.feed.filterType === 'category') {
                    parts.push('分类: ' + cleanName);
                } else {
                    parts.push('标签: #' + cleanName);
                }
            } else if (this.feed.type && this.feed.type !== 'global' && this.feed.type !== 'search') {
                var feedTypeLabels = {
                    'hot': '热门动态',
                    'featured': '推荐动态',
                    'voting': '投票动态',
                    'redpacket': '红包动态',
                    'bounty': '悬赏动态',
                    'following': '关注动态'
                };
                if (feedTypeLabels[this.feed.type]) {
                    parts.push('分类: ' + feedTypeLabels[this.feed.type]);
                }
            }
            if (this.feed.searchQuery) {
                parts.push('关键词: ' + this.feed.searchQuery);
            }
            if (this.feed.authorQuery) {
                parts.push('作者: ' + this.feed.authorQuery);
            }
            if (this.feed.tagQuery) {
                parts.push('标签: #' + this.feed.tagQuery.replace(/^#/, ''));
            }
            if (this.feed.postType && this.feed.postType !== 'all') {
                var postTypeLabel = $('#qibbs-search-post-type option:selected').text();
                if (postTypeLabel) {
                    parts.push('类型: ' + postTypeLabel);
                }
            }
            if (this.feed.resolved && this.feed.resolved !== 'all') {
                var resolvedLabel = $('#qibbs-search-resolved option:selected').text();
                if (resolvedLabel) {
                    parts.push('问答: ' + resolvedLabel);
                }
            }
            if (this.feed.sort && this.feed.sort !== 'latest') {
                var sortLabel = $('#qibbs-search-sort option:selected').text();
                if (sortLabel) {
                    parts.push('排序: ' + sortLabel);
                }
            }
            if (this.feed.featuredOnly) {
                parts.push('只看推荐');
            }

            if (parts.length) {
                $('#qibbs-filter-bar .qibbs-filter-label').text(parts.join(' · '));
                $('#qibbs-filter-bar').show();
            } else {
                $('#qibbs-filter-bar').hide();
            }
        },

        applyAdvancedSearch: function (state) {
            this.feed.searchQuery = state.searchQuery || '';
            this.feed.authorQuery = state.authorQuery || '';
            this.feed.tagQuery = state.tagQuery || '';
            this.feed.postType = state.postType || 'all';
            this.feed.resolved = state.resolved || 'all';
            this.feed.sort = state.sort || 'latest';
            this.feed.featuredOnly = !!state.featuredOnly;

            this.feed.type = this.hasActiveAdvancedSearch()
                ? 'search'
                : (this.feed.filterType ? 'filter' : 'global');

            this.feed.page = 1;
            this.feed.hasMore = true;
            this.updateFilterBar();
            $('#qibbs-search-results').hide();
            $('#qibbs-feed').html('<div class="qibbs-loading">' + qibbsData.strings.loading + '</div>');
            this.loadFeed();
        },

        handleSearchInput: function (e) {
            var self = this;
            var query = ($(e.currentTarget).val() || '').trim();
            clearTimeout(this.searchSuggestTimer);

            if (query.length < 2) {
                $('#qibbs-search-results').hide().empty();
                return;
            }

            this.searchSuggestTimer = setTimeout(function () {
                self.performSearchSuggestions(query);
            }, 300);
        },

        handleSearchKeydown: function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.handleSearchTrigger(e);
            }
        },

        handleSearchTrigger: function (e) {
            if (e) {
                e.preventDefault();
            }
            this.applyAdvancedSearch(this.readAdvancedSearchStateFromUI());
        },

        handleAdvancedSearchSubmit: function (e) {
            e.preventDefault();
            this.applyAdvancedSearch(this.readAdvancedSearchStateFromUI());
        },

        handleAdvancedSearchReset: function (e) {
            if (e) {
                e.preventDefault();
            }
            this.resetAdvancedSearchState();
            this.feed.type = this.feed.filterType ? 'filter' : 'global';
            this.feed.page = 1;
            this.feed.hasMore = true;
            this.updateFilterBar();
            $('#qibbs-feed').html('<div class="qibbs-loading">' + qibbsData.strings.loading + '</div>');
            this.loadFeed();
        },

        performSearchSuggestions: function (query) {
            var self = this;
            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_search_posts',
                    nonce: qibbsData.nonce,
                    query: query
                },
                success: function (response) {
                    var $results = $('#qibbs-search-results');
                    if (response.success && response.data.posts.length > 0) {
                        var html = '';
                        response.data.posts.forEach(function (post) {
                            var meta = '';
                            if (post.match_label) {
                                meta += '<span class="qibbs-search-result-badge">' + post.match_label + '</span>';
                            }
                            if (post.post_type_label && post.post_type !== 'discussion') {
                                meta += '<span class="qibbs-search-result-badge">' + post.post_type_label + '</span>';
                            }
                            if (post.forum) {
                                meta += '<span class="qibbs-search-result-badge">#' + post.forum + '</span>';
                            }
                            if (Array.isArray(post.tags) && post.tags.length) {
                                meta += '<span class="qibbs-search-result-badge">' + post.tags.map(function (tag) {
                                    return '#' + tag;
                                }).join(' ') + '</span>';
                            }
                            html += '<a href="' + self.sanitizeUrl(post.link || '') + '" class="qibbs-search-result-item">' +
                                '<span class="qibbs-search-result-author">' + (post.author || '') + '</span>' +
                                (meta ? '<div class="qibbs-search-result-meta">' + meta + '</div>' : '') +
                                '<div class="qibbs-search-result-content">' + (post.content || '') + '</div>' +
                                '</a>';
                        });
                        $results.html(html).show();
                    } else {
                        $results.html('<div class="qibbs-search-empty">未找到相关动态</div>').show();
                    }
                }
            });
        },

        loadFeed: function () {
            if (this.feed.loading) return;
            this.feed.loading = true;

            var self = this;
            var $feed = $('#qibbs-feed');
            var profileUserId = $feed.data('user-id');
            var profileTab = $feed.data('tab');

            var data = {
                action: 'qibbs_load_feed',
                nonce: qibbsData.nonce,
                feed_type: this.feed.type,
                page: this.feed.page
            };

            // 个人主页：覆盖 feed_type 并传递 user_id
            if (profileUserId) {
                data.user_id = profileUserId;
                data.feed_type = profileTab || 'posts';
            }

            if (this.feed.filterType) {
                data.filter_type = this.feed.filterType;
                data.filter_id = this.feed.filterId;
                if (this.feed.filterName) {
                    data.filter_name = this.feed.filterName.replace(/^#/, '');
                }
            }

            if (this.feed.type === 'hot') {
                data.hot_window = this.feed.hotWindow;
            }

            if (this.feed.type === 'search' || this.hasActiveAdvancedSearch()) {
                data.query = this.feed.searchQuery;
                data.author_query = this.feed.authorQuery;
                data.tag_query = this.feed.tagQuery;
                data.post_type = this.feed.postType;
                data.resolved = this.feed.resolved;
                data.sort = this.feed.sort;
                data.featured_only = this.feed.featuredOnly ? 1 : 0;
            }

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: data,
                success: function (response) {
                    if (response.success) {
                        if (self.feed.page === 1) {
                            $('#qibbs-feed').html(response.data.html);
                        } else {
                            $('#qibbs-feed').append(response.data.html);
                        }

                        self.feed.hasMore = response.data.has_more;
                        $('#qibbs-load-more').toggle(self.feed.hasMore);

                        if (!response.data.html && self.feed.page === 1) {
                            $('#qibbs-feed').html('<div class="qibbs-empty"><svg viewBox="0 0 24 24" width="64" height="64"><path fill="#e0e0e0" d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-7 12h-2v-2h2v2zm0-4h-2V6h2v4z"/></svg><p>暂无内容</p></div>');
                        }

                        // 触发动态流加载完成事件
                        self.triggerEvent('feed:loaded', {
                            page: self.feed.page,
                            hasMore: self.feed.hasMore,
                            feedType: self.feed.type,
                            searchQuery: self.feed.searchQuery,
                            total: response.data.total
                        });
                    }
                },
                complete: function () {
                    self.feed.loading = false;
                }
            });
        },

        loadMore: function () {
            if (!this.feed.hasMore || this.feed.loading) return;
            this.feed.page++;
            this.loadFeed();
        },

        initInfiniteScroll: function () {
            if (typeof qibbsData !== 'undefined' && qibbsData.infiniteScroll === false) {
                return;
            }
            var self = this;
            var $window = $(window);
            var $feed = $('#qibbs-feed');
            var throttleTimer = null;

            var checkAndLoad = function () {
                if (self.feed.loading || !self.feed.hasMore) return;
                if (!$feed.length || !$feed.offset()) return;

                var scrollTop = $window.scrollTop();
                var windowHeight = $window.height();
                var feedBottom = $feed.offset().top + $feed.height();

                if (scrollTop + windowHeight >= feedBottom - 300) {
                    self.loadMore();
                }
            };

            $window.off('scroll.qibbsInfinite').on('scroll.qibbsInfinite', function () {
                if (throttleTimer) return;
                throttleTimer = setTimeout(function () {
                    throttleTimer = null;
                    checkAndLoad();
                }, 120);
            });
        },

        handlePostSubmit: function (e) {
            e.preventDefault();

            var $form = $(e.currentTarget);
            var $btn = $form.find('.qibbs-submit-btn');
            var content = $('#qibbs-post-content').val().trim();
            if (typeof tinymce !== 'undefined' && tinymce.get('qibbs_rich_content') && $('#qibbs-rich-editor-container').is(':visible')) {
                var richHtml = tinymce.get('qibbs_rich_content').getContent();
                if (richHtml && richHtml.trim()) {
                    content = richHtml.trim();
                    $('#qibbs-post-content').val(content);
                }
            }

            var hasPendingMedia = (this.mediaQueue && this.mediaQueue.some(function (f) { return f !== null; })) ||
                                  (window.qibbsRichMediaIds && window.qibbsRichMediaIds.length > 0);

            if (!content && !hasPendingMedia) {
                this.showToast('请输入内容', 'error');
                return;
            }

            $btn.prop('disabled', true).html('<span class="qibbs-spinner"></span><span class="qibbs-btn-text">发布中...</span>');

            var self = this;
            var forumId = $('#qibbs-forum-select').val() || 0;
            var postType = $('#qibbs-post-type').val() || 'discussion';

            this.uploadMediaQueue(function (mediaIds) {
                if (mediaIds === false) {
                    $btn.prop('disabled', false).text('发布');
                    return;
                }

                var nonceVal = (typeof qibbsData !== 'undefined' && qibbsData.nonce) ? qibbsData.nonce : ($('#qibbs-post-nonce').val() || '');
                var postPayload = {
                    action: 'qibbs_create_post',
                    nonce: nonceVal,
                    content: content,
                    forum_id: forumId,
                    post_type: postType,
                    media_ids: mediaIds.join(','),
                    qibbs_poll_id: $form.find('[name="qibbs_poll_id"]').val() || ''
                };

                if (postType === 'bounty') {
                    postPayload.bounty_amount = $('#qibbs-bounty-amount-input').val();
                } else if (postType === 'redpacket') {
                    postPayload.redpacket_amount     = $('#qibbs-redpacket-amount-input').val();
                    postPayload.redpacket_num        = $('#qibbs-redpacket-num-input').val();
                    postPayload.redpacket_draw_type  = $('#qibbs-redpacket-draw-type').val();
                    postPayload.redpacket_alloc_type = $('#qibbs-redpacket-alloc-type').val();
                }

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: postPayload,
                    success: function (response) {
                        if (response.success) {
                            $('#qibbs-post-content').val('');
                            $('#qibbs-media-preview').empty();
                            $('#qibbs-post-type').val('discussion');
                            $('#qibbs-forum-select').val('0');
                            $('#qibbs-bounty-amount-input').val('');
                            $('#qibbs-redpacket-amount-input').val('');
                            $('#qibbs-redpacket-num-input').val('');
                            $('#qibbs-bounty-fields').slideUp(180);
                            $('#qibbs-redpacket-fields').slideUp(180);
                            self.mediaQueue = [];
                            if (typeof tinymce !== 'undefined' && tinymce.get('qibbs_rich_content')) {
                                tinymce.get('qibbs_rich_content').setContent('');
                            }
                            window.qibbsRichMediaIds = [];
                            self.clearDraftData(true);
                            self.hideMentionBox();

                            self.feed.page = 1;
                            self.loadFeed();

                            self.showToast(response.data.message);

                            // 触发帖子发布成功事件
                            self.triggerEvent('post:created', {
                                postId: response.data.post_id,
                                content: content,
                                forumId: forumId,
                                postType: postType
                            });
                        } else {
                            self.showToast(response.data.message, 'error');
                        }
                    },
                    error: function () {
                        self.showToast(qibbsData.strings.error, 'error');
                    },
                    complete: function () {
                        $btn.prop('disabled', false).text('发布');
                    }
                });
            });
        },

        handleMediaSelect: function (e) {
            var files = e.target.files;
            this.addFilesToMediaQueue(files);
            e.target.value = '';
        },

        handlePostContentPaste: function (e) {
            var cd = (e.originalEvent || e).clipboardData || window.clipboardData;
            if (!cd) return;

            var images = [];
            if (cd.items && cd.items.length > 0) {
                for (var i = 0; i < cd.items.length; i++) {
                    var item = cd.items[i];
                    if (item.type && item.type.indexOf('image/') === 0) {
                        var f = item.getAsFile();
                        if (f) images.push(f);
                    }
                }
            } else if (cd.files && cd.files.length > 0) {
                for (var j = 0; j < cd.files.length; j++) {
                    var file = cd.files[j];
                    if (file.type && file.type.indexOf('image/') === 0) {
                        images.push(file);
                    }
                }
            }

            if (images.length > 0) {
                e.preventDefault();
                this.addFilesToMediaQueue(images);
            }
        },

        addFilesToMediaQueue: function (files) {
            if (!files || !files.length) return;
            var $preview = $('#qibbs-media-preview');
            var self = this;

            // 从后端配置读取上传限制
            var maxSizeMB = qibbsData.maxUploadSize || 5;
            var maxSizeBytes = maxSizeMB * 1024 * 1024;
            var allowedFormatsStr = qibbsData.allowedImageFormats || 'jpg,jpeg,png,webp,gif';
            var allowedFormats = allowedFormatsStr.split(',').map(function (f) { return f.trim().toLowerCase(); });
            var videoFormats = ['mp4', 'webm', 'mov', 'avi', 'mkv'];

            Array.from(files).forEach(function (file) {
                var currentQueueCount = self.mediaQueue.filter(function(item) { return item !== null; }).length;
                if (currentQueueCount >= 9) {
                    self.showToast('最多上传9张图片', 'error');
                    return;
                }

                // 获取文件扩展名
                var fileName = (file.name || 'image.png').toLowerCase();
                var ext = fileName.split('.').pop();
                if (file.type && (!ext || ext === fileName)) {
                    ext = file.type.replace('image/', '').toLowerCase();
                    if (ext === 'jpeg') ext = 'jpg';
                }

                // 校验文件格式
                var isVideo = videoFormats.indexOf(ext) !== -1;
                var isAllowedImage = allowedFormats.indexOf(ext) !== -1;

                if (!isVideo && !isAllowedImage) {
                    self.showToast('不支持的文件格式: ' + ext + '。允许的图片格式：' + allowedFormatsStr, 'error');
                    return;
                }

                // 校验文件体积
                if (file.size > maxSizeBytes) {
                    self.showToast('文件 "' + (file.name || '图片') + '" 大小超过 ' + maxSizeMB + 'MB 限制', 'error');
                    return;
                }

                self.mediaQueue.push(file);
                var currentIndex = self.mediaQueue.length - 1;

                var reader = new FileReader();
                reader.onload = function (e) {
                    var $item = $('<div class="qibbs-media-preview-item" data-index="' + currentIndex + '">' +
                        '<img src="' + e.target.result + '">' +
                        '<button type="button" class="remove" data-index="' + currentIndex + '">&times;</button>' +
                        '</div>');
                    $preview.append($item);
                };
                reader.readAsDataURL(file);
            });
        },

        handleMediaRemove: function (e) {
            e.preventDefault();
            e.stopPropagation();

            var $btn = $(e.currentTarget);
            var index = parseInt($btn.data('index'), 10);
            var $item = $btn.closest('.qibbs-media-preview-item');

            // 从 mediaQueue 队列中移除（置空保留索引）
            if (index >= 0 && index < this.mediaQueue.length) {
                this.mediaQueue[index] = null;
            }

            // 从 DOM 中移除预览节点
            $item.fadeOut(200, function () {
                $(this).remove();
            });
        },

        uploadMediaQueue: function (callback) {
            // 过滤队列中无效占位
            var filesToUpload = this.mediaQueue.filter(function (file) {
                return file !== null;
            });

            var initialMediaIds = (window.qibbsRichMediaIds && Array.isArray(window.qibbsRichMediaIds))
                ? window.qibbsRichMediaIds.slice()
                : [];

            if (filesToUpload.length === 0) {
                callback(initialMediaIds);
                return;
            }

            var self = this;
            var mediaIds = initialMediaIds.slice();
            var uploaded = 0;
            var totalToUpload = filesToUpload.length;
            var nonceVal = (typeof qibbsData !== 'undefined' && qibbsData.nonce) ? qibbsData.nonce : ($('#qibbs-post-nonce').val() || '');
            var uploadErrors = [];

            filesToUpload.forEach(function (file) {
                var formData = new FormData();
                formData.append('action', 'qibbs_upload_media');
                formData.append('nonce', nonceVal);
                var safeName = file.name || '';
                if (!safeName || safeName.indexOf('.') === -1 || safeName === 'blob') {
                    var fallbackExt = 'png';
                    if (file.type) {
                        fallbackExt = file.type.replace('image/', '').replace('video/', '').toLowerCase();
                        if (fallbackExt === 'jpeg') fallbackExt = 'jpg';
                    }
                    safeName = 'upload-' + Date.now() + '.' + fallbackExt;
                }
                formData.append('file', file, safeName);

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (response) {
                        if (response.success && response.data && response.data.id) {
                            if (mediaIds.indexOf(response.data.id) === -1) {
                                mediaIds.push(response.data.id);
                            }
                        } else {
                            var errMsg = (response.data && response.data.message) ? response.data.message : '图片上传失败';
                            uploadErrors.push(errMsg);
                        }
                    },
                    error: function (xhr) {
                        uploadErrors.push('网络或服务异常 (HTTP ' + xhr.status + ')');
                    },
                    complete: function () {
                        uploaded++;
                        if (uploaded === totalToUpload) {
                            if (uploadErrors.length > 0) {
                                self.showToast('图片上传失败: ' + uploadErrors[0], 'error');
                                callback(false);
                            } else {
                                callback(mediaIds);
                            }
                        }
                    }
                });
            });
        },

        handleLikeClick: function (e) {
            e.preventDefault();

            if (!qibbsData.isLoggedIn) {
                this.showToast(qibbsData.strings.loginRequired, 'error');
                return;
            }

            var $btn = $(e.currentTarget);
            var postId = $btn.data('post-id');

            $btn.addClass('qibbs-animating');

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_toggle_like',
                    nonce: qibbsData.nonce,
                    post_id: postId
                },
                success: function (response) {
                    if (response.success) {
                        $btn.toggleClass('active', response.data.liked);
                        $btn.find('.count').text(response.data.like_count || '');

                        // 触发点赞状态切换事件
                        Qibbs.triggerEvent('like:toggled', {
                            postId: postId,
                            liked: response.data.liked,
                            likeCount: response.data.like_count
                        });
                    }
                },
                complete: function () {
                    setTimeout(function () {
                        $btn.removeClass('qibbs-animating');
                    }, 300);
                }
            });
        },

        handleFavoriteClick: function (e) {
            e.preventDefault();

            if (!qibbsData.isLoggedIn) {
                this.showToast(qibbsData.strings.loginRequired, 'error');
                return;
            }

            var $btn = $(e.currentTarget);
            var postId = $btn.data('post-id');

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_toggle_favorite',
                    nonce: qibbsData.nonce,
                    post_id: postId
                },
                success: function (response) {
                    if (response.success) {
                        $btn.toggleClass('active', response.data.favorited);

                        // 触发收藏状态切换事件
                        Qibbs.triggerEvent('favorite:toggled', {
                            postId: postId,
                            favorited: response.data.favorited
                        });
                    }
                }
            });
        },

        handleCommentToggle: function (e) {
            var $btn = $(e.currentTarget);
            var $card = $btn.closest('.qibbs-feed-card');
            var $comments = $card.find('.qibbs-comments-section');
            var postId = $btn.data('post-id');

            if ($comments.is(':visible')) {
                $comments.slideUp(200);
            } else {
                this.loadComments(postId, $comments);
                $comments.slideDown(200);
            }
        },

        loadComments: function (postId, $container, page) {
            var $list = $container.find('.qibbs-comments-list');
            var self = this;
            page = page || 1;
            $list.html('<div class="qibbs-loading-small">加载中...</div>');

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_load_comments',
                    nonce: qibbsData.nonce,
                    post_id: postId,
                    page: page
                },
                success: function (response) {
                    if (response.success) {
                        var discussionStatus = response.data.discussion_status || '';
                        var html = '';
                        if (discussionStatus && discussionStatus !== 'discussion' && !!response.data.can_reopen) {
                            html += '<div class="qibbs-comments-toolbar">' +
                                '<button class="qibbs-reopen-question-btn" data-post-id="' + postId + '">' +
                                self.escapeHtml((qibbsData.strings && qibbsData.strings.reopenQuestion) ? qibbsData.strings.reopenQuestion : '追问/重开') +
                                '</button>' +
                                '</div>';
                        }
                        response.data.comments.forEach(function (comment) {
                            html += self.renderComment(comment, postId);
                        });

                        // 渲染评论分页组件
                        if (response.data.total_pages > 1) {
                            html += '<div class="qibbs-comments-pagination" data-post-id="' + postId + '">';
                            html += '<span class="qibbs-page-info">' + response.data.page + ' / ' + response.data.total_pages + '</span>';
                            if (response.data.page > 1) {
                                html += '<button class="qibbs-page-btn qibbs-page-prev" data-page="' + (response.data.page - 1) + '">上一页</button>';
                            }
                            if (response.data.has_more) {
                                html += '<button class="qibbs-page-btn qibbs-page-next" data-page="' + (response.data.page + 1) + '">下一页</button>';
                            }
                            html += '</div>';
                        }

                        $list.html(html || '<div class="qibbs-empty-small">暂无评论，来说两句吧~</div>');

                        var $card = $container.closest('.qibbs-feed-card');
                        var shouldSyncDiscussionBadge = false;
                        if ($card.length) {
                            shouldSyncDiscussionBadge = $card.find('.qibbs-discussion-badge, .qibbs-resolved-badge, .qibbs-closed-badge, .qibbs-discussion-status-btn').length > 0;
                            if (!shouldSyncDiscussionBadge) {
                                shouldSyncDiscussionBadge = (parseInt(response.data.best_comment_id, 10) || 0) > 0;
                            }
                        }
                        if (discussionStatus && shouldSyncDiscussionBadge) {
                            self.updateCardDiscussionStatus($card, discussionStatus);
                        }
                    } else {
                        var errMsg = (response && response.data && response.data.message) ? self.escapeHtml(response.data.message) : '加载评论失败';
                        $list.html('<div class="qibbs-empty-small">' + errMsg + '</div>');
                    }
                },
                error: function () {
                    $list.html('<div class="qibbs-empty-small">加载评论失败，请重试</div>');
                }
            });
        },

        renderComment: function (comment, postId) {
            var self = this;
            var canDelete = (typeof comment.can_delete !== 'undefined')
                ? !!comment.can_delete
                : (comment.is_owner || qibbsData.isAdmin);
            var canBlock = (typeof comment.can_block !== 'undefined')
                ? !!comment.can_block
                : (qibbsData.isAdmin && !comment.is_owner);
            var canSetBest = !!comment.can_set_best;
            var canPinQuality = !!comment.can_pin_quality;
            var canLike = !!comment.can_like;
            var userLiked = !!comment.user_liked;
            var isBest = !!comment.is_best;
            var isQualityPinned = !!comment.is_quality_pinned;
            var likeCount = parseInt(comment.like_count, 10) || 0;
            var canReply = qibbsData.isLoggedIn;
            var authorName = this.escapeHtml(comment.author && comment.author.display_name ? comment.author.display_name : '');
            var authorProfile = this.sanitizeUrl(comment.author && comment.author.profile_url ? comment.author.profile_url : '');
            var authorAvatar = this.sanitizeUrl(comment.author && comment.author.avatar ? comment.author.avatar : '');
            var safeComment = this.formatSafeText(comment.content || '');

            var badgeHtml = '';
            if (isBest) {
                badgeHtml += '<span class="qibbs-comment-badge qibbs-comment-badge-best">最佳答案</span>';
            }
            if (isQualityPinned) {
                badgeHtml += '<span class="qibbs-comment-badge qibbs-comment-badge-quality">优质置顶</span>';
            }

            var qualityActionLabel = isQualityPinned
                ? ((qibbsData.strings && qibbsData.strings.unpinQualityReply) ? qibbsData.strings.unpinQualityReply : '取消置顶')
                : ((qibbsData.strings && qibbsData.strings.pinQualityReply) ? qibbsData.strings.pinQualityReply : '置顶');

            var actionsHtml =
                (canLike ? '<button class="qibbs-comment-like-btn ' + (userLiked ? 'active' : '') + '" data-id="' + comment.id + '" data-post-id="' + postId + '">' +
                    '<span class="qibbs-comment-like-label">' + this.escapeHtml(userLiked ? ((qibbsData.strings && qibbsData.strings.likedComment) ? qibbsData.strings.likedComment : '已赞') : ((qibbsData.strings && qibbsData.strings.likeComment) ? qibbsData.strings.likeComment : '赞')) + '</span>' +
                    '<span class="qibbs-comment-like-count">' + (likeCount > 0 ? likeCount : '') + '</span>' +
                    '</button>' : '') +
                (canReply ? '<button class="qibbs-reply-btn" data-id="' + comment.id + '" data-author="' + authorName + '" data-post-id="' + postId + '">回复</button>' : '') +
                ((canSetBest && !isBest) ? '<button class="qibbs-set-best-answer" data-id="' + comment.id + '" data-post-id="' + postId + '">' + this.escapeHtml((qibbsData.strings && qibbsData.strings.setBestAnswer) ? qibbsData.strings.setBestAnswer : '采纳最佳') + '</button>' : '') +
                (canPinQuality ? '<button class="qibbs-toggle-quality-pin" data-id="' + comment.id + '" data-post-id="' + postId + '">' + this.escapeHtml(qualityActionLabel) + '</button>' : '') +
                (canBlock ? '<button class="qibbs-block-comment" data-id="' + comment.id + '">屏蔽</button>' : '') +
                (canDelete ? '<button class="qibbs-delete-comment" data-id="' + comment.id + '">删除</button>' : '');

            var authorNameColor = (comment.author && comment.author.name_color) ? this.escapeHtml(comment.author.name_color) : '';
            var authorColorStyle = authorNameColor ? ' style="color:' + authorNameColor + ';font-weight:600;"' : '';
            var html = '<div class="qibbs-comment" id="comment-' + comment.id + '" data-comment-id="' + comment.id + '"' + (comment.author && comment.author.id ? ' data-author-id="' + comment.author.id + '"' : '') + '>' +
                '<a href="' + authorProfile + '" class="qibbs-comment-avatar"><img src="' + authorAvatar + '" alt=""></a>' +
                '<div class="qibbs-comment-body">' +
                '<div class="qibbs-comment-header">' +
                '<div class="qibbs-comment-meta-row">' +
                '<a href="' + authorProfile + '" class="qibbs-comment-author"' + authorColorStyle + '>' + authorName + '</a>' +
                (comment.author && comment.author.cuid_badge ? comment.author.cuid_badge : '') +
                (comment.author && comment.author.level_badge ? comment.author.level_badge : '') +
                '<span class="qibbs-comment-time"' + ((comment.created_at_formatted || comment.created_at) ? ' title="' + self.escapeHtml(comment.created_at_formatted || comment.created_at) + '"' : '') + '>' + comment.time_ago + '</span>' +
                (badgeHtml ? '<span class="qibbs-comment-badges">' + badgeHtml + '</span>' : '') +
                '</div>' +
                (actionsHtml ? '<div class="qibbs-comment-actions">' + actionsHtml + '</div>' : '') +
                '</div>' +
                '<div class="qibbs-comment-content">' + safeComment + '</div>';

            // 渲染二级回复列表
            if (comment.replies && comment.replies.length > 0) {
                html += '<div class="qibbs-replies">';
                comment.replies.forEach(function (reply) {
                    var replyAuthorName = self.escapeHtml(reply.author && reply.author.display_name ? reply.author.display_name : '');
                    var replyAuthorProfile = self.sanitizeUrl(reply.author && reply.author.profile_url ? reply.author.profile_url : '');
                    var replyAuthorAvatar = self.sanitizeUrl(reply.author && reply.author.avatar ? reply.author.avatar : '');
                    var replyContent = self.formatSafeText(reply.content || '');
                    var replyCanLike = !!reply.can_like;
                    var replyUserLiked = !!reply.user_liked;
                    var replyLikeCount = parseInt(reply.like_count, 10) || 0;
                    var replyNameColor = (reply.author && reply.author.name_color) ? self.escapeHtml(reply.author.name_color) : '';
                    var replyColorStyle = replyNameColor ? ' style="color:' + replyNameColor + ';font-weight:600;"' : '';
                    html += '<div class="qibbs-reply" id="comment-' + reply.id + '" data-comment-id="' + reply.id + '"' + (reply.author && reply.author.id ? ' data-author-id="' + reply.author.id + '"' : '') + '>' +
                        '<a href="' + replyAuthorProfile + '" class="qibbs-reply-avatar"><img src="' + replyAuthorAvatar + '" alt=""></a>' +
                        '<div class="qibbs-reply-body">' +
                        '<div class="qibbs-reply-header-row" style="display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap;">' +
                        '<a href="' + replyAuthorProfile + '" class="qibbs-reply-author"' + replyColorStyle + '>' + replyAuthorName + '</a>' +
                        (reply.author && reply.author.cuid_badge ? reply.author.cuid_badge : '') +
                        (reply.author && reply.author.level_badge ? reply.author.level_badge : '') +
                        '</div>' +
                        '<span class="qibbs-reply-content">' + replyContent + '</span>' +
                        '<span class="qibbs-reply-time"' + ((reply.created_at_formatted || reply.created_at) ? ' title="' + self.escapeHtml(reply.created_at_formatted || reply.created_at) + '"' : '') + '>' + reply.time_ago + '</span>' +
                        (replyCanLike ? '<button class="qibbs-comment-like-btn qibbs-reply-like-btn ' + (replyUserLiked ? 'active' : '') + '" data-id="' + reply.id + '" data-post-id="' + postId + '">' +
                            '<span class="qibbs-comment-like-label">' + self.escapeHtml(replyUserLiked ? ((qibbsData.strings && qibbsData.strings.likedComment) ? qibbsData.strings.likedComment : '已赞') : ((qibbsData.strings && qibbsData.strings.likeComment) ? qibbsData.strings.likeComment : '赞')) + '</span>' +
                            '<span class="qibbs-comment-like-count">' + (replyLikeCount > 0 ? replyLikeCount : '') + '</span>' +
                            '</button>' : '') +
                        '</div>' +
                        '</div>';
                });
                if (comment.reply_count > 5) {
                    html += '<div class="qibbs-more-replies">还有 ' + (comment.reply_count - 5) + ' 条回复</div>';
                }
                html += '</div>';
            }

            // 二级回复表单容器（默认隐藏）
            html += '<div class="qibbs-reply-form-container" style="display:none;"></div>';

            html += '</div></div>';
            return html;
        },

        handleCommentSubmit: function (e) {
            e.preventDefault();

            var $form = $(e.currentTarget);
            var postId = $form.find('[name="post_id"]').val();
            var $input = $form.find('[name="content"]');
            var content = $input.val().trim();
            var self = this;

            if (!content) return;

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_add_comment',
                    nonce: qibbsData.nonce,
                    post_id: postId,
                    content: content
                },
                success: function (response) {
                    if (response.success) {
                        $input.val('');
                        var $container = $form.closest('.qibbs-comments-section');
                        self.loadComments(postId, $container);

                        var $btn = $form.closest('.qibbs-feed-card').find('.qibbs-comment-btn .count');
                        var count = parseInt($btn.text()) || 0;
                        $btn.text(count + 1);

                        // 红包领取祝贺或发帖人提示并实时刷新卡片状态
                        if (response.data && response.data.redpacket) {
                            var rp = response.data.redpacket;
                            if (rp.claimed) {
                                self.showToast(rp.message || ('恭喜获得 ' + rp.amount + ' 积分红包！'));
                                if (typeof rp.remaining_num !== 'undefined') {
                                    var $banner = $('.qibbs-banner-redpacket[data-post-id="' + postId + '"]');
                                    if ($banner.length) {
                                        var $badge = $banner.find('.qibbs-banner-status-badge');
                                        var badgeText = $badge.text() || '';
                                        var totalMatch = badgeText.match(/\/(\d+)/);
                                        var totalNum = totalMatch ? parseInt(totalMatch[1], 10) : 0;
                                        if (rp.remaining_num <= 0) {
                                            $banner.removeClass('is-active').addClass('is-finished');
                                            $badge.removeClass('is-active').addClass('is-finished').text('已抢完');
                                            $banner.find('.qibbs-banner-desc').html('<span>红包已被抢完，可查看手气榜</span>');
                                        } else if (totalNum > 0) {
                                            $badge.text('剩余 ' + rp.remaining_num + '/' + totalNum + ' 份');
                                            $banner.find('.qibbs-banner-desc').html('<span><span class="qibbs-banner-owner-note">您已领过该红包（每人限领 1 份）</span></span>');
                                        }
                                    }
                                }
                            } else if (rp.notice) {
                                self.showToast(rp.notice);
                            }
                        }
                    } else {
                        self.showToast(response.data.message, 'error');
                    }
                }
            });
        },

        handleReplySubmit: function (e) {
            e.preventDefault();

            var $form = $(e.currentTarget);
            var parentId = $form.data('parent-id');
            var postId = $form.data('post-id');
            var $input = $form.find('[name="reply_content"]');
            var content = $input.val().trim();
            var self = this;

            if (!content) return;

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_add_comment',
                    nonce: qibbsData.nonce,
                    post_id: postId,
                    content: content,
                    parent_id: parentId
                },
                success: function (response) {
                    if (response.success) {
                        $input.val('');
                        var $container = $form.closest('.qibbs-comments-section');
                        self.loadComments(postId, $container);

                        var $btn = $form.closest('.qibbs-feed-card').find('.qibbs-comment-btn .count');
                        var count = parseInt($btn.text()) || 0;
                        $btn.text(count + 1);
                    } else {
                        self.showToast(response.data.message, 'error');
                    }
                }
            });
        },

        handleDeleteComment: function (e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var commentId = $btn.data('id');
            var $comment = $btn.closest('.qibbs-comment');
            var self = this;

            this.showConfirm({
                title: '删除评论',
                message: '确定要删除这条评论吗？此操作无法撤销。'
            }, function (confirmed) {
                if (!confirmed) return;

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_delete_comment',
                        nonce: qibbsData.nonce,
                        comment_id: commentId
                    },
                    success: function (response) {
                        if (response.success) {
                            var postId = parseInt($comment.closest('.qibbs-feed-card').data('post-id'), 10) || 0;
                            if (postId) {
                                self.refreshCommentSection($btn, postId, response.data && response.data.discussion_status ? response.data.discussion_status : '');
                            } else {
                                $comment.fadeOut(200, function () { $(this).remove(); });
                            }
                            if (response.data && response.data.message) {
                                self.showToast(response.data.message);
                            }
                        }
                    }
                });
            });
        },

        handleBlockComment: function (e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var commentId = $btn.data('id');
            var $comment = $btn.closest('.qibbs-comment');
            var self = this;

            this.showConfirm({
                title: '屏蔽评论',
                message: '确定要屏蔽这条评论吗？此操作将隐藏该评论。',
                type: 'warning'
            }, function (confirmed) {
                if (!confirmed) return;

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_block_comment',
                        nonce: qibbsData.nonce,
                        comment_id: commentId
                    },
                    success: function (response) {
                        if (response.success) {
                            var postId = parseInt($comment.closest('.qibbs-feed-card').data('post-id'), 10) || 0;
                            if (postId) {
                                self.refreshCommentSection($btn, postId, response.data && response.data.discussion_status ? response.data.discussion_status : '');
                            } else {
                                $comment.fadeOut(200, function () { $(this).remove(); });
                            }
                            if (response.data && response.data.message) {
                                self.showToast(response.data.message);
                            }
                        }
                    }
                });
            });
        },

        handleCommentLikeClick: function (e) {
            e.preventDefault();

            if (!qibbsData.isLoggedIn) {
                this.showToast(qibbsData.strings.loginRequired, 'error');
                return;
            }

            var $btn = $(e.currentTarget);
            if ($btn.prop('disabled')) {
                return;
            }

            var commentId = parseInt($btn.data('id'), 10) || 0;
            if (!commentId) {
                return;
            }

            var self = this;
            $btn.prop('disabled', true);

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_toggle_comment_like',
                    nonce: qibbsData.nonce,
                    comment_id: commentId
                },
                success: function (response) {
                    if (response && response.success && response.data) {
                        var liked = !!response.data.liked;
                        var likeCount = parseInt(response.data.like_count, 10) || 0;
                        $btn.toggleClass('active', liked);
                        $btn.find('.qibbs-comment-like-label').text(liked
                            ? ((qibbsData.strings && qibbsData.strings.likedComment) ? qibbsData.strings.likedComment : '已赞')
                            : ((qibbsData.strings && qibbsData.strings.likeComment) ? qibbsData.strings.likeComment : '赞'));
                        $btn.find('.qibbs-comment-like-count').text(likeCount > 0 ? likeCount : '');
                    } else {
                        self.showToast((response && response.data && response.data.message) ? response.data.message : qibbsData.strings.error, 'error');
                    }
                },
                error: function () {
                    self.showToast(qibbsData.strings.error, 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        },

        handleSetBestAnswer: function (e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var commentId = parseInt($btn.data('id'), 10) || 0;
            var postId = parseInt($btn.data('post-id'), 10) || 0;
            var self = this;

            if (!commentId || !postId) {
                return;
            }

            this.showConfirm({
                title: (qibbsData.strings && qibbsData.strings.setBestAnswer) ? qibbsData.strings.setBestAnswer : '最佳答案',
                message: (qibbsData.strings && qibbsData.strings.bestAnswerConfirm) ? qibbsData.strings.bestAnswerConfirm : '确定将该回复设为最佳答案吗？'
            }, function (confirmed) {
                if (!confirmed) return;

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_set_best_answer',
                        nonce: qibbsData.nonce,
                        post_id: postId,
                        comment_id: commentId
                    },
                    success: function (response) {
                        if (response.success) {
                            self.refreshCommentSection($btn, postId, response.data && response.data.discussion_status ? response.data.discussion_status : 'resolved');
                            self.showToast((response.data && response.data.message) ? response.data.message : '已设为最佳答案');
                        } else {
                            self.showToast((response.data && response.data.message) ? response.data.message : qibbsData.strings.error, 'error');
                        }
                    },
                    error: function () {
                        self.showToast(qibbsData.strings.error, 'error');
                    }
                });
            });
        },

        handleClearBestAnswer: function (e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var postId = parseInt($btn.data('post-id'), 10) || 0;
            var self = this;

            if (!postId) {
                return;
            }

            this.showConfirm({
                title: (qibbsData.strings && qibbsData.strings.clearBestAnswer) ? qibbsData.strings.clearBestAnswer : '取消最佳',
                message: (qibbsData.strings && qibbsData.strings.clearBestAnswerConfirm) ? qibbsData.strings.clearBestAnswerConfirm : '确定取消当前最佳答案吗？'
            }, function (confirmed) {
                if (!confirmed) return;

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_clear_best_answer',
                        nonce: qibbsData.nonce,
                        post_id: postId
                    },
                    success: function (response) {
                        if (response.success) {
                            var nextStatus = response.data && response.data.discussion_status ? response.data.discussion_status : 'discussion';
                            self.refreshCommentSection($btn, postId, nextStatus);
                            self.showToast((response.data && response.data.message) ? response.data.message : '已取消最佳答案');
                        } else {
                            self.showToast((response.data && response.data.message) ? response.data.message : qibbsData.strings.error, 'error');
                        }
                    },
                    error: function () {
                        self.showToast(qibbsData.strings.error, 'error');
                    }
                });
            });
        },

        handleToggleQualityPin: function (e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var commentId = parseInt($btn.data('id'), 10) || 0;
            var postId = parseInt($btn.data('post-id'), 10) || 0;
            var self = this;

            if (!commentId || !postId) {
                return;
            }

            var isPinned = $btn.closest('.qibbs-comment').find('.qibbs-comment-badge-quality').length > 0;

            this.showConfirm({
                title: isPinned
                    ? ((qibbsData.strings && qibbsData.strings.unpinQualityReply) ? qibbsData.strings.unpinQualityReply : '取消置顶')
                    : ((qibbsData.strings && qibbsData.strings.pinQualityReply) ? qibbsData.strings.pinQualityReply : '置顶'),
                message: isPinned
                    ? ((qibbsData.strings && qibbsData.strings.unpinQualityConfirm) ? qibbsData.strings.unpinQualityConfirm : '确定取消该优质置顶吗？')
                    : ((qibbsData.strings && qibbsData.strings.pinQualityConfirm) ? qibbsData.strings.pinQualityConfirm : '确定置顶该优质回复吗？')
            }, function (confirmed) {
                if (!confirmed) return;

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_toggle_quality_pin',
                        nonce: qibbsData.nonce,
                        comment_id: commentId
                    },
                    success: function (response) {
                        if (response.success) {
                            self.refreshCommentSection($btn, postId);
                            self.showToast((response.data && response.data.message) ? response.data.message : '操作成功');
                        } else {
                            self.showToast((response.data && response.data.message) ? response.data.message : qibbsData.strings.error, 'error');
                        }
                    },
                    error: function () {
                        self.showToast(qibbsData.strings.error, 'error');
                    }
                });
            });
        },

        handleReopenQuestionClick: function (e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var postId = parseInt($btn.data('post-id'), 10) || 0;
            var self = this;

            if (!postId) {
                return;
            }

            this.showConfirm({
                title: (qibbsData.strings && qibbsData.strings.reopenQuestion) ? qibbsData.strings.reopenQuestion : '追问/重开',
                message: (qibbsData.strings && qibbsData.strings.reopenQuestionConfirm) ? qibbsData.strings.reopenQuestionConfirm : '确定要重开该讨论吗？'
            }, function (confirmed) {
                if (!confirmed) return;

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_reopen_post_question',
                        nonce: qibbsData.nonce,
                        post_id: postId
                    },
                    success: function (response) {
                        if (response.success) {
                            self.refreshCommentSection($btn, postId, response.data && response.data.discussion_status ? response.data.discussion_status : 'discussion');
                            self.showToast((response.data && response.data.message) ? response.data.message : '已重开讨论');
                        } else {
                            self.showToast((response.data && response.data.message) ? response.data.message : qibbsData.strings.error, 'error');
                        }
                    },
                    error: function () {
                        self.showToast(qibbsData.strings.error, 'error');
                    }
                });
            });
        },

        refreshCommentSection: function ($trigger, postId, discussionStatus) {
            var $container = $trigger.closest('.qibbs-comments-section');
            if ($container.length) {
                this.loadComments(postId, $container, 1);
            }

            if (discussionStatus) {
                var $card = $trigger.closest('.qibbs-feed-card');
                if ($card.length) {
                    this.updateCardDiscussionStatus($card, discussionStatus);
                }
            }
        },

        handleReplyClick: function (e) {
            e.preventDefault();

            if (!qibbsData.isLoggedIn) {
                this.showToast(qibbsData.strings.loginRequired, 'error');
                return;
            }

            var $btn = $(e.currentTarget);
            var commentId = $btn.data('id');
            var authorName = $btn.data('author');
            var postId = $btn.data('post-id');
            var safeAuthorName = this.escapeHtml(authorName || '');
            var $comment = $btn.closest('.qibbs-comment');
            var $container = $comment.find('.qibbs-reply-form-container');

            // 切换表单显隐
            if ($container.is(':visible')) {
                $container.slideUp(200).empty();
                return;
            }

            // 关闭其它已打开的回复表单
            $('.qibbs-reply-form-container').slideUp(200).empty();

            // 构建并插入回复表单
            var replyHint = this.getString('inlineReplyHint', '支持 Ctrl/Command + Enter 快速回复');
            var formHtml = '<form class="qibbs-reply-form" data-parent-id="' + commentId + '" data-post-id="' + postId + '">' +
                '<div class="qibbs-reply-form-row">' +
                '<input type="text" name="reply_content" placeholder="回复 @' + safeAuthorName + '..." autocomplete="off">' +
                '<button type="submit">发送</button>' +
                '</div>' +
                (replyHint ? '<div class="qibbs-reply-form-hint">' + this.escapeHtml(replyHint) + '</div>' : '') +
                '</form>';
            $container.html(formHtml).slideDown(200);
            $container.find('input').focus();
        },

        handleCommentPagination: function (e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var page = parseInt($btn.data('page'), 10);
            var $pagination = $btn.closest('.qibbs-comments-pagination');
            var postId = $pagination.data('post-id');
            var $container = $btn.closest('.qibbs-comments-section');

            this.loadComments(postId, $container, page);
        },

        handleFollowClick: function (e) {
            e.preventDefault();

            if (!qibbsData.isLoggedIn) {
                this.showToast(qibbsData.strings.loginRequired, 'error');
                return;
            }

            var $btn = $(e.currentTarget);
            var userId = $btn.data('user-id');

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_toggle_follow',
                    nonce: qibbsData.nonce,
                    user_id: userId
                },
                success: function (response) {
                    if (response.success) {
                        var isFollowing = response.data.following;
                        $btn.toggleClass('active', isFollowing);

                        // 更新按钮文案
                        var $textSpan = $btn.find('.qibbs-quick-btn-text');
                        if ($textSpan.length) {
                            // 快捷操作按钮（包含图标与文字）
                            $textSpan.text(isFollowing ? '已关注' : '关注');
                            $btn.attr('title', isFollowing ? '取消关注' : '关注');

                            // 更新 SVG 图标
                            var $svg = $btn.find('svg');
                            if ($svg.length) {
                                if (isFollowing) {
                                    $svg.html('<path d="M9 16.2l-4.2-4.2-1.4 1.4 5.6 5.6 12-12-1.4-1.4z"/>');
                                } else {
                                    $svg.html('<path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>');
                                }
                            }
                        } else {
                            // 基础按钮样式（个人主页风格）
                            $btn.text(isFollowing ? '已关注' : '关注');
                        }

                        // 触发关注状态切换事件
                        Qibbs.triggerEvent('follow:toggled', {
                            userId: userId,
                            following: isFollowing,
                            followerCount: response.data.follower_count
                        });
                    }
                }
            });
        },

        handleForumSubscribeClick: function (e) {
            e.preventDefault();
            e.stopPropagation();

            if (!qibbsData.isLoggedIn) {
                this.showToast(qibbsData.strings.loginRequired, 'error');
                return;
            }

            if (!qibbsData.enableForumSubscriptionNotifications) {
                this.showToast(
                    (qibbsData.strings && qibbsData.strings.forumSubscriptionDisabled) ?
                        qibbsData.strings.forumSubscriptionDisabled :
                        '圈子订阅通知已关闭',
                    'error'
                );
                return;
            }

            var $btn = $(e.currentTarget);
            if ($btn.prop('disabled')) {
                return;
            }

            var forumId = parseInt($btn.data('forum-id'), 10) || 0;
            if (!forumId) {
                return;
            }

            var self = this;
            $btn.prop('disabled', true);

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_toggle_forum_subscription',
                    nonce: qibbsData.nonce,
                    forum_id: forumId
                },
                success: function (response) {
                    if (response && response.success && response.data) {
                        var subscribed = !!response.data.subscribed;
                        $btn.toggleClass('is-subscribed', subscribed);
                        $btn.text(response.data.label || (subscribed ? '已订阅' : '订阅'));
                        self.showToast(response.data.message || '操作成功');
                    } else {
                        self.showToast(response && response.data && response.data.message ? response.data.message : qibbsData.strings.error, 'error');
                    }
                },
                error: function () {
                    self.showToast(qibbsData.strings.error, 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        },

        handleDeletePost: function (e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var postId = $btn.data('post-id');
            var $card = $btn.closest('.qibbs-feed-card');
            var self = this;

            this.showConfirm({
                title: '删除动态',
                message: '确定要删除这条动态吗？删除后将无法恢复。'
            }, function (confirmed) {
                if (!confirmed) return;

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_delete_post',
                        nonce: qibbsData.nonce,
                        post_id: postId
                    },
                    success: function (response) {
                        if (response.success) {
                            $card.fadeOut(300, function () { $(this).remove(); });
                            self.showToast(response.data.message);
                        } else {
                            self.showToast(response.data.message, 'error');
                        }
                    }
                });
            });
        },

        handleBlockPost: function (e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var postId = $btn.data('post-id');
            var $card = $btn.closest('.qibbs-feed-card');
            var self = this;

            this.showConfirm({
                title: '屏蔽动态',
                message: '确定要屏蔽这条动态吗？屏蔽后将不再显示给其他用户。',
                type: 'warning'
            }, function (confirmed) {
                if (!confirmed) return;

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_block_post',
                        nonce: qibbsData.nonce,
                        post_id: postId
                    },
                    success: function (response) {
                        if (response.success) {
                            $card.fadeOut(300, function () { $(this).remove(); });
                            self.showToast(response.data.message);
                        } else {
                            self.showToast(response.data.message, 'error');
                        }
                    }
                });
            });
        },

        handleDiscussionStatusUpdate: function (e) {
            e.preventDefault();
            e.stopPropagation();

            var $btn = $(e.currentTarget);
            var postId = parseInt($btn.data('post-id'), 10) || 0;
            var newStatus = ($btn.data('status') || '').toString();
            var $card = $btn.closest('.qibbs-feed-card');
            var self = this;

            if (!postId || !newStatus) {
                return;
            }

            var labelMap = {
                discussion: (qibbsData.strings && qibbsData.strings.statusToDiscussion) ? qibbsData.strings.statusToDiscussion : '设为讨论中',
                resolved: (qibbsData.strings && qibbsData.strings.statusToResolved) ? qibbsData.strings.statusToResolved : '标记为已解决',
                closed: (qibbsData.strings && qibbsData.strings.statusToClosed) ? qibbsData.strings.statusToClosed : '关闭讨论'
            };
            var confirmPrefix = (qibbsData.strings && qibbsData.strings.discussionStatusConfirmPrefix) ? qibbsData.strings.discussionStatusConfirmPrefix : '确定执行：';
            var confirmTitle = (qibbsData.strings && qibbsData.strings.discussionStatusTitle) ? qibbsData.strings.discussionStatusTitle : '更新讨论状态';

            $('.qibbs-menu-dropdown').removeClass('show');

            this.showConfirm({
                title: confirmTitle,
                message: confirmPrefix + (labelMap[newStatus] || newStatus) + ' ?'
            }, function (confirmed) {
                if (!confirmed) return;

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_update_post_discussion_status',
                        nonce: qibbsData.nonce,
                        post_id: postId,
                        discussion_status: newStatus
                    },
                    success: function (response) {
                        if (response.success) {
                            self.updateCardDiscussionStatus($card, response.data.discussion_status || newStatus);
                            var $comments = $card.find('.qibbs-comments-section:visible');
                            if ($comments.length) {
                                self.loadComments(postId, $comments, 1);
                            }
                            self.showToast(response.data.message || qibbsData.strings.discussionStatusUpdated || '讨论状态已更新');
                        } else {
                            self.showToast((response.data && response.data.message) ? response.data.message : (qibbsData.strings.discussionStatusUpdateFailed || '讨论状态更新失败'), 'error');
                        }
                    },
                    error: function () {
                        self.showToast(qibbsData.strings.discussionStatusUpdateFailed || '讨论状态更新失败', 'error');
                    }
                });
            });
        },

        updateCardDiscussionStatus: function ($card, status) {
            var $meta = $card.find('.qibbs-card-info');
            if (!$meta.length) {
                return;
            }
            var labelDiscussion = (qibbsData.strings && qibbsData.strings.statusLabelDiscussion) ? qibbsData.strings.statusLabelDiscussion : '讨论中';
            var labelResolved = (qibbsData.strings && qibbsData.strings.statusLabelResolved) ? qibbsData.strings.statusLabelResolved : '已解决';
            var labelClosed = (qibbsData.strings && qibbsData.strings.statusLabelClosed) ? qibbsData.strings.statusLabelClosed : '已关闭';
            var closedNote = (qibbsData.strings && qibbsData.strings.discussionClosedNote) ? qibbsData.strings.discussionClosedNote : '该动态已关闭讨论，暂不支持新评论。';

            var $oldBadge = $meta.find('.qibbs-discussion-badge, .qibbs-resolved-badge, .qibbs-closed-badge');
            var $oldSeparator = $oldBadge.prev('.qibbs-separator');
            $oldBadge.remove();
            if ($oldSeparator.length) {
                $oldSeparator.remove();
            }

            var badgeHtml = '';
            if (status === 'resolved') {
                badgeHtml = '<span class="qibbs-separator">·</span>' +
                    '<span class="qibbs-resolved-badge qibbs-discussion-badge" title="' + this.escapeHtml(labelResolved) + '">' +
                    '<svg viewBox="0 0 24 24" width="12" height="12"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"></path></svg>' + this.escapeHtml(labelResolved) + '</span>';
            } else if (status === 'closed') {
                badgeHtml = '<span class="qibbs-separator">·</span>' +
                    '<span class="qibbs-closed-badge qibbs-discussion-badge" title="' + this.escapeHtml(labelClosed) + '">' +
                    '<svg viewBox="0 0 24 24" width="12" height="12"><path d="M12 1a5 5 0 0 0-5 5v3H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2h-1V6a5 5 0 0 0-5-5zm-3 8V6a3 3 0 0 1 6 0v3H9z"></path></svg>' + this.escapeHtml(labelClosed) + '</span>';
            } else {
                badgeHtml = '<span class="qibbs-separator">·</span><span class="qibbs-discussion-badge" title="' + this.escapeHtml(labelDiscussion) + '">' + this.escapeHtml(labelDiscussion) + '</span>';
            }

            // 优先插入在文章徽章之前，否则追加到末尾
            var $articleBadge = $meta.find('.qibbs-article-badge').first();
            if ($articleBadge.length) {
                $articleBadge.before(badgeHtml);
            } else {
                $meta.append(badgeHtml);
            }

            $card.find('.qibbs-discussion-status-btn').removeClass('is-active');
            $card.find('.qibbs-discussion-status-btn[data-status="' + status + '"]').addClass('is-active');

            var closed = status === 'closed';
            var $commentForm = $card.find('.qibbs-comment-form');
            var $closedNote = $card.find('.qibbs-comments-closed-note');
            if (closed) {
                if ($commentForm.length) {
                    $commentForm.hide();
                }
                if (!$closedNote.length) {
                    $card.find('.qibbs-comments-section').append('<div class="qibbs-comments-closed-note">' + this.escapeHtml(closedNote) + '</div>');
                }
            } else {
                if ($closedNote.length) {
                    $closedNote.remove();
                }
                if ($commentForm.length) {
                    $commentForm.show();
                }
            }
        },

        // 处理加精操作（管理员）
        handleFeaturePost: function (e) {
            e.preventDefault();
            e.stopPropagation();

            var $btn = $(e.currentTarget);
            var postId = $btn.data('post-id');
            var self = this;

            // 先关闭下拉操作菜单
            $('.qibbs-menu-dropdown').removeClass('show');

            // 弹出加精时长选择框
            this.showFeatureDialog(postId, function (days) {
                if (days === null || days === undefined) return; // 用户已取消

                $.ajax({
                    url: qibbsData.restUrl + 'post/' + postId + '/feature',
                    type: 'POST',
                    headers: { 'X-WP-Nonce': qibbsData.restNonce },
                    data: { days: days },
                    success: function (response) {
                        if (response.success) {
                            self.showToast(qibbsData.strings.featureSuccess || response.data.message);
                            // 切换按钮为取消加精
                            $btn.removeClass('qibbs-feature-btn').addClass('qibbs-unfeature-btn');
                            $btn.find('path').attr('d', 'M22 9.24l-7.19-.62L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.63-7.03L22 9.24zM12 15.4l-3.76 2.27 1-4.28-3.32-2.88 4.38-.38L12 6.1l1.71 4.04 4.38.38-3.32 2.88 1 4.28L12 15.4z');
                            $btn.contents().filter(function () { return this.nodeType === 3; }).first().replaceWith('取消推荐');
                        } else {
                            self.showToast(response.message || '操作失败', 'error');
                        }
                    },
                    error: function () {
                        self.showToast('操作失败', 'error');
                    }
                });
            });
        },

        // 处理取消加精操作（管理员）
        handleUnfeaturePost: function (e) {
            e.preventDefault();
            e.stopPropagation();

            var $btn = $(e.currentTarget);
            var postId = $btn.data('post-id');
            var self = this;

            // 先关闭下拉操作菜单
            $('.qibbs-menu-dropdown').removeClass('show');

            $.ajax({
                url: qibbsData.restUrl + 'post/' + postId + '/feature',
                type: 'DELETE',
                headers: { 'X-WP-Nonce': qibbsData.restNonce },
                success: function (response) {
                    if (response.success) {
                        self.showToast(qibbsData.strings.unfeatureSuccess || response.data.message);
                        // 切换按钮为加精
                        $btn.removeClass('qibbs-unfeature-btn').addClass('qibbs-feature-btn');
                        $btn.find('path').attr('d', 'M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z');
                        $btn.contents().filter(function () { return this.nodeType === 3; }).first().replaceWith('推荐');
                    } else {
                        self.showToast(response.message || '操作失败', 'error');
                    }
                },
                error: function () {
                    self.showToast('操作失败', 'error');
                }
            });
        },

        showFeatureDialog: function (postId, callback) {
            $('.qibbs-feature-dialog-overlay').remove();

            var dialogHtml =
                '<div class="qibbs-feature-dialog-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;z-index:999999;backdrop-filter:blur(4px);">' +
                '<div class="qibbs-feature-dialog" style="background:var(--qibbs-bg-card,#fff);border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);max-width:400px;width:90%;margin:20px;animation:fadeIn 0.2s ease-out;">' +
                '<div style="display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--qibbs-border,#e2e8f0);">' +
                '<h3 style="margin:0;font-size:18px;font-weight:600;color:var(--qibbs-text,#0f172a);">推荐动态</h3>' +
                '<button type="button" class="qibbs-modal-close" style="width:32px;height:32px;border:none;background:transparent;font-size:24px;cursor:pointer;color:var(--qibbs-text-muted,#94a3b8);border-radius:9999px;">&times;</button></div>' +
                '<div style="padding:22px;"><p style="margin:0 0 16px;color:var(--qibbs-text-secondary,#475569);">请选择推荐时长：</p>' +
                '<div class="qibbs-feature-days-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">' +
                '<label style="display:flex;align-items:center;justify-content:center;padding:12px 16px;background:var(--qibbs-bg,#f8fafc);border:2px solid var(--qibbs-border,#e2e8f0);border-radius:10px;cursor:pointer;font-weight:600;color:var(--qibbs-text,#0f172a);transition:all 0.2s;" data-days="3">3天</label>' +
                '<label style="display:flex;align-items:center;justify-content:center;padding:12px 16px;background:var(--qibbs-bg,#f8fafc);border:2px solid var(--qibbs-border,#e2e8f0);border-radius:10px;cursor:pointer;font-weight:600;color:var(--qibbs-text,#0f172a);transition:all 0.2s;" data-days="7">7天</label>' +
                '<label style="display:flex;align-items:center;justify-content:center;padding:12px 16px;background:var(--qibbs-bg,#f8fafc);border:2px solid var(--qibbs-border,#e2e8f0);border-radius:10px;cursor:pointer;font-weight:600;color:var(--qibbs-text,#0f172a);transition:all 0.2s;" data-days="14">14天</label>' +
                '<label style="display:flex;align-items:center;justify-content:center;padding:12px 16px;background:var(--qibbs-bg,#f8fafc);border:2px solid var(--qibbs-border,#e2e8f0);border-radius:10px;cursor:pointer;font-weight:600;color:var(--qibbs-text,#0f172a);transition:all 0.2s;" data-days="30">30天</label>' +
                '<label style="display:flex;align-items:center;justify-content:center;padding:12px 16px;background:var(--qibbs-bg,#f8fafc);border:2px solid var(--qibbs-border,#e2e8f0);border-radius:10px;cursor:pointer;font-weight:600;color:var(--qibbs-text,#0f172a);transition:all 0.2s;grid-column:span 2;" data-days="0">永久推荐</label>' +
                '</div></div>' +
                '<div style="display:flex;justify-content:flex-end;gap:12px;padding:18px 22px;border-top:1px solid var(--qibbs-border,#e2e8f0);">' +
                '<button type="button" class="qibbs-feature-cancel" style="padding:10px 20px;border-radius:10px;font-weight:500;font-size:14px;cursor:pointer;background:var(--qibbs-bg,#f8fafc);color:var(--qibbs-text-secondary,#475569);border:1px solid var(--qibbs-border,#e2e8f0);">取消</button>' +
                '<button type="button" class="qibbs-feature-confirm" style="padding:10px 20px;border-radius:10px;font-weight:500;font-size:14px;cursor:pointer;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:white;border:none;box-shadow:0 4px 12px -2px rgba(99,102,241,0.4);">确定推荐</button>' +
                '</div></div></div>';

            var $dialog = $(dialogHtml).appendTo('body');
            var selectedDays = 3;

            $dialog.find('.qibbs-feature-days-grid label').first().css({ 'background': 'linear-gradient(135deg, #6366f1, #8b5cf6)', 'color': 'white', 'border-color': 'transparent' });
            $dialog.find('.qibbs-feature-days-grid label').on('click', function () {
                selectedDays = parseInt($(this).data('days'), 10);
                $dialog.find('.qibbs-feature-days-grid label').css({ 'background': 'var(--qibbs-bg, #f8fafc)', 'color': 'var(--qibbs-text, #0f172a)', 'border-color': 'var(--qibbs-border, #e2e8f0)' });
                $(this).css({ 'background': 'linear-gradient(135deg, #6366f1, #8b5cf6)', 'color': 'white', 'border-color': 'transparent' });
            });
            $dialog.on('click', function (e) {
                if ($(e.target).hasClass('qibbs-feature-dialog-overlay')) { $dialog.remove(); callback(null); }
            });
            $dialog.find('.qibbs-modal-close, .qibbs-feature-cancel').on('click', function (e) {
                e.preventDefault(); $dialog.remove(); callback(null);
            });
            $dialog.find('.qibbs-feature-confirm').on('click', function (e) {
                e.preventDefault(); $dialog.remove(); callback(selectedDays);
            });
        },

        handleMenuToggle: function (e) {
            e.stopPropagation();
            var $menu = $(e.currentTarget).siblings('.qibbs-menu-dropdown');
            $('.qibbs-menu-dropdown').not($menu).removeClass('show');
            $menu.toggleClass('show');
        },

        handleShare: function (e) {
            e.preventDefault();
            var url = $(e.currentTarget).data('url');

            if (navigator.share) {
                navigator.share({ url: url });
            } else if (navigator.clipboard) {
                navigator.clipboard.writeText(url);
                this.showToast('链接已复制');
            }
        },

        handleReportPost: function (e) {
            e.preventDefault();
            if (!qibbsData.isLoggedIn) {
                this.showToast(qibbsData.strings.loginRequired, 'error');
                return;
            }
            if (!qibbsData.enableReporting) {
                this.showToast('举报功能已关闭', 'error');
                return;
            }
            var postId = parseInt($(e.currentTarget).data('post-id'), 10) || 0;
            if (!postId) {
                return;
            }
            $('.qibbs-menu-dropdown').removeClass('show');
            this.showReportDialog(postId);
        },

        showReportDialog: function (postId) {
            $('.qibbs-report-modal-overlay').remove();

            var s = (window.qibbsData && qibbsData.strings) ? qibbsData.strings : {};
            var title = this.escapeHtml(s.reportTitle || '举报动态');
            var placeholder = this.escapeHtml(s.reportPlaceholder || '请描述举报原因');
            var submitText = this.escapeHtml(s.reportSubmit || '提交举报');
            var cancelText = this.escapeHtml(s.reportCancel || '取消');

            var html = '<div class="qibbs-report-modal-overlay"><div class="qibbs-report-modal">' +
                '<div class="qibbs-report-modal-header"><h3>' + title + '</h3><button type="button" class="qibbs-report-close">&times;</button></div>' +
                '<div class="qibbs-report-modal-body"><textarea id="qibbs-report-reason" rows="5" placeholder="' + placeholder + '"></textarea></div>' +
                '<div class="qibbs-report-modal-footer">' +
                '<button type="button" class="qibbs-btn qibbs-btn-secondary qibbs-report-cancel">' + cancelText + '</button>' +
                '<button type="button" class="qibbs-btn qibbs-btn-primary qibbs-report-submit" data-post-id="' + postId + '">' + submitText + '</button>' +
                '</div></div></div>';

            var self = this;
            var $modal = $(html).appendTo('body');
            $modal.find('#qibbs-report-reason').focus();
            $modal.on('click', '.qibbs-report-close, .qibbs-report-cancel', function (evt) { evt.preventDefault(); $modal.remove(); });
            $modal.on('click', function (evt) { if ($(evt.target).hasClass('qibbs-report-modal-overlay')) $modal.remove(); });
            $modal.on('click', '.qibbs-report-submit', function (evt) { evt.preventDefault(); self.submitReport($(this), $modal); });
        },

        submitReport: function ($btn, $modal) {
            var postId = parseInt($btn.data('post-id'), 10) || 0;
            var reason = $.trim($modal.find('#qibbs-report-reason').val() || '');
            if (!postId) {
                return;
            }
            if (reason.length < 2) {
                this.showToast('请填写举报原因', 'error');
                return;
            }

            var self = this;
            $btn.prop('disabled', true).text('提交中...');
            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_report_post',
                    nonce: qibbsData.nonce,
                    post_id: postId,
                    reason: reason
                },
                success: function (response) {
                    if (response && response.success) {
                        self.showToast(response.data && response.data.message ? response.data.message : '举报已提交');
                        $modal.remove();
                        if (response.data && response.data.auto_hidden) {
                            self.feed.page = 1;
                            self.loadFeed();
                        }
                    } else {
                        self.showToast(response && response.data && response.data.message ? response.data.message : qibbsData.strings.error, 'error');
                    }
                },
                error: function () {
                    self.showToast(qibbsData.strings.error, 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false).text((qibbsData.strings && qibbsData.strings.reportSubmit) ? qibbsData.strings.reportSubmit : '提交举报');
                }
            });
        },

        applyUnreadCounts: function (counts) {
            var normalized = counts || {};
            var notificationCount = parseInt(normalized.notifications, 10) || 0;
            var messageCount = parseInt(normalized.messages, 10) || 0;

            $('#qibbs-notification-badge, #qibbs-mobile-notification-badge').text(notificationCount > 0 ? String(notificationCount) : '');
            $('#qibbs-message-badge, #qibbs-mobile-message-badge').text(messageCount > 0 ? String(messageCount) : '');

            if (window.qibbsData) {
                qibbsData.unreadCounts = {
                    notifications: notificationCount,
                    messages: messageCount
                };
            }
        },

        loadUnreadCounts: function (forceRefresh) {
            if (!qibbsData.isLoggedIn) return;

            if (!forceRefresh && qibbsData.unreadCountsPreloaded) {
                this.applyUnreadCounts(qibbsData.unreadCounts || {});
                return;
            }

            var self = this;
            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_get_unread_counts',
                    nonce: qibbsData.nonce
                },
                success: function (response) {
                    if (!response.success) return;
                    self.applyUnreadCounts(response.data || {});
                    qibbsData.unreadCountsPreloaded = true;
                }
            });
        },

        loadNotificationCount: function (forceRefresh) {
            this.loadUnreadCounts(forceRefresh);
        },

        loadMessageCount: function (forceRefresh) {
            this.loadUnreadCounts(forceRefresh);
        },

        syncMobileNavState: function () {
            var $items = $('.qibbs-mobile-action-bar [data-filter-type="feed"]');
            if (!$items.length) {
                return;
            }

            $items.removeClass('active');

            var target = this.feed.type;
            if (this.feed.type === 'filter') {
                target = 'global';
            }

            var $target = $items.filter('[data-filter="' + target + '"]');
            if (!$target.length) {
                $target = $items.filter('[data-filter="global"]');
            }
            $target.addClass('active');
        },

        openMobileSheet: function (e) {
            if (e) {
                e.preventDefault();
            }

            this.refreshMobileBottomOffset();

            var $sheet = $('#qibbs-mobile-sheet');
            var $backdrop = $('#qibbs-mobile-sheet-backdrop');
            if (!$sheet.length || !$backdrop.length) {
                return;
            }

            $('body').addClass('qibbs-mobile-sheet-open');
            $backdrop.prop('hidden', false);
            $sheet.attr('aria-hidden', 'false');
        },

        closeMobileSheet: function (e) {
            if (e) {
                e.preventDefault();
            }

            var $sheet = $('#qibbs-mobile-sheet');
            var $backdrop = $('#qibbs-mobile-sheet-backdrop');
            if (!$sheet.length || !$backdrop.length) {
                return;
            }

            $('body').removeClass('qibbs-mobile-sheet-open');
            $sheet.attr('aria-hidden', 'true');
            setTimeout(function () {
                if (!$('body').hasClass('qibbs-mobile-sheet-open')) {
                    $backdrop.prop('hidden', true);
                }
            }, 260);
        },

        scheduleMobileBottomOffsetRefresh: function () {
            var self = this;
            if (this.mobileOffsetTimer) {
                clearTimeout(this.mobileOffsetTimer);
            }
            this.mobileOffsetTimer = setTimeout(function () {
                self.refreshMobileBottomOffset();
            }, 120);
        },

        refreshMobileBottomOffset: function () {
            var docEl = document.documentElement;
            if (!docEl) {
                return;
            }

            if (!window.matchMedia('(max-width: 900px)').matches) {
                docEl.style.setProperty('--qibbs-mobile-bottom-offset', '0px');
                return;
            }

            var selectors = ['.mobile-bottom-nav', '#privacy-banner', '#ds-bottom-banner'];
            var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
            var maxOffset = 0;

            selectors.forEach(function (selector) {
                var el = document.querySelector(selector);
                if (!el) {
                    return;
                }

                var style = window.getComputedStyle(el);
                if (!style || style.display === 'none' || style.visibility === 'hidden' || parseFloat(style.opacity || '1') === 0) {
                    return;
                }
                if (style.position !== 'fixed') {
                    return;
                }

                var rect = el.getBoundingClientRect();
                if (!rect || rect.height <= 1) {
                    return;
                }

                var distanceFromBottom = Math.max(0, viewportHeight - rect.bottom);
                if (distanceFromBottom > 48) {
                    return;
                }

                var candidate = Math.ceil(rect.height + distanceFromBottom);
                if (candidate > maxOffset) {
                    maxOffset = candidate;
                }
            });

            docEl.style.setProperty('--qibbs-mobile-bottom-offset', String(maxOffset) + 'px');
        },

        openMobileStylePicker: function (e) {
            if (e) {
                e.preventDefault();
            }

            this.openMobileSheet();
            var $container = $('#qibbs-mobile-sheet .qibbs-mobile-sheet-body');
            var $target = $('#qibbs-mobile-style-group');
            if (!$container.length || !$target.length) {
                return;
            }

            setTimeout(function () {
                var top = Math.max($target.position().top - 8, 0);
                $container.stop(true).animate({ scrollTop: top }, 220);
                $target.addClass('is-highlight');
                setTimeout(function () {
                    $target.removeClass('is-highlight');
                }, 760);
            }, 120);
        },

        handleMobileCompose: function (e) {
            if (e) {
                e.preventDefault();
            }

            if (!qibbsData.isLoggedIn) {
                var $loginTrigger = $('.qibbs-login-modal-trigger').first();
                if ($loginTrigger.length) {
                    $loginTrigger.trigger('click');
                    return;
                }
                window.location.href = '/wp-login.php?redirect_to=' + encodeURIComponent(window.location.href);
                return;
            }

            var $composer = $('.qibbs-composer').first();
            var $input = $('#qibbs-post-content').first();
            if (!$composer.length || !$input.length) {
                this.showToast('当前页面未启用发布器', 'error');
                return;
            }

            this.closeMobileSheet();
            $('html, body').stop(true).animate({
                scrollTop: Math.max($composer.offset().top - 14, 0)
            }, 260);

            $composer.addClass('qibbs-mobile-compose-focus');
            setTimeout(function () {
                $composer.removeClass('qibbs-mobile-compose-focus');
            }, 1200);

            setTimeout(function () {
                $input.trigger('focus');
            }, 280);
        },

        getThemeStyleMap: function () {
            return {
                'default': '默认',
                'fresh': '小清新',
                'tech': '科技',
                'gaming': '游戏'
            };
        },

        getThemeStorageKey: function () {
            var userPart = (qibbsData && qibbsData.currentUser) ? String(qibbsData.currentUser) : 'guest';
            return 'qibbs_theme_style_v1_' + userPart;
        },

        initThemeStyleSwitcher: function () {
            var styleMap = this.getThemeStyleMap();
            var defaultStyle = (qibbsData && qibbsData.themeStyle && styleMap[qibbsData.themeStyle]) ? qibbsData.themeStyle : 'default';
            var key = this.getThemeStorageKey();
            var saved = null;

            try {
                saved = localStorage.getItem(key);
            } catch (e) {
                saved = null;
            }

            var active = (saved && styleMap[saved]) ? saved : defaultStyle;
            this.applyThemeStyle(active, false);
        },

        toggleThemeStylePanel: function (e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            $('#qibbs-theme-style-panel').toggleClass('is-open');
        },

        handleThemeStyleChange: function (e) {
            if (e) {
                e.preventDefault();
            }
            var style = String($(e.currentTarget).data('theme-style') || '').trim();
            if (!style) {
                return;
            }

            this.applyThemeStyle(style, true);
            $('#qibbs-theme-style-panel').removeClass('is-open');

            if ($(e.currentTarget).closest('#qibbs-mobile-sheet').length) {
                this.closeMobileSheet();
            }
        },

        applyThemeStyle: function (style, persist) {
            var styleMap = this.getThemeStyleMap();
            if (!styleMap[style]) {
                style = 'default';
            }

            this.themeStyle = style;
            var attrStyle = (style === 'default') ? '' : style;

            // 全局同步更新到 html, body, 社区容器以及交易大厅容器
            if (attrStyle) {
                document.documentElement.setAttribute('data-qibbs-theme', attrStyle);
                document.body.setAttribute('data-qibbs-theme', attrStyle);
                $('.qibbs-wrapper, #qibbs-vanity-mall-container, .qibbs-vanity-modal').attr('data-qibbs-theme', attrStyle);
            } else {
                document.documentElement.removeAttribute('data-qibbs-theme');
                document.body.removeAttribute('data-qibbs-theme');
                $('.qibbs-wrapper, #qibbs-vanity-mall-container, .qibbs-vanity-modal').removeAttr('data-qibbs-theme');
            }

            $('[data-theme-style]').removeClass('is-active');
            $('[data-theme-style="' + style + '"]').addClass('is-active');
            $('#qibbs-theme-style-current').text(styleMap[style]);

            $(document).trigger('qibbs:theme-style-changed', [style]);

            if (!persist) {
                return;
            }

            try {
                localStorage.setItem(this.getThemeStorageKey(), style);
            } catch (e) {
                // 静默捕获 localStorage 异常
            }
        },

        // 每日签到打卡交互
        initCheckinWidget: function () {
            var self = this;
            var $widget = $('.qibbs-checkin-widget, #qibbs-checkin-widget');
            if (!$widget.length) return;

            $(document).on('click', '.qibbs-checkin-btn, #qibbs-checkin-btn', function (e) {
                var $btn = $(this);
                if ($btn.prop('disabled') || $btn.hasClass('is-completed')) {
                    return;
                }

                if (!qibbsData.isLoggedIn) {
                    return;
                }

                e.preventDefault();
                var $allBtns = $('.qibbs-checkin-btn, #qibbs-checkin-btn');
                var originalHtml = $btn.html();
                $allBtns.prop('disabled', true).addClass('loading');
                $allBtns.find('.qibbs-btn-loading-spinner').show();
                $allBtns.find('.qibbs-checkin-btn-label, .qibbs-btn-text').text('打卡中...');

                $.ajax({
                    url: qibbsData.restUrl + 'user/checkin',
                    type: 'POST',
                    beforeSend: function (xhr) {
                        if (qibbsData.restNonce) {
                            xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                        }
                    },
                    success: function (res) {
                        if (res && res.success && res.data) {
                            var data = res.data;
                            self.showToast(data.message || '签到成功！', 'success');

                            // 更新所有按钮为已完成状态
                            $allBtns.removeClass('qibbs-btn-primary loading')
                                .addClass('is-completed')
                                .prop('disabled', true)
                                .html('<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg><span class="qibbs-checkin-btn-label">今日已签到</span>');

                            // 更新所有连续签到天数角标
                            var streak = data.consecutive_days || 1;
                            $('.qibbs-checkin-streak-badge, #qibbs-checkin-streak-badge').addClass('is-checked').text('已连签 ' + streak + ' 天');

                            // 更新所有阶梯完成状态与图标
                            var effectiveStreak = (streak % 7 === 0 && streak > 0) ? 7 : (streak % 7);
                            $('.qibbs-checkin-ladder, #qibbs-checkin-ladder').each(function () {
                                $(this).find('.qibbs-ladder-step').each(function () {
                                    var day = parseInt($(this).attr('data-day'), 10);
                                    $(this).removeClass('is-today is-future is-done');
                                    if (day <= effectiveStreak) {
                                        $(this).addClass('is-done').find('.qibbs-step-icon').html('<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>');
                                    } else {
                                        $(this).addClass('is-future');
                                    }
                                });
                            });

                            // 更新所有提示文案
                            if (data.status && data.status.tomorrow_points) {
                                var ptsName = data.status.points_name || '积分';
                                $('.qibbs-checkin-hint, #qibbs-checkin-hint').text('今日打卡已完成，明天签到可领 +' + data.status.tomorrow_points + ' ' + ptsName + '！');
                            }

                            // 动态递增更新页面上的积分显示
                            if (data.new_balance_text) {
                                $('#qibbs-hero-user-points span').text(data.new_balance_text);
                                $('.qibbs-user-points-val').text(data.new_balance_text);
                                $('#qibbs-composer-points-val').text(data.new_balance_text);
                            }

                            // 消除移动端底部操作栏红点
                            $('.qibbs-mobile-checkin-dot').fadeOut(200);
                        } else {
                            var errMsg = (res && res.message) ? res.message : '签到失败，请稍后重试';
                            self.showToast(errMsg, 'error');
                            $allBtns.prop('disabled', false).removeClass('loading').html(originalHtml);
                        }
                    },
                    error: function (xhr) {
                        var msg = '签到失败，请稍后重试';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                            msg = xhr.responseJSON.message;
                        }
                        self.showToast(msg, 'error');
                        $allBtns.prop('disabled', false).removeClass('loading').html(originalHtml);
                    }
                });
            });

            $(document).on('click', '.qibbs-btn-makeup, #qibbs-btn-makeup', function (e) {
                var $btn = $(this);
                if ($btn.prop('disabled') || $btn.hasClass('loading')) {
                    return;
                }

                if (!qibbsData.isLoggedIn) {
                    return;
                }

                e.preventDefault();
                var $allMakeupBtns = $('.qibbs-btn-makeup, #qibbs-btn-makeup');
                var originalHtml = $btn.html();
                $allMakeupBtns.prop('disabled', true).addClass('loading');
                $allMakeupBtns.find('.qibbs-btn-loading-spinner').show();
                $allMakeupBtns.find('.qibbs-makeup-btn-text').text('补签中...');

                $.ajax({
                    url: qibbsData.restUrl + 'user/checkin-makeup',
                    type: 'POST',
                    beforeSend: function (xhr) {
                        if (qibbsData.restNonce) {
                            xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                        }
                    },
                    success: function (res) {
                        if (res && res.success && res.data) {
                            var data = res.data;
                            self.showToast(data.message || '补签成功！已恢复连续打卡', 'success');

                            // 更新所有连续签到天数角标
                            var streak = data.consecutive_days || 1;
                            $('.qibbs-checkin-streak-badge, #qibbs-checkin-streak-badge').addClass('is-checked').text('已连签 ' + streak + ' 天');

                            // 更新所有阶梯完成状态与图标
                            var effectiveStreak = (streak % 7 === 0 && streak > 0) ? 7 : (streak % 7);
                            $('.qibbs-checkin-ladder, #qibbs-checkin-ladder').each(function () {
                                $(this).find('.qibbs-ladder-step').each(function () {
                                    var day = parseInt($(this).attr('data-day'), 10);
                                    $(this).removeClass('is-today is-future is-done');
                                    if (day <= effectiveStreak) {
                                        $(this).addClass('is-done').find('.qibbs-step-icon').html('<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>');
                                    } else {
                                        $(this).addClass('is-future');
                                    }
                                });
                            });

                            // 动态递增更新页面上的积分显示
                            if (data.new_balance_text) {
                                $('#qibbs-hero-user-points span').text(data.new_balance_text);
                                $('.qibbs-user-points-val').text(data.new_balance_text);
                                $('#qibbs-composer-points-val').text(data.new_balance_text);
                            }

                            // 补签成功后，更新所有剩余卡片数与栏目状态为正常背包栏
                            var remainingCards = typeof data.remaining_cards !== 'undefined' ? data.remaining_cards : 0;
                            var shopUrl = (qibbsData.urls && qibbsData.urls.gamesShop) ? qibbsData.urls.gamesShop : '/qiling-games/?qlg_section=shop#qlg-game-shop-section';
                            $('.qibbs-checkin-props-wrap, #qibbs-checkin-props-wrap').html(
                                '<div class="qibbs-checkin-inventory-bar">' +
                                    '<span class="qibbs-inventory-stat">' +
                                        '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>' +
                                        '持有补签卡: <strong class="qibbs-user-cards-count">' + remainingCards + '</strong> 张' +
                                    '</span>' +
                                    '<a href="' + shopUrl + '" class="qibbs-inventory-link" target="_blank">游戏币商城兑换 &rarr;</a>' +
                                '</div>'
                            );

                            // 更新所有提示文案
                            if (data.status) {
                                var ptsName = data.status.points_name || '积分';
                                if (data.status.checked_in) {
                                    $('.qibbs-checkin-hint, #qibbs-checkin-hint').text('昨日补签完成，明日签到可领 +' + data.status.tomorrow_points + ' ' + ptsName + '！');
                                } else {
                                    $('.qibbs-checkin-hint, #qibbs-checkin-hint').text('昨日补签完成，今日打卡预计可领 +' + data.status.today_points + ' ' + ptsName + '！');
                                }
                            }
                        } else {
                            var errMsg = (res && res.message) ? res.message : '补签失败，请稍后重试';
                            self.showToast(errMsg, 'error');
                            $allMakeupBtns.prop('disabled', false).removeClass('loading').html(originalHtml);
                        }
                    },
                    error: function (xhr) {
                        var msg = '补签失败，请稍后重试';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                            msg = xhr.responseJSON.message;
                        }
                        self.showToast(msg, 'error');
                        $allMakeupBtns.prop('disabled', false).removeClass('loading').html(originalHtml);
                    }
                });
            });
        },

        initDraftAutosave: function () {
            if (!qibbsData.isLoggedIn || !qibbsData.enableDraftAutosave || !$('#qibbs-post-form').length) {
                $('#qibbs-draft-clear').hide();
                return;
            }

            var pathKey = (window.location && window.location.pathname) ? window.location.pathname : '/community/';
            this.draftStorageKey = 'qibbs_draft_v1_' + (qibbsData.currentUser || 0) + '_' + pathKey;

            this.restoreDraft();

            var self = this;
            $(document).on('input', '#qibbs-post-content', function () {
                self.scheduleDraftSave();
            });
            $(document).on('change', '#qibbs-forum-select', function () {
                self.scheduleDraftSave();
            });
        },

        scheduleDraftSave: function () {
            var self = this;
            if (this.draftSaveTimer) {
                clearTimeout(this.draftSaveTimer);
            }
            this.draftSaveTimer = setTimeout(function () {
                self.saveDraft();
            }, 450);
        },

        saveDraft: function () {
            if (!this.draftStorageKey) {
                return;
            }
            var content = $('#qibbs-post-content').val() || '';
            var forumId = $('#qibbs-forum-select').val() || '0';
            var payload = {
                content: content,
                forumId: forumId,
                updatedAt: Date.now()
            };

            try {
                localStorage.setItem(this.draftStorageKey, JSON.stringify(payload));
                this.setDraftStatus((qibbsData.strings && qibbsData.strings.draftSaved) ? qibbsData.strings.draftSaved : '草稿已自动保存');
            } catch (e) {
                // 忽略无痕模式或 localStorage 写入限制异常
            }
        },

        restoreDraft: function () {
            if (!this.draftStorageKey) {
                return;
            }

            var currentValue = $.trim($('#qibbs-post-content').val() || '');
            if (currentValue.length > 0) {
                return;
            }

            try {
                var raw = localStorage.getItem(this.draftStorageKey);
                if (!raw) {
                    return;
                }
                var draft = JSON.parse(raw);
                if (!draft || typeof draft.content !== 'string') {
                    return;
                }

                if (draft.content.length > 0) {
                    $('#qibbs-post-content').val(draft.content);
                }
                if (draft.forumId) {
                    $('#qibbs-forum-select').val(String(draft.forumId));
                }

                this.setDraftStatus((qibbsData.strings && qibbsData.strings.draftRestored) ? qibbsData.strings.draftRestored : '已恢复草稿');
            } catch (e) {
                // 忽略无效的本地草稿数据
            }
        },

        clearDraft: function (e) {
            if (e) {
                e.preventDefault();
            }
            this.clearDraftData(false);
            $('#qibbs-post-content').val('');
            $('#qibbs-forum-select').val('0');
            this.showToast((qibbsData.strings && qibbsData.strings.draftCleared) ? qibbsData.strings.draftCleared : '草稿已清除');
        },

        clearDraftData: function (silent) {
            if (this.draftSaveTimer) {
                clearTimeout(this.draftSaveTimer);
                this.draftSaveTimer = null;
            }

            if (!this.draftStorageKey) {
                this.setDraftStatus('');
                return;
            }
            try {
                localStorage.removeItem(this.draftStorageKey);
            } catch (e) {
                // 静默捕获 localStorage 异常
            }
            this.setDraftStatus(silent ? '' : ((qibbsData.strings && qibbsData.strings.draftCleared) ? qibbsData.strings.draftCleared : '草稿已清除'));
        },

        setDraftStatus: function (text) {
            var $el = $('#qibbs-draft-status');
            if (!$el.length) {
                return;
            }
            $el.text(text || '');
        },

        initMentionAutocomplete: function () {
            if (!qibbsData.enableMentions) {
                this.hideMentionBox();
            }
        },

        handleMentionInput: function (e) {
            if (!qibbsData.enableMentions || !qibbsData.isLoggedIn) {
                return;
            }
            var textarea = e.currentTarget;
            if (!textarea) {
                return;
            }
            var context = this.getMentionContext(textarea);
            if (!context) {
                this.hideMentionBox();
                return;
            }

            var sameQuery = this.mentionState.active && this.mentionState.query === context.query && (this.mentionState.users || []).length > 0;
            this.mentionState.start = context.start;
            this.mentionState.end = context.end;
            this.mentionState.query = context.query;
            if (sameQuery) {
                this.renderMentionBox(this.mentionState.users);
                return;
            }
            this.mentionState.index = 0;
            if (this.mentionSearchTimer) {
                clearTimeout(this.mentionSearchTimer);
            }
            var self = this;
            this.mentionSearchTimer = setTimeout(function () {
                self.searchMentionUsers(context.query);
            }, 180);
        },

        handleMentionKeydown: function (e) {
            if (!this.mentionState.active) {
                return;
            }

            var key = e.key;
            if (['ArrowDown', 'ArrowUp', 'Enter', 'Escape'].indexOf(key) === -1) {
                return;
            }

            var users = this.mentionState.users || [];
            if (!users.length) {
                this.hideMentionBox();
                return;
            }

            e.preventDefault();
            if (key === 'Escape') {
                this.hideMentionBox();
                return;
            }
            if (key === 'ArrowDown') {
                this.mentionState.index = (this.mentionState.index + 1) % users.length;
                this.renderMentionBox(users);
                return;
            }
            if (key === 'ArrowUp') {
                this.mentionState.index = (this.mentionState.index - 1 + users.length) % users.length;
                this.renderMentionBox(users);
                return;
            }
            if (key === 'Enter') {
                var target = users[this.mentionState.index];
                if (target) {
                    this.insertMentionAtCursor(target.username || target.display_name || '');
                }
            }
        },

        hideMentionBox: function () {
            if (this.mentionSearchTimer) {
                clearTimeout(this.mentionSearchTimer);
                this.mentionSearchTimer = null;
            }
            this.mentionState.active = false;
            this.mentionState.users = [];
            $('#qibbs-mention-box').hide().empty();
        },

        getMentionContext: function (textarea) {
            var value = textarea.value || '';
            var cursor = textarea.selectionStart || 0;
            var before = value.slice(0, cursor);
            var match = before.match(/(^|\s)@([A-Za-z0-9_\u4e00-\u9fa5-]{1,32})$/);
            if (!match) {
                return null;
            }
            var query = (match[2] || '').trim();
            if (!query) {
                return null;
            }
            return {
                query: query,
                start: cursor - query.length - 1,
                end: cursor
            };
        },

        searchMentionUsers: function (query) {
            var self = this;
            var $box = $('#qibbs-mention-box');
            if (!$box.length) {
                return;
            }
            $box.html('<div class="qibbs-mention-loading">' + this.escapeHtml((qibbsData.strings && qibbsData.strings.mentionLoading) ? qibbsData.strings.mentionLoading : '正在搜索用户...') + '</div>').show();

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_search_users',
                    nonce: qibbsData.nonce,
                    query: query
                },
                success: function (response) {
                    if (!response || !response.success) {
                        self.hideMentionBox();
                        return;
                    }
                    var users = (response.data && response.data.users) ? response.data.users : [];
                    if (!Array.isArray(users) || users.length === 0) {
                        self.hideMentionBox();
                        return;
                    }
                    self.mentionState.users = users;
                    self.mentionState.active = true;
                    if (self.mentionState.index >= users.length) {
                        self.mentionState.index = 0;
                    }
                    self.renderMentionBox(users);
                },
                error: function () {
                    self.hideMentionBox();
                }
            });
        },

        renderMentionBox: function (users) {
            var self = this;
            var $box = $('#qibbs-mention-box');
            if (!$box.length || !users || !users.length) {
                this.hideMentionBox();
                return;
            }

            var html = '';
            users.forEach(function (user, idx) {
                var name = self.escapeHtml(user.display_name || '');
                var username = self.escapeHtml(user.username || '');
                var avatar = self.sanitizeUrl(user.avatar || '');
                var activeClass = (idx === self.mentionState.index) ? ' active' : '';
                html += '<button type="button" class="qibbs-mention-item' + activeClass + '" data-username="' + username + '">' +
                    '<img src="' + avatar + '" alt="">' +
                    '<span class="qibbs-mention-name">' + name + '</span>' +
                    '<span class="qibbs-mention-username">@' + username + '</span>' +
                    '</button>';
            });

            $box.html(html).show();
        },

        handleMentionSelect: function (e) {
            e.preventDefault();
            var username = $(e.currentTarget).data('username');
            if (!username) {
                return;
            }
            this.insertMentionAtCursor(String(username));
        },

        insertMentionAtCursor: function (username) {
            var $textarea = $('#qibbs-post-content');
            if (!$textarea.length) {
                return;
            }
            var textarea = $textarea[0];
            var value = textarea.value || '';
            var start = Math.max(0, this.mentionState.start);
            var end = Math.max(start, this.mentionState.end);
            var mentionText = '@' + username + ' ';
            textarea.value = value.slice(0, start) + mentionText + value.slice(end);
            var cursor = start + mentionText.length;
            textarea.selectionStart = cursor;
            textarea.selectionEnd = cursor;
            $textarea.trigger('input').focus();
            this.hideMentionBox();
        },

        showToast: function (message, type) {
            type = type || 'success';
            var iconSvg = type === 'success'
                ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>'
                : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
            var safeMessage = this.escapeHtml(message == null ? '' : String(message));

            var $toast = $('<div class="qibbs-toast qibbs-toast-' + type + '"><span class="qibbs-toast-icon">' + iconSvg + '</span>' + safeMessage + '</div>');
            $('body').append($toast);

            setTimeout(function () { $toast.addClass('show'); }, 10);
            setTimeout(function () {
                $toast.removeClass('show');
                setTimeout(function () { $toast.remove(); }, 300);
            }, 3000);
        },

        // 自定义确认对话框组件
        showConfirm: function (options, callback) {
            var defaults = {
                title: '确认操作',
                message: '确定要执行此操作吗？',
                confirmText: '确定',
                cancelText: '取消',
                type: 'danger'
            };
            var opts = $.extend({}, defaults, options);
            var iconClass = opts.type === 'warning' ? 'warning' : 'danger';
            var safeTitle = this.escapeHtml(opts.title);
            var safeMessage = this.escapeHtml(opts.message);
            var safeConfirmText = this.escapeHtml(opts.confirmText);
            var safeCancelText = this.escapeHtml(opts.cancelText);
            var warnSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';

            var html = '<div class="qibbs-modal-overlay"><div class="qibbs-modal">' +
                '<div class="qibbs-modal-header"><div class="qibbs-modal-icon ' + iconClass + '">' + warnSvg + '</div>' +
                '<h3 class="qibbs-modal-title">' + safeTitle + '</h3></div>' +
                '<div class="qibbs-modal-body"><p class="qibbs-modal-message">' + safeMessage + '</p></div>' +
                '<div class="qibbs-modal-footer">' +
                '<button class="qibbs-modal-btn qibbs-modal-btn-cancel">' + safeCancelText + '</button>' +
                '<button class="qibbs-modal-btn qibbs-modal-btn-confirm">' + safeConfirmText + '</button>' +
                '</div></div></div>';

            var $modal = $(html);
            $('body').append($modal);
            setTimeout(function () { $modal.addClass('show'); }, 10);

            function closeModal(result) {
                $modal.removeClass('show');
                setTimeout(function () { $modal.remove(); }, 200);
                if (typeof callback === 'function') {
                    callback(result);
                }
            }

            $modal.find('.qibbs-modal-btn-cancel, .qibbs-modal-overlay').on('click', function (e) {
                if (e.target === this) closeModal(false);
            });
            $modal.find('.qibbs-modal-btn-confirm').on('click', function () {
                closeModal(true);
            });
            $modal.find('.qibbs-modal').on('click', function (e) {
                e.stopPropagation();
            });
        },

        // 暗黑模式：无缝兼容并跟随启灵主题（qiling）的暗黑模式状态
        initDarkMode: function () {
            var self = this;

            function isThemeDark() {
                var docEl = document.documentElement;
                // 1. 优先以启灵主题在 <html> 节点上的显式属性为绝对权威判定
                if (docEl.classList.contains('dark-mode') || docEl.getAttribute('data-theme') === 'dark') {
                    return true;
                }
                // 若启灵主题明确标记为浅色模式（data-theme="light"），坚决保持浅色，严禁越过主题判定
                if (docEl.getAttribute('data-theme') === 'light') {
                    return false;
                }

                // 2. 检查 <body> 是否带有暗黑相关类名
                if (document.body && (document.body.classList.contains('dark-mode') || document.body.classList.contains('qiling-dark-mode'))) {
                    return true;
                }

                // 3. 检查启灵主题保存的主题偏好缓存
                try {
                    var pref = localStorage.getItem('qiling-theme-preference') || localStorage.getItem('theme');
                    if (pref === 'dark') return true;
                    if (pref === 'light') return false;
                } catch (e) {}

                // 4. 只有在启灵主题后台明确开启了自动跟随系统（autoEnabled）的前提下，才参考系统的 prefers-color-scheme
                // 严禁在主题为纯亮色或未开启自动模式时，因浏览器深色外观而单方面强制社区插件变暗！
                var config = window.qilingDarkModeConfig;
                if (config && config.autoEnabled && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    return true;
                }

                return false;
            }

            function syncDarkMode() {
                var dark = isThemeDark();
                if (dark) {
                    $('body').addClass('qibbs-dark-mode').removeClass('qibbs-light-mode');
                } else {
                    $('body').addClass('qibbs-light-mode').removeClass('qibbs-dark-mode');
                }
            }

            // 清理旧版社区独立的暗黑开关缓存，避免与主题冲突
            try {
                localStorage.removeItem('qibbs_dark_mode');
            } catch (e) {}

            // 页面加载时立即同步当前主题状态
            syncDarkMode();

            // 监听启灵主题对 <html> 属性（class / data-theme）的实时动态修改
            if (window.MutationObserver) {
                var observer = new MutationObserver(function (mutations) {
                    for (var i = 0; i < mutations.length; i++) {
                        var m = mutations[i];
                        if (m.type === 'attributes' && (m.attributeName === 'class' || m.attributeName === 'data-theme')) {
                            syncDarkMode();
                            break;
                        }
                    }
                });
                observer.observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['class', 'data-theme']
                });
            }

            // 监听启灵主题顶部或侧栏暗黑模式切换按钮点击事件
            $(document).on('click', '#darkmode-toggle, .darkmode-toggle, .qiling-darkmode-toggle', function () {
                setTimeout(syncDarkMode, 50);
            });

            // 监听操作系统级深色模式切换（仅当启灵主题开启了自动模式时才主动响应，否则全权交由主题的属性变化驱动）
            if (window.matchMedia) {
                var mq = window.matchMedia('(prefers-color-scheme: dark)');
                var handleMediaChange = function () {
                    var config = window.qilingDarkModeConfig;
                    if (config && config.autoEnabled) {
                        syncDarkMode();
                    }
                };
                if (typeof mq.addEventListener === 'function') {
                    mq.addEventListener('change', handleMediaChange);
                } else if (typeof mq.addListener === 'function') {
                    mq.addListener(handleMediaChange);
                }
            }

            // 监听跨标签页 localStorage 状态变化
            window.addEventListener('storage', function (e) {
                if (e.key === 'qiling-theme-preference' || e.key === 'theme') {
                    syncDarkMode();
                }
            });
        },

        // ========== 编辑动态功能 ==========
        editMediaQueue: [],
        editExistingMedia: [],

        handleEditPost: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var postId = $btn.data('post-id');
            var self = this;

            // 获取原内容
            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_get_post_content',
                    nonce: qibbsData.nonce,
                    post_id: postId
                },
                success: function (response) {
                    if (response.success) {
                        self.showEditModal(postId, response.data.content, response.data.media || [], response.data.media_ids || '', response.data.post_type || 'discussion');
                    } else {
                        self.showToast(response.data.message, 'error');
                    }
                }
            });
        },

        showEditModal: function (postId, content, media, mediaIds, postType) {
            var self = this;
            this.editMediaQueue = [];
            this.editExistingMedia = media.slice();
            var postTypeOptionsHtml = $('#qibbs-post-type').length
                ? $('#qibbs-post-type').html()
                : '<option value="discussion">普通帖</option><option value="question">提问帖</option><option value="experience">经验帖</option><option value="announcement">公告帖</option><option value="activity">活动帖</option>';

            // 构建媒体预览HTML
            var mediaPreviewHtml = '';
            var safePostId = parseInt(postId, 10) || 0;
            media.forEach(function (m) {
                var safeMediaId = parseInt(m.id, 10) || 0;
                var safeMediaUrl = self.sanitizeUrl(m.file_url || '');
                mediaPreviewHtml += '<div class="qibbs-edit-media-item" data-media-id="' + safeMediaId + '">' +
                    '<img src="' + safeMediaUrl + '" alt="">' +
                    '<button type="button" class="qibbs-edit-media-remove" data-media-id="' + safeMediaId + '">&times;</button>' +
                    '</div>';
            });

            var modalHtml = '<div class="qibbs-edit-modal-overlay">' +
                '<div class="qibbs-edit-modal">' +
                '<div class="qibbs-edit-modal-header">' +
                '<h3>编辑动态</h3>' +
                '<button type="button" class="qibbs-edit-modal-close" id="qibbs-edit-cancel">&times;</button>' +
                '</div>' +
                '<div class="qibbs-edit-modal-body">' +
                '<select id="qibbs-edit-post-type" class="qibbs-tool-select">' + postTypeOptionsHtml + '</select>' +
                '<textarea id="qibbs-edit-content" rows="6">' + this.escapeHtml(content) + '</textarea>' +
                '<div class="qibbs-edit-media-section">' +
                '<div class="qibbs-edit-media-preview" id="qibbs-edit-media-preview">' + mediaPreviewHtml + '</div>' +
                '<label class="qibbs-edit-media-add">' +
                '<input type="file" id="qibbs-edit-media-input" accept="image/*" multiple style="display:none;">' +
                '<svg viewBox="0 0 24 24" width="20" height="20"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg> 添加图片' +
                '</label>' +
                '</div>' +
                '</div>' +
                '<div class="qibbs-edit-modal-footer">' +
                '<button type="button" class="qibbs-btn qibbs-btn-secondary" id="qibbs-edit-cancel">取消</button>' +
                '<button type="button" class="qibbs-btn qibbs-btn-primary" id="qibbs-edit-submit" data-post-id="' + safePostId + '">保存</button>' +
                '</div>' +
                '</div>' +
                '</div>';

            $('body').append(modalHtml);
            $('#qibbs-edit-post-type').val(postType || 'discussion');
            $('#qibbs-edit-content').focus();
        },

        handleEditMediaRemove: function (e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn = $(e.currentTarget);
            var mediaId = $btn.data('media-id');
            var $item = $btn.closest('.qibbs-edit-media-item');
            var self = this;

            // 从现有媒体中移除
            this.editExistingMedia = this.editExistingMedia.filter(function (m) {
                return m.id != mediaId;
            });

            // 从新上传队列中移除 (如果是新上传的)
            var index = $btn.data('new-index');
            if (index !== undefined && index >= 0 && index < this.editMediaQueue.length) {
                this.editMediaQueue[index] = null;
            }

            $item.fadeOut(200, function () { $(this).remove(); });
        },

        handleEditMediaSelect: function (e) {
            var files = e.target.files;
            var $preview = $('#qibbs-edit-media-preview');
            var self = this;
            var maxSizeMB = qibbsData.maxUploadSize || 5;
            var maxSizeBytes = maxSizeMB * 1024 * 1024;

            var totalCount = this.editExistingMedia.length + this.editMediaQueue.filter(function (f) { return f !== null; }).length;

            Array.from(files).forEach(function (file) {
                if (totalCount >= 9) {
                    self.showToast('最多上传9张图片', 'error');
                    return;
                }

                if (file.size > maxSizeBytes) {
                    self.showToast('文件"' + file.name + '"大小超过' + maxSizeMB + 'MB限制', 'error');
                    return;
                }

                self.editMediaQueue.push(file);
                var currentIndex = self.editMediaQueue.length - 1;
                totalCount++;

                var reader = new FileReader();
                reader.onload = function (e) {
                    var $item = $('<div class="qibbs-edit-media-item" data-new-index="' + currentIndex + '">' +
                        '<img src="' + e.target.result + '">' +
                        '<button type="button" class="qibbs-edit-media-remove" data-new-index="' + currentIndex + '">&times;</button>' +
                        '</div>');
                    $preview.append($item);
                };
                reader.readAsDataURL(file);
            });

            e.target.value = '';
        },

        handleEditSubmit: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var postId = $btn.data('post-id');
            var content = $('#qibbs-edit-content').val().trim();
            var postType = $('#qibbs-edit-post-type').val() || 'discussion';
            var self = this;

            if (!content) {
                this.showToast('内容不能为空', 'error');
                return;
            }

            $btn.prop('disabled', true).html('<span class="qibbs-spinner"></span><span class="qibbs-btn-text">保存中...</span>');

            // 先上传新图片
            this.uploadEditMediaQueue(function (newMediaIds) {
                if (newMediaIds === false) {
                    $btn.prop('disabled', false).text('保存');
                    return;
                }

                // 合并现有的和新上传的media_ids
                var existingIds = self.editExistingMedia.map(function (m) { return m.id; });
                var allIds = existingIds.concat(newMediaIds);
                var mediaIdsStr = allIds.join(',');

                var nonceVal = (typeof qibbsData !== 'undefined' && qibbsData.nonce) ? qibbsData.nonce : ($('#qibbs-post-nonce').val() || '');
                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_edit_post',
                        nonce: nonceVal,
                        post_id: postId,
                        content: content,
                        post_type: postType,
                        media_ids: mediaIdsStr
                    },
                    success: function (response) {
                        if (response.success) {
                            self.showToast(response.data.message);
                            self.closeEditModal();
                            self.feed.page = 1;
                            self.loadFeed();
                        } else {
                            self.showToast(response.data.message, 'error');
                        }
                    },
                    error: function () {
                        self.showToast(qibbsData.strings.error, 'error');
                    },
                    complete: function () {
                        $btn.prop('disabled', false).text('保存');
                    }
                });
            });
        },

        uploadEditMediaQueue: function (callback) {
            var filesToUpload = this.editMediaQueue.filter(function (file) {
                return file !== null;
            });

            if (filesToUpload.length === 0) {
                callback([]);
                return;
            }

            var self = this;
            var mediaIds = [];
            var uploaded = 0;
            var totalToUpload = filesToUpload.length;
            var nonceVal = (typeof qibbsData !== 'undefined' && qibbsData.nonce) ? qibbsData.nonce : ($('#qibbs-post-nonce').val() || '');
            var uploadErrors = [];

            filesToUpload.forEach(function (file) {
                var formData = new FormData();
                formData.append('action', 'qibbs_upload_media');
                formData.append('nonce', nonceVal);
                var safeName = file.name || '';
                if (!safeName || safeName.indexOf('.') === -1 || safeName === 'blob') {
                    var fallbackExt = 'png';
                    if (file.type) {
                        fallbackExt = file.type.replace('image/', '').replace('video/', '').toLowerCase();
                        if (fallbackExt === 'jpeg') fallbackExt = 'jpg';
                    }
                    safeName = 'upload-' + Date.now() + '.' + fallbackExt;
                }
                formData.append('file', file, safeName);

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function (response) {
                        if (response.success && response.data && response.data.id) {
                            mediaIds.push(response.data.id);
                        } else {
                            var errMsg = (response.data && response.data.message) ? response.data.message : '图片上传失败';
                            uploadErrors.push(errMsg);
                        }
                    },
                    error: function (xhr) {
                        uploadErrors.push('网络或服务异常 (HTTP ' + xhr.status + ')');
                    },
                    complete: function () {
                        uploaded++;
                        if (uploaded === totalToUpload) {
                            if (uploadErrors.length > 0) {
                                self.showToast('编辑图片上传失败: ' + uploadErrors[0], 'error');
                                callback(false);
                            } else {
                                callback(mediaIds);
                            }
                        }
                    }
                });
            });
        },

        closeEditModal: function (e) {
            if (e && e.target && !$(e.target).hasClass('qibbs-edit-modal-overlay') && !$(e.target).is('#qibbs-edit-cancel')) {
                return;
            }
            this.editMediaQueue = [];
            this.editExistingMedia = [];
            $('.qibbs-edit-modal-overlay').remove();
        },

        escapeHtml: function (text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        formatSafeText: function (text) {
            return this.escapeHtml(text || '').replace(/\n/g, '<br>');
        },

        sanitizeUrl: function (url) {
            if (!url) {
                return '#';
            }

            try {
                var parsed = new URL(url, window.location.origin);
                if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
                    return '#';
                }
                return this.escapeHtml(parsed.href);
            } catch (e) {
                return '#';
            }
        },

        handlePostTypeChange: function (e) {
            var val = $(e.currentTarget).val();
            var $bounty = $('#qibbs-bounty-fields');
            var $redpacket = $('#qibbs-redpacket-fields');

            if (val === 'bounty') {
                $redpacket.slideUp(180);
                $bounty.slideDown(220);
            } else if (val === 'redpacket') {
                $bounty.slideUp(180);
                $redpacket.slideDown(220);
            } else {
                $bounty.slideUp(180);
                $redpacket.slideUp(180);
            }
        },

        handleModalClose: function (e) {
            e.preventDefault();
            $('.qibbs-modal-overlay').fadeOut(150);
        },

        handleViewPacketLogs: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var redpacketId = $btn.data('redpacket-id') || 0;
            var postId = $btn.data('post-id') || 0;
            var $modal = $('#qibbs-redpacket-modal');
            var $list = $('#qibbs-redpacket-logs-list');

            $list.html('<div class="qibbs-modal-loading"><span class="qibbs-spinner"></span> 正在加载记录...</div>');
            $modal.addClass('show').fadeIn(200);

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_get_redpacket_logs',
                    nonce: qibbsData.nonce,
                    redpacket_id: redpacketId,
                    post_id: postId
                },
                success: function (res) {
                    if (res.success && res.data && res.data.logs) {
                        var logs = res.data.logs;
                        if (logs.length === 0) {
                            $list.html('<div class="qibbs-modal-empty">暂无领取记录，快来发表评论抢先领取吧！</div>');
                            return;
                        }
                        var html = '<ul class="qibbs-packet-logs-ul">';
                        logs.forEach(function (item, idx) {
                            var rankCls = (idx === 0) ? 'is-first' : '';
                            html += '<li class="qibbs-packet-log-item ' + rankCls + '">';
                            html += '<span class="qibbs-log-rank">' + (idx + 1) + '</span>';
                            html += '<img src="' + item.avatar + '" alt="" class="qibbs-log-avatar">';
                            html += '<div class="qibbs-log-info">';
                            html += '<span class="qibbs-log-name">' + item.display_name + '</span>';
                            html += '<time class="qibbs-log-time">' + item.created_at + '</time>';
                            html += '</div>';
                            html += '<div class="qibbs-log-amount">+' + parseFloat(item.amount).toFixed(2) + '</div>';
                            html += '</li>';
                        });
                        html += '</ul>';
                        $list.html(html);
                    } else {
                        $list.html('<div class="qibbs-modal-empty">' + (res.data ? res.data.message : '加载记录失败') + '</div>');
                    }
                },
                error: function () {
                    $list.html('<div class="qibbs-modal-empty">网络请求失败，请稍后重试</div>');
                }
            });
        },

        handleDrawPacketLottery: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var postId = $btn.data('post-id') || 0;
            var self = this;

            if (!postId) return;

            if (!window.confirm('确定现在为该抽奖红包开奖吗？系统将从当前回帖者中随机抽取幸运中奖用户并立即发放积分。')) {
                return;
            }

            $btn.prop('disabled', true).text('开奖中...');

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_draw_redpacket',
                    nonce: qibbsData.nonce,
                    post_id: postId
                },
                success: function (res) {
                    if (res.success) {
                        self.showToast(res.data.message || '开奖成功！');
                        setTimeout(function () {
                            location.reload();
                        }, 1200);
                    } else {
                        self.showToast(res.data ? res.data.message : '开奖失败', 'error');
                        $btn.prop('disabled', false).text('立即开奖');
                    }
                },
                error: function () {
                    self.showToast('请求失败，请稍后重试', 'error');
                    $btn.prop('disabled', false).text('立即开奖');
                }
            });
        },

        handleArbitrateTrigger: function (e) {
            e.preventDefault();
            var $btn = $(e.currentTarget);
            var postId = $btn.data('post-id') || 0;
            var $modal = $('#qibbs-arbitration-modal');
            var $list = $('#qibbs-arbitrate-comments-list');

            if (!postId) return;

            $('#qibbs-arbitrate-post-id').val(postId);
            $('#qibbs-arbitrate-reason').val('');
            $('input[name="arbitration_action"][value="award"]').prop('checked', true);
            $('#qibbs-arbitrate-comment-select-wrap').show();

            $list.html('<div class="qibbs-modal-loading"><span class="qibbs-spinner"></span> 正在检索评论候选列表...</div>');
            $modal.addClass('show').fadeIn(200);

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_load_comments',
                    nonce: qibbsData.nonce,
                    post_id: postId,
                    page: 1
                },
                success: function (res) {
                    if (res.success && res.data && res.data.comments) {
                        var comments = res.data.comments;
                        if (comments.length === 0) {
                            $list.html('<div class="qibbs-modal-empty">该动态暂无回复，可选择「无有效答案，退还楼主」。</div>');
                            $('input[name="arbitration_action"][value="refund"]').prop('checked', true);
                            $('#qibbs-arbitrate-comment-select-wrap').hide();
                            return;
                        }

                        var html = '<div class="qibbs-arbitrate-candidates">';
                        comments.forEach(function (c, idx) {
                            var checked = (idx === 0) ? 'checked' : '';
                            html += '<label class="qibbs-candidate-item">';
                            html += '<input type="radio" name="winner_comment_id" value="' + c.id + '" ' + checked + '>';
                            html += '<img src="' + c.author.avatar + '" alt="" class="qibbs-candidate-avatar">';
                            html += '<div class="qibbs-candidate-meta">';
                            html += '<span class="qibbs-candidate-name">' + c.author.display_name + '</span>';
                            html += '<p class="qibbs-candidate-text">' + c.content.replace(/<[^>]+>/g, '').substring(0, 100) + '</p>';
                            html += '</div>';
                            html += '</label>';
                        });
                        html += '</div>';
                        $list.html(html);
                    } else {
                        $list.html('<div class="qibbs-modal-empty">加载评论失败</div>');
                    }
                },
                error: function () {
                    $list.html('<div class="qibbs-modal-empty">请求失败，请稍后重试</div>');
                }
            });
        },

        handleArbitrationActionChange: function (e) {
            var val = $('input[name="arbitration_action"]:checked').val();
            if (val === 'award') {
                $('#qibbs-arbitrate-comment-select-wrap').slideDown(180);
            } else {
                $('#qibbs-arbitrate-comment-select-wrap').slideUp(180);
            }
        },

        handleArbitrationSubmit: function (e) {
            e.preventDefault();
            var $btn = $('#qibbs-submit-arbitration-btn');
            var postId = $('#qibbs-arbitrate-post-id').val();
            var action = $('input[name="arbitration_action"]:checked').val();
            var winnerCommentId = $('input[name="winner_comment_id"]:checked').val() || 0;
            var reason = $('#qibbs-arbitrate-reason').val().trim();
            var self = this;

            if (action === 'award' && !winnerCommentId) {
                self.showToast('请选择一条要采纳为最佳答案的评论', 'error');
                return;
            }

            if (!window.confirm('确认执行此仲裁裁决吗？此操作将立即结算积分并无法撤销。')) {
                return;
            }

            $btn.prop('disabled', true).text('正在执行仲裁...');

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_arbitrate_bounty',
                    nonce: qibbsData.nonce,
                    post_id: postId,
                    arbitration_action: action,
                    winner_comment_id: winnerCommentId,
                    reason: reason
                },
                success: function (res) {
                    if (res.success) {
                        self.showToast(res.data.message || '仲裁执行成功！');
                        $('#qibbs-arbitration-modal').fadeOut(150);
                        setTimeout(function () {
                            location.reload();
                        }, 1200);
                    } else {
                        self.showToast(res.data ? res.data.message : '仲裁失败', 'error');
                        $btn.prop('disabled', false).text('确认仲裁并执行');
                    }
                },
                error: function () {
                    self.showToast('网络请求失败', 'error');
                    $btn.prop('disabled', false).text('确认仲裁并执行');
                }
            });
        },

        /**
         * 道具大厅与背包前台交互体系
         */
        initPropMall: function () {
            var self = this;
            if (!$('#qibbs-prop-mall-container').length && !$('.qibbs-promote-post-btn').length) {
                return;
            }

            // 道具大厅 Tab 分类切换
            $(document).on('click', '.qibbs-prop-tab-btn', function (e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                $('.qibbs-prop-tab-btn').removeClass('is-active');
                $(this).addClass('is-active');

                if (tab === 'backpack') {
                    $('#qibbs-prop-mall-list-section').hide();
                    $('#qibbs-prop-backpack-section').fadeIn(150);
                } else {
                    $('#qibbs-prop-backpack-section').hide();
                    $('#qibbs-prop-mall-list-section').fadeIn(150);
                    if (tab === 'all') {
                        $('.qibbs-prop-card').show();
                    } else if (tab === 'name_color') {
                        $('.qibbs-prop-card[data-prop-type="name_color"]').show();
                        $('.qibbs-prop-card[data-prop-type="post_sticky"]').hide();
                    } else if (tab === 'post_sticky') {
                        $('.qibbs-prop-card[data-prop-type="post_sticky"]').show();
                        $('.qibbs-prop-card[data-prop-type="name_color"]').hide();
                    }
                }
            });

            // Hero 区域快捷跳转背包
            $(document).on('click', '#qibbs-tab-backpack-trigger', function (e) {
                e.preventDefault();
                $('#qibbs-prop-backpack-btn').trigger('click');
            });

            // 实时试穿按钮
            $(document).on('click', '.qibbs-tryon-btn', function (e) {
                e.preventDefault();
                var color = $(this).data('color') || '#ef4444';
                var name = $(this).data('name') || '';

                if (!qibbsData.isLoggedIn) {
                    self.showToast('请先登录后再进行试穿预览', 'warning');
                    return;
                }

                $('#qibbs-tryon-sample-name').css('color', color);
                $('#qibbs-tryon-color-label').text('试穿中：' + name + ' (' + color + ')');

                // 按钮自身视觉反馈
                $('.qibbs-tryon-btn').removeClass('is-trying').text('试穿');
                $(this).addClass('is-trying').text('试穿中');

                var $tryonCard = $('#qibbs-prop-tryon-card');
                if ($tryonCard.length) {
                    $tryonCard.addClass('qibbs-pulse-highlight');
                    setTimeout(function () {
                        $tryonCard.removeClass('qibbs-pulse-highlight');
                    }, 1200);

                    // 检查试穿预览卡片是否偏离屏幕视口，若是则平滑居中滚动过去
                    var winScroll = $(window).scrollTop();
                    var cardOffset = $tryonCard.offset().top - 80;
                    if (winScroll > cardOffset + 240 || winScroll < cardOffset - 300) {
                        $('html, body').stop().animate({ scrollTop: cardOffset }, 300);
                    }
                }
                self.showToast('已切换「' + name + '」试穿预览', 'success');
            });

            // 点击兑换道具按钮
            $(document).on('click', '.qibbs-buy-prop-btn', function (e) {
                e.preventDefault();
                if (!qibbsData.isLoggedIn) {
                    self.showToast(qibbsData.strings.loginRequired || '请先登录', 'warning');
                    return;
                }

                var $card = $(this).closest('.qibbs-prop-card');
                var propId = $(this).data('prop-id');
                var propType = $(this).data('prop-type');
                var propName = $(this).data('prop-name') || $card.find('.qibbs-prop-card-title').text().trim();
                var price = $(this).data('price');
                var color = $card.data('color') || '#ef4444';

                if (propType === 'name_color') {
                    self.activeBuyColorProp = {
                        id: propId,
                        name: propName,
                        price: price,
                        color: color
                    };
                    $('#qibbs-modal-prop-name').text(propName);
                    $('#qibbs-modal-cost-val').text(price);
                    $('#qibbs-modal-color-sphere').css({
                        'background': 'radial-gradient(circle at 35% 35%, #ffffff 0%, ' + color + ' 65%, #0f172a 100%)',
                        'box-shadow': '0 8px 24px -4px ' + color + '80'
                    });
                    $('#qibbs-buy-color-modal').addClass('show').fadeIn(150);
                } else if (propType === 'post_sticky') {
                    self.activeBuyStickyProp = {
                        id: propId,
                        name: propName,
                        price: price
                    };
                    $('#qibbs-sticky-modal-prop-name').text(propName);
                    $('#qibbs-sticky-modal-cost').text(price);
                    $('#qibbs-sticky-select-modal').addClass('show').fadeIn(150);
                    self.loadUserPromotablePosts();
                }
            });

            // 帖子卡片与详情页一键置顶推广
            $(document).on('click', '.qibbs-promote-post-btn', function (e) {
                e.preventDefault();
                if (!qibbsData.isLoggedIn) {
                    self.showToast(qibbsData.strings.loginRequired || '请先登录', 'warning');
                    return;
                }
                var postId = $(this).data('post-id');
                self.openPromoteModalForPost(postId);
            });

            // 确认购买名字变色
            $(document).on('click', '#qibbs-confirm-buy-color-btn', function (e) {
                e.preventDefault();
                if (self.isPurchasingColor) {
                    return;
                }
                if (!self.activeBuyColorProp || !self.activeBuyColorProp.id) {
                    return;
                }
                self.isPurchasingColor = true;
                var $btn = $(this);
                $btn.prop('disabled', true).text('正在兑换并佩戴...');

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_purchase_prop',
                        nonce: qibbsData.nonce,
                        prop_id: self.activeBuyColorProp.id
                    },
                    complete: function () {
                        self.isPurchasingColor = false;
                        $btn.prop('disabled', false).text('确认兑换并佩戴');
                    },
                    success: function (res) {
                        if (res.success) {
                            self.showToast(res.data.message || '兑换并佩戴成功！');
                            $('#qibbs-buy-color-modal').removeClass('show').fadeOut(150);
                            if (res.data.points_formatted) {
                                $('#qibbs-hero-prop-balance span').text(res.data.points_formatted);
                                $('#qibbs-modal-user-balance').text(res.data.points_formatted);
                            }
                            if (res.data.active_color) {
                                self.updateActiveColorOnPage(res.data.active_color);
                            }
                            if (res.data.backpack) {
                                self.renderBackpackUI(res.data.backpack, res.data.active_color);
                            }
                        } else {
                            self.showToast(res.data ? res.data.message : '兑换失败', 'error');
                        }
                    },
                    error: function (xhr) {
                        var msg = '网络请求失败';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            msg = xhr.responseJSON.data.message;
                        } else if (xhr && xhr.status) {
                            msg = '网络请求失败 (' + xhr.status + ')';
                        }
                        self.showToast(msg, 'error');
                    }
                });
            });

            // 确认购买置顶推广
            $(document).on('click', '#qibbs-confirm-sticky-btn', function (e) {
                e.preventDefault();
                if (self.isPurchasingSticky) {
                    return;
                }
                var targetPostId = $('input[name="qibbs_promote_target_post"]:checked').val() || self.directPromotePostId;
                if (!targetPostId) {
                    self.showToast('请选择要置顶推广的动态', 'warning');
                    return;
                }
                var propId = (self.activeBuyStickyProp && self.activeBuyStickyProp.id) ? self.activeBuyStickyProp.id : $('input[name="qibbs_promote_prop_choice"]:checked').val();
                if (!propId) {
                    self.showToast('请选择置顶推广套餐', 'warning');
                    return;
                }

                self.isPurchasingSticky = true;
                var $btn = $(this);
                $btn.prop('disabled', true).text('正在兑换并置顶...');

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_purchase_prop',
                        nonce: qibbsData.nonce,
                        prop_id: propId,
                        target_post_id: targetPostId
                    },
                    complete: function () {
                        self.isPurchasingSticky = false;
                        $btn.prop('disabled', false).text('确认兑换并置顶');
                    },
                    success: function (res) {
                        if (res.success) {
                            self.showToast(res.data.message || '动态置顶推广成功！');
                            $('#qibbs-sticky-select-modal').removeClass('show').fadeOut(150);
                            if (res.data.points_formatted) {
                                $('#qibbs-hero-prop-balance span').text(res.data.points_formatted);
                                $('#qibbs-modal-user-balance').text(res.data.points_formatted);
                            }
                            if (res.data.backpack) {
                                self.renderBackpackUI(res.data.backpack, res.data.active_color);
                            }
                            setTimeout(function () {
                                if (self.feed && self.feed.type) {
                                    self.loadFeed();
                                }
                            }, 500);
                        } else {
                            self.showToast(res.data ? res.data.message : '置顶推广失败', 'error');
                        }
                    },
                    error: function (xhr) {
                        var msg = '网络请求失败';
                        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                            msg = xhr.responseJSON.data.message;
                        } else if (xhr && xhr.status) {
                            msg = '网络请求失败 (' + xhr.status + ')';
                        }
                        self.showToast(msg, 'error');
                    }
                });
            });

            // 背包佩戴颜色
            $(document).on('click', '.qibbs-equip-color-btn', function (e) {
                e.preventDefault();
                var userPropId = $(this).data('user-prop-id');
                var color = $(this).data('color');
                var $btn = $(this);
                $btn.prop('disabled', true).text('佩戴中...');

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_equip_name_color',
                        nonce: qibbsData.nonce,
                        user_prop_id: userPropId
                    },
                    success: function (res) {
                        $btn.prop('disabled', false).text('佩戴');
                        if (res.success) {
                            self.showToast(res.data.message || '佩戴成功！');
                            self.updateActiveColorOnPage(res.data.active_color || color);
                            if (res.data.backpack) {
                                self.renderBackpackUI(res.data.backpack, res.data.active_color || color);
                            }
                        } else {
                            self.showToast(res.data ? res.data.message : '佩戴失败', 'error');
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).text('佩戴');
                        self.showToast('网络请求失败', 'error');
                    }
                });
            });

            // 卸下当前名字颜色
            $(document).on('click', '#qibbs-unequip-color-btn', function (e) {
                e.preventDefault();
                var $btn = $(this);
                $btn.prop('disabled', true).text('正在卸下...');

                $.ajax({
                    url: qibbsData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'qibbs_unequip_name_color',
                        nonce: qibbsData.nonce
                    },
                    success: function (res) {
                        $btn.prop('disabled', false).text('卸下名字颜色');
                        if (res.success) {
                            self.showToast(res.data.message || '已恢复默认昵称颜色');
                            self.updateActiveColorOnPage('');
                            if (res.data.backpack) {
                                self.renderBackpackUI(res.data.backpack, '');
                            }
                        } else {
                            self.showToast(res.data ? res.data.message : '卸下失败', 'error');
                        }
                    },
                    error: function () {
                        $btn.prop('disabled', false).text('卸下名字颜色');
                        self.showToast('网络请求失败', 'error');
                    }
                });
            });

            // 选帖单选框联动激活确认按钮
            $(document).on('change', 'input[name="qibbs_promote_target_post"]', function () {
                $('#qibbs-confirm-sticky-btn').prop('disabled', false);
            });

            // 弹窗关闭监听
            $(document).on('click', '[data-modal-close]', function (e) {
                e.preventDefault();
                $(this).closest('.qibbs-modal-overlay').removeClass('show').fadeOut(150);
            });
            $(document).on('click', '.qibbs-modal-overlay', function (e) {
                if ($(e.target).is('.qibbs-modal-overlay')) {
                    $(this).removeClass('show').fadeOut(150);
                }
            });

            // URL 携带 target_post 时自动唤起置顶
            var urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('target_post')) {
                var targetPostParam = parseInt(urlParams.get('target_post'), 10);
                if (targetPostParam > 0) {
                    setTimeout(function () {
                        self.openPromoteModalForPost(targetPostParam);
                    }, 300);
                }
            }
        },

        openPromoteModalForPost: function (postId) {
            var self = this;
            self.directPromotePostId = postId;

            if ($('#qibbs-prop-mall-container').length) {
                var stickyProps = [];
                $('#qibbs-prop-grid .qibbs-prop-card[data-prop-type="post_sticky"]').each(function () {
                    stickyProps.push({
                        id: $(this).data('prop-id'),
                        name: $(this).find('.qibbs-prop-card-title').text().trim(),
                        price: $(this).data('price'),
                        duration: $(this).find('.qibbs-prop-duration').text().trim()
                    });
                });

                if (stickyProps.length > 0) {
                    self.activeBuyStickyProp = stickyProps[0];
                    $('#qibbs-sticky-modal-prop-name').text(stickyProps[0].name);
                    $('#qibbs-sticky-modal-cost').text(stickyProps[0].price);

                    var optionsHtml = '<div style="margin-bottom:14px;padding:12px;background:var(--qibbs-bg-muted,#f8fafc);border-radius:10px;">';
                    optionsHtml += '<div style="font-size:12px;color:var(--qibbs-text-muted);margin-bottom:8px;">选择置顶推广套餐：</div>';
                    optionsHtml += '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
                    stickyProps.forEach(function (sp, idx) {
                        optionsHtml += '<label style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px solid var(--qibbs-border,#e2e8f0);border-radius:8px;cursor:pointer;background:var(--qibbs-card-bg,#fff);font-size:13px;">';
                        optionsHtml += '<input type="radio" name="qibbs_promote_prop_choice" value="' + sp.id + '" data-name="' + self.escapeHtml(sp.name) + '" data-price="' + sp.price + '" ' + (idx === 0 ? 'checked' : '') + '> ';
                        optionsHtml += '<span>' + self.escapeHtml(sp.name) + ' (' + sp.price + '积分)</span>';
                        optionsHtml += '</label>';
                    });
                    optionsHtml += '</div></div>';

                    $('#qibbs-sticky-post-list-container label').html('正在为动态 <strong>#' + postId + '</strong> 选购置顶推广：');
                    $('#qibbs-sticky-post-list').html(optionsHtml + '<div style="font-size:13px;color:var(--qibbs-text-muted);">置顶生效后，该动态将置顶于所属圈子顶部并显示「推广」角标。</div>');
                    $('#qibbs-confirm-sticky-btn').prop('disabled', false);

                    $(document).off('change.propchoice').on('change.propchoice', 'input[name="qibbs_promote_prop_choice"]', function () {
                        var cId = $(this).val();
                        var cName = $(this).data('name');
                        var cPrice = $(this).data('price');
                        self.activeBuyStickyProp = { id: cId, name: cName, price: cPrice };
                        $('#qibbs-sticky-modal-prop-name').text(cName);
                        $('#qibbs-sticky-modal-cost').text(cPrice);
                    });

                    $('#qibbs-sticky-select-modal').addClass('show').fadeIn(150);
                    return;
                }
            }

            // 若不在社区主页，跳转至道具大厅
            if (qibbsData.urls && qibbsData.urls.propMall) {
                window.location.href = qibbsData.urls.propMall + (qibbsData.urls.propMall.indexOf('?') !== -1 ? '&' : '?') + 'target_post=' + postId;
            }
        },

        loadUserPromotablePosts: function () {
            var self = this;
            var $list = $('#qibbs-sticky-post-list');
            $list.html('<div class="qibbs-loading" style="padding:24px 0;">正在读取你的动态...</div>');
            $('#qibbs-confirm-sticky-btn').prop('disabled', true);

            $.ajax({
                url: qibbsData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'qibbs_get_user_promotable_posts',
                    nonce: qibbsData.nonce
                },
                success: function (res) {
                    if (res.success && res.data.posts && res.data.posts.length > 0) {
                        var html = '<div class="qibbs-post-picker-list">';
                        res.data.posts.forEach(function (p) {
                            var isChecked = (self.directPromotePostId && parseInt(self.directPromotePostId, 10) === parseInt(p.id, 10)) ? 'checked' : '';
                            html += '<label class="qibbs-post-picker-item">';
                            html += '<input type="radio" name="qibbs_promote_target_post" value="' + p.id + '" ' + isChecked + '>';
                            html += '<div class="qibbs-post-picker-body">';
                            html += '<div class="qibbs-post-picker-title">#' + p.id + ' ' + self.escapeHtml(p.title) + '</div>';
                            html += '<div class="qibbs-post-picker-meta">';
                            html += '<span>' + self.escapeHtml(p.forum_name || '社区') + '</span> · ';
                            html += '<span>' + self.escapeHtml(p.created_at) + '</span>';
                            if (p.is_promoted) {
                                html += ' · <span style="color:#d97706;font-weight:600;">已在置顶推广中</span>';
                            }
                            html += '</div></div></label>';
                        });
                        html += '</div>';
                        $list.html(html);

                        if (self.directPromotePostId || $('input[name="qibbs_promote_target_post"]:checked').length) {
                            $('#qibbs-confirm-sticky-btn').prop('disabled', false);
                        }
                    } else {
                        $list.html('<div class="qibbs-empty-small" style="padding:24px 0;">暂无可置顶推广的动态，发表新动态后再来体验吧！</div>');
                    }
                },
                error: function () {
                    $list.html('<div class="qibbs-error-hint" style="padding:24px 0;">加载动态失败，请稍后重试</div>');
                }
            });
        },

        updateActiveColorOnPage: function (activeColor) {
            // 更新 Hero 个人颜色
            var $heroColor = $('#qibbs-hero-active-color');
            if ($heroColor.length) {
                $heroColor.find('.qibbs-color-dot').css('background', activeColor || '#94a3b8');
                $heroColor.find('span:last-child').css('color', activeColor || 'inherit').text(activeColor ? '专属颜色中' : '默认灰色');
            }

            // 更新试穿预览卡片
            var $sample = $('#qibbs-tryon-sample-name');
            if ($sample.length) {
                $sample.css('color', activeColor || 'inherit');
                $('#qibbs-tryon-color-label').text(activeColor ? ('已生效颜色：' + activeColor) : '当前默认颜色');
            }

            // 卸下按钮显隐
            var $unequipBtn = $('#qibbs-unequip-color-btn');
            if (activeColor) {
                if ($unequipBtn.length) {
                    $unequipBtn.show();
                } else {
                    $('.qibbs-backpack-header-row').append('<button type="button" class="qibbs-btn qibbs-btn-outline qibbs-btn-sm" id="qibbs-unequip-color-btn">卸下名字颜色</button>');
                }
            } else {
                $unequipBtn.hide();
            }

            // 联动更新全站当前用户发言昵称颜色
            if (qibbsData.currentUser) {
                $('.qibbs-post-card[data-author-id="' + qibbsData.currentUser + '"] .qibbs-post-author, .qibbs-comment[data-author-id="' + qibbsData.currentUser + '"] .qibbs-comment-author, .qibbs-reply[data-author-id="' + qibbsData.currentUser + '"] .qibbs-reply-author').css('color', activeColor || '');
            }
        },

        renderBackpackUI: function (backpack, activeColor) {
            var self = this;
            if (!backpack) {
                return;
            }

            // 名字颜色列表
            var $colorGrid = $('#qibbs-backpack-colors-grid');
            if ($colorGrid.length) {
                if (!backpack.colors || backpack.colors.length === 0) {
                    $colorGrid.html('<div class="qibbs-backpack-empty-card"><p>暂无已兑换的名字颜色，前往专柜挑选中意的专属颜色吧！</p></div>');
                } else {
                    var cHtml = '';
                    backpack.colors.forEach(function (c) {
                        var isEquipped = !!c.is_equipped;
                        var isExpired = !!c.is_expired;
                        cHtml += '<div class="qibbs-backpack-item ' + (isEquipped ? 'is-equipped' : '') + ' ' + (isExpired ? 'is-expired' : '') + '" data-user-prop-id="' + c.id + '">';
                        cHtml += '<div class="qibbs-backpack-visual"><span class="qibbs-color-pill-sample" style="background:' + self.escapeHtml(c.color) + ';"></span></div>';
                        cHtml += '<div class="qibbs-backpack-meta">';
                        cHtml += '<div class="qibbs-backpack-item-name" style="color:' + self.escapeHtml(c.color) + ';font-weight:600;">' + self.escapeHtml(c.prop_name) + '</div>';
                        cHtml += '<div class="qibbs-backpack-item-tip">' + self.escapeHtml(c.expires_tip) + '</div></div>';
                        cHtml += '<div class="qibbs-backpack-action">';
                        if (isExpired) {
                            cHtml += '<span class="qibbs-badge-expired">已到期</span>';
                        } else if (isEquipped) {
                            cHtml += '<span class="qibbs-badge-active-equipped">佩戴中</span>';
                        } else {
                            cHtml += '<button type="button" class="qibbs-btn qibbs-btn-primary qibbs-btn-sm qibbs-equip-color-btn" data-user-prop-id="' + c.id + '" data-color="' + self.escapeHtml(c.color) + '">佩戴</button>';
                        }
                        cHtml += '</div></div>';
                    });
                    $colorGrid.html(cHtml);
                }
            }

            // 置顶推广列表
            var $promoGrid = $('#qibbs-backpack-promotions-grid');
            if ($promoGrid.length) {
                if (!backpack.promotions || backpack.promotions.length === 0) {
                    $promoGrid.html('<div class="qibbs-backpack-empty-card"><p>暂无置顶推广记录，前往圈子发表动态并选购推广吧！</p></div>');
                } else {
                    var prHtml = '';
                    backpack.promotions.forEach(function (pr) {
                        var isExpired = !!pr.is_expired;
                        prHtml += '<div class="qibbs-backpack-item ' + (isExpired ? 'is-expired' : 'is-promoted') + '">';
                        prHtml += '<div class="qibbs-backpack-visual"><span class="qibbs-promo-flag"><svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2L1 21h22L12 2zm0 3.99L19.53 19H4.47L12 5.99zM11 10h2v4h-2zm0 5h2v2h-2z"/></svg></span></div>';
                        prHtml += '<div class="qibbs-backpack-meta">';
                        prHtml += '<div class="qibbs-backpack-item-name">动态 #' + pr.post_id + ' 置顶推广</div>';
                        prHtml += '<div class="qibbs-backpack-item-tip">' + self.escapeHtml(pr.expires_tip) + '</div></div>';
                        prHtml += '<div class="qibbs-backpack-action">';
                        if (pr.post_id) {
                            prHtml += '<a href="?p=' + pr.post_id + '" class="qibbs-btn qibbs-btn-outline qibbs-btn-sm" target="_blank">查看动态</a>';
                        }
                        prHtml += '</div></div>';
                    });
                    $promoGrid.html(prHtml);
                }
            }

            // 更新背包气泡徽标
            var count = (backpack.colors ? backpack.colors.length : 0) + (backpack.promotions ? backpack.promotions.length : 0);
            var $countBadge = $('#qibbs-backpack-count-badge');
            if (count > 0) {
                $countBadge.text(count).show();
            } else {
                $countBadge.hide();
            }
        }
    };

    // 全局灯箱大图预览
    window.qibbsLightbox = function (img) {
        var src = img.src;
        var $lightbox = $('<div class="qibbs-lightbox"><img src="' + src + '"></div>');
        $('body').append($lightbox);
        setTimeout(function () { $lightbox.addClass('show'); }, 10);
        $lightbox.on('click', function () {
            $(this).removeClass('show');
            setTimeout(function () { $lightbox.remove(); }, 300);
        });
    };

    // Init
    $(document).ready(function () {
        if ($('#qibbs-feed').length || $('.qibbs-wrapper').length) {
            Qibbs.init();
        }
    });

})(jQuery);
