# Playing and replays

## Controls

| Action | Keyboard and mouse | Controller |
|---|---|---|
| Steer left | Left, A, J, primary mouse button | D-pad Left, left stick left |
| Steer right | Right, D, L, secondary mouse button | D-pad Right, left stick right |
| Pause | Escape or P | Start / Options |

Touch screens show left and right steering zones over the bottom of the game. Steering is not analog: the fixed simulation samples whether each direction is held once per 55 ms tick, so a partly tilted stick steers at full strength once it registers. Holding both directions preserves current course velocity; releasing both applies drag.

Open **Input settings** to add or remove bindings separately for keyboard/mouse and controller. Capture waits until the activating control returns to neutral. Conflicts can replace an existing binding, share the physical source, or be cancelled. Pause bindings remain fixed so every configuration has an escape route. Valid changes are stored locally; blocked storage leaves the setting active for the session.

## Runs and stages

A fresh run begins at score zero. The stage-checkpoint menu offers all six starts: Stage 1 at 0, Stage 2 at 8,000, Stage 3 at 12,000, Stage 4 at 25,000, Stage 5 at 40,000, and Stage 6 at 100,000 points. Checkpoint scores, including the zero-score Stage 1 checkpoint, are deliberately kept in their own table.

The first three stages increase obstacle density. Stages four and five replace scattered gates with a moving road; stage five introduces breaks and wider blockers. Stage six continues indefinitely. Each live tick awards twenty points, including the tick that detects impact. After impact, the course and score freeze while the effect completes.

## Replays

Every non-replay run records two input bits per tick. The `.pslr` file contains a magic marker, version, starting checkpoint, seed, starting score, tick count, packed input stream, and CRC-32 checksum. Imports reject unknown versions, invalid checkpoints, mismatched starting scores, malformed lengths, corrupt checksums, and files over one MiB.

Replays drive the same `Simulation` class as live play. They do not read current bindings or touch state, so playback is deterministic for a given package version. The latest local run can be watched or exported. Fresh-run personal bests retain their replay separately from checkpoint results.
