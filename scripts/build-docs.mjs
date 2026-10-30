import fs from 'node:fs/promises';
import {pharExtension} from '../cli/phar-extension.mjs';
import path from 'node:path';
import {pack} from '../cli/pack.mjs';
import {root} from './files.mjs';
import {runtimeDirectory} from './runtime.mjs';
const games = ['sane-phonics','php-noid','php-tris','php-slalom','php-buster','doom-phonics'];

for(const game of games) await pack(path.join(root, 'packages', game));

// Only the browser host and its imports run outside the application archives.
const browserFiles = ['browser/Phonics.mjs'
	, 'browser/ResourceStore.mjs'
	, 'browser/PackageGraph.mjs'
	, 'browser/ArchiveResources.mjs'
	, 'browser/LoadProgress.mjs'
	, 'browser/network/OAuthPopup.mjs'
	, 'browser/network/InviteLink.mjs'
];

// The documentation distribution carries its licensed, playable reference world. Keep these
// outside the package manifest so npm/Composer consumers never download demo WAD data.
const demoFiles = ['DOOM1.WAD'
	, 'PORTALS.WAD'
	, 'layered-navigation.html'
	, 'SEEDED.WAD'
	, 'seeded-navigation.html'
	, 'SKULLTAG-WEAPONS.WAD'
	, 'SKULLTAG_ACTORS.lmp'
	, 'SKULLTAG-WEAPONS.DEC'
	, 'SKULLTAG-WEAPONS.SNDINFO'
	, 'DBIGFONT.WAD'
	, 'SHAREWARE-LICENSE.txt'
];

const files = [
	...browserFiles
	, ...games.flatMap(game => ['phar','phar.integrity'].map(extension => `packages/${game}/dist/game.${extension}`))
	, ...demoFiles.map(name => 'packages/doom-phonics/demo/' + name)
	, 'packages/doom-phonics/DOOM1_OGG.wad'
];

// Rebuild this generated tree so incremental builds also remove retired loose packages.
const engine = path.join(root, 'docs/dist/engine');

for(const name of files) await fs.access(path.join(root, name));

await fs.rm(engine, {recursive: true, force: true});

for(const name of files)
{
	const destination = path.join(engine, name);
	await fs.mkdir(path.dirname(destination), {recursive: true});
	await fs.copyFile(path.join(root, name), destination);
}

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

const phar = await pharExtension();
await fs.mkdir(path.join(root, 'docs/dist/runtime/extensions/phar'), {recursive: true});

for(const name of ['php8.4-phar.so','LICENSE','LICENSE-GPL','NOTICE','package.json'])
await fs.copyFile(path.join(phar, name), path.join(root, 'docs/dist/runtime/extensions/phar', name));

console.log('Built the Phonics browser host, application PHARs, external game assets and runtime in docs/dist.');
