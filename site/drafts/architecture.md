# Architecture — drafting outline

Source: `docs/architecture.md`. Keep the overview and reference details on this page for now. Preserve existing section anchors when headings change. Introduce the purpose of each subsystem before its class names; use tables for choices and lifecycle stages.

I. Introduce the application model
    A. Identify the executable PHP archive as the entry point
        1. The application chooses its code, content, and optional browser modules
        2. Tiled content and networking remain optional
    B. Separate the responsibilities of PHP and the browser
        1. PHP owns simulation and rendering
        2. The browser supplies the runtime, canvas, and host services
II. Start and stop an application
    A. Fetch, verify, and execute the archive
        1. Describe the package declarations and reproducible archive
        2. Show the PHP bootstrap and optional composition service
    B. Select content and initialization data
        1. Explain the fields in a world document before its example
        2. Preserve application authority over code and networking
    C. Dispose a session when leaving the application
III. Resolve and release resources
    A. Resolve each relative URL from the document that declares it
    B. Distinguish resource identity, archive storage, and native paths
    C. Retain published identities in Tiled authoring caches
IV. Run the game loop
    A. Assign work to PHP, SDL, and browser services
    B. Poll input, advance fixed simulation, and render
    C. Reset timing and restore graphics after interruption
V. Organize entities and systems
    A. Explain entity IDs, object components, and queries
    B. Defer structural changes and own entity cleanup
    C. Schedule systems and register module cleanup
    D. Describe optional factories and short-lived effects
VI. Read maps and query geometry
    A. Load Tiled data and stream complete maps
    B. Cast against nearby collision geometry
    C. Keep game rules in game packages
VII. Draw the scene and interface
    A. Batch tiles, sprites, and unchanged text
    B. Use offscreen targets for post-processing
    C. Translate input into game actions and UI
    D. Share browser controls and accessible hit regions
VIII. Load, activate, and unload content
    A. Define a source, scope, and adapter before naming their classes
    B. Compare load, prepare, and activate operations
    C. Explain leases, scoped queries, and cleanup order
    D. Compare retain, snapshot, and reset policies
    E. Inspect Tiled content and attach nested components
    F. Compare optional tile entity grouping modes
IX. Connect multiplayer games
    A. Configure the lobby and transport in the application
    B. Verify code and negotiate content before simulation
    C. Explain the browser services and shared lobby components
    D. Describe the two-peer host-authoritative replication path
X. Preserve compatibility
    A. Keep namespace aliases and the browser launch contract
    B. Separate packaged game code from the host-controlled runtime
