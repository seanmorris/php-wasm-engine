# Phonics architecture

Phonics runs PHP games in the browser. Your application starts from an executable PHP archive, or PHAR, and chooses the game code and content to load. PHP owns simulation and rendering. The browser supplies the PHP/SDL runtime, canvas, and services such as audio output and fullscreen controls.

This page explains how those parts work together, from startup to cleanup. Tiled worlds, content streaming, and multiplayer are optional. For a first working game, start with the [quickstart](../site/pages/quickstart.md).

<a id="executable-application-startup"></a>

## Start and stop an application

### Build and launch the archive

`Phonics.run(applicationUrl, options)` accepts an executable `.phar`. It fetches the archive under the host's code-origin policy, checks optional SHA-256 integrity, starts PHP with the pinned Phar extension, validates the archive's internal signature, and executes its stub. Worlds, WADs, and package manifests are resources rather than launch entry points.

`phonics pack` builds `dist/game.phar` from the PHP file named by `application.entry` in `phonics.json`. The archive includes declared engine and application PHP, browser modules, assets, installed dependencies, and a versioned bridge descriptor. Files outside those declarations are excluded.

Fixed member ordering and timestamps make the build reproducible. A `.integrity` sidecar records the complete archive digest. Packaging requires native PHP with Phar; serving or playing the game does not.

A typical application bootstrap is:

```php
<?php
require_once 'phar://' . Phar::running(false) . '/core/autoload.php';
$app = new \Phonics\Application\Context;
$phonics = $app->compose(__DIR__ . '/worlds/game.world', __DIR__ . '/phonics.json#game');
require $app->entry();
```

The optional `compose()` service resolves and verifies the content's imported packages and prepares `Phonics\Package\Context`. The application selects its game entry explicitly; `entry()` supplies that path for the bootstrap to require in its own PHP scope. A world cannot replace this selection.

An application can also run PHP without loading a world. It can activate a packaged browser module with `$app->browser($path)` and signal readiness with `$app->ready()`. The module's `activate(host)` receives the session, module, canvas, abort signal, and two shared services: `QuickControls` for corner mute/fullscreen controls and `SafeStorage` for guarded storage.

### Choose content and startup options

`options.init` supplies JSON data through `$app->init`; `compose()` merges it over the world's `init`. The default bootstraps accept `init.world` to select external content. Doom also accepts `startMap` and `cheats`. Application-owned network configuration is supplied as the third argument to `compose()`. These choices belong to PHP application code, not the browser host.

A `phonics-world` document describes content and the systems that run with it. A *source* is content opened by an adapter, such as a WAD or a Tiled world. A *scope* is one activatable part of that content, such as a map.

| Field | Purpose |
| --- | --- |
| `imports`, `modules` | Package aliases and optional additional modules. |
| `sources` | Adapters and their resource URLs or slot placements. |
| `resourceSlots` | Requirements and defaults for files supplied by the player or match. |
| `bindings` | Groups of systems to activate for matching scopes. |
| `start`, `transitions` | The initial scope and available scope changes. |
| `view` | Optional viewport dimensions. |
| `init` | Game-owned initialization data. |

Remote executable imports require allowed origins, compatible package declarations, and verified file integrity before activation.

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

### Leave the application

A ready session exposes its application URL and code identity, PHP runtime, abort signal, and asynchronous `dispose()`. Applications that use composition also expose their world, graph, assets, and network services.

Call `dispose()` before launching another application. It aborts outstanding requests, closes PHP resources and browser services, and releases resources owned by the session. Moving between stages of the same game stays inside the existing runtime.

<a id="resource-identity-and-ownership"></a>

## Resolve and release resources

Every loaded resource retains a canonical source URL separately from its cache key or WASM mount. Relative references resolve against the resource that declares them: world to map, map to tileset, tileset to image, and manifest to code or assets. Root-relative paths, queries, and fragments use normal URL semantics.

Several services preserve that identity as resources move between storage formats:

| Service | Responsibility |
| --- | --- |
| `ResourceStore` | Share requests for the same canonical URL, verify SHA-256 integrity, retry when asked, and count owners through cancellable scopes. |
| `ArchiveResources` | Map public declaring URLs to archive members while preserving external relative references and query identity. |
| `SessionAssets` | Materialize assets needed by native SDL or browser consumers. PHP can read packaged files directly through `phar://`. |
| `Resource::native()` | Supply a filesystem path to native APIs that cannot open PHP streams. |

Cached resource bytes can be shared across scenes. Mutable map placements and ECS state belong to their scene or scope and need their own lifecycle.

Tiled authoring caches use `phonics.source` and `phonics.source.property.<name>` metadata to retain canonical provenance. Browser and native PHP loaders honor those overrides. Relocating an authoring cache therefore does not change the published identity of its dependencies.

<a id="php-runtime-boundary"></a>

## Run the game loop

Asset, ECS, scheduling, Tiled parsing, spatial indexing, fixed-step simulation, generic collision queries, controls, and most layout calculations are ordinary PHP. SDL input and WebGL rendering use the PHP/SDL runtime extensions. The browser bridge supplies request scheduling, asynchronous data transfer, DOM-backed textures, audio output, quick controls, and session lifecycle callbacks.

`Engine` polls input and renders on visible browser frames while `FixedStep` advances simulation at a stable interval. Catch-up is bounded; excess time is reported through `FixedStep::$droppedTime`. Paused, hidden, and lost-context frames reset timing and input baselines so stale movement does not enter the next update.

Graphics resources retain their PHP-side configuration across WebGL context loss. Owners restore shared devices, atlases, programs, buffers, textures, and renderers in dependency order before drawing resumes. Explicit `close()` methods release GPU and SDL handles before their parent device or runtime closes.

## Entities, modules, and systems

An entity is a stable integer ID. Components are PHP objects attached to that ID, and systems query those components to update the game. `Registry` stores the IDs and components; `Scheduler` orders the systems.

Queries accept a component class or a combination of `Query::all()`, `any()`, and `not()` expressions. They return snapshots that remain stable while callers iterate them. The registry resolves both canonical and compatibility class names at lookup boundaries.

The registry indexes concrete classes, parents, and interfaces. Structural mutations invalidate bounded query caches; changing fields inside an attached component does not. Deferred commands allow systems to add or remove entities safely during an update. Lifecycle listeners observe component additions and removals.

To retire an entity after iteration, call `Registry::destroyLater()`. Its queued command destroys the entity only if it is still alive. `OwnedEntities` records the entities a manager created and destroys the survivors when the manager closes, in insertion or reverse order. `ScopeContext` uses it to own a scope's entities.

Short-lived effects can use `Lifetime` to track their age, duration, previous-step age for interpolation, and a fade that starts at a chosen age. Catch-up never rewinds the age. Games can extend it with marker classes to query effects by kind. `LifetimeSystem` advances these lifetimes, fades their sprites, and queues expired entities for destruction at the next flush. Games that need a reaction in the same step as expiry handle that separately.

`Scheduler` runs named systems in dependency order and rejects missing dependencies and cycles. A package module receives `Phonics\Package\Context`, which owns its system and factory registrations. Modules register cleanup with `onClose()` rather than retaining global process state.

For factory-based Tiled content, `ObjectSpawner` turns recognized object classes and pallet references into the components returned by registered factories. A pallet reference selects reusable content declared by a package; see [resource identity](../site/pages/resources.md). Game-specific entity names and properties remain the factory's responsibility.

## Tiled data and streaming

`Loader` reads worlds, maps, templates, external tilesets, images, inherited file properties, and inline tilesets while preserving each document's source identity. `Projection` converts supported tile coordinate systems into world coordinates. Layers retain visibility, tint, opacity, offsets, parallax, objects, chunks, properties, and revision state.

For large environments, `World` indexes finite map bounds without loading every map. `WorldStream` requests visible maps and all their dependencies through a host callback. It activates maps only when their files are present and reports entered, exited, and failed maps. Preload and retention margins let applications load adjacent maps early and keep them briefly after they leave the view.

Streaming operates on complete maps. Applications own the entity and terrain state created for each resident map and decide whether to persist edits before eviction.

## Collision and spatial queries

Collision queries find where a ray or moving shape first meets nearby geometry. `Terrain` stores indexed segments and polygons and supports ray, circle, and continuously swept body casts. A swept cast checks the movement between positions, rather than only the destination. Spatial buckets narrow the search before the solver tests exact geometry.

Core physics describes bodies with `Pose`, bounds, masks, and surface metadata. Games decide what a contact means for movement or damage.

Collision hits report the first contact time, point, normal, source metadata, and optional removable-shape handle. Adding or removing a shape updates only affected index buckets. Casts are read-only, and geometry changes belong between simulation steps or in a caller-controlled impact callback.

`MapTerrain::compile()` fills a supplied `Terrain`, including a game's subclass. Hooks let games choose surface metadata, leave objects to other systems, or route objects themselves using the same coordinate projection. Those hooks preserve traversal order, so shape handles keep their order. `build()` provides the default path without those hooks.

For collisions between moving bodies, `Sweep::box()` tests a moving point against an expanded axis-aligned box. It returns entry and exit fractions and the entry face's normal. Callers choose the tolerance for a stationary axis and whether a point on its edge counts as inside. The game then decides which returned contacts to accept.

Rules such as rail interpretation, directional layer switching, character adhesion, damage, brick behavior, and water gameplay belong to game packages. They compose the neutral casts rather than extending core with concrete game classes.

## Rendering

### Draw maps and sprites

An atlas packs source images together so renderers can draw many tiles or sprites in batches. Tiled global IDs retain their unsigned flag bits and map to dense atlas indexes. `Atlas` also packs animation metadata once. A time value sent to the tile shader selects animated frames without rewriting a GPU texture each frame.

Tile layers use bounded texture arrays and instanced placement; changed layer revisions upload only their affected slices. `ImageRenderer` draws ordinary and repeating image layers in map order.

`SpriteRenderer` batches sprites that share an atlas. Each sprite carries position, origin, scale, rotation, flips, tint, opacity, animation time, crop, filtering, and outline state. `SpriteGroup` and `SpriteLayers` preserve drawing order for artwork made from several sprites.

A caller drawing an unchanged batch, such as laid-out text, can pass a cache key. While that key repeats, the renderer reuses the packed records and their GPU upload. Menu and HUD renderers use this to avoid rebuilding static text each frame.

`WorldRenderer` combines resident map renderers in manifest order. Cameras define world viewports and projections.

### Render offscreen effects

Post-processing first draws a scene into an offscreen texture. `RenderTarget` owns that color texture, its framebuffer, and an optional depth renderbuffer. Binding the target saves the surrounding framebuffer and sets the viewport; unbinding restores the previous target. Its graphics objects rebuild after context restoration.

Post-process renderers and offscreen scene targets use this shared owner rather than allocating those objects themselves. Targets can nest, and `RenderTargetPair` holds two targets for passes that alternate their input and output. It also lets callers redraw retained content after a resize.

## Input and UI

Games read actions rather than handling every device directly. `SdlInput` maps keyboard and gamepad state into logical buttons and action bindings. `Controls` preserves press and release transitions across fixed updates. `Sequences` recognizes timed button sequences, simultaneous buttons, held modifiers, relative directions, and release steps without consuming gameplay controls. See [input and sequences](input.md).

Framework UI supplies bitmap text, editable fields, menus, timed title/loading screens, temporary messages, labels inside the world, and panels drawn from HTML. Games provide the content, HUD layout, screens, and accessibility labels. See [UI, menus, and cards](ui.md).

The browser-side `GameShell` handles shared host work: claiming the WebGL2 context, measuring the viewport for PHP, controlling fullscreen through `QuickControls`, persisting mute settings with migration from the old key, bridging status/actions, and cleaning up. Browser modules receive shared audio and mute/fullscreen services through their activation context. `RegionButtons` mirrors PHP hit regions as accessible buttons; `TouchPad` supplies held buttons for touch-style input.

<a id="world-runtime"></a>

## Load, activate, and unload content

A game may inspect a world before playing it, prepare the next map in advance, or suspend a map when the player leaves. `Phonics\World` provides that lifecycle without assuming a content format. Each package `Context` owns a `WorldRuntime` that shares the session registry and scheduler.

### Open a source and activate its scopes

A *content adapter* opens a format-specific asset. The resulting *source* lists stable scopes, such as its maps, which the runtime can prepare and activate individually.

Packages register adapter and system-set factories under their resolved export IDs. In the API, these roles are `ContentAdapter` and `SystemSet`; an adapter opens a `SourceDefinition` into a `ContentSource`, whose catalog contains `ScopeDescriptor` entries.

| Operation | Result |
| --- | --- |
| `WorldRuntime::load()` | Open the source as an asset without starting gameplay. |
| `prepare()` | Construct a scope's content, components, and system registrations with execution disabled. |
| `activate()` | Enable the scope and return a `ScopeLease`, a handle that keeps it active until released. |

```php
$lease = $phonics->runtime->activate('episode', 'E1M1');
// The scope stays active while at least one activation lease is held.
$lease->release();
```

Each scope's `ScopeContext` owns its systems, entities, render passes, cleanup, and explicit snapshot callbacks. Its `query()` returns entities owned by active scopes, with descendants included only when requested. Its `entities()` method also supports inspection while inactive. Systems must use scoped queries when multiple content instances share a registry.

### Choose what happens when a scope stops

The scope or source selects a lifecycle policy:

| Policy | What it preserves |
| --- | --- |
| `retain` | Suspend the existing instances for later reuse. |
| `snapshot` | Capture explicitly provided JSON-safe state, then release the instances. This remains the default for existing adapters. |
| `reset` | Release the instances and forget their state. |

Packages can export their own `scope-policy` implementations of `ScopeLifecycle`. Children stop and unload before parents, even if prepared in another order. Transitions requested during simulation take effect at the scheduler boundary. A stale lease cannot deactivate a later activation.

`fork()` creates an independent content session. It shares engine services and already loaded registrations, but has its own content lifecycle.

### Inspect Tiled content and attach components

The optional `@phonics/tiled` adapter implements `InspectableSource`. It can list map identities, inspect maps/layers/tilesets/objects, and read resources without running systems. Game systems decide whether map placements form a spatial world, a campaign sequence, or movable maps and layers. Inspection does not reset positions controlled by the game.

Loading another world never fetches additional PHP code. The component constructors, system sets, and lifecycle policies it uses must already be registered.

Tiled's main custom class and class-valued properties resolve through the portable `phonics.classes` catalog. Classes bound to component exports construct components. Unbound preset classes collect nested components; they do not register executable constructors. Only already registered constructors can run.

Defaults, preset values, and instance overrides remain nested, so `Movement.speed` and `Animation.speed` cannot collide. A `Target` component identifies the world, map, layer (including groups/images), object, or tile group to which the components apply. These targets remain ordinary ECS entities.

### Choose whether tiles create entities

Tiles do not create entities by default. Set `phonics.tiles` to choose a grouping:

| Value | Entity grouping |
| --- | --- |
| `collection` | One per canonical tile definition across the map's layers. |
| `placement` | One per placed cell. |
| `region` | One per connected group of matching cells within a layer. |

For regions, `phonics.connectivity` chooses edge neighbors (`edges`, the default) or includes corner neighbors (`corners`). Individual tile properties override map defaults.

`TileCells` records the exact cells, including holes, flags, negative coordinates, and layer identities. It can project cells for orthogonal or isometric maps. Creating these entities does not create colliders or change rendering.

Tile edits reconcile after a scheduler step and preserve unchanged group identities. If registered, the `tiled.tileTransfer` service receives removed groups, added groups, and the scope.

See the [World runtime API](../src/World) for the adapter, source, and scope classes.

## Networking

Multiplayer is optional. The application chooses a lobby for finding players and a transport for exchanging packets. Without network configuration, no lobby or transport activates.

### Configure a lobby and transport

The application's network configuration accepts:

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

The core manifest exports the `webrtc` transport and the `memory-lobby` provider for an in-memory lobby.

PHAR applications use match protocol `2.0.0`. Application ID and executable-code digest are separate from the content manifest. Peers with differing code or protocol are rejected before simulation begins. Verified remote code dependencies participate in the digest; files such as WADs are selected through resource negotiation.

### Browser networking services

The browser coordinates invitations, connection setup, and content verification before forwarding game packets to PHP. These services are exported from [`@seanmorris/phonics/networking`](../browser/network):

| Service | Responsibility |
| --- | --- |
| `LobbyController` | Run lobby and invitation state independently of a particular provider or transport. |
| `ResourceNegotiator` | Acquire, verify, and bind files for world-declared resource slots without interpreting their format. |
| `NetworkSession` | Coordinate the match and resource handshake for one controller. |
| `WebRtcTransport` | Carry ordered `control` packets and unordered `realtime` packets with no retransmits. |
| `NetworkBridge` | Keep bounded packet queues between the browser and PHP. |

Two interfaces use those services: `LobbyOverlay`, with `GameLauncher` for world games, and `EmbeddedRoomLobby` for direct applications. They share `OverlayTheme` styles under the `phonics-ui` scope; its reference-counted `useStyle()` also manages styles added by a view.

[`LobbyKit`](../browser/ui/LobbyKit.mjs) supplies their shared DOM components: nodes and buttons, player rows with avatars, presence-derived display names, chat, status lines, countdowns, and copying text. New session-owned views should reuse these parts. The relevant exports include `node`, `button`, `personRow`, `DisplayNames`, `ChatLog`, `StatusLine`, `Ticker`, `timeLeft`, and `copyText`.

### Replicate game state in PHP

For two-peer host-authoritative games, `ReplicationSession` exchanges commands and snapshots from fixed-step systems. The host validates player commands and sends the resulting state. A game opts in by registering component codecs and prefabs in `ReplicationRegistry`, then attaching `NetworkIdentity`, `NetworkAuthority`, and `NetworkPrefab` to the entities it replicates.

Only explicitly registered components cross this boundary; arbitrary objects are never serialized by reflection. `CommandValidator` checks command ownership, tick windows, sequence, and rate on the host. `SnapshotBuilder` and `SnapshotApplier` transfer the allowed components between registries.

`BrowserBridge` exchanges validated binary packets through `phonicsNetworkReceive` and `phonicsNetworkSend`. See the [Networking API](../src/Network) for the classes and [Doom-Phonics multiplayer](https://github.com/seanmorris/phonics-examples/blob/main/games/doom-phonics/docs/multiplayer.md) for a complete game integration.

<a id="compatibility-boundary"></a>

## Preserve compatibility

The supported PHP namespace is `Phonics\`. Pre-1.0 `PhpWasmEngine\` names remain aliases and resolve consistently in mixed ECS lookups. Browser consumers have one launch entry point, `Phonics.run()` with an executable archive; the development example adapter uses it too.

Compatibility adapters do not make the core depend on a game package. An application archive carries its PHP dependencies. The browser PHP/SDL runtime is distributed separately and remains host-controlled. Native desktop gameplay is outside this application contract.
