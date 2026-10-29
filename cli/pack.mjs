import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';
import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {packagePath} from '../browser/PackageGraph.mjs';

const core = fileURLToPath(new URL('../', import.meta.url));
const execute = promisify(execFile);

const hash = bytes => 'sha256-' + createHash('sha256').update(bytes).digest('base64');
const runtimes = new Map();
const builds = new Map();

/** Bundle the engine's optional application-composition service for activation from PHP. */
async function browserRuntime(directory)
{
	const {default: webpack} = await import('webpack');

	await new Promise((resolve, reject) => {
		const compiler = webpack({
			mode: 'production'
			, context: core
			, entry: './browser/ApplicationRuntime.mjs'
			, devtool: false
			, experiments: {outputModule: true}
			, optimization: {minimize: false}
			, output: {path: directory, filename: 'runtime.mjs', module: true, library: {type: 'module'}}
		});

		compiler.run((error, stats) => compiler.close(closeError => {
			if(error || closeError || stats?.hasErrors())
			reject(error ?? closeError ?? Error(stats.toString('errors-only')));
			else resolve();
		}));
	});

	return fs.readFile(path.join(directory, 'runtime.mjs'));
}

/** Build an executable application, including only declared package files and dependencies. */
export function pack(directory, options = {})
{
	const key = path.resolve(directory, options.out ?? 'dist/game.phar');

	if(builds.has(key)) return builds.get(key);

	const task = build(directory, options).finally(() => builds.delete(key));
	builds.set(key, task);

	return task;
}

async function build(directory, {out = 'dist/game.phar', coreUrl, cache = false} = {})
{
	const root = await fs.realpath(directory);
	const destination = path.resolve(root, out);

	if(!destination.endsWith('.phar')) throw Error('Application output must end in .phar');

	const staging = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-phar-'));
	const files = {}, fingerprints = {}, packages = [], discovered = new Map();
	const executable = new Set();
	let application;

	try
	{

		const add = async (member, bytes) => {
			packagePath(member);

			if(files[member]) throw Error('Conflicting archive member: ' + member);

			const file = path.join(staging, 'files', member);
			await fs.mkdir(path.dirname(file), {recursive: true});
			await fs.writeFile(file, bytes);
			// Fixed timestamps make native PHAR output reproducible.
			await fs.utimes(file, 0, 0);
			files[member] = file;
			fingerprints[member] = hash(bytes);
		};

		const include = async (directory, prefix, base, chain = []) => {
			directory = await fs.realpath(directory);

			if(chain.includes(directory))
			throw Error('Application dependency cycle: ' + [...chain, directory].join(' -> '));

			if(discovered.has(directory)) return discovered.get(directory);

			const data = JSON.parse(await fs.readFile(path.join(directory, 'phonics.json')));

			if(data.schemaVersion !== 1 || typeof data.name !== 'string' || !data.name
				|| !/^\d+\.\d+\.\d+$/.test(data.version)
			)
			throw Error(directory + ': expected a versioned Phonics manifest (schemaVersion 1)');

			for(const list of [data.php?.files
				, data.browser?.files
				, data.assets
				, data.preload
				, data.php?.bootstrap, data.application?.assets
			])
			if(list !== undefined && (!Array.isArray(list) || list.some(name => typeof name !== 'string')))
			throw Error(directory + ': application file declarations must be arrays of paths');

			const record = {path: prefix + 'phonics.json', base, name: data.name};
			discovered.set(directory, record);
			packages.push(record);

			if(directory === root) application = data;

			const names = new Set([...(data.php?.files ?? [])
				, ...(data.browser?.files ?? [])
				, ...(data.assets ?? [])
				, ...(data.preload ?? [])
				, ...(data.php?.bootstrap ?? [])
				, ...(data.application?.assets ?? [])
				, ...(data.application?.entry ? [data.application.entry] : [])
			]);

			for(const name of [...(data.php?.files ?? []), ...(data.browser?.files ?? [])
				, ...(data.php?.bootstrap ?? [])
				, ...(data.application?.entry ? [data.application.entry] : [])
			])
			executable.add(prefix + name);

			data.integrity = {};

			for(const name of [...names].sort())
			{
				packagePath(name);
				const source = await fs.realpath(path.join(directory, name));

				if(!source.startsWith(directory + path.sep)) throw Error('Application file escapes package: ' + name);

				if(source === destination) throw Error('Application output is also a declared input');

				const bytes = await fs.readFile(source);
				data.integrity[name] = hash(bytes);
				await add(prefix + name, bytes);
			}

			await add(record.path, JSON.stringify(data));

			for(const dependency of Object.values({...data.dependencies, ...data.application?.dependencies}))
			{
				const location = typeof dependency === 'string' ? dependency : dependency.url;

				if(!location || /^[a-z]+:/i.test(location))
				throw Error('Pack dependencies must be installed local packages: ' + location);

				const target = path.dirname(path.resolve(directory, location));

				await include(
					target
					, 'dependencies/' + encodeURIComponent(path.basename(target)) + '/'
					, path.posix.join(base, path.relative(directory, target).split(path.sep).join('/')) + '/'
					, [...chain, directory]
				);
			}

			return record;
		};

		const applicationBase = path.relative(path.dirname(destination), root).split(path.sep).join('/') + '/';
		const coreBase = coreUrl ?? applicationBase + '.phonics/core/';
		await include(core, 'core/', coreBase);
		const app = await include(root, 'application/', applicationBase);

		if(!application.application?.entry) throw Error('phonics.json must declare application.entry');

		const input = hash(JSON.stringify([fingerprints, packages
			, hash(await fs.readFile(import.meta.filename))
			, hash(await fs.readFile(new URL('pack.php', import.meta.url)))
		]));

		if(cache)
		{
			try
			{
				const previous = JSON.parse(await fs.readFile(destination + '.build.json'));

				if(previous.input === input && hash(await fs.readFile(destination)) === previous.integrity)
				{
					const integrity = previous.integrity + '\n';

					const current = await fs.readFile(destination + '.integrity', 'utf8').catch(error => {
						if(error.code !== 'ENOENT') throw error;

						return null;
					});

					if(current !== integrity) await fs.writeFile(destination + '.integrity', integrity);

					return destination;
				}
			}
			catch(error)
			{
				if(error.code !== 'ENOENT' && !(error instanceof SyntaxError)) throw error;
			}
		}

		const runtimeKey = hash(
			JSON.stringify(Object.entries(fingerprints).filter(([name]) => name.startsWith('core/browser/')))
		);

		if(!runtimes.has(runtimeKey)) runtimes.set(runtimeKey, browserRuntime(staging));

		const runtime = await runtimes.get(runtimeKey);
		await add('runtime.mjs', runtime);
		const code = [];

		for(const member of Object.keys(files).sort())
			if(executable.has(member) || /\.(php|mjs|js)$/.test(member))
			code.push([member, hash(await fs.readFile(files[member]))]);

		for(const record of packages)
		{
			const manifest = JSON.parse(await fs.readFile(files[record.path]));
			const {name, version, requires, dependencies, php, browser, exports} = manifest;
			const entry = manifest.application?.entry;
			code.push([record.path, {name, version, requires, dependencies, php, browser, exports, entry}]);
		}

		const descriptor = {
			abi: 1
			, id: application.name
			, code: hash(JSON.stringify(code))
			, packages
			, application: app.path
			, members: Object.keys(files).sort()
		};

		await add('application.json', JSON.stringify(descriptor));
		const entry = 'application/' + packagePath(application.application.entry);
		const spec = path.join(staging, 'pack.json');
		await fs.writeFile(spec, JSON.stringify({files: Object.fromEntries(Object.entries(files).sort()), entry}));
		const archive = path.join(staging, 'game.phar');

		await execute(
			'php'
			, ['-d', 'phar.readonly=0', fileURLToPath(new URL('pack.php', import.meta.url)), spec, archive]
		);

		const bytes = await fs.readFile(archive);

		if(cache)
		{
			try
			{
				if((await fs.readFile(destination)).equals(bytes))
				{
					await fs.writeFile(destination + '.integrity', hash(bytes) + '\n');
					await fs.writeFile(destination + '.build.json', JSON.stringify({input, integrity: hash(bytes)}));

					return destination;
				}
			}
			catch(error)
			{
				if(error.code !== 'ENOENT') throw error;
			}
		}

		await fs.mkdir(path.dirname(destination), {recursive: true});
		await fs.copyFile(archive, destination + '.tmp');
		await fs.rename(destination + '.tmp', destination);
		await fs.writeFile(destination + '.integrity', hash(bytes) + '\n');
		await fs.writeFile(destination + '.build.json', JSON.stringify({input, integrity: hash(bytes)}));

		return destination;
	}
	finally
	{
		await fs.rm(staging, {recursive: true, force: true});
	}
}
