---
title: Load content through adapters
description-meta: Connect archive content to the systems that run when its maps are active.
llms: guides
menus:
  documentation:
    parent: author
    weight: 25
---

Use a content adapter when your game reads a format such as a WAD archive, or needs to combine several content formats in one world. The adapter exposes that content to the same world runtime used by Tiled. [Load a world](worlds.html) covers the application launch and cleanup that also apply here.

## Connect content to gameplay

An adapter-backed world uses four terms. In the Doom example below, they mean:

| Term | Meaning in the example |
| --- | --- |
| Source | `episode`, a named collection of content loaded from the WAD resource |
| Adapter | `@doom/wad`, the package export that reads WAD archives |
| Scope | A part of the source that can become active, such as the map `E1M1` |
| Binding | A rule that runs `@doom/episode` systems while matching maps are active |

The world uses `type: "phonics-world"`. Follow the name `episode` through `sources`, `bindings`, and `start` to see how the content connects to its gameplay systems and starting map:

```json
{
  "type": "phonics-world",
  "schemaVersion": 1,
  "imports": {
    "doom": "https://cdn.example/doom-phonics/0.1.0/phonics.json"
  },
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

The binding matches maps tagged `episode-1` in the `episode` source. Its systems run while a matching scope is active. `transitions` describes the route from `E1M1` to `E1M2`; the game controls when to take that route.

Replace the example manifest URL with an immutable published URL and the integrity placeholder with the WAD's SHA-256 digest in base64. The executable PHAR supplies application code. The world supplies content URLs and may import additional verified modules. The package archive does not include the repository's reference WAD; supply a base WAD you are licensed to use.

## Pass settings that belong to the game

The optional `init` object carries game-specific settings. Phonics checks that it is an object and makes it available to the game, leaving the meaning of its keys to that game.

| Where the setting is read | Access path |
| --- | --- |
| Browser module | `context.world.init` |
| PHP module callback | `$context->world['init']` |
| PHP game entry point | `$phonics->world['init']` |

In Doom-Phonics, `startMap` selects the map for the initial title and new-game flow. It overrides `start.scope` for those operations; `start.source` still selects the content source.

Doom also accepts one classic cheat string or an ordered `cheats` string list. It applies them after creating the player for a new game. These are Doom options, rather than fields with built-in meaning across all Phonics games.

## Let the launcher choose resources

A world can declare resource slots instead of fixed URLs. The launcher fills those slots with selected resources. A slot with `multiple: true` expands into an ordered sequence at its position in `sources.*.resources`.

Use `stack.first` and `stack.following` when the first resource has different requirements from the rest; [ordered resource lists](resource-stacks.html) has the complete slot declaration. Doom-Phonics requires an IWAD base archive first, then accepts additional IWAD or PWAD archives. When archives define the same lump or whole map, the last archive takes precedence.

Doom's launcher uses the declared inspector metadata to preview resources and build the available map list. It passes the selected map as the world's replacement `start.scope`. The same binding path is used for local single-player launches and negotiated online matches.

## Set Doom's display resolution

Doom-Phonics sizes its output framebuffer to the canvas's physical display size. **Native** resolution renders the three-dimensional scene at that size.

The Options menu's **Classic** resolution uses `renderScale` as a scene multiplier. In the example, `0.5` renders the scene at half the output width and height, then enlarges it with nearest-neighbor composition. Weapons, the status bar, menus, PHP Info, and screen wipes stay at full output resolution.

A world can declare both Tiled and WAD sources. A game can use them, for example, to transition from a two-dimensional level into an adapter-owned three-dimensional special stage.

[Play Doom-Phonics](games/doom-phonics/) or inspect its [complete world definition](https://github.com/seanmorris/php-wasm-engine/blob/HEAD/packages/doom-phonics/demo/episode-one-online.phonics-world.json).
