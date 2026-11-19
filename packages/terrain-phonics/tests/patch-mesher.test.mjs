import {test} from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {patchMesher, activate} from '../browser/PatchMesher.mjs';

const reference = fileURLToPath(new URL('./patch-reference.php', import.meta.url));
const count = 33 ** 2;

for(const options of [
	{preset: 'coast'}
	, {preset: 'canyon', version: 1, terraceSpacing: 19}
	, {preset: 'canyon', seed: 'canyon-λ', terraceSpacing: 19, terraceStrength: .75}

	, {preset: 'mountain', warpAmplitude: 0, detailOctaves: 12}
	, {preset: 'coast', heightScale: 0, detailAmplitude: 0, worldSize: 128, spacing: .25}
])
test(`worker agrees with PHP for ${JSON.stringify(options)}`, () => {
	const {recipe, permutation, patches, beds, ramps, dip} = JSON.parse(
		execFileSync('php', [reference, JSON.stringify(options)], {maxBuffer: 2 ** 20})
	);

	const build = patchMesher(recipe, permutation, beds, ramps, dip);
	const parents = new Map();

	for(const {node, bytes} of patches)
	{
		const parentKey = [node.level-1, node.ix >> 1, node.iz >> 1].join(':');
		const actual = build(node, parents.get(parentKey));
		const expected = Buffer.from(bytes, 'base64');
		assert.equal(actual.length, expected.length);
		const data = new DataView(actual.buffer);

		// Bounds, LOD error and authoritative heights are doubles. Normals and all
		// eleven shader attributes are rounded to the GPU's float representation.
		for(let i = 0; i<24+8*count; i += 8)
			assert.ok(Math.abs(data.getFloat64(i, true)-expected.readDoubleLE(i)) < 1e-8, `${node.key} double ${i/8}`);

		for(let i = 24+8*count; i<actual.length; i += 4)
			assert.ok(Math.abs(data.getFloat32(i, true)-expected.readFloatLE(i)) < 1e-5, `${node.key} float ${i/4}`);
		parents.set(node.key, actual.subarray(24, 24+count*20));
	}
});

test('hosts without a worker retain incremental generation', () => {
	const module = {};
	const close = activate({module});
	assert.equal(module.terrainPatchBatch('configure', '/tmp/mesh', '', '{}'), 'unavailable');
	close();
	assert.equal(module.terrainPatchBatch, undefined);
});

test('replacing a landscape drops stale results and disposing one scene leaves the other running', t => {
	const workers = [];
	const original = globalThis.Worker;

	t.after(() => {
		if(original === undefined) delete globalThis.Worker;
		else globalThis.Worker = original;
	});

	globalThis.Worker = class {

		constructor() { workers.push(this); }

		postMessage() {}

		terminate() { this.closed = true; }
	};

	const files = new Map();
	const module = {FS: {writeFile: (path, bytes) => files.set(path, bytes)}};
	const close = activate({module});
	const batch = module.terrainPatchBatch;
	const key = '0:0:0';
	const payload = JSON.stringify({node: {key, level: 0},parent: null});

	batch('configure', 'scene-A', '', '{}');
	batch('request', 'scene-A', key, payload);
	batch('configure', 'scene-B', '', '{}');
	batch('configure', 'scene-A', '', '{}');
	assert.equal(workers[0].closed, true);
	assert.equal(batch('take', 'scene-A', key, ''), 'request');
	batch('request', 'scene-A', key, payload);

	const bytes = Uint8Array.of(1, 2, 3);
	const message = {data: {group: 'root', key, bytes}};
	workers[0].onmessage(message);
	workers[0].onerror();
	assert.equal(batch('take', 'scene-A', key, ''), '');
	workers[2].onmessage(message);
	assert.equal(batch('take', 'scene-A', key, ''), 'ready');
	assert.deepEqual(files.get('scene-A'), bytes);

	batch('close', 'scene-A', '', '');
	assert.equal(workers[2].closed, true);
	assert.equal(workers[1].closed, undefined);
	close();
	assert.equal(workers[1].closed, true);
});
