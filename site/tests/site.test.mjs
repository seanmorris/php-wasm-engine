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

const read = name => fs.readFile(path.join(output, name.endsWith('/') ? name + 'index.html' : name), 'utf8');

test('all planned pages have navigation, contents for sections, and a drafting outline', async() => {
	for(const page of pages)
	{
		const html = await read(page);

		assert.match(html, /aria-label="Documentation"/);

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

		if(packageDoc)
		sources.push(fs.access(path.join(root, 'packages', packageDoc[1], 'docs', packageDoc[2] + '.md')));

		await Promise.any(sources);
	}
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
		assert.ok(html.includes(`<h1>${title}</h1>`));
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

	assert.equal(planned.filter(name => /^api-browser-.+\.md$/.test(name)).length, 9);
	assert.match(index, /GameLauncher/);
	assert.match(index, /Compatibility aliases/);
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

	let total = 0;

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
			total++;
		}
	}

	assert.equal(total, 289);
});

test('the static Doom-Phonics example includes its integrity-matched content stack', async() => {
	const directory = path.join(output, 'engine/packages/doom-phonics/demo');
	const world = JSON.parse(await fs.readFile(path.join(directory, 'episode-one.phonics-world.json'), 'utf8'));

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
	])
	{
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
		, '11d7984173cc796a652eee324f85897a545ba7c9'
	);

	const manifest = JSON.parse(await fs.readFile(path.join(root, 'package.json'), 'utf8'));

	assert.equal(
		manifest.devDependencies['smgen-assistant']
		, 'github:seanmorris/smgen-assistant#289e2eaa35f3a1cd5698e7d485700f201e13d0e2'
	);

	assert.equal(manifest.devDependencies['smgen-search'], '^0.1.1');

	const home = await read('index.html');
	const input = await read('input.html');
	const search = await fs.readFile(path.join(output, 'search.bin'));

	assert.equal(search.subarray(0, 4).toString(), 'SRCH');
	assert.match(home, /data-search-toggle/);
	assert.ok(home.includes(`data-search-index="${publicPath('search.bin')}"`));
	assert.ok(home.includes(`data-search-reader="${publicPath('vendor/smgen-search/SearchReader.mjs')}"`));
	assert.equal([...input.matchAll(/class="controller-glyph"/g)].length, 32);
	assert.match(input, /alt="Xbox A button"/);
	assert.match(input, /alt="PlayStation Cross button"/);
	await fs.access(path.join(output, 'vendor', 'smgen-search', 'SearchReader.mjs'));
});
