const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname,
    '../../public/userscripts/ro-survivors-control.user.js'), 'utf8');
const begin = source.indexOf('  function createLootAudit(');
const end = source.indexOf('  function installStatBoosts(', begin);
assert.ok(begin >= 0 && end > begin);
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };

function environment() {
    let now = 0, reply = {}, status = 200, reject = null;
    const calls = [];
    const window = {
        fetch(...args) {
            const response = {
                status, ok: status < 400,
                json() { throw Error('The observer must not consume the original body'); },
                clone() { const value = reply; return { json: async () => value }; }
            };
            const promise = reject ? Promise.reject(reject) : Promise.resolve(response);
            calls.push({ args, receiver: this, promise, response });
            return promise;
        }
    };
    const original = window.fetch;
    const create = vm.runInNewContext(source.slice(begin, end) + '\ncreateLootAudit;', {
        window, document: { baseURI: 'https://ro-survivors.com/' },
        location: { origin: 'https://ro-survivors.com' },
        URL, performance: { now: () => now }
    });
    const settings = { speed: 10, discovery: 0, attack: 1, cooldown: 0 };
    const audit = create(() => settings, () => {});
    audit.start();
    return {
        window, original, audit, calls, settings,
        set now(value) { now = value; },
        set reply(value) { reply = value; },
        set status(value) { status = value; },
        set reject(value) { reject = value; },
        async send(body, endpoint = 'op') {
            const init = { method: 'POST', body: JSON.stringify(body),
                headers: { 'x-session': 'SECRET-TOKEN' } };
            const promise = window.fetch(`https://ro-survivors.com/api/${endpoint}`, init);
            assert.equal(promise, calls.at(-1).promise);
            assert.equal(calls.at(-1).args[1], init);
            assert.equal(calls.at(-1).receiver, window);
            const response = await promise;
            assert.equal(response, calls.at(-1).response);
            await flush();
            return response;
        },
        data() { return JSON.parse(JSON.stringify(audit.exportData())); }
    };
}

test('compares the full seeded payload with actual grants and real elapsed time', async () => {
    const e = environment();
    e.reply = { cap: { mob: 20, card: 2, boss: { boss: 1 } }, seed: 123 };
    await e.send({ key: 'run-secret', stage: 'prontera', mult: 2.5 }, 'run');
    e.now = 5000;
    e.reply = {};
    await e.send({ op: 'tick', rk: { key: 'run-secret', secs: 50,
        kills: { poring: 100 }, dr: { poring: [[1], [], [0]] } } });
    e.now = 90000;
    e.reply = { granted: { gear: [{ itemId: 1201, quality: 3, src: 'poring' }],
        cards: [4001], eggs: [] }, doc: { secret: 'ACCOUNT-DOC' } };
    const dr = { poring: [[1, 6, 10], [3], [0, 100, 200]] };
    await e.send({ op: 'loot', key: 'run-secret', secs: 900, disc: 0.1,
        kills: { poring: 300 }, dr });
    const run = e.data().runs[0], event = run.events.at(-1);
    assert.equal(run.completeStart, true);
    assert.deepEqual(run.cap, { mob: 20, card: 2, boss: { boss: 1 } });
    assert.equal(run.seeded, true);
    assert.equal(event.atSeconds, 90);
    assert.equal(event.gameSeconds, 900);
    assert.equal(event.sent.gear, 3);
    assert.equal(event.sent.cards, 1);
    assert.equal(event.sent.maxGearIndex, 10);
    assert.equal(event.granted.gear, 1);
    assert.deepEqual(event.granted.missingSources, [
        { source: 'poring', sent: 3, granted: 1, missing: 2 }
    ]);
    assert.deepEqual(event.sent.sources[0].discovery, [0, 100, 200]);
    assert.deepEqual(JSON.parse(e.calls.at(-1).args[1].body).dr, dr);
    assert.match(e.audit.summary(), /送出 3 件／核發 1（少：poring×2） · 最大序號 #10/);
    assert.doesNotMatch(JSON.stringify(e.data()), /run-secret|SECRET-TOKEN|ACCOUNT-DOC/);
});

test('supports save heartbeats and legacy count records without assuming a known start', async () => {
    const e = environment();
    await e.send({ rk: { key: 'late', secs: 300, dr: { poring: [4, 2] } },
        doc: { private: 'NO-EXPORT' } }, 'save');
    const run = e.data().runs[0];
    assert.equal(run.completeStart, false);
    assert.equal(run.events[0].sent.gear, 4);
    assert.equal(run.events[0].sent.cards, 2);
    assert.match(e.audit.summary(), /記錄期間/);
    assert.doesNotMatch(JSON.stringify(e.data()), /NO-EXPORT/);
});

test('marks resumed runs and does not call absent or unknown drop data zero', async () => {
    const e = environment();
    await e.send({ key: 'resume', stage: 'prontera', played: 120 }, 'run');
    await e.send({ op: 'loot', key: 'resume', secs: 500, dr: { poring: 'new-format' } });
    const run = e.data().runs[0];
    assert.equal(run.completeStart, false);
    assert.equal(run.events.at(-1).sent, null);
    assert.match(e.audit.summary(), /送出 — 件／核發 未確認/);
});

test('distinguishes mail, group settlement and HTTP failures from zero equipment', async () => {
    const e = environment();
    e.reply = { granted: { mail: true } };
    await e.send({ op: 'loot', key: 'mail', dr: {} });
    assert.match(e.audit.summary(), /郵差領取/);
    e.reply = { view: { drops: [1, 2] } };
    await e.send({ op: 'gloot', key: 'group', dr: {} });
    assert.match(e.audit.summary(), /組隊分配未追蹤/);
    e.status = 429;
    e.reply = { error: 'slow' };
    await e.send({ op: 'loot', key: 'error', dr: {} });
    assert.match(e.audit.summary(), /429 slow/);
    assert.equal(e.data().runs.at(-1).events[0].status, 'http-error');
});

test('records transport errors while preserving the original rejected promise', async () => {
    const e = environment(), error = new Error('network down');
    e.reject = error;
    await assert.rejects(e.send({ op: 'loot', key: 'fail' }), value => value === error);
    await flush();
    assert.equal(e.data().runs[0].events[0].status, 'network-error');
});

test('ignores other origins, unrelated operations, malformed bodies and Request bodies', async () => {
    const e = environment();
    await e.window.fetch('https://example.com/api/run', {
        body: JSON.stringify({ key: 'secret' }) });
    await e.send({ op: 'sell', key: 'secret' });
    await e.window.fetch('/api/op', { body: 'invalid json' });
    await e.window.fetch({ url: 'https://ro-survivors.com/api/op', body: {} });
    assert.equal(e.calls.length, 4);
    assert.equal(e.data().runs.length, 0);
});

test('bounded history, duplicate installation and restore leave later wrappers intact', async () => {
    const e = environment(), wrapper = e.window.fetch;
    e.audit.start();
    assert.equal(e.window.fetch, wrapper);
    for (let i = 0; i < 13; i++) await e.send({ key: `run-${i}` }, 'run');
    for (let i = 0; i < 125; i++) await e.send({ op: 'tick', rk: { key: 'run-12' } });
    const runs = e.data().runs;
    assert.equal(runs.length, 12);
    assert.equal(runs[0].id, 2);
    assert.equal(runs.at(-1).events.length, 120);
    assert.equal(runs.at(-1).omittedEvents, 6);
    e.audit.stop();
    assert.equal(e.window.fetch, e.original);
    e.audit.start();
    const observer = e.window.fetch;
    const laterWrapper = function(...args) { return observer.apply(this, args); };
    e.window.fetch = laterWrapper;
    e.audit.stop();
    assert.equal(e.window.fetch, laterWrapper);
    await e.send({ key: 'after-stop' }, 'run');
    assert.equal(e.data().runs.at(-1).id, 13);
    e.audit.start();
    await e.send({ key: 'after-restart' }, 'run');
    assert.equal(e.data().runs.at(-1).id, 14);
    assert.equal(e.data().runs.at(-1).events.length, 1);
});

test('reading a cloned native Response leaves the original JSON readable', async () => {
    const window = { fetch: () => Promise.resolve(Response.json({ granted: {
        gear: [{ itemId: 1, quality: 2 }], cards: [], eggs: []
    } })) };
    const create = vm.runInNewContext(source.slice(begin, end) + '\ncreateLootAudit;', {
        window, document: { baseURI: 'https://ro-survivors.com/' },
        location: { origin: 'https://ro-survivors.com' }, URL, performance
    });
    let updated;
    const observed = new Promise(resolve => { updated = resolve; });
    const audit = create(() => ({}), () => {
        if (audit.exportData().runs[0]?.events[0]?.granted) updated();
    });
    audit.start();
    const response = await window.fetch('/api/op', {
        method: 'POST', body: JSON.stringify({ op: 'loot', key: 'test', dr: {} })
    });
    assert.equal((await response.json()).granted.gear.length, 1);
    await observed;
    assert.equal(audit.exportData().runs[0].events[0].granted.gear, 1);
});

test('mysteryDrop throttles accelerated mob drops by auto wall-clock cap or manual mobDropCap without blocking bosses', () => {
    const clampBegin = source.indexOf('  const clamp = ');
    const clampEnd = source.indexOf('  // Observe the current game', clampBegin);
    const hookBegin = source.indexOf('  function patchHiddenMethod(');
    const hookEnd = source.indexOf('  function classifyTimer(', hookBegin);
    assert.ok(clampBegin >= 0 && clampEnd > clampBegin);
    assert.ok(hookBegin >= 0 && hookEnd > hookBegin);

    const installGameHook = vm.runInNewContext(
        source.slice(clampBegin, clampEnd) + '\n' +
        source.slice(hookBegin, hookEnd) + '\ninstallGameHook;',
        { performance: { now: () => 0 } }
    );

    const hooks = { start: [], tick: [], hurt: [], damageMult: [] };
    const state = {
        speed: 20, discovery: 0, money: 1, cooldown: 0, targets: 1,
        pickup: 1, suction: 1, god: false, fullHp: false, mobDropCap: 0, limited: 0
    };
    installGameHook(hooks, state, () => {});

    const dropCalls = [];
    const game = {
        player: { dead: false, x: 0, y: 0 },
        stats: { discovery: 0, zenyMult: 1, pickupRadius: 80, maxHp: 100 },
        runCtx: { locals: new Map() },
        weapons: [],
        pickups: [],
        killsBy: new Map(),
        state: 'playing',
        time: 0,
        recomputeStats() {},
        advance(dt) { this.time += dt; },
        updatePickups() {},
        buffValue() { return 0; },
        mysteryDrop(enemy) { dropCalls.push(`${enemy.def.id}:${this.killsBy.get(enemy.def.id)}`); }
    };

    hooks.start[0](game);

    // At 20x speed (est 45s wall-clock), auto cap is 45 * 4 = 180.
    game.killsBy.set('poring', 180);
    game.mysteryDrop({ def: { id: 'poring', boss: false } });
    game.killsBy.set('poring', 181);
    game.mysteryDrop({ def: { id: 'poring', boss: false } });

    // Boss drops are never suppressed regardless of kill index.
    game.killsBy.set('eddga', 500);
    game.mysteryDrop({ def: { id: 'eddga', boss: 'mvp' } });

    // Manual override lowers cap immediately to 100.
    state.mobDropCap = 100;
    game.killsBy.set('lunatic', 100);
    game.mysteryDrop({ def: { id: 'lunatic', boss: false } });
    game.killsBy.set('lunatic', 101);
    game.mysteryDrop({ def: { id: 'lunatic', boss: false } });

    assert.deepEqual(dropCalls, ['poring:180', 'eddga:500', 'lunatic:100']);
});

test('clampAcceleratedKills caps per-mob and total kills while preserving reported drop indices', () => {
    const clampBegin = source.indexOf('  const clamp = ');
    const clampEnd = source.indexOf('  // Observe the current game', clampBegin);
    const fnBegin = source.indexOf('  function clampAcceleratedKills(');
    const fnEnd = source.indexOf('  function normalizeAcceleratedRunTime(', fnBegin);
    assert.ok(fnBegin >= 0 && fnEnd > fnBegin);

    const state = { speed: 20, mobDropCap: 0 };
    const clampAcceleratedKills = vm.runInNewContext(
        source.slice(clampBegin, clampEnd) + '\n' +
        source.slice(fnBegin, fnEnd) + '\nclampAcceleratedKills;',
        { state }
    );

    const target = {
        kills: {
            poring: 900,
            lunatic: 800,
            fabre: 700,
            pupa: 600,
            drops: 500
        },
        dr: {
            poring: [[12, 160], [], [100, 100]],
            lunatic: [[220], [], [100]]
        }
    };

    // At 45s wall-clock: perMobCap = 180, totalKillBudget = 540.
    const changed = clampAcceleratedKills(target, 45);
    assert.equal(changed, true);
    assert.ok(target.kills.poring >= 160);
    assert.equal(target.kills.lunatic, 220);
    const total = Object.values(target.kills).reduce((a, b) => a + b, 0);
    assert.ok(total <= 540, `expected total kills <= 540, got ${total}`);
    for (const [id, count] of Object.entries(target.kills)) {
        assert.ok(count >= 1, `expected ${id} kills >= 1, got ${count}`);
    }
});
