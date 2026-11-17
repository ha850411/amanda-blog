const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname,
    '../../public/userscripts/ro-survivors-control.user.js'), 'utf8');
const begin = source.indexOf('  function createLobbyNavigator(');
const end = source.indexOf('  // adc0fdf6 的一般單人難度', begin);
assert.ok(begin >= 0 && end > begin);
const create = vm.runInNewContext(source.slice(begin, end) + '\ncreateLobbyNavigator;');
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };

function environment({ loader, onTap } = {}) {
    let now = 0, lobby = true, overlay = false;
    const taps = [], down = [], sheet = {}, originalPoint = { x: -1, y: -1 };
    class World {
        constructor() {
            this.map = { name: 'prontera_plaza' };
            this.target = { x: 100, z: 200 };
        }
        render() { return 'rendered'; }
        pickGround() { return originalPoint; }
    }
    const originalRender = World.prototype.render;
    const world = new World();
    const captain = { x: 900, y: 750, children: [{ sheet }] };
    const layer = { children: [{ x: 1, y: 1, children: [{ sheet: {} }] }, captain] };
    const stage = { dispatchEvent(event) { down.push(event); } };
    const win = {
        PointerEvent: class { constructor(type, args) { this.type = type; Object.assign(this, args); } },
        dispatchEvent(event) {
            assert.equal(event.type, 'pointerup');
            assert.equal(event.pointerType, 'touch');
            const point = world.pickGround(event.clientX, event.clientY);
            taps.push({ x: point.x, y: point.y });
            onTap?.();
        }
    };
    const doc = {
        hidden: false, defaultView: win,
        body: { classList: { contains: name => lobby && name === 'in-lobby' } },
        getElementById: id => id === 'stage' ? stage : null,
        querySelector: () => overlay ? {} : null
    };
    const module = { ed: World, cn: { sheetInfo: name => name === 'npc_knight' ? sheet : null } };
    const navigation = create(loader ?? (() => Promise.resolve(module)), doc, () => now);
    return {
        navigation, world, World, layer, captain, taps, down, doc, module,
        originalRender, originalPoint,
        set now(value) { now = value; },
        set lobby(value) { lobby = value; },
        set overlay(value) { overlay = value; },
        render() { return world.render(layer); },
        async connect() { navigation.step(); await flush(); this.render(); }
    };
}

test('targets the rendered captain through native input without moving the player directly', async () => {
    const e = environment();
    await e.connect();
    assert.equal(e.render(), 'rendered');
    assert.match(e.navigation.step(), /自動走向/);
    assert.deepEqual(e.taps, [{ x: 900, y: 750 }]);
    assert.deepEqual(e.world.target, { x: 100, z: 200 });
    assert.equal(e.world.pickGround(), e.originalPoint);
    assert.equal(Object.hasOwn(e.world, 'pickGround'), false);
    assert.equal(e.down[0].pointerType, 'touch');
    e.navigation.step();
    assert.equal(e.taps.length, 1, 'does not reset the route on every tick');
    e.now = 1800;
    e.world.target.x = 300;
    e.render();
    e.navigation.step();
    assert.deepEqual(e.taps[1], { x: 900, y: 750 });
    e.navigation.stop();
    assert.deepEqual(e.taps[2], { x: 300, y: 200 }, 'stop cancels with current ground position');
    assert.equal(e.World.prototype.render, e.originalRender);
});

test('does not issue movement outside town, behind a dialog or in a hidden tab', async () => {
    const e = environment();
    await e.connect();
    e.overlay = true;
    e.navigation.step();
    e.overlay = false;
    e.doc.hidden = true;
    e.navigation.step();
    e.doc.hidden = false;
    e.lobby = false;
    e.navigation.step();
    assert.equal(e.taps.length, 0);
    e.navigation.stop();
});

test('waits for fresh plaza actors and never targets an unrelated knight sprite', async () => {
    const e = environment();
    e.captain.children = [{ sheet: {} }];
    await e.connect();
    assert.match(e.navigation.step(), /定位/);
    assert.equal(e.taps.length, 0);
    e.navigation.stop();
    const stale = environment();
    await stale.connect();
    stale.now = 2000;
    assert.match(stale.navigation.step(), /定位/);
    assert.equal(stale.taps.length, 0);
    stale.navigation.stop();
});

test('reports a blocked route after twelve seconds without progress', async () => {
    const e = environment();
    await e.connect();
    e.navigation.step();
    e.now = 12500;
    e.render();
    assert.throws(() => e.navigation.step(), /沒有進展/);
    e.navigation.stop();
    assert.equal(e.World.prototype.render, e.originalRender);
});

test('stopping while import is pending prevents a late render hook', async () => {
    let resolve;
    const pending = new Promise(done => { resolve = done; });
    const e = environment({ loader: () => pending });
    e.navigation.step();
    e.navigation.stop();
    resolve(e.module);
    await flush();
    assert.equal(e.World.prototype.render, e.originalRender);
    assert.equal(e.taps.length, 0);
});

test('restores ground picking even if an input listener throws', async () => {
    const e = environment({ onTap: () => { throw Error('listener failed'); } });
    await e.connect();
    assert.throws(() => e.navigation.step(), /listener failed/);
    assert.equal(e.world.pickGround(), e.originalPoint);
    assert.equal(Object.hasOwn(e.world, 'pickGround'), false);
    e.navigation.stop(false);
});

test('leaving town restores the hook without issuing another movement command', async () => {
    const e = environment();
    await e.connect();
    e.navigation.step();
    e.overlay = true;
    e.navigation.stop(false);
    assert.equal(e.taps.length, 1);
    assert.equal(e.World.prototype.render, e.originalRender);
});
