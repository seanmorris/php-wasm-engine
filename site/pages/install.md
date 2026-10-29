---
title: Run locally
description-meta: From a game package to a playable world.
llms: start
menus:
  documentation:
    parent: begin
    weight: 20
---

For a complete walkthrough with a game you can edit and play, start with the [quickstart tutorial](quickstart.html).

## Install Phonics

Use Node.js 22 or newer. The Phonics npm package supplies the framework and the `phonics` command. Before registry publication, use a packed artifact: run its CLI once to scaffold a package, then install the artifact inside that package so `npx phonics` resolves locally.

Use Tiled 1.12.2 to author maps and worlds. Browser preview also needs a built PHP/SDL runtime directory containing `php8.4-sdl.mjs`. From a Phonics source checkout, `npm run setup:runtime` prints that directory, first downloading the pinned runtime to `<checkout>/.runtime` when none is installed; see [Contributing](contributing.html). Native PHP consumers can install the PHP distribution through Composer; the CLI itself runs on Node.

## Create and preview a game

```sh
npm exec --package=/path/to/seanmorris-phonics-0.1.0.tgz -- phonics init my-game --name @you/my-game
cd my-game
npm install --save-dev /path/to/seanmorris-phonics-0.1.0.tgz
npx phonics preview --runtime /path/to/php-sdl-wasm
```

Open the preview URL printed by the command. Move the starter player with arrows or WASD. The generated package contains its own component, movement system, factory, map, and world.

Use `--world worlds/another.world` to select another world in your package. `PHP_SDL_ROOT` can supply the runtime directory instead of `--runtime`. See the [CLI guide](cli.html) for all commands and options.

## Iterate on your package

Open `worlds/start.tmj` in Tiled and change the layout or player speed. After editing code or assets, refresh and verify the manifest from a second terminal in `my-game`:

```sh
npx phonics integrity
npx phonics validate --world worlds/game.world
```

Reload the preview after rebuilding hashes. An integrity mismatch means the served bytes differ from the manifest; a missing-resource error usually points to a path or hosting problem.

To work on Phonics itself or run the bundled demo source, follow [Contributing](contributing.html).
