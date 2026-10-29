import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {pack} from '../../cli/pack.mjs';
import {ArchiveResources} from '../../browser/ArchiveResources.mjs';
import {ResourceStore} from '../../browser/ResourceStore.mjs';
import {pharExtension} from '../../cli/phar-extension.mjs';

test(
	'pack executes a real deterministic PHAR stub, excludes undeclared files and refreshes edited inputs'
	, async () => {
		const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-pack-test-'));

		try
		{
			await fs.writeFile(
				path.join(directory, 'application.php')
				, `<?php
require_once 'phar://' . Phar::running(false) . '/core/autoload.php';
$stream = __DIR__ . '/data.bin';
$native = Phonics\\Asset\\Resource::native($stream);
if(str_starts_with($native, 'phar://') || file_get_contents($native) !== file_get_contents($stream))
throw new RuntimeException('Native materialization changed binary bytes');
echo bin2hex(file_get_contents($native));
unlink($native);
`
			);

			await fs.writeFile(path.join(directory, 'data.bin'), Buffer.from([0, 255, 128, 42]));
			await fs.writeFile(path.join(directory, 'private.wad'), 'do not include');
			await fs.writeFile(path.join(directory, 'alternate.php'), '<?php echo "alternate";');
			await fs.writeFile(path.join(directory, 'module.js'), 'export const version = 1;');

			await fs.writeFile(path.join(directory, 'phonics.json'), JSON.stringify({
				schemaVersion: 1
				, name: '@test/application'
				, version: '1.0.0'
				, application: {entry: 'application.php', assets: ['data.bin']}
				, php: {files: ['application.php', 'alternate.php']}
				, browser: {files: ['module.js']}
			}));

			const file = await pack(directory, {cache: true});
			const first = await fs.readFile(file);

			const descriptor = () => JSON.parse(execFileSync('php', [
				'-r'
				, 'echo file_get_contents("phar://".$argv[1]."/application.json");'
				, file
			], {encoding: 'utf8'}));

			const originalCode = descriptor().code;
			assert.equal(execFileSync('php', [file], {encoding: 'utf8'}), '00ff802a');

			const members = JSON.parse(
				execFileSync(
					'php'
					, ['-r', 'echo json_encode(array_keys(json_decode(file_get_contents("phar://".$argv[1]."/application.json"),true)));', file]
					, {encoding: 'utf8'}
				)
			);

			assert.ok(members.includes('members'));
			assert.ok(!first.includes(Buffer.from('private.wad')));
			await pack(directory);
			assert.deepEqual(await fs.readFile(file), first);
			const mtime = (await fs.stat(file)).mtimeMs;
			await fs.unlink(file + '.integrity');
			await pack(directory, {cache: true});
			assert.equal((await fs.stat(file)).mtimeMs, mtime);
			assert.match(await fs.readFile(file + '.integrity', 'utf8'), /^sha256-/);
			await fs.utimes(file + '.integrity', 0, 0);
			await pack(directory, {cache: true});
			assert.equal((await fs.stat(file + '.integrity')).mtimeMs, 0, 'cache hits do not trigger file watchers');
			await fs.writeFile(path.join(directory, 'data.bin'), 'edited');
			await pack(directory, {cache: true});
			assert.equal(descriptor().code, originalCode, 'resource changes do not change executable identity');
			assert.equal(execFileSync('php', [file], {encoding: 'utf8'}), Buffer.from('edited').toString('hex'));
			const broken = Buffer.from(await fs.readFile(file));
			broken[broken.length - 50] ^= 1;
			await fs.writeFile(file, broken);
			assert.throws(() => execFileSync('php', ['-r', 'new Phar($argv[1]);', file], {stdio: 'pipe'}));
			await pack(directory, {cache: true});
			assert.equal(execFileSync('php', [file], {encoding: 'utf8'}), Buffer.from('edited').toString('hex'));
			const manifestPath = path.join(directory, 'phonics.json');
			const manifest = JSON.parse(await fs.readFile(manifestPath));
			await fs.writeFile(path.join(directory, 'module.js'), 'export const version = 2;');
			await pack(directory);
			const changedCode = descriptor().code;
			assert.notEqual(changedCode, originalCode, 'declared JavaScript participates in code identity');
			manifest.application.entry = 'alternate.php';
			await fs.writeFile(manifestPath, JSON.stringify(manifest));
			await pack(directory);
			assert.notEqual(descriptor().code, changedCode, 'the executable entry participates in code identity');
			assert.equal(execFileSync('php', [file], {encoding: 'utf8'}), 'alternate');
		}
		finally
		{
			await fs.rm(directory, {recursive: true, force: true});
		}
	}
);

test('archive resources preserve binary bytes, declaring URLs, queries and remote scope ownership', async () => {
	const requested = [];
	const remote = new ResourceStore({fetch: async url => { requested.push(url); return new Response('remote'); }});

	const archive = new ArchiveResources({
		url: 'https://game.test/nested/dist/game.phar'
		, store: remote
		, descriptor: {
			abi: 1
			, id: 'app'
			, code: 'hash'
			, packages: [
				{name: 'app', path: 'application/phonics.json', base: '../'}
				, {name: '@seanmorris/phonics', path: 'core/phonics.json', base: '../.phonics/core/'}
			]
			, members: ['application/phonics.json', 'application/maps/a.world', 'core/phonics.json']
		}
		, read: async () => new Uint8Array([0,255,128])
	});

	const scope = archive.store.scope();
	const source = 'https://game.test/nested/maps/a.world';
	assert.deepEqual(await scope.read(source), new Uint8Array([0,255,128]));
	assert.equal(archive.source(archive.stream(source)), source);
	assert.equal(archive.source(archive.stream(source) + '#start'), source + '#start');
	assert.equal(archive.stream(source + '?override=1'), null);
	assert.equal(new URL('../extra.wad', source).href, 'https://game.test/nested/extra.wad');
	assert.equal(new TextDecoder().decode(await scope.read(source + '?override=1')), 'remote');
	assert.deepEqual(requested, [source + '?override=1']);
	scope.close();
	archive.close();
	assert.equal(remote.entries.size, 0);
});

test('external archive resources retry, retire and cancel with their owning scope', async () => {
	let attempts = 0, transportSignal;

	const remote = new ResourceStore({
		fetch: async (url, {signal}) => {
			attempts++;

			if(url.endsWith('/pending'))
			{
				transportSignal = signal;

				return new Promise((resolve, reject) => signal.addEventListener('abort', () => reject(signal.reason)));
			}

			return new Response('asset', {status: attempts === 1 ? 503 : 200});
		}
	});

	const archive = new ArchiveResources({
		url: 'https://game.test/game.phar'
		, store: remote
		, descriptor: {abi: 1, packages: [], members: []}
		, read: async () => { throw new Error('Unexpected archive read'); }
	});

	const scope = archive.store.scope();
	const url = 'https://game.test/asset';
	await assert.rejects(scope.read(url, {retries: 0}), /503/);
	assert.equal(remote.entries.size, 0);
	assert.equal(new TextDecoder().decode(await scope.read(url, {retry: true})), 'asset');
	assert.equal(attempts, 2);
	await scope.read(url);
	assert.equal(attempts, 2, 'the scene still shares successfully loaded bytes');
	scope.release(url);
	assert.equal(archive.store.entries.size, 0);
	await scope.read(url);
	assert.equal(attempts, 3, 'retired resources are fetched again');

	const pending = scope.read('https://game.test/pending');
	const cancelled = assert.rejects(pending, /closed/);
	scope.close();
	await cancelled;
	assert.equal(transportSignal.aborted, true);
	assert.equal(remote.entries.size, 0);
	archive.close();
});

test('Phar extension matches the pinned published artifact', async () => {
	assert.ok((await pharExtension()).endsWith('/php-wasm-phar'));
});

test('application cancellation, readiness failures and runtime errors dispose once', async () => {
	const {run} = await import('../../browser/Phonics.mjs');

	for(const mode of ['ready', 'abort', 'timeout', 'exit'])
	{
		const controller = new AbortController();
		let refreshes = 0;
		const module = {
			FS: {

				mkdirTree() {}

				, writeFile() {}
			}
		};

		const php = new EventTarget();
		php.binary = Promise.resolve(module);

		php.refresh = async () => { refreshes++; };

		php.run = async () => {
			if(mode === 'ready') module.sceneReady(null);

			if(mode === 'abort') controller.abort(new Error('cancelled by test'));

			return mode === 'exit' ? 1 : 0;
		};

		const resources = new ResourceStore({fetch: async () => new Response('fake archive')});

		const pending = run('https://game.test/game.phar', {
			canvas: {}
			, runtime: {create: async () => php}
			, resources
			, signal: controller.signal
			, timeout: 10
		});

		if(mode === 'ready')
		{
			const session = await pending;
			await session.dispose();
			await session.dispose();
		}
		else await assert.rejects(pending, /cancelled by test|did not become ready|PHP returned 1/);

		assert.equal(refreshes, 1);
		assert.equal(resources.entries.size, 0);
	}
});

test('cancellation and timeout release loading sessions before a late runtime finishes', {timeout: 3000}, async () => {
	const {run} = await import('../../browser/Phonics.mjs');

	for(const stage of ['factory', 'binary'])
	for(const cancel of [true, false])
	{
		let finish, started, refreshes = 0, executions = 0;
		const late = new Promise(resolve => { finish = resolve; });

		const entered = new Promise(resolve => { started = resolve; });

		const controller = new AbortController();
		const php = new EventTarget();
		php.binary = stage === 'binary' ? late : Promise.resolve({});

		php.refresh = async () => { refreshes++; };

		php.run = async () => { executions++; };

		const resources = new ResourceStore({fetch: async () => new Response('archive')});

		const pending = run('https://game.test/game.phar', {
			canvas: {}
			, resources
			, signal: controller.signal
			, timeout: cancel ? 1000 : 10
			, runtime: {create: () => { started(); return stage === 'factory' ? late : php; }}
		});

		await entered;

		if(cancel) controller.abort(new Error('cancelled during startup'));

		await assert.rejects(pending, /cancelled during startup|did not become ready/);
		assert.equal(resources.entries.size, 0);
		assert.equal(refreshes, 0);
		finish(stage === 'factory' ? php : {});
		await new Promise(resolve => setImmediate(resolve));
		assert.equal(refreshes, 1, 'late runtimes are released exactly once');
		assert.equal(executions, 0, 'a cancelled application must not execute');
	}
});

test('packing rejects undeclared entry paths, escaping symlinks and dependency cycles', async () => {
	const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-pack-invalid-'));
	const app = path.join(directory, 'app');
	await fs.mkdir(app);
	await fs.writeFile(path.join(directory, 'private.php'), '<?php echo "outside";');
	await fs.writeFile(path.join(app, 'application.php'), '<?php');
	await fs.symlink('../private.php', path.join(app, 'escape.php'));
	const manifest = {
		schemaVersion: 1
		, name: '@test/invalid'
		, version: '1.0.0'
		, application: {entry: 'application.php'}
	};

	try
	{
		for(const [changes, expected] of [
			[{application: {entry: '../private.php'}}, /Invalid package path/]
			, [{application: {entry: 'escape.php'}}, /escapes package/]
			, [{application: {entry: 'missing.php'}}, /ENOENT/]
			, [{application: {entry: 'application.php', dependencies: {self: './phonics.json'}}}, /dependency cycle/]
			, [{application: {entry: 'application.php', dependencies: {remote: 'https:\/\/cdn.test/phonics.json'}}}
				, /installed local/
			]
			, [{assets: 'private.php'}, /arrays of paths/]
		]){
			await fs.writeFile(path.join(app, 'phonics.json'), JSON.stringify({...manifest, ...changes}));
			await assert.rejects(pack(app), expected);
		}
	}
	finally
	{
		await fs.rm(directory, {recursive: true, force: true});
	}
});
