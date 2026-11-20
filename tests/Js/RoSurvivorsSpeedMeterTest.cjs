const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname,
    '../../public/userscripts/ro-survivors-control.user.js'), 'utf8');
const begin = source.indexOf('  function updateSpeedMeter()');
const end = source.indexOf('  setInterval(', begin);
assert.ok(begin >= 0 && end > begin);

function environment() {
    const classes = new Set();
    const timer = {
        classList: { contains: value => classes.has(value) },
        closest: () => null, textContent: ''
    };
    const meter = {};
    const elements = { timer };
    const events = {};
    const doc = {
        getElementById: id => elements[id],
        addEventListener(type, fn) { events[type] = fn; }
    };
    let now = 0;
    const update = vm.runInNewContext('let samples = [];\n' + source.slice(begin, end) +
        '\nupdateSpeedMeter;', {
        document: doc, performance: { now: () => now }, state: {}, $: () => meter
    });
    return { classes, elements, events, timer,
        sample(time, text) {
            now = time;
            timer.textContent = text;
            update();
            return meter.textContent;
        }
    };
}

test('measures elapsed time and both countdowns without mixing clock phases', () => {
    const e = environment();
    assert.match(e.sample(0, '00:10'), /量測中/);
    assert.match(e.sample(5000, '00:20'), /2\.00×/);
    e.classes.add('mvp');
    assert.match(e.sample(6000, '00:32'), /量測中/);
    e.classes.add('loot'); // The game highlights the final 30 seconds of an MVP.
    assert.match(e.sample(11000, '00:27'), /1\.00×/);
    e.classes.delete('mvp');
    assert.match(e.sample(12000, '00:08'), /量測中/);
    assert.match(e.sample(17000, '00:03'), /1\.00×/);
    e.classes.clear();
    assert.match(e.sample(18000, '01:10'), /量測中/);
    assert.match(e.sample(23000, '01:15'), /1\.00×/);
    assert.match(e.sample(24000, '00:00'), /量測中/);
});

test('discards samples across pause, hidden HUD, and background tab transitions', () => {
    const e = environment();
    e.sample(0, '00:00');
    e.elements.pause = { closest: () => null };
    assert.match(e.sample(5000, '00:05'), /已暫停/);
    delete e.elements.pause;
    assert.match(e.sample(6000, '00:06'), /量測中/);
    e.timer.closest = () => ({});
    assert.match(e.sample(9000, '00:09'), /等待計時器/);
    e.timer.closest = () => null;
    assert.match(e.sample(11000, '00:11'), /量測中/);
    e.events.visibilitychange();
    assert.match(e.sample(17000, '00:17'), /量測中/);
    assert.match(e.sample(22000, '00:22'), /1\.00×/);
    delete e.elements.timer;
    assert.match(e.sample(23000, ''), /等待計時器/);
});
