import {cachePixelStoreQueries} from './GlesPixelStore.mjs';

const optimizedContexts = new WeakSet();

/**
 * Replace synchronous glIsProgram validation with exact create/delete lifecycle tracking.
 *
 * php-sdl-wasm validates numeric GLES program handles before each shader switch. Some WebGL
 * implementations flush their command stream for isProgram(), so a renderer with several passes
 * can otherwise spend most of a frame validating programs that it created itself.
 *
 * @param {WebGL2RenderingContext} gl Context used by the SDL GLES bridge.
 * @param {HTMLCanvasElement|EventTarget} canvas Context-loss event source.
 * The replaced methods stay writable, so a host or a spec can still hook them by assignment.
 *
 * @returns {WebGL2RenderingContext} The same context, optimized at most once.
 */
export function cacheProgramValidation(gl, canvas)
{
	if(!gl || optimizedContexts.has(gl)) return gl;

	for(const name of ['createProgram','deleteProgram','isProgram'])
	if(typeof gl[name] !== 'function') throw new TypeError(`WebGL context is missing ${name}()`);
	optimizedContexts.add(gl);
	let programs = new WeakSet();
	const createProgram = gl.createProgram.bind(gl);
	const deleteProgram = gl.deleteProgram.bind(gl);
	const isProgram = gl.isProgram.bind(gl);

	Object.defineProperties(gl, {
		createProgram: {
			configurable: true
			, writable: true

			, value() {
				const program = createProgram();

				if(program) programs.add(program);

				return program;
			}
		}
		, deleteProgram: {
			configurable: true
			, writable: true

			, value(program) {
				if(program) programs.delete(program);

				return deleteProgram(program);
			}
		}
		, isProgram: {
			configurable: true
			, writable: true

			, value(program) {
				if(programs.has(program)) return true;

				const valid = isProgram(program);

				if(valid) programs.add(program);

				return valid;
			}
		}
	});

	canvas?.addEventListener?.('webglcontextlost', () => { programs = new WeakSet(); });

	return gl;
}

/**
 * Apply both GLES bridge caches to every WebGL context created on a canvas from now on.
 *
 * php-sdl-wasm validates program handles with `isProgram()` before each shader switch and reads pixel-store
 * state before uploads; both are synchronous queries that some browsers answer only after flushing pending
 * draws, and a game with several passes can spend most of a frame in them. Wrapping `getContext` before PHP
 * starts covers SDL's own context creation, so no game has to opt in.
 *
 * @param {HTMLCanvasElement} canvas Canvas the PHP runtime will draw to.
 * @returns {function(): void} Restores the canvas's own `getContext`.
 */
export function optimizeCanvasContexts(canvas)
{
	const original = canvas?.getContext;

	if(typeof original !== 'function') return () => {};

	const getContext = function(type, ...rest) {
		const context = original.call(this, type, ...rest);

		if(context && /^(?:webgl2?|experimental-webgl)$/.test(String(type)))
		{
			cacheProgramValidation(context, canvas);
			cachePixelStoreQueries(context, canvas);
		}

		return context;
	};

	canvas.getContext = getContext;

	return () => { if(canvas.getContext === getContext) delete canvas.getContext; };
}
