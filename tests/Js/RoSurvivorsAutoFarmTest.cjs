const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../public/userscripts/ro-survivors-control.user.js'), 'utf8');
const begin = source.indexOf('  const farmDifficultyFields =');
const end = source.indexOf("  const host = document.createElement('div');", begin);
assert.ok(begin >= 0 && end > begin);
const createAutoFarm = vm.runInNewContext(
    source.slice(begin, end) + '\ncreateAutoFarm;', { URL }
);

function environment({ screen = 'settlement', label = '單人大廳', dismiss = true,
    soloDisabled = false, chooser = true, extraButton = false,
    captainNear = true, festivalNear = false, stagesLoaded = true,
    stage = '0', navigation = null } = {}) {
    let now = 0, returned = 0, soloClicks = 0, publicClicks = 0, launches = 0, captainClicks = 0;
    let festivalClicks = 0, festivalPlays = 0, latestStages = [];
    const node = (properties = {}) => ({
        isConnected: true, disabled: false, textContent: '',
        closest: () => null, getClientRects: () => [{}], getAttribute: () => null,
        classList: { contains: () => false }, click() {}, ...properties
    });
    const solo = node({ textContent: label, disabled: soloDisabled,
        click() { soloClicks++; if (dismiss) screen = 'lobby'; } });
    const publicLobby = node({ textContent: '阿斯嘉特', click() { publicClicks++; } });
    const restart = node({ textContent: '返回角色', click() { returned++; screen = 'chooser'; } });
    const captain = node({ textContent: '與隊長交談', click() { captainClicks++; screen = 'stages'; } });
    const festivalHost = node({ textContent: '與主持人交談', click() { festivalClicks++; screen = 'festival'; } });
    const festivalPlay = node({ textContent: '開始遊戲', click() { festivalPlays++; screen = 'festival-diff'; } });
    const card = node({ dataset: { i: '0' }, classList: { contains: name => name === 'sel' },
        querySelector: () => ({ textContent: '斐揚洞穴' }) });
    const go = node({ click() { screen = 'difficulty'; } });
    const launch = node({ click() { launches++; screen = 'playing'; } });
    const sliders = ['count', 'damage', 'hp', 'speed', 'events'].map(key => node({ dataset: { key } }));
    const bank = node({ classList: { contains: name => name === 'active' } });
    const doc = {
        scripts: [{ src: 'https://ro-survivors.com/build/main-DyDJZBsa.js' }],
        body: { classList: { contains: name => name === 'in-lobby' && screen === 'lobby' } },
        querySelector(selector) {
            if (selector === '#gameover') return screen === 'settlement' ? node() : null;
            if (selector === '#btn-restart') return restart;
            if (selector === '#npc-dialog') return ['chooser', 'difficulty', 'festival-diff'].includes(screen) ? node() : null;
            if (selector === '#npc-text .lb-pick') return screen === 'chooser' && chooser ? node() : null;
            if (selector === '#npc-text .diff') return ['difficulty', 'festival-diff'].includes(screen) ? node() : null;
            if (selector === '#npc-name') return { textContent: '選擇大廳' };
            if (selector === '#lobby-prompt') return festivalNear ? festivalHost : captainNear ? captain : null;
            if (selector === '#stages') return screen === 'stages' ? node() : null;
            if (selector === '#festival') return screen === 'festival' ? node() : null;
            if (selector === '#festival-play') return festivalPlay;
            if (selector === '#stg-go') return go;
            if (selector === '#npc-options button.primary') return launch;
            if (selector === '#npc-text .diff [data-bank="1"]') return bank;
            if (selector === '#hud') return screen === 'playing' ? node() : null;
            return null;
        },
        querySelectorAll(selector) {
            if (selector === '#stg-list .stg-card') return stagesLoaded ? [card] : [];
            if (selector === '#npc-options button') return extraButton ? [solo, publicLobby] : [solo];
            if (selector === '#npc-text .diff input[data-key]') return screen === 'festival-diff' ? [] : sliders;
            if (selector === '#npc-text [data-lb]') return [publicLobby];
            if (selector === '.overlay') return screen === 'unknown' ? [node()] : [];
            return [];
        }
    };
    const config = { stage, difficultyMode: 'current', immediateCards: false, autoPick: false };
    const farm = createAutoFarm(config, () => {}, list => { latestStages = list; }, doc, () => now, navigation);
    return {
        farm, solo, config,
        tick(ms = 2000) { now += ms; farm.tick(); },
        set screen(value) { screen = value; }, get screen() { return screen; },
        set captainNear(value) { captainNear = value; },
        set festivalNear(value) { festivalNear = value; },
        set stagesLoaded(value) { stagesLoaded = value; },
        get returned() { return returned; }, get soloClicks() { return soloClicks; },
        get publicClicks() { return publicClicks; }, get launches() { return launches; },
        get captainClicks() { return captainClicks; },
        get festivalClicks() { return festivalClicks; },
        get festivalPlays() { return festivalPlays; },
        get latestStages() { return latestStages; }
    };
}

for (const scanOnly of [true, false]) {
    test(`${scanOnly ? 'read stages' : 'auto farm'} walks to captain before opening the menu`, () => {
        let steps = 0, stops = 0;
        const navigation = {
            step() { steps++; return '正在自動走向遠征隊長…'; },
            stop() { stops++; }
        };
        const e = environment({ screen: 'lobby', captainNear: false,
            stagesLoaded: false, navigation });
        e.farm.start(scanOnly);
        assert.equal(e.farm.running, true);
        assert.equal(steps, 1);
        assert.equal(e.captainClicks, 0);
        e.captainNear = true;
        e.tick();
        assert.equal(e.captainClicks, 1);
        assert.ok(stops >= 2, 'resets navigation on start and stops at the captain');
        e.stagesLoaded = true;
        e.tick();
        if (scanOnly) {
            assert.equal(e.farm.running, false);
            assert.match(e.farm.status, /關卡已更新/);
            assert.equal(e.launches, 0);
        } else {
            e.tick();
            assert.equal(e.screen, 'playing');
            assert.equal(e.launches, 1);
        }
    });
}

test('navigation failure stops automation and releases navigation', () => {
    let stops = 0;
    const e = environment({ screen: 'lobby', captainNear: false, navigation: {
        step() { throw Error('路線受阻'); }, stop() { stops++; }
    } });
    e.farm.start(true);
    assert.equal(e.farm.running, false);
    assert.match(e.farm.status, /路線受阻/);
    assert.equal(e.captainClicks, 0);
    assert.equal(stops, 2);
});

test('navigation times out when the captain never appears', () => {
    const e = environment({ screen: 'lobby', captainNear: false,
        navigation: { step: () => '定位中', stop() {} } });
    e.farm.start(true);
    e.tick(46000);
    assert.equal(e.farm.running, false);
    assert.match(e.farm.status, /逾時/);
});

test('settlement -> solo lobby -> captain -> stage -> next run, for two rounds', () => {
    const env = environment();
    env.farm.start();
    assert.equal(env.returned, 0, 'wait for settlement grace period');
    for (let round = 1; round <= 2; round++) {
        env.tick();
        assert.equal(env.screen, 'chooser');
        env.tick();
        assert.equal(env.screen, 'lobby');
        env.tick();
        assert.equal(env.screen, 'stages');
        env.tick();
        assert.equal(env.screen, 'difficulty');
        env.tick();
        assert.equal(env.screen, 'playing');
        assert.equal(env.launches, round);
        assert.equal(env.soloClicks, round);
        assert.equal(env.publicClicks, 0);
        assert.equal(env.farm.running, true);
        env.tick();
        env.screen = 'settlement';
        env.tick();
    }
});

for (const label of ['單人大廳', 'Solo lobby', 'Lobby solo']) {
    test(`handles lobby selector when starting: ${label}`, () => {
        const env = environment({ screen: 'chooser', label });
        env.farm.start();
        assert.equal(env.soloClicks, 1);
        assert.equal(env.publicClicks, 0);
        assert.equal(env.farm.running, true);
    });
}

test('read stages mode also passes through solo lobby and then stops at stages', () => {
    const env = environment({ screen: 'chooser' });
    env.farm.start(true);
    env.tick();
    env.tick();
    assert.equal(env.soloClicks, 1);
    assert.equal(env.captainClicks, 1);
    assert.equal(env.launches, 0);
    assert.equal(env.farm.running, false);
    assert.match(env.farm.status, /關卡已更新/);
});

test('an unresponsive chooser is clicked once and times out', () => {
    const env = environment({ screen: 'chooser', dismiss: false });
    env.farm.start();
    env.tick(); env.tick();
    assert.equal(env.soloClicks, 1);
    env.tick(16000);
    assert.equal(env.soloClicks, 1);
    assert.equal(env.farm.running, false);
    assert.match(env.farm.status, /沒有回應/);
});

test('waits for a disabled solo button to become enabled', () => {
    const env = environment({ screen: 'chooser', soloDisabled: true });
    env.farm.start();
    assert.equal(env.soloClicks, 0);
    env.solo.disabled = false;
    env.tick();
    assert.equal(env.soloClicks, 1);
});

test('a permanently disabled button times out without clicking', () => {
    const env = environment({ screen: 'chooser', soloDisabled: true });
    env.farm.start();
    env.tick(16000);
    assert.equal(env.soloClicks, 0);
    assert.equal(env.farm.running, false);
});

for (const overrides of [{ chooser: false }, { label: '刪除角色' }, { extraButton: true }]) {
    test(`does not click an unrelated or ambiguous dialog: ${JSON.stringify(overrides)}`, () => {
        const env = environment({ screen: 'chooser', ...overrides });
        env.farm.start();
        assert.equal(env.soloClicks, 0);
        assert.equal(env.publicClicks, 0);
        assert.equal(env.farm.running, false);
    });
}

test('does not handle dialogs when automation is stopped', () => {
    const env = environment({ screen: 'chooser' });
    env.tick();
    assert.equal(env.soloClicks, 0);
});

test('unknown overlays continue to stop automation', () => {
    const env = environment({ screen: 'unknown' });
    env.farm.start();
    assert.equal(env.farm.running, false);
    assert.equal(env.soloClicks, 0);
});

test('includes festival stage in stage list without requiring reading captain stages first', () => {
    const targets = [];
    const navigation = {
        step(target) { targets.push(target); return '正在自動走向節慶主持人…'; },
        stop() {}
    };
    const env = environment({
        screen: 'lobby', captainNear: false, festivalNear: false,
        stagesLoaded: false, stage: 'louyang', navigation
    });
    env.farm.refreshStages();
    assert.ok(env.latestStages.some(s => s.id === 'louyang'));
    env.farm.start(false);
    assert.equal(env.farm.running, true);
    assert.deepEqual(targets, ['festival']);
    env.festivalNear = true;
    env.tick();
    assert.equal(env.festivalClicks, 1);
    assert.equal(env.screen, 'festival');
    env.tick();
    assert.equal(env.festivalPlays, 1);
    assert.equal(env.screen, 'festival-diff');
    env.tick();
    assert.equal(env.launches, 1);
    assert.equal(env.screen, 'playing');
});
