import {SafeStorage} from './SafeStorage.mjs';

/**
 * The browser chores every game host repeats, in one place.
 *
 * A game's browser module claims a WebGL2 context, wraps the canvas in its own frame, measures that frame into the
 * viewport string PHP polls, runs the fullscreen button's busy/error state machine behind `QuickControls`, keeps a
 * persisted mute, exposes status and one-shot action bridges on the module, and on close puts the canvas back where
 * it found it and deletes every bridge. Each host wrote those again with small differences; `GameShell` holds the
 * common machinery and takes each host's differences as options, so a game keeps its viewport format, storage keys
 * and fullscreen target while sharing the code.
 */
export class GameShell
{
	/** PHP bridge object the host exposes functions on. */
	module;

	/** The game's canvas. */
	canvas;

	/** Session object games publish state getters on. */
	session;

	/** Game title used in error messages. */
	title;

	/** Fires when the shell closes; listeners registered with it need no removal. */
	signal;

	/** Whether `close()` has run. */
	closed = false;

	#abort = new AbortController;
	#cleanups = [];
	#closers = [];
	#measures = [];
	#fullscreen = null;
	#mount = null;

	/**
	 * Prepare a shell for one game.
	 * @param {object} options Host objects.
	 * @param {object} options.module PHP bridge object the host exposes functions on.
	 * @param {HTMLCanvasElement} options.canvas The game's canvas.
	 * @param {object} [options.session] Session object to publish state getters on.
	 * @param {string} [options.title] Game title for error messages.
	 * @param {AbortSignal} [options.signal] Host signal; the shell closes when it aborts.
	 */
	constructor({module, canvas, session = {}, title = 'This game', signal = null})
	{
		this.module = module;
		this.canvas = canvas;
		this.session = session;
		this.title = title;
		this.signal = this.#abort.signal;

		if(signal) signal.addEventListener('abort', () => { void this.close(); }, {once: true});
	}

	/**
	 * Claim the canvas's WebGL2 context, or fail with the game's name.
	 * @param {WebGLContextAttributes} [attributes] Context attributes; games usually turn antialiasing off.
	 * @returns {WebGL2RenderingContext} The context.
	 */
	context(attributes = {antialias: false})
	{
		const gl = this.canvas.getContext('webgl2', attributes);

		if(!gl) throw Error(`${this.title} requires WebGL2`);

		return gl;
	}

	/**
	 * Put the game's frame where the canvas was and move the canvas into it, remembering how to undo that.
	 * @param {HTMLElement} ui Frame element inserted in the canvas's place.
	 * @param {object} [options] Placement.
	 * @param {HTMLElement} [options.screen] Element the canvas is appended to; the frame itself by default.
	 * @returns {HTMLElement} The frame.
	 */
	mount(ui, {screen = ui} = {})
	{
		const canvas = this.canvas;
		const parent = canvas.parentNode, next = canvas.nextSibling;
		this.#mount = {
			ui
			, parent
			, next
			, style: canvas.getAttribute('style')
			, tabIndex: canvas.getAttribute('tabindex')
			, label: canvas.getAttribute('aria-label')
		};

		parent.insertBefore(ui, canvas);
		screen.append(canvas);
		canvas.tabIndex = Math.max(0, canvas.tabIndex);

		return ui;
	}

	/**
	 * Measure an element into the viewport string PHP polls, now and whenever it resizes.
	 * @param {object} options Measurement policy.
	 * @param {HTMLElement} options.observe Element whose box is measured.
	 * @param {function(number, number, number, DOMRect, boolean): string} [options.format] Builds the viewport
	 * string from the device-pixel width and height, the pixel ratio, the CSS box and the coarse-pointer flag;
	 * `[width, height, dpr]` as JSON by default. Each game keeps the string its PHP expects.
	 * @param {number} [options.dprCap] Upper bound on the pixel ratio used.
	 * @param {boolean} [options.cssSize] Whether to size the canvas's CSS box to the measured element.
	 * @param {boolean} [options.skipEmpty] Whether to keep the last string when the element has no size yet.
	 * @param {string[]} [options.windowEvents] Window events that trigger another measurement, such as scroll.
	 * @param {string} [options.initial] Viewport string before the first measurement.
	 * @returns {{viewport: function(): string, measure: function(): void, disconnect: function(): void}} Readers.
	 */
	measure(options)
	{
		const {
			observe
			, format = null
			, dprCap = Infinity
			, cssSize = false
			, skipEmpty = false
			, windowEvents = []
			, initial = '[1,1,1]'
		} = options;

		const canvas = this.canvas;
		let viewport = initial;

		const measure = () => {
			const box = observe.getBoundingClientRect();

			if(skipEmpty && (box.width < 1 || box.height < 1)) return;

			const dpr = Math.min(dprCap, devicePixelRatio || 1);
			const width = Math.max(1, Math.round(box.width * dpr));
			const height = Math.max(1, Math.round(box.height * dpr));
			const coarse = globalThis.matchMedia?.('(pointer: coarse)').matches ?? false;
			viewport = format ? format(width, height, dpr, box, coarse) : JSON.stringify([width, height, dpr]);

			if(cssSize)
			{
				canvas.style.width = box.width + 'px';
				canvas.style.height = box.height + 'px';
			}
		};

		const resize = new ResizeObserver(measure);
		resize.observe(observe);

		for(const event of windowEvents) addEventListener(event, measure, {signal: this.signal, passive: true});

		measure();
		const reader = {viewport: () => viewport, measure, disconnect: () => resize.disconnect()};
		this.#measures.push(reader);

		return reader;
	}

	/**
	 * Run the fullscreen button: one request at a time, errors shown through the controls, the canvas refocused
	 * after each change, and fullscreen left again when the shell closes.
	 * @param {object} options Fullscreen policy.
	 * @param {HTMLElement} options.target Element that goes fullscreen.
	 * @param {object} [options.quick] `QuickControls` whose fullscreen button drives this and shows its state.
	 * @param {function(Error): string} [options.describeError] Text shown when a request fails.
	 * @param {function(): void} [options.onChange] Runs after every state update, before the canvas is refocused.
	 * @returns {{active: boolean, busy: boolean, toggle: function(): Promise<void>, update: function(string=): void}}
	 * The state machine.
	 */
	fullscreen({target, quick = null, describeError = error => error.message, onChange = () => {}})
	{
		const canvas = this.canvas;
		const state = {busy: false, task: null, error: ''};

		const update = (error = '') => {
			state.error = error;

			quick?.setFullscreen(
				document.fullscreenElement === target
				, Boolean(document.fullscreenEnabled && target.requestFullscreen)
				, state.busy
				, error
			);

			onChange();
		};

		const toggle = async() => {
			if(state.busy || this.closed) return;

			state.busy = true;
			update();

			try
			{
				const leaving = document.fullscreenElement === target;
				state.task = leaving ? document.exitFullscreen() : target.requestFullscreen();
				await state.task;
			}
			catch(error)
			{
				if(!this.closed) update(describeError(error));
			}

			state.busy = false;

			if(!this.closed)
			{
				update(state.error);
				canvas.focus({preventScroll: true});
			}
		};

		quick?.fullscreenButton.addEventListener('click', () => { void toggle(); }, {signal: this.signal});
		document.addEventListener('fullscreenchange', () => update(), {signal: this.signal});
		update();

		const machine = {
			get active() { return document.fullscreenElement === target; }
			, get busy() { return state.busy; }
			, get error() { return state.error; }
			, toggle
			, update
		};

		this.#fullscreen = {target, state};

		return machine;
	}

	/**
	 * A persisted mute, read once and written on every change, migrated once from older keys.
	 * @param {object} options Storage policy.
	 * @param {string} options.key Storage key the mute lives under.
	 * @param {function(string): boolean} [options.parse] Reads a stored value; `'true'` by default.
	 * @param {function(boolean): string} [options.serialize] Writes a value; `String(muted)` by default.
	 * @param {Array<{key: string, parse: function(string): boolean}>} [options.legacy] Older keys read when
	 * `key` is absent, then written to `key` and removed.
	 * @param {boolean} [options.controlled] Whether another owner, such as PHP, holds the state; then `set()`
	 * persists only when told to and `toggle()` only reports.
	 * @param {function(boolean): void} [options.onChange] Runs after every change, including the first read.
	 * @param {object} [options.quick] `QuickControls` whose mute button drives this and shows its state.
	 * @returns {{muted: boolean, set: function(boolean, object=): void, toggle: function(): void}} The mute.
	 */
	mute(options)
	{
		const {
			key
			, parse = value => value === 'true'
			, serialize = muted => String(muted)
			, legacy = []
			, controlled = false
			, onChange = () => {}
			, quick = null
		} = options;

		const storage = new SafeStorage;
		let muted = false;
		const stored = storage.get(key);

		if(stored !== null && stored !== undefined) muted = Boolean(parse(stored));
		else
		{
			for(const old of legacy)
			{
				const value = storage.get(old.key);

				if(value === null || value === undefined) continue;

				muted = Boolean(old.parse(value));
				storage.set(key, serialize(muted));
				storage.remove(old.key);
				break;
			}
		}

		const set = (value, {persist = !controlled} = {}) => {
			muted = Boolean(value);

			if(persist) storage.set(key, serialize(muted));

			quick?.setMuted(muted);
			onChange(muted);
		};

		const toggle = () => set(!muted);

		quick?.muteButton.addEventListener('click', () => {
			toggle();
			this.canvas.focus({preventScroll: true});
		}, {signal: this.signal});

		quick?.setMuted(muted);
		onChange(muted);

		return {get muted() { return muted; }, set, toggle};
	}

	/**
	 * Receive status JSON from PHP: keep the parsed state, mirror the JSON on the canvas, and publish a getter.
	 * @param {string} name Module function PHP calls with the JSON.
	 * @param {object} [options] Where the status goes.
	 * @param {string} [options.dataset] `canvas.dataset` key that holds the raw JSON.
	 * @param {string} [options.session] Session key that receives `{state}` plus `extras`.
	 * @param {object} [options.extras] Further members of the session object.
	 * @param {function(object, string): void} [options.handler] Runs with each parsed state and its JSON.
	 * @returns {{state: object|null}} A reader of the latest state.
	 */
	status(name, {dataset = null, session = null, extras = {}, handler = null} = {})
	{
		let state = null;
		const reader = {get state() { return state; }};

		this.bridge(name, json => {
			state = JSON.parse(json);

			if(dataset) this.canvas.dataset[dataset] = json;

			handler?.(state, json);
		});

		if(dataset) this.#cleanups.push(() => { delete this.canvas.dataset[dataset]; });

		if(session)
		{
			this.session[session] = Object.defineProperty({...extras}, 'state', {get: () => state, enumerable: true});
			this.#cleanups.push(() => { delete this.session[session]; });
		}

		return reader;
	}

	/**
	 * A one-shot action queue PHP drains: the next poll returns the queued id once, then nothing.
	 * @param {string} name Module function PHP polls.
	 * @returns {{act: function(string): void}} The queue.
	 */
	action(name)
	{
		let pending = '';
		this.bridge(name, () => { const value = pending; pending = ''; return value; });

		return {act: id => { pending = String(id); }};
	}

	/**
	 * Expose a function to PHP for the shell's lifetime.
	 * @param {string} name Module key.
	 * @param {Function} fn The function.
	 * @returns {void} Deletes the key on close.
	 */
	bridge(name, fn)
	{
		this.module[name] = fn;
		this.#cleanups.push(() => { delete this.module[name]; });
	}

	/**
	 * A positive 31-bit seed from the platform's random source, never zero.
	 * @returns {number} The seed.
	 */
	seed31()
	{
		const seed = new Uint32Array(1);
		globalThis.crypto.getRandomValues(seed);

		return (seed[0] & 0x7fffffff) || 1;
	}

	/**
	 * Random bytes as lowercase hex.
	 * @param {number} [bytes] How many bytes.
	 * @returns {string} Hex of that many random bytes.
	 */
	seedHex(bytes = 32)
	{
		const seed = new Uint8Array(bytes);
		globalThis.crypto.getRandomValues(seed);

		return Array.from(seed, byte => byte.toString(16).padStart(2, '0')).join('');
	}

	/**
	 * Keep the browser's context menu off an element, usually the canvas.
	 * @param {HTMLElement} [element] Element to guard.
	 * @returns {void} Registers a listener that lasts until close.
	 */
	suppressContextMenu(element = this.canvas)
	{
		element.addEventListener('contextmenu', event => event.preventDefault(), {signal: this.signal});
	}

	/**
	 * Register game teardown that runs on close, after fullscreen is left and before the canvas is put back.
	 * @param {function(): (void|Promise<void>)} fn Teardown.
	 * @returns {void} Runs once, in registration order.
	 */
	onClose(fn) { this.#closers.push(fn); }

	/**
	 * Take everything down: stop listeners and measurements, leave fullscreen if this frame holds it, run the
	 * game's teardown, put the canvas back with its original attributes, and delete every bridge.
	 * @returns {Promise<void>} Resolves once the DOM is restored; a second call does nothing.
	 */
	async close()
	{
		if(this.closed) return;

		this.closed = true;
		this.#abort.abort();

		for(const reader of this.#measures) reader.disconnect();

		if(this.#fullscreen)
		{
			await this.#fullscreen.state.task?.catch(() => {});

			if(document.fullscreenElement === this.#fullscreen.target) await document.exitFullscreen().catch(() => {});
		}

		for(const fn of this.#closers) await fn();

		if(this.#mount)
		{
			const {ui, parent, next, style, tabIndex, label} = this.#mount;
			const canvas = this.canvas;

			if(next?.parentNode === parent) parent.insertBefore(canvas, next); else parent.append(canvas);

			for(const [name, value] of [['style', style], ['tabindex', tabIndex], ['aria-label', label]])
			if(value === null) canvas.removeAttribute(name); else canvas.setAttribute(name, value);

			ui.remove();
		}

		for(const cleanup of this.#cleanups) cleanup();
	}
}
