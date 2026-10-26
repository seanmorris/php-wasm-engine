import {spawn} from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from '@playwright/test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const output = path.join(root, 'site/static/images/games');
const port = Number(process.env.PHONICS_SCREENSHOT_PORT || 9142);
const baseURL = process.env.PHONICS_SCREENSHOT_URL || `http://127.0.0.1:${port}`;
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

async function capture(slug, start)
{
	const browser = await chromium.launch({
		headless: true
		, executablePath
		, args: ['--no-sandbox', '--use-gl=angle', '--use-angle=swiftshader', '--enable-unsafe-swiftshader']
	});

	const context = await browser.newContext({viewport: {width: 1280, height: 720}, deviceScaleFactor: 1});
	const page = await context.newPage();

	try
	{
		await page.goto(`${baseURL}/${slug}/?embed=1`, {waitUntil: 'domcontentloaded'});
		await start(page);
		await page.waitForTimeout(750);
		const selector = {'php-noid': '.php-noid-screen', 'php-tris': '.php-tris-screen'}[slug];
		const target = selector ? page.locator(selector) : page;
		await target.screenshot({path: path.join(output, `${slug}.png`)});
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
