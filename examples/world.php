<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
use PhpWasmEngine\Engine;
use PhpWasmEngine\Tiled\
{
	Loader,WorldStream
};
use PhpWasmEngine\Render\
{
	Camera,Device,WorldRenderer
};

$world = (new Loader)->world(__DIR__.'/assets/stream.world');
$stream = new WorldStream($world, vrzno_env('requestAsset'), vrzno_env('releaseAsset'), 256, 768);
$device = new Device;
$engine = new Engine($device);
$renderer = new WorldRenderer($stream);
$camera = new Camera(32, 0, 960, 540);
$canvas = vrzno_env('canvas');
$browser = new Vrzno;
$status = $browser->document->querySelector('#status');
$frames = 0;
require __DIR__.'/menu.php';
$pauseMenu = createDemoMenu($engine, $camera, 'world');

$engine->systems->add('camera', function($entities, $dt) use ($engine, $camera, $stream) {
	$camera->x += $engine->controls->axis('left', 'right') * 900 * $dt;
	$camera->y += $engine->controls->axis('up', 'down') * 900 * $dt;

	if($engine->controls->pressed('reset'))
	{
		$camera->x = 32;
		$camera->y = 0;
	}

	if($engine->controls->pressed('action'))
	{
		foreach(array_keys($stream->errors) as $path)
		{
			$stream->retry($path);
		}
	}
});

$engine->start(function() use ($engine, $device, $renderer, $stream, $camera, $canvas, $status
	, &$frames, $pauseMenu
) {
	$stream->update(
		$camera->x
		, $camera->y
		, $camera->width / $camera->zoom
		, $camera->height / $camera->zoom
	);
	$device->begin();
	$stats = $renderer->render($camera, $engine->time * 1000, 0);
	$pauseMenu->render();
	$device->present();
	$canvas->dataset->menu = demoMenuState($pauseMenu, $engine);
	$canvas->dataset->frames = (string)++$frames;
	$paths = array_map('basename', array_keys($stream->loaded()));
	$pending = array_map('basename', $stream->pending());

	$canvas->dataset->world = json_encode(
		['x' => $camera->x
		, 'y' => $camera->y
		, 'loaded' => $paths
		, 'pending' => $pending
			, 'errors' => $stream->errors, 'draws' => $stats['draws']
		]
	);

	$status->textContent = sprintf(
		'Camera %.0f, %.0f · %d maps ready · %d pending · %d draws · %s'
		, $camera->x
		, $camera->y
		, count($paths)
		, count($pending)
		, $stats['draws']
		, implode(', ', $paths)
	);

	if($stream->errors)
	{
		$status->textContent =
			'Map download failed. Press keyboard X or gamepad B / Circle to retry. '
			.implode('; ', $stream->errors);
	}

	if($engine->userPaused)
	{
		$status->textContent = 'Paused. Press Start / Options or P to resume.';
	}
}, function() use ($renderer, $stream, $pauseMenu) {
	$pauseMenu->close();
	$renderer->close();
	$stream->close();
}, function() use ($renderer, $pauseMenu) {
	$renderer->restore();
	$pauseMenu->restore();
});
