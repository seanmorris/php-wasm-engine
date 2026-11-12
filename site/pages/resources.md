---
title: Paths and shared assets
description-meta: Find the base for a relative path and understand when assets are shared or released.
llms: guides
menus:
  documentation:
    parent: author
    weight: 30
---

A map can refer to a tileset, which refers to an image, while the whole game runs from a different web page. Phonics keeps track of which document supplied each path. That lets the same content work in an editor cache, a packaged application, or a served world.

<span id="follow-the-declaring-resource"></span>

## Resolve paths from the file that contains them

Suppose `/game/maps/start.tmj` refers to `../tiles/terrain.tsj`. The tileset is `/game/tiles/terrain.tsj`. If that tileset refers to `terrain.png`, the image is `/game/tiles/terrain.png`, regardless of the host page's location.

The same rule applies throughout the resource chain:

| Reference | Resolve it relative to |
| --- | --- |
| World → map | World |
| Map → external tileset | Map |
| External tileset → image | Tileset |
| Inline tileset → image | Map |
| Manifest → module or asset | Manifest |
| Inherited file property | Resource that declared the property |

A copied or cached file retains its original source identity. Moving it into an editor cache or the WebAssembly filesystem does not silently change the meaning of its relative paths. Native PHP consumers can also use local filesystem paths. Browser resolution follows URL rules, including root-relative paths, query strings, and fragments.

<span id="use-pallets-and-fragments"></span>

## Refer to a package export by alias

An export is a named feature or asset in a package's `phonics.json`. If the world imports a package under the alias `arcade`, `@arcade/game` selects that package's `game` export. Phonics calls the alias a *pallet*.

The explicit form names the manifest URL and export fragment:

```text
https://example.test/arcade/phonics.json#game
```

The alias is shorthand within the current session. Missing exports and conflicting registrations produce errors rather than silently selecting another module.

Browser modules resolve references with `context.resolve(reference, declaringUrl)`. PHP uses `Phonics\Asset\Resource::resolve` and asset scopes with the same aliases. Request asynchronously loaded assets before reading their mounted files.

<span id="keep-identity-separate-from-state"></span>

## Share file bytes without sharing game state

Phonics uses a resource's resolved, or *canonical*, URL to avoid loading the same bytes repeatedly. Two files named `terrain.png` at different URLs remain different resources. Query strings can distinguish versions of a file.

That sharing does not make two placements of a map the same game state. Each placement has its own mutable state and entities, even when both use the same source file.

An asset scope records which resources a part of the game owns. Releasing a scope drops those ownership references; resources stay available while another owner still needs them. Loading also supports cancellation and retries. Loaded PHP classes remain for the lifetime of that PHP runtime.

<span id="declare-ordered-resource-stacks"></span>

## Load a base file followed by add-ons

Some applications need several data files in a specific order, such as a base archive followed by overrides. Declare those as one ordered resource slot. [Load an ordered list of data files](resource-stacks.html) covers the declaration, launcher behavior, and multiplayer matching rules.
