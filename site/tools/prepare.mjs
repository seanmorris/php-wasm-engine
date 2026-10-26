import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const site = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const root = path.resolve(site, '..');
const destination = path.resolve(site, process.env.PAGES_DIR ?? '.cache/pages');
const references = [
	['architecture', 'Architecture', 'docs/architecture.md']
	, ['input', 'Input & sequences', 'docs/input.md']
	, ['performance', 'ECS performance', 'docs/ecs-performance.md']
];

const games = [
	{
		slug: 'sane-phonics'
		, title: 'Sane-Phonics'
		, description: 'A streamed platformer with loops, vehicles, water, enemies, and three movement profiles.'
		, chapters: [['index', 'Overview']
			, ['gameplay', 'Gameplay systems']
			, ['loops', 'Loops and collision layers']
			, [
				'menus', 'Menus and browser controls'
			]
			, ['startup', 'Startup flow']
		]
	}
	, {
		slug: 'php-noid'
		, title: 'PHP-Noid'
		, description: 'A 25-stage brick-breaker with stackable powers, enemy ships, and an arcade HUD.'
		, chapters: [['index', 'Overview']
			, ['playing', 'Playing PHP-Noid']
			, ['campaign', 'Campaign authoring']
			, [
				'architecture', 'Architecture and performance'
			]
		]
	}
	, {
		slug: 'php-tris'
		, title: 'PHPTris'
		, description: 'Three solo modes and five progressive falling-block challenge tracks.'
		, chapters: [['index', 'Overview']
			, ['playing', 'Playing and scoring']
			, ['challenges', 'Challenge tracks']
			, [
				'world-authoring', 'World authoring'
			]
			, ['architecture', 'Architecture']
		]
	}
	, {
		slug: 'doom-phonics'
		, title: 'Doom-Phonics'
		, description: 'A WAD adapter, DECORATE actor runtime, multiplayer simulation, and direct GLES renderer.'
		, chapters: [['index', 'Overview']
			, ['playing', 'Playing and controls']
			, ['launcher', 'WAD stacks and launcher']
			, ['wad-and-decorate', 'WAD and DECORATE']
			, ['multiplayer', 'Multiplayer and replication']
			, ['rendering', 'Rendering and performance']
		]
	}
];

await fs.rm(destination, {recursive: true, force: true});
await fs.cp(path.join(site, 'pages'), destination, {recursive: true});

for(const [index, [slug, title, source]] of references.entries())
{
	let body = await fs.readFile(path.join(root, source), 'utf8');
	body = body.replace(/^# .+\r?\n/, '');

	// Repository-relative links remain source links, not broken generated-site paths.
	body = body.replace(/(!?\[[^\]]*\]\()([^\s)]+)(\))/g, (match, start, target, end) => {
		if(/^(?:[a-z]+:|\/\/|#)/i.test(target)) return match;

		const resolved = path.posix.normalize(path.posix.join(path.posix.dirname(source), target));

		return `${start}https://github.com/seanmorris/php-wasm-engine/blob/HEAD/${resolved}${end}`;
	});

	const header = `---\ntitle: ${title}\nmenus:\n  documentation:\n    parent: reference\n    weight: ${index}\n---\n`;
	const note = `\nSource: [${source}](https://github.com/seanmorris/php-wasm-engine/blob/HEAD/${source}). `
		+ 'This chapter is imported from the repository on every build.\n';

	await fs.writeFile(path.join(destination, `${slug}.md`), header + note + body);
}

for(const game of games)
{
	const targetDirectory = path.join(destination, 'games', game.slug);
	const chapterNames = new Set(game.chapters.map(([slug]) => slug));

	await fs.mkdir(targetDirectory, {recursive: true});

	for(const [weight, [slug, title]] of game.chapters.entries())
	{
		const source = `packages/${game.slug}/docs/${slug}.md`;
		let body = await fs.readFile(path.join(root, source), 'utf8');
		body = body.replace(/^# .+\r?\n/, '');

		body = body.replace(/(!?\[[^\]]*\]\()([^\s)]+)(\))/g, (match, start, target, end) => {
			if(/^(?:[a-z]+:|\/\/|#)/i.test(target)) return match;

			const clean = target.split('#')[0], fragment = target.slice(clean.length);
			const local = path.posix.basename(clean, '.md');

			if(clean.endsWith('.md') && chapterNames.has(local))
			return `${start}${local === 'index' ? './' : local + '.html'}${fragment}${end}`;

			if(clean.endsWith('loop-layout.svg'))
			return `${start}../../images/games/sane-loop-layout.svg${fragment}${end}`;

			if(clean === './' || clean === '') return match;

			let resolved;

			if(/^(?:docs|examples|browser|src|assets)\//.test(clean))
				resolved = path.posix.join(`packages/${game.slug}`, clean);
			else resolved = path.posix.normalize(path.posix.join(path.posix.dirname(source), clean));

			return `${start}https://github.com/seanmorris/php-wasm-engine/blob/HEAD/${resolved}${fragment}${end}`;
		});

		const header = `---\ntitle: ${JSON.stringify(
			title
		)}\ndescription-meta: ${JSON.stringify(
			slug === 'index' ? game.description : `${title} in ${game.title}.`
		)}\nllms: games\nmenus:\n  documentation:\n    parent: game-${game.slug}\n    weight: ${weight}\n---\n`;

		const embed = slug === 'index'
			? `\n<div class="game-embed" data-game-embed data-src="../../${game.slug}/?embed=1" data-title="${game.title}">\n<button type="button" class="game-embed-poster" aria-label="Load and play ${game.title}">\n<img src="../../images/games/${game.slug}.png" alt="${game.title} gameplay">\n<span>Play ${game.title}</span>\n</button>\n<p><a href="../../${game.slug}/" target="_blank" rel="noopener noreferrer">Open the standalone game <span aria-hidden="true">↗</span></a></p>\n</div>\n\n`
			: '';

		await fs.writeFile(path.join(targetDirectory, `${slug}.md`), header + embed + body);
	}
}

// Reflection and source parsing run after authored/imported pages are staged so
// the generated API reference participates in the same SMGen render.
await import('./api-reference.mjs');
