const { test } = require('node:test');
const assert = require('node:assert/strict');
const adapter = { exports: {} };
require('node:vm').runInNewContext(require('node:fs').readFileSync(require('node:path').join(__dirname, '../../public/js/gks-media.js'), 'utf8'), {
    module: adapter, ArrayBuffer, Uint8Array, DataView
});
const { unwrap } = adapter.exports;
const buffer = b => b.buffer.slice(b.byteOffset, b.byteOffset + b.byteLength);
const ts = Buffer.alloc(188 * 3);
for (const i of [0, 188, 376]) ts[i] = 0x47;
function wrapped(payload = ts) {
    const chunk = Buffer.alloc(8 + payload.length);
    chunk.write('EXIF'); chunk.writeUInt32LE(payload.length, 4); payload.copy(chunk, 8);
    // An odd-size preceding chunk exercises RIFF padding.
    const padding = Buffer.from([73, 67, 67, 80, 1, 0, 0, 0, 1, 0]);
    const b = Buffer.concat([Buffer.from('RIFF0000WEBP'), padding, chunk]);
    b.writeUInt32LE(b.length - 8, 4);
    return b;
}
test('extracts TS after padded RIFF chunks without changing original input', () => {
    const b = wrapped(), before = Buffer.from(b);
    assert.deepEqual(Buffer.from(unwrap(buffer(b))), ts);
    assert.deepEqual(b, before);
});
test('passes through ordinary media without allocating a copy', () => {
    const b = buffer(ts); assert.equal(unwrap(b), b);
});
test('extracts HOTEL TS appended after a complete WebP image', () => {
    const image = Buffer.from([82,73,70,70,28,0,0,0,87,69,66,80,86,80,56,76,15,0,0,0,47,0,0,0,0,7,16,245,143,254,7,34,162,255,1,0]);
    const wrapped = Buffer.concat([image, ts]);
    assert.deepEqual(Buffer.from(unwrap(buffer(wrapped))), ts);
    assert.throws(() => unwrap(buffer(wrapped.subarray(0, -1))), /size/);
    wrapped[36 + 188] = 0;
    assert.throws(() => unwrap(buffer(wrapped)), /size/);
});
test('rejects truncated and inconsistent containers', () => {
    const b = wrapped();
    assert.throws(() => unwrap(buffer(b.subarray(0, -1))), /size/);
    b.writeUInt32LE(99999, 26);
    assert.throws(() => unwrap(buffer(b)), /Truncated/);
});
test('rejects image-only EXIF and invalid transport packets', () => {
    const payload = Buffer.from(ts); payload[188] = 0;
    assert.throws(() => unwrap(buffer(wrapped(payload))), /Unsupported/);
    assert.throws(() => unwrap(buffer(wrapped(Buffer.from('unrelated metadata')))), /Unsupported/);
});
