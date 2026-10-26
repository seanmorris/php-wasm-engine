/** Adapt canonical resources to the existing synchronous PHP streaming poll protocol. */
export class SessionAssets
{

	/**
	 * Bridge canonical URLs, package-relative layouts, and one WASM filesystem.
	 * @param {object} FS Emscripten filesystem facade.
	 * @param {ResourceStore} store Shared canonical byte store.
	 * @param {object[]} packages Resolved package records and isolated mount roots.
	 * @param {AbortSignal} signal Owning session signal.
	 * @param {function(string): string} [resolveReference] Pallet/direct asset resolver.
	 */
	constructor(FS, store, packages, signal, resolveReference = reference => new URL(reference).href)
	{
		Object.assign(this, {FS, store, packages, signal, resolveReference});
		this.entries = new Map();
		this.sources = new Map();
		this.mounts = new Map();
	}

	/**
	 * Recover canonical source identity from a URL or WASM mount path.
	 * @param {string} path Canonical URL, package mount, or resource mount.
	 * @returns {string} Canonical source URL.
	 */
	source(path)
	{
		if(this.sources.has(path)) return this.sources.get(path);

		for(const record of this.packages)
			if(path.startsWith(record.mount)) return new URL(path.slice(record.mount.length), record.url).href;

		return this.resolveReference(path);
	}

	/**
	 * Allocate or recover an isolated WASM path for a canonical resource.
	 * @param {string} url Canonical URL or known mount path.
	 * @returns {string} Stable path preserving package-relative layout when possible.
	 */
	path(url)
	{
		url = this.source(url);

		if(this.mounts.has(url)) return this.mounts.get(url);

		for(const record of [...this.packages].sort((a, b) => b.url.length - a.url.length))
		{
			const base = new URL('.', record.url).href;

			if(url.startsWith(base) && !/[?#]/.test(url.slice(base.length)))
			{
				const path = record.mount + decodeURIComponent(url.slice(base.length));
				this.bind(url, path);

				return path;
			}
		}

		const path = '/phonics/resources/' + encodeURIComponent(url);
		this.bind(url, path);

		return path;
	}

	/**
	 * Bind an already mounted file to its canonical source URL.
	 * @param {string} url Canonical source URL.
	 * @param {string} path WASM filesystem path.
	 * @returns {void} Records both lookup directions.
	 * @throws {Error} When the path is already owned by another source.
	 */
	bind(url, path)
	{
		if(this.sources.has(path) && this.sources.get(path) !== url) throw new Error(`Conflicting mount ${path}`);

		this.mounts.set(url, path);
		this.sources.set(path, url);
	}

	/**
	 * Find package-published integrity metadata for a resource.
	 * @param {string} url Canonical resource URL.
	 * @returns {string|undefined} Integrity value when the resource is declared by a package.
	 */
	integrity(url)
	{
		for(const record of this.packages)
			for(const [path, integrity] of Object.entries(record.data.integrity ?? {}))
				if(new URL(path, record.url).href === url) return integrity;
	}

	/**
	 * Create a reference-counted mount scope for one scene or subsystem.
	 * @returns {{request: function(string, boolean=): (boolean|string), release: function(string): void,
	 * load: function(string): Promise<void>, progress: function(): {total: number, ready: number, failed: number},
	 * close: function(): void}} Synchronous polling and asynchronous loading operations sharing ownership.
	 */
	scope()
	{
		const owned = new Set();
		let closed = false;

		const request = (reference, retry = false) => {
			if(closed) return 'Asset scope is closed';

			try
			{
				const url = this.source(reference), path = this.path(url);
				let entry = this.entries.get(url);

				if(retry && entry && entry.state !== false)
				{
					entry.state = false;
					entry.promise = null;
				}

				if(!entry)
				{
					entry = {state: false, refs: 0, transport: this.store.scope(this.signal)};
					this.entries.set(url, entry);
				}

				if(!owned.has(url))
				{
					owned.add(url);
					entry.refs++;
				}

				if(!entry.promise)
				{
					entry.promise = entry.transport.read(
						url
						, {integrity: this.integrity(url), retry, reload: retry, retries: 0}
					).then(
						bytes => {
							if(this.entries.get(url) !== entry) return;

							this.FS.mkdirTree(path.slice(0, path.lastIndexOf('/')));
							this.FS.writeFile(path, bytes);
							entry.mounted = true;
							entry.state = true;
						}
						, error => { entry.state = error.message; }
					);
				}

				return entry.state;
			}
			catch(error)
			{
				return error.message;
			}
		};

		const release = reference => {
			const url = this.source(reference);

			if(!owned.delete(url)) return;

			const entry = this.entries.get(url);

			if(entry && --entry.refs === 0)
			{
				this.entries.delete(url);
				entry.transport.close();

				if(entry.mounted) this.FS.unlink(this.path(url));
			}
		};

		return {
			request
			, release
			, load: async reference => {
				const state = request(reference);

				if(typeof state === 'string') throw new Error(state);

				const entry = this.entries.get(this.source(reference));
				await entry.promise;

				if(entry.state !== true) throw new Error(entry.state ?? 'Cancelled resource');
			}
			, progress: () => ({
				total: owned.size
				, ready: [...owned].filter(url => this.entries.get(url)?.state === true).length
				, failed: [...owned].filter(url => typeof this.entries.get(url)?.state === 'string').length
			})
			, close: () => { closed = true; for(const url of owned) release(url); }
		};
	}
}
