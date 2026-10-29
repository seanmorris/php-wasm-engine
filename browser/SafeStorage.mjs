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

	/**
	 * Read the session value first, then the persistent value.
	 * @param {*} key Storage key; converted to a string.
	 * @returns {string|null} Stored string, or null when the key was removed, is absent, or storage fails.
	 */
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

	/**
	 * Retain the value for this session and attempt to persist it.
	 * @param {*} key Storage key; converted to a string.
	 * @param {*} value Value to store; converted to a string.
	 * @returns {boolean} True when the persistent store accepted the value; false keeps it for this session only.
	 */
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

	/**
	 * Hide the value for this session and attempt to remove its persistent copy.
	 * @param {*} key Storage key; converted to a string.
	 * @returns {boolean} True when the persistent copy was removed; false hides it for this session only.
	 */
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
