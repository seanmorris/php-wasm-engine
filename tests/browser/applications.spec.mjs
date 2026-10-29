import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {pack} from '../../cli/pack.mjs';
import {serve} from '../../cli/server.mjs';
let directory, server, origin;

test.beforeAll(async () => {
	directory = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-application-browser-'));

	await fs.writeFile(path.join(directory, 'application.php'), `<?php
require_once 'phar://' . Phar::running(false) . '/core/autoload.php';
$app = new \\Phonics\\Application\\Context;
vrzno_env('canvas')->dataset->binary = bin2hex(file_get_contents(__DIR__ . '/data.bin'));
vrzno_env('canvas')->dataset->init = json_encode($app->init);
$app->browser(__DIR__ . '/browser.mjs');
$app->ready();
`
	);

	await fs.writeFile(
		path.join(directory, 'browser.mjs')
		, 'export function activate({canvas}) { canvas.dataset.started = "yes"; return () => { delete canvas.dataset.started; }; }'
	);

	await fs.writeFile(path.join(directory, 'data.bin'), Buffer.from([0,255,128]));

	await fs.writeFile(
		path.join(directory, 'phonics.json')
		, JSON.stringify({
			schemaVersion: 1
			, name: '@test/no-world'
			, version: '1.0.0'
			, application: {entry: 'application.php', assets: ['data.bin']}
			, browser: {files: ['browser.mjs']}
		})
	);

	await pack(directory);
	server = await serve(directory, {port: 0, headers: {'Access-Control-Allow-Origin': '*'}});
	origin = `http://127.0.0.1:${server.address().port}`;
});

test.afterAll(
	async () => {
		await new Promise(resolve => server?.close(resolve));
		await fs.rm(directory, {recursive: true, force: true});
	}
);

test('executes an authored stub without a world, passes init and disposes browser services', async ({page}) => {
	await page.goto('/preview/');

	await page.evaluate(async origin => {
		const {Phonics} = await import('/engine/browser/Phonics.mjs');

		window.app = await Phonics.run(origin + '/dist/game.phar', {
			canvas: document.querySelector('canvas')
			, init: {cheats: ['idkfa'], startMap: 'MAP04'}
			, policy: {codeOrigins: [location.origin, origin]}
		});
	}, origin
	);

	await expect(page.locator('canvas')).toHaveAttribute('data-binary', '00ff80');
	await expect(page.locator('canvas')).toHaveAttribute('data-started', 'yes');
	expect(await page.locator('canvas').getAttribute('data-init')).toBe('{"cheats":["idkfa"],"startMap":"MAP04"}');
	expect(await page.evaluate(() => app.world)).toBeUndefined();
	await page.evaluate(async () => { await app.dispose(); await app.dispose(); });

	await expect(page.locator('canvas')).not.toHaveAttribute('data-started');
});

for(const slug of ['sane-phonics','php-noid','php-tris','php-slalom','php-buster','doom-phonics'])
test(`${slug} boots its archive without fetching loose PHP and restarts cleanly`, async ({page}) => {
	await page.goto('/preview/');
	const errors = [], requests = [];
	page.on('pageerror', error => errors.push(error.message));
	page.on('console', message => { if(message.type() === 'error') errors.push(message.text()); });

	page.on('request', request => requests.push(request.url()));

	for(let attempt = 0; attempt < 2; attempt++)
	{
		await page.evaluate(async slug => {
			const {Phonics} = await import('/engine/browser/Phonics.mjs');

			window.app = await Phonics.run(
				'/engine/packages/' + slug + '/dist/game.phar'
				, {canvas: document.querySelector('canvas')}
			);
		}, slug
		);

		expect(await page.evaluate(() => app.application.id)).toBe('@seanmorris/' + slug);
		await page.locator('canvas').focus();
		await page.keyboard.press('Enter');
		await page.waitForTimeout(500);

		expect(
			await page.evaluate(
				() => app.php.run('<?php if(!isset($engine)) throw new RuntimeException("Missing application scope");')
			)
		).toBe(0);

		await page.evaluate(() => app.dispose());
	}

	expect(requests.filter(url => /\.php(?:\?|$)/.test(url))).toEqual([]);
	expect(errors).toEqual([]);
});
