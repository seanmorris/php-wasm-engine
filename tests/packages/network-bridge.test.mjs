import test from 'node:test';
import assert from 'node:assert/strict';
import {NetworkBridge} from '../../browser/network/NetworkBridge.mjs';

test('network bridge sheds realtime packets before reliable packets and exchanges base64 with PHP', () => {
	const sent = [];
	const bridge = new NetworkBridge({maximumPackets: 2, maximumBytes: 8});
	bridge.attach({send: (channel, bytes) => { sent.push({channel, bytes}); return true; }});
	bridge.push('control', new Uint8Array([1,2,3]));
	bridge.push('realtime', new Uint8Array([4,5,6]));
	bridge.push('control', new Uint8Array([7,8,9]));

	const values = JSON.parse(bridge.receive());

	assert.equal(values.length, 2);
	assert.deepEqual(values.map(value => value.channel), ['control','control']);
	assert.equal(bridge.diagnostics().dropped, 1);
	bridge.send('realtime', 'AQID');
	assert.deepEqual([...sent[0].bytes], [1,2,3]);
	bridge.close();
});
