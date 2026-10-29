# ECS performance

The registry stores object components under stable integer entity IDs. It supports class and interface lookups, boolean `Query::all()`, `any()`, and `not()` expressions, snapshot arrays, lifecycle events, and deferred structural commands.

## Measurements

At 10,000 entities, two fresh browser runs against the current ECS produced these pooled sample medians:

| Workload | Median |
| --- | ---: |
| Cached direct type query | 2.59 µs |
| Direct type query after a structural change | 85.80 µs |
| Common query after a structural change | 0.73 ms |
| Nested query after a structural change | 1.93 ms |
| 10,000 exact component lookups | 8.72 ms |
| 10,000 interface component lookups | 16.01 ms |
| 10,000 sparse membership checks | 6.94 ms |
| Movement system, 4,000 matching entities | 7.35 ms |
| Add and remove 1,000 components | 7.52 ms |
| Replace 1,000 components | 2.41 ms |
| Create and destroy 1,000 entities | 10.95 ms |

Repeated direct and composed queries without structural changes take about 2.6–8.0 µs with the result cache. Those measurements consume the snapshot's count and boundary IDs; they do not iterate every returned entity. A direct type query forced cold by a structural change measured about 86 µs. The sparse membership workload checks all 10,000 entities for a component present on ten of them. The movement-system workload includes queries, component lookups, position updates, scheduler dispatch, per-system timing, and command flushing. It excludes physics and rendering.

The earlier exact-name fast paths avoid alias reflection and hierarchy scans for canonical component names and query terms. Compared with the [first](../benchmarks/results/ecs-browser-10000-2026-10-29-before-fast-path-run-1.json) and [second](../benchmarks/results/ecs-browser-10000-2026-10-29-before-fast-path-run-2.json) original records, exact lookup fell from 21.17 ms to 9.23 ms per 10,000 calls and the movement workload fell from 15.93 ms to 7.21 ms. Interface lookup also improved because canonical hierarchy names can be recognized directly from the type index.

The earlier query pass caches direct type snapshots, retains one local component array per lookup, and canonicalizes membership queries only when their raw type index is absent. Against the [first](../benchmarks/results/ecs-browser-10000-2026-10-29-before-snapshot-cache-run-1.json) and [second](../benchmarks/results/ecs-browser-10000-2026-10-29-before-snapshot-cache-run-2.json) preceding records, repeated direct queries fell from 72.71 µs to 2.65 µs, exact lookup fell from 10.01 ms to 9.23 ms, and 10,000 mostly negative membership checks fell from 12.86 ms to 7.02 ms.

The first lifecycle pass caches component hierarchies, detects lifecycle observers once when they are registered, and consolidates index and query-cache work when no callback can observe intermediate state. Replacing the sole component of a concrete type updates its stored object without rebuilding unchanged memberships. Against the [first](../benchmarks/results/ecs-browser-10000-2026-10-29-before-lifecycle-fast-path-run-1.json) and [second](../benchmarks/results/ecs-browser-10000-2026-10-29-before-lifecycle-fast-path-run-2.json) immediately preceding records, add/remove fell from 11.04 ms to 7.89 ms, replacement from 11.64 ms to 2.48 ms, and create/destroy from 29.41 ms to 18.70 ms. Those are reductions of 28.5%, 78.7%, and 36.4%.

The entity batching pass builds each initial component table once and retires whole entities without per-component storage changes or index-count decrements. Against fresh [first](../benchmarks/results/ecs-browser-10000-2026-10-29-before-entity-batching-run-1.json) and [second](../benchmarks/results/ecs-browser-10000-2026-10-29-before-entity-batching-run-2.json) baseline runs at `75e6eb4`, create/destroy fell from 18.43 ms to 10.95 ms (40.6%). Creation alone fell 25.1% and destruction 57.0%. Native create/destroy fell from 1.80 ms to 1.25 ms (30.2%) against the corresponding [first](../benchmarks/results/ecs-native-10000-2026-10-29-before-entity-batching-run-1.json) and [second](../benchmarks/results/ecs-native-10000-2026-10-29-before-entity-batching-run-2.json) native baselines.

The split browser diagnostics measure add at 3.57 ms, remove at 3.32 ms, create at 8.16 ms, and destroy at 3.27 ms per 1,000 entities. Their fixture construction occurs outside the timer. With no-op added and removed listeners installed, the combined add/remove, replacement, and create/destroy cases measure 11.67 ms, 12.74 ms, and 33.36 ms. This observed path retains event order and fresh-query visibility inside every callback.

The matching native PHP fixture retains about 6.91 MB of registry storage. Six warmed query workloads, including the 10,000-ID direct type snapshot, retain another 810,112 bytes. The WASM build does not expose PHP allocator statistics, so memory figures are native-only.

The samples were recorded on one shared Linux host on 2026-10-29. Browser figures pool fourteen samples from two independent headless-browser runs; the [first](../benchmarks/results/ecs-browser-10000-2026-10-29-entity-batching-run-1.json) and [second](../benchmarks/results/ecs-browser-10000-2026-10-29-entity-batching-run-2.json) records retain every sample. The [first native record](../benchmarks/results/ecs-native-10000-2026-10-29-entity-batching-run-1.json) and [second native record](../benchmarks/results/ecs-native-10000-2026-10-29-entity-batching-run-2.json) also contain seven samples each. Native PHP was 8.2.33 on 64-bit Linux; browser PHP was the pinned 8.4.1 SDL build on wasm32 in Chromium 152.0.7977.75. JIT was disabled. The browser benchmark runs the PHP workload in a headless browser without the game demos or development server. Results vary with hardware and host load.

## Registry design

- Each type index stores entity membership counts. Counts preserve membership when several attached components share an interface or ancestor.
- A concrete class's parents and interfaces are computed once per registry.
- Creation without lifecycle listeners builds the component table locally and installs it once, then indexes the cached hierarchies. Repeated concrete types retain sequential attachment so replacing a parent still removes earlier matching subclasses.
- Destruction without removal listeners retires the entity table once and erases its hierarchy memberships directly. Shared counts need no decrements because every component is leaving. Components remain referenced until indexes and query snapshots are cleared, so destructors see a fully retired entity. No persistent per-entity metadata is added.
- Exact concrete lookups, liveness checks, alias fallback, and hierarchy scans share one local component array. Parent and interface lookups use the type index to recognize canonical names, while aliases and case variants fall back to normalization.
- Boolean queries use key intersection, union, and difference operations. Intersections begin with their smallest positive set. A `not` term directly inside an `all` query subtracts indexed sets without constructing a world-sized complement. A top-level `not` query, or a `not` nested in `any` or another `not`, starts from every live entity.
- Direct type and composed queries share the ID snapshot cache. Direct keys use the resolved canonical type. Composed keys include the complete query description, so an equivalent query can reuse a result while a changed public term produces a different key.
- Creating or destroying an entity and adding or removing a component invalidate query caches before lifecycle callbacks run. When no applicable listener exists, an operation can consolidate invalidation and index maintenance because no caller can observe its intermediate state. Changing fields inside a component does not change membership.

PHP arrays provide independent snapshots through copy-on-write. Editing a returned array does not edit the cache, and later registry changes do not edit an earlier snapshot. The cache retains at most 64 descriptions and 131,072 entity IDs in total. A single result larger than 131,072 IDs is returned without caching. When adding a result would exceed either budget, the registry clears the whole cache and then caches the new result.

Query ordering is stable. An `all` query can seed from its smallest direct class index; ties retain the earlier candidate source. `any`, `not`, and queries without a direct seed use entity creation order. Internal set operations may reorder candidates, after which the registry restores the selected candidate order. `get()` prefers an exact concrete class, then the first attached component matching an interface or parent.

## Reproduce

Install development dependencies with `composer install` and `npm ci`. For the browser run, install Chromium with `npx playwright install chromium`; the runner also recognizes `/usr/bin/chromium`. Runtime setup downloads the pinned PHP/SDL package when it is absent.

```sh
php benchmarks/ecs.php --entities=10000 > ecs-native.json
node scripts/bench-ecs-browser.mjs --entities=10000 > ecs-browser.json
```

`composer bench:ecs` and `npm run bench:ecs:browser` are convenient aliases. Direct commands are preferable when redirecting JSON because package managers may print command banners.

Use `--samples=7` to set the sample count and `--iterations=40` to set the initial batch size. Each workload warms up, then grows its batch toward at least 20 ms to reduce timer quantization for cached queries, capped at 100,000 repetitions. The output records actual repetition counts. A 100,000-entity native run should use five samples and an initial batch of two.

Every fixture gives all entities positions, every second entity a velocity, three of every four a health component, every fifth a frozen marker, and every thousandth a rare marker. Initial query snapshots are checked against independently computed memberships. Every measured batch consumes and verifies a checksum. Mutation-query workloads add and remove an unrelated marker before each query to force a cache miss. Split lifecycle cases prepare a fresh fixture before each invocation, start their timer after preparation, and accumulate only the requested phase.

Timings are per invocation. `get_exact` and `get_interface` perform one lookup per entity. Lifecycle cases process 1,000 entities per invocation; `units_per_call` records that distinction. Correctness tests run in native PHP and the browser suite. Performance thresholds are intentionally separate from correctness assertions.
