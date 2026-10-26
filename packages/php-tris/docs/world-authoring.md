# World authoring

The package world declares its PHP bootstrap, browser module, game resources, and startup binding. Tiled data supplies authored presentation and challenge configuration while the package manifest controls which files are available to a self-contained browser export.

Keep paths package-relative so the same world works in the repository preview and packed distribution. After changing resources or modules, refresh integrity metadata with the repository manifest generator.

The public component classes are suitable for object factories and diagnostics; executable entry scripts remain documented here because they are lifecycle modules rather than reusable PHP types.
