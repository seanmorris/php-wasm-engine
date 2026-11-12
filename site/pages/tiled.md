---
title: Author in Tiled
description-meta: Package URLs become editor-ready game content.
llms: guides
menus:
  documentation:
    parent: author
    weight: 20
---


## Install the extension

For the examples repository, run `npm install` at its root and open the game's `.tiled-project` before opening a map. Sane's `worlds/demo.tiled-project` includes its custom classes, templates folder, and a project-relative extension directory. Enable the extension when Tiled prompts. Opening a map alone does not load the project's class definitions. See [Sane course authoring](games/sane-phonics/authoring.html) for its 34 entity templates and nested settings.

For a standalone project, install the extension as follows.

Use **Tiled 1.12.2**. Install the core and Tiled helper packages together and expose `tiled-phonics` on Tiled's PATH. Before registry publication, install the packed core and editor artifacts together, for example:

```sh
npm install -g /path/to/seanmorris-phonics-0.1.0.tgz /path/to/seanmorris-tiled-phonics-0.1.0.tgz
```

Copy both `extension/*.mjs` files from the installed Tiled-Phonics package into the extensions directory opened through **Edit → Preferences → Plugins → Open**, then restart Tiled. Open a saved project and a world.

## Compose the world

### Import a package

Choose **File → Add Package URL…**, enter a versioned `phonics.json` URL, and assign a pallet alias. The importer reads dependencies, classes, typed defaults, templates, and preview resources. It caches resources in the project's `.phonics-cache` directory without executing downloaded gameplay code.

### Configure the content

Use **File → Phonics World Settings…** to choose a starting map and additional modules. Imports are saved as `phonics.import.<alias>` custom properties. Module selections use those aliases. The application's PHP bootstrap selects the game export.

### Place entities

Select an object layer and choose **File → Place Phonics Entity…**. Choose an exported factory, then move and configure the resulting object using Tiled's normal tools. Cached templates and tilesets provide package previews.

A project can also supply concrete object templates. Drag one from its templates folder onto an object layer to inherit its class, nested settings, geometry, and tileset reference. Sane's ring and monitor templates use this route; they are presets interpreted by its existing game loaders rather than new global framework component registrations. Choose map-local object references after placement instead of copying IDs from another map.

### Components, presets, and scope systems

**Attach Phonics Components** adds a class-valued property to a world, map, layer, selected object, or selected tile. Image and group layers are included. Classes bound to component exports construct ordinary ECS components; a preset class can contain several nested component classes. Their fields remain separate: `Movement.speed` and `Animation.speed` have independent defaults and overrides.

**Phonics Scope Systems** selects systems and lifecycle for a world or map. The game chooses when maps become active: Sane selects nearby maps, while Noid selects a campaign stage by identity. `retain` suspends instances, `snapshot` saves explicitly captured state and releases instances, and `reset` starts fresh next time. Custom package policies can provide their own hooks. Worlds are inspectable assets that can load and unload during play using already loaded code.

**Phonics Tile Entities** is optional. `collection` groups a tile definition's placements across a map, `placement` creates one entity per cell, and `region` joins touching matching cells within each layer. Edges connect by default; diagonal corners are optional. Per-tile properties override map defaults. Exact cells and holes are preserved, and the game decides how to render, move, or collide with them.

The extension exports saved project class definitions to `phonics.classes.json`, referenced by the world's `phonics.classes` file property. Package that file with the world: unchanged Tiled defaults live in the project rather than the map. Save the project before previewing. Validation checks nested defaults and component references before gameplay runs.

Native Tiled 1.12 list properties hold `phonics.modules` and `phonics.systems`. Older JSON-string lists remain readable. Layer components are available on object, tile, image, and group layers; the framework supplies their data and ownership, while game systems choose behavior such as parallax or moving a layer. Importing a spritesheet alone does not activate systems.

## Validate and preview

**File → Validate and Preview Phonics World…** saves modified open assets and the world, checks imports and typed properties, then asks for three URLs:

- **Preview host URL**: a running Phonics host. The default is `http://localhost:9130/preview/`; the project remembers your entry.
- **Application PHAR URL**: the executable application that will open the content, such as `http://127.0.0.1:9140/dist/game.phar` from `phonics preview`.
- **Served world URL**: the HTTP(S) URL of this `.world` file. The default is `http://localhost:9130/`; the world remembers your entry.

The extension opens the host with `application=` selecting the PHAR and `world=` supplying the content URL as application initialization data. Any of these hosts works:

| Host | Start it with | Preview host URL |
| --- | --- | --- |
| Your game package | `phonics preview --runtime /path/to/php-sdl-wasm` in the package (`npx phonics` for a local installation) | `http://127.0.0.1:9140/__phonics/` |
| Built framework site | `npm run serve:docs`, or `npx phonics serve docs/dist` after `npm run build:docs`, in a Phonics checkout | `http://127.0.0.1:9140/preview/` |
| Framework development server | `npm start` in a Phonics checkout | `http://localhost:9130/preview/` |

`phonics preview` serves your package directory at `/` beside its host, so a world inside the package has a same-origin URL such as `http://127.0.0.1:9140/worlds/game.world`. `phonics serve` serves one static directory at `/` and sends no CORS headers; a world it serves can be loaded only by a host on the same origin. Serve cross-origin worlds and resources from a server that supplies CORS. Each host also limits executable package origins: start `phonics preview` with `--allow-origin URL` and copy the `origin` parameters from its printed URL into the host URL, or add the origin to the `/preview/` page's `data-code-origins` list. The extension does not publish your files or generate a custom bootstrap.

Keep CDN URLs and `@alias/export` references as strings. Use file properties for actual local resources. Preserve `phonics.source*` custom properties on cached resources: these retain the original declaring URL when resources are relocated. See [resource identity](resources.html).

After editing bundled content, refresh the package with `npx phonics integrity`, validate with `npx phonics validate --world worlds/game.world`, and rebuild using `npx phonics pack` from the game directory. Declare newly added runtime files in the manifest before packaging. Editor preview does not rebuild or publish the executable archive.
