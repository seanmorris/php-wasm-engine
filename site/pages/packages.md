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
| `dependencies` | Named manifest URLs and compatible version ranges |
| `exports` | Typed `game`, `module`, `factory`, `component`, and `asset` exports |
| `php` | Files, namespace autoload mappings, and bootstrap files |
| `browser` | Browser module files |
| `assets`, `preload` | Content and eagerly requested resources |
| `integrity` | SHA-256 file hashes |
| `tiled` | Declarative classes, defaults, templates, and previews |

Supported version ranges are exact, `^x.y.z`, and `>=x.y.z`. The browser resolves declared dependencies; it does not run installation scripts or solve arbitrary npm/Composer graphs.

## Activate in dependency order

The loader resolves dependencies before execution and verifies declared PHP and browser files before activating modules. Each package retains its relative layout in an isolated WASM mount, so internal PHP includes can stay synchronous.

Cycles, overlapping PHP namespaces, incompatible versions, unsupported runtime requirements, missing resources, and conflicting registrations produce errors with resource context. Changing incompatible code graphs requires a new runtime.

The `php.autoload` mapping declares namespace ownership for graph validation. Register actual PHP autoloading in a file listed in both `php.files` and `php.bootstrap`; the CLI starter includes this `autoload.php` for you. All PHP files must be fetched before that bootstrap runs.

## Regenerate hashes

```sh
phonics integrity
phonics validate
```

Run these commands from your game package after editing PHP, browser code, or assets. `phonics integrity` updates the hashes in your `phonics.json`; `phonics validate` checks the resulting package. Commit the manifest with the files it describes.

If validation reports a missing resource, check its declaring URL and your server’s paths. Rebuilding hashes does not repair an incorrectly hosted URL.

npm and Composer distributions expose the same manifest. Composer supports PHP installation; it is not a browser file CDN. [Release checks](release.html) verify packed consumers before publication.
