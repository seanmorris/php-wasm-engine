import {test,expect} from '@playwright/test';

async function boot(page, zlib = false)
{
	await page.goto('/');
	await page.waitForFunction(() => typeof window.bootEngine==='function');

	await page.evaluate(options => window.bootEngine({run: false,...options}), {
		zlib
		, version: process.env.ENGINE_PHP_VERSION ??'8.4'
	});
}

async function run(page, source)
{
	const result = await page.evaluate(async source => {
		let output = '',errors = '';

		const out = e => output += e.detail.join(''),err = e => errors += e.detail.join('');
		enginePhp.addEventListener('output', out);
		enginePhp.addEventListener('error', err);

		try
		{
			const code = await enginePhp.run('<?php ' + source);

			return {output,errors,code};
		}
		finally
		{
			enginePhp.removeEventListener('output', out);
			enginePhp.removeEventListener('error', err);
		}
	}, source
	);

	expect(result.errors).toBe('');
	expect(result.code).toBe(0);

	return result.output;
}

test.afterEach(async({page}) => {await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});});

test('pure PHP regressions also pass inside the 32-bit WASM runtime', async({page}) => {
	await boot(page, true);
	expect(await run(page, 'require "/engine/tests/run.php";')).toContain('0 failures');
});

test('real GPU renders all flip flags, instances layers, edits tile slices, and releases resources', async({page}) => {
	await boot(page);
	const output = await run(page, 'require "/engine/tests/render.php";');
	const result = JSON.parse(output);
	expect(result.error).toBe(0);
	expect(result.initial.draws).toBe(1);
	expect(result.initial.instances).toBe(2);
	const expected = [[0,1,2,3],[2,3,0,1],[1,0,3,2],[3,2,1,0],[0,2,1,3],[1,3,0,2],[2,0,3,1],[3,1,2,0]];
	expect(result.quadrants).toEqual(expected);
	expect(result.clean.uploads).toBe(0);
	expect(result.dirty.uploads).toBe(1);
	expect(result.erased).toBe('000000ff');
	expect(result.culled.draws).toBe(0);
	expect(result.live).toEqual([false,false]);
});

test('isometric chunk seams match a CPU reference and sparse negative chunks stay culled', async({page}) => {
	await boot(page);
	const result = JSON.parse(await run(page, 'require "/engine/tests/render-cases.php";'));
	expect(result.isoExact).toBe(true);
	expect(result.sparse.instances).toBe(1);
	expect(result.negative).toBe('ff0000ff');
	expect(result.rotated).toEqual(['ffff00ff','00ff00ff','0000ffff','ff0000ff']);
	expect(result.error).toBe(0);
});

test('background strips repeat, anchor, blend in map order and share textures', async({page}) => {
	await boot(page);
	const result = JSON.parse(await run(page, 'require "/engine/tests/render-parallax.php";'));
	expect(result.exact).toEqual([true,true,true]);
	expect(result.cleanUploads).toBe(0);
	expect(result.sharedUploads).toBe(1);
	expect(result.draws).toBe(3);
	expect(result.painter).toEqual(['ff0000ff','ff0000ff','df0000ff','00ff00ff']);
	expect(result.released).toEqual([true]);
	expect(result.reordered).toBe('ff0000ff');
	expect(result.stripPixels).toEqual(['000000ff','ff0000ff','00ff00ff','0000ffff','00ff00ff']);
	expect(result.keyPixels).toEqual(['000000ff','00ff00ff','000000ff']);
	expect(result.animation).toEqual([['ff0000ff',1],['00ff00ff',1],['ff0000ff',0]]);
	expect(result.worldPixels).toEqual(['ff0000ff','ff0000ff']);
	expect(result.worldStats).toEqual({maps: 2,draws: 4,instances: 2,uploads: 2});
	expect(result.tileImageMatch).toBe(true);
	expect(result.error).toBe(0);
});

test('sprite animations switch by name, restart independently and reuse their atlas', async({page}) => {
	await boot(page);
	const result = JSON.parse(await run(page, 'require "/engine/tests/render-sprites.php";'));
	const red = 'ff0000ff',green = '00ff00ff',blue = '0000ffff',yellow = 'ffff00ff';

	expect(result.frames).toEqual(
		[[red,red]
			, [green,blue]
			, [green,yellow]
			, [red,yellow]
			, [green,red]
			, [blue,red]
			, [yellow,red]
			, [yellow,green]
			, [red
				, red
			]
		]
	);

	for(const [name,passed] of Object.entries(result.checks)) expect(passed, name).toBe(true);
	expect(result.error).toBe(0);
});

test('backdrop twist displaces tiles before foreground sprites and survives restoration', async({page}) => {
	await boot(page);
	const result = JSON.parse(await run(page, 'require "/engine/tests/render-backdrop-twist.php";'));

	for(const [name, passed] of Object.entries(result.checks)) expect(passed, name).toBe(true);
	expect(result.error).toBe(0);
});

test('camera motion blur follows travel while actors stay sharp and resources restore', async({page}) => {
	await boot(page);
	const result = JSON.parse(await run(page, 'require "/engine/tests/render-motion-blur.php";'));

	for(const [name, passed] of Object.entries(result.checks)) expect(passed, name).toBe(true);
	expect(result.error).toBe(0);
});

test('sprite groups reproduce whole images, freeze animation and restore cropped debris', async({page}) => {
	await boot(page);
	const result = JSON.parse(await run(page, 'require "/engine/tests/render-breakables.php";'));
	expect(Object.keys(result.checks).length).toBeGreaterThan(48);

	for(const [name,passed] of Object.entries(result.checks)) expect(passed, name).toBe(true);
	expect(result.error).toBe(0);
});

test('sprite outlines expand by one world pixel with entity grouping and per-instance opt-outs', async({page}) => {
	await boot(page);
	const result = JSON.parse(await run(page, 'require "/engine/tests/render-outlines.php";'));
	expect(Object.keys(result.checks).length).toBeGreaterThan(35);

	for(const [name,passed] of Object.entries(result.checks))
	{
		expect.soft(passed, JSON.stringify({name,differences: result.differences[name]})).toBe(true);
	}

	expect(result.error).toBe(0);
});

test('Tiled breakable objects render every flip and preserve the image when shattered', async({page}) => {
	await boot(page);
	const result = JSON.parse(await run(page, 'require "/engine/tests/render-tiled-breakables.php";'));
	expect(Object.keys(result.checks)).toHaveLength(96);

	for(const [name,passed] of Object.entries(result.checks)) expect(passed, name).toBe(true);
	expect(result.error).toBe(0);
});

test('smooth and pixel sprites share a batch without crop bleeding or alpha fringes', async({page}) => {
	await boot(page);
	const result = JSON.parse(await run(page, 'require "/engine/tests/render-filtering.php";'));
	expect(Object.keys(result.checks)).toHaveLength(18);

	for(const [name,passed] of Object.entries(result.checks))
	{
		expect.soft(passed, JSON.stringify({name,differences: result.differences[name]})).toBe(true);
	}

	expect(result.error).toBe(0);
});

test('bitmap fonts align exact pixels and menu editing survives graphics rebuilding', async({page}) => {
	await boot(page);
	const result = JSON.parse(await run(page, 'require "/engine/tests/render-menus.php";'));

	for(const [name,passed] of Object.entries(result.checks))
	{
		expect.soft(passed, JSON.stringify({name,differences: result.differences})).toBe(true);
	}

	expect(result.error).toBe(0);
});

test('anchored backgrounds keep a shared phase across map boundaries and streaming', async({page}) => {
	await boot(page);
	const result = JSON.parse(await run(page, 'require "/engine/tests/render-parallax-world.php";'));
	expect(Object.keys(result.checks).length).toBeGreaterThan(90);

	for(const [name,passed] of Object.entries(result.checks)) expect(passed, name).toBe(true);
	expect(result.error).toBe(0);
});

test('water displacement is clipped, animated, tinted and releases its textures', async({page}) => {
	await boot(page);
	const result = JSON.parse(await run(page, 'require "/engine/tests/render-water.php";'));
	expect(result.draws).toEqual([1, 2, 2]);
	expect(result.outsideExact).toBe(true);
	expect(result.wetPixels).toBeGreaterThan(1000);
	expect(result.animatedPixels).toBeGreaterThan(500);
	expect(result.live).toEqual([true, true, true]);
	expect(result.released).toEqual([true, true, true]);
	expect(result.error).toBe(0);
});

test('the live water scene applies buoyancy and draws the visible liquid pass', async({page}) => {
	const errors = [];
	const setup = `<?php
		$body->x = 1500;
		$body->y = 500;
		$body->vx = $body->vy = $body->groundSpeed = 0;
		$body->density = .8;
		$body->gravity = 300;
		$body->grounded = false;
	`;

	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/');
	await page.waitForFunction(() => typeof window.bootEngine === 'function');
	await page.evaluate(() => window.bootEngine({scene: 'water'}));
	const canvas = page.locator('canvas');

	await expect(canvas).toHaveAttribute('data-scene', 'water', {timeout: 60000});
	await page.evaluate(source => enginePhp.run(source), setup);

	const body = async() => JSON.parse(await canvas.getAttribute('data-body') || '{}');

	const water = async() => JSON.parse(await canvas.getAttribute('data-water') || '{}');
	await expect.poll(async() => (await body()).underwater).toBe(true);
	expect((await water()).depth).toBeGreaterThan(.5);
	expect((await water()).features).toBeNull();
	await expect.poll(async() => (await water()).draws, {timeout: 10000}).toBe(2);

	const splash = '<?php $game->level->waterParticles->splash($body->x, $body->water->y);';
	await page.evaluate(source => enginePhp.run(source), splash);

	await expect.poll(async() => (await water()).particles?.splashes).toBe(1);
	const initial = await body();
	await expect.poll(async() => (await body()).y).toBeLessThan(initial.y - 3);
	expect(errors).toEqual([]);
	await page.evaluate(() => window.stopEngine());
});

test('the rising-water loop exposes scriptable switches and object 3958', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/');
	await page.waitForFunction(() => typeof window.bootEngine === 'function');
	await page.evaluate(() => window.bootEngine({scene: 'water-loop'}));
	const canvas = page.locator('canvas');

	await expect(canvas).toHaveAttribute('data-scene', 'water-loop', {timeout: 60000});

	const water = async() => JSON.parse(await canvas.getAttribute('data-water') || '{}');

	const body = async() => JSON.parse(await canvas.getAttribute('data-body') || '{}');
	const setup = `<?php
		$body->x = 156;
		$body->y = 430;
		$body->vx = $body->vy = $body->groundSpeed = 0;
		$body->angle = 0;
		$body->grounded = true;
		$body->jumping = $body->rolling = false;
	`;

	await expect.poll(async() => (await water()).features?.platforms?.['3958']?.y).toBeGreaterThan(400);
	expect((await water()).draws).toBe(2);
	expect((await water()).features?.switches?.['103']).toEqual({active: false, top: 440});
	await page.evaluate(source => enginePhp.run(source), setup);
	await page.keyboard.down('Space');
	await expect.poll(async() => (await body()).supportY).toBeLessThan(430);
	await page.keyboard.up('Space');
	await expect.poll(async() => (await water()).features?.activeSwitch, {timeout: 5000}).toBe(101);
	const landedFrame = Number(await canvas.getAttribute('data-frames'));

	await expect.poll(async() => Number(await canvas.getAttribute('data-frames'))).toBeGreaterThan(landedFrame + 3);
	expect((await body()).grounded).toBe(true);
	expect((await body()).supportY).toBeCloseTo(440, 2);
	expect((await body()).angle).toBeCloseTo(0, 3);

	await page.evaluate(() => enginePhp.run('<?php $body->x = 480; $game->level->waterFeatures->activate(103);'));

	await expect.poll(async() => (await water()).features?.activeSwitch).toBe(103);
	expect((await water()).features?.switches?.['103']?.active).toBe(true);
	await expect.poll(async() => (await water()).features?.levels?.['10']?.surface).toBe(384);
	await expect.poll(async() => (await water()).features?.platforms?.['3958']?.y).toBeLessThan(513);
	expect(errors).toEqual([]);
	await page.evaluate(() => window.stopEngine());
});

for(const direction of [1, -1])
test('charged spindash follows the submerged loop in direction ' + direction, async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/');
	await page.waitForFunction(() => typeof window.bootEngine === 'function');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'water-loop'}));
	const canvas = page.locator('canvas');

	await expect(canvas).toHaveAttribute('data-scene', 'water-loop', {timeout: 60000});

	const body = async() => JSON.parse(await canvas.getAttribute('data-body') || '{}');

	await run(page, `
		$engine->userPaused = true;
		$body->x = ${direction > 0 ? 736 : 1056};
		$body->y = 558;
		$body->vx = $body->vy = $body->groundSpeed = $body->angle = 0;
		$body->grounded = true;
		$body->jumping = $body->rolling = false;
		$body->halfHeight = 18;
		$body->facing = $body->direction = ${direction};
		$body->collisionLayer = ${direction > 0 ? 1 : 2};
		$engine->controls->reset();
		$loopTrace = [];
		$loopDone = false;
		$engine->systems->add('submerged-loop-trace', static function() use ($body, $engine) {
			global $loopTrace, $loopDone;

			if(!$body->rolling || $loopDone)
			{
				return;
			}

			$loopTrace[] = [
				'angle' => $body->angle, 'grounded' => $body->grounded, 'skimming' => $body->skimming,
				'underwater' => $body->underwater, 'entered' => $body->waterEntered,
				'exited' => $body->waterExited, 'layer' => $body->collisionLayer,
				'x' => $body->x, 'supportY' => $body->supportY()
			];

			if($body->skimming || ${direction > 0 ? '$body->x > 1100' : '$body->x < 700'})
			{
				$loopDone = true;
				$engine->userPaused = true;
			}
		}, after: ['movement']);
		$engine->userPaused = false;
	`
	);

	await canvas.focus();
	await page.keyboard.down('ArrowDown');
	await page.keyboard.down('x');
	await expect.poll(async() => (await body()).charge).toBeGreaterThan(.99);
	await page.keyboard.up('x');
	await page.keyboard.up('ArrowDown');

	await expect.poll(async() => {
		const current = await body();

		return current.skimming || (direction > 0 ? current.x > 1100 : current.x < 700);
	}).toBe(true);

	const trace = JSON.parse(await run(page, 'echo json_encode($loopTrace);'));
	expect(trace.length).toBeGreaterThan(30);
	expect(trace.every(frame => frame.grounded && !frame.skimming)).toBe(true);
	expect(trace.some(frame => frame.exited)).toBe(true);
	expect(trace.some(frame => frame.entered)).toBe(true);
	expect(trace.some(frame => Math.abs(frame.angle) > 3)).toBe(true);

	const final = trace.at(-1);
	expect(final.underwater).toBe(true);
	expect(final.layer).toBe(direction > 0 ? 2 : 1);
	expect(final.supportY).toBeCloseTo(576, 2);
	expect(errors).toEqual([]);
});

for(const scene of [
	'platformer', 'water', 'water-rail', 'water-skim-rail'
	, 'water-loop', 'badniks', 'grinding'
	, 'loop', 'infinite', 'isometric', 'breakables', 'bridges'
])
test(
	scene + ' live PHP demo starts, responds to input and stops'
	, async({page}) => {
		const errors = [];
		page.on('pageerror', e => errors.push(e.message));
		await page.goto('/');
		await page.waitForFunction(() => typeof window.bootEngine==='function');
		await page.evaluate(scene => window.bootEngine({scene}), scene);

		await expect.poll(
			async() => Number(await page.locator('canvas').getAttribute('data-frames'))
			, {timeout: 60000}
		).toBeGreaterThan(20);

		await page.evaluate(() => {
			window.loseExtension = document.querySelector('canvas').getContext('webgl2').getExtension(
				'WEBGL_lose_context'
			);

			loseExtension.loseContext();
		});

		await expect(page.locator('#status')).toContainText('Graphics paused');
		const pausedFrames = Number(await page.locator('canvas').getAttribute('data-frames'));
		await page.waitForTimeout(100);
		expect(Number(await page.locator('canvas').getAttribute('data-frames'))).toBe(pausedFrames);
		await page.evaluate(() => loseExtension.restoreContext());

		await expect.poll(async() => Number(await page.locator('canvas').getAttribute('data-frames'))).toBeGreaterThan(
			pausedFrames+20
		);

		if(scene!=='isometric')
		{
			await expect.poll(
				async() => JSON.parse(await page.locator('canvas').getAttribute('data-body')||'{}').grounded
				, {timeout: 15000}
			).toBe(true);

			const before = JSON.parse(await page.locator('canvas').getAttribute('data-body'));
			await page.keyboard.down('ArrowRight');
			await page.waitForTimeout(700);
			await page.keyboard.up('ArrowRight');
			const after = JSON.parse(await page.locator('canvas').getAttribute('data-body'));
			expect(after.x).toBeGreaterThan(before.x+10);
			await page.keyboard.press('Space');
			await page.waitForTimeout(100);
		}

		if(scene==='water-rail')
		{
			await page.evaluate(() => enginePhp.run(`<?php
				$body->x = 2880;
				$body->y = 300;
				$body->vx = $body->vy = $body->groundSpeed = 0;
				$body->angle = 0;
				$body->grounded = $body->jumping = false;
			`
			));

			await expect.poll(
				async() => JSON.parse(await page.locator('canvas').getAttribute('data-body')||'{}').grounded
				, {timeout: 5000}
			).toBe(true);

			const body = JSON.parse(await page.locator('canvas').getAttribute('data-body'));
			expect(body.supportY).toBeCloseTo(448, 2);
		}

		await page.screenshot({path: 'test-results/' + scene + '.png',fullPage: true});
		await page.evaluate(() => window.stopEngine());
		await expect(page.locator('#start')).toBeEnabled();
		expect(errors).toEqual([]);
	}
);

test('a live block stops walking, shatters on rolling impact, restores debris and resets', async({page}) => {
	const errors = [];
	page.on('pageerror', e => errors.push(e.message));
	await page.goto('/');
	await page.waitForFunction(() => typeof window.bootEngine==='function');
	await page.evaluate(() => window.bootEngine({scene: 'breakables'}));
	const canvas = page.locator('canvas');

	const state = async() => JSON.parse(await canvas.getAttribute('data-breakables')||'{}');

	const body = async() => JSON.parse(await canvas.getAttribute('data-body')||'{}');
	await expect.poll(async() => (await body()).grounded, {timeout: 60000}).toBe(true);
	expect(await state()).toEqual({intact: 3,pieces: 0});
	await page.keyboard.down('ArrowRight');
	await expect.poll(async() => (await body()).x, {timeout: 15000}).toBeGreaterThan(295);
	await page.waitForTimeout(250);
	expect((await body()).x).toBeLessThanOrEqual(296.001);
	await page.keyboard.press('x');
	await page.waitForTimeout(100);
	expect(await state()).toEqual({intact: 3,pieces: 0});
	expect((await body()).rolling).toBe(false);
	await page.keyboard.up('ArrowRight');
	await page.keyboard.press('r');
	await expect.poll(async() => Math.abs((await body()).x-80)).toBeLessThan(1);
	await expect.poll(async() => (await body()).grounded).toBe(true);
	await page.keyboard.down('ArrowRight');
	await expect.poll(async() => (await body()).x, {intervals: [16]}).toBeGreaterThan(160);
	await page.keyboard.down('ArrowDown');
	await expect.poll(async() => (await body()).rolling, {intervals: [16]}).toBe(true);
	await expect.poll(async() => await state(), {intervals: [16]}).toEqual({intact: 2,pieces: 12});
	await page.keyboard.up('ArrowRight');
	await page.keyboard.up('ArrowDown');

	await page.evaluate(() => {
		window.shardContext = document.querySelector('canvas').getContext('webgl2')
			.getExtension('WEBGL_lose_context');

		shardContext.loseContext();
	});

	await expect(page.locator('#status')).toContainText('Graphics paused');
	const pausedFrames = Number(await canvas.getAttribute('data-frames'));
	await page.waitForTimeout(100);
	expect(Number(await canvas.getAttribute('data-frames'))).toBe(pausedFrames);
	expect(await state()).toEqual({intact: 2,pieces: 12});
	await page.evaluate(() => shardContext.restoreContext());

	await expect.poll(async() => Number(await canvas.getAttribute('data-frames')))
		.toBeGreaterThan(pausedFrames+2);

	expect(await state()).toEqual({intact: 2,pieces: 12});
	await page.keyboard.down('ArrowRight');
	await expect.poll(async() => (await body()).x, {timeout: 10000}).toBeGreaterThan(420);
	await page.keyboard.up('ArrowRight');
	await expect.poll(async() => (await state()).pieces).toBe(0);
	await page.keyboard.press('r');
	await expect.poll(async() => await state()).toEqual({intact: 3,pieces: 0});
	await expect.poll(async() => Math.abs((await body()).x-80)).toBeLessThan(1);
	expect((await body()).rolling).toBe(false);
	await page.evaluate(() => window.stopEngine());
	expect(errors).toEqual([]);
});

test('the live loop switches routes, exits, and runs back through the other direction', async({page}) => {
	const errors = [];
	page.on('pageerror', e => errors.push(e.message));
	await page.goto('/');
	await page.waitForFunction(() => typeof window.bootEngine==='function');
	await page.evaluate(() => window.bootEngine({scene: 'loop'}));

	const body = async() => JSON.parse(await page.locator('canvas').getAttribute('data-body')||'{}');
	await expect.poll(async() => (await body()).grounded, {timeout: 60000}).toBe(true);

	await run(page, `
		$liveLoopTrace = [];
		$engine->systems->add('browser-loop-trace', static function() use ($body) {
			global $liveLoopTrace;
			$liveLoopTrace[] = [
				'x' => $body->x
				, 'y' => $body->y
				, 'angle' => $body->angle
				, 'collisionLayer' => $body->collisionLayer
			];
		}, after: ['movement']);
	`
	);

	await page.evaluate(() => {
		window.motionBlurTrace = [];

		new MutationObserver(() => {
			motionBlurTrace.push(JSON.parse(document.querySelector('canvas').dataset.motionBlur||'{}'));
		}).observe(document.querySelector('canvas'), {attributes: true,attributeFilter: ['data-body']});
	});

	await page.keyboard.down('ArrowRight');
	await expect.poll(async() => (await body()).x, {timeout: 20000,intervals: [50]}).toBeGreaterThan(1184);
	await page.keyboard.up('ArrowRight');

	const forward = JSON.parse(await run(page, `
		echo json_encode($liveLoopTrace);
		$liveLoopTrace = [];
		$game->setMotionBlur(false);
	`
	));

	expect(forward.some(b => b.y<250)).toBe(true);
	expect(forward.some(b => Math.abs(b.angle)>2.6)).toBe(true);
	expect(forward.some(b => b.y<300&&b.x<912&&b.collisionLayer===1)).toBe(true);
	expect(await page.evaluate(() => motionBlurTrace.some(blur => blur.active&&blur.sigma>.5))).toBe(true);
	expect((await body()).collisionLayer).toBe(2);
	await page.keyboard.down('ArrowLeft');
	await expect.poll(async() => (await body()).x, {timeout: 20000,intervals: [50]}).toBeLessThan(640);
	await page.keyboard.up('ArrowLeft');
	const backward = JSON.parse(await run(page, 'echo json_encode($liveLoopTrace);'));
	expect(backward.some(b => b.y<250)).toBe(true);
	expect((await body()).collisionLayer).toBe(1);
	await page.keyboard.press('r');
	await expect.poll(async() => Math.abs((await body()).x-80), {intervals: [50]}).toBeLessThan(1);
	await page.evaluate(() => window.stopEngine());
	expect(errors).toEqual([]);
});
