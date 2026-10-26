import test from 'node:test';
import assert from 'node:assert/strict';
import {
	decodeJsonPayload
	, decodePacket
	, encodeJsonPacket
	, encodePacket
	, MessageFamily
	, SequenceWindow
} from '../../browser/network/Protocol.mjs';

test('binary protocol round trips bounded headers and JSON payloads', () => {
	const bytes = encodeJsonPacket({
		family: MessageFamily.hello
		, session: 12
		, peer: 4
		, sequence: 8
		, tick: 35
		, ack: 7
		, ackBits: 3
	}, {versions: ['1.0.0']});

	const packet = decodePacket(bytes);

	assert.equal(packet.family, MessageFamily.hello);
	assert.equal(packet.tick, 35);
	assert.deepEqual(decodeJsonPayload(packet), {versions: ['1.0.0']});
	assert.throws(() => decodePacket(bytes.slice(0, -1)), /length/);

	assert.throws(
		() => encodePacket({family: MessageFamily.chat, payload: new Uint8Array(5)}, {maximumBytes: 4})
		, /exceeds/
	);
});

test('sequence windows reject duplicates and values older than their acknowledgement window', () => {
	const window = new SequenceWindow;

	assert.equal(window.accept(100), true);
	assert.equal(window.accept(100), false);
	assert.equal(window.accept(102), true);
	assert.equal(window.accept(101), true);
	assert.equal(window.accept(101), false);
	assert.equal(window.accept(60), false);
});
