---
title: Author in Tiled
description-meta: Place objects, edit their settings, and preview a world in your game.
llms: guides
menus:
  documentation:
    parent: author
    weight: 20
---

Use Tiled to arrange a game's maps and objects and edit their settings. Your game supplies the code that gives those objects behavior. This guide assumes you already have a Phonics application; the [quickstart](quickstart.html) creates one.

You can start with an example's ready-made project or configure a project for your own packages. Both paths lead to the same workflow: place content, save its settings, validate, and preview.

## Install the extension

### Open an example project

In the examples repository, run `npm install` at the root, then open the game's `.tiled-project` **before opening a map**. Enable the project extension when prompted. Opening a map alone does not load the project's custom classes, so its inherited properties may appear to be missing.

Sane's `worlds/demo.tiled-project` includes its classes, templates folder, and a relative extension directory. Follow [Sane course authoring](games/sane-phonics/authoring.html) to use its 34 entity templates.

### Set up your own project

Use **Tiled 1.12.2**. Install the core and helper packages together and make the `tiled-phonics` command available on Tiled's PATH. Before registry publication, use packed artifacts:

```sh
npm install -g /path/to/seanmorris-phonics-0.1.0.tgz /path/to/seanmorris-tiled-phonics-0.1.0.tgz
```

Copy both `extension/*.mjs` files from the installed Tiled-Phonics package into the extensions directory opened through **Edit → Preferences → Plugins → Open**. Restart Tiled, then open a saved project and a world.

<span id="compose-the-world"></span>
<span id="import-a-package"></span>

## Choose packages and a starting map

Choose **File → Add Package URL…**, enter a versioned `phonics.json` URL, and assign a short alias. The editor calls this alias a *pallet*: for example, `arcade` lets the world refer to an export as `@arcade/game`.

The importer caches package metadata, classes, defaults, templates, and preview resources in `.phonics-cache`. It does not execute downloaded gameplay code.

<span id="configure-the-content"></span>

Use **File → Phonics World Settings…** to choose the starting map and additional modules. Imports are stored as `phonics.import.<alias>` properties. The `phonics.modules` list names the modules available to the world; the application's PHP startup code selects the game export.

<span id="place-entities"></span>

## Place objects with factories or templates

Select an object layer. You can then choose either of these placement methods:

| Method | What it supplies |
| --- | --- |
| **File → Place Phonics Entity…** | An exported factory that creates an entity when the game loads the object. |
| Drag a project template onto the map | A concrete object with inherited class, settings, geometry, and any tileset reference. |

Move and configure the object using Tiled's normal tools. Choose linked objects in the same map after placement; an ID copied from another map does not identify the intended target here. Cached templates and tilesets provide package previews.

Sane's ring and monitor templates are interpreted by Sane's own gameplay loaders. They do not register new global framework components. Its [authoring guide](games/sane-phonics/authoring.html) explains those presets.

<span id="components-presets-and-scope-systems"></span>

## Attach components and edit nested settings

A component holds one part of an entity's data, such as movement settings. **Attach Phonics Components** adds a class-valued property to a selected object or tile, the current layer, a map, or a world. Image and group layers are supported too.

Expand that property to edit its fields. `Movement.speed` means the `speed` field inside `Movement`; it is separate from a `speed` field inside `Animation`. A *preset* groups several nested settings so they can be applied together.

Classes bound to component exports construct ordinary ECS components. Game systems give those components meaning: a layer component might control parallax or layer movement, for example. Loading a spritesheet does not by itself start gameplay systems.

## Choose when systems run and what survives a revisit

**Phonics Scope Systems** selects systems for a world or map. That portion of content is called a *scope*: it owns the systems and entities that belong to it.

The game decides which maps are active. Sane's spatial demo selects nearby maps; Noid selects the current campaign stage. Editor coordinates do not force a particular activation policy.

Choose how the scope handles state when it stops and later becomes active again:

| Policy | Behavior |
| --- | --- |
| `retain` | Suspend the existing instances and resume them later. |
| `snapshot` | Save explicitly captured state, release the instances, and restore that state on re-entry. |
| `reset` | Release the instances and start fresh next time. |

Packages can provide custom lifecycle hooks. Worlds can also be loaded and unloaded during play, using code already loaded by the application. See [world loading](worlds.html) for the application side.

Edit `phonics.modules` and `phonics.systems` as native Tiled 1.12 lists. Older JSON-string lists remain readable.

## Create entities from tiles when needed

Tile entities are optional. **Phonics Tile Entities** controls how tile placements become entities:

| Mode | Result |
| --- | --- |
| Disabled | No framework tile entities. |
| `collection` | One entity groups a tile definition's placements across the map. |
| `placement` | One entity for each cell. |
| `region` | One entity for each connected group of matching cells within a layer. |

Regions connect by shared edges unless diagonal corners are selected. Individual tile properties override map defaults. Groups preserve their exact cells and holes; the game still decides how to render, move, or collide with them.

## Save defaults and rebuild bundled content

Save the project as well as the maps and world. Tiled may omit unchanged class defaults from map files. The extension exports those defaults to `phonics.classes.json`, referenced by the world's `phonics.classes` file property. Include that catalog in the package so the game sees the same values as the editor.

Declare newly added runtime files in the package manifest. Then, from your game directory:

```sh
npx phonics integrity
npx phonics validate --world worlds/game.world
npx phonics pack
```

Use file properties for local resources. Keep CDN URLs and `@alias/export` references as strings. Preserve cached resources' `phonics.source*` properties: they record where references came from after a file is relocated. See [paths and shared assets](resources.html).

## Validate and preview

For a local game package, start the preview server:

```sh
npx phonics preview --runtime /path/to/php-sdl-wasm
```

Choose **File → Validate and Preview Phonics World…**. The extension saves modified open assets and the world, validates them, and asks for three URLs. With the default package preview server, they have these forms:

| URL | Purpose | Example |
| --- | --- | --- |
| Preview host | Page that runs the application | `http://127.0.0.1:9140/__phonics/` |
| Application PHAR | Executable game archive | `http://127.0.0.1:9140/dist/game.phar` |
| Served world | Saved content to open | `http://127.0.0.1:9140/worlds/game.world` |

Use the host URL printed by your server, especially if you changed its port or allowed origins. The project remembers the host; the world remembers its served URL. The extension passes `application=` and `world=` to the host. It does not publish files or generate startup code. The `phonics preview` server rebuilds the PHAR when its declared inputs change; an ordinary static server needs you to rebuild it separately.

### Use another host

| Host | Start it in a framework checkout | Host URL |
| --- | --- | --- |
| Built documentation | `npm run serve:docs`, or `npx phonics serve docs/dist` after a full build | `http://127.0.0.1:9140/preview/` |
| Development server | `npm start` | `http://localhost:9130/preview/` |

The extension's default host is `http://localhost:9130/preview/`. Its default served-world base is `http://localhost:9130/`; replace that with the actual world file URL.

### Load files from another origin

The local example above keeps the host, application, and world on one origin. If you serve them from different origins, two separate permissions matter:

- The remote server must supply CORS headers so the browser can read its files. `phonics serve` does not add CORS headers.
- The host must allow executable package origins. With `phonics preview`, pass `--allow-origin URL` and use the printed host URL, including its `origin` parameters. A `/preview/` page can declare its allowed origins in `data-code-origins`.

These permissions do not correct a wrong path. Check the exact URL reported by validation or the browser when a resource cannot be found.
