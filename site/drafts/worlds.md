# Load a world — drafting outline

Reader: a game author who has an executable application and wants to choose its content.
Outcome: explain the application/world boundary, declare a Tiled world, and launch and dispose its session.

I. Establish what a world supplies
    A. The executable application selects code and the game export.
    B. The world selects maps, content, and optional modules.
        1. Tiled worlds are the direct route for editor-authored maps.
        2. Adapter-backed worlds support other formats and mixed sources.
II. Declare maps and module references
    A. Show a small Tiled world with one map, import alias, and starting map.
        1. Resolve relative paths from the world file.
        2. Explain the alias used in export references.
    B. Declare additional modules before loading later maps.
        1. Prefer native Tiled list properties.
            a. Each list entry carries its own type and value.
            b. Keep JSON-string lists readable for existing worlds.
        2. Link to editor setup and scope activation rather than repeat them.
III. Launch and replace the application
    A. Pass the world URL to Phonics.run through init.
        1. Name the required canvas, runtime URL, and built archive.
        2. Preserve the working JavaScript example.
    B. Separate cancellation, disposal, and stage changes.
        1. Abort a load that is still in progress.
        2. Dispose a ready session when leaving the application.
        3. Keep the runtime for ordinary stage changes.
IV. Choose adapters and allowed code origins
    A. Link to the focused adapter guide from the old adapter heading.
    B. Explain executable-origin policy and manifest trust.
        1. Require explicit permission for a CDN origin.
        2. Distinguish immutable URLs and byte integrity from publisher trust.

Editorial decisions:
- Move the long WAD example and Doom-specific settings to world-adapters.md.
- Keep old heading IDs through explicit anchors when headings become more descriptive.
- Correct the stale claim that phonics.modules must be a JSON string.
