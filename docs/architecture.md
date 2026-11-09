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

`compose()` is an optional engine service. It resolves and verifies the content's imported packages, prepares `Phonics\Package\Context`, and returns the explicitly selected game entry through `entry()`. The bootstrap requires that entry in its own PHP scope. The world cannot replace it. An application can instead execute arbitrary PHP, activate a packaged browser module with `$app->browser($path)`, and call `$app->ready()` without loading a world. That module's `activate(host)` receives the session, module, canvas, abort signal, and `services.QuickControls` and `services.SafeStorage`, the same corner mute/fullscreen controls and guarded storage that composed applications get.

`options.init` supplies JSON data through `$app->init`; `compose()` merges it over the world's `init`. The default bootstraps accept `init.world` to select external content. Doom also accepts `startMap` and `cheats`. Application-owned network configuration is supplied as the third argument to `compose()`. These choices belong to PHP application code, not the browser host.

A `phonics-world` content document declares:

- `imports` and `modules` — package aliases and optional additional modules. Remote executable imports still require allowed origins, compatible package declarations, and verified file integrity before activation.
- `sources` — adapters and their resource URLs or slot placements.
- `resourceSlots` — requirements and defaults for files supplied by the player or match.
- `bindings` — system sets activated for matching content scopes.
- `start`, `transitions`, and `view` — initial scope, scope changes, and optional viewport dimensions.
- `init` — game-owned initialization data.

A document that opens a WAD through an adapter, binds one system set to its maps, and starts at the first map looks like this:

```json
{
  "type": "phonics-world",
  "schemaVersion": 1,
  "imports": {"doom": "https://cdn.example/doom-phonics/0.1.0/phonics.json"},
  "sources": {
    "episode": {
      "adapter": "@doom/wad",
      "resources": [{"url": "./licensed/base.wad", "role": "iwad", "integrity": "sha256-…"}]
    }
  },
  "bindings": [
    {"id": "episode-gameplay", "use": "@doom/episode", "on": {"source": "episode", "kind": "map"}, "options": {"skill": 2}}
  ],
  "transitions": [
    {"id": "e1m1-e1m2", "from": {"source": "episode", "scope": "E1M1"}, "to": {"source": "episode", "scope": "E1M2"}}
  ],
  "start": {"source": "episode", "scope": "E1M1"},
  "view": {"width": 960, "height": 540},
  "init": {"startMap": "E1M1"}
}
```

[Load a world](../site/pages/worlds.md) walks through a complete document, including resource slots that the player or a match supplies.

Tiled `.world` resources are supported alongside these documents: `phonics.import.<alias>`, `phonics.start`, and `phonics.modules` properties carry the same meaning. Content never selects the game or the network configuration. A `phonics.game` property, or `game` and `network` fields in a content document, cannot override the application's selections.

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

`Registry::destroyLater()` queues the idempotent alive-then-destroy command systems use to retire entities after iteration. `OwnedEntities` records the entities a manager created and destroys the survivors when it closes, in insertion or reverse order; `ScopeContext` builds its scope ownership on it.

`Lifetime` gives a short-lived effect its age against a duration, the previous step's age for interpolation, a fade from a chosen age and a catch-up that never rewinds; games extend it with marker classes to query effects by kind. `LifetimeSystem` ages every lifetime, fades its sprite and retires the expired at the next flush, for games that need no same-step reaction to expiry.

`Scheduler` runs named systems in dependency order and rejects missing dependencies and cycles. A package module receives `Phonics\Package\Context`, which owns its system and factory registrations. Modules register cleanup with `onClose()` rather than retaining global process state.

`ObjectSpawner` converts recognized Tiled object classes and pallet references into components returned by registered factories. The framework does not interpret game-specific entity names or properties.

## Tiled data and streaming

`Loader` reads worlds, maps, templates, external tilesets, images, inherited file properties, and inline tilesets while preserving each document's source identity. `Projection` converts supported tile coordinate systems into world coordinates. Layers retain visibility, tint, opacity, offsets, parallax, objects, chunks, properties, and revision state.

`World` indexes finite map bounds in a static bounds tree without loading every map. `WorldStream` requests visible maps and their dependency closure through a host callback, activates them only when all files are present, and reports entered, exited, and failed maps. Preload and retention margins let applications stage adjacent maps and delay eviction.

Streaming operates on complete maps. Applications own the entity and terrain state created for each resident map and decide whether to persist edits before eviction.

## Collision and spatial queries

Core physics uses `Pose`, bounds, masks, surface metadata, and generic geometry. `Terrain` stores indexed segments and polygons and exposes ray, circle, and continuously swept body casts. Candidate shapes come from spatial buckets; the solver tests exact geometry only for nearby candidates.

Collision hits report the first contact time, point, normal, source metadata, and optional removable-shape handle. Adding or removing a shape updates only affected index buckets. Casts are read-only, and geometry changes belong between simulation steps or in a caller-controlled impact callback.

`RenderTarget` owns one offscreen colour texture, an optional depth renderbuffer and their framebuffer: binding saves the caller's framebuffer and sets the viewport, unbinding returns to it, and the objects rebuild after context restoration; post-process renderers and offscreen scene targets compose it instead of allocating the three GL objects themselves; `RenderTargetPair` holds two for ping-pong passes and lets a caller redraw kept content across a resize.

`MapTerrain::compile()` fills a given `Terrain`, or a game's subclass, with hooks at the three places games differ: the surface metadata read for a shape, objects left to other systems, and objects a game routes itself with the same projection the default uses; the walk over layers, tiles and objects is unchanged by them, so shape handles keep their order. `build()` is `compile()` with none.

`Sweep::box()` is the shared slab test for a point moving against an expanded axis-aligned box, as bodies test one another's grown bounds; callers choose the stillness epsilon and whether a still axis on the edge counts as inside, and read the raw entry and exit fractions and the entered face's normal, so each game keeps its own acceptance window.

Rules such as rail interpretation, directional layer switching, character adhesion, damage, brick behavior, and water gameplay belong to game packages. They compose the neutral casts rather than extending core with concrete game classes.

## Rendering

Tiled global IDs retain their unsigned flag bits and map to dense atlas indexes. `Atlas` packs source images and animation metadata once: the tile shader picks each animated tile's frame from that metadata by a time uniform, so animating a map rewrites no GPU texture per frame. Tile layers use bounded texture arrays and instanced placement; changed layer revisions upload only their affected slices. `ImageRenderer` draws ordinary and repeating image layers in map order.

`SpriteRenderer` batches sprites that share an atlas. Each sprite carries position, origin, scale, rotation, flips, tint, opacity, animation time, crop, filtering, and outline state. `SpriteGroup` and `SpriteLayers` preserve entity-level painter order for composite artwork. A caller that draws the same static batch every frame, such as laid-out text, passes a key with it; while the key repeats the renderer reuses the packed records and their GPU upload instead of rebuilding them, which is what the menu and HUD renderers do.

`WorldRenderer` combines resident map renderers in manifest order. Cameras define world viewports and projections. Generic post-process renderers each own an offscreen `RenderTarget` and can nest, since binding a target remembers the surrounding framebuffer and unbinding returns to it.

## Input and UI

`SdlInput` maps keyboard and gamepad state into logical numbered buttons and action aliases. `Controls` preserves pressed and released edges across fixed updates. `Sequences` recognizes timed codes, chords, held modifiers, relative directions, and release steps without consuming gameplay controls.

Framework UI includes bitmap fonts, editable text buffers, menu input and rendering, cards, transient messages, generic world text actors, and DOM-backed texture modals. Browser modules receive shared audio and mute/fullscreen services through their activation context. Games provide their own menu content, HUD composition, screens, and accessibility labels. In the browser, `GameShell` packages the host chores games share: the WebGL2 context claim, frame measurement into the viewport string PHP polls, the fullscreen state machine behind `QuickControls`, a persisted mute with one-time key migration, status and action bridges, and symmetric teardown; `RegionButtons` mirrors PHP hit regions as accessible buttons and `TouchPad` gives coarse pointers held buttons.

## World runtime

`Phonics\World` activates content without assuming a file format. Each package module's `Context` owns a `WorldRuntime` that shares the session registry and scheduler. Packages register `ContentAdapter` factories and `SystemSet` factories under their resolved export IDs; a `ContentAdapter` opens a `SourceDefinition` into a `ContentSource`, which catalogs stable `ScopeDescriptor`s and activates one scope at a time.

`WorldRuntime::activate($source, $scope)` returns a reference-counted `ScopeLease`. The first lease activates the adapter's scope and then every binding whose selector matches it, in `after` order. Each activation receives a `ScopeContext`, which owns the systems, entities, render passes, and cleanup it registers. Releasing the last lease snapshots the adapter scope, runs the context's cleanup in reverse order, destroys the entities it owns, and keeps its JSON-serializable state for a later activation of the same scope.

```php
$lease = $phonics->runtime->activate('episode', 'E1M1');
// The scope's systems, entities, and render passes stay active while any lease is held.
$lease->release();
```

See the [World runtime API](../src/World) for the adapter, source, and scope classes.

## Networking

Multiplayer is optional and selected by the application's network configuration:

- `activation` — `menu` (the default) or `auto`.
- `players` — a `{min, max}` range from 2 through 16; the default is exactly two players.
- `lobby.provider` — a `lobby-provider` export, with provider `options`.
- `transport` — a `transport` export reference, or `{use, options}`.

Doom-Phonics keeps its configuration in a JSON file beside the application and passes it as the third argument to `compose()`:

```json
{
  "activation": "menu",
  "players": {"min": 2, "max": 2},
  "lobby": {
    "provider": "@matrix/lobby",
    "options": {"homeserver": "https://matrix.org", "room": "#doom-phonics:matrix.org"}
  },
  "transport": "@phonics/webrtc"
}
```

```php
$network = json_decode(file_get_contents(__DIR__ . '/application-network.json'), true, flags: JSON_THROW_ON_ERROR);
$phonics = $app->compose(__DIR__ . '/worlds/game.world', __DIR__ . '/phonics.json#game', $network);
```

The core manifest exports `webrtc` as a transport and `memory-lobby` as an in-memory lobby provider. An application without network configuration activates no lobby or transport. PHAR applications use match protocol `2.0.0`, with application ID and executable-code digest separate from the content manifest. Peers with differing code or protocol are rejected before simulation begins. Verified remote code dependencies participate in that digest; WAD selection remains part of resource negotiation.

A game package opts in through that configuration, a `ReplicationRegistry` that names the component codecs and prefabs it replicates, and the `NetworkIdentity`, `NetworkAuthority`, and `NetworkPrefab` components on entities that exist on both peers; its fixed-step systems then drive a `ReplicationSession`. The remaining classes are framework services behind those entry points.

In the browser, a `LobbyController` runs the lobby and invitation state machine independently of Matrix and WebRTC classes. A `ResourceNegotiator` acquires, verifies, and binds the files for world-declared resource slots without interpreting their format. `NetworkSession` coordinates the match and resource handshake for one controller, and `WebRtcTransport` carries packets on an ordered `control` channel and an unordered `realtime` channel with no retransmits. `NetworkBridge` keeps bounded packet queues between the browser and PHP. These classes are exported from [`@seanmorris/phonics/networking`](../browser/network).

Two lobbies sit on top of those classes: `LobbyOverlay`, with the `GameLauncher` for world games, and `EmbeddedRoomLobby` for direct applications. They share one look and one set of parts. `OverlayTheme` installs the design tokens and component styles under a `phonics-ui` scope, with a reference-counted `useStyle()` for anything a view adds of its own. [`LobbyKit`](../browser/ui/LobbyKit.mjs) supplies the DOM parts both lobbies use: `node` and `button`, `personRow` with its avatar, `DisplayNames` learned from presence, `ChatLog`, `StatusLine`, the `Ticker` and `timeLeft` countdowns, and `copyText`. A new session-owned view should build from these parts rather than adding its own copies.

In PHP, `BrowserBridge` exchanges validated binary packets with the browser through the `phonicsNetworkReceive` and `phonicsNetworkSend` bridge functions. `ReplicationSession` coordinates host-authoritative commands and snapshots for two peers in fixed-step systems. Games register explicit component codecs and prefabs in a `ReplicationRegistry`; arbitrary objects are never serialized by reflection. `CommandValidator` checks command ownership, tick windows, sequence, and rate on the host, and `SnapshotBuilder` and `SnapshotApplier` move whitelisted components between registries. Entities opt in with `NetworkIdentity`, `NetworkAuthority`, and `NetworkPrefab` components. See the [Networking API](../src/Network). [Doom-Phonics multiplayer](../packages/doom-phonics/docs/multiplayer.md) shows a complete game built on these layers.

## Compatibility boundary

The supported PHP namespace is `Phonics\`. Pre-1.0 `PhpWasmEngine\` names remain aliases and resolve consistently in mixed ECS lookups. Browser consumers have one launch entry point, `Phonics.run()` with an executable archive; the development example adapter uses it too.

Compatibility adapters do not make the core depend on a game package. An application archive carries its PHP dependencies. The browser PHP/SDL runtime is distributed separately and remains host-controlled. Native desktop gameplay is outside this application contract.
