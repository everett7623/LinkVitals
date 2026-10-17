'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../src/assets/js/admin.js'), 'utf8');

// 执行真实页面脚本；仅替代浏览器、jQuery 与时间，不暴露内部 LHA 对象。
function createPage(status = 'running') {
    const document = {};
    const nodes = new Map();
    const requests = [];
    const timers = new Map();
    let now = 0;
    let timerId = 0;
    let reloads = 0;

    if (status !== 'other-page') {
        for (const selector of [
            '#lha-dashboard', '#lha-scan-progress', '#lha-btn-full-scan',
            '#lha-btn-incremental-scan', '#lha-btn-recheck-broken',
            '#lha-btn-pause', '#lha-btn-resume', '.lha-progress-fill',
            '#lha-progress-percentage', '#lha-progress-done', '#lha-progress-total'
        ]) {
            nodes.set(selector, { visible: true, handlers: {}, props: {} });
        }
        nodes.get('#lha-scan-progress').visible = status === 'running';
        nodes.get('#lha-btn-pause').visible = status === 'running';
        nodes.get('#lha-btn-resume').visible = status === 'paused';
    }

    function $(selector) {
        const selected = typeof selector === 'string'
            ? selector.split(',').map(key => nodes.get(key.trim())).filter(Boolean)
            : [];
        return {
            length: selected.length,
            on(event, handler) {
                if (typeof handler === 'function') {
                    selected.forEach(node => { node.handlers[event] = handler; });
                }
                return this;
            },
            ready(callback) { callback(); return this; },
            is(filter) {
                assert.equal(filter, ':visible');
                return selected.some(node => node.visible);
            },
            show() { selected.forEach(node => { node.visible = true; }); return this; },
            hide() { selected.forEach(node => { node.visible = false; }); return this; },
            prop(name, value) {
                selected.forEach(node => { node.props[name] = value; });
                return this;
            },
            css() { return this; },
            text() { return this; }
        };
    }

    $.ajax = options => {
        const request = { options, settled: false };
        requests.push(request);
        if (options.beforeSend) options.beforeSend();
        return request;
    };

    function addTimer(callback, delay, interval) {
        const id = ++timerId;
        timers.set(id, { callback, at: now + delay, interval });
        return id;
    }

    vm.runInNewContext(source, {
        document,
        jQuery: $,
        lhaAdmin: { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'test-nonce', i18n: {} },
        console,
        window: {},
        location: { reload() { reloads++; } },
        setTimeout: (callback, delay) => addTimer(callback, delay, 0),
        clearTimeout: id => timers.delete(id),
        setInterval: (callback, delay) => addTimer(callback, delay, delay),
        clearInterval: id => timers.delete(id)
    }, { filename: 'admin.js' });

    return {
        get reloads() { return reloads; },
        count(action) { return requests.filter(request => request.options.data.action === action).length; },
        pending(action) {
            return requests.filter(request => !request.settled && request.options.data.action === action);
        },
        visible(selector) { return nodes.get(selector).visible; },
        click(selector) {
            const node = nodes.get(selector);
            assert.equal(typeof node?.handlers.click, 'function', `${selector} 未绑定点击事件`);
            node.handlers.click.call(node);
        },
        respond(actionOrRequest, status, failed = false) {
            const request = typeof actionOrRequest === 'string'
                ? this.pending(actionOrRequest)[0]
                : actionOrRequest;
            assert.ok(request && !request.settled, '需要尚未结束的请求');
            request.settled = true;
            if (failed) {
                if (request.options.error) request.options.error({});
            } else if (request.options.success) {
                request.options.success({ success: true, data: { status } });
            }
            if (request.options.complete) request.options.complete();
        },
        advance(milliseconds) {
            const target = now + milliseconds;
            let iterations = 0;
            while (true) {
                const next = [...timers.entries()]
                    .filter(([, timer]) => timer.at <= target)
                    .sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
                if (!next) break;
                assert.ok(++iterations < 10000, '定时器出现无限循环');
                const [id, timer] = next;
                now = timer.at;
                if (timer.interval) timer.at += timer.interval;
                else timers.delete(id);
                timer.callback();
            }
            now = target;
        }
    };
}

const batch = 'lha_process_batch';
const progress = 'lha_scan_progress';

function startPage() {
    const page = createPage('idle');
    page.click('#lha-btn-full-scan');
    page.respond('lha_start_scan', 'started');
    return page;
}

test('运行中的仪表盘刷新后立即续跑，并维持一条批处理链', () => {
    const page = createPage();
    assert.equal(page.count(batch), 1);
    page.respond(batch, 'running');
    page.advance(1000);
    assert.equal(page.count(batch), 2);
    page.advance(8000);
    assert.equal(page.count(batch), 2);
    assert.equal(page.pending(batch).length, 1);
    assert.equal(page.count(progress), 1);
});
for (const status of ['paused', 'completed', 'idle', 'other-page']) {
    test(`${status} 页面初始化不会启动批处理或轮询`, () => {
        const page = createPage(status);
        page.advance(10000);
        assert.equal(page.count(batch), 0);
        assert.equal(page.count(progress), 0);
    });
}

test('暂停状态刷新后恢复扫描会显示进度条并启动批处理', () => {
    const page = createPage('paused');
    assert.equal(page.visible('#lha-scan-progress'), false);
    page.click('#lha-btn-resume');
    page.respond('lha_resume_scan', 'running');
    assert.equal(page.visible('#lha-scan-progress'), true);
    assert.equal(page.visible('#lha-btn-pause'), true);
    assert.equal(page.visible('#lha-btn-resume'), false);
    assert.equal(page.count(batch), 1);
    page.advance(3000);
    assert.equal(page.count(progress), 1);
});

test('暂停清除旧批次定时器，恢复后只执行一条处理链', () => {
    const page = startPage();
    page.respond(batch, 'running');
    page.click('#lha-btn-pause');
    page.respond('lha_pause_scan', 'paused');
    page.click('#lha-btn-resume');
    page.respond('lha_resume_scan', 'running');
    assert.equal(page.count(batch), 2);
    page.respond(batch, 'running');
    page.advance(1000);
    assert.equal(page.count(batch), 3);
    page.advance(1000);
    assert.equal(page.count(batch), 3);
    assert.equal(page.pending(batch).length, 1);
});

test('恢复等待旧批处理结束，并忽略其过期暂停状态', () => {
    const page = startPage();
    const oldBatch = page.pending(batch)[0];
    page.click('#lha-btn-pause');
    page.respond('lha_pause_scan', 'paused');
    page.click('#lha-btn-resume');
    page.respond('lha_resume_scan', 'running');
    page.advance(1000);
    assert.equal(page.count(batch), 1);
    page.respond(oldBatch, 'paused');
    assert.equal(page.visible('#lha-btn-pause'), true);
    assert.equal(page.visible('#lha-btn-resume'), false);
    page.advance(1000);
    assert.equal(page.count(batch), 2);
    assert.equal(page.pending(batch).length, 1);
});

test('暂停后的迟到批处理响应不会重启处理链', () => {
    const page = startPage();
    page.click('#lha-btn-pause');
    page.respond('lha_pause_scan', 'paused');
    page.respond(batch, 'running');
    page.advance(10000);
    assert.equal(page.count(batch), 1);
    assert.equal(page.count(progress), 0);
});

test('进度请求较慢或失败时不会重叠，结束后继续轮询', () => {
    const page = startPage();
    page.advance(15000);
    assert.equal(page.count(progress), 1);
    page.respond(progress, 'running');
    page.advance(3000);
    assert.equal(page.count(progress), 2);
    page.respond(progress, null, true);
    page.advance(3000);
    assert.equal(page.count(progress), 3);
    assert.equal(page.pending(progress).length, 1);
});

test('恢复后的旧进度响应不会覆盖当前状态或误触发刷新', () => {
    const page = startPage();
    page.advance(3000);
    const oldProgress = page.pending(progress)[0];
    page.click('#lha-btn-pause');
    page.respond('lha_pause_scan', 'paused');
    page.click('#lha-btn-resume');
    page.respond('lha_resume_scan', 'running');
    page.respond(oldProgress, 'completed');
    page.advance(3000);
    assert.equal(page.reloads, 0);
    assert.equal(page.count(progress), 2);
    assert.equal(page.visible('#lha-btn-pause'), true);
});

test('进度确认完成时清除待执行批次并仅刷新一次', () => {
    const page = startPage();
    page.advance(3000);
    page.respond(batch, 'running');
    page.respond(progress, 'completed');
    page.advance(10000);
    assert.equal(page.count(batch), 1);
    assert.equal(page.count(progress), 1);
    assert.equal(page.reloads, 1);
});

test('网络错误的延迟重试也受暂停清理约束', () => {
    const page = startPage();
    page.respond(batch, null, true);
    page.advance(1000);
    page.click('#lha-btn-pause');
    page.respond('lha_pause_scan', 'paused');
    page.advance(10000);
    assert.equal(page.count(batch), 1);
    assert.equal(page.count(progress), 0);
});

test('进度先确认暂停时，迟到的暂停响应不能覆盖后续恢复', () => {
    const page = startPage();
    page.advance(3000);
    page.click('#lha-btn-pause');
    const oldPause = page.pending('lha_pause_scan')[0];
    page.respond(progress, 'paused');
    page.respond(batch, 'paused');
    page.click('#lha-btn-resume');
    page.respond('lha_resume_scan', 'running');
    page.respond(oldPause, 'paused');
    assert.equal(page.visible('#lha-btn-pause'), true);
    assert.equal(page.visible('#lha-btn-resume'), false);
    page.respond(batch, 'running');
    page.advance(1000);
    assert.equal(page.count(batch), 3);
    assert.equal(page.pending(batch).length, 1);
});

test('较早的恢复响应不能覆盖后续暂停命令', () => {
    const page = createPage('paused');
    page.click('#lha-btn-resume');
    const oldResume = page.pending('lha_resume_scan')[0];
    page.click('#lha-btn-resume');
    page.respond(page.pending('lha_resume_scan')[1], 'running');
    page.click('#lha-btn-pause');
    page.respond('lha_pause_scan', 'paused');
    page.respond(oldResume, 'running');
    page.respond(batch, 'paused');
    page.advance(10000);
    assert.equal(page.visible('#lha-btn-pause'), false);
    assert.equal(page.visible('#lha-btn-resume'), true);
    assert.equal(page.count(batch), 1);
    assert.equal(page.count(progress), 0);
});
