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
			new HtmlWebpackPlugin({template: './docs/index.html'})
			, new CopyPlugin({
				patterns: ['architecture.md'
					, 'loops.md'
					, 'menus.md'
					, 'startup.md'
					, 'input.md'
					, 'loop-layout.svg'
				]
				.map(
					name => ({from: 'docs/'+name,to: name})
				)
			})
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
			, liveReload: true
			, headers: {'Cache-Control': 'no-store'}
			, static: [
				...['src','shaders','examples','tests'].map(directory => ({
					directory: path.join(root, directory)
					, publicPath: '/engine/'+directory
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

				server.app.get('/engine/autoload.php', (_req, res) => res.sendFile(path.join(root, 'autoload.php')));
				server.app.get('/docs/index.html', (_req, res) => res.redirect('/'));

				return middlewares;
			}
		};
	}

	return config;
};
