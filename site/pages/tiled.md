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

Use **Tiled 1.12.2**. Install the core and Tiled helper packages together and expose `tiled-phonics` on Tiled's PATH. Before registry publication, install the packed core and editor artifacts together, for example:

```sh
npm install -g /path/to/seanmorris-phonics-0.1.0.tgz /path/to/seanmorris-tiled-phonics-0.1.0.tgz
```

Copy `extension/phonics.mjs` from the installed Tiled-Phonics package into the extensions directory opened through **Edit → Preferences → Plugins → Open**, then restart Tiled. Open a saved project and a world.

## Compose the world

### Import a package

Choose **File → Add Package URL…**, enter a versioned `phonics.json` URL, and assign a pallet alias. The importer reads dependencies, classes, typed defaults, templates, and preview resources. It caches resources in the project's `.phonics-cache` directory without executing downloaded gameplay code.

### Select the game

Use **File → Phonics World Settings…** to choose a game export, starting map, and additional modules. Imports are saved as `phonics.import.<alias>` custom properties. Game and module selections use those aliases.

### Place entities

Select an object layer and choose **File → Place Phonics Entity…**. Choose an exported factory, then move and configure the resulting object using Tiled's normal tools. Cached templates and tilesets provide package previews.

## Validate and preview

**File → Validate and Preview Phonics World…** saves modified open assets and the world, checks imports and typed properties, then asks for two URLs:

- **Preview host URL**: a running Phonics host. The default is `http://localhost:9130/preview/`; the project remembers your entry.
- **Served world URL**: the HTTP(S) URL of this `.world` file. The default is `http://localhost:9130/`; the world remembers your entry.

The extension opens the host URL with `world=` set to the served world URL. Any of these hosts works:

| Host | Start it with | Preview host URL |
| --- | --- | --- |
| Your game package | `phonics preview --runtime /path/to/php-sdl-wasm` in the package (`npx phonics` for a local installation) | `http://127.0.0.1:9140/__phonics/` |
| Built framework site | `npm run serve:docs`, or `npx phonics serve docs/dist` after `npm run build:docs`, in a Phonics checkout | `http://127.0.0.1:9140/preview/` |
| Framework development server | `npm start` in a Phonics checkout | `http://localhost:9130/preview/` |

`phonics preview` serves your package directory at `/` beside its host, so a world inside the package has a same-origin URL such as `http://127.0.0.1:9140/worlds/game.world`. `phonics serve` serves one static directory at `/` and sends no CORS headers; a world it serves can be loaded only by a host on the same origin. Serve cross-origin worlds and resources from a server that supplies CORS. Each host also limits executable package origins: start `phonics preview` with `--allow-origin URL` and copy the `origin` parameters from its printed URL into the host URL, or add the origin to the `/preview/` page's `data-code-origins` list. The extension does not publish your files or generate a custom bootstrap.

Keep CDN URLs and `@alias/export` references as strings. Use file properties for actual local resources. Preserve `phonics.source*` custom properties on cached resources: these retain the original declaring URL when resources are relocated. See [resource identity](resources.html).
