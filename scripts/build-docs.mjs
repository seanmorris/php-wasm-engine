import fs from 'node:fs/promises';
import path from 'node:path';
import {root} from './files.mjs';
import {runtimeDirectory} from './runtime.mjs';
const files = new Set();

for(const directory of [''
	, 'packages/sane-phonics/'
	, 'packages/php-noid/'
	, 'packages/php-tris/'
	, 'packages/php-slalom/'
	, 'packages/php-buster/'
	, 'packages/doom-phonics/'
	, 'packages/phonics-matrix/'
	, 'packages/tiled-phonics/'
]){
	const manifest = JSON.parse(await fs.readFile(path.join(root, directory, 'phonics.json')));

	for(const file of ['phonics.json'
		, ...(manifest.php?.files ?? [])
		, ...(manifest.browser?.files
		?? []
		)
		, ...(manifest.assets ?? [])
	])
		files.add(directory + file);
}

// Preserve the deprecated /engine/examples entry points and assets in the demo distribution.
for(const file of await fs.readdir(path.join(root, 'examples')))
	if(file.endsWith('.php') && !file.startsWith('generate-')) files.add('examples/' + file);

for(const file of await fs.readdir(path.join(root, 'examples/assets'))) files.add('examples/assets/' + file);

for(const name of files)
{
	const destination = path.join(root, 'docs/dist/engine', name);
	await fs.mkdir(path.dirname(destination), {recursive: true});
	await fs.copyFile(path.join(root, name), destination);
}

// The documentation distribution carries its licensed, playable reference world. Keep these
// outside the package manifest so npm/Composer consumers never download demo WAD data.
const demoFiles = ['episode-one.phonics-world.json'
	, 'episode-one-online.phonics-world.json'
	, 'skulltag-stress.phonics-world.json'
	, 'portal-demo.phonics-world.json'
	, 'portal-rooms.phonics-world.json'
	, 'camera-mirrors.phonics-world.json'

	, 'DOOM1.WAD'
	, 'PORTALS.WAD'
	, 'SKULLTAG-WEAPONS.WAD'
	, 'SKULLTAG_ACTORS.lmp'
	, 'SKULLTAG-WEAPONS.DEC'
	, 'SKULLTAG-WEAPONS.SNDINFO'
	, 'DBIGFONT.WAD'
	, 'SHAREWARE-LICENSE.txt'
];

for(const name of demoFiles)
{
	const source = path.join(root, 'packages/doom-phonics/demo', name);
	const destination = path.join(root, 'docs/dist/engine/packages/doom-phonics/demo', name);
	await fs.mkdir(path.dirname(destination), {recursive: true});
	await fs.copyFile(source, destination);
}

await fs.copyFile(
	path.join(root, 'packages/doom-phonics/DOOM1_OGG.wad')
	, path.join(root, 'docs/dist/engine/packages/doom-phonics/DOOM1_OGG.wad')
);

await fs.writeFile(
	path.join(root, 'docs/dist/engine/engine-files.json')
	, JSON.stringify([...files].filter(file => !file.endsWith('.mjs')))
);

const runtime = await runtimeDirectory();
const manifest = JSON.parse(await fs.readFile(path.join(runtime, 'php8.4-sdl.manifest.json')));
await fs.mkdir(path.join(root, 'docs/dist/runtime'), {recursive: true});

for(const name of [...manifest.files.map(file => file.path), 'php8.4-sdl.manifest.json'])
	await fs.copyFile(path.join(runtime, name), path.join(root, 'docs/dist/runtime', name));
let zlib = path.join(runtime, 'extensions/zlib');

try { await fs.access(zlib); } catch { zlib = path.resolve(runtime, '../zlib'); }

await fs.mkdir(path.join(root, 'docs/dist/runtime/extensions/zlib'), {recursive: true});

for(const name of ['8.4.mjs','php8.4-zlib.so','libz.so','LICENSE','LICENSE-GPL','NOTICE','package.json'])
	await fs.copyFile(path.join(zlib, name), path.join(root, 'docs/dist/runtime/extensions/zlib', name));
console.log('Built the Phonics preview, embedded game documentation, package manifests and runtime in docs/dist.');
