import {ResourceStore} from './ResourceStore.mjs';

/** Map packaged bytes to their public provenance without exposing temporary filesystem paths. */
export class ArchiveResources
{

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

	member(reference) { const url = new URL(reference); url.hash = ''; return this.members.get(url.href); }

	stream(reference)
	{
		const member = this.member(reference);

		return member ? 'phar:///phonics/application.phar/' + member : null;
	}

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

	close() { this.controller.abort(new Error('Application resources are closed')); }
}
