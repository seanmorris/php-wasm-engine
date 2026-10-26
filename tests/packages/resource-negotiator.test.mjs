import test from 'node:test';
import assert from 'node:assert/strict';
import {ResourceStore} from '../../browser/ResourceStore.mjs';
import {normalizeWorld} from '../../browser/WorldDefinition.mjs';
import {ResourceNegotiator} from '../../browser/network/ResourceNegotiator.mjs';
import {sha256} from '../../browser/network/Values.mjs';

const declaring = 'https://game.example/worlds/episode.json';
const bytes = new TextEncoder().encode('IWAD fixture bytes');

const world = normalizeWorld({
	type: 'phonics-world'
	, schemaVersion: 1
	, imports: {}
	, game: 'https://game.example/game/phonics.json#game'
	, resourceSlots: {
		'base-wad': {authority: 'host', accept: ['.wad'], maxBytes: 1024}
	}
	, sources: {
		episode: {
			adapter: 'https://game.example/doom/phonics.json#wad'
			, resources: [
				{slot: 'base-wad', role: 'iwad'}
				, {url: './font.wad', role: 'pwad'}
			]
		}
	}
	, start: {source: 'episode', scope: 'E1M1'}
}, declaring
);

const store = () => new ResourceStore({

	async fetch(url) {
		if(!url.endsWith('.wad')) return new Response('', {status: 404});

		return new Response(bytes);
	}
});

test('host offers and guest binds identical resource bytes from another URL', async () => {
	const host = new ResourceNegotiator({
		world
		, store: store()
		, role: 'host'
		, policy: {dataOrigins: ['https://cdn.example']}
	});

	const offer = await host.offer('base-wad', 'https://cdn.example/DOOM1.wad', {role: 'iwad'});

	const guest = new ResourceNegotiator({
		world
		, store: store()
		, role: 'guest'
		, policy: {dataOrigins: ['https://mirror.example']}
	});

	await guest.accept(offer, {url: 'https://mirror.example/shareware.wad'});
	const bound = guest.bindWorld();

	assert.equal(bound.sources.episode.resources[0].sourceUrl, 'https://mirror.example/shareware.wad');
	assert.equal(bound.sources.episode.resources[0].offerUrl, 'https://cdn.example/DOOM1.wad');
	assert.equal(bound.sources.episode.resources[0].role, 'iwad');
	assert.equal(bound.sources.episode.resources[1].url, 'https://game.example/worlds/font.wad');
	host.close();
	guest.close();
});

test('resource negotiation rejects authority, origins, size, and unresolved required slots', async () => {
	const guest = new ResourceNegotiator({
		world
		, store: store()
		, role: 'guest'
		, policy: {dataOrigins: ['https://cdn.example'], maxResourceBytes: 4}
	});

	await assert.rejects(() => guest.offer('base-wad', 'https://cdn.example/a.wad'), /cannot fill/);

	await assert.rejects(
		() => guest.accept({
			slot: 'base-wad'
			, url: 'https://elsewhere.example/a.wad'
			, integrity: 'sha256-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
			, bytes: 17
			, role: 'iwad'
		})
		, /not allowed/
	);

	assert.throws(() => guest.bindWorld(), /requires unresolved slot/);
	guest.close();
});

test('world-owned resources are pinned while an explicit wildcard permits CORS data hosts', async () => {
	const integrity = await sha256(bytes);

	const fixedWorld = normalizeWorld({
		type: 'phonics-world'
		, schemaVersion: 1
		, imports: {}
		, game: 'https://game.example/game/phonics.json#game'
		, resourceSlots: {
			'font-wad': {
				authority: 'world'
				, defaultUrl: 'https://assets.example/DBIGFONT.wad'
				, integrity
				, accept: ['.wad']
			}
		}
		, sources: {
			episode: {
				adapter: 'https://game.example/doom/phonics.json#wad'
				, resources: [{slot: 'font-wad', role: 'pwad'}]
			}
		}
		, start: {source: 'episode', scope: 'E1M1'}
	}, declaring
	);

	const negotiator = new ResourceNegotiator({
		world: fixedWorld
		, store: store()
		, role: 'host'
		, policy: {dataOrigins: '*'}
	});

	const offer = await negotiator.offer('font-wad', fixedWorld.resourceSlots['font-wad'].defaultUrl, {
		role: 'pwad'
	});

	assert.equal(offer.url, 'https://assets.example/DBIGFONT.wad');
	assert.equal(offer.integrity, integrity);

	await assert.rejects(
		() => negotiator.offer('font-wad', 'https://other.example/DBIGFONT.wad', {role: 'pwad'})
		, /fixed by its declaring world/
	);

	negotiator.close();
});
