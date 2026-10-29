# Phonics documentation site

SMGen renders the framework guide and playable game manuals in one documentation tree. Markdown source, PHP templates, declarative navigation, a documentation sidebar, and per-page contents lists produce static output. The build also emits a curated `llms.txt`, JSON-LD on every page, breadcrumbs that follow the documentation menu, and a private in-browser documentation assistant. Game runtimes load only when a reader activates the poster on a game overview.

## Build

Install Bash 4+, Git, PHP CLI with the YAML extension, Pandoc, and mikefarah/yq v4. On Debian/Ubuntu, `apt install php-cli php-yaml pandoc` supplies the PHP and Pandoc dependencies; install mikefarah/yq separately if your distribution supplies the unrelated Python `yq`.

From the repository root:

```sh
npm run build:site
npm run test:site
npm run test:site:browser # Requires Playwright Chromium or /usr/bin/chromium
npm run serve:docs
```

Open <http://localhost:9140/>. `serve:docs` rebuilds the complete site, including game code, assets, and runtime, on startup and serves the generated `index.html` directly. The local and published sites use the same static entry point. Use `npm run build:site` for documentation-only edits; it does not refresh packaged game code. Neither command watches for subsequent edits.

`site/.smgen-version` pins `04af8b116317724863af544c023818eb8b67d135`, the verified `seanmorris/smgen` revision used by this site. The wrapper fetches that exact revision into ignored `site/.cache/smgen/`. `SMGEN_BIN` allows an explicit local generator override. To update, inspect `git ls-remote https://github.com/seanmorris/smgen.git HEAD`, update the pin, rebuild, and run the checks. The test's expected revision should change with an intentional update.

The root npm dependencies pin `smgen-assistant` at `289e2eaa35f3a1cd5698e7d485700f201e13d0e2` and `smgen-plugins` at `30c99f413f33333b2c17e8ee993ad2acfc04b516`. The latter supplies `smgen-jsdoc`, which is not published separately yet. SMGen runs `site/after-smgen.sh` only after it has installed a complete render, and restores the previous render if the assistant build fails. The generated assistant, corpus, and `llms.txt` are published with `docs/dist/`; edit their source configuration and Markdown rather than generated files. The assistant checks WebGPU before offering compatible models. Questions and retrieved documentation stay in the visitor's browser.

## Content and drafting

- `drafts/site.md`: Harvard outline for the whole site.
- `drafts/*.md`: a separate Harvard outline for every page, used to draft its argument and supporting details.
- `pages/`: authored introductory and procedural content.
- `tools/prepare.mjs`: copies `pages/` into ignored build-source staging, imports the reference docs listed in it from `docs/`, and imports every bundled game's chapters from `packages/<game>/docs/` under `games/<game>/`, adding a lazy playable embed to each game overview. Edit the original files to change reference or game content. It then generates the PHP and JavaScript references before running the two staging steps below.
- `tools/breadcrumbs.mjs`: adds `breadcrumbs` frontmatter to every staged page from the `documentation` menu hierarchy in `.smgen.yaml`. A page that sets its own `breadcrumbs` is left unchanged.
- `tools/structured-data.mjs`: adds a default TechArticle `jsonld` document to every staged page. A page that sets its own `jsonld`, such as the homepage, is left unchanged.
- `tools/php-api.php` and `tools/api-reference.mjs`: reflect the supported PHP surface, enforce complete public documentation, and generate the PHP programming reference during every site build.
- `../.smgen-jsdoc.yaml` and the pinned `smgen-jsdoc` CLI: statically analyze `browser/**/*.mjs` without executing it and generate the owned `api-js/` Markdown subtree. SMGen then includes those pages in navigation, search, `llms.txt`, JSON-LD, and the assistant corpus.
- `.smgen-assistant.yaml`: selects the assistant models and retrieval limits; `after-smgen.sh` builds the browser assets and corpus.
- `.smgen.yaml` and page `llms` frontmatter: select and group links for the generated `llms.txt` index.
- `templates/page.php`: semantic shared shell, static navigation, and page contents.
- `static/site.css`: responsive presentation.
- `static/nav-state.js`: a blocking head script, loaded through `SCRIPTS` in `.smgen.yaml`, that restores which navigation groups a visitor opened or closed before the page paints.

The Harvard outlines are drafting tools, not public numbering. PHP API landing, subsystem, and class outlines are generated in `site/.cache/api-drafts/` beside the staged reference. JavaScript API pages derive directly from JavaScript and JSDoc. Output in `docs/dist/` is generated and ignored; never edit it by hand.

## Deployment

Publish all of `docs/dist/` as a static tree. The documentation homepage is at `/`, game overviews and their lazy playable surfaces live under `/games/`, and the generic world loader remains at `/preview/`. The guide does not require PHP on the production host.

For a prefixed deployment, build with the site's public URL prefix:

```sh
BASE_URL=/phonics npm run build:site
BASE_URL=/phonics npm run test:site
```

Publish the distribution under `/phonics/`. `OUTPUT_DIR` may override SMGen’s staging directory and `SITE_DESTINATION` its publication directory; both are relative to `site/`. By default, the renderer writes `.cache/rendered` and overlays those files into `../docs/dist`, preserving the game/runtime output. For test output elsewhere, set `SITE_OUTPUT` relative to the repository root. Rebuild without overrides to restore the default layout. No deployment or registry publication is performed by these commands.
