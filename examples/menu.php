<?php
use PhpWasmEngine\Engine;
use PhpWasmEngine\Render\BitmapFont;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Ui\Menu;
use PhpWasmEngine\Ui\PauseMenu;

/**
 * Build shared pause settings for both the platformer and streaming-world demos.
 *
 * @param Engine $engine Active engine whose input is captured by the menu.
 * @param Camera $camera Camera receiving live zoom changes.
 * @param string $scene Scene name displayed as read-only output.
 * @return PauseMenu Adapter owned by the demo's render, restore, and cleanup callbacks.
 */
function createDemoMenu(Engine $engine, Camera $camera, string $scene): PauseMenu
{
	$settings = (object)['name' => 'Player', 'scale' => 2, 'theme' => 'auto', 'hints' => true];
	$adapter = null;
	$items = [
		'name' => ['label' => 'Player name'
		, 'input' => 'string'
		, 'default' => 'Player'
			, 'subtext' => 'Type to edit. Changes apply immediately.'
			, 'get' => fn() => $settings->name
			, 'set' => fn($value) => $settings->name = $value
		]
		, 'zoom' => ['label' => 'Camera zoom', 'input' => 'number', 'default' => 1
			, 'min' => .5, 'max' => 3, 'step' => .25
			, 'subtext' => 'Left/right adjusts. Select to type a value.'
			, 'get' => fn() => $camera->zoom
			, 'set' => fn($value) => $camera->zoom = $value
		]
		, 'scale' => ['label' => 'Menu scale'
		, 'input' => 'number'
		, 'subType' => 'range'
			, 'min' => 1, 'max' => 3, 'step' => .25, 'default' => 2
			, 'subtext' => 'Adjust with left/right or drag the slider.'
			, 'get' => fn() => $settings->scale
			, 'set' => function($value) use ($settings, &$adapter) {
				$settings->scale = $value;
				$adapter->renderer->style['scale'] = $value;
			}
		]
		, 'theme' => ['label' => 'Button hints'
		, 'input' => 'select'
		, 'default' => 'auto'
			, 'options' => ['auto', 'keyboard', 'xbox', 'playstation', 'generic']
			, 'subtext' => 'Auto follows the last keyboard or controller input.'
			, 'get' => fn() => $settings->theme
			, 'set' => function($value) use ($settings, &$adapter) {
				$settings->theme = $adapter->theme = $value;
			}
		]
		, 'hints' => ['label' => 'Control hints'
		, 'input' => 'boolean'
		, 'default' => true
			, 'subtext' => 'Show keyboard or gamepad glyphs below the menu.'
			, 'get' => fn() => $settings->hints
			, 'set' => function($value) use ($settings, &$adapter) {
				$settings->hints = $adapter->renderer->showHints = $value;
			}
		]
		, 'scene' => ['label' => 'Scene', 'input' => 'output', 'value' => $scene]
		, 'time' => ['label' => 'Game time', 'input' => 'output'
			, 'get' => fn() => sprintf('%.2f s', $engine->time)
			, 'subtext' => 'Simulation time stays fixed while the menu is open.'
		]
		, 'locked' => ['label' => 'Unavailable item', 'disabled' => true
			, 'subtext' => 'Disabled rows remain readable but cannot activate.'
		]
	];

	$reset = function(Menu $menu) use ($settings, $camera, &$adapter) {
		$settings->name = 'Player';
		$settings->scale = $adapter->renderer->style['scale'] = 2;
		$settings->theme = $adapter->theme = 'auto';
		$settings->hints = $adapter->renderer->showHints = true;
		$camera->zoom = 1;
		$menu->back();
	};
	$items['defaults'] = ['label' => 'Reset settings'
	, 'children' => [
		'no' => ['label' => 'No, keep settings', 'callback' => fn(Menu $menu) => $menu->back()]
		, 'yes' => ['label' => 'Yes, restore defaults', 'callback' => $reset]
	]];

	$menu = new Menu(
		['resume' => ['label' => 'Resume'
		, 'callback' => fn(Menu $menu) => $menu->close()
			, 'subtext' => 'Return to the game.'
		]
		, 'settings' => ['label' => 'Settings'
			, 'children' => $items
			, 'subtext' =>
			'Live text, numbers, sliders, choices, and boolean inputs.'
		]
		, 'name' => ['label' =>
			'Player'
			, 'input' => 'output', 'get' => fn() => $settings->name
		]
		]
		, 'Paused'
	);
	$font = new BitmapFont(new Loader, __DIR__.'/assets/font.tsj', [__DIR__.'/assets/buttons.tsj']);
	$adapter = new PauseMenu($engine, $menu, $font);
	return $adapter;
}

/**
 * Expose demo state for browser regressions and the browser's inspector.
 *
 * @param PauseMenu $pauseMenu Demo menu adapter after its latest render.
 * @param Engine $engine Engine supplying paused simulation time.
 * @return string JSON snapshot of page state, values, hit regions, and active glyph theme.
 */
function demoMenuState(PauseMenu $pauseMenu, Engine $engine): string
{
	$menu = $pauseMenu->menu;
	$values = [];

	foreach($menu->rows() as $id => $row)
	{
		$values[$id] = $row['value'] ?? null;
	}

	return json_encode(
		['open' => $menu->isOpen
		, 'title' => $menu->title
		, 'selected' => $menu->selected
			, 'editing' => $menu->editor !== null
			, 'composition' => $menu->editor?->composition
			, 'values' => $values, 'time' => $engine->time
			, 'theme' => $pauseMenu->renderer->font
			->theme
			, 'regions' => $pauseMenu->renderer->regions
		]
	);
}
