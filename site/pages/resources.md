---
title: Resource identity
description-meta: Resolve every reference from the document that declares it.
llms: guides
menus:
  documentation:
    parent: author
    weight: 30
---


## Follow the declaring resource

Relative paths resolve from their declaring document, not from the host page or a filesystem cache:

| Reference | Base URL |
| --- | --- |
| World → map | World |
| Map → external tileset | Map |
| External tileset → image | Tileset |
| Inline tileset → image | Map |
| Manifest → module or asset | Manifest |
| Inherited file property | Resource that declared the property |

Source URLs remain available after editor caching and WASM mounting. Native PHP consumers can also load local filesystem paths. Browser resolution follows URL rules, including root-relative paths, query strings, and fragments.

## Use pallets and fragments

`@arcade/game` resolves an export in the session's `arcade` pallet. A direct reference uses a manifest URL and export fragment, such as `https://example.test/arcade/phonics.json#game`. Missing exports and conflicting registrations fail instead of replacing another module silently.

Browser modules can call `context.resolve(reference, declaringUrl)`. PHP uses `Phonics\Asset\Resource::resolve` and asset scopes with the same session pallets. Request asynchronous assets before consuming their mounted files.

## Keep identity separate from state

Canonical URLs deduplicate resource bytes. Query strings distinguish versions; equal filenames at different URLs remain distinct resources. A map placed twice has separate mutable placement and entity state.

Loading supports cancellation, retries, scoped ownership, and shared-resource reference counts. Cleanup releases a scope's ownership; shared resources remain while another owner needs them. PHP classes remain loaded for their runtime's lifetime.

## Declare ordered resource stacks

Set a resource slot to `multiple: true` when one source placement should expand into an ordered list. Most stacks use one `requirement` for every entry. When the head has a distinct role, replace it with `stack.first` and `stack.following` requirements:

```json
{
  "resourceSlots": {
    "wad-stack": {
      "type": "url",
      "distribution": "local",
      "authority": "host",
      "required": true,
      "multiple": true,
      "accept": [".wad"],
      "stack": {
        "first": {
          "id": "game-data",
          "names": ["GAME.WAD", "freegame.wad"],
          "inspector": "@game/archive-inspector",
          "equivalence": "profile",
          "criteria": {"archiveType": "IWAD"}
        },
        "following": {
          "id": "game-overrides",
          "names": ["*.wad"],
          "inspector": "@game/archive-inspector",
          "equivalence": "integrity",
          "criteria": {"archiveTypes": ["IWAD", "PWAD"]}
        }
      }
    }
  }
}
```

The launcher displays this as one list. It revalidates entries after every reorder. During multiplayer negotiation the selected `first` requirement travels with the first offer and `following` travels with every later offer. This permits a declared compatible replacement for the stack root while keeping every override byte-identical between peers.
