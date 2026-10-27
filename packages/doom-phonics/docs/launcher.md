# WAD stacks and the launcher

The launcher presents one ordered resource stack. The first archive supplies the IWAD identity; later IWADs, PWADs, loose DECORATE lumps, KEYCONF, SNDINFO, and music packs contribute resources using last-file-wins lookup. The effective map list is derived from the complete stack. Weapon slots retain the existing `Player.WeaponSlot` and `Weapon.SlotNumber` assignments, then append KEYCONF entries while keeping each weapon name only at its first assignment.

Drag files into the launcher, choose a map and game mode, then start the session. A browser export must include every declared browser module and resource required by that world. Locally selected archives can remain local: online setup exchanges safe names, roles, sizes, digests, ordering, and the selected map rather than file bytes or acquisition URLs.

DOOM1 and DOOM2 are different base games and should not be stacked as interchangeable IWADs. Add-ons such as Skulltag belong after the selected base IWAD.

World authors can preconfigure a Doom session with the top-level `init` object:

```json
{
  "init": {
    "startMap": "MAP04",
    "cheats": ["iddqd", "idkfa"]
  }
}
```

`startMap` selects the initial map and the New Game destination. `cheats` accepts one string or an ordered list of classic typed cheat sequences and is applied after the new player has spawned. Launcher map selection still controls locally selected and negotiated matches.

The documentation overview mounts the runtime in place only after the reader selects the gameplay poster. Fullscreen remains available from the game controls without leaving the manual or its navigation.
