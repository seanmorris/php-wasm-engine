import {test, expect} from '@playwright/test';

async function fixture(page)
{
	await page.route('**/game-shell-fixture', route => route.fulfill({
		contentType: 'text/html'
		, body: '<main id="frame"><p id="before">before</p><canvas width="4" height="4" style="border:1px solid red"'
			+ ' tabindex="3" aria-label="Game"></canvas><p id="after">after</p></main>'
	}));

	await page.goto('/game-shell-fixture');

	await page.evaluate(async() => {
		const shell = await import('/engine/browser/GameShell.mjs');
		const regions = await import('/engine/browser/RegionButtons.mjs');
		const pad = await import('/engine/browser/TouchPad.mjs');
		const quick = await import('/engine/browser/QuickControls.mjs');

		Object.assign(
			window
			, {
				GameShell: shell.GameShell
				, RegionButtons: regions.RegionButtons
				, TouchPad: pad.TouchPad
				, QuickControls: quick.QuickControls
			}
		);

		window.module = {};
		window.session = {};
		window.canvas = document.querySelector('canvas');
	});
}

test(
	'GameShell mounts a frame, measures it in each host\'s format, and restores the canvas on close'
	, async({page}) => {
		await fixture(page);

		const result = await page.evaluate(async() => {
			const shell = new GameShell({module, canvas, session, title: 'Fixture'});
			const gl = shell.context({antialias: false});
			const ui = document.createElement('section');
			const screen = document.createElement('div');
			ui.append(screen);
			shell.mount(ui, {screen});
			const mounted = {
				uiBeforeAfter: ui.nextElementSibling?.id === 'after' && ui.previousElementSibling?.id === 'before'
				, canvasInScreen: canvas.parentNode === screen
				, tabIndex: canvas.tabIndex
			};

			Object.assign(screen.style, {width: '300px', height: '150px', display: 'block'});
			const dpr = devicePixelRatio || 1;
			const plain = shell.measure({observe: screen});

			const buster = shell.measure({
				observe: screen
				, cssSize: true
				, format: (width, height, ratio, box, coarse) => JSON.stringify([width, height, ratio, coarse])
			});

			const capped = shell.measure({observe: screen, dprCap: 1, format: (w, h, r) => JSON.stringify([w, h, r])});
			const empty = document.createElement('div');
			screen.append(empty);
			const skipped = shell.measure({observe: empty, skipEmpty: true, initial: '[9,9,9]'});

			const measured = {
				plain: plain.viewport()
				, expectedPlain: JSON.stringify([Math.round(300 * dpr), Math.round(150 * dpr), dpr])
				, buster: buster.viewport()
				, expectedBuster: JSON.stringify(
					[Math.round(300 * dpr), Math.round(150 * dpr), dpr, matchMedia('(pointer: coarse)').matches]
				)
				, cssSize: [canvas.style.width, canvas.style.height]
				, capped: capped.viewport()
				, skipped: skipped.viewport()
			};

			shell.bridge('fixtureSeed', () => shell.seed31());
			const seeds = [module.fixtureSeed(), module.fixtureSeed()];
			const hex = shell.seedHex(8);
			shell.suppressContextMenu();
			const contextMenu = !canvas.dispatchEvent(new MouseEvent('contextmenu', {cancelable: true}));
			let closedRan = 0;
			shell.onClose(() => { closedRan++; });

			await shell.close();
			await shell.close();
			const restored = {
				canvasBack: canvas.previousElementSibling?.id === 'before' && canvas.nextElementSibling?.id === 'after'
				, uiGone: !document.contains(ui)
				, style: canvas.getAttribute('style')
				, tabIndex: canvas.getAttribute('tabindex')
				, label: canvas.getAttribute('aria-label')
				, bridgeGone: !('fixtureSeed' in module)
				, closedRan
				, closed: shell.closed
			};

			return {gl: Boolean(gl), mounted, measured, seeds, hex, contextMenu, restored};
		});

		expect(result.gl).toBe(true);
		expect(result.mounted).toEqual({uiBeforeAfter: true, canvasInScreen: true, tabIndex: 3});
		expect(result.measured.plain).toBe(result.measured.expectedPlain);
		expect(result.measured.buster).toBe(result.measured.expectedBuster);
		expect(result.measured.cssSize).toEqual(['300px', '150px']);
		expect(JSON.parse(result.measured.capped)[2]).toBe(1);
		expect(result.measured.skipped).toBe('[9,9,9]');
		expect(result.seeds.every(seed => Number.isInteger(seed) && seed > 0 && seed <= 0x7fffffff)).toBe(true);
		expect(result.hex).toMatch(/^[0-9a-f]{16}$/);
		expect(result.contextMenu).toBe(true);

		expect(result.restored).toEqual({
			canvasBack: true
			, uiGone: true
			, style: 'border:1px solid red'
			, tabIndex: '3'
			, label: 'Game'
			, bridgeGone: true
			, closedRan: 1
			, closed: true
		});
	}
);

test(
	'GameShell runs the fullscreen machine, a migrated persisted mute, and status and action bridges'
	, async({page}) => {
		await fixture(page);

		const result = await page.evaluate(async() => {
			const shell = new GameShell({module, canvas, session, title: 'Fixture'});
			const ui = document.createElement('section');
			shell.mount(ui);
			const quick = new QuickControls(ui, ui);
			// A fullscreen request that fails, then one that succeeds, without a real fullscreen transition.
			let mode = 'reject';
			let element = null;
			Object.defineProperty(document, 'fullscreenElement', {get: () => element, configurable: true});

			ui.requestFullscreen = () => mode === 'reject'
				? Promise.reject(new Error('denied'))
				: Promise.resolve().then(() => {
					element = ui;
					document.dispatchEvent(new Event('fullscreenchange'));
				});

			document.exitFullscreen = () => Promise.resolve().then(
				() => { element = null; document.dispatchEvent(new Event('fullscreenchange')); }
			);

			const changes = [];
			let fullscreen = null;

			fullscreen = shell.fullscreen(
				{
					target: ui
					, quick
					, describeError: () => 'fixed message'
					, onChange: () => changes.push(fullscreen ? fullscreen.busy : null)
				}
			);

			await fullscreen.toggle();
			const afterFailure = {
				error: fullscreen.error
				, active: fullscreen.active
				, busy: fullscreen.busy
				, shown: quick.error.textContent
			};

			mode = 'resolve';
			await fullscreen.toggle();
			const afterSuccess = {
				active: fullscreen.active
				, pressed: quick.fullscreenButton.getAttribute('aria-pressed')
			};

			localStorage.clear();
			localStorage.setItem('old.muted', 'true');
			const mutes = [];

			const mute = shell.mute({
				key: 'new.options'
				, parse: value => { try { return JSON.parse(value).muted === true; } catch { return false; } }
				, serialize: muted => JSON.stringify({version: 1, muted})
				, legacy: [{key: 'old.muted', parse: value => value === 'true'}]
				, quick
				, onChange: muted => mutes.push(muted)
			});

			const migrated = {
				muted: mute.muted
				, stored: localStorage.getItem('new.options')
				, oldGone: localStorage.getItem('old.muted')
			};

			quick.muteButton.click();
			const toggled = {
				muted: mute.muted
				, stored: localStorage.getItem('new.options')
				, pressed: quick.muteButton.getAttribute('aria-pressed')
			};

			const controlled = shell.mute({key: 'controlled.muted', controlled: true});
			controlled.set(true);
			const controlledStored = localStorage.getItem('controlled.muted');
			controlled.set(true, {persist: true});
			const controlledPersisted = localStorage.getItem('controlled.muted');

			const seen = [];

			const status = shell.status(
				'fixtureStatus'
				, {
					dataset: 'phpFixture'
					, session: 'phpFixture'
					, extras: {kind: 'test'}
					, handler: state => seen.push(state.page)
				}
			);

			module.fixtureStatus(JSON.stringify({page: 'title', score: 1}));
			module.fixtureStatus(JSON.stringify({page: 'play', score: 2}));
			const statused = {
				state: status.state
				, dataset: canvas.dataset.phpFixture
				, session: session.phpFixture.state
				, kind: session.phpFixture.kind
				, seen
			};

			const action = shell.action('fixtureAction');
			action.act('start');
			const acted = [module.fixtureAction(), module.fixtureAction()];
			await shell.close();
			const gone = {dataset: canvas.dataset.phpFixture, session: session.phpFixture, module: Object.keys(module)};

			return {
				afterFailure
				, afterSuccess
				, changes
				, migrated
				, toggled
				, mutes
				, controlledStored
				, controlledPersisted
				, statused
				, acted
				, gone
				, leftFullscreen: element === null
			};
		});

		const muted = {version: 1, muted: true};
		const unmuted = {version: 1, muted: false};
		const failure = {error: 'fixed message', active: false, busy: false, shown: 'fixed message'};

		expect(result.afterFailure).toEqual(failure);
		expect(result.afterSuccess).toEqual({active: true, pressed: 'true'});
		expect(result.changes.length).toBeGreaterThan(2);
		expect(result.migrated).toEqual({muted: true, stored: JSON.stringify(muted), oldGone: null});
		expect(result.toggled).toEqual({muted: false, stored: JSON.stringify(unmuted), pressed: 'false'});
		expect(result.mutes).toEqual([true, false]);
		expect(result.controlledStored).toBe(null);
		expect(result.controlledPersisted).toBe('true');

		expect(result.statused).toEqual(
			{
				state: {page: 'play', score: 2}
				, dataset: JSON.stringify({page: 'play', score: 2})
				, session: {page: 'play', score: 2}
				, kind: 'test'
				, seen: ['title', 'play']
			}
		);

		expect(result.acted).toEqual(['start', '']);
		expect(result.gone).toEqual({dataset: undefined, session: undefined, module: []});
		expect(result.leftFullscreen).toBe(true);
	}
);

test('RegionButtons mirror regions by signature and TouchPad holds ids per pointer', async({page}) => {
	await fixture(page);

	const result = await page.evaluate(() => {
		const abort = new AbortController;
		const container = document.createElement('div');
		document.body.append(container);
		const presses = [], holds = [];

		const buttons = new RegionButtons(
			container
			, {signal: abort.signal, onPress: id => presses.push(id), onHold: (id, held) => holds.push([id, held])}
		);

		const regions = [
			{id: 'start', label: 'Start game', rect: [10, 20, 100, 40]}
			, {id: 7, rect: [0, 0, 50, 50], disabled: true}
			, {id: 'left', label: 'Left', rect: [0, 60, 50, 40], hold: true}
		];

		const first = buttons.sync(320, 480, regions);
		const again = buttons.sync(320, 480, regions);
		const start = buttons.buttons.get('start');
		const geometry = [start.style.left, start.style.top, start.style.width, start.style.height];

		const labels = [start.getAttribute('aria-label')
			, buttons.buttons.get('7').getAttribute('aria-label')
			, buttons.buttons.get('7').disabled
		];

		start.click();
		const left = buttons.buttons.get('left');
		left.dispatchEvent(new PointerEvent('pointerdown', {pointerId: 1, bubbles: true}));
		left.dispatchEvent(new PointerEvent('pointerdown', {pointerId: 2, bubbles: true}));
		left.dispatchEvent(new PointerEvent('pointerup', {pointerId: 1, bubbles: true}));
		left.dispatchEvent(new PointerEvent('pointerup', {pointerId: 2, bubbles: true}));
		const changed = buttons.sync(320, 480, regions.slice(0, 1));
		const remaining = [...buttons.buttons.keys()];
		buttons.clear();
		const cleared = container.childElementCount;

		const padContainer = document.createElement('div');
		document.body.append(padContainer);
		const changes = [];

		const pad = new TouchPad(
			padContainer
			, [{id: 'fire', label: 'Fire', text: 'FIRE'}, {id: 'left', label: 'Steer left'}]
			, {signal: abort.signal, onChange: held => changes.push([...held].sort())}
		);

		const hiddenAtStart = padContainer.hidden;
		pad.visible = true;
		const fire = pad.buttons.get('fire');
		fire.dispatchEvent(new PointerEvent('pointerdown', {pointerId: 1, bubbles: true}));
		fire.dispatchEvent(new PointerEvent('pointerdown', {pointerId: 2, bubbles: true}));
		fire.dispatchEvent(new PointerEvent('pointerup', {pointerId: 1, bubbles: true}));
		const stillHeld = pad.held.has('fire');
		fire.dispatchEvent(new PointerEvent('pointerup', {pointerId: 2, bubbles: true}));
		const released = pad.held.has('fire');
		pad.buttons.get('left').dispatchEvent(new PointerEvent('pointerdown', {pointerId: 3, bubbles: true}));
		pad.visible = false;
		abort.abort();

		return {
			first
			, again
			, geometry
			, labels
			, presses
			, holds
			, changed
			, remaining
			, cleared
			, hiddenAtStart
			, text: fire.textContent
			, stillHeld
			, released
			, changes
			, heldAfterHide: pad.held.size
		};
	});

	expect(result.first).toBe(true);
	expect(result.again).toBe(false);
	const [left, top, width, height] = result.geometry.map(parseFloat);

	expect([left, width]).toEqual([3.125, 31.25]);
	expect(top).toBeCloseTo(100 * 20 / 480, 4);
	expect(height).toBeCloseTo(100 * 40 / 480, 4);
	expect(result.labels).toEqual(['Start game', '7', true]);
	expect(result.presses).toEqual(['start']);
	expect(result.holds).toEqual([['left', true], ['left', false]]);
	expect(result.changed).toBe(true);
	expect(result.remaining).toEqual(['start']);
	expect(result.cleared).toBe(0);
	expect(result.hiddenAtStart).toBe(true);
	expect(result.text).toBe('FIRE');
	expect(result.stillHeld).toBe(true);
	expect(result.released).toBe(false);
	expect(result.changes).toEqual([['fire'], [], ['left'], []]);
	expect(result.heldAfterHide).toBe(0);
});
