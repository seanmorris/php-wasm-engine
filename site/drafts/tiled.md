# Author in Tiled — Harvard drafting outline

Reader: a game author with a running Phonics application who wants to edit its content.
Outcome: place and configure an object, save inherited defaults, and preview the result.

I. Choose the right starting point
    A. Open an existing example project
        1. Install its dependencies and enable its relative extension
        2. Open the project before maps so custom types appear
    B. Configure a standalone project
        1. Install the core and helper commands
        2. Install the extension and save a project/world
II. Select code already available to the game
    A. Import a manifest and give it a local alias
        1. Define pallet once as the editor's package alias
        2. Explain caching without executing gameplay
    B. Choose the starting map and modules
III. Place an entity and edit its data
    A. Use a factory or a concrete object template
    B. Expand nested component settings
        1. Explain components and presets with separate speed fields
        2. Distinguish Sane loader presets from direct component bindings
IV. Decide when content runs
    A. Define a scope as an owned portion of content
    B. Select systems and state on re-entry
        1. Retain, snapshot, reset
        2. Let game rules select maps
    C. Opt into tile entities only when needed
        1. Compare collection, placement, region
        2. Keep rendering and collision game-owned
V. Save inherited defaults and package files
    A. Save the project and portable class catalog
    B. Declare new files, refresh hashes, validate, and pack
VI. Preview the edited world
    A. Start with the single-origin package preview
    B. Identify application, world, and host URLs
    C. Describe other hosts and cross-origin requirements separately
        1. Browser CORS controls data access
        2. Host origin policy controls executable packages
