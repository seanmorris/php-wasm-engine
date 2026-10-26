---
title: Write a module
description-meta: Register small systems against a shared session.
llms: guides
menus:
  documentation:
    parent: extend
    weight: 10
---


## Register PHP behavior

A PHP module file returns a callback accepting [`Phonics\Package\Context`](api-phonics-package-context.html). The context provides the session's registry, scheduler, factories, and Tiled loader.

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

System names are unique. Explicit dependencies determine scheduler order. Context teardown removes registered systems and invokes cleanup in reverse registration order.

## Expose entities and browser services

Register namespaced factories with `context->factory(name, callback)` and describe their typed properties in the package's Tiled metadata. A factory export identifies its registered class and module. Keep components as data and implement behavior in independently registered systems.

Browser entries are self-contained ES modules exporting `activate(context)`. Return a cleanup callback or register it with `context.onClose`. The [browser context reference](api-browser-phonics.html#type-browser-module-context) documents every supplied value. The shared [`QuickControls`](api-browser-quick-controls.html) service supplies mute/fullscreen controls for browser games without importing Sane-Phonics.

The repository's [composition fixtures](https://github.com/seanmorris/php-wasm-engine/tree/HEAD/tests/fixtures/composition) demonstrate independently packaged modules. Match the current checkout when inspecting source: the checked-out branch may differ from the repository default.

## Coordinate game lifecycle

Modules needing the first map during registration declare `preloadStart: true`. A game can declare `preloadStart: false` to show an intro before loading a map; list its required modules explicitly on the world.

A game uses the shared registry and scheduler and calls `vrzno_env('sceneReady')()` when ready. Its entry point coordinates loading, transitions, and cleanup. It should not replace ECS systems with a single large entity class.
