# Playing and controls

W/S or Up/Down moves, A/D strafes, the Left/Right arrows turn, and the mouse or right stick looks. Click the game to capture the mouse for look. E or controller A uses. Space, left click, or controller RT fires, and Z, Enter, or left Ctrl also fire; right click or controller LT uses a weapon's alternate fire. Number keys 0–9 select weapon slots, while the mouse wheel and D-pad cycle owned weapons: D-pad left or down selects the previous weapon and right or up the next. Escape, P, or Start pauses. While the mouse is captured, the browser may use the first Escape press only to release it.

The title menu offers **New Game** with the five skill levels, **Settings** for Input, Sound, and Video, **Read This**, and **PHP Info**. Worlds that declare a `network` section also show **Online Play** and **WAD Stack**; the WAD Stack launcher starts Single Player, Co-op, or Deathmatch locally, as described in [WAD stacks and the launcher](launcher.md) and [Multiplayer and replication](multiplayer.md#playing-online). The pause menu resumes, starts a new game, opens Settings, restarts the level, or quits to the title. Gameplay bindings are stored independently for keyboard/mouse and controller. Mouse displacement and stick axes feed the same named look actions after device-specific sensitivity and deadzone processing. Video settings include a saved Blood Splatter range from off to 200%; the default 100% produces five droplets per damaging hit.

Episode starts, cooperative starts, and deathmatch starts are selected for the requested mode. Difficulty filters retain unflagged objects and include multiplayer-only objects only in multiplayer.

The status bar shows health, armor, ammo, keys, face state, arms, and the currently relevant ammo maximums. Pickups and cheats update the same typed inventory used by weapon firing.

On death, movement and weapons stop, the view drops toward the floor, the player scream runs from the actor's death state, and the strongest red PLAYPAL effect remains visible. After the short death presentation, press Fire or Use to restart the current map.

`IDOOPSALLGIBS` is a runtime cheat for WAD stacks that provide the Railgun. It removes the player's other weapons, grants infinite cells, and redirects gun-bearing monster attack actions through the same railgun hitscan and beam behavior. If the active stack has no Railgun, the cheat reports that it is unavailable and does not activate. It does not replace or reload DECORATE while the game is running.

## Look and controller settings

**Settings → Input** contains Keyboard & Mouse and Controller binding pages. Capture waits for neutral input, supports alternative bindings and removal, and offers Replace, Share, or Cancel for conflicts. Escape, P, and Start stay reserved for Pause; Escape or Start cancels capture. Menu navigation stays fixed. Profiles and look settings are saved in browser storage as `doom-phonics.controls.v1`; invalid entries fall back independently, and unavailable storage allows session-only edits.

Right-stick look defaults to a 12% radial deadzone, a rescaled 1.5 exponent response, 180 degrees/second horizontally, and 120 degrees/second vertically. Controller sensitivity multipliers range from 0.25 to 3, and the deadzone ranges from 0 to 30%. Mouse sensitivity ranges from 0.5 to 2. Vertical inversion is optional. Releasing the stick stops integration without recentering. Mouse and controller look settings are independent.

Look renders at display rate: the view shows the simulated yaw plus any pending yaw, and the next fixed-step player command consumes that pending yaw exactly once. Pitch is absolute and visible immediately. Offline and online play use the same command sampler.

## Finishing a map

Reaching an exit pauses the game and opens the WAD's intermission screen. Kill, item, and secret percentages count up in turn, followed by the level time and, for Doom `E1M1`–`E4M9` and Doom II `MAP01`–`MAP32`, the original par time. Fire, Use, or menu confirmation completes the tally at once; pressing again shows the next map's name and loads it.

Normal exits advance to the next map. Secret exits lead to `E1M9` from `E1M3`, `E2M9` from `E2M5`, `E3M9` from `E3M6`, and `E4M9` from `E4M2`; each `ExM9` returns to the map after the one that led there. In Doom II, `MAP15`'s secret exit leads to `MAP31`, whose secret exit leads to `MAP32`, and both return to `MAP16`. `ExM8` and `MAP30` end the episode. Other map names follow the archive's map order. When the destination is missing from the WAD stack, or the episode ends, the game returns to the title.
