---
title: Game-package CLI
description-meta: Create, preview, validate, and build your own game package.
llms: guides
menus:
  documentation:
    parent: begin
    weight: 25
---

For a guided first project, follow the [quickstart tutorial](quickstart.html).

## Install the command

The Phonics npm package exposes the **`phonics`** bin command. It works outside this repository. Before registry publication, install a packed artifact into your own project:

```sh
npm install --save-dev /path/to/seanmorris-phonics-0.1.0.tgz
npx phonics --help
```

A global installation exposes `phonics` directly; a local installation exposes it through `npx` and npm scripts. Node.js 22 or newer is required. No npm installation scripts run during CLI builds, and the command does not publish packages.

## Create and preview a game

```sh
npx phonics init my-game --name @you/my-game
npx phonics preview my-game --runtime /path/to/php-sdl-wasm
```

Open the URL printed by `preview`. The starter displays a movable rectangle controlled by arrows or WASD. It includes a package-owned player component, a separately registered movement system, a factory, a Tiled map, and a world. It does not import Sane-Phonics or PHP-Noid.

Open `worlds/start.tmj` in Tiled to move the player or change its `speed` property. The generic preview host loads `worlds/game.world` by default; use `--world` for another package-relative world.

Supply a built PHP/SDL runtime with `--runtime`, set `PHP_SDL_ROOT`, or install it at `node_modules/php-sdl-wasm` inside your game project. The CLI does not silently download a runtime. To prepare the runtime from the framework source checkout, see [Contributing](contributing.html).

Both servers accept `--port` and `--host`. `preview` mounts the installed Phonics framework and the supplied runtime under `/__phonics/`. `serve` serves an ordinary static directory. PHP files are delivered as source bytes for the WASM runtime, never executed by the server.

## Generate a manifest for an existing project

Run `phonics init` without a directory to create just `phonics.json`, in the style of `npm init`:

```sh
cd my-game
npx phonics init
```

The wizard asks for the package name and version, game or module export, PHP entry point and autoloader, owned PHP namespace, and file lists. Name and version default to `package.json` when present. It detects root files and files under `src/`, `browser/`, `assets/`, and `worlds/`; it skips hidden files, symlinks, and development directories such as `tests/`, `scratch/`, and `node_modules/`. Review the suggested lists and add any runtime files stored elsewhere. Lists are comma-separated; use `-` for an empty list or an optional entry point, autoloader, or namespace you do not have.

It computes integrity hashes and shows the complete proposed manifest before asking whether to write it. Your source files and `package.json` are preserved. An existing `phonics.json` is never overwritten.

For defaults without prompts, or to target another directory:

```sh
npx phonics init --yes
# Equivalent explicit command:
npx phonics manifest ./my-game --yes --name @you/my-game --version 0.1.0
```

Choose one command for your project. `--yes` (or `-y`) is required in noninteractive scripts. `phonics init my-game` still scaffolds a complete starter in a new directory; `phonics manifest my-game` only generates metadata for an existing directory.

The generator does not infer factory exports, system dependencies, Tiled classes, or PHP class ownership from source code. Add these declarations as needed using the [manifest guide](packages.html). Namespace metadata describes ownership; your bootstrap must actually register the PHP autoloader. Browser files are listed but need explicit browser exports to activate them. If no PHP entry exists, the generated exports start empty. Finish with `phonics validate`, and validate your world once it is configured.

## Maintain the package contract

Declare every PHP file, browser module, and asset in [phonics.json](packages.html). Adding a file to disk does not automatically include it in the package.

```sh
cd my-game
phonics integrity
phonics validate
phonics validate --world worlds/game.world
```

`integrity` hashes the declared files. `validate` checks those hashes, resolves the package graph, checks runtime requirements and namespace conflicts, and confirms executable exports are declared. With `--world`, it also resolves the game, selected modules, map and tileset references, images, and file properties. Validation reads metadata and bytes; it does not execute downloaded game code. The Tiled extension provides additional editor property checks.

Remote dependencies require an explicit `--allow-origin https://your-cdn.example` for validation and preview. Repeat the option to allow several origins. This controls executable origins; the remote server still needs to supply CORS headers for browser preview.

## Build and release

```sh
phonics build
npm pack ./dist
```

`build` refreshes integrity and copies declared runtime files and package metadata into `dist/`. It removes package scripts and development dependencies from the built npm manifest. Undeclared files, such as scratch material, stay out of the artifact. Use `--out` to select another output directory. Existing output is replaced only when it carries the marker created by a previous Phonics build.

Test the artifact in a separate consumer, choose a license, and publish it as a separate release action. `phonics build` never invokes `npm publish`. See [release checks](release.html) for CDN composition and immutable URLs.
