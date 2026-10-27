import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const site = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const rendered = path.resolve(site, process.env.OUTPUT_DIR ?? '.cache/rendered');
const destination = path.resolve(site, process.env.SITE_DESTINATION ?? '../docs/dist');

const indexName = 'index.html';

function directoryUrl(value)
{
	if(/^(?:[a-z][a-z\d+.-]*:|\/\/|#)/i.test(value)) return value;

	const match = /^([^?#]*)([?#].*)?$/.exec(value);
	const pathname = match?.[1] ?? value;

	if(pathname !== indexName && !pathname.endsWith('/' + indexName)) return value;

	const directory = pathname.slice(0, -indexName.length) || './';

	return directory + (match?.[2] ?? '');
}

async function files(directory)
{
	const result = [];

	for(const entry of await fs.readdir(directory, {withFileTypes: true}))
	{
		const entryPath = path.join(directory, entry.name);

		if(entry.isDirectory()) result.push(...await files(entryPath));
		else result.push(entryPath);
	}

	return result;
}

async function publishDirectoryUrls()
{
	for(const file of await files(destination))
	{
		if(file.endsWith('.html'))
		{
			const source = await fs.readFile(file, 'utf8');

			const quoted = source.replace(
				/\bhref=(["'])(.*?)\1/g
				, (attribute, quote, value) => `href=${quote}${directoryUrl(value)}${quote}`
			);

			const clean = quoted.replace(/\bhref=([^\s"'=<>`]+)/g, (attribute, value) => `href=${directoryUrl(value)}`);

			if(clean !== source) await fs.writeFile(file, clean);

			continue;
		}

		if(file.endsWith('sitemap.xml'))
		{
			const source = await fs.readFile(file, 'utf8');

			const clean = source.replace(
				/(<loc>)([^<]+)(<\/loc>)/g
				, (line, open, value, close) => open + value.replace(/(^|\/)index\.html$/, '$1') + close
			);

			if(clean !== source) await fs.writeFile(file, clean);

			continue;
		}

		if(file.endsWith('llms.txt'))
		{
			const source = await fs.readFile(file, 'utf8');

			const clean = source.replace(
				/(\]\()([^)]+)(\))/g
				, (link, open, value, close) => open + directoryUrl(value) + close
			);

			if(clean !== source) await fs.writeFile(file, clean);
		}
	}

	const corpusPath = path.join(destination, '__smgen-assistant', 'corpus.json');
	const corpus = JSON.parse(await fs.readFile(corpusPath, 'utf8'));

	for(const source of corpus.sources ?? []) source.url = directoryUrl(source.url);

	await fs.writeFile(corpusPath, JSON.stringify(corpus));
}

// Root HTML belongs to SMGen. Remove retired chapters while preserving the
// nested game, preview, package, and runtime output from the complete build.
for(const entry of await fs.readdir(destination, {withFileTypes: true}).catch(() => []))
	if(entry.isFile() && entry.name.endsWith('.html')) await fs.rm(path.join(destination, entry.name));

// Nested documentation is wholly generated. Remove stale chapters and API
// pages, along with the retired standalone game pages. Games now mount lazily
// inside their documentation overviews.
await fs.rm(path.join(destination, 'games'), {recursive: true, force: true});

for(const directory of ['sane-phonics', 'php-noid', 'php-tris', 'php-slalom', 'php-buster', 'doom-phonics'])
	await fs.rm(path.join(destination, directory), {recursive: true, force: true});

await fs.cp(rendered, destination, {recursive: true});
await publishDirectoryUrls();
console.log(`Wrote static documentation to ${destination}.`);
