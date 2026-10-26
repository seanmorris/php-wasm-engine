import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {root} from './files.mjs';
const destination = path.join(root, 'dist');
await fs.mkdir(destination, {recursive: true});
const temporary = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-consumer-'));

const execute = (command, args, cwd = root) => execFileSync(
	command
	, args
	, {cwd, encoding: 'utf8', stdio: ['ignore','pipe','pipe']}
);

const developmentFile = name =>
	/(^|\/)(tests?|benchmarks|scratch|node_modules|vendor)\/|Screenshot|generate-|legacy-host/.test(name);

try
{
	const archives = [];

	for(const directory of ['.'
		, 'packages/sane-phonics'
		, 'packages/php-noid'
		, 'packages/php-tris'
		, 'packages/doom-phonics'
		, 'packages/phonics-matrix'
		, 'packages/tiled-phonics'
	]){
		const result = JSON.parse(
			execute(
				'npm'
				, ['pack', '--ignore-scripts', '--json', '--pack-destination', destination]
				, path.join(root, directory)
			)
		)[0];

		const bad = result.files.find(file => developmentFile(file.path));

		if(bad) throw new Error(`Development file in ${result.name}: ${bad.path}`);

		if(result.name === '@seanmorris/doom-phonics'
			&& result.files.some(file => /(^|\/)demo\/|\.wad$/i.test(file.path))
		)
		throw new Error('Doom-Phonics artifact contains reference game data');

		archives.push(path.join(destination, result.filename));

		if(directory !== 'packages/tiled-phonics')
		{
			execute(
				'composer'
				, ['archive', '--format=zip', '--dir=' + destination, '--file=' + result.name.split('/')[1]]
				, path.join(root, directory)
			);

			const entries = execute('php', [
				'-r'
				, '$zip=new ZipArchive; $zip->open($argv[1]); for($i=0;$i<$zip->numFiles;$i++) echo $zip->getNameIndex($i), "\n";'
				, path.join(destination, result.name.split('/')[1] + '.zip')
			]).trim().split('\n');

			const leaked = entries.find(developmentFile);

			if(leaked) throw new Error(`Development file in Composer ${result.name}: ${leaked}`);
		}
	}

	await fs.writeFile(
		path.join(temporary, 'package.json')
		, JSON.stringify({name: 'phonics-consumer',version: '1.0.0',private: true,type: 'module'})
	);

	execute('npm', ['install', '--ignore-scripts', '--no-audit', '--no-fund', ...archives], temporary);

	for(const name of [
		'phonics'
		, 'sane-phonics'
		, 'php-noid'
		, 'php-tris'
		, 'doom-phonics'
		, 'phonics-matrix'
		, 'tiled-phonics'
	]){
		const directory = path.join(temporary, 'node_modules/@seanmorris', name);

		if((await fs.lstat(directory)).isSymbolicLink()) throw new Error(`Consumer uses a symlink: ${name}`);

		const manifest = JSON.parse(await fs.readFile(path.join(directory, 'phonics.json')));

		for(const file of [...(manifest.php?.files ?? [])
			, ...(manifest.browser?.files ?? [])
			, ...(manifest.assets
			?? []
			)
		])
			await fs.access(path.join(directory, file));
	}

	execute(
		'node'
		, ['--input-type=module', '-e', "import {Phonics} from '@seanmorris/phonics'; if(typeof Phonics.loadWorld !== 'function') throw Error('missing loader');"]
		, temporary
	);

	const composer = path.join(temporary, 'composer');
	await fs.mkdir(composer);

	await fs.writeFile(path.join(composer, 'composer.json'), JSON.stringify({
		name: 'fixture/consumer'
		, require: {
			'seanmorris/phonics': '0.1.0'
			, 'seanmorris/php-tris': '0.1.0'
			, 'seanmorris/php-noid': '0.1.0'
			, 'seanmorris/sane-phonics': '0.1.0'
			, 'seanmorris/doom-phonics': '0.1.0'
			, 'seanmorris/phonics-matrix': '0.1.0'
		}
		, repositories: [{type: 'artifact',url: destination}]
		, config: {'allow-plugins': false}
	}));

	execute('composer', ['install', '--no-scripts', '--no-interaction', '--prefer-dist'], composer);

	execute(
		'php'
		, ['-r', `require 'vendor/autoload.php'; $r=new Phonics\\Ecs\\Registry; $id=$r->create(new SanePhonics\\Physics\\Body); if(!$r->has($id, PhpWasmEngine\\Physics\\Body::class)) exit(2); $c=new Phonics\\Package\\Context; PhpNoid\\Module::factories($c); new DoomPhonics\\Archive\\Archive('PWAD'.pack('V2',0,12),'fixture.wad');`]
		, composer
	);

	// A separate core consumer proves neither framework nor PhpNoid requires the demo.
	const core = path.join(temporary, 'core');
	await fs.mkdir(core);

	await fs.writeFile(
		path.join(core, 'composer.json')
		, JSON.stringify(
			{
				require: {'seanmorris/phonics': '0.1.0','seanmorris/php-noid': '0.1.0'}
				, repositories: [{
					type: 'artifact'
					, url: destination
				}]
			}
		)
	);

	execute('composer', ['install','--no-scripts','--no-interaction','--prefer-dist'], core);

	execute(
		'php'
		, ['-r',"require 'vendor/autoload.php'; if(class_exists('SanePhonics\\\\Physics\\\\Body')) exit(2); $c=new Phonics\\Package\\Context; PhpNoid\\Module::factories($c); new PhpNoid\\Playfield($c);"]
		, core
	);

	const tris = path.join(temporary, 'php-tris-only');
	await fs.mkdir(tris);

	await fs.writeFile(
		path.join(tris, 'composer.json')
		, JSON.stringify(
			{
				require: {'seanmorris/phonics': '0.1.0','seanmorris/php-tris': '0.1.0'}
				, repositories: [{
					type: 'artifact'
					, url: destination
				}]
			}
		)
	);

	execute('composer', ['install','--no-scripts','--no-interaction','--prefer-dist'], tris);

	execute(
		'php'
		, ['-r',"require 'vendor/autoload.php'; $c=new Phonics\\Package\\Context; PhpTris\\Module::systems($c); $id=$c->entities->create(...PhpTris\\Module::components()); $c->systems->update(1/60); if(class_exists('PhpNoid\\Playfield') || class_exists('SanePhonics\\Physics\\Body')) exit(2);"]
		, tris
	);

	const doom = path.join(temporary, 'doom-only');
	await fs.mkdir(doom);

	await fs.writeFile(
		path.join(doom, 'composer.json')
		, JSON.stringify(
			{
				require: {'seanmorris/phonics': '0.1.0','seanmorris/doom-phonics': '0.1.0'}
				, repositories: [{type: 'artifact',url: destination}]
			}
		)
	);

	execute('composer', ['install','--no-scripts','--no-interaction','--prefer-dist'], doom);

	execute(
		'php'
		, ['-r',"require 'vendor/autoload.php'; $wad=new DoomPhonics\\Archive\\Archive('PWAD'.pack('V2',0,12),'fixture.wad'); if($wad->type!=='PWAD' || class_exists('PhpNoid\\Playfield') || class_exists('SanePhonics\\Physics\\Body')) exit(2);"]
		, doom
	);

	console.log('Packed npm and Composer consumers passed without workspace symlinks. Artifacts: ' + destination);
}
finally
{
	await fs.rm(temporary, {recursive: true, force: true});
}
