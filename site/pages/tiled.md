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

Choose **File → Add Package URL**, enter a versioned `phonics.json` URL, and assign a pallet alias. The importer reads dependencies, classes, typed defaults, templates, and preview resources. It caches resources in the project's `.phonics-cache` directory without executing downloaded gameplay code.

### Select the game

Use **Phonics World Settings** to choose a game export, starting map, and additional modules. Imports are saved as `phonics.import.<alias>` custom properties. Game and module selections use those aliases.

### Place entities

Select an object layer and choose **Place Phonics Entity**. Choose an exported factory, then move and configure the resulting object using Tiled's normal tools. Cached templates and tilesets provide package previews.

## Validate and preview

**Validate and Preview** saves the assets, checks imports and typed properties, then opens the generic Phonics host. Your static server must serve the world and maps; cross-origin resources need CORS. The extension does not publish your files or generate a custom bootstrap.

Keep CDN URLs and `@alias/export` references as strings. Use file properties for actual local resources. Preserve `phonics.source*` custom properties on cached resources: these retain the original declaring URL when resources are relocated. See [resource identity](resources.html).
