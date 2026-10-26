import fs from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import {chromium} from '@playwright/test';
import {runtimeDirectory} from './runtime.mjs';

const root = path.resolve(import.meta.dirname, '..');

const options = Object.fromEntries(process.argv.slice(2).map(argument => {
	const match = /^--([a-z-]+)=(.*)$/.exec(argument);

	if(!match) throw new Error('Expected --name=value arguments');

	return [match[1], match[2]];
}));

const source = path.resolve(options['source-root'] ?? root);
const runtime = await runtimeDirectory();
const version = options.version ?? '8.4';

if(!/^8\.[0-5]$/.test(version)) throw new Error('Invalid PHP version');

// Serve only the installed runtime. PHP source is mounted directly into its filesystem.
const server = http.createServer(async (request, response) => {
	try
	{
		if(request.url === '/')
		{
			response.setHeader('Content-Type', 'text/html');
			response.end('<!doctype html><title>PHP-Noid collision benchmark</title><canvas></canvas>');

			return;
		}

		const pathname = decodeURIComponent(new URL(request.url, 'http://localhost').pathname);
		const file = path.resolve(runtime, '.' + pathname);

		if(!file.startsWith(runtime + path.sep)) throw new Error('Invalid runtime path');

		response.setHeader('Content-Type', file.endsWith('.mjs') ? 'text/javascript' : 'application/wasm');
		response.end(await fs.readFile(file));
	}
	catch
	{
		response.writeHead(404);
		response.end();
	}
});

await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
let browser;

try
{
	const executablePath = await fs.access('/usr/bin/chromium').then(() => '/usr/bin/chromium', () => undefined);
	browser = await chromium.launch({executablePath, args: ['--no-sandbox']});
	const page = await browser.newPage();
	await page.goto(`http://127.0.0.1:${server.address().port}/`);

	const names = ['autoload.php', 'legacy.php', 'packages/php-noid/autoload.php'
		, 'packages/php-noid/assets/capsules.json'
		, 'packages/php-noid/worlds/stage-01.tmj'
	];

	for(const directory of ['src', 'packages/php-noid/src'])
	for(const file of await fs.readdir(path.join(source, directory), {recursive: true}))
	if(file.endsWith('.php')) names.push(directory + '/' + file);

	const files = Object.fromEntries(
		await Promise.all(names.map(async file => [file, await fs.readFile(path.join(source, file), 'utf8')]))
	);

	const benchmark = 'packages/php-noid/benchmarks/collisions.php';
	files[benchmark] = await fs.readFile(path.join(root, benchmark), 'utf8');
	delete options['source-root'];

	const result = await page.evaluate(async ({files, options, version}) => {
		const {PhpSdl} = await import(`/php${version}-sdl.mjs`);

		const php = new PhpSdl({
			canvas: document.querySelector('canvas')
			, persist: false
			, ini: 'display_errors=0\nlog_errors=1\nerror_log=/dev/stderr\nmemory_limit=512M'
		});

		let output = '', errors = '';
		php.addEventListener('output', event => {output += event.detail.join('');});

		php.addEventListener('error', event => {errors += event.detail.join('');});

		const module = await php.binary;

		for(const [file, text] of Object.entries(files))
		{
			const name = '/engine/' + file;
			module.FS.mkdirTree(name.slice(0, name.lastIndexOf('/')));
			module.FS.writeFile(name, text);
		}

		const json = JSON.stringify(options).replaceAll('\\', '\\\\').replaceAll("'", "\\'");

		try
		{
			const code = await php.run(
				`<?php $benchmarkOptions = json_decode('${json}', true); `
				+ "require '/engine/packages/php-noid/benchmarks/collisions.php';"
			);

			if(code || errors) throw new Error(`PHP exited ${code}: ${errors}\n${output}`);

			return JSON.parse(output);
		}
		finally
		{
			await php.refresh();
		}
	}, {files, options, version});

	console.log(JSON.stringify({...result, browser: await browser.version()}, null, 2));
}
finally
{
	await browser?.close();
	await new Promise(resolve => server.close(resolve));
}
