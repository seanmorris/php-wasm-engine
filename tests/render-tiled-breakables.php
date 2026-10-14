<?php
require dirname(__DIR__) . '/autoload.php';
require dirname(__DIR__) . '/examples/breakables.php';

use PhpWasmEngine\Asset\Assets;
use PhpWasmEngine\Engine;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Render\Atlas;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\Device;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Render\SpriteRenderer;
use PhpWasmEngine\Tiled\Loader;

$device = new Device(160, 160);
$engine = new Engine($device);
$camera = new Camera(0, 0, 160, 160);
$colors = ['ff0000ff', '00ff00ff', '0000ffff', 'ffff00ff'];
$expected = [[0, 1, 2, 3], [2, 3, 0, 1], [1, 0, 3, 2], [3, 2, 1, 0]
	, [0, 2, 1, 3], [1, 3, 0, 2], [2, 0, 3, 1], [3, 1, 2, 0]
];

$checks = [];

foreach([0, 90] as $rotation)
{
	foreach([0, 4, 8, 12, 2, 6, 10, 14] as $index => $flags)
	{
		$data = [
			'orientation' => 'orthogonal'
			, 'width' => 1
			, 'height' => 1
			, 'tilewidth' => 16
			, 'tileheight' => 16
			, 'tilesets' => [[
				'firstgid' => 1
				, 'tilewidth' => 16
				, 'tileheight' => 16
				, 'columns' => 1
				, 'tilecount' => 1
				, 'image' => dirname(__DIR__) . '/examples/assets/test.png'
			]]
			, 'layers' => [[
				'id' => 1
				, 'type' => 'objectgroup'
				, 'offsetx' => 3
				, 'offsety' => 5
				, 'objects' => [[
					'class' => 'breakable'
					, 'gid' => 1 + $flags * 268435456.0
					, 'x' => 60
					, 'y' => 74
					, 'width' => 48
					, 'height' => 32
					, 'rotation' => $rotation
					, 'properties' => [['name' => 'columns', 'value' => 3]
						, ['name' => 'rows', 'value' => 4]
					]
				]]
			]]
		];

		$loader = new Loader(new Assets(static fn($path) => json_encode($data)));
		$map = $loader->map('blocks.tmj');
		$map->x = 7;
		$map->y = 11;
		$blocks = configureBreakables($engine, new Terrain, $map, "$rotation-$flags-");
		$atlas = new Atlas($map);
		$renderer = new SpriteRenderer($atlas);
		$device->begin([0, 0, 0, 1]);
		$renderer->renderEntities($engine->entities, $camera);
		$whole = glReadPixels(0, 0, 160, 160, GL_RGBA, GL_UNSIGNED_BYTE);
		$cos = cos(deg2rad($rotation));
		$sin = sin(deg2rad($rotation));

		foreach([[12, -24], [36, -24], [12, -8], [36, -8]] as $corner => [$x, $y])
		{
			$px = (int)round(70 + $x * $cos - $y * $sin);
			$py = (int)round(90 + $x * $sin + $y * $cos);
			$pixel = bin2hex(glReadPixels($px, 159 - $py, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE));
			$checks["orientation $rotation $flags $corner"] = $pixel === $colors[$expected[$index][
				$corner
			]];
		}

		$id = $engine->entities->query(Sprite::class)[0];
		$blocks->shatter($id);
		$device->begin([0, 0, 0, 1]);
		$renderer->renderEntities($engine->entities, $camera);

		$checks["partition $rotation $flags"] = $whole === glReadPixels(0, 0, 160, 160, GL_RGBA, GL_UNSIGNED_BYTE);
		$checks["batch $rotation $flags"] = $renderer->instances === 12 && $renderer->draws === 1;
		$blocks->close();
		$renderer->close();
		$atlas->close();
	}
}

$error = glGetError();
$engine->input->close();
$device->close();
echo json_encode(compact('checks', 'error'));
