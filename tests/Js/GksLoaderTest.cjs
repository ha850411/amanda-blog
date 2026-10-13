const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
function environment(smart = true) {
    let now = 100, id = 0;
    const timers = new Map(), calls = [], module = { exports: {} };
    // Use the exact pinned loader's stats contract, including nested objects.
    const vendor = { module: { exports: {} }, exports: {}, self: {}, console };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/vendor/hls/hls-1.7.3.min.js'), 'utf8'), vendor);
    const context = { module, ArrayBuffer, Uint8Array, DataView, AbortController, Headers, URL,
        performance: { now: () => now },
        setTimeout(fn, ms) { timers.set(++id, { at: now + ms, fn }); return id; },
        clearTimeout(key) { timers.delete(key); },
        fetch(url, options) {
            return new Promise((resolve, reject) => {
                const call = { url, options, resolve, reject, pending: null, queue: [] };
                options.signal.addEventListener('abort', () => {
                    call.aborted = true;
                    reject(new Error('Aborted'));
                    call.pending?.reject(new Error('Aborted'));
                });
                calls.push(call);
            });
        }
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/js/gks-media.js'), 'utf8'), context);
    const Loader = module.exports.loader(vendor.module.exports, { smart });
    function start(url = 'https://media.test/signed.image?token=unchanged', extra = {}) {
        const loader = new Loader({}), events = [], stats = loader.stats;
        const callbacks = Object.fromEntries(['onSuccess', 'onError', 'onTimeout', 'onAbort', 'onProgress'].map(name => [name, (...args) => events.push({ name, args })]));
        loader.load({ url, responseType: 'arraybuffer', frag: { duration: 4 }, ...extra },
            { loadPolicy: { maxLoadTimeMs: 12000, maxTimeToFirstByteMs: 5000 } }, callbacks);
        return { loader, events, stats, callbacks };
    }
    const headers = async (i, { status = 200, length, age, encoding } = {}) => {
        const call = calls[i];
        const values = new Headers();
        if (length) values.set('content-length', length);
        if (age) values.set('age', age);
        if (encoding) values.set('content-encoding', encoding);
        call.resolve({ ok: status >= 200 && status < 300, status, url: call.url, headers: values,
            body: { getReader: () => ({ read: () => new Promise((resolve, reject) => {
                if (call.queue.length) resolve(call.queue.shift());
                else call.pending = { resolve, reject };
            }), releaseLock() {} }) }
        });
        await flush();
    };
    const push = async (i, value, done = false) => {
        const call = calls[i], result = { value, done };
        if (call.pending) { const pending = call.pending; call.pending = null; pending.resolve(result); }
        else call.queue.push(result);
        await flush();
    };
    const complete = async (i, data) => { await push(i, new Uint8Array(data)); await push(i, undefined, true); };
    const tick = async ms => {
        const end = now + ms;
        while (true) {
            const next = [...timers].filter(([, t]) => t.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
            if (!next) break;
            timers.delete(next[0]); now = next[1].at; next[1].fn(); await flush();
        }
        now = end; await flush();
    };
    return { start, calls, timers, headers, push, complete, tick };
}
function media(valid = true) {
    const bytes = new Uint8Array(20 + 188 * 3), view = new DataView(bytes.buffer);
    bytes.set(Buffer.from('RIFF'), 0); view.setUint32(4, bytes.length - 8, true);
    bytes.set(Buffer.from('WEBPEXIF'), 8); view.setUint32(16, bytes.length - 20, true);
    if (valid) [20, 208, 396].forEach(i => bytes[i] = 0x47);
    return bytes.buffer;
}
const names = result => result.events.map(event => event.name);

test('healthy segment is fetched once, unwrapped before progress, and retains stats identity', async () => {
    const env = environment(), result = env.start();
    await env.headers(0, { length: media().byteLength, age: 3 }); await env.complete(0, media());
    await env.tick(20000);
    assert.deepEqual(names(result), ['onProgress', 'onSuccess']);
    assert.equal(result.events[0].args[2].byteLength, 564);
    assert.equal(result.events[1].args[1], result.stats);
    assert.equal(result.loader.stats, result.stats);
    assert.equal(env.calls.length, 1); assert.equal(env.timers.size, 0);
    assert.equal(result.loader.getCacheAge(), 3);
});

test('a mid-body stall hedges unchanged URL, validates the winner, and cancels original', async () => {
    const env = environment(), result = env.start();
    await env.headers(0); await env.push(0, new Uint8Array(media()).slice(0, 100));
    await env.tick(1500);
    assert.equal(env.calls.length, 2);
    assert.equal(env.calls[0].url, env.calls[1].url);
    assert.equal(env.calls[1].options.cache, 'no-store');
    assert.equal(env.calls[0].aborted, undefined);
    await env.headers(1); await env.complete(1, media());
    assert.equal(env.calls[0].aborted, true);
    assert.deepEqual(names(result), ['onProgress', 'onSuccess']);
    assert.equal(result.stats.gks.winner, 1);
    assert.equal(result.stats.gks.bytes, 100 + media().byteLength);
    assert.equal(result.stats.aborted, false); assert.equal(env.timers.size, 0);
});

test('original may still win after hedge; loser can never publish a second result', async () => {
    const env = environment(), result = env.start();
    await env.headers(0); await env.tick(1500); await env.headers(1);
    await env.complete(0, media()); await env.complete(1, media());
    assert.equal(result.stats.gks.winner, 0); assert.equal(env.calls[1].aborted, true);
    assert.deepEqual(names(result), ['onProgress', 'onSuccess']);
});

test('continuous slow progress also triggers one bounded hedge', async () => {
    const env = environment(), result = env.start(); await env.headers(0);
    for (let i = 0; i < 4; i++) { await env.tick(1000); await env.push(0, new Uint8Array([1])); }
    assert.equal(env.calls.length, 2); result.loader.destroy();
    assert.equal(env.timers.size, 0);
});

test('both stalled bodies share an absolute 12-second deadline with one terminal callback', async () => {
    const env = environment(), result = env.start();
    await env.headers(0); await env.tick(1500); await env.headers(1); await env.tick(10500);
    assert.deepEqual(names(result), ['onTimeout']); assert.equal(env.calls.length, 2);
    assert.ok(env.calls.every(call => call.aborted)); assert.equal(env.timers.size, 0);
});

test('missing headers time out, and ordinary 403 is never hedged', async () => {
    const env = environment(), result = env.start(); await env.tick(6500);
    assert.deepEqual(names(result), ['onTimeout']); assert.equal(env.calls.length, 2);
    const other = environment(), denied = other.start(); await other.headers(0, { status: 403 });
    assert.deepEqual(names(denied), ['onError']); assert.equal(other.calls.length, 1);
});

test('malformed fast response cannot beat a complete valid response', async () => {
    const env = environment(), result = env.start();
    await env.headers(0); await env.tick(1500); await env.headers(1); await env.complete(1, media(false));
    assert.deepEqual(names(result), []); assert.equal(env.calls[0].aborted, undefined);
    await env.complete(0, media()); assert.equal(result.stats.gks.winner, 0);
});

test('abort/destroy cancel both requests, timers and late callbacks', async () => {
    for (const operation of ['abort', 'destroy']) {
        const env = environment(), result = env.start();
        await env.headers(0); await env.tick(1500); await env.headers(1);
        result.loader[operation](); await flush(); await env.tick(20000);
        assert.deepEqual(names(result), operation === 'abort' ? ['onAbort'] : []);
        assert.ok(env.calls.every(call => call.aborted)); assert.equal(env.timers.size, 0);
    }
});

test('retry of same URL cannot hedge again and different fragments share cooldown', async () => {
    const env = environment(); const first = env.start(); await env.tick(1500); first.loader.abort();
    const retry = env.start(); await env.headers(2); await env.tick(5000);
    assert.equal(env.calls.length, 3); retry.loader.abort();
    const next = env.start('https://media.test/next'); await env.tick(1500);
    assert.equal(env.calls.length, 5); next.loader.abort();
    const close = env.start('https://media.test/close'); await env.headers(5); await env.tick(1500);
    assert.equal(env.calls.length, 6); close.loader.abort();
});

test('range/header contract is preserved and truncated/oversize responses are rejected', async () => {
    const env = environment(false), result = env.start(undefined, { rangeStart: 10, rangeEnd: 20, headers: { 'X-Test': 'yes' } });
    assert.equal(env.calls[0].options.headers.get('Range'), 'bytes=10-19');
    assert.equal(env.calls[0].options.headers.get('X-Test'), 'yes');
    await env.headers(0, { length: 800 }); await env.complete(0, media());
    assert.deepEqual(names(result), ['onError']);
    const huge = environment(false), tooBig = huge.start(); await huge.headers(0, { length: 17 * 1024 * 1024 });
    assert.deepEqual(names(tooBig), ['onError']); assert.equal(huge.calls[0].aborted, true);
});

test('destroying from progress suppresses success; control mode never hedges', async () => {
    const env = environment(false), result = env.start();
    await env.headers(0); await env.tick(6000); assert.equal(env.calls.length, 1);
    result.callbacks.onProgress = () => result.loader.destroy();
    await env.complete(0, media()); assert.deepEqual(names(result), []);
});

test('decoded compressed response is accepted; an empty response is rejected', async () => {
    const env = environment(false), good = env.start();
    await env.headers(0, { length: 100, encoding: 'gzip' }); await env.complete(0, media());
    assert.deepEqual(names(good), ['onProgress', 'onSuccess']);
    const other = environment(false), empty = other.start(); await other.headers(0); await other.complete(0, new ArrayBuffer(0));
    assert.deepEqual(names(empty), ['onError']);
});

test('two failed hedges pause only that host for 30 seconds, then permit a probe', async () => {
    const env = environment();
    for (let i = 0; i < 2; i++) {
        env.start('https://slow.test/' + i); await env.headers(i * 2);
        await env.tick(1500); await env.headers(i * 2 + 1); await env.tick(10500);
    }
    const paused = env.start('https://slow.test/third'); await env.headers(4); await env.tick(1500);
    assert.equal(env.calls.length, 5); assert.equal(paused.stats.gks.suppressed, true); paused.loader.abort();
    const other = env.start('https://healthy.test/first'); await env.headers(5); await env.tick(1500);
    assert.equal(env.calls.length, 7); other.loader.abort();
    await env.tick(27000);
    const probe = env.start('https://slow.test/probe'); await env.headers(7); await env.tick(1500);
    assert.equal(env.calls.length, 9); probe.loader.abort();
});

test('successful primary clears host backoff without changing source or quality', async () => {
    const env = environment();
    for (let i = 0; i < 2; i++) {
        env.start('https://slow.test/' + i); await env.headers(i * 2);
        await env.tick(1500); await env.headers(i * 2 + 1); await env.tick(10500);
    }
    env.start('https://slow.test/recovered'); await env.headers(4); await env.complete(4, media());
    const next = env.start('https://slow.test/next'); await env.headers(5); await env.tick(1500);
    assert.equal(env.calls.length, 7); next.loader.abort();
});
