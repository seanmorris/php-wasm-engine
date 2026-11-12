# Sane gameplay reference: drafting outline

This Harvard outline defines the split of the former all-in-one gameplay chapter.
The published chapters use ordinary descriptive headings. Preserve the original
gameplay URLs as short summaries linking to the relevant chapter, and retain the
examples, ownership rules, units, limits, and compatibility details at their new homes.

I. Find a course or a system (`gameplay.md`).
   A. Play and inspect the demo.
      1. Choose a level and movement profile; distinguish reset from restart.
      2. Find keyboard/gamepad controls and the breakable-block exercise.
   B. Choose the next reference chapter.
      1. Separate movement, vehicles, platforms, combat, water, setup, and drawing.
      2. Explain authoring presets versus runtime components and legacy names.
   C. Retain previous heading addresses.
      1. Give each former section a useful short description and a destination link.
      2. Keep camera and graphics subsections independently reachable.

II. Move the player and camera (`movement.md`).
   A. Move against terrain.
      1. Describe profiles, body casts, sensors, and collision planes.
      2. Preserve limits, loop traversal, and links to the dedicated loop guide.
   B. Change gravity and use springs.
      1. Explain cardinal axes, surface acquisition, and forced launches.
      2. Preserve authoring fields, units, examples, and owned-resource cleanup.
   C. Follow rings and rails.
      1. Explain light-dash acquisition, exit momentum, and combo rewards.
      2. Explain rail contacts, directional gates, and banking or losing combos.
   D. Follow the player with a camera.
      1. Describe automatic and scripted modes, look input, bounds, and damping.
      2. Preserve focus and teleport/reset examples; link arena-specific locks.

III. Ride vehicles (`vehicles.md`).
   A. Share a rider/vehicle lifecycle.
      1. Demonstrate skateboard controls, tricks, boarding, carrying, and collision.
      2. Preserve mount/dismount causes, regions, authoring, diagnostics, and cleanup.
   B. Fly the Tornado.
      1. Explain takeoff, pitch, thrust, fuel, gear, and course behavior.
      2. Preserve reusable capability components, artwork, and tuning details.
   C. Drive the EggWalker.
      1. Explain crouch, booster, punch, cannon, and landing shake.
      2. Preserve weapons composition, sprite layers, snapshots, and authoring.

IV. Build platforms and breakable terrain (`platforms.md`).
   A. Move and bend supporting surfaces.
      1. Cover floating, oscillating, and linked-path platforms with units and fields.
      2. Cover rider carrying, obstructions, bridges, rails, and sampled collision.
   B. Hang from ropes.
      1. Cover grabbing, pumping, release, anchor resolution, and callbacks.
      2. Preserve geometry restrictions, artwork, snapshots, and sweep behavior.
   C. Break blocks into particles.
      1. Preserve rolling impacts, removable colliders, and the complete setup example.
      2. Explain particle ownership, transforms, cleanup, and general shape removal.

V. Collect rewards and handle combat (`combat.md`).
   A. Collect and lose rings.
      1. Cover tile/object pickups, swept contact, teleport synchronization, and HUD.
      2. Place ring scattering and delayed recovery beside ordinary enemy damage.
   B. Add enemies and boss encounters.
      1. Preserve CrabMeat timing, shared damage, projectiles, and component roles.
      2. Preserve boss phases, hit rules, arena activation/release, and diagnostics.
   C. Grant rewards and shields.
      1. Cover monitor collision, reward interfaces, and nested authoring.
      2. Cover shield inventory, immunity, abilities, aura ownership, and cleanup.

VI. Add water and buoyant objects (`water.md`).
   A. Define water physics and effects.
      1. Cover swimming, skimming, buoyancy, currents, state edges, and particles.
      2. Preserve property units, scene capture/composition, and resource restoration.
   B. Connect water features.
      1. Explain the four courses and transitions between water and rails.
      2. Preserve switch links, level targets, buoyant blocks, and scripting examples.

VII. Set up a game and load its worlds (`worlds.md`).
   A. Create and manage the PHP session.
      1. Keep the complete minimal game, ECS query example, and lifecycle callbacks.
      2. Cover input, rumble, focus recovery, pause, and timed sequences.
   B. Load Tiled maps and stream a world.
      1. Preserve supported formats, dependencies, editing, and residency behavior.
      2. Explain proximity, transport callbacks, readiness, activation, and ownership.
      3. Preserve bounds, retries, reindexing, level transitions, and cleanup.
   C. Integrate the demo presentation.
      1. Link startup and menus to their existing focused guides.
      2. Retain debug overlay controls, metrics, PHP access, and browser diagnostics.

VIII. Draw the world and effects (`rendering.md`).
   A. Draw backgrounds and actors.
      1. Cover parallax, anchored/animated image layers, ordering, and shared stacks.
      2. Cover sprites, animation clocks, crops, anchors, outlines, and sprite groups.
   B. Apply effects in the correct order.
      1. Preserve backdrop displacement and camera-blur examples and their costs.
      2. Describe nested captures, sharp foreground/UI, restore/reset, and cleanup.
   C. Understand renderer implementation and limits.
      1. Preserve tile-plane uploads, instancing, ordering, statistics, and GPU limits.
      2. Replace the stale claim that editor integration is absent with the authoring link.
      3. Retain licensing and reference-art provenance.
