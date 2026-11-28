---
title: Build an editable landscape
description-meta: Generate a streamed 3D heightfield, join landscapes into a journey, grade roads, and draw the ground, water, weather, and sky.
llms: guides
menus:
  documentation:
    parent: author
    weight: 35
---

The optional **terrain-phonics** package supplies editable 3D landscapes, water waves, weather, and an atmosphere.
It works with direct Phonics applications and does not require Tiled. Try the [Landscape Sandbox](games/terrain-sandbox/index.html)
to fly over a landscape, change its shape, lay a road, and drive, sail, or fly across it.

This page introduces the main interfaces. The [terrain package reference](terrain-package.html) lists every
recipe, material, and appearance setting, and the [PHP API](api-terrain/index.html) documents each class.

## Choose the ground

A landscape recipe selects a seed, a preset, and a bounded world size. Coordinates are meters with Y upward,
and the square world is centered on zero. The default world is 32,768 meters across with a one-meter
authoritative sample lattice. Only the samples a query or the renderer asks for are generated.

```php
$ground = new TerrainPhonics\Landscape(['preset' => 'coast', 'seed' => 'first-light']);
$contact = $ground->sample(120, -80);
```

The contact contains height, gradient, upward normal, material, and revision. `height()` and `sample()` use
the same reconstruction, so neighboring cells agree on height and slope at their boundaries. Rendering uses
less geometry far from the camera while contact queries keep their original resolution.

`TerrainPhonics\Recipe::PRESETS` names 28 landforms, from `coast`, `mountain`, and `canyon` to `karst-bay`,
`tepui-highlands`, `amber-dunes`, `rice-terraces`, and `boreal-lakes`. A preset fills in a common set of shape
coefficients: directional rise, rounded ridges, canyon walls, rolling relief, and terraces. Override any of
them in the recipe. `noiseOffsetX` and `noiseOffsetZ` choose which part of the seeded landscape appears at
the world origin, and `surround` extends the scenery past the playable square as sea, plateau, or mountains.

The generator smooths small features on steep faces by attenuating fine-noise amplitude with a separate
larger-scale slope guide. Height stays a function of position alone: no erosion pass or baked grid is
needed before a point can be sampled.

A body that only has to stay above the ground can test `localBounds($x, $z, $cells)` first. It bounds the
surface over a block of cells without generating a sample. Use `pin()` to reserve a small region for a
physical body independently of camera travel, and `unpin()` when the body leaves.

## Join landscapes into a journey

A journey places a departure map west of one or two destinations, each a full-size landscape with its own
preset. A 4,096-meter strip blends heights and surface colors between their edges.

```php
$journey = new TerrainPhonics\Landscape([
    'preset' => 'rolling-prairie',
    'join' => [
        'version' => 2,
        'span' => 32768,
        'destinations' => [['preset' => 'mountain'], ['preset' => 'coast']],
    ],
]);
```

Each map keeps its own noise coordinates and is raised or lowered so its sea level meets the departure map's
water level. With two destinations, the first sits northeast and the second southeast. Road edits are saved
with the recipe, so a highway can cross the whole layout.

## Prepare an edit before publishing it

Brushes raise, lower, flatten, smooth, excavate craters, or paint material. A road uses a spline elevation
profile, width, crown, and shoulders to cut and fill the surrounding ground, and the renderer paints its
pavement, centerline, and edge stripes. Bridges are separate meshes.

```php
$candidate = $ground->fork();
$candidate->queue(['type' => 'raise', 'x' => 120, 'z' => -80, 'radius' => 24, 'amount' => 8]);
$candidate->queue([
    'type' => 'road',
    'points' => [[100, 20, -100], [150, 24, -60], [210, 26, -20]],
    'width' => 8, 'shoulder' => 5, 'crown' => .2,
]);
$candidate->commit();
$scene->prepareLandscape($candidate);
```

Keep drawing and simulating the previous landscape while `Scene::update()` prepares the changed geometry.
At a fixed-update boundary, call `takePreparedLandscape()`. A non-null result is the new surface to install
in physics; its matching graphics data is now ready. This avoids a vehicle colliding with a road that has
not appeared yet. An application should wait for this transaction before accepting another terrain edit.

Everything queued before one `commit()` is a single transaction, and undo and redo work on whole
transactions. A road can carry Bézier `handles` to shape a bend or a crest, be revised in place with
`replaceAt`, and be removed with a `delete-road` operation. For a long spline, advance `queueSteps($road)`
within the frame budget instead of queueing it in one call.

`save()` includes the recipe version and edit history. `load()` validates them and rebuilds derived caches.
For pointer picking at a distance, advance `rayQuery()` with a time budget.

## Draw the scene

`Camera` supplies flight motion, a perspective matrix, and picking rays. `Scene` draws the ground and water.

```php
$water = new TerrainPhonics\Water($ground->recipe()['waterLevel']);
$air = new TerrainPhonics\Atmosphere;
$scene = new TerrainPhonics\Scene($ground, $water, $air);

$scene->update($seconds, $camera);
$scene->render($camera, $width, $height, $time);
```

The scene draws nested grids around the camera. Each grid has an elevation texture that the GPU fills from
the seed and recipe, so only newly exposed strips are evaluated as the camera travels. Coarse grids draw
first after a jump or a restored context, and finer ones follow. For a ground vehicle, pass a world-space
`[x, z]` look-ahead point as the third argument to `update()` to carry fine detail ahead of it.

`drawChart()` draws a heading-up map from the same elevation and surface layers, and `drawRecipeChart()`
draws a north-up overview of an unedited landscape for a route planner. Call `restore()` after context loss
and `close()` when unloading.

## Shape the look of the surface

The ground's colors and relief come from a stack of up to sixteen ordered layers. Height, slope, water level,
rock beds, noise fields, and an earlier layer's coverage can each restrict where a layer appears. A layer
can add color, lighting relief, or both.

```php
$surface = TerrainPhonics\SurfaceLayers::landscape($ground->recipe(), $scene->appearance);
$rows = $surface->layers;
$rows[] = ['id' => 'ripples', 'name' => 'Sand ripples', 'parent' => 'sand',
    'channel' => 'relief', 'relief' => 'pattern', 'pattern' => 'ripples',
    'patternScale' => 1.4, 'strength' => 0.25];
$scene->layers = new TerrainPhonics\SurfaceLayers($rows, $surface->fields, $surface->bounce);
```

For broader changes, replace `Scene::$appearance`. A `TerrainAppearance` holds each preset's colors, band
spacing, blend slopes, snow line, and rock cover, and clamps every number to a documented range.

```php
$scene->appearance = new TerrainPhonics\TerrainAppearance(['snowline' => 220, 'snowColor' => '#f4f8ff'] + $saved);
```

`GrassShells` draws standing grass, rice, and heath around the eye in one extra pass over the nearest
grids. On a journey it takes one style per landscape and blends them where the landscapes meet.

## Add weather, water, and sky

Assign a `Weather` to the scene for clouds, rain, snow, or lightning. The cloud sheet, its shadows on the
ground, and its reflections in the water all sample the same moving field.

```php
$weather = $scene->weather = new TerrainPhonics\Weather;
$weather->edit(TerrainPhonics\Weather::PRESETS['rain'], $time);
$weather->edit(['altitude' => 6000, 'wind' => 20, 'heading' => 90], $time);
```

The `storm` preset adds lightning to heavy rain. Each bolt lands on the highest ground near where it leaves
the cloud and lights the cloud around its top. While it is lit, the land and the rest of the cloud darken
against it. `strike($x, $z, $time)` calls a single bolt down over a place.

`Water::sample(x, z, time)` returns height, normal, and vertical surface velocity. The shader evaluates the
same directional waves and bounded wake packets. `Watercraft` uses several contact points with buoyancy,
drag, steering, and shallow-water handling; it is a small arcade demonstration rather than a general rigid-body solver.

`Atmosphere` models Rayleigh and Mie single scattering in a spherical atmosphere. Sun direction changes
illumination, the visible disk, and sky color together, and distant terrain receives the same attenuation
and scattered light. `setLayers()` adds up to six colored mist or dust layers between chosen elevations.
Assign a `Starfield` to `$atmosphere->stars` to set star brightness, twinkle, and shooting stars.

## Keep resources bounded

The renderer retains coarse coverage while finer detail is prepared. Generation work is budgeted, and
camera-relative rendering keeps positions precise far from the origin. Unpin regions when bodies leave and
close the scene and landscape when unloading the world.

Use `Scene::stats()` and `Landscape::stats()` to inspect pending work, cache occupancy, and revisions.
The sandbox reports generation and render CPU time. Measure actual frame intervals on the target GPU as
well: software WebGL checks do not establish a hardware frame rate.

The package is a heightfield: it cannot represent caves or overhangs. It also does not include multiplayer
synchronization, global fluid simulation, erosion simulation, volumetric clouds, or multiple atmospheric
scattering. Those can be developed independently of the surface and resource interfaces.
