# PHP Slalom

PHP Slalom recreates Jet Slalom, originally programmed by MR-C, as a self-contained Phonics package. A fixed-step PHP simulation generates the course and collision state. WebGL renders the original low-resolution presentation into a responsive canvas, while the browser module supplies storage, sound, replay files, touch controls, fullscreen, and accessible menu hit targets.

The port retains the gameplay values that determine the run: 55 ms ticks, steering acceleration and drag, the six score stages, seeded obstacle placement, road motion, stage color transitions, and the twenty-tick impact sequence. It replaces Java applet integration and unbounded browser state with explicit package lifecycle and bounded persistence.

## What to study

- [Playing and replays](playing.md) describes controls, stage checkpoints, score tables, and `.pslr` files.
- [Architecture](architecture.md) explains the deterministic simulation, rendering pipeline, browser boundary, and reusable engine work.

The [PHP API](api/index.html) lists every public type in the package.
