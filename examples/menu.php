<?php
use PhpWasmEngine\Engine;
use PhpWasmEngine\Render\BitmapFont;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Ui\Menu;
use PhpWasmEngine\Ui\PauseMenu;

/**
 * Build pause settings and browser controls for the platformer and streaming-world demos.
 *
 * @param Engine $engine Active engine whose input is captured by the menu.
 * @param Camera $camera Camera receiving live zoom changes.
 * @param DemoGame $game Persistent session receiving level and movement selections.
 * @return PauseMenu Adapter owned by the demo's render, restore, and cleanup callbacks.
 */
function createDemoMenu(Engine $engine, Camera $camera, DemoGame $game): PauseMenu
{
	$settings = (object)['name' => 'Player', 'scale' => 2, 'theme' => 'auto', 'hints' => true];
	$adapter = null;
	$items = [
		'name' => [
			'label' => 'Player name'
			, 'input' => 'string'
			, 'default' => 'Player'
			, 'subtext' => 'Type to edit. Changes apply immediately.'
			, 'get' => fn() => $settings->name
			, 'set' => fn($value) => $settings->name = $value
		]
		, 'zoom' => [
			'label' => 'Camera zoom'
			, 'input' => 'number'
			, 'default' => 1
			, 'min' => .5
			, 'max' => 3
			, 'step' => .25
			, 'subtext' => 'Left/right adjusts. Select to type a value.'
			, 'get' => fn() => $camera->zoom
			, 'set' => fn($value) => $camera->zoom = $value
		]
		, 'scale' => [
			'label' => 'Menu scale'
			, 'input' => 'number'
			, 'subType' => 'range'
			, 'min' => 1
			, 'max' => 3
			, 'step' => .25
			, 'default' => 2
			, 'subtext' => 'Adjust with left/right or drag the slider.'
			, 'get' => fn() => $settings->scale
			, 'set' => function($value) use ($settings, &$adapter) {
				$settings->scale = $value;
				$adapter->renderer->style['scale'] = $value;
			}
		]
		, 'theme' => [
			'label' => 'Button hints'
			, 'input' => 'select'
			, 'default' => 'auto'
			, 'options' => ['auto', 'keyboard', 'xbox', 'playstation', 'generic']
			, 'subtext' => 'Auto follows the last keyboard or controller input.'
			, 'get' => fn() => $settings->theme
			, 'set' => function($value) use ($settings, &$adapter) {
				$settings->theme = $adapter->theme = $value;
			}
		]
		, 'hints' => [
			'label' => 'Control hints'
			, 'input' => 'boolean'
			, 'default' => true
			, 'subtext' => 'Show keyboard or gamepad glyphs below the menu.'
			, 'get' => fn() => $settings->hints
			, 'set' => function($value) use ($settings, &$adapter) {
				$settings->hints = $adapter->renderer->showHints = $value;
			}
		]
		, 'debug' => [
			'label' => 'Debug overlay'
			, 'input' => 'boolean'
			, 'default' => false
			, 'subtext' => 'F9 toggles player, camera, collision, and rendering diagnostics.'
			, 'get' => fn() => $game->debug->enabled
			, 'set' => fn($value) => $game->debug->enabled = $value
		]
		, 'fps' => [
			'label' => 'FPS meter'
			, 'input' => 'boolean'
			, 'default' => true
			, 'subtext' => 'Browser frames per second and average frame interval in milliseconds.'
			, 'get' => fn() => $game->debug->showFps
			, 'set' => fn($value) => $game->debug->showFps = $value
		]
		, 'scene' => ['label' => 'Scene', 'input' => 'output', 'get' => fn() => $game->scene]
		, 'time' => [
			'label' => 'Game time'
			, 'input' => 'output'
			, 'get' => fn() => sprintf('%.2f s', $engine->time)
			, 'subtext' => 'Simulation time stays fixed while the menu is open.'
		]
		, 'locked' => [
			'label' => 'Unavailable item'
			, 'disabled' => true
			, 'subtext' => 'Disabled rows remain readable but cannot activate.'
		]
	];

	$reset = function(Menu $menu) use ($settings, $camera, $game, &$adapter) {
		$settings->name = 'Player';
		$settings->scale = $adapter->renderer->style['scale'] = 2;
		$settings->theme = $adapter->theme = 'auto';
		$settings->hints = $adapter->renderer->showHints = true;
		$game->debug->enabled = false;
		$game->debug->showFps = true;
		$camera->zoom = 1;
		$menu->back();
	};

	$items['defaults'] = [
		'label' => 'Reset settings'
		, 'children' => [
			'no' => ['label' => 'No, keep settings', 'callback' => fn(Menu $menu) => $menu->back()]
			, 'yes' => ['label' => 'Yes, restore defaults', 'callback' => $reset]
		]
	];

	$game->settings = $items;

	$host = vrzno_env('demoControls');
	$levels = [];
	$profiles = [];

	$levelNames = [
		'platformer' => 'Platformer'
		, 'world' => 'Streaming world'
		, 'loop' => 'Loop-de-loop'
		, 'breakables' => 'Breakable blocks'
		, 'infinite' => 'Infinite map'
		, 'isometric' => 'Isometric map'
	];

	$profileNames = [
		'runner' => 'Runner: dash'
		, 'glider' => 'Glider: climb'
		, 'flyer' => 'Flyer: flight'
	];

	foreach($levelNames as $id => $label)
	{
		$levels[$id] = [
			'label' => $label
			, 'input' => 'output'
			, 'get' => fn() => $game->pending?->id === $id ? 'Loading' : ($game->scene === $id ? 'Current' : '')
			, 'callback' => fn() => $game->selectScene($id)
		];
	}

	$levels['load-error'] = [
		'label' => 'Level download failed'
		, 'input' => 'output'
		, 'hidden' => fn() => $game->error === ''
		, 'subtext' => 'Select the level again to retry, or choose another level.'
	];

	$levels['cancel-load'] = [
		'label' => 'Cancel level change'
		, 'hidden' => fn() => $game->pending === null && $game->error === ''
		, 'callback' => fn() => $game->cancelLoad()
	];

	foreach($profileNames as $id => $label)
	{
		$profiles[$id] = [
			'label' => $label
			, 'input' => 'output'
			, 'get' => fn() => $host->profile === $id ? 'Current' : ''
			, 'callback' => fn() => $game->selectProfile($id)
		];
	}

	$menu = new Menu(
		[
			'resume' => [
				'label' => 'Resume'
				, 'callback' => fn(Menu $menu) => $menu->close()
				, 'subtext' => 'Return to the game.'
			]
			, 'settings' => [
				'label' => 'Settings'
				, 'children' => $items
				, 'subtext' => 'Live text, numbers, sliders, choices, and boolean inputs.'
			]
			, 'level' => [
				'label' => 'Level'
				, 'input' => 'output'
				, 'get' => fn() => $levelNames[$game->scene] ?? 'Loading'
				, 'children' => $levels
				, 'subtext' => 'Change levels while keeping game time and settings.'
			]
			, 'movement' => [
				'label' => 'Movement'
				, 'input' => 'output'
				, 'get' => fn() => $profileNames[$host->profile]
				, 'children' => $profiles
				, 'subtext' => 'Change the current player without restarting the level.'
			]
			, 'mute' => [
				'label' => 'Mute audio'
				, 'input' => 'boolean'
				, 'get' => fn() => $host->muted
				, 'set' => fn($value) => $host->setMuted($value)
				, 'subtext' => 'Shared with the speaker button in the lower-right corner.'
			]
			, 'fullscreen' => [
				'label' => 'Fullscreen'
				, 'input' => 'boolean'
				, 'get' => fn() => $host->fullscreen
				, 'set' => fn($value) => $host->setFullscreen($value)
				, 'hidden' => fn() => !$host->fullscreenAvailable
				, 'disabled' => fn() => $host->fullscreenBusy
				, 'subtext' => 'Some browsers require clicking the corner fullscreen button.'
			]
			, 'fullscreen-error' => [
				'label' => 'Fullscreen request denied'
				, 'input' => 'output'
				, 'hidden' => fn() => $host->fullscreenError === ''
				, 'subtext' => 'Try the corner fullscreen button. The game can stay in this view.'
			]
			, 'name' => [
				'label' => 'Player'
				, 'input' => 'output'
				, 'get' => fn() => $settings->name
			]
			, 'main-menu' => [
				'label' => 'Main menu'
				, 'hidden' => fn() => $game->startup === null
				, 'callback' => fn() => $game->startup->showMenu()
				, 'subtext' => 'Keep this level available to resume.'
			]
		]
		, 'Paused'
	);

	$font = new BitmapFont(new Loader, __DIR__ . '/assets/font.tsj', [__DIR__ . '/assets/buttons.tsj']);
	$adapter = new PauseMenu($engine, $menu, $font);

	return $adapter;
}

/**
 * Expose demo state for browser regressions and the browser's inspector.
 *
 * @param PauseMenu $pauseMenu Demo menu adapter after its latest render.
 * @param Engine $engine Engine supplying paused simulation time.
 * @param Menu|null $active Optional main menu instead of the adapter's pause menu.
 * @return string JSON snapshot of page state, values, hit regions, and active glyph theme.
 */
function demoMenuState(PauseMenu $pauseMenu, Engine $engine, ?Menu $active = null): string
{
	$menu = $active ?? $pauseMenu->menu;
	$values = [];

	foreach($menu->rows() as $id => $row)
	{
		$values[$id] = $row['value'] ?? null;
	}

	return json_encode([
		'open' => $menu->isOpen
		, 'title' => $menu->title
		, 'selected' => $menu->selected
		, 'editing' => $menu->editor !== null
		, 'composition' => $menu->editor?->composition
		, 'values' => $values
		, 'time' => $engine->time
		, 'theme' => $pauseMenu->renderer->font->theme
		, 'regions' => $pauseMenu->renderer->regions
	]);
}
