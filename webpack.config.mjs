import fs from 'node:fs/promises';
import path from 'node:path';
import HtmlWebpackPlugin from 'html-webpack-plugin';
import CopyPlugin from 'copy-webpack-plugin';
import {root,engineFiles} from './scripts/files.mjs';
import {runtimeDirectory} from './scripts/runtime.mjs';

export default async (_env, argv) => {
	const development = argv.mode!=='production';

	const config = {
		context: root
		, entry: './docs/bootstrap.mjs'
		, output: {path: path.join(root, 'docs/dist'),filename: 'demo.[contenthash].js',publicPath: 'auto',clean: true}
		, devtool: development ? 'eval-source-map' : false
		, module: {rules: [{test: /\.css$/,use: ['style-loader','css-loader']}]}
		, plugins: [
			new HtmlWebpackPlugin(
				{template: './docs/index.html', filename: 'sane-phonics/index.html', sitePrefix: '../'}
			)
			, new CopyPlugin(
				{
					patterns: [{from: 'preview', to: 'preview'}
						, {from: 'packages/php-tris/index.html', to: 'php-tris/index.html'}
						, {from: 'packages/doom-phonics/index.html', to: 'doom-phonics/index.html'}
						, {
							from: 'packages/php-noid/index.html'
							, to: 'php-noid/index.html'
						}
					]
				}
			)
		]
	};

	if(development)
	{
		const runtime = await runtimeDirectory();
		let zlib = path.join(runtime, 'extensions/zlib');

		try {await fs.access(zlib);} catch {zlib = path.resolve(runtime, '../zlib');}

		config.devServer = {
			host: '127.0.0.1'
			, port: Number(process.env.PORT||9130)
			, hot: false
			, liveReload: !process.env.PHONICS_TEST
			, client: process.env.PHONICS_TEST ? false : undefined
			, headers: {'Cache-Control': 'no-store'}
			, static: [
				{directory: path.join(root, 'site/.cache/rendered'), publicPath: '/', watch: false}

				, ...['src','shaders','resources','examples','tests','packages','browser'].map(directory => ({
					directory: path.join(root, directory)
					, publicPath: '/engine/' + directory
					, serveIndex: false
				}))
				, {directory: zlib,publicPath: '/runtime/extensions/zlib',serveIndex: false,watch: false}
				, {directory: runtime,publicPath: '/runtime',serveIndex: false,watch: false}
			]
			, watchFiles: [path.join(root, 'autoload.php')]


			, onListening(server) {
				// The dev server handles edits; additions and removals also change the PHP manifest.
				for(const watcher of server.staticWatchers)
				{

					const reload = file => server.sendMessage(server.webSocketServer.clients, 'static-changed', file);
					watcher.on('add', reload).on('unlink', reload);
				}
			}


			, setupMiddlewares(middlewares, server) {
				server.app.get('/engine/engine-files.json', async(_req, res, next) => {
					try {res.json(await engineFiles());} catch(error) {next(error);}
				});

				server.app.get('/engine/phonics.json', (_req, res) => res.sendFile(path.join(root, 'phonics.json')));
				server.app.get('/engine/legacy.php', (_req, res) => res.sendFile(path.join(root, 'legacy.php')));
				server.app.get('/engine/autoload.php', (_req, res) => res.sendFile(path.join(root, 'autoload.php')));
				server.app.get('/docs/index.html', (_req, res) => res.redirect('/sane-phonics/'));

				return middlewares;
			}
		};
	}

	return config;
};
