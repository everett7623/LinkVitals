/**
 * LinkVitals missing image variant repairs.
 *
 * Handles one-click row repairs and intentionally processes bulk selections
 * one request at a time so each server request stays bounded.
 */

(function($) {
    'use strict';

    var BULK_ACTION = 'repair_image_variants';

    function formatCounts(template, first, second) {
        return template
            .replace('%1$d', first)
            .replace('%2$d', second);
    }

    function getErrorMessage(response, xhr) {
        if (response && response.data && response.data.message) {
            return response.data.message;
        }
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            return xhr.responseJSON.data.message;
        }
        return lhaAdmin.i18n.image_repair_failed;
    }

    function requestRepair(linkId) {
        return $.ajax({
            url: lhaAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'lha_repair_image_variant',
                nonce: lhaAdmin.nonce,
                link_id: linkId
            }
        });
    }

    function setRowResult($row, message, isError) {
        var $target = $row.find('.lha-action-repair-image').first();
        if (!$target.length) {
            $target = $('<span class="lha-image-repair-result"></span>');
            $row.find('.column-url').append('<br>').append($target);
        }

        $target
            .text(message)
            .css('color', isError ? '#d63638' : '#00a32a');

        if (!isError) {
            $row.css('background', '#edfaef');
        }
    }

    function ensureNotice($form) {
        var $notice = $('#lha-image-repair-progress');
        if (!$notice.length) {
            $notice = $('<div id="lha-image-repair-progress" class="notice notice-info"><p></p></div>');
            $form.before($notice);
        }
        return $notice;
    }

    function repairSingle(linkId, $trigger) {
        if ($trigger.data('lha-repairing')) {
            return;
        }

        $trigger
            .data('lha-repairing', true)
            .text(lhaAdmin.i18n.image_repair_checking)
            .css('color', '');

        requestRepair(linkId)
            .done(function(response) {
                if (response.success) {
                    setRowResult($trigger.closest('tr'), response.data.message, false);
                    setTimeout(function() { location.reload(); }, 1200);
                    return;
                }

                setRowResult($trigger.closest('tr'), getErrorMessage(response), true);
                $trigger.data('lha-repairing', false);
            })
            .fail(function(xhr) {
                setRowResult($trigger.closest('tr'), getErrorMessage(null, xhr), true);
                $trigger.data('lha-repairing', false);
            });
    }

    function getSelectedBulkAction($form) {
        var topAction = $form.find('select[name="action"]').val();
        var bottomAction = $form.find('select[name="action2"]').val();
        return topAction === BULK_ACTION || bottomAction === BULK_ACTION ? BULK_ACTION : '';
    }

    function repairSelection($form, linkIds) {
        var $notice = ensureNotice($form);
        var $controls = $form.find('select[name="action"], select[name="action2"], input[type="submit"], input[name="link_ids[]"]');
        var index = 0;
        var repaired = 0;
        var failed = 0;

        $controls.prop('disabled', true);

        function finish() {
            $controls.prop('disabled', false);
            $form.find('select[name="action"], select[name="action2"]').val('-1');
            $notice
                .removeClass('notice-info notice-success notice-warning')
                .addClass(failed > 0 ? 'notice-warning' : 'notice-success')
                .find('p')
                .text(formatCounts(lhaAdmin.i18n.image_repair_complete, repaired, failed));

            if (0 === failed) {
                setTimeout(function() { location.reload(); }, 1200);
            }
        }

        function next() {
            if (index >= linkIds.length) {
                finish();
                return;
            }

            var linkId = linkIds[index];
            var $row = $form.find('input[name="link_ids[]"][value="' + linkId + '"]').closest('tr');
            $notice.find('p').text(
                formatCounts(lhaAdmin.i18n.image_repair_progress, index + 1, linkIds.length)
            );

            requestRepair(linkId)
                .done(function(response) {
                    if (response.success) {
                        repaired++;
                        setRowResult($row, response.data.message, false);
                    } else {
                        failed++;
                        setRowResult($row, getErrorMessage(response), true);
                    }
                })
                .fail(function(xhr) {
                    failed++;
                    setRowResult($row, getErrorMessage(null, xhr), true);
                })
                .always(function() {
                    index++;
                    next();
                });
        }

        next();
    }

    $(document).on('click', '.lha-action-repair-image', function(event) {
        event.preventDefault();
        repairSingle($(this).data('link-id'), $(this));
    });

    $(document).on('submit', '#lha-report-form', function(event) {
        var $form = $(this);
        if (BULK_ACTION !== getSelectedBulkAction($form)) {
            return;
        }

        event.preventDefault();
        var linkIds = $form.find('input[name="link_ids[]"]:checked').map(function() {
            return parseInt(this.value, 10);
        }).get().filter(function(linkId) {
            return linkId > 0;
        });

        if (!linkIds.length) {
            ensureNotice($form)
                .removeClass('notice-info notice-success notice-warning')
                .addClass('notice-error')
                .find('p')
                .text(lhaAdmin.i18n.image_repair_no_selection);
            return;
        }

        repairSelection($form, linkIds);
    });
})(jQuery);
