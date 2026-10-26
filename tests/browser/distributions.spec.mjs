import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import http from 'node:http';
import {execFileSync} from 'node:child_process';

test('packed core and PhpNoid boot from a separate static origin without workspace files', async ({page}) => {
	const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-distribution-'));
	let server;

	try
	{
		for(const [name,source] of [['phonics','.'],['php-noid','packages/php-noid']])
		{
			const [packed] = JSON.parse(
				execFileSync(
					'npm'
					, ['pack','--json','--ignore-scripts','--pack-destination',directory]
					, {cwd: path.resolve(source),encoding: 'utf8'}
				)
			);

			await fs.mkdir(path.join(directory, name));

			execFileSync(
				'tar'
				, ['-xzf',path.join(directory, packed.filename),'-C',path.join(directory, name),'--strip-components=1']
			);
		}

		const requests = [];

		server = http.createServer(async(request, response) => {
			response.setHeader('Access-Control-Allow-Origin', '*');
			const url = new URL(request.url, 'http://localhost');
			requests.push(url.pathname);

			if(url.pathname.endsWith('.mjs')) response.setHeader('Content-Type', 'text/javascript');

			try
			{
				response.end(await fs.readFile(path.join(directory, url.pathname)));
			}
			catch
			{
				response.statusCode = 404;
				response.end('missing');
			}
		});

		await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
		const origin = `http://127.0.0.1:${server.address().port}`;
		await page.goto('/preview/');
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));

		await page.evaluate(async origin => {
			const {Phonics} = await import(origin + '/phonics/browser/Phonics.mjs');

			window.packedSession = await Phonics.loadWorld(origin + '/php-noid/worlds/campaign.world', {
				canvas: document.querySelector('canvas')
				, runtime: {url: location.origin + '/runtime/php8.4-sdl.mjs'}
				, policy: {codeOrigins: [origin,location.origin]}
			});
		}, origin
		);

		const canvas = page.locator('canvas');

		const state = async () => JSON.parse(await canvas.getAttribute('data-php-noid'));
		await expect.poll(async () => (await state()).phase).toBe('playing');
		await canvas.focus();
		await page.keyboard.press('Space');
		await expect.poll(async () => (await state()).frames).toBeGreaterThan(10);

		await page.click('#mute');
		await expect(page.locator('#mute')).toHaveAttribute('aria-label', 'Unmute audio');
		await expect(page.locator('#mute svg')).toHaveCount(1);
		await page.evaluate(() => window.packedSession.dispose());
		await expect(page.locator('.quick-controls')).toHaveCount(0);

		// A canvas-only consumer also receives the shared UI and a complete fullscreen display.
		await page.evaluate(async origin => {
			const canvas = document.querySelector('canvas');
			document.body.replaceChildren(canvas);
			const {Phonics} = await import(origin + '/phonics/browser/Phonics.mjs');

			window.packedSession = await Phonics.loadWorld(origin + '/php-noid/worlds/campaign.world', {
				canvas
				, runtime: {url: location.origin + '/runtime/php8.4-sdl.mjs'}
				, policy: {codeOrigins: [origin,location.origin]}
			});
		}, origin
		);

		await expect(page.locator('.quick-controls button')).toHaveCount(2);
		await page.click('#fullscreen');

		await expect.poll(
			() => page.evaluate(() => document.fullscreenElement?.classList.contains('php-noid-ui'))
		).toBe(true);

		const display = await page.locator('.php-noid-screen').boundingBox();
		expect(display.width / display.height).toBeCloseTo(16 / 9, 2);
		expect(display.y + display.height).toBeLessThanOrEqual(page.viewportSize().height + 1);
		await page.evaluate(() => window.packedSession.dispose());
		await expect(page.locator('.quick-controls, .php-noid-ui')).toHaveCount(0);
		expect(await canvas.evaluate(element => element.parentElement === document.body)).toBe(true);
		expect(requests.some(url => url.includes('/sane-phonics/'))).toBe(false);
		expect(requests.some(url => url.includes('/phonics/src/Engine.php'))).toBe(true);
		expect(errors).toEqual([]);
	}
	finally
	{
		if(server) await new Promise(resolve => server.close(resolve));

		await fs.rm(directory, {recursive: true,force: true});
	}
});

test('packed core and PHPTris boot from a separate static origin without workspace files', async ({page}) => {
	const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'phonics-distribution-'));
	let server;

	try
	{
		for(const [name,source] of [['phonics','.'],['php-tris','packages/php-tris']])
		{
			const [packed] = JSON.parse(
				execFileSync(
					'npm'
					, ['pack','--json','--ignore-scripts','--pack-destination',directory]
					, {cwd: path.resolve(source),encoding: 'utf8'}
				)
			);

			await fs.mkdir(path.join(directory, name));

			execFileSync(
				'tar'
				, ['-xzf',path.join(directory, packed.filename),'-C',path.join(directory, name),'--strip-components=1']
			);
		}

		const requests = [];

		server = http.createServer(async(request, response) => {
			response.setHeader('Access-Control-Allow-Origin', '*');
			const url = new URL(request.url, 'http://localhost');
			requests.push(url.pathname);

			if(url.pathname.endsWith('.mjs')) response.setHeader('Content-Type', 'text/javascript');

			try
			{
				response.end(await fs.readFile(path.join(directory, url.pathname)));
			}
			catch
			{
				response.statusCode = 404;
				response.end('missing');
			}
		});

		await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
		const origin = `http://127.0.0.1:${server.address().port}`;
		await page.goto('/preview/');
		const errors = [];
		page.on('pageerror', error => errors.push(error.message));

		await page.evaluate(async origin => {
			const {Phonics} = await import(origin + '/phonics/browser/Phonics.mjs');

			window.packedSession = await Phonics.loadWorld(origin + '/php-tris/worlds/arcade.world', {
				canvas: document.querySelector('canvas')
				, runtime: {url: location.origin + '/runtime/php8.4-sdl.mjs'}
				, policy: {codeOrigins: [origin,location.origin]}
			});
		}, origin
		);

		const canvas = page.locator('canvas');

		const state = async () => JSON.parse(await canvas.getAttribute('data-php-tris'));
		await expect.poll(async () => (await state()).page).toBe('title');
		await page.locator('[data-action="start"]').click();
		await expect.poll(async () => (await state()).phase).toBe('playing');
		await page.keyboard.press('Space');
		await expect.poll(async () => (await state()).placed).toBe(1);

		await page.click('#mute');
		await expect(page.locator('#mute')).toHaveAttribute('aria-label', 'Unmute audio');
		await expect(page.locator('#mute svg')).toHaveCount(1);
		await page.evaluate(() => window.packedSession.dispose());
		await expect(page.locator('.quick-controls')).toHaveCount(0);

		// A canvas-only consumer also receives the shared UI and a complete fullscreen display.
		await page.evaluate(async origin => {
			const canvas = document.querySelector('canvas');
			document.body.replaceChildren(canvas);
			const {Phonics} = await import(origin + '/phonics/browser/Phonics.mjs');

			window.packedSession = await Phonics.loadWorld(origin + '/php-tris/worlds/arcade.world', {
				canvas
				, runtime: {url: location.origin + '/runtime/php8.4-sdl.mjs'}
				, policy: {codeOrigins: [origin,location.origin]}
			});
		}, origin
		);

		await expect(page.locator('.quick-controls button')).toHaveCount(2);
		await page.click('#fullscreen');

		await expect.poll(
			() => page.evaluate(() => document.fullscreenElement?.classList.contains('php-tris-ui'))
		).toBe(true);

		const display = await page.locator('.php-tris-screen').boundingBox();
		expect(display.width / display.height).toBeCloseTo(16 / 9, 2);
		expect(display.y + display.height).toBeLessThanOrEqual(page.viewportSize().height + 1);
		await page.evaluate(() => window.packedSession.dispose());
		await expect(page.locator('.quick-controls, .php-tris-ui')).toHaveCount(0);
		expect(await canvas.evaluate(element => element.parentElement === document.body)).toBe(true);
		expect(requests.some(url => url.includes('/sane-phonics/'))).toBe(false);
		expect(requests.some(url => url.includes('/phonics/src/Engine.php'))).toBe(true);
		expect(errors).toEqual([]);
	}
	finally
	{
		if(server) await new Promise(resolve => server.close(resolve));

		await fs.rm(directory, {recursive: true,force: true});
	}
});
