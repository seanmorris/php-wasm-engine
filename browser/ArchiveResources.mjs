import {ResourceStore} from './ResourceStore.mjs';

/**
 * Map packaged bytes to their public provenance without exposing temporary filesystem paths.
 *
 * The adapter retains canonical source URLs for diagnostics and integrity while serving immutable archive
 * members to the synchronous PHP resource bridge.
 */
export class ArchiveResources
{
	/** Public canonical URL from which the application archive was loaded. */
	url;

	/** Stable application identifier embedded in the archive descriptor. */
	id;

	/** Application entrypoint path embedded in the archive descriptor. */
	code;

	/** Callback that reads one immutable archive member by path. */
	read;

	/** Archive member metadata indexed by normalized member path. */
	members;

	/** Archive object URLs indexed by normalized member path. */
	urls;

	/** Abort controller governing archive and fallback resource reads. */
	controller;

	/** Canonical URL of the core Phonics package in the archive. */
	coreUrl;

	/** Canonical URL of the application package in the archive. */
	applicationUrl;

	/** Shared canonical resource store backing this resolver. */
	store;


	/** Create an archive-backed resource adapter with canonical core and application provenance. */
	constructor({url, descriptor, read, store, signal})
	{
		if(descriptor.abi !== 1) throw new Error('Unsupported application bridge version');

		Object.assign(this, {url, id: descriptor.id, code: descriptor.code, read});
		this.members = new Map();
		this.urls = new Map();
		this.controller = new AbortController();
		signal = signal ? AbortSignal.any([signal, this.controller.signal]) : this.controller.signal;

		const packages = descriptor.packages.map(record => ({
			...record
			, prefix: record.path.slice(0, record.path.lastIndexOf('/') + 1)
			, url: new URL('phonics.json', new URL(record.base, url)).href
		}));

		for(const member of descriptor.members)
		{
			const record = packages.find(record => member.startsWith(record.prefix));

			if(!record) continue;

			const source = new URL(member.slice(record.prefix.length), record.url).href;

			if(this.members.has(source)) throw new Error('Conflicting packaged resource: ' + source);

			this.members.set(source, member);
			this.urls.set(member, source);
		}

		this.coreUrl = packages.find(record => record.name === '@seanmorris/phonics')?.url;
		this.applicationUrl = packages.find(record => record.path === descriptor.application)?.url;

		this.store = new ResourceStore({
			retries: store.retries
			, fetch: async (reference, options) => {
				signal.throwIfAborted();
				options.signal.throwIfAborted();
				const member = this.member(reference);
				const remote = member ? null : store.scope(AbortSignal.any([signal, options.signal]));

				try
				{
					const bytes = member ? await read(member) : await remote.read(reference, {retries: 0});
					signal.throwIfAborted();
					options.signal.throwIfAborted();

					return new Response(bytes);
				}
				finally
				{
					// The outer store owns these bytes; do not pin a second copy or failed request.
					remote?.close();
				}
			}
		});
	}

	/** Resolve and validate one normalized archive member name. */
	member(reference) { const url = new URL(reference); url.hash = ''; return this.members.get(url.href); }

	/** Return the PHP stream URL for one packaged member. */
	stream(reference)
	{
		const member = this.member(reference);

		return member ? 'phar:///phonics/application.phar/' + member : null;
	}

	/** Return the public source URL represented by one packaged member. */
	source(reference)
	{
		const prefix = 'phar:///phonics/application.phar/';

		if(reference.startsWith(prefix))
		{
			const [member, fragment] = reference.slice(prefix.length).split('#');
			const source = this.urls.get(member);

			if(!source) throw new Error('Undeclared archive member: ' + member);

			return source + (fragment ? '#' + fragment : '');
		}

		return new URL(reference, this.url).href;
	}

	/** Release archive members retained in the shared resource store. */
	close() { this.controller.abort(new Error('Application resources are closed')); }
}
