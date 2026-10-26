/** Shared, accessible corner shortcuts. Games supply their audio and fullscreen actions. */
export class QuickControls
{

	/**
	 * Attach accessible mute and fullscreen shortcuts to a game overlay.
	 * @param {HTMLElement} container Positioned element that owns the controls.
	 * @param {HTMLElement} [frame=container] Fullscreen target and error-message anchor.
	 */
	constructor(container, frame = container)
	{
		this.container = container;
		this.hadContainerClass = container.classList.contains('phonics-controls-container');
		container.classList.add('phonics-controls-container');
		this.root = document.createElement('div');
		this.root.className = 'quick-controls';
		this.root.setAttribute('role', 'group');
		this.root.setAttribute('aria-label', 'Game controls');
		this.root.innerHTML = `<style>
			.phonics-controls-container{position:relative}
			.quick-controls{position:absolute;right:8px;bottom:8px;display:flex;gap:2px;padding:2px;
				border-radius:5px;background:#0009;color:#fff;opacity:.7;transition:opacity .12s;
				user-select:none;z-index:1}
			.quick-controls:hover,.quick-controls:focus-within{opacity:1}
			.quick-controls button{box-sizing:border-box;display:flex;align-items:center;justify-content:center;
				width:40px;height:40px;padding:8px;border:0;border-radius:3px;background:transparent;
				color:inherit;touch-action:manipulation;cursor:pointer}
			.quick-controls button:hover{background:#ffffff20}
			.quick-controls button:focus-visible{outline:2px solid #8bffaf;outline-offset:2px}
			.quick-controls button:disabled{opacity:.4;cursor:default}
			.quick-controls button[hidden]{display:none}
			.quick-controls svg{width:24px;height:24px;pointer-events:none}
			.quick-controls [aria-pressed="false"] .sound-off,.quick-controls [aria-pressed="true"] .sound-waves,
			.quick-controls [aria-pressed="false"] .contract,.quick-controls [aria-pressed="true"] .expand{display:none}
			@media(prefers-reduced-motion:reduce){.quick-controls{transition:none}}
		</style>
		<button id="mute" type="button" aria-label="Mute audio" aria-pressed="false" title="Mute audio">
			<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9h4l5-4v14l-5-4H3z" fill="currentColor"/>
			<g class="sound-waves" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
			<path d="M16 8a6 6 0 0 1 0 8M19 5a10 10 0 0 1 0 14"/></g>
			<path class="sound-off" d="m16 9 6 6m0-6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
			</svg>
		</button>
		<button id="fullscreen" type="button" aria-label="Fullscreen" aria-pressed="false" title="Fullscreen" hidden>
			<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round">
			<path class="expand" d="M9 3H3v6m12-6h6v6M3 15v6h6m12-6v6h-6"/>
			<path class="contract" d="M3 9h6V3m6 0v6h6M9 21v-6H3m18 0h-6v6"/></svg>
		</button>`;

		container.append(this.root);
		this.muteButton = this.root.querySelector('#mute');
		this.fullscreenButton = this.root.querySelector('#fullscreen');

		if(frame.id) this.fullscreenButton.setAttribute('aria-controls', frame.id);

		this.error = document.querySelector('#fullscreen-error');
		this.ownsError = !this.error;

		if(!this.error)
		{
			this.error = document.createElement('p');
			this.error.setAttribute('role', 'alert');
			this.error.hidden = true;
			frame.after(this.error);
		}
	}

	/**
	 * Update the mute button's accessible and visual state.
	 * @param {boolean} muted Whether game audio is muted.
	 * @returns {void} Updates labels without invoking the game's audio action.
	 */
	setMuted(muted)
	{
		const label = muted ? 'Unmute audio' : 'Mute audio';
		this.muteButton.setAttribute('aria-label', label);
		this.muteButton.setAttribute('aria-pressed', String(muted));
		this.muteButton.title = label;
	}

	/**
	 * Update fullscreen availability, progress, state, and error feedback.
	 * @param {boolean} active Whether the frame currently owns fullscreen.
	 * @param {boolean} available Whether the browser offers fullscreen.
	 * @param {boolean} busy Whether a fullscreen request is pending.
	 * @param {string} [error=''] User-facing failure message.
	 * @returns {void} Updates the control without requesting fullscreen itself.
	 */
	setFullscreen(active, available, busy, error = '')
	{
		const label = active ? 'Exit fullscreen' : 'Fullscreen';
		this.fullscreenButton.setAttribute('aria-label', label);
		this.fullscreenButton.setAttribute('aria-pressed', String(active));
		this.fullscreenButton.title = label;
		this.fullscreenButton.hidden = !available;
		this.fullscreenButton.disabled = busy;
		this.error.textContent = error;
		this.error.hidden = !error;
	}

	/**
	 * Remove controls and restore container/error ownership.
	 * @returns {void} Releases DOM elements created by this instance.
	 */
	close()
	{
		this.root.remove();

		if(!this.hadContainerClass) this.container.classList.remove('phonics-controls-container');

		if(this.ownsError) this.error.remove();
		else
		{
			this.error.textContent = '';
			this.error.hidden = true;
		}
	}
}
