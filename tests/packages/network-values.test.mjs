import test from 'node:test';
import assert from 'node:assert/strict';
import {
	canonicalJson
	, ContentManifest
	, MatchDescriptor
	, matrixConfiguration
	, ResourceOffer
	, ResolvedResource
} from '../../browser/network/Values.mjs';

const hash = 'sha256-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

test('canonical protocol JSON and match digests ignore object insertion order', async () => {
	assert.equal(canonicalJson({z: 1, a: {y: 2, x: 3}}), '{"a":{"x":3,"y":2},"z":1}');

	const first = new MatchDescriptor({
		game: '@game/main'
		, start: {source: 'episode', scope: 'E1M1'}
		, settings: {skill: 2, mode: 'co-op'}
	});

	const second = new MatchDescriptor({
		game: '@game/main'
		, start: {scope: 'E1M1', source: 'episode'}
		, settings: {mode: 'co-op', skill: 2}
	});

	assert.equal(await first.digest(), await second.digest());
});

test('resource offers retain URLs but identify equivalent bytes by integrity', async () => {
	const one = new ResourceOffer({
		slot: 'base-wad'
		, url: 'https://cdn.example/doom.wad?token=one'
		, integrity: hash
		, bytes: 1024
		, role: 'iwad'
		, order: 2
	});

	const two = new ResourceOffer({
		slot: 'base-wad'
		, url: 'https://mirror.example/doom.wad'
		, integrity: hash
		, bytes: 1024
		, role: 'iwad'
		, order: 2
	});

	assert.equal(new ResolvedResource(one, {sourceUrl: two.url}).offer.integrity, hash);
	assert.notEqual(one.url, two.url);
	assert.equal((await new ContentManifest([one]).digest()).startsWith('sha256-'), true);

	assert.throws(
		() => new ResourceOffer({...one, url: 'https://user:secret@cdn.example/a.wad'})
		, /without embedded credentials/
	);
});

test('Matrix defaults to Matrix.org and honors authorized overrides', () => {
	assert.equal(matrixConfiguration().homeserver, 'https://matrix.org');

	assert.equal(
		matrixConfiguration(
			{homeserver: 'https://world.example'}
			, {homeserver: 'https://session.example/path'}
			, {signalingOrigins: ['https://session.example']}
		).homeserver
		, 'https://session.example'
	);

	assert.throws(
		() => matrixConfiguration(
			{homeserver: 'https://world.example'}
			, {}
			, {signalingOrigins: ['https://host.example']}
		)
		, /not allowed/
	);
});
