# Tiled-Phonics

Tiled **1.12.2** integration for Phonics package URLs and world configuration. Install the packed/published `@seanmorris/phonics` and `@seanmorris/tiled-phonics` packages together, exposing the `tiled-phonics` command on Tiled's PATH (for example with a global npm install). Copy `extension/phonics.mjs` into Tiled's extensions directory, available through **Edit → Preferences → Plugins → Open**. Restart Tiled. Open a saved project and a world.

- **File → Add Package URL** imports a versioned `phonics.json`, verifies preview integrity, caches dependency metadata, templates and tilesets in `.phonics-cache`, registers classes and defaults, and saves `phonics.import.<alias>` on the world.
- **Phonics World Settings** selects the game, starting map and additional modules from the imported pallets. Select modules used by any later streamed map as well as the starting map.
- **Place Phonics Entity** inserts an exported factory on the current object layer. Move the object and edit properties using the normal editor tools. Open cached tilesets or templates to use package previews.
- **Validate and Preview** saves assets, validates imports, references and typed properties, and opens the generic host with `?world=...`. Supply URLs served by your static server; the extension does not generate game bootstrap code or publish local files.

Downloaded gameplay PHP and JavaScript are never executed by the editor importer. URL and `@alias/export` values remain strings. Actual local resources use file properties. Preview cache files preserve original URLs in supported `phonics.source`, `phonics.source.image`, `phonics.source.source` and `phonics.source.template` custom properties; template provenance is stored on its object so Tiled retains it. Worlds use only supported custom properties, not additional JSON keys.

The helper CLI exposes `tiled-phonics import MANIFEST_URL CACHE_DIRECTORY`, `tiled-phonics validate WORLD_FILE CACHE_DIRECTORY`, and `tiled-phonics open PREVIEW_URL`. Node 22 or newer is required. Package requirements use the runtime's exact, caret and minimum version grammar. See the [root guide](https://github.com/seanmorris/php-wasm-engine/blob/HEAD/README.md) for the package contract and the post-publication jsDelivr smoke test.
