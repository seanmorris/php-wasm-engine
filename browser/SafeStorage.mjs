/** Failure-tolerant string storage with a session overlay and removal tombstones. */
export class SafeStorage
{

	/**
	 * Wrap browser storage without allowing access, quota, or security failures to escape.
	 * @param {Storage|null|undefined} [storage] Persistent backing store; localStorage is used when omitted.
	 */
	constructor(storage = undefined)
	{
		this.memory = new Map();
		this.removed = new Set();
		this.storage = null;
		this.sessionOnly = false;

		try
		{
			this.storage = storage === undefined ? globalThis.localStorage : storage;
			this.sessionOnly = !this.storage;
		}
		catch
		{
			this.sessionOnly = true;
		}
	}

	/** Read the session value first, then the persistent value. */
	get(key)
	{
		key = String(key);

		if(this.removed.has(key)) return null;

		if(this.memory.has(key)) return this.memory.get(key);

		try
		{
			return this.storage?.getItem(key) ?? null;
		}
		catch
		{
			this.sessionOnly = true;

			return null;
		}
	}

	/** Retain the value for this session and attempt to persist it. */
	set(key, value)
	{
		key = String(key);
		value = String(value);
		this.memory.set(key, value);
		this.removed.delete(key);

		try
		{
			if(!this.storage) throw new Error('Storage unavailable');

			this.storage.setItem(key, value);
			this.sessionOnly = false;

			return true;
		}
		catch
		{
			this.sessionOnly = true;

			return false;
		}
	}

	/** Hide the value for this session and attempt to remove its persistent copy. */
	remove(key)
	{
		key = String(key);
		this.memory.delete(key);
		this.removed.add(key);

		try
		{
			if(!this.storage) throw new Error('Storage unavailable');

			this.storage.removeItem(key);
			this.sessionOnly = false;

			return true;
		}
		catch
		{
			this.sessionOnly = true;

			return false;
		}
	}
}
