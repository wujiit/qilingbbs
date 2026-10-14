/**
 * 工单后台管理交互脚本
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        initAdminTickets();
    });

    function initAdminTickets() {
        // 删除附件事件处理
        $(document).on('click', '.qibbs-delete-attachment', function (e) {
            e.preventDefault();

            if (!confirm(qibbsTicketAdmin.i18n.confirm_delete)) {
                return;
            }

            var $btn = $(this);
            var $item = $btn.closest('.qibbs-attachment-item');
            var mediaId = $btn.data('media-id');
            var ticketId = $btn.data('ticket-id');

            $btn.prop('disabled', true).text('...');

            $.ajax({
                url: qibbsTicketAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'qibbs_ticket_action',
                    nonce: qibbsTicketAdmin.nonce,
                    ticket_action: 'delete_attachment',
                    ticket_id: ticketId,
                    media_id: mediaId
                },
                success: function (response) {
                    if (response.success) {
                        $item.fadeOut(200, function () {
                            $(this).remove();
                        });
                    } else {
                        alert(response.data.message || qibbsTicketAdmin.i18n.error);
                        $btn.prop('disabled', false).text('×');
                    }
                },
                error: function () {
                    alert(qibbsTicketAdmin.i18n.error);
                    $btn.prop('disabled', false).text('×');
                }
            });
        });
    }

})(jQuery);
