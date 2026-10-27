# PHP Buster

PHP Buster ports **Blockade Buster**, programmed by Greg Troutman, to a self-contained Phonics package. Graphics are courtesy of Ari Feldman's SpriteLib. A deterministic fixed-step PHP simulation runs the enemy waves, projectiles, pickups, collision, score, and stage transitions. The browser module supplies pointer and touch input, storage, sound, fullscreen, and accessible menu hit targets.

The port retains the original four-stage wave shape, increasing level pressure, firing intervals, upgrade drops, shield drops, accuracy bonus, and scrolling 320×480 presentation. It replaces the applet host with explicit package lifecycle, editable bindings, a local top-ten table, and input that works across keyboard, mouse, controller, and touch.

## What to study

- [Playing and scoring](playing.md) covers controls, upgrades, stages, and local scores.
- [Architecture](architecture.md) explains simulation ownership, rendering, the browser boundary, and the engine utilities promoted during the port.

The [PHP API](api/index.html) lists every public type in the package.
