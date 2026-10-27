import test from 'node:test';
import assert from 'node:assert/strict';
import {
	normalizeNetwork
	, normalizeResourceSlots
	, normalizeWorld
	, readWorldResource
} from '../../browser/WorldDefinition.mjs';

const origin = 'https://world.test/game/';

const bytes = value => new TextEncoder().encode(JSON.stringify(value));

const scope = values => ({

	async read(url) {
		if(!values[url]) throw new Error(`HTTP 404: ${url}`);

		return bytes(values[url]);
	}
});

const hybrid = {
	type: 'phonics-world'
	, schemaVersion: 1
	, imports: {tiled: '../tiled/phonics.json', wad: '../wad/phonics.json'}
	, game: '#game'
	, sources: {
		platformer: {adapter: '@tiled/world', resources: ['./maps/platform.world']}
		, specials: {
			adapter: '@wad/archive'
			, resources: [{
				url: './data/DOOM1.WAD'
				, role: 'iwad'
				, integrity: 'sha256-HX1DvlAeZ9kn5BXguPPinDvzMHXoWXIYFvZSpSbKx3E='
			}]
		}
	}
	, bindings: [
		{id: 'tiles', use: '@tiled/render', on: {source: 'platformer', kind: 'map'}}
		, {id: 'wad', use: '@wad/render-3d', on: {source: 'specials', kind: 'map'}}
	]
	, transitions: [{
		id: 'special-1'
		, from: {source: 'platformer', scope: 'zone', entry: 'ring'}
		, to: {source: 'specials', scope: 'E1M1', entry: 'start'}
	}]
	, init: {startMap: 'E1M2', cheats: ['iddqd','idkfa']}
	, start: {source: 'platformer', scope: 'zone'}
	, view: {width: 960, height: 540}
};

test('neutral worlds canonicalize each declared import and source resource', () => {
	const world = normalizeWorld(hybrid, origin + 'phonics.json');
	assert.equal(world.imports.wad, 'https://world.test/wad/phonics.json');
	assert.equal(world.sources.platformer.resources[0].url, origin + 'maps/platform.world');
	assert.equal(world.sources.specials.resources[0].url, origin + 'data/DOOM1.WAD');
	assert.equal(world.start.source, 'platformer');
	assert.equal(world.transitions[0].to.scope, 'E1M1');
	assert.deepEqual(world.init, {startMap: 'E1M2', cheats: ['iddqd','idkfa']});
	assert.notEqual(world.init, hybrid.init);
});

test('neutral worlds expose an empty game-owned init object and reject other shapes', () => {
	const world = normalizeWorld({...hybrid, init: undefined}, origin + 'world.json');

	assert.deepEqual(world.init, {});
	assert.throws(
		() => normalizeWorld({...hybrid, init: ['iddqd']}, origin + 'world.json')
		, /init must be an object/
	);
});

test('phonics.json launches an inline or referenced world with correct provenance', async () => {
	const inlineUrl = origin + 'phonics.json';

	const inline = await readWorldResource(scope({
		[inlineUrl]: {schemaVersion: 1, name: '@fixture/hybrid', version: '1.0.0', world: hybrid}
	}), inlineUrl
	);

	assert.equal(inline.packageUrl, inlineUrl);
	assert.equal(inline.world.sources.specials.resources[0].url, origin + 'data/DOOM1.WAD');

	const externalUrl = origin + 'worlds/game.phonics-world.json';

	const external = await readWorldResource(scope({
		[inlineUrl]: {
			schemaVersion: 1
			, name: '@fixture/hybrid'
			, version: '1.0.0'
			, world: './worlds/game.phonics-world.json'
		}
		, [externalUrl]: hybrid
	}), inlineUrl
	);

	assert.equal(external.packageUrl, inlineUrl);
	assert.equal(external.url, externalUrl);
	assert.equal(external.world.sources.specials.resources[0].url, origin + 'worlds/data/DOOM1.WAD');
});

test('legacy Tiled worlds remain accepted and malformed transitions fail early', async () => {
	const url = origin + 'legacy.world';

	const legacy = await readWorldResource(scope({
		[url]: {type: 'world', properties: [{name: 'phonics.game', value: '@game/main'}], maps: []}
	}), url
	);

	assert.equal(legacy.legacy, true);
	assert.equal(legacy.world.sourceUrl, url);

	assert.throws(
		() => normalizeWorld(
			{...hybrid, transitions: [{id: 'bad', from: {source: 'missing', scope: 'x'}, to: hybrid.start}]}
			, url
		)
		, /unknown origin source missing/
	);
});

test('network declarations default to two players and normalize Matrix homeservers', () => {
	const network = normalizeNetwork({
		lobby: {provider: '@matrix/lobby', options: {homeserver: 'https://matrix.example/path'}}
		, transport: '@phonics/webrtc'
	}, origin + 'world.json'
	);

	assert.equal(network.activation, 'menu');
	assert.deepEqual(network.players, {min: 2, max: 2});
	assert.equal(network.lobby.options.homeserver, 'https://matrix.example');
	assert.equal(network.transport.use, '@phonics/webrtc');

	assert.throws(
		() => normalizeNetwork({lobby: {provider: 'x'}, transport: 'y', players: {min: 1, max: 2}}, origin)
		, /ordered range/
	);
});

test('resource slots retain provenance and source placements cannot select code', () => {
	const world = normalizeWorld({
		...hybrid
		, resourceSlots: {
			'base-wad': {
				type: 'url'
				, authority: 'host'
				, accept: ['.wad','application/x-doom-wad']
				, maxBytes: 20_000_000
				, defaultUrl: './data/DOOM1.WAD?edition=shareware'
			}
		}
		, sources: {
			...hybrid.sources
			, specials: {adapter: '@wad/archive', resources: [{slot: 'base-wad', role: 'iwad'}]}
		}
	}, origin + 'worlds/game.json'
	);

	assert.equal(world.resourceSlots['base-wad'].defaultUrl, origin + 'worlds/data/DOOM1.WAD?edition=shareware');

	assert.equal(world.sources.specials.resources[0].slot, 'base-wad');
	assert.equal(world.sources.specials.resources[0].url, undefined);

	assert.throws(() => normalizeResourceSlots({'bad/slot': {}}, origin), /invalid resource slot ID/);

	assert.throws(
		() => normalizeWorld({
			...hybrid
			, resourceSlots: {base: {}}
			, sources: {...hybrid.sources, specials: {adapter: '@wad/archive', resources: [{slot: 'missing'}]}}
		}, origin
		)
		, /uses missing slot/
	);
});

test('local resource requirements canonicalize free replacements and require an inspector', () => {
	const slots = normalizeResourceSlots({
		'base-wad': {
			distribution: 'local'
			, accept: ['.wad']
			, requirement: {
				id: 'doom-episode'
				, names: ['DOOM1.WAD']
				, inspector: '@doom/wad-inspector'
				, equivalence: 'profile'
				, criteria: {mustContain: ['E1M1']}
				, candidates: [{
					id: 'freedoom.phase1'
					, names: ['freedoom1.wad']
					, criteria: {mustContain: ['FREEDOOM']}
					, url: '../free/freedoom1.wad?version=1'
				}]
			}
		}
	}, origin + 'worlds/game.json'
	);

	assert.equal(slots['base-wad'].distribution, 'local');
	assert.equal(slots['base-wad'].integrityRequired, false);

	assert.equal(slots['base-wad'].requirement.candidates[0].url, origin + 'free/freedoom1.wad?version=1');

	assert.throws(
		() => normalizeResourceSlots({'base-wad': {distribution: 'local'}}, origin)
		, /must declare a content requirement/
	);

	assert.throws(
		() => normalizeResourceSlots({
			'base-wad': {distribution: 'local', requirement: {names: ['DOOM1.WAD']}}
		}, origin
		)
		, /has no inspector/
	);
});

test('ordered slots can validate the first resource separately from later overrides', () => {
	const slots = normalizeResourceSlots({
		'wad-stack': {
			distribution: 'local'
			, multiple: true
			, defaultUrl: './DOOM1.WAD'
			, stack: {
				first: {
					id: 'doom-base'
					, names: ['DOOM1.WAD','freedoom1.wad']
					, inspector: '@doom/wad-inspector'
					, equivalence: 'profile'
				}
				, following: {
					id: 'doom-overrides'
					, names: ['*.wad']
					, inspector: '@doom/wad-inspector'
					, equivalence: 'integrity'
				}
			}
		}
	}, origin + 'worlds/game.json'
	);

	assert.equal(slots['wad-stack'].stack.first.equivalence, 'profile');
	assert.equal(slots['wad-stack'].stack.following.equivalence, 'integrity');
	assert.equal(slots['wad-stack'].defaultUrl, origin + 'worlds/DOOM1.WAD');
	assert.equal(slots['wad-stack'].integrityRequired, false);

	assert.throws(
		() => normalizeResourceSlots({
			stack: {
				distribution: 'local'
				, stack: {first: {}, following: {}}
			}
		}, origin
		)
		, /requires multiple resources/
	);
});

test('world-owned resource slots require an immutable retrievable default', () => {
	const integrity = 'sha256-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

	const slots = normalizeResourceSlots({
		'font-wad': {
			authority: 'world'
			, defaultUrl: './DBIGFONT.WAD'
			, integrity
			, bytes: 6699
			, accept: ['.wad']
		}
	}, origin + 'worlds/game.json'
	);

	assert.equal(slots['font-wad'].authority, 'world');
	assert.equal(slots['font-wad'].defaultUrl, origin + 'worlds/DBIGFONT.WAD');
	assert.equal(slots['font-wad'].integrity, integrity);
	assert.equal(slots['font-wad'].bytes, 6699);

	assert.throws(
		() => normalizeResourceSlots({'font-wad': {authority: 'world', defaultUrl: './font.wad'}}, origin)
		, /requires defaultUrl and integrity/
	);

	assert.throws(
		() => normalizeResourceSlots({
			'font-wad': {authority: 'world', distribution: 'local', defaultUrl: './font.wad', integrity}
		}, origin
		)
		, /must declare a content requirement|must be retrievable/
	);
});

test('profile equivalence may depend on a separately exact resource slot', () => {
	const guarded = normalizeWorld({
		...hybrid
		, resourceSlots: {
			base: {
				distribution: 'local'
				, requirement: {
					id: 'base-data'
					, names: ['BASE.WAD']
					, inspector: '@wad/inspector'
					, equivalence: 'profile'
					, profileRequires: ['map']
				}
			}
			, map: {authority: 'host', required: false, accept: ['.wad']}
		}
		, sources: {
			...hybrid.sources
			, specials: {
				adapter: '@wad/archive'
				, resources: [{slot: 'base'}, {slot: 'map'}]
			}
		}
	}, origin + 'worlds/guarded.json'
	);

	assert.deepEqual(guarded.resourceSlots.base.requirement.profileRequires, ['map']);

	assert.throws(
		() => normalizeWorld({
			...hybrid
			, resourceSlots: {
				base: {
					distribution: 'local'
					, requirement: {
						id: 'base-data'
						, names: ['BASE.WAD']
						, inspector: '@wad/inspector'
						, equivalence: 'profile'
						, profileRequires: ['missing']
					}
				}
			}
		}, origin + 'worlds/invalid-guard.json'
		)
		, /requires missing guard slot/
	);
});
