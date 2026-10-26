# PHP-Noid

PHP-Noid is an original-art, 25-stage brick-breaker campaign. It demonstrates how a self-contained game package can own its maps, assets, factories, simulation systems, WebGL rendering, audio, menus, persistence, and lifecycle while reusing Phonics services.

The package imports no Sane-Phonics code or assets. Load `worlds/campaign.world` or import `phonics.json` in Tiled and select the `@php-noid/game` module.

## What to study

- [Playing PHP-Noid](playing.md) explains controls, scoring, powers, and enemy ships.
- [Campaign authoring](campaign.md) describes stage maps, factories, and capsule data.
- [Architecture and performance](architecture.md) explains the ECS split, collision indexes, HUD, and cleanup.

The [PHP API](api/index.html) lists every public type declared by this package.
