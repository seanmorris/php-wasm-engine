import {test, expect} from '@playwright/test';
import fs from 'node:fs/promises';
import path from 'node:path';

async function files(directory, prefix = '')
{
	const result = [];

	for(const entry of await fs.readdir(directory, {withFileTypes: true}))
	{
		const name = path.posix.join(prefix, entry.name);

		if(entry.isDirectory()) result.push(...await files(new URL(entry.name + '/', directory), name));
		else result.push(name);
	}

	return result;
}

const pages = (await files(new URL('../.cache/pages/', import.meta.url)))
	.filter(name => name.endsWith('.md')).map(name => name.replace(/\.md$/, '.html'));

const guidePages = pages.filter(name => !/^api-phonics-/.test(name) && !/^games\/[^/]+\/api\//.test(name));

async function openGame(page, slug, title)
{
	await page.goto(`/games/${slug}/`);
	await page.getByRole('button', {name: `Load and play ${title}`, exact: true}).click();
}

test('all guide pages fit desktop, tablet and mobile without loading a game runtime', async({page}) => {
	test.setTimeout(180000);

	const errors = [], failed = [], runtimes = [];
	page.on('pageerror', error => errors.push(error.message));

	page.on('response', response => {
		if(response.status() >= 400) failed.push(response.url());

		if(/\.wasm(?:\?|$)/.test(response.url())) runtimes.push(response.url());
	});

	for(const width of [1440, 768, 390])
	{
		await page.setViewportSize({width, height: 900});

		for(const name of guidePages)
		{
			await page.goto(`/${name}`);
			await expect(page.locator('h1')).toHaveCount(1);
			expect(await page.evaluate(() => document.documentElement.scrollWidth), name).toBeLessThanOrEqual(width);
		}
	}

	expect(errors).toEqual([]);
	expect(failed).toEqual([]);
	expect(runtimes).toEqual([]);
});

test('mobile menus expose navigation and per-page anchors', async({page}) => {
	await page.setViewportSize({width: 390, height: 844});
	await page.goto('/');
	await expect(page.locator('.site-navigation')).toBeVisible();

	await page.getByRole('navigation', {name: 'Documentation', exact: true})
		.getByRole('link', {name: 'Author in Tiled', exact: true}).click();

	await expect(page).toHaveURL(/tiled.html$/);
	await page.locator('.page-navigation > summary').click();

	await page.getByRole('navigation', {name: 'On this page', exact: true})
		.getByRole('link', {name: 'Place entities', exact: true}).click();

	await expect(page).toHaveURL(/#place-entities$/);
});

test('desktop navigation headings align across documentation columns', async({page}) => {
	await page.setViewportSize({width: 1440, height: 900});
	await page.goto('/');

	const tops = await Promise.all([
		page.getByText('Get started', {exact: true}).evaluate(element => element.getBoundingClientRect().top)
		, page.locator('.section-label').evaluate(element => element.getBoundingClientRect().top)
		, page.getByText('On this page', {exact: true}).evaluate(element => element.getBoundingClientRect().top)
	]);

	expect(Math.max(...tops) - Math.min(...tops)).toBeLessThanOrEqual(1);
});

test('PHP class names are highlighted as complete identifiers', async({page}) => {
	await page.goto('/api-phonics-ecs-scheduler.html');

	await expect(page.locator('#declaration + .sourceCode .hljs-class > .hljs-title')).toHaveText('Scheduler');
	await expect(page.locator('#property-registry + p + .sourceCode code')).toContainText('Phonics\\Ecs\\Registry');
	await expect(page.locator('#property-registry + p + .sourceCode .cn')).toHaveCount(0);
});

test('documentation follows the system theme and saves an explicit preference', async({page}) => {
	await page.emulateMedia({colorScheme: 'dark'});
	await page.goto('/');

	const root = page.locator('html');
	const toggle = page.locator('[data-theme-toggle]');
	const assistantLauncher = page.locator('[data-smgen-assistant-launcher]');

	await expect(root).not.toHaveAttribute('data-theme', 'light');
	await expect(root).toHaveClass(/(?:^|\s)dark(?:\s|$)/);
	await expect(toggle).toBeVisible();
	await expect(toggle).toHaveAttribute('aria-label', 'Use light theme');
	await expect(toggle.locator('[data-theme-icon="light"]')).toBeVisible();
	await expect(toggle.locator('[data-theme-icon="dark"]')).toBeHidden();
	await expect(assistantLauncher).toHaveAttribute('data-theme', 'dark');
	await assistantLauncher.evaluate(button => button.click());
	await expect(page.frameLocator('.smgen-assistant-frame').locator('html')).toHaveAttribute('data-theme', 'dark');

	expect(await page.locator('body').evaluate(element => getComputedStyle(element).backgroundColor))
		.toBe('rgb(16, 23, 34)');

	await toggle.evaluate(button => button.click());
	await expect(root).toHaveAttribute('data-theme', 'light');
	await expect(root).toHaveClass(/(?:^|\s)light(?:\s|$)/);
	await expect(toggle).toHaveAttribute('aria-label', 'Use dark theme');
	await expect(toggle.locator('[data-theme-icon="dark"]')).toBeVisible();
	await expect(toggle.locator('[data-theme-icon="light"]')).toBeHidden();
	await expect(assistantLauncher).toHaveAttribute('data-theme', 'light');
	await expect(page.frameLocator('.smgen-assistant-frame').locator('html')).toHaveAttribute('data-theme', 'light');

	expect(await page.locator('body').evaluate(element => getComputedStyle(element).backgroundColor))
		.toBe('rgb(255, 255, 255)');

	await page.reload();
	await expect(root).toHaveAttribute('data-theme', 'light');
	await expect(root).toHaveClass(/(?:^|\s)light(?:\s|$)/);
	await expect(toggle).toHaveAttribute('aria-label', 'Use dark theme');
	await expect(assistantLauncher).toHaveAttribute('data-theme', 'light');

	const toggleStyle = await toggle.evaluate(element => {
		const style = getComputedStyle(element);

		return {
			backgroundColor: style.backgroundColor
			, borderRadius: style.borderRadius
			, height: style.height
			, padding: style.padding
			, width: style.width
		};
	});

	await page.goto('/games/php-noid/');
	const gameToggle = page.locator('[data-theme-toggle]');

	await expect(gameToggle).toBeVisible();
	await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
	await expect(gameToggle).toHaveAttribute('aria-label', 'Use dark theme');

	await expect.poll(() => gameToggle.evaluate(element => {
		const style = getComputedStyle(element);

		return {
			backgroundColor: style.backgroundColor
			, borderRadius: style.borderRadius
			, height: style.height
			, padding: style.padding
			, width: style.width
		};
	})).toEqual(toggleStyle);
});

test('header search overlays the page and resolves live results', async({page}) => {
	await page.setViewportSize({width: 1440, height: 900});
	await page.goto('/');

	const searchToggle = page.locator('[data-search-toggle]');
	const themeToggle = page.locator('[data-theme-toggle]');
	const panel = page.locator('[data-search-panel]');
	const input = page.locator('#search-query');
	const layout = page.locator('.layout');
	const layoutTop = await layout.evaluate(element => element.getBoundingClientRect().top);
	const searchBox = await searchToggle.boundingBox();
	const themeBox = await themeToggle.boundingBox();

	expect(searchBox.x).toBeLessThan(themeBox.x);
	await expect(panel).toBeHidden();
	await searchToggle.click();
	await expect(searchToggle).toHaveAttribute('aria-expanded', 'true');
	await expect(panel).toBeVisible();
	await expect(input).toBeFocused();
	expect(await layout.evaluate(element => element.getBoundingClientRect().top)).toBe(layoutTop);

	await input.fill('CommandValidator');
	const commandResult = page.getByRole('link', {name: 'Phonics\\Network\\CommandValidator', exact: true});
	await expect(commandResult).toBeVisible();
	await commandResult.click();
	await expect(page).toHaveURL(/api-phonics-network-command-validator\.html$/);

	await page.locator('[data-search-toggle]').click();
	await page.locator('#search-query').fill('Doom-Phonics');
	const gameResult = page.locator('#search-results a').filter({hasText: 'Doom-Phonics'}).first();
	await expect(gameResult).toHaveAttribute('href', '/games/doom-phonics/');
	await page.keyboard.press('Escape');
	await expect(page.locator('[data-search-panel]')).toBeHidden();
});

test('input reference uses accessible Xbox and PlayStation controller art', async({page}) => {
	await page.goto('/input.html');

	await expect(page.locator('img.controller-glyph')).toHaveCount(32);
	await expect(page.getByAltText('Xbox A button', {exact: true})).toBeVisible();
	await expect(page.getByAltText('PlayStation Cross button', {exact: true})).toBeVisible();

	for(const image of await page.locator('img.controller-glyph').all())
	{
		expect(await image.evaluate(element => element.naturalWidth)).toBeGreaterThan(0);
	}
});

test('documentation menu expansion survives navigation and reloads', async({page}) => {
	await page.goto('/');
	const api = page.locator('details[data-id-path="api"]');
	await expect(api).not.toHaveAttribute('open', '');
	await api.locator(':scope > summary').click();
	await expect(api).toHaveAttribute('open', '');

	await page.getByRole('link', {name: 'Author in Tiled', exact: true}).click();
	await expect(page.locator('details[data-id-path="api"]')).toHaveAttribute('open', '');

	await page.locator('details[data-id-path="api"] > summary').click();
	await expect(page.locator('details[data-id-path="api"]')).not.toHaveAttribute('open', '');
	await page.reload();
	await expect(page.locator('details[data-id-path="api"]')).not.toHaveAttribute('open', '');
});

test('documentation menu gives each nested group a modest indent', async({page}) => {
	await page.setViewportSize({width: 1440, height: 900});
	await page.goto('/');

	const paths = ['reference', 'api', 'api-network', 'api-network-component'];

	for(const path of paths)
	await page.locator(`details[data-id-path="${path}"]`).evaluate(details => details.open = true);

	const offsets = await Promise.all(paths.map(async path => {
		const box = await page.locator(`details[data-id-path="${path}"] > summary`).boundingBox();

		return box.x;
	}));

	for(let index = 1; index < offsets.length; index++)
	{
		const indent = offsets[index] - offsets[index - 1];

		expect(indent).toBeGreaterThan(3);
		expect(indent).toBeLessThan(8);
	}
});

test('expandable menu labels align with page links at the same level', async({page}) => {
	await page.setViewportSize({width: 1440, height: 900});
	await page.goto('/');

	for(const path of ['reference', 'api'])
	await page.locator(`details[data-id-path="${path}"]`).evaluate(details => details.open = true);

	const pairs = [
		['details[data-id-path="reference"] > ul > li > a', 'details[data-id-path="api"] > summary']
		, ['details[data-id-path="api"] > ul > li > a', 'details[data-id-path="api-core"] > summary']
	];

	for(const [pageLink, groupLabel] of pairs)
	{
		const [linkBox, summaryBox] = await Promise.all([
			page.locator(pageLink).first().boundingBox()
			, page.locator(groupLabel).boundingBox()
		]);

		expect(summaryBox.x).toBeCloseTo(linkBox.x, 0);
	}
});

test('long sidebar links wrap inside their highlight', async({page}) => {
	await page.setViewportSize({width: 1440, height: 900});
	await page.goto('/api-phonics-ecs-object-spawner.html');

	const activeLink = page.locator('.sidebar .active-link');
	await expect(activeLink).toHaveText('Phonics\\Ecs\\ObjectSpawner');
	await expect(activeLink).toBeVisible();

	const bounds = await activeLink.evaluate(link => ({
		clientWidth: link.clientWidth
		, scrollWidth: link.scrollWidth
	}));

	expect(bounds.scrollWidth).toBeLessThanOrEqual(bounds.clientWidth);
});

test('API class header groups breadcrumbs, summary, and source without repetition', async({page}) => {
	await page.goto('/api-phonics-ecs-scheduler.html');

	const summary = 'Execute named ECS systems in dependency order and flush deferred entity changes.';
	const header = page.locator('.api-page-header');
	await expect(header.getByRole('navigation', {name: 'Breadcrumb'})).toHaveText('Programming API/ECS');
	await expect(header.getByRole('heading', {level: 1})).toHaveText('Phonics\\Ecs\\Scheduler');
	await expect(header.locator('.subtitle')).toHaveText(summary);

	const source = header.getByRole('link', {name: 'View source'});
	await expect(source).toHaveAttribute('href', /src\/Ecs\/Scheduler\.php#L\d+$/);
	await expect(source).toHaveAttribute('target', '_blank');
	await expect(source).toHaveAttribute('rel', 'noopener noreferrer');

	await expect(page.locator('article')).not.toContainText(summary);
	await expect(page.locator('article > h2').first()).toHaveText('Declaration');
});

test('navigation prefetches on hover and starts on primary mousedown', async({page}) => {
	await page.goto('/');
	const tiled = page.getByRole('link', {name: 'Author in Tiled', exact: true});

	await tiled.hover();
	await expect(page.locator('link[rel="prefetch"][href$="/tiled.html"]')).toHaveCount(1);
	await page.locator('h1').hover();
	await tiled.hover();
	await page.waitForTimeout(100);
	await expect(page.locator('link[rel="prefetch"][href$="/tiled.html"]')).toHaveCount(1);

	const github = page.locator('a[href="https://github.com/seanmorris/php-wasm-engine"]');
	await expect(github).toHaveAttribute('target', '_blank');
	await expect(github).toHaveAttribute('rel', 'noopener noreferrer');
	await github.hover();
	await page.waitForTimeout(100);
	await expect(page.locator('link[rel="prefetch"]')).toHaveCount(1);

	await tiled.dispatchEvent('mousedown', {button: 0});
	await expect(page).toHaveURL(/tiled\.html$/);
});

test('the programming reference filters symbols without a round trip', async({page}) => {
	await page.goto('/api.html');
	const status = page.locator('[data-api-status]');
	await expect(status).toHaveText(/\d+ of \d+ API entries/);
	const total = (await status.textContent()).match(/of (\d+) API entries/)?.[1];
	await page.locator('[data-api-filter]').fill('Registry query');
	await expect(page.locator('[data-api-entry]:visible')).toHaveCount(1);
	await expect(page.locator('[data-api-entry]:visible')).toContainText('Phonics\\Ecs\\Registry');
	await expect(status).toHaveText(`1 of ${total} API entries`);
});

test('production exposes the unified documentation and generic preview', async({page}) => {
	const failed = [];
	const retired = /\/(?:sane-phonics|php-noid|php-tris|php-slalom|php-buster|doom-phonics)\/$/;

	page.on('response', response => {
		if(response.status() >= 400 && !retired.test(response.url()))
			failed.push(response.url());
	});

	for(const route of ['/sane-phonics/', '/php-noid/', '/php-tris/', '/php-slalom/', '/php-buster/', '/doom-phonics/'])
	{
		const response = await page.goto(route);
		expect(response.status()).toBe(404);
	}

	const preview = await page.goto('/preview/');
	expect(preview.status()).toBe(200);

	await page.goto('/');
	await expect(page).toHaveURL('http://127.0.0.1:9141/');
	await expect(page.locator('h1')).toHaveText('Phonics');

	expect(failed).toEqual([]);
});

test('game selector uses gameplay captures and mounts a game only on activation', async({page}) => {
	const wasm = [];

	page.on('request', request => {
		if(/\.wasm(?:\?|$)/.test(request.url())) wasm.push(request.url());
	});

	await page.goto('/games/');
	await expect(page).toHaveURL(/\/games\/$/);
	await expect(page.locator('h1')).toHaveText('Games');
	await expect(page.locator('.game-selector li')).toHaveCount(6);
	await expect(page.locator('.game-selector img')).toHaveCount(6);
	await expect(page.locator('.game-selector img').first()).toHaveCSS('color', 'rgb(244, 247, 251)');
	expect(wasm).toEqual([]);

	await page.getByRole('link', {name: /PHPTris/}).click();
	await expect(page).toHaveURL(/games\/php-tris\/$/);
	await expect(page.locator('[data-game-runtime]')).toBeHidden();
	expect(wasm).toEqual([]);

	await page.getByRole('button', {name: 'Load and play PHPTris'}).click();
	const game = page.locator('[data-game-runtime]');
	await expect(game.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await expect(game).toHaveAttribute('data-game-embed', 'true');
	await expect(page.locator('.sidebar')).toBeVisible();
	await expect(page.locator('iframe')).toHaveCount(0);
	expect(wasm.length).toBeGreaterThan(0);
});

test('PHP Buster starts, moves, fires, pauses, and cleans up in its documentation page', async({page}) => {
	test.setTimeout(120000);
	const errors = [];
	page.on('pageerror', error => errors.push(error.message));
	await page.goto('/games/php-buster/');
	await expect(page.locator('h1')).toHaveText('PHP Buster');
	await expect(page.locator('[data-game-runtime]')).toBeHidden();
	await page.getByRole('button', {name: 'Load and play PHP Buster'}).click();
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await page.locator('[data-action="new-game"]').click();

	const state = () => page.locator('canvas').evaluate(canvas => JSON.parse(canvas.dataset.phpBuster));

	await expect.poll(async() => (await state()).page).toBe('play');
	const startX = (await state()).player[0];
	await page.locator('canvas').focus();
	await page.keyboard.down('ArrowLeft');
	await page.keyboard.down('Space');
	await page.waitForTimeout(350);
	await page.keyboard.up('Space');
	await page.keyboard.up('ArrowLeft');
	await expect.poll(async() => (await state()).player[0]).toBeLessThan(startX);
	await expect.poll(async() => (await state()).shots).toBeGreaterThan(0);
	await expect.poll(async() => (await state()).actors).toBeGreaterThan(1);
	await page.keyboard.press('Escape');
	await expect.poll(async() => (await state()).page).toBe('pause');
	await page.locator('#stop').click();
	await expect(page.locator('#status')).toHaveText('Stopped');
	await expect(page.locator('.php-buster-ui')).toHaveCount(0);
	expect(errors).toEqual([]);
});

test('Matrix OAuth returns through a popup without navigating the running host', async({page}) => {
	await page.goto('/preview/');
	const original = page.url();

	await page.evaluate(async() => {
		const {authorizeWithPopup} = await import('/engine/browser/network/OAuthPopup.mjs');
		const button = document.createElement('button');
		button.textContent = 'Test Matrix popup';

		button.addEventListener('click', () => {
			window.oauthPopupResult = authorizeWithPopup({
				begin: returnUrl => {
					const callback = new URL(returnUrl);
					callback.hash = '#state=test-state&code=test-code';

					return callback.href;
				}
				, complete: callbackUrl => {
					window.oauthCallbackUrl = callbackUrl;

					return 'connected';
				}
			});
		});

		document.body.append(button);
	});

	const opened = page.waitForEvent('popup');
	await page.getByRole('button', {name: 'Test Matrix popup'}).click();
	const popup = await opened;

	await expect.poll(() => page.evaluate(() => window.oauthCallbackUrl ?? null)).toContain('code=test-code');
	await expect.poll(() => popup.isClosed()).toBe(true);
	expect(page.url()).toBe(original);
	expect(await page.evaluate(() => window.oauthPopupResult)).toBe('connected');
});

test('all games start and clean up inside the shared site design', async({page}) => {
	test.setTimeout(240000);

	const errors = [];

	await page.addInitScript(() => {
		window.doomAudioStarts = 0;
		window.doomAudioPeak = 0;
		window.doomAudioStates = [];
		const start = AudioBufferSourceNode.prototype.start;

		AudioBufferSourceNode.prototype.start = function(...args) {
			window.doomAudioStarts++;
			window.doomAudioStates.push(this.context.state);
			const samples = this.buffer?.getChannelData(0);

			if(samples)
			for(let index = 0; index < samples.length; index += Math.max(1, Math.ceil(samples.length / 4096)))
			window.doomAudioPeak = Math.max(window.doomAudioPeak, Math.abs(samples[index]));

			return start.apply(this, args);
		};
	});

	page.on('pageerror', error => errors.push(error.message));

	await openGame(page, 'php-noid', 'PHP-Noid');
	await expect(page.locator('.masthead .brand')).toBeVisible();
	await expect(page.locator('.masthead .brand-label')).toHaveText('docs');
	await expect(page.locator('.masthead [data-theme-toggle]')).toBeVisible();
	await expect(page.locator('.masthead').getByRole('link', {name: 'Games'})).toHaveAttribute('href', '/games/');
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await page.locator('[data-action="start"]').click();
	await expect(page.locator('.php-noid-title')).toBeHidden();
	await page.locator('#stop').click();
	await expect(page.locator('#status')).toHaveText('Stopped');

	await openGame(page, 'php-tris', 'PHPTris');
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await page.locator('[data-action="start"]').click();
	await expect(page.locator('[data-action="pause"]')).toBeVisible();
	await page.locator('#stop').click();
	await expect(page.locator('.php-tris-ui')).toHaveCount(0);

	await openGame(page, 'sane-phonics', 'Sane-Phonics');
	await expect(page.locator('.masthead .brand')).toBeVisible();
	await page.waitForFunction(() => Boolean(document.querySelector('canvas').dataset.intro));
	await page.locator('#stop').click();
	await expect(page.locator('#status')).toContainText('Stopped');

	await page.route('**/DOOM1.WAD', async route => {
		await new Promise(resolve => setTimeout(resolve, 500));
		await route.continue();
	}, {times: 1});

	await openGame(page, 'doom-phonics', 'Doom-Phonics');
	await expect(page.locator('.phonics-preloader')).toBeVisible();
	await expect(page.locator('.phonics-preloader')).toHaveAttribute('data-phase', 'assets', {timeout: 30000});
	await expect(page.locator('.phonics-preloader')).toHaveAttribute('aria-label', /Loading episode resource/);
	await expect(page.locator('.phonics-preloader > progress')).toHaveCount(1);
	const preloadStatus = page.locator('.phonics-preloader > .phonics-preloader-status');

	await expect(preloadStatus).toContainText('Loading episode resource');
	await expect(page.locator('.phonics-preloader > :not(progress, .phonics-preloader-status)')).toHaveCount(0);
	await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});
	await expect(page.locator('.phonics-preloader')).toBeHidden();
	await expect.poll(() => page.evaluate(() => window.previewLoadProgress?.fraction)).toBe(1);
	await expect.poll(() => page.evaluate(() => Boolean(window.previewSession?.doomState))).toBe(true);

	const doomState = () => page.evaluate(() => window.previewSession?.doomState);

	const matrixConfiguration = () => page.evaluate(
		() => JSON.parse(localStorage.getItem('phonics.matrix.configuration'))
	);

	const canvasBuffer = () => page.locator('canvas').evaluate(canvas => [canvas.width,canvas.height]);

	const physicalCanvas = () => page.locator('canvas').evaluate(canvas => {
		const box = canvas.getBoundingClientRect();

		return canvas.width === Math.round(box.width * devicePixelRatio)
			&& canvas.height === Math.round(box.height * devicePixelRatio);
	});

	const classicSceneMatches = async() => {
		const state = await doomState();

		return state.render?.scene?.[0] === Math.round(state.render.framebuffer[0] * .5)
			&& state.render?.scene?.[1] === Math.round(state.render.framebuffer[1] * .5);
	};

	const press = async key => {
		await page.keyboard.press(key, {delay: 80});
		await page.waitForTimeout(80);
	};

	await expect.poll(async() => (await doomState()).menu?.screen).toBe('title');
	await expect.poll(async() => (await doomState()).menu?.font).toBe('DBIGFONT');
	await expect.poll(async() => (await doomState()).renderResolution).toBe('native');
	await expect.poll(async() => (await doomState()).pitchMode).toBe('perspective');
	await expect.poll(async() => (await doomState()).reticle).toBe(true);
	await expect.poll(physicalCanvas).toBe(true);

	const initialBuffer = await canvasBuffer();

	await expect.poll(async() => (await doomState()).render?.framebuffer).toEqual(initialBuffer);
	await expect.poll(async() => (await doomState()).render?.scene?.slice(0, 2)).toEqual(initialBuffer);
	await page.locator('canvas').focus();

	const soundsBeforeMenu = await page.evaluate(() => window.doomAudioStarts);
	await press('ArrowDown');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('online-play');

	await press('Enter');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('online');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('enter-lobby');
	await press('Enter');
	await expect(page.locator('.phonics-lobby')).toHaveAttribute('open', '');
	await expect(page.locator('.phonics-lobby-status')).toHaveText('Sign-in required');
	await expect(page.getByRole('button', {name: 'Sign in with Matrix'})).toBeVisible();
	await expect(page.locator('.phonics-lobby input[type="password"]')).toHaveCount(0);
	await expect(page.getByLabel('Homeserver')).toHaveCount(0);
	await expect(page.getByLabel('Lobby channel')).toHaveCount(0);
	await page.getByRole('button', {name: 'Game settings'}).click();
	await expect(page.locator('.phonics-lobby')).toHaveAttribute('open', '');
	await expect(page.locator('.phonics-launcher')).toHaveAttribute('open', '');
	await expect(page.locator('input[name="resource:wad-stack"]')).toHaveAttribute('multiple', '');
	await expect(page.locator('input[name="resource:base-wads"]')).toHaveCount(0);
	await expect(page.locator('input[name="resource:addon-wads"]')).toHaveCount(0);
	await expect(page.locator('.phonics-launcher-list [data-position="0"]')).toHaveCount(1, {timeout: 30000});
	await page.getByRole('button', {name: 'Close game settings'}).click();
	await page.getByRole('button', {name: 'Close lobby'}).click();
	await page.locator('canvas').focus();

	await press('ArrowDown');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('networking');
	await press('Enter');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('networking');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('homeserver');

	await expect.poll(async() => (await doomState()).menu?.networking).toMatchObject({
		homeserver: 'https://matrix.org'
		, lobbyChannel: '#doom-phonics:matrix.org'
		, dirty: false
	});

	await press('ArrowDown');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('lobby-channel');
	await press('Enter');
	await expect.poll(async() => (await doomState()).menu?.editing).toBe(true);
	await page.keyboard.press('Control+A');
	await page.keyboard.type('#test-lobby:matrix.org');
	await press('Enter');

	await expect.poll(async() => (await doomState()).menu?.networking).toMatchObject({
		lobbyChannel: '#test-lobby:matrix.org'
		, dirty: true
	});

	await press('ArrowDown');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('apply-networking');
	await press('Enter');

	await expect.poll(async() => (await doomState()).menu?.networking).toMatchObject({
		lobbyChannel: '#test-lobby:matrix.org'
		, dirty: false
		, message: 'Networking settings saved'
		, error: false
	});

	await expect.poll(matrixConfiguration).toMatchObject({room: '#test-lobby:matrix.org'});

	await press('Escape');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('online');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('networking');
	await press('Escape');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('title');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('online-play');

	await press('ArrowDown');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('wad-stack');
	await press('Enter');
	await expect(page.locator('.phonics-launcher')).toHaveAttribute('open', '');
	await expect(page.locator('.phonics-lobby')).not.toHaveAttribute('open', '');
	await expect(page.getByRole('button', {name: 'Start locally'})).toBeVisible();
	await expect(page.locator('select[name="match:mode"]')).toHaveValue('singleplayer');
	await expect(page.locator('input[name="resource:wad-stack"]')).toHaveAttribute('multiple', '');
	await expect(page.locator('select[name="match:map"] option')).toHaveCount(9, {timeout: 30000});
	await expect(page.locator('select[name="match:map"]')).toHaveValue('E1M1');
	await page.getByRole('button', {name: 'Close game settings'}).click();
	await page.locator('canvas').focus();

	await press('ArrowDown');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('settings');

	await expect.poll(() => page.evaluate(() => window.doomAudioStarts)).toBeGreaterThan(soundsBeforeMenu);
	await expect.poll(() => page.evaluate(() => window.doomAudioPeak)).toBeGreaterThan(.1);
	await expect.poll(() => page.evaluate(() => window.doomAudioStates.at(-1))).toBe('running');

	await press('Enter');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('settings');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('input');
	await press('ArrowDown');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('sound');
	await press('ArrowDown');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('video');
	await press('Enter');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('video');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('fullscreen');
	await press('ArrowDown');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('render-resolution');
	await press('ArrowRight');
	await expect.poll(async() => (await doomState()).renderResolution).toBe('classic');
	await expect.poll(classicSceneMatches).toBe(true);

	await press('ArrowDown');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('pitch-mode');
	await press('ArrowRight');
	await expect.poll(async() => (await doomState()).pitchMode).toBe('classic');
	await press('ArrowLeft');
	await expect.poll(async() => (await doomState()).pitchMode).toBe('perspective');
	await press('ArrowDown');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('reticle');
	await press('Enter');
	await expect.poll(async() => (await doomState()).reticle).toBe(false);
	await expect.poll(() => page.evaluate(
		() => JSON.parse(localStorage.getItem('doom-phonics.options.v1')).reticle
	)).toBe(false);
	await press('Enter');
	await expect.poll(async() => (await doomState()).reticle).toBe(true);
	await press('ArrowUp');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('pitch-mode');
	await press('ArrowUp');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('render-resolution');
	await press('ArrowLeft');
	await expect.poll(async() => (await doomState()).renderResolution).toBe('native');
	await expect.poll(async() => (await doomState()).render?.scene?.slice(0, 2)).toEqual(await canvasBuffer());

	await press('Escape');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('settings');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('video');
	await press('Escape');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('title');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('settings');
	await press('ArrowDown');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('read-this');
	await press('ArrowDown');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('php-info');

	await press('Enter');
	await expect.poll(async() => (await doomState()).menu?.phpInfo?.ready).toBe(true);
	await expect.poll(async() => (await doomState()).menu?.phpInfo?.markupBytes).toBeGreaterThan(10000);

	const infoBack = page.getByRole('button', {name: 'Back to title screen'});
	await expect(infoBack).toBeVisible();
	await expect(infoBack).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
	await expect(infoBack).toHaveCSS('border-top-width', '0px');
	await infoBack.click();
	await expect.poll(async() => (await doomState()).menu?.phpInfo?.open).toBe(false);
	await expect.poll(async() => (await doomState()).wipe?.active).toBe(true);
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('title');
	await expect.poll(async() => (await doomState()).wipe?.active, {timeout: 5000}).toBe(false);

	await press('ArrowUp');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('read-this');
	await press('ArrowUp');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('settings');
	await press('ArrowUp');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('wad-stack');
	await press('ArrowUp');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('online-play');
	await press('ArrowUp');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('new-game');

	await press('Enter');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('skill');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('skill-2');
	await press('Enter');
	await expect.poll(async() => (await doomState()).menu?.open, {timeout: 10000}).toBe(false);
	await expect.poll(async() => (await doomState()).wipe?.active).toBe(true);
	await expect.poll(async() => (await doomState()).wipe?.active, {timeout: 5000}).toBe(false);
	await expect.poll(async() => (await doomState()).render?.skyOccluderVertices).toBe(210);

	await page.locator('canvas').focus();

	const spawn = (await doomState()).position.slice(0, 2);
	await page.keyboard.down('KeyW');
	await page.waitForTimeout(650);
	await page.keyboard.up('KeyW');

	const advanced = (await doomState()).position.slice(0, 2);

	await page.waitForTimeout(500);

	const retained = (await doomState()).position.slice(0, 2);

	const distance = position => Math.hypot(position[0] - spawn[0], position[1] - spawn[1]);

	expect(distance(advanced)).toBeGreaterThan(100);
	expect(distance(retained)).toBeGreaterThan(100);

	await page.keyboard.type('idffa');
	await expect.poll(async() => (await doomState()).armor).toBe(200);
	await expect.poll(async() => (await doomState()).weapons).toContain('BFG9000');

	await expect.poll(async() => (await doomState()).weapons).toEqual(expect.arrayContaining([
		'Minigun','GrenadeLauncher','Railgun','BFG10K'
	]));

	await expect.poll(async() => (await doomState()).message).toBe('AMMO (NO KEYS) ADDED');
	await press('Digit4');
	await expect.poll(async() => (await doomState()).weapon, {timeout: 10000}).toBe('Chaingun');
	await page.locator('canvas').hover();
	await page.mouse.wheel(0, 100);
	await expect.poll(async() => (await doomState()).weapon, {timeout: 10000}).toBe('Minigun');

	await page.keyboard.type('iddqd');
	await expect.poll(async() => (await doomState()).godMode).toBe(true);
	await page.keyboard.type('iddqd');
	await expect.poll(async() => (await doomState()).godMode).toBe(false);

	await page.keyboard.type('idclip');
	await expect.poll(async() => (await doomState()).noClip).toBe(true);
	await page.keyboard.type('idspispopd');
	await expect.poll(async() => (await doomState()).noClip).toBe(false);
	await page.keyboard.type('idoopsallgibs', {delay: 140});
	await expect.poll(async() => (await doomState()).menu?.open).toBe(false);
	await expect.poll(async() => (await doomState()).message).toBe('OOPS! ALL GIBS');
	await expect.poll(async() => (await doomState()).weapons).toEqual(['Railgun']);
	await expect.poll(async() => (await doomState()).oopsAllGibs).toBe(true);

	await press('Escape');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('pause');
	await press('ArrowDown');
	await press('ArrowDown');
	await press('ArrowDown');
	await press('Enter');
	await expect.poll(async() => (await doomState()).menu?.open).toBe(false);
	await expect.poll(async() => (await doomState()).weapons).toEqual(['Railgun']);
	await expect.poll(async() => (await doomState()).oopsAllGibs).toBe(true);
	await page.keyboard.type('idoopsallgibs');
	await expect.poll(async() => (await doomState()).message).toBe('OOPS! ALL GIBS OFF');
	await expect.poll(async() => (await doomState()).oopsAllGibs).toBe(false);
	await expect.poll(async() => (await doomState()).weapons).toEqual(expect.arrayContaining([
		'Minigun','GrenadeLauncher','Railgun','BFG10K'
	]));

	await page.keyboard.type('idclev12');
	await expect.poll(async() => (await doomState()).map).toBe('E1M2');
	await expect.poll(async() => (await doomState()).wipe?.active).toBe(true);
	await page.getByRole('button', {name: 'Fullscreen', exact: true}).click();
	await expect.poll(() => page.evaluate(() => document.fullscreenElement?.id)).toBe('game-frame');
	await expect.poll(physicalCanvas).toBe(true);
	await expect.poll(async() => (await doomState()).render?.framebuffer).toEqual(await canvasBuffer());
	await expect.poll(async() => (await doomState()).wipe?.active, {timeout: 5000}).toBe(false);
	await press('KeyP');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('pause');
	await expect.poll(() => page.evaluate(() => document.fullscreenElement?.id)).toBe('game-frame');
	await press('KeyP');
	await expect.poll(async() => (await doomState()).menu?.open).toBe(false);
	await page.getByRole('button', {name: 'Exit fullscreen', exact: true}).click();
	await expect.poll(() => page.evaluate(() => document.fullscreenElement)).toBeNull();

	await page.locator('canvas').hover();
	const soundsBeforeShot = await page.evaluate(() => window.doomAudioStarts);
	await page.mouse.down();
	await expect.poll(async() => (await doomState()).weaponLayers, {timeout: 10000}).toBeGreaterThan(0);
	await expect.poll(async() => (await doomState()).viewLight, {timeout: 10000}).toBeGreaterThan(0);
	await page.mouse.up();
	await expect.poll(() => page.evaluate(() => window.doomAudioStarts)).toBeGreaterThan(soundsBeforeShot);
	await expect.poll(async() => (await doomState()).weaponLayers).toBe(0);
	await expect.poll(async() => (await doomState()).viewLight).toBe(0);

	await press('Digit6');
	await expect.poll(async() => (await doomState()).weapon, {timeout: 10000}).toBe('Railgun');
	const soundsBeforeRail = await page.evaluate(() => window.doomAudioStarts);
	await page.mouse.down();
	await expect.poll(async() => (await doomState()).render?.beamVertices, {timeout: 10000}).toBe(6);
	await page.mouse.up();
	await expect.poll(() => page.evaluate(() => window.doomAudioStarts)).toBeGreaterThan(soundsBeforeRail);
	await expect.poll(async() => (await doomState()).render?.beamVertices, {timeout: 5000}).toBe(0);

	await press('Escape');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('pause');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('resume');
	await press('Enter');
	await expect.poll(async() => (await doomState()).menu?.open).toBe(false);
	await page.getByRole('button', {name: 'Fullscreen', exact: true}).click();
	await expect.poll(() => page.evaluate(() => document.fullscreenElement?.id)).toBe('game-frame');

	await expect.poll(() => page.evaluate(() => {
		const frame = document.querySelector('#game-frame').getBoundingClientRect();
		const canvas = document.querySelector('canvas').getBoundingClientRect();
		const width = Math.min(innerWidth, innerHeight * 16 / 9);

		return frame.width === innerWidth
			&& frame.height === innerHeight
			&& Math.abs(canvas.width - width) < 1
			&& Math.abs(canvas.height - width * 9 / 16) < 1;
	})).toBe(true);

	const fullscreen = await page.evaluate(() => {
		const frame = document.querySelector('#game-frame').getBoundingClientRect();
		const canvas = document.querySelector('canvas').getBoundingClientRect();

		return {
			frame: [frame.width, frame.height]
			, canvas: [canvas.width, canvas.height]
			, buffer: [document.querySelector('canvas').width, document.querySelector('canvas').height]
			, viewport: [innerWidth, innerHeight]
			, dpr: devicePixelRatio
		};
	});

	expect(fullscreen.frame).toEqual(fullscreen.viewport);

	expect(fullscreen.canvas[0]).toBeCloseTo(Math.min(fullscreen.viewport[0], fullscreen.viewport[1] * 16 / 9));

	expect(fullscreen.canvas[1]).toBeCloseTo(fullscreen.canvas[0] * 9 / 16);
	expect(fullscreen.buffer).toEqual(fullscreen.canvas.map(size => Math.round(size * fullscreen.dpr)));
	await expect.poll(async() => (await doomState()).render?.framebuffer).toEqual(fullscreen.buffer);
	await expect.poll(async() => (await doomState()).render?.scene?.slice(0, 2)).toEqual(fullscreen.buffer);

	await page.getByRole('button', {name: 'Exit fullscreen', exact: true}).click();
	await expect.poll(() => page.evaluate(() => document.fullscreenElement)).toBeNull();

	await press('Escape');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('pause');
	await press('ArrowUp');
	await expect.poll(async() => (await doomState()).menu?.selected).toBe('title');
	await press('Enter');
	await expect.poll(async() => (await doomState()).menu?.screen).toBe('title');
	await expect.poll(async() => (await doomState()).wipe?.active).toBe(true);
	await expect.poll(async() => (await doomState()).wipe?.active, {timeout: 5000}).toBe(false);
	await page.locator('#stop').click();
	await expect(page.locator('#status')).toHaveText('Stopped');

	expect(errors).toEqual([]);
});

test('Doom native framebuffer follows a high-density display through fullscreen', async({browser}) => {
	const context = await browser.newContext({viewport: {width: 800, height: 600}, deviceScaleFactor: 2});
	const page = await context.newPage();
	const errors = [];

	page.on('pageerror', error => errors.push(error.message));

	try
	{
		await openGame(page, 'doom-phonics', 'Doom-Phonics');
		await expect(page.locator('#status')).toHaveText('Ready', {timeout: 60000});

		const canvas = page.locator('canvas');

		const state = () => page.evaluate(() => window.previewSession?.doomState);

		const physical = () => canvas.evaluate(element => {
			const box = element.getBoundingClientRect();

			return [
				element.width
				, element.height
				, Math.round(box.width * devicePixelRatio)
				, Math.round(box.height * devicePixelRatio)
			];
		});

		await expect.poll(async() => {
			const [width,height,expectedWidth,expectedHeight] = await physical();

			return width === expectedWidth && height === expectedHeight;
		}).toBe(true);

		let [width,height] = await physical();

		await expect.poll(async() => (await state()).render?.framebuffer).toEqual([width,height]);
		await expect.poll(async() => (await state()).render?.scene?.slice(0, 2)).toEqual([width,height]);
		await page.getByRole('button', {name: 'Fullscreen', exact: true}).click();
		await expect.poll(() => page.evaluate(() => document.fullscreenElement?.id)).toBe('game-frame');

		await expect.poll(async() => {
			const [bufferWidth,bufferHeight,expectedWidth,expectedHeight] = await physical();

			return bufferWidth === expectedWidth && bufferHeight === expectedHeight;
		}).toBe(true);

		[width,height] = await physical();

		await expect.poll(async() => (await state()).render?.framebuffer).toEqual([width,height]);
		await expect.poll(async() => (await state()).render?.scene?.slice(0, 2)).toEqual([width,height]);
		await page.getByRole('button', {name: 'Exit fullscreen', exact: true}).click();
		await expect.poll(() => page.evaluate(() => document.fullscreenElement)).toBeNull();
		await page.locator('#stop').click();
		await expect(page.locator('#status')).toHaveText('Stopped');

		expect(errors).toEqual([]);
	}
	finally
	{
		await context.close();
	}
});
