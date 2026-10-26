import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {ResourceStore} from '../../browser/ResourceStore.mjs';
import {SessionAssets} from '../../browser/SessionAssets.mjs';
import {PackageGraph} from '../../browser/PackageGraph.mjs';
import {LoadProgress} from '../../browser/Phonics.mjs';
const origin = 'https://cdn.test';

const integrity = value => 'sha256-' + createHash('sha256').update(value).digest('base64');

const manifest = (name, extra = {}) => ({
	schemaVersion: 1
	, name
	, version: '0.1.0'
	, requires: {phonics: '^0.1.0'}
	, php: {files: ['module.php'], autoload: {[name + '\\']: 'src/'}}
	, exports: {module: {type: 'module', php: 'module.php'}}
	, integrity: {'module.php': integrity('<?php')}
	, ...extra
});

function setup(data)
{
	const store = new ResourceStore({
		retries: 0
		, fetch: async url => new Response(
			typeof data[url] === 'object' ? JSON.stringify(data[url]) : data[url] ?? ''
			, {status: url in data ? 200 : 404}
		)
	});

	const scope = store.scope();

	return {store, scope, graph: new PackageGraph(scope, {origin})};
}

test('pallets, direct URLs, dependency ordering and verified PHP prerequisites', async () => {
	const {graph, scope} = setup({
		[origin + '/a/phonics.json']: manifest('A', {
			dependencies: {b: {url: '../b/phonics.json', version: '^0.1.0'}}
			, exports: {game: {type: 'game', php: 'module.php', modules: ['@b/module']}}
		})
		, [origin + '/b/phonics.json']: manifest('B')
		, [origin + '/a/module.php']: '<?php'
		, [origin + '/b/module.php']: '<?php'
	});

	await graph.imports({properties: [{name: 'phonics.import.a', value: './a/phonics.json'}]}, origin + '/world.world');
	const game = await graph.resolve('@a/game', origin + '/world.world');
	await graph.activateGraph(game);
	await graph.activateGraph(game);
	assert.deepEqual(graph.active.map(entry => entry.record.data.name), ['B','A']);
	assert.equal((await graph.resolve('./a/phonics.json#game', origin + '/world.world')).id, game.id);
	const progress = [];
	assert.equal((await graph.files(event => progress.push(event))).length, 2);

	assert.deepEqual(progress.map(event => [event.state,event.completed,event.total]), [
		['planned',0,2]
		, ['loading',0,2]
		, ['verified',1,2]
		, ['loading',1,2]
		, ['verified',2,2]
	]);

	assert.deepEqual(graph.order.map(entry => entry.data.name), ['B','A']);
	await assert.rejects(graph.resolve('@a/missing', origin + '/world.world'), /Missing export.*declared by/);
	scope.close();
});

test('load progress exposes immutable phase and resource counts', () => {
	const progress = new LoadProgress('assets', 'Loading package asset', {
		completed: 2
		, total: 4
		, resource: origin + '/art.wad'
	});

	assert.equal(progress.phaseIndex, 4);
	assert.equal(progress.phaseCount, 8);
	assert.equal(progress.fraction, 4.5 / 8);
	assert.equal(progress.resource, origin + '/art.wad');
	assert.ok(Object.isFrozen(progress));
	assert.equal(new LoadProgress('ready', 'Ready', {completed: 1, total: 1}).fraction, 1);
	assert.throws(() => new LoadProgress('imaginary', 'No phase'), /Unknown load phase/);
	assert.throws(() => new LoadProgress('assets', 'Invalid', {completed: -1}), /non-negative/);
});

test('cycles, unsupported runtimes, conflicting namespaces and untrusted origins fail before execution', async () => {
	for(const [data, pattern] of [
		[{
			[origin + '/a.json']: manifest('A', {dependencies: {b: {url: 'b.json', version: '^0.1.0'}}})
			, [origin + '/b.json']: manifest('B', {dependencies: {a: {url: 'a.json', version: '^0.1.0'}}})
		}
		, /dependency cycle/
		]
		, [{[origin + '/a.json']: manifest('A', {requires: {phonics: '^4.0.0'}})}, /Unsupported runtime/]
		, [{
			[origin + '/a.json']: manifest('Same', {dependencies: {b: {url: 'b.json', version: '^0.1.0'}}})
			, [origin + '/b.json']: manifest('Same')
		}
		, /Incompatible PHP namespace/
		]
		, [{
			[origin + '/a.json']: manifest('MixedCase', {dependencies: {b: {url: 'b.json', version: '^0.1.0'}}})
			, [origin + '/b.json']: manifest('mixedcase')
		}
		, /Incompatible PHP namespace/
		]
	]){
		const {graph, scope} = setup(data);
		await assert.rejects(graph.manifest(origin + '/a.json'), pattern);
		scope.close();
	}

	const {graph} = setup({});
	await assert.rejects(graph.manifest('https://evil.test/a.json'), /origin is not allowed/);
});

test('integrity failures and missing files report the resource URL', async () => {
	const {graph, scope} = setup({[origin + '/a.json']: manifest('A'), [origin + '/module.php']: 'modified'});
	await graph.manifest(origin + '/a.json');
	await assert.rejects(graph.files(), /Integrity mismatch.*module.php/);
	await assert.rejects(scope.read(origin + '/missing.php'), /HTTP 404.*missing.php/);
	scope.close();
});

test('query identity, shared references, cancellation and retries remain independent', async () => {
	let calls = 0;
	let finish;

	const store = new ResourceStore({
		retries: 0
		, fetch: async (_url, {signal}) => {
			calls++;

			return new Promise(
				(resolve, reject) => {
					finish = resolve;
					signal.addEventListener('abort', () => reject(signal.reason));
				}
			);
		}
	});

	const abort = new AbortController(), a = store.scope(abort.signal), b = store.scope();
	const first = a.read(origin + '/same.png?v=1');
	const second = b.read(origin + '/same.png?v=1');
	abort.abort();
	await assert.rejects(first);
	assert.equal(calls, 1);
	finish(new Response('pixels'));
	assert.equal(new TextDecoder().decode(await second), 'pixels');
	assert.equal(store.entries.size, 1);
	b.close();
	assert.equal(store.entries.size, 0);
	let attempt = 0;

	const retry = new ResourceStore(
		{retries: 0, fetch: async () => new Response('ok', {status: ++attempt === 1 ? 503 : 200})}
	);

	const scoped = retry.scope();
	await assert.rejects(scoped.read(origin + '/retry'));
	assert.equal(new TextDecoder().decode(await scoped.read(origin + '/retry', {retry: true})), 'ok');
	scoped.close();
});

test('shared WASM resources survive another owner closing during transport and release only once', async () => {
	let finish, calls = 0;

	const store = new ResourceStore(
		{fetch: async () => { calls++; return new Promise(resolve => { finish = resolve; }); }}
	);

	const mounted = new Map(), removed = [];
	const FS = {

		mkdirTree() {
		}

		, writeFile(path, bytes) {
			mounted.set(path, bytes);
		}

		, unlink(path) {
			removed.push(path);
			mounted.delete(path);
		}
	};

	const assets = new SessionAssets(FS, store, [], new AbortController().signal);
	const a = assets.scope(), b = assets.scope(), url = origin + '/image.png?v=1';
	assert.equal(a.request(url), false);
	assert.equal(b.request(url), false);
	a.close();
	finish(new Response('pixels'));
	await b.load(url);
	assert.equal(calls, 1);
	assert.equal(mounted.size, 1);
	assert.equal(b.request(url), true);
	b.close();
	assert.equal(mounted.size, 0);
	assert.equal(removed.length, 1);
	assert.equal(store.entries.size, 0);
	assert.notEqual(assets.path(origin + '/a/image.png?v=1'), assets.path(origin + '/b/image.png?v=1'));
	assert.notEqual(assets.path(origin + '/image.png?v=1'), assets.path(origin + '/image.png?v=2'));
});

test('undeclared bootstraps and factory module type mismatches are rejected', async () => {
	const {graph,scope} = setup({
		[origin + '/phonics.json']: manifest('Fixture', {
			php: {files: ['module.php'],bootstrap: ['missing.php']}
			, exports: {asset: {type: 'asset',path: 'art.png'},actor: {type: 'factory',class: 'Actor',module: '#asset'}}
		})
		, [origin + '/module.php']: '<?php'
	});

	await graph.manifest(origin + '/phonics.json');
	await assert.rejects(graph.files(), /Undeclared PHP bootstrap/);
	const entry = await graph.resolve(origin + '/phonics.json#actor', origin + '/world.world');
	await assert.rejects(graph.activateGraph(entry), /Expected module/);
	scope.close();
});

test('adapter and system-set exports participate in dependency ordering', async () => {
	const {graph,scope} = setup({
		[origin + '/phonics.json']: manifest('Neutral', {
			exports: {
				base: {type: 'module',php: 'module.php'}
				, archive: {type: 'adapter',php: 'adapter.php',modules: ['#base']}
				, render: {type: 'system-set',php: 'render.php',modules: ['#base']}
			}
			, php: {files: ['module.php','adapter.php','render.php'],bootstrap: []}
		})
		, [origin + '/module.php']: '<?php'
		, [origin + '/adapter.php']: '<?php'
		, [origin + '/render.php']: '<?php'
	});

	await graph.imports({type: 'phonics-world',imports: {game: './phonics.json'}}, origin + '/world.json');
	const adapter = await graph.resolve('@game/archive', origin + '/world.json');
	const systems = await graph.resolve('@game/render', origin + '/world.json');
	await graph.activateGraph(adapter);
	await graph.activateGraph(systems);
	assert.deepEqual(graph.active.map(entry => entry.type), ['module','adapter','system-set']);
	scope.close();
});

test('integrity retry replaces corrupt bytes and closing one resource scope cancels its own waiter', async () => {
	let calls = 0;
	const store = new ResourceStore({fetch: async () => new Response(++calls===1 ? 'corrupt' : 'verified')});
	const owner = store.scope();
	await assert.rejects(owner.read(origin + '/asset', {integrity: integrity('verified')}), /Integrity mismatch/);
	await owner.read(origin + '/asset', {integrity: integrity('verified'),retry: true});
	assert.equal(calls, 2);
	owner.close();
	let finish;
	const shared = new ResourceStore({fetch: async () => new Promise(resolve => {finish = resolve;})});
	const a = shared.scope(), b = shared.scope();
	const first = a.read(origin + '/shared'), second = b.read(origin + '/shared');
	a.close();
	await assert.rejects(first, /scope is closed/);
	finish(new Response('okay'));
	await second;
	b.close();
});

test('asset pallets resolve in the declaring package and preserve direct URL semantics', async () => {
	const a = manifest('A', {dependencies: {art: {url: '../b/phonics.json',version: '^0.1.0'}}});
	const b = manifest('B', {exports: {tile: {type: 'asset',path: 'images/tile.png'}}});
	const {graph,scope} = setup({[origin + '/a/phonics.json']: a,[origin + '/b/phonics.json']: b});

	await graph.imports(
		{
			properties: [{name: 'phonics.import.art',value: './b/phonics.json'}
				, {
					name: 'phonics.import.a'
					, value: './a/phonics.json'
				}
			]
		}
		, origin + '/world.world'
	);

	assert.equal(graph.asset('@art/tile', origin + '/a/module.php'), origin + '/b/images/tile.png');
	assert.equal(graph.asset('./b/phonics.json#tile', origin + '/world.world'), origin + '/b/images/tile.png');
	assert.equal(graph.asset('/image.png?q=2#preview', origin + '/world.world'), origin + '/image.png?q=2#preview');
	assert.throws(() => graph.asset('@art/missing', origin + '/world.world'), /Missing export/);
	assert.throws(() => graph.asset('@a/module', origin + '/world.world'), /Expected asset/);
	scope.close();
});

test('streaming retries refresh parsed resources and failed refreshes release mounted bytes', async () => {
	let calls = 0;
	const responses = [new Response('{broken'), new Response('unavailable', {status: 503})];
	const store = new ResourceStore({fetch: async () => { calls++; return responses.shift(); }});
	const mounted = new Map();
	const FS = {

		mkdirTree() {}

		, writeFile(path, bytes) { mounted.set(path, bytes); }

		, unlink(path) { mounted.delete(path); }
	};

	const assets = new SessionAssets(FS, store, [], new AbortController().signal);
	const scope = assets.scope(), url = origin + '/map.tmj';
	await scope.load(url);
	assert.throws(() => JSON.parse(new TextDecoder().decode(mounted.get(assets.path(url)))));
	assert.equal(scope.request(url, true), false);
	await assert.rejects(scope.load(url), /503/);
	assert.equal(calls, 2, 'polling transport failures stay visible until an explicit retry');
	assert.match(scope.request(url), /503/);
	assert.equal(calls, 2);
	scope.close();
	assert.equal(mounted.size, 0, 'failed reloads still release previously mounted bytes');
	assert.equal(store.entries.size, 0);
});
