/** Master output gain for the pinned php-sdl-wasm runtime's SDL2 audio bridge. */
export class SdlAudio
{
	/**
	 * Observe output devices before SDL connects them to its own AudioContext.
	 * @param {object} module Initialized php-sdl-wasm module, before opening audio.
	 * @param {boolean} muted Initial mute preference, including for future devices.
	 */
	constructor(module, muted = false)
	{
		this.muted = muted;
		this.gain = null;
		const sdl = module.SDL2 ??= {};
		let audio;

		// SDL replaces this object on every output-device open/close. Capture that
		// boundary so even the first buffer of a newly opened device is muted.
		Object.defineProperty(sdl, 'audio', {
			configurable: true,
			get: () => audio,
			set: device => {
				this.close();
				audio = device;

				if(!device)
				{
					return;
				}

				let processor;

				Object.defineProperty(device, 'scriptProcessorNode', {
					get: () => processor,
					set: node => {
						processor = node;
						this.close();

						if(node)
						{
							this.connect(node);
						}
					}
				});
			}
		});
	}

	/**
	 * Route only SDL output through a gain, leaving capture and other contexts alone.
	 * @param {ScriptProcessorNode} node Output processor about to connect to the speakers.
	 * @returns {void} Installs a device-scoped route without modifying Web Audio prototypes.
	 */
	connect(node)
	{
		const gain = this.gain = node.context.createGain();
		gain.gain.value = this.muted ? 0 : 1;
		gain.connect(node.context.destination);
		const connect = node.connect.bind(node);
		const disconnect = node.disconnect.bind(node);
		const target = value => value === node.context.destination ? gain : value;
		node.connect = (destination, ...ports) => connect(target(destination), ...ports);
		node.disconnect = (...args) => disconnect(...args.map(target));
	}

	/**
	 * Change audibility without altering channel volumes, fades, or playback position.
	 * @param {boolean} muted Whether the SDL output should be silent.
	 * @returns {void} Applies immediately to an open output and remembers future opens.
	 */
	setMuted(muted)
	{
		this.muted = Boolean(muted);

		if(this.gain)
		{
			this.gain.gain.value = this.muted ? 0 : 1;
		}
	}

	/**
	 * Disconnect the current output route after SDL closes or replaces its device.
	 * @returns {void} Releases the gain while retaining the mute preference.
	 */
	close()
	{
		this.gain?.disconnect();
		this.gain = null;
	}
}
