# Phonics architecture

Phonics is a game-neutral runtime for worlds assembled in Tiled. The browser resolves a world's package graph, verifies executable files, prepares an isolated PHP filesystem, and starts one PHP/SDL session. PHP owns simulation and rendering. JavaScript owns asynchronous loading, host policy, mounting, and cleanup.

## World-first startup

`Phonics.loadWorld(worldUrl, options)` is the public browser entry point. It reads supported Tiled custom properties instead of a host-side game table:

- `phonics.import.<alias>` registers a package manifest in the session pallet.
- `phonics.game` selects a game export by `@alias/name` or a manifest URL and fragment.
- `phonics.start` selects the first map or exported asset.
- `phonics.modules` lists additional module exports as a JSON string.

The loader resolves the complete dependency graph before executing code. Package requirements, dependency cycles, export types, PHP namespace ownership, code origins, and integrity metadata are checked first. Every declared PHP dependency is fetched and mounted before its bootstrap runs, so PHP autoloading remains synchronous.

A ready session exposes its canonical world URL, abort signal, parsed world, package graph, asset adapter, starting map, PHP runtime, and `dispose()` method. Disposal aborts outstanding work, runs module cleanup in reverse registration order, releases scoped resources, and refreshes the PHP runtime.

## Resource identity and ownership

Every loaded resource retains a canonical source URL separately from its cache key or WASM mount. Relative references resolve against the resource that declares them: world to map, map to tileset, tileset to image, and manifest to code or assets. Root-relative paths, queries, and fragments use normal URL semantics.

`ResourceStore` deduplicates canonical URLs, retries failed requests when asked, verifies SHA-256 integrity, and reference-counts ownership through cancellable scopes. `SessionAssets` maps those URLs into isolated package mounts while preserving package-relative layouts. Shared bytes can outlive one scene; mutable map placement and ECS state cannot.

Tiled authoring caches use `phonics.source` and `phonics.source.property.<name>` metadata to retain canonical provenance. Browser and native PHP loaders honor those overrides. Relocating an authoring cache therefore does not change the published identity of its dependencies.

## PHP runtime boundary

Asset, ECS, scheduling, Tiled parsing, spatial indexing, fixed-step simulation, generic collision queries, controls, and most layout calculations are ordinary PHP. SDL input and WebGL rendering use the PHP/SDL runtime extensions. The browser bridge supplies request scheduling, asynchronous data transfer, DOM-backed textures, audio output, quick controls, and session lifecycle callbacks.

`Engine` polls input and renders on visible browser frames while `FixedStep` advances simulation at a stable interval. Catch-up is bounded; excess time is reported through `FixedStep::$droppedTime`. Paused, hidden, and lost-context frames reset timing and input baselines so stale movement does not enter the next update.

Graphics resources retain their PHP-side configuration across WebGL context loss. Owners restore shared devices, atlases, programs, buffers, textures, and renderers in dependency order before drawing resumes. Explicit `close()` methods release GPU and SDL handles before their parent device or runtime closes.

## Entities, modules, and systems

`Registry` stores stable integer entity IDs and object components. It normalizes canonical and compatibility class names at lookup boundaries. Queries accept a component class or a composed `Query::all()`, `any()`, and `not()` expression, and return snapshots that remain stable while callers iterate them.

The registry indexes concrete classes, parents, and interfaces. Structural mutations invalidate bounded query caches; changing fields inside an attached component does not. Deferred commands allow systems to add or remove entities safely during an update. Lifecycle listeners observe component additions and removals.

`Scheduler` runs named systems in dependency order and rejects missing dependencies and cycles. A package module receives `Phonics\Package\Context`, which owns its system and factory registrations. Modules register cleanup with `onClose()` rather than retaining global process state.

`ObjectSpawner` converts recognized Tiled object classes and pallet references into components returned by registered factories. The framework does not interpret game-specific entity names or properties.

## Tiled data and streaming

`Loader` reads worlds, maps, templates, external tilesets, images, inherited file properties, and inline tilesets while preserving each document's source identity. `Projection` converts supported tile coordinate systems into world coordinates. Layers retain visibility, tint, opacity, offsets, parallax, objects, chunks, properties, and revision state.

`World` indexes finite map bounds in a static bounds tree without loading every map. `WorldStream` requests visible maps and their dependency closure through a host callback, activates them only when all files are present, and reports entered, exited, and failed maps. Preload and retention margins let applications stage adjacent maps and delay eviction.

Streaming operates on complete maps. Applications own the entity and terrain state created for each resident map and decide whether to persist edits before eviction.

## Collision and spatial queries

Core physics uses `Pose`, bounds, masks, surface metadata, and generic geometry. `Terrain` stores indexed segments and polygons and exposes ray, circle, and continuously swept body casts. Candidate shapes come from spatial buckets; the solver tests exact geometry only for nearby candidates.

Collision hits report the first contact time, point, normal, source metadata, and optional removable-shape handle. Adding or removing a shape updates only affected index buckets. Casts are read-only, and geometry changes belong between simulation steps or in a caller-controlled impact callback.

Rules such as rail interpretation, directional layer switching, character adhesion, damage, brick behavior, and water gameplay belong to game packages. They compose the neutral casts rather than extending core with concrete game classes.

## Rendering

Tiled global IDs retain their unsigned flag bits and map to dense atlas indexes. `Atlas` packs source images and animation metadata. Tile layers use bounded texture arrays and instanced placement; changed layer revisions upload only their affected slices. `ImageRenderer` draws ordinary and repeating image layers in map order.

`SpriteRenderer` batches sprites that share an atlas. Each sprite carries position, origin, scale, rotation, flips, tint, opacity, animation time, crop, filtering, and outline state. `SpriteGroup` and `SpriteLayers` preserve entity-level painter order for composite artwork.

`WorldRenderer` combines resident map renderers in manifest order. Cameras define world viewports and projections. Generic post-process renderers own their framebuffer and texture resources and can nest when callers preserve the surrounding framebuffer.

## Input and UI

`SdlInput` maps keyboard and gamepad state into logical numbered buttons and action aliases. `Controls` preserves pressed and released edges across fixed updates. `Sequences` recognizes timed codes, chords, held modifiers, relative directions, and release steps without consuming gameplay controls.

Framework UI includes bitmap fonts, editable text buffers, menu input and rendering, cards, transient messages, generic world text actors, and DOM-backed texture modals. Browser modules receive shared audio and mute/fullscreen services through their activation context. Games provide their own menu content, HUD composition, screens, and accessibility labels.

## Compatibility boundary

The supported PHP namespace is `Phonics\`. Pre-1.0 `PhpWasmEngine\` names remain aliases and resolve consistently in mixed ECS lookups. Legacy browser boot functions and development example URLs adapt to `loadWorld()` without adding game selection logic to the host.

Compatibility adapters do not make the core depend on a game package. A core-only installation can load a separately packaged game when its manifest declares all code and assets it needs.
