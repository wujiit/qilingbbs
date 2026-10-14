jQuery(document).ready(function ($) {

    // 1. 发帖编辑器中注入投票按钮
    var $tools = $('.qibbs-composer-tools');
    if ($tools.length > 0) {
        var $voteBtn = $('<label class="qibbs-tool-btn qibbs-vote-btn" title="' + qibbsVoting.i18n.createPoll + '">' +
            '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M4 14h4v-4H4v4zm0 5h4v-4H4v4zM4 9h4V5H4v4zm5 5h12v-4H9v4zm0 5h12v-4H9v4zM9 5v4h12V5H9z"/></svg>' +
            '</label>');

        $tools.prepend($voteBtn);

        $voteBtn.on('click', function () {
            openPollModal();
        });
    }

    // 2. 投票创建弹窗交互逻辑

    function openPollModal() {
        if ($('#qibbs-poll-modal').length === 0) {
            createPollModal();
        }
        $('#qibbs-poll-modal').addClass('show');
    }

    function closePollModal() {
        $('#qibbs-poll-modal').removeClass('show');
        resetPollForm();
    }

    function createPollModal() {
        var modalHtml =
            '<div class="qibbs-modal-overlay" id="qibbs-poll-modal">' +
            '<div class="qibbs-modal">' +
            '<div class="qibbs-modal-header">' +
            '<h3 class="qibbs-modal-title">' + qibbsVoting.i18n.createPoll + '</h3>' +
            '<button class="qibbs-modal-close">&times;</button>' +
            '</div>' +
            '<div class="qibbs-modal-body">' +
            '<div class="qibbs-form-group">' +
            '<label class="qibbs-form-label">' + qibbsVoting.i18n.pollTitle + '</label>' +
            '<input type="text" class="qibbs-form-input" id="qibbs-poll-title" placeholder="' + qibbsVoting.i18n.pollTitle + '">' +
            '</div>' +
            '<div class="qibbs-form-group">' +
            '<label class="qibbs-form-label">' + qibbsVoting.i18n.options + '</label>' +
            '<div class="qibbs-poll-options-list" id="qibbs-poll-options">' +
            '<div class="qibbs-poll-option-item">' +
            '<input type="text" class="qibbs-form-input poll-option-input" placeholder="' + qibbsVoting.i18n.options + ' 1">' +
            '</div>' +
            '<div class="qibbs-poll-option-item">' +
            '<input type="text" class="qibbs-form-input poll-option-input" placeholder="' + qibbsVoting.i18n.options + ' 2">' +
            '</div>' +
            '</div>' +
            '<button class="qibbs-add-option-btn" id="qibbs-add-option">+ ' + qibbsVoting.i18n.addOption + '</button>' +
            '</div>' +
            '<div class="qibbs-form-group" style="display:flex;gap:10px;">' +
            '<div style="flex:1">' +
            '<label class="qibbs-form-label">' + qibbsVoting.i18n.single + '/' + qibbsVoting.i18n.multiple + '</label>' +
            '<select class="qibbs-form-select" id="qibbs-poll-type">' +
            '<option value="single">' + qibbsVoting.i18n.single + '</option>' +
            '<option value="multiple">' + qibbsVoting.i18n.multiple + '</option>' +
            '</select>' +
            '</div>' +
            '<div style="flex:1">' +
            '<label class="qibbs-form-label">' + qibbsVoting.i18n.days + '</label>' +
            '<select class="qibbs-form-select" id="qibbs-poll-days">' +
            '<option value="7">7 ' + qibbsVoting.i18n.days + '</option>' +
            '<option value="3">3 ' + qibbsVoting.i18n.days + '</option>' +
            '<option value="1">1 ' + qibbsVoting.i18n.days + '</option>' +
            '<option value="30">30 ' + qibbsVoting.i18n.days + '</option>' +
            '</select>' +
            '</div>' +
            '</div>' +
            '</div>' +
            '<div class="qibbs-modal-footer">' +
            '<button class="qibbs-btn qibbs-btn-secondary" id="qibbs-cancel-poll">' + qibbsVoting.i18n.cancel + '</button>' +
            '<button class="qibbs-btn qibbs-btn-primary" id="qibbs-submit-poll">' + qibbsVoting.i18n.create + '</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        $('body').append(modalHtml);

        $(document).on('click', '.qibbs-modal-close, #qibbs-cancel-poll', function () {
            closePollModal();
        });

        $('#qibbs-add-option').on('click', function () {
            var count = $('.poll-option-input').length + 1;
            var optionHtml =
                '<div class="qibbs-poll-option-item">' +
                '<input type="text" class="qibbs-form-input poll-option-input" placeholder="' + qibbsVoting.i18n.options + ' ' + count + '">' +
                '<button class="qibbs-poll-remove-option">&times;</button>' +
                '</div>';
            $('#qibbs-poll-options').append(optionHtml);
        });

        $(document).on('click', '.qibbs-poll-remove-option', function () {
            $(this).parent().remove();
        });

        $('#qibbs-submit-poll').on('click', function () {
            submitPoll();
        });
    }

    function resetPollForm() {
        $('#qibbs-poll-title').val('');
        $('.poll-option-input').val('');
        while ($('.poll-option-input').length > 2) {
            $('.qibbs-poll-option-item:last').remove();
        }
        $('#qibbs-poll-type').val('single');
    }

    /**
     * 弹出投票交互 Toast 提示
     */
    function showVotingToast(message, type) {
        type = type || 'error';
        var iconSvg = type === 'success'
            ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>'
            : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
        var $toast = $('<div class="qibbs-toast qibbs-toast-' + type + '"><span class="qibbs-toast-icon">' + iconSvg + '</span>' + message + '</div>');
        $('body').append($toast);

        setTimeout(function () { $toast.addClass('show'); }, 10);
        setTimeout(function () {
            $toast.removeClass('show');
            setTimeout(function () { $toast.remove(); }, 300);
        }, 3000);
    }

    function submitPoll() {
        var title = $('#qibbs-poll-title').val().trim();
        var options = [];
        $('.poll-option-input').each(function () {
            var val = $(this).val().trim();
            if (val) options.push(val);
        });

        if (!title) {
            showVotingToast((qibbsVoting.i18n.pollTitle || '投票标题') + ' 不能为空', 'error');
            return;
        }
        if (options.length < 2) {
            showVotingToast('投票至少需要填写 2 个选项', 'error');
            return;
        }

        var type = $('#qibbs-poll-type').val();
        var days = $('#qibbs-poll-days').val();

        var data = {
            action: 'qibbs_create_poll',
            nonce: qibbsVoting.nonce,
            title: title,
            options: options,
            type: type,
            days: days
        };

        var $submitBtn = $('#qibbs-submit-poll');
        $submitBtn.prop('disabled', true).text(qibbsVoting.i18n.creating || '创建中...');

        $.post(qibbsVoting.ajaxUrl, data, function (response) {
            $submitBtn.prop('disabled', false).text(qibbsVoting.i18n.create || '创建投票');

            if (response.success) {
                attachPollToComposer(response.data.poll_id, title);
                closePollModal();
                showVotingToast('投票已成功附加到动态', 'success');
            } else {
                showVotingToast(response.data || '创建投票失败，请稍后重试', 'error');
            }
        });
    }

    function formatDate(date) {
        var y = date.getFullYear();
        var m = ('0' + (date.getMonth() + 1)).slice(-2);
        var d = ('0' + date.getDate()).slice(-2);
        return y + '-' + m + '-' + d;
    }

    function formatTime(date) {
        var h = ('0' + date.getHours()).slice(-2);
        var m = ('0' + date.getMinutes()).slice(-2);
        return h + ':' + m;
    }

    function attachPollToComposer(pollId, title) {
        $('.qibbs-poll-attachment').remove();
        $('input[name="qibbs_poll_id"]').remove();

        var html =
            '<div class="qibbs-poll-attachment">' +
            '<div class="qibbs-poll-attachment-info">' +
            '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M4 14h4v-4H4v4zm0 5h4v-4H4v4zM4 9h4V5H4v4zm5 5h12v-4H9v4zm0 5h12v-4H9v4zM9 5v4h12V5H9z"/></svg>' +
            '<span>' + title + '</span>' +
            '</div>' +
            '<button type="button" class="qibbs-poll-remove-btn">&times;</button>' +
            '</div>';

        $('.qibbs-composer-body').append(html);
        $('#qibbs-post-form').append('<input type="hidden" name="qibbs_poll_id" value="' + pollId + '">');

        $('.qibbs-poll-remove-btn').on('click', function () {
            $('.qibbs-poll-attachment').remove();
            $('input[name="qibbs_poll_id"]').remove();
        });
    }

    // 3. 动态流投票卡片折叠与加载交互

    function loadPolls() {
        $('.qivot-poll-placeholder:not(.loaded)').each(function () {
            var $placeholder = $(this);
            var pollId = $placeholder.data('poll-id');

            $placeholder.addClass('loaded');

            // 创建简洁的折叠触发按钮 - 图标和文字在同一行
            var toggleHtml =
                '<div class="qivot-poll-toggle" data-poll-id="' + pollId + '">' +
                '<div class="qivot-poll-toggle-icon">' +
                '<svg viewBox="0 0 24 24" width="14" height="14"><path d="M4 14h4v-4H4v4zm0 5h4v-4H4v4zM4 9h4V5H4v4zm5 5h12v-4H9v4zm0 5h12v-4H9v4zM9 5v4h12V5H9z"/></svg>' +
                '</div>' +
                '<span class="qivot-poll-toggle-text">点击参与投票</span>' +
                '<svg class="qivot-poll-toggle-arrow" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>' +
                '</div>' +
                '<div class="qivot-poll-wrapper" data-poll-id="' + pollId + '"></div>';

            $placeholder.html(toggleHtml);
        });
    }

    // 点击展开/折叠投票
    $(document).on('click', '.qivot-poll-toggle', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $toggle = $(this);
        var pollId = $toggle.data('poll-id');
        var $wrapper = $toggle.siblings('.qivot-poll-wrapper[data-poll-id="' + pollId + '"]');
        var isExpanded = $toggle.hasClass('expanded');

        if (isExpanded) {
            // 收起
            $toggle.removeClass('expanded');
            $wrapper.removeClass('show');
            $toggle.find('.qivot-poll-toggle-text').text('点击参与投票');
        } else {
            // 展开
            $toggle.addClass('expanded');
            $toggle.find('.qivot-poll-toggle-text').text('点击收起');

            if (!$wrapper.hasClass('content-loaded')) {
                // 首次加载内容
                $wrapper.html('<div class="qivot-loading"><div class="qivot-spinner"></div><span>加载中...</span></div>');
                $wrapper.addClass('show');

                $.post(qibbsVoting.ajaxUrl, {
                    action: 'qibbs_get_poll_html',
                    nonce: qibbsVoting.nonce,
                    poll_id: pollId
                }, function (response) {
                    if (response.success) {
                        var $content = $(response.data.html);

                        // 准备进度条动画
                        $content.find('.qivot-option-bar-fill').each(function () {
                            var $bar = $(this);
                            var targetWidth = $bar[0].style.width;
                            if (targetWidth) {
                                $bar.attr('data-target-width', targetWidth);
                                $bar.css('width', '0%');
                            }
                        });

                        $wrapper.html($content).addClass('content-loaded');

                        // 触发进度条动画
                        setTimeout(function () {
                            $wrapper.find('.qivot-option-bar-fill').each(function () {
                                var $bar = $(this);
                                var width = $bar.attr('data-target-width');
                                if (width) {
                                    $bar.css('width', width);
                                }
                            });
                        }, 50);

                        // 初始化 Qivoting 交互
                        if (typeof window.qivotInitPolls === 'function') {
                            window.qivotInitPolls();
                        }
                    } else {
                        $wrapper.html('<div class="qivot-poll-message qivot-message-info">加载失败，请重试</div>');
                    }
                });
            } else {
                // 已加载，直接显示
                $wrapper.addClass('show');
            }
        }
    });

    // 初始化加载页面投票
    loadPolls();

    // 监听动态流加载事件，渲染新增投票
    document.addEventListener('qibbs:feed:loaded', function () {
        loadPolls();
    });

    document.addEventListener('qibbs:post:created', function () {
        setTimeout(loadPolls, 500);
    });

});
