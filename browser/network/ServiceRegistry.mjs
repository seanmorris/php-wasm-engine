/** Session-scoped registry for optional browser providers and transports. */
export class ServiceRegistry
{

	/**
	 * Create an empty, open registry.
	 */
	constructor()
	{
		this.services = new Map;
		this.closed = false;
	}

	/**
	 * Register one factory under a service type and ID.
	 * @param {string} type Nonempty service type, such as lobby-provider or transport.
	 * @param {string} id Nonempty service ID unique within its type.
	 * @param {function(object): *} factory Callback that builds the service from create() options.
	 * @returns {function(object): *} The registered factory.
	 * @throws {Error} When the registry is closed, the arguments are invalid, or the key is already registered.
	 */
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

	/**
	 * Remove one registration if it exists.
	 * @param {string} type Service type.
	 * @param {string} id Service ID.
	 * @returns {void}
	 */
	unregister(type, id) { this.services.delete(`${type}:${id}`); }

	/**
	 * Check whether a factory is registered.
	 * @param {string} type Service type.
	 * @param {string} id Service ID.
	 * @returns {boolean} True when the type and ID have a factory.
	 */
	has(type, id) { return this.services.has(`${type}:${id}`); }

	/**
	 * Build a service from its registered factory.
	 * @param {string} type Service type.
	 * @param {string} id Service ID.
	 * @param {object} [options] Value passed unchanged to the factory; defaults to an empty object.
	 * @returns {*} The factory's return value.
	 * @throws {Error} When the registry is closed or no factory is registered.
	 */
	create(type, id, options = {})
	{
		this.requireOpen();
		const factory = this.services.get(`${type}:${id}`);

		if(!factory) throw new Error(`Missing browser service ${type}:${id}`);

		return factory(options);
	}

	/**
	 * List the IDs registered for one type.
	 * @param {string} type Service type.
	 * @returns {string[]} Registered IDs in registration order.
	 */
	list(type)
	{
		const prefix = `${type}:`;

		return [...this.services.keys()].filter(key => key.startsWith(prefix)).map(key => key.slice(prefix.length));
	}

	/**
	 * Close the registry and discard every registration.
	 * @returns {void}
	 */
	close()
	{
		this.closed = true;
		this.services.clear();
	}

	/**
	 * Reject use after close().
	 * @returns {void}
	 * @throws {Error} When the registry is closed.
	 */
	requireOpen()
	{
		if(this.closed) throw new Error('Browser service registry is closed');
	}
}
