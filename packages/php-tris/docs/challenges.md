# Challenge tracks

PHPTris includes five challenge tracks with fifteen ranks apiece. A track selects an objective and progressively tighter target values while retaining the ordinary piece and scoring rules.

Challenge state records the active track, rank, progress, target, completion, and failure conditions. The HUD reads that state without owning it, which keeps challenge evaluation testable without WebGL or browser input.

Add a challenge by defining its authored metadata, selecting the evaluator used by the game state, and providing concise HUD text for the objective and progress value.
