---
title: PHP Billiards
description-meta: A photorealistic 3D pool practice table with deterministic PHP physics.
llms: games
menus:
  documentation:
    title: Overview
    parent: game-php-billiards
    weight: 0
---

<div class="game-embed external-game-embed" data-external-game-embed data-title="PHP Billiards" data-frame="https://seanmorris.github.io/phonics-examples/embed/php-billiards/">
<button type="button" class="game-embed-poster" aria-label="Load and play PHP Billiards">
<img src="../../images/games/php-billiards.svg" width="1280" height="720" fetchpriority="high" alt="PHP Billiards gameplay">
<span>Play PHP Billiards</span>
</button>
<div class="game-embed-runtime external-game-runtime" data-external-game-runtime hidden>
<div class="game-embed-controls"><p role="status">Preparing PHP Billiards…</p><button type="button">Stop</button></div>
</div>
</div>

PHP Billiards is a single-player pool practice table. PHP owns deterministic fixed-step simulation, swept ball contacts, cushion response, sliding and rolling friction, spin transfer, pockets, scratches, and complete pre-shot restoration. Its application-owned WebGL 2 renderer draws the table, balls, shadows, and SVG-derived material textures.

The game runs directly from its executable PHAR. It does not create a world or import Tiled, Doom, or WAD code. This makes it a compact example of the generic application lifecycle beneath Phonics' optional content systems.

Use the controls beside the table to set aim, power, top or back spin, and side spin. Drag the table to orbit the camera, use the wheel to zoom, and press **Space** to shoot. After a scratch, choose a legal cue-ball position and confirm it.

- [Open the full-page game](https://seanmorris.github.io/phonics-examples/games/php-billiards/)
- [Browse the examples repository](https://github.com/seanmorris/phonics-examples)

