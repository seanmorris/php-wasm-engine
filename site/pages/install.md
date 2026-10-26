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

Use Node.js 22 or newer. Install the Phonics npm package in your project to obtain the framework and `phonics` command. Before registry publication, use a packed artifact:

```sh
npm install --save-dev /path/to/seanmorris-phonics-0.1.0.tgz
npx phonics --help
```

Use Tiled 1.12.2 to author maps and worlds. Browser preview also needs a built PHP/SDL runtime. Native PHP consumers can install the PHP distribution through Composer; the CLI itself runs on Node.

## Create and preview a game

```sh
npx phonics init my-game --name @you/my-game
npx phonics preview my-game --runtime /path/to/php-sdl-wasm
```

Open the preview URL printed by the command. Move the starter player with arrows or WASD. The generated package contains its own component, movement system, factory, map, and world.

Use `--world worlds/another.world` to select another world in your package. `PHP_SDL_ROOT` can supply the runtime directory instead of `--runtime`. See the [CLI guide](cli.html) for all commands and options.

## Iterate on your package

Open `my-game/worlds/start.tmj` in Tiled and change the layout or player speed. After editing code or assets, refresh and verify the manifest:

```sh
cd my-game
phonics integrity
phonics validate --world worlds/game.world
```

Reload the preview after rebuilding hashes. An integrity mismatch means the served bytes differ from the manifest; a missing-resource error usually points to a path or hosting problem.

To work on Phonics itself or run the bundled demo source, follow [Contributing](contributing.html).
