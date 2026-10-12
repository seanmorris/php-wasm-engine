import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync,readFileSync,readdirSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {basename,dirname,join,resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';

const assets=fileURLToPath(new URL('../examples/assets/',import.meta.url));
const read=path=>JSON.parse(readFileSync(path,'utf8'));
const properties=value=>Object.fromEntries((value??[]).map(p=>[p.name,p.value]));
const sourcePath=(file,relative)=>resolve(dirname(file),relative);

function roundTrip(file,kind,check) {
  const directory=mkdtempSync(join(tmpdir(),'php-engine-tiled-'));
  const exported=join(directory,basename(file));
  try {
    const result=spawnSync(process.env.TILED_BIN??'tiled',[
      '--export-'+kind,'json',file,exported
    ],{encoding:'utf8',timeout:30000,env:{...process.env,
      QT_QPA_PLATFORM:process.env.QT_QPA_PLATFORM??(process.env.DISPLAY?'xcb':'offscreen')
    }});
    assert.ifError(result.error);
    assert.equal(result.status,0,result.stderr||result.stdout);
    check(read(file),read(exported),exported);
  } finally {rmSync(directory,{recursive:true,force:true});}
}

// Tiled can resize/repartition infinite chunks when saving. Compare occupied
// cells at their coordinates instead of expecting the same chunk boundaries.
function cells(layer) {
  const result=new Map();
  for(const chunk of layer.chunks??[layer]) {
    assert.ok(Array.isArray(chunk.data));
    assert.equal(chunk.data.length,chunk.width*chunk.height);
    chunk.data.forEach((gid,index)=>{
      if(gid) result.set(`${(chunk.x??0)+index%chunk.width},${(chunk.y??0)+Math.floor(index/chunk.width)}`,gid);
    });
  }
  return result;
}

function objects(before,after) {
  assert.equal(after.length,before.length);
  before.forEach((object,index)=>{
    const imported=after[index];
    assert.equal(imported.visible,object.visible??true);
    assert.equal(imported.type??imported.class??'',object.type??object.class??'');
    assert.deepEqual(properties(imported.properties),properties(object.properties));
    for(const key of ['id','name','gid','x','y','width','height','rotation','polygon','polyline','point']) {
      if(key in object) assert.deepEqual(imported[key],object[key],key);
    }
  });
}

for(const name of readdirSync(assets).filter(name=>name.endsWith('.tmj'))) {
  test('Tiled imports '+name+' with visible terrain and intact layers',()=>{
    const file=join(assets,name);
    roundTrip(file,'map',(before,after,exported)=>{
      assert.equal(after.orientation,before.orientation);
      assert.equal(after.infinite,before.infinite??false);
      assert.equal(after.layers.length,before.layers.length);
      let visibleTerrain=0;
      before.layers.forEach((layer,index)=>{
        const imported=after.layers[index];
        for(const key of ['id','name','type']) assert.equal(imported[key],layer[key]);
        assert.equal(imported.visible,layer.visible??true,layer.name+' visibility');
        assert.equal(imported.opacity,layer.opacity??1,layer.name+' opacity');
        for(const key of ['offsetx','offsety']) assert.equal(imported[key]??0,layer[key]??0);
        assert.deepEqual(properties(imported.properties),properties(layer.properties));
        if(layer.type==='tilelayer') {
          assert.deepEqual(cells(imported),cells(layer),layer.name+' cells');
          if(imported.visible&&imported.opacity>0) visibleTerrain+=cells(imported).size;
        }
        if(layer.type==='imagelayer') {
          assert.equal(sourcePath(exported,imported.image),sourcePath(file,layer.image));
          for(const key of ['repeatx','repeaty']) assert.equal(imported[key]??false,layer[key]??false);
          assert.equal(imported.parallaxx??1,layer.parallaxx??1);
          const anchored='from' in properties(layer.properties);
          assert.equal(imported.parallaxy??1,layer.parallaxy??(anchored?0:1),layer.name+' vertical parallax');
        }
        if(layer.type==='objectgroup') objects(layer.objects,imported.objects);
      });
      assert.ok(visibleTerrain>0,'the editor must have visible terrain');
      assert.equal(after.tilesets.length,before.tilesets.length);
      before.tilesets.forEach((set,index)=>{
        assert.equal(after.tilesets[index].firstgid,set.firstgid);
        assert.equal(sourcePath(exported,after.tilesets[index].source),sourcePath(file,set.source));
      });
    });
  });
}

for(const name of readdirSync(assets).filter(name=>name.endsWith('.tsj'))) {
  test('Tiled preserves '+name+' images, animations and collision shapes',()=>{
    const file=join(assets,name);
    roundTrip(file,'tileset',(before,after,exported)=>{
      for(const key of ['tilewidth','tileheight','tilecount','columns','imagewidth','imageheight']) {
        assert.equal(after[key],before[key],key);
      }
      assert.equal(sourcePath(exported,after.image),sourcePath(file,before.image));
      for(const tile of before.tiles??[]) {
        const imported=after.tiles.find(candidate=>candidate.id===tile.id);
        assert.ok(imported);
        assert.equal(imported.type??imported.class??'',tile.type??tile.class??'');
        assert.deepEqual(properties(imported.properties),properties(tile.properties));
        assert.deepEqual(imported.animation,tile.animation);
        if(tile.objectgroup) objects(tile.objectgroup.objects,imported.objectgroup.objects);
      }
    });
  });
}
