import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import {existsSync} from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {execFileSync, spawn} from 'node:child_process';
import {serve} from '../cli/server.mjs';
import {init} from '../cli/project.mjs';
import {manifest} from '../cli/manifest.mjs';
import {validate} from '../cli/validate.mjs';

const root = path.resolve(import.meta.dirname, '..');
const temporary = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-cli-'));

const execute = (file, args, cwd = temporary) => execFileSync(file, args, {cwd, encoding: 'utf8', timeout: 90000});

test.after(async() => { await fs.rm(temporary, {recursive: true, force: true}); });

test('static server preserves PHP bytes, supports HEAD, and confines files to declared roots', async() => {
	const directory = path.join(temporary, 'static');
	await fs.mkdir(directory);
	await fs.writeFile(path.join(directory, 'index.html'), '<h1>Static</h1>');
	await fs.writeFile(path.join(directory, 'game.php'), '<?php echo "must not execute";');
	await fs.writeFile(path.join(temporary, 'outside.txt'), 'not public');
	await fs.symlink(path.join(temporary, 'outside.txt'), path.join(directory, 'escape.txt'));
	const server = await serve(directory, {port: 0});
	const base = `http://127.0.0.1:${server.address().port}`;

	try
	{
		const php = await fetch(base + '/game.php');
		assert.match(php.headers.get('content-type'), /text\/plain/);
		assert.equal(await php.text(), '<?php echo "must not execute";');
		assert.equal((await fetch(base + '/')).status, 200);
		assert.equal(await (await fetch(base + '/game.php', {method: 'HEAD'})).text(), '');
		assert.equal((await fetch(base + '/escape.txt')).status, 404);
		assert.equal((await fetch(base + '/%2e%2e%2foutside.txt')).status, 404);
		assert.equal((await fetch(base + '/game.php', {method: 'POST'})).status, 405);
	}
	finally
	{
		server.closeAllConnections();
		await new Promise(resolve => server.close(resolve));
	}
});

test('packed CLI creates, validates, builds and previews an independent game outside the workspace', async() => {
	const packed = JSON.parse(
		execute('npm', ['pack', '--ignore-scripts', '--json', '--pack-destination', temporary], root)
	)[0];

	const consumer = path.join(temporary, 'consumer');
	await fs.mkdir(consumer);
	await fs.writeFile(path.join(consumer, 'package.json'), '{"name":"cli-consumer","private":true}');

	execute(
		'npm'
		, ['install', '--ignore-scripts', '--no-audit', '--no-fund', path.join(temporary, packed.filename)]
		, consumer
	);

	const bin = path.join(consumer, 'node_modules/.bin/phonics');
	const installed = path.join(consumer, 'node_modules/@seanmorris/phonics');
	assert.equal((await fs.lstat(installed)).isSymbolicLink(), false);
	assert.match(execute(bin, ['--help']), /writing your own game packages/);
	assert.match(execute(bin, ['preview', '--help']), /--runtime/);
	assert.throws(() => execute(bin, ['unknown']), /Unknown command/);
	assert.throws(() => execute(bin, ['serve', '--port', '-1']), /--port/);

	const existing = path.join(temporary, 'existing');
	await fs.mkdir(path.join(existing, 'src'), {recursive: true});
	await fs.mkdir(path.join(existing, 'scratch'));

	await fs.writeFile(
		path.join(existing, 'package.json')
		, JSON.stringify({name: '@fixture/existing', version: '1.2.3'})
	);

	await fs.writeFile(path.join(existing, 'module.php'), '<?php return static function($context): void {};');
	await fs.writeFile(path.join(existing, 'src/Thing.php'), '<?php // component');
	await fs.writeFile(path.join(existing, 'scratch/private.php'), '<?php // excluded');
	await fs.symlink(path.join(temporary, 'outside.txt'), path.join(existing, 'escape.php'));
	assert.throws(() => execute(bin, ['manifest'], existing), /terminal/);
	execute(bin, ['init', '-y'], existing);
	const generatedPath = path.join(existing, 'phonics.json');
	const generatedBytes = await fs.readFile(generatedPath, 'utf8');
	const generated = JSON.parse(generatedBytes);
	assert.equal(generated.name, '@fixture/existing');
	assert.equal(generated.version, '1.2.3');
	assert.deepEqual(generated.php.files, ['module.php', 'src/Thing.php']);
	assert.deepEqual(generated.exports.module, {type: 'module', php: 'module.php'});
	assert.match(generated.integrity['src/Thing.php'], /^sha256-/);
	assert.match(execute(bin, ['validate'], existing), /Validated/);
	assert.throws(() => execute(bin, ['manifest', '-y'], existing), /already exists/);
	assert.equal(await fs.readFile(generatedPath, 'utf8'), generatedBytes);
	await fs.unlink(generatedPath);
	assert.throws(() => execute(bin, ['manifest', '-y', '--version', 'latest'], existing), /exact x.y.z/);
	assert.equal(existsSync(generatedPath), false);
	execute(bin, ['manifest', existing, '--yes', '--name', '@fixture/renamed', '--version', '2.0.0']);
	assert.equal(JSON.parse(await fs.readFile(generatedPath)).name, '@fixture/renamed');
	assert.equal(JSON.parse(await fs.readFile(generatedPath)).version, '2.0.0');
	assert.equal((await fs.readdir(existing)).some(file => file.startsWith('.phonics-init-')), false);

	const game = path.join(temporary, 'my-game');
	execute(bin, ['init', game, '--name', '@fixture/my-game']);
	assert.throws(() => execute(bin, ['init', game]), /nonempty/);
	assert.match(execute(bin, ['validate', '--world', 'worlds/game.world'], game), /Validated 2 package/);
	const manifestPath = path.join(game, 'phonics.json');
	const originalManifest = await fs.readFile(manifestPath, 'utf8');
	const conflicting = JSON.parse(originalManifest);
	conflicting.php.autoload = {'Phonics\\': 'src/'};
	await fs.writeFile(manifestPath, JSON.stringify(conflicting));
	assert.throws(() => execute(bin, ['validate'], game), /Incompatible PHP namespace/);
	await fs.writeFile(manifestPath, originalManifest);

	await fs.appendFile(path.join(game, 'game.php'), '\n// Changed by the author.\n');
	assert.throws(() => execute(bin, ['validate'], game), /integrity mismatch/);
	execute(bin, ['integrity'], game);
	execute(bin, ['validate', '--world', 'worlds/game.world'], game);
	await fs.writeFile(path.join(game, 'scratch.txt'), 'do not publish');
	execute(bin, ['build'], game);
	execute(bin, ['build'], game);
	assert.equal(existsSync(path.join(game, 'dist/scratch.txt')), false);
	assert.equal(existsSync(path.join(game, 'dist/game.php')), true);
	assert.equal(JSON.parse(await fs.readFile(path.join(game, 'dist/package.json'))).scripts, undefined);
	assert.match(execute(bin, ['validate', 'dist', '--world', 'worlds/game.world'], game), /Validated/);
	await fs.mkdir(path.join(game, 'valuable'));
	await fs.writeFile(path.join(game, 'valuable/keep.txt'), 'keep');
	assert.throws(() => execute(bin, ['build', '--out', 'valuable'], game), /not created by phonics/);
	assert.equal(await fs.readFile(path.join(game, 'valuable/keep.txt'), 'utf8'), 'keep');

	const gamePack = JSON.parse(execute('npm', ['pack', './dist', '--ignore-scripts', '--json'], game))[0];
	assert.ok(gamePack.files.some(file => file.path === 'phonics.json'));
	assert.ok(!gamePack.files.some(file => /scratch|node_modules|\.phonics-build/.test(file.path)));

	if(!process.env.PHONICS_CLI_BROWSER) return;

	const {runtimeDirectory} = await import('../scripts/runtime.mjs');
	const runtime = await runtimeDirectory();

	const child = spawn(
		bin
		, ['preview', '--port', '0', '--runtime', runtime]
		, {cwd: game, stdio: ['ignore', 'pipe', 'pipe']}
	);

	const url = await new Promise((resolve, reject) => {
		const timer = setTimeout(() => reject(Error('Preview server did not start')), 10000);
		let output = '';

		child.stdout.on('data', chunk => {
			output += chunk;
			const match = output.match(/http:\/\/[^\s]+/);

			if(match)
			{
				clearTimeout(timer);
				resolve(match[0]);
			}
		});

		child.once('error', reject);
		child.once('exit', code => { clearTimeout(timer); reject(Error(`Preview exited: ${code}`)); });
	});

	const {chromium} = await import('@playwright/test');

	const browser = await chromium.launch({
		executablePath: existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : undefined
		, args: ['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader']
	});

	try
	{
		const page = await browser.newPage();
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));
		await page.goto(url);

		await page.waitForFunction(
			() => document.querySelector('#status').textContent.startsWith('Ready')
			, {}
			, {timeout: 60000}
		);

		const position = async() => {
			await page.evaluate(() => previewSession.php.run(String.raw`<?php
				$ids = $phonics->entities->query(\Phonics\Physics\Pose::class);
				vrzno_env('canvas')->dataset->x = $phonics->entities->get($ids[0], \Phonics\Physics\Pose::class)->x;
			`
			));

			return Number(await page.locator('canvas').getAttribute('data-x'));
		};

		const before = await position();
		await page.keyboard.down('ArrowRight');
		await page.waitForTimeout(300);
		await page.keyboard.up('ArrowRight');
		assert.ok(await position() > before);
		await page.locator('#stop').click();
		await page.waitForFunction(() => document.querySelector('#status').textContent === 'Stopped');
		assert.equal(await page.locator('#status').textContent(), 'Stopped');
		assert.deepEqual(errors, []);
	}
	finally
	{
		await browser.close();
		child.kill('SIGTERM');
	}
});

test('world validation follows canonical file properties without a cache after relocation', async() => {
	const sources = path.join(temporary, 'canonical');
	await fs.mkdir(path.join(sources, 'assets'), {recursive: true});
	await fs.writeFile(path.join(sources, 'assets/canonical.bin'), 'canonical resource');
	await fs.writeFile(path.join(sources, 'assets/fallback.bin'), 'ordinary file property');
	const server = await serve(sources, {port: 0});
	const origin = `http://127.0.0.1:${server.address().port}`;
	let game = path.join(temporary, 'authored-game');

	try
	{
		await init(game, {name: '@fixture/canonical-properties'});
		const map = JSON.parse(await fs.readFile(path.join(game, 'worlds/start.tmj'), 'utf8'));
		const props = [
			{name: 'phonics.source', type: 'string', value: origin + '/maps/original.tmj'}
			, {name: 'texture', type: 'file', value: '.cache/removed.bin'}
			, {name: 'phonics.source.property.texture', type: 'string', value: '../assets/canonical.bin?rev=1'}
			, {name: 'sound', type: 'file', value: '../assets/fallback.bin?rev=2'}
		];

		map.properties = props;
		map.layers[0].objects[0].properties.push(...props);

		const save = async() => {
			await fs.writeFile(path.join(game, 'worlds/start.tmj'), JSON.stringify(map));
			await manifest(path.join(game, 'phonics.json'), {write: true});
		};

		await save();
		assert.equal(await validate(game, {world: 'worlds/game.world'}), 2);
		const relocated = path.join(temporary, 'relocated-game');
		await fs.rename(game, relocated);
		game = relocated;
		assert.equal(await validate(game, {world: 'worlds/game.world'}), 2);

		// A broken canonical override must fail even when the cached value is readable.
		props[1].value = '../assets/fallback.bin';
		props[2].value = '../assets/missing-canonical.bin';
		await save();
		await assert.rejects(validate(game, {world: 'worlds/game.world'}), /missing-canonical\.bin/);
	}
	finally
	{
		server.closeAllConnections();
		await new Promise(resolve => server.close(resolve));
	}
});
