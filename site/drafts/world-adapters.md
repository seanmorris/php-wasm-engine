# Load content through adapters — drafting outline

Reader: a game author whose content comes from archives or multiple formats.
Outcome: connect a source, its maps, and the systems that run while a map is active.

I. Introduce the four terms through a Doom example
    A. A source names a collection of content; its adapter reads the format.
    B. A scope identifies part of that content; a binding chooses its active systems.
        1. Name episode, the WAD adapter, E1M1, and the episode systems.
        2. Link back to ordinary world loading and session ownership.
II. Connect the example world
    A. Preserve the complete JSON example.
        1. Imports select reusable code.
        2. Sources name resources and adapters.
        3. Bindings, transitions, and start select behavior and initial content.
    B. Explain the order in which a reader should inspect its fields.
        1. Follow episode from source to binding and start.
        2. Explain that transition behavior belongs to the game.
III. Configure game-owned values
    A. Show where PHP and browser modules read init.
    B. Explain Doom's optional launch settings.
        1. startMap overrides the initial scope selection for Doom.
        2. Cheats apply after the player is created.
IV. Select resources and display settings
    A. Separate bundled application code from licensed resource URLs.
    B. Explain an ordered resource slot.
        1. multiple expands at the slot's position.
        2. Positional validation distinguishes base and add-on files.
            a. Doom requires an IWAD first.
            b. Later archives can replace lumps and whole maps.
    C. Preserve Doom's resolution behavior in a short dedicated subsection.
        1. Native resolution uses the output dimensions.
        2. Classic resolution scales the scene, preserving full-resolution overlays.

Editorial decisions:
- Define source, adapter, scope, and binding before presenting the schema.
- Keep game-specific options labeled as Doom behavior, not framework guarantees.
- Keep the existing complete-world and playable-example links.
