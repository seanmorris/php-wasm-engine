---
title: Write a module
description-meta: Add reusable PHP systems and browser behavior to an existing game.
llms: guides
menus:
  documentation:
    parent: extend
    weight: 10
---

A module adds behavior to a running game: movement systems, entity factories, browser controls, or other reusable features. Modules share the game's entity registry and scheduler. The game entry point remains responsible for starting the loop, choosing maps, and signaling readiness.

This guide starts with a PHP module, shows how to declare it in a package, then covers optional entity and browser behavior. For the full manifest format, see [packages and integrity](packages.html).

<a id="register-php-behavior"></a>

## Register PHP systems and their cleanup

A PHP module file returns a callback that accepts [`Phonics\Package\Context`](api-phonics-package-context.html). Save the following as `module.php`:

```php
<?php
use Phonics\Package\Context;
use Phonics\Ecs\Registry;

return static function (Context $context): void {
    $context->system('example.clock', static function (Registry $entities, float $dt): void {
        // Update this module's components here.
    });

    $context->system('example.effects', static function (Registry $entities, float $dt): void {
        // Runs after example.clock.
    }, after: ['example.clock']);

    $context->onClose(static function (): void {
        // Release any external resources owned by this module.
    });
};
```

Each system receives the shared registry and elapsed time `$dt` in seconds. The `after` list makes `example.effects` run after `example.clock`; it is a scheduler dependency, rather than a delay.

System names must be unique across the session. Use a package-specific prefix to avoid conflicts with another module. When the context closes, it removes registered systems and invokes cleanup callbacks in reverse registration order. Register cleanup for resources your module owns, such as renderers or open handles.

## Declare and load the module

Add the module file to `php.files` and give it a named export in `phonics.json`. This fragment adds an export called `clock`:

```json
{
  "php": {
    "files": ["module.php"]
  },
  "exports": {
    "clock": {"type": "module", "php": "module.php"}
  }
}
```

Merge those entries into the existing manifest, preserving its other files and exports. Then select the module in one of two places:

- In a game export's `modules` array, use `"#clock"` for an export in the same package. Phonics activates that prerequisite before the game.
- In a Tiled world's `phonics.modules` list, use `"@example/clock"` if the world imports this package as `example`. See [world module lists](worlds.html#include-modules-needed-by-later-maps).

After changing the file or manifest, run `npx phonics integrity` and `npx phonics validate` from the package directory.

<a id="expose-entities-and-browser-services"></a>

## Give Tiled objects an entity factory

A factory turns a placed Tiled object into an entity's initial components. Register one inside the PHP module callback with `$context->factory(name, callback)`. For example, this factory gives an object a 16-by-16 bounds component:

```php
$context->factory('Example.Marker', static fn(array $object): array => [
    new \Phonics\Physics\Pose((float)$object['x'], (float)$object['y'], 8, 8)
]);
```

A `factory` export identifies that registered class and the module that installs it. Describe author-editable fields in the package's Tiled metadata so the editor can show their types and defaults. Systems supply the resulting entity's behavior.

For individual components attached through Tiled's nested classes, use component exports instead. The portable class catalog maps those classes to their component constructors. [Author in Tiled](tiled.html) explains components, presets, and object templates.

## Add browser behavior when needed

A browser entry is a self-contained ES module that exports `activate(context)`. It can use the game's canvas, loaded assets, and shared services. This small example focuses the canvas on a pointer press and removes the listener when the session closes:

```js
export function activate(context)
{
  const focusCanvas = () => context.canvas.focus();
  context.canvas.addEventListener('pointerdown', focusCanvas);

  return () => context.canvas.removeEventListener('pointerdown', focusCanvas);
}
```

The canvas must be focusable, for example with `tabindex="0"`. Declare the file in the package's `browser.files` list and set the module export's `browser` field to its path.

Return a cleanup callback as above, or register it with `context.onClose`. Choose one route for each resource so it is released once. Bundle any JavaScript dependencies into the browser entry before packaging; runtime exports must be self-contained.

The [browser context reference](api-js/types/application-runtime/browser-module-context.html) lists all supplied values. For shared mute and fullscreen controls, use [`QuickControls`](api-js/classes/quick-controls/quick-controls.html) from `context.services`.

<a id="coordinate-game-lifecycle"></a>

## Let the game control map loading and readiness

For a Tiled world, the starting map is normally preloaded. A game export can set `preloadStart: false` to show an intro before loading that map. A module that needs the starting map during registration can declare `preloadStart: true`, which requires that preload even when the game has deferred it.

List the modules needed by later maps explicitly on the world. The game's entry point then chooses when to load maps and run the shared scheduler. Once the game is ready, it calls `(vrzno_env('sceneReady'))(null)` to notify the host.

The repository's [composition fixtures](https://github.com/seanmorris/php-wasm-engine/tree/HEAD/tests/fixtures/composition) demonstrate separately packaged modules sharing a registry, factories, and scheduler. Inspect the version matching your checkout when following the example.
