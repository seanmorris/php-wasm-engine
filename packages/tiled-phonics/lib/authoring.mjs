import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {satisfies, packagePath, properties} from '@seanmorris/phonics/package-graph';
import {verify} from '@seanmorris/phonics/resources';

const key = url => createHash('sha256').update(url).digest('hex');

const property = (name, value) => ({name, type: 'string', value});

/** Import declarative authoring data only. Downloaded PHP and browser modules are never executed. */
export async function importPackage(url, directory, {fetch: fetcher = globalThis.fetch} = {})
{
	const packages = new Map(), visiting = new Set(), namespaces = new Map(), classes = new Map();
	const cache = path.resolve(directory);
	await fs.mkdir(cache, {recursive: true});

	const read = async url => {
		if(!/^https?:/.test(url)) throw new Error(`Package URL must use HTTP(S): ${url}`);

		const response = await fetcher(url);

		if(!response.ok) throw new Error(`${url}: HTTP ${response.status}`);

		return new Uint8Array(await response.arrayBuffer());
	};

	const visit = async reference => {
		const url = new URL(reference).href;

		if(visiting.has(url)) throw new Error(`Package dependency cycle: ${[...visiting, url].join(' -> ')}`);

		if(packages.has(url)) return packages.get(url);

		visiting.add(url);
		const manifest = JSON.parse(new TextDecoder().decode(await read(url)));

		if(manifest.schemaVersion !== 1 || !satisfies('0.1.0', manifest.requires?.phonics ?? '^0.1.0'))
			throw new Error(`Incompatible package ${url}`);

		for(const dependency of Object.values(manifest.dependencies ?? {}))
		{
			const target = await visit(new URL(dependency.url, url));

			if(!satisfies(target.manifest.version, dependency.version))
			throw new Error(`Incompatible dependency of ${url}`);
		}

		for(const namespace of Object.keys(manifest.php?.autoload ?? {}))
		{
			const normalized = namespace.toLowerCase();

			for(const [other, source] of namespaces)
				if(normalized.startsWith(other) || other.startsWith(normalized))
				throw new Error(`Namespace conflict: ${url} and ${source}`);
			namespaces.set(normalized, url);
		}

		for(const definition of manifest.tiled?.classes ?? [])
		{
			if(classes.has(definition.name)) throw new Error(`Conflicting Tiled class ${definition.name}`);

			for(const member of definition.members ?? []) validateValue(member, member.value, url);
			classes.set(definition.name, {...definition, source: url});
		}

		const previews = [];

		const preview = async (relative, chain = []) => {
			const source = new URL(relative, url).href;

			if(chain.includes(source)) throw new Error(`Preview cycle: ${[...chain, source].join(' -> ')}`);

			const base = new URL('.', url).href;

			if(!source.startsWith(base)) throw new Error(`Preview outside package: ${source}`);

			const packageFile = source.slice(base.length);
			packagePath(packageFile);

			if(/\.(php|m?js)$/i.test(packageFile)) throw new Error(`Executable authoring preview rejected: ${source}`);

			const integrity = manifest.integrity?.[packageFile];

			if(!integrity) throw new Error(`Missing preview integrity: ${source}`);

			const bytes = await read(source);
			await verify(bytes, integrity, source);
			const file = key(source) + path.extname(new URL(source).pathname);

			if(/\.(json|tsj|tx|tmj)$/.test(file))
			{
				const data = JSON.parse(new TextDecoder().decode(bytes));

				const rewrite = async object => {
					if(!object || typeof object !== 'object') return;

					for(const item of [...(object.properties ?? [])])
					{
						if(item.type !== 'file' || !item.value) continue;

						const original = new URL(item.value, source).href;
						const cached = await preview(original, [...chain, source]);
						object.properties.push(property('phonics.source.property.' + item.name, original));
						item.value = cached.file;
					}

					for(const field of ['image', 'source', 'template'])
					{
						if(typeof object[field] !== 'string') continue;

						const original = new URL(object[field], source).href;
						const cached = await preview(original, [...chain, source]);
						object.properties ??= [];
						object.properties.push(property('phonics.source.' + field, original));
						object[field] = cached.file;
					}

					for(const [name, child] of Object.entries(object))
					if(name !== 'properties')
						if(Array.isArray(child))
						{
							for(const item of child) await rewrite(item);
						}
						else if(child && typeof child === 'object') await rewrite(child);
				};

				await rewrite(data);
				const owner = data.type === 'template' ? data.object : data;
				owner.properties ??= [];
				owner.properties.push(property('phonics.source', source));
				await fs.writeFile(path.join(cache, file), JSON.stringify(data, null, 2) + '\n');
			}
			else await fs.writeFile(path.join(cache, file), bytes);

			return {source, file};
		};

		for(const relative of [...(manifest.tiled?.templates ?? [])
			, ...(manifest.tiled?.tilesets
			?? []
			)
			, ...(manifest.tiled?.previews ?? [])
		])
			previews.push(await preview(relative));
		const record = {source: url, manifest, previews};
		packages.set(url, record);
		visiting.delete(url);
		await fs.writeFile(path.join(cache, key(url) + '.manifest.json'), JSON.stringify(record, null, 2) + '\n');

		return record;
	};

	const primary = await visit(url);

	return {primary: primary.source, packages: [...packages.values()], classes: [...classes.values()], cache};
}

export function validateValue(member, value, source)
{
	const valid = member.type === 'int'
		? Number.isInteger(value)
		: member.type === 'float'
			? typeof value === 'number' && Number.isFinite(value)
			: member.type === 'bool'
				? typeof value === 'boolean'
				: ['string', 'file', 'color'].includes(member.type) ? typeof value === 'string' : false;

	if(!valid) throw new Error(`${source}: Invalid ${member.type} property ${member.name}`);
}

export function validateWorld(world, records)
{
	const config = properties(world.properties), aliases = new Map();

	for(const [name, value] of Object.entries(config))
		if(name.startsWith('phonics.import.'))
		{
			const record = records.find(record => record.source === value);

			if(!record) throw new Error(`Import ${name} has not been cached: ${value}`);

			aliases.set(name.slice(15), record);
		}

	const visited = new Set(), namespaces = new Map();

	const compatible = (record, chain = []) => {
		if(chain.includes(record.source))
		throw new Error(`Package dependency cycle: ${[...chain, record.source].join(' -> ')}`);

		if(visited.has(record.source)) return;

		if(!satisfies('0.1.0', record.manifest.requires?.phonics ?? '^0.1.0'))
		throw new Error(`Incompatible package ${record.source}`);

		for(const dependency of Object.values(record.manifest.dependencies ?? {}))
		{
			const url = new URL(dependency.url, record.source).href;
			const target = records.find(item => item.source === url);

			if(!target || !satisfies(target.manifest.version, dependency.version))
			throw new Error(`Missing or incompatible dependency ${url} from ${record.source}`);

			compatible(target, [...chain,record.source]);
		}

		for(const namespace of Object.keys(record.manifest.php?.autoload ?? {}))
		{
			const normalized = namespace.toLowerCase();

			for(const [other,source] of namespaces)
				if(source !== record.source && (normalized.startsWith(other) || other.startsWith(normalized)))
					throw new Error(`Namespace conflict: ${record.source} and ${source}`);
			namespaces.set(normalized, record.source);
		}

		visited.add(record.source);
	};

	for(const record of aliases.values()) compatible(record);

	const resolve = reference => {
		if(typeof reference !== 'string') throw new Error('Missing export reference');

		const match = /^@([^/]+)\/(.+)$/.exec(reference);
		const url = match ? null : new URL(reference);
		const record = match ? aliases.get(match[1]) : records.find(r => r.source === url.href.split('#')[0]);
		const name = match ? match[2] : decodeURIComponent(url.hash.slice(1));
		const entry = record?.manifest.exports?.[name];

		if(!entry) throw new Error(`Unresolved export ${reference}`);

		compatible(record);

		return entry;
	};

	if(resolve(config['phonics.game']).type !== 'game') throw new Error('phonics.game must select a game export');

	if(!config['phonics.start']) throw new Error('Missing phonics.start');

	if(config['phonics.start'].startsWith('@') && resolve(config['phonics.start']).type !== 'asset')
		throw new Error('phonics.start must select a map asset');

	for(const reference of JSON.parse(config['phonics.modules'] ?? '[]'))
		if(resolve(reference).type !== 'module') throw new Error(`Expected module: ${reference}`);

	return {resolve, config};
}
