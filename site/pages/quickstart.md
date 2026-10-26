---
title: Quickstart
description-meta: Make your first Phonics game, edit it in Tiled, and build a package.
llms: start
menus:
  documentation:
    parent: begin
    weight: 15
---

You’ll create a small game with a movable player, change its layout and speed in Tiled, change its color in PHP, and produce an npm tarball. Your game will own its code and world; neither demo game is required.

## Before you start

Have these ready:

- **Node.js 22 or newer**, with npm.
- **Tiled 1.12.2** for editing the map.
- A packed **Phonics npm artifact**, such as `seanmorris-phonics-0.1.0.tgz`.
- A built **PHP 8.4/SDL runtime directory** containing `php8.4-sdl.mjs` and its matching runtime files.

The commands below use local artifacts, so they do not assume the package is already published to npm. Replace `/path/to/...` with your actual absolute paths. If you need to prepare the framework artifacts or runtime from source, see [Contributing](contributing.html).

## Create your package

From a directory where you keep projects, scaffold a game using the CLI from the tarball:

```sh
npm exec --package=/path/to/seanmorris-phonics-0.1.0.tgz -- phonics init my-game --name @you/my-game
cd my-game
npm install --save-dev /path/to/seanmorris-phonics-0.1.0.tgz
npx phonics --help
```

`@you/my-game` is your package name; replace it with your own. Keep this terminal in `my-game` for the remaining commands. The local installation makes `npx phonics` available without installing a global command.

The starter includes:

| File | What you’ll use it for |
| --- | --- |
| `worlds/game.world` | Select the game package and starting map. |
| `worlds/start.tmj` | Place the player and set its speed in Tiled. |
| `src/Player.php` | Define the player’s movement settings. |
| `module.php` | Turn Tiled player objects into ECS components. |
| `game.php` | Register movement, draw the player, and coordinate cleanup. |
| `autoload.php` | Load your package’s PHP classes. |
| `phonics.json` | Declare exports, runtime files, and integrity hashes. |

## Play the world

```sh
npx phonics preview --runtime /path/to/php-sdl-wasm
```

Open the URL printed in the terminal. When the status reads **Ready**, click the canvas and move the green rectangle with **arrows or WASD**. The player stays inside a 640 × 360 playfield.

Leave this server running. Use a second terminal in `my-game` for edits and build commands. The preview serves files directly; reload the browser after changes. If the port is busy, add `--port 9142`.

## Understand the ECS

Phonics uses an **Entity Component System (ECS)** to separate game state from behavior. The starter has all three parts:

| Part | Meaning | In this game |
| --- | --- | --- |
| Entity | An ID in the session’s entity registry. | The player spawned from your Tiled object. |
| Component | A PHP object holding one part of an entity’s state. | `Player` stores movement speed; the framework’s `Pose` stores position and bounds. |
| System | A registered callback that updates entities with the components it needs. | The movement system reads input and speed, then changes `Pose`. |

When the game loads its map, the factory in `module.php` returns a `Pose` and a `Player` for each matching Tiled object. The spawner attaches those components to a new entity. The Tiled object describes its initial state; the live components hold its state while you play.

Look inside the movement callback in `game.php`:

```php
foreach($entities->query(Player::class) as $id) {
    $pose = $entities->get($id, Pose::class);
    $speed = $entities->get($id, Player::class)->speed;
    $pose->x = max(12, min(628, $pose->x + $engine->controls->axis('left', 'right') * $speed * $dt));
    $pose->y = max(12, min(348, $pose->y + $engine->controls->axis('up', 'down') * $speed * $dt));
}
```

[`query()`](api-phonics-ecs-registry.html#method-query) returns entity IDs that have a `Player` component. [`get()`](api-phonics-ecs-registry.html#method-get) retrieves a component attached to an ID. This starter’s factory always supplies both `Player` and [`Pose`](api-phonics-physics-pose.html), so the movement system can use both. Updating `$pose` changes the component stored in the registry.

The scheduler runs the callback on simulation updates. `$dt` is the simulation step in seconds: multiplying input direction by speed and `$dt` produces the movement for that step. The bounds keep the rectangle inside the playfield. Separately, the render callback reads the same `Pose` to draw the player at its current position.

To extend the game, add focused components such as `Health` and systems that query and update them. Keep factories responsible for initial state, systems responsible for behavior, and the game entry point responsible for startup and cleanup. Systems can declare ordering dependencies when one needs another’s results; see [Write a module](modules.html) and the [architecture reference](architecture.html).

## Change the layout in Tiled

1. Open `worlds/start.tmj` in Tiled.
2. Select the **Entities** object layer, then the object named **Player**.
3. Set its **X** position to `120` and **Y** position to `100`.
4. Under **Custom Properties**, change **speed** from `180` to `300`.
5. Save the map as the existing JSON map file.

In your second terminal, refresh the package’s hashes and validate the world:

```sh
npx phonics integrity
npx phonics validate --world worlds/game.world
```

Reload the preview. The player should start nearer the upper-left corner and move faster. Tiled supplies the object’s position and speed; your package’s factory and movement system apply them.

If you see an integrity mismatch, check that you saved the map and ran `integrity` in this game’s directory before reloading. For a missing-file error, check the path named in the error instead of repeatedly rebuilding hashes.

## Change the player in PHP

Open `game.php`. Inside the render callback, find the rectangle added to `$batch`:

```php
$batch[] = [$pose->x - 12, $pose->y - 12, 24, 24, .35, .85, .7, 1];
```

The first four values describe the rectangle. The last four are red, green, blue, and opacity, each from `0` to `1`. Change the color to orange:

```php
$batch[] = [$pose->x - 12, $pose->y - 12, 24, 24, 1, .55, .15, 1];
```

Save, run `npx phonics integrity`, and reload. Your orange player should retain the position and speed authored in Tiled. You changed the game without changing the preview host.

Declare any new PHP files in `phonics.json` before hashing or building them.

## Build and pack your game

```sh
npx phonics validate --world worlds/game.world
npx phonics build
npx phonics validate dist --world worlds/game.world
npm pack ./dist
```

You now have a `dist/` directory containing the declared game files and an npm tarball named after your package and version. The built package excludes undeclared scratch files and development scripts. These commands do **not** publish it to a registry.

To try the built copy, stop the first preview server with **Ctrl+C**, then run:

```sh
npx phonics preview dist --runtime /path/to/php-sdl-wasm
```

Open the newly printed URL and confirm the player still has your layout, speed, and color. Use **Stop** in the page to dispose the game session; Ctrl+C stops the server itself.

Next, learn how to [export reusable modules](modules.html), [compose packages in Tiled](tiled.html), and [prepare a release](release.html). The [programming API](api.html), [CLI reference](cli.html), and [manifest guide](packages.html) document the framework, commands, and package contract in detail.
