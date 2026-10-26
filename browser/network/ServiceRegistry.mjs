/** Session-scoped registry for optional browser providers and transports. */
export class ServiceRegistry
{

	constructor()
	{
		this.services = new Map;
		this.closed = false;
	}

	register(type, id, factory)
	{
		this.requireOpen();

		if(typeof type !== 'string' || !type || typeof id !== 'string' || !id)
		throw new TypeError('Browser service registrations require type and ID');

		if(typeof factory !== 'function') throw new TypeError(`Browser service ${id} requires a factory`);

		const key = `${type}:${id}`;

		if(this.services.has(key)) throw new Error(`Conflicting browser service ${key}`);

		this.services.set(key, factory);

		return factory;
	}

	unregister(type, id) { this.services.delete(`${type}:${id}`); }

	has(type, id) { return this.services.has(`${type}:${id}`); }

	create(type, id, options = {})
	{
		this.requireOpen();
		const factory = this.services.get(`${type}:${id}`);

		if(!factory) throw new Error(`Missing browser service ${type}:${id}`);

		return factory(options);
	}

	list(type)
	{
		const prefix = `${type}:`;

		return [...this.services.keys()].filter(key => key.startsWith(prefix)).map(key => key.slice(prefix.length));
	}

	close()
	{
		this.closed = true;
		this.services.clear();
	}

	requireOpen()
	{
		if(this.closed) throw new Error('Browser service registry is closed');
	}
}
