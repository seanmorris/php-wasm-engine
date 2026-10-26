import fs from 'node:fs/promises';
import path from 'node:path';
import {root} from './files.mjs';

const [iwad, skulltag] = process.argv.slice(2);

if(!iwad || !skulltag)
throw new Error('Usage: node scripts/prepare-doom-stress.mjs /path/to/DOOM2.WAD /path/to/Skulltag-v097d5.wad');

const directory = path.join(root, 'packages/doom-phonics/demo/local-wads');
await fs.mkdir(directory, {recursive: true});
await fs.copyFile(iwad, path.join(directory, 'DOOM2.WAD'));
await fs.copyFile(skulltag, path.join(directory, 'SKULLTAG.WAD'));
console.log('/doom-phonics/?world=/engine/packages/doom-phonics/demo/skulltag-stress.phonics-world.json');
