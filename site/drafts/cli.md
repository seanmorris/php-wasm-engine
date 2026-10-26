# Game-package CLI — drafting outline

I. Install the command in an independent project
    A. Use the packed Phonics npm artifact before publication
    B. Discover commands and options through help
II. Create and preview a game
    A. Scaffold a package-owned player and movement system
    B. Edit the Tiled world and map
    C. Supply the PHP/SDL runtime explicitly
III. Generate a manifest for an existing project
    A. Prompt for metadata and detect conventional runtime files
    B. Preview and hash before creating phonics.json
    C. Support noninteractive defaults and preserve existing manifests
    D. Explain declarations that require author review
IV. Maintain the package contract
    A. Declare files and exports in phonics.json
    B. Refresh hashes and validate the package graph
    C. Validate world references without executing code
V. Build and release
    A. Copy declared files into an isolated output
    B. Exclude scratch files and development scripts
    C. Pack and publish as separate release operations
