import {execFile} from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import {promisify} from 'node:util';
import {fileURLToPath} from 'node:url';
import {parse, stringify} from 'yaml';
import {addBreadcrumbs} from './breadcrumbs.mjs';
import {chapterFigures, examplesSourceUrl, loadExamples} from './examples.mjs';
import {addStructuredData} from './structured-data.mjs';

const site = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const root = path.resolve(site, '..');
const destination = path.resolve(site, process.env.PAGES_DIR ?? '.cache/pages');
const references = [
	['architecture', 'Architecture', 'docs/architecture.md']
	, ['input', 'Input & sequences', 'docs/input.md']
	, ['ui', 'UI, menus & cards', 'docs/ui.md']
	, ['performance', 'ECS performance', 'docs/ecs-performance.md']
	, ['random-cards', 'Random streams and card piles', 'docs/random-cards.md']
];

const examples = await loadExamples();

const exampleFiles = new Set(
	(await fs.readdir(path.join(examples.root, 'games'), {recursive: true}))
		.map(file => path.posix.join('games', file.split(path.sep).join('/')))
);

const games = examples.games.filter(game => game.documentation?.chapters?.length).map(game => ({
	...game
	, canvas: [game.canvas.width, game.canvas.height]
	, canvasLabel: `${game.title} game canvas`
	, aspectClass: game.aspectRatio === '8/5'
		? 'game-aspect-8-5'
		: game.aspectRatio === '2/3' ? 'game-aspect-2-3' : undefined
	, dataOrigins: game.documentation.dataOrigins
	, posterExtension: path.extname(game.poster)
	, chapters: game.documentation.chapters.map(chapter => [chapter.slug, chapter.title, chapter.path])
}));

// Authored site pages, keyed by repository path, so imported chapters can link to them on the site.
const authoredPages = new Set(
	(await fs.readdir(path.join(site, 'pages'), {recursive: true}))
		.filter(file => file.endsWith('.md'))
		.map(file => path.posix.join('site/pages', file.split(path.sep).join('/')))
);

/**
 * Map a repository Markdown path to the generated page that publishes it.
 *
 * @param {string} resolved Normalized repository-relative path without a fragment.
 * @returns {string|null} Site-relative page path, or null when the file is not staged as a page.
 */
function sitePage(resolved)
{
	const reference = references.find(([, , file]) => file === resolved);

	if(reference) return `${reference[0]}.html`;

	const chapter = /^games\/([^/]+)\/docs\/([^/]+)\.md$/.exec(resolved);
	const game = chapter && games.find(value => value.slug === chapter[1]);

	if(game?.chapters.some(([slug]) => slug === chapter[2]))
		return `games/${game.slug}/${chapter[2] === 'index' ? '' : chapter[2] + '.html'}`;

	if(authoredPages.has(resolved))
	{
		const page = resolved.slice('site/pages/'.length).replace(/\.md$/, '');

		return page === 'index' ? './' : page.endsWith('/index') ? page.slice(0, -'index'.length) : page + '.html';
	}

	// Links to framework source directories publish as the generated reference pages for those
	// namespaces, so the same Markdown reads correctly on GitHub and on the site.
	const php = /^src(?:\/([A-Za-z]+(?:\/[A-Za-z]+)*))?\/?$/.exec(resolved);

	if(php) return php[1] ? `api-${php[1].replaceAll('/', '-').toLowerCase()}.html` : 'api.html';

	if(/^browser\/network\/?$/.test(resolved)) return 'api-js/modules/networking/';

	if(/^browser\/?$/.test(resolved)) return 'api-js/';

	return null;
}

/**
 * Express a site page relative to the directory of the page that links to it.
 *
 * @param {string} from Site-relative directory of the linking page, or '' for the site root.
 * @param {string} page Site-relative target from sitePage().
 * @returns {string} Relative URL, keeping directory targets as directories.
 */
function relativeSiteLink(from, page)
{
	const directory = page.endsWith('/') || page === './';
	const target = page === './' ? '.' : page.replace(/\/$/, '');
	const relative = path.posix.relative(from || '.', target) || '.';

	return directory ? (relative === '.' ? './' : relative + '/') : relative;
}

await fs.rm(destination, {recursive: true, force: true});
await fs.cp(path.join(site, 'pages'), destination, {recursive: true});

for(const [index, [slug, title, source]] of references.entries())
{
	let body = await fs.readFile(path.join(root, source), 'utf8');
	body = body.replace(/^# .+\r?\n/, '');

	// Controller glyphs point at the repository art so they also render on GitHub.
	body = body.replace(/(<img\b[^>]*\bsrc=["'])\.\.\/art\/controllers\/([^"']+)(["'])/g, '$1images/controllers/$2$3');

	// Repository-relative links remain source links, not broken generated-site paths. Links to other
	// reference chapters, staged game chapters, and generated API pages stay on the site.
	body = body.replace(/(!?\[[^\]]*\]\()([^\s)]+)(\))/g, (match, start, target, end) => {
		if(/^(?:[a-z]+:|\/\/|#)/i.test(target)
			|| /^api(?:-[a-z0-9-]+)?\.html(?:#|$)/.test(target)
			|| /^api-js\//.test(target)
		) return match;

		const clean = target.split('#')[0], fragment = target.slice(clean.length);
		const resolved = path.posix.normalize(path.posix.join(path.posix.dirname(source), clean));
		const page = sitePage(resolved);

		if(page) return `${start}${relativeSiteLink('', page)}${fragment}${end}`;

		return `${start}https://github.com/seanmorris/php-wasm-engine/blob/HEAD/${resolved}${fragment}${end}`;
	});

	const header = `---\ntitle: ${title}\nmenus:\n  documentation:\n    parent: reference\n    weight: ${index}\n---\n`;
	const note = `\nSource: [${source}](https://github.com/seanmorris/php-wasm-engine/blob/HEAD/${source}). `
		+ 'This chapter is imported from the repository on every build.\n';

	await fs.writeFile(path.join(destination, `${slug}.md`), header + note + body);
}

for(const game of games)
{
	const targetDirectory = path.join(destination, 'games', game.slug);
	const figures = new Set(await chapterFigures(game));

	await fs.mkdir(targetDirectory, {recursive: true});

	for(const [weight, [slug, title, chapterPath]] of game.chapters.entries())
	{
		const source = `games/${game.slug}/${chapterPath}`;
		let body = await fs.readFile(path.join(examples.root, source), 'utf8');
		body = body.replace(/^# .+\r?\n/, '');

		body = body.replace(
			/(<img\b[^>]*\bsrc=["'])\.\.\/\.\.\/\.\.\/art\/controllers\/([^"']+)(["'])/g
			, '$1../../images/controllers/$2$3'
		);

		body = body.replace(/(!?\[[^\]]*\]\()([^\s)]+)(\))/g, (match, start, target, end) => {
			if(/^(?:[a-z]+:|\/\/|#)/i.test(target)) return match;

			const clean = target.split('#')[0], fragment = target.slice(clean.length);

			if(clean === 'api/index.html')
			return `${start}api/${fragment}${end}`;

			if(clean === './' || clean === '') return match;

			// Figures beside a chapter are published with the game's poster by publish.mjs.
			const figure = path.posix.basename(clean);
			const beside = path.posix.normalize(path.posix.join(path.posix.dirname(chapterPath), clean));

			if(match.startsWith('!') && figures.has(figure) && beside === `docs/${figure}`)
			return `${start}../../images/games/${game.slug}/${figure}${fragment}${end}`;

			let resolved;

			if(/^(?:docs|examples|browser|src|assets)\//.test(clean))
				resolved = path.posix.join(`games/${game.slug}`, clean);
			else resolved = path.posix.normalize(path.posix.join(path.posix.dirname(source), clean));

			// Chapters, reference docs, and authored pages resolve by repository path, not basename.
			const page = sitePage(resolved);

			if(page) return `${start}${relativeSiteLink(`games/${game.slug}`, page)}${fragment}${end}`;

			if(exampleFiles.has(resolved))
			return `${start}${examplesSourceUrl(examples, resolved)}${fragment}${end}`;

			const engineSource = resolved.replace(/^games\/phonics-matrix\//, 'packages/phonics-matrix/');

			return `${start}https://github.com/seanmorris/php-wasm-engine/blob/HEAD/${engineSource}${fragment}${end}`;
		});

		const overviewTitle = slug === 'index' ? '    title: Overview\n' : '';

		const header = `---\ntitle: ${JSON.stringify(
			title
		)}\ndescription-meta: ${JSON.stringify(
			slug === 'index' ? game.description : `${title} in ${game.title}.`
		)}\nllms: games\nmenus:\n  documentation:\n`
			+ `${overviewTitle}    parent: game-${game.slug}\n    weight: ${weight}\n---\n`;

		const embedClass = game.aspectClass ? ` ${game.aspectClass}` : '';
		const dataOrigins = game.dataOrigins
			? ` data-data-origins="${game.dataOrigins.join(',')}"`
			: '';

		let embed = '';

		if(slug === 'index' && game.documentation.embed.kind === 'external')
		embed = `\n<div class="game-embed external-game-embed" data-external-game-embed data-title="${game.title}" data-frame="${game.documentation.embed.frame}">\n<button type="button" class="game-embed-poster" aria-label="Load and play ${game.title}">\n<img src="../../images/games/${game.slug}${game.posterExtension}" width="1280" height="720" fetchpriority="high" alt="${game.title} gameplay">\n<span>Play ${game.title}</span>\n</button>\n<div class="game-embed-runtime external-game-runtime" data-external-game-runtime hidden>\n<div class="game-embed-controls"><p role="status">Preparing ${game.title}…</p><button type="button">Stop</button></div>\n</div>\n</div>\n\n`;
		else if(slug === 'index')
		embed = `\n<div class="game-embed${embedClass}" data-game-embed data-application="../../engine/packages/${game.slug}/dist/game.phar" data-host="../../preview/host.mjs" data-title="${game.title}"${dataOrigins}>\n<button type="button" class="game-embed-poster" aria-label="Load and play ${game.title}">\n<img src="../../images/games/${game.slug}${game.posterExtension}" width="1280" height="720" fetchpriority="high" alt="${game.title} gameplay">\n<span>Play ${game.title}</span>\n</button>\n<div class="game-embed-runtime" data-game-runtime data-game-embed="true" hidden>\n<div id="game-frame"><canvas width="${game.canvas[0]}" height="${game.canvas[1]}" tabindex="0" aria-label="${game.canvasLabel}"></canvas></div>\n<p id="fullscreen-error" role="alert"></p>\n<div class="game-embed-controls"><p id="status" role="status">Preparing ${game.title}…</p><button id="stop" type="button">Stop</button></div>\n</div>\n</div>\n\n`;

		await fs.writeFile(path.join(targetDirectory, `${slug}.md`), header + embed + body);
	}
}

// Reflection and source parsing run after authored/imported pages are staged so
// the generated API reference participates in the same SMGen render.
const {apiSourceLink} = await import('./api-reference.mjs');

const jsDoc = path.join(root, 'node_modules/smgen-plugins/packages/smgen-jsdoc/bin/smgen-jsdoc');

const generated = await promisify(execFile)(process.execPath, [
	jsDoc
	, 'generate'
	, '--root', root
	, '--config', '.smgen-jsdoc.yaml'
	, '--pages', destination
], {cwd: root});

process.stdout.write(generated.stdout);
process.stderr.write(generated.stderr);

// The shared site template owns the single page heading. Normalize the generated
// API subtree for that contract, then route its pages into readable sidebar groups.
const javascriptDirectory = path.join(destination, 'api-js');

const javascriptPages = (await fs.readdir(javascriptDirectory, {recursive: true}))
	.filter(file => file.endsWith('.md'));

for(const file of javascriptPages)
{
	const page = path.join(javascriptDirectory, file);
	const source = await fs.readFile(page, 'utf8');
	const match = source.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n+/);

	if(match === null) throw new Error(`Generated JavaScript API page has no frontmatter: ${file}`);

	const metadata = parse(match[1]);
	const relative = file.split(path.sep).join('/');
	const menu = metadata.menus?.documentation;

	if(menu)
	{
		if(relative.startsWith('modules/')) menu.parent = 'api-js-modules';
		else if(relative.startsWith('classes/')) menu.parent = 'api-js-classes';
		else if(relative.startsWith('functions/')) menu.parent = 'api-js-functions';
		else if(relative.startsWith('types/')) menu.parent = 'api-js-types';
		else if(relative.startsWith('values/')) menu.parent = 'api-js-values';
	}

	let body = source.slice(match[0].length).replace(/^(?:# [^\r\n]+\r?\n+)/, '');

	// Keep the complete generated comment in the article, followed by the shared
	// source button. The page header supplies only the title and breadcrumbs.
	if(metadata['api-page'] && metadata['source-url'])
	{
		body = body.replace(
			`\n[View source](${metadata['source-url']})\n`
			, `\n${apiSourceLink(metadata['source-url'])}\n`
		);
	}

	const normalized = `---\n${stringify(metadata, {lineWidth: 0})}---\n\n${body}`;

	await fs.writeFile(page, normalized);
}

await addBreadcrumbs(destination, path.join(site, '.smgen.yaml'));
await addStructuredData(destination);
