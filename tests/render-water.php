<?php
require dirname(__DIR__) . '/autoload.php';

use PhpWasmEngine\Physics\WaterRegion;
use PhpWasmEngine\Physics\WaterRegions;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\Device;
use PhpWasmEngine\Render\RectRenderer;
use PhpWasmEngine\Render\WaterRenderer;

$width = $height = 64;
$device = new Device($width, $height);
$camera = new Camera(0, 0, $width, $height);
$rectangles = [];

for($y = 0; $y < $height; $y += 4)
{
	for($x = 0; $x < $width; $x += 4)
	{
		$color = (($x + $y) / 4) % 2
			? [28 / 255, 198 / 255, 236 / 255, 1]
			: [242 / 255, 88 / 255, 54 / 255, 1];

		$rectangles[] = [$x, $y, 4, 4, ...$color];
	}
}

$water = new WaterRegion(
	0
	, 8
	, 64
	, 24
	, tint: [.08, .48, .72, .24]
	, displacement: 4
	, displacementScale: 64
	, displacementSpeed: 42.6666667
);

$waters = new WaterRegions($water);
$rects = new RectRenderer;
$renderer = new WaterRenderer($width, $height);
$frames = [];
$draws = [];

foreach([[new WaterRegions, 0], [$waters, 0], [$waters, 187.5]] as [$regions, $time])
{
	$renderer->begin([0, 0, 0, 1]);
	$rects->render($rectangles, $width, $height);
	$renderer->compose($regions, $camera, $time);
	$frames[] = glReadPixels(0, 0, $width, $height, GL_RGBA, GL_UNSIGNED_BYTE);
	$draws[] = $renderer->draws;
}

$outsideExact = true;
$wetPixels = $animatedPixels = 0;

for($y = 0; $y < $height; $y++)
{
	for($x = 0; $x < $width; $x++)
	{
		$offset = (($height - 1 - $y) * $width + $x) * 4;
		$baseline = substr($frames[0], $offset, 4);
		$initial = substr($frames[1], $offset, 4);
		$moved = substr($frames[2], $offset, 4);

		if($y < 8 || $y >= 32)
		{
			$outsideExact = $outsideExact && $baseline === $initial && $initial === $moved;
		}
		else
		{
			$wetPixels += $baseline !== $initial ? 1 : 0;
			$animatedPixels += $initial !== $moved ? 1 : 0;
		}
	}
}

$sceneTexture = (new ReflectionProperty($renderer, 'sceneTexture'))->getValue($renderer);
$mapTexture = (new ReflectionProperty($renderer, 'displacementTexture'))->getValue($renderer);
$depthBuffer = (new ReflectionProperty($renderer, 'depthBuffer'))->getValue($renderer)[0];
$live = [glIsTexture($sceneTexture), glIsTexture($mapTexture), glIsRenderbuffer($depthBuffer)];
$error = glGetError();
$renderer->close();
$rects->close();
$released = [!glIsTexture($sceneTexture), !glIsTexture($mapTexture), !glIsRenderbuffer($depthBuffer)];
$device->close();

echo json_encode(compact('draws', 'outsideExact', 'wetPixels', 'animatedPixels', 'live', 'released', 'error'));
