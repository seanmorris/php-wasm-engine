# Phonics architecture

Phonics runs executable PHP applications in the browser. The browser supplies PHP/SDL, policy, a canvas, and lifecycle services. The application's PHAR stub runs first and chooses its own game code, content, and optional browser modules. PHP owns simulation and rendering.

## Executable application startup

`Phonics.run(applicationUrl, options)` accepts an executable `.phar`. It fetches the archive under the host's code-origin policy, checks optional SHA-256 integrity, starts PHP with the pinned Phar extension, validates the archive's internal signature, and executes its stub. Worlds, WADs, and package manifests are resources rather than launch entry points.

`phonics pack` builds `dist/game.phar` from `application.entry` in `phonics.json`. That entry is an authored PHP file. The archive includes declared engine and application PHP, browser modules, assets, installed dependencies, and a versioned bridge descriptor. Files outside those declarations are excluded. Fixed member ordering and timestamps make the build reproducible; a `.integrity` sidecar supplies the complete archive digest. Native PHP with Phar is required for packaging, not for serving or playing the game.

A typical application bootstrap is:

```php
<?php
require_once 'phar://' . Phar::running(false) . '/core/autoload.php';
$app = new \Phonics\Application\Context;
$phonics = $app->compose(__DIR__ . '/worlds/game.world', __DIR__ . '/phonics.json#game');
require $app->entry();
```

`compose()` is an optional engine service. It resolves and verifies the content's imported packages, prepares `Phonics\Package\Context`, and returns the explicitly selected game entry through `entry()`. The bootstrap requires that entry in its own PHP scope. The world cannot replace it. An application can instead execute arbitrary PHP, activate a packaged browser module with `$app->browser($path)`, and call `$app->ready()` without loading a world.

`options.init` supplies JSON data through `$app->init`; `compose()` merges it over the world's `init`. The default bootstraps accept `init.world` to select external content. Doom also accepts `startMap` and `cheats`. Application-owned network configuration is supplied as the third argument to `compose()`. These choices belong to PHP application code, not the browser host.

A `phonics-world` content document declares:

- `imports` and `modules` — package aliases and optional additional modules. Remote executable imports still require allowed origins, compatible package declarations, and verified file integrity before activation.
- `sources` — adapters and their resource URLs or slot placements.
- `resourceSlots` — requirements and defaults for files supplied by the player or match.
- `bindings` — system sets activated for matching content scopes.
- `start`, `transitions`, and `view` — initial scope, scope changes, and optional viewport dimensions.
- `init` — game-owned initialization data.

Tiled `.world` resources remain supported. `phonics.import.<alias>`, `phonics.start`, and `phonics.modules` retain their meaning. `phonics.game` is no longer used. The `game` and `network` fields in a content document cannot override the application's selections.

A ready session exposes its application URL and code identity, PHP runtime, abort signal, and asynchronous `dispose()`. Applications that use composition additionally expose their world, graph, assets, and network services. Disposal aborts outstanding requests, closes PHP resources and browser services, and releases scoped bytes. Dispose the previous session before launching another application. Stage transitions stay inside the existing runtime.

## Resource identity and ownership

Every loaded resource retains a canonical source URL separately from its cache key or WASM mount. Relative references resolve against the resource that declares them: world to map, map to tileset, tileset to image, and manifest to code or assets. Root-relative paths, queries, and fragments use normal URL semantics.

`ResourceStore` deduplicates canonical URLs, retries failed requests when asked, verifies SHA-256 integrity, and reference-counts ownership through cancellable scopes. `ArchiveResources` maps public declaring URLs to archive members, retaining external relative references and query identity. PHP reads packaged files directly through `phar://`; `SessionAssets` materializes only the assets required by native SDL or browser consumers. `Resource::native()` supplies a real filesystem path for native APIs that cannot open PHP streams. Shared bytes can outlive one scene; mutable map placement and ECS state cannot.

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

Multiplayer is optional and selected by the application's network configuration:

- `activation` — `menu` (the default) or `auto`.
- `players` — a `{min, max}` range from 2 through 16; the default is exactly two players.
- `lobby.provider` — a `lobby-provider` export, with provider `options`.
- `transport` — a `transport` export reference, or `{use, options}`.

The core manifest exports `webrtc` as a transport and `memory-lobby` as an in-memory lobby provider. An application without network configuration activates no lobby or transport. PHAR applications use match protocol `2.0.0`, with application ID and executable-code digest separate from the content manifest. Peers with differing code or protocol are rejected before simulation begins. Verified remote code dependencies participate in that digest; WAD selection remains part of resource negotiation.

In the browser, a `LobbyController` runs the lobby and invitation state machine independently of Matrix and WebRTC classes. A `ResourceNegotiator` acquires, verifies, and binds the files for world-declared resource slots without interpreting their format. `NetworkSession` coordinates the match and resource handshake for one controller, and `WebRtcTransport` carries packets on an ordered `control` channel and an unordered `realtime` channel with no retransmits. `NetworkBridge` keeps bounded packet queues between the browser and PHP. These classes are exported from [`@seanmorris/phonics/networking`](api-js/modules/networking/).

In PHP, `BrowserBridge` exchanges validated binary packets with the browser through the `phonicsNetworkReceive` and `phonicsNetworkSend` bridge functions. `ReplicationSession` coordinates host-authoritative commands and snapshots for two peers in fixed-step systems. Games register explicit component codecs and prefabs in a `ReplicationRegistry`; arbitrary objects are never serialized by reflection. `CommandValidator` checks command ownership, tick windows, sequence, and rate on the host, and `SnapshotBuilder` and `SnapshotApplier` move whitelisted components between registries. Entities opt in with `NetworkIdentity`, `NetworkAuthority`, and `NetworkPrefab` components. See the [Networking API](api-network.html). [Doom-Phonics multiplayer](../packages/doom-phonics/docs/multiplayer.md) shows a complete game built on these layers.

## Compatibility boundary

The supported PHP namespace is `Phonics\`. Pre-1.0 `PhpWasmEngine\` names remain aliases and resolve consistently in mixed ECS lookups. The former `Phonics.loadWorld()` launch API is retired. Browser consumers call `Phonics.run()` with an executable archive; the development example adapter uses the same entry point.

Compatibility adapters do not make the core depend on a game package. An application archive carries its PHP dependencies. The browser PHP/SDL runtime is distributed separately and remains host-controlled. Native desktop gameplay is outside this application contract.
