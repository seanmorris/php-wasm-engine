---
title: Contributing
description-meta: Work on the Phonics framework, bundled demos, and documentation.
llms: project
menus:
  documentation:
    parent: maintain
    weight: 30
---


This page is for contributors to the Phonics repository. To create and ship your own game package, follow the [CLI guide](cli.html) and [package release workflow](release.html).

## Set up the repository

Use Node.js 22 or newer, PHP CLI, Composer, and Git. Install Tiled 1.12.2 for editor work. The documentation build also needs the tools listed below.

```sh
git clone https://github.com/seanmorris/php-wasm-engine.git
cd php-wasm-engine
npm ci
composer install
npm run setup:runtime
npm start
```

`npm run setup:runtime` prints the PHP/SDL runtime directory used by the demos, first downloading the runtime pinned in `runtime-lock.json` to `.runtime/` when no runtime is installed. `PHP_SDL_ROOT` selects another built runtime. Pass the printed directory to `phonics preview --runtime` when previewing your own packages.

Open `http://localhost:9130/`. The homepage is the documentation; bundled games run directly from their `/games/<name>/` overview pages. Their source lives in `packages/`; framework services live in `src/` and `browser/`.

For development of the Tiled extension, expose the workspace helper with `npm link --workspace @seanmorris/tiled-phonics`.

## Regenerate repository manifests

After changing framework or bundled-game executable files and assets:

```sh
npm run build:manifests
```

This command regenerates all manifests maintained by this repository, including composition fixtures. Commit them with the source changes. Game-package authors use `phonics integrity` in their own project instead.

## Run focused checks

```sh
npm test
npm run test:browser
npm run test:generated
npm run lint
composer lint
npm run test:artifacts
npm run test:cli
npm run test:cli:browser
```

Editor checks target Tiled 1.12.2:

```sh
TILED_BIN=/path/to/Tiled/AppRun xvfb-run -a npm run test:tiled
TILED_BIN=/path/to/Tiled/AppRun xvfb-run -a npm run test:editor
```

## Build the documentation

This site uses [smgen](https://github.com/seanmorris/smgen), pinned in `site/.smgen-version`. Its first build fetches that exact commit; subsequent builds reuse the ignored cache. Required tools are Bash 4+, Git, PHP with YAML support, Pandoc, and **mikefarah/yq v4**. A bundled PHP helper supplies UUIDs when `uuid` and `uuidgen` are unavailable.

```sh
npm run build:site    # Documentation only
npm run build:docs    # Games, runtime, packages, and documentation
npm run test:site     # Generated links, navigation, and assets
npm run serve:docs    # Rebuild the complete site and serve http://localhost:9140/
```

`serve:docs` rebuilds the documentation, game packages, assets, and runtime on startup and serves the same static homepage used in production. It does not watch for subsequent edits.

The complete static distribution lives in `docs/dist`. Documentation is at the site root and every game runs inside its overview under `/games/`. To deploy beneath a path, build the site with its full public prefix, for example `BASE_URL=/phonics npm run build:site`. Publish the entire `docs/dist` tree under `/phonics/`.

## Maintain the content

Edit `site/pages`, `site/templates`, or `site/static`, then rebuild. Reference chapters are imported from repository documentation at build time; edit their source files rather than generated copies. Harvard outlines in `site/drafts` guide the site's structure and each page's argument. They are drafting materials, not public navigation numbering.

The generator combines server-rendered navigation, Markdown content, PHP templates, a sidebar, and a per-page contents list. No game runtime is downloaded just to read the guide.
