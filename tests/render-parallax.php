<?php
require dirname(__DIR__) . '/autoload.php';
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Render\
{
	Device,Camera,MapRenderer
};

$image = dirname(__DIR__) . '/examples/assets/test.png';
$device = new Device(96, 64);
$sequence = 0;

function imageMap(array $layers, array $extra = []): MapRenderer
{
	global $sequence,$image;
	$sequence++;
	$path = '/parallax-' . $sequence . '.tmj';
	$set = [
		'firstgid' => 1
		, 'tilewidth' => 16
		, 'tileheight' => 16
		, 'columns' => 1
		, 'tilecount' => 1
		, 'image' => $image
	];

	$data = $extra + [
		'orientation' => 'orthogonal'
		, 'width' => 6
		, 'height' => 4
		, 'tilewidth' => 16
		, 'tileheight' => 16
		, 'tilesets' => [$set]
		, 'layers' => $layers
	];

	file_put_contents($path, json_encode($data));

	return new MapRenderer((new Loader)->map($path));
}

function pixel(int $x, int $y): string
{
	return bin2hex(glReadPixels($x, 63 - $y, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE));
}

$repeatImage = dirname(__DIR__) . '/examples/assets/test-parallax.png';
$imageLayer = [
	'id' => 1
	, 'type' => 'imagelayer'
	, 'image' => $repeatImage
	, 'repeatx' => true
	, 'repeaty' => true
	, 'parallaxx' => .5
	, 'parallaxy' => .25
	, 'offsetx' => 3
	, 'offsety' => -2
];

$renderer = imageMap([$imageLayer], ['parallaxoriginx' => 7, 'parallaxoriginy' => -3, 'infinite' => true]);
$renderer->map->x = -100;
$renderer->map->y = 20;
$exact = [];

foreach([[-100,-20,1],[-132,44,2],[-1000004,-500006,.5]] as [$cx,$cy,$zoom])
{
	$camera = new Camera($cx, $cy, 96, 64, $zoom);
	$device->begin([0,0,0,1]);
	$renderer->render($camera);
	$actual = glReadPixels(0, 0, 96, 64, GL_RGBA, GL_UNSIGNED_BYTE);
	$expected = '';
	$originX = -93;
	$originY = 17;
	$scrollX = ($cx + 48 / $zoom - $originX) * .5 + $originX - 48 / $zoom;
	$scrollY = ($cy + 32 / $zoom - $originY) * .25 + $originY - 32 / $zoom;

	for($y = 63; $y >= 0; $y--)
	{
		for($x = 0; $x < 96; $x++)
		{
			$u = (int)floor(($x + .5) / $zoom + $scrollX + 100 - 3);
			$v = (int)floor(($y + .5) / $zoom + $scrollY - 20 + 2);
			$u = ($u % 7 + 7) % 7;
			$v = ($v % 5 + 5) % 5;

			$expected .= hex2bin($v < 2?($u < 3?'ff0000ff':'00ff00ff'):($u < 3?'0000ffff' : 'ffff00ff'));
		}
	}

	$exact[] = $actual === $expected;
}

$cleanUploads = $renderer->images->uploads;
$renderer->close();

// A shared image can appear on both sides of a tile layer without changing painter order.
$back = ['type' => 'imagelayer','image' => $image,'repeatx' => true,'repeaty' => true];
$tile = ['type' => 'tilelayer','width' => 1,'height' => 1,'offsetx' => 8,'data' => [1]];
$front = [
	'type' => 'imagelayer'
	, 'image' => $image
	, 'offsetx' => 12
	, 'offsety' => 4
	, 'tintcolor' => '#8080ffff'
	, 'opacity' => .5
];

$hidden = ['type' => 'imagelayer','image' => '/missing.png','visible' => false];
$renderer = imageMap([$back,$tile,$front,$hidden]);
$device->begin([0,0,0,1]);
$renderer->render(new Camera(0, 0, 96, 64));
$painter = [pixel(4, 4),pixel(10, 4),pixel(14, 6),pixel(22, 6)];
$sharedUploads = $renderer->images->uploads;
$draws = $renderer->stats['draws'];
$reflection = new ReflectionProperty($renderer->images, 'textures');

if(PHP_VERSION_ID < 80100)
{
	$reflection->setAccessible(true);
}

$textures = array_column($reflection->getValue($renderer->images), 0);
$layers = $renderer->map->layers;
$renderer->map->layers = [$layers[0],$layers[2],$layers[1],$layers[3]];
$device->begin([0,0,0,1]);
$renderer->render(new Camera(0, 0, 96, 64));
$reordered = pixel(14, 6);
$renderer->close();
$renderer->close();
$released = array_map(fn($texture) => !glIsTexture($texture), $textures);

$stripProperties = [['name' => 'from','value' => 'bottom'],['name' => 'scrollX','value' => 8]];
$strip = [
	'type' => 'imagelayer'
	, 'image' => $image
	, 'repeatx' => true
	, 'parallaxx' => .5
	, 'offsety' => 8
	, 'properties' => $stripProperties
];

$renderer = imageMap([$strip]);
$camera = new Camera(-20, 200, 96, 64);
$device->begin([0,0,0,1]);
$renderer->render($camera, 1000);
$stripPixels = [pixel(0, 47),pixel(2, 52),pixel(10, 52),pixel(2, 60),pixel(90, 52)];
$renderer->close();

$keyed = ['type' => 'imagelayer','image' => $image,'transparentcolor' => '#ff0000'];
$renderer = imageMap([$keyed]);
$device->begin([0,0,0,1]);
$renderer->render(new Camera(0, 0, 96, 64));
$keyPixels = [pixel(4, 4),pixel(12, 4),pixel(20, 4)];
$renderer->close();

$animated = $back;

$animated['properties'] = [[
	'name' => 'frames'
	, 'value' => json_encode([['image' => $image, 'duration' => 100], ['image' => $repeatImage,'duration' => 200]])
]];

$renderer = imageMap([$animated]);
$animation = [];

foreach([0,100,300] as $time)
{
	$device->begin([0,0,0,1]);
	$renderer->render(new Camera(0, 0, 96, 64), $time);
	$animation[] = [pixel(4, 1),$renderer->images->uploads];
}

$renderer->close();
$matching = [];

foreach(['tilelayer','imagelayer'] as $type)
{
	$layer = [
		'type' => $type
		, 'image' => $image
		, 'width' => 1
		, 'height' => 1
		, 'data' => [1]
		, 'parallaxx' => .5
		, 'parallaxy' => .25
		, 'offsetx' => 3
		, 'offsety' => -2
	];

	$renderer = imageMap([$layer], ['parallaxoriginx' => 10,'parallaxoriginy' => 5]);
	$renderer->map->x = 100;
	$renderer->map->y = 20;
	$device->begin([0,0,0,1]);
	$renderer->render(new Camera(86, -7, 96, 64));
	$matching[] = glReadPixels(0, 0, 96, 64, GL_RGBA, GL_UNSIGNED_BYTE);
	$renderer->close();
}

$tileImageMatch = $matching[0] === $matching[1];
$worldMaps = [];

foreach([0,48] as $x)
{
	$renderer = imageMap([$back,$tile]);
	$worldMaps[] = [
		'fileName' => $renderer->map->path
		, 'x' => $x
		, 'y' => 0
		, 'width' => 48
		, 'height' => 64
	];

	$renderer->close();
}

file_put_contents('/parallax.world', json_encode(['maps' => $worldMaps]));
$world = new PhpWasmEngine\Render\WorldRenderer((new Loader)->world('/parallax.world'));
$device->begin([0,0,0,1]);
$worldStats = $world->render(new Camera(0, 0, 96, 64));
$worldPixels = [pixel(10, 4),pixel(58, 4)];
$world->close();
$error = glGetError();
$device->close();
echo json_encode(
	compact(
		'exact'
		, 'cleanUploads'
		, 'painter'
		, 'sharedUploads'
		, 'draws'
		, 'released'
		, 'reordered'
		, 'stripPixels'
		, 'keyPixels'
		, 'animation'
		, 'tileImageMatch'
		, 'worldPixels'
		, 'worldStats'
		, 'error'
	)
);
