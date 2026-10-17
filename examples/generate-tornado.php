<?php
declare(strict_types=1);

/**
 * Encode one PNG chunk for the generated aircraft artwork.
 *
 * @param string $type Four-character PNG chunk type.
 * @param string $data Raw chunk payload.
 * @return string Encoded length, type, payload, and checksum.
 */
function tornadoPngChunk(string $type, string $data): string
{
	return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/**
 * Generate one original aircraft state as a transparent RGBA PNG.
 *
 * @param string $path Destination path.
 * @param string $state Ground, flight, gear, thrust, or crashed presentation.
 * @return void Writes a deterministic ninety-six by forty-eight image.
 */
function tornadoPng(string $path, string $state): void
{
	$bytes = '';

	for($y = 0; $y < 48; $y++)
	{
		$bytes .= "\0";

		for($x = 0; $x < 96; $x++)
		{
			$color = [0, 0, 0, 0];
			$dark = $state === 'crashed' ? [76, 66, 70, 255] : [53, 67, 92, 255];
			$red = $state === 'crashed' ? [145, 74, 68, 255] : [221, 65, 71, 255];
			$cream = [246, 226, 164, 255];
			$blue = [89, 191, 226, 255];

			if($x >= 14 && $x <= 82 && $y >= 20 && $y <= 34
				&& hypot(($x - 50) / 42, ($y - 27) / 10) <= 1
			){
				$color = $y < 24 ? $cream : $red;
			}

			if($x >= 34 && $x <= 61 && $y >= 12 && $y <= 23
				&& hypot(($x - 48) / 16, ($y - 21) / 11) <= 1
			){
				$color = $blue;
			}

			if($x >= 37 && $x <= 58 && $y >= 18 && $y <= 20) $color = $dark;

			if($x >= 42 && $x <= 70 && $y >= 31 && $y <= 38) $color = $red;

			if($x >= 71 && $x <= 86 && $y >= 11 && $y <= 29) $color = $red;

			if($x >= 76 && $x <= 89 && $y >= 9 && $y <= 13) $color = $cream;

			if($x >= 5 && $x <= 17 && $y >= 23 && $y <= 32) $color = $dark;

			$propeller = ($state === 'flight' || $state === 'thrust') ? 3 : 1;

			if(abs($x - 8) <= $propeller && $y >= 10 && $y <= 42) $color = [215, 229, 236, 210];

			if($x >= 8 && $x <= 14 && $y >= 25 && $y <= 28) $color = $cream;

			if(in_array($state, ['ground', 'gear'], true))
			{
				if(($x >= 27 && $x <= 30 || $x >= 68 && $x <= 71) && $y >= 34 && $y <= 41) $color = $dark;

				if((hypot($x - 28, $y - 42) < 4 || hypot($x - 70, $y - 42) < 4)) $color = [30, 34, 45, 255];
			}

			if($state === 'thrust' && $x >= 83 && $y >= 23 && $y <= 31)
			{
				$length = 95 - $x;
				$color = abs($y - 27) <= max(1, $length / 4)
					? ($x > 90 ? [255, 103, 48, 230] : [255, 226, 103, 255])
					: $color;
			}

			if($state === 'crashed' && (($x + $y) % 13 === 0 || ($x - $y) % 17 === 0)
				&& $x >= 20 && $x <= 80 && $y >= 14 && $y <= 37
			){
				$color = [32, 35, 43, 255];
			}

			$bytes .= pack('C*', ...$color);
		}
	}

	file_put_contents(
		$path
		, "\x89PNG\r\n\x1a\n" . tornadoPngChunk('IHDR', pack('NNC5', 96, 48, 8, 6, 0, 0, 0))
			. tornadoPngChunk('IDAT', gzcompress($bytes)) . tornadoPngChunk('IEND', '')
	);
}

$assets = __DIR__ . '/assets/';
$states = [
	'tornado' => 'ground'
	, 'tornado-flight' => 'flight'
	, 'tornado-gear' => 'gear'
	, 'tornado-thrust' => 'thrust'
	, 'tornado-crashed' => 'crashed'
];

$tiles = [];

foreach($states as $class => $state)
{
	$name = $class . '.png';
	tornadoPng($assets . $name, $state);
	$tiles[] = [
		'id' => count($tiles)
		, 'class' => $class
		, 'image' => $name
		, 'imagewidth' => 96
		, 'imageheight' => 48
	];
}

$tileset = [
	'type' => 'tileset'
	, 'version' => '1.10'
	, 'name' => 'tornado'
	, 'tilewidth' => 96
	, 'tileheight' => 48
	, 'columns' => 0
	, 'tilecount' => count($tiles)
	, 'tiles' => $tiles
];

$base = json_decode(file_get_contents($assets . 'platformer.tmj'), true, 512, JSON_THROW_ON_ERROR);
$backgrounds = [];

foreach($base['layers'] as $layer)
{
	if($layer['type'] !== 'imagelayer') continue;

	$layer['id'] = count($backgrounds) + 1;
	$backgrounds[] = $layer;
}

$width = 320;
$height = 96;
$terrain = $rings = array_fill(0, $width * $height, 0);

/**
 * Fill an inclusive tile rectangle inside one generated layer.
 *
 * @param array<int,int> $data Tile GID layer updated by reference.
 * @param int $width Map width in tiles.
 * @param int $x1 Inclusive left tile.
 * @param int $y1 Inclusive top tile.
 * @param int $x2 Inclusive right tile.
 * @param int $y2 Inclusive bottom tile.
 * @param int $gid Tile GID to place.
 * @return void Clips and fills the requested rectangle.
 */
function tornadoRect(array &$data, int $width, int $x1, int $y1, int $x2, int $y2, int $gid): void
{
	for($y = max(0, $y1); $y <= min(95, $y2); $y++)
	for($x = max(0, $x1); $x <= min(319, $x2); $x++) $data[$y * $width + $x] = $gid;
}

// A ten-thousand-pixel course: runway, climb gate, tunnel, slalom, landing shelf, and return runway.
tornadoRect($terrain, $width, 0, 88, 319, 95, 1);
tornadoRect($terrain, $width, 0, 84, 42, 87, 1);
tornadoRect($terrain, $width, 66, 51, 72, 87, 1);
tornadoRect($terrain, $width, 104, 0, 138, 14, 1);
tornadoRect($terrain, $width, 104, 77, 138, 87, 1);
tornadoRect($terrain, $width, 160, 46, 166, 87, 1);
tornadoRect($terrain, $width, 195, 0, 201, 54, 1);
tornadoRect($terrain, $width, 226, 57, 253, 61, 1);
tornadoRect($terrain, $width, 274, 82, 319, 87, 1);

$route = [];

for($x = 12; $x <= 60; $x += 4) $route[] = [$x, max(38, 78 - intdiv($x, 2))];

for($x = 76; $x <= 98; $x += 4) $route[] = [$x, 38 + (($x / 4) % 2) * 9];

for($x = 110; $x <= 132; $x += 4) $route[] = [$x, 42];

for($x = 142; $x <= 218; $x += 4) $route[] = [$x, 28 + (int)(18 * sin($x / 10))];

for($x = 232; $x <= 264; $x += 4) $route[] = [$x, 50];

for($x = 278; $x <= 314; $x += 4) $route[] = [$x, 76];

foreach($route as [$x, $y]) $rings[$y * $width + $x] = 5;

$layers = $backgrounds;
$layers[] = [
	'id' => 7
	, 'name' => 'Flight terrain'
	, 'type' => 'tilelayer'
	, 'width' => $width
	, 'height' => $height
	, 'data' => $terrain
	, 'opacity' => 1
	, 'visible' => true
	, 'x' => 0
	, 'y' => 0
];

$layers[] = [
	'id' => 8
	, 'name' => 'Flight route rings'
	, 'type' => 'tilelayer'
	, 'width' => $width
	, 'height' => $height
	, 'data' => $rings
	, 'properties' => [['name' => 'collision', 'type' => 'bool', 'value' => false]]
	, 'opacity' => 1
	, 'visible' => true
	, 'x' => 0
	, 'y' => 0
];

$layers[] = [
	'id' => 9
	, 'name' => 'Aircraft and player'
	, 'type' => 'objectgroup'
	, 'objects' => [
		['id' => 1, 'class' => 'Player', 'x' => 176, 'y' => 2580, 'point' => true]
		, [
			'id' => 2
			, 'class' => 'Tornado'
			, 'name' => 'Tornado flight trainer'
			, 'x' => 128
			, 'y' => 2688
			, 'width' => 96
			, 'height' => 48
			, 'gid' => 38
			, 'properties' => [
				['name' => 'lockedIn', 'type' => 'bool', 'value' => true]
				, ['name' => 'seatHeight', 'type' => 'float', 'value' => 19]
				, ['name' => 'seatForward', 'type' => 'float', 'value' => -12]
			]
		]
	]
	, 'opacity' => 1
	, 'visible' => true
	, 'x' => 0
	, 'y' => 0
];

$map = [
	'type' => 'map'
	, 'version' => '1.10'
	, 'orientation' => 'orthogonal'
	, 'renderorder' => 'right-down'
	, 'width' => $width
	, 'height' => $height
	, 'tilewidth' => 32
	, 'tileheight' => 32
	, 'infinite' => false
	, 'tilesets' => [
		['firstgid' => 1, 'source' => 'tiles.tsj']
		, ['firstgid' => 38, 'source' => 'tornado.tsj']
	]
	, 'layers' => $layers
	, 'nextobjectid' => 3
	, 'nextlayerid' => 10
];

foreach(['tornado.tsj' => $tileset, 'tornado.tmj' => $map] as $name => $data)
{
	file_put_contents($assets . $name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
