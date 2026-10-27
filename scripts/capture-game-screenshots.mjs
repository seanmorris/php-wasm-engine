import {spawn} from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from '@playwright/test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const output = path.join(root, 'site/static/images/games');
const port = Number(process.env.PHONICS_SCREENSHOT_PORT || 9142);
const baseURL = process.env.PHONICS_SCREENSHOT_URL || `http://127.0.0.1:${port}`;
const selected = process.env.PHONICS_SCREENSHOT_GAME || '';
let server = null;

const available = async() => {
	try
	{
		return (await fetch(baseURL)).ok;
	}
	catch
	{
		return false;
	}
};

if(!(await available()))
{
	server = spawn('npm', ['start'], {
		cwd: root
		, detached: process.platform !== 'win32'
		, env: {...process.env, PORT: String(port)}
		, stdio: 'ignore'
	});

	for(let attempt = 0; attempt < 1200 && !(await available()); attempt++)
		await new Promise(resolve => setTimeout(resolve, 500));

	if(!(await available())) throw new Error(`Documentation server did not start at ${baseURL}`);
}

await fs.mkdir(output, {recursive: true});
const executablePath = await fs.access('/usr/bin/chromium').then(() => '/usr/bin/chromium').catch(() => undefined);

async function capture(slug, start, settle = 750, beforeLoad = null, crop = null)
{
	if(selected && slug !== selected) return;

	const browser = await chromium.launch({
		headless: true
		, executablePath
		, args: ['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader']
	});

	const context = await browser.newContext({
		viewport: {width: 1280, height: slug === 'php-buster' ? 1200 : 720}
		, deviceScaleFactor: slug === 'php-buster' ? 2 : 1
	});

	const page = await context.newPage();

	try
	{
		if(beforeLoad) await beforeLoad(page);

		const route = `games/${slug}/`;
		await page.goto(`${baseURL}/${route}?embed=1`, {waitUntil: 'domcontentloaded'});
		const poster = page.locator('[data-game-embed] > .game-embed-poster');

		if(await poster.count()) await poster.click();

		await start(page);

		if(settle > 0) await page.waitForTimeout(settle);

		const selector = {
			'php-noid': '.php-noid-screen'
			, 'php-tris': '.php-tris-screen'
			, 'php-slalom': '.php-slalom-screen'
			, 'php-buster': '.php-buster-screen'
		}[slug];

		const target = selector ? page.locator(selector) : page;
		const screenshot = {path: path.join(output, `${slug}.png`)};

		if(crop)
		{
			const box = await target.boundingBox();

			if(!box) throw new Error(`Cannot measure ${slug} screenshot target`);

			screenshot.clip = await crop(page, box);
			await page.screenshot(screenshot);
		}
		else await target.screenshot(screenshot);

		console.log(`Captured ${slug}.png`);
	}
	finally
	{
		await browser.close();
	}
}

try
{
	await capture('sane-phonics', async page => {
		await page.waitForFunction(() => typeof window.bootEngine === 'function');
		await page.evaluate(() => window.bootEngine({intro: false, scene: 'platformer'}));
		await page.waitForSelector('canvas[data-scene="platformer"]', {timeout: 60000});
		await page.locator('canvas').click();
		await page.keyboard.down('ArrowRight');
		await page.waitForTimeout(800);
		await page.keyboard.up('ArrowRight');
		await page.waitForTimeout(2000);
	});

	await capture('php-noid', async page => {
		await page.waitForFunction(
			() => document.querySelector('#status')?.textContent === 'Ready'
			, null
			, {timeout: 60000}
		);

		await page.locator('[data-action="start"]').click();

		await page.waitForFunction(
			() => JSON.parse(document.querySelector('canvas').dataset.phpNoid).phase === 'playing'
		);

		await page.locator('canvas').focus();
		await page.keyboard.press('Space');
	});

	await capture('php-tris', async page => {
		await page.waitForFunction(
			() => document.querySelector('#status')?.textContent === 'Ready'
			, null
			, {timeout: 60000}
		);

		await page.locator('[data-action="start"]').click();

		await page.waitForFunction(
			() => JSON.parse(document.querySelector('canvas').dataset.phpTris).phase === 'playing'
		);

		await page.keyboard.press('Space');
	});

	await capture('php-slalom', async page => {
		await page.waitForFunction(
			() => document.querySelector('#status')?.textContent === 'Ready'
			, null
			, {timeout: 60000}
		);

		await page.locator('[data-action="new-game"]').click();

		await page.waitForFunction(
			() => JSON.parse(document.querySelector('canvas').dataset.phpSlalom).page === 'play'
		);

		await page.locator('canvas').focus();

		const score = value => page.waitForFunction(value => {
			try
			{
				return JSON.parse(document.querySelector('canvas')?.dataset.phpSlalom || '{}').score >= value;
			}
			catch
			{
				return false;
			}
		}, value, {polling: 'raf', timeout: 30000});

		await page.keyboard.down('ArrowLeft');
		await score(180);
		await page.keyboard.up('ArrowLeft');
		await score(540);
		await page.keyboard.down('ArrowLeft');
		await score(660);
		await page.evaluate(() => window.__freezeSlalomCapture());
		await page.waitForFunction(() => window.__slalomCaptureFrozen === true);
		await page.keyboard.up('ArrowLeft');
		await page.locator('canvas').evaluate(canvas => canvas.getContext('webgl2').finish());
	}, 0, async page => page.addInitScript(seed => {
		const nativeContext = HTMLCanvasElement.prototype.getContext;

		HTMLCanvasElement.prototype.getContext = function(type, options) {
			if(type === 'webgl2') options = {...options, preserveDrawingBuffer: true};

			return nativeContext.call(this, type, options);
		};

		const nativeRandom = crypto.getRandomValues.bind(crypto);

		crypto.getRandomValues = array => {
			if(array instanceof Uint32Array && array.length === 1)
			{
				array[0] = seed;

				return array;
			}

			return nativeRandom(array);
		};

		const nativeRaf = requestAnimationFrame.bind(window), pending = new Set;

		window.requestAnimationFrame = callback => {
			let id = 0;

			id = nativeRaf(now => {
				pending.delete(id);
				callback(now);
			});

			pending.add(id);

			return id;
		};

		window.__freezeSlalomCapture = () => {
			for(const pendingId of pending) cancelAnimationFrame(pendingId);
			pending.clear();

			window.requestAnimationFrame = () => 0;
			window.__slalomCaptureFrozen = true;
		};
	}, 20
	), async(page, box) => {
		const height = Math.min(box.height, box.width * 9 / 16);

		return {x: box.x, y: box.y + box.height - height, width: box.width, height};
	});

	await capture('php-buster', async page => {
		await page.waitForFunction(
			() => document.querySelector('#status')?.textContent === 'Ready'
			, null
			, {timeout: 60000}
		);

		await page.locator('[data-action="new-game"]').click();

		await page.waitForFunction(
			() => JSON.parse(document.querySelector('canvas').dataset.phpBuster).page === 'play'
		);

		await page.locator('canvas').focus();
		await page.keyboard.down('ArrowLeft');
		await page.waitForTimeout(450);
		await page.keyboard.up('ArrowLeft');
		await page.keyboard.down('ArrowUp');
		await page.waitForTimeout(875);
		await page.keyboard.up('ArrowUp');
		await page.waitForTimeout(900);
		await page.keyboard.down('Space');
		await page.waitForTimeout(150);
	}, 100, async page => page.addInitScript(seed => {
		const nativeRandom = crypto.getRandomValues.bind(crypto);

		crypto.getRandomValues = array => {
			if(array instanceof Uint32Array && array.length === 1)
			{
				array[0] = seed;

				return array;
			}

			return nativeRandom(array);
		};
	}, 37
	), async(page, box) => {
		const state = await page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.phpBuster));
		const height = Math.min(box.height, box.width * 9 / 16);
		const viewport = state.viewport ?? [0, 0, state.width, state.height];
		const playerY = viewport[1] + state.player[1] / 480 * viewport[3];
		const screenY = box.y + playerY / state.height * box.height;
		const y = Math.max(box.y, Math.min(box.y + box.height - height, screenY - height * .8));

		return {x: box.x, y, width: box.width, height};
	});

	await capture('doom-phonics', async page => {
		await page.waitForFunction(
			() => window.previewSession?.doomState?.menu?.screen === 'title'
			, null
			, {timeout: 60000}
		);

		await page.locator('canvas').focus();
		await page.keyboard.press('Enter');
		await page.waitForFunction(() => window.previewSession?.doomState?.menu?.screen === 'skill');
		await page.keyboard.press('Enter');

		await page.waitForFunction(
			() => window.previewSession?.doomState?.menu?.open === false
				&& window.previewSession?.doomState?.wipe?.active === false
			, null
			, {timeout: 90000}
		);

		await page.keyboard.down('w');
		await page.waitForTimeout(600);
		await page.keyboard.up('w');
	});
}
finally
{
	if(server)
	{
		if(process.platform === 'win32') server.kill();
		else process.kill(-server.pid, 'SIGTERM');
	}
}
