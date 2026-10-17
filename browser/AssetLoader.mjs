/** Fetch and mount files selected by PHP. No Tiled parsing or proximity policy lives here. */
export class AssetLoader
{

	constructor(FS, base, {root = '/engine/'} = {})
	{
		this.FS = FS;
		this.base = new URL(base);
		this.root = root;
		this.files = new Map();
		this.references = new Map();
		this.closed = false;
	}

	request(path, retry = false)
	{
		if(this.closed) return 'Asset loader is closed';

		if(!path.startsWith(this.root)
			|| path.slice(this.root.length).split('/').some(part => part === '..' || part === '.')
		){
			return 'Asset path is outside the mounted root';
		}

		const url = new URL(path.slice(this.root.length), this.base);

		if(url.origin !== this.base.origin || !url.pathname.startsWith(this.base.pathname))
		{
			return 'Asset URL is outside the source root';
		}

		let entry = this.files.get(path);
		// PHP may retry a successfully fetched file that failed parsing. Retain its mount
		// until replacement bytes arrive so another scene can still restore its graphics.
		let mounted = entry?.mounted || false;

		if(retry && entry && entry.state !== false)
		{
			entry.controller.abort();
			entry = undefined;
		}

		if(!entry)
		{
			entry = {state: false, mounted, controller: new AbortController()};
			this.files.set(path, entry);

			entry.promise = (async () => {
				try
				{
					const response = await fetch(url, {signal: entry.controller.signal});

					if(!response.ok) throw Error('HTTP ' + response.status + ' ' + url.pathname);

					const bytes = new Uint8Array(await response.arrayBuffer());

					if(this.closed || this.files.get(path) !== entry) return;

					this.FS.mkdirTree(path.slice(0, path.lastIndexOf('/')));
					this.FS.writeFile(path, bytes);
					entry.mounted = true;
					entry.state = true;
				}
				catch(error)
				{
					if(this.files.get(path) === entry) entry.state = error.message || String(error);
				}
			})();
		}

		return entry.state;
	}

	async load(path)
	{
		const state = this.request(path);

		if(typeof state === 'string') throw Error(state);

		const entry = this.files.get(path);
		await entry.promise;

		if(entry.state !== true) throw Error(entry.state || 'Asset request was cancelled');
	}

	/**
* Own a set of files while sharing transfers and mounts with other live scopes.
* @returns {object} Request, progress, release, and close callbacks for a PHP WorldStream.
*/
	scope()
	{
		const owned = new Set();
		let closed = false;

		const release = path => {
			if(!owned.delete(path)) return;

			const count = (this.references.get(path) || 1) - 1;

			if(count > 0) this.references.set(path, count);
			else
			{
				this.references.delete(path);
				this.release(path);
			}
		};

		return {
			// Counts describe dependencies discovered so far, not a fixed download percentage.
			progress: () => {
				let ready = 0, failed = 0;

				for(const path of owned)
				{
					const state = this.files.get(path)?.state;

					if(state === true) ready++;
					else if(typeof state === 'string') failed++;
				}

				return {ready, total: owned.size, failed};
			}
			, request: (path, retry = false) => {
				if(closed) return 'Asset scope is closed';

				const state = this.request(path, retry);

				if(this.files.has(path) && !owned.has(path))
				{
					owned.add(path);
					this.references.set(path, (this.references.get(path) || 0) + 1);
				}

				return state;
			}
			, release
			, close: () => {
				closed = true;

				for(const path of owned) release(path);
			}
		};
	}

	release(path)
	{
		const entry = this.files.get(path);

		if(!entry) return;

		this.files.delete(path);
		entry.controller.abort();

		if(entry.mounted) this.FS.unlink(path);
	}

	close()
	{
		if(this.closed) return;

		this.closed = true;

		for(const path of this.files.keys()) this.release(path);
		this.references.clear();
	}
}
