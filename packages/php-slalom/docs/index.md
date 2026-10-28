# PHP Slalom

PHP Slalom recreates Jet Slalom, originally programmed by MR-C, as a self-contained Phonics package. A fixed-step PHP simulation generates the course and collision state. WebGL renders the original low-resolution presentation into a responsive canvas, while the browser module supplies storage, sound, replay files, touch controls, fullscreen, and accessible menu hit targets.

The port retains the gameplay values that determine the run: 55 ms ticks, steering acceleration and drag, the six score stages, seeded obstacle placement, road motion, stage color transitions, and the twenty-tick impact sequence. It replaces Java applet integration and unbounded browser state with explicit package lifecycle and bounded persistence.

## How to play

Bank the jet left and right to pass the oncoming obstacles for as long as possible. Every live simulation tick adds points. Later stages increase obstacle density, introduce the moving road, add breaks and wider blockers, and eventually continue without an upper limit. Hitting an obstacle freezes the course while the impact animation completes and ends the run.

A fresh run starts from zero, while the checkpoint menu can begin at any of the six stage thresholds. Controller steering is analog. Touch screens expose left and right steering zones at the bottom of the game.

## Controls

| Action | Xbox | PlayStation | Keyboard and mouse |
| --- | --- | --- | --- |
| Steer left | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-ls-left.svg" alt="Xbox left stick left"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-button-left.svg" alt="Xbox D-pad left"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-ls-left.svg" alt="PlayStation left stick left"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-button-left.svg" alt="PlayStation D-pad left"></span> | Left, A, J, or left click |
| Steer right | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/xbox-button-ls-right.svg" alt="Xbox left stick right"><span>or</span><img class="controller-glyph" src="../../../art/controllers/xbox-button-right.svg" alt="Xbox D-pad right"></span> | <span class="controller-glyphs"><img class="controller-glyph" src="../../../art/controllers/playstation-button-ls-right.svg" alt="PlayStation left stick right"><span>or</span><img class="controller-glyph" src="../../../art/controllers/playstation-button-right.svg" alt="PlayStation D-pad right"></span> | Right, D, L, or right click |
| Pause | <img class="controller-glyph" src="../../../art/controllers/xbox-button-start.svg" alt="Xbox Start button"> | <img class="controller-glyph" src="../../../art/controllers/playstation-button-start.svg" alt="PlayStation Options button"> | Escape or P |

## What to study

- [Playing and replays](playing.md) describes controls, stage checkpoints, score tables, and `.pslr` files.
- [Architecture](architecture.md) explains the deterministic simulation, rendering pipeline, browser boundary, and reusable engine work.

The [PHP API](api/index.html) lists every public type in the package.
