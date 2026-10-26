import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {serve} from './server.mjs';
import {manifest} from './manifest.mjs';
import {ResourceStore} from '../browser/ResourceStore.mjs';
import {PackageGraph, properties} from '../browser/PackageGraph.mjs';

/** Validate metadata and bytes with the runtime graph, without executing package code. */
export async function validate(directory, {world, origins = []} = {})
{
	const root = await fs.realpath(directory);
	await manifest(path.join(root, 'phonics.json'));
	const core = await fs.realpath(fileURLToPath(new URL('../', import.meta.url)));
	const server = await serve(root, {port: 0, mounts: {'/__phonics/core/': core}});
	const origin = `http://127.0.0.1:${server.address().port}`;
	const scope = new ResourceStore().scope(AbortSignal.timeout(30000));
	const graph = new PackageGraph(scope, {origin, codeOrigins: [origin, ...origins]});
	const visited = new Set();

	const document = async url => {
		if(visited.has(url)) return;

		visited.add(url);
		const data = JSON.parse(new TextDecoder().decode(await scope.read(url)));

		const visit = async value => {
			if(!value || typeof value !== 'object') return;

			const props = properties(value.properties);
			const declaring = props['phonics.source'] ?? url;

			if(props['phonics.entity'])
			{
				const entity = await graph.resolve(props['phonics.entity'], declaring);

				if(!['factory', 'component'].includes(entity.type)) throw Error(`${url}: expected entity factory`);

				await graph.activateGraph(entity);
			}

			for(const key of ['image', 'source', 'template'])
			{
				if(typeof value[key] !== 'string') continue;

				const target = new URL(props['phonics.source.' + key] ?? value[key], declaring).href;

				if(key === 'image') await scope.read(target);
				else await document(target);
			}

			for(const property of value.properties ?? [])
			{
				if(property.type !== 'file' || !property.value) continue;

				const reference = props['phonics.source.property.' + property.name] ?? property.value;
				await scope.read(new URL(reference, declaring));
			}

			for(const child of Object.values(value))
				if(Array.isArray(child)) for(const entry of child) await visit(entry);
		};

		await visit(data);

		return data;
	};

	try
	{
		if(root !== core) await graph.manifest(origin + '/__phonics/core/phonics.json');

		const record = await graph.manifest(origin + '/phonics.json');

		for(const name of Object.keys(record.data.exports ?? {}))
			await graph.activateGraph(await graph.resolve('#' + name, record.url));

		if(world)
		{
			const worldUrl = new URL(world, origin + '/').href;
			const data = JSON.parse(new TextDecoder().decode(await scope.read(worldUrl)));
			const config = properties(data.properties);

			if(data.type !== 'world' || !config['phonics.game']) throw Error(`${world}: expected a Phonics world`);

			await graph.imports(data, worldUrl);
			const game = await graph.resolve(config['phonics.game'], worldUrl);

			if(game.type !== 'game') throw Error(`${world}: phonics.game must select a game`);

			await graph.activateGraph(game);

			for(const reference of JSON.parse(config['phonics.modules'] ?? '[]'))
				await graph.activateGraph(await graph.resolve(reference, worldUrl));

			for(const map of data.maps ?? []) await document(new URL(map.fileName, worldUrl).href);

			const start = config['phonics.start'] ?? data.maps?.[0]?.fileName;

			if(!start) throw Error(`${world}: missing starting map`);

			if(start.startsWith('@') || new URL(start, worldUrl).hash)
			{
				const asset = await graph.resolve(start, worldUrl);

				if(asset.type !== 'asset') throw Error(`${world}: starting map must be an asset`);

				await document(new URL(asset.path, asset.record.url).href);
			}
			else await document(new URL(start, worldUrl).href);
		}

		await graph.files();

		for(const record of graph.order)
			for(const file of record.data.assets ?? [])
			{
				if(!record.data.integrity?.[file]) throw Error(`${record.url}: missing asset integrity for ${file}`);

				await scope.read(new URL(file, record.url), {integrity: record.data.integrity[file]});
			}

		return graph.order.length;
	}
	finally
	{
		scope.close();
		server.closeAllConnections();
		await new Promise(resolve => server.close(resolve));
	}
}
