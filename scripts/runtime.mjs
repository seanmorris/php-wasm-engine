import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import {root} from './files.mjs';
export async function runtimeDirectory() {
  const explicit=process.env.PHP_SDL_ROOT;
  const candidates=explicit?[path.resolve(explicit)]:[path.join(root,'node_modules/php-sdl-wasm'),path.join(root,'.runtime')];
  for(const dir of candidates) { try { await fs.access(path.join(dir,'php8.4-sdl.manifest.json')); return dir; } catch {} }
  if(explicit) throw Error('PHP_SDL_ROOT must name a built php-sdl-wasm package directory: '+explicit);
  let lock; try { lock=JSON.parse(await fs.readFile(path.join(root,'runtime-lock.json'))); } catch { throw Error('Set PHP_SDL_ROOT to your php-sdl-wasm package directory. No preview runtime lock is available.'); }
  console.log('Downloading the pinned php-sdl-wasm demo runtime…');
  const response=await fetch(lock.url); if(!response.ok) throw Error('Runtime download: '+response.status);
  const bytes=Buffer.from(await response.arrayBuffer());
  if(createHash('sha256').update(bytes).digest('hex')!==lock.sha256) throw Error('Runtime archive checksum mismatch');
  const archive=path.join(root,'.runtime-download.tgz'); const staging=path.join(root,'.runtime-staging');
  await fs.writeFile(archive,bytes); await fs.mkdir(staging,{recursive:true});
  try {
    const entries=execFileSync('tar',['-tzf',archive],{encoding:'utf8'}).split('\n').filter(Boolean);
    if(entries.some(name=>name.startsWith('/')||name.split('/').includes('..'))) throw Error('Unsafe runtime archive');
    execFileSync('tar',['-xzf',archive,'-C',staging]);
    const manifest=JSON.parse(await fs.readFile(path.join(staging,'php8.4-sdl.manifest.json')));
    for(const file of manifest.files) { const data=await fs.readFile(path.join(staging,file.path)); if(createHash('sha256').update(data).digest('hex')!==file.sha256) throw Error('Runtime checksum mismatch: '+file.path); }
    await fs.rename(staging,path.join(root,'.runtime'));
  } finally { await fs.rm(archive,{force:true}); await fs.rm(staging,{recursive:true,force:true}); }
  return path.join(root,'.runtime');
}
if(process.argv[1]===import.meta.filename) console.log(await runtimeDirectory());
