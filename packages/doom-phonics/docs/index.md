# Doom-Phonics

Doom-Phonics adapts Doom-format archives to Phonics. It reads WAD maps and resources, builds DECORATE-driven ECS actors, runs mutable sector specials, and renders the world directly through GLES. The published package contains no commercial game data.

Its launcher stacks an IWAD with later WAD archives, whose DECORATE, KEYCONF, SNDINFO, and music lumps join the effective resources. Loose definition files come from the world manifest. Offline and peer-to-peer sessions use the same fixed-step gameplay commands.

## How to play

The demonstration starts on the shareware `DOOM1.WAD`. Choose **New Game** and a skill to play its starting map, or, in worlds that show **WAD Stack**, replace the base with another IWAD you own, add PWADs, and start Single Player, Co-op, or Deathmatch from the launcher. Explore each map, operate switches, collect keys, weapons, ammunition, health, and armor, fight the monsters blocking the route, and reach the exit. An intermission screen then tallies kills, items, secrets, and time before the next map, as described in [Playing and controls](playing.md). The status bar tracks the resources needed to survive the map. After dying, press Fire or Use once the death sequence finishes to restart.

Gameplay input can be rebound independently for keyboard and mouse or controller under **Settings → Input**. The default controller layout uses the left stick to move, the right stick to aim, the triggers to attack, and the D-pad to cycle weapons.

## Controls

| Action | Xbox | PlayStation | Keyboard and mouse |
| --- | --- | --- | --- |
| Move and strafe | <img class="controller-glyph" src="../../../art/controllers/xbox-button-ls.svg" alt="Xbox left stick"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-ls.svg" alt="PlayStation left stick"> | W, A, S, D |
| Look and aim | <img class="controller-glyph" src="../../../art/controllers/xbox-button-rs.svg" alt="Xbox right stick"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-rs.svg" alt="PlayStation right stick"> | Mouse |
| Fire | <img class="controller-glyph" src="../../../art/controllers/xbox-button-rt.svg" alt="Xbox RT button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-r2.svg" alt="PlayStation R2 button"> | Left click or Space |
| Alternate fire | <img class="controller-glyph" src="../../../art/controllers/xbox-button-lt.svg" alt="Xbox LT button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-l2.svg" alt="PlayStation L2 button"> | Right click |
| Use doors and switches | <img class="controller-glyph" src="../../../art/controllers/xbox-button-a.svg" alt="Xbox A button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-x.svg" alt="PlayStation Cross button"> | E |
| Previous or next weapon | <img class="controller-glyph" src="../../../art/controllers/xbox-dpad.svg" alt="Xbox D-pad"> | <img class="controller-glyph" src="../../../art/controllers/playstation-dpad.svg" alt="PlayStation D-pad"> | Mouse wheel |
| Select weapon slot | — | — | Number keys 0–9 |
| Pause | <img class="controller-glyph" src="../../../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | Escape or P |

## Cheats

Type a cheat during active gameplay; it takes effect as soon as the complete code is recognized. The same codes can be supplied through a world manifest's `init.cheats` value, as described in [WAD stacks and launcher](launcher.md).

| Code | Effect |
| --- | --- |
| `IDDQD` | Toggles god mode. |
| `IDFA` or `IDFFA` | Grants every weapon and ammo type in the active WAD stack, fills the ammo pools, and gives 200 armor. |
| `IDKFA` | Grants the full arsenal and all card and skull keys. |
| `IDCLIP` or `IDSPISPOPD` | Toggles collision clipping. |
| `IDBEHOLD` | Shows the available power-up suffixes. |
| `IDBEHOLDV` | Toggles invulnerability. |
| `IDBEHOLDS` | Toggles berserk strength, restores health up to 100, and selects the fist. |
| `IDBEHOLDI` | Toggles partial invisibility. |
| `IDBEHOLDR` | Toggles radiation protection. |
| `IDBEHOLDA` | Toggles the computer area map. |
| `IDBEHOLDL` | Toggles the light-amplification visor. |
| `IDCHOPPERS` | Grants the chainsaw and 30 seconds of invulnerability. |
| `IDMYPOS` | Displays the player's world coordinates and facing angle. |
| `IDCLEVxy` | Warps to episode `x`, map `y`; for example, `IDCLEV19` loads `E1M9`. Both digits must be 1–9, so only Doom-style `ExMy` maps are reachable; Doom II `MAPxx` maps cannot be selected this way. |
| `IDOOPSALLGIBS` | When the active WAD stack supplies a Railgun, toggles a Railgun-only mode with infinite cells for the player and gun-bearing enemies. The mode survives death and map restarts until disabled. |

## Executable PHP

| File | Role |
|---|---|
| `game.php` | Creates the 35 Hz game session, input profile, menu, frame pipeline, status reporting, and lifecycle cleanup. |
| `modules/episode.php` | Exposes the ordered Doom gameplay system set to world manifests. |
| `adapters/wad.php` | Exposes the WAD content adapter used to turn archive maps and resources into playable scopes. |

## What to study

- [Playing and controls](playing.md) covers menus, input, and game modes.
- [WAD stacks and launcher](launcher.md) explains archive selection and self-contained exports.
- [WAD and DECORATE support](wad-and-decorate.md) covers content adaptation.
- [Multiplayer and replication](multiplayer.md) describes timing and authority.
- [Rendering and performance](rendering.md) explains visibility, batches, and large maps.

The [PHP API](api/index.html) lists every public type declared by this package.
