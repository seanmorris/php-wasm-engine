const loadPhases = ['application','runtime','world','packages','code','assets','modules','game','ready'];

/**
 * Immutable progress snapshot emitted while a world becomes playable.
 *
 * Each instance records completed, total, current resource, and phase information without exposing the
 * mutable loader that produced it.
 */
export class LoadProgress
{
	/** Current world-loading phase. */
	phase;

	/** Concise activity label suitable for loading UI. */
	label;

	/** Work units completed within the current phase. */
	completed;

	/** Known work units in the current phase, or zero while indeterminate. */
	total;

	/** Canonical resource currently being processed, when known. */
	resource;

	/** Zero-based position of the current phase in the loading sequence. */
	phaseIndex;

	/** Total number of phases in the loading sequence. */
	phaseCount;


	/**
	 * Describe one real loader phase and its completed unit count.
	 * @param {string} phase Stable phase identifier.
	 * @param {string} label Concise user-facing activity label.
	 * @param {object} [state] Optional unit counts and current resource identity.
	 * @param {number} [state.completed=0] Work units completed in this phase.
	 * @param {number} [state.total=0] Known work units, or zero while discovery is indeterminate.
	 * @param {string|null} [state.resource=null] Canonical resource currently being processed.
	 */
	constructor(phase, label, {completed = 0, total = 0, resource = null} = {})
	{
		const phaseIndex = loadPhases.indexOf(phase);

		if(phaseIndex < 0) throw new RangeError(`Unknown load phase: ${phase}`);

		if(typeof label !== 'string' || !label) throw new TypeError('Load progress requires a label');

		if(!Number.isFinite(completed) || !Number.isFinite(total) || completed < 0 || total < 0)
		throw new RangeError('Load progress counts must be finite and non-negative');

		Object.assign(this, {
			phase
			, label
			, completed
			, total
			, resource
			, phaseIndex
			, phaseCount: loadPhases.length
		});

		Object.freeze(this);
	}

	/** Return overall stage and unit progress from zero through one. */
	get fraction()
	{
		if(this.phase === 'ready') return 1;

		const within = this.total > 0 ? Math.min(1, this.completed / this.total) : 0;

		return (this.phaseIndex + within) / this.phaseCount;
	}
}
