import fs from 'node:fs/promises';
import path from 'node:path';
export const root = path.resolve(import.meta.dirname, '..');

export async function engineFiles()
{
	const result = ['autoload.php', 'legacy.php'];

	async function walk(dir)
	{
		for(const entry of await fs.readdir(path.join(root, dir), {withFileTypes: true}))
		{
			const name = dir + '/' + entry.name;

			if(entry.isDirectory() || (entry.isSymbolicLink() && (await fs.stat(path.join(root, name))).isDirectory()))
			await walk(name); else if(!name.endsWith('.mjs'))
			result.push(name);
		} }

	for(const directory of ['src','shaders','resources','examples','tests','packages/sane-phonics'])
	await walk(directory);

	return result;
}
