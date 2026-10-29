---
title: Load a world
description-meta: One URL describes the game and its content.
llms: guides
menus:
  documentation:
    parent: author
    weight: 10
---


## Declare the world

Store runtime configuration as Tiled custom properties. This abbreviated world uses a manifest relative to the world file:

```json
{
  "type": "world",
  "maps": [{"fileName": "stage.tmj", "x": 0, "y": 0, "width": 640, "height": 480}],
  "properties": [
    {"name": "phonics.import.arcade", "type": "string", "value": "../packages/php-noid/phonics.json"},
    {"name": "phonics.game", "type": "string", "value": "@arcade/game"},
    {"name": "phonics.start", "type": "string", "value": "stage.tmj"}
  ]
}
```

The loader resolves imports before `phonics.game`. Optional `phonics.modules` is a JSON array of export references stored in a **string** property. Include modules used by later streamed maps as well as the first map. Use the game package's factories to author the contents of `stage.tmj`.

## Use a content adapter

Worlds can activate content that does not come from Tiled. This example uses Doom-Phonics to open a licensed base WAD, bind its Episode systems to every Episode 1 map, and begin at E1M1:

```json
{
  "type": "phonics-world",
  "schemaVersion": 1,
  "imports": {
    "doom": "https://cdn.example/doom-phonics/0.1.0/phonics.json"
  },
  "game": "@doom/game",
  "sources": {
    "episode": {
      "adapter": "@doom/wad",
      "resources": [
        {
          "url": "./licensed/base.wad",
          "role": "iwad",
          "integrity": "sha256-REPLACE_WITH_BASE64_DIGEST"
        }
      ]
    }
  },
  "bindings": [
    {
      "id": "episode-gameplay",
      "use": "@doom/episode",
      "on": {
        "source": "episode",
        "kind": "map",
        "tags": ["episode-1"]
      },
      "options": {
        "skill": 2,
        "fov": 90,
        "renderScale": 0.5
      }
    }
  ],
  "transitions": [
    {
      "id": "e1m1-e1m2",
      "from": {"source": "episode", "scope": "E1M1"},
      "to": {"source": "episode", "scope": "E1M2"}
    }
  ],
  "init": {
    "startMap": "E1M1",
    "cheats": ["iddqd", "idkfa"]
  },
  "start": {"source": "episode", "scope": "E1M1"},
  "view": {"width": 960, "height": 540}
}
```

`init` is an optional game-owned object. The engine validates that it is an object and copies it into the session's world without assigning meaning to its keys. Browser modules read it as `context.world.init`; PHP reads it as `$context->world['init']` in a module callback, or `$phonics->world['init']` in a game entry point. This lets a world configure a game without adding game-specific fields to the neutral world schema. Doom-Phonics accepts `startMap` plus either one classic cheat string or an ordered `cheats` string list. It applies the cheats after creating the player for a new game. `startMap` overrides `start.scope` for Doom's initial title and new-game flow, while `start.source` still selects the content source.

Replace the example manifest URL with an immutable published URL and generate the integrity value from the WAD you are licensed to use. Package manifests provide code; worlds provide content URLs. The package archive does not contain the repository's reference WAD.

`bindings` apply the exported system set only while a matching scope is active. Doom-Phonics resizes its output framebuffer to the canvas's physical display size. Native resolution renders the three-dimensional scene directly into that output; the Options menu's Classic resolution uses `renderScale` as its scene multiplier, so `0.5` renders at half the output width and height before nearest-neighbor composition. Weapons, the status bar, menus, PHP Info, and screen wipes remain at full output resolution. The same world may declare Tiled and WAD sources, which allows a two-dimensional game to transition into an adapter-owned three-dimensional special stage.

A resource slot with `multiple: true` expands into an ordered sequence at the slot's position in `sources.*.resources`. A slot may declare `stack.first` and `stack.following` requirements when position changes what makes a resource valid. Doom-Phonics uses this to require an IWAD first, accept later IWAD or PWAD archives, and resolve duplicate lumps and whole maps from the last archive. Its reusable launcher previews the declared inspector metadata, builds the effective map list, and passes the selected map as the world's replacement `start.scope`. The same binding path serves local single-player launches and negotiated online matches.

[Play the repository example](games/doom-phonics/) or inspect its [complete world definition](https://github.com/seanmorris/php-wasm-engine/blob/HEAD/packages/doom-phonics/demo/episode-one-online.phonics-world.json).

## Launch a session

```js
import {Phonics} from '@seanmorris/phonics';

const loading = new AbortController();
const session = await Phonics.loadWorld(worldUrl, {
  canvas,
  runtime: {url: '/runtime/php8.4-sdl.mjs'},
  signal: loading.signal,
  policy: {codeOrigins: [location.origin]}
});

// When the application leaves this game:
await session.dispose();
```

Call `loading.abort()` to cancel an in-progress load. A ready session has explicit cleanup; dispose it before replacing the game. Ordinary stage changes belong to the game's lifecycle and keep its runtime alive. A new code graph gets a fresh runtime.

## Control executable origins

Remote modules are executable application code. The host sets `policy.codeOrigins`; the default allows only the host origin. Add a CDN origin only when you intend to execute packages hosted there.

Use exact-version npm URLs, commit-pinned GitHub CDN URLs, or immutable static hosting. Integrity checks verify bytes against a trusted manifest; they do not authenticate an untrusted manifest. See [packages and integrity](packages.html).
