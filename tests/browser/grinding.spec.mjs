import {test,expect} from '@playwright/test';

const state = page => page.locator('canvas').evaluate(canvas => ({
	body: JSON.parse(canvas.dataset.body ?? 'null')
	, combo: JSON.parse(canvas.dataset.combo ?? 'null')
	, hud: JSON.parse(canvas.dataset.hud ?? 'null')
}));

async function run(page, source)
{
	return page.evaluate(async source => {
		let output = '';

		const listener = event => output += event.detail.join('');
		enginePhp.addEventListener('output', listener);

		try
		{
			const code = await enginePhp.run('<?php ' + source);

			if(code) throw Error('PHP returned ' + code);

			return output;
		}
		finally
		{
			enginePhp.removeEventListener('output', listener);
		}
	}, source
	);
}

test.afterEach(async({page}) => {
	await page.evaluate(() => window.enginePhp?.refresh()).catch(() => {});
});

test('Tiled rail contact drives the top-right combo HUD and banks into score', async({page}) => {
	await page.goto('/');
	await page.evaluate(() => window.bootEngine({intro: false,scene: 'platformer'}));
	await expect.poll(async() => (await state(page)).body).not.toBeNull();

	const contact = JSON.parse(await run(page, `
		$game->menu->menu->open();
		$engine->userPaused = true;
		$body->x = 1360;
		$body->y = 350;
		$body->vx = 0;
		$body->vy = 3000;
		$body->terminalSpeed = 3000;
		$body->gravity = 0;
		$body->grounded = false;
		$body->grinding = false;
		$engine->controls->reset();
		$engine->controls->set([]);
		$game->level->movement->step($body, $engine->controls, 1 / 60);
		$game->combo->update($body, 1 / 60);
		echo json_encode([$body->grounded, $body->grinding, $body->y]);
	`
	));

	expect(contact[0]).toBe(true);
	expect(contact[1]).toBe(true);
	expect(contact[2]).toBeCloseTo(398, 2);
	await expect.poll(async() => (await state(page)).combo.visible).toBe(true);
	let current = await state(page);
	expect(current.body.grinding).toBe(true);
	expect(current.body.pose).toBe('grinding');
	expect(current.combo.labels).toEqual(['Rail Grind']);
	expect(current.combo.draws).toBe(1);

	await run(page, `
		foreach(range(1, 5) as $index)
		{
			$game->combo->add(new PhpWasmEngine\\Gameplay\\ComboReward('Trick ' . $index, 10, 1));
		}
	`
	);

	await expect.poll(async() => (await state(page)).combo.overflow).toBe(2);
	current = await state(page);
	expect(current.combo.labels).toEqual(['Trick 2','Trick 3','Trick 4','Trick 5']);
	const before = current.hud.score;

	await run(page, `
		$body->grinding = false;
		$body->grounded = true;
		$score = $game->combo->update($body, .1);
		$counter->score += $score ?? 0;
	`
	);

	await expect.poll(async() => (await state(page)).combo.result).toBeGreaterThan(0);
	current = await state(page);
	expect(current.combo.failed).toBe(false);
	expect(current.combo.labels).toEqual([]);
	expect(current.hud.score).toBeGreaterThan(before);
});

test('the live grinding course takes every bend and collision switch in both directions', async({page}) => {
	test.setTimeout(60000);
	await page.goto('/');
	await page.evaluate(() => window.bootEngine({intro: false,scene: 'grinding'}));
	await expect.poll(async() => (await state(page)).body?.grinding, {timeout: 30000}).toBe(true);

	await page.evaluate(() => {
		window.grindingTrace = [];

		new MutationObserver(() => {
			const body = JSON.parse(document.querySelector('canvas').dataset.body ?? 'null');

			if(body) grindingTrace.push(body);
		}).observe(document.querySelector('canvas'), {attributes: true,attributeFilter: ['data-body']});
	});

	await page.keyboard.press('r');
	await expect.poll(async() => (await state(page)).body.x).toBeLessThan(100);
	await expect.poll(async() => (await state(page)).body.grounded).toBe(true);
	await page.evaluate(() => grindingTrace.splice(0));

	await page.keyboard.down('ArrowRight');
	await expect.poll(async() => (await state(page)).body.x, {timeout: 20000,intervals: [50]}).toBeGreaterThan(1920);
	await page.keyboard.up('ArrowRight');
	const forward = await page.evaluate(() => grindingTrace.splice(0));
	expect(forward.some(body => body.x > 210 && body.x < 300 && !body.grinding)).toBe(true);
	expect(forward.some(body => body.x > 340 && body.x < 480 && body.grinding)).toBe(true);
	expect(forward.filter(body => body.x > 320 && body.x < 1920).every(body => body.grinding)).toBe(true);
	expect(forward.some(body => body.x < 1280 && Math.abs(Math.abs(body.angle) - Math.PI / 2) < .2)).toBe(true);
	expect(forward.some(body => body.x > 768 && body.x < 1024 && body.y < 190)).toBe(true);
	expect(forward.some(body => Math.abs(Math.abs(body.angle) - Math.PI) < .2)).toBe(true);
	expect(forward.some(body => body.y < 300 && body.x < 1680 && body.collisionLayer === 1)).toBe(true);
	expect((await state(page)).body.collisionLayer).toBe(2);

	await page.keyboard.down('ArrowLeft');
	await expect.poll(async() => (await state(page)).body.x, {timeout: 20000,intervals: [50]}).toBeLessThan(160);
	await page.keyboard.up('ArrowLeft');
	const backward = await page.evaluate(() => grindingTrace);
	expect(backward.filter(body => body.x > 320 && body.x < 1920).every(body => body.grinding)).toBe(true);
	expect(backward.some(body => body.x > 210 && body.x < 300 && !body.grinding)).toBe(true);
	expect(backward.some(body => body.x < 170 && body.grinding)).toBe(true);
	expect(backward.some(body => body.y < 300 && body.collisionLayer === 2)).toBe(true);
	expect((await state(page)).body.collisionLayer).toBe(1);
	expect((await state(page)).combo.labels).toEqual(['Rail Grind']);
});
