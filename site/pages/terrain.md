---
title: Build an editable landscape
description-meta: Generate a streamed 3D heightfield, grade roads, and share terrain and water queries with physics.
llms: guides
menus:
  documentation:
    parent: author
    weight: 35
---

The optional **terrain-phonics** package supplies editable 3D landscapes, water waves, and an atmosphere.
It works with direct Phonics applications and does not require Tiled. Try the [Landscape Sandbox](games/terrain-sandbox/index.html)
to fly over a landscape, change its shape, lay a road, and drive a test watercraft.

## Choose the ground

A landscape recipe selects a seed, a mountain/canyon/coast preset, and a bounded world size. Coordinates are
metres with Y upward. The default world is 32,768 metres square with a one-metre authoritative sample lattice.
The cache only generates samples that are requested; it does not allocate the entire world.

```php
$ground = new TerrainPhonics\Landscape(['preset' => 'coast', 'seed' => 'first-light']);
$contact = $ground->sample(120, -80);
```

The contact contains height, gradient, upward normal, material, and revision. A bicubic reconstruction gives
neighboring cells the same height and first derivatives at their boundaries. Rendering uses less geometry
far from the camera while contact queries keep their original resolution.

The generator smooths small features on steep faces by attenuating fine-noise amplitude using a separate
larger-scale slope guide. Finer octaves receive stronger damping. This controls the landscape's shape;
mesh LOD remains a separate rendering decision.

The canyon preset has distinct floors and plateaus joined by narrow, nearly vertical walls. The joins are
smooth, so contact normals remain continuous. Terracing adds shelves without requiring a different surface type.

## Prepare an edit before publishing it

Brushes raise, lower, flatten, smooth, excavate craters, or paint material. A road uses a spline elevation
profile, width, crown, and shoulders to cut and fill the surrounding ground. Bridges are separate meshes.

```php
$candidate = TerrainPhonics\Landscape::load($ground->save());
$candidate->queue(['type' => 'raise', 'x' => 120, 'z' => -80, 'radius' => 24, 'amount' => 8]);
$candidate->commit();
$scene->prepareLandscape($candidate);
```

Keep drawing and simulating the previous landscape while `Scene::update()` prepares the changed geometry.
At a fixed-update boundary, call `takePreparedLandscape()`. A non-null result is the new surface to install
in physics; its matching graphics data is now ready. This avoids a vehicle colliding with a road that has
not appeared yet. An application should wait for this transaction before accepting another terrain edit.

`save()` includes the recipe version and edit history. `load()` validates them and rebuilds derived caches.
Undo and redo operate on whole committed transactions. The sandbox wraps this data with its camera and
atmosphere settings and offers file save/load controls.

## Share water and sunlight

`Water::sample(x, z, time)` returns height, normal, and vertical surface velocity. The shader evaluates the
same directional waves and bounded wake packets. `Watercraft` uses several contact points with buoyancy,
drag, steering, and shallow-water handling; it is a small arcade demonstration rather than a general rigid-body solver.

`Atmosphere` models Rayleigh and Mie single scattering in a spherical atmosphere. Sun direction changes
illumination, the visible disk, and sky color together. Cached transmittance and sky data reduce repeated
integration. Distant terrain receives atmospheric attenuation and scattered light before exposure and tone mapping.

## Keep resources bounded

The renderer retains coarse coverage while finer patches are prepared. Generation work is budgeted, GPU
meshes are cached, and camera-relative rendering keeps positions precise. Physical bodies can pin small
sample regions independently of the camera. Unpin regions when bodies leave and close the scene and
landscape when unloading the world.

Use `Scene::stats()` and `Landscape::stats()` to inspect pending work, cache occupancy, and revisions.
The sandbox reports generation and render CPU time. Measure actual frame intervals on the target GPU as
well: software WebGL checks do not establish a 1080p/60 FPS hardware result.

The current package is a heightfield: it cannot represent caves or overhangs. It also does not include
multiplayer synchronization, global fluid simulation, erosion simulation, volumetric clouds, or multiple
atmospheric scattering. Those can be developed independently of the surface and resource interfaces.
