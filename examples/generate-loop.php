<?php
declare(strict_types=1);
// Invoked by generate-assets.php. Original art and polygon geometry for an 8x8 loop.
// Clip each quarter's solid exterior to the tile grid, retaining the curved inner edge.

function clipLoop(array $points, int $axis, float $bound, bool $greater): array
{
	$result = [];
	$previous = $points[count($points) - 1];

	$inside = fn($p) => $greater?$p[$axis] >= $bound:$p[$axis] <= $bound;

	foreach($points as $point)
	{
		if($inside($point) !== $inside($previous))
		{
			$t = ($bound - $previous[$axis]) / ($point[$axis] - $previous[$axis]);
			$intersection = [$previous[0] + $t * ($point[0] - $previous[0])
			, $previous[1] + $t * (
				$point[1] - $previous[1]
			)];

			$intersection[$axis] = $bound;
			$result[] = $intersection;
		}

		if($inside($point))
		{
			$result[] = $point;
		}

		$previous = $point;
	}

	return $result;
}

png(__DIR__ . '/assets/loop.png', 256, 256, static function($x, $y) {
	$distance = hypot($x + .5 - 128, $y + .5 - 128);

	if($distance < 128)
	{
		return [0,0,0,0];
	}

	if($distance < 134)
	{
		return [105,212,157,255];
	}

	return ($x + $y) % 7 === 0?[71,100,105,255]:[42,63,77,255];
});

$loopTiles = [];

for($y = 0; $y < 8; $y++)
{
	for($x = 0; $x < 8; $x++)
	{
		// The four quarter polygons run from an outer corner along the circular hole.
		$quarter = $y < 4?($x < 4?2:3):($x < 4?1:0);
		$polygon = [[$x < 4?0:256,$y < 4?0:256]];

		for($i = 0; $i <= 64; $i++)
		{
			$angle = ($quarter + $i / 64) * M_PI / 2;
			$polygon[] = [round(128 + cos($angle) * 128, 6),round(128 + sin($angle) * 128, 6)];
		}

		foreach([[0,$x * 32,true],[0,($x + 1) * 32,false],[1,$y * 32,true]
		, [1
		, ($y + 1) * 32
			, false
		]] as [
			$axis,$bound,$greater
		]
		){
			if($polygon)
			{
				$polygon = clipLoop($polygon, $axis, $bound, $greater);
			}
		}

		$unique = [];

		foreach($polygon as [$px,$py])
		{
			$point = ['x' => round($px - $x * 32, 6),'y' => round($py - $y * 32, 6)];

			if(!$unique || $point !== $unique[count($unique) - 1])
			{
				$unique[] = $point;
			}
		}

		if(count($unique) > 1 && $unique[0] === $unique[count($unique) - 1])
		{
			array_pop($unique);
		}

		if(count($unique) >= 3)
		{
			$loopTiles[] = ['id' => $y * 8 + $x
			, 'objectgroup' => ['objects' => [['id' => 1
			, 'x' => 0
			, 'y' => 0
				, 'polygon' => $unique
			]]]];
		}
	}
}

jsonAsset(
	'loop.tsj'
	, ['type' => 'tileset', 'name' => 'loop', 'tilewidth' => 32, 'tileheight' => 32
	, 'tilecount' =>
		64
		, 'columns' => 8
		, 'image' => 'loop.png'
		, 'imagewidth' => 256
		, 'imageheight' => 256
		, 'tiles' => $loopTiles
	]
);

$width = 64;
$height = 20;
$planes = array_fill(0, 3, array_fill(0, $width * $height, 0));

// A 256px loop at (768,192), with a shared floor at y=448.
for($y = 14; $y < $height; $y++)
{
	for($x = 0; $x < $width; $x++)
	{
		$planes[0][$y * $width + $x] = 1;
	}
}

for($y = 0; $y < 8; $y++)
{
	for($x = 0; $x < 8; $x++)
	{
		// Common crown, left route on 1, right route on 2, as in moving-boxes-2.
		$plane = $y < 2?0:($x < 4?1:2);
		$planes[$plane][($y + 6) * $width + $x + 24] = 10 + $y * 8 + $x;
	}
}

$loopLayers = $background;

foreach($planes as $plane => $cells)
{
	$loopLayers[] = ['id' => $plane + 1
	, 'name' => 'Collision ' . $plane
	, 'type' => 'tilelayer'
	, 'width' =>
		$width
		, 'height' => $height,'data' => $cells
		, 'properties' => [['name' => 'collisionLayer'
		, 'type' => 'int','value' => $plane
		]]
	] + $layerDefaults;
}

$gates = [];

foreach([['Left entrance',704,176,32,288,false],['Crown',880,168,32,64,true]
, ['Right entrance'
, 1056
	, 176,32,288,false
]] as $i => [$name,$x,$y,$w,$h,$back]
){
	$gates[] = ['id' => $i + 1
	, 'name' => $name
	, 'class' => 'layer-switch'
	, 'x' => $x
	, 'y' => $y
	, 'width' =>
		$w
		, 'height' => $h
		, 'properties' => [['name' => 'back','type' => 'bool','value' => $back]]
	];
}

$loopLayers[] = ['id' => 4,'name' => 'Layer switches','type' => 'objectgroup'
, 'objects' =>
	$gates
] + $layerDefaults;

jsonAsset(
	'loop.tmj'
	, ['type' => 'map', 'version' => '1.10', 'orientation' => 'orthogonal'
	, 'renderorder' =>
		'right-down'
		, 'width' => $width
		, 'height' => $height
		, 'tilewidth' => 32
		, 'tileheight' => 32
		, 'infinite' => false
		, 'tilesets' => [['firstgid' => 1,'source' => 'tiles.tsj']
		, [
		'firstgid' => 10,'source' => 'loop.tsj'
		]]
		, 'layers' => $loopLayers
	]
);
