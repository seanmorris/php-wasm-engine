import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {ResourceStore} from '../../browser/ResourceStore.mjs';
import {normalizeWorld} from '../../browser/WorldDefinition.mjs';
import {ResourceNegotiator} from '../../browser/network/ResourceNegotiator.mjs';
import {NetworkSession} from '../../browser/network/NetworkSession.mjs';
import {sha256} from '../../browser/network/Values.mjs';
import {DoomWadInspector, inspectWad} from '../../packages/doom-phonics/browser/wad-inspector.mjs';

const wad = (names, type = 'IWAD') => {
	const encoder = new TextEncoder;
	const result = new Uint8Array(12 + names.length * 16);
	const view = new DataView(result.buffer);

	result.set(encoder.encode(type), 0);
	view.setUint32(4, names.length, true);
	view.setUint32(8, 12, true);

	for(const [index, name] of names.entries())
	{
		const offset = 12 + index * 16;

		view.setUint32(offset, 12, true);
		view.setUint32(offset + 4, 0, true);
		result.set(encoder.encode(name), offset + 8);
	}

	return result;
};

const proprietary = wad(['E1M1','THINGS','LINEDEFS','SIDEDEFS','PLAYPAL']);

const freedoom = wad([
	'E1M1','THINGS','LINEDEFS','SIDEDEFS','E2M1','THINGS','LINEDEFS','SIDEDEFS'
	, 'E3M1','THINGS','LINEDEFS','SIDEDEFS','FREEDOOM'
]);

const freedoomDemo = wad(['E1M1','THINGS','LINEDEFS','SIDEDEFS','FREEDOOM']);
const matchWad = wad(['E1M1','THINGS','LINEDEFS','SIDEDEFS'], 'PWAD');
const declaring = 'https://game.example/world.json';
const requirement = {
	id: 'doom-episode-iwad'
	, label: 'Episode-compatible base game'
	, names: ['DOOM1.WAD','DOOM.WAD']
	, inspector: 'https://game.example/doom/phonics.json#wad-inspector'
	, equivalence: 'profile'
	, criteria: {archiveType: 'IWAD', mapNamespace: 'episode', requiredMaps: ['E1M1']}
	, candidates: [
		{
			id: 'freedoom.phase1'
			, label: 'Freedoom: Phase 1'
			, names: ['freedoom1.wad']
			, criteria: {mustContain: ['E1M1','E2M1','E3M1','FREEDOOM']}
			, url: 'https://free.example/freedoom1.wad'
		}
		, {
			id: 'doom.shareware'
			, label: 'DOOM Shareware'
			, names: ['DOOM1.WAD']
			, criteria: {mustNotContain: ['E2M1','FREEDOOM']}
		}
	]
};

const world = normalizeWorld({
	type: 'phonics-world'
	, schemaVersion: 1
	, imports: {}
	, game: 'https://game.example/doom/phonics.json#game'
	, resourceSlots: {
		'base-wad': {
			distribution: 'local'
			, authority: 'host'
			, accept: ['.wad']
			, requirement
		}
	}
	, sources: {
		episode: {
			adapter: 'https://game.example/doom/phonics.json#wad'
			, resources: [{slot: 'base-wad', role: 'iwad'}]
		}
	}
	, start: {source: 'episode', scope: 'E1M1'}
}, declaring
);

const store = () => new ResourceStore({
	fetch: async url => {
		if(url.startsWith('https://private.example/')) return new Response(proprietary);

		if(url.startsWith('https://free.example/')) return new Response(freedoom);

		if(url.startsWith('https://shared.example/')) return new Response(matchWad);

		return new Response('', {status: 404});
	}
});

const negotiator = role => new ResourceNegotiator({
	world
	, store: store()
	, role
	, policy: {dataOrigins: ['https://private.example','https://free.example']}
	, inspectors: () => new DoomWadInspector
});

test('WAD profiles use contents rather than discovery filenames', () => {
	const profile = inspectWad(freedoom, {requirement});

	assert.equal(profile.candidate, 'freedoom.phase1');
	assert.equal(profile.profile.mapNamespace, 'episode');
	assert.deepEqual(profile.profile.maps, ['E1M1','E2M1','E3M1']);

	assert.throws(() => inspectWad(wad(['FREEDOOM']), {requirement}), /does not satisfy requirement/);

	assert.throws(() => inspectWad(freedoomDemo, {requirement}), /does not match an accepted .* profile/);
});

test('WAD inspection rejects malformed directories and missing selected maps', () => {
	const malformed = new Uint8Array(12);
	const view = new DataView(malformed.buffer);

	malformed.set(new TextEncoder().encode('IWAD'));
	view.setUint32(4, 1, true);
	view.setUint32(8, 4096, true);

	assert.throws(() => inspectWad(malformed, {requirement}), /directory is outside/);

	assert.throws(
		() => inspectWad(wad(['E1M2','THINGS','LINEDEFS','SIDEDEFS','FREEDOOM']), {requirement})
		, /does not satisfy requirement/
	);

	assert.throws(
		() => inspectWad(wad(['E1M1','THINGS','LINEDEFS','FREEDOOM']), {requirement})
		, /does not satisfy requirement/
	);
});

test('local WAD offers transmit names and profiles without transmitting acquisition URLs', async () => {
	const host = negotiator('host');
	const guest = negotiator('guest');

	const offer = await host.offer('base-wad', 'https://private.example/DOOM1.WAD?account=secret', {role: 'iwad'});

	assert.equal(offer.distribution, 'local');
	assert.equal(offer.url, undefined);
	assert.equal(JSON.stringify(offer).includes('private.example'), false);
	assert.equal(JSON.stringify(offer).includes('free.example'), false);
	assert.deepEqual(offer.requirement.names, ['DOOM1.WAD','DOOM.WAD']);

	await guest.accept(offer, {source: 'https://free.example/freedoom1.wad'});
	host.verifyPeer(guest.resolutions());
	guest.verifyPeer(host.resolutions());

	const bound = guest.bindWorld().sources.episode.resources[0];

	assert.equal(bound.sourceUrl, 'https://free.example/freedoom1.wad');
	assert.equal(bound.candidate, 'freedoom.phase1');
	assert.equal(bound.offerUrl, undefined);
	assert.equal(bound.negotiated, true);
	host.close();
	guest.close();
});

test('integrity-equivalent local slots reject a different compatible archive', async () => {
	const exactWorld = structuredClone(world);

	exactWorld.resourceSlots['base-wad'].requirement.equivalence = 'integrity';

	const host = new ResourceNegotiator({
		world: exactWorld
		, store: store()
		, role: 'host'
		, policy: {dataOrigins: ['https://private.example','https://free.example']}
		, inspectors: () => new DoomWadInspector
	});

	const guest = new ResourceNegotiator({
		world: exactWorld
		, store: store()
		, role: 'guest'
		, policy: {dataOrigins: ['https://private.example','https://free.example']}
		, inspectors: () => new DoomWadInspector
	});

	const offer = await host.offer('base-wad', 'https://private.example/DOOM1.WAD', {role: 'iwad'});

	await assert.rejects(
		() => guest.accept(offer, {source: 'https://free.example/freedoom1.wad'})
		, /does not match the host archive/
	);

	host.close();
	guest.close();
});

test('one ordered slot permits a compatible first archive and requires exact later overrides', async () => {
	const stackWorld = structuredClone(world);
	const first = stackWorld.resourceSlots['base-wad'].requirement;

	delete stackWorld.resourceSlots['base-wad'].requirement;
	stackWorld.resourceSlots['base-wad'].multiple = true;
	stackWorld.resourceSlots['base-wad'].stack = {
		first
		, following: {
			id: 'doom-stack-overrides'
			, label: 'WAD override'
			, names: ['*.wad']
			, inspector: requirement.inspector
			, equivalence: 'integrity'
			, criteria: {archiveTypes: ['IWAD','PWAD']}
			, profileRequires: []
			, candidates: []
		}
	};

	const create = role => new ResourceNegotiator({
		world: stackWorld
		, store: store()
		, role
		, policy: {dataOrigins: ['https://private.example','https://free.example','https://shared.example']}
		, inspectors: () => new DoomWadInspector
	});

	const host = create('host');
	const guest = create('guest');

	const base = await host.offer('base-wad', 'https://private.example/DOOM1.WAD', {
		role: 'iwad'
		, order: 0
		, stackIndex: 0
	});

	const override = await host.offer('base-wad', 'https://shared.example/maps.wad', {
		role: 'iwad'
		, order: 1
		, stackIndex: 1
	});

	assert.equal(base.requirement.equivalence, 'profile');
	assert.equal(override.requirement.equivalence, 'integrity');
	await guest.accept(base, {source: 'https://free.example/freedoom1.wad', stackIndex: 0});
	await guest.accept(override, {source: 'https://shared.example/maps.wad', stackIndex: 1});
	host.verifyPeer(guest.resolutions());
	guest.verifyPeer(host.resolutions());

	const misplaced = create('guest');

	await assert.rejects(
		() => misplaced.accept(override, {source: 'https://shared.example/maps.wad', stackIndex: 0})
		, /world's requirement for this stack position/
	);

	host.close();
	guest.close();
	misplaced.close();
});

test('a different IWAD profile requires an exact later map WAD when the world declares a guard', async () => {
	const guarded = structuredClone(world);

	guarded.resourceSlots['base-wad'].requirement.profileRequires = ['match-wad'];
	guarded.resourceSlots['match-wad'] = {
		authority: 'host'
		, distribution: 'retrievable'
		, required: false
		, multiple: false
		, accept: ['.wad']
		, maxBytes: 1024 * 1024
	};

	guarded.sources.episode.resources.push({slot: 'match-wad', role: 'pwad'});
	const policy = {
		dataOrigins: ['https://private.example','https://free.example','https://shared.example']
	};

	const negotiate = role => new ResourceNegotiator({
		world: guarded
		, store: store()
		, role
		, policy
		, inspectors: () => new DoomWadInspector
	});

	const host = negotiate('host');
	const guest = negotiate('guest');

	const base = await host.offer('base-wad', 'https://private.example/DOOM1.WAD', {
		role: 'iwad'
		, order: 0
	});

	const match = await host.offer('match-wad', 'https://shared.example/match.wad', {
		role: 'pwad'
		, order: 1
	});

	await guest.accept(base, {source: 'https://free.example/freedoom1.wad'});
	await guest.accept(match);
	host.verifyPeer(guest.resolutions());
	guest.verifyPeer(host.resolutions());
	host.close();
	guest.close();

	const unguardedHost = negotiate('host');
	const unguardedGuest = negotiate('guest');

	const unguardedBase = await unguardedHost.offer('base-wad', 'https://private.example/DOOM1.WAD', {role: 'iwad'});

	await unguardedGuest.accept(unguardedBase, {source: 'https://free.example/freedoom1.wad'});

	assert.throws(() => unguardedHost.verifyPeer(unguardedGuest.resolutions()), /needs exact guard slot match-wad/);

	unguardedHost.close();
	unguardedGuest.close();
});

test('missing members of an ordered WAD stack report source and slot context', async () => {
	const stackWorld = structuredClone(world);

	stackWorld.resourceSlots['episode-addon'] = {
		authority: 'host'
		, accept: ['.wad']
		, required: true
		, multiple: false
		, maxBytes: 1024 * 1024
	};

	stackWorld.sources.episode.resources.push({slot: 'episode-addon', role: 'pwad'});

	const host = new ResourceNegotiator({
		world: stackWorld
		, store: store()
		, role: 'host'
		, policy: {dataOrigins: ['https://private.example','https://free.example']}
		, inspectors: () => new DoomWadInspector
	});

	await host.offer('base-wad', 'https://private.example/DOOM1.WAD', {role: 'iwad'});

	assert.throws(
		() => host.bindWorld()
		, error => error.code === 'resource_missing'
			&& error.declaring === declaring
			&& error.chain.includes('source:episode')
			&& error.chain.includes('slot:episode-addon')
	);

	host.close();
});

test('single-player selection keeps multiple IWADs and PWADs in declared load order', async () => {
	const stackWorld = normalizeWorld({
		type: 'phonics-world'
		, schemaVersion: 1
		, imports: {}
		, game: 'https://game.example/doom/phonics.json#game'
		, resourceSlots: {
			'wad-stack': {
				authority: 'host'
				, accept: ['.wad']
				, required: true
				, multiple: true
				, stack: {
					first: {
						id: 'stack-first'
						, names: ['*.wad']
						, inspector: 'https://game.example/doom/phonics.json#wad-inspector'
						, equivalence: 'integrity'
						, criteria: {archiveType: 'IWAD'}
					}
					, following: {
						id: 'stack-following'
						, names: ['*.wad']
						, inspector: 'https://game.example/doom/phonics.json#wad-inspector'
						, equivalence: 'integrity'
						, criteria: {archiveTypes: ['IWAD','PWAD']}
					}
				}
			}
		}
		, sources: {
			episode: {
				adapter: 'https://game.example/doom/phonics.json#wad'
				, resources: [{slot: 'wad-stack', role: 'wad'}]
			}
		}
		, start: {source: 'episode', scope: 'E1M1'}
	}, 'https://game.example/stack-world.json'
	);

	const archives = new Map([
		['https://assets.example/first.wad', wad(['E1M1','THINGS','LINEDEFS','SIDEDEFS'])]
		, ['https://assets.example/second.wad', wad(['MAP01','THINGS','LINEDEFS','SIDEDEFS'])]
		, ['https://assets.example/maps.wad'
			, wad(['E1M1','THINGS','LINEDEFS','SIDEDEFS','E1M2','THINGS','LINEDEFS','SIDEDEFS'], 'PWAD')
		]
	]);

	const local = new ResourceNegotiator({
		world: stackWorld
		, store: new ResourceStore({fetch: async url => new Response(archives.get(url))})
		, role: 'host'
		, policy: {dataOrigins: '*'}
		, inspectors: () => new DoomWadInspector
	});

	const session = new NetworkSession({controller: new EventTarget, negotiator: local, world: stackWorld});
	const ready = new Promise(resolve => session.addEventListener('resourcesready', resolve, {once: true}));
	const settings = {mode: 'singleplayer', skill: '1'};

	const selected = await session.prepareLocalWorld({
		resources: {
			'wad-stack': [
				'https://assets.example/first.wad'
				, 'https://assets.example/second.wad'
				, 'https://assets.example/maps.wad'
			]
		}
		, start: {source: 'episode', scope: 'e1m2'}
		, settings
	});

	const mounted = await ready;

	assert.equal(mounted.detail.role, 'local');
	assert.equal(selected.start.scope, 'E1M2');
	assert.deepEqual(selected.settings, settings);
	settings.skill = '4';
	assert.equal(selected.settings.skill, '1');
	assert.equal(stackWorld.settings, undefined);

	assert.deepEqual(
		selected.sources.episode.resources.map(resource => new URL(resource.offerUrl).pathname.split('/').pop())
		, ['first.wad','second.wad','maps.wad']
	);

	assert.deepEqual(local.maps(), ['MAP01','E1M1','E1M2']);
	local.close();
});

test('Doom online world declares every support resource in deterministic stack order', async () => {
	const source = new URL('../../packages/doom-phonics/demo/episode-one-online.phonics-world.json', import.meta.url);
	const value = JSON.parse(await readFile(source, 'utf8'));
	const online = normalizeWorld(value, 'https://game.example/doom/episode-one-online.phonics-world.json');
	const placements = online.sources.episode.resources.map(({slot, role}) => [slot, role]);

	assert.deepEqual(placements, [
		['wad-stack','wad']
		, ['music-wad','pwad']
		, ['weapon-wad','pwad']
		, ['actor-decorate','decorate']
		, ['weapon-decorate','decorate']
		, ['weapon-sndinfo','sndinfo']
		, ['menu-font-wad','pwad']
	]);

	for(const id of ['music-wad','weapon-wad','actor-decorate','weapon-decorate','weapon-sndinfo','menu-font-wad'])
	{
		assert.equal(online.resourceSlots[id].authority, 'world');
		assert.match(online.resourceSlots[id].integrity, /^sha256-/);

		const bytes = await readFile(new URL(value.resourceSlots[id].defaultUrl, source));

		assert.equal(await sha256(bytes), online.resourceSlots[id].integrity);
	}

	assert.equal(online.resourceSlots['wad-stack'].multiple, true);
	assert.equal(online.resourceSlots['wad-stack'].stack.first.equivalence, 'profile');
	assert.equal(online.resourceSlots['wad-stack'].stack.following.equivalence, 'integrity');
	assert.deepEqual(online.resourceSlots['wad-stack'].stack.first.profileRequires, []);

	const profiles = Object.fromEntries(
		online.resourceSlots['wad-stack'].stack.first.candidates.map(candidate => [candidate.id, candidate])
	);

	for(const id of ['doom.registered','doom.ultimate'])
	{
		const markers = profiles[id].criteria.mustContain.filter(name => name !== 'E1M1');

		const inspected = inspectWad(wad(['E1M1','THINGS','LINEDEFS','SIDEDEFS', ...markers]), {
			requirement: online.resourceSlots['wad-stack'].stack.first
		});

		assert.equal(inspected.candidate, id);
	}
});
