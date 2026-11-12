---
title: Load a world
description-meta: Choose maps and content, then launch and clean up the application that uses them.
llms: guides
menus:
  documentation:
    parent: author
    weight: 10
---

A world describes the content a game can load: its maps, resources, and additional modules. The executable PHP application selects the game code and composes that content with `Phonics\Application\Context::compose()`.

This guide assumes you already have a built application PHAR. Start with [the command-line tools](cli.html) if you need to create one. For editor setup and object placement, see [Author in Tiled](tiled.html).

<a id="declare-the-world"></a>

## Choose the maps and starting map

A Tiled `.world` file lists maps and stores Phonics configuration as custom properties. This small world imports a game package as `arcade` and starts with `stage.tmj`:

```json
{
  "type": "world",
  "maps": [{"fileName": "stage.tmj", "x": 0, "y": 0, "width": 640, "height": 480}],
  "properties": [
    {"name": "phonics.import.arcade", "type": "string", "value": "../packages/php-noid/phonics.json"},
    {"name": "phonics.start", "type": "string", "value": "stage.tmj"}
  ]
}
```

Both relative paths are resolved from the world file. The `arcade` alias lets other properties refer to that package's exports as `@arcade/export-name`. The application's PHP bootstrap still chooses which game export to run.

Use the package's factories, templates, or components to author `stage.tmj`. Map positions can describe a continuous landscape or simply organize a collection of stages; the game decides which maps become active.

### Include modules needed by later maps

Add optional `phonics.modules` to the world's properties when it needs exports beyond those already selected by the game. Declare modules for later maps as well as the starting map, so their code is available before play reaches them.

Tiled 1.12 supports a native list property. For example, a world that imports Sane as `sane` can include:

```json
{
  "name": "phonics.modules",
  "type": "list",
  "value": [
    {"type": "string", "value": "@sane/demo-map"},
    {"type": "string", "value": "@sane/spawn"}
  ]
}
```

Older worlds that store a JSON array in a string property remain readable. Use [Tiled's world settings and scope tools](tiled.html) to edit module selections and choose the systems that run while a world or map is active.

## Launch the application

Pass the world URL as application initialization data. In this example, `canvas` is the game's canvas element and `worldUrl` is the world to load. Replace the PHAR and runtime URLs with the files served by your host.

```js
import {Phonics} from '@seanmorris/phonics';

const loading = new AbortController();
const session = await Phonics.run('/dist/game.phar', {
  init: {world: new URL(worldUrl, location.href).href},
  canvas,
  runtime: {url: '/runtime/php8.4-sdl.mjs'},
  signal: loading.signal,
  policy: {codeOrigins: [location.origin]}
});

// When the application leaves this game:
await session.dispose();
```

Call `loading.abort()` to cancel a load that is still in progress. Once the session is ready, call `session.dispose()` when leaving or replacing the application; this releases its resources.

Ordinary stage changes belong to the game's lifecycle and keep the runtime alive. Loading a different set of executable packages requires a new runtime.

## Use a content adapter

A world can also load content from formats such as WAD archives. An adapter reads the format and exposes maps or other parts of the content to the game. See [Load content through adapters](world-adapters.html) for a complete Doom example, bindings, game-specific settings, and ordered resource slots. A world may combine Tiled and adapter-backed sources.

<a id="control-executable-origins"></a>

## Allow the origins that host your code

Remote modules execute as part of the application. The host's `policy.codeOrigins` allows only the host origin by default. Add a CDN origin when you intend to execute packages hosted there.

Prefer exact-version npm URLs, commit-pinned GitHub CDN URLs, or immutable static hosting. Integrity checks confirm that files match a manifest's hashes; you still need to trust the manifest itself. See [packages and integrity](packages.html).
