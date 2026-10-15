import {test, expect} from '@playwright/test';

const state = page => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.badniks ?? 'null'));

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

test('CrabMeat patrols, fires, damages a walker, and pops under a rolling player', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'badniks'}));
	await expect.poll(() => state(page), {timeout: 60000}).not.toBeNull();
	await expect.poll(async() => (await state(page)).badniks).toBe(2);
	await expect.poll(async() => (await state(page)).projectiles, {timeout: 10000}).toBeGreaterThanOrEqual(2);

	const hit = JSON.parse(await run(page, `
		$id = $game->level->crabs->entities()[0];
		$badnik = $engine->entities->get($id, PhpWasmEngine\\Gameplay\\Badnik::class);
		$combat = $engine->entities->get($game->entity, PhpWasmEngine\\Gameplay\\PlayerCombat::class);
		$combat->mercy = 0;
		$game->counter->rings = 5;
		$body->x = $badnik->x;
		$body->y = $badnik->y - $badnik->height / 2;
		$body->grounded = true;
		$body->rolling = false;
		$game->level->badniks->sync($game->entity, $body);
		$game->level->badniks->update($engine->entities, 1 / 60);
		echo json_encode([$combat->hits, $combat->mercy, $game->counter->rings,
			$game->level->rings->scatteredCount(),
			$engine->entities->has($id, PhpWasmEngine\\Gameplay\\Badnik::class)]);
	`
	));

	expect(hit[0]).toBe(1);
	expect(hit[1]).toBeGreaterThan(0);
	expect(hit[2]).toBe(0);
	expect(hit[3]).toBe(5);
	expect(hit[4]).toBe(true);
	await expect.poll(async() => (await state(page)).scatteredRings).toBe(5);

	await run(page, `
		$combat = $engine->entities->get($game->entity, PhpWasmEngine\\Gameplay\\PlayerCombat::class);
		$game->menu->menu->open();
		$engine->userPaused = true;
		$combat->mercy = .35;
	`
	);

	await expect.poll(
		async() => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.body).opacity)
		, {intervals: [20], timeout: 1500}
	).toBe(0);

	const popped = JSON.parse(await run(page, `
		$game->menu->menu->close();
		$engine->userPaused = false;

		foreach($engine->entities->query(PhpWasmEngine\\Gameplay\\BadnikProjectile::class) as $shot)
		{
			$engine->entities->destroy($shot);
		}

		$id = $game->level->crabs->entities()[0];
		$badnik = $engine->entities->get($id, PhpWasmEngine\\Gameplay\\Badnik::class);
		$combat = $engine->entities->get($game->entity, PhpWasmEngine\\Gameplay\\PlayerCombat::class);
		$combat->mercy = 0;
		$body->x = $badnik->x - 100;
		$body->y = $badnik->y - $badnik->height / 2;
		$body->grounded = true;
		$body->rolling = true;
		$game->level->badniks->sync($game->entity, $body);
		$body->x = $badnik->x + 100;
		$game->level->badniks->update($engine->entities, 1 / 60);
		echo json_encode([$combat->defeats, $game->counter->score,
			$engine->entities->has($id, PhpWasmEngine\\Gameplay\\Badnik::class)]);
	`
	));

	expect(popped).toEqual([1, 100, false]);
	await expect.poll(async() => (await state(page)).badniks).toBe(1);
	expect(errors).toEqual([]);
});
