# Bitmap fonts and menus

Press P, Escape, or Start in a live demo to open its pause menu. Settings
contains text, numeric, slider, selection, boolean, and read-only rows. Valid
changes apply immediately. The simulation stops while menu input and rendering
continue.

## Demo browser controls

**Level** and **Movement** open selection pages. Selecting an item restarts the
level with the chosen profile; changing levels retains that profile. The centered
Run PHP button hides once the game starts and returns if a download fails. A
restart refreshes PHP and reuses the WASM instance when its version and extensions
match. Scripts can call `bootEngine({scene, profile})` and `stopEngine()`; stopping
also cancels an in-progress download.

**Mute audio** and **Fullscreen** appear in the pause menu and as two icon buttons
at the bottom-right of the game. The buttons have keyboard focus, accessible
labels, and pressed states. Fullscreen includes only the game frame, canvas, and
corner controls. The page text stays outside it. Menu text editing
also stays inside the fullscreen frame, including the SDL textarea fallback.

Fullscreen state follows the browser, including exits through Escape. If a
browser rejects a gamepad-triggered request, click the corner fullscreen button.
Browsers without fullscreen support hide both fullscreen controls.

Mute uses a master gain on the pinned runtime's SDL audio output. Channel/music
volumes, fades, and playback positions are preserved, and newly opened output
devices inherit the setting. The preference survives level changes and page
reloads when local storage is available. The sample levels currently have no
soundtrack; the audio regression plays a generated tone through the native mixer
and checks the signal after this gain.

The PHP menu calls the demo host through `vrzno_env('demoControls')`. The
JavaScript host supplies browser fullscreen, persistent mute, and deferred
restart actions. Level restarts wait until the current PHP callback returns,
then stop its frame loop, close streaming requests, and refresh PHP. These host
controls live in `docs/controls.mjs`; the reusable `Menu` API does not require
a browser host.

## Define a menu

```php
use PhpWasmEngine\Ui\Menu;

$settings = (object)['name' => 'Player', 'hints' => true];
$menu = new Menu([
    'resume' => [
        'label' => 'Resume',
        'callback' => fn(Menu $menu) => $menu->close()
    ],
    'settings' => [
        'label' => 'Settings',
        'children' => [
            'name' => [
                'label' => 'Name', 'input' => 'string', 'default' => 'Player',
                'get' => fn() => $settings->name,
                'set' => fn($value) => $settings->name = $value
            ],
            'hints' => [
                'label' => 'Hints', 'input' => 'boolean', 'default' => true,
                'get' => fn() => $settings->hints,
                'set' => fn($value) => $settings->hints = $value
            ],
            'speed' => [
                'label' => 'Speed', 'input' => 'number', 'subType' => 'range',
                'min' => .5, 'max' => 2, 'step' => .25, 'value' => 1
            ],
            'difficulty' => [
                'label' => 'Difficulty', 'input' => 'select',
                'options' => ['Easy', 'Normal', 'Hard'], 'value' => 'Normal'
            ],
            'score' => ['label' => 'Score', 'input' => 'output', 'value' => 100]
        ]
    ]
], 'Paused');
```

IDs are stable string keys, independent of labels. IDs beginning with `@` are
reserved for generated Back/Retry rows. Labels default to their IDs. `subtext`
supplies the selected row's description. `hidden` and `disabled` accept booleans
or callbacks receiving the menu. Disabled rows remain readable but cannot
activate, adjust, or reset.

`get()` supplies a live value. `set($value)` runs once per accepted change,
never merely because a page redraws. `value` stores local state when there is
no getter. Numbers snap to `step` relative to `min` (zero without a minimum)
and clamp to `min`/`max`. Empty selection lists are disabled. Selection options
are scalar values compared strictly and displayed as strings.

Text is single-line UTF-8; pasted line breaks become spaces. Cursor and selection
positions use Unicode codepoints. IME preedit stays separate from committed text.
Enter, Escape, or B leaves a field and keeps valid live changes. Leaving an
incomplete number retains its last valid value. The bitmap font does not shape
complex scripts: unknown characters render its fallback without changing the
stored value.

Use `default` for per-item reset, or `revert(Menu $menu)` for application-specific
reset behavior. A revert callback can return a value or mutate state read by
`get()`. Persistence belongs in application callbacks.

## Attach to the engine

```php
use PhpWasmEngine\Render\BitmapFont;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Ui\PauseMenu;

$font = new BitmapFont(new Loader, '/assets/font.tsj', ['/assets/buttons.tsj']);
$pauseMenu = new PauseMenu($engine, $menu, $font);

// After the world render and before Device::present():
$pauseMenu->render();

// In the context restoration callback:
$pauseMenu->restore();

// In the engine cleanup callback, before closing the device:
$pauseMenu->close();
```

Use one `PauseMenu` adapter per engine. It owns the optional input handler and UI
graphics, captures opening/closing frames, and clears buffered gameplay actions.
Release held controls before returning to gameplay. Pages and button themes
share one font atlas; changing a theme downloads no extra runtime or image.

`Menu` also exposes `open()`, `close()`, `select($id)`, `move($distance, $wrap)`,
`activate()`, `adjust($direction)`, `back()`, `revert()`, `setValue($id, $value)`,
`rows()`, and `refresh()`. These commands work without SDL. `MenuInput::update()`
accepts named buttons, elapsed seconds, and ordered UI events. `MenuRenderer`
can draw menus without pausing anything.

Custom integrations use
`Engine::setInputHandler(callable(Engine, float): bool)`. The callback runs before
pause handling and simulation; return true to capture the frame. Without a
handler, the engine retains its original gameplay input and pause behavior.

`children` accepts an item array or a loader callback receiving the menu. Return
an array when ready, or `null` while pending. Only the active page polls its
loader. Exceptions produce Back/Retry controls. Hosts manage asynchronous
requests separately; PHP requires no JavaScript Promise type.

## Controls and appearance

| Input | Command |
| --- | --- |
| Arrows/WASD, D-pad, left stick | Move or adjust |
| Enter, A/Cross | Activate or finish editing |
| Space | Activate outside a field; type a space while editing |
| Escape, B/Circle | Leave field, parent page, then menu |
| X/Ctrl | Back outside a field; normal editing inside a field |
| P, Start/Options | Open/close; P types normally inside a field |
| Page Up/Down, shoulders | Move seven rows, clamping at the ends |
| Home/End | First/last row, or field boundary while editing |
| Hold keyboard Z / pad Y or Triangle for 500 ms, middle-click | Reset outside text editing |
| Mouse, wheel | Select rows, scroll, drag sliders or text selections |

Navigation repeats after 500 ms, every 250 ms initially, then every 50 ms after
1.5 seconds. Editing supports Shift-selection, Ctrl/Cmd+A, deletion, and
SDL/browser clipboard transport. Clipboard permissions and on-screen keyboard
activation follow the runtime's browser behavior.

`$pauseMenu->theme` defaults to `auto`. Overrides are `keyboard`, `xbox`,
`playstation`, and `generic` (numbered generic/10-button pads). Auto mode follows
fresh keyboard/pointer or controller activity. Unknown controller names use
generic glyphs. This changes hints, not SDL's button mapping.

The font accepts moving-boxes-2's characters, including `⓿`, `❶` through `❿`, `⓫`, `✚`,
`←→↑↓`, `⬲`, `⟴`, and stick-direction arrows. For example,
`⓿ Select   ❶ Back   Hold ❸ Reset` becomes themed keycaps or buttons. These
glyphs retain their original colors when surrounding text is tinted.

| Character | Keyboard | Xbox | PlayStation |
| --- | --- | --- | --- |
| `⓿` | Space | A | Cross |
| `❶` | Ctrl | B | Circle |
| `❸` | Z | Y | Triangle |
| `❾` | P | Menu | Options |
| `✚` | Arrow keys | D-pad | D-pad |
| `⬲` | WASD | Left stick | Left stick |
| `⟴` | Numpad directions | Right stick | Right stick |

Generic pads use numbered buttons. Arrow variants highlight individual directions.
The complete character-to-artwork mapping is in `art/controllers/manifest.json`.

```php
$pauseMenu->renderer->style['selected'] = [.4, 1, 1, 1];
$pauseMenu->renderer->style['panel'] = [.05, .05, .1, .9];
$pauseMenu->renderer->style['scale'] = 2;
$pauseMenu->renderer->showHints = true;
```

Style also provides `width`, `padding`, `foreground`, `disabled`, `highlight`,
`selection`, and `dimmer`. Layout uses drawing-buffer dimensions and screen
coordinates independent of the world camera. Source-pixel cropping clips glyphs
without changing GL scissor state. Render UI in the normal full-screen viewport.

## Annotate a tileset

Character identity and metrics belong in Tiled custom properties. Tiles can
move, use different column counts, or live in image collections without PHP edits.

| Location | Property | Meaning |
| --- | --- | --- |
| Font tileset | `font.lineHeight` | Line advance in source pixels |
| Font tileset | `font.spaceAdvance` | Space width without a drawn glyph |
| Font tileset | `font.fallback` | Annotated replacement character, default `?` |
| Font tileset | `font.caseFold` | Resolve ASCII lowercase through uppercase glyphs |
| Tile | `glyph.char` | One Unicode character identifying this glyph |
| Tile | `glyph.aliases` | Additional characters sharing this image |
| Tile | `glyph.theme` | Optional theme; omit for ordinary text |
| Tile | `glyph.advance` | Horizontal cursor advance |
| Tile | `glyph.width`, `glyph.height` | Visible source crop dimensions |
| Tile | `glyph.cropX`, `glyph.cropY` | Crop origin inside the tile |
| Tile | `glyph.offsetX`, `glyph.offsetY` | Placement relative to the text origin |
| Tile | `glyph.tint` | False preserves colored keycap/button artwork |

Unannotated tiles are ignored. Duplicate characters within a theme, invalid
metrics/crops, and missing fallback glyphs are rejected. `BitmapFont::layout()`
returns sprites, dimensions, and caret positions; `measure()` returns the same
dimensions. Both work without SDL or GPU allocation.

The font comes from Tigl, annotated using moving-boxes-2's character mapping
and CSS spacing. Button artwork and its regeneration recipe are recorded in
[`art/controllers/README.md`](https://github.com/seanmorris/php-wasm-engine/blob/feat/bitmap-menus/art/controllers/README.md).
