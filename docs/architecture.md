# Implementation notes

## Reference projects

The initial design was informed by Sean Morris's local projects:

- tigl/app/world/World.js, TileMap.js, and sprite/SpriteBoard.js: manifest bounds, spatial queries, deferred map initialization, cached requests, and visibility-driven renderer lifetimes.
- tigl/app/sprite/Parallax.js: repeating image strips, independent scroll factors, and viewport anchors.
- moving-boxes-2/app/backdrop/Backdrop.js: stacked strips, automatic drift, and animated image frames.
- soop: object components, boolean queries, systems, and deferred structural commands.
- moving-boxes-2/app/behavior/Platformer.js: surface modes, ground/air velocity, terrain sensors, tangent momentum, and normal-directed jumps.
- moving-boxes-2/app/behavior/Crouch.js and Spindash.js: grounded crouch state, Down + tap 0 or hold 1 charging, passive charge decay, and Down-release launch.
- moving-boxes-2/app/actor/Sonic.js: landing-triggered drop dash.
- moving-boxes-2/app/actor/Knuckles.js: glide, wall catching, climbing, and jumping away.
- moving-boxes-2/app/actor/Tails.js: lift, flight duration, reduced gravity, and exhaustion.
- moving-boxes-2/app/controller/Controller.js, Axis.js, and GamepadConfig.js: left-stick/D-pad movement, A jump, B dash actions, Start pause, and a proportional 20% stick dead zone.

The PHP implementation has its own data model, solver, units, control state, and renderer. Original actors, framework integration, combat, powerups, and artwork are not included.

## PHP and browser boundaries

Asset, Tiled, Ecs, FixedStep, Controls, and Physics are ordinary PHP. Render and SdlInput call php-sdl-wasm extensions. Engine uses vrzno for frame scheduling and cleanup. The demo's PHP code updates its DOM status through vrzno.

Gamepads polls SDL controller handles by instance ID, detects connection changes, and releases detached handles. SDL handles browser Gamepad API access and device mappings. SdlInput merges keyboard and gamepad strengths; Controls retains press/release edges for fixed simulation steps. Individual pad axes and buttons remain available for custom bindings. Canvas focus listeners cover element blur, which does not produce an SDL window-focus event. Focus, visibility and graphics recovery suppress held gamepad controls until neutral. Start toggles simulation pause while polling and rendering continue. Engine closes input before SDL teardown.

docs/bootstrap.mjs imports the runtime and mounts files. It contains no movement, map parsing, entity processing, or rendering logic. Optional zlib uses php-sdl-wasm's sharedLibs resolver.

## GPU representation

Tiled GIDs use flag bits above PHP WASM's signed integer range. Gid decodes without narrowing to signed int32 and stores four bytes. The renderer remaps sparse global IDs to dense atlas indexes.

Layers are split into at most 32×32 cells. Texture arrays are bounded by GL_MAX_ARRAY_TEXTURE_LAYERS. Independent instance attributes retain layer placement and appearance. Dirty revisions trigger a slice upload. Floating-point metadata supplies atlas rectangles and offsets; animation updates metadata rather than every tile.

Isometric drawing orders diagonals globally, including negative chunks, at the cost of more draws. Games order sprites before passing them to SpriteRenderer. Objects retain metadata; games supply their graphics.

SpriteSheet::play resolves an animation name to a tile GID and starts it by storing the negative simulation time in Sprite::time. SpriteRenderer adds that offset to its render time before selecting the Tiled animation frame. Repeated requests for the current GID preserve the offset unless an explicit restart is requested. Switching does not recreate the sprite or upload textures, and sprite playback survives atlas restoration.

SpriteGroup stores several world-positioned sprites on one entity while preserving the registry's one-component-per-class rule. SpriteRenderer::renderEntities collects primary Sprite components and group members into its existing instanced batch. Each sprite may select a tile-relative integer crop; this adjusts the source rectangle and rendered dimensions without uploading a texture. Frozen sprites bypass tile animation lookup. Tint is constant per instance in the shader, avoiding interpolation rounding across triangles.

Sprite::$outline defaults to true and occupies a flag bit in the existing 64-byte instance record. The vertex shader expands the quad by one world unit along each local axis before rotation. It keeps the artwork's original anchor and computes the outline radius in source pixels from the absolute sprite scale. The fragment shader preserves visible artwork and fills transparent pixels with black using the largest alpha in that source neighborhood. It clamps every lookup to the current frame or crop. Checking intermediate texels keeps outlines continuous when source pixels shrink below one world pixel. Fully opaque neighbors end the search early; opt-outs skip expansion and the neighborhood scan. Integer texel fetches and stabilization at texel boundaries keep cropped artwork consistent. Camera zoom applies after world-space expansion. Outlines add no textures, instance attributes, or draw passes, and do not alter physics bounds. BreakableBlocks disables the flag, which ParticleBurst copies to every shard.

ParticleBurst partitions a sprite's current frame into an integer grid. It maps each cell through the original anchor, scale, rotation, and flips, then gives each piece an independent center, velocity, spin, and fade. Odd dimensions distribute all source pixels exactly. Motion uses constant-acceleration integration and clamps at the configured lifetime. ParticleSystem retires the owning entity through the registry's deferred queue. A burst uses one SpriteGroup and one entity regardless of its piece count.

ImageRenderer draws Tiled image layers between tile batches in map order. A vertex-ID quad covers the visible part of the image, or the viewport along repeated axes. The fragment shader wraps texel coordinates, so a wide view does not create more geometry. PHP reduces the repeat phase before sending it to the GPU, retaining precision at negative and distant world coordinates. Integer texel fetches stabilize non-power-of-two repeat boundaries. Each image uploads once per renderer, including animated frames when first used; changing the camera or scrolling updates only uniforms.

Parallax calculates layout without SDL. Normal image and tile layers use Tiled's view-center reference and the map's world-space parallax origin. Anchored strips use a common top/center/bottom stack offset and world-camera coordinates multiplied by independent camera factors. Their phase excludes map placement and map-local parallax origins, so matching stacks stay aligned across maps. Time is expressed in milliseconds, and automatic scrolling in pixels per second. The implementation uses explicit image frames instead of the reference project's DOM background and palette-recolor pipeline.

WorldRenderer draws image layers before each map's first tile layer in a background pass. Only the ready map containing the camera center contributes its anchored stack; overlaps use manifest order. While that map is pending or the center is in a gap, the previous visible ready stack remains selected, falling back to the first visible ready map. Regular image layers still render for all visible maps. The remaining layers follow in map order. This keeps repeating skies behind terrain and prevents duplicate alpha blending at boundaries. MapRenderer's optional start/end layer indexes describe a half-open range for these passes, and its `anchoredImages` argument suppresses anchored images for unselected backgrounds.

Format semantics follow the official [Tiled JSON format](https://doc.mapeditor.org/en/stable/reference/json-map-format/), [global tile IDs](https://doc.mapeditor.org/en/stable/reference/global-tile-ids/), and [world format](https://doc.mapeditor.org/en/stable/manual/worlds/).

## Where raycasting prevents tunneling

Physics/Terrain.php provides two casts:

1. raycast intersects a ray segment with front-facing terrain edges. It walks crossed spatial cells and returns the earliest intersection along the entire ray.
2. castBody casts an oriented rectangle through its full displacement. Candidate segments expand by body extents; interval intersection on three separating axes finds the first contact. Segment endpoints participate, so obstacles smaller than the spacing between foot sensors still block movement.

Platformer::sweep uses castBody for horizontal, vertical, and diagonal movement, including dash, fall, flight, glide, and climb. Its optional onImpact callback receives the earliest body hit before collision response. Hit::$shape identifies removable geometry; a null handle means permanent terrain. If the callback removes the contacted shape, the sweep advances to that contact and recasts the remaining displacement without clipping velocity. Removed shapes do not consume the six-contact budget; a separate limit of 1024 removals bounds callbacks that keep creating obstacles. Otherwise, the sweep moves to contact, projects velocity and remaining displacement onto the surface, then repeats for up to six contacts. Unresolved displacement is discarded. Callback return values alone cannot disable a collision.

Platformer::adhere casts two short inward rays for surface height and angle. Platformer::stand checks head clearance. Platformer::climb probes the wall's end. Grounded movement updates its tangent in increments of at most four pixels, capped at 1024 increments per step. Each increment still uses a continuous cast.

Bodies must start outside solid geometry. Geometry can change between steps or in the impact callback, after a body cast has completed. Casts and sensor probes are read-only. Matching opposite tile edges are linked during construction and cancel during a query when both neighbors belong to active collision layers. Arbitrary intersecting polygons are not boolean-unioned.

Terrain::addShape returns a stable removal handle. Segment and polygon IDs remain monotonic after removals. Terrain::removeShape clears only the affected spatial buckets and opposite-edge links, so surviving neighbors become exposed without rebuilding the map. Permanent Terrain::add calls discard the extra ownership record. BreakableBlocks ties these handles to intact block components. Its impact method shatters only when Body::$rolling is true and the contacted handle belongs to an intact block. Walking, nearby action presses, and resting support leave it solid. Shattering validates and prepares a frozen sprite grid before removing collision, then retains the same entity as a nonsolid particle burst. Entity destruction and scene cleanup release the owned geometry. Blocks have stationary collision rectangles; moving platforms and particle collision are outside this component's behavior.

Each body selects one positive collision layer in addition to shared layer 0. MapTerrain reads layer properties or Collision N names and creates nonsolid LayerSwitch regions from Tiled objects. Platformer compares continuous body casts with continuous gate crossings, processes the earliest event, and recasts remaining motion after changing planes. Gates do not consume the terrain-contact budget. Destination occupancy and oriented-body intersection checks reject newly solid geometry before a switch; blocked gates retry while overlapping. Adhesion, standing, jump-platform and climbing probes all use the body's selected plane.

The [loop guide](loops.md) analyzes moving-boxes-2's big-loop tileset, its shared crown and two selectable halves, and its three directional gates. The PHP implementation uses original procedural tiles with polygon collision metadata, tangent-speed routing at the ceiling, airborne crown-gate behavior, and swept triggers. Rendering remains independent of collision selection. The demo anchors sprites along the rotated body normal so their feet stay on the loop surface.

## Ownership and limits

Close renderers before Device::close destroys the context. MapRenderer owns its Atlas and ImageRenderer. SpriteRenderer borrows an Atlas. WorldRenderer owns resident map renderers. Engine runs user cleanup on stop and runtime refresh.

World indexes manifest bounds in a static AABB tree. Queries preserve manifest painter order and allocate no cells between distant maps. Optional local bounds cover negative infinite chunks without shifting their world origin. With mounted files, World::visible loads intersecting maps synchronously. WorldStream instead polls a host file-transfer callback, discovering JSON, external tilesets, tile images, and image-layer frames in PHP. It activates maps only when dependencies are mounted. WorldRenderer accepts either source and never starts synchronous loads for pending streamed maps.

Tigl's compiled world keeps map headers available before initializing its maps. SpriteBoard requests nearby renderers, TileMap caches initialization, and Session limits nearby entity simulation. WorldStream uses the standard explicit Tiled manifest bounds and adds separate preload and retention margins. Per-file reference counts keep shared files mounted until their last map leaves. Applications consume entered/exited changes to manage entities and collision terrain; they can gate motion on isReady for the body's swept bounds. Eviction discards map edits unless the application saves them.

browser/AssetLoader.mjs only handles fetch, cancellation, and mounting. Promise identity checks prevent obsolete responses from writing to a restarted or closed session. Files are scoped to a configured source root. The PHP frame loop polls readiness, so downloads never suspend a PHP animation-frame callback. Live demos load source, shaders, and menu fonts at startup, then request the selected map or world dependencies. GPU test fixtures still mount their assets up front. AssetLoader scopes share pending transfers and mounted files across active and staged scenes. A scene closes its scope only after releasing graphics; shared files remain until their final owner leaves. Whole maps are streamed; individual infinite-map chunks are not fetched separately.

The atlas packs each map's source images and reports GPU capacity errors. Dense tile metadata currently uses one column per tile. Device listens for WebGL loss and restoration and checks `isContextLost()` before each frame, including the interval before the loss event arrives. Engine pauses hidden or lost-context frames, resets its time baseline and input on resume, and invokes the third `start()` callback to restore GPU resources before drawing. A loss during rendering also suspends the loop. Renderers retain PHP data while rebuilding programs, uniform locations, buffers, VAOs, texture arrays, atlas animation metadata, and image caches. Restore a shared Atlas through its owner before restoring sprite batches. Stop detaches browser listeners and releases resources even while the context is lost. Full browser page discard still requires application persistence.

## Live demo level changes

DemoGame retains one Engine, registry, clock, camera, player, and pause menu. DemoLevel stages a destination using WorldStream, including a one-map manifest for standalone maps. Its renderer and collision data are prepared at a frame boundary before the old level is closed. The player keeps its identity and profile but moves to the new level's spawn. Menu zoom, player name, hint settings, audio, and fullscreen persist.

Failures close only staged resources. The previous scene remains usable, and another selection retries or replaces the request. Leaving breakables removes that level's systems, colliders, intact blocks, debris, and ECS listener. Registry::off detaches the exact callback previously passed to Registry::on. World streaming remains a camera-navigation demo; its proximity loading continues after the scene swap.
