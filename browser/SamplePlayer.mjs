/** Lazy, failure-tolerant Web Audio playback for package-local effects. */
export class SamplePlayer
{

	/**
	 * Create a lazy sample cache for assets mounted in the PHP filesystem.
	 *
	 * @param {object} module php-sdl-wasm module exposing FS.readFile().
	 * @param {object} assets SessionAssets path adapter.
	 * @param {string} packageUrl Declaring package manifest URL.
	 * @param {{muted?: boolean, contextFactory?: function(): AudioContext}} [options]
	 */
	constructor(module, assets, packageUrl, options = {})
	{
		this.module = module;
		this.assets = assets;
		this.packageUrl = packageUrl;
		this.muted = Boolean(options.muted);
		this.contextFactory = options.contextFactory ?? (() => new AudioContext);
		this.context = null;
		this.samples = new Map;
		this.closed = false;
	}

	/** Create or resume the shared context after a browser user gesture. */
	async unlock()
	{
		if(this.closed || this.muted || typeof AudioContext === 'undefined' && !this.contextFactory) return false;

		try
		{
			this.context ??= this.contextFactory();
			await this.context.resume?.();

			return this.context.state !== 'closed';
		}
		catch
		{
			return false;
		}
	}

	/** Decode one mounted package asset once. Failures remain silent and cached. */
	async load(name, path)
	{
		name = String(name);

		if(this.samples.has(name)) return this.samples.get(name);

		const pending = (async() => {
			if(!await this.unlock() || !this.context) return null;

			try
			{
				const url = new URL(path, this.packageUrl).href;
				const bytes = this.module.FS.readFile(this.assets.path(url));

				return await this.context.decodeAudioData(bytes.slice().buffer);
			}
			catch
			{
				return null;
			}
		})();

		this.samples.set(name, pending);

		return pending;
	}

	/** Play a decoded effect through a short-lived gain node. */
	async play(name, {gain = 1} = {})
	{
		if(this.closed || this.muted || !Number.isFinite(gain) || gain <= 0) return false;

		const sample = await this.samples.get(String(name));

		if(!sample || !await this.unlock() || this.closed || this.muted) return false;

		try
		{
			const source = this.context.createBufferSource();
			const volume = this.context.createGain();
			source.buffer = sample;
			volume.gain.value = gain;
			source.connect(volume).connect(this.context.destination);
			source.start();

			source.onended = () => {
				source.disconnect();
				volume.disconnect();
			};

			return true;
		}
		catch
		{
			return false;
		}
	}

	/** Change mute state without discarding decoded samples. */
	setMuted(muted)
	{
		this.muted = Boolean(muted);

		if(!this.muted) void this.unlock();
	}

	/** Close the owned AudioContext and discard all sample promises. */
	async close()
	{
		if(this.closed) return;

		this.closed = true;
		this.samples.clear();
		const context = this.context;
		this.context = null;

		try { await context?.close?.(); } catch {}
	}
}
