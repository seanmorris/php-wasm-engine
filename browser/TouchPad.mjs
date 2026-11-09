/**
 * On-screen buttons for coarse pointers: held while a finger is down, released when it lifts or is lost.
 *
 * Games show these only on touch devices, over the canvas. Each button reports into a shared set of held ids, so a
 * game reads "is FIRE held" or "is LEFT or RIGHT held" the same way it reads a key. Pointer capture keeps a press
 * that slides off the button counted until the finger lifts.
 */
export class TouchPad
{
	/** Element the buttons live in. */
	container;

	/** Ids currently held. */
	held = new Set;

	/** Buttons by id. */
	buttons = new Map;

	#pointers = new Map;
	#onChange;

	/**
	 * Build the buttons.
	 * @param {HTMLElement} container Element the buttons are appended to; hidden until `visible` is set.
	 * @param {Array<{id: string, label: string, text?: string}>} buttons Buttons to make, with their accessible
	 * label and visible text (the label by default).
	 * @param {object} options Behaviour.
	 * @param {AbortSignal} options.signal Removes the listeners when it aborts.
	 * @param {function(Set<string>): void} [options.onChange] Runs whenever the held set changes.
	 * @param {function(): void} [options.focus] Returns focus to the game on a press, such as to its canvas.
	 */
	constructor(container, buttons, {signal, onChange = () => {}, focus = () => {}})
	{
		this.container = container;
		this.#onChange = onChange;
		container.hidden = true;

		for(const {id, label, text = label} of buttons)
		{
			const button = document.createElement('button');
			button.type = 'button';
			button.textContent = text;
			button.setAttribute('aria-label', label);
			button.dataset.id = id;

			const release = event => {
				if(this.#pointers.get(event.pointerId) !== id) return;

				this.#pointers.delete(event.pointerId);

				if(![...this.#pointers.values()].includes(id))
				{
					this.held.delete(id);
					onChange(this.held);
				}
			};

			button.addEventListener('pointerdown', event => {
				event.preventDefault();

				try { button.setPointerCapture(event.pointerId); } catch { /* pointer already gone */ }

				this.#pointers.set(event.pointerId, id);
				focus();

				if(!this.held.has(id))
				{
					this.held.add(id);
					onChange(this.held);
				}
			}, {signal});

			for(const event of ['pointerup', 'pointercancel', 'lostpointercapture'])
			button.addEventListener(event, release, {signal});

			this.buttons.set(id, button);
			container.append(button);
		}
	}

	/**
	 * Whether the pad is shown.
	 * @returns {boolean} True when visible.
	 */
	get visible() { return !this.container.hidden; }

	/**
	 * Show or hide the pad; hiding releases every held button.
	 * @param {boolean} value Whether to show it.
	 */
	set visible(value)
	{
		this.container.hidden = !value;

		if(!value && this.held.size)
		{
			this.held.clear();
			this.#pointers.clear();
			this.#onChange(this.held);
		}
	}
}
