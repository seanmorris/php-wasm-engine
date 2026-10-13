import {SdlAudio} from '../browser/SdlAudio.mjs';

/** Browser actions shared by the PHP pause menu and the two corner shortcuts. */
export class DemoControls
{
	/**
	 * Bind accessible shortcuts without starting PHP or opening an audio device.
	 * @param {Function} restart Deferred demo restart using the selected level/profile.
	 */
	constructor(restart)
	{
		this.scene = 'platformer';
		this.profile = 'runner';
		this.muted = false;
		this.fullscreenBusy = false;
		this.fullscreenError = '';
		this.restart = restart;
		this.frame = document.querySelector('#game-frame');
		this.canvas = this.frame.querySelector('canvas');
		this.muteButton = document.querySelector('#mute');
		this.fullscreenButton = document.querySelector('#fullscreen');
		this.error = document.querySelector('#fullscreen-error');

		try
		{
			this.muted = localStorage.getItem('php-wasm-engine-muted') === 'true';
		}
		catch
		{
			// Storage can be disabled; the preference still works for this page.
		}

		this.muteButton.addEventListener('click', () => {
			this.setMuted(!this.muted);
			this.canvas.focus({preventScroll: true});
		});
		this.fullscreenButton.addEventListener('click', () => this.setFullscreen(!this.fullscreen));
		document.addEventListener('fullscreenchange', () => {
			this.update();
			this.canvas.focus({preventScroll: true});
		});
		this.update();
	}

	/** @returns {boolean} Whether this browser permits requesting fullscreen. */
	get fullscreenAvailable()
	{
		return Boolean(document.fullscreenEnabled && this.frame.requestFullscreen);
	}

	/** @returns {boolean} Whether the demo is currently the browser's fullscreen element. */
	get fullscreen()
	{
		return document.fullscreenElement === this.frame;
	}

	/**
	 * Install the audio route before PHP can open an SDL output device.
	 * @param {object} module Fresh php-sdl-wasm module owned by the demo.
	 * @returns {void} Carries the mute preference across runtime changes.
	 */
	attach(module)
	{
		this.audio?.close();
		this.audio = new SdlAudio(module, this.muted);
	}

	/**
	 * Store and apply the shared mute preference.
	 * @param {boolean} muted Whether to silence game audio.
	 * @returns {void} Updates audio, button state, and optional local persistence.
	 */
	setMuted(muted)
	{
		this.muted = Boolean(muted);
		this.audio?.setMuted(this.muted);

		try
		{
			localStorage.setItem('php-wasm-engine-muted', String(this.muted));
		}
		catch
		{
			// A blocked store does not prevent changing audio.
		}

		this.update();
	}

	/**
	 * Request browser fullscreen and report gesture/permission failures beside the game.
	 * @param {boolean} enabled Whether the demo should occupy the fullscreen view.
	 * @returns {Promise<void>} Settles after success or a visible, recoverable error.
	 */
	async setFullscreen(enabled)
	{
		if(this.fullscreenBusy || !this.fullscreenAvailable || enabled === this.fullscreen)
		{
			return;
		}

		this.fullscreenBusy = true;
		this.fullscreenError = '';
		this.update();

		try
		{
			if(enabled)
			{
				await this.frame.requestFullscreen();
			}
			else
			{
				await document.exitFullscreen();
			}
		}
		catch
		{
			this.fullscreenError = 'Fullscreen could not be changed. '
				+ 'Try the corner fullscreen button.';
		}
		finally
		{
			this.fullscreenBusy = false;
			this.update();
			this.canvas.focus({preventScroll: true});
		}
	}

	/**
	 * Restart after a menu callback has returned to the browser event loop.
	 * @param {string} scene Selected demo scene ID.
	 * @param {string} profile Selected movement profile ID.
	 * @returns {void} Leaves PHP teardown to the serialized bootstrap lifecycle.
	 */
	load(scene, profile)
	{
		this.cancelLoad();
		this.pendingLoad = setTimeout(() => this.restart({scene, profile}), 0);
	}

	/** @returns {void} Cancels a queued menu action when Stop or another load supersedes it. */
	cancelLoad()
	{
		clearTimeout(this.pendingLoad);
		this.pendingLoad = null;
	}

	/** @returns {void} Synchronizes labels, pressed states, and fullscreen error output. */
	update()
	{
		const muteLabel = this.muted ? 'Unmute audio' : 'Mute audio';
		this.muteButton.setAttribute('aria-label', muteLabel);
		this.muteButton.setAttribute('aria-pressed', String(this.muted));
		this.muteButton.title = muteLabel;
		const fullscreenLabel = this.fullscreen ? 'Exit fullscreen' : 'Fullscreen';
		this.fullscreenButton.setAttribute('aria-label', fullscreenLabel);
		this.fullscreenButton.setAttribute('aria-pressed', String(this.fullscreen));
		this.fullscreenButton.title = fullscreenLabel;
		this.fullscreenButton.hidden = !this.fullscreenAvailable;
		this.fullscreenButton.disabled = this.fullscreenBusy;
		this.error.textContent = this.fullscreenError;
		this.error.hidden = !this.fullscreenError;
	}
}
