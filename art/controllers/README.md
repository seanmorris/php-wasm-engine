# Imported menu glyphs

The SVGs and `manifest.json` come from moving-boxes-2 at commit
`1d2e4d26f578a702e8215a84cc8961a189e44d4e`, under `app/assets/ui/` and
`app/ui/button-font-{keyboard,xbox,playstation,generic}.css`.

The manifest converts the CSS's UTF-16 character selectors into Unicode
characters, including both aliases for right-stick directions. The first
keyboard D-pad mapping uses arrow keys. The reference's missing
`playstation-dpad.svg` URL resolves to its existing `playstation-button-dpad.svg`.

Run `node scripts/build-menu-icons.mjs` from the repository root to rasterize
these SVGs into `examples/assets/buttons.png` and write its annotated TSJ.
Playwright and Chromium are build-time tools; the game loads only the PNG/TSJ.
The original artwork is preserved here for attribution and regeneration.
Imported SVG line endings and trailing whitespace are normalized.

The regular font PNG was copied from Tigl's `app/assets/font.png` at commit
`b1d91e52adec6a3a6687a2d30bea7bd22945e412`. Its TSJ was copied and annotated
using moving-boxes-2's `CharacterString.js` and `hud-character.css`. The
unreachable duplicate quote mapping in the reference leaves tile 60
unannotated. No engine code assumes a character's row or column.
