# Sane-Phonics

Sane-Phonics is the platformer package built on Phonics. It combines reusable movement profiles, terrain following, loops, vehicles, water, enemies, menus, and streamed Tiled worlds without moving those game rules into the engine.

The package manifest exposes focused modules as well as the complete game. Import `phonics.json#platformer` for movement and the `SanePhonics.Player` factory, or load the demo world for the title flow, level selection, and all gameplay systems.

## Executable PHP

| File | Role |
|---|---|
| `modules/platformer.php` | Registers the reusable player factory and terrain-backed platform movement system. |
| `examples/game.php` | Builds the complete demo engine, player, menus, HUD, startup flow, input, and render lifecycle. |
| `examples/*.php` | Defines the demo's scenes, cards, menus, world flow, breakables, and presentation helpers. |

## What to study

- [Gameplay systems](gameplay.md) explains actors, abilities, combat, and world ownership.
- [Loops and collision layers](loops.md) covers high-speed terrain traversal.
- [Menus and browser controls](menus.md) covers the canvas UI and PHP-info modal.
- [Startup](startup.md) follows the preloader, intro cards, title, and play transition.

The [PHP API](api/index.html) lists every public type declared by this package.
