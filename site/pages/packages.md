---
title: Package files and integrity checks
description-meta: Declare runtime files and exports, build an application, and keep its hashes current.
llms: guides
menus:
  documentation:
    parent: extend
    weight: 20
---

`phonics.json` tells Phonics which code and assets a package provides, what it depends on, and which file bytes it expects. The current manifest schema is version 1. The [CLI](cli.html) creates and validates this file.

A reusable package exposes features that other packages can select. An executable application adds a PHP entry point and can be packed into `dist/game.phar`. The same manifest describes both.

## Read the manifest

Adding a file to your directory does not automatically include it at runtime. Declare it in the appropriate list:

| Field | Purpose |
| --- | --- |
| `name`, `version`, `requires` | Package identity and supported runtime |
| `application` | PHP entry point, bundled assets, and optional local packaging dependencies |
| `dependencies` | Named manifest URLs and compatible version ranges |
| `exports` | Named features or assets another package can select |
| `php` | PHP files, namespace ownership, and bootstrap files |
| `browser` | Browser module files |
| `assets`, `preload` | Content files and resources to request early |
| `integrity` | Expected SHA-256 hashes of declared files |
| `tiled` | Editor classes, defaults, templates, and previews |

Supported dependency ranges are exact versions, `^x.y.z`, and `>=x.y.z`. The browser follows these declared dependencies; it does not run npm/Composer installation scripts or resolve their full package graphs.

## Choose what your package exports

An export gives a package feature a name. A reference such as `@arcade/player` selects the `player` export from the imported `arcade` package. Each export also has a type that tells the loader how to use it.

These types cover common game building blocks:

| Type | Purpose |
| --- | --- |
| `game` | Game entry selected by the application's PHP startup code |
| `module` | Code that registers systems, factories, and cleanup for the session |
| `factory` | Entity factory; names its registered `class` and `module` |
| `component` | Registered component constructor, which a portable catalog can bind to a Tiled class |
| `asset` | Declared file `path`, such as a map, tileset, or font |

These types connect optional content and networking services:

| Type | Purpose |
| --- | --- |
| `adapter` | PHP loader for a content format, selected by a world source's `adapter` |
| `system-set` | Group of PHP systems attached to matching content scopes through `bindings[].use` |
| `resource-inspector` | Browser module that checks launcher resources named by a requirement's `inspector` |
| `lobby-provider` | Online lobby selected by the application's network configuration |
| `transport` | Peer transport selected by the application's network configuration |

See [Write a module](modules.html) for registration code and [world adapters](world-adapters.html) for scope bindings.

<span id="package-an-application"></span>

## Build the executable application

`phonics pack` starts from `application.entry` and produces `dist/game.phar`. It includes explicitly declared files, the PHP engine, and local package dependencies. The PHP/SDL WebAssembly runtime is supplied separately by the browser host.

The browser starts the archive with `Phonics.run()`. Its PHP entry point controls initialization and selects the game export. Worlds are content loaded by that application; their maps and assets may be bundled or served remotely. Relative references keep the base URL of the file that declared them.

The packer does not download remote executable dependencies. Those packages are verified when loaded. To prepare a reusable npm distribution alongside the application archive, use `phonics build --out package-dist`.

<span id="regenerate-hashes"></span>

## Refresh hashes after editing files

Run these commands from your game package after changing PHP, browser code, or assets:

```sh
npx phonics integrity
npx phonics validate
```

`integrity` updates the expected file hashes in `phonics.json`; `validate` checks the package against that manifest. Commit the manifest with the files it describes.

An integrity mismatch means the bytes differ from their recorded hash. A missing-resource error means a required file could not be found. Check the named path or URL for the latter; rebuilding hashes cannot repair a bad URL.

npm and Composer distributions expose the same runtime manifest. Composer provides PHP installation, rather than a browser file CDN. Follow [release checks](release.html) when preparing an artifact for publication.

<span id="activate-in-dependency-order"></span>

## Understand loading order and PHP autoloading

The loader resolves dependencies and verifies declared PHP and browser files before activating modules. Each package keeps its relative layout inside the archive or a verified WebAssembly mount, so PHP code can use synchronous internal includes.

Namespace ownership and PHP autoloading are separate responsibilities. `php.autoload` declares which namespaces a package owns for validation. A bootstrap file must register the actual PHP autoloader; list it in both `php.files` and `php.bootstrap`. The CLI starter supplies this `autoload.php`. All declared PHP files must be available before the bootstrap runs.

The loader reports cycles, conflicting namespaces or registrations, incompatible versions, unsupported runtimes, and missing resources with their package context. Replacing loaded code with an incompatible package set requires a new runtime.
