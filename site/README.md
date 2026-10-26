# Phonics documentation site

SMGen renders the framework guide at the site root alongside the existing playable routes. Markdown source, PHP templates, declarative navigation, a documentation sidebar, and per-page contents lists produce static output. The build also emits a curated `llms.txt`, JSON-LD on the homepage, and a private in-browser documentation assistant. No game runtime loads while reading documentation.

## Build

Install Bash 4+, Git, PHP CLI with the YAML extension, Pandoc, and mikefarah/yq v4. On Debian/Ubuntu, `apt install php-cli php-yaml pandoc` supplies the PHP and Pandoc dependencies; install mikefarah/yq separately if your distribution supplies the unrelated Python `yq`.

From the repository root:

```sh
npm run build:site
npm run test:site
npm run test:site:browser # Requires Playwright Chromium or /usr/bin/chromium
npm run serve:docs
```

Open <http://localhost:9140/>. `serve:docs` rebuilds the complete site, including game code, assets, and runtime, on startup and serves the generated `index.html` directly. The local and published sites use the same static entry point. Use `npm run build:site` for documentation-only edits; it does not refresh playable demos. Neither command watches for subsequent edits.

`site/.smgen-version` pins `e0404765733b08fdb92f82e18de223c61d615e1d`, the verified `seanmorris/smgen` revision used by this site. The wrapper fetches that exact revision into ignored `site/.cache/smgen/`. `SMGEN_BIN` allows an explicit local generator override. To update, inspect `git ls-remote https://github.com/seanmorris/smgen.git HEAD`, update the pin, rebuild, and run the checks. The test's expected revision should change with an intentional update.

The root npm dependency pins `smgen-assistant` at `44f182245c476179c68e2b454785854863b1bc9d`. SMGen runs `site/after-smgen.sh` only after it has installed a complete render, and restores the previous render if the assistant build fails. The generated assistant, corpus, and `llms.txt` are published with `docs/dist/`; edit their source configuration and Markdown rather than generated files. The assistant checks WebGPU before offering compatible models. Questions and retrieved documentation stay in the visitor's browser.

## Content and drafting

- `drafts/site.md`: Harvard outline for the whole site.
- `drafts/*.md`: a separate Harvard outline for every page, used to draft its argument and supporting details.
- `pages/`: authored introductory and procedural content.
- `tools/prepare.mjs`: imports the current architecture, input, and performance documentation into ignored build-source staging. Edit the original files to change reference content.
- `tools/php-api.php` and `tools/api-reference.mjs`: reflect the supported PHP surface, parse the supported browser exports, enforce complete public documentation, and generate the programming reference during every site build.
- `.smgen-assistant.yaml`: selects the assistant models and retrieval limits; `after-smgen.sh` builds the browser assets and corpus.
- `.smgen.yaml` and page `llms` frontmatter: select and group links for the generated `llms.txt` index.
- `templates/page.php`: semantic shared shell, static navigation, and page contents.
- `static/site.css`: responsive presentation.

The Harvard outlines are drafting tools, not public numbering. API landing and subsystem outlines are authored here; class and browser-module outlines are generated in `site/.cache/api-drafts/` beside the staged reference. Output in `docs/dist/` is generated and ignored; never edit it by hand.

## Deployment

Publish all of `docs/dist/` as a static tree. The documentation homepage is at `/`, with Sane-Phonics at `/sane-phonics/`, PHP-Noid at `/php-noid/`, and the generic loader at `/preview/`. The guide does not require PHP on the production host.

For a prefixed deployment, build with the site's public URL prefix:

```sh
BASE_URL=/phonics npm run build:site
BASE_URL=/phonics npm run test:site
```

Publish the distribution under `/phonics/`. `OUTPUT_DIR` may override SMGen’s staging directory and `SITE_DESTINATION` its publication directory; both are relative to `site/`. By default, the renderer writes `.cache/rendered` and overlays those files into `../docs/dist`, preserving the game/runtime output. For test output elsewhere, set `SITE_OUTPUT` relative to the repository root. Rebuild without overrides to restore the default layout. No deployment or registry publication is performed by these commands.
