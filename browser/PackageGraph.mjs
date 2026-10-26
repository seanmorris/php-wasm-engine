/** Package-contract version understood by this runtime. */
export const VERSION = '0.1.0';

/**
 * Convert a Tiled custom-property array into a name/value object.
 * @param {Array<{name: string, value: *}>} [value] Tiled property records.
 * @returns {Record<string, *>} Last value for each property name.
 */
export const properties = value => Object.fromEntries((value ?? []).map(p => [p.name, p.value]));

/**
 * Test an exact, caret, or minimum version requirement.
 * @param {string} version Three-part semantic version to test.
 * @param {string} range Exact version, =version, ^version, or >=version requirement.
 * @returns {boolean} Whether the version satisfies the deliberately small runtime grammar.
 */
export function satisfies(version, range)
{
	if(!/^\d+\.\d+\.\d+$/.test(version)) return false;

	const match = /^(\^|>=|=)?(\d+)\.(\d+)\.(\d+)$/.exec(range);

	if(!match) return false;

	const current = version.split('.').map(Number), wanted = match.slice(2).map(Number);
	const compare = current.reduce((result, n, i) => result || Math.sign(n - wanted[i]), 0);

	if(match[1] === '>=') return compare >= 0;

	if(match[1] !== '^') return compare === 0;

	return compare >= 0 && current[0] === wanted[0]
		&& (wanted[0] !== 0 || current[1] === wanted[1])
		&& (wanted[0] !== 0 || wanted[1] !== 0 || current[2] === wanted[2]);
}

/**
 * Validate a manifest-relative package path.
 * @param {string} path Unencoded relative path from a package manifest.
 * @returns {string} The unchanged validated path.
 * @throws {Error} When the value is absolute, encoded, empty, or contains traversal, queries, or fragments.
 */
export function packagePath(path)
{
	if(typeof path !== 'string' || !path || /[?#\\:]/.test(path)
		|| path.startsWith('/') || path.split('/').some(p => !p || p === '.' || p === '..')
		|| decodeURIComponent(path) !== path
	) throw new Error(`Invalid package path: ${path}`);

	return path;
}

/** Resolve imports and the complete executable graph before activating any code. */
export class PackageGraph
{

	/**
	 * Create an empty graph bound to one cancellable resource scope.
	 * @param {object} scope ResourceStore scope providing read().
	 * @param {object} [options] Host policy and runtime capability values.
	 * @param {Iterable<string>} [options.codeOrigins] Origins allowed to supply executable files.
	 * @param {string} [options.origin] Default allowed origin.
	 * @param {string} [options.phpVersion='8.4.0'] Runtime PHP version.
	 * @param {Iterable<string>} [options.extensions] Available PHP extension names.
	 */
	constructor(scope, {codeOrigins, origin, phpVersion = '8.4.0', extensions = ['sdl', 'vrzno']} = {})
	{
		this.scope = scope;
		this.codeOrigins = new Set(codeOrigins ?? [origin]);
		this.phpVersion = phpVersion;
		this.extensions = extensions;
		this.packages = new Map();
		this.aliases = new Map();
		this.namespaces = new Map();
		this.order = [];
		this.active = [];
		this.activated = new Set();
	}

	/**
	 * Enforce the host's executable-code origin policy.
	 * @param {string|URL} url Executable manifest, PHP, browser, or runtime URL.
	 * @returns {void} Returns after validating an HTTP(S) URL on an allowed origin.
	 * @throws {Error} When the protocol or origin is not allowed.
	 */
	policy(url)
	{
		const parsed = new URL(url);

		if(!['https:', 'http:'].includes(parsed.protocol) || !this.codeOrigins.has(parsed.origin))
			throw new Error(`Executable code origin is not allowed: ${url}`);
	}

	/**
	 * Resolve and validate a manifest and all declared package dependencies.
	 * @param {string|URL} reference Manifest reference.
	 * @param {string[]} [chain] Internal dependency chain used in diagnostics.
	 * @returns {Promise<object>} Canonical package record shared by every reference to that URL.
	 * @throws {Error} For unsupported requirements, cycles, namespace conflicts, or malformed manifests.
	 */
	async manifest(reference, chain = [])
	{
		const source = new URL(reference);
		source.hash = '';
		const url = source.href;
		this.policy(url);

		if(chain.includes(url)) throw new Error(`Package dependency cycle: ${[...chain, url].join(' -> ')}`);

		if(this.packages.has(url)) return this.packages.get(url);

		try
		{
			const data = JSON.parse(new TextDecoder().decode(await this.scope.read(url)));

			if(data.schemaVersion !== 1 || !data.name || !satisfies(data.version, '>=' + data.version))
				throw new Error('Expected a versioned Phonics manifest (schemaVersion 1)');

			if(!satisfies(VERSION, data.requires?.phonics ?? '=0.1.0')
				|| !satisfies(this.phpVersion, data.requires?.php ?? '>=8.0.0')
			)
				throw new Error(`Unsupported runtime requirements for ${data.name}@${data.version}`);

			for(const extension of data.requires?.extensions ?? [])
				if(!this.extensions.includes(extension)) throw new Error(`Missing runtime extension ${extension}`);
			const record = {url, data, dependencies: new Map(), mount: `/phonics/packages/${this.packages.size}/`};
			this.packages.set(url, record);

			for(const [alias, dependency] of Object.entries(data.dependencies ?? {}))
			{
				const target = await this.manifest(new URL(dependency.url, url), [...chain, url]);

				if(!satisfies(target.data.version, dependency.version))
				throw new Error(`Incompatible dependency ${alias}`);

				record.dependencies.set(alias, target);
			}

			for(const namespace of Object.keys(data.php?.autoload ?? {}))
			{
				if(!/^(?:[A-Za-z_]\w*\\)+$/.test(namespace)) throw new Error(`Invalid PHP namespace ${namespace}`);

				const normalized = namespace.toLowerCase();

				for(const [other, owner] of this.namespaces)
				{
					if((normalized.startsWith(other) || other.startsWith(normalized)) && owner.url !== url)
						throw new Error(`Incompatible PHP namespace ${namespace}: ${owner.url} and ${url}`);
				}

				this.namespaces.set(normalized, record);
			}

			for(const [name, entry] of Object.entries(data.exports ?? {}))
			{
				if(![
					'game'
					, 'module'
					, 'factory'
					, 'component'
					, 'asset'
					, 'adapter'
					, 'system-set'
					, 'lobby-provider'
					, 'transport'
					, 'resource-inspector'
				].includes(entry.type))
					throw new Error(`Invalid export type: ${name}`);

				for(const path of [entry.php, entry.browser, entry.path].filter(Boolean)) packagePath(path);
			}

			this.order.push(record);

			return record;
		}
		catch(error)
		{
			this.packages.delete(url);
			throw new Error(`${[...chain, url].join(' -> ')}: ${error.message}`, {cause: error});
		}
	}

	/**
	 * Register aliases declared by a neutral or legacy Tiled world.
	 * @param {object} world Parsed neutral or Tiled world.
	 * @param {string} url Canonical declaring world URL.
	 * @returns {Promise<void>} Resolves all imported manifests before returning.
	 * @throws {Error} For invalid, duplicate, or unloadable imports.
	 */
	async imports(world, url)
	{
		const imports = world.type === 'phonics-world'
			? Object.entries(world.imports ?? {}).map(([alias, value]) => ['phonics.import.' + alias, value])
			: Object.entries(properties(world.properties));

		for(const [name, value] of imports)
		{
			if(!name.startsWith('phonics.import.')) continue;

			const alias = name.slice(15);

			if(!/^[\w-]+$/.test(alias) || this.aliases.has(alias))
			throw new Error(`Invalid or conflicting import ${name}`);

			this.aliases.set(alias, await this.manifest(new URL(value, url)));
		}
	}

	/**
	 * Resolve an @alias/export or manifest-fragment reference.
	 * @param {string} reference Pallet or direct export reference.
	 * @param {string} declaring Canonical URL of the resource containing the reference.
	 * @param {object} [owner] Owning package record used for dependency-local aliases.
	 * @returns {Promise<object>} Typed export record with stable manifest-fragment identity.
	 * @throws {Error} When the import or export is missing or malformed.
	 */
	async resolve(reference, declaring, owner)
	{
		let record, name;

		if(reference.startsWith('@'))
		{
			const match = /^@([^/]+)\/(.+)$/.exec(reference);

			if(!match) throw new Error(`Invalid pallet reference ${reference} in ${declaring}`);

			record = owner?.dependencies.get(match[1]) ?? this.aliases.get(match[1]);

			if(!record) throw new Error(`Missing import ${match[1]} in ${declaring}`);

			name = match[2];
		}
		else
		{
			const url = new URL(reference, declaring);
			name = decodeURIComponent(url.hash.slice(1));
			url.hash = '';
			record = await this.manifest(url);
		}

		const entry = record.data.exports?.[name];

		if(!entry) throw new Error(`Missing export ${name} in ${record.url}, declared by ${declaring}`);

		return {record, name, ...entry, id: `${record.url}#${name}`};
	}

	/**
	 * Resolve an already declared asset export synchronously after graph discovery.
	 * @param {string} reference Pallet, manifest-fragment, or ordinary URL reference.
	 * @param {string} declaring Canonical declaring-resource URL.
	 * @returns {string} Canonical asset URL.
	 * @throws {Error} When a pallet/export is missing or does not identify an asset.
	 */
	asset(reference, declaring)
	{
		let record, name;

		if(reference.startsWith('@'))
		{
			const match = /^@([^/]+)\/(.+)$/.exec(reference);

			if(!match) throw new Error(`Invalid pallet reference ${reference} in ${declaring}`);

			const owner = [...this.packages.values()].sort((a, b) => b.url.length-a.url.length)
				.find(packageRecord => declaring?.startsWith(new URL('.', packageRecord.url).href));

			record = owner?.dependencies.get(match[1]) ?? this.aliases.get(match[1]);
			name = match[2];

			if(!record) throw new Error(`Missing import ${match[1]} in ${declaring}`);
		}
		else
		{
			const target = new URL(reference, declaring);
			const manifest = new URL(target);
			manifest.hash = '';
			record = this.packages.get(manifest.href);

			if(!record || !target.hash) return target.href;

			name = decodeURIComponent(target.hash.slice(1));
		}

		const entry = record.data.exports?.[name];

		if(!entry) throw new Error(`Missing export ${name} in ${record.url}, declared by ${declaring}`);

		if(entry.type !== 'asset') throw new Error(`Expected asset export: ${record.url}#${name}`);

		return new URL(entry.path, record.url).href;
	}

	/**
	 * Topologically select an export and its prerequisite modules for activation.
	 * @param {object} entry Resolved game, module, component, or factory export.
	 * @param {string[]} [chain] Internal export chain used for cycle diagnostics.
	 * @param {Set<string>} [done] Export identities already activated.
	 * @returns {Promise<void>} Appends dependencies before dependants in active.
	 * @throws {Error} For module cycles or references to non-module prerequisites.
	 */
	async activateGraph(entry, chain = [], done = this.activated)
	{
		if(chain.includes(entry.id)) throw new Error(`Module cycle: ${[...chain, entry.id].join(' -> ')}`);

		if(done.has(entry.id)) return;

		for(const reference of entry.modules ?? [])
		{
			const dependency = await this.resolve(reference, entry.record.url, entry.record);

			if(dependency.type !== 'module') throw new Error(`Expected module: ${dependency.id}`);

			await this.activateGraph(dependency, [...chain, entry.id], done);
		}

		if(entry.module)
		{
			const module = await this.resolve(entry.module, entry.record.url, entry.record);

			if(module.type !== 'module') throw new Error(`Expected module: ${module.id}`);

			await this.activateGraph(module, [...chain, entry.id], done);
		}

		done.add(entry.id);
		this.active.push(entry);
	}

	/**
	 * Fetch and integrity-check every executable file before code activation.
	 * @param {function({completed: number, total: number, url: string|null,
	 * state: string}): void} [onProgress] Receives planned, loading, and verified file counts.
	 * @returns {Promise<object[]>} Verified bytes and isolated mount paths in package order.
	 * @throws {Error} For undeclared bootstraps, missing integrity, policy failures, or missing executable files.
	 */
	async files(onProgress)
	{
		const planned = [];

		for(const record of this.order)
		{
			for(const bootstrap of record.data.php?.bootstrap ?? [])
			{
				packagePath(bootstrap);

				if(!record.data.php.files?.includes(bootstrap))
					throw new Error(`Undeclared PHP bootstrap ${bootstrap} in ${record.url}`);
			}

			for(const path of new Set([...(record.data.php?.files ?? []), ...(record.data.browser?.files ?? [])]))
			{
				packagePath(path);
				const url = new URL(path, record.url).href;
				this.policy(url);
				const integrity = record.data.integrity?.[path];

				if(!integrity) throw new Error(`Missing integrity for executable ${url}`);

				planned.push({url, path: record.mount + path, integrity, record});
			}
		}

		for(const entry of this.active)
			for(const path of [entry.php, entry.browser].filter(Boolean))
				if(!planned.some(f => f.record === entry.record && f.url === new URL(path, entry.record.url).href))
					throw new Error(`Export ${entry.id} is missing declared executable file ${path}`);
		const files = [];
		let completed = 0;

		onProgress?.({completed, total: planned.length, url: null, state: 'planned'});

		for(const file of planned)
		{
			onProgress?.({completed, total: planned.length, url: file.url, state: 'loading'});
			const bytes = await this.scope.read(file.url, {integrity: file.integrity});
			files.push({url: file.url, path: file.path, bytes, record: file.record});
			completed++;
			onProgress?.({completed, total: planned.length, url: file.url, state: 'verified'});
		}

		return files;
	}
}
