---
title: Release a package
description-meta: Verify your game package before publishing an immutable version.
llms: project
menus:
  documentation:
    parent: maintain
    weight: 20
---

## Prepare your distribution

Run these commands from your game package, with the Phonics CLI installed there as in the [CLI guide](cli.html):

```sh
npx phonics integrity
npx phonics validate --world worlds/game.world
npx phonics build
npm pack ./dist
```

`phonics build` writes declared runtime files and package metadata to `dist/`. Inspect the tarball’s file list, choose a license, and confirm that `package.json` and `phonics.json` agree on the package name and version. Declare all required files explicitly so the package does not depend on undeclared source-tree content.

## Test a separate consumer

Install the tarball in an empty project rather than using a workspace link. Preview a world from the installed package with the installed CLI and your PHP/SDL runtime. Exercise input, stage changes, restarting, and cleanup. Verify that every dependency comes from a declared package or an explicitly supplied runtime.

## Publish separately

Registry publication is a separate release operation. The CLI never publishes automatically. When you are ready, publish the reviewed artifact or built directory using your normal npm release process.

Use exact versions or commit-pinned URLs. Do not change file contents under a published manifest; release a new version. npm and Composer distributions should expose the same runtime manifest.

## Check CDN composition

After publishing a chosen version:

1. Serve the generic preview host with the intended CDN added to its host-owned code-origin policy.
2. In Tiled, import the package's exact-version `phonics.json`, select its game export, and place exported entities in a new local world.
3. Serve that world, run **Validate and Preview**, and exercise movement, powers, restart, and cleanup.
4. Repeat with independently published modules served across origins. Relocate the world/map directory and launch it again.
5. Inspect network requests for exact URLs, CORS, integrity success, and the absence of undeclared dependencies.

An example URL shape is `https://cdn.jsdelivr.net/npm/@you/my-game@VERSION/phonics.json`. Replace the name and version with a package you have actually published.
