/** Fetch and mount files selected by PHP. No Tiled parsing or proximity policy lives here. */
export class AssetLoader {
  constructor(FS,base,{root='/engine/'}={}) {
    this.FS=FS;
    this.base=new URL(base);
    this.root=root;
    this.files=new Map();
    this.closed=false;
  }
  request(path,retry=false) {
    if(this.closed) return 'Asset loader is closed';
    if(!path.startsWith(this.root)||path.slice(this.root.length).split('/').some(part=>part==='..'||part==='.')) return 'Asset path is outside the mounted root';
    const relative=path.slice(this.root.length);
    const url=new URL(relative,this.base);
    if(url.origin!==this.base.origin||!url.pathname.startsWith(this.base.pathname)) return 'Asset URL is outside the source root';
    let entry=this.files.get(path);
    if(retry && entry && entry.state!==false) {this.release(path);entry=undefined;}
    if(!entry) {
      entry={state:false,controller:new AbortController()};
      this.files.set(path,entry);
      entry.promise=(async()=>{
        try {
          const response=await fetch(url,{signal:entry.controller.signal});
          if(!response.ok) throw Error('HTTP '+response.status+' '+url.pathname);
          const bytes=new Uint8Array(await response.arrayBuffer());
          if(this.closed||this.files.get(path)!==entry) return;
          this.FS.mkdirTree(path.slice(0,path.lastIndexOf('/')));
          this.FS.writeFile(path,bytes);
          entry.state=true;
        } catch(error) {
          if(this.files.get(path)===entry) entry.state=error.message||String(error);
        }
      })();
    }
    return entry.state;
  }
  async load(path) {
    const state=this.request(path);
    if(typeof state==='string') throw Error(state);
    const entry=this.files.get(path);
    await entry.promise;
    if(entry.state!==true) throw Error(entry.state||'Asset request was cancelled');
  }
  release(path) {
    const entry=this.files.get(path);
    if(!entry) return;
    this.files.delete(path);
    entry.controller.abort();
    if(entry.state===true) this.FS.unlink(path);
  }
  close() {
    if(this.closed) return;
    this.closed=true;
    for(const path of this.files.keys()) this.release(path);
  }
}
