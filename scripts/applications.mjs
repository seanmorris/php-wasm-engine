import path from 'node:path';
import {pack} from '../cli/pack.mjs';
import {root} from './files.mjs';
export const games = ['sane-phonics','php-noid','php-tris','php-slalom','php-buster','doom-phonics'];

for(const game of games) console.log('Packed ' + await pack(path.join(root, 'packages', game), {cache: true}));
