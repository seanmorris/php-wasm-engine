import './site.css';

const status=document.querySelector('#status');
const canvas=document.querySelector('canvas');
const start=document.querySelector('#start');
const stop=document.querySelector('#stop');
const demo=document.querySelector('#demo');
const fullscreen=document.querySelector('#fullscreen');
const fullscreenError=document.querySelector('#fullscreen-error');
fullscreen.hidden=!(document.fullscreenEnabled&&demo.requestFullscreen);
document.addEventListener('fullscreenchange',()=>{
  fullscreen.textContent=document.fullscreenElement===demo?'Exit fullscreen':'Fullscreen';
  canvas.focus({preventScroll:true});
});
fullscreen.addEventListener('click',async()=>{
  fullscreen.disabled=true;fullscreenError.hidden=true;
  try {
    if(document.fullscreenElement===demo) await document.exitFullscreen();
    else await demo.requestFullscreen();
  }
  catch(error) {
    fullscreenError.textContent='Fullscreen could not be changed. You can keep playing in the current view.';
    fullscreenError.hidden=false;
  }
  finally {fullscreen.disabled=false;}
});
const base=new URL('./engine/',document.baseURI);
const runtimeBase=new URL('./runtime/',document.baseURI);
let php;
export async function boot({scene='platformer',profile='runner',run=true,zlib=false,version='8.4'}={}) {
  if(!/^8\.[0-5]$/.test(version)) throw Error('Unsupported PHP version');
  if(php) await php.refresh();
  const {PhpSdl}=await import(/* webpackIgnore: true */ new URL('php'+version+'-sdl.mjs',runtimeBase));
  const sharedLibs=zlib?[await import(/* webpackIgnore: true */ new URL('extensions/zlib/'+version+'.mjs',runtimeBase))]:[];
  php=new PhpSdl({canvas,scene,profile,sharedLibs,persist:false,ini:'display_errors=0\nlog_errors=1\nerror_log=/dev/stderr'});
  window.enginePhp=php;
  php.addEventListener('output',event=>{status.textContent=event.detail.join('');});
  php.addEventListener('error',event=>{status.textContent=event.detail.join('');console.error(status.textContent);});
  const module=await php.binary;
  const response=await fetch(new URL('engine-files.json',base));
  const files=await response.json();
  await Promise.all(files.map(async path=>{
    const response=await fetch(new URL(path,base),{cache:'no-store'});if(!response.ok) throw Error('Asset '+path+': '+response.status);
    const full='/engine/'+path; module.FS.mkdirTree(full.slice(0,full.lastIndexOf('/')));
    module.FS.writeFile(full,new Uint8Array(await response.arrayBuffer()));
  }));
  if(run) {const code=await php.run('<?php require "/engine/examples/game.php";'); if(code!==0) throw Error('PHP returned '+code);}
  return php;
}
window.bootEngine=boot;
start?.addEventListener('click',async()=>{
  start.disabled=true; status.textContent='Loading PHP SDL…';
  try {await boot({scene:document.querySelector('#scene').value,profile:document.querySelector('#profile').value});stop.disabled=false;canvas.focus();}
  catch(error) {status.textContent=error.message;console.error(error);start.disabled=false;}
});
stop?.addEventListener('click',async()=>{await php?.refresh();stop.disabled=true;start.disabled=false;status.textContent='Stopped. Run to restart.';});
