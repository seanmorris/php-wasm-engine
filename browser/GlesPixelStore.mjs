const optimizedContexts = new WeakSet();

/**
 * Cache pixel-layout queries used to validate PHP texture uploads and readbacks.
 *
 * Some WebGL drivers wait for pending draws when reading UNPACK_ROW_LENGTH, even
 * though pixel-store state only changes through pixelStorei(). Invalidate on every
 * write and let the next native query observe its result, including invalid writes.
 * Other GL queries and all native argument/error validation remain unchanged.
 *
 * @param {WebGL2RenderingContext} gl Context used by the SDL GLES bridge.
 * @param {HTMLCanvasElement|EventTarget} canvas Context-loss event source.
 * @returns {WebGL2RenderingContext} The same context, optimized at most once.
 */
export function cachePixelStoreQueries(gl, canvas)
{
	if(!gl || optimizedContexts.has(gl)) return gl;

	for(const name of ['getParameter','pixelStorei','isContextLost'])
	if(typeof gl[name] !== 'function') throw new TypeError(`WebGL context is missing ${name}()`);

	const parameters = new Set([
		'PACK_ALIGNMENT','PACK_ROW_LENGTH','PACK_SKIP_PIXELS','PACK_SKIP_ROWS'
		, 'UNPACK_ALIGNMENT'
		, 'UNPACK_ROW_LENGTH'
		, 'UNPACK_SKIP_PIXELS'
		, 'UNPACK_SKIP_ROWS'
		, 'UNPACK_IMAGE_HEIGHT','UNPACK_SKIP_IMAGES'
	].map(name => gl[name]).filter(Number.isInteger));

	const values = new Map();
	const getParameter = gl.getParameter.bind(gl);
	const pixelStorei = gl.pixelStorei.bind(gl);
	const isContextLost = gl.isContextLost.bind(gl);

	Object.defineProperties(gl, {
		getParameter: {
			configurable: true

			, value(...args) {
				const [parameter] = args;

				if(!parameters.has(parameter)) return getParameter(...args);

				if(isContextLost())
				{
					values.clear();

					return getParameter(...args);
				}

				if(values.has(parameter)) return values.get(parameter);

				const value = getParameter(...args);

				if(typeof value === 'number') values.set(parameter, value);

				return value;
			}
		}
		, pixelStorei: {
			configurable: true

			, value(...args) {
				values.clear();

				return pixelStorei(...args);
			}
		}
	});

	canvas?.addEventListener?.('webglcontextlost', () => values.clear());
	canvas?.addEventListener?.('webglcontextrestored', () => values.clear());
	optimizedContexts.add(gl);

	return gl;
}
