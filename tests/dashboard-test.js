'use strict';

// Run the real dashboard against a small browser surface. Fetches stay
// pending until a scenario supplies a reply, making ordering repeatable.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const [file, scenario, argument] = process.argv.slice(2);
const requests = [];
const events = {};
const intervals = [];
const app = { innerHTML: '', querySelector: () => null };
const location = {
    pathname: '/clockwerk', search: scenario === 'actions' ? '?token=s3cret&view=kept' : '',
    hash: scenario === 'initial route' ? '#queue/' + argument : '#overview',
};
const stored = new Map();
const storage = { getItem: (key) => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, value) };
let replaced = '';
const context = {
    URLSearchParams, location, localStorage: storage, sessionStorage: storage,
    history: { replaceState: (data, title, url) => { replaced = url; } },
    document: {
        documentElement: { dataset: {} }, activeElement: null, hidden: false,
        getElementById: () => app, querySelector: () => null, querySelectorAll: () => [],
        addEventListener: (name, callback) => { events['document:' + name] = callback; },
    },
    window: {
        scrollY: 0, scrollTo: () => {},
        addEventListener: (name, callback) => { events['window:' + name] = callback; },
    },
    fetch: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject })),
    setInterval: (callback) => intervals.push(callback), setTimeout: () => 0, confirm: () => true,
};
vm.createContext(context);
const html = fs.readFileSync(file, 'utf8');
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
const end = script.lastIndexOf('})();');
// Expose existing functions only inside this isolated evaluation; the
// resource on disk stays untouched and its startup still runs normally.
const expose = 'globalThis.dashboard = { ui, refresh, queue, queueVerdict, exceptionRows, schedule, workers, overview };\n';
vm.runInContext(script.slice(0, end) + expose + script.slice(end), context);
const dashboard = context.dashboard;
const turn = () => new Promise((resolve) => setImmediate(resolve));
const measured = () => ({ total: 0, errors: 0, avg_ms: 0, max_ms: 0, rows: [], series: [] });
const state = (name = 'Shop') => ({
    app: name, now: 1000, since: 100, minutes: 15,
    requests: measured(),
    jobs: {
        ...measured(), installed: true, queues: [], supervisors: [], recent: [],
        failed: [], failed_total: 0, failed_more: false, list: [], list_state: '',
    },
    tasks: { installed: false, rows: [] }, workers: [], exceptions: [],
});
const counts = (name, more = {}) => ({
    name, pending: 0, processing: 0, delayed: 0, completed: 0, retried: 0,
    failed: 0, longest_wait: null, avg_ms: 0, ...more,
});
const response = async (index, value, status = 200) => {
    requests[index].resolve({ status, ok: status === 200, json: async () => value, text: async () => String(value) });
    await turn();
};
const click = async (dataset) => {
    const target = { dataset };
    await events['document:click']({
        target: { closest: (selector) => selector === 'button, a' ? null : target },
        stopPropagation: () => {},
    });
};
const keys = (markup) => Array.from(markup.matchAll(/data-open="([^"]+)"/g), (match) => match[1]);

async function run() {
    assert.equal(requests.length, 1, 'startup must ask once');
    if (scenario === 'framework error') {
        await response(0, JSON.stringify({ error: { status: 403, message: 'Configure the dashboard token.' } }), 403);
        assert.equal(dashboard.ui.error, 'Configure the dashboard token.');
        assert.match(app.innerHTML, /Configure the dashboard token\./);
        assert.doesNotMatch(app.innerHTML, /&quot;status&quot;/);
        return;
    }
    if (scenario === 'slow polling') {
        for (let tick = 0; tick < 4; ++tick) { intervals[0](); }
        assert.equal(requests.length, 1, 'polling must wait for the outstanding answer');
        await response(0, state('Slow answer'));
        assert.equal(dashboard.ui.state.app, 'Slow answer');
        intervals[0]();
        assert.equal(requests.length, 2, 'polling resumes after success');
        requests[1].reject(new Error('offline'));
        await turn();
        intervals[0]();
        assert.equal(requests.length, 3, 'polling resumes after failure');
        return;
    }
    if (scenario === 'latest answer' || scenario === 'older error') {
        const second = dashboard.refresh();
        await response(1, state('Newest'));
        await second;
        const fetched = dashboard.ui.fetchedAt;
        if (scenario === 'latest answer') {
            await response(0, state('Old'));
        } else {
            await response(0, 'Closed by an obsolete reply', 403);
        }
        assert.equal(dashboard.ui.state.app, 'Newest');
        assert.equal(dashboard.ui.error, null);
        assert.equal(dashboard.ui.gate, false);
        assert.equal(dashboard.ui.fetchedAt, fetched);
        assert.match(app.innerHTML, /Newest/);
        return;
    }
    if (scenario === 'initial route') {
        const tab = argument === 'unknown' ? 'overview' : argument;
        assert.equal(dashboard.ui.page, 'queue');
        assert.equal(dashboard.ui.queueTab, tab);
        const query = ['pending', 'processing', 'delayed'].includes(tab) ? '&list=' + tab : '';
        assert.equal(requests[0].url, '/clockwerk/api?minutes=60' + query);
        return;
    }
    await response(0, state());
    if (scenario === 'actions') {
        assert.equal(replaced, '/clockwerk?view=kept#overview');
        assert.equal(stored.get('clockwerk.token'), 's3cret');
        assert.equal(requests[0].options.headers.Authorization, 'Bearer s3cret');
        assert.doesNotMatch(requests[0].url, /token/);
        const retry = click({ job: 'retry:job:1' });
        assert.equal(requests[1].url, '/clockwerk/api/jobs/retry');
        assert.equal(requests[1].options.method, 'POST');
        assert.equal(requests[1].options.headers['Content-Type'], 'application/json');
        assert.equal(requests[1].options.headers.Authorization, 'Bearer s3cret');
        assert.deepEqual(JSON.parse(requests[1].options.body), { id: 'job:1' });
        await click({ job: 'retry:job:1' });
        assert.equal(requests.length, 2, 'a pending action prevents duplicate clicks');
        await response(1, { retried: 1 });
        await retry;
        assert.match(dashboard.ui.toast, /1 job put back/);
        assert.equal(dashboard.ui.busy, false);
        assert.equal(requests.length, 3, 'a completed action refreshes the state');
        await response(2, state());
        context.confirm = () => false;
        await click({ job: 'forget:all' });
        assert.equal(requests.length, 3, 'cancelling forget all changes nothing');
        context.confirm = () => true;
        const forget = click({ job: 'forget:all' });
        assert.equal(requests[3].url, '/clockwerk/api/jobs/forget');
        assert.deepEqual(JSON.parse(requests[3].options.body), { id: 'all' });
        await response(3, 'offline', 500);
        await forget;
        assert.equal(dashboard.ui.busy, false);
        assert.match(dashboard.ui.toast, /500/);
        return;
    }
    if (scenario === 'worker names') {
        const s = state();
        s.workers = [{ group_name: 'constructor', pid: 1, host: '<host>', started_at: 900, seen_at: 999, memory: 1000, handled: 1 }];
        assert.match(dashboard.overview(s), /constructor/);
        assert.match(dashboard.workers(s), /&lt;host&gt;/);
        return;
    }
    if (scenario === 'schedule') {
        const s = state();
        s.tasks = { installed: true, rows: [{ name: '<task>', when: '0 0 31 2 *', one_server: true, without_overlapping: true, next: null, next_error: '<impossible>', runs: 0, errors: 0 }] };
        const markup = dashboard.schedule(s);
        assert.match(markup, /No next run/);
        assert.match(markup, /without overlapping/);
        assert.match(markup, /&lt;task&gt;/);
        assert.match(markup, /&lt;impossible&gt;/);
        assert.doesNotMatch(markup, /while the scheduler runs/);
        return;
    }
    if (scenario === 'unserved queue') {
        const s = state();
        s.jobs.supervisors = [{ name: 'queue', queues: ['default'], workers: 1 }];
        s.jobs.queues = [counts('default'), counts('unserved', { pending: 1, longest_wait: 1 })];
        s.workers = [{ group_name: 'queue' }];
        const markup = dashboard.queue(s);
        assert.match(markup, /unserved<\/b> has no worker/);
        assert.doesNotMatch(markup, /Keeping up/);
        return;
    }
    if (scenario === 'navigation') {
        dashboard.ui.page = 'requests';
        dashboard.ui.filter = 'orders';
        dashboard.ui.shown.listed = 75;
        location.hash = '#queue/pending';
        events['window:hashchange']();
        assert.equal(dashboard.ui.page, 'queue');
        assert.equal(dashboard.ui.queueTab, 'pending');
        assert.equal(dashboard.ui.filter, '');
        assert.equal(dashboard.ui.shown.listed, 25);
        assert.equal(requests.length, 2);
        assert.equal(requests[1].url, '/clockwerk/api?minutes=60&list=pending');
        assert.match(app.innerHTML, /Loading…/);
        dashboard.ui.shown.listed = 50;
        events['window:hashchange']();
        assert.equal(requests.length, 2, 'same address must not ask again');
        assert.equal(dashboard.ui.shown.listed, 50);
        location.hash = '#queue/not-a-tab';
        events['window:hashchange']();
        assert.equal(dashboard.ui.queueTab, 'overview');
        location.hash = '#not-a-page';
        events['window:hashchange']();
        assert.equal(dashboard.ui.page, 'queue');
        return;
    }
    if (scenario === 'job list') {
        const s = state();
        s.jobs.queues = [counts('mail', { [argument]: 150 })];
        s.jobs.list_state = argument;
        s.jobs.list = Array.from({ length: 30 }, (_, i) => ({
            id: 'id-' + i, job: 'App\\Send', queue: 'mail', attempts: 2,
            available_at: argument === 'delayed' ? 1120 : 880,
            reserved_at: 880, data: { token: '(hidden)', label: '<safe>' },
        }));
        dashboard.ui.page = 'queue';
        dashboard.ui.queueTab = argument;
        dashboard.ui.state = s;
        let markup = dashboard.queue(s);
        assert.match(markup, new RegExp({ pending: 'Waiting', processing: 'Reservation age', delayed: 'Due in' }[argument]));
        assert.match(markup, />2m<\/td>/);
        assert.match(markup, /first 30 of 150/);
        assert.match(markup, /Show 5 more/);
        assert.equal(keys(markup).length, 25);
        await click({ more: 'listed' });
        markup = app.innerHTML;
        assert.equal(keys(markup).length, 30);
        await click({ open: keys(markup)[0] });
        assert.match(app.innerHTML, /class="detail"/);
        assert.match(app.innerHTML, /\(hidden\)/);
        assert.match(app.innerHTML, /&lt;safe&gt;/);
        s.jobs.list_state = '';
        assert.match(dashboard.queue(s), /Loading…/);
        s.jobs.list_state = argument;
        s.jobs.list = [];
        assert.match(dashboard.queue(s), new RegExp('Nothing ' + (argument === 'processing' ? 'running' : argument)));
        return;
    }
    if (scenario === 'recent identity') {
        const s = state();
        const job = { id: 'older', job: 'App\\Send', queue: 'mail', attempts: 1, at: 990, ms: 1, outcome: 'completed', error: null };
        s.jobs.recent = [job];
        dashboard.ui.queueTab = 'recent';
        const key = keys(dashboard.queue(s))[0];
        dashboard.ui.open.add(key);
        s.jobs.recent.unshift({ ...job, id: 'newer', at: 991 });
        let markup = dashboard.queue(s);
        assert.equal(keys(markup)[1], key);
        assert.match(markup, /<dd class="mono">older<\/dd>/);
        s.jobs.recent.unshift({ ...job, at: 992 });
        markup = dashboard.queue(s);
        assert.notEqual(keys(markup)[0], keys(markup)[2], 'different tries of the same id need different identities');
        return;
    }
    if (scenario === 'exception identity') {
        const s = state();
        const older = { at: 990, title: 'older', detail: { class: 'LogicException', during: 'GET /old', message: 'Older message', file: 'old.php', line: 1, trace: [] } };
        const key = keys(dashboard.exceptionRows(s, [older], 'e'))[0];
        dashboard.ui.open.add(key);
        const newer = { ...older, detail: { ...older.detail, during: 'GET /new', message: 'Newer message' } };
        const markup = dashboard.exceptionRows(s, [newer, older], 'e');
        assert.equal(keys(markup)[1], key);
        assert.notEqual(keys(markup)[0], key);
        assert.match(markup, /<dd>Older message<\/dd>/);
        assert.doesNotMatch(markup, /<dd>Newer message<\/dd>/);
        return;
    }
    if (scenario === 'worker groups') {
        const s = state();
        s.jobs.queues = ['mail', 'rest', 'default'].map((name) => counts(name));
        s.jobs.supervisors = [
            { name: 'mail', queues: ['mail'], workers: 1 },
            { name: 'rest', queues: ['rest'], workers: 2 },
            { name: 'queue', queues: ['default'], workers: 1 },
        ];
        s.workers = [{ group_name: 'queue:mail+rest' }, { group_name: 'queue' }];
        const markup = dashboard.queue(s);
        assert.equal((markup.match(/>1\/1<\/span>/g) || []).length, 2);
        assert.match(markup, />1\/2<\/span>/);
        assert.match(markup, /rest<\/b> has 1 of 2 workers/);
        assert.doesNotMatch(markup, /has no worker/);
        assert.equal((markup.match(/<td class="r num">1<\/td>/g) || []).length, 3);
        return;
    }
    if (scenario === 'queue overview') {
        const s = state();
        s.jobs.failed_total = 2;
        s.jobs.failed_more = true;
        s.jobs.queues = [counts('mail', { pending: 3, processing: 4, delayed: 5, completed: 10, failed: 99, retried: 7 })];
        const markup = dashboard.queue(s);
        for (const tab of ['overview', 'pending', 'processing', 'delayed', 'recent', 'failed']) {
            assert.match(markup, new RegExp('href="#queue/' + tab + '"'));
        }
        const failed = markup.match(/<div class="label">Failed<\/div><div class="value num">([\s\S]*?)<\/div>/)[1];
        assert.match(failed, />2\+</);
        assert.doesNotMatch(failed, /99/);
        assert.match(markup, /Pending[^]*Processing[^]*Delayed/);
        assert.match(markup, /Throughput/);
        assert.match(markup, /<h2>Jobs<\/h2>/);
        return;
    }
    if (scenario === 'queue verdict') {
        const s = state();
        s.jobs.supervisors = [{ name: 'mail', queues: ['mail'], workers: 1 }];
        s.jobs.queues = [counts('mail', { pending: 1, processing: 2, longest_wait: 600 }), counts('bulk', { longest_wait: 70, completed: 7, retried: 2, failed: 1 })];
        s.jobs.failed_total = 200;
        s.jobs.failed_more = true;
        const markup = dashboard.queueVerdict(s, {});
        assert.match(markup, /verdict bad/);
        assert.match(markup, /mail<\/b> has no worker · 3 waiting/);
        assert.doesNotMatch(markup, /mail<\/b> is behind/);
        assert.match(markup, /bulk<\/b> is behind · oldest waited 1m/);
        assert.match(markup, /bulk<\/b> · 30% of tries fail/);
        assert.match(markup, /href="#queue\/failed">200\+ failed/);
        assert.match(dashboard.queueVerdict(state(), {}), /Keeping up/);
        const stalled = state();
        stalled.jobs.queues = [counts('default', { pending: 1 })];
        assert.match(dashboard.queueVerdict(stalled, {}), /No worker is running/);
        return;
    }
    if (scenario === 'recent and failed') {
        const s = state();
        s.jobs.recent = ['completed', 'retrying', 'failed'].map((outcome, i) => ({ id: String(i), job: 'Send', queue: 'mail', attempts: 1, at: 990, ms: 2, outcome, error: outcome === 'completed' ? null : '<failure>' }));
        dashboard.ui.queueTab = 'recent';
        let markup = dashboard.queue(s);
        assert.equal(keys(markup).length, 3);
        assert.match(markup, /Will retry/);
        assert.match(markup, /&lt;failure&gt;/);
        dashboard.ui.jobFilter = 'failed';
        assert.equal(keys(dashboard.queue(s)).length, 1);
        dashboard.ui.queueTab = 'failed';
        assert.equal(dashboard.ui.shown.failed, 25);
        s.jobs.failed = [{ id: 'failed-1', job: 'Send', queue: 'mail', attempts: 3, failed_at: 990, error: 'failed', data: {} }];
        markup = dashboard.queue(s);
        for (const action of ['retry:failed-1', 'forget:failed-1', 'retry:all', 'forget:all']) {
            assert.match(markup, new RegExp('data-job="' + action + '"'));
        }
        s.jobs.failed = Array.from({ length: 30 }, (_, i) => ({ ...s.jobs.failed[0], id: 'failed-' + i }));
        markup = dashboard.queue(s);
        assert.equal(keys(markup).length, 25);
        assert.match(markup, /Show 5 more/);
        s.jobs.failed = [];
        markup = dashboard.queue(s);
        assert.match(markup, /Nothing failed/);
        assert.doesNotMatch(markup, /data-job=/);
        return;
    }
    assert.fail('Unknown dashboard scenario: ' + scenario);
}

run().then(() => process.stdout.write('passed\n')).catch((error) => {
    process.stderr.write(error.stack + '\n');
    process.exitCode = 1;
});
