import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {createHash} from 'node:crypto';

/** Resolve and verify the extension distributed with this host version. */
export async function pharExtension()
{
	const directory = path.dirname(fileURLToPath(import.meta.resolve('php-wasm-phar/package.json')));
	const lock = JSON.parse(await fs.readFile(new URL('../runtime-lock.json', import.meta.url))).extensions.phar;
	const metadata = JSON.parse(await fs.readFile(path.join(directory, 'package.json')));
	const bytes = await fs.readFile(path.join(directory, lock.file));

	if(metadata.version !== lock.version || createHash('sha256').update(bytes).digest('hex') !== lock.sha256)
	throw Error('Installed Phar extension does not match runtime-lock.json');

	return directory;
}
