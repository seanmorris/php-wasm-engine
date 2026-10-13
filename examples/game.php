<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/autoload.php';
require_once __DIR__.'/breakables.php';
require_once __DIR__.'/DemoLevel.php';
require_once __DIR__.'/DemoGame.php';
require_once __DIR__.'/menu.php';
require_once __DIR__.'/cards.php';
require_once __DIR__.'/DemoBackdrop.php';
require_once __DIR__.'/DemoStartup.php';

use PhpWasmEngine\Engine;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\Device;
use PhpWasmEngine\Render\Sprite;

$scene = $scene ?? (vrzno_env('scene') ?: 'platformer');
$profile = vrzno_env('profile') ?: 'runner';
$engine = new Engine(new Device);
$body = new Body(80, 100, $profile);

// The demo PNG has two transparent rows below the visible soles.
$entity = $engine->entities->create(
	$body
	, new Sprite(['runner' => 7, 'glider' => 8, 'flyer' => 9][$profile], originY: 30 / 32)
);
$camera = new Camera(0, 0, 960, 540);
$game = new DemoGame($engine, $camera, $body, $entity);
$pauseMenu = $game->menu = createDemoMenu($engine, $camera, $game);

if($intro ?? false)
{
	$game->startup = new DemoStartup($game, $scene);
}
else
{
	$game->selectScene($scene);
}

$engine->start([$game, 'render'], [$game, 'close'], [$game, 'restore']);

if($game->startup !== null)
{
	(vrzno_env('sceneReady'))(null);
}
