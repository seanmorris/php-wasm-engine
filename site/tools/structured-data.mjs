import fs from 'node:fs/promises';
import path from 'node:path';
import {parse, stringify} from 'yaml';

const frontmatterPattern = /^---\r?\n([\s\S]*?)\r?\n---/;

async function markdownFiles(directory)
{
	const result = [];

	for(const entry of await fs.readdir(directory, {withFileTypes: true}))
	{
		const absolute = path.join(directory, entry.name);

		if(entry.isDirectory()) result.push(...await markdownFiles(absolute));
		else if(entry.isFile() && entry.name.endsWith('.md')) result.push(absolute);
	}

	return result;
}

function article(metadata)
{
	const headline = String(metadata.title ?? metadata['display-title'] ?? '').trim();
	const description = String(metadata['description-meta'] ?? '').trim();

	return {
		'@context': 'https://schema.org'
		, '@type': 'TechArticle'
		, headline
		, ...(description ? {description} : {})
		, inLanguage: 'en'
		, isPartOf: {
			'@type': 'WebSite'
			, name: 'Phonics documentation'
		}
		, about: {
			'@type': 'SoftwareSourceCode'
			, name: 'Phonics'
			, codeRepository: 'https://github.com/seanmorris/php-wasm-engine'
		}
	};
}

/**
 * Add a useful default JSON-LD document to every staged Markdown page.
 *
 * Pages can provide a more specific `jsonld` value in their own frontmatter;
 * the site homepage does this for its WebSite and SoftwareSourceCode graph.
 *
 * @param {string} pagesDirectory Staged Markdown directory.
 * @returns {Promise<void>} Resolves after all staged pages have structured data.
 */
export async function addStructuredData(pagesDirectory)
{
	for(const absolute of await markdownFiles(pagesDirectory))
	{
		const source = await fs.readFile(absolute, 'utf8');
		const match = frontmatterPattern.exec(source);

		if(!match) throw new Error(`Markdown page has no frontmatter: ${absolute}`);

		const metadata = parse(match[1]) ?? {};

		if(Object.hasOwn(metadata, 'jsonld')) continue;

		const jsonld = article(metadata);

		if(!jsonld.headline) throw new Error(`Markdown page has no JSON-LD headline: ${absolute}`);

		const insert = stringify({jsonld}).trimEnd() + '\n';
		const boundary = match[0].lastIndexOf('---');
		const updated = source.slice(0, boundary) + insert + source.slice(boundary);

		await fs.writeFile(absolute, updated);
	}
}
