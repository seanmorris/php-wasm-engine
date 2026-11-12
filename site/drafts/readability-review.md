# Phonics documentation readability review — 12 November 2026

This review covers authored guides and framework/game manuals. Generated API pages
remain reference material; improve their prose at the source-comment level rather
than editing generated HTML. The companion Harvard outlines were written before
reorganizing and rewriting this pass.

I. Make each page's purpose clear before introducing implementation names.
    A. Start with a reader task and explain the relevant terms in context.
        1. Reworked `tiled.md`, `worlds.md`, `modules.md`, `resources.md`, and `packages.md`.
        2. Defined aliases, exports, scopes, presets, and resource slots before their contracts.
        3. Kept reference tables and exact field names after the explanation.
    B. Replace misleading site framing.
        1. “One archive is the whole game” becomes “Package your game as an executable archive.”
        2. “Build a world” becomes “Content and worlds”; Tiled remains optional.
        3. “Extend Phonics” becomes “Build game packages”; game authors need not modify the engine.

II. Split pages at changes of reader task.
    A. Separate world loading from content-adapter configuration.
        1. `worlds.md` explains selecting content and launching the application.
        2. `world-adapters.md` introduces the source/scope/binding model with the complete example.
    B. Separate ordinary paths from ordered launcher resources.
        1. `resources.md` explains relative paths, aliases, sharing, and ownership.
        2. `resource-stacks.md` preserves the base/add-on declaration and multiplayer rules.
    C. Replace Sane's 13,599-word gameplay chapter with a navigable guide and seven references.
        1. Movement/camera, vehicles, platforms/ropes/blocks, combat/rewards, water, worlds, rendering.
        2. Move camera behavior out of boss combat and ring loss beside ordinary damage.
        3. Preserve the old section addresses with summaries and links.
        4. Keep code examples, units, constraints, cleanup rules, and compatibility details.

III. Rewrite dense or context-free framework reference prose.
    A. Reorganize `docs/architecture.md` within its existing URL.
        1. Explain startup and ownership before inventories of class names.
        2. Put RenderTarget under rendering; separate input from browser host services.
        3. Use tables for activation operations, state policies, and tile grouping.
    B. Reorganize `docs/random-cards.md` around repeatable dice and card-pile operations.
        1. Introduce seed/purpose and distinct physical card instances before the wire format.
        2. Demonstrate sequential PHP/JavaScript calls, peeks, moves, and restoration.
        3. Retain exact sampling mathematics, byte format, and hidden-information limitations.
    C. Correct misleading maintenance details.
        1. Identify the input handler that continues while paused.
        2. Replace removed package-manual paths with canonical examples links.
        3. Explain where game chapters live and how PHONICS_EXAMPLES_ROOT selects them.

IV. Continue with these bounded editorial changes.
    A. `docs/input.md`: separate configurable controls from sequence recognition.
        1. Lead with one logical action; move default/legacy bindings to a reference section.
        2. Put combinations, timing, device limits, and recovery on an input-sequences page.
    B. `docs/ui.md`: coordinate with the pending text-page work.
        1. Begin with a capability table and one menu action before editable fields.
        2. Rename “Cards” to “Title and loading screens” to distinguish playing cards.
        3. Consider menus versus text/screens as separate guides. This pass changes only one obsolete link.
    C. `docs/ecs-performance.md`: separate current benchmarks from optimization history.
        1. Put interpretation and reproduction before cache/index implementation details.
        2. Preserve dated comparisons in a linked benchmark report.
    D. `games/php-poker/docs/architecture.md`: add navigation to its 1,773-word unsectioned reference.
        1. Separate action validation, authorized bot views, presentation, and private dealing.
        2. Group betting/showdown, deadlines/reload, and offline persistence under distinct headings.
        3. Consider a dedicated online-dealing page; keep cryptographic invariants intact.
    E. `games/php-poker/docs/design.md`: replace project-log framing and verify old asset claims.
        1. “Intent” → “Table appearance”; “Two looks” → “Classic and modern themes.”
        2. Remove agent ownership and private plan-node references from reader-facing prose.
        3. Verify the 64×64 SVG-emblem claim against the current 32×32 icon pipeline before rewriting it.
    F. `games/doom-phonics/docs/seeded-levels.md`: separate use from generator internals.
        1. Keep commands, flags, files, timing, and validation on the usage page.
        2. Move graph embedding, room fitting, cuts, elevations, and walls into generator design.
        3. Give reusable geometry helpers a reference section or page.
    G. `games/phlappy-phant/docs/architecture.md`: clarify three headings after current work lands.
        1. “Filling any frame” → “Resize without changing play.”
        2. “Browser boundary” → “Connect PHP to the browser.”
        3. “License boundary” → “Licenses and credits.”

V. Verify the reorganization.
    A. Preserve prior heading fragments and all moved code examples.
    B. Rebuild with the matching examples checkout and check navigation, links, and assets.
    C. Exercise documentation navigation in a browser without starting game runtimes.
    D. Keep game PHARs, runtime files, and other agents' preview work unchanged.
