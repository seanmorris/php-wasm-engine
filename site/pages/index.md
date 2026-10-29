---
title: Phonics
description-meta: Compose a world. Bring it to life.
eyebrow: PHP · Tiled · SDL · WebGL 2
hero:
  lead: Build games from worlds, systems, and reusable packages. Author in Tiled. Run PHP in the browser.
  actions:
    - label: Start the quickstart
      href: quickstart.html
      primary: true
    - label: Play the games
      href: games/
  shots:
    - label: Doom-Phonics
      href: games/doom-phonics/
      src: images/games/hero/doom-phonics.webp
      alt: Doom-Phonics gameplay
    - label: PHP-Noid
      href: games/php-noid/
      src: images/games/hero/php-noid.webp
      alt: PHP-Noid gameplay
    - label: Sane-Phonics
      href: games/sane-phonics/
      src: images/games/hero/sane-phonics.webp
      alt: Sane-Phonics gameplay
    - label: PHPTris
      href: games/php-tris/
      src: images/games/hero/php-tris.webp
      alt: PHPTris gameplay
    - label: PHP Slalom
      href: games/php-slalom/
      src: images/games/hero/php-slalom.webp
      alt: PHP Slalom gameplay
    - label: PHP Buster
      href: games/php-buster/
      src: images/games/hero/php-buster.webp
      alt: PHP Buster gameplay
llms: start
menus:
  documentation:
    parent: begin
    weight: 10
jsonld:
  "@context": https://schema.org
  "@graph":
    - "@type": WebSite
      name: Phonics documentation
      description: Build game worlds in PHP and run them through WebAssembly in the browser.
      inLanguage: en
    - "@type": SoftwareSourceCode
      name: Phonics
      description: A game-neutral PHP and browser ECS runtime for Tiled worlds.
      codeRepository: https://github.com/seanmorris/php-wasm-engine
      programmingLanguage: [PHP, JavaScript, WebAssembly]
      runtimePlatform: Web browser
      license: https://www.apache.org/licenses/LICENSE-2.0
      isAccessibleForFree: true
---


<div class="flow" aria-label="Tiled world loads packages which run in PHP and SDL"><span><b>01</b>Tiled world<small>Maps &amp; properties</small></span><span><b>02</b>Package exports<small>Games &amp; systems</small></span><span><b>03</b>PHP + SDL<small>A playable session</small></span></div>

## A world is the entry point

A Phonics world declares its game, imported packages, and starting map. The generic host resolves that graph, verifies its files, and starts the game. Change the world to choose another game; the host stays the same.

```js
import {Phonics} from '@seanmorris/phonics';

const session = await Phonics.loadWorld('/worlds/game.world', {
  canvas: document.querySelector('canvas'),
  runtime: {url: '/runtime/php8.4-sdl.mjs'}
});

// Release the runtime and session-owned resources when leaving.
await session.dispose();
```

## Choose your starting point

- **Build your first game:** follow the [quickstart tutorial](quickstart.html), from a new package to a playable world.
- **Play and inspect:** explore the [complete game packages](games/), from platforming and puzzles to PHP Slalom and Doom.
- **Design a world:** [add package URLs in Tiled](tiled.html), place entities, and launch your saved world.
- **Start your own game:** use the [game-package CLI](cli.html) to scaffold, preview, validate, and build.
- **Write a module:** [register PHP systems and factories](modules.html) against the shared session context.

## What belongs in the framework

Phonics owns ECS scheduling, Tiled parsing and streaming, asset lifetimes, geometry queries, rendering, input, text, menus, and generic effects. Game packages own movement rules, cameras, HUDs, enemies, and courses.

Every game package is an independent peer and declares its own code and assets. Legacy `PhpWasmEngine\` aliases remain until Phonics 1.0; install Sane-Phonics when using its old gameplay aliases.
