const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(`${__dirname}/../../public/js/adsense.js`, 'utf8');

function setup(widths = []) {
    const events = {};
    const slots = widths.map(width => {
        const unit = { dataset: {}, width, getBoundingClientRect() { return { width: this.width }; } };
        return { unit, querySelector: () => unit };
    });
    const requests = [];
    const context = {
        document: {
            readyState: 'complete',
            querySelectorAll: () => slots,
            addEventListener: (name, callback) => { events[name] = callback; },
        },
        window: {
            adsbygoogle: { push: () => requests.push('request') },
            addEventListener: (name, callback) => { events[name] = callback; },
        },
    };
    vm.runInNewContext(source, context);
    return { events, slots, requests };
}

test('mobile requests the article-end ad without requesting the hidden sidebar', () => {
    const { requests, slots } = setup([350, 0]);
    assert.equal(requests.length, 1);
    assert.equal(slots[1].unit.dataset.manualRequested, undefined);
});

test('desktop initializes two slots only once across repeated ready and resize events', () => {
    const { events, requests } = setup([728, 300]);
    events['amanda:content-ready']();
    events.resize();
    assert.equal(requests.length, 2);
});

test('sidebar can initialize after resizing from mobile to desktop without refreshing the first ad', () => {
    const { events, requests, slots } = setup([350, 0]);
    slots[1].unit.width = 300;
    events.resize();
    events.resize();
    assert.equal(requests.length, 2);
});

test('Vue content arriving after the deferred script is initialized by content-ready', () => {
    const { events, requests, slots } = setup();
    const unit = { dataset: {}, getBoundingClientRect: () => ({ width: 728 }) };
    slots.push({ querySelector: () => unit });
    events['amanda:content-ready']();
    assert.equal(requests.length, 1);
});
