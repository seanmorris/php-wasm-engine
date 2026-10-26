import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const site = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const rendered = path.resolve(site, process.env.OUTPUT_DIR ?? '.cache/rendered');
const destination = path.resolve(site, process.env.SITE_DESTINATION ?? '../docs/dist');

// Root HTML belongs to SMGen. Remove retired chapters while preserving the
// nested game, preview, package, and runtime output from the complete build.
for(const entry of await fs.readdir(destination, {withFileTypes: true}).catch(() => []))
	if(entry.isFile() && entry.name.endsWith('.html')) await fs.rm(path.join(destination, entry.name));

// Nested documentation is wholly generated. Remove stale chapters and API
// pages while preserving the independent runtime directories beside it.
await fs.rm(path.join(destination, 'games'), {recursive: true, force: true});

await fs.cp(rendered, destination, {recursive: true});
console.log(`Wrote static documentation to ${destination}.`);
