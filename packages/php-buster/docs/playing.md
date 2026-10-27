# Playing and scoring

## Controls

| Action | Keyboard and mouse | Controller | Touch |
|---|---|---|---|
| Move | Arrow keys, WASD, or mouse position | Left stick | Drag the craft |
| Fire | Space or primary mouse button | A or RB | Fire button |
| Pause | Escape or P | Start / Options | Browser pause control |

Keyboard and controller movement is analog and normalized, so diagonal movement is not faster. Mouse position targets the logical playfield directly. Touch drag begins at the craft's current position, which prevents the craft from jumping to the finger when a drag starts.

Open **Input settings** to add or remove gameplay bindings separately for keyboard/mouse and controller. Capture waits for the activating input to return to neutral, handles conflicts explicitly, and keeps the fixed pause controls available. Valid changes are stored locally; blocked browser storage leaves them active for the session.

## Waves, upgrades, and scores

Each stage alternates fighter squads and asteroid groups before artillery and a heavy fighter. Destroying the heavy fighter ends the stage. Completing stages one, two, and three advances the level and increases pressure.

The ruby from a heavy fighter upgrades the missile pattern through four weapon levels. Mini fighters can drop shields. A collision resets the weapon and removes one shield, followed by 450 ms of hit grace so a single overlap cannot empty the shield counter. The stage bonus is the current level multiplied by shot accuracy; a stage with no recorded shots safely awards zero.

After the last shield is lost, choose three initials and save the score. The browser keeps the best ten entries locally. Scores and bindings never leave the device.
