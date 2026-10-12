import fs from 'node:fs/promises';

const files = process.argv.slice(2);
if(files.length !== 2) throw new Error('Usage: node scripts/compare-ecs.mjs before.json after.json');
const [before, after] = await Promise.all(files.map(async file => JSON.parse(await fs.readFile(file, 'utf8'))));
for(const key of ['php', 'int_bytes', 'entities']) {
  if(before[key] !== after[key]) throw new Error(`Incompatible ${key}: ${before[key]} vs ${after[key]}`);
}
if(Object.keys(before.results).join() !== Object.keys(after.results).join()) {
  throw new Error('Workload lists differ');
}
console.log('| Workload | Before (µs) | After (µs) | Speedup |');
console.log('| --- | ---: | ---: | ---: |');
for(const [name, first] of Object.entries(before.results)) {
  const last = after.results[name];
  if(first.checksum !== last.checksum || first.units_per_call !== last.units_per_call) {
    throw new Error(`Workload result changed: ${name}`);
  }
  console.log(`| ${name} | ${first.median_us.toFixed(2)} | ${last.median_us.toFixed(2)} | ${(first.median_us / last.median_us).toFixed(2)}× |`);
}
if(before.registry_bytes !== null && after.registry_bytes !== null) {
  console.log(`\nRegistry bytes: ${before.registry_bytes} → ${after.registry_bytes}`);
  console.log(`Retained query bytes: ${before.query_cache_bytes} → ${after.query_cache_bytes}`);
}
