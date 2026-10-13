<?php
require dirname(__DIR__).'/autoload.php';
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Render\
{
	Device,Camera,MapRenderer,Sprite,SpriteRenderer
};

$image = dirname(__DIR__).'/examples/assets/test.png';
$set = ['firstgid' => 1
, 'tilewidth' => 16
, 'tileheight' => 16
, 'columns' => 1
, 'tilecount' => 1
	, 'image' => $image
];
$device = new Device(384, 192);
$camera = new Camera(-48, -16, 384, 192);
$data = ['orientation' => 'isometric'
, 'width' => 34
, 'height' => 2
, 'tilewidth' => 16
, 'tileheight' =>
	8
	, 'tilesets' => [$set]
	, 'layers' => [['id' => 1
	, 'type' => 'tilelayer'
	, 'width' => 34
	, 'height' => 2
	, 'data' => array_fill(0, 68, 1)]]
];
file_put_contents('/iso-test.tmj', json_encode($data));
$map = (new Loader)->map('/iso-test.tmj');
$renderer = new MapRenderer($map);
$device->begin([0,0,0,1]);
$renderer->render($camera);
$actual = glReadPixels(0, 0, 384, 192, GL_RGBA, GL_UNSIGNED_BYTE);
$expected = str_repeat("\0\0\0\xff", 384 * 192);
$cells = [];

for($y = 0; $y < 2; $y++)
{
	for($x = 0; $x < 34; $x++)
	{
		$cells[] = [$x,$y];
	}
}

usort($cells, fn($a, $b) => ($a[0] + $a[1]) <=> ($b[0] + $b[1])?:$b[0] <=> $a[0]);
$colors = [hex2bin('ff0000ff'),hex2bin('00ff00ff'),hex2bin('0000ffff'),hex2bin('ffff00ff')];

foreach($cells as [$x,$y])
{
	[$px,$py] = $map->projection->project($x, $y);
	$px = $px - 8 - $camera->x;
	$py = $py - 8 - $camera->y;

	for($v = 0; $v < 16; $v++)
	{
		for($u = 0; $u < 16; $u++)
		{
			$sx = (int)$px + $u;
			$sy = (int)$py + $v;

			if($sx < 0 || $sx >= 384 || $sy < 0 || $sy >= 192)
			{
				continue;
			}

			$color = $colors[($u >= 8?1:0) + ($v >= 8?2:0)];
			$offset = ((191 - $sy) * 384 + $sx) * 4;

			for($channel = 0; $channel < 4; $channel++)
			{
				$expected[$offset + $channel] = $color[$channel];
			}
		}
	}
}

$isoExact = $expected === $actual;
$isoStats = $renderer->stats;
$renderer->close();

$data = ['orientation' => 'orthogonal'
, 'infinite' => true
, 'width' => 0
, 'height' => 0
, 'tilewidth' =>
	16
	, 'tileheight' => 16,'tilesets' => [$set]
	, 'layers' => [['id' => 1
	, 'type' => 'tilelayer'
	, 'chunks' => [['x' => -10000,'y' => -2,'width' => 1,'height' => 1,'data' => [1]]
	, ['x' => 10000
	, 'y' => 0,'width' => 1,'height' => 1,'data' => [1]]]]]
];
file_put_contents('/sparse-test.tmj', json_encode($data));
$map = (new Loader)->map('/sparse-test.tmj');
$renderer = new MapRenderer($map);
$camera = new Camera(-160000, -32, 384, 192);
$device->begin([0,0,0,1]);
$renderer->render($camera);
$sparse = $renderer->stats;
$negative = bin2hex(glReadPixels(4, 187, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE));
$sprites = new SpriteRenderer($renderer->atlas);
$sprite = new Sprite(1, 32, 32, .5, .5);
$sprite->flipX = true;
$sprite->rotation = M_PI / 2;
$device->begin([0,0,0,1]);
$sprites->render([$sprite], new Camera(0, 0, 384, 192));
$rotated = [];

foreach([[28,28],[36,28],[28,36],[36,36]] as [$x,$y])
{
	$rotated[] = bin2hex(glReadPixels($x, 191 - $y, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE));
}

$sprites->close();
$renderer->close();
$error = glGetError();
$device->close();
echo json_encode(compact('isoExact', 'isoStats', 'sparse', 'negative', 'rotated', 'error'));
