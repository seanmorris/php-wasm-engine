import fs from 'node:fs/promises';
import path from 'node:path';
import {parse, stringify} from 'yaml';

const frontmatterPattern = /^---\r?\n([\s\S]*?)\r?\n---/;

async function markdownFiles(directory, prefix = '')
{
	const result = [];

	for(const entry of await fs.readdir(directory, {withFileTypes: true}))
	{
		const relative = path.posix.join(prefix, entry.name);
		const absolute = path.join(directory, entry.name);

		if(entry.isDirectory()) result.push(...await markdownFiles(absolute, relative));
		else if(entry.isFile() && entry.name.endsWith('.md')) result.push(relative);
	}

	return result;
}

function publicPath(file)
{
	if(file === 'index.md') return '/';

	if(file.endsWith('/index.md')) return `/${file.slice(0, -'index.md'.length)}`;

	return `/${file.slice(0, -'.md'.length)}.html`;
}

function preferredLanding(left, right)
{

	const score = page => [
		path.posix.basename(page.file) === 'index.md' ? 0 : 1
		, page.weight < 0 ? 0 : 1
		, page.weight
		, page.file
	];

	const leftScore = score(left), rightScore = score(right);

	for(let index = 0; index < leftScore.length; index++)
	{
		if(leftScore[index] < rightScore[index]) return -1;

		if(leftScore[index] > rightScore[index]) return 1;
	}

	return 0;
}

function groupChain(parent, groups)
{
	const result = [], visited = new Set;
	let id = parent;

	while(id && groups.has(id) && !visited.has(id))
	{
		visited.add(id);
		result.unshift(groups.get(id));
		id = groups.get(id).parent;
	}

	return result;
}

/**
 * Add explicit, linked breadcrumb ancestors to staged SMGen pages.
 *
 * Manual menu groups intentionally have no navigation target because they must
 * remain expandable. Their direct overview page supplies the breadcrumb target
 * instead. SMGen then resolves the root-relative paths against BASE_URL.
 *
 * @param {string} pagesDirectory Staged Markdown directory.
 * @param {string} configuration SMGen YAML configuration path.
 * @returns {Promise<void>} Resolves after every staged page is updated.
 */
export async function addBreadcrumbs(pagesDirectory, configuration)
{
	const config = parse(await fs.readFile(configuration, 'utf8'));
	const links = config?.MENUS?.documentation?.links ?? [];

	const groups = new Map(links.filter(link => link.group).map(link => [link.id, {
		id: link.id
		, title: link.title
		, parent: link.parent ?? null
		, landing: null
	}]));

	const pages = [];

	for(const file of await markdownFiles(pagesDirectory))
	{
		const absolute = path.join(pagesDirectory, file);
		const source = await fs.readFile(absolute, 'utf8');
		const match = frontmatterPattern.exec(source);

		if(!match) continue;

		const metadata = parse(match[1]) ?? {};
		const menu = metadata?.menus?.documentation;
		const parent = menu?.parent;
		const weight = Number(menu?.weight ?? 0);

		pages.push({absolute, file, source, match, metadata, parent, weight, path: publicPath(file)});
	}

	for(const group of groups.values())
	{
		const candidates = pages.filter(page => page.parent === group.id).sort(preferredLanding);
		group.landing = candidates[0]?.path ?? null;
	}

	for(const page of pages)
	{
		let chainParent = page.parent;
		const explicit = Array.isArray(page.metadata.breadcrumbs) ? page.metadata.breadcrumbs : null;

		if(explicit?.[0]?.path)
		{
			const landing = pages.find(candidate => candidate.path === explicit[0].path);

			if(landing) chainParent = landing.parent;
		}

		const breadcrumbs = groupChain(chainParent, groups).filter(group => group.landing !== page.path)
			.map(group => ({
				title: group.title
				, ...(group.landing ? {path: group.landing} : {})
			}));

		if(explicit)
		{
			const existingPaths = new Set(explicit.map(item => item.path).filter(Boolean));
			const existingTitles = new Set(explicit.map(item => item.title));
			page.metadata.breadcrumbs = [
				...breadcrumbs.filter(item => !existingTitles.has(item.title) && !existingPaths.has(item.path))
				, ...explicit
			];

			const frontmatter = stringify(page.metadata).trimEnd();
			const updated = `---\n${frontmatter}\n---${page.source.slice(page.match[0].length)}`;

			await fs.writeFile(page.absolute, updated);
			continue;
		}

		const insert = stringify({breadcrumbs}).trimEnd() + '\n';
		const boundary = page.match[0].lastIndexOf('---');
		const updated = page.source.slice(0, boundary) + insert + page.source.slice(boundary);

		await fs.writeFile(page.absolute, updated);
	}
}
