import {test,expect} from '@playwright/test';

async function pads(page, connected = true)
{
	await page.addInitScript(connected => {
		window.inputPads = [];

		window.connectPad = (index = 0) => {
			const pad = {
				id: 'Standard Gamepad ' + index
				, index
				, connected: true
				, mapping: 'standard'
				, timestamp: performance.now()
				, axes: [
					0
					, 0
					, 0
					, 0
				]
				, buttons: Array.from({length: 17}, () => ({pressed: false,touched: false,value: 0}))
			};

			inputPads[index] = pad;
			window.dispatchEvent(Object.assign(new Event('gamepadconnected'), {gamepad: pad}));
		};

		window.changePad = ({index = 0,axes = [],buttons = []} = {}) => {
			const pad = inputPads[index];
			pad.axes = [0,0,0,0].map((_, i) => axes[i]??0);

			pad.buttons = pad.buttons.map((_, i) => ({
				pressed: buttons.includes(i)
				, touched: buttons.includes(i)
				, value: buttons.includes(i) ? 1 : 0
			}));

			pad.timestamp = performance.now();
		};

		window.disconnectPad = (index = 0) => {
			const pad = inputPads[index];
			pad.connected = false;
			inputPads[index] = null;
			window.dispatchEvent(Object.assign(new Event('gamepaddisconnected'), {gamepad: pad}));
		};

		Object.defineProperty(navigator, 'getGamepads', {value: () => inputPads});

		if(connected) connectPad();
	}, connected
	);
}

async function start(page, connected = true, step = 1/60)
{
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => {if(message.type()==='error') errors.push(message.text());});

	await page.route('**/favicon.ico', route => route.fulfill({status: 204}));
	await pads(page, connected);
	await page.goto('/');
	await page.waitForFunction(() => typeof window.bootEngine==='function');

	await page.evaluate(async ({version,step}) => {
		await bootEngine({run: false,version});
		window.inputFrames = [];
		const module = await enginePhp.binary;
		module.inputStep = step;

		module.inputProbe = frame => inputFrames.push(JSON.parse(frame));
		await enginePhp.run('<?php require "/engine/tests/input-browser.php";');
	}, {version: process.env.ENGINE_PHP_VERSION??'8.4',step});

	await page.locator('canvas').focus();
	const focusedAt = await page.evaluate(() => inputFrames.length);
	await expect.poll(() => page.evaluate(() => window.inputFrames.length)).toBeGreaterThan(focusedAt+3);

	return errors;
}

const frame = page => page.evaluate(() => inputFrames.at(-1));

const change = (page, state) => page.evaluate(state => changePad(state), state);
test.afterEach(async({page}) => {await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});});

test('SDL pads use moving-boxes mappings, proportional sticks, and keyboard merging', async({page}) => {
	const errors = await start(page);
	expect((await frame(page)).ids).toHaveLength(1);
	await change(page, {axes: [.15,-.15]});
	await page.waitForTimeout(100);
	expect((await frame(page)).axis).toBe(0);
	expect((await frame(page)).keys.up).toBeFalsy();
	await change(page, {axes: [.6,0,.6],buttons: [6]});
	await expect.poll(async() => (await frame(page)).axis).toBeCloseTo(.5, 3);
	expect((await frame(page)).rightStick).toBeCloseTo(.5, 3);
	expect((await frame(page)).trigger).toBeCloseTo(1, 3);
	await page.keyboard.down('ArrowRight');
	await expect.poll(async() => (await frame(page)).axis).toBe(1);
	await page.keyboard.up('ArrowRight');
	await expect.poll(async() => (await frame(page)).axis).toBeCloseTo(.5, 3);
	await change(page, {axes: [-1]});
	await expect.poll(async() => (await frame(page)).axis).toBeCloseTo(-1, 3);
	await change(page, {buttons: [0,1,13]});
	await expect.poll(async() => (await frame(page)).presses).toBe(1);
	expect((await frame(page)).keys).toMatchObject({jump: true,action: true,down: 1});
	await page.waitForTimeout(100);
	expect((await frame(page)).presses).toBe(1);
	await page.keyboard.down('Space');
	await change(page, {buttons: [2]});
	await page.waitForTimeout(100);
	expect((await frame(page)).releases).toBe(0);
	expect((await frame(page)).keys.action).toBeFalsy();
	expect((await frame(page)).extra).toBe(true);
	await page.keyboard.up('Space');
	await expect.poll(async() => (await frame(page)).releases).toBe(1);
	await change(page, {axes: [1],buttons: [14]});
	await expect.poll(async() => (await frame(page)).axis).toBe(-1);
	await change(page, {buttons: [12,15,8]});
	await expect.poll(async() => (await frame(page)).keys).toMatchObject({up: 1,right: 1,reset: true});
	expect(errors).toEqual([]);
});

test('late connection, multiple pads, disconnection and reused browser slots release input', async({page}) => {
	const errors = await start(page, false);
	expect((await frame(page)).ids).toEqual([]);
	await page.evaluate(() => {connectPad(0);connectPad(2);});

	await expect.poll(async() => (await frame(page)).ids.length).toBe(2);
	const ids = (await frame(page)).ids;
	await change(page, {buttons: [0]});
	await change(page, {index: 2,buttons: [0,15]});
	await expect.poll(async() => (await frame(page)).presses).toBe(1);
	await page.evaluate(() => disconnectPad(0));
	await expect.poll(async() => (await frame(page)).ids.length).toBe(1);
	expect((await frame(page)).keys.jump).toBe(true);
	await page.evaluate(() => disconnectPad(2));
	await expect.poll(async() => (await frame(page)).releases).toBe(1);
	expect((await frame(page)).axis).toBe(0);
	await page.evaluate(() => connectPad(0));
	await expect.poll(async() => (await frame(page)).ids.length).toBe(1);
	expect(ids).not.toContain((await frame(page)).ids[0]);
	await change(page, {buttons: [0]});
	await expect.poll(async() => (await frame(page)).presses).toBe(2);

	await page.evaluate(async() => {
		await enginePhp.refresh();
		inputFrames = [];
		await enginePhp.run('<?php require "/engine/tests/input-browser.php";');
	});

	await expect.poll(() => page.evaluate(() => inputFrames.length)).toBeGreaterThan(3);
	expect((await frame(page)).ids).toHaveLength(1);
	expect(errors).toEqual([]);
});

test('short keyboard and gamepad taps survive frames without a simulation step', async({page}) => {
	const errors = await start(page, true, 1);
	await page.keyboard.press('Space');
	await expect.poll(async() => (await frame(page)).presses).toBe(1);
	await expect.poll(async() => (await frame(page)).releases).toBe(1);
	await change(page, {buttons: [0]});
	await expect.poll(async() => (await frame(page)).keys.jump, {intervals: [20]}).toBe(true);
	await change(page, {});
	await expect.poll(async() => (await frame(page)).presses).toBe(2);
	await expect.poll(async() => (await frame(page)).releases).toBe(2);
	expect(errors).toEqual([]);
});

test('individual controller reads, dead-zone changes and closing a second owner preserve the engine', async({page}) => {
	const errors = await start(page);
	await page.evaluate(() => connectPad(2));
	await expect.poll(async() => (await frame(page)).ids.length).toBe(2);
	await change(page, {axes: [.6],buttons: [0]});
	await change(page, {index: 2,axes: [-.8],buttons: [1]});
	await expect.poll(async() => (await frame(page)).keys.action).toBe(true);

	const result = await page.evaluate(async() => {
		const module = await enginePhp.binary;

		module.inputResult = json => window.inputResult = JSON.parse(json);

		const status = await enginePhp.run(`<?php
      $pads=$engine->input->gamepads; $ids=$pads->ids();
      $separate=[$pads->axis(SDL_CONTROLLER_AXIS_LEFTX,$ids[0]),$pads->axis(SDL_CONTROLLER_AXIS_LEFTX,$ids[1]),$pads->button(SDL_CONTROLLER_BUTTON_A,$ids[0]),$pads->button(SDL_CONTROLLER_BUTTON_A,$ids[1])];
      $invalid=0;
      foreach([-1,1,NAN,INF] as $zone) {try {$pads->setDeadZone($zone);} catch(InvalidArgumentException $error) {$invalid++;}}
      $pads->setDeadZone(0); $pads->poll();
      $unscaled=$pads->axis(SDL_CONTROLLER_AXIS_LEFTX,$ids[0]);
      $other=new PhpWasmEngine\\Input\\Gamepads; $other->poll();
      $opened=count($other->ids()); $other->close(); $other->close(); $other->poll();
      $pads->poll();
      vrzno_env('inputResult')(json_encode([$separate,$invalid,$unscaled,$opened,$other->ids(),count($pads->ids()),SDL_WasInit(SDL_INIT_GAMECONTROLLER)!==0]));
    `
		);

		if(status!==0) throw Error('PHP returned ' + status);

		return inputResult;
	});

	expect(result[0][0]).toBeCloseTo(.5, 3);
	expect(result[0][1]).toBeCloseTo(-.75, 3);
	expect(result[0].slice(2)).toEqual([true,false]);
	expect(result[1]).toBe(4);
	expect(result[2]).toBeCloseTo(.6, 3);
	expect(result.slice(3)).toEqual([2,[],2,true]);
	await change(page, {});
	await change(page, {index: 2});
	await expect.poll(async() => (await frame(page)).axis).toBe(0);
	expect(errors).toEqual([]);
});

for(const suspension of ['visibility','WebGL','focus'])
test(
	suspension + ' resume requires releasing held pad inputs'
	, async({page}) => {
		const errors = await start(page);
		await change(page, {axes: [1],buttons: [0,1]});
		await expect.poll(async() => (await frame(page)).presses).toBe(1);

		if(suspension==='visibility')
		await page.evaluate(() => {
			Object.defineProperty(document, 'hidden', {configurable: true,get: () => true});
			document.dispatchEvent(new Event('visibilitychange'));
		});

		if(suspension==='WebGL')
		await page.evaluate(() => {
			window.inputLoss = document.querySelector('canvas').getContext('webgl2').getExtension('WEBGL_lose_context');
			inputLoss.loseContext();
		});

		if(suspension==='focus') await page.locator('#mute').focus();

		await page.waitForTimeout(150);

		if(suspension==='focus') expect((await frame(page)).axis).toBe(0);

		const count = await page.evaluate(() => inputFrames.length);
		const before = await frame(page);

		if(suspension==='visibility')
		await page.evaluate(() => {
			delete document.hidden;
			document.dispatchEvent(new Event('visibilitychange'));
		});

		if(suspension==='WebGL') await page.evaluate(() => inputLoss.restoreContext());

		if(suspension==='focus') await page.locator('canvas').focus();

		await expect.poll(() => page.evaluate(() => inputFrames.length)).toBeGreaterThan(count+3);
		expect((await frame(page)).axis).toBe(0);
		expect((await frame(page)).keys.jump).toBeFalsy();
		expect((await frame(page)).keys.action).toBeFalsy();
		expect((await frame(page)).presses).toBe(1);

		if(suspension!=='focus') expect(await page.evaluate(index => inputFrames[index].time, count)).toBe(before.time);

		await change(page, {});
		await page.waitForTimeout(80);
		await change(page, {axes: [-1],buttons: [0]});
		await expect.poll(async() => (await frame(page)).presses).toBe(2);
		expect((await frame(page)).axis).toBeCloseTo(-1, 3);
		expect(errors).toEqual([]);
	}
);

test('Start pauses and resumes without a repeated toggle or idle catch-up', async({page}) => {
	const errors = await start(page);
	await change(page, {buttons: [9]});
	await expect.poll(async() => (await frame(page)).paused).toBe(true);
	const before = await frame(page);
	await page.waitForTimeout(180);
	expect((await frame(page)).ticks).toBe(before.ticks);
	await change(page, {});
	await page.waitForTimeout(80);
	const index = await page.evaluate(() => inputFrames.length);
	await change(page, {buttons: [9]});
	await expect.poll(async() => (await frame(page)).paused).toBe(false);
	expect(await page.evaluate(index => inputFrames.slice(index).find(f => !f.paused).time, index)).toBe(before.time);
	await page.waitForTimeout(150);
	expect((await frame(page)).paused).toBe(false);
	expect((await frame(page)).ticks).toBeGreaterThan(before.ticks);
	expect(errors).toEqual([]);
});

test('keyboard pause ignores key repeat after resuming', async({page}) => {
	const errors = await start(page);
	await page.keyboard.down('p');
	await expect.poll(async() => (await frame(page)).paused).toBe(true);
	await page.keyboard.up('p');
	await expect.poll(async() => (await frame(page)).keys.pause).toBe(false);
	await page.keyboard.down('p');
	await expect.poll(async() => (await frame(page)).paused).toBe(false);
	const before = (await frame(page)).ticks;
	await page.keyboard.down('p'); // A second keydown while held has repeat=true.
	await expect.poll(async() => (await frame(page)).ticks).toBeGreaterThan(before+5);
	expect((await frame(page)).paused).toBe(false);
	await page.keyboard.up('p');
	await page.keyboard.press('Escape');
	await expect.poll(async() => (await frame(page)).paused).toBe(true);
	expect(errors).toEqual([]);
});

for(const profile of ['runner','glider','flyer'])
test(
	profile + ' demo moves and activates abilities with a gamepad'
	, async({page}) => {
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));
		await pads(page);
		await page.goto('/');
		await page.waitForFunction(() => typeof window.bootEngine==='function');
		await page.evaluate(profile => window.bootEngine({profile}), profile);

		const body = async() => JSON.parse(await page.locator('canvas').getAttribute('data-body')||'{}');
		await expect.poll(async() => (await body()).grounded, {timeout: 60000}).toBe(true);
		const before = await body();
		await change(page, {axes: [.6]});
		await expect.poll(async() => (await body()).x).toBeGreaterThan(before.x+12);
		await change(page, {buttons: [0]});
		await expect.poll(async() => (await body()).grounded, {intervals: [20]}).toBe(false);
		await change(page, {});
		await page.waitForTimeout(60);

		if(profile==='runner')
		{
			await change(page, {buttons: [1]});
			await expect.poll(async() => (await body()).grounded, {intervals: [20]}).toBe(true);
			expect(Math.abs((await body()).speed)).toBeGreaterThan(100);
		}
		else
		{
			await change(page, {buttons: [0]});

			await expect.poll(async() => (await body()).ability, {intervals: [20]}).toBe(
				profile==='glider' ? 'glide' : 'fly'
			);
		}

		await page.evaluate(() => window.stopEngine());
		await expect(page.locator('#start')).toBeEnabled();
		expect(errors).toEqual([]);
	}
);

test('gamepad dash breaks blocks on rolling contact after releasing the charge', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await pads(page);
	await page.goto('/');
	await page.waitForFunction(() => typeof window.bootEngine==='function');
	await page.evaluate(() => window.bootEngine({scene: 'breakables'}));

	const body = async() => JSON.parse(await page.locator('canvas').getAttribute('data-body')||'{}');

	const blocks = async() => JSON.parse(await page.locator('canvas').getAttribute('data-breakables')||'{}');
	await expect.poll(async() => (await body()).grounded, {timeout: 60000}).toBe(true);
	await change(page, {axes: [1]});
	await expect.poll(async() => (await body()).x).toBeGreaterThan(295);
	await page.waitForTimeout(100);
	expect((await body()).x).toBeLessThanOrEqual(296.001);
	expect(await blocks()).toEqual({intact: 3,pieces: 0});
	await change(page, {buttons: [1,13]});
	await page.waitForTimeout(250);
	expect(await blocks()).toEqual({intact: 3,pieces: 0});
	expect((await body()).rolling).toBe(false);
	await change(page, {buttons: [13]});
	await expect.poll(async() => (await blocks()).intact, {intervals: [16]}).toBe(2);
	expect((await body()).rolling).toBe(true);
	expect((await body()).speed).toBeGreaterThan(480);
	await expect.poll(async() => (await body()).x, {intervals: [16]}).toBeGreaterThan(410);
	await change(page, {buttons: [8]});
	await expect.poll(async() => await blocks()).toEqual({intact: 3,pieces: 0});
	await expect.poll(async() => Math.abs((await body()).x-80)).toBeLessThan(1);
	expect((await body()).rolling).toBe(false);
	await page.evaluate(() => window.stopEngine());
	expect(errors).toEqual([]);
});

async function configureSequence(page, code)
{
	const status = await page.evaluate(code => enginePhp.run('<?php ' + code), code);
	expect(status).toBe(0);
}

async function padTap(page, button)
{
	await change(page, {buttons: [button]});
	await expect.poll(async () => (await frame(page)).keys['button' + button]).toBe(1);
	await change(page, {});
	await expect.poll(async () => (await frame(page)).keys['button' + button]).toBe(0);
}

test('logical button zero combines Space and A without duplicate presses or releases', async ({page}) => {
	const errors = await start(page);
	await configureSequence(page, "$engine->input->sequences->add('zero', [0]);");
	await page.keyboard.down('Space');
	await expect.poll(async () => (await frame(page)).matches.length).toBe(1);
	await change(page, {buttons: [0]});
	await page.keyboard.up('Space');
	await page.waitForTimeout(100);
	expect((await frame(page)).matches).toHaveLength(1);
	expect((await frame(page)).keys).toMatchObject({button0: 1, jump: true});
	await change(page, {});
	await expect.poll(async () => (await frame(page)).keys.button0).toBe(0);
	await padTap(page, 0);
	await expect.poll(async () => (await frame(page)).matches.length).toBe(2);
	await configureSequence(page, "$engine->input->bindButton(0, [SDLK_b]);");
	await page.keyboard.press('Space');
	await page.waitForTimeout(80);
	expect((await frame(page)).matches).toHaveLength(2);
	await page.keyboard.press('b');
	await expect.poll(async () => (await frame(page)).matches.length).toBe(3);
	expect(errors).toEqual([]);
});

test('standard SDL mapping includes all face buttons, shoulders, triggers and stick clicks', async ({page}) => {
	const errors = await start(page);
	const buttons = [2, 3, 4, 5, 6, 7, 10, 11, 16];
	await configureSequence(page, "$engine->input->sequences->add('chord', [[2,3,4,5,6,7,10,11,16]]);");
	await change(page, {buttons});
	await expect.poll(async () => (await frame(page)).matches.length).toBe(1);

	for(const button of buttons) expect((await frame(page)).keys['button' + button]).toBe(1);
	expect((await frame(page)).keys).toMatchObject({button0: 0, button1: 0, reset: false, pause: false});
	await change(page, {});
	await expect.poll(async () => (await frame(page)).keys.button16).toBe(0);

	for(const [key, button] of [['c', 2], ['v', 3], ['q', 4], ['e', 5], ['1', 6], ['3', 7]])
	{
		await page.keyboard.down(key);
		await expect.poll(async () => (await frame(page)).keys['button' + button]).toBe(1);
		await page.keyboard.up(key);
		await expect.poll(async () => (await frame(page)).keys['button' + button]).toBe(0);
	}

	expect(errors).toEqual([]);
});

test('Konami uses keyboard, D-pad and mixed devices on the same observer', async ({page}) => {
	const errors = await start(page);
	const keys = ['ArrowUp'
		, 'ArrowUp'
		, 'ArrowDown'
		, 'ArrowDown'
		, 'ArrowLeft'
		, 'ArrowRight'
		, 'ArrowLeft'
		, 'ArrowRight'
		, 'x'
		, 'Space'
	];

	const buttons = [12,12,13,13,14,15,14,15,1,0];
	await configureSequence(page, "$engine->input->sequences->add('combo', [12,12,13,13,14,15,14,15,1,0]);");

	for(const key of keys) await page.keyboard.press(key);
	await expect.poll(async () => (await frame(page)).matches.length).toBe(1);

	for(const button of buttons) await padTap(page, button);
	await expect.poll(async () => (await frame(page)).matches.length).toBe(2);

	for(let index = 0; index < keys.length; index++)
	{
		if(index % 2) await padTap(page, buttons[index]);
		else
		{
			// Device handoffs without a sampled neutral state are one logical hold.
			await page.keyboard.down(keys[index]);
			await expect.poll(async () => (await frame(page)).keys['button' + buttons[index]]).toBe(1);
			await page.keyboard.up(keys[index]);
			await expect.poll(async () => (await frame(page)).keys['button' + buttons[index]]).toBe(0);
		}
	}

	await expect.poll(async () => (await frame(page)).matches.length).toBe(3);
	expect((await frame(page)).matches.every(match => match.name === 'combo')).toBe(true);
	expect(errors).toEqual([]);
});

test('ordered taps between simulation updates survive and keep their SDL timing', async ({page}) => {
	const errors = await start(page, true, 10);
	await configureSequence(page, "$engine->input->sequences->add('combo', [0,0,1], .1);");
	const ticks = (await frame(page)).ticks;

	await page.evaluate(() => {
		for(const [key, code] of [[' ', 'Space'], [' ', 'Space'], ['x', 'KeyX']])
		{
			for(const type of ['keydown', 'keyup'])
			{
				document.querySelector('canvas').dispatchEvent(new KeyboardEvent(type, {
					key
					, code
					, keyCode: key === ' ' ? 32 : 88
					, which: key === ' ' ? 32 : 88
					, bubbles: true
				}));
			}
		}
	});

	await expect.poll(async () => (await frame(page)).matches.length).toBe(1);
	expect((await frame(page)).ticks).toBe(ticks);
	await page.keyboard.press('Space');
	await page.waitForTimeout(140);
	await page.keyboard.press('Space');
	await page.keyboard.press('x');
	await page.waitForTimeout(50);
	expect((await frame(page)).matches).toHaveLength(1);
	expect(errors).toEqual([]);
});

test('Mortal Kombat style motion and held-direction attacks match from pad sticks or keys', async ({page}) => {
	const errors = await start(page);

	await configureSequence(page, `
    $engine->input->sequences->add('motion', ['down','down-forward','forward',1], .5);
    $engine->input->sequences->add('uppercut', [['press'=>2, 'held'=>['down']]]);
  `
	);

	for(const axes of [[0,1], [1,1], [1,0]])
	{
		await change(page, {axes});
		await page.waitForTimeout(60);
	}

	await change(page, {axes: [1,0], buttons: [1]});
	await expect.poll(async () => (await frame(page)).matches.map(match => match.name)).toEqual(['motion']);
	await change(page, {});
	await page.waitForTimeout(50);
	await configureSequence(page, "$engine->input->sequences->setFacing(-1);");
	await page.keyboard.down('ArrowDown');
	await page.keyboard.down('ArrowLeft');
	await page.keyboard.up('ArrowDown');
	await page.keyboard.press('x');
	await page.keyboard.up('ArrowLeft');
	await expect.poll(async () => (await frame(page)).matches.length).toBe(2);
	await change(page, {buttons: [13]});
	await page.waitForTimeout(50);
	await change(page, {buttons: [13, 2]});
	await expect.poll(async () => (await frame(page)).matches.length).toBe(3);
	expect((await frame(page)).matches.at(-1).name).toBe('uppercut');
	await page.waitForTimeout(120);
	expect((await frame(page)).matches).toHaveLength(3);
	expect(errors).toEqual([]);
});

for(const recovery of ['focus', 'visibility', 'WebGL'])
test('partial combos are discarded across ' + recovery, async (
	{page}
) => {
	const errors = await start(page);
	await configureSequence(page, "$engine->input->sequences->add('combo', [0,1], 5);");
	await page.keyboard.press('Space');
	await expect.poll(async () => (await frame(page)).progress).toBe(1);

	if(recovery === 'focus') await page.locator('#mute').focus();

	if(recovery === 'visibility')
	await page.evaluate(() => {
		Object.defineProperty(document, 'hidden', {configurable: true, get: () => true});
		document.dispatchEvent(new Event('visibilitychange'));
	});

	if(recovery === 'WebGL')
	await page.evaluate(() => {
		window.comboLoss = document.querySelector('canvas').getContext('webgl2').getExtension('WEBGL_lose_context');
		comboLoss.loseContext();
	});

	await page.waitForTimeout(100);

	if(recovery === 'focus') await page.locator('canvas').focus();

	if(recovery === 'visibility')
	await page.evaluate(() => {
		delete document.hidden;
		document.dispatchEvent(new Event('visibilitychange'));
	});

	if(recovery === 'WebGL') await page.evaluate(() => comboLoss.restoreContext());

	await expect.poll(async () => (await frame(page)).progress).toBe(0);
	await page.keyboard.press('x');
	await page.waitForTimeout(80);
	expect((await frame(page)).matches).toEqual([]);
	await page.keyboard.press('Space');
	await page.keyboard.press('x');
	await expect.poll(async () => (await frame(page)).matches.length).toBe(1);
	expect(errors).toEqual([]);
});

test('text entry cannot trigger input sequences or carry progress back into play', async ({page}) => {
	const errors = await start(page);
	await configureSequence(page, "$engine->input->sequences->add('combo', [0,1]);");
	await page.keyboard.press('Space');
	await expect.poll(async () => (await frame(page)).progress).toBe(1);
	await configureSequence(page, "$engine->input->setTextInput(true);");
	await page.keyboard.press('x');
	await page.keyboard.press('Space');
	await page.keyboard.press('x');
	await page.waitForTimeout(100);
	expect((await frame(page)).matches).toEqual([]);
	await configureSequence(page, "$engine->input->setTextInput(false);");
	await page.locator('canvas').focus();
	await page.keyboard.press('x');
	await page.waitForTimeout(80);
	expect((await frame(page)).matches).toEqual([]);
	await configureSequence(page, "$engine->input->sequences->add('single', [0]); $engine->input->setTextInput(true);");
	await page.keyboard.down('Space');
	await expect.poll(async () => (await frame(page)).keys.button0).toBe(1);
	await configureSequence(page, "$engine->input->setTextInput(false);");
	await page.waitForTimeout(80);
	expect((await frame(page)).matches).toEqual([]);
	await page.keyboard.up('Space');
	await page.keyboard.press('Space');
	await expect.poll(async () => (await frame(page)).matches.map(match => match.name)).toEqual(['single']);
	expect(errors).toEqual([]);
});
