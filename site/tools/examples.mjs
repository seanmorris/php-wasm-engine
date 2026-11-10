import {execFile} from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
import {promisify} from 'node:util';
import {fileURLToPath} from 'node:url';

const execute = promisify(execFile);
const site = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const engine = path.resolve(site, '..');
const source = JSON.parse(await fs.readFile(path.join(site, 'examples-source.json')));
let loaded;

async function isExamplesRoot(directory)
{
	try
	{
		const manifest = JSON.parse(await fs.readFile(path.join(directory, 'package.json')));

		return manifest.name === '@seanmorris/phonics-examples';
	}
	catch
	{
		return false;
	}
}

async function checkout()
{
	const override = process.env.PHONICS_EXAMPLES_ROOT;
	const sibling = path.resolve(engine, '../phonics-examples');

	if(override)
	{
		const candidate = path.resolve(override);

		if(await isExamplesRoot(candidate)) return candidate;

		throw new Error(`PHONICS_EXAMPLES_ROOT is not a phonics-examples checkout: ${candidate}`);
	}

	if(await isExamplesRoot(sibling)) return sibling;

	const cache = path.join(site, '.cache/phonics-examples');

	if(await isExamplesRoot(cache))
	{
		await execute('git', ['fetch','--depth','1','origin',source.ref], {cwd: cache});
		await execute('git', ['checkout','--detach','FETCH_HEAD'], {cwd: cache});

		return cache;
	}

	await fs.mkdir(path.dirname(cache), {recursive: true});
	await fs.rm(cache, {recursive: true, force: true});
	await execute('git', ['clone','--depth','1','--branch',source.ref,source.repository,cache], {cwd: engine});

	return cache;
}

export async function loadExamples()
{
	if(loaded) return loaded;

	const root = await checkout();
	const gamesRoot = path.join(root, 'games');
	const games = [];

	for(const entry of (await fs.readdir(gamesRoot, {withFileTypes: true}))
		.filter(entry => entry.isDirectory()).sort((a, b) => a.name.localeCompare(b.name))
	){
		const directory = path.join(gamesRoot, entry.name);
		let metadata;

		try
		{
			metadata = JSON.parse(await fs.readFile(path.join(directory, 'example.json')));
		}
		catch(error)
		{
			if(error.code === 'ENOENT') continue;

			throw error;
		}

		if(metadata.schemaVersion !== 1 || metadata.slug !== entry.name)
		throw new Error(`${entry.name}: incompatible example metadata`);

		for(const chapter of metadata.documentation?.chapters ?? [])
		await fs.access(path.join(directory, chapter.path));

		games.push({...metadata, directory});
	}

	loaded = {root, games, repository: source.repository.replace(/\.git$/, ''), ref: source.ref};

	return loaded;
}

/**
 * List the figures a game's chapters embed from its own docs directory.
 *
 * The site publishes them beside the game's poster, so a chapter's relative image link renders the same on GitHub
 * and in the documentation.
 *
 * @param {{directory: string, documentation?: {chapters?: {path: string}[]}}} game Example metadata from
 * loadExamples().
 * @returns {Promise<string[]>} File names inside the game's docs directory, without duplicates.
 */
export async function chapterFigures(game)
{
	const names = new Set;

	for(const chapter of game.documentation?.chapters ?? [])
	{
		const source = await fs.readFile(path.join(game.directory, chapter.path), 'utf8');

		for(const [, target] of source.matchAll(/!\[[^\]]*\]\(([^\s)]+)\)/g))
		{
			if(/^(?:[a-z]+:|\/\/|#)/i.test(target)) continue;

			const relative = path.posix.join(path.posix.dirname(chapter.path), target.split('#')[0]);
			const resolved = path.posix.normalize(relative);

			if(path.posix.dirname(resolved) !== 'docs') continue;

			await fs.access(path.join(game.directory, resolved));
			names.add(path.posix.basename(resolved));
		}
	}

	return [...names];
}

export function examplesSourceUrl(examples, relative)
{
	return `${examples.repository}/blob/${examples.ref}/${relative.split(path.sep).join('/')}`;
}
