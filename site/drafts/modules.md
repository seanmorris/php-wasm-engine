# Write a module — drafting outline

Reader: a PHP game author adding reusable behavior to an existing application.
Outcome: register a module, load it through a manifest, and release what it owns.

I. Explain the module's role
    A. A module adds behavior to the registry and scheduler shared by the game.
    B. The application still controls startup, stage changes, and readiness.
II. Register and order PHP systems
    A. A module file returns a callback receiving Package Context.
        1. Preserve the small clock/effects registration example.
        2. Explain delta time in seconds and explicit after dependencies.
    B. Register cleanup alongside the resources it releases.
        1. System names must be unique across the session.
        2. Context close removes systems and runs cleanup in reverse order.
III. Make the module loadable
    A. Add its PHP file and export to phonics.json.
    B. Reference the export from a game or world.
        1. A game export's modules field lists prerequisites.
        2. A Tiled world's phonics.modules list uses package aliases.
        3. Refresh integrity hashes after changes.
IV. Add entities or browser behavior when needed
    A. A factory translates a Tiled object into initial components.
        1. Register a namespaced class and describe it in the manifest.
        2. Keep component constructors and concrete entity factories distinct.
    B. A browser entry exports activate(context).
        1. Demonstrate an event listener with cleanup.
            a. Return a cleanup callback or use context.onClose.
            b. Use one cleanup route for each resource.
        2. Link to browser context and shared controls references.
V. Let the game coordinate loading
    A. Explain preloadStart for Tiled starting maps and intro screens.
    B. Keep readiness and the game loop with the game entry point.
        1. List modules for later maps before play starts.
        2. Link to composition fixtures for a complete example.

Editorial decisions:
- Add the missing manifest-to-module connection.
- Split the old combined entities/browser section into two tasks.
- Replace the vague warning about monolithic classes with concrete ownership guidance.
- Preserve existing heading anchors for deep links.
