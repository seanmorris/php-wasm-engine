# Phonics

Phonics is a game-neutral PHP ECS runtime for Tiled worlds, running natively for simulation and in a PHP/SDL/WebGL 2 browser runtime for play. Games, entity factories, modules, and assets are independently packaged. The repository contains [Sane-Phonics](packages/sane-phonics/README.md), [PHP-Noid](packages/php-noid/README.md), [PHPTris](packages/php-tris/README.md), [PHP Slalom](packages/php-slalom/README.md), [PHP Buster](packages/php-buster/README.md), and [Doom-Phonics](packages/doom-phonics/README.md), plus the [Tiled integration](packages/tiled-phonics/README.md).

```js
import {Phonics} from '@seanmorris/phonics';
const session = await Phonics.loadWorld('https://your-host/game.world', {
  canvas: document.querySelector('canvas'),
  runtime: {url: '/runtime/php8.4-sdl.mjs'},
  policy: {codeOrigins: [location.origin, 'https://cdn.jsdelivr.net']}
});
// When the host leaves the game:
await session.dispose();
```

The loader reads `phonics.game`, `phonics.import.<alias>`, `phonics.start`, and optional `phonics.modules` from **Tiled custom properties** on a `.world` file. Imports are resolved before the game. `phonics.modules` is a JSON array stored as a string. A game is an export reference such as `@php-noid/game` or an absolute manifest URL with `#game`. The host has no game selection table. `/preview/` is the generic host, and each bundled game mounts lazily in its documentation overview under `/games/`.

## Author a world

1. Install the core and Tiled helper packages together. Install Tiled **1.12.2**, open a project and world, and install the extension as described in the [editor guide](packages/tiled-phonics/README.md).
2. Choose **File → Add Package URL**, enter the package's exact-version `phonics.json` URL, and assign a pallet alias.
3. Use **Phonics World Settings** to select a game, start map, and additional modules. On an object layer, **Place Phonics Entity** selects an exported factory. Move and configure the resulting object with Tiled's normal tools. Cached templates and tilesets are also available in the project's `.phonics-cache` directory.
4. Save. **Validate and Preview** checks imports and properties and launches the unchanged Phonics host with the world URL. Your static server must serve the world/maps and allow CORS for cross-origin assets.

URLs and `@` references are string properties. Use Tiled file properties for actual local files. Cached authoring resources record canonical sources in supported `phonics.source*` properties. References retain the declaring document's identity, including inherited template and tile properties. Maps placed in different worlds have separate mutable state; resources share canonical URL identity. Query strings distinguish versions; fragments select manifest exports.

## Package contract

Every distribution includes the same generated `phonics.json` (schema version 1). It declares `name`, exact `version`, runtime `requires`, named `dependencies` with manifest `url` and `version` range, typed `exports`, `php.files`, `php.autoload`, `php.bootstrap`, `browser.files`, `assets`, `preload`, `integrity`, and declarative `tiled` metadata. Supported ranges are exact, `^x.y.z`, and `>=x.y.z`; the browser does not install npm/Composer dependencies or execute installation scripts.

Export types are `game`, `module`, `factory`, `component`, and `asset`. Executable exports name a declared `php` and/or `browser` file. `modules` lists prerequisite module references; factories name their registered `class` and `module`. Dependencies resolve relative to their manifest. Asset exports resolve through `context.resolve(reference, declaringUrl)` in browser modules and normal asset scopes; PHP uses `Phonics\Asset\Resource::resolve` and `Assets` with the same session pallets. Request asynchronous assets before reading their mounted files. Browser entries must be self-contained ES module bundles exporting `activate(context)`; return cleanup or use `context.onClose`. PHP module files return a callback accepting `Phonics\Package\Context`: register `system(name, callback, after: [...])`, `factory(name, callback)`, and `onClose(callback)`. A module that needs the first map during registration declares `preloadStart: true`. A game may declare `preloadStart: false` to manage its own map-loading lifecycle (for example, an intro before the first level); its world must explicitly list any required modules. The game shares that context's registry and scheduler, starts its lifecycle, and calls `vrzno_env('sceneReady')()` when ready. The [fixture modules](tests/fixtures/composition) demonstrate independent composition; [PHP-Noid](packages/php-noid/game.php) demonstrates a full game lifecycle.

All declared PHP and browser files are fetched and SHA-256 checked before code runs. Each package keeps its relative layout in its own WASM mount. Missing exports, cycles, conflicting registrations, overlapping PHP namespaces, unsupported runtime requirements, and integrity failures report their declaring resource. PHP code lives for a session; loading another code graph creates a fresh runtime. A game's ordinary stage changes stay within its session. Loading supports abort signals, retries, scoped ownership, and shared resource reference counts.

Remote modules are executable code. The host, not the world, controls `policy.codeOrigins`; the default permits only the host origin. Serve packages from exact npm versions, commit-pinned GitHub URLs, or immutable static locations. Integrity detects file corruption relative to the trusted manifest; it does not authenticate an untrusted manifest. Composer installs PHP distributions; it is not used as a per-file CDN.

## Compatibility and boundaries

Core namespaces are `Phonics\`; games use `SanePhonics\` and `PhpNoid\`. `PhpWasmEngine\` aliases remain until 1.0, including normalized mixed-name ECS lookups. Install/load Sane-Phonics for old gameplay aliases. Core never imports Sane-Phonics. Legacy `bootEngine` and `/engine/examples/*` are deprecated adapters supplied by the development/demo distribution.

Core provides scheduling, loading, Tiled parsing and streaming, bounds indexing, geometry queries, rendering, input, menus, text, fonts, controller glyphs, shaders, and generic effects. The games own movement rules, cameras, HUD content, assets, and gameplay systems. Collision queries use neutral poses, bounds, masks and surface metadata; Sane-Phonics interprets rails and layer switches. Geometry keeps the existing 1/256-world-pixel tolerance.

Browser games receive `context.services.QuickControls`, the shared mute/fullscreen icon UI used by both demos. Construct it with an overlay container and fullscreen target, bind its buttons to the game's actions, update its accessible states, and call `close()` during session cleanup. PHP-Noid uses this core service without importing Sane-Phonics.

## Development and verification

```sh
npm ci
composer install
npm run setup:runtime
npm start
npm test
npm run test:packages
npm run test:browser
TILED_BIN=/path/to/Tiled-1.12.2/AppRun xvfb-run -a npm run test:tiled
TILED_BIN=/path/to/Tiled-1.12.2/AppRun xvfb-run -a npm run test:editor
npm run lint
composer lint
npm run test:artifacts
npm run build:docs
```

`npm test` aggregates the existing 304 PHP regressions, four PHPDoc tests, and package suites. The browser suite covers rendering, all existing demo courses, two npm-packed modules served from a second CORS origin, world relocation, and PHP-Noid's 25-stage lifecycle. Artifact checks install npm tarballs and Composer archives into temporary consumers, including a core-plus-PHP-Noid install without Sane-Phonics. No workspace symlinks are used by those consumers.

Regenerate authored assets with `npm run generate`; regenerate manifests **after** code or assets change with `npm run build:manifests`. Do not edit a published package in place: its integrity manifest identifies exact bytes. Production archives and the static build exclude tests, scratch archives, generators, and development tools.

## Release and jsDelivr smoke test

`npm run build:manifests && npm run test:artifacts` writes verified npm tarballs and Composer ZIP archives to ignored `dist/`. Review the manifests, licensing, and package contents, then publish the core and game/editor artifacts as a separate release operation. Nothing in this implementation publishes to a registry or establishes live CDN availability.

After publishing version `0.1.0`, serve `/preview/` with `https://cdn.jsdelivr.net` added to its host-owned `data-code-origins` array. In Tiled import `https://cdn.jsdelivr.net/npm/@seanmorris/php-noid@0.1.0/phonics.json`, select `@php-noid/game`, and place entities in a new local map/world. Serve that world, run **Validate and Preview**, verify movement, launch, capsule collection and stage restart, then stop and reload. Repeat with two independently published fixture modules and move the world/map directory. Inspect the network panel for exact-version package URLs, successful CORS, no Sane-Phonics downloads, and successful integrity checks. This is a documented post-publication check, not a claim that these URLs are already live.

## Documentation site

The smgen documentation lives in `site/` and builds to `docs/dist/`. Its generated programming reference covers every supported `Phonics\` class, public method and property, compatibility alias, npm entry point, and browser module service. Run `npm run build:site` for the guide or `npm run build:docs` for the complete distribution, then `npm run serve:docs` and open <http://localhost:9140/>. See [site/README.md](site/README.md) for prerequisites, the pinned generator, drafting outlines, and content sources.

## Game-package CLI

The npm distribution exposes `phonics` for people writing their own game packages:

```sh
phonics init my-game --name @you/my-game
phonics preview my-game --runtime /path/to/php-sdl-wasm
cd my-game
phonics integrity
phonics validate --world worlds/game.world
phonics build
npm pack ./dist
```

Run `phonics --help` for all commands. The CLI ships in the packed npm artifact and works without this checkout; before registry publication, install that tarball locally and use `npx phonics`. Builds include declared runtime files, not arbitrary project contents. Publishing is a separate operation. See the [CLI guide](site/pages/cli.md).

PHPTris is the independent falling-block puzzle package in `packages/php-tris`, playable at `/games/php-tris/`. Its three solo modes and five progressive challenge tracks use the same world loader, ECS, SDL rendering, and package contracts. See [its reference](packages/php-tris/README.md).

PHP Slalom is the deterministic Jet Slalom preservation port in `packages/php-slalom`, playable at `/games/php-slalom/`. Its simulation, checkpoint runs, score tables, input bindings, and compact replay codec are independent of the other games. See [its reference](packages/php-slalom/README.md).

PHP Buster is the deterministic Blockade Buster port in `packages/php-buster`, playable at `/games/php-buster/`. It preserves the original wave loop and credits while adding editable input, pointer/touch play, interpolation, and a local score table. See [its reference](packages/php-buster/README.md).
