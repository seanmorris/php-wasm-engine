---
title: Packages & integrity
description-meta: Publish the same contract through npm and Composer.
llms: guides
menus:
  documentation:
    parent: extend
    weight: 20
---


## Read the manifest

Every distribution exposes a versioned `phonics.json`. The current schema is version 1.

| Field | Purpose |
| --- | --- |
| `name`, `version`, `requires` | Package identity and supported runtime |
| `application` | Executable PHP entry, bundled application assets, and optional local packaging dependencies |
| `dependencies` | Named manifest URLs and compatible version ranges |
| `exports` | Named, typed exports, listed below |
| `php` | Files, namespace autoload mappings, and bootstrap files |
| `browser` | Browser module files |
| `assets`, `preload` | Content and eagerly requested resources |
| `integrity` | SHA-256 file hashes |
| `tiled` | Declarative classes, defaults, templates, and previews |

Each export declares one of these types:

| Type | Purpose |
| --- | --- |
| `game` | Game entry selected explicitly by the PHP application bootstrap |
| `module` | Registers systems, factories, and cleanup against the shared session context |
| `factory` | Tiled entity factory; names its registered `class` and `module` |
| `component` | Tiled entity class that objects reference in the same way as a factory |
| `asset` | Declared file `path`, such as a map, tileset, or font |
| `adapter` | PHP content adapter selected by a world source's `adapter` |
| `system-set` | PHP systems bound to matching adapter scopes by a world's `bindings[].use` |
| `resource-inspector` | Browser module that inspects launcher resources named by a resource requirement's `inspector` |
| `lobby-provider` | Online lobby selected by the application's network configuration |
| `transport` | Peer transport selected by the application's network configuration |

Supported version ranges are exact, `^x.y.z`, and `>=x.y.z`. The browser resolves declared dependencies; it does not run installation scripts or solve arbitrary npm/Composer graphs.

## Package an application

`phonics pack` produces an executable `dist/game.phar` from `application.entry` and the explicitly declared files. The archive includes the engine and local package dependencies. Worlds, maps, and other assets can be bundled or loaded from URLs; their declaring URLs remain the base for relative references. Remote executable packages are verified when loaded instead of being downloaded by the packer.

The browser starts this archive with `Phonics.run()`. Its PHP entry controls initialization, selects the game export, and supplies any network configuration. Worlds remain content consumed by that application.

Reusable packages still use `phonics.json` and typed exports. Use `phonics build --out package-dist` to prepare an npm package alongside the executable archive.

## Activate in dependency order

The loader resolves dependencies before execution and verifies declared PHP and browser files before activating modules. Each package retains its relative layout inside the archive or a verified WASM mount, so internal PHP includes can stay synchronous.

Cycles, overlapping PHP namespaces, incompatible versions, unsupported runtime requirements, missing resources, and conflicting registrations produce errors with resource context. Changing incompatible code graphs requires a new runtime.

The `php.autoload` mapping declares namespace ownership for graph validation. Register actual PHP autoloading in a file listed in both `php.files` and `php.bootstrap`; the CLI starter includes this `autoload.php` for you. All declared PHP files must be available from the archive or fetched and verified before that bootstrap runs.

## Regenerate hashes

```sh
npx phonics integrity
npx phonics validate
```

Run these commands from your game package after editing PHP, browser code, or assets. `phonics integrity` updates the hashes in your `phonics.json`; `phonics validate` checks the resulting package. Commit the manifest with the files it describes.

If validation reports a missing resource, check its declaring URL and your server’s paths. Rebuilding hashes does not repair an incorrectly hosted URL.

npm and Composer distributions expose the same manifest. Composer supports PHP installation; it is not a browser file CDN. [Release checks](release.html) verify packed consumers before publication.
