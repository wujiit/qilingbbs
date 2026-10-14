/**
 * 启灵微社区 - 原生支付收银台与号码背包客户端交互
 *
 * @package Qibbs_Community
 * @subpackage Assets/JS
 */

(function($) {
    'use strict';

    window.QibbsPayment = {
        activePollTimer: null,
        currentOrderSn: null,

        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            const self = this;

            // 点击“购买靓号”触发原生收银台 (支持官方自营与玩家C2C寄售)
            $(document).on('click', '.qibbs-buy-vanity-btn', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const cuid = $btn.data('cuid');
                const priceCash = parseFloat($btn.data('price-cash') || 0);
                const pricePoints = parseInt($btn.data('price-points') || 0, 10);
                const gradeLabel = $btn.data('grade-label') || '靓号';
                const itemType = $btn.data('item-type') || 'vanity_uid';

                self.openCheckout({
                    cuid: cuid,
                    priceCash: priceCash,
                    pricePoints: pricePoints,
                    gradeLabel: gradeLabel,
                    itemType: itemType
                });
            });

            // 交易大厅 Tab 选项卡切换
            $(document).on('click', '.qibbs-mall-tab-btn[data-target]', function(e) {
                e.preventDefault();
                const target = $(this).data('target');
                const $wrap = $(this).closest('.qibbs-vanity-mall-wrap');
                $wrap.find('.qibbs-mall-tab-btn').removeClass('is-active');
                $(this).addClass('is-active');
                $wrap.find('.qibbs-mall-tab-pane').removeClass('is-active');
                $wrap.find(target).addClass('is-active');
            });

            // 点击用户自身 UID 徽章触发“我的号码背包”
            $(document).on('click', '.qibbs-open-backpack-btn, .qibbs-my-cuid-trigger', function(e) {
                e.preventDefault();
                self.openBackpack();
            });

            // 关闭弹窗
            $(document).on('click', '.qibbs-pay-modal-close, .qibbs-pay-modal-backdrop', function() {
                self.closeModal();
            });

            // 阻止弹窗内冒泡
            $(document).on('click', '.qibbs-pay-modal-dialog', function(e) {
                e.stopPropagation();
            });
        },

        // 打开原生支付收银台
        openCheckout: function(item) {
            const self = this;
            if (!qibbsData.isLoggedIn) {
                if (typeof qibbsCommunity !== 'undefined' && qibbsCommunity.showLoginModal) {
                    qibbsCommunity.showLoginModal();
                } else {
                    alert(qibbsData.strings.loginRequired || '请先登录');
                }
                return;
            }

            self.closeModal();
            const symbol = qibbsData.currencySymbol || '¥';

            const isC2C = (item.itemType === 'c2c_vanity');
            const modalTitle = isC2C ? '选购玩家寄售靓号' : '选购社区靓号';

            // 构建收银台骨架
            const modalHtml = `
                <div id="qibbs-checkout-modal" class="qibbs-pay-modal-wrap">
                    <div class="qibbs-pay-modal-backdrop"></div>
                    <div class="qibbs-pay-modal-dialog">
                        <div class="qibbs-pay-modal-header">
                            <h3 class="qibbs-pay-modal-title">${modalTitle}</h3>
                            <button type="button" class="qibbs-pay-modal-close">&times;</button>
                        </div>
                        <div class="qibbs-pay-modal-body">
                            <div class="qibbs-checkout-item-preview">
                                <div class="qibbs-checkout-item-cuid">UID: <strong>${item.cuid}</strong> <span class="qibbs-uid-tag qibbs-uid-vanity">${item.gradeLabel}</span></div>
                                <div class="qibbs-checkout-item-price">
                                    <span class="qibbs-cash-price-val">${symbol}${item.priceCash.toFixed(2)}</span>
                                    ${item.pricePoints > 0 ? `<span class="qibbs-points-price-hint">或 ${item.pricePoints} 积分</span>` : ''}
                                </div>
                            </div>

                            <div class="qibbs-checkout-gateway-section">
                                <label class="qibbs-checkout-section-label">选择支付方式：</label>
                                <div id="qibbs-checkout-gateways" class="qibbs-gateways-container">
                                    <div class="qibbs-pay-loading-hint">正在加载可用支付通道...</div>
                                </div>
                            </div>

                            <div id="qibbs-checkout-action-wrap" class="qibbs-checkout-action-wrap" style="display:none;">
                                <button type="button" id="qibbs-submit-pay-btn" class="qibbs-btn qibbs-btn-primary qibbs-btn-block">立即支付 ${symbol}${item.priceCash.toFixed(2)}</button>
                            </div>

                            <div id="qibbs-qr-pay-wrap" class="qibbs-qr-pay-wrap" style="display:none;">
                                <div class="qibbs-qr-title">请打开手机 App 扫码支付</div>
                                <div id="qibbs-qr-canvas-holder" class="qibbs-qr-canvas-holder"></div>
                                <div class="qibbs-qr-status-hint"><span class="qibbs-spinner-dot"></span> 等待扫码完成，请勿关闭窗口...</div>
                            </div>

                            <div id="qibbs-pay-success-wrap" class="qibbs-pay-success-wrap" style="display:none;">
                                <div class="qibbs-success-icon"><svg viewBox="0 0 24 24" width="34" height="34" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg></div>
                                <h4>支付成功！靓号已存入背包</h4>
                                <p>您已拥有靓号 <strong>${item.cuid}</strong>，是否立即设为对外展示主号？</p>
                                <div class="qibbs-success-actions">
                                    <button type="button" id="qibbs-equip-now-btn" class="qibbs-btn qibbs-btn-success" data-cuid="${item.cuid}">立即佩戴</button>
                                    <button type="button" class="qibbs-btn qibbs-btn-default qibbs-pay-modal-close">稍后佩戴</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            $('body').append(modalHtml);

            // 获取可用网关
            $.ajax({
                url: qibbsData.restUrl + 'payment/gateways',
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                },
                success: function(res) {
                    const $container = $('#qibbs-checkout-gateways');
                    $container.empty();

                    let gateways = (res && res.gateways) ? res.gateways : [];

                    // 若无现金网关但有积分且支持积分购买
                    if (gateways.length === 0 && item.pricePoints > 0) {
                        gateways.push({
                            id: 'points',
                            name: '商城积分兑换',
                            icon: 'dashicons-star-filled'
                        });
                    }

                    if (gateways.length === 0) {
                        $container.html('<div style="color:#d63638;padding:12px;">暂无可用支付通道，请联系管理员配置</div>');
                        return;
                    }

                    gateways.forEach(function(gw, index) {
                        const checked = index === 0 ? 'checked' : '';
                        const activeClass = index === 0 ? 'is-selected' : '';
                        const iconHtml = gw.icon_url
                            ? `<img src="${gw.icon_url}" class="qibbs-gateway-icon" alt="${gw.name}">`
                            : `<span class="dashicons ${gw.icon || 'dashicons-cart'}"></span>`;
                        const gwHtml = `
                            <label class="qibbs-gateway-option ${activeClass}" data-gateway="${gw.id}">
                                <input type="radio" name="qibbs_pay_gateway" value="${gw.id}" ${checked}>
                                ${iconHtml}
                                <span class="qibbs-gateway-name">${gw.name}</span>
                            </label>
                        `;
                        $container.append(gwHtml);
                    });

                    $('#qibbs-checkout-action-wrap').show();
                },
                error: function() {
                    $('#qibbs-checkout-gateways').html('<div style="color:#d63638;padding:12px;">加载支付网关失败</div>');
                }
            });

            // 切换网关高亮
            $(document).on('change', 'input[name="qibbs_pay_gateway"]', function() {
                $('.qibbs-gateway-option').removeClass('is-selected');
                $(this).closest('.qibbs-gateway-option').addClass('is-selected');
            });

            // 提交支付按钮点击
            $('#qibbs-submit-pay-btn').off('click').on('click', function() {
                const selectedGw = $('input[name="qibbs_pay_gateway"]:checked').val();
                if (!selectedGw) {
                    alert('请选择支付方式');
                    return;
                }

                const $btn = $(this);
                $btn.prop('disabled', true).text('正在调起安全支付...');

                // 若为积分直接兑换
                if (selectedGw === 'points') {
                    const buyUrl = isC2C ? (qibbsData.restUrl + 'c2c/buy-points') : (qibbsData.restUrl + 'uid/mall/buy');
                    $.ajax({
                        url: buyUrl,
                        method: 'POST',
                        data: { cuid: item.cuid },
                        beforeSend: function(xhr) {
                            xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                        },
                        success: function(resp) {
                            self.showPaySuccess(item.cuid);
                        },
                        error: function(xhr) {
                            $btn.prop('disabled', false).text('立即支付');
                            const msg = xhr.responseJSON ? xhr.responseJSON.message : '兑换失败，请检查积分余额';
                            alert(msg);
                        }
                    });
                    return;
                }

                // 现金支付通道
                $.ajax({
                    url: qibbsData.restUrl + 'payment/checkout',
                    method: 'POST',
                    data: {
                        cuid: item.cuid,
                        gateway: selectedGw,
                        return_url: window.location.href,
                        item_type: item.itemType || 'vanity_uid'
                    },
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                    },
                    success: function(res) {
                        if (!res || !res.success) {
                            $btn.prop('disabled', false).text('立即支付');
                            alert(res.message || '创建订单失败');
                            return;
                        }

                        self.currentOrderSn = res.order_sn;

                        // 二维码扫码支付场景 (当面付或微信 Native)
                        if (res.type === 'qrcode' && (res.qr_code || res.pay_url)) {
                            const qrData = res.qr_code || res.pay_url;
                            $('#qibbs-checkout-action-wrap, .qibbs-checkout-gateway-section').hide();
                            $('#qibbs-qr-pay-wrap').show();

                            const $holder = $('#qibbs-qr-canvas-holder').empty();
                            if ($.fn.qrcode) {
                                $holder.qrcode({
                                    render: 'canvas',
                                    width: 180,
                                    height: 180,
                                    text: qrData
                                });
                            } else {
                                const qrImgUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(qrData);
                                $holder.html(`<img src="${qrImgUrl}" alt="Scan QR Code" style="width:180px;height:180px;display:block;margin:0 auto;" />`);
                            }

                            // 启动状态轮询
                            self.startPollingOrderStatus(res.order_sn, item.cuid);
                        } else if (res.type === 'redirect' && res.pay_url) {
                            // 跳转支付场景 (易支付、支付宝 WAP/Page)
                            window.open(res.pay_url, '_blank');
                            $('#qibbs-checkout-action-wrap, .qibbs-checkout-gateway-section').hide();
                            $('#qibbs-qr-pay-wrap').show().html(`
                                <div class="qibbs-qr-title">已为您在新窗口打开支付页面</div>
                                <p style="color:#646970;margin:15px 0;">请在新窗口中完成付款。付款成功后系统将自动为您发放靓号。</p>
                                <div class="qibbs-qr-status-hint"><span class="qibbs-spinner-dot"></span> 检测支付结果中...</div>
                            `);
                            self.startPollingOrderStatus(res.order_sn, item.cuid);
                        }
                    },
                    error: function(xhr) {
                        $btn.prop('disabled', false).text('立即支付');
                        const msg = xhr.responseJSON ? xhr.responseJSON.message : '创建订单失败，请稍后重试';
                        alert(msg);
                    }
                });
            });

            // 立即佩戴按钮处理
            $(document).on('click', '#qibbs-equip-now-btn', function() {
                const cuid = $(this).data('cuid');
                self.equipCuid(cuid);
            });
        },

        // 轮询订单实时状态
        startPollingOrderStatus: function(orderSn, cuid) {
            const self = this;
            if (self.activePollTimer) {
                clearInterval(self.activePollTimer);
            }

            self.activePollTimer = setInterval(function() {
                $.ajax({
                    url: qibbsData.restUrl + 'payment/status/' + orderSn,
                    method: 'GET',
                    success: function(resp) {
                        if (resp && resp.is_paid) {
                            clearInterval(self.activePollTimer);
                            self.activePollTimer = null;
                            self.showPaySuccess(cuid);
                        }
                    }
                });
            }, 2500);
        },

        // 展示支付成功界面
        showPaySuccess: function(cuid) {
            $('#qibbs-checkout-action-wrap, .qibbs-checkout-gateway-section, #qibbs-qr-pay-wrap').hide();
            $('#qibbs-pay-success-wrap').show();
        },

        // 切换佩戴 CUID
        equipCuid: function(cuid) {
            const self = this;
            $.ajax({
                url: qibbsData.restUrl + 'uid/equip',
                method: 'POST',
                data: { cuid: cuid },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                },
                success: function(res) {
                    alert('佩戴成功！当前社区对外展示主号已更新为 UID: ' + cuid);
                    self.closeModal();
                    // 局部动态刷新页面上的个人 UID 标识
                    $('.qibbs-current-cuid-val').text(cuid);
                },
                error: function(xhr) {
                    const msg = xhr.responseJSON ? xhr.responseJSON.message : '佩戴失败';
                    alert(msg);
                }
            });
        },

        // 打开用户号码背包
        openBackpack: function() {
            const self = this;
            if (!qibbsData.isLoggedIn) {
                return;
            }

            self.closeModal();

            const modalHtml = `
                <div id="qibbs-backpack-modal" class="qibbs-pay-modal-wrap">
                    <div class="qibbs-pay-modal-backdrop"></div>
                    <div class="qibbs-pay-modal-dialog" style="max-width:540px;">
                        <div class="qibbs-pay-modal-header">
                            <h3 class="qibbs-pay-modal-title">我的号码背包</h3>
                            <button type="button" class="qibbs-pay-modal-close">&times;</button>
                        </div>
                        <div class="qibbs-pay-modal-body">
                            <div id="qibbs-backpack-content" class="qibbs-backpack-list">
                                <div class="qibbs-pay-loading-hint">正在盘点您的号码资产...</div>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            $('body').append(modalHtml);

            $.ajax({
                url: qibbsData.restUrl + 'uid/backpack',
                method: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                },
                success: function(res) {
                    const $content = $('#qibbs-backpack-content').empty();
                    if (!res || !res.items || res.items.length === 0) {
                        $content.html('<p style="text-align:center;color:#646970;padding:20px;">暂无号码资产记录</p>');
                        return;
                    }

                    res.items.forEach(function(item) {
                        const isEquipped = item.is_active;
                        const isListed = item.is_c2c_listed;
                        const badgeClass = item.grade === 4 ? 'qibbs-uid-supreme' : (item.grade === 3 ? 'qibbs-uid-legend' : (item.grade === 2 ? 'qibbs-uid-gold' : 'qibbs-uid-vanity'));

                        let actionsHtml = '';
                        if (isListed) {
                            actionsHtml = `<button type="button" class="qibbs-btn qibbs-btn-sm qibbs-btn-warning qibbs-bp-delist-btn" data-cuid="${item.cuid}">下架撤单</button>`;
                        } else {
                            if (!isEquipped) {
                                actionsHtml += `<button type="button" class="qibbs-btn qibbs-btn-sm qibbs-btn-primary qibbs-bp-equip-btn" data-cuid="${item.cuid}">佩戴</button>`;
                            }
                            if (item.can_sell) {
                                actionsHtml += `<button type="button" class="qibbs-btn qibbs-btn-sm qibbs-btn-success qibbs-bp-list-btn" data-cuid="${item.cuid}">上架寄售</button>`;
                                actionsHtml += `<button type="button" class="qibbs-btn qibbs-btn-sm qibbs-btn-default qibbs-bp-recycle-btn" data-cuid="${item.cuid}">官方回收</button>`;
                            }
                        }

                        let statusBadge = '';
                        if (isEquipped) {
                            statusBadge = '<span class="qibbs-bp-status-badge">正在佩戴</span>';
                        } else if (isListed) {
                            const priceStr = item.c2c_price_cash > 0 ? ('¥' + item.c2c_price_cash.toFixed(2)) : (item.c2c_price_points + '积分');
                            statusBadge = `<span class="qibbs-bp-status-badge qibbs-bp-status-listed">寄售中 (${priceStr})</span>`;
                        }

                        const itemHtml = `
                            <div class="qibbs-backpack-item ${isEquipped ? 'is-active-equipped' : ''} ${isListed ? 'is-c2c-listed' : ''}">
                                <div class="qibbs-bp-left">
                                    <div class="qibbs-bp-cuid">UID: <strong>${item.cuid}</strong> <span class="qibbs-uid-tag ${item.is_default ? 'qibbs-uid-default' : badgeClass}">${item.grade_label}</span></div>
                                    ${statusBadge}
                                </div>
                                <div class="qibbs-bp-actions">
                                    ${actionsHtml}
                                </div>
                            </div>
                        `;
                        $content.append(itemHtml);
                    });

                    // 绑定背包内佩戴操作
                    $('.qibbs-bp-equip-btn').on('click', function() {
                        const cuid = $(this).data('cuid');
                        self.equipCuid(cuid);
                    });

                    // 绑定挂牌寄售操作
                    $('.qibbs-bp-list-btn').on('click', function() {
                        const cuid = $(this).data('cuid');
                        const priceCashStr = prompt('【挂牌寄售】请输入靓号 ' + cuid + ' 的现金售价 (人民币/元，0为不设现金价):', '10.00');
                        if (priceCashStr === null) return;
                        const pricePointsStr = prompt('【挂牌寄售】请输入靓号 ' + cuid + ' 的积分售价 (0为不设积分价):', '0');
                        if (pricePointsStr === null) return;

                        const priceCash = parseFloat(priceCashStr) || 0;
                        const pricePoints = parseInt(pricePointsStr, 10) || 0;

                        if (priceCash <= 0 && pricePoints <= 0) {
                            alert('现金售价或积分售价必须至少设置一项！');
                            return;
                        }

                        $.ajax({
                            url: qibbsData.restUrl + 'c2c/list',
                            method: 'POST',
                            data: {
                                cuid: cuid,
                                price_cash: priceCash,
                                price_points: pricePoints
                            },
                            beforeSend: function(xhr) {
                                xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                            },
                            success: function(resp) {
                                alert(resp.message || '挂牌成功！号码已进入交易行寄售大厅。');
                                self.openBackpack();
                            },
                            error: function(xhr) {
                                alert(xhr.responseJSON ? xhr.responseJSON.message : '挂牌失败');
                            }
                        });
                    });

                    // 绑定下架撤单操作
                    $('.qibbs-bp-delist-btn').on('click', function() {
                        const cuid = $(this).data('cuid');
                        if (!confirm('确认下架撤单靓号 ' + cuid + '？下架后号码将退回背包可正常佩戴。')) {
                            return;
                        }

                        $.ajax({
                            url: qibbsData.restUrl + 'c2c/delist',
                            method: 'POST',
                            data: { cuid: cuid },
                            beforeSend: function(xhr) {
                                xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                            },
                            success: function(resp) {
                                alert(resp.message || '撤单下架成功！号码已恢复持有状态。');
                                self.openBackpack();
                            },
                            error: function(xhr) {
                                alert(xhr.responseJSON ? xhr.responseJSON.message : '下架失败');
                            }
                        });
                    });

                    // 绑定官方回收变现操作
                    $('.qibbs-bp-recycle-btn').on('click', function() {
                        const cuid = $(this).data('cuid');
                        $.ajax({
                            url: qibbsData.restUrl + 'c2c/recycle-quote',
                            method: 'GET',
                            data: { cuid: cuid },
                            beforeSend: function(xhr) {
                                xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                            },
                            success: function(q) {
                                const quoteStr = q.payout_type === 'balance' ? ('¥' + parseFloat(q.refund_cash).toFixed(2)) : (q.refund_points + ' 积分');
                                if (!confirm('【官方回购变现】系统折价预估返还金额: ' + quoteStr + '\n\n确认立即将靓号 ' + cuid + ' 回售给官方池吗？回售后号码将解除绑定。')) {
                                    return;
                                }

                                $.ajax({
                                    url: qibbsData.restUrl + 'c2c/recycle',
                                    method: 'POST',
                                    data: { cuid: cuid },
                                    beforeSend: function(xhr) {
                                        xhr.setRequestHeader('X-WP-Nonce', qibbsData.restNonce);
                                    },
                                    success: function(resp) {
                                        alert(resp.message || '回售变现成功！');
                                        self.openBackpack();
                                    },
                                    error: function(xhr) {
                                        alert(xhr.responseJSON ? xhr.responseJSON.message : '回售失败');
                                    }
                                });
                            },
                            error: function(xhr) {
                                alert(xhr.responseJSON ? xhr.responseJSON.message : '获取回收估价失败');
                            }
                        });
                    });
                },
                error: function() {
                    $('#qibbs-backpack-content').html('<p style="color:#d63638;text-align:center;padding:20px;">获取背包数据失败</p>');
                }
            });
        },

        // 关闭弹窗
        closeModal: function() {
            if (this.activePollTimer) {
                clearInterval(this.activePollTimer);
                this.activePollTimer = null;
            }
            $('#qibbs-checkout-modal, #qibbs-backpack-modal').remove();
        }
    };

    $(document).ready(function() {
        window.QibbsPayment.init();
    });

})(jQuery);
