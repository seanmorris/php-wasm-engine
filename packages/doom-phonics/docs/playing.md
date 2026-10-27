# Playing and controls

W/S moves, A/D strafes, and the mouse or right stick looks. E or controller A uses. Space, left click, or controller RT fires; right click or controller LT uses a weapon's alternate fire. Number keys or the D-pad select weapons. Escape releases pointer lock and P or Start pauses.

The title menu starts single-player or multiplayer modes, opens the WAD stack, and exposes controls and look settings. Gameplay bindings are stored independently for keyboard/mouse and controller. Mouse displacement and stick axes feed the same named look actions after device-specific sensitivity and deadzone processing. Video settings include a saved Blood Splatter range from off to 200%; the default 100% produces five droplets per damaging hit.

Episode starts, cooperative starts, and deathmatch starts are selected for the requested mode. Difficulty filters retain unflagged objects and include multiplayer-only objects only in multiplayer.

The status bar shows health, armor, ammo, keys, face state, arms, and the currently relevant ammo maximums. Pickups and cheats update the same typed inventory used by weapon firing.

On death, movement and weapons stop, the view drops toward the floor, the player scream runs from the actor's death state, and the strongest red PLAYPAL effect remains visible. After the short death presentation, press Fire or Use to restart the current map.

`IDOOPSALLGIBS` is a runtime cheat for WAD stacks that provide the Railgun. It removes the player's other weapons, grants infinite cells, and redirects gun-bearing monster attack actions through the same railgun hitscan and beam behavior. If the active stack has no Railgun, the cheat reports that it is unavailable and does not activate. It does not replace or reload DECORATE while the game is running.
