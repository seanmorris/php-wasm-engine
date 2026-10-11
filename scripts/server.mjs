import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import {engineFiles,root} from './files.mjs';
import {runtimeDirectory} from './runtime.mjs';
const runtime=await runtimeDirectory();
const types={'.mjs':'text/javascript','.json':'application/json','.html':'text/html','.css':'text/css','.wasm':'application/wasm','.so':'application/wasm','.png':'image/png'};
http.createServer(async(req,res)=>{
  try {
    let name=decodeURIComponent(new URL(req.url,'http://localhost').pathname);
    if(name==='/engine-files.json') {res.setHeader('Content-Type','application/json');res.end(JSON.stringify(await engineFiles()));return;}
    let base=root;
    if(name.startsWith('/runtime/extensions/zlib/')) {
      base=path.join(runtime,'extensions/zlib');try{await fs.access(base);}catch{base=path.resolve(runtime,'../zlib');}
      name=name.slice('/runtime/extensions/zlib'.length);
    }
    else if(name.startsWith('/runtime/')) {base=runtime;name=name.slice(8);}
    else if(name==='/') {res.writeHead(302,{Location:'/docs/index.html'});res.end();return;}
    if(name.split('/').some(part=>part.startsWith('.')||part==='node_modules'||part==='vendor')) throw Error('Hidden path');
    const file=path.resolve(base,'.'+name); if(!file.startsWith(base+path.sep)) throw Error('Invalid path');
    res.setHeader('Content-Type',types[path.extname(file)]||'application/octet-stream');
    res.setHeader('Cache-Control','no-store');
    const bytes=await fs.readFile(file);
    res.end(path.extname(file)==='.html'?bytes.toString().replace('data-static="true"','data-static="false"'):bytes);
  } catch(error) {res.statusCode=404;res.end('Not found');}
}).listen(Number(process.env.PORT||9130),'127.0.0.1',()=>console.log('PHP engine: http://localhost:'+(process.env.PORT||9130)));
