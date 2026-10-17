<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/autoload.php';
require_once __DIR__ . '/breakables.php';
require_once __DIR__ . '/DemoLevel.php';
require_once __DIR__ . '/DemoGame.php';
require_once __DIR__ . '/DemoDebugOverlay.php';
require_once __DIR__ . '/DemoPlayerHud.php';
require_once __DIR__ . '/DemoComboHud.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/cards.php';
require_once __DIR__ . '/DemoBackdrop.php';
require_once __DIR__ . '/DemoStartup.php';

use PhpWasmEngine\Engine;
use PhpWasmEngine\Gameplay\PlayerCombat;
use PhpWasmEngine\Gameplay\Combo;
use PhpWasmEngine\Gameplay\LightDash;
use PhpWasmEngine\Gameplay\GrappleGrip;
use PhpWasmEngine\Gameplay\Rider;
use PhpWasmEngine\Gameplay\RingCounter;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\Device;
use PhpWasmEngine\Render\Sprite;

$scene = $scene ?? (vrzno_env('scene') ?: 'platformer');
$profile = vrzno_env('profile') ?: 'runner';
$engine = new Engine(new Device);
$body = new Body(80, 100, $profile);
$counter = new RingCounter;
$combo = new Combo;

// The demo PNG has two transparent rows below the visible soles.
$entity = $engine->entities->create(
	$body
	, $counter
	, $combo
	, new Rider
	, new GrappleGrip
	, new LightDash
	, new PlayerCombat
	, new Sprite(['runner' => 7, 'glider' => 8, 'flyer' => 9][$profile], originY: 30 / 32)
);

$camera = new Camera(0, 0, 960, 540);
$game = new DemoGame($engine, $camera, $body, $counter, $combo, $entity);
$game->setMotionBlur(vrzno_env('motionBlur') !== false);
$pauseMenu = $game->menu = createDemoMenu($engine, $camera, $game);
$game->hud = new DemoPlayerHud($pauseMenu->renderer->font, $counter, $pauseMenu->renderer->atlas);
$game->comboHud = new DemoComboHud($pauseMenu->renderer->font, $combo, $pauseMenu->renderer->atlas);
$game->debug = new DemoDebugOverlay($pauseMenu->renderer->font, $pauseMenu->renderer->atlas);

if($intro ?? false)
{
	$game->startup = new DemoStartup($game, $scene);
}
else
{
	$game->selectScene($scene);
}

$engine->setInputHandler([$game, 'input']);
$engine->start([$game, 'render'], [$game, 'close'], [$game, 'restore']);

if($game->startup !== null)
{
	(vrzno_env('sceneReady'))(null);
}
