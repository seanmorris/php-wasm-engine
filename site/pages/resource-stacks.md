---
title: Load an ordered list of data files
description-meta: Declare a base archive and add-ons as one ordered launcher field.
llms: guides
menus:
  documentation:
    parent: author
    weight: 35
---

A game may load a base data file and then apply several add-ons in order. A *resource slot* is an input the launcher asks the player or host to supply. Setting that slot to `multiple: true` makes it an ordered list, also called a *stack*.

This guide builds on [world adapters](world-adapters.html). For ordinary relative file references, see [paths and shared assets](resources.html).

## Choose how each position is checked

Use one `requirement` when every file follows the same validation rule. If the first file has a different role, use `stack.first` and `stack.following` instead.

For example, a Doom data stack needs a base archive first and accepts overrides afterward. The launcher checks each file against the rule for its current position.

## Declare a stack in the world

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

The slot uses the following settings:

| Setting | Role |
| --- | --- |
| `multiple: true` | Expand the selected source resource into an ordered list. |
| `accept` | File extensions offered by the picker. |
| `stack.first` | Requirement for the base file. |
| `stack.following` | Requirement for every later file. |
| `inspector` | Package export that inspects the selected data. |
| `equivalence: "profile"` | Match the declared compatibility profile for the base file. |
| `equivalence: "integrity"` | Require identical bytes for an override. |

The inspector and its criteria determine whether a file satisfies the requirement; a filename alone does not establish compatibility.

## Keep order consistent during selection and online play

The launcher presents the slot as one list and revalidates entries after every reorder. A file that was valid as an add-on may not be valid when moved to the first position.

During multiplayer negotiation, the first offered file carries the `first` requirement, and every later file carries `following`. This allows a declared compatible replacement for the base archive while keeping overrides byte-identical between peers.
