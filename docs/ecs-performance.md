# ECS performance

The registry stores object components under stable integer entity IDs. It supports class and interface lookups, boolean `Query::all()`, `any()`, and `not()` expressions, snapshot arrays, lifecycle events, and deferred structural commands.

## Measurements

At 10,000 entities, the current browser benchmark measured these sample medians:

| Workload | Median |
| --- | ---: |
| Common query after a structural change | 0.73 ms |
| Nested query after a structural change | 2.08 ms |
| Movement system, 4,000 matching entities | 11.73 ms |
| Add and remove 1,000 components | 10.39 ms |
| Replace 1,000 components | 11.15 ms |
| Create and destroy 1,000 entities | 29.72 ms |

Repeated queries without structural changes take about 4–7 µs with the result cache. Those measurements consume the snapshot's count and boundary IDs; they do not iterate every returned entity. The movement-system workload includes queries, component lookups, position updates, scheduler dispatch, and command flushing. It excludes physics and rendering.

The 10,000-entity native PHP fixture retains 6,874,152 bytes of registry storage. Six warmed query workloads retain another 543,752 bytes. The WASM build does not expose PHP allocator statistics, so memory figures are native-only.

These samples were recorded on one shared Linux host on 2026-10-12. Native PHP was 8.2.33 on 64-bit Linux; browser PHP was the pinned 8.4.1 SDL build on wasm32 in Chromium 152.0.7977.75. JIT was disabled. The browser benchmark runs the PHP workload in a headless browser without the game demos or development server. Results vary with hardware and host load; benchmark output includes every sample.

## Registry design

- Each type index stores entity membership counts. Counts preserve membership when several attached components share an interface or ancestor.
- A concrete class's parents and interfaces are computed once per registry.
- Boolean queries use key intersection, union, and difference operations. Intersections begin with their smallest positive set. Direct negations subtract indexed sets without constructing a world-sized complement.
- Composed queries cache ID snapshots. Cache keys include the complete query description, so an equivalent query can reuse a result while a changed public term produces a different key.
- Creating or destroying an entity and adding or removing a component invalidate query caches before lifecycle callbacks run. Changing fields inside a component does not change membership.

PHP arrays provide independent snapshots through copy-on-write. Editing a returned array does not edit the cache, and later registry changes do not edit an earlier snapshot. The cache retains at most 64 descriptions and 131,072 entity IDs in total. A result that would exceed either budget is returned without caching.

Query ordering is stable. An `all` query can seed from its smallest direct class index; ties retain the earlier candidate source. `any`, `not`, and queries without a direct seed use entity creation order. Internal set operations may reorder candidates, after which the registry restores the selected candidate order. `get()` prefers an exact concrete class, then the first attached component matching an interface or parent.

## Reproduce

Install development dependencies with `composer install` and `npm ci`. For the browser run, install Chromium with `npx playwright install chromium`; the runner also recognizes `/usr/bin/chromium`. Runtime setup downloads the pinned PHP/SDL package when it is absent.

```sh
php benchmarks/ecs.php --entities=10000 > ecs-native.json
node scripts/bench-ecs-browser.mjs --entities=10000 > ecs-browser.json
```

`composer bench:ecs` and `npm run bench:ecs:browser` are convenient aliases. Direct commands are preferable when redirecting JSON because package managers may print command banners.

Use `--samples=7` to set the sample count and `--iterations=40` to set the initial batch size. Each workload warms up, then grows its batch toward at least 20 ms to reduce timer quantization for cached queries, capped at 100,000 repetitions. The output records actual repetition counts. A 100,000-entity native run should use five samples and an initial batch of two.

Every fixture gives all entities positions, every second entity a velocity, three of every four a health component, every fifth a frozen marker, and every thousandth a rare marker. Initial query snapshots are checked against independently computed memberships. Every measured batch consumes and verifies a checksum. Mutation-query workloads add and remove an unrelated marker before each query to force a cache miss.

Timings are per invocation. `get_exact` and `get_interface` perform one lookup per entity. Lifecycle cases process 1,000 entities per invocation; `units_per_call` records that distinction. Correctness tests run in native PHP and the browser suite. Performance thresholds are intentionally separate from correctness assertions.
