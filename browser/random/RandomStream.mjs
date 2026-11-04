/** Versioned public/local deterministic sampling. Never use a disclosed seed for private-deck secrets. */
export class RandomStream
{
	#seed;
	#domain;
	#position = 0;
	#prefix;
	#cachedIndex = -1;
	#cachedBlock;

	/**
	 * Create an independent deterministic stream from explicit seed bytes.
	 * @param {string|Uint8Array} seed UTF-8 text or raw bytes supplied by the caller.
	 * @param {string} domain UTF-8 purpose used to separate independent streams.
	 */
	constructor(seed, domain = 'default')
	{
		if(typeof seed !== 'string' && !(seed instanceof Uint8Array)) throw new TypeError('Invalid stream seed');

		this.#seed = typeof seed === 'string' ? new TextEncoder().encode(seed) : new Uint8Array(seed);

		if(typeof domain !== 'string') throw new TypeError('Invalid stream domain');

		const purpose = new TextEncoder().encode(domain);

		if(new TextDecoder().decode(purpose) !== domain) throw new TypeError('Invalid domain Unicode');

		if(!this.#seed.length || this.#seed.length > 1024 || purpose.length > 256)
		throw new RangeError('Invalid random stream seed or domain');

		this.#domain = domain;
		const header = new TextEncoder().encode('Phonics.RandomStream.v1\0');
		this.#prefix = new Uint8Array(header.length + 8 + this.#seed.length + purpose.length);
		this.#prefix.set(header);
		const view = new DataView(this.#prefix.buffer);
		view.setUint32(header.length, this.#seed.length);
		this.#prefix.set(this.#seed, header.length + 4);
		view.setUint32(header.length + 4 + this.#seed.length, purpose.length);
		this.#prefix.set(purpose, header.length + 8 + this.#seed.length);
	}

	/**
	 * Reserve and read deterministic bytes, advancing the saved position.
	 * @param {number} length Byte count from zero through one MiB.
	 * @returns {Promise<Uint8Array>} Bytes from the reserved interval.
	 */
	async nextBytes(length)
	{
		if(!Number.isSafeInteger(length) || length < 0 || length > 1048576 || this.#position > 2147483647-length)
			throw new RangeError('Random stream request exceeds its bounds');

		// Reserve the requested interval synchronously so overlapping callers cannot reuse bytes.
		let position = this.#position;
		this.#position += length;
		const output = new Uint8Array(length);

		for(let written = 0; written < length;)
		{
			const index = Math.floor(position/32), offset = position%32;
			let block;

			if(this.#cachedIndex === index) block = this.#cachedBlock;
			else
			{
				const input = new Uint8Array(this.#prefix.length + 4);
				input.set(this.#prefix);
				new DataView(input.buffer).setUint32(this.#prefix.length, index);
				block = new Uint8Array(await crypto.subtle.digest('SHA-256', input));
				this.#cachedIndex = index;
				this.#cachedBlock = block;
			}

			const take = Math.min(32-offset, length-written);
			output.set(block.subarray(offset, offset+take), written);
			written += take;
			position += take;
		}

		return output;
	}

	/**
	 * Sample an unbiased integer with rejection of the incomplete residue range.
	 * @param {number} bound Exclusive upper bound, from one through 2^31-1.
	 * @returns {Promise<number>} Integer in the half-open range [0, bound).
	 */
	async boundedInt(bound)
	{
		if(!Number.isInteger(bound) || bound < 1 || bound > 2147483647) throw new RangeError('Invalid random bound');

		const limit = Math.floor(4294967296/bound)*bound;
		let value;

		do
		{
			value = new DataView((await this.nextBytes(4)).buffer).getUint32(0);
		}
		while(value >= limit);

		return value%bound;
	}

	/**
	 * Roll a die with equally likely faces.
	 * @param {number} sides Positive face count.
	 * @returns {Promise<number>} One-based face number.
	 */
	async dice(sides = 6) { return await this.boundedInt(sides) + 1; }

	/**
	 * Shuffle a copy of a canonically ordered collection with Fisher-Yates.
	 * @param {Array<unknown>} values Ordered input instances.
	 * @returns {Promise<Array<unknown>>} A permutation preserving every input instance.
	 */
	async shuffle(values)
	{
		const result = [...values];

		for(let i = result.length-1; i > 0; i--)
		{
			const j = await this.boundedInt(i+1);
			[result[i],result[j]] = [result[j],result[i]];
		}

		return result;
	}

	/**
	 * Sample distinct positions without replacing an input instance.
	 * @param {Array<unknown>} values Ordered finite population; equal faces may be distinct instances.
	 * @param {number} count Number of instances to select.
	 * @returns {Promise<Array<unknown>>} The selected sample.
	 */
	async sampleWithoutReplacement(values, count)
	{
		if(!Number.isInteger(count) || count < 0 || count > values.length) throw new RangeError('Invalid sample size');

		return (await this.shuffle(values)).slice(0, count);
	}

	/**
	 * Serialize the exact stream position without consuming bytes.
	 * @returns {object} Version, base64 seed, domain and absolute byte position.
	 */
	snapshot()
	{
		return {
			version: 1
			, seed: btoa(String.fromCharCode(...this.#seed))
			, domain: this.#domain
			, position: this.#position
		};
	}

	/**
	 * Restore a stream from a validated versioned snapshot.
	 * @param {object} state Serialized stream identity and byte position.
	 * @returns {RandomStream} An independent stream at the saved position.
	 */
	static restore(state)
	{
		if(state?.version !== 1 || typeof state.seed !== 'string' || typeof state.domain !== 'string'
			|| !Number.isInteger(state.position) || state.position < 0 || state.position > 2147483647
			|| !/^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(state.seed)
		)
			throw new TypeError('Invalid random stream snapshot');

		const result = new RandomStream(Uint8Array.from(atob(state.seed), char => char.charCodeAt(0)), state.domain);
		result.#position = state.position;

		return result;
	}
}
