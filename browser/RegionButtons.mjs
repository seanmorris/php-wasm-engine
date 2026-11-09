/**
 * Accessible DOM buttons mirrored from the hit regions a game's PHP reports.
 *
 * A game that draws its own menus on the canvas describes each clickable region as `{id, label, rect, disabled,
 * hold}` in design pixels. This keeps one transparent button per region, positioned in percentages of the design
 * size so it tracks any letterboxing, adds and removes buttons as the set changes, and routes clicks and held
 * presses back to the game. Keyboard users and screen readers reach the canvas's controls through these.
 */
export class RegionButtons
{
	/** Element the buttons live in, positioned over the canvas. */
	container;

	/** Buttons by region id. */
	buttons = new Map;

	#signature = '';
	#options;

	/**
	 * Mirror regions into a container.
	 * @param {HTMLElement} container Positioned element covering the canvas; `pointer-events` pass through it.
	 * @param {object} options Behaviour.
	 * @param {AbortSignal} options.signal Removes the listeners when it aborts.
	 * @param {function(string): void} options.onPress Runs with a region's id when its button is activated.
	 * @param {function(string, boolean): void} [options.onHold] Runs when a `hold` region's button is first
	 * pressed and when its last pointer lifts, with the id and whether it is now held.
	 * @param {function(): void} [options.focus] Returns focus to the game after a press, such as to its canvas.
	 */
	constructor(container, {signal, onPress, onHold = null, focus = () => {}})
	{
		this.container = container;
		this.#options = {signal, onPress, onHold, focus};
	}

	/**
	 * Bring the buttons in line with the regions the game reports, doing nothing when they are unchanged.
	 * @param {number} width Design width the rects are measured in.
	 * @param {number} height Design height the rects are measured in.
	 * @param {Array<{id: string|number, label?: string, rect: number[], disabled?: boolean, hold?: boolean}>} regions
	 * The regions, each with `[x, y, width, height]` in design pixels.
	 * @returns {boolean} Whether anything changed.
	 */
	sync(width, height, regions)
	{
		regions = Array.isArray(regions) ? regions : [];
		const signature = JSON.stringify([width, height, regions]);

		if(signature === this.#signature) return false;

		this.#signature = signature;
		const wanted = new Set(regions.map(region => String(region.id)));

		for(const [id, button] of this.buttons)
		if(!wanted.has(id))
		{
			button.remove();
			this.buttons.delete(id);
		}

		for(const region of regions)
		{
			const id = String(region.id);
			let button = this.buttons.get(id);

			if(!button)
			{
				button = this.#create(id, Boolean(region.hold));
				this.buttons.set(id, button);
				this.container.append(button);
			}

			button.setAttribute('aria-label', region.label || id);
			button.disabled = Boolean(region.disabled);
			const [x, y, w, h] = region.rect;

			Object.assign(button.style, {
				left: 100 * x / width + '%'
				, top: 100 * y / height + '%'
				, width: 100 * w / width + '%'
				, height: 100 * h / height + '%'
			});
		}

		return true;
	}

	/**
	 * Remove every button.
	 * @returns {void} The next `sync()` rebuilds them.
	 */
	clear()
	{
		for(const button of this.buttons.values()) button.remove();

		this.buttons.clear();
		this.#signature = '';
	}

	#create(id, hold)
	{
		const {signal, onPress, onHold, focus} = this.#options;
		const button = document.createElement('button');
		button.type = 'button';
		button.dataset.action = id;

		if(!hold || !onHold)
		{
			button.addEventListener('click', () => {
				onPress(id);
				focus();
			}, {signal});

			return button;
		}

		const pointers = new Set;

		const release = event => {
			if(button.releasePointerCapture && button.hasPointerCapture?.(event.pointerId))
			button.releasePointerCapture(event.pointerId);

			if(!pointers.delete(event.pointerId) || pointers.size) return;

			onHold(id, false);
		};

		button.addEventListener('pointerdown', event => {
			event.preventDefault();

			try { button.setPointerCapture(event.pointerId); } catch { /* pointer already gone */ }

			if(!pointers.size) onHold(id, true);

			pointers.add(event.pointerId);
			focus();
		}, {signal});

		for(const event of ['pointerup', 'pointercancel', 'lostpointercapture'])
		button.addEventListener(event, release, {signal});

		return button;
	}
}
