<?php
declare(strict_types=1);
// Procedural demo art. No assets from the reference games are distributed.

function png(string $path, int $width, int $height, callable $pixel): void
{

	$chunk = static fn($type, $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
	$data = '';

	for($y = 0; $y < $height; $y++)
	{
		$data .= "\0";

		for($x = 0; $x < $width; $x++)
		{
			$data .= pack('C*', ...$pixel($x, $y));
		}
	}

	file_put_contents(
		$path
		, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNC5', $width, $height, 8, 6, 0, 0, 0)) . $chunk(
			'IDAT'
			, gzcompress($data)
		) . $chunk('IEND', '')
	);
}

function jsonAsset(string $name, array $data): void
{
	file_put_contents(
		__DIR__ . '/assets/' . $name
		, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
	);
}

// Tiled's JSON importer reads omitted visibility and opacity as false and zero.
$layerDefaults = ['visible' => true,'opacity' => 1,'x' => 0,'y' => 0];

$playerColors = [[86,167,245,255],[242,120,114,255],[246,189,83,255]];

png(__DIR__ . '/assets/tiles.png', 832, 32, static function($x, $y) use($playerColors) {
	$id = intdiv($x, 32);
	$x %= 32;

	if($id <= 2)
	{
		$top = $id === 1?31 - $x:($id === 2?$x:0);

		if($y < $top)
		{
			return [0,0,0,0];
		}

		return $y < $top + 5
			? [105,212,157,255]
			: (($x + $y) % 7 === 0
				? [71,100,105,255]
				: [
					42
					, 63
					, 77
					, 255
				]
			);
	}

	if($id === 3)
	{
		return $y < 6?[244,195,107,255]:[0,0,0,0];
	}

	if($id === 4 || $id === 5)
	{
		return hypot($x - 16, $y - 16) < ($id === 4?10:7) && hypot($x - 16, $y - 16) > 4
			? [
				255
				, 211
				, 107
				, 255
			]
			: [
				0
				, 0,0,0
			];
	}

	if($id >= 18 && $id <= 22)
	{
		$walking = $id === 19 || $id === 20;
		$shooting = $id === 21 || $id === 22;
		$alternate = $id % 2;
		$red = [226,76,88,255];
		$dark = [112,39,58,255];
		$cream = [248,229,180,255];

		if(($x >= 9 && $x <= 22 && $y >= 13 && $y <= 26)
			|| ($y >= 24 && $y <= 27 && (($x >= 4 && $x <= 11) || ($x >= 20 && $x <= 27)))
		){
			return $y >= 22 ? $dark : $red;
		}

		if(($x >= 11 && $x <= 14 && $y >= 8 && $y <= 15)
			|| ($x >= 18 && $x <= 21 && $y >= 8 && $y <= 15)
		){
			return $y <= 10 ? $cream : $dark;
		}

		$legY = $walking ? 27 + $alternate : 28;

		if($y >= $legY && $y <= 30 && (($x >= 2 && $x <= 12) || ($x >= 19 && $x <= 29)))
		{
			return $dark;
		}

		$clawY = $shooting ? 5 + $alternate * 3 : 15 + $alternate;

		if($y >= $clawY && $y <= $clawY + 7
			&& (($x >= 1 && $x <= 8) || ($x >= 23 && $x <= 30))
		){
			return $red;
		}

		return [0,0,0,0];
	}

	if($id === 23)
	{
		$distance = hypot($x - 16, $y - 16);

		if($distance <= 5)
		{
			return $distance < 2 ? [255,244,190,255] : [101,214,239,255];
		}

		return [0,0,0,0];
	}

	if($id === 24 || $id === 25)
	{
		$dx = abs($x - 16);
		$dy = abs($y - 16);
		$outer = $id === 24 ? 12 : 15;
		$inside = $dx + $dy <= $outer && (min($dx, $dy) <= 4 || $dx + $dy <= 8);

		return $inside
			? ($dx + $dy < 7 ? [255,246,183,255] : [255,132,62,255])
			: [0,0,0,0];
	}

	if($id >= 12 && $id < 18)
	{
		$profile = intdiv($id - 12, 2);
		$dx = $x - 16;
		$dy = $y - 18;

		if(hypot($dx, $dy) > 11)
		{
			return [0,0,0,0];
		}

		$stripe = ($id - 12) % 2 === 0 ? abs($dx - $dy) <= 1 : abs($dx + $dy) <= 1;

		return $stripe && hypot($dx, $dy) < 8 ? [240,247,255,255] : $playerColors[$profile];
	}

	if($id >= 9)
	{
		if($x < 5 || $x > 26 || $y < 14 || $y > 29 || (($x < 9 || $x > 23) && $y < 23))
		{
			return [0,0,0,0];
		}

		if($x >= 18 && $x <= 22 && $y >= 16 && $y <= 19)
		{
			return [240,247,255,255];
		}

		return $y > 26 ? [222,238,247,255] : $playerColors[$id - 9];
	}

	if($x < 6 || $x > 25 || $y < 3 || $y > 29)
	{
		return [0,0,0,0];
	}

	if($x >= 18 && $x <= 22 && $y >= 8 && $y <= 12)
	{
		return [240,247,255,255];
	}

	if($y > 25)
	{
		return [222,238,247,255];
	}

	return $playerColors[$id - 6];
});

$solid = [['name' => 'solid','type' => 'bool','value' => true]];
$tiles = [
	'type' => 'tileset'
	, 'name' => 'demo'
	, 'tilewidth' => 32
	, 'tileheight' => 32
	, 'columns' => 26
	, 'tilecount' => 26
	, 'image' => 'tiles.png'
	, 'imagewidth' => 832
	, 'imageheight' => 32
	, 'tiles' => [
		['id' => 0,'properties' => $solid]
		, [
			'id' => 1
			, 'objectgroup' => [
				'objects' => [
					[
						'x' => 0
						, 'y' => 0
						, 'polygon' => [['x' => 0,'y' => 32],['x' => 32,'y' => 0],['x' => 32,'y' => 32]]
					]
				]
			]
		]
		, [
			'id' => 2
			, 'objectgroup' => [
				'objects' => [
					[
						'x' => 0
						, 'y' => 0
						, 'polygon' => [
							['x' => 0,'y' => 0]
							, [
								'x' => 32
								, 'y' => 32
							]
							, ['x' => 0,'y' => 32]
						]
					]
				]
			]
		]
		, [
			'id' => 3
			, 'properties' => [
				[
					'name' => 'oneWay'
					, 'value' => true
				]
			]
			, 'objectgroup' => [
				'objects' => [
					[
						'x' => 0
						, 'y' => 0
						, 'width' => 32
						, 'height' => 6
					]
				]
			]
		]
		, [
			'id' => 4
			, 'class' => 'ring'
			, 'properties' => [
				['name' => 'ring.radius', 'type' => 'float', 'value' => 12]
				, ['name' => 'ring.value', 'type' => 'int', 'value' => 1]
			]
			, 'animation' => [
				['tileid' => 4,'duration' => 180]
				, [
					'tileid' => 5
					, 'duration' => 180
				]
			]
		]
		, ['id' => 6,'class' => 'runner'] , ['id' => 7,'class' => 'glider']
		, [
			'id' => 8
			, 'class' => 'flyer'
		]
		, ['id' => 9,'class' => 'runner-crouching']
		, ['id' => 10,'class' => 'glider-crouching']
		, ['id' => 11,'class' => 'flyer-crouching']
		, [
			'id' => 12
			, 'class' => 'runner-rolling'
			, 'animation' => [
				['tileid' => 12,'duration' => 80]
				, ['tileid' => 13,'duration' => 80]
			]
		]
		, [
			'id' => 14
			, 'class' => 'glider-rolling'
			, 'animation' => [
				['tileid' => 14,'duration' => 80]
				, ['tileid' => 15,'duration' => 80]
			]
		]
		, [
			'id' => 16
			, 'class' => 'flyer-rolling'
			, 'animation' => [
				['tileid' => 16,'duration' => 80]
				, ['tileid' => 17,'duration' => 80]
			]
		]
		, ['id' => 18,'class' => 'crabmeat-standing']
		, [
			'id' => 19
			, 'class' => 'crabmeat-walking'
			, 'animation' => [
				['tileid' => 19,'duration' => 120]
				, ['tileid' => 20,'duration' => 120]
			]
		]
		, [
			'id' => 21
			, 'class' => 'crabmeat-shooting'
			, 'animation' => [
				['tileid' => 21,'duration' => 100]
				, ['tileid' => 22,'duration' => 100]
			]
		]
		, ['id' => 23,'class' => 'badnik-projectile']
		, [
			'id' => 24
			, 'class' => 'badnik-explosion'
			, 'animation' => [
				['tileid' => 24,'duration' => 80]
				, ['tileid' => 25,'duration' => 80]
			]
		]
	]
];

jsonAsset('tiles.tsj', $tiles);

png(__DIR__ . '/assets/monitors.png', 768, 32, static function($x, $y) {
	$id = intdiv($x, 32);
	$x %= 32;
	$transparent = [0,0,0,0];
	$dark = [31,39,52,255];
	$middle = [92,111,128,255];
	$light = [204,220,224,255];
	$gold = [255,205,73,255];
	$colors = [
		[139,231,255,255]
		, [112,184,255,255]
		, [255,100,43,255]
		, [67,220,240,255]
		, [255,242,116,255]
	];

	if($id === 0 || $id === 1)
	{
		if(($x >= 2 && $x <= 29 && $y >= 2 && $y <= 27)
			|| ($y >= 28 && $y <= 31 && (($x >= 4 && $x <= 11) || ($x >= 21 && $x <= 28)))
		){
			if($x >= 5 && $x <= 26 && $y >= 5 && $y <= 21)
			{
				return ($x + $y + $id) % 7 === 0 ? [52,75,85,255] : [12,20,29,255];
			}

			return ($x <= 4 || $y <= 4) ? $light : (($x + $y + $id) % 5 === 0 ? $middle : $dark);
		}

		return $transparent;
	}

	if($id === 2)
	{
		if($y >= 18 && $y <= 29 && $x >= 2 && $x <= 29)
		{
			return (($x + $y) % 6 === 0) ? $middle : $dark;
		}

		if(($x >= 3 && $x <= 8 && $y >= 10 && $y <= 20)
			|| ($x >= 23 && $x <= 28 && $y >= 13 && $y <= 21)
		){
			return $middle;
		}

		return $transparent;
	}

	if($id === 3)
	{
		$distance = hypot($x - 16, $y - 16);

		return $distance >= 5 && $distance <= 8 ? $gold : $transparent;
	}

	if($id === 4)
	{
		return $transparent;
	}

	if($id === 5)
	{
		foreach([[11,17],[16,12],[21,17]] as [$cx, $cy])
		{
			$distance = hypot($x - $cx, $y - $cy);

			if($distance >= 3 && $distance <= 5)
			{
				return $gold;
			}
		}

		return $transparent;
	}

	if($id === 6 || $id === 7)
	{
		$dx = abs($x - 16);
		$dy = abs($y - 16);
		$outer = $id === 6 ? 12 : 15;
		$inside = $dx + $dy <= $outer && (min($dx, $dy) <= 4 || $dx + $dy <= 8);

		return $inside
			? ($dx + $dy < 7 ? [255,246,183,255] : [255,132,62,255])
			: $transparent;
	}

	if($id >= 9 && $id <= 13)
	{
		$type = $id - 9;
		$color = $colors[$type];
		$dx = $x - 16;
		$dy = $y - 16;
		$distance = hypot($dx, $dy);

		if($type === 0)
		{
			return $distance >= 7 && $distance <= 10 ? $color : $transparent;
		}

		if($type === 1)
		{
			$bolt = ($y >= 5 && $y <= 26)
				&& (($x >= 15 && $x <= 19 && $y < 15)
					|| ($x >= 11 && $x <= 16 && $y >= 13 && $y < 18)
					|| ($x >= 13 && $x <= 16 && $y >= 17)
				);

			return $bolt ? $color : $transparent;
		}

		if($type === 2)
		{
			$flame = $y >= 6 && $y <= 26 && abs($dx) <= max(2, intdiv($y - 2, 3))
				&& !($y > 18 && abs($dx) < 3);

			return $flame ? ($distance < 6 ? [255,225,96,255] : $color) : $transparent;
		}

		if($type === 3)
		{
			return $distance >= 7 && $distance <= 11
				? ($x < 13 && $y < 13 ? $light : $color)
				: $transparent;
		}

		$star = abs($dx) <= 2 || abs($dy) <= 2 || abs($dx - $dy) <= 1 || abs($dx + $dy) <= 1;

		return $star && $distance <= 11 ? $color : $transparent;
	}

	if($id >= 14 && $id <= 23)
	{
		$type = intdiv($id - 14, 2);
		$frame = ($id - 14) % 2;
		$color = $colors[$type];
		$dx = $x - 16;
		$dy = $y - 16;
		$distance = hypot($dx, $dy);
		$radius = 13 + $frame;
		$edge = $distance >= $radius - 1.5 && $distance <= $radius + .75;
		$spark = (($x + $y * 3 + $type * 5 + $frame * 7) % 19 === 0)
			&& $distance >= 10 && $distance <= 15;

		if($type === 1 && $edge)
		{
			$edge = (($x + $y + $frame * 2) % 5) < 3;
		}

		if($type === 2 && $edge)
		{
			$edge = $y < 22 || (($x + $frame) % 4) < 2;
		}

		if($type === 4 && $spark)
		{
			return [255,255,255,255];
		}

		return $edge || $spark ? $color : $transparent;
	}

	return $transparent;
});

$monitors = [
	'type' => 'tileset'
	, 'name' => 'monitors'
	, 'tilewidth' => 32
	, 'tileheight' => 32
	, 'columns' => 24
	, 'tilecount' => 24
	, 'image' => 'monitors.png'
	, 'imagewidth' => 768
	, 'imageheight' => 32
	, 'tiles' => [
		[
			'id' => 0
			, 'class' => 'monitor-shell'
			, 'animation' => [
				['tileid' => 0,'duration' => 180]
				, ['tileid' => 1,'duration' => 180]
			]
		]
		, ['id' => 2,'class' => 'monitor-broken']
		, [
			'id' => 3
			, 'class' => 'monitor-screen-ring'
			, 'animation' => [
				['tileid' => 4,'duration' => 192]
				, ['tileid' => 3,'duration' => 460]
				, ['tileid' => 4,'duration' => 116]
			]
		]
		, [
			'id' => 5
			, 'class' => 'monitor-screen-triple-ring'
			, 'animation' => [
				['tileid' => 4,'duration' => 192]
				, ['tileid' => 5,'duration' => 460]
				, ['tileid' => 4,'duration' => 116]
			]
		]
		, [
			'id' => 6
			, 'class' => 'monitor-explosion'
			, 'animation' => [
				['tileid' => 6,'duration' => 80]
				, ['tileid' => 7,'duration' => 80]
			]
		]
		, ...array_map(
			static fn($type, $id) => [
				'id' => $id
				, 'class' => 'monitor-screen-shield-' . $type
				, 'animation' => [
					['tileid' => 4,'duration' => 192]
					, ['tileid' => $id,'duration' => 460]
					, ['tileid' => 4,'duration' => 116]
				]
			]
			, ['normal', 'electric', 'fire', 'water', 'star']
			, range(9, 13)
		)
		, ...array_map(
			static fn($type, $id) => [
				'id' => $id
				, 'class' => 'shield-aura-' . $type
				, 'animation' => [
					['tileid' => $id,'duration' => 120]
					, ['tileid' => $id + 1,'duration' => 120]
				]
			]
			, ['normal', 'electric', 'fire', 'water', 'star']
			, [14, 16, 18, 20, 22]
		)
	]
];

jsonAsset('monitors.tsj', $monitors);
$width = 96;
$height = 20;
$floor = array_fill(0, $width * $height, 0);
$items = $floor;

for($x = 0; $x < $width; $x++)
{
	$top = 14;

	if($x >= 18 && $x <= 23)
	{
		$top = 14 - ($x - 18);
	}

	if($x >= 24 && $x <= 34)
	{
		$top = 9;
	}

	if($x >= 35 && $x <= 39)
	{
		$top = 9 + $x - 35;
	}

	if($x >= 54 && $x <= 58)
	{
		continue;
	}

	for($y = $top; $y < $height; $y++)
	{
		$floor[$y * $width + $x] = 1;
	}

	if($x >= 18 && $x <= 23)
	{
		$floor[$top * $width + $x] = 2;
	}

	if($x >= 35 && $x <= 39)
	{
		$floor[$top * $width + $x] = 3;
	}

	if($x % 4 === 0)
	{
		$items[($top - 2) * $width + $x] = 5;
	}
}

for($x = 8; $x <= 12; $x++)
{
	$floor[10 * $width + $x] = 4;
}

for($y = 6; $y < 14; $y++)
{
	$floor[$y * $width + 68] = 1;
}

$base = [
	'type' => 'map'
	, 'version' => '1.10'
	, 'orientation' => 'orthogonal'
	, 'renderorder' => 'right-down'
	, 'width' => $width
	, 'height' => $height
	, 'tilewidth' => 32
	, 'tileheight' => 32
	, 'infinite' => false
	, 'tilesets' => [['firstgid' => 1,'source' => 'tiles.tsj']]
];

// Repeating coastal strips, drawn entirely by the PHP image renderer.
png(
	__DIR__ . '/assets/sky.png'
	, 8
	, 300
	, static fn($x, $y) => [15 + (int)($y / 18), 30 + (int)($y / 10), 52 + (int)($y / 7),255]
);

png(__DIR__ . '/assets/clouds.png', 512, 96, static function($x, $y) {
	foreach([[72,26,62,12],[110,19,29,17],[300,64,88,10],[348,55,42,15]] as [$cx,$cy,$rx,$ry])
	{
		if((($x - $cx) / $rx) ** 2 + (($y - $cy) / $ry) ** 2 < 1)
		{
			return [116,159,180,85];
		}
	}

	return [0,0,0,0];
});

foreach(['far' => [180,48,78,101],'near' => [140,39,67,80]] as $name => [$h,$r,$g,$b])
{
	png(__DIR__ . '/assets/mountains-' . $name . '.png', 512, $h, static function($x, $y) use ($name, $h
		, $r, $g
		, $b
	) {
		$ridge = $name === 'far'
			? 45 + 25 * sin($x * M_PI / 128) + 16 * cos($x * M_PI / 64)
			: 56 + 27 * cos($x * M_PI / 256) + 13 * sin($x * M_PI / 64);

		if($y < $ridge)
		{
			return [0,0,0,0];
		}

		$shade = (int)(12 * ($y - $ridge) / $h);

		return [$r - $shade,$g - $shade,$b - $shade,255];
	}
	);
}

png(__DIR__ . '/assets/water.png', 128, 256, static function($x, $y) {
	$shine = ($y % 13 === 0 && ($x + intdiv($y, 13) * 19) % 64 < 28)?12:0;

	return [31 + $shine,69 + $shine - (int)($y / 24),89 + $shine - (int)($y / 16),255];
});

foreach([0,1] as $frame)
{
	png(
		__DIR__ . '/assets/shine-' . $frame . '.png'
		, 128
		, 8
		, static fn($x, $y) => ($x + $frame * 16 + $y * 7) % 48 < 18 && $y % 3 === 0
			? [
				124
				, 172
				, 180
				, 130
			]
			: [0,0,0,0]
	);
}

$background = [];
$bands = [
	['sky',0,0,0,8,300]
	, ['clouds',36,.06,6,512,96]
	, ['mountains-far',128,.16,0,512,180]
	, ['water',336,.28,0,128,256]
	, ['mountains-near',196,.38,0,512,140]
	, ['shine-0',336,.28,18,128,8]
];

foreach($bands as $i => [$name,$offset,$parallax,$scroll,$w,$h])
{
	$properties = [
		['name' => 'from','type' => 'string','value' => 'bottom']
		, [
			'name' => 'scrollX'
			, 'type' => 'float'
			, 'value' => $scroll
		]
	];

	if($name === 'shine-0')
	{
		$properties[] = [
			'name' => 'frames'
			, 'type' => 'string'
			, 'value' => json_encode([
				['image' => 'shine-0.png', 'duration' => 180]
				, [
					'image' => 'shine-1.png'
					, 'duration' => 180
				]
			])
		];
	}

	$background[] = [
		'id' => 10 + $i
		, 'name' => $name
		, 'type' => 'imagelayer'
		, 'image' => $name . '.png'
		, 'imagewidth' => $w
		, 'imageheight' => $h
		, 'repeatx' => true
		, 'parallaxx' => $parallax
		, 'parallaxy' => 0
		, 'offsety' => $offset
		, 'properties' => $properties
	] + $layerDefaults;
}

$title = $base;
$title['width'] = 30;
$title['height'] = 17;
$title['tilesets'] = [];
$title['layers'] = $background;

jsonAsset('title.tmj', $title);

$base['layers'] = [
	[
		'id' => 1
		, 'name' => 'Terrain'
		, 'type' => 'tilelayer'
		, 'width' => $width
		, 'height' => $height
		, 'data' => $floor
	] + $layerDefaults
	, [
		'id' => 2
		, 'name' => 'Markers'
		, 'type' => 'tilelayer'
		, 'width' => $width
		, 'height' => $height
		, 'data' => $items
	] + $layerDefaults
	, [
		'id' => 3
		, 'name' => 'Spawns'
		, 'type' => 'objectgroup'
		, 'objects' => [
			[
				'id' => 1
				, 'class' => 'Player'
				, 'x' => 80
				, 'y' => 100
			]
		]
	] + $layerDefaults
];

$base['layers'] = array_merge($background, $base['layers']);
jsonAsset('platformer.tmj', $base);
$badniks = $base;
$badniks['tilesets'][] = ['firstgid' => 27,'source' => 'monitors.tsj'];
$badniks['layers'][] = [
	'id' => 4
	, 'name' => 'Badniks'
	, 'type' => 'objectgroup'
	, 'objects' => [
		[
			'id' => 2
			, 'class' => 'CrabMeat'
			, 'point' => true
			, 'x' => 480
			, 'y' => 448
			, 'properties' => [
				['name' => 'direction','type' => 'int','value' => -1]
				, ['name' => 'patrolBeat','type' => 'float','value' => 90]
				, ['name' => 'patrolPause','type' => 'float','value' => 25]
				, ['name' => 'patrolSpeed','type' => 'float','value' => .25]
				, ['name' => 'shotTelegraph','type' => 'float','value' => 65]
			]
		]
		, [
			'id' => 3
			, 'class' => 'CrabMeat'
			, 'point' => true
			, 'x' => 1408
			, 'y' => 448
			, 'properties' => [
				['name' => 'direction','type' => 'int','value' => 1]
				, ['name' => 'patrolOffset','type' => 'float','value' => 45]
			]
		]
	]
] + $layerDefaults;

$badniks['layers'][] = [
	'id' => 5
	, 'name' => 'Monitors'
	, 'type' => 'objectgroup'
	, 'objects' => [
		[
			'id' => 4
			, 'class' => 'RingMonitor'
			, 'point' => true
			, 'x' => 288
			, 'y' => 448
		]
		, [
			'id' => 5
			, 'class' => 'TripleRingMonitor'
			, 'point' => true
			, 'x' => 800
			, 'y' => 288
		]
		, [
			'id' => 6
			, 'class' => 'NormalShieldMonitor'
			, 'point' => true
			, 'x' => 1056
			, 'y' => 288
		]
		, [
			'id' => 7
			, 'class' => 'ElectricShieldMonitor'
			, 'point' => true
			, 'x' => 1312
			, 'y' => 448
		]
		, [
			'id' => 8
			, 'class' => 'FireShieldMonitor'
			, 'point' => true
			, 'x' => 1632
			, 'y' => 448
		]
		, [
			'id' => 9
			, 'class' => 'WaterShieldMonitor'
			, 'point' => true
			, 'x' => 1936
			, 'y' => 448
		]
		, [
			'id' => 10
			, 'class' => 'StarShieldMonitor'
			, 'point' => true
			, 'x' => 2080
			, 'y' => 448
		]
	]
] + $layerDefaults;

jsonAsset('badniks.tmj', $badniks);
$infinite = $base;
$infinite['infinite'] = true;
$infinite['width'] = $infinite['height'] = 0;
$infinite['layers'] = [];

foreach([-32,0,32] as $offset)
{
	$data = array_fill(0, 32 * 20, 0);

	for($y = 14; $y < 20; $y++)
	{
		for($x = 0; $x < 32; $x++)
		{
			$data[$y * 32 + $x] = 1;
		}
	}

	$chunks[] = ['x' => $offset,'y' => 0,'width' => 32,'height' => 20,'data' => $data];
}

$infinite['layers'] = [
	[
		'id' => 1
		, 'name' => 'Infinite terrain'
		, 'type' => 'tilelayer'
		, 'chunks' => $chunks
	] + $layerDefaults
];

$infinite['layers'] = array_merge($background, $infinite['layers']);
jsonAsset('infinite.tmj', $infinite);

png(__DIR__ . '/assets/iso.png', 128, 64, static function($x, $y) {
	$id = intdiv($x, 64);
	$x %= 64;
	$dy = $y - ($id?22:48);

	if(abs($x - 32) / 32 + abs($dy) / 16 <= 1)
	{
		return $id?[159,159,235,255]:[104,200,173,255];
	}

	if($id && $y >= 22 && $y < 48 + 16 - abs($x - 32) / 2)
	{
		return $x < 32?[71,83,139,255]:[91,104,166,255];
	}

	return [0,0,0,0];
});

jsonAsset(
	'iso.tsj'
	, [
		'type' => 'tileset'
		, 'tilewidth' => 64
		, 'tileheight' => 64
		, 'tilecount' => 2
		, 'columns' => 2
		, 'image' => 'iso.png'
		, 'imagewidth' => 128
		, 'imageheight' => 64
	]
);

$iso = [
	'type' => 'map'
	, 'orientation' => 'isometric'
	, 'width' => 12
	, 'height' => 12
	, 'tilewidth' => 64
	, 'tileheight' => 32
	, 'tilesets' => [['firstgid' => 1,'source' => 'iso.tsj']]
	, 'layers' => [
		[
			'id' => 1
			, 'name' => 'Isometric'
			, 'type' => 'tilelayer'
			, 'width' => 12
			, 'height' => 12
			, 'data' => array_map(fn($i) => ($i % 13 === 0 || $i === 58 || $i === 59)?2:1, range(0, 143))
		] + $layerDefaults
	]
];

$iso['layers'] = array_merge($background, $iso['layers']);
jsonAsset('isometric.tmj', $iso);

jsonAsset(
	'demo.world'
	, [
		'type' => 'world'
		, 'maps' => [
			[
				'fileName' => 'platformer.tmj'
				, 'x' => 0
				, 'y' => 0
				, 'width' => 3072
				, 'height' => 640
			]
			, [
				'fileName' => 'infinite.tmj'
				, 'x' => 4096
				, 'y' => 0
				, 'width' => 3072
				, 'height' => 640
				, 'bounds' => ['x' => -1024,'y' => 0,'width' => 3072,'height' => 640]
			]
		]
	]
);

// Asymmetric four-quadrant image for orientation and flip regressions.
png(
	__DIR__ . '/assets/test.png'
	, 16
	, 16
	, static fn($x, $y) => $y < 8
		? ($x < 8?[255,0,0,255]:[0, 255, 0, 255])
		: ($x < 8
			? [0,0,255,255]
			: [
				255
				, 255,0,255
			]
		)
);

png(
	__DIR__ . '/assets/test-parallax.png'
	, 7
	, 5
	, static fn($x, $y) => $y < 2
		? ($x < 3?[255,0,0,255]:[0, 255, 0,255])
		: ($x < 3
			? [0,0,255,255]
			: [
				255
				, 255,0,255
			]
		)
);

// Independently downloadable maps with shared tilesets and parallax images.
$worldMaps = [];

foreach(range(-2, 2) as $section)
{
	$streamMap = $base;
	$streamMap['width'] = 32;
	$streamMap['layers'] = $background;
	$cells = array_fill(0, 32 * 20, 0);

	for($y = 13; $y < 20; $y++)
	{
		for($x = 0; $x < 32; $x++)
		{
			$cells[$y * 32 + $x] = 1;
		}
	}

	foreach(range(0, 31, 4) as $x)
	{
		$cells[(9 + abs($section)) * 32 + $x] = 5;
	}

	$streamMap['layers'][] = [
		'id' => 1
		, 'name' => 'Terrain'
		, 'type' => 'tilelayer'
		, 'width' => 32
		, 'height' => 20
		, 'data' => $cells
	] + $layerDefaults;

	$name = 'stream-' . ($section + 2) . '.tmj';
	jsonAsset($name, $streamMap);
	$worldMaps[] = [
		'fileName' => $name
		, 'x' => $section * 1024
		, 'y' => 0
		, 'width' => 1024
		, 'height' => 640
	];
}

jsonAsset('stream.world', ['type' => 'world','maps' => $worldMaps]);
require __DIR__ . '/generate-loop.php';
