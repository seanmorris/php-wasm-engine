# UI, menus, and cards

`Phonics\Ui` provides framework UI that games compose with their own content: declarative menus, timed cards, bitmap text, transient messages, world text actors, gameplay binding pages, and DOM-backed texture modals. State classes such as `Menu`, `CardSequence`, `TextBuffer`, and `TextMessages` have no SDL or GPU dependency and can be scripted in native tests. Renderers own their graphics, draw in screen space after the world, and rebuild after WebGL context loss through `restore()`. Games still provide menu content, HUD composition, screens, and accessibility labels.

## Bitmap fonts

`Phonics\Render\BitmapFont` assembles a font from annotated Tiled tilesets. The core package ships the font and controller symbols as `resources/font.tsj` and `resources/buttons.tsj`:

```php
use Phonics\Asset\Resource;
use Phonics\Render\BitmapFont;

$font = new BitmapFont($context->loader, Resource::framework('resources/font.tsj'), [
	Resource::framework('resources/buttons.tsj')
]);

$box = $font->measure('READY', scale: 2);
$text = $font->layout('Press ⓿ to start', scale: 2, maxWidth: 320, align: 'center');
```

`layout()` returns a sprite batch, its width and height, and glyph positions; `measure()` returns the same line boxes without sprites. Text is UTF-8 and wraps at `maxWidth`. The font does not shape complex scripts: an unknown character renders the font's fallback glyph without changing the stored text. `$font->theme` selects the symbol theme used before the generic glyphs.

Symbol characters become themed keycaps or buttons, and keep their own colors when the surrounding text is tinted:

| Character | Keyboard | Xbox | PlayStation |
| --- | --- | --- | --- |
| `⓿` | Space | A | Cross |
| `❶` | Ctrl | B | Circle |
| `❸` | Z | Y | Triangle |
| `❾` | P | Menu | Options |
| `✚` | Arrow keys | D-pad | D-pad |
| `⬲` | WASD | Left stick | Left stick |
| `⟴` | Numpad directions | Right stick | Right stick |

The complete character-to-artwork mapping is in [`art/controllers/manifest.json`](../art/controllers/manifest.json).

## Define a menu

`Menu` holds declarative pages, live bindings, and selection state:

```php
use Phonics\Ui\Menu;

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

IDs are stable string keys, independent of labels. IDs beginning with `@` are reserved for the generated Back and Retry rows. Labels default to their IDs. `input` is one of `string`, `number`, `select`, `boolean`, or `output`; rows without an input are actions, submenus, or [text pages](#text-pages). `subtext` describes the selected row. `hidden` and `disabled` accept booleans or callbacks receiving the menu. Disabled rows remain readable but cannot activate, adjust, or reset. `align` and `valueAlign` accept `left`, `center`, or `right`, and a submenu's `orientation` is `Menu::VERTICAL` or `Menu::HORIZONTAL`.

`get()` supplies a live value. `set($value)` runs once per accepted change, never merely because a page redraws. `value` stores local state when there is no getter. Numbers snap to `step` relative to `min` (zero without a minimum) and clamp to `min` and `max`. Empty selection lists are disabled. Use `default` for per-item reset, or a `revert(Menu $menu)` callback for application-specific reset behavior. Persistence belongs in application callbacks.

`children` accepts an item array or a loader callback receiving the menu. A loader returns an array when ready, or `null` while pending; only the active page polls it, and an exception produces Back and Retry rows with the message in `$menu->error`. Hosts manage asynchronous requests separately; PHP needs no JavaScript Promise type.

Text fields edit a single-line `TextBuffer`. Cursor and selection offsets are Unicode codepoints, as in SDL text events, and IME composition stays separate from committed text. Leaving a field keeps valid live changes; leaving an incomplete number retains its last valid value.

`open()`, `close()`, `select($id)`, `move($distance, $wrap)`, `activate()`, `adjust($direction)`, `back()`, `revert()`, `setValue($id, $value)`, `rows()`, and `refresh()` work without SDL, so tests and scripts can drive a menu directly. `press($id)` runs a control by its region ID, as an accessible button does: it selects and activates a visible row, or scrolls a text page for `@text-up` and `@text-down`.

## Text pages

A row with `text` opens a page of text to read, such as how to play, credits, or licences, instead of a page of rows:

```php
$menu = new Menu([
	'help' => [
		'label' => 'How to play',
		'text' => [
			['heading' => 'Fly'],
			'Tap, click, or press Space to flap.',
			['heading' => 'Credits'],
			['name' => 'Music', 'value' => 'Funktify by Mr. Spastic'],
			['name' => 'Code', 'value' => 'Apache-2.0']
		]
	]
], 'Title');
```

A block is a heading, a paragraph (a string or `['text' => ...]`, keeping explicit line breaks), a note (`['note' => ...]`, secondary text such as a licence), or a name and value pair, which may carry a `note` set under its value, or a name and a note alone, set where the value would stand. `TextPage` sets the blocks in one column for the font and width it is drawn at: words wrap, a word wider than the column is cut after a hyphen or slash where it can be, a blank line separates blocks except after a heading or between pairs, and the pairs form a table: every pair on the page shares one column of names, so tables under different headings line up, and when that column would take more than 45% of the width each value hangs under its name instead. A `text` row cannot also have `input` or `children`; a `TextPage` instance may be given instead of the blocks.

While a text page is open, `$menu->text` is its `TextPage` and its only row is the generated Back. `TextPage::$first` is the first line in view; `scroll($lines)`, `page($direction)`, and `scrollTo($line)` move it by whole lines and clamp it, and opening the page again starts from the top. `MenuInput` reads the page with the vertical keys: arrows, D-pad and stick move one line, Page Up/Down and shoulders a page, Home/End to either end, and each wheel event one line. Confirm and Back both return to the parent page.

`MenuRenderer` draws the page in the menu panel, up to the height the frame allows, with the Back row beneath. A page whose lines do not all fit gives up a column to a text-mode scrollbar: pixel arrows at each end, a track in the `highlight` color, and a thumb in the `foreground` color, never shorter than the bar is wide. Headings use the optional `heading` style color, names the optional `name` color, and notes the optional `note` color, falling back to `selected`, `disabled`, and three quarters of `foreground`. The renderer publishes `@text-up` and `@text-down` arrow regions, and `@text` (the lines) and `@text-track` regions marked `drag`: dragging the lines moves them with the pointer, a press on the track pages towards it, and the thumb drags in proportion. `RegionButtons` gives `drag` regions buttons that let the pointer through, so keyboards and screen readers keep them while drags reach the canvas.

A game that draws its own menu art can draw a text page itself: call `layout($font, $width, $visible, $gutter)` with the column width in font pixels at scale one and the width its scrollbar would take (the page is set narrower by that much only when its lines do not all fit, and `last()` is then above zero), draw `shown()` (lines of spans with a `kind` of `TextPage::HEADING`, `TEXT`, `NAME`, `VALUE` or `NOTE`, and an `x` in font pixels), place a thumb with `thumb($trackLength, $minimum)`, and publish regions of the same shape for `MenuInput`. `plain()` returns the page as plain text for screen readers.

## Attach a menu to the engine

`PauseMenu` connects a `Menu` to an `Engine` through `Engine::setInputHandler()`:

```php
use Phonics\Ui\PauseMenu;

$pauseMenu = new PauseMenu($engine, $menu, $font);

// After the world render and before Device::present():
$pauseMenu->render();

// In the context restoration callback:
$pauseMenu->restore();

// In the engine cleanup callback, before closing the device:
$pauseMenu->close();
```

Use one `PauseMenu` per engine. The input handler runs before pause handling and fixed simulation; while it returns true, the frame is captured and gameplay does not update. Opening or closing a capturing menu resets gameplay input, as described in [Logical input and sequences](input.md#timing-and-recovery). `$pauseMenu->theme` defaults to `auto`, which follows the latest keyboard, pointer, or controller activity; set it to a theme name to fix the hint artwork. Themes change hints, not SDL's button mapping.

Custom integrations combine the parts directly. `MenuInput::update($menu, $buttons, $dt, $events, $regions)` routes one UI frame from `SdlInput::uiFrame()` buttons and events plus the hit regions from the latest render. Rows that edit text or numbers, sliders included, publish regions marked `drag`: a game that mirrors regions as DOM buttons must let the pointer through those (as `RegionButtons` does) so presses and drags reach the canvas and `MenuInput`. `MenuRenderer` draws a menu without pausing anything, and `renderHint()` and `renderHints()` draw control hints over other surfaces.

| Input | Command |
| --- | --- |
| Arrows/WASD, D-pad, left stick | Move or adjust |
| Enter, keypad Enter, Space, Y; pad A | Activate |
| Escape, X, Ctrl; pad B | Leave a field, then a page, then the menu |
| Pause keys (P by default), Start | Open or close; Escape opens only while the menu is closed |
| Page Up/Down, shoulders | Move seven rows, clamping at the ends |
| Home/End | First or last row |
| Hold Z or pad Y for 500 ms | Reset the selected row outside text editing |
| Mouse | Select rows, press or drag a slider's track, and place the caret or drag a selection in a field (a slider row's value text is a field); middle-click resets a row |
| Wheel | Move one row per event along the page axis, or one line of a text page |

Keyboard shortcuts are suspended while a text field is editing, so typed characters reach the field. Navigation repeats after 500 ms, every 250 ms at first, then every 50 ms after 1.5 seconds.

## Gameplay binding pages

`BindingMenu` builds Controls pages for a `Phonics\Input\Bindings` profile. Pass the profile, the named action rows, and game-owned `save` and `reset` callbacks; optional callbacks reject reserved sources, add device-specific settings, and describe persistence state. `item()` returns the root row to place in a menu. Call `update($menu, $engine->input->actions, $focused)` before ordinary menu input: while `capturing()` is true, it waits for neutral input and then consumes the next fresh physical input as a binding.

```php
use Phonics\Ui\BindingMenu;
use Phonics\Ui\Menu;

// $profile is the Bindings profile installed with $engine->input->useBindings().
$bindings = new BindingMenu(
	$profile
	, [
		'left' => [Actions::MOVE_X, -1, 'Move left']
		, 'right' => [Actions::MOVE_X, 1, 'Move right']
		, 'fire' => [Actions::FIRE, 1, 'Fire']
	]
	, $settings->save(...)
	, $settings->reset(...)
	, Actions::allowed(...)
	, status: static fn(): string => $settings->sessionOnly ? 'Changes apply to this session' : ''
);

$menu = new Menu(['controls' => $bindings->item('Controls')], 'Paused');

// Each UI frame, before ordinary menu navigation sees the same input:
$captured = $bindings->update($menu, $engine->input->actions, $focused);
```

The rows name an action code, a signed direction, and a label. `allowed` keeps reserved sources such as Escape and Start out of gameplay rows, and `status` describes session-only persistence. [Named action profiles](input.md#named-action-profiles) shows how the profile itself is built.

## Cards

A `Card` describes one timed screen: an ID, title, text, `duration` (null waits for activation), `skipAfter`, fade times, whether it is skippable, an optional polled `ready` gate, and `onEnter`/`onLeave` hooks. `CardSequence` visits cards in order without a renderer:

```php
use Phonics\Ui\Card;
use Phonics\Ui\CardRenderer;
use Phonics\Ui\CardSequence;

$cards = new CardSequence([
	new Card('studio', 'PHONICS', duration: 2),
	new Card('loading', 'Loading', 'Preparing assets', duration: null, ready: fn() => $assetsReady),
]);
$cards->start();

// Each UI frame:
$cards->update($elapsed, $confirmHeld);
$cardRenderer->render($cards, $width, $height, prompt: '⓿ Continue');
```

`update()` takes active UI seconds and the current held state of the confirm control. A press skips a card only when it is fresh, the card is ready and skippable, and `skipAfter` has elapsed; a press held across a card change does not skip the next card. Timers wait for the `ready` gate. `advance()` requests the same skip from scripts, `cancel()` stops without entering later cards, and `complete()` reports normal completion. `state` moves through `opening`, `waiting`, `shown`, and `closing` for each card, ending in `complete` or `cancelled`. `CardRenderer` draws the current card with its fade, an optional prompt, file progress, and an optional backdrop; pass a menu's atlas to its constructor to share the font texture.

## Text and messages

`TextMessages` is DOM-free state for a centered status line, a typewriter dialog with a speaker and side, a focus prompt, and a demo record/playback badge:

```php
$messages->showCenter('READY', [1, .9, .3, 1], 2.5);
$messages->showDialog(['Find the next panel.', '⓿ continues.'], 'GUIDE', 'left');
$messages->showFocus();
$messages->showDemo('play', 2.5);
```

`update($elapsed)` advances durations and dialog reveal using real UI time, including pause-menu frames. `setFocused(bool)` shows the standard prompt on blur and dismisses it shortly after focus returns. `TextRenderer::renderMessages()` draws these layers in screen space above gameplay.

`TextActors` promotes visible Tiled objects with class `TextActor` or `text-actor` into non-colliding ECS labels. Text comes from a `content` or `text` property, or from Tiled's native text payload; optional `color`, `scale`, and `align` properties apply, and labels inherit their layer's tint. `TextRenderer::renderActors()` draws them in the camera plane of map sprites, and `close()` removes only the entities created for that map. `TextRenderer` borrows the `MenuRenderer` atlas and owns only its own programs and buffers.

## DOM texture modals

`DomTextureModal` shows trusted, application-owned HTML rendered by the browser's DOM texture bridge over the finished scene. `show($title, $html)` opens it, `update($engine, $elapsed, $menu)` captures familiar menu controls while it is visible, `render()` draws it, and `close()` returns to the menu page beneath it. PHP-Noid, PHPTris, and Doom-Phonics use it for their PHP information panels:

```php
use Phonics\Ui\DomTextureModal;

$info = new DomTextureModal(vrzno_env('domTexture'), $menuRenderer);

// From a menu row's callback:
ob_start();
phpinfo();
$info->show('PHP Info', (string)ob_get_clean());

// In the engine input handler, where true keeps the frame captured:
if($info->isOpen()) return $info->update($engine, $elapsed, $menu);

// After the world render:
$info->render($width, $height);

// In the engine cleanup callback:
$info->close();
```

The second constructor argument is an optional `MenuRenderer` whose font draws the themed back hint; without it the bridge shows a plain text hint. `isOpen()` and `state()` expose the panel for diagnostics and browser tests.

See the [UI API](../src/Ui) for every class and member, and [Sane-Phonics menus and browser controls](../packages/sane-phonics/docs/menus.md) for a complete game menu built on these parts.
