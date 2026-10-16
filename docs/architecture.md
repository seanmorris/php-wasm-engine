# Implementation notes

## Reference projects

The initial design was informed by Sean Morris's local projects:

- tigl/app/world/World.js, TileMap.js, and sprite/SpriteBoard.js: manifest bounds, spatial queries, deferred map initialization, cached requests, and visibility-driven renderer lifetimes.
- tigl/app/sprite/Parallax.js: repeating image strips, independent scroll factors, and viewport anchors.
- moving-boxes-2/app/backdrop/Backdrop.js: stacked strips, automatic drift, and animated image frames.
- moving-boxes-2/app/actor/Block.js: rider-weighted settling, single-axis squine and two-axis
  elliptical oscillation, and linked `path`/`next` route nodes. `Platforms` separates collision
  supports, oscillators, and path cursors into ECS components. `PlatformTransport` casts the full
  relative motion to detect pushes, then casts passengers against world geometry before moving
  the support. Each path segment is processed separately so corners remain intact.
- soop: object components, boolean queries, systems, and deferred structural commands.
- moving-boxes-2/app/behavior/Platformer.js: surface modes, ground/air velocity, terrain sensors, tangent momentum, and normal-directed jumps.
- moving-boxes-2/app/behavior/Crouch.js and Spindash.js: grounded crouch state, Down + tap 0 or hold 1 charging, passive charge decay, and Down-release launch.
- moving-boxes-2/app/actor/Sonic.js: landing-triggered drop dash, braking and spindash visual
  displacement triggers, signed effect strengths, and button-2 ring acquisition, light-dash
  chaining, nine-frame timers, and combo accrual.
- moving-boxes-2/app/actor/PointActor.js, app/effects/Twist.js, and the viewport background-filter
  layer: the radial tangential field placed between the map and actor artwork.
- moving-boxes-2/app/actor/Knuckles.js: glide, wall catching, climbing, and jumping away.
- moving-boxes-2/app/actor/Tails.js: lift, flight duration, reduced gravity, and exhaustion.
- moving-boxes-2/app/region/WaterRegion.js and the viewport waves filter: liquid gravity, drag,
  density, surface skimming, tinted clipping, and scrolling displacement.
- moving-boxes-2/app/behavior/Platformer.js, region/GrindingRegion.js, actor/SkateBoard.js,
  actor/PointActor.js, and viewport/Viewport.js: annotated grinding surfaces, retained direction,
  minimum rail speed, airborne combo links, summed reward multipliers, settlement, and top-right HUD.
- moving-boxes-2/app/actor/CrabMeat.js, behavior/Patrol.js, mixin/CanPop.js, and actor/Projectile.js: reusable badnik contacts, alternating patrol phases, paired delayed shots, ballistic motion, and player attack responses.
- moving-boxes-2/app/actor/PointActor.js and actor/Ring.js: bounded radial ring loss, a delayed recovery window, reduced collectible sets, ballistic gravity, terrain bounces, and expiry.
- moving-boxes-2/app/actor/Monitor.js, BrokenMonitor.js, and actor/monitor ring variants: solid reward boxes, attack-triggered popping, separated screen art, one-shot effects, broken casings, and airborne rebounds.
- moving-boxes-2/app/powerups/NormalSheild.js, ElectricSheild.js, FireSheild.js, BubbleSheild.js, StarSheild.js, and their monitor actors: one-hit defense, elemental immunity, retained selections, temporary star override, and shield-specific airborne controls. The PHP API corrects the reference source's `Sheild` spelling while accepting it in imported Tiled class and reward names.
- moving-boxes-2/app/controller/Controller.js, Axis.js, and GamepadConfig.js: left-stick/D-pad movement, A jump, B dash actions, Start pause, and a proportional 20% stick dead zone.

The PHP implementation has its own data model, solver, units, control state, and renderer. The CrabMeat and monitor demo uses original procedural artwork and component systems rather than the reference framework's actor hierarchy.

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

Sprite::$outline defaults to true and occupies a transform flag in the instance record. The vertex shader expands the quad by one world unit along each local axis before rotation. It keeps the artwork's original anchor and computes the outline radius in source pixels from the absolute sprite scale. The fragment shader preserves visible artwork and fills transparent pixels with black using the largest alpha in that source neighborhood. It clamps every lookup to the current frame or crop. Checking intermediate texels keeps outlines continuous when source pixels shrink below one world pixel. Fully opaque neighbors end the search early; opt-outs skip expansion and the neighborhood scan. Integer texel fetches and stabilization at texel boundaries keep cropped artwork consistent. Camera zoom applies after world-space expansion. Outlines add no textures or draw passes and do not alter physics bounds. BreakableBlocks disables the flag, which ParticleBurst copies to every shard.

BackdropTwistRenderer captures map output in an RGBA8 texture, restores that texture to the caller's framebuffer, and redraws a clipped 64-world-pixel square through a cubic radial tangential field. Sprites render after composition, matching the reference background-filter layer and keeping actor and particle pixels unchanged. The renderer remembers its parent framebuffer, so it can capture and compose inside WaterRenderer before sprites enter the water pass. It owns one texture, framebuffer, vertex array, and shader program and recreates them after context restoration. The demo applies the reference braking and spindash offsets at the player's support point, derives braking strength from opposing tangent speed with a ±256 clamp, and derives spindash strength from retained direction and normalized charge up to ±120. Inactive frames skip capture and both extra draws.

ParticleBurst partitions a sprite's current frame into an integer grid. It maps each cell through the original anchor, scale, rotation, and flips, then gives each piece an independent center, velocity, spin, and fade. Odd dimensions distribute all source pixels exactly. Motion uses constant-acceleration integration and clamps at the configured lifetime. ParticleSystem retires the owning entity through the registry's deferred queue. A burst uses one SpriteGroup and one entity regardless of its piece count.

ImageRenderer draws Tiled image layers between tile batches in map order. A vertex-ID quad covers the visible part of the image, or the viewport along repeated axes. The fragment shader wraps texel coordinates, so a wide view does not create more geometry. PHP reduces the repeat phase before sending it to the GPU, retaining precision at negative and distant world coordinates. Integer texel fetches stabilize non-power-of-two repeat boundaries. Each image uploads once per renderer, including animated frames when first used; changing the camera or scrolling updates only uniforms.

Parallax calculates layout without SDL. Normal image and tile layers use Tiled's view-center reference and the map's world-space parallax origin. Anchored strips use a common top/center/bottom stack offset and world-camera coordinates multiplied by independent camera factors. Their phase excludes map placement and map-local parallax origins, so matching stacks stay aligned across maps. Time is expressed in milliseconds, and automatic scrolling in pixels per second. The implementation uses explicit image frames instead of the reference project's DOM background and palette-recolor pipeline.

WorldRenderer draws image layers before each map's first tile layer in a background pass. Only the ready map containing the camera center contributes its anchored stack; overlaps use manifest order. While that map is pending or the center is in a gap, the previous visible ready stack remains selected, falling back to the first visible ready map. Regular image layers still render for all visible maps. The remaining layers follow in map order. This keeps repeating skies behind terrain and prevents duplicate alpha blending at boundaries. MapRenderer's optional start/end layer indexes describe a half-open range for these passes, and its `anchoredImages` argument suppresses anchored images for unselected backgrounds.

WaterRenderer captures map and sprite output into one RGBA8 framebuffer. Its base pass copies that
scene to the default framebuffer. Each visible water rectangle then redraws its clipped area with
a procedural repeating RG displacement texture, a small horizontal blur, and the Tiled tint.
World-space lookup coordinates keep wave phase continuous across neighboring regions and camera
movement. HUD and menu passes follow composition, so screen-space text is not displaced. The
renderer owns its framebuffer, color texture, wave texture, vertex array, and shader program, and
recreates each one after context restoration.

Format semantics follow the official [Tiled JSON format](https://doc.mapeditor.org/en/stable/reference/json-map-format/), [global tile IDs](https://doc.mapeditor.org/en/stable/reference/global-tile-ids/), and [world format](https://doc.mapeditor.org/en/stable/manual/worlds/).

PlatformCamera separates gameplay framing from the render camera. Modes select target viewport
anchors for ground, wall, ceiling, air, climbing, tubes, bosses, scripted focus points, and the
other moving-boxes-2 camera contexts. High ground or air speed biases the target along the active
surface. Anchor changes use the reference frame divisors converted to elapsed-time exponential
damping. Camera position uses the same conversion with faster panning for scripted focus changes.
World rectangles and camera-coordinate limits clamp the desired top-left point before damping.
Consequently, a newly engaged boss arena changes the camera destination without changing its
current pose, and release pans back through the same path. Direct Camera::follow remains the small
general-purpose primitive; Camera::panTo accepts a top-left destination for policy controllers.

## Where raycasting prevents tunneling

Physics/Terrain.php provides two casts:

1. raycast intersects a ray segment with front-facing terrain edges. It walks crossed spatial cells and returns the earliest intersection along the entire ray.
2. castBody casts an oriented rectangle through its full displacement. Candidate segments expand by body extents; interval intersection on three separating axes finds the first contact. Segment endpoints participate, so obstacles smaller than the spacing between foot sensors still block movement.

Platformer::sweep uses castBody for horizontal, vertical, and diagonal movement, including dash, fall, flight, glide, and climb. Its optional onImpact callback receives the earliest body hit before collision response. Hit::$shape identifies removable geometry; a null handle means permanent terrain. If the callback removes the contacted shape, the sweep advances to that contact and recasts the remaining displacement without clipping velocity. Removed shapes do not consume the six-contact budget; a separate limit of 1024 removals bounds callbacks that keep creating obstacles. Otherwise, the sweep moves to contact, projects velocity and remaining displacement onto the surface, then repeats for up to six contacts. Unresolved displacement is discarded. Callback return values alone cannot disable a collision.

Platformer::adhere casts two short inward rays for surface height and angle. Platformer::stand checks head clearance. Platformer::climb probes the wall's end. Grounded movement updates its tangent in increments of at most four pixels, capped at 1024 increments per step. Each increment still uses a continuous cast.

WaterRegions uses a slab intersection test over the body's complete center displacement. A body
that starts and ends outside a liquid rectangle still reports both waterEntered and waterExited
when it crosses the volume in one step. Surface skimming compares the complete vertical movement
with the surface and snaps only actors at or above the configured horizontal speed. These regions
are nonsolid and do not replace Terrain::castBody for walls, floors, or underwater collision.
Because rail contact and surface support are resolved independently, a rail placed at the exact
waterline can report both `grinding` and `skimming`. Extending each rail into the water rectangle
creates an overlap zone, so leaving or entering the open surface does not require crossing a
vertical liquid boundary.

Badniks stores each player's and badnik's previous center. It performs a relative swept AABB test over both full displacements, which catches fast rolling attacks and normal contacts even when the rectangles do not overlap at either endpoint. Projectile contacts sweep the player and shot relative to one another. Projectile terrain motion constructs a small body and calls `Terrain::castBody()` for the complete ballistic displacement before committing the new position. These checks prevent high-speed actors and shots from tunneling through their targets. CrabMeats uses short downward rays to retain floor contact and `castBody()` to stop horizontal patrol movement at a wall.

Rings applies the same continuous rules to damage bursts. Each `ScatteredRing` casts its circular body over the complete ballistic step, reflects velocity from the earliest terrain normal, and processes up to four contacts. Pickup checks subtract ring motion from player motion before testing the swept bounds. Fast bounces and player crossings therefore cannot pass through thin terrain or each other between fixed updates. A pickup delay prevents the player from immediately reclaiming rings while still inside the burst. Decorative members never enter the pickup path and expire earlier.

Monitors inserts each intact box into Terrain as a removable rectangle. `Platformer::sweep()` reports the earliest shape to `Monitors::impact()` over the actor's complete displacement. An eligible roll or jump somersault removes that exact handle before the sweep recasts its remaining movement. Walking and plain falling retain the shape and receive ordinary collision response. The same cast therefore prevents a high-speed attacker from skipping a thin monitor and prevents an ineligible body from passing through it. The reward screen is a separate sprite component and has no collision after the pop.

Shield monitor rewards attach a `Shield` inventory component to the player. Defense remains in `PlayerCombat`, where elemental immunity is checked before mercy or knockback and an ordinary absorption is resolved before ring loss. `Badniks` therefore uses the same contact and projectile sweeps with a damage category; shields do not introduce a second collision path. `Shields` owns optional aura entities and movement abilities. Its control hook runs before `Platformer::step()`, while its scheduled update runs after badnik damage to remove consumed art in the same simulation step and to detect water-shield landings. Star stores the selected persistent shield, overrides it for a bounded timer, and restores it without copying renderer state.

Bodies must start outside solid geometry. Geometry can change between steps or in the impact callback, after a body cast has completed. Casts and sensor probes are read-only. Matching opposite tile edges are linked during construction and cancel during a query when both neighbors belong to active collision layers. Arbitrary intersecting polygons are not boolean-unioned.

Shield input follows moving-boxes-2's command routing. `Shields::control` runs before movement and returns whether it consumed Jump. `Platformer::step` accepts that result, preventing the same edge from also starting flight or glide. Flight-capable profiles reserve neutral Jump for their native ability, use a vertical modifier for electric jump, and use Down for water bounce. These abilities require `Body::$jumping`, which is set by an actual jump and remains false after simply walking off a ledge. Fire thrust changes horizontal speed without replacing an active flight or glide state. Climbing rejects airborne shield movement.

`Body::$jumping` and `Body::$grounded` are independent. A surface jump sets the former for the complete airborne arc, selects the compact somersault bounds, and clears it when collision finds a landing. Loss of floor adhesion only clears `grounded`, producing a plain fall at standing height. Player combat, monitors, and shield jumps use `jumping` rather than treating every airborne body as an attack. Ground rolling remains a separate state and is not inherited from a jump at landing.

Terrain::addShape returns a stable removal handle. Segment and polygon IDs remain monotonic after removals. Terrain::removeShape clears only the affected spatial buckets and opposite-edge links, so surviving neighbors become exposed without rebuilding the map. Permanent Terrain::add calls discard the extra ownership record. BreakableBlocks ties these handles to intact block components. Its impact method shatters only when Body::$rolling is true and the contacted handle belongs to an intact block. Walking, nearby action presses, and resting support leave it solid. Shattering validates and prepares a frozen sprite grid before removing collision, then retains the same entity as a nonsolid particle burst. Entity destruction and scene cleanup release the owned geometry. Blocks have stationary collision rectangles; moving platforms and particle collision are outside this component's behavior.

Each body selects one positive collision layer in addition to shared layer 0. MapTerrain reads layer properties or Collision N names and creates nonsolid LayerSwitch regions from Tiled objects. Platformer compares continuous body casts with continuous gate crossings, processes the earliest event, and recasts remaining motion after changing planes. Gates do not consume the terrain-contact budget. Destination occupancy and oriented-body intersection checks reject newly solid geometry before a switch; blocked gates retry while overlapping. Adhesion, standing, jump-platform and climbing probes all use the body's selected plane. When an upward climber's side probe passes a wall end, a second probe looks beyond the edge for an upward-facing surface and checks standing clearance. A valid result places the body on top facing inward; a missing or blocked surface keeps the ordinary airborne detach path.

Grinding is metadata on the same indexed collision segments. MapTerrain reads `grinding` or
`grind` from a layer, tile, collision shape, or object. `Rail` and `Grinding` polylines become open
one-way paths, while `Terrain::addRail()` supplies the equivalent scripting API. `Hit::$grinding`
carries the tag through body casts and foot rays. Platformer therefore enters a rail on the exact
continuous landing contact, adheres across ordinary sloped segments, and leaves an endpoint with
tangent momentum. The system does not sample by speed, so a thin rail remains reachable during a
large fixed-step displacement.

`Body::$facing` is the visual look direction, while `Body::$direction` is the retained surface and
action orientation; `Body::$vx` remains the actual horizontal air movement. Opposite input during a
grind turns the sprite without reversing rail travel, and the rail solver keeps `direction` aligned
with tangent speed until the actor can actually reverse. Tails-style flight keeps its takeoff look
direction while ordinary horizontal air acceleration remains active, allowing velocity to pass
through zero and reverse without turning the airborne sprite. Gliding keeps a separate
`Body::$glideDirection` for the last horizontal steering request. Opposite input brakes at four
times the normal glide acceleration; neutral input retains that request, and `facing`/`direction`
follow actual horizontal velocity after it crosses zero. Starting a glide keeps existing travel
direction, falling back to facing only at rest. Landing, climbing, cancellation, and demo resets
clear the requested turn. The existing 420 px/s glide target remains unchanged, with 240 px/s²
acceleration and 960 px/s² reversal braking.

MB2's `Knuckles.js` uses a stick-dependent limit of `16 + 8 * abs(xAxis)` pixels per frame,
halved underwater, and separate flying, turning, and stalled animations. This change carries over
its steering state and reversal rules while retaining the PHP controller's speed tuning and the
demo's existing glide artwork.

Dropdash applies held landing input before choosing the boost direction, matching
`PointActor::processInputDirect()` followed by `Platformer::checkDropDash()` in MB2. Neutral
input retains the earlier direction. A held opposite direction subtracts its boost from existing
landing momentum; it does not force the entire velocity to reverse. Fast grinding retains its
separate travel-direction lock.

`LightDash` is per-player ECS state. `LightDashes` reads live `Ring` components and receives the
generic `Rings` post-pickup notification. Initial acquisition requires a forward pair within 48
pixels; chaining searches within 128 pixels after all same-step pickups retire. World-space
centers replace MB2's bottom-anchored actor offsets. Angular comparisons use a dot product or
wrapped difference so leftward trails work across the minus/plus-pi boundary. The reference speed
formula is converted to pixels per second with a 1,650 cap, and nine reference frames become .15
seconds. No teleport or collision bypass is used: `Platformer` retains gravity and full-motion
body casts while suppressing directional input. Solid impacts release the active ability, and
the next control step retires its target. The demo clears route state on respawn, profile changes,
and level changes, and gates shield controls while the dash owns input.

Combo is a player component rather than renderer state. `ComboReward` retains a live point value,
an additive multiplier, a label, and optional presentation metadata. `Combo::update()` owns rail
accrual and safe-landing settlement, returns a score exactly once, and keeps success or failure data
long enough for any HUD to inspect. DemoComboHud reads that component after world rendering and
draws it in screen space with the shared annotated font atlas. Badnik damage fails the component
through the registry; other gameplay systems can call `add()`, `settle()`, or `fail()` directly.

The generated grinding course combines these systems with the three-plane loop layout. Matching
polygons first switch from rail to ordinary ground and back without changing surface height. Four
separate quarter-circle boundaries raise and lower the rail before the loop. Entrance, crown, and
exit gates then switch its active lower half by travel direction. Every loop plane is tagged
`grinding`, so body casts and adhesion retain the rail state through vertical and inverted contacts
at normal and extreme speeds.

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

## Grapple constraints and riders

`GrappleGrip` opts a `Body` into automatic swept contact with a `GrapplePoint`. `Grapples` owns
handle and anchor entities; each handle also has a `Rope` and optional `Sprite`/`SpriteGroup`.
`GrappleLoader` resolves `tiedTo` across flattened layers, translates world offsets, and chooses
annotated atlas art. An application resolver can link anchor proxies to existing body, suspended
platform, or buoyant platform entities without transferring their ownership.

The movement update records swing input and consumes a fresh Jump to detach. `Platformer` retains
water-state updates but skips ordinary motion while the ability is `hanging`. After moving-anchor
systems run, `Grapples` interpolates anchor movement and advances the rope through short curvature
increments. Each increment uses full-distance terrain casts for the hook and every rider. The
rope clamps its maximum length and removes outward relative velocity while retaining tangential
momentum and slack. Contact also sweeps relative player/handle bounds; alignment requires free
standing bounds and a clear cast. Obstructed transport releases riders if the anchor pulls out of
reach. Ropes do not wrap around terrain.

The first rider pumps the shared rope; release uses anchor velocity when available, endpoint
velocity otherwise, and a three-quarter jump impulse for button 0. Per-handle cooldowns prevent
reattachment for fifteen reference frames. Damage detaches without replacing knockback. Reset,
level retirement, missing anchors, and handle removal clear attachments and grouped rope sprites.
`PlatformCamera` derives its existing `hooked` preset from the hanging ability. Ring pickup runs
after grapple transport so collections see the carried position.
