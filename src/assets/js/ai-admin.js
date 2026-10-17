(function ($) {
    'use strict';

    const config = window.lhaAdmin || {};
    const messages = config.i18n || {};
    const pollTimers = {};
    const maxPolls = 60;

    function message(key, fallback) {
        return messages[key] || fallback;
    }

    function request(action, data) {
        return $.post(config.ajaxUrl, Object.assign({
            action: action,
            nonce: config.nonce
        }, data || {}));
    }

    function getState(response) {
        return response && response.data && typeof response.data === 'object'
            ? response.data
            : {};
    }

    function setButtonRunning($button, running) {
        if (!$button.data('original-label')) {
            $button.data('original-label', $button.text());
        }
        $button.prop('disabled', running).attr('aria-busy', running ? 'true' : 'false');
        $button.text(running
            ? message('ai_generating', 'Generating suggestions...')
            : message('ai_generate_again', 'Generate again'));
    }

    function getResultBox($button) {
        const targetId = $button.attr('aria-controls');
        const $row = $('#' + targetId);
        $row.prop('hidden', false);
        return $row.find('.lha-ai-suggestions').first();
    }

    function showProgress($button, text) {
        const $box = getResultBox($button).empty();
        $('<span>', { class: 'spinner is-active' }).appendTo($box);
        $('<span>').text(text).appendTo($box);
    }

    function showFailure($button, state) {
        const error = state.error || state.message || message('ai_failed', 'AI suggestion failed.');
        const $box = getResultBox($button).empty();
        $('<p>', { class: 'lha-ai-error notice notice-error inline' }).text(error).appendTo($box);
        setButtonRunning($button, false);
    }

    function addDetail($container, label, value) {
        const $line = $('<p>');
        $('<strong>').text(label + ': ').appendTo($line);
        $('<span>').text(value).appendTo($line);
        $line.appendTo($container);
    }

    function renderSuggestions($button, state) {
        const $box = getResultBox($button).empty();
        const suggestions = Array.isArray(state.suggestions) ? state.suggestions : [];

        $('<p>', { class: 'lha-ai-result-message' }).text(state.message || '').appendTo($box);
        if (suggestions.length) {
            const $list = $('<ol>', { class: 'lha-ai-suggestion-list' }).appendTo($box);
            suggestions.forEach(function (suggestion) {
                const $item = $('<li>', { class: 'lha-ai-suggestion-item' }).appendTo($list);
                const $title = $('<p>', { class: 'lha-ai-suggestion-title' }).appendTo($item);
                $('<strong>').text(message('ai_source_page', 'Suggested source page') + ': ').appendTo($title);
                $('<a>', {
                    href: suggestion.source_url,
                    target: '_blank',
                    rel: 'noopener noreferrer'
                }).text(suggestion.source_title).appendTo($title);
                addDetail($item, message('ai_anchor_text', 'Anchor text'), suggestion.anchor_text);
                addDetail($item, message('ai_placement', 'Placement'), suggestion.placement_hint);
                addDetail($item, message('ai_reason', 'Why'), suggestion.reason);
                $('<a>', {
                    href: suggestion.source_edit_url,
                    class: 'button button-secondary button-small'
                }).text(message('ai_edit_source', 'Edit source page')).appendTo($item);
            });
        }

        if (state.model) {
            $('<p>', { class: 'description' })
                .text(state.model + (state.tokens ? ' · ' + state.tokens + ' tokens' : ''))
                .appendTo($box);
        }

        $button.data('job-id', '').attr('data-job-id', '');
        setButtonRunning($button, false);
    }

    function schedulePoll($button, jobId, attempt) {
        const postId = String($button.data('post-id'));
        clearTimeout(pollTimers[postId]);
        pollTimers[postId] = setTimeout(function () {
            pollJob($button, jobId, attempt + 1);
        }, 2000);
    }

    function pollJob($button, jobId, attempt) {
        if (attempt > maxPolls) {
            showFailure($button, {
                error: message('ai_poll_timeout', 'The job is taking longer than expected. Reload this page to continue checking.')
            });
            return;
        }

        request('lha_ai_orphan_status', { job_id: jobId })
            .done(function (response) {
                const state = getState(response);
                if (!response.success || state.status === 'failed') {
                    showFailure($button, state);
                    return;
                }
                if (state.status === 'success') {
                    renderSuggestions($button, state);
                    return;
                }

                showProgress($button, state.message || message('ai_waiting', 'Waiting for the background job...'));
                schedulePoll($button, jobId, attempt);
            })
            .fail(function (xhr) {
                showFailure($button, getState(xhr.responseJSON));
            });
    }

    function startPolling($button, jobId) {
        $button.data('job-id', jobId).attr('data-job-id', jobId);
        setButtonRunning($button, true);
        showProgress($button, message('ai_waiting', 'Waiting for the background job...'));
        pollJob($button, jobId, 0);
    }

    $(document).on('click', '.lha-ai-orphan-trigger', function () {
        const $button = $(this);
        if ($button.prop('disabled')) {
            return;
        }

        setButtonRunning($button, true);
        showProgress($button, message('ai_generating', 'Generating suggestions...'));
        request('lha_ai_orphan_trigger', { post_id: $button.data('post-id') })
            .done(function (response) {
                const state = getState(response);
                if (!response.success || state.status === 'failed' || !state.job_id) {
                    showFailure($button, state);
                    return;
                }
                startPolling($button, state.job_id);
            })
            .fail(function (xhr) {
                showFailure($button, getState(xhr.responseJSON));
            });
    });

    $(document).on('click', '.lha-ai-test', function () {
        const $button = $(this);
        const provider = $button.data('provider');
        const $status = $('.lha-ai-test-status[data-provider="' + provider + '"]');
        const apiKey = $($button.data('key-input')).val();
        const model = $($button.data('model-input')).val();

        $button.prop('disabled', true);
        $status.removeClass('lha-ai-test-ok lha-ai-test-error')
            .text(message('ai_testing', 'Testing...'));

        request('lha_ai_test', { provider: provider, api_key: apiKey, model: model })
            .done(function (response) {
                const data = getState(response);
                $status.addClass(response.success ? 'lha-ai-test-ok' : 'lha-ai-test-error')
                    .text(data.message || message('request_failed', 'Request failed'));
            })
            .fail(function (xhr) {
                const data = getState(xhr.responseJSON);
                $status.addClass('lha-ai-test-error')
                    .text(data.message || message('request_failed', 'Request failed'));
            })
            .always(function () {
                $button.prop('disabled', false);
            });
    });

    $('.lha-ai-orphan-trigger[data-job-id!=""]').each(function () {
        startPolling($(this), $(this).attr('data-job-id'));
    });

    $(window).on('beforeunload', function () {
        Object.keys(pollTimers).forEach(function (key) {
            clearTimeout(pollTimers[key]);
        });
    });
})(jQuery);
