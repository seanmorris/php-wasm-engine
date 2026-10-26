#!/usr/bin/env node
import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {serve} from '../cli/server.mjs';
import {manifest} from '../cli/manifest.mjs';
import {init, build} from '../cli/project.mjs';
import {initManifest} from '../cli/init-manifest.mjs';
import {validate} from '../cli/validate.mjs';

const help = `Phonics — tools for writing your own game packages

Usage: phonics <command> [directory] [options]

  init                       Interactively create phonics.json in the current directory.
  manifest [directory]       Generate only phonics.json for an existing package.
    -y, --yes               Accept detected defaults without prompting.
    --name NAME --version X.Y.Z  Override package.json metadata.
  init <directory>           Create an independent PHP/Tiled starter game.
    --name @you/my-game      Set the npm package name (default: directory name).
  build [directory]          Refresh integrity and build a publishable package.
    --out dist               Output relative to the project; only generated output is replaced.
  integrity [directory]      Rebuild phonics.json hashes for declared files.
  validate [directory]       Verify files, exports, dependencies, and integrity without running code.
    --world worlds/game.world  Also validate world references and Tiled resources.
    --allow-origin URL      Allow a remote dependency origin; repeat as needed.
  preview [directory]        Serve your package with the generic Phonics host.
    --world worlds/game.world  Starting world (default shown).
    --runtime DIRECTORY     Built php-sdl-wasm runtime, or set PHP_SDL_ROOT.
    --allow-origin URL      Permit remote executable packages; repeat as needed.
  serve [directory]          Serve a built site or package as static files, including PHP source.
    --port 9140             Local server port (preview also accepts this).
    --host 127.0.0.1         Bind address (preview also accepts this).

  -h, --help                Show help; also accepted after any command.
  -v, --version             Show the installed version.

Quick start:
  phonics init my-game --name @you/my-game
  cd my-game
  phonics preview --runtime /path/to/php-sdl-wasm
  phonics integrity
  phonics validate --world worlds/game.world
  phonics build
  npm pack ./dist

Declare new PHP files, browser modules, assets, and exports in phonics.json.
Open worlds/start.tmj in Tiled to change the starter layout and player speed.
Builds contain only declared runtime files and package metadata. Registry
publication is a separate action; phonics never runs npm publish for you.
`;

const commands = {
	init: ['name', 'yes', 'version']
	, manifest: ['name', 'yes', 'version']
	, build: ['out']
	, integrity: []
	, validate: ['world', 'allow-origin']
	, preview: ['world', 'runtime', 'allow-origin', 'port', 'host']
	, serve: ['port', 'host']
};

const args = process.argv.slice(2);
const command = args.shift();

function options(values)
{
	const result = {origins: []};

	while(values.length)
	{
		const argument = values.shift();
		const value = argument === '-y' ? '--yes' : argument;

		if(value.startsWith('--'))
		{
			const key = value.slice(2);

			if(!commands[command]?.includes(key)) throw Error(`Unknown option ${value} for ${command}`);

			if(key === 'yes')
			{
				result.yes = true;
				continue;
			}

			const next = values.shift();

			if(!next || next.startsWith('--')) throw Error(`${value} requires a value`);

			if(key === 'allow-origin')
			{
				const origin = new URL(next);

				if(!['http:', 'https:'].includes(origin.protocol)) throw Error('Origins must use HTTP(S)');

				result.origins.push(origin.origin);
			}
			else result[key] = next;
		}
		else if(value.startsWith('-') || result.directory) throw Error(`Unexpected argument: ${value}`);
		else result.directory = value;
	}

	if(result.port !== undefined)
	{
		if(!/^\d+$/.test(result.port) || Number(result.port) > 65535) throw Error('--port must be 0–65535');

		result.port = Number(result.port);
	}

	return result;
}

async function startServer(directory, settings)
{
	let mounts = {};

	if(command === 'preview')
	{
		const runtime = path.resolve(
			settings.runtime ?? process.env.PHP_SDL_ROOT ?? path.join(directory, 'node_modules/php-sdl-wasm')
		);

		try
		{
			await fs.access(path.join(runtime, 'php8.4-sdl.mjs'));
		}
		catch
		{
			throw Error('Supply a built PHP/SDL runtime with --runtime DIRECTORY or PHP_SDL_ROOT');
		}

		mounts = {
			'/__phonics/core/': fileURLToPath(new URL('../', import.meta.url))
			, '/__phonics/runtime/': runtime
			, '/__phonics/': fileURLToPath(new URL('../cli/preview/', import.meta.url))
		};
	}

	const server = await serve(directory, {...settings, mounts});
	const host = settings.host ?? '127.0.0.1';
	const origin = `http://${host.includes(':') ? `[${host}]` : host}:${server.address().port}`;
	const url = new URL(command === 'preview' ? '/__phonics/' : '/', origin);

	if(command === 'preview')
	{
		const world = path.relative(directory, path.resolve(directory, settings.world ?? 'worlds/game.world'));

		if(world.startsWith('..'))
		{
			server.close();
			throw Error('World must be inside the served package');
		}

		url.searchParams.set('world', '/' + world.split(path.sep).map(encodeURIComponent).join('/'));

		for(const origin of settings.origins) url.searchParams.append('origin', origin);
	}

	console.log(`Phonics serving ${directory}\n${url.href}`);

	for(const signal of ['SIGINT', 'SIGTERM'])
	process.once(signal, () => {
		server.close();
		server.closeAllConnections();
	});
}

try
{
	if(!command || ['-h', '--help', 'help'].includes(command) || args.includes('--help') || args.includes('-h'))
		console.log(help);
	else if(['-v', '--version'].includes(command))
		console.log(JSON.parse(await fs.readFile(new URL('../package.json', import.meta.url), 'utf8')).version);
	else
	{
		if(!commands[command]) throw Error(`Unknown command: ${command}. Run phonics --help.`);

		const settings = options(args), directory = path.resolve(settings.directory ?? '.');

		if(command === 'manifest' || (command === 'init' && !settings.directory))
			await initManifest(directory, settings);
		else if(command === 'init')
		{
			if(settings.yes || settings.version)
			throw Error('Use phonics manifest <directory> for manifest-only options');

			console.log('Created ' + await init(directory, settings));
		}
		else if(command === 'build') console.log('Built ' + await build(directory, settings.out));
		else if(command === 'integrity')
			console.log(`Updated ${await manifest(path.join(directory, 'phonics.json'), {write: true})} files`);
		else if(command === 'validate')
			console.log(
				`Validated ${await validate(directory, settings)} package(s)${settings.world ? ' and world' : ''}`
			);
		else await startServer(directory, settings);
	}
}
catch(error)
{
	console.error(`phonics: ${error.message}`);
	process.exitCode = 1;
}
