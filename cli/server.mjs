import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import {createReadStream} from 'node:fs';
import {pipeline} from 'node:stream/promises';

const types = {
	'.html': 'text/html; charset=utf-8'
	, '.css': 'text/css; charset=utf-8'
	, '.js': 'text/javascript; charset=utf-8'
	, '.mjs': 'text/javascript; charset=utf-8'
	, '.json': 'application/json'
	, '.world': 'application/json'
	, '.tmj': 'application/json'
	, '.tsj': 'application/json'
	, '.wasm': 'application/wasm'
	, '.png': 'image/png'
	, '.svg': 'image/svg+xml'
	, '.webp': 'image/webp'
	, '.wav': 'audio/wav'
	, '.php': 'text/plain; charset=utf-8'
	, '.md': 'text/plain; charset=utf-8'
};

/** Serve package bytes unchanged, including PHP source consumed by the WASM runtime. */
export async function serve(directory, {host = '127.0.0.1', port = 9140, mounts = {}} = {})
{
	const root = await fs.realpath(directory);

	if(!(await fs.stat(root)).isDirectory()) throw Error(`Not a directory: ${directory}`);

	const mounted = await Promise.all(
		Object.entries(mounts).map(async ([prefix, directory]) => [prefix, await fs.realpath(directory)])
	);

	mounted.sort((a, b) => b[0].length - a[0].length);

	const server = http.createServer(async(request, response) => {
		try
		{
			if(!['GET', 'HEAD'].includes(request.method))
			{
				response.writeHead(405, {Allow: 'GET, HEAD'}).end();

				return;
			}

			const url = new URL(request.url, 'http://localhost');
			let pathname = decodeURIComponent(url.pathname);
			const mount = mounted.find(([prefix]) => pathname.startsWith(prefix));
			const allowed = mount?.[1] ?? root;

			if(mount) pathname = '/' + pathname.slice(mount[0].length);

			let file = path.resolve(allowed, '.' + pathname);

			if(file !== allowed && !file.startsWith(allowed + path.sep)) throw Error('Outside root');

			const stat = await fs.stat(file);

			if(stat.isDirectory())
			{
				if(!url.pathname.endsWith('/'))
				{
					response.writeHead(301, {Location: url.pathname + '/' + url.search}).end();

					return;
				}

				file = path.join(file, 'index.html');
			}

			file = await fs.realpath(file);

			if(file !== allowed && !file.startsWith(allowed + path.sep)) throw Error('Outside root');

			const info = await fs.stat(file);

			if(!info.isFile()) throw Error('Not a file');

			response.writeHead(200, {
				'Content-Type': types[path.extname(file)] ?? 'application/octet-stream'
				, 'Content-Length': info.size
				, 'Cache-Control': 'no-store'
				, 'X-Content-Type-Options': 'nosniff'
			});

			if(request.method === 'HEAD') response.end();
			else await pipeline(createReadStream(file), response);
		}
		catch
		{
			if(!response.headersSent) response.writeHead(404, {'Content-Type': 'text/plain'});

			response.end('Not found');
		}
	});

	await new Promise((resolve, reject) => {
		server.once('error', reject);
		server.listen(port, host, resolve);
	});

	return server;
}
