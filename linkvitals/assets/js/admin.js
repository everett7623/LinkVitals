/**
 * LinkVitals - Admin JavaScript
 *
 * Handles AJAX scan operations, progress polling, and row actions.
 * Dependencies: jQuery (WordPress bundled)
 */

(function($) {
    'use strict';

    var LHA = {
        pollInterval: null,
        isScanning: false,

        init: function() {
            this.bindEvents();
            this.checkInitialState();
        },

        getAjaxErrorMessage: function(xhr, fallback) {
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                return xhr.responseJSON.data.message;
            }
            if (xhr && xhr.responseText) {
                try {
                    var parsed = JSON.parse(xhr.responseText);
                    if (parsed && parsed.data && parsed.data.message) {
                        return parsed.data.message;
                    }
                } catch (e) {}
            }
            return fallback || lhaAdmin.i18n.request_failed;
        },

        bindEvents: function() {
            // Dashboard scan buttons
            $('#lha-btn-full-scan').on('click', function() {
                LHA.startScan('full');
            });
            $('#lha-btn-incremental-scan').on('click', function() {
                LHA.startScan('incremental');
            });
            $('#lha-btn-recheck-broken').on('click', function() {
                LHA.startScan('recheck_broken');
            });
            $('#lha-btn-pause').on('click', function() {
                LHA.pauseScan();
            });
            $('#lha-btn-resume').on('click', function() {
                LHA.resumeScan();
            });
            $('#lha-btn-cleanup-orphans').on('click', function() {
                LHA.runMaintenanceAction('lha_cleanup_orphans', $(this));
            });
            $('#lha-btn-purge-logs').on('click', function() {
                LHA.runMaintenanceAction('lha_purge_logs', $(this), {
                    confirmMessage: lhaAdmin.i18n.purge_logs_confirm
                });
            });
            $('#lha-btn-purge-repairs').on('click', function() {
                LHA.runMaintenanceAction('lha_purge_repairs', $(this), {
                    confirmMessage: lhaAdmin.i18n.purge_repairs_confirm
                });
            });
            $('#lha-btn-reset-data').on('click', function() {
                var confirmation = window.prompt(lhaAdmin.i18n.reset_data_prompt);
                if (confirmation !== 'RESET') {
                    $('#lha-maintenance-result')
                        .text(lhaAdmin.i18n.reset_data_invalid)
                        .css('color', '#d63638');
                    return;
                }

                LHA.runMaintenanceAction('lha_reset_data', $(this), {
                    data: {
                        confirmation: confirmation
                    },
                    reloadOnSuccess: true
                });
            });

            // Report page row actions
            $(document).on('click', '.lha-action-recheck', function(e) {
                e.preventDefault();
                LHA.recheckLink($(this).data('link-id'), $(this));
            });
            $(document).on('click', '.lha-action-ignore', function(e) {
                e.preventDefault();
                LHA.ignoreLink($(this).data('link-id'), $(this));
            });
            $(document).on('click', '.lha-action-unignore', function(e) {
                e.preventDefault();
                LHA.unignoreLink($(this).data('link-id'), $(this));
            });
            $(document).on('click', '.lha-action-replace', function(e) {
                e.preventDefault();
                LHA.replaceUrl($(this));
            });
            $(document).on('click', '.lha-action-unlink', function(e) {
                e.preventDefault();
                LHA.unlinkUrl($(this).data('link-id'), $(this));
            });
            $(document).on('click', '.lha-action-rollback-repair', function(e) {
                e.preventDefault();
                LHA.rollbackRepair($(this).data('repair-id'), $(this));
            });

            // Inline edit actions
            $(document).on('click', '.lha-action-edit-url', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var $trigger = $(this);
                var linkId = $trigger.data('link-id');
                var $row = $trigger.closest('tr');
                var $wrap = $row.find('.lha-inline-edit[data-link-id="' + linkId + '"]');

                // Fallback when data parsing differs across WP/jQuery versions.
                if (!$wrap.length) {
                    $wrap = $row.find('.lha-inline-edit').first();
                }

                if (!$wrap.length) {
                    return;
                }

                $row.find('.lha-inline-edit').not($wrap).hide();
                $wrap.stop(true, true).slideToggle(200);

                if ($wrap.is(':visible')) {
                    $wrap.find('.lha-new-url-input').trigger('focus');
                }
            });
            $(document).on('click', '.lha-inline-cancel', function(e) {
                e.preventDefault();
                $(this).closest('.lha-inline-edit').slideUp(200);
            });
            $(document).on('click', '.lha-inline-save', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $wrap = $(this).closest('.lha-inline-edit');
                var oldUrl = $wrap.data('old-url');
                var newUrl = $wrap.find('.lha-new-url-input').val().trim();
                var $status = $wrap.find('.lha-inline-status');

                if (!oldUrl) {
                    $status.text(lhaAdmin.i18n.original_url_missing).css('color', '#d63638');
                    return;
                }

                if (!newUrl) {
                    $status.text(lhaAdmin.i18n.enter_url).css('color', '#d63638');
                    return;
                }
                if (newUrl === oldUrl) {
                    $status.text(lhaAdmin.i18n.url_same).css('color', '#d63638');
                    return;
                }

                LHA.doReplace(oldUrl, newUrl, $wrap);
            });
        },

        checkInitialState: function() {
            // If progress bar is visible, start polling
            if ($('#lha-scan-progress').is(':visible')) {
                this.isScanning = true;
                this.startPolling();
            }
        },

        /**
         * Start a scan via AJAX
         */
        startScan: function(type) {
            var self = this;

            $.ajax({
                url: lhaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lha_start_scan',
                    nonce: lhaAdmin.nonce,
                    scan_type: type
                },
                beforeSend: function() {
                    $('#lha-btn-full-scan, #lha-btn-incremental-scan, #lha-btn-recheck-broken').prop('disabled', true);
                },
                success: function(response) {
                    if (response.success) {
                        self.isScanning = true;
                        $('#lha-scan-progress').show();
                        $('#lha-btn-pause').show();
                        $('#lha-btn-resume').hide();
                        self.startPolling();
                        self.processBatch(); // Start processing immediately
                    }
                },
                error: function() {
                    console.error('Scan start failed');
                },
                complete: function() {
                    $('#lha-btn-full-scan, #lha-btn-incremental-scan, #lha-btn-recheck-broken').prop('disabled', false);
                }
            });
        },

        /**
         * Process next batch via AJAX (client-driven batching)
         */
        processBatch: function() {
            var self = this;

            if (!this.isScanning) {
                return;
            }

            $.ajax({
                url: lhaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lha_process_batch',
                    nonce: lhaAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        if (data.status === 'running' || data.status === 'checking_links') {
                            // Continue processing after a short delay
                            if (self.isScanning) {
                                setTimeout(function() {
                                    self.processBatch();
                                }, 1000);
                            }
                        } else if (data.status === 'completed') {
                            self.onScanComplete();
                        }
                    }
                },
                error: function() {
                    // Retry after delay on error
                    if (self.isScanning) {
                        setTimeout(function() {
                            self.processBatch();
                        }, 5000);
                    }
                }
            });
        },

        /**
         * Poll for scan progress
         */
        startPolling: function() {
            var self = this;
            this.stopPolling();

            this.pollInterval = setInterval(function() {
                self.getProgress();
            }, 3000);
        },

        stopPolling: function() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
        },

        getProgress: function() {
            var self = this;

            $.ajax({
                url: lhaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lha_scan_progress',
                    nonce: lhaAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        self.updateProgressUI(data);

                        if (data.status === 'completed' || data.status === 'idle') {
                            self.onScanComplete();
                        }
                    }
                }
            });
        },

        updateProgressUI: function(data) {
            var percentage = data.percentage || 0;
            $('.lha-progress-fill').css('width', percentage + '%');
            $('#lha-progress-percentage').text(percentage);
            $('#lha-progress-done').text(data.done || 0);
            $('#lha-progress-total').text(data.total || 0);
        },

        onScanComplete: function() {
            this.isScanning = false;
            this.stopPolling();
            $('.lha-progress-fill').css('width', '100%');
            $('#lha-progress-percentage').text('100');
            $('#lha-btn-pause').hide();
            $('#lha-btn-resume').hide();

            // Show completion message briefly then reload
            setTimeout(function() {
                location.reload();
            }, 2000);
        },

        /**
         * Pause the current scan
         */
        pauseScan: function() {
            var self = this;

            $.ajax({
                url: lhaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lha_pause_scan',
                    nonce: lhaAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.isScanning = false;
                        self.stopPolling();
                        $('#lha-btn-pause').hide();
                        $('#lha-btn-resume').show();
                    }
                }
            });
        },

        /**
         * Resume a paused scan
         */
        resumeScan: function() {
            var self = this;

            $.ajax({
                url: lhaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lha_resume_scan',
                    nonce: lhaAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.isScanning = true;
                        $('#lha-btn-pause').show();
                        $('#lha-btn-resume').hide();
                        self.startPolling();
                        self.processBatch();
                    }
                }
            });
        },

        /**
         * Recheck a single link
         */
        recheckLink: function(linkId, $el) {
            var $row = $el.closest('tr');

            $.ajax({
                url: lhaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lha_recheck_link',
                    nonce: lhaAdmin.nonce,
                    link_id: linkId
                },
                beforeSend: function() {
                    $el.text(lhaAdmin.i18n.scanning);
                },
                success: function(response) {
                    if (response.success) {
                        // Reload page to show updated data
                        location.reload();
                    } else {
                        $el.text(response.data.message || lhaAdmin.i18n.recheck_failed).css('color', '#d63638');
                    }
                },
                error: function(xhr) {
                    $el.text(LHA.getAjaxErrorMessage(xhr, lhaAdmin.i18n.recheck_failed)).css('color', '#d63638');
                }
            });
        },

        /**
         * Ignore a link
         */
        ignoreLink: function(linkId, $el) {
            $.ajax({
                url: lhaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lha_ignore_link',
                    nonce: lhaAdmin.nonce,
                    link_id: linkId
                },
                success: function(response) {
                    if (response.success) {
                        $el.closest('tr').fadeOut(300, function() {
                            $(this).remove();
                        });
                    }
                }
            });
        },

        /**
         * Unignore a link
         */
        unignoreLink: function(linkId, $el) {
            $.ajax({
                url: lhaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lha_unignore_link',
                    nonce: lhaAdmin.nonce,
                    link_id: linkId
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    }
                }
            });
        },

        /**
         * Replace a redirect URL with its final destination (one-click)
         */
        replaceUrl: function($el) {
            var oldUrl = $el.data('old-url');
            var newUrl = $el.data('new-url');

            if (!oldUrl || !newUrl) {
                $el.text(lhaAdmin.i18n.invalid_url).css('color', '#d63638');
                return;
            }

            $el.text(lhaAdmin.i18n.saving);
            $.ajax({
                url: lhaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lha_replace_url',
                    nonce: lhaAdmin.nonce,
                    old_url: oldUrl,
                    new_url: newUrl
                },
                success: function(res) {
                    if (res.success) {
                        $el.closest('tr').css('background', '#edfaef');
                        var resolvedText = (res.data.replaced > 0)
                            ? lhaAdmin.i18n.done_count.replace('%d', res.data.replaced)
                            : lhaAdmin.i18n.fixed;
                        $el.text(resolvedText).css('color', '#00a32a');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        $el.text(res.data.message || lhaAdmin.i18n.save_failed).css('color', '#d63638');
                    }
                },
                error: function(xhr) {
                    $el.text(LHA.getAjaxErrorMessage(xhr, lhaAdmin.i18n.save_failed)).css('color', '#d63638');
                }
            });
        },

        /**
         * Inline replace: execute replacement from the inline edit form
         */
        doReplace: function(oldUrl, newUrl, $wrap) {
            var $status = $wrap.find('.lha-inline-status');
            var $btn = $wrap.find('.lha-inline-save');

            $btn.prop('disabled', true).text(lhaAdmin.i18n.saving);
            $status.text('').css('color', '');

            $.ajax({
                url: lhaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lha_replace_url',
                    nonce: lhaAdmin.nonce,
                    old_url: oldUrl,
                    new_url: newUrl
                },
                success: function(res) {
                    if (res.success) {
                        $wrap.closest('tr').css('background', '#edfaef');
                        var statusText = (res.data.replaced > 0)
                            ? lhaAdmin.i18n.replaced_count.replace('%d', res.data.replaced)
                            : lhaAdmin.i18n.fixed;
                        $status.text(statusText).css('color', '#00a32a');
                        $btn.prop('disabled', false).text(lhaAdmin.i18n.save);
                        setTimeout(function() { location.reload(); }, 2000);
                    } else {
                        $status.text(res.data.message || lhaAdmin.i18n.save_failed).css('color', '#d63638');
                        $btn.prop('disabled', false).text(lhaAdmin.i18n.save);
                    }
                },
                error: function(xhr) {
                    $status.text(LHA.getAjaxErrorMessage(xhr, lhaAdmin.i18n.save_failed)).css('color', '#d63638');
                    $btn.prop('disabled', false).text(lhaAdmin.i18n.save);
                }
            });
        },

        /**
         * Unlink a URL (remove <a> tag, keep text)
         */
        unlinkUrl: function(linkId, $el) {
            $.ajax({
                url: lhaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lha_unlink',
                    nonce: lhaAdmin.nonce,
                    link_id: linkId
                },
                success: function(response) {
                    if (response.success) {
                        $el.text(lhaAdmin.i18n.unlink_done_count.replace('%d', response.data.unlinked)).css('color', '#00a32a');
                        location.reload();
                    } else {
                        $el.text(response.data.message || lhaAdmin.i18n.unlink_failed).css('color', '#d63638');
                    }
                },
                error: function(xhr) {
                    $el.text(LHA.getAjaxErrorMessage(xhr, lhaAdmin.i18n.unlink_failed)).css('color', '#d63638');
                }
            });
        },

        /**
         * Roll back a repair history entry
         */
        rollbackRepair: function(repairId, $el) {
            if (!window.confirm(lhaAdmin.i18n.rollback_confirm)) {
                return;
            }

            $.ajax({
                url: lhaAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lha_rollback_repair',
                    nonce: lhaAdmin.nonce,
                    repair_id: repairId
                },
                beforeSend: function() {
                    $el.prop('disabled', true).text(lhaAdmin.i18n.rolling_back);
                },
                success: function(response) {
                    if (response.success) {
                        $el.text(lhaAdmin.i18n.rolled_back).css('color', '#00a32a');
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        $el.prop('disabled', false)
                            .text(response.data.message || lhaAdmin.i18n.rollback_failed)
                            .css('color', '#d63638');
                    }
                },
                error: function(xhr) {
                    $el.prop('disabled', false)
                        .text(LHA.getAjaxErrorMessage(xhr, lhaAdmin.i18n.rollback_failed))
                        .css('color', '#d63638');
                }
            });
        },

        /**
         * Run a dashboard maintenance action and show the result inline.
         */
        runMaintenanceAction: function(action, $button, options) {
            options = options || {};

            if (options.confirmMessage && !window.confirm(options.confirmMessage)) {
                return;
            }

            var $result = $('#lha-maintenance-result');
            var requestData = $.extend({
                action: action,
                nonce: lhaAdmin.nonce
            }, options.data || {});

            $.ajax({
                url: lhaAdmin.ajaxUrl,
                type: 'POST',
                data: requestData,
                beforeSend: function() {
                    $button.prop('disabled', true);
                    $result.text(lhaAdmin.i18n.maintenance_running).css('color', '');
                },
                success: function(response) {
                    if (response.success) {
                        $result.text(response.data.message || '').css('color', '#00a32a');
                        if (options.reloadOnSuccess) {
                            setTimeout(function() { location.reload(); }, 1000);
                        }
                    } else {
                        $result
                            .text(response.data.message || lhaAdmin.i18n.maintenance_failed)
                            .css('color', '#d63638');
                    }
                },
                error: function(xhr) {
                    $result
                        .text(LHA.getAjaxErrorMessage(xhr, lhaAdmin.i18n.maintenance_failed))
                        .css('color', '#d63638');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        LHA.init();
    });

})(jQuery);
