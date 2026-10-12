<?php
require_once dirname(__DIR__).'/autoload.php';
use PhpWasmEngine\Engine;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Render\
{
	Device,Camera,MapRenderer,Sprite,SpriteRenderer
};
$map = (new Loader)->map(dirname(__DIR__).'/examples/assets/platformer.tmj');
$device = new Device(320, 180);
$engine = new Engine($device);
$renderer = new MapRenderer($map);
$sprites = new SpriteRenderer($renderer->atlas);
$camera = new Camera(0, 260, 320, 180);
$sprite = new Sprite(7, 120, 380);
$ticks = $restores = 0;

// An in-memory tile edit must survive rebuilding the GPU texture array.
foreach($map->layers as $layer)
{
	if($layer->type === 'tilelayer')
	{
		$layer->set(2, 12, 1);
		break;
	}
}
$engine->systems->add('count', function() use (&$ticks) { $ticks++; });
$probe = vrzno_env('contextProbe');
$beforeRender = vrzno_env('contextBeforeRender');
$beforeRestore = vrzno_env('contextBeforeRestore');

$engine->start(function() use ($engine, $device, $renderer, $sprites, $sprite, $camera, $probe
	, $beforeRender, &$ticks, &$restores
) {
	$beforeRender(json_encode(['time' => $engine->time,'ticks' => $ticks]));
	$device->begin();
	$renderer->render($camera, 350);
	$sprites->render([$sprite], $camera, 350);
	$pixels = hash('sha256', glReadPixels(0, 0, 320, 180, GL_RGBA, GL_UNSIGNED_BYTE));
	$device->present();

	$probe(
		json_encode(
			['time' => $engine->time, 'ticks' => $ticks, 'restores' => $restores
			, 'pixels' =>
				$pixels
				, 'atlas' => spl_object_id($renderer->atlas)
				, 'uploads' => $renderer->stats[
				'uploads'
				]
				, 'images' => $renderer->images->uploads
				, 'right' => $engine->controls
				->down('right')
				, 'error' => glGetError()
			]
		)
	);
}, function() use ($renderer, $sprites) {
	$sprites->close();
	$renderer->close();
}, function() use ($renderer, $sprites, $beforeRestore, &$restores) {
	$beforeRestore();
	$renderer->restore();
	$sprites->restore();
	$restores++;
});
