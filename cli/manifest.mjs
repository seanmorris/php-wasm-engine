import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {packagePath} from '../browser/PackageGraph.mjs';

/** Check declared local package files and optionally regenerate their integrity metadata. */
export async function manifest(filename, {write = false} = {})
{
	const location = path.resolve(filename);
	const root = await fs.realpath(path.dirname(location));
	const data = JSON.parse(await fs.readFile(location, 'utf8'));

	if(data.schemaVersion !== 1 || typeof data.name !== 'string' || !/^\d+\.\d+\.\d+$/.test(data.version))
		throw Error(`${location}: expected schemaVersion 1, name, and an exact x.y.z version`);

	const files = new Set([
		...(data.php?.files ?? [])
		, ...(data.browser?.files ?? [])
		, ...(data.assets ?? [])
		, ...(data.preload ?? []), ...(data.php?.bootstrap ?? [])
	]);

	for(const entry of Object.values(data.exports ?? {}))
	{
		if(!['game', 'module', 'factory', 'component', 'asset'].includes(entry.type))
			throw Error(`${location}: unsupported export type ${entry.type}`);

		for(const key of ['php', 'browser', 'path']) if(entry[key]) files.add(entry[key]);
	}

	const integrity = {};

	for(const name of [...files].sort())
	{
		packagePath(name);
		const file = await fs.realpath(path.join(root, name));

		if(!file.startsWith(root + path.sep)) throw Error(`${location}: file escapes package: ${name}`);

		const hash = 'sha256-' + createHash('sha256').update(await fs.readFile(file)).digest('base64');

		if(!write && data.integrity?.[name] !== hash) throw Error(`${location}: integrity mismatch: ${name}`);

		integrity[name] = hash;
	}

	if(write)
	{
		data.integrity = integrity;
		await fs.writeFile(location, JSON.stringify(data, null, 2) + '\n');
	}

	return files.size;
}
