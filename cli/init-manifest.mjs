import fs from 'node:fs/promises';
import path from 'node:path';
import {createInterface} from 'node:readline/promises';
import {randomUUID} from 'node:crypto';
import {manifest} from './manifest.mjs';
import {packagePath} from '../browser/PackageGraph.mjs';

/** Generate only package metadata; never load PHP or execute package scripts. */
export async function initManifest(directory, settings = {})
{
	const root = await fs.realpath(directory);
	const target = path.join(root, 'phonics.json');

	try
	{
		await fs.lstat(target);
		throw Error('phonics.json already exists; edit it and run phonics integrity');
	}
	catch(error)
	{
		if(error.code !== 'ENOENT') throw error;
	}

	let npm = {};

	try
	{
		npm = JSON.parse(await fs.readFile(path.join(root, 'package.json'), 'utf8'));
	}
	catch(error)
	{
		if(error.code !== 'ENOENT') throw error;
	}

	const files = [];

	const walk = async(relative = '') => {
		for(const entry of await fs.readdir(path.join(root, relative), {withFileTypes: true}))
		{
			if(entry.name.startsWith('.')) continue;

			const name = relative ? relative + '/' + entry.name : entry.name;

			if(entry.isFile()) files.push(name);
			else if(entry.isDirectory() && (relative || ['src', 'assets', 'worlds', 'browser'].includes(name)))
			{
				if(!['node_modules', 'vendor', 'tests', 'test', 'scratch', 'dist'].includes(entry.name))
				await walk(name);
			}
		}
	};

	await walk();
	files.sort();

	if(!settings.yes && !process.stdin.isTTY)
	throw Error('Interactive input requires a terminal; use --yes for defaults');

	const rl = settings.yes ? null : createInterface({input: process.stdin, output: process.stdout});

	const cancellation = new AbortController();
	rl?.on('SIGINT', () => cancellation.abort());
	rl?.on('close', () => cancellation.abort());

	const ask = async(label, value) => rl
		? (await rl.question(`${label} (${value}): `, {signal: cancellation.signal})).trim() || value
		: value;

	const list = value => value === '-' ? [] : [...new Set(value.split(',').map(item => item.trim()).filter(Boolean))];
	let temporary;

	try
	{
		const name = await ask('Package name', settings.name ?? npm.name ?? path.basename(root).toLowerCase());
		const version = await ask('Version', settings.version ?? npm.version ?? '0.1.0');
		const kind = await ask('Export type: game or module', files.includes('game.php') ? 'game' : 'module');
		const entry = await ask('PHP entry file; use - for none', files.includes(kind + '.php') ? kind + '.php' : '-');

		const bootstrap = await ask(
			'PHP autoloader; use - for none'
			, files.includes('autoload.php') ? 'autoload.php' : '-'
		);

		const namespace = await ask('Owned PHP namespace; use - for none', '-');
		const source = namespace === '-' ? '' : await ask('Namespace source directory', 'src/');

		const php = list(
			await ask('PHP files, comma-separated', files.filter(file => file.endsWith('.php')).join(', '))
		);

		const browser = list(
			await ask(
				'Browser modules, comma-separated'
				, files.filter(file => /^browser\/.*\.m?js$/.test(file)).join(', ')
			)
		);

		const assets = list(
			await ask(
				'Assets, comma-separated'
				, files.filter(file => /^(assets|worlds)\//.test(file) && !/\.(php|m?js)$/.test(file)).join(', ')
			)
		);

		if(!/^(?:@[a-z0-9][a-z0-9._-]*\/)?[a-z0-9][a-z0-9._-]*$/.test(name)) throw Error('Invalid npm package name');

		if(!/^\d+\.\d+\.\d+$/.test(version)) throw Error('Version must be exact x.y.z');

		if(!['game', 'module'].includes(kind)) throw Error('Export type must be game or module');

		if(namespace !== '-' && !/^[A-Za-z_][A-Za-z0-9_]*(\\[A-Za-z_][A-Za-z0-9_]*)*\\?$/.test(namespace))
			throw Error('Invalid PHP namespace');

		if(namespace !== '-')
		{
			packagePath(source.replace(/\/$/, ''));
			const resolved = await fs.realpath(path.join(root, source));

			if(!resolved.startsWith(root + path.sep) || !(await fs.stat(resolved)).isDirectory())
				throw Error('Namespace source directory must be inside the package');
		}

		if(entry !== '-') php.push(entry);

		if(bootstrap !== '-') php.push(bootstrap);

		const data = {
			schemaVersion: 1
			, name
			, version
			, requires: {phonics: '^0.1.0', php: '>=8.2.0', extensions: ['sdl', 'vrzno']}
			, dependencies: {}
			, php: {
				files: [...new Set(php)]
				, bootstrap: bootstrap === '-' ? [] : [bootstrap]
				, autoload: namespace === '-' ? {} : {[namespace.replace(/\\?$/, '\\')]: source}
			}
			, browser: {files: browser}
			, exports: entry === '-' ? {} : {[kind]: {type: kind, php: entry}}
			, assets
			, tiled: {classes: []}
			, integrity: {}
		};

		// Hash and check every declared path before creating the destination.
		temporary = path.join(root, '.phonics-init-' + randomUUID() + '.json');
		await fs.writeFile(temporary, JSON.stringify(data), {flag: 'wx'});
		await manifest(temporary, {write: true});
		const content = await fs.readFile(temporary, 'utf8');
		console.log(content);

		if(rl && !['y', 'yes'].includes((await ask('Write phonics.json? yes/no', 'yes')).toLowerCase()))
		{
			console.log('Cancelled; no manifest written.');

			return;
		}

		await fs.writeFile(target, content, {flag: 'wx'});
		console.log('Created ' + target);
		console.log('Review exports, dependencies, namespace ownership and Tiled classes; then run phonics validate.');
	}
	finally
	{
		rl?.close();

		if(temporary) await fs.rm(temporary, {force: true});
	}
}
