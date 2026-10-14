import fs from 'node:fs/promises';
import path from 'node:path';
import {root,engineFiles} from './files.mjs';
import {runtimeDirectory} from './runtime.mjs';
const files=await engineFiles();

for(const name of files)
{
	const dest=path.join(root, 'docs/dist/engine', name);
	await fs.mkdir(path.dirname(dest), {recursive: true});
	await fs.copyFile(path.join(root, name), dest);
}

await fs.writeFile(path.join(root, 'docs/dist/engine/engine-files.json'), JSON.stringify(files));
const runtime=await runtimeDirectory();
const manifest=JSON.parse(await fs.readFile(path.join(runtime, 'php8.4-sdl.manifest.json')));
await fs.mkdir(path.join(root, 'docs/dist/runtime'), {recursive: true});

for(const name of [...manifest.files.map(
	file=>file.path
)
, 'php8.4-sdl.manifest.json'
]) await fs.copyFile(
	path.join(runtime, name)
	, path.join(root, 'docs/dist/runtime', name)
);
let zlib=path.join(runtime, 'extensions/zlib');

try{await fs.access(zlib);}catch{zlib=path.resolve(runtime, '../zlib');}

await fs.mkdir(path.join(root, 'docs/dist/runtime/extensions/zlib'), {recursive: true});

for(const name of ['8.4.mjs','php8.4-zlib.so','libz.so','LICENSE','LICENSE-GPL','NOTICE','package.json']) await fs
	.copyFile(
		path.join(zlib, name)
		, path.join(root, 'docs/dist/runtime/extensions/zlib', name)
	);
console.log('Built docs/dist/ with PHP sources, assets and the selected runtime.');
