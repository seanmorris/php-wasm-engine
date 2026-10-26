# Rendering and performance

The renderer builds visible subsectors from BSP data, resolves wall spans, batches flats and walls by material, and submits sprites, particles, projectiles, beams, sky, and HUD in ordered passes. Every pass receives the same camera yaw and pitch.

Large ZDoom and Skulltag maps stress sector triangulation, portals, 3D presentation features, actor counts, and texture lookup. Static geometry and material bindings are cached after load. Per-frame DOM work is limited to diagnostics that actually changed; the canvas renderer owns normal gameplay presentation.

Use the browser benchmark scripts to separate world-load cost, PHP simulation time, visibility traversal, draw submission, and browser composition. D2ST9 is the package stress map because it combines a large layout with extended actors and renderer conditionals.

Context restoration rebuilds GPU resources from mounted archive data. Map changes and session disposal release the old map's buffers, textures, audio, DOM overlays, and scoped actors.
