# Phonics

Phonics is a game-neutral PHP ECS runtime for Tiled worlds, running natively for simulation and in a PHP/SDL/WebGL 2 browser runtime for play. Games, entity factories, modules, and assets are independently packaged. The [documentation site](https://seanmorris.github.io/php-wasm-engine/) has the quickstart, the guides, every example game, and the generated PHP and JavaScript references.

```js
import {Phonics} from '@seanmorris/phonics';
const session = await Phonics.run('https://your-host/dist/game.phar', {
  canvas: document.querySelector('canvas'),
  runtime: {url: '/runtime/php8.4-sdl.mjs'},
  policy: {codeOrigins: [location.origin, 'https://cdn.jsdelivr.net']}
});
// When the host leaves the game:
await session.dispose();
```

The browser executes the PHAR stub. Its authored PHP bootstrap chooses the game and optionally composes a Tiled or adapter-based content world. Declare `application.entry` in `phonics.json`, then run `npx phonics pack` to produce the archive. `options.init` passes application data such as `{world, startMap, cheats}`. Worlds and WADs can be packaged assets or external URLs. `/preview/` is the generic application host; each game mounts lazily in its documentation page under `/games/`.

The runtime requires PHP 8.4 SDL plus the pinned `php-wasm-phar` extension. Packaging requires native PHP with Phar; gameplay remains browser-only. See [the architecture](docs/architecture.md) for the application bootstrap and resource lifecycle.

## Example games

| Game | What it demonstrates |
| --- | --- |
| [Sane-Phonics](https://github.com/seanmorris/phonics-examples/tree/main/games/sane-phonics) | A streamed platformer with loops, vehicles, water, enemies, and three movement profiles. |
| [PHP-Noid](https://github.com/seanmorris/phonics-examples/tree/main/games/php-noid) | A 25-stage brick-breaker with stackable powers, enemy ships, and an arcade HUD. |
| [PHPTris](https://github.com/seanmorris/phonics-examples/tree/main/games/php-tris) | Three solo modes and five progressive falling-block challenge tracks. |
| [PHP Slalom](https://github.com/seanmorris/phonics-examples/tree/main/games/php-slalom) | A deterministic port of the rare Jet Slalom game with saved bindings and compact replays. |
| [PHP Buster](https://github.com/seanmorris/phonics-examples/tree/main/games/php-buster) | A deterministic port of Blockade Buster with original waves, editable input, and local scores. |
| [Doom-Phonics](https://github.com/seanmorris/phonics-examples/tree/main/games/doom-phonics) | A WAD adapter, DECORATE actor runtime, multiplayer simulation, and direct GLES renderer. |
| [PHP Billiards](https://github.com/seanmorris/phonics-examples/tree/main/games/php-billiards) | A photorealistic 3D pool practice table with deterministic PHP physics. |

Each game is maintained in the [phonics-examples repository](https://github.com/seanmorris/phonics-examples) with its own namespace, assets, tests, full-page route, and embed route. The documentation build imports their manuals and packages the six established local embeds at their existing URLs; PHP Billiards uses the examples site's embed. Release compatibility mirrors remain under `packages/` while the framework's package pipeline is separated. The [Tiled integration](packages/tiled-phonics/README.md) remains part of this repository.

## Get started

**Write your own game package** with the `phonics` command from the npm distribution; it works outside this checkout. Before registry publication, scaffold from the packed tarball, then install the tarball inside the new package so `npx phonics` resolves locally:

```sh
npm exec --package=/path/to/seanmorris-phonics-0.1.0.tgz -- phonics init my-game --name @you/my-game
cd my-game
npm install --save-dev /path/to/seanmorris-phonics-0.1.0.tgz
npx phonics preview --runtime /path/to/php-sdl-wasm
```

`--runtime` names a built PHP/SDL runtime directory containing `php8.4-sdl.mjs`; `PHP_SDL_ROOT` may supply it instead. After editing, `npx phonics integrity` refreshes the manifest hashes and `npx phonics validate --world worlds/game.world` checks the world. `npx phonics pack` builds `dist/game.phar`; `npx phonics build --out package-dist` followed by `npm pack ./package-dist` produces the npm artifact. Builds include declared runtime files, not arbitrary project contents, and nothing is published. `npx phonics --help` lists every command; the [quickstart](https://seanmorris.github.io/php-wasm-engine/quickstart.html) and the [CLI guide](site/pages/cli.md) walk through them.

**Work on this repository**, its games, or its documentation from a clone:

```sh
npm ci
composer install
npm run setup:runtime
npm start
```

`npm run setup:runtime` prints the runtime directory, first downloading the pinned runtime to `.runtime/` when none is installed; pass that directory to `phonics preview --runtime` when previewing your own packages. `npm start` serves the documentation and games at <http://localhost:9130/>. The test and lint commands are under [Development and verification](#development-and-verification).

## Author a world

1. Install Tiled **1.12.2** and the editor extension: install the packed core and `tiled-phonics` artifacts together, for example `npm install -g /path/to/seanmorris-phonics-0.1.0.tgz /path/to/seanmorris-tiled-phonics-0.1.0.tgz`, copy `extension/phonics.mjs` into Tiled's extensions directory, and restart Tiled. The [editor guide](packages/tiled-phonics/README.md) has the details.
2. Open a project and a world. Choose **File → Add Package URL**, enter the package's exact-version `phonics.json` URL, and assign a pallet alias.
3. Use **Phonics World Settings** to select a start map and additional modules. On an object layer, **Place Phonics Entity** selects an exported factory. Move and configure the resulting object with Tiled's normal tools. Cached templates and tilesets are also available in the project's `.phonics-cache` directory.
4. Save. **Validate and Preview** checks imports and properties and opens the Phonics host with an application PHAR URL and the served world URL. Your static server must serve the world and maps and allow CORS for cross-origin assets.

Package URLs and `@alias/export` references are string properties; use Tiled file properties for actual local files. Cached authoring resources record their canonical sources in `phonics.source*` properties, and references keep the declaring document's identity, including inherited template and tile properties, so relocating a project does not change what it depends on. Maps placed in different worlds have separate mutable state while sharing one canonical resource. Query strings distinguish versions; fragments select manifest exports. See [resource identity](site/pages/resources.md).

## Package contract

Every distribution includes the same generated `phonics.json` (schema version 1). It declares `name`, exact `version`, runtime `requires`, named `dependencies` with manifest `url` and `version` range, typed `exports`, `php.files`, `php.autoload`, `php.bootstrap`, `browser.files`, `assets`, `preload`, `integrity`, and declarative `tiled` metadata. Supported ranges are exact, `^x.y.z`, and `>=x.y.z`; the browser does not install npm/Composer dependencies or execute installation scripts.

Export types:

| Type | Meaning |
| --- | --- |
| `game` | Game entry selected explicitly by the PHP application bootstrap |
| `module` | Registers systems, factories, and cleanup against the shared session context |
| `factory` | Tiled entity factory; names its registered `class` and `module` |
| `component` | Tiled entity class that objects reference in the same way as a factory |
| `asset` | Declared file `path`, such as a map, tileset, or font |
| `adapter` | PHP content adapter selected by a world source's `adapter` |
| `system-set` | PHP systems bound to matching adapter scopes by a world's `bindings[].use` |
| `resource-inspector` | Browser module that inspects launcher resources named by a resource requirement's `inspector` |
| `lobby-provider` | Online lobby selected by the application's `network.lobby.provider` |
| `transport` | Peer transport selected by the application's `network.transport` reference or `network.transport.use` |

Executable exports name a declared `php` and/or `browser` file; core's built-in `@seanmorris/phonics` `webrtc` transport and `memory-lobby` provider are supplied by the loader instead. `modules` lists prerequisite module references; factories name their registered `class` and `module`. Dependencies resolve relative to their manifest. Asset exports resolve through `context.resolve(reference, declaringUrl)` in browser modules and normal asset scopes; PHP uses `Phonics\Asset\Resource::resolve` and `Assets` with the same session pallets. Request asynchronous assets before reading their mounted files.

A PHP module file returns a callback that receives `Phonics\Package\Context` and registers factories, systems, and cleanup against the shared session:

```php
<?php
use Phonics\Ecs\Registry;
use Phonics\Package\Context;
use Phonics\Physics\Pose;

return static function(Context $context): void {
	$context->factory('Example.Crate', static fn(array $object): array => [
		new Pose((float)$object['x'], (float)$object['y'], 8, 8)
	]);

	$context->system('example.drift', static function(Registry $entities, float $dt): void {
		foreach($entities->query(Pose::class) as $id) $entities->get($id, Pose::class)->x += 20 * $dt;
	});

	$context->system('example.effects', static function(Registry $entities, float $dt): void {
		// Runs after example.drift.
	}, after: ['example.drift']);

	$context->onClose(static function(): void {
		// Release resources this module owns.
	});
};
```

Browser entries are self-contained ES module bundles exporting `activate(context)`; return cleanup or use `context.onClose`. A module that needs the first map during registration declares `preloadStart: true`. A game may declare `preloadStart: false` to manage its own map-loading lifecycle, for example an intro before the first level; its world must then list every required module explicitly. The game shares that context's registry and scheduler, starts its lifecycle, and calls `vrzno_env('sceneReady')()` when ready. The [fixture modules](tests/fixtures/composition) demonstrate independent composition, [PHP-Noid](packages/php-noid/game.php) demonstrates a full game lifecycle, and [Write a module](site/pages/modules.md) covers the browser context.

The PHAR signature and optional host-supplied integrity are checked before its stub runs. Composition verifies declared PHP and browser dependencies before activation. Each package keeps its relative layout in its own WASM mount. Missing exports, cycles, conflicting registrations, overlapping PHP namespaces, unsupported runtime requirements, and integrity failures report their declaring resource. PHP code lives for a session; loading another code graph creates a fresh runtime. A game's ordinary stage changes stay within its session. Loading supports abort signals, retries, scoped ownership, and shared resource reference counts.

Remote modules are executable code. The host, not the world, controls `policy.codeOrigins`; the default permits only the host origin. Serve packages from exact npm versions, commit-pinned GitHub URLs, or immutable static locations. Integrity detects file corruption relative to the trusted manifest; it does not authenticate an untrusted manifest. Composer installs PHP distributions; it is not used as a per-file CDN.

## Compatibility and boundaries

Core namespaces are `Phonics\`; each game owns its own namespace, such as `SanePhonics\`, `PhpNoid\`, `PhpTris\`, `PhpSlalom\`, `PhpBuster\`, and `DoomPhonics\`. `PhpWasmEngine\` aliases remain until 1.0, including normalized mixed-name ECS lookups. Install/load Sane-Phonics for old gameplay aliases. Core never imports Sane-Phonics. Legacy `bootEngine` and `/engine/examples/*` are deprecated adapters supplied by the development/demo distribution.

Core provides scheduling, loading, Tiled parsing and streaming, bounds indexing, geometry queries, rendering, input, menus, text, fonts, controller glyphs, shaders, and generic effects. The games own movement rules, cameras, HUD content, assets, and gameplay systems. Collision queries use neutral poses, bounds, masks and surface metadata; Sane-Phonics interprets rails and layer switches. Geometry keeps the existing 1/256-world-pixel tolerance.

Browser games receive `context.services.QuickControls`, the shared mute/fullscreen icon UI used by every bundled game. Construct it with an overlay container and fullscreen target, bind its buttons to the game's actions, update its accessible states, and call `close()` during session cleanup. Because the service lives in core, games use it without importing Sane-Phonics.

## Development and verification

Pure local/public random streams and finite ordered/random card piles are documented in
[Deterministic random streams and finite piles](docs/random-cards.md). PHP and browser samplers share golden vectors;
private online dealing and network acceptance remain separate protocol responsibilities.

```sh
npm test
npm run test:browser
TILED_BIN=/path/to/Tiled-1.12.2/AppRun xvfb-run -a npm run test:tiled
TILED_BIN=/path/to/Tiled-1.12.2/AppRun xvfb-run -a npm run test:editor
npm run lint
composer lint
npm run test:artifacts
npm run build:docs
```

`npm test` aggregates the core PHP regressions, the PHPDoc tests, and the package suites (`npm run test:packages`). The browser suite covers rendering, all existing demo courses, two npm-packed modules served from a second CORS origin, world relocation, and PHP-Noid's 25-stage lifecycle. Artifact checks install npm tarballs and Composer archives into temporary consumers, including a core-plus-PHP-Noid install without Sane-Phonics. No workspace symlinks are used by those consumers.

Regenerate authored assets with `npm run generate`; regenerate manifests **after** code or assets change with `npm run build:manifests`. Do not edit a published package in place: its integrity manifest identifies exact bytes. Production archives and the static build exclude tests, scratch archives, generators, and development tools.

## Documentation site

The smgen documentation lives in `site/`, builds to `docs/dist/`, and is published at <https://seanmorris.github.io/php-wasm-engine/>. Its generated programming reference covers every supported `Phonics\` class, public method and property, compatibility alias, npm entry point, and browser module service. Run `npm run build:site` for the guide or `npm run build:docs` for the complete distribution. `npm run serve:docs` runs `build:docs` itself and then serves <http://localhost:9140/>. See [site/README.md](site/README.md) for prerequisites, the pinned generator, drafting outlines, and content sources.

## Release

`npm run build:manifests && npm run test:artifacts` writes verified npm tarballs and Composer ZIP archives to the ignored `dist/` directory. Review the manifests, licensing, and package contents, then publish the core and the game and editor artifacts as a separate release operation; no command in this repository publishes to a registry or a CDN. The [release guide](site/pages/release.md) covers testing a separate consumer and checking CDN composition after a version is live.
