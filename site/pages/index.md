---
title: Phonics
description-meta: A PHP game engine for the browser.
eyebrow: PHP · Tiled · SDL · WebGL 2
hero:
  lead: Write games in PHP. Author content in Tiled when it fits. Ship an executable game archive.
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
      description: A PHP game engine for browsers, with optional Tiled content authoring.
      codeRepository: https://github.com/seanmorris/php-wasm-engine
      programmingLanguage: [PHP, JavaScript, WebAssembly]
      runtimePlatform: Web browser
      license: https://www.apache.org/licenses/LICENSE-2.0
      isAccessibleForFree: true
---


<div class="flow" aria-label="One executable archive carries the game into browser PHP and SDL"><span><b>01</b>Executable PHAR<small>Your bootstrap picks the game</small></span><span><b>02</b>Worlds and modules<small>Maps, systems, and assets</small></span><span><b>03</b>Browser session<small>PHP, SDL, and WebGL 2</small></span></div>

<span id="one-archive-is-the-whole-game"></span>

## Package your game as an executable archive

A PHAR is a PHP archive containing your application. The browser fetches it, starts the PHP/SDL runtime, and runs your PHP entry point. That code chooses the game and any content to load. Code and assets can be packaged together; worlds and large assets can also be served separately.

The host page starts each application through the same API:

```js
import {Phonics} from '@seanmorris/phonics';

const session = await Phonics.run('/dist/game.phar', {
  canvas: document.querySelector('canvas'),
  runtime: {url: '/runtime/php8.4-sdl.mjs'}
});

// Release the runtime when the host leaves the game.
await session.dispose();
```

Gameplay code is ordinary PHP. It runs natively under your tests and in WebAssembly for players.

## Where to start

- **New here?** The [quickstart](quickstart.html) takes you from an empty package to a world you can edit in Tiled and in PHP.
- **Want to see it first?** [Play eight finished games](games/), from a platformer to billiards and Doom. Each is an independent package.
- **Authoring content in Tiled?** [Import packages](tiled.html), place entities, and preview the saved world.
- **Building a package?** The [CLI](cli.html) scaffolds, previews, validates, and builds it.
- **Extending a game?** [Write a module](modules.html) that adds PHP systems and factories to the shared session.

## The engine ends where your game begins

Phonics supplies scheduling, asset loading and lifetimes, collision queries, rendering, input, text, menus, and effects. Its optional Tiled adapter adds map loading and streaming. Your package owns the game: movement, cameras, HUD, enemies, and levels. Packages are peers. Each declares its own code and assets, and none depends on another game.
