<?php
require dirname(__DIR__).'/autoload.php';
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Render\
{
	Device,Camera,MapRenderer
};
$path = dirname(__DIR__).'/examples/assets/';
$set = ['firstgid' => 1
, 'tilewidth' => 16
, 'tileheight' => 16
, 'columns' => 1
, 'tilecount' => 1
	, 'image' => $path.'test.png'];
$values = [];
foreach([0,4,8,12,2,6,10,14] as $flags)
{
	$values[] = 1 + $flags * 268435456.0;
}
$map = ['orientation' => 'orthogonal'
, 'width' => 8
, 'height' => 1
, 'tilewidth' => 16
, 'tileheight' =>
	16
	, 'tilesets' => [$set]
	, 'layers' => [ ['id' => 1
	, 'type' => 'tilelayer'
	, 'name' => 'test'
	, 'width' => 8,'height' => 1,'data' => $values]
	, ['id' => 2,'type' => 'tilelayer'
	, 'name' =>
	'second'
	, 'width' => 8
	, 'height' => 1
	, 'offsety' => 32
	, 'data' => [1,0,0,0,0,0,0,0]] ] ];
file_put_contents('/test.tmj', json_encode($map));
$loader = new Loader;
$map = $loader->map('/test.tmj');
$device = new Device(160, 64);
$renderer = new MapRenderer($map);
$camera = new Camera(0, 0, 160, 64);
$device->begin([0,0,0,1]);
$renderer->render($camera);
$initial = $renderer->stats;
$colors = ['ff0000ff' => 0,'00ff00ff' => 1,'0000ffff' => 2,'ffff00ff' => 3];
$quadrants = [];
for($i = 0; $i < 8; $i++)
{
	$sample = [];
	foreach([[4,4],[12,4],[4,12],[12,12]] as [$x,$y])
	{
		$sample[] = $colors[bin2hex(glReadPixels($i * 16 + $x, 63 - $y, 1, 1, GL_RGBA
			, GL_UNSIGNED_BYTE))] ?? -1;
	}
	$quadrants[] = $sample;
}
$renderer->render($camera);
$clean = $renderer->stats;
$map->layers[0]->set(0, 0, 0);
$device->begin([0,0,0,1]);
$renderer->render($camera);
$dirty = $renderer->stats;
$erased = bin2hex(glReadPixels(4, 59, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE));
$camera->x = 10000;
$renderer->render($camera);
$culled = $renderer->stats;
$ids = [$renderer->atlas->texture,$renderer->atlas->metadata];
$renderer->close();
$renderer->close();
$live = array_map(fn($id) => glIsTexture($id), $ids);
$error = glGetError();
$device->close();
echo json_encode(compact('quadrants', 'initial', 'clean', 'dirty', 'erased', 'culled', 'live'
	, 'error'));
