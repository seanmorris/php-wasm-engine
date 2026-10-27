# Doom-Phonics

`@seanmorris/doom-phonics` is a source-format adapter, renderer, and reusable set of first-person systems for Phonics. It parses IWAD/PWAD resources and classic binary maps in native PHP and does not depend on Sane-Phonics or a Tiled runtime.

The browser host resizes the SDL/WebGL framebuffer to the canvas's physical display size, including fullscreen and high-density displays. Native resolution is the default. The Episode system set's `renderScale` option defines the alternate Classic resolution scale; `0.5` renders the three-dimensional scene at half width and height, upscales it with nearest sampling, and keeps the weapon, status bar, menus, and screen wipes at the full output resolution. Indexed WAD textures and sprites retain their blocky nearest-neighbor sampling in both modes while Native keeps polygon and billboard edges sharp. The renderer batches static map geometry, visible masked actors, and the HUD separately; it also uses BSP sectors, the WAD REJECT table, and camera-frustum culling before expanding sprite vertices. A compact depth-only mesh preserves finite sky ceilings and shared-sky portal clipping without drawing seams over the panoramic sky.

Its optional `movement` object defines `maxSpeed`, `acceleration`, `gravity`, `maxStep`, and a `friction` multiplier per 35 Hz simulation tic. Player motion accumulates in the ECS `Velocity` component, falls between sector planes, and slides along a swept circular body rather than resolving only its final position.

Episode 1 linedef specials are interpreted as typed `LineAction` definitions. Doors, lifts, floors, stairs, and donuts run as `SectorMover` ECS components with one explicit lock per sector plane. Walk, use, and gun triggers preserve their repeat and front-side rules; switches, buttons, scrolling walls, teleports, damage floors, secrets, and animated lights update the same live state consumed by collision and rendering. Map geometry stays static while small recorded VBO spans and the sector data texture carry visual changes to GLES.

World effects use the WAD's DMX samples through a map-scoped audio service. Door, platform, switch, lock, and teleport sounds are attenuated and positioned relative to the player, cached across maps from the same WAD stack, and released with the game session. Browser effects use Web Audio; Ogg music uses browser media playback so connection work and PHP frames cannot stall its audio callback. Native consumers retain the SDL_mixer fallback. The service keeps only the active music track resident and selects `D_INTRO` for the title and `D_ExMy` for episode maps. The repository demo mounts `DOOM1_OGG.wad` after the base IWAD, so the game owns the title mapping directly and does not require a duplicate `D_INTROA` lump. MUS parsing remains available through `MusScore` for consumers that provide a sequencer.

DECORATE actions dispatch into ECS combat primitives. Weapon fire creates an independent `WeaponOverlay` entity for the `Flash` state, applies `A_Light0`–`A_Light2` through `ViewLighting`, and retires the layer when its state flow completes. Actor actions cover the reference renderer's hitscan, projectile, charge, explosion, respawn, floor-clip, pain, death, and boss behaviors. Inline `Goto`, `Loop`, `Wait`, and `Stop` edges remain attached to the frame that declares them, so alternate chaingun and plasma flashes do not run as one long sequence.

Weapon banks combine `Player.WeaponSlot`, each weapon's `Weapon.SlotNumber`, and embedded or loose `KEYCONF` documents in that order. KEYCONF `setslot`, `addslot`, and `addslotdefault` declarations append to the effective banks; the first case-insensitive occurrence of a weapon name keeps its assignment so layered add-ons cannot duplicate or move an already configured weapon.

The optional WRW compatibility harness accepts a locally supplied archive and keeps the parser and slot assertions independent of whether the WAD ships with the repository:

```sh
php packages/doom-phonics/tests/wrw-stress.php /path/to/wrw.wad [/path/to/base.wad]
```

The demo worlds load [SKULLTAG_ACTORS.lmp](demo/SKULLTAG_ACTORS.lmp) as a standalone resource with `role: "decorate"`. Its 103 actor definitions are read directly as text, without a WAD container. It precedes `SKULLTAG-WEAPONS.DEC` so the existing weapon definitions retain precedence. Maps, sprites, and sounds still come from the selected WAD stack; the online world pins the actor lump's integrity so both peers use the same definitions.

[wiki-actors.dec](resources/wiki-actors.dec) fills missing Doom, Doom II, and Skulltag classes and their referenced dependencies. It loads with the built-in definitions before WAD and sidecar overrides. The import includes the Doom II monsters, their projectile/effect actors, stealth variants, scripted marines, team items, and missing powerup parents. Definitions supply properties and states; native actions, invasion spawning, scoring, and ZScript-only features still require engine implementations.

The XML backup, extractor, its tests, and the full extraction report are maintained in a separate import workspace outside this repository. Building the game uses the generated definitions directly and does not require the backup or extractor. The import chooses the newest complete DECORATE definition for each class, expands wiki links/templates, and restores editor numbers from page metadata. It excludes existing classes and tutorial examples, rejects unresolved markup and number conflicts, and records historical fallbacks. Only the Doom/Skulltag subset and required dependencies ship with the game. [Source revisions and transformations](resources/wiki-actors.sources.json), [attribution](resources/wiki-actors.NOTICE), and the [wiki license](resources/wiki-actors.COPYING) accompany the runtime data.

The game opens on a WAD-rendered title menu. Press Enter or the controller A button to select, use the arrows, WASD, or D-pad to move, and press Escape, P, or Start to pause. The pause menu can resume, restart the current map, start a new game at any of the five skill levels, show controls, or return to the title. Its options change look sensitivity, vertical inversion, Native or Classic render resolution, and Perspective or Classic pitch projection without rebuilding the active map.

**WAD Stack** opens the **Game launcher**. Drop every archive into one list, drag or use the arrow controls to set its order, then choose a starting map, mode, and skill. The first archive must be an IWAD. Every later archive uses normal last-archive-wins lookup regardless of its IWAD or PWAD header, so a later complete archive can contribute weapons, sprites, sounds, and other resources while an earlier archive supplies the selected map. **Start locally** runs Single Player, Co-op, or Deathmatch with one local player. The chosen mode controls spawn selection and Doom/Hexen object flags independently of the network connection. **Play online** opens the lobby with the same stack, map, and compatible settings for a two-player offer. Local file bytes and object URLs remain in the browser session. A failed map load reports its error in the launcher and recreates the previous map with its previous mode and skill so another selection can be tried; it does not preserve in-progress gameplay state.

Single Player requires a normal player-one start. Co-op prefers numbered player starts; Deathmatch prefers deathmatch starts. Multiplayer modes can also use team markers on arenas such as D2ST9, assigning occupied slots across the available bases. To explore D2ST9, select **Deathmatch** or **Co-op**, then **Start locally**. These modes do not implement Skulltag team scoring, objectives, or ACS scripts.

**Online Play** contains separate **Enter Lobby** and **Networking** pages. Networking edits and persists the Matrix homeserver and lobby channel through the native Phonics menu before a connection begins. The lobby opens directly into chat or a compact Matrix OAuth action. **Game settings** opens the same launcher as a modal above chat, and offering or accepting a match uses that modal before sending the response. The game never handles a Matrix password. Match offers carry the selected map, archive order, safe filenames, profiles, sizes, and integrity digests. Locally owned archive URLs and bytes are not transmitted; each player selects their own copies. A compatible freely distributable profile may satisfy the first stack entry, while every later override must match exactly.

The host simulates projectiles from both players' fire commands and replicates their stable identities, poses, and DECORATE animation state. The same path handles rockets, plasma, BFG balls, bouncing grenades, monster missiles, and custom projectile definitions. Guests display those missiles and explosions without running collision or damage actions again. Railgun beams replicate their world endpoints, width, duration, and fade progress; the host resolves rail damage and line triggers once. Guests continue fading between snapshots without restarting the effect or creating a second predicted beam. Local weapon animation responds immediately; the guest's projectile or beam appears when its host snapshot arrives. These effects are removed by host despawn snapshots and are owned by the active map scope. Ordinary hitscan puffs use a separate combat path.

Starting or restarting a game, changing maps, warping, and returning to the title use the classic staggered-column screen melt. A session-owned compositor retains the outgoing complete frame while map-owned render resources are replaced, then reveals the incoming 3D scene, HUD, or title at the original 35 Hz logical timing. Gameplay input and simulation remain paused until the transition finishes.

During play, WASD or the left stick moves forward/back and strafes, the left and right arrows or right stick turn, and the mouse looks. Left click, Space, or controller RT/R2 fires; right click or controller LT/L2 uses a weapon's alternate fire. **E** or controller A/Cross opens doors and uses switches. Number keys 1–7 select weapons; the mouse wheel and D-pad cycle owned weapons (left/down for previous, right/up for next). The Controls screen shows button hints for the last-used input device.

Classic keyboard sequences are recognized during play. `IDFA` and the accepted `IDFFA` alias grant weapons, full ammo, and armor; `IDKFA` also grants every key. `IDDQD` toggles damage immunity, while `IDCLIP` and `IDSPISPOPD` toggle collision. `IDBEHOLD` shows the power suffixes and `V`, `S`, `I`, `R`, `A`, or `L` toggles its corresponding power. `IDCHOPPERS`, `IDMYPOS`, and `IDCLEV` followed by an episode and map number are also active. With a Railgun in the active WAD stack, `IDOOPSALLGIBS` leaves the player with that weapon and infinite cells and redirects gun-bearing monsters to runtime rail attacks. It changes live state rather than replacing DECORATE. Cheat input is matched incrementally without taking ordinary movement keys away from the input system.

Lethal damage now enters the player's DECORATE death sequence while the simulation continues. Control and the view weapon are removed, the camera lowers, the player scream and held red PLAYPAL effect play, and Fire or Use restarts after the death delay.

The repository demo uses the unmodified shareware data file under its own redistribution terms. That data is not part of the published npm or Composer package.

## Skulltag D2ST9 stress world

The local [stress world](demo/skulltag-stress.phonics-world.json) loads **Skulltag v0.97d5's D2ST9** over Doom II. Prepare locally owned archives, start the development server, and open the printed world URL:

```sh
node scripts/prepare-doom-stress.mjs /path/to/DOOM2.WAD /path/to/Skulltag-v097d5.wad
npm start
```

The archives are copied into the ignored `demo/local-wads` directory. The documentation build includes the world definition but does not copy these archives. For a local static preview, copy that directory into `docs/dist/engine/packages/doom-phonics/demo/` after building.

D2ST9 is a Hexen-format map with 6,995 linedefs, 1,123 sectors, 4,762 subsectors, and 261 things. Examining `doom-renderer/src/Room.js` found the relevant compatibility branch in `map.format === 'HEXEN'`, particularly linedef 181 (`Plane_Align`); it does not have separate renderer implementations selected by ZDoom, GZDoom, Skulltag, or Zandronum names. Those ports share individual map/resource features, so this adapter dispatches by map format and action arguments.

The implemented subset covers the map's non-interlaced 8-bit indexed/RGB/RGBA PNG pictures, signed `grAb` origins, Hexen skill and game-mode flags, floor/ceiling slopes, plane light transfers, doors, lifts, destination-TID teleports, scrolling walls, and flickering/glowing sectors. Floor and ceiling materials prefer flats and fall back to composite wall textures, preserving their dimensions and WAD overrides; this includes D2ST9's `LITE3` light strips. Sloped heights feed rendering, player spawning and movement, moving sectors, and projectile plane intersections. Hexen actions have a separate dispatch path so their numbers cannot accidentally invoke unrelated classic Doom actions. PNG inflation uses the browser runtime's zlib extension.

This is an **arena inspection world**, not a complete Skulltag match implementation. `playerStartType: 5080` starts one player at the blue base, bypassing the ACS team-selection room. `inspectUnsupportedThings: true` records skipped actor types in diagnostics; ordinary worlds still reject missing definitions. The standalone actor lump supplies `HellScorePillar` (5020), `InvisibilitySphere` (5035), `RedParticleFountain` (9027), and `BlueParticleFountain` (9029), resolving all D2ST9 thing types. Their definitions do not implement the native scoring, translucency power, or particle emission behavior. ACS `BEHAVIOR` scripts, team scoring and resets, `Line_Horizon` (9), `Line_SetIdentification` (121), and `TranslucentLine` (208) remain unsupported. ACS-controlled gates and switches, scripted panning, and team objectives therefore do not operate. Unsupported action counts and skipped thing types are exposed in the session diagnostics.

The slope and action argument semantics were checked against GZDoom's [slope loader](https://github.com/ZDoom/gzdoom/blob/master/src/maploader/slopes.cpp), [action implementations](https://github.com/ZDoom/gzdoom/blob/master/src/playsim/p_lnspec.cpp), and [static specials](https://github.com/ZDoom/gzdoom/blob/master/src/maploader/specials.cpp).

Run the browser benchmark against an already running local server:

```sh
node scripts/bench-doom-browser.mjs \
  --views=packages/doom-phonics/benchmarks/d2st9-views.json \
  --seconds=8
```

The default URL uses port 9130; pass `--url=http://...` for another server. The harness starts a new game, samples the blue base, rotates in the central arena, and walks through a ramp at both Native and Classic resolution. It writes frame samples, summary JSON, and screenshots under `scratch/d2st9/performance`. Frame intervals are measured at each animation frame; PHP CPU timings are the latest 10 Hz diagnostic sample. Headless Chromium uses SwiftShader by default; `--software=false` removes that override, and the report records the actual GL renderer. Treat software-renderer results as comparisons within that environment, not hardware-GPU frame-rate predictions.

Browser diagnostics are available as `session.doomState` (`window.previewSession.doomState` in the preview host). They update in memory instead of rewriting a canvas data attribute. Accessible status text and the PHP-info back button only change when their displayed state changes. A [five-second DOM trace](benchmarks/d2st9-dom-updates.json) recorded 117 mutations during idle D2ST9 gameplay before this change and zero afterward, with 39 diagnostic updates in both samples.

Startup now indexes sprite frame/rotation lookup once, resolves repeated DECORATE frames once per atlas, and packs palette/alpha data by whole rows. A [startup comparison](benchmarks/startup-optimization.json) measured the PHP game-start phase at 12.2 → 3.6 seconds for E1M1 and 31.5 → 13.9 seconds for the D2ST9 stress world in headless Chromium with SwiftShader. Each is one fresh-context observation per version; remaining work includes image decoding and map geometry construction. Native comparisons verified identical sprite selection, mirroring, atlas pixels, rectangles, and metadata for E1M1, D2ST9, and stacked MAP04, including all 2,219 D2ST9 atlas images.

A [fresh A/B benchmark of the DOM cleanup and ECS component-name cache](benchmarks/d2st9-dom-cache-optimization.json) ran each view twice for six seconds per version, in current/baseline/baseline/current order. Both versions retain the earlier WebGL optimizations. Pooled median render CPU time improved by 0.6–1.1 ms in four of six view/resolution combinations and increased by 0.2 ms in the other two. Native blue-base rendering remained 15.6 ms. FPS results were mixed, with substantial variation between repetitions; this does not establish a consistent frame-rate gain. The report retains individual runs, pooled frame percentiles, and simulation time per step. The status callback runs outside the reported render CPU timer.

On October 24, 2026, Chromium 152 with SwiftShader loaded the world in 19.1 seconds and completed all six eight-second samples without browser errors. [Recorded results](benchmarks/d2st9-swiftshader.json):

| View | Native 960×540 FPS | Classic 480×270 FPS |
| --- | ---: | ---: |
| Blue base | 15.31 | 19.09 |
| Central arena, turning | 18.17 | 21.21 |
| Ramp movement and wall collision | 13.29 | 22.08 |

The initial blue-base run averaged 4.71 FPS with a median 157.9 ms in map rendering. Material synchronization was visiting every recorded vertex span each frame. Grouping spans by sector/plane and linedef reduced that to 25.4 ms by updating only changed flats, switches, and scrolling walls.

A subsequent profile found that the GLES bridge's `UNPACK_ROW_LENGTH` query forced a graphics-driver wait before every sector texture upload. The browser now caches pixel-layout reads, invalidates them on every `pixelStorei()` call, and queries the native result after a write. Context loss and restoration clear the cache. Unchanged plane materials also avoid temporary arrays, and sector interpolation clamps its fraction once per frame.

[Before/after measurements](benchmarks/d2st9-render-optimization.json) use six-second samples at the same views and resolutions in Chromium 152 with SwiftShader. All runs completed without browser errors:

| View | Resolution | Map CPU ms, before → after | Total render CPU ms, before → after | FPS, before → after |
| --- | --- | ---: | ---: | ---: |
| Blue base | 960×540 | 26.1 → 4.0 | 34.5 → 16.1 | 13.88 → 14.84 |
| Central arena, turning | 960×540 | 29.8 → 3.9 | 36.2 → 12.3 | 10.39 → 15.46 |
| Ramp movement | 960×540 | 28.7 → 4.1 | 37.9 → 13.6 | 10.63 → 14.75 |
| Blue base | 480×270 | 15.6 → 4.3 | 23.6 → 17.8 | 14.71 → 14.84 |
| Central arena, turning | 480×270 | 16.6 → 4.2 | 21.5 → 12.6 | 23.54 → 22.27 |
| Ramp movement | 480×270 | 19.3 → 4.0 | 27.3 → 13.6 | 18.28 → 21.98 |

CPU values are medians and include driver waits; they do not measure GPU execution time. Removing the synchronous query allows graphics work to overlap CPU work, but SwiftShader still executes the draw workload on the CPU. Frame-rate gains are therefore smaller and vary by view. The map still draws 99,444 vertices with the same shaders, textures, and resolution. The ramp sample moved from `(32, 2384, 4)` to `(32, 2703.99, 32)` and stopped at the wall, exercising slope ascent and collision. `render.mapStagesMs` exposes material preparation, mesh uploads, sector preparation/uploads, sky, and draw submission separately for future profiling.
