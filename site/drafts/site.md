# Phonics documentation — Harvard site outline

The site follows reader tasks: start a game, author optional content, build packages,
then consult a reference. Each changed page has its own Harvard drafting outline.
These outlines are planning tools; published pages use descriptive headings.

I. Get a game running
    A. Explain the executable-application model (index)
        1. A browser starts a PHP archive with a separate PHP/SDL runtime
        2. The application chooses game code and optional world content
    B. Build a first game through a tutorial (quickstart)
        1. Preview a player, edit its map and PHP behavior, then build
        2. Introduce entities, components, systems, and factories in context
    C. Find installation and command details (install, cli)
    D. Play and study independent games (games)
II. Author and load content
    A. Load a Tiled world and start/stop the application (worlds)
    B. Place objects and edit nested settings (tiled)
    C. Configure other content formats and scoped systems (world-adapters)
    D. Resolve paths and manage shared assets (resources)
    E. Supply ordered base files and overrides (resource-stacks)
III. Build reusable game packages
    A. Register behavior and cleanup (modules)
    B. Declare exports, files, dependencies, and integrity (packages)
IV. Consult framework references
    A. Trace startup, updates, and ownership (architecture)
    B. Configure controls and recognize input sequences (input)
    C. Build menus and title/loading screens (ui)
    D. Use reproducible random streams and card piles (random-cards)
    E. Measure and understand ECS performance (performance)
    F. Look up exact PHP and JavaScript contracts (api)
V. Study game-specific behavior
    A. Use each game's overview and authoring guide as the entry point
    B. Separate Sane movement, vehicles, platforms, combat, water, worlds, and rendering
    C. Keep rules, implementation details, and design history distinguishable
VI. Maintain and distribute the project
    A. Set up sources, edit documentation, and verify changes (contributing)
    B. Prepare artifacts and test published composition (release)
