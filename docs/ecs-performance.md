# ECS performance

The registry uses the same object components, integer entity IDs, `Query::all/any/not`
descriptions, snapshot arrays, lifecycle events, and deferred commands as before.
The changes are private storage and query evaluation details.

## Measurements

At 10,000 entities, the browser benchmark measured:

| Workload | Before | After | Speedup |
| --- | ---: | ---: | ---: |
| Common query after a structural change | 23.15 ms | 0.73 ms | 31.7× |
| Nested query after a structural change | 60.47 ms | 2.08 ms | 29.1× |
| Movement system, 4,000 matching entities | 40.34 ms | 11.73 ms | 3.4× |
| Add and remove 1,000 components | 13.08 ms | 10.39 ms | 1.3× |
| Replace 1,000 components | 14.96 ms | 11.15 ms | 1.3× |
| Create and destroy 1,000 entities | 35.07 ms | 29.72 ms | 1.2× |

Repeated queries without structural changes take about 4–7 µs with the result cache.
Those measurements consume the snapshot's count and boundary IDs. They do not include
iterating every returned entity. The movement-system measurement includes queries,
component lookups, position updates, scheduler dispatch, and command flushing, but no
physics or rendering.

Native PHP registry storage fell from 19,832,208 to 6,874,152 bytes, a 65% reduction.
The six warmed query workloads retained another 543,752 bytes. The WASM build reports
no PHP allocator statistics, so these memory figures are from native PHP only.

These are sample medians from one shared Linux host, measured on 2026-10-12.
Native PHP was 8.2.33 on 64-bit Linux; browser PHP was the pinned 8.4.1 SDL build
on wasm32, in Chromium 152.0.7977.75. JIT was disabled. The browser measurement runs
the PHP workload in a real headless browser, without the demo or development server.
Results vary with hardware and load; the JSON files include every sample.

The baseline is engine commit `a83916aac62411d63d7943222acac333fb7434d1`.
[Raw measurements](../benchmarks/results/) cover 1,000 and 10,000 entities in both
runtimes, plus 100,000 entities in native PHP. Compare before and after within the
same runtime; native and WASM use different PHP versions.

## Changes adapted from SOOP

[SOOP](https://github.com/seanmorris/SoopECS)'s `src/EntityComponentManager.mjs` uses
component-to-entity sets, smallest-set intersections, structural query keys, and
mutation-based cache invalidation. Analysis used the local SOOP working tree on
2026-10-12, including its local edits, with base commit
`241afbd286510865f1a2e9448fa805a211a82ca4`. The PHP registry applies those ideas as follows:

- Each type index stores an entity membership count, replacing the nested map of
  concrete class names. Counts preserve membership when several components share
  an interface or ancestor.
- A concrete class's ancestors and interfaces are computed once per registry.
- Boolean queries use PHP's key intersection, union, and difference operations.
  Intersections start with their smallest positive set. Direct negations subtract
  sets without constructing a complement containing most of the world.
- Composed queries cache their ID arrays. Keys include the current query description,
  so rebuilding an equivalent query can reuse a result, while editing public query
  fields or a nested query changes its key.
- Creating an entity, adding or removing a component, and destroying an entity
  invalidate cached queries before lifecycle callbacks observe the change.
  Changing a component's values does not change membership.

PHP arrays provide independent snapshots through copy-on-write. Editing a returned
array does not edit the cache, and later registry changes do not edit an earlier
snapshot. The cache retains at most 64 descriptions and 131,072 entity IDs in total;
it clears when a new result would exceed either budget. Larger results are returned
without caching. Simple class-string queries still take snapshots directly from
the type index.

Query ordering is preserved. An `all` query uses its smallest direct class term
only when that index is smaller than the entity collection; ties retain the earlier
candidate source. `any`, `not`, and queries without such a seed use creation order.
Internal set evaluation is reordered for speed, then the original candidate order
is applied to the result. `get()` still prefers an exact concrete class, then the
first attached matching component. Public signatures and properties are unchanged.

SOOP's `src/EntityComponentTrie.mjs` stores component subsets eagerly.
A component set can have exponentially many
subsets, increasing insertion work and retained storage. This implementation uses
bounded query caching instead. SOOP's sorted-array indexes and singleton/proxy APIs
were also left out: PHP hash indexes already provide membership lookup, and those
APIs are outside this optimization.

## Reproduce

Install the development dependencies with `composer install` and `npm ci`.
For the browser run, install Chromium with `npx playwright install chromium`;
the runner also recognizes `/usr/bin/chromium`. The existing runtime setup downloads
the pinned package if it is absent.

```sh
php benchmarks/ecs.php --entities=10000 > after-native.json
node scripts/bench-ecs-browser.mjs --entities=10000 > after-browser.json
```

`composer bench:ecs` and `npm run bench:ecs:browser` are convenient aliases. Direct
commands are preferable when redirecting JSON because package managers may print
their own command banners.

To compare the same harness against the old implementation:

```sh
git worktree add --detach /tmp/engine-ecs-before a83916a
php benchmarks/ecs.php --engine-root=/tmp/engine-ecs-before > before-native.json
node scripts/bench-ecs-browser.mjs --engine-root=/tmp/engine-ecs-before > before-browser.json
node scripts/compare-ecs.mjs before-native.json after-native.json
node scripts/compare-ecs.mjs before-browser.json after-browser.json
```

Use `--samples=7` to set the sample count and `--iterations=40` to set the initial
batch size. Each workload is warmed, then its batch grows toward at least 20 ms
to reduce timer quantization for cached queries, capped at 100,000 repetitions.
The recorded results include the actual repetition counts. The 100,000-entity
native runs use five samples and an initial batch of two.

Every scene has positions on all entities, velocities on every second entity,
health on three of every four, frozen markers on every fifth, and rare markers
on every thousandth. Initial query arrays are checked against independent expected
memberships. Every measured batch consumes and verifies a checksum. Mutation-query
workloads add and remove an unrelated marker before each query, forcing a cache miss.

Timings are per invocation. `get_exact` and `get_interface` each perform one lookup
per entity. Lifecycle cases process 1,000 entities per invocation. `units_per_call`
in each result records this distinction. Correctness tests run on native PHP and
inside the browser test suite; performance thresholds are not CI assertions.
