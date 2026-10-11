import {test,expect} from '@playwright/test';
async function boot(page,zlib=false) {
  await page.goto('/');await page.waitForFunction(()=>typeof window.bootEngine==='function');
  await page.evaluate(options=>window.bootEngine({run:false,...options}),{zlib,version:process.env.ENGINE_PHP_VERSION??'8.4'});
}
async function run(page,source) {
  const result=await page.evaluate(async source=>{
    let output='',errors='';
    const out=e=>output+=e.detail.join(''),err=e=>errors+=e.detail.join('');
    enginePhp.addEventListener('output',out);enginePhp.addEventListener('error',err);
    try {const code=await enginePhp.run('<?php '+source);return {output,errors,code};}
    finally {enginePhp.removeEventListener('output',out);enginePhp.removeEventListener('error',err);}
  },source);
  expect(result.errors).toBe('');expect(result.code).toBe(0);return result.output;
}
test.afterEach(async({page})=>{await page.evaluate(()=>window.enginePhp?.refresh()).catch(()=>{});});
test('pure PHP regressions also pass inside the 32-bit WASM runtime',async({page})=>{
  await boot(page,true);expect(await run(page,'require "/engine/tests/run.php";')).toContain('0 failures');
});
test('real GPU renders all flip flags, instances layers, edits tile slices, and releases resources',async({page})=>{
  await boot(page);const output=await run(page,'require "/engine/tests/render.php";');
  const result=JSON.parse(output);
  expect(result.error).toBe(0);expect(result.initial.draws).toBe(1);expect(result.initial.instances).toBe(2);
  const expected=[[0,1,2,3],[2,3,0,1],[1,0,3,2],[3,2,1,0],[0,2,1,3],[1,3,0,2],[2,0,3,1],[3,1,2,0]];
  expect(result.quadrants).toEqual(expected);
  expect(result.clean.uploads).toBe(0);expect(result.dirty.uploads).toBe(1);
  expect(result.erased).toBe('000000ff');expect(result.culled.draws).toBe(0);
  expect(result.live).toEqual([false,false]);
});
test('isometric chunk seams match a CPU reference and sparse negative chunks stay culled',async({page})=>{
  await boot(page);
  const result=JSON.parse(await run(page,'require "/engine/tests/render-cases.php";'));
  expect(result.isoExact).toBe(true);
  expect(result.sparse.instances).toBe(1);
  expect(result.negative).toBe('ff0000ff');
  expect(result.rotated).toEqual(['ffff00ff','00ff00ff','0000ffff','ff0000ff']);
  expect(result.error).toBe(0);
});
for(const scene of ['platformer','infinite','isometric']) test(scene+' live PHP demo starts, responds to input and stops',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/');await page.locator('#scene').selectOption(scene);await page.locator('#start').click();
  await expect.poll(async()=>Number(await page.locator('canvas').getAttribute('data-frames')),{timeout:60000}).toBeGreaterThan(20);
  if(scene!=='isometric') {
    await expect.poll(async()=>JSON.parse(await page.locator('canvas').getAttribute('data-body')||'{}').grounded,{timeout:15000}).toBe(true);
    const before=JSON.parse(await page.locator('canvas').getAttribute('data-body'));
    await page.keyboard.down('ArrowRight');await page.waitForTimeout(700);await page.keyboard.up('ArrowRight');
    const after=JSON.parse(await page.locator('canvas').getAttribute('data-body'));expect(after.x).toBeGreaterThan(before.x+10);
    await page.keyboard.press('Space');await page.waitForTimeout(100);
  }
  await page.screenshot({path:'test-results/'+scene+'.png',fullPage:true});
  await page.locator('#stop').click();await expect(page.locator('#start')).toBeEnabled();
  expect(errors).toEqual([]);
});
