# PHP-Noid

PHP-Noid is an original-art, 25-stage brick-breaker campaign. It demonstrates how a self-contained game package can own its maps, assets, factories, simulation systems, WebGL rendering, audio, menus, persistence, and lifecycle while reusing Phonics services.

The package imports no Sane-Phonics code or assets. Load `worlds/campaign.world` or import `phonics.json` in Tiled and select the `@php-noid/game` module.

## How to play

Launch the ball, keep it above the paddle, and clear every destructible brick. Copper and silver bricks need extra hits, gold bricks are permanent, and green metal requires red energy. Catch capsules to resize the paddle, split or alter the ball, equip lasers or missiles, gain another life, or advance the stage. Enemy ships can change the board, fire back, home in on the paddle, obstruct the arena, or drop helpful supplies.

Move with the pointer, keyboard, or controller. The same Fire control launches a waiting ball, releases a caught ball, and fires the active paddle weapon. The game adds no on-screen touch buttons; touch input reaches the playfield as mouse input.

## Controls

| Action | Xbox | PlayStation | Keyboard and mouse |
| --- | --- | --- | --- |
| Move paddle | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-ls.svg" alt="Xbox left stick"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-dpad.svg" alt="Xbox D-pad"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-ls.svg" alt="PlayStation left stick"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-dpad.svg" alt="PlayStation D-pad"></span> | Mouse, Left/Right, or A/D |
| Launch, release, or fire | <img class="controller-glyph" src="../../../art/controllers/xbox-button-a.svg" alt="Xbox A button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-x.svg" alt="PlayStation Cross button"> | Left click, Space, Z, or Enter |
| Pause | <img class="controller-glyph" src="../../../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | Right click, P, or Escape |
| Restart | <img class="controller-glyph" src="../../../art/controllers/xbox-button-back.svg" alt="Xbox Back button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-select.svg" alt="PlayStation Select button"> | R |

## What to study

- [Playing PHP-Noid](playing.md) explains controls, scoring, powers, and enemy ships.
- [Campaign authoring](campaign.md) describes stage maps, factories, and capsule data.
- [Architecture and performance](architecture.md) explains the ECS split, collision indexes, HUD, and cleanup.

The [PHP API](api/index.html) lists every public type declared by this package.
