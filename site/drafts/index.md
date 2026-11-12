# Phonics — Harvard drafting outline

Reader: someone deciding what Phonics does and which guide to read first.
Outcome: understand the executable-application model and choose a tutorial or reference.

I. Describe what the browser starts
    A. Package game code as an executable PHP archive
        1. PHP startup code selects the game and optional content
        2. The PHP/SDL runtime remains separate; assets may be remote
    B. Show the browser start/dispose example
II. Offer routes by reader task
    A. Create a first game with the quickstart
    B. Play and inspect independent examples
    C. Author content with optional Tiled integration
    D. Build a package or reusable module
III. Explain responsibility boundaries
    A. Phonics supplies scheduling, assets, input, collision, and rendering
    B. Games supply movement, cameras, rules, and presentation
    C. Independent packages can share framework services
