/** Failure with a stable category, declaring resource, and dependency chain. */
export class NetworkError extends Error
{

	constructor(code, message, {cause, declaring = null, chain = [], details = {}} = {})
	{
		super(message, {cause});
		this.name = 'NetworkError';
		this.code = code;
		this.declaring = declaring;
		this.chain = Object.freeze([...chain]);
		this.details = Object.freeze({...details});
	}

	/** Return a diagnostic safe for UI and logs. */
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

/** Preserve one stable NetworkError while adding declaration context to arbitrary failures. */
export function networkFailure(code, message, error, context = {})
{
	if(error instanceof NetworkError) return error;

	return new NetworkError(code, `${message}: ${error?.message ?? error}`, {...context, cause: error});
}
