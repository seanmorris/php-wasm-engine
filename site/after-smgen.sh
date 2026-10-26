#!/usr/bin/env bash

set -euo pipefail

highlight_source=../node_modules/@highlightjs/cdn-assets/highlight.min.js
highlight_destination="${OUTPUT_DIR}/vendor/highlight.js"

mkdir -p "${highlight_destination}"
cp "${highlight_source}" "${highlight_destination}/highlight.min.js"
cp ../node_modules/@highlightjs/cdn-assets/LICENSE "${highlight_destination}/LICENSE"

npx --no-install smgen-assistant build \
	--pages "${PAGES_DIR}" \
	--output "${OUTPUT_DIR}" \
	--base-url "${BASE_URL}"
