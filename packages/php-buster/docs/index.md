# PHP Buster

PHP Buster ports **Blockade Buster**, programmed by Greg Troutman, to a self-contained Phonics package. Graphics are courtesy of Ari Feldman's SpriteLib. A deterministic fixed-step PHP simulation runs the enemy waves, projectiles, pickups, collision, score, and stage transitions. The browser module supplies pointer and touch input, storage, sound, fullscreen, and accessible menu hit targets.

The port retains the original four-phase stage shape (fighter formations, asteroid groups, artillery, and a heavy fighter), three stages per level, increasing level pressure, firing intervals, upgrade drops, shield drops, accuracy bonus, and scrolling 320×480 presentation. It replaces the applet host with explicit package lifecycle, editable bindings, a local top-ten table, and input that works across keyboard, mouse, controller, and touch.

## How to play

Move the fighter around the playfield, keep firing, and avoid enemy shots and collisions. Each stage alternates fighter formations and asteroid groups before artillery and a heavy fighter arrive. Destroying the heavy fighter finishes the stage. Collect its ruby to upgrade the missile pattern, and catch mini-fighter shield drops to absorb another collision. Three completed stages advance the level; accuracy increases the stage bonus.

Mouse and touch input move directly toward the pointer. Controller stick movement is analog; keyboard movement is full-speed. Both are normalized, so diagonals are no faster than cardinal movement. Gameplay bindings can be edited under **Input settings**.

## Controls

| Action | Xbox | PlayStation | Keyboard and mouse |
| --- | --- | --- | --- |
| Move | <img class="controller-glyph" src="../../../art/controllers/xbox-button-ls.svg" alt="Xbox left stick"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-ls.svg" alt="PlayStation left stick"> | Arrow keys, W/A/S/D, or mouse position |
| Fire | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-a.svg" alt="Xbox A button"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-button-rb.svg" alt="Xbox RB button"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-x.svg" alt="PlayStation Cross button"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-button-r1.svg" alt="PlayStation R1 button"></span> | Space or left click |
| Pause | <img class="controller-glyph" src="../../../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | Escape or P |

## What to study

- [Playing and scoring](playing.md) covers controls, upgrades, stages, and local scores.
- [Architecture](architecture.md) explains simulation ownership, rendering, the browser boundary, and the engine utilities promoted during the port.

The [PHP API](api/index.html) lists every public type in the package.
