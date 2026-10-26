import fs from 'node:fs/promises';
import path from 'node:path';
import {manifest} from './manifest.mjs';

export async function init(directory, {name} = {})
{
	const root = path.resolve(directory);
	name ??= path.basename(root).toLowerCase();

	if(!/^(?:@[a-z0-9][a-z0-9._-]*\/)?[a-z0-9][a-z0-9._-]*$/.test(name)) throw Error('Invalid npm package name');

	const namespace = 'Game' + name.split('/').at(-1).split(/[^a-z0-9]+/i).filter(Boolean)
		.map(word => word[0]?.toUpperCase() + word.slice(1)).join('');

	let exists = false;

	try { exists = (await fs.readdir(root)).length > 0; } catch(error) { if(error.code !== 'ENOENT') throw error; }

	if(exists) throw Error(`Refusing to overwrite nonempty directory: ${root}`);

	await fs.mkdir(path.join(root, 'src'), {recursive: true});
	await fs.mkdir(path.join(root, 'worlds'), {recursive: true});

	const write = (file, value) => fs.writeFile(path.join(root, file), JSON.stringify(value, null, 2) + '\n');

	for(const file of ['autoload.php', 'src/Player.php', 'module.php', 'game.php'])
	{
		const source = await fs.readFile(new URL('starter/' + file, import.meta.url), 'utf8');
		await fs.writeFile(path.join(root, file), source.replaceAll('StarterGame', namespace));
	}

	await write('package.json', {
		name
		, version: '0.1.0'
		, type: 'module'
		, description: 'A game package built with Phonics'
		, files: ['src/', 'worlds/', 'game.php', 'module.php', 'autoload.php', 'phonics.json', 'README.md']
		, peerDependencies: {'@seanmorris/phonics': '^0.1.0'}
		, scripts: {build: 'phonics build', validate: 'phonics validate', preview: 'phonics preview'}
	});

	await write('phonics.json', {
		schemaVersion: 1
		, name
		, version: '0.1.0'
		, requires: {phonics: '^0.1.0', php: '>=8.2.0', extensions: ['sdl', 'vrzno']}
		, dependencies: {}
		, php: {
			files: ['autoload.php', 'game.php', 'module.php', 'src/Player.php']
			, bootstrap: ['autoload.php']
			, autoload: {[namespace + '\\']: 'src/'}
		}
		, browser: {files: []}
		, exports: {
			game: {type: 'game', php: 'game.php', modules: ['#movement']}
			, movement: {type: 'module', php: 'module.php'}
			, player: {type: 'factory', class: namespace + '.Player', module: '#movement'}
			, world: {type: 'asset', path: 'worlds/game.world'}
		}
		, assets: ['worlds/game.world', 'worlds/start.tmj']
		, tiled: {classes: [{name: namespace + '.Player', members: [{name: 'speed', type: 'float', value: 180}]}]}
		, integrity: {}
	});

	await write('worlds/game.world', {
		type: 'world'
		, maps: [{fileName: 'start.tmj', x: 0, y: 0, width: 640, height: 360}]
		, properties: [
			{name: 'phonics.import.game', type: 'string', value: '../phonics.json'}
			, {name: 'phonics.game', type: 'string', value: '@game/game'}
			, {name: 'phonics.start', type: 'string', value: 'start.tmj'}
		]
	});

	await write('worlds/start.tmj', {
		type: 'map'
		, version: '1.10'
		, tiledversion: '1.12.2'
		, orientation: 'orthogonal'
		, renderorder: 'right-down'
		, width: 40
		, height: 23
		, tilewidth: 16
		, tileheight: 16
		, infinite: false
		, nextlayerid: 2
		, nextobjectid: 2
		, tilesets: []
		, layers: [{
			id: 1
			, name: 'Entities'
			, type: 'objectgroup'
			, opacity: 1
			, visible: true
			, x: 0
			, y: 0
			, objects: [{
				id: 1
				, name: 'Player'
				, class: namespace + '.Player'
				, x: 320
				, y: 180
				, width: 24
				, height: 24
				, rotation: 0
				, visible: true
				, properties: [{name: 'speed', type: 'float', value: 180}]
			}]
		}]
	});

	await fs.writeFile(path.join(root, '.gitignore'), 'node_modules/\ndist/\n');

	await fs.writeFile(
		path.join(root, 'README.md')
		, `# ${name}\n\nMove with arrows or WASD. Edit worlds/start.tmj in Tiled.\n\n`
			+ 'Run `phonics integrity` after edits, `phonics validate` to check the package, and `phonics build` '
				+ 'to produce dist/. Preview with `phonics preview --runtime /path/to/php-sdl-wasm`.\n\n'
					+ 'Declare new PHP/browser files and assets in phonics.json. No other game package is required. '
						+ 'Choose a license before publishing with `npm publish ./dist` as a separate release step.\n'
	);

	await manifest(path.join(root, 'phonics.json'), {write: true});

	return root;
}

export async function build(directory, output)
{
	const root = await fs.realpath(directory);
	const destination = path.resolve(root, output ?? 'dist');

	if(destination === root || root.startsWith(destination + path.sep))
	throw Error('Build output must not contain the project');

	try
	{
		const info = await fs.lstat(destination);

		if(info.isSymbolicLink()) throw Error('Build output cannot be a symlink');

		await fs.access(path.join(destination, '.phonics-build'));
	}
	catch(error)
	{
		if(error.code !== 'ENOENT') throw error;

		try
		{
			await fs.access(destination);
			throw Error('Build output exists and was not created by phonics');
		}
		catch(missing)
		{
			if(missing.code !== 'ENOENT') throw missing;
		}
	}

	await manifest(path.join(root, 'phonics.json'), {write: true});
	const data = JSON.parse(await fs.readFile(path.join(root, 'phonics.json')));
	const files = [...Object.keys(data.integrity), 'phonics.json'];

	for(const file of files)
		if(path.resolve(root, file).startsWith(destination + path.sep))
		throw Error(`Source file is inside output: ${file}`);

	for(const file of ['README.md', 'LICENSE', 'composer.json'])
	{
		try
		{
			await fs.access(path.join(root, file));
			files.push(file);
		}
		catch(error)
		{
			if(error.code !== 'ENOENT') throw error;
		}
	}

	await fs.mkdir(path.dirname(destination), {recursive: true});
	const staging = await fs.mkdtemp(path.join(path.dirname(destination), '.phonics-build-'));

	try
	{
		for(const file of files)
		{
			await fs.mkdir(path.dirname(path.join(staging, file)), {recursive: true});
			await fs.copyFile(path.join(root, file), path.join(staging, file));
		}

		const pkg = JSON.parse(await fs.readFile(path.join(root, 'package.json')));

		if(pkg.name !== data.name || pkg.version !== data.version)
		throw Error('package.json and phonics.json identity differ');

		delete pkg.scripts;
		delete pkg.devDependencies;
		pkg.files = files;
		await fs.writeFile(path.join(staging, 'package.json'), JSON.stringify(pkg, null, 2) + '\n');
		await fs.writeFile(path.join(staging, '.phonics-build'), 'Generated by phonics build\n');
		await fs.rm(destination, {recursive: true, force: true});
		await fs.rename(staging, destination);
	}
	finally
	{
		await fs.rm(staging, {recursive: true, force: true});
	}

	return destination;
}
