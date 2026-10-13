import fs from 'node:fs/promises';
import {existsSync} from 'node:fs';
import {chromium} from '@playwright/test';

// Source SVGs and character mappings were imported from moving-boxes-2's button-font CSS.
// Regenerate the committed PNG/TSJ with: node scripts/build-menu-icons.mjs
const root=new URL('../',import.meta.url);
const source=new URL('art/controllers/',root);
const entries=JSON.parse(await fs.readFile(new URL('manifest.json',source),'utf8'));
const images=await Promise.all(entries.map(async item=>({...item,
  image:'data:image/svg+xml;base64,'+(await fs.readFile(new URL(item.file,source))).toString('base64')
})));
const browser=await chromium.launch({
  ...(existsSync('/usr/bin/chromium')?{executablePath:'/usr/bin/chromium'}:{}),
  args:['--no-sandbox']
});
try {
  const page=await browser.newPage();
  const data=await page.evaluate(async entries=>{
    const canvas=document.createElement('canvas');
    canvas.width=384;canvas.height=Math.ceil(entries.length/12)*20;
    const context=canvas.getContext('2d');
    for(const [index,item] of entries.entries()) {
      const img=new Image();img.src=item.image;await img.decode();
      const scale=Math.min(item.width/img.width,16/img.height);
      const w=img.width*scale,h=img.height*scale;
      context.drawImage(img,(index%12)*32+(item.width-w)/2,Math.floor(index/12)*20+(16-h)/2,w,h);
    }
    return canvas.toDataURL('image/png').split(',')[1];
  },images);
  await fs.writeFile(new URL('examples/assets/buttons.png',root),Buffer.from(data,'base64'));
  const prop=(name,value)=>({name,type:typeof value==='number'?'int':typeof value==='boolean'?'bool':'string',value});
  const tiles=entries.map((entry,id)=>({id,properties:[
    prop('glyph.char',entry.char),prop('glyph.theme',entry.theme),
    prop('glyph.width',entry.width),prop('glyph.height',16),
    prop('glyph.advance',entry.width+2),prop('glyph.offsetY',-2),prop('glyph.tint',false)
  ]}));
  const set={type:'tileset',version:'1.10',tiledversion:'1.10.1',name:'buttons',
    image:'buttons.png',imagewidth:384,imageheight:Math.ceil(entries.length/12)*20,
    tilewidth:32,tileheight:20,columns:12,tilecount:Math.ceil(tiles.length/12)*12,margin:0,spacing:0,tiles};
  await fs.writeFile(new URL('examples/assets/buttons.tsj',root),JSON.stringify(set,null,2)+'\n');
  console.log(`Built ${tiles.length} annotated keyboard/controller glyphs.`);
} finally {await browser.close();}
