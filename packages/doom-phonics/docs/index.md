# Doom-Phonics

Doom-Phonics adapts Doom-format archives to Phonics. It reads WAD maps and resources, builds DECORATE-driven ECS actors, runs mutable sector specials, and renders the world directly through GLES. The published package contains no commercial game data.

Its launcher can stack an IWAD with later PWAD, DECORATE, SNDINFO, and music resources. Offline and peer-to-peer sessions use the same fixed-step gameplay commands.

## How to play

Supply an IWAD you own, optionally add PWADs in the WAD stack, and start a single-player or multiplayer mode from the launcher. Explore each map, operate switches, collect keys, weapons, ammunition, health, and armor, fight the monsters blocking the route, and reach the exit. The status bar tracks the resources needed to survive the map. After dying, press Fire or Use once the death sequence finishes to restart.

Gameplay input can be rebound independently for keyboard and mouse or controller under **Settings → Input**. The default controller layout uses the left stick to move, the right stick to aim, the triggers to attack, and the D-pad to change weapons.

## Controls

| Action | Xbox | PlayStation | Keyboard and mouse |
| --- | --- | --- | --- |
| Move and strafe | <img class="controller-glyph" src="../../../art/controllers/xbox-button-ls.svg" alt="Xbox left stick"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-ls.svg" alt="PlayStation left stick"> | W, A, S, D |
| Look and aim | <img class="controller-glyph" src="../../../art/controllers/xbox-button-rs.svg" alt="Xbox right stick"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-rs.svg" alt="PlayStation right stick"> | Mouse |
| Fire | <img class="controller-glyph" src="../../../art/controllers/xbox-button-rt.svg" alt="Xbox RT button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-r2.svg" alt="PlayStation R2 button"> | Left click or Space |
| Alternate fire | <img class="controller-glyph" src="../../../art/controllers/xbox-button-lt.svg" alt="Xbox LT button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-l2.svg" alt="PlayStation L2 button"> | Right click |
| Use doors and switches | <img class="controller-glyph" src="../../../art/controllers/xbox-button-a.svg" alt="Xbox A button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-x.svg" alt="PlayStation Cross button"> | E |
| Change weapon | <img class="controller-glyph" src="../../../art/controllers/xbox-dpad.svg" alt="Xbox D-pad"> | <img class="controller-glyph" src="../../../art/controllers/playstation-dpad.svg" alt="PlayStation D-pad"> | Number keys 0–9 |
| Pause | <img class="controller-glyph" src="../../../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | P |

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
