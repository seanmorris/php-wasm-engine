/** Canonical URLs identify shared bytes; scopes own cancellable references, not cache paths. */
export class ResourceStore
{

	/**
	 * Create a canonical byte cache shared by independently owned scopes.
	 * @param {object} [options] Transport settings.
	 * @param {typeof fetch} [options.fetch] Fetch-compatible transport.
	 * @param {number} [options.retries=1] Automatic retries after the first failed request.
	 */
	constructor({fetch: fetcher = globalThis.fetch, retries = 1} = {})
	{

		this.fetch = (...args) => fetcher(...args);
		this.retries = retries;
		this.entries = new Map();
	}

	/**
	 * Create a cancellable ownership scope over shared requests.
	 * @param {AbortSignal} [signal] Host signal that closes the scope.
	 * @returns {{read: function(string, object=): Promise<Uint8Array>, release: function(string): void,
	 * close: function(): void}} Scoped reader and explicit ownership controls.
	 */
	scope(signal)
	{
		const controller = new AbortController();
		signal = signal ? AbortSignal.any([signal, controller.signal]) : controller.signal;
		const owned = new Map();
		let closed = false;

		const release = url => {
			const entry = owned.get(url);

			if(!entry) return;

			owned.delete(url);

			if(--entry.refs === 0)
			{
				entry.controller.abort();

				if(this.entries.get(url) === entry) this.entries.delete(url);
			}
		};

		const close = () => {
			if(closed) return;

			closed = true;
			controller.abort(new Error('Resource scope is closed'));

			for(const url of owned.keys()) release(url);
			signal?.removeEventListener('abort', close);
		};

		signal?.addEventListener('abort', close, {once: true});

		return {
			close
			, release
			, read: async (reference, {integrity, retry = false, reload = false, retries = this.retries} = {}) => {
				signal?.throwIfAborted();

				if(closed) throw new Error('Resource scope is closed');

				const url = new URL(reference);
				url.hash = '';
				const key = url.href;

				if(reload || (retry && owned.get(key)?.failed)) release(key);

				let entry = reload ? null : (owned.get(key) ?? this.entries.get(key));

				if(entry?.failed && retry) entry = null;

				if(!entry)
				{
					entry = {refs: 0, controller: new AbortController()};
					const current = entry;

					entry.promise = (async () => {
						for(let attempt = 0; ; attempt++)
						{
							try
							{
								const response = await this.fetch(
									key
									, {signal: current.controller.signal, redirect: 'error'}
								);

								if(!response.ok) throw new Error(`HTTP ${response.status}: ${key}`);

								return new Uint8Array(await response.arrayBuffer());
							}
							catch(error)
							{
								if(current.controller.signal.aborted || attempt >= retries)
								{
									current.failed = true;
									throw new Error(`Resource ${key}: ${error.message}`, {cause: error});
								}
							}
						}
					})();

					this.entries.set(key, entry);
				}

				if(!owned.has(key))
				{
					owned.set(key, entry);
					entry.refs++;
				}

				let abort;

				const cancelled = new Promise((_, reject) => {

					abort = () => reject(signal.reason);
					signal?.addEventListener('abort', abort, {once: true});
				});

				try
				{
					const bytes = await Promise.race([entry.promise, cancelled]);
					signal?.throwIfAborted();

					if(closed) throw new Error('Resource scope is closed');

					if(integrity)
					{
						try
						{
							await verify(bytes, integrity, key);
						}
						catch(error)
						{
							entry.failed = true;
							throw error;
						}
					}

					return bytes;
				}
				finally
				{
					signal?.removeEventListener('abort', abort);
				}
			}
		};
	}
}

/**
 * Verify bytes against strict SHA-256 Subresource Integrity metadata.
 * @param {Uint8Array} bytes Bytes to hash.
 * @param {string} integrity Base64 sha256-* integrity value.
 * @param {string} url Resource URL included in errors.
 * @returns {Promise<void>} Resolves only when the digest matches.
 * @throws {Error} For invalid metadata or a mismatched digest.
 */
export async function verify(bytes, integrity, url)
{
	if(!/^sha256-[A-Za-z0-9+/]{43}=$/.test(integrity)) throw new Error(`Invalid integrity metadata: ${url}`);

	const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', bytes));
	const actual = 'sha256-' + btoa(String.fromCharCode(...digest));

	if(actual !== integrity) throw new Error(`Integrity mismatch: ${url}`);
}
