/**
 * Failure with a stable category, declaring resource, and dependency chain.
 *
 * The error keeps machine-readable failure codes and dependency context alongside the human message so UI
 * and recovery code can respond without parsing text.
 */
export class NetworkError extends Error
{
	/** Stable JavaScript error class name. */
	name;

	/** Machine-readable network error category. */
	code;

	/** Resource or package that declared the failed operation. */
	declaring;

	/** Ordered dependency chain that led to the failure. */
	chain;

	/** Additional immutable context associated with the failure. */
	details;


	/**
	 * Create a categorized failure with frozen declaration context.
	 * @param {string} code Stable failure category.
	 * @param {string} message Human-readable description.
	 * @param {object} [context] Optional cause and declaration context.
	 * @param {*} [context.cause] Underlying failure passed to Error.
	 * @param {string|null} [context.declaring] Declaring resource URL; null when unknown.
	 * @param {Array<string>} [context.chain] Dependency chain leading to the failure.
	 * @param {object} [context.details] Additional diagnostic fields.
	 */
	constructor(code, message, {cause, declaring = null, chain = [], details = {}} = {})
	{
		super(message, {cause});
		this.name = 'NetworkError';
		this.code = code;
		this.declaring = declaring;
		this.chain = Object.freeze([...chain]);
		this.details = Object.freeze({...details});
	}

	/**
	 * Return a diagnostic safe for UI and logs.
	 * @returns {object} Name, code, message, declaring resource, chain, and details.
	 */
	toJSON()
	{
		return {
			name: this.name
			, code: this.code
			, message: this.message
			, declaring: this.declaring
			, chain: this.chain
			, details: this.details
		};
	}
}

/**
 * Preserve one stable NetworkError while adding declaration context to arbitrary failures.
 * @param {string} code Failure category for a wrapped error.
 * @param {string} message Prefix for the wrapped error's message.
 * @param {*} error Original failure; an existing NetworkError is returned unchanged.
 * @param {object} [context] NetworkError context for a wrapped error.
 * @returns {NetworkError} The original NetworkError or a new one caused by error.
 */
export function networkFailure(code, message, error, context = {})
{
	if(error instanceof NetworkError) return error;

	return new NetworkError(code, `${message}: ${error?.message ?? error}`, {...context, cause: error});
}
