# Implementation notes

## Reference projects

The initial design was informed by Sean Morris's local projects:

- tigl: Tiled worlds, maps, tilesets, sprite sheets, properties, and world-space lookup.
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

Isometric drawing orders diagonals globally, including negative chunks, at the cost of more draws. Games order sprites before passing them to SpriteRenderer. Objects and image layers retain metadata; games supply their graphics.

Format semantics follow the official [Tiled JSON format](https://doc.mapeditor.org/en/stable/reference/json-map-format/), [global tile IDs](https://doc.mapeditor.org/en/stable/reference/global-tile-ids/), and [world format](https://doc.mapeditor.org/en/stable/manual/worlds/).

## Where raycasting prevents tunneling

Physics/Terrain.php provides two casts:

1. raycast intersects a ray segment with front-facing terrain edges. It walks crossed spatial cells and returns the earliest intersection along the entire ray.
2. castBody casts an oriented rectangle through its full displacement. Candidate segments expand by body extents; interval intersection on three separating axes finds the first contact. Segment endpoints participate, so obstacles smaller than the spacing between foot sensors still block movement.

Platformer::sweep uses castBody for horizontal, vertical, and diagonal movement, including dash, fall, flight, glide, and climb. It moves to contact, projects velocity and remaining displacement onto the surface, then repeats for up to six contacts. Unresolved displacement is discarded.

Platformer::adhere casts two short inward rays for surface height and angle. Platformer::stand checks head clearance. Platformer::climb probes the wall's end. Grounded movement updates its tangent in increments of at most four pixels, capped at 1024 increments per step. Each increment still uses a continuous cast.

Bodies must start outside solid geometry; terrain stays static during a step. Matching opposite tile edges cancel during construction. Arbitrary intersecting polygons are not boolean-unioned.

## Ownership and limits

Close renderers before Device::close destroys the context. MapRenderer owns its Atlas. SpriteRenderer borrows an Atlas. WorldRenderer owns resident map renderers. Engine runs user cleanup on stop and runtime refresh.

The world file is a manifest. Intersecting maps load lazily. Each loaded map retains decoded chunks, and its renderer allocates the map's tile planes. WorldRenderer limits off-screen GPU residency; Loader retains data until forgotten. Sparse maps avoid allocating the rectangle between distant chunks. Individual chunks are not streamed from a server protocol.

The atlas packs each map's source images and reports GPU capacity errors. Dense tile metadata currently uses one column per tile. Context restoration requires recreating the device and render resources.
