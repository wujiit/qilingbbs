/**
 * 启灵微社区 - 靓号商城、C2C交易大厅与号码背包前端控制器
 *
 * @package Qibbs_Community
 */

(function ($) {
    'use strict';

    /**
     * 安全 HTML 转义辅助函数，抵御 XSS 并保障各作用域无缝可用
     *
     * @param {string} str 待转义字符串
     * @return {string}
     */
    function escapeHtml(str) {
        if (!str) return '';
        return $('<div>').text(str).html();
    }

    var QibbsVanityMall = {
        state: {
            activeTab: 'official', // 'official' | 'c2c' | 'backpack'
            official: {
                page: 1,
                perPage: 20,
                grade: 0,
                orderBy: 'sale_price',
                order: 'ASC',
                search: '',
                total: 0,
                totalPages: 1,
                loaded: false
            },
            c2c: {
                page: 1,
                perPage: 20,
                grade: 0,
                currencyType: 'all',
                orderBy: 'c2c_listed_at',
                order: 'DESC',
                search: '',
                total: 0,
                totalPages: 1,
                loaded: false
            },
            backpack: {
                items: [],
                activeCuid: 0,
                loaded: false
            },
            records: {
                tab: 'market',
                tradeType: 'all',
                page: 1,
                perPage: 20,
                total: 0,
                totalPages: 1,
                loaded: false
            },
            activeBuyItem: null,
            activeRecycleQuote: null
        },

        searchTimers: {},

        init: function () {
            if (!$('#qibbs-vanity-mall-container').length) {
                return;
            }

            // 读取服务端预渲染的真实数量，初始化内部统计状态
            var initOfficialCount = parseInt($('#qibbs-official-tab-count').text(), 10);
            if (!isNaN(initOfficialCount) && initOfficialCount >= 0) {
                this.state.official.total = initOfficialCount;
            }
            var initC2CCount = parseInt($('#qibbs-c2c-tab-count').text(), 10);
            if (!isNaN(initC2CCount) && initC2CCount >= 0) {
                this.state.c2c.total = initC2CCount;
            }

            this.bindEvents();
            this.handleInitialRouting();
            this.syncTabCounts();
        },

        bindEvents: function () {
            var self = this;

            // 1. 侧边栏/移动端路由导航切换
            $(document).on('click', '[data-filter="vanity-mall"], #qibbs-nav-vanity-mall', function (e) {
                e.preventDefault();
                self.showVanityMall();
            });

            // 当点击社区原生动态流、圈子分类或成长中心时，切换并退出交易大厅
            $(document).on('click', '[data-filter-type="feed"], [data-filter-type="forum"], [data-filter-type="category"], [data-filter="growth-center"], #qibbs-nav-growth-center', function () {
                var filter = $(this).data('filter');
                if (filter !== 'vanity-mall') {
                    self.hideVanityMall(false);
                }
            });

            // 浏览器前进/后退历史监听
            window.addEventListener('popstate', function () {
                var urlParams = new URLSearchParams(window.location.search);
                if (urlParams.get('view') === 'vanity-mall' || window.location.hash === '#vanity-mall') {
                    self.showVanityMall(false);
                } else {
                    self.hideVanityMall(false);
                }
            });

            // 2. 交易大厅主 Tab 切换
            $(document).on('click', '.qibbs-vanity-tab', function (e) {
                e.preventDefault();
                var tab = $(this).data('vanity-tab');
                self.switchTab(tab);
            });

            // 快捷跳转号码背包
            $(document).on('click', '#qibbs-jump-backpack-btn, #qibbs-c2c-first-list-btn, #qibbs-go-list-c2c-btn', function (e) {
                e.preventDefault();
                self.switchTab('backpack');
            });

            // 3. 官方商城筛选与操作
            $(document).on('click', '#qibbs-official-grade-filter .qibbs-grade-pill', function () {
                var grade = parseInt($(this).data('grade'), 10) || 0;
                $('#qibbs-official-grade-filter .qibbs-grade-pill').removeClass('active');
                $(this).addClass('active');
                self.state.official.grade = grade;
                self.state.official.page = 1;
                self.loadOfficialMall();
            });

            $(document).on('change', '#qibbs-official-sort', function () {
                var val = $(this).val().split('-');
                self.state.official.orderBy = val[0] || 'sale_price';
                self.state.official.order = (val[1] || 'ASC').toUpperCase();
                self.state.official.page = 1;
                self.loadOfficialMall();
            });

            $(document).on('input', '#qibbs-official-search', function () {
                var q = $(this).val().trim();
                clearTimeout(self.searchTimers.official);
                self.searchTimers.official = setTimeout(function () {
                    self.state.official.search = q;
                    self.state.official.page = 1;
                    self.loadOfficialMall();
                }, 350);
            });

            $(document).on('click', '#qibbs-official-reset-btn', function () {
                $('#qibbs-official-search').val('');
                $('#qibbs-official-sort').val('sale_price-asc');
                $('#qibbs-official-grade-filter .qibbs-grade-pill').removeClass('active');
                $('#qibbs-official-grade-filter .qibbs-grade-pill[data-grade="0"]').addClass('active');
                self.state.official.grade = 0;
                self.state.official.search = '';
                self.state.official.orderBy = 'sale_price';
                self.state.official.order = 'ASC';
                self.state.official.page = 1;
                self.loadOfficialMall();
            });

            $(document).on('click', '#qibbs-official-prev-btn', function () {
                if (self.state.official.page > 1) {
                    self.state.official.page--;
                    self.loadOfficialMall();
                }
            });

            $(document).on('click', '#qibbs-official-next-btn', function () {
                if (self.state.official.page < self.state.official.totalPages) {
                    self.state.official.page++;
                    self.loadOfficialMall();
                }
            });

            // 购买弹窗呼出
            $(document).on('click', '.qibbs-buy-mall-btn', function (e) {
                e.preventDefault();
                var itemData = $(this).data('item');
                self.openBuyModal(itemData);
            });

            // 确认购买兑换
            $(document).on('click', '#qibbs-confirm-buy-btn', function () {
                self.executeBuyMallItem();
            });

            // 切换购买后是否佩戴
            $(document).on('change', '#qibbs-buy-auto-equip-toggle', function () {
                var isEquip = $(this).is(':checked');
                var pointsName = qibbsData.pointsName || '灵力';
                if (isEquip) {
                    $('#qibbs-confirm-buy-btn .qibbs-btn-label').text(pointsName + '兑换并立即佩戴');
                } else {
                    $('#qibbs-confirm-buy-btn .qibbs-btn-label').text(pointsName + '兑换（存入背包）');
                }
            });

            // 现金直接购买
            $(document).on('click', '#qibbs-cash-buy-direct-btn', function (e) {
                e.preventDefault();
                $('#qibbs-buy-modal').fadeOut(100);
                if (self.state.activeBuyItem) {
                    self.openCashCheckout(self.state.activeBuyItem);
                }
            });

            // 4. C2C 寄售大厅筛选与操作
            $(document).on('click', '#qibbs-c2c-grade-filter .qibbs-grade-pill', function () {
                var grade = parseInt($(this).data('grade'), 10) || 0;
                $('#qibbs-c2c-grade-filter .qibbs-grade-pill').removeClass('active');
                $(this).addClass('active');
                self.state.c2c.grade = grade;
                self.state.c2c.page = 1;
                self.loadC2CMarket();
            });

            $(document).on('change', '#qibbs-c2c-currency', function () {
                self.state.c2c.currencyType = $(this).val();
                self.state.c2c.page = 1;
                self.loadC2CMarket();
            });

            $(document).on('change', '#qibbs-c2c-sort', function () {
                var val = $(this).val().split('-');
                self.state.c2c.orderBy = val[0] || 'c2c_listed_at';
                self.state.c2c.order = (val[1] || 'DESC').toUpperCase();
                self.state.c2c.page = 1;
                self.loadC2CMarket();
            });

            $(document).on('input', '#qibbs-c2c-search', function () {
                var q = $(this).val().trim();
                clearTimeout(self.searchTimers.c2c);
                self.searchTimers.c2c = setTimeout(function () {
                    self.state.c2c.search = q;
                    self.state.c2c.page = 1;
                    self.loadC2CMarket();
                }, 350);
            });

            $(document).on('click', '#qibbs-c2c-prev-btn', function () {
                if (self.state.c2c.page > 1) {
                    self.state.c2c.page--;
                    self.loadC2CMarket();
                }
            });

            $(document).on('click', '#qibbs-c2c-next-btn', function () {
                if (self.state.c2c.page < self.state.c2c.totalPages) {
                    self.state.c2c.page++;
                    self.loadC2CMarket();
                }
            });

            // 购买 C2C 寄售号码（唤起专属 C2C 购买确认弹窗）
            $(document).on('click', '.qibbs-buy-c2c-btn', function (e) {
                e.preventDefault();
                var item = $(this).data('item');
                self.openC2CBuyModal(item);
            });

            // 确认 C2C 积分购买
            $(document).on('click', '#qibbs-c2c-confirm-buy-btn', function () {
                self.executeBuyC2CItemWithPoints();
            });

            // C2C 现金支付跳转收银台
            $(document).on('click', '#qibbs-c2c-cash-buy-direct-btn', function () {
                if (self.state.activeC2CBuyItem) {
                    $('#qibbs-c2c-buy-modal').fadeOut(150);
                    self.openCashCheckout(self.state.activeC2CBuyItem);
                }
            });

            // 下架 C2C 寄售号码
            $(document).on('click', '.qibbs-delist-c2c-btn', function (e) {
                e.preventDefault();
                var cuid = $(this).data('cuid');
                self.executeDelistNumber(cuid);
            });

            // 5. 号码背包管理与交互
            $(document).on('click', '#qibbs-bp-refresh-btn', function () {
                self.loadBackpack(true);
            });

            // 切换佩戴号码
            $(document).on('click', '.qibbs-equip-btn', function (e) {
                e.preventDefault();
                var cuid = $(this).data('cuid');
                self.executeEquipNumber(cuid);
            });

            // 呼出 C2C 挂牌弹窗
            $(document).on('click', '.qibbs-list-modal-btn', function (e) {
                e.preventDefault();
                var cuid = $(this).data('cuid');
                var gradeLabel = $(this).data('grade-label') || '靓号';
                var gradeLevel = $(this).data('grade-level') || 1;
                self.openListModal(cuid, gradeLabel, gradeLevel);
            });

            // 确认挂牌
            $(document).on('click', '#qibbs-confirm-list-btn', function () {
                self.executeListNumber();
            });

            // 呼出官方回收报价弹窗
            $(document).on('click', '.qibbs-recycle-modal-btn', function (e) {
                e.preventDefault();
                var cuid = $(this).data('cuid');
                var gradeLabel = $(this).data('grade-label') || '靓号';
                var gradeLevel = $(this).data('grade-level') || 1;
                self.openRecycleModal(cuid, gradeLabel, gradeLevel);
            });

            // 确认官方回收
            $(document).on('click', '#qibbs-confirm-recycle-btn', function () {
                self.executeRecycleNumber();
            });

            // 6. 全局弹窗关闭事件
            $(document).on('click', '[data-modal-close], .qibbs-vanity-modal-backdrop', function (e) {
                if (e.target === this || $(e.target).closest('[data-modal-close]').length) {
                    $('.qibbs-vanity-modal-backdrop').fadeOut(150);
                }
            });

            $(document).on('keydown', function (e) {
                if (e.key === 'Escape' || e.keyCode === 27) {
                    $('.qibbs-vanity-modal-backdrop').fadeOut(150);
                }
            });

            // 7. 成交动态与记录 Tab/类型筛选/翻页
            $(document).on('click', '#qibbs-records-tab-filter .qibbs-grade-pill', function () {
                var rTab = $(this).data('records-tab') || 'market';
                $('#qibbs-records-tab-filter .qibbs-grade-pill').removeClass('active');
                $(this).addClass('active');
                self.state.records.tab = rTab;
                self.state.records.page = 1;
                self.loadTradeRecords();
            });

            $(document).on('click', '#qibbs-records-type-filter .qibbs-type-filter-btn', function () {
                var tType = $(this).data('trade-type') || 'all';
                $('#qibbs-records-type-filter .qibbs-type-filter-btn').removeClass('active');
                $(this).addClass('active');
                self.state.records.tradeType = tType;
                self.state.records.page = 1;
                self.loadTradeRecords();
            });

            $(document).on('click', '#qibbs-records-prev-btn', function () {
                if (self.state.records.page > 1) {
                    self.state.records.page--;
                    self.loadTradeRecords();
                }
            });

            $(document).on('click', '#qibbs-records-next-btn', function () {
                if (self.state.records.page < self.state.records.totalPages) {
                    self.state.records.page++;
                    self.loadTradeRecords();
                }
            });
        },

        handleInitialRouting: function () {
            var urlParams = new URLSearchParams(window.location.search);
            var isVanityView = urlParams.get('view') === 'vanity-mall' || window.location.hash === '#vanity-mall';

            if (isVanityView) {
                var requestedTab = urlParams.get('tab');
                if (requestedTab && ['official', 'c2c', 'backpack', 'records'].indexOf(requestedTab) !== -1) {
                    this.state.activeTab = requestedTab;
                }

                this.showVanityMall(false);

                // 若网关/收银台支付成功回调跳转回交易中心
                if (urlParams.get('pay_success') === '1') {
                    var cuid = urlParams.get('cuid');
                    this.showToast('订单支付成功！' + (cuid ? '靓号 ' + cuid + ' 已发放到您的号码背包！' : ''), 'success');
                    this.switchTab('backpack', true);
                    this.syncTabCounts();

                    // 清除 pay_success 和 cuid 避免刷新反复提示
                    urlParams.delete('pay_success');
                    urlParams.delete('cuid');
                    var newSearch = urlParams.toString();
                    var newUrl = window.location.pathname + (newSearch ? '?' + newSearch : '') + window.location.hash;
                    if (window.history && window.history.replaceState) {
                        window.history.replaceState({ view: 'vanity-mall' }, '', newUrl);
                    }
                }
            }
        },

        syncTabCounts: function () {
            var self = this;
            if (this._syncingCounts) {
                return;
            }
            this._syncingCounts = true;
            setTimeout(function () {
                self._syncingCounts = false;
            }, 1500);

            // 1. 同步 C2C 寄售大厅数量（若当前处于其他 Tab 且 C2C 尚未全量加载）
            if ($('#qibbs-c2c-tab-count').length > 0 && (!this.state.c2c.loaded || this.state.activeTab !== 'c2c')) {
                $.ajax({
                    url: qibbsData.restUrl + 'c2c/market',
                    type: 'GET',
                    data: {
                        page: 1,
                        per_page: 1
                    },
                    beforeSend: function (xhr) {
                        if (qibbsData.restNonce) {
                            xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                        }
                    },
                    success: function (res) {
                        if (res && res.success && res.data && typeof res.data.total !== 'undefined') {
                            var total = parseInt(res.data.total, 10) || 0;
                            self.state.c2c.total = total;
                            $('#qibbs-c2c-tab-count').text(total);
                        }
                    }
                });
            }

            // 2. 同步号码背包数量（若用户已登录且背包尚未全量加载）
            if (qibbsData.isLoggedIn && (!this.state.backpack.loaded || this.state.activeTab !== 'backpack')) {
                $.ajax({
                    url: qibbsData.restUrl + 'uid/backpack',
                    type: 'GET',
                    beforeSend: function (xhr) {
                        if (qibbsData.restNonce) {
                            xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                        }
                    },
                    success: function (res) {
                        if (res && res.success && res.data && Array.isArray(res.data.items)) {
                            var bpCount = res.data.items.length;
                            $('#qibbs-backpack-tab-count').text(bpCount);
                            $('#qibbs-bp-total-count').text(bpCount);
                        }
                    }
                });
            }

            // 3. 同步官方自营商城数量（若当前处于其他 Tab 且官方商城尚未全量加载）
            if ($('#qibbs-official-tab-count').length > 0 && (!this.state.official.loaded || this.state.activeTab !== 'official')) {
                $.ajax({
                    url: qibbsData.restUrl + 'uid/mall',
                    type: 'GET',
                    data: {
                        page: 1,
                        per_page: 1
                    },
                    beforeSend: function (xhr) {
                        if (qibbsData.restNonce) {
                            xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                        }
                    },
                    success: function (res) {
                        if (res && res.success && res.data && typeof res.data.total !== 'undefined') {
                            var total = parseInt(res.data.total, 10) || 0;
                            self.state.official.total = total;
                            $('#qibbs-official-tab-count').text(total);
                        }
                    }
                });
            }
        },

        showVanityMall: function (pushHistory) {
            if (pushHistory !== false && window.history && window.history.pushState) {
                var mallUrl = (window.qibbsData && window.qibbsData.urls && window.qibbsData.urls.vanityMall)
                    ? window.qibbsData.urls.vanityMall
                    : window.location.pathname + '?view=vanity-mall';
                window.history.pushState({ view: 'vanity-mall' }, '', mallUrl);
            }

            // 更新侧边栏高亮
            $('.qibbs-nav-item').removeClass('active');
            $('#qibbs-nav-vanity-mall, [data-filter="vanity-mall"]').addClass('active');

            // 严苛互斥：彻底隐藏成长中心、发帖框与主动态流，显示交易大厅
            $('#qibbs-growth-center').hide();
            $('#qibbs-nav-growth-center, [data-filter="growth-center"]').removeClass('active');
            $('#qibbs-composer, .qibbs-composer, #qibbs-filter-bar, #qibbs-feed, #qibbs-load-more').hide();
            $('#qibbs-vanity-mall-container').fadeIn(200);

            // 自动加载首屏数据
            this.switchTab(this.state.activeTab, false);
            this.syncTabCounts();
        },

        hideVanityMall: function (pushHistory) {
            if (pushHistory !== false && window.history && window.history.pushState) {
                var homeUrl = (window.qibbsData && window.qibbsData.urls && window.qibbsData.urls.community)
                    ? window.qibbsData.urls.community
                    : window.location.pathname;
                window.history.pushState({}, '', homeUrl);
            }

            // 隐藏交易大厅
            $('#qibbs-vanity-mall-container').hide();
            $('#qibbs-nav-vanity-mall, [data-filter="vanity-mall"]').removeClass('active');
            // 仅在成长中心未处于显示状态时才恢复发帖框与动态流
            if ($('#qibbs-growth-center:visible').length === 0) {
                $('#qibbs-composer, .qibbs-composer, #qibbs-feed').show();
            }
        },

        switchTab: function (tabName, forceReload) {
            this.state.activeTab = tabName;

            $('.qibbs-vanity-tab').removeClass('active').attr('aria-selected', 'false');
            $('.qibbs-vanity-tab[data-vanity-tab="' + tabName + '"]').addClass('active').attr('aria-selected', 'true');

            $('.qibbs-vanity-pane').removeClass('is-active');
            $('#qibbs-pane-' + tabName).addClass('is-active');

            if (tabName === 'official') {
                if (!this.state.official.loaded || forceReload) {
                    this.loadOfficialMall();
                }
            } else if (tabName === 'c2c') {
                if (!this.state.c2c.loaded || forceReload) {
                    this.loadC2CMarket();
                }
            } else if (tabName === 'backpack') {
                if (!this.state.backpack.loaded || forceReload) {
                    this.loadBackpack();
                }
            } else if (tabName === 'records') {
                if (!this.state.records.loaded || forceReload) {
                    this.loadTradeRecords();
                }
            }
        },

        /* ==================== 1. 官方自营商城加载与渲染 ==================== */
        loadOfficialMall: function () {
            var self = this;
            var params = {
                page: this.state.official.page,
                per_page: this.state.official.perPage,
                grade: this.state.official.grade,
                order_by: this.state.official.orderBy,
                order: this.state.official.order
            };

            $('#qibbs-official-grid').empty();
            $('#qibbs-official-empty').hide();
            $('#qibbs-official-pagination').hide();
            $('#qibbs-official-loading').show();

            $.ajax({
                url: qibbsData.restUrl + 'uid/mall',
                type: 'GET',
                data: params,
                beforeSend: function (xhr) {
                    if (qibbsData.restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                    }
                },
                success: function (res) {
                    $('#qibbs-official-loading').hide();
                    self.state.official.loaded = true;

                    if (!res || !res.success || !res.data) {
                        $('#qibbs-official-empty').show();
                        return;
                    }

                    var data = res.data;
                    var items = data.items || [];
                    self.state.official.total = data.total || 0;
                    self.state.official.totalPages = data.total_pages || 1;

                    // 刷新 Tab 数量角标
                    $('#qibbs-official-tab-count').text(data.total || 0);

                    // 刷新个人积分显示
                    if (typeof data.user_balance !== 'undefined' && data.user_balance !== null) {
                        $('#qibbs-hero-user-points span').text(data.user_balance);
                    }

                    if (items.length === 0) {
                        $('#qibbs-official-empty').show();
                        return;
                    }

                    // 客户端关键字过滤（若有搜索输入）
                    if (self.state.official.search) {
                        var kw = self.state.official.search.toLowerCase();
                        items = items.filter(function (it) {
                            return String(it.cuid).indexOf(kw) !== -1;
                        });
                        if (items.length === 0) {
                            $('#qibbs-official-empty').show();
                            return;
                        }
                    }

                    self.renderOfficialGrid(items, data.points_name || '积分');

                    // 分页控制器更新
                    if (self.state.official.totalPages > 1) {
                        $('#qibbs-official-page-info').text(self.state.official.page + ' / ' + self.state.official.totalPages);
                        $('#qibbs-official-prev-btn').prop('disabled', self.state.official.page <= 1);
                        $('#qibbs-official-next-btn').prop('disabled', self.state.official.page >= self.state.official.totalPages);
                        $('#qibbs-official-pagination').show();
                    }
                },
                error: function () {
                    $('#qibbs-official-loading').hide();
                    $('#qibbs-official-empty').show();
                    self.showToast('获取官方货架失败，请稍后重试', 'error');
                }
            });
        },

        renderOfficialGrid: function (items, pointsName) {
            var self = this;
            var $grid = $('#qibbs-official-grid');
            $grid.empty();
            var hasShopPoints = !!(qibbsData.hasShopPoints || (qibbsData.vanityMall && qibbsData.vanityMall.hasShopPoints));
            var symbol = qibbsData.currencySymbol || '¥';

            items.forEach(function (it) {
                var gradeClass = 'qibbs-grade-level-' + it.grade;
                var gradeLabel = it.grade_label || '优质靓号';
                var pointsPrice = parseInt(it.price_points || it.price, 10) || 0;
                var cashPrice = parseFloat(it.price_cash) || 0;

                var pricesHtml = '<div class="qibbs-vanity-card-prices">';
                if (hasShopPoints && pointsPrice > 0) {
                    pricesHtml += '<div class="qibbs-price-points"><svg viewBox="0 0 24 24" width="16" height="16" fill="#f59e0b"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M15 9.5a2.5 2.5 0 00-5 0c0 3 5 2 5 5a2.5 2.5 0 01-5 0" fill="none" stroke="#fff" stroke-width="2"/></svg><strong>' + pointsPrice.toLocaleString() + '</strong> ' + self.escapeHtml(pointsName) + '</div>';
                }
                if (cashPrice > 0 && qibbsData.paymentEnabled) {
                    pricesHtml += '<div class="qibbs-price-cash"><span class="qibbs-currency-symbol">' + symbol + '</span><strong>' + cashPrice.toFixed(2) + '</strong></div>';
                }
                pricesHtml += '</div>';

                var cardHtml = [
                    '<div class="qibbs-vanity-card ' + gradeClass + '">',
                        '<div class="qibbs-vanity-card-header">',
                            '<span class="qibbs-card-grade-badge">' + self.escapeHtml(gradeLabel) + '</span>',
                            '<span class="qibbs-card-official-tag">官方自营</span>',
                        '</div>',
                        '<div class="qibbs-vanity-card-num">',
                            self.formatCuid(it.cuid),
                        '</div>',
                        pricesHtml,
                        '<div class="qibbs-vanity-card-actions">',
                            '<button type="button" class="qibbs-btn qibbs-btn-primary qibbs-btn-block qibbs-buy-mall-btn" data-item=\'' + JSON.stringify(it) + '\'>',
                                '<span>' + (hasShopPoints ? '立即购买' : '现金购买') + '</span>',
                            '</button>',
                        '</div>',
                    '</div>'
                ].join('');

                $grid.append(cardHtml);
            });
        },

        /* ==================== 2. C2C 寄售大厅加载与渲染 ==================== */
        loadC2CMarket: function () {
            var self = this;
            var params = {
                page: this.state.c2c.page,
                per_page: this.state.c2c.perPage,
                grade: this.state.c2c.grade,
                currency_type: this.state.c2c.currencyType,
                order_by: this.state.c2c.orderBy,
                order: this.state.c2c.order,
                keyword: this.state.c2c.search
            };

            $('#qibbs-c2c-grid').empty();
            $('#qibbs-c2c-empty').hide();
            $('#qibbs-c2c-pagination').hide();
            $('#qibbs-c2c-loading').show();

            $.ajax({
                url: qibbsData.restUrl + 'c2c/market',
                type: 'GET',
                data: params,
                beforeSend: function (xhr) {
                    if (qibbsData.restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                    }
                },
                success: function (res) {
                    $('#qibbs-c2c-loading').hide();
                    self.state.c2c.loaded = true;

                    if (!res || !res.success || !res.data) {
                        $('#qibbs-c2c-empty').show();
                        return;
                    }

                    var data = res.data;
                    var items = data.items || [];
                    self.state.c2c.total = data.total || 0;
                    self.state.c2c.totalPages = data.total_pages || 1;

                    // 刷新 Tab 数量角标
                    $('#qibbs-c2c-tab-count').text(data.total || 0);

                    if (items.length === 0) {
                        $('#qibbs-c2c-empty').show();
                        return;
                    }

                    self.renderC2CGrid(items);

                    // 分页控制器更新
                    if (self.state.c2c.totalPages > 1) {
                        $('#qibbs-c2c-page-info').text(self.state.c2c.page + ' / ' + self.state.c2c.totalPages);
                        $('#qibbs-c2c-prev-btn').prop('disabled', self.state.c2c.page <= 1);
                        $('#qibbs-c2c-next-btn').prop('disabled', self.state.c2c.page >= self.state.c2c.totalPages);
                        $('#qibbs-c2c-pagination').show();
                    }
                },
                error: function () {
                    $('#qibbs-c2c-loading').hide();
                    $('#qibbs-c2c-empty').show();
                    self.showToast('获取 C2C 寄售大厅列表失败', 'error');
                }
            });
        },

        renderC2CGrid: function (items) {
            var self = this;
            var $grid = $('#qibbs-c2c-grid');
            $grid.empty();
            var currentUserId = parseInt(qibbsData.currentUser, 10) || 0;
            var hasShopPoints = !!(qibbsData.hasShopPoints || (qibbsData.vanityMall && qibbsData.vanityMall.hasShopPoints));
            var pointsName = (qibbsData.vanityMall && qibbsData.vanityMall.pointsName) ? qibbsData.vanityMall.pointsName : '积分';

            items.forEach(function (it) {
                var isMine = (currentUserId > 0 && it.seller_id === currentUserId);
                var gradeClass = 'qibbs-grade-level-' + it.grade;
                var gradeLabel = it.grade_label || '稀有靓号';
                var pointsPrice = parseInt(it.c2c_price_points || it.price_points, 10) || 0;
                var cashPrice = parseFloat(it.c2c_price_cash || it.price_cash) || 0;

                var actionBtnHtml = '';
                if (isMine) {
                    actionBtnHtml = '<button type="button" class="qibbs-btn qibbs-btn-warning qibbs-btn-block qibbs-delist-c2c-btn" data-cuid="' + it.cuid + '">下架撤单</button>';
                } else {
                    actionBtnHtml = '<button type="button" class="qibbs-btn qibbs-btn-success qibbs-btn-block qibbs-buy-c2c-btn" data-item=\'' + JSON.stringify(it) + '\'>立即购买</button>';
                }

                var cardHtml = [
                    '<div class="qibbs-vanity-card qibbs-c2c-card ' + gradeClass + '">',
                        '<div class="qibbs-vanity-card-header">',
                            '<span class="qibbs-card-grade-badge">' + self.escapeHtml(gradeLabel) + '</span>',
                            (isMine ? '<span class="qibbs-card-mine-tag">我的寄售</span>' : '<span class="qibbs-card-c2c-tag">玩家寄售</span>'),
                        '</div>',
                        '<div class="qibbs-vanity-card-num">',
                            self.formatCuid(it.cuid),
                        '</div>',
                        '<div class="qibbs-c2c-seller-info">',
                            '<img src="' + self.escapeHtml(it.seller_avatar) + '" alt="" class="qibbs-seller-avatar">',
                            '<div class="qibbs-seller-meta">',
                                '<span class="qibbs-seller-name">' + self.escapeHtml(it.seller_name) + '</span>',
                                '<span class="qibbs-seller-time"' + ((it.created_at_formatted || it.created_at) ? ' title="' + self.escapeHtml(it.created_at_formatted || it.created_at) + '"' : '') + '>' + self.escapeHtml(it.listed_time_ago) + '</span>',
                            '</div>',
                        '</div>',
                        '<div class="qibbs-vanity-card-prices">',
                            (hasShopPoints && pointsPrice > 0 ? '<div class="qibbs-price-points"><svg viewBox="0 0 24 24" width="16" height="16" fill="#f59e0b"><circle cx="12" cy="12" r="10"/><path d="M12 6v12M15 9.5a2.5 2.5 0 00-5 0c0 3 5 2 5 5a2.5 2.5 0 01-5 0" fill="none" stroke="#fff" stroke-width="2"/></svg> <strong>' + pointsPrice.toLocaleString() + '</strong> ' + self.escapeHtml(pointsName) + '</div>' : ''),
                            (cashPrice > 0 && qibbsData.paymentEnabled ? '<div class="qibbs-price-cash">' + (qibbsData.currencySymbol || '¥') + ' <strong>' + cashPrice.toFixed(2) + '</strong></div>' : ''),
                        '</div>',
                        '<div class="qibbs-vanity-card-actions">',
                            actionBtnHtml,
                        '</div>',
                    '</div>'
                ].join('');

                $grid.append(cardHtml);
            });
        },

        /* ==================== 3. 我的号码背包加载与渲染 ==================== */
        loadBackpack: function (forceToast) {
            var self = this;
            if (!qibbsData.isLoggedIn) {
                return;
            }

            $('#qibbs-backpack-loading').show();
            $('#qibbs-backpack-grid').empty();

            $.ajax({
                url: qibbsData.restUrl + 'uid/backpack',
                type: 'GET',
                beforeSend: function (xhr) {
                    if (qibbsData.restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                    }
                },
                success: function (res) {
                    $('#qibbs-backpack-loading').hide();
                    self.state.backpack.loaded = true;

                    if (!res || !res.success || !res.data) {
                        self.showToast('读取号码背包失败', 'error');
                        return;
                    }

                    var data = res.data;
                    var items = data.items || [];
                    self.state.backpack.items = items;
                    self.state.backpack.activeCuid = data.active_cuid || 0;

                    // 刷新背包数量与当前生效号
                    $('#qibbs-backpack-tab-count').text(items.length);
                    $('#qibbs-bp-total-count').text(items.length);

                    if (data.active_cuid) {
                        $('#qibbs-hero-active-cuid span').text(data.active_cuid);
                    }

                    if (typeof data.points_balance !== 'undefined' && data.points_balance !== null) {
                        $('#qibbs-hero-user-points span').text(data.points_balance);
                    }

                    self.renderBackpackGrid(items, data.active_cuid);

                    if (forceToast) {
                        self.showToast('号码背包资产已刷新', 'success');
                    }
                },
                error: function () {
                    $('#qibbs-backpack-loading').hide();
                    self.showToast('同步号码背包失败，请检查网络连接', 'error');
                }
            });
        },

        renderBackpackGrid: function (items, activeCuid) {
            var self = this;
            var $grid = $('#qibbs-backpack-grid');
            $grid.empty();

            items.forEach(function (it) {
                var isDefault = !!it.is_default;
                var isEquipped = (it.cuid === activeCuid);
                var isListed = (it.status === 'c2c_listed' || !!it.is_c2c_listed);
                var gradeClass = isDefault ? 'qibbs-grade-level-default' : ('qibbs-grade-level-' + it.grade);
                var gradeLabel = isDefault ? '系统保底号' : (it.grade_label || '优质靓号');

                // 状态角标
                var statusBadgeHtml = '';
                if (isEquipped) {
                    statusBadgeHtml = '<span class="qibbs-bp-status-badge qibbs-bp-status-equipped"><svg viewBox="0 0 24 24" width="14" height="14" fill="#f59e0b"><path d="M5 16L3 5l5.5 5L12 4l3.5 6L21 5l-2 11H5m14 3c0 .6-.4 1-1 1H6c-.6 0-1-.4-1-1v-1h14v1z"/></svg> 佩戴中</span>';
                } else if (isListed) {
                    statusBadgeHtml = '<span class="qibbs-bp-status-badge qibbs-bp-status-listed">寄售展示中</span>';
                } else {
                    statusBadgeHtml = '<span class="qibbs-bp-status-badge qibbs-bp-status-idle">背包闲置</span>';
                }

                // 操作按钮组
                var actionsHtml = '<div class="qibbs-bp-actions">';
                if (isDefault) {
                    if (!isEquipped) {
                        actionsHtml += '<button type="button" class="qibbs-btn qibbs-btn-primary qibbs-btn-sm qibbs-equip-btn" data-cuid="' + it.cuid + '">恢复佩戴</button>';
                    } else {
                        actionsHtml += '<span class="qibbs-bp-hint-text">永久保底号 · 佩戴中</span>';
                    }
                } else {
                    if (isListed) {
                        actionsHtml += '<button type="button" class="qibbs-btn qibbs-btn-warning qibbs-btn-sm qibbs-delist-c2c-btn" data-cuid="' + it.cuid + '">撤销下架</button>';
                    } else {
                        if (!isEquipped) {
                            actionsHtml += '<button type="button" class="qibbs-btn qibbs-btn-primary qibbs-btn-sm qibbs-equip-btn" data-cuid="' + it.cuid + '">佩戴生效</button>';
                        } else {
                            actionsHtml += '<button type="button" class="qibbs-btn qibbs-btn-default qibbs-btn-sm qibbs-equip-btn" data-cuid="' + (self.getDefaultCuid(items)) + '">卸下佩戴</button>';
                        }
                        actionsHtml += '<button type="button" class="qibbs-btn qibbs-btn-success qibbs-btn-sm qibbs-list-modal-btn" data-cuid="' + it.cuid + '" data-grade-label="' + self.escapeHtml(gradeLabel) + '" data-grade-level="' + it.grade + '">挂牌寄售</button>';
                        actionsHtml += '<button type="button" class="qibbs-btn qibbs-btn-danger qibbs-btn-sm qibbs-recycle-modal-btn" data-cuid="' + it.cuid + '" data-grade-label="' + self.escapeHtml(gradeLabel) + '" data-grade-level="' + it.grade + '">官方回售</button>';
                    }
                }
                actionsHtml += '</div>';

                var cardHtml = [
                    '<div class="qibbs-backpack-card ' + gradeClass + (isEquipped ? ' is-equipped' : '') + '">',
                        '<div class="qibbs-bp-card-top">',
                            '<span class="qibbs-card-grade-badge">' + self.escapeHtml(gradeLabel) + '</span>',
                            statusBadgeHtml,
                        '</div>',
                        '<div class="qibbs-bp-cuid-num">',
                            self.formatCuid(it.cuid),
                        '</div>',
                        (isListed ? '<div class="qibbs-bp-listed-price">寄售价: ' + (it.c2c_price_points ? (it.c2c_price_points + ' ' + self.escapeHtml(pointsName) + ' ') : '') + (it.c2c_price_cash && qibbsData.paymentEnabled ? ((qibbsData.currencySymbol || '¥') + it.c2c_price_cash) : '') + '</div>' : ''),
                        actionsHtml,
                    '</div>'
                ].join('');

                $grid.append(cardHtml);
            });
        },

        getDefaultCuid: function (items) {
            for (var i = 0; i < items.length; i++) {
                if (items[i].is_default) {
                    return items[i].cuid;
                }
            }
            return 0;
        },

        /* ==================== 4. 购买、佩戴与交易执行逻辑 ==================== */
        openBuyModal: function (item) {
            var self = this;
            if (!qibbsData.isLoggedIn) {
                self.showToast('请先登录后再进行购买', 'warning');
                return;
            }

            self.state.activeBuyItem = item;
            var hasShopPoints = !!(qibbsData.hasShopPoints || (qibbsData.vanityMall && qibbsData.vanityMall.hasShopPoints));
            var cashPrice = parseFloat(item.price_cash) || 0;
            var pointsPrice = parseInt(item.price_points || item.price, 10) || 0;
            var symbol = qibbsData.currencySymbol || '¥';
            var pointsName = (qibbsData.vanityMall && qibbsData.vanityMall.pointsName) ? qibbsData.vanityMall.pointsName : '积分';

            // 1. 无启灵商城积分系统模式 -> 纯现金直购，直接拉起现金收银台！
            if (!hasShopPoints) {
                self.openCashCheckout(item);
                return;
            }

            // 2. 有启灵商城积分系统模式 -> 智能支持“商城积分兑换”或“现金直购”
            var gradeLabel = item.grade_label || '靓号';
            var userPoints = parseInt($('#qibbs-hero-user-points span').text().replace(/,/g, ''), 10) || 0;
            var diff = userPoints - pointsPrice;

            $('#qibbs-buy-grade-tag').text(gradeLabel);
            $('#qibbs-buy-cuid-num').text(item.cuid);
            $('#qibbs-buy-price-display').text(pointsPrice.toLocaleString() + ' ' + pointsName);
            $('#qibbs-buy-balance-display').text(userPoints.toLocaleString() + ' ' + pointsName);
            $('#qibbs-buy-points-price-row span:first').text(pointsName + '价格');
            $('#qibbs-buy-user-balance-row span:first').text('您的当前' + pointsName);
            // 默认不勾选立即佩戴，按钮显示“兑换（存入背包）”
            $('#qibbs-buy-auto-equip-toggle').prop('checked', false);
            $('#qibbs-confirm-buy-btn .qibbs-btn-label').text(pointsName + '兑换（存入背包）');

            // 现金直购快捷按钮与价格展示
            if (cashPrice > 0 && qibbsData.paymentEnabled) {
                $('#qibbs-buy-cash-price-row').show();
                $('#qibbs-buy-cash-price-display').text(symbol + cashPrice.toFixed(2));
                $('#qibbs-cash-buy-direct-btn').show().find('.qibbs-btn-label').text('现金支付 ' + symbol + cashPrice.toFixed(2));
            } else {
                $('#qibbs-buy-cash-price-row').hide();
                $('#qibbs-cash-buy-direct-btn').hide();
            }

            if (diff < 0) {
                // 积分不足：显示不足警告，隐藏积分兑换按钮，高亮现金直购按钮
                $('#qibbs-buy-remain-display').text(pointsName + '不足 (差 ' + Math.abs(diff).toLocaleString() + ')').css('color', '#ef4444');
                $('#qibbs-buy-insufficient-alert').show();
                $('#qibbs-confirm-buy-btn').hide();

                if (cashPrice > 0 && qibbsData.paymentEnabled) {
                    $('#qibbs-buy-insufficient-alert').html('您的' + self.escapeHtml(pointsName) + '不足。您可直接使用现金支付 <strong>' + symbol + cashPrice.toFixed(2) + '</strong> 购买此号码！');
                    $('#qibbs-cash-buy-direct-btn').addClass('qibbs-btn-primary').removeClass('qibbs-btn-success');
                } else {
                    $('#qibbs-buy-insufficient-alert').text('您的' + pointsName + '不足，请先充值或通过社区互动获取' + pointsName + '。');
                }
            } else {
                // 积分充足：允许积分兑换，现金作为备选
                $('#qibbs-buy-remain-display').text(diff.toLocaleString() + ' ' + pointsName).css('color', '#10b981');
                $('#qibbs-buy-insufficient-alert').hide();
                $('#qibbs-confirm-buy-btn').show().prop('disabled', false);
                $('#qibbs-cash-buy-direct-btn').removeClass('qibbs-btn-primary').addClass('qibbs-btn-success');
            }

            $('#qibbs-buy-modal').fadeIn(150);
        },

        openCashCheckout: function (item) {
            var self = this;
            if (!qibbsData.isLoggedIn) {
                self.showToast('请先登录后再进行购买', 'warning');
                return;
            }

            var cuid = item.cuid;
            var priceCash = parseFloat(item.price_cash || item.c2c_price_cash) || 0;
            var pricePoints = parseInt(item.price_points || item.price || item.c2c_price_points, 10) || 0;
            var gradeLabel = item.grade_label || '靓号';
            var itemType = (item.status === 'c2c_listed' || item.itemType === 'c2c_vanity') ? 'c2c_vanity' : 'vanity_uid';

            if (window.QibbsPayment && typeof window.QibbsPayment.openCheckout === 'function') {
                window.QibbsPayment.openCheckout({
                    cuid: cuid,
                    priceCash: priceCash,
                    pricePoints: pricePoints,
                    gradeLabel: gradeLabel,
                    itemType: itemType
                });
            } else {
                this.showToast('支付收银台模块未就绪，请刷新重试', 'error');
            }
        },

        executeBuyMallItem: function () {
            var self = this;
            if (!this.state.activeBuyItem) {
                return;
            }

            var cuid = this.state.activeBuyItem.cuid;
            var autoEquip = $('#qibbs-buy-auto-equip-toggle').is(':checked') ? 1 : 0;
            var pointsName = qibbsData.pointsName || '灵力';
            var $btn = $('#qibbs-confirm-buy-btn');

            $btn.prop('disabled', true).find('.qibbs-btn-loading-spinner').show();
            $btn.find('.qibbs-btn-label, .qibbs-btn-text').text('正在兑换中...');

            $.ajax({
                url: qibbsData.restUrl + 'uid/mall/buy',
                type: 'POST',
                data: { cuid: cuid, auto_equip: autoEquip },
                beforeSend: function (xhr) {
                    if (qibbsData.restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                    }
                },
                success: function (res) {
                    $btn.prop('disabled', false).find('.qibbs-btn-loading-spinner').hide();
                    var defaultBtnText = autoEquip ? (pointsName + '兑换并立即佩戴') : (pointsName + '兑换（存入背包）');
                    $btn.find('.qibbs-btn-label, .qibbs-btn-text').text(defaultBtnText);
                    $('#qibbs-buy-modal').fadeOut(150);

                    if (res && res.success) {
                        var msg = (res.data && res.data.message) ? res.data.message : (autoEquip ? ('靓号 ' + cuid + ' 兑换成功，并已成功佩戴！') : ('靓号 ' + cuid + ' 兑换成功，已存入您的背包！可在号码背包随时一键佩戴。'));
                        self.showToast(msg, 'success');
                        if (autoEquip) {
                            self.state.backpack.activeCuid = cuid;
                            $('#qibbs-hero-active-cuid span').text(cuid);
                        }
                        self.loadOfficialMall();
                        self.loadBackpack();
                    } else {
                        self.showToast(res && res.message ? res.message : '购买失败', 'error');
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false).find('.qibbs-btn-loading-spinner').hide();
                    var defaultBtnText = autoEquip ? (pointsName + '兑换并立即佩戴') : (pointsName + '兑换（存入背包）');
                    $btn.find('.qibbs-btn-label, .qibbs-btn-text').text(defaultBtnText);
                    var msg = '兑换失败，请稍后重试';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    self.showToast(msg, 'error');
                }
            });
        },

        executeEquipNumber: function (cuid) {
            var self = this;
            cuid = parseInt(cuid, 10);
            if (!cuid) {
                return;
            }

            $.ajax({
                url: qibbsData.restUrl + 'uid/equip',
                type: 'POST',
                data: { cuid: cuid },
                beforeSend: function (xhr) {
                    if (qibbsData.restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                    }
                },
                success: function (res) {
                    if (res && res.success) {
                        self.showToast('佩戴成功！您的社区主号已切换为 ' + cuid, 'success');
                        self.state.backpack.activeCuid = cuid;
                        $('#qibbs-hero-active-cuid span').text(cuid);
                        self.loadBackpack();
                    } else {
                        self.showToast(res && res.message ? res.message : '佩戴失败', 'error');
                    }
                },
                error: function (xhr) {
                    var msg = '佩戴失败，请刷新后重试';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    self.showToast(msg, 'error');
                }
            });
        },

        openListModal: function (cuid, gradeLabel, gradeLevel) {
            var self = this;
            var hasShopPoints = !!(qibbsData.hasShopPoints || (qibbsData.vanityMall && qibbsData.vanityMall.hasShopPoints));
            var pointsName = (qibbsData.vanityMall && qibbsData.vanityMall.pointsName) ? qibbsData.vanityMall.pointsName : '积分';
            $('#qibbs-list-target-cuid').val(cuid);
            $('#qibbs-list-grade-tag').text(gradeLabel);
            $('#qibbs-list-cuid-num').text(cuid);
            $('#qibbs-list-price-points').val('');
            $('#qibbs-list-price-cash').val('');

            if (!hasShopPoints) {
                $('#qibbs-list-points-group').hide();
            } else {
                $('#qibbs-list-points-group').show();
                $('#qibbs-list-points-group label').text(pointsName + '标价');
                $('#qibbs-list-price-points').attr('placeholder', '输入买家需支付的' + pointsName + '数额，不设留空或为0');
            }

            $('#qibbs-c2c-list-modal').fadeIn(150);
        },

        executeListNumber: function () {
            var self = this;
            var cuid = parseInt($('#qibbs-list-target-cuid').val(), 10);
            var points = parseInt($('#qibbs-list-price-points').val(), 10) || 0;
            var cash = parseFloat($('#qibbs-list-price-cash').val()) || 0;
            var hasShopPoints = !!(qibbsData.hasShopPoints || (qibbsData.vanityMall && qibbsData.vanityMall.hasShopPoints));
            var pointsName = (qibbsData.vanityMall && qibbsData.vanityMall.pointsName) ? qibbsData.vanityMall.pointsName : '积分';

            if (!hasShopPoints) {
                if (cash <= 0) {
                    this.showToast('请输入有效的现金寄售价格（元）', 'warning');
                    return;
                }
            } else {
                if (points <= 0 && cash <= 0) {
                    this.showToast('请至少输入标价（' + pointsName + '或现金）', 'warning');
                    return;
                }
            }

            var $btn = $('#qibbs-confirm-list-btn');
            $btn.prop('disabled', true).find('.qibbs-btn-loading-spinner').show();
            $btn.find('.qibbs-btn-label, .qibbs-btn-text').text('正在上架...');

            $.ajax({
                url: qibbsData.restUrl + 'c2c/list',
                type: 'POST',
                data: {
                    cuid: cuid,
                    price_points: points,
                    price_cash: cash
                },
                beforeSend: function (xhr) {
                    if (qibbsData.restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                    }
                },
                success: function (res) {
                    $btn.prop('disabled', false).find('.qibbs-btn-loading-spinner').hide();
                    $btn.find('.qibbs-btn-label, .qibbs-btn-text').text('确认上架寄售');
                    $('#qibbs-c2c-list-modal').fadeOut(150);

                    if (res && res.success) {
                        self.showToast('挂牌成功！号码 ' + cuid + ' 已进入 C2C 寄售大厅公开展示', 'success');
                        self.loadBackpack();
                        self.loadC2CMarket();
                    } else {
                        self.showToast(res && res.message ? res.message : '上架失败', 'error');
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false).find('.qibbs-btn-loading-spinner').hide();
                    $btn.find('.qibbs-btn-label, .qibbs-btn-text').text('确认上架寄售');
                    var msg = '挂牌寄售失败';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    self.showToast(msg, 'error');
                }
            });
        },

        executeDelistNumber: function (cuid) {
            var self = this;
            self.showConfirm({
                title: '撤回下架',
                message: '确定要撤回下架靓号 ' + cuid + ' 吗？下架后号码将退回您的背包并可立即佩戴。',
                confirmText: '确认撤回',
                cancelText: '取消',
                type: 'warning'
            }, function (confirmed) {
                if (!confirmed) {
                    return;
                }

                $.ajax({
                    url: qibbsData.restUrl + 'c2c/delist',
                    type: 'POST',
                    data: { cuid: cuid },
                    beforeSend: function (xhr) {
                        if (qibbsData.restNonce) {
                            xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                        }
                    },
                    success: function (res) {
                        if (res && res.success) {
                            self.showToast('已成功撤销寄售，号码已恢复至背包', 'success');
                            self.loadBackpack();
                            self.loadC2CMarket();
                        } else {
                            self.showToast(res && res.message ? res.message : '下架失败', 'error');
                        }
                    },
                    error: function (xhr) {
                        var msg = '下架操作失败';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            msg = xhr.responseJSON.message;
                        }
                        self.showToast(msg, 'error');
                    }
                });
            });
        },

        openC2CBuyModal: function (item) {
            var self = this;
            if (!qibbsData.isLoggedIn) {
                this.showToast('请先登录后再进行购买', 'warning');
                return;
            }

            this.state.activeC2CBuyItem = item;
            var hasShopPoints = !!(qibbsData.hasShopPoints || (qibbsData.vanityMall && qibbsData.vanityMall.hasShopPoints));
            var pointsPrice = parseInt(item.c2c_price_points || item.price_points, 10) || 0;
            var cashPrice = parseFloat(item.c2c_price_cash || item.price_cash) || 0;
            var symbol = qibbsData.currencySymbol || '¥';
            var pointsName = (qibbsData.vanityMall && qibbsData.vanityMall.pointsName) ? qibbsData.vanityMall.pointsName : '积分';
            var gradeLabel = item.grade_label || '靓号';

            // 1. 无启灵商城积分且未标积分价，仅标现金价时直接引导唤起原生收银台
            if (!hasShopPoints || (pointsPrice <= 0 && cashPrice > 0)) {
                self.openCashCheckout(item);
                return;
            }

            // 填充卖家信息
            $('#qibbs-c2c-buy-seller-avatar').attr('src', item.seller_avatar || '');
            $('#qibbs-c2c-buy-seller-name').text(item.seller_name || '玩家');
            $('#qibbs-c2c-buy-seller-time').text(item.listed_time_ago ? ('挂牌于 ' + item.listed_time_ago) : '寄售在售');

            // 填充卡片信息
            $('#qibbs-c2c-buy-grade-tag').text(gradeLabel);
            $('#qibbs-c2c-buy-cuid-num').text(item.cuid);

            var userPoints = parseInt($('#qibbs-hero-user-points span').text().replace(/,/g, ''), 10) || 0;
            var diff = userPoints - pointsPrice;

            // 现金直购快捷按钮与价格行
            if (cashPrice > 0 && qibbsData.paymentEnabled) {
                $('#qibbs-c2c-buy-cash-price-row').show();
                $('#qibbs-c2c-buy-cash-price-display').text(symbol + cashPrice.toFixed(2));
                $('#qibbs-c2c-cash-buy-direct-btn').show().find('.qibbs-btn-label').text('现金支付 ' + symbol + cashPrice.toFixed(2));
            } else {
                $('#qibbs-c2c-buy-cash-price-row').hide();
                $('#qibbs-c2c-cash-buy-direct-btn').hide();
            }

            // 积分购买行与余额核算
            if (hasShopPoints && pointsPrice > 0) {
                $('#qibbs-c2c-buy-points-price-row').show();
                $('#qibbs-c2c-buy-price-display').text(pointsPrice.toLocaleString() + ' ' + pointsName);
                $('#qibbs-c2c-buy-user-balance-row').show().find('span:first').text('您的当前' + pointsName);
                $('#qibbs-c2c-buy-balance-display').text(userPoints.toLocaleString() + ' ' + pointsName);
                $('#qibbs-c2c-buy-remain-row').show().find('span:first').text(pointsName + '支付后剩余');

                if (diff < 0) {
                    // 积分不足：友好的系统 UI 警示框引导现金支付或充值
                    $('#qibbs-c2c-buy-remain-display').text(pointsName + '不足 (差 ' + Math.abs(diff).toLocaleString() + ')').css('color', '#ef4444');
                    $('#qibbs-c2c-buy-insufficient-alert').show();
                    $('#qibbs-c2c-confirm-buy-btn').hide();

                    if (cashPrice > 0 && qibbsData.paymentEnabled) {
                        $('#qibbs-c2c-buy-insufficient-alert').html('您的' + self.escapeHtml(pointsName) + '不足。您可直接使用现金支付 <strong>' + symbol + cashPrice.toFixed(2) + '</strong> 购买此号码！');
                        $('#qibbs-c2c-cash-buy-direct-btn').addClass('qibbs-btn-primary').removeClass('qibbs-btn-success');
                    } else {
                        $('#qibbs-c2c-buy-insufficient-alert').text('您的' + pointsName + '不足，请充值后重试。');
                    }
                } else {
                    // 积分充足
                    $('#qibbs-c2c-buy-remain-display').text(diff.toLocaleString() + ' ' + pointsName).css('color', '#10b981');
                    $('#qibbs-c2c-buy-insufficient-alert').hide();
                    $('#qibbs-c2c-confirm-buy-btn').show().prop('disabled', false).find('.qibbs-btn-label').text(pointsName + '购买（存入背包）');
                    $('#qibbs-c2c-cash-buy-direct-btn').removeClass('qibbs-btn-primary').addClass('qibbs-btn-success');
                }
            } else {
                $('#qibbs-c2c-buy-points-price-row').hide();
                $('#qibbs-c2c-buy-user-balance-row').hide();
                $('#qibbs-c2c-buy-remain-row').hide();
                $('#qibbs-c2c-buy-insufficient-alert').hide();
                $('#qibbs-c2c-confirm-buy-btn').hide();
                if (cashPrice > 0 && qibbsData.paymentEnabled) {
                    $('#qibbs-c2c-cash-buy-direct-btn').addClass('qibbs-btn-primary').removeClass('qibbs-btn-success');
                }
            }

            $('#qibbs-c2c-buy-modal').fadeIn(150);
        },

        executeBuyC2CItemWithPoints: function () {
            var self = this;
            if (!this.state.activeC2CBuyItem) {
                return;
            }

            var item = this.state.activeC2CBuyItem;
            var $btn = $('#qibbs-c2c-confirm-buy-btn');
            var pointsName = (qibbsData.vanityMall && qibbsData.vanityMall.pointsName) ? qibbsData.vanityMall.pointsName : '积分';

            $btn.prop('disabled', true).find('.qibbs-btn-loading-spinner').show();
            $btn.find('.qibbs-btn-label').text('正在购买中...');

            $.ajax({
                url: qibbsData.restUrl + 'c2c/buy-points',
                type: 'POST',
                data: { cuid: item.cuid },
                beforeSend: function (xhr) {
                    if (qibbsData.restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                    }
                },
                success: function (res) {
                    $btn.prop('disabled', false).find('.qibbs-btn-loading-spinner').hide();
                    $btn.find('.qibbs-btn-label').text(pointsName + '购买（存入背包）');
                    $('#qibbs-c2c-buy-modal').fadeOut(150);

                    if (res && res.success) {
                        self.showToast('交易成功！靓号 ' + item.cuid + ' 已过户至您的背包！可在号码背包随时一键佩戴。', 'success');
                        self.loadC2CMarket();
                        self.loadBackpack();
                    } else {
                        self.showToast(res && res.message ? res.message : '购买失败', 'error');
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false).find('.qibbs-btn-loading-spinner').hide();
                    $btn.find('.qibbs-btn-label').text(pointsName + '购买（存入背包）');
                    var msg = '购买失败，请检查' + pointsName + '余额';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    self.showToast(msg, 'error');
                }
            });
        },

        executeBuyC2CItem: function (item) {
            this.openC2CBuyModal(item);
        },

        openRecycleModal: function (cuid, gradeLabel, gradeLevel) {
            var self = this;
            $('#qibbs-recycle-target-cuid').val(cuid);
            $('#qibbs-recycle-grade-tag').text(gradeLabel);
            $('#qibbs-recycle-cuid-num').text(cuid);

            $('#qibbs-recycle-amount-display').text('正在查询报价...');
            $('#qibbs-confirm-recycle-btn').prop('disabled', true);
            $('#qibbs-recycle-modal').fadeIn(150);

            // 预查回购估值报价
            $.ajax({
                url: qibbsData.restUrl + 'c2c/recycle-quote',
                type: 'GET',
                data: { cuid: cuid },
                beforeSend: function (xhr) {
                    if (qibbsData.restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                    }
                },
                success: function (res) {
                    if (res && res.success) {
                        var quote = res;
                        var pointsName = (qibbsData.vanityMall && qibbsData.vanityMall.pointsName) ? qibbsData.vanityMall.pointsName : '积分';
                        $('#qibbs-recycle-rate-display').text(quote.rebate_rate + '%');
                        var payoutLabel = quote.payout_label ? quote.payout_label : (quote.payout_type === 'points' ? (pointsName + '（商城积分）') : '商城【可提现余额】');
                        $('#qibbs-recycle-type-display').text(payoutLabel);
                        var refundAmount = (quote.payout_type === 'points')
                            ? (quote.refund_points + ' ' + pointsName)
                            : ('¥ ' + quote.refund_cash.toFixed(2));
                        $('#qibbs-recycle-amount-display').text(refundAmount);
                        $('#qibbs-confirm-recycle-btn').prop('disabled', false);
                    } else {
                        $('#qibbs-recycle-amount-display').text('暂不可回收');
                        self.showToast(res && res.message ? res.message : '暂无法获取回售报价', 'warning');
                    }
                },
                error: function () {
                    $('#qibbs-recycle-amount-display').text('获取报价失败');
                }
            });
        },

        executeRecycleNumber: function () {
            var self = this;
            var cuid = parseInt($('#qibbs-recycle-target-cuid').val(), 10);
            if (!cuid) {
                return;
            }

            var $btn = $('#qibbs-confirm-recycle-btn');
            $btn.prop('disabled', true).find('.qibbs-btn-loading-spinner').show();
            $btn.find('.qibbs-btn-label, .qibbs-btn-text').text('正在回售...');

            $.ajax({
                url: qibbsData.restUrl + 'c2c/recycle',
                type: 'POST',
                data: { cuid: cuid },
                beforeSend: function (xhr) {
                    if (qibbsData.restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                    }
                },
                success: function (res) {
                    $btn.prop('disabled', false).find('.qibbs-btn-loading-spinner').hide();
                    $btn.find('.qibbs-btn-label, .qibbs-btn-text').text('确认回售变现');
                    $('#qibbs-recycle-modal').fadeOut(150);

                    if (res && res.success) {
                        var pointsName = (qibbsData.vanityMall && qibbsData.vanityMall.pointsName) ? qibbsData.vanityMall.pointsName : '积分';
                        var amountStr = res.refund_type === 'points' ? (res.refund_amount + ' ' + pointsName) : ('¥' + res.refund_amount);
                        self.showToast('回售变现成功！已向您的启灵商城发放 ' + amountStr, 'success');
                        self.loadBackpack();
                        self.loadOfficialMall();
                    } else {
                        self.showToast(res && res.message ? res.message : '回售操作失败', 'error');
                    }
                },
                error: function (xhr) {
                    $btn.prop('disabled', false).find('.qibbs-btn-loading-spinner').hide();
                    $btn.find('.qibbs-btn-label, .qibbs-btn-text').text('确认回售变现');
                    var msg = '回售失败';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    self.showToast(msg, 'error');
                }
            });
        },

        /* ==================== 4. 成交动态与记录加载与渲染 ==================== */
        loadTradeRecords: function () {
            var self = this;
            var params = {
                tab: this.state.records.tab,
                trade_type: this.state.records.tradeType,
                page: this.state.records.page,
                per_page: this.state.records.perPage
            };

            $('#qibbs-records-list').empty();
            $('#qibbs-records-empty').hide();
            $('#qibbs-records-pagination').hide();
            $('#qibbs-records-loading').show();

            $.ajax({
                url: qibbsData.restUrl + 'uid/trade-records',
                type: 'GET',
                data: params,
                beforeSend: function (xhr) {
                    if (qibbsData.restNonce) {
                        xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                    }
                },
                success: function (res) {
                    $('#qibbs-records-loading').hide();
                    self.state.records.loaded = true;

                    if (!res || !res.success || !res.data) {
                        $('#qibbs-records-empty').show();
                        return;
                    }

                    var data = res.data;
                    var items = data.items || [];
                    self.state.records.total = data.total || 0;
                    self.state.records.totalPages = data.total_pages || 1;

                    // 刷新 Tab 数量角标
                    if (data.total > 0) {
                        $('#qibbs-records-tab-count').text(data.total).show();
                    } else {
                        $('#qibbs-records-tab-count').text(0).hide();
                    }

                    if (items.length === 0) {
                        $('#qibbs-records-empty').show();
                        return;
                    }

                    self.renderTradeRecords(items, data.points_name || '积分');

                    if (self.state.records.totalPages > 1) {
                        $('#qibbs-records-page-info').text(self.state.records.page + ' / ' + self.state.records.totalPages);
                        $('#qibbs-records-prev-btn').prop('disabled', self.state.records.page <= 1);
                        $('#qibbs-records-next-btn').prop('disabled', self.state.records.page >= self.state.records.totalPages);
                        $('#qibbs-records-pagination').show();
                    }
                },
                error: function (xhr) {
                    $('#qibbs-records-loading').hide();
                    $('#qibbs-records-empty').show();
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : '获取成交记录失败';
                    self.showToast(msg, 'error');
                }
            });
        },

        renderTradeRecords: function (items, pointsName) {
            var self = this;
            var $list = $('#qibbs-records-list');
            $list.empty();
            var symbol = qibbsData.currencySymbol || '¥';

            items.forEach(function (it) {
                var typeClass = 'type-' + it.trade_type;
                var gradeClass = 'qibbs-grade-level-' + it.grade;

                var priceHtml = '';
                if (it.is_cash) {
                    priceHtml = '<div class="qibbs-record-price is-cash">' +
                        '<span class="qibbs-record-price-sym">' + self.escapeHtml(symbol) + '</span>' +
                        '<strong class="qibbs-record-price-val">' + self.escapeHtml(it.amount_cash) + '</strong>' +
                        '</div>';
                } else {
                    priceHtml = '<div class="qibbs-record-price is-points">' +
                        '<strong class="qibbs-record-price-val">' + self.escapeHtml(it.amount_points) + '</strong> ' +
                        '<span class="qibbs-record-price-unit">' + self.escapeHtml(pointsName) + '</span>' +
                        '</div>';
                }

                var sellerAvatar = it.seller.avatar
                    ? '<img src="' + self.escapeHtml(it.seller.avatar) + '" alt="' + self.escapeHtml(it.seller.name) + '" class="qibbs-record-avatar">'
                    : '<span class="qibbs-record-avatar-placeholder">' + (it.seller.name ? it.seller.name.substring(0, 1) : '商') + '</span>';

                var buyerAvatar = it.buyer.avatar
                    ? '<img src="' + self.escapeHtml(it.buyer.avatar) + '" alt="' + self.escapeHtml(it.buyer.name) + '" class="qibbs-record-avatar">'
                    : '<span class="qibbs-record-avatar-placeholder">' + (it.buyer.name ? it.buyer.name.substring(0, 1) : '客') + '</span>';

                var sellerCuidBadge = (it.seller.cuid && it.seller.cuid > 0)
                    ? '<span class="qibbs-record-user-cuid">' + self.escapeHtml(it.seller.cuid) + '</span>'
                    : '';

                var buyerCuidBadge = (it.buyer.cuid && it.buyer.cuid > 0)
                    ? '<span class="qibbs-record-user-cuid">' + self.escapeHtml(it.buyer.cuid) + '</span>'
                    : '';

                var html = '<div class="qibbs-record-card ' + typeClass + '">' +
                    '<div class="qibbs-record-top">' +
                        '<div class="qibbs-record-cuid-wrap">' +
                            '<span class="qibbs-grade-tag ' + gradeClass + '">' + self.escapeHtml(it.grade_label) + '</span>' +
                            '<span class="qibbs-record-cuid-number">' + self.escapeHtml(it.cuid) + '</span>' +
                        '</div>' +
                        '<div class="qibbs-record-meta">' +
                            '<span class="qibbs-record-badge ' + typeClass + '">' + self.escapeHtml(it.trade_type_label) + '</span>' +
                            '<span class="qibbs-record-time"' + ((it.created_at_formatted || it.created_at) ? ' title="' + self.escapeHtml(it.created_at_formatted || it.created_at) + '"' : '') + '>' + self.escapeHtml(it.time_ago || it.created_at) + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="qibbs-record-parties">' +
                        '<div class="qibbs-record-party seller" title="出让方">' +
                            sellerAvatar +
                            '<div class="qibbs-record-party-info">' +
                                '<span class="qibbs-party-role">出让方</span>' +
                                '<span class="qibbs-party-name">' + self.escapeHtml(it.seller.name) + '</span>' +
                                sellerCuidBadge +
                            '</div>' +
                        '</div>' +
                        '<div class="qibbs-record-arrow">' +
                            '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">' +
                                '<path d="M5 13h11.86l-5.43 5.43 1.42 1.42L21.14 12l-8.29-8.29-1.42 1.42 5.43 5.43H5v2.44z"/>' +
                            '</svg>' +
                        '</div>' +
                        '<div class="qibbs-record-party buyer" title="受让方">' +
                            buyerAvatar +
                            '<div class="qibbs-record-party-info">' +
                                '<span class="qibbs-party-role">受让方</span>' +
                                '<span class="qibbs-party-name">' + self.escapeHtml(it.buyer.name) + '</span>' +
                                buyerCuidBadge +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="qibbs-record-bottom">' +
                        '<div class="qibbs-record-sn">流水: ' + self.escapeHtml(it.trade_sn) + '</div>' +
                        priceHtml +
                    '</div>' +
                '</div>';

                $list.append(html);
            });
        },

        /* ==================== 5. 工具方法与全局 Toast 提示 ==================== */
        formatCuid: function (cuid) {
            return String(cuid);
        },

        escapeHtml: function (str) {
            if (!str) return '';
            return $('<div>').text(str).html();
        },

        showToast: function (msg, type) {
            type = type || 'info';
            var $toast = $('#qibbs-vanity-toast');
            if (!$toast.length) {
                $toast = $('<div id="qibbs-vanity-toast" class="qibbs-vanity-toast"></div>').appendTo('body');
            }

            $toast.removeClass('is-success is-error is-warning is-info')
                .addClass('is-' + type)
                .html('<span>' + this.escapeHtml(msg) + '</span>')
                .stop(true, true)
                .fadeIn(200);

            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(function () {
                $toast.fadeOut(300);
            }, 3200);
        },

        showConfirm: function (options, callback) {
            var defaults = {
                title: '确认操作',
                message: '确定要执行此操作吗？',
                confirmText: '确定',
                cancelText: '取消',
                type: 'warning'
            };
            var opts = $.extend({}, defaults, options);
            var safeTitle = this.escapeHtml(opts.title);
            var safeMessage = this.escapeHtml(opts.message);
            var safeConfirmText = this.escapeHtml(opts.confirmText);
            var safeCancelText = this.escapeHtml(opts.cancelText);
            var confirmBtnClass = opts.type === 'danger' ? 'qibbs-btn-danger' : (opts.type === 'primary' ? 'qibbs-btn-primary' : (opts.type === 'success' ? 'qibbs-btn-success' : 'qibbs-btn-warning'));

            var iconSvg = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';

            var modalId = 'qibbs-confirm-modal-' + Math.floor(Math.random() * 100000);
            var html = [
                '<div class="qibbs-vanity-modal-backdrop qibbs-dynamic-confirm-modal" id="' + modalId + '">',
                    '<div class="qibbs-vanity-modal qibbs-confirm-dialog-box">',
                        '<div class="qibbs-vanity-modal-header">',
                            '<div style="display:flex;align-items:center;gap:8px;">',
                                '<span class="qibbs-confirm-icon qibbs-confirm-' + opts.type + '">' + iconSvg + '</span>',
                                '<h3 style="margin:0;font-size:16px;">' + safeTitle + '</h3>',
                            '</div>',
                            '<button type="button" class="qibbs-modal-close" data-action="cancel">&times;</button>',
                        '</div>',
                        '<div class="qibbs-vanity-modal-body" style="padding:20px 24px;">',
                            '<p style="margin:0;font-size:14px;line-height:1.6;color:var(--qibbs-text,#334155);">' + safeMessage.replace(/\n/g, '<br>') + '</p>',
                        '</div>',
                        '<div class="qibbs-vanity-modal-footer">',
                            '<button type="button" class="qibbs-btn qibbs-btn-default" data-action="cancel">' + safeCancelText + '</button>',
                            '<button type="button" class="qibbs-btn ' + confirmBtnClass + '" data-action="confirm">' + safeConfirmText + '</button>',
                        '</div>',
                    '</div>',
                '</div>'
            ].join('');

            var $modal = $(html).appendTo('body');
            $modal.fadeIn(150);

            function close(confirmed) {
                $modal.fadeOut(150, function () {
                    $modal.remove();
                });
                if (typeof callback === 'function') {
                    callback(confirmed);
                }
            }

            $modal.find('[data-action="cancel"]').on('click', function () { close(false); });
            $modal.find('[data-action="confirm"]').on('click', function () { close(true); });
            $modal.on('click', function (e) {
                if ($(e.target).hasClass('qibbs-vanity-modal-backdrop')) {
                    close(false);
                }
            });
        }
    };

    $(document).ready(function () {
        QibbsVanityMall.init();
    });

    window.QibbsVanityMall = QibbsVanityMall;

})(jQuery);
