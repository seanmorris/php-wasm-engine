# Doom-Phonics

Doom-Phonics adapts Doom-format archives to Phonics. It reads WAD maps and resources, builds DECORATE-driven ECS actors, runs mutable sector specials, and renders the world directly through GLES. The published package contains no commercial game data.

Its launcher can stack an IWAD with later PWAD, DECORATE, SNDINFO, and music resources. Offline and peer-to-peer sessions use the same fixed-step gameplay commands.

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
