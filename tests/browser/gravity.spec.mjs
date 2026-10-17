import {expect, test} from '@playwright/test';

/**
 * Execute PHP inside the running demo module.
 * @param {import('@playwright/test').Page} page Browser page owning the PHP runtime.
 * @param {string} source PHP statements to execute after the opening tag.
 * @returns {Promise<string>} Captured standard output from the PHP invocation.
 */
async function run(page, source)
{
	const result = await page.evaluate(async source => {
		let output = '';
		let errors = '';

		const out = event => output += event.detail.join('');

		const error = event => errors += event.detail.join('');
		enginePhp.addEventListener('output', out);
		enginePhp.addEventListener('error', error);

		try
		{
			const code = await enginePhp.run('<?php ' + source);

			return {output, errors, code};
		}
		finally
		{
			enginePhp.removeEventListener('output', out);
			enginePhp.removeEventListener('error', error);
		}
	}, source
	);

	expect(result.errors).toBe('');
	expect(result.code).toBe(0);

	return result.output;
}

test('gravity course cycles all surfaces and oriented springs launch through the live ECS', async({page}) => {
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/');
	await page.waitForFunction(() => typeof window.bootEngine === 'function');
	await page.evaluate(() => window.bootEngine({intro: false, scene: 'gravity', motionBlur: false}));
	await expect(page.locator('canvas')).toHaveAttribute('data-scene', 'gravity', {timeout: 60000});

	const features = JSON.parse(await page.locator('canvas').getAttribute('data-gravity'));
	expect(Object.keys(features.switches)).toHaveLength(4);
	expect(Object.keys(features.springs)).toHaveLength(4);

	const directions = await page.evaluate(async() => {
		const canvas = document.querySelector('canvas');
		const seen = [];
		const started = performance.now();

		while(performance.now() - started < 5000 && seen.length < 4)
		{
			await new Promise(resolve => requestAnimationFrame(resolve));
			const body = JSON.parse(canvas.dataset.body || '{}');
			const direction = body.gravityX + ',' + body.gravityY;

			if(direction !== 'undefined,undefined' && !seen.includes(direction)) seen.push(direction);
		}

		return seen;
	});

	expect(directions).toEqual(expect.arrayContaining(['1,0', '0,-1', '-1,0', '0,1']));

	const launch = JSON.parse(await run(page, `
		$body->x = 430;
		$body->y = 526;
		$body->vx = $body->vy = $body->groundSpeed = 0;
		$body->setGravityDirection(0, 1);
		$body->angle = 0;
		$body->grounded = true;
		$game->level->gravityFeatures->update($engine->entities, 1 / 60);
		echo json_encode([
			$body->vx,
			$body->vy,
			$body->grounded,
			$body->jumping,
			$body->ability,
			$game->level->gravityFeatures->springs[411]->active
		]);
	`
	));

	expect(launch).toEqual([0, -1100, false, false, 'spring', true]);
	expect(errors).toEqual([]);
});
