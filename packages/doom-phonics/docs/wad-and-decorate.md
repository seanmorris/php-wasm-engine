# WAD and DECORATE support

The archive layer indexes lumps, namespaces, maps, textures, flats, palettes, sprites, sounds, music, and text definitions. Map loading supports Doom and Hexen-format geometry plus the sector, linedef, and thing metadata needed by ZDoom-family maps.

DECORATE parsing supplies actor inheritance, replacements, editor numbers, states, sprites, sounds, inventory definitions, projectiles, and supported action functions. Built-in adapters cover Doom actors while package or loose definitions can add Skulltag and ZDoom actors without modifying the engine.

Unsupported definitions remain visible in diagnostics with their editor number and source. Decorative particle fountains and mode-specific score objects can degrade to presentation-only or inert actors when their complete game mode is unavailable.

Map specials include doors, floors, ceilings, lifts, switches, teleports, scrollers, lights, damage, exits, and mutable textures. The renderer resolves missing upper and lower wall spans against adjacent sector heights.
