import assert from 'node:assert/strict';
import test from 'node:test';
import {SamplePlayer} from '../../browser/SamplePlayer.mjs';

test('SamplePlayer caches decoding, plays through gain, mutes, and closes', async() => {
	let reads = 0, decodes = 0, starts = 0, closes = 0, gain = 0;
	const source = {

		connect(){
			return {

				connect(){}
			};
		}

		, start(){ starts++; queueMicrotask(() => source.onended?.()); }

		, disconnect(){}
	};

	const context = {
		state: 'running'
		, destination: {}

		, async resume(){}

		, async decodeAudioData(){ decodes++; return {sample: true}; }

		, createBufferSource(){ return source; }

		, createGain(){
			return {
				gain: {set value(value){ gain = value; }}

				, connect(){ return this; }

				, disconnect(){}
			};
		}

		, async close(){ closes++; }
	};

	const player = new SamplePlayer(
		{
			FS: {

				readFile(){ reads++; return new Uint8Array([1,2,3]); }
			}
		}
		, {path: value => value}
		, 'https://example.test/packages/game/phonics.json'
		, {contextFactory: () => context}
	);

	await player.load('impact', 'assets/impact.wav');
	await player.load('impact', 'assets/impact.wav');
	assert.equal(reads, 1);
	assert.equal(decodes, 1);
	assert.equal(await player.play('impact', {gain: .4}), true);
	assert.equal(starts, 1);
	assert.equal(gain, .4);
	player.setMuted(true);
	assert.equal(await player.play('impact'), false);
	await player.close();
	assert.equal(closes, 1);
});

test('SamplePlayer contains missing assets and decode failures', async() => {
	const context = {
		state: 'running'
		, destination: {}

		, async resume(){}

		, async decodeAudioData(){ throw Error('bad'); }

		, async close(){}
	};

	const player = new SamplePlayer(
		{
			FS: {

				readFile(){ return new Uint8Array([0]); }
			}
		}
		, {path: value => value}
		, 'https://example.test/game/phonics.json'
		, {contextFactory: () => context}
	);

	assert.equal(await player.load('broken', 'broken.wav'), null);
	assert.equal(await player.play('broken'), false);
	await player.close();
});
