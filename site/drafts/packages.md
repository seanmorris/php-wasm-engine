# Package files and integrity checks — Harvard drafting outline

Reader: a game or module author preparing code and assets for loading or distribution.
Outcome: declare the right files and exports, build an archive, and refresh its hashes.

I. Explain what the manifest does
    A. Distinguish reusable package metadata from an executable PHAR
    B. Identify file lists, entry points, dependencies, and integrity
II. Choose the exported features
    A. Explain an export as a named thing another package can select
    B. Group common game/module/component exports before advanced integration points
III. Build an executable application
    A. Describe application.entry and included local dependencies
    B. Keep remote assets and the external PHP/SDL runtime distinct
    C. Link reusable package builds and release guidance
IV. Refresh and validate hashes after changes
    A. Show commands in the game directory
    B. Separate stale hashes from missing paths
V. Explain dependency activation and autoloading
    A. Verify bytes before executing modules
    B. Distinguish namespace ownership from registering an autoloader
    C. Explain conflicts and runtime lifetime without unexplained code-graph shorthand
