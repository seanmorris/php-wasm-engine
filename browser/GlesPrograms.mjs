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

			, value() {
				const program = createProgram();

				if(program) programs.add(program);

				return program;
			}
		}
		, deleteProgram: {
			configurable: true

			, value(program) {
				if(program) programs.delete(program);

				return deleteProgram(program);
			}
		}
		, isProgram: {
			configurable: true

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
