/**
 * 工单前台交互脚本
 */
(function ($) {
    'use strict';

    // 待上传媒体文件队列
    var ticketMediaQueue = [];
    var ticketMediaIds = [];

    // DOM 就绪后初始化
    $(document).ready(function () {
        initTicketForms();
        initTicketImageUpload();
    });

    /**
     * 初始化工单表单交互
     */
    function initTicketForms() {
        // 文本域高度自适应
        $('textarea').on('input', function () {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });

        // 工单表单提交与附件上传处理
        $('#qibbs-ticket-form').on('submit', function (e) {
            var form = $(this);
            var submitBtn = $('#ticket_submit_btn');

            // 过滤队列中无效项
            var filesToUpload = ticketMediaQueue.filter(function (file) {
                return file !== null;
            });

            // 若有图片附件先进行异步上传
            if (filesToUpload.length > 0) {
                e.preventDefault();

                submitBtn.prop('disabled', true).text(qibbsTicket.i18n.uploading || '上传图片中...');

                uploadTicketMedia(function (mediaIds) {
                    // 填充上传完成的附件 ID
                    $('#ticket_attachments').val(mediaIds.join(','));

                    submitBtn.text(qibbsTicket.i18n.submitting || '提交中...');

                    // 触发表单原生提交
                    form[0].submit();
                });

                return false;
            }

            // 无附件直接提交
            submitBtn.prop('disabled', true).text(qibbsTicket.i18n.submitting || '提交中...');
        });
    }

    /**
     * 初始化图片附件上传功能
     */
    function initTicketImageUpload() {
        var $uploadBtn = $('#ticket_upload_btn');
        var $fileInput = $('#ticket_media_input');
        var $preview = $('#ticket_media_preview');

        // 点击上传按钮触发文件选择框
        $uploadBtn.on('click', function (e) {
            e.preventDefault();
            $fileInput.click();
        });

        // 监听文件选择
        $fileInput.on('change', function (e) {
            var files = e.target.files;
            handleFileSelect(files, $preview);
            e.target.value = ''; // 重置输入框以便重复选择
        });

        // 删除已选图片（事件代理）
        $preview.on('click', '.qibbs-ticket-media-remove', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var index = parseInt($(this).data('index'), 10);
            var $item = $(this).closest('.qibbs-ticket-media-item');

            // 队列对应位置置空
            if (index >= 0 && index < ticketMediaQueue.length) {
                ticketMediaQueue[index] = null;
            }

            $item.fadeOut(200, function () {
                $(this).remove();
            });
        });

        // 拖拽上传支持
        var $uploadArea = $('.qibbs-ticket-upload-area');

        $uploadArea.on('dragover dragenter', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('qibbs-drag-over');
        });

        $uploadArea.on('dragleave drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('qibbs-drag-over');
        });

        $uploadArea.on('drop', function (e) {
            var files = e.originalEvent.dataTransfer.files;
            handleFileSelect(files, $preview);
        });
    }

    /**
     * 处理选中的附件文件
     */
    function handleFileSelect(files, $preview) {
        var maxSizeMB = qibbsTicket.maxUploadSize || 5;
        var maxSizeBytes = maxSizeMB * 1024 * 1024;
        var allowedFormatsStr = qibbsTicket.allowedImageFormats || 'jpg,jpeg,png,webp,gif';
        var allowedFormats = allowedFormatsStr.split(',').map(function (f) {
            return f.trim().toLowerCase();
        });

        Array.from(files).forEach(function (file) {
            // 校验最大上传张数
            var activeCount = ticketMediaQueue.filter(function (f) { return f !== null; }).length;
            if (activeCount >= 9) {
                showTicketToast(qibbsTicket.i18n.maxImages || '最多上传9张图片', 'error');
                return;
            }

            // 获取文件扩展名
            var fileName = file.name.toLowerCase();
            var ext = fileName.split('.').pop();

            // 校验文件格式
            if (allowedFormats.indexOf(ext) === -1) {
                showTicketToast((qibbsTicket.i18n.invalidFormat || '不支持的格式: ') + ext, 'error');
                return;
            }

            // 校验文件体积
            if (file.size > maxSizeBytes) {
                showTicketToast((qibbsTicket.i18n.fileTooLarge || '文件太大: ') + file.name, 'error');
                return;
            }

            // 加入上传队列
            ticketMediaQueue.push(file);
            var currentIndex = ticketMediaQueue.length - 1;

            // 生成本地缩略图预览
            var reader = new FileReader();
            reader.onload = function (e) {
                var $item = $('<div class="qibbs-ticket-media-item" data-index="' + currentIndex + '">' +
                    '<img src="' + e.target.result + '" alt="">' +
                    '<button type="button" class="qibbs-ticket-media-remove" data-index="' + currentIndex + '">&times;</button>' +
                    '</div>');
                $preview.append($item);
            };
            reader.readAsDataURL(file);
        });
    }

    /**
     * 批量上传工单附件
     */
    function uploadTicketMedia(callback) {
        // 过滤队列中无效占位
        var filesToUpload = ticketMediaQueue.filter(function (file) {
            return file !== null;
        });

        if (filesToUpload.length === 0) {
            callback([]);
            return;
        }

        var mediaIds = [];
        var uploaded = 0;
        var totalToUpload = filesToUpload.length;

        filesToUpload.forEach(function (file) {
            var formData = new FormData();
            formData.append('action', 'qibbs_upload_media');
            formData.append('nonce', qibbsTicket.nonce);
            formData.append('file', file);

            $.ajax({
                url: qibbsTicket.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (response) {
                    if (response.success) {
                        mediaIds.push(response.data.id);
                    }
                },
                complete: function () {
                    uploaded++;
                    if (uploaded === totalToUpload) {
                        callback(mediaIds);
                    }
                }
            });
        });
    }

    /**
     * 弹出 Toast 提示框
     */
    function showTicketToast(message, type) {
        type = type || 'info';

        var $toast = $('<div class="qibbs-ticket-toast qibbs-ticket-toast--' + type + '">' + message + '</div>');
        $('body').append($toast);

        setTimeout(function () {
            $toast.addClass('show');
        }, 10);

        setTimeout(function () {
            $toast.removeClass('show');
            setTimeout(function () {
                $toast.remove();
            }, 300);
        }, 3000);
    }

})(jQuery);
