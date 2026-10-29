# Playing PHP-Noid

Move with the mouse, Left/Right, A/D, or a controller. Click, press Space, Z, or Enter, or press controller A to launch, release a caught ball, and fire equipped weapons. Right click, P, Escape, or Start pauses. R or controller Back/Select restarts the campaign. Fire after game over continues from the current stage. There are no on-screen touch buttons; touch input reaches the playfield as mouse input.

Compatible powers keep independent timers. Paddle size, Catch, one weapon, Power Ball, and ball speed can coexist. Expand/Shrink, Fast/Slow, and Lasers/Missiles replace only their counterpart. Split adds balls, Extra Life adds a paddle, and Stage Exit advances the campaign.

Ordinary, copper, and silver bricks take one, two, and three hits. Gold is permanent. Green metal requires red energy. Enemy ships add hazards and supplies: Menacers alter the board, Destroyers fire, Annihilators home, Sympathizers drop helpful capsules, and Floaters obstruct the arena.

The side console keeps score, best score, stage, lives, active powers, timers, and capsule help visible. Scores persist for the current world when browser storage is available.

## Scoring

A destroyed brick awards its Tiled `score` property, which defaults to 100. The campaign sets 100 points per hit: ordinary bricks award 100, copper 200, and silver 300. A brick converted by Menacer energy keeps its original value. Green metal and gold award nothing. Destroyer and Annihilator ships award 500 points; Menacers and Floaters award 250. Sympathizers award none, and a ship retired by paddle contact scores nothing. Capsules, lives, and stage clears add no points.
