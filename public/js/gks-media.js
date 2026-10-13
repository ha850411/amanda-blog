(function (root) {
    'use strict';
    function unwrap(data) {
        if (!(data instanceof ArrayBuffer) || data.byteLength < 12) return data;
        const bytes = new Uint8Array(data), view = new DataView(data);
        const tag = (p, text) => [...text].every((c, i) => bytes[p + i] === c.charCodeAt(0));
        if (!tag(0, 'RIFF') || !tag(8, 'WEBP')) return data;
        const limit = view.getUint32(4, true) + 8;
        // HOTEL carries TS after a complete small WebP image; ADMIN uses EXIF.
        const tail = bytes.length - limit;
        if (limit >= 20 && limit < bytes.length && tail >= 564 && tail % 188 === 0 && [0, 188, 376].every(n => bytes[limit + n] === 0x47)) {
            return data.slice(limit);
        }
        if (limit !== bytes.length) throw new Error('Invalid media container size');
        for (let p = 12; p + 8 <= limit;) {
            const size = view.getUint32(p + 4, true), begin = p + 8, end = begin + size;
            if (end > limit) throw new Error('Truncated media container');
            if (tag(p, 'EXIF') && size >= 564 && size % 188 === 0 && [0, 188, 376].every(n => bytes[begin + n] === 0x47)) {
                return data.slice(begin, end);
            }
            p = end + size % 2;
        }
        throw new Error('Unsupported media container');
    }
    // One class per player: retries share a small, bounded hedge budget.
    function loader(Hls, { smart = true } = {}) {
        let lastHedge = -Infinity;
        const hedged = new Set();
        const penalties = new Map();
        return class extends Hls.DefaultConfig.loader {
            constructor(config) {
                super(config);
                this.attempts = [];
                this.done = false;
                this.response = null;
            }
            load(context, config, callbacks) {
                if (this.context || this.done) throw new Error('Loader can only be used once.');
                this.context = context;
                this.host = new URL(context.url).hostname;
                this.callbacks = callbacks;
                this.policy = config.loadPolicy;
                this.stats.loading.start = performance.now();
                this.stats.gks = { requests: 0, hedged: false, winner: -1, bytes: 0 };
                this.deadline = setTimeout(() => this.finishError('timeout'), Math.min(12000, this.policy.maxLoadTimeMs));
                this.request();
                this.watch();
            }
            watch() {
                if (this.done || this.attempts.length !== 1) return;
                const first = this.attempts[0], now = performance.now();
                // XHR's onProgress callback is only delivered at completion in
                // HLS 1.7.3. Reading the Fetch stream detects mid-body stalls.
                const stalled = now - first.progressAt >= 1500;
                const slow = now - first.started >= Math.max(2500, Math.min(4000, (this.context.frag?.duration || 4) * 1000));
                if ((stalled || slow) && this.canHedge()) this.request();
                if (!this.done && this.attempts.length === 1) this.watchTimer = setTimeout(() => this.watch(), 250);
            }
            canHedge() {
                const now = performance.now(), url = this.context.url;
                if (!smart || this.attempts.length !== 1 || hedged.has(url) || now - lastHedge < 5000) return false;
                if ((penalties.get(this.host)?.until || 0) > now) {
                    this.stats.gks.suppressed = true;
                    return false;
                }
                lastHedge = now;
                hedged.add(url);
                if (hedged.size > 32) hedged.delete(hedged.values().next().value);
                this.stats.gks.hedged = true;
                return true;
            }
            async request() {
                const context = this.context;
                const index = this.attempts.length;
                const attempt = { controller: new AbortController(), loaded: 0, started: performance.now(), failed: false };
                attempt.progressAt = attempt.started;
                this.attempts.push(attempt);
                this.stats.gks.requests++;
                attempt.headerTimer = setTimeout(() => {
                    attempt.timedOut = true;
                    attempt.controller.abort();
                }, Math.min(5000, this.policy.maxTimeToFirstByteMs || 5000));
                try {
                    const headers = new Headers(context.headers);
                    if (context.rangeEnd) headers.set('Range', `bytes=${context.rangeStart || 0}-${context.rangeEnd - 1}`);
                    // A hedge bypasses the browser cache's in-flight request
                    // coalescing. Keep the signed URL and CORS rules unchanged.
                    const response = attempt.response = await fetch(context.url, {
                        headers, signal: attempt.controller.signal,
                        credentials: 'same-origin', cache: index ? 'no-store' : 'default'
                    });
                    clearTimeout(attempt.headerTimer);
                    if (this.done) { attempt.controller.abort(); return; }
                    if (!response.ok) throw Object.assign(new Error('Media HTTP ' + response.status), { code: response.status });
                    attempt.first = performance.now();
                    if (!this.stats.loading.first) this.stats.loading.first = attempt.first;
                    // Fetch exposes decoded bytes; compressed Content-Length is not their size.
                    const encoded = response.headers.get('Content-Encoding');
                    const total = encoded && encoded !== 'identity' ? 0 : Number(response.headers.get('Content-Length')) || 0;
                    const limit = 16 * 1024 * 1024;
                    if (total > limit) throw new Error('Media segment exceeds size limit');
                    const chunks = [];
                    const accept = value => {
                        attempt.loaded += value.byteLength;
                        this.stats.gks.bytes += value.byteLength;
                        if (attempt.loaded > limit) throw new Error('Media segment exceeds size limit');
                        attempt.progressAt = performance.now();
                        this.stats.loaded = Math.max(this.stats.loaded, attempt.loaded);
                        this.stats.total = Math.max(this.stats.total, total, attempt.loaded);
                        chunks.push(value);
                    };
                    if (response.body) {
                        const reader = response.body.getReader();
                        try {
                            while (!this.done) {
                                const { value, done } = await reader.read();
                                if (this.done) return;
                                if (done) break;
                                accept(value);
                            }
                        } finally { reader.releaseLock(); }
                    } else {
                        const data = await response.arrayBuffer();
                        if (this.done) return;
                        accept(new Uint8Array(data));
                    }
                    if (this.done) return;
                    if (!attempt.loaded || (total && attempt.loaded !== total)) throw new Error('Truncated media segment');
                    const bytes = new Uint8Array(attempt.loaded);
                    let offset = 0;
                    for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
                    const data = unwrap(bytes.buffer);
                    // Only a complete, validated response wins; never concatenate
                    // bytes from different attempts or deliver wrapped progress.
                    this.done = true;
                    this.response = response;
                    penalties.delete(this.host);
                    this.cleanup();
                    const stats = this.stats;
                    stats.aborted = false;
                    stats.loaded = stats.total = attempt.loaded;
                    stats.loading.end = performance.now();
                    stats.bwEstimate = stats.total * 8000 / Math.max(1, stats.loading.end - stats.loading.start);
                    stats.gks.winner = index;
                    const callbacks = this.callbacks;
                    callbacks.onProgress?.(stats, context, data, response);
                    // onProgress can synchronously stop or destroy the player.
                    if (this.callbacks === callbacks) callbacks.onSuccess({ url: response.url || context.url, data, code: response.status }, stats, context, response);
                } catch (error) {
                    if (this.done) return;
                    clearTimeout(attempt.headerTimer);
                    attempt.failed = true;
                    attempt.controller.abort();
                    const terminal = error.code >= 400 && error.code < 500 && error.code !== 408 && error.code !== 429;
                    if (!terminal && this.canHedge()) this.request();
                    if (this.attempts.every(item => item.failed)) this.finishError(attempt.timedOut ? 'timeout' : 'error', error, attempt.response);
                }
            }
            cleanup() {
                clearTimeout(this.deadline);
                clearTimeout(this.watchTimer);
                for (const attempt of this.attempts) {
                    clearTimeout(attempt.headerTimer);
                    attempt.controller.abort();
                }
            }
            finishError(kind, error, details) {
                if (this.done) return;
                this.done = true;
                this.cleanup();
                if (this.stats.gks.hedged) {
                    const failures = (penalties.get(this.host)?.failures || 0) + 1;
                    penalties.set(this.host, { failures, until: failures >= 2 ? performance.now() + 30000 : 0 });
                    if (penalties.size > 4) penalties.delete(penalties.keys().next().value);
                }
                if (kind === 'timeout') this.callbacks.onTimeout(this.stats, this.context, details || null);
                else this.callbacks.onError({ code: error?.code || 0, text: error?.message || 'Media download failed' }, this.context, details || null, this.stats);
            }
            abort() {
                const pending = !this.done && this.context;
                this.done = true;
                this.cleanup();
                if (pending) {
                    this.stats.aborted = true;
                    this.callbacks?.onAbort?.(this.stats, this.context, this.response);
                }
                this.callbacks = null;
            }
            destroy() {
                this.done = true;
                this.cleanup();
                this.callbacks = this.context = this.response = null;
            }
            getCacheAge() {
                const age = this.getResponseHeader('age');
                return age === null ? null : Number(age);
            }
            getResponseHeader(name) { return this.response?.headers.get(name) ?? null; }
        };
    }
    const api = { unwrap, loader };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.GksMedia = api;
})(typeof window === 'undefined' ? {} : window);
