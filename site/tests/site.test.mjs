import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';
import {createHash} from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import {promisify} from 'node:util';
import {fileURLToPath} from 'node:url';
import test from 'node:test';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const output = path.resolve(root, process.env.SITE_OUTPUT ?? 'docs/dist');
const base = new URL((process.env.BASE_URL ?? '') + '/', 'http://phonics.test');

const publicPath = target => new URL(target, base).pathname;

async function files(directory, prefix = '')
{
	const result = [];

	for(const entry of await fs.readdir(directory, {withFileTypes: true}))
	{
		const name = path.posix.join(prefix, entry.name);

		if(entry.isDirectory()) result.push(...await files(path.join(directory, entry.name), name));
		else result.push(name);
	}

	return result;
}

const planned = (await files(path.join(root, 'site/.cache/pages'))).filter(name => name.endsWith('.md'));
const pages = planned.map(name => name.replace(/\.md$/, '.html'));

const reads = new Map;

const read = name => {
	const file = path.join(output, name.endsWith('/') ? name + 'index.html' : name);

	if(!reads.has(file)) reads.set(file, fs.readFile(file, 'utf8'));

	return reads.get(file);
};

test('all planned pages have navigation, contents for sections, and a drafting outline', async() => {
	for(const page of pages)
	{
		const html = await read(page);

		assert.match(html, /aria-label="Documentation"/);

		if(page !== 'index.html')
		{
			assert.match(html, /<nav class="breadcrumbs" aria-label="Breadcrumb"><ol>/, page);
		}

		assert.doesNotMatch(html, /PHONICS \/ DOCUMENTATION|class="api-breadcrumb"/, page);
		const breadcrumb = /<nav class="breadcrumbs"[^>]*>([\s\S]*?)<\/nav>/.exec(html)?.[1] ?? '';
		assert.doesNotMatch(breadcrumb, /<span>/, `${page} has an unlinked breadcrumb ancestor`);

		assert.equal(/aria-label="On this page"/.test(html), /<h2(?:\s|>)/.test(html), page);

		assert.match(html, /aria-current="page"/);
		assert.match(html, /data-theme-toggle/);
		assert.equal([...html.matchAll(/<h1(?:\s|>)/g)].length, 1, page);
		assert.doesNotMatch(html, /<script[^>]+(?:demo|php8|host\.mjs)/);

		const outline = page.replace('.html', '.md');
		const authored = path.join(root, 'site/drafts', outline);
		const generated = path.join(root, 'site/.cache/api-drafts', outline);
		const packageDoc = /^games\/([^/]+)\/([^/]+)\.html$/.exec(page);
		const sources = [fs.access(authored), fs.access(generated)];

		if(page.startsWith('api-js/'))
			sources.push(fs.access(path.join(root, '.smgen-jsdoc.yaml')));

		if(packageDoc)
		sources.push(fs.access(path.join(root, 'packages', packageDoc[1], 'docs', packageDoc[2] + '.md')));

		await Promise.any(sources);
	}
});

test('SMGen breadcrumbs follow the documentation menu hierarchy', async() => {
	const install = await read('install.html');
	const game = await read('games/doom-phonics/playing.html');
	const api = await read('api-phonics-ecs-scheduler.html');
	const javascriptApi = await read('api-js/classes/quick-controls/quick-controls.html');

	assert.ok(
		install.includes(
			`<li><a href="${publicPath('/')}">Get started</a></li><li aria-current="page">Run locally</li>`
		)
	);

	assert.ok(
		game.includes(
			`<li><a href="${publicPath(
				'/'
			)}">Documentation</a></li><li><a href="${publicPath(
				'/games/'
			)}">Games</a></li><li><a href="${publicPath(
				'/games/doom-phonics/'
			)}">Doom-Phonics</a></li><li aria-current="page">Playing and controls</li>`
		)
	);

	assert.ok(
		api.includes(
			`<li><a href="${publicPath(
				'/'
			)}">Documentation</a></li><li><a href="${publicPath(
				'/architecture.html'
			)}">Reference</a></li><li><a href="${publicPath(
				'/api.html'
			)}">PHP API</a></li><li><a href="${publicPath(
				'/api-ecs.html'
			)}">ECS</a></li><li aria-current="page">Phonics\\Ecs\\Scheduler</li>`
		)
	);

	assert.ok(
		javascriptApi.includes(
			`<li><a href="${publicPath(
				'/architecture.html'
			)}">Reference</a></li><li><a href="${publicPath(
				'/api-js/'
			)}">Phonics JavaScript API</a></li><li><a href="${publicPath(
				'/api-js/modules/quick-controls/'
			)}">QuickControls</a></li><li aria-current="page">QuickControls</li>`
		)
	);
});

test('games mount inside their documentation pages', async() => {
	const games = new Map([
		['sane-phonics', 'Sane-Phonics']
		, ['php-noid', 'PHP-Noid']
		, ['php-tris', 'PHPTris']
		, ['php-slalom', 'PHP Slalom']
		, ['php-buster', 'PHP Buster']
		, ['doom-phonics', 'Doom-Phonics']
	]);

	await fs.access(path.join(output, 'games', 'index.html'));
	await assert.rejects(fs.access(path.join(output, 'games.html')));

	for(const [page, title] of games)
	{
		const html = await read(`games/${page}/index.html`);

		assert.ok(html.includes(`<title>${title} · Phonics documentation</title>`));
		const heading = /<h1>([\s\S]*?)<\/h1>/.exec(html);
		assert.equal(heading?.[1].replace(/\s+/g, ' '), title);
		assert.match(html, /class="game-embed/);
		assert.match(html, /data-game-runtime/);
		assert.match(html, /data-host="\.\.\/\.\.\/preview\/host\.mjs"/);
		assert.match(html, new RegExp(`data-world="\\.\\.\\/\\.\\.\\/engine\\/packages\\/${page}\\/`));
		assert.doesNotMatch(html, /<iframe|Open the standalone game/);
		await assert.rejects(fs.access(path.join(output, page, 'index.html')));
	}

	const preview = await read('preview/');
	assert.match(preview, /<span class="?brand-label"?>docs<\/span>/);
	assert.match(preview, /data-theme-toggle/);
});

test('the generated PHP reference exposes every public class and member', async() => {
	const {stdout} = await promisify(execFile)('php', ['site/tools/php-api.php'], {cwd: root});
	const api = JSON.parse(stdout);
	const index = await read('api.html');

	const slug = name => 'api-' + name.replaceAll('\\', '-')
		.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();

	const anchor = (kind, name) => {
		const member = name.replace(/([a-z0-9])([A-Z])/g, '$1-$2')
			.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '').toLowerCase();

		return `${kind}-${member}`;
	};

	assert.equal(api.classes.length, planned.filter(name => /^api-phonics-.+\.md$/.test(name)).length);

	for(const item of api.classes)
	{
		const page = await read(slug(item.name) + '.html');
		const doubledName = item.name.replaceAll('\\', '\\\\');

		assert.ok(index.includes(`${slug(item.name)}.html`), item.name);
		assert.ok(page.includes(`<title>${item.name} · Phonics documentation</title>`), item.name);
		assert.ok(page.includes(`<h1>${item.name}</h1>`), item.name);
		assert.ok(page.includes(`<span>${item.name}</span>`), item.name);
		assert.ok(!page.includes(`<span>${doubledName}</span>`), item.name);

		for(const value of item.constants)
			assert.ok(page.includes(`id="${anchor('constant', value.name)}"`), `${item.name}::${value.name}`);

		for(const value of item.properties)
			assert.ok(page.includes(`id="${anchor('property', value.name)}"`), `${item.name}::$${value.name}`);

		for(const value of item.methods)
			assert.ok(page.includes(`id="${anchor('method', value.name)}"`), `${item.name}::${value.name}()`);
	}

	assert.match(index, /Compatibility aliases/);
});

test('the generated JavaScript reference exposes browser modules and exports', async() => {
	const javascriptPages = planned.filter(name => name.startsWith('api-js/') && name.endsWith('.md'));
	const index = await read('api-js/');
	const quickControls = await read('api-js/classes/quick-controls/quick-controls.html');

	assert.ok(javascriptPages.length > 100);
	assert.match(index, /Phonics JavaScript API/);
	assert.match(index, /GameLauncher/);
	assert.match(index, /data-api-filter/);
	assert.match(index, /data-api-entry/);
	assert.match(quickControls, /data-id-path="api-js-classes"/);
	assert.match(quickControls, /<summary>Classes<\/summary>/);
	assert.match(quickControls, /aria-current="page"><span>QuickControls<\/span>/);
	assert.match(quickControls, /<h2 id="declaration">Declaration<\/h2>/);
	assert.match(quickControls, /browser\/QuickControls\.mjs#L/);
});

test('every public game type has a nested package reference page', async() => {
	const packages = [
		['sane-phonics', 'SanePhonics']
		, ['php-noid', 'PhpNoid']
		, ['php-tris', 'PhpTris']
		, ['php-slalom', 'PhpSlalom']
		, ['php-buster', 'PhpBuster']
		, ['doom-phonics', 'DoomPhonics']
	];

	for(const [slug, rootNamespace] of packages)
	{
		const {stdout} = await promisify(execFile)('php', ['site/tools/php-api.php', `packages/${slug}`], {cwd: root});
		const api = JSON.parse(stdout), index = await read(`games/${slug}/api/index.html`);
		const overview = await read(`games/${slug}/index.html`);
		assert.ok(overview.includes(`href="${publicPath(`games/${slug}/api/`)}"`), slug);
		assert.ok(!overview.includes(`packages/${slug}/docs/api/index.html`), slug);

		for(const item of api.classes)
		{
			const relative = item.name.slice(rootNamespace.length).replace(/^\\/, '').split('\\')
				.map(value => value.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase()).join('/');

			const route = `games/${slug}/api/${relative}.html`;
			const page = await read(route);

			assert.ok(index.includes(item.name), item.name);
			assert.ok(page.includes(`<h1>${item.name}</h1>`), item.name);
			assert.ok(page.includes('View source'), item.name);
		}
	}
});

test('the static Doom-Phonics example includes its integrity-matched content stack', async() => {
	const directory = path.join(output, 'engine/packages/doom-phonics/demo');
	const packageDirectory = path.dirname(directory);
	const manifest = JSON.parse(await fs.readFile(path.join(packageDirectory, 'phonics.json'), 'utf8'));
	const world = JSON.parse(await fs.readFile(path.join(directory, 'episode-one.phonics-world.json'), 'utf8'));

	assert.ok(manifest.preload.includes('resources/language.txt'));

	for(const name of manifest.preload) await fs.access(path.join(packageDirectory, name));

	for(const [index, name] of [
		'DOOM1.WAD'
		, '../DOOM1_OGG.wad'
		, 'SKULLTAG-WEAPONS.WAD'
		, 'SKULLTAG_ACTORS.lmp'
		, 'SKULLTAG-WEAPONS.DEC'
		, 'SKULLTAG-WEAPONS.SNDINFO'
		, 'DBIGFONT.WAD'
	].entries()){
		const wad = await fs.readFile(path.resolve(directory, name));
		const integrity = 'sha256-' + createHash('sha256').update(wad).digest('base64');

		assert.equal(world.sources.episode.resources[index].url, name.startsWith('..') ? name : './' + name);
		assert.equal(world.sources.episode.resources[index].integrity, integrity);
	}

	assert.deepEqual(world.sources.episode.resources.map(resource => resource.role), [
		'iwad','pwad','pwad','decorate','decorate','sndinfo','pwad'
	]);

	assert.equal(world.start.scope, 'E1M1');
	await fs.access(path.join(directory, 'SHAREWARE-LICENSE.txt'));

	for(const [file,source,scope] of [
		['portal-demo.phonics-world.json','portal-demo','PORTALS']
		, ['portal-rooms.phonics-world.json','portal-rooms','PORTAL2']
		, ['camera-mirrors.phonics-world.json','camera-mirrors','CAMERAS']
	]){
		const portalWorld = JSON.parse(await fs.readFile(path.join(directory, file), 'utf8'));

		for(const [index,name] of ['DOOM1.WAD','PORTALS.WAD'].entries())
		{
			const bytes = await fs.readFile(path.join(directory, name));
			assert.equal(portalWorld.sources[source].resources[index].url, './' + name);

			assert.equal(
				portalWorld.sources[source].resources[index].integrity
				, 'sha256-' + createHash('sha256').update(bytes).digest('base64')
			);
		}

		assert.deepEqual(portalWorld.sources[source].resources.map(resource => resource.role), ['iwad','pwad']);
		assert.equal(portalWorld.start.scope, scope);
	}
});

test('all local documentation links, fragments, and styles resolve', async() => {
	let links = 0;

	for(const page of pages)
	{
		const html = await read(page);

		for(const [, value] of html.matchAll(/(?:href|src)="([^"]+)"/g))
		{
			const url = new URL(value.replaceAll('&amp;', '&'), new URL(page, base));

			if(url.origin !== base.origin || !url.pathname.startsWith(base.pathname)) continue;

			const relative = decodeURIComponent(url.pathname.slice(base.pathname.length)) || 'index.html';
			const target = await read(relative);

			if(url.hash)
			{
				const id = decodeURIComponent(url.hash.slice(1));
				assert.ok(target.includes(`id="${id}"`), `${page}: missing ${value}`);
			}

			links++;
		}
	}

	assert.ok(links > 100);
});

test('the site publishes structured metadata, llms.txt, and the browser assistant', async() => {
	const index = await read('index.html');
	const jsonLdText = index.match(/<script type="application\/ld\+json">([^<]+)<\/script>/)?.[1];
	assert.ok(jsonLdText, 'index.html is missing JSON-LD');

	const jsonLd = JSON.parse(jsonLdText);
	assert.deepEqual(jsonLd['@graph'].map(value => value['@type']), ['WebSite', 'SoftwareSourceCode']);
	assert.equal(jsonLd['@graph'][1].codeRepository, 'https://github.com/seanmorris/php-wasm-engine');

	for(const page of pages.filter(page => page !== 'index.html'))
	{
		const html = await read(page);
		const scripts = [...html.matchAll(/<script type="application\/ld\+json">([^<]+)<\/script>/g)];

		assert.equal(scripts.length, 1, `${page} must contain one JSON-LD document`);

		const structured = JSON.parse(scripts[0][1]);

		assert.equal(structured['@context'], 'https://schema.org', page);
		assert.equal(structured['@type'], 'TechArticle', page);
		assert.ok(structured.headline, `${page} is missing a JSON-LD headline`);
		assert.equal(structured.inLanguage, 'en', page);
		assert.equal(structured.isPartOf?.name, 'Phonics documentation', page);
		assert.equal(structured.about?.codeRepository, 'https://github.com/seanmorris/php-wasm-engine', page);
	}

	const llms = await fs.readFile(path.join(output, 'llms.txt'), 'utf8');
	const headings = [
		'# Phonics'
		, '## Start Here'
		, '## Guides'
		, '## Reference'
		, '## Games'
		, '## Release and Contribute'
	];

	const targets = [
		'index.html'
		, 'quickstart.html'
		, 'worlds.html'
		, 'architecture.html'
		, 'api.html'
		, 'contributing.html'
	];

	for(const heading of headings)
		assert.ok(llms.includes(heading), heading);

	for(const target of targets)
		assert.ok(llms.includes(target), target);

	const assistant = path.join(output, '__smgen-assistant');
	const config = JSON.parse(await fs.readFile(path.join(assistant, 'config.json'), 'utf8'));
	const corpus = JSON.parse(await fs.readFile(path.join(assistant, 'corpus.json'), 'utf8'));
	assert.equal(config.title, 'Ask Phonics');
	assert.equal(config.models[0].id, 'Qwen3.5-9B-q4f16_1-MLC');
	assert.ok(corpus.sources.some(source => source.title === 'Phonics' && source.url === publicPath('')));

	assert.ok(
		corpus.sources.some(
			source => source.title === 'Phonics\\Spatial\\BoundsIndex'
				&& source.url === publicPath('api-phonics-spatial-bounds-index.html')
		)
	);

	assert.ok(corpus.chunks.length > planned.length);
	await fs.access(path.join(assistant, 'loader.js'));
	await fs.access(path.join(output, '.nojekyll'));

	for(const page of pages)
	{
		const html = await read(page);
		assert.match(html, /<link rel="describedby" type="text\/markdown" href="[^"]*\/llms\.txt">/);
		assert.match(html, /<script src="[^"]*\/__smgen-assistant\/loader\.js\?site=phonics"><\/script>/);
	}
});

test('search and controller references are published with the requested smgen pins', async() => {
	for(const page of ['sane-phonics', 'php-noid', 'php-tris', 'php-slalom', 'php-buster', 'doom-phonics'])
	{
		await fs.access(path.join(output, 'games', page, 'index.html'));
		await fs.access(path.join(output, 'images', 'games', page + '.png'));
	}

	assert.equal(
		(await fs.readFile(path.join(root, 'site/.smgen-version'), 'utf8')).trim()
		, '04af8b116317724863af544c023818eb8b67d135'
	);

	const manifest = JSON.parse(await fs.readFile(path.join(root, 'package.json'), 'utf8'));

	assert.equal(
		manifest.devDependencies['smgen-assistant']
		, 'github:seanmorris/smgen-assistant#289e2eaa35f3a1cd5698e7d485700f201e13d0e2'
	);

	assert.equal(
		manifest.devDependencies['smgen-plugins']
		, 'github:seanmorris/smgen-plugins#30c99f413f33333b2c17e8ee993ad2acfc04b516'
	);

	assert.equal(manifest.devDependencies.typescript, '^6.0.3');

	assert.equal(manifest.devDependencies['smgen-search'], '^0.1.1');

	const home = await read('index.html');
	const input = await read('input.html');
	const search = await fs.readFile(path.join(output, 'search.bin'));

	assert.equal(search.subarray(0, 4).toString(), 'SRCH');
	assert.match(home, /data-search-toggle/);
	assert.ok(home.includes(`data-search-index="${publicPath('search.bin')}"`));
	assert.ok(home.includes(`data-search-reader="${publicPath('vendor/smgen-search/SearchReader.mjs')}"`));
	const navigationStateScript = `<script src="${publicPath('nav-state.js')}"></script>`;
	const deferredSiteScript = `<script defer src="${publicPath('site.js')}"></script>`;
	assert.ok(home.includes(navigationStateScript));
	assert.ok(home.indexOf(navigationStateScript) < home.indexOf(deferredSiteScript));
	assert.equal([...input.matchAll(/class="controller-glyph"/g)].length, 32);
	assert.match(input, /alt="Xbox A button"/);
	assert.match(input, /alt="PlayStation Cross button"/);
	await fs.access(path.join(output, 'vendor', 'smgen-search', 'SearchReader.mjs'));
});
