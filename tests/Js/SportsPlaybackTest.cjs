const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
    constructor() { this.dataset = {}; this.listeners = {}; this.textContent = ''; this.children = []; this.classList = { add() {}, toggle() {}, remove() {} }; }
    addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
    dispatchEvent(event) { for (const fn of this.listeners[event.type] || []) fn(event); return !event.defaultPrevented; }
    setAttribute(name, value) { this[name] = value; }
    getAttribute(name) { return this[name]; }
    removeAttribute(name) { delete this[name]; }
    querySelectorAll() { return []; }
    replaceChildren(...children) { this.children = children; }
    append(...children) { this.children.push(...children); }
    focus() {}
}
class CustomEvent {
    constructor(type, options = {}) { this.type = type; Object.assign(this, options); }
    preventDefault() { if (this.cancelable) this.defaultPrevented = true; }
}
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
const rootPath = path.join(__dirname, '../../public/js');
function environment() {
    const elements = new Map();
    const el = id => { if (!elements.has(id)) elements.set(id, new Element()); return elements.get(id); };
    let nextTimer = 0;
    const timers = new Map();
    const document = { getElementById: el, createElement: () => new Element(), querySelectorAll: () => [], addEventListener() {} };
    const window = { addEventListener() {} };
    const context = vm.createContext({ window, document, URL, CustomEvent, AbortController, queueMicrotask,
        location: { origin: 'http://localhost:8900', href: 'http://localhost:8900/sports' },
        history: { pushState() {}, replaceState() {} },
        MutationObserver: class { observe() {} disconnect() {} }, performance: { now: () => 10000 },
        setTimeout(fn, delay) { timers.set(++nextTimer, { fn, delay }); return nextTimer; },
        clearTimeout(id) { timers.delete(id); }
    });
    return { el, window, context, timers, run(delay) { for (const [id, task] of [...timers]) if (task.delay === delay) { timers.delete(id); task.fn(); } } };
}
function playerEnvironment(adapter = 'gks') {
    const env = environment(), { window, el } = env;
    const arts = [], streams = [];
    window.Hls = class {
        static isSupported() { return true; }
        static Events = { MANIFEST_PARSED: 'manifest', AUDIO_TRACKS_UPDATED: 'audio', LEVEL_SWITCHED: 'level', ERROR: 'error' };
        static ErrorTypes = { NETWORK_ERROR: 'network', MEDIA_ERROR: 'media' };
        static ErrorDetails = { MANIFEST_LOAD_ERROR: 'manifest-error', MANIFEST_LOAD_TIMEOUT: 'manifest-timeout' };
        constructor(config) { this.config = config; this.listeners = {}; this.levels = [{ height: 540, bitrate: 700000 }, { height: 1080, bitrate: 8000000 }]; streams.push(this); }
        on(event, fn) { (this.listeners[event] ||= []).push(fn); }
        emit(event, data) { for (const fn of this.listeners[event] || []) fn(event, data); }
        attachMedia(video) { this.media = video; }
        loadSource() { this.emit('manifest'); }
        destroy() { this.destroyed = true; }
        stopLoad() { this.stopped = true; }
        startLoad() { this.stopped = false; }
    };
    window.GksMedia = { loader: () => class {} };
    window.artplayerI18nZhTw = {};
    window.Artplayer = class {
        constructor(options) {
            arts.push(this); this.listeners = {}; this.video = new Element();
            Object.assign(this.video, { paused: false, readyState: 4, seeking: false, videoHeight: 1080 });
            this.layers = { 'play-prompt': {} }; this.loading = {}; this.notice = {};
            const items = new Map(options.settings.map(item => [item.name, item]));
            this.setting = { items, update(item) { items.set(item.name, item); }, add(item) { items.set(item.name, item); }, find(name) { return items.get(name); }, remove(name) { items.delete(name); } };
            options.customType['sports-hls'](this.video, options.url, this);
        }
        on(event, fn) { this.listeners[event] = fn; }
        emit(event) { this.listeners[event]?.(); }
        play() { return Promise.resolve(); }
        destroy() { this.destroyed = true; }
    };
    el('sports-player').dataset = { mode: 'hls', mediaAdapter: adapter };
    vm.runInContext(fs.readFileSync(path.join(rootPath, 'sports-live-player.js'), 'utf8'), env.context);
    window.SportsLivePlayer.load({ url: '/sports/player/' + 'a'.repeat(64) + '/hls/playlist', adapter }, 'Test match');
    return { ...env, arts, streams };
}

test('GKS retains fixed quality, normal playback speed, without increasing live distance or buffer targets', async () => {
    const { streams } = playerEnvironment(); await flush();
    const hls = streams[0];
    assert.equal(hls.loadLevel, 1);
    assert.equal(hls.config.maxLiveSyncPlaybackRate, 1);
    assert.equal(hls.config.lowLatencyMode, false);
    assert.ok(hls.config.liveMaxLatencyDurationCount > hls.config.liveSyncDurationCount);
    assert.equal(hls.config.maxBufferLength, 12);
    assert.equal(hls.config.maxMaxBufferLength, 24);
    assert.equal(hls.config.liveSyncDurationCount, 3);
    assert.equal(hls.config.liveMaxLatencyDurationCount, 6);
    assert.equal(hls.config.liveSyncOnStallIncrease, 0);
    assert.equal(hls.config.preserveManualLevelOnError, true);
    assert.equal(hls.config.minAutoBitrate, 8000000);
    assert.equal(hls.autoLevelCapping, 1);
    assert.ok(hls.config.fragLoadPolicy.default.maxLoadTimeMs < 15000);
});

test('a long GKS stall keeps the selected source and quality', async () => {
    const env = playerEnvironment(); await flush(); const art = env.arts[0]; let fallbacks = 0;
    env.el('sports-player').addEventListener('sports:unstable', () => fallbacks++);
    art.emit('video:playing'); art.video.readyState = 2; art.emit('video:waiting');
    env.run(8000); await flush();
    assert.equal(fallbacks, 0);
    assert.equal(env.streams.length, 1); assert.equal(env.streams[0].destroyed, undefined);
    assert.equal(env.streams[0].loadLevel, 1);
    assert.match(env.el('player-feedback').textContent, /保留所選畫質/);
    art.emit('video:playing'); assert.equal(env.el('player-feedback').textContent, '');
});

test('initial loading, seek, brief buffering, and paused playback do not switch sources', async () => {
    for (const action of ['initial', 'seek', 'recovered', 'paused', 'replaced']) {
        const env = playerEnvironment(); await flush(); const art = env.arts[0]; let count = 0;
        env.el('sports-player').addEventListener('sports:unstable', () => count++);
        if (action !== 'initial') art.emit('video:playing');
        art.video.readyState = 2; art.video.seeking = action === 'seek'; art.emit('video:waiting');
        if (action === 'recovered') { art.video.readyState = 4; art.emit('video:playing'); }
        if (action === 'paused') art.emit('video:pause');
        if (action === 'replaced') env.window.SportsLivePlayer.clear();
        env.run(8000); assert.equal(count, 0, action);
    }
});

test('manual quality choice pins both bounds; an unexpected quality switch fails visibly', async () => {
    const env = playerEnvironment(); await flush();
    env.arts[0].setting.items.get('stream-quality').onSelect({ value: 0 }); await flush();
    const hls = env.streams[1];
    assert.equal(hls.loadLevel, 0); assert.equal(hls.autoLevelCapping, 0);
    assert.equal(hls.config.minAutoBitrate, 700000);
    hls.emit('level', { level: 1 });
    assert.equal(hls.destroyed, true);
    assert.match(env.el('player-message').textContent, /所選畫質暫時無法播放/);
});

test('pausing stops downloads and source replacement destroys the previous loader owner', async () => {
    const env = playerEnvironment(); await flush(); const art = env.arts[0], old = env.streams[0];
    art.emit('video:pause'); assert.equal(old.stopped, true);
    art.emit('video:play'); assert.equal(old.stopped, false);
    env.window.SportsLivePlayer.load({ url: '/sports/player/' + 'b'.repeat(64) + '/hls/playlist', adapter: 'gks' }, 'Next');
    await flush(); assert.equal(old.destroyed, true); assert.equal(art.destroyed, true);
    assert.equal(env.streams[1].loadLevel, 1);
});

test('hub retains selected source when notified of instability', async () => {
    const env = environment(), loads = [];
    const gks = { url: 'https://embed.st/embed/admin/test/1', platform: 'GKS', language: 'English', number: 1, playback: { url: '/sports/player/a/hls/playlist', adapter: 'gks' } };
    const otherGks = { ...gks, url: 'https://embed.st/embed/hotel/test/1', playback: { url: '/sports/player/b/hls/playlist', adapter: 'gks' } };
    const backup = { ...gks, url: 'https://mlbwebcast.com/stream/rays.html', platform: 'MLBWebcast', playback: { url: '/sports/player/c/hls/playlist', adapter: '' } };
    const data = { match: { translated_title: 'Same match' }, streams: [gks, otherGks, backup] };
    env.window.SportsLivePlayer = { clear() {}, load(source) { loads.push(source.url); } };
    env.context.fetch = async () => ({ ok: true, json: async () => data });
    env.el('sports-hub').dataset = { initialEvent: 'test-match', eventBase: '/sports/events' };
    vm.runInContext(fs.readFileSync(path.join(rootPath, 'sports-hub.js'), 'utf8'), env.context); await flush();
    assert.deepEqual(loads, [gks.playback.url]);
    const event = new CustomEvent('sports:unstable', { cancelable: true, detail: { sourceUrl: gks.playback.url } });
    env.el('sports-player').dispatchEvent(event);
    assert.equal(event.defaultPrevented, undefined);
    assert.deepEqual(loads, [gks.playback.url]);
    env.el('sports-player').dispatchEvent(new CustomEvent('sports:playback', { detail: { playing: true } }));
    assert.match(env.el('hub-status').textContent, /正在播放所選來源/);
});
