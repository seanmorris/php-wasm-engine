# Implementation notes

## Reference projects

The initial design was informed by Sean Morris's local projects:

- tigl: Tiled worlds, maps, tilesets, sprite sheets, properties, and world-space lookup.
- tigl/app/sprite/Parallax.js: repeating image strips, independent scroll factors, and viewport anchors.
- moving-boxes-2/app/backdrop/Backdrop.js: stacked strips, automatic drift, and animated image frames.
- soop: object components, boolean queries, systems, and deferred structural commands.
- moving-boxes-2/app/behavior/Platformer.js: surface modes, ground/air velocity, terrain sensors, tangent momentum, and normal-directed jumps.
- moving-boxes-2/app/actor/Sonic.js: charge dash and landing-triggered drop dash.
- moving-boxes-2/app/actor/Knuckles.js: glide, wall catching, climbing, and jumping away.
- moving-boxes-2/app/actor/Tails.js: lift, flight duration, reduced gravity, and exhaustion.

The PHP implementation has its own data model, solver, units, control state, and renderer. Original actors, framework integration, combat, powerups, and artwork are not included.

## PHP and browser boundaries

Asset, Tiled, Ecs, FixedStep, Controls, and Physics are ordinary PHP. Render and SdlInput call php-sdl-wasm extensions. Engine uses vrzno for frame scheduling and cleanup. The demo's PHP code updates its DOM status through vrzno.

docs/bootstrap.mjs imports the runtime and mounts files. It contains no movement, map parsing, entity processing, or rendering logic. Optional zlib uses php-sdl-wasm's sharedLibs resolver.

## GPU representation

Tiled GIDs use flag bits above PHP WASM's signed integer range. Gid decodes without narrowing to signed int32 and stores four bytes. The renderer remaps sparse global IDs to dense atlas indexes.

Layers are split into at most 32×32 cells. Texture arrays are bounded by GL_MAX_ARRAY_TEXTURE_LAYERS. Independent instance attributes retain layer placement and appearance. Dirty revisions trigger a slice upload. Floating-point metadata supplies atlas rectangles and offsets; animation updates metadata rather than every tile.

Isometric drawing orders diagonals globally, including negative chunks, at the cost of more draws. Games order sprites before passing them to SpriteRenderer. Objects retain metadata; games supply their graphics.

ImageRenderer draws Tiled image layers between tile batches in map order. A vertex-ID quad covers the visible part of the image, or the viewport along repeated axes. The fragment shader wraps texel coordinates, so a wide view does not create more geometry. PHP reduces the repeat phase before sending it to the GPU, retaining precision at negative and distant world coordinates. Integer texel fetches stabilize non-power-of-two repeat boundaries. Each image uploads once per renderer, including animated frames when first used; changing the camera or scrolling updates only uniforms.

Parallax calculates layout without SDL. Normal image and tile layers use Tiled's view-center reference and the map's world-space parallax origin. Anchored strips use a common top/center/bottom stack offset and independent camera factors. Time is expressed in milliseconds, and automatic scrolling in pixels per second. The implementation uses explicit image frames instead of the reference project's DOM background and palette-recolor pipeline.

WorldRenderer draws image layers before each map's first tile layer in a background pass across all visible maps. The remaining layers follow in map order. This keeps repeating skies behind terrain at map boundaries. MapRenderer's optional start/end layer indexes describe a half-open range for these passes.

Format semantics follow the official [Tiled JSON format](https://doc.mapeditor.org/en/stable/reference/json-map-format/), [global tile IDs](https://doc.mapeditor.org/en/stable/reference/global-tile-ids/), and [world format](https://doc.mapeditor.org/en/stable/manual/worlds/).

## Where raycasting prevents tunneling

Physics/Terrain.php provides two casts:

1. raycast intersects a ray segment with front-facing terrain edges. It walks crossed spatial cells and returns the earliest intersection along the entire ray.
2. castBody casts an oriented rectangle through its full displacement. Candidate segments expand by body extents; interval intersection on three separating axes finds the first contact. Segment endpoints participate, so obstacles smaller than the spacing between foot sensors still block movement.

Platformer::sweep uses castBody for horizontal, vertical, and diagonal movement, including dash, fall, flight, glide, and climb. It moves to contact, projects velocity and remaining displacement onto the surface, then repeats for up to six contacts. Unresolved displacement is discarded.

Platformer::adhere casts two short inward rays for surface height and angle. Platformer::stand checks head clearance. Platformer::climb probes the wall's end. Grounded movement updates its tangent in increments of at most four pixels, capped at 1024 increments per step. Each increment still uses a continuous cast.

Bodies must start outside solid geometry; terrain stays static during a step. Matching opposite tile edges cancel during construction. Arbitrary intersecting polygons are not boolean-unioned.

## Ownership and limits

Close renderers before Device::close destroys the context. MapRenderer owns its Atlas and ImageRenderer. SpriteRenderer borrows an Atlas. WorldRenderer owns resident map renderers. Engine runs user cleanup on stop and runtime refresh.

The world file is a manifest. Intersecting maps load lazily. Each loaded map retains decoded chunks, and its renderer allocates the map's tile planes. WorldRenderer limits off-screen GPU residency; Loader retains data until forgotten. Sparse maps avoid allocating the rectangle between distant chunks. Individual chunks are not streamed from a server protocol.

The atlas packs each map's source images and reports GPU capacity errors. Dense tile metadata currently uses one column per tile. Device listens for WebGL loss and restoration and checks `isContextLost()` before each frame, including the interval before the loss event arrives. Engine pauses hidden or lost-context frames, resets its time baseline and input on resume, and invokes the third `start()` callback to restore GPU resources before drawing. A loss during rendering also suspends the loop. Renderers retain PHP data while rebuilding programs, uniform locations, buffers, VAOs, texture arrays, atlas animation metadata, and image caches. Restore a shared Atlas through its owner before restoring sprite batches. Stop detaches browser listeners and releases resources even while the context is lost. Full browser page discard still requires application persistence.
