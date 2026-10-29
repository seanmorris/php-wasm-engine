# Phonics architecture

Phonics is a game-neutral runtime for worlds assembled in Tiled or opened by content adapters. The browser resolves a world's package graph, verifies executable files, prepares an isolated PHP filesystem, and starts one PHP/SDL session. PHP owns simulation and rendering. JavaScript owns asynchronous loading, host policy, mounting, and cleanup.

## World-first startup

`Phonics.loadWorld(worldUrl, options)` is the public browser entry point. The URL names one of three launchable documents, and the host keeps no game table:

- A **`phonics-world` document** (`"type": "phonics-world"`, `"schemaVersion": 1`) is the primary format.
- A **package manifest** (`phonics.json`) launches the world it declares: `world` is either a URL to a world document or an inline `phonics-world` object. The manifest's package joins the session's package graph with the core package.
- A **Tiled `.world` file** with `phonics.*` custom properties is the legacy format. It remains supported and is described below.

A `phonics-world` document declares:

- `imports` — aliases mapped to package manifest URLs, so references such as `@doom/game` resolve.
- `game` (required) — the export reference of a `game` export.
- `modules` — optional array of additional module export references.
- `sources` (required) — content sources keyed by ID. Each names an `adapter` export and lists `resources`, either URLs with optional `integrity` or `slot` placements filled from `resourceSlots`.
- `resourceSlots` — world-owned slots for files the player or a match supplies, with acceptance requirements and an optional `defaultUrl`.
- `bindings` — `system-set` exports activated for matching scopes. Each binding has an `id`, a `use` reference, an `on` selector (`source`, `kind`, `id`, `tags`), `options`, and `after` dependencies.
- `start` (required) — the first `{source, scope}` to activate.
- `transitions` — declared moves between `{source, scope}` endpoints.
- `view` — optional positive integer `width` and `height`.
- `network` — optional multiplayer declaration, described under [Networking](#networking).
- `init` — an optional object passed to the game unchanged. Its meaning belongs to the game.

The loader validates the document, then resolves the complete dependency graph before executing code. Package requirements, dependency cycles, export types, PHP namespace ownership, code origins, and integrity metadata are checked first. Every declared PHP dependency is fetched and mounted before its bootstrap runs, so PHP autoloading remains synchronous. The validated world reaches PHP through the session's `phonicsWorld` bridge value.

### Legacy Tiled worlds

A Tiled `.world` file is recognized when it has a string `phonics.game` property. Its custom properties replace the `phonics-world` fields:

- `phonics.import.<alias>` registers a package manifest in the session pallet.
- `phonics.game` selects a game export by `@alias/name` or a manifest URL and fragment.
- `phonics.start` selects the first map or exported asset; without it, the first map in the world is used.
- `phonics.modules` lists additional module exports as a JSON string.

A ready session exposes its canonical world URL, abort signal, parsed world, package graph, asset adapter, starting map or `{source, scope}` selector, PHP runtime, and `dispose()` method. Disposal aborts outstanding work, runs module cleanup in reverse registration order, releases scoped resources, and refreshes the PHP runtime. The [JavaScript API](api-js/modules/phonics/) documents the options and session in full.

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

## World runtime

`Phonics\World` activates content without assuming a file format. Each package module's `Context` owns a `WorldRuntime` that shares the session registry and scheduler. Packages register `ContentAdapter` factories and `SystemSet` factories under their resolved export IDs; a `ContentAdapter` opens a `SourceDefinition` into a `ContentSource`, which catalogs stable `ScopeDescriptor`s and activates one scope at a time.

`WorldRuntime::activate($source, $scope)` returns a reference-counted `ScopeLease`. The first lease activates the adapter's scope and then every binding whose selector matches it, in `after` order. Each activation receives a `ScopeContext`, which owns the systems, entities, render passes, and cleanup it registers. Releasing the last lease snapshots the adapter scope, runs the context's cleanup in reverse order, destroys the entities it owns, and keeps its JSON-serializable state for a later activation of the same scope. See the [World runtime API](api-world.html).

## Networking

Multiplayer is optional and declared by a world's `network` object:

- `activation` — `menu` (the default) or `auto`.
- `players` — a `{min, max}` range from 2 through 16; the default is exactly two players.
- `lobby.provider` — a `lobby-provider` export, with provider `options`.
- `transport` — a `transport` export reference, or `{use, options}`.

The core manifest exports `webrtc` as a transport and `memory-lobby` as an in-memory lobby provider. A world without `network` activates no lobby or transport.

In the browser, a `LobbyController` runs the lobby and invitation state machine independently of Matrix and WebRTC classes. A `ResourceNegotiator` acquires, verifies, and binds the files for world-declared resource slots without interpreting their format. `NetworkSession` coordinates the match and resource handshake for one controller, and `WebRtcTransport` carries packets on an ordered `control` channel and an unordered `realtime` channel with no retransmits. `NetworkBridge` keeps bounded packet queues between the browser and PHP. These classes are exported from [`@seanmorris/phonics/networking`](api-js/modules/networking/).

In PHP, `BrowserBridge` exchanges validated binary packets with the browser through the `phonicsNetworkReceive` and `phonicsNetworkSend` bridge functions. `ReplicationSession` coordinates host-authoritative commands and snapshots for two peers in fixed-step systems. Games register explicit component codecs and prefabs in a `ReplicationRegistry`; arbitrary objects are never serialized by reflection. `CommandValidator` checks command ownership, tick windows, sequence, and rate on the host, and `SnapshotBuilder` and `SnapshotApplier` move whitelisted components between registries. Entities opt in with `NetworkIdentity`, `NetworkAuthority`, and `NetworkPrefab` components. See the [Networking API](api-network.html). [Doom-Phonics multiplayer](../packages/doom-phonics/docs/multiplayer.md) shows a complete game built on these layers.

## Compatibility boundary

The supported PHP namespace is `Phonics\`. Pre-1.0 `PhpWasmEngine\` names remain aliases and resolve consistently in mixed ECS lookups. Legacy browser boot functions and development example URLs adapt to `loadWorld()` without adding game selection logic to the host.

Compatibility adapters do not make the core depend on a game package. A core-only installation can load a separately packaged game when its manifest declares all code and assets it needs.
