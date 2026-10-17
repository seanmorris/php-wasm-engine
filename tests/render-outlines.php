<?php
require dirname(__DIR__).'/autoload.php';

use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Render\Atlas;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\Device;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Render\SpriteGroup;
use PhpWasmEngine\Render\SpriteRenderer;
use PhpWasmEngine\Render\SpriteSheet;
use PhpWasmEngine\Tiled\Loader;

// The PNG contains these colored rectangles and a fully green adjacent animation frame.
$regions = [
	[0, 0, 6, 2, [255, 0, 0, 255]], [0, 2, 2, 6, [255, 0, 0, 255]]
	, [4, 4, 4, 4, [0, 0, 255, 255]], [11, 11, 1, 1, [0, 255, 0, 255]]
	, [14, 2, 2, 3, [255, 0, 255, 128]]
];
$green = [[0, 0, 16, 16, [0, 255, 0, 255]]];
$device = new Device(96, 96);
$camera = new Camera(0, 0, 96, 96);
$sheet = new SpriteSheet(new Loader, __DIR__.'/assets/outline.tsj');
$atlas = new Atlas($sheet->map);
$renderer = new SpriteRenderer($atlas);
$texture = $atlas->texture;
$checks = [];
$differences = [];

/**
 * Evaluate colored rectangles and their one-world-pixel expansion without sampling a texture.
 *
 * @param Sprite $sprite Pose, crop, tint, opacity, and outline setting to evaluate.
 * @param Camera $camera Transform from the framebuffer pixel to world coordinates.
 * @param array $regions Disjoint source rectangles containing byte RGBA colors.
 * @param float $x Framebuffer X coordinate measured from the left, before the half-pixel offset.
 * @param float $y Framebuffer Y coordinate measured from the top, before the half-pixel offset.
 * @return array Four normalized straight-alpha channels before framebuffer blending.
 */
function outlineSample(Sprite $sprite, Camera $camera, array $regions, float $x, float $y): array
{
	if($sprite->scaleX == 0 || $sprite->scaleY == 0)
	{
		return [0, 0, 0, 0];
	}

	[$left, $top, $width, $height] = $sprite->crop ?? [0, 0, 16, 16];
	$dx = ($x + .5) / $camera->zoom + $camera->x - $sprite->x;
	$dy = ($y + .5) / $camera->zoom + $camera->y - $sprite->y;
	$cos = cos($sprite->rotation);
	$sin = sin($sprite->rotation);
	$u = ($dx * $cos + $dy * $sin) / $sprite->scaleX + $sprite->originX * $width;
	$v = (-$dx * $sin + $dy * $cos) / $sprite->scaleY + $sprite->originY * $height;
	$u = $left + ($sprite->flipX ? $width - $u : $u);
	$v = $top + ($sprite->flipY ? $height - $v : $v);
	$pixel = [0, 0, 0, 0];
	$alpha = 0;

	foreach($regions as [$rx, $ry, $rw, $rh, $color])
	{
		$x1 = max($left, $rx);
		$y1 = max($top, $ry);
		$x2 = min($left + $width, $rx + $rw);
		$y2 = min($top + $height, $ry + $rh);

		if($x1 >= $x2 || $y1 >= $y2)
		{
			continue;
		}

		if($u >= $x1 && $u < $x2 && $v >= $y1 && $v < $y2)
		{
			$pixel = $color;
		}

		$paddingX = 1 / abs($sprite->scaleX);
		$paddingY = 1 / abs($sprite->scaleY);

		if(($sprite->outline ?? true) && $u >= $x1 - $paddingX && $u < $x2 + $paddingX
			&& $v >= $y1 - $paddingY && $v < $y2 + $paddingY
		){
			$alpha = max($alpha, $color[3]);
		}
	}

	if($pixel[3] === 0)
	{
		$pixel = [0, 0, 0, $alpha];
	}

	return [$pixel[0] / 255 * $sprite->tint[0], $pixel[1] / 255 * $sprite->tint[1]
		, $pixel[2] / 255 * $sprite->tint[2]
		, $pixel[3] / 255 * $sprite->tint[3] * $sprite->opacity
	];
}

/**
 * Composite the independently expanded regions over an opaque white background.
 *
 * @param list<array{Sprite,array}> $layers Sprite poses paired with source frame regions.
 * @param Camera $camera Viewport transform used for the draw.
 * @param float $x Framebuffer X coordinate, allowing subpixel rasterization comparisons.
 * @param float $y Framebuffer Y coordinate, allowing subpixel rasterization comparisons.
 * @return list<int> Byte RGBA channels with the device's separate-alpha blending rule.
 */
function outlineComposite(array $layers, Camera $camera, float $x, float $y): array
{
	$expected = [1, 1, 1, 1];

	foreach($layers as [$sprite, $regions])
	{
		$color = outlineSample($sprite, $camera, $regions, $x, $y);

		foreach($color as $channel => $value)
		{
			$expected[$channel] = $value * ($channel === 3 ? 1 : $color[3])
				+ $expected[$channel] * (1 - $color[3]);
		}
	}

	return array_map(static fn($value) => (int)round($value * 255), $expected);
}

/**
 * Allow only the single-byte rounding difference in fixed-point framebuffer blending.
 *
 * @param list<int> $expected Reference RGBA bytes.
 * @param list<int> $actual GPU framebuffer RGBA bytes.
 * @return bool True when every channel differs by at most one byte value.
 */
function outlineColorMatches(array $expected, array $actual): bool
{
	foreach($actual as $channel => $value)
	{
		if(abs($expected[$channel] - $value) > 1)
		{
			return false;
		}
	}

	return true;
}

/**
 * Compare the framebuffer with independently expanded source rectangles in painter order.
 *
 * @param string $pixels Raw bottom-up RGBA framebuffer bytes.
 * @param list<array{Sprite,array}> $layers Sprite poses paired with their current frame regions.
 * @param Camera $camera Viewport transform used for the GPU draw.
 * @return array Up to eight mismatched pixel coordinates and expected/actual channel values.
 */
function outlineDifferences(string $pixels, array $layers, Camera $camera): array
{
	$differences = [];
	$rotated = false;

	foreach($layers as [$sprite])
	{
		$rotated = $rotated || abs(sin($sprite->rotation * 2)) > 1e-6;
	}

	for($y = 0; $y < 96; $y++)
	{
		for($x = 0; $x < 96; $x++)
		{
			$actual = array_values(unpack('C4', substr($pixels, ((95 - $y) * 96 + $x) * 4, 4)));
			$expected = outlineComposite($layers, $camera, $x, $y);
			$matches = outlineColorMatches($expected, $actual);

			// Triangle vertices snap to a subpixel grid. At arbitrary rotations, permit a
			// 1/64-screen-pixel phase shift, while checking every interior pixel exactly.
			if(!$matches && $rotated)
			{
				foreach([-1 / 64, 0, 1 / 64] as $dy)
				{
					foreach([-1 / 64, 0, 1 / 64] as $dx)
					{
						$nearby = outlineComposite($layers, $camera, $x + $dx, $y + $dy);
						$matches = $matches || outlineColorMatches($nearby, $actual);
					}
				}
			}

			if(!$matches)
			{
				$differences[] = [$x, $y, $expected, $actual];
			}

			if(count($differences) === 8)
			{
				return $differences;
			}
		}
	}

	return $differences;
}

$cases = [
	'default' => []
	, 'opt-out' => ['outline' => false]
	, 'upscaled' => ['scaleX' => 3, 'scaleY' => 2]
	, 'negative scale' => ['scaleX' => -2, 'scaleY' => -1]
	, 'quarter turn' => ['rotation' => M_PI / 2, 'scaleX' => 2]
	, 'rotated' => ['rotation' => .37, 'scaleX' => 2, 'scaleY' => .75]
	, 'flipped X' => ['flipX' => true]
	, 'flipped Y' => ['flipY' => true]
	, 'flipped both' => ['flipX' => true, 'flipY' => true]
	, 'cropped' => ['crop' => [3, 2, 7, 7], 'scaleX' => 2, 'flipX' => true]
	, 'empty crop' => ['crop' => [8, 0, 6, 6]]
	, 'faded' => ['opacity' => .5, 'tint' => [.5, .75, .25, .75]]
	, 'hidden' => ['opacity' => 0]
	, 'zero scale' => ['scaleX' => 0]
	, 'zoomed' => ['x' => 16, 'y' => 20]
	, 'zoomed out' => ['x' => 80.25, 'y' => 80.25]
	, 'subpixel source' => ['x' => 20, 'y' => 20, 'originX' => 0, 'originY' => 0
		, 'crop' => [11, 11, 1, 1], 'scaleX' => .5, 'scaleY' => .5
	]
];

foreach($cases as $name => $properties)
{
	$sprite = new Sprite(1, 32, 40, .25, .5);
	$sprite->animated = false;

	foreach($properties as $property => $value)
	{
		$sprite->$property = $value;
	}

	$camera->zoom = $name === 'zoomed out' ? .5
		: (in_array($name, ['zoomed', 'subpixel source'], true) ? 2 : 1);
	$device->begin([1, 1, 1, 1]);
	$renderer->render([$sprite], $camera);
	$pixels = glReadPixels(0, 0, 96, 96, GL_RGBA, GL_UNSIGNED_BYTE);
	$differences[$name] = outlineDifferences($pixels, [[$sprite, $regions]], $camera);
	$checks[$name] = !$differences[$name];
	$checks['batch '.$name] = $renderer->draws === 1 && $renderer->instances === 1
		&& $atlas->texture === $texture;
}

$camera->zoom = 1;
$camera->x = -10;
$camera->y = 5;
$sprite = $sheet->sprite('outline', 20, 32);
$plain = new Sprite(1, 32, 36);
$plain->animated = false;
$plain->outline = false;
$registry = new Registry;
$registry->create($sprite, new SpriteGroup($plain));

foreach([0, 150] as $time)
{
	$device->begin([1, 1, 1, 1]);
	$renderer->renderEntities($registry, $camera, $time);
	$pixels = glReadPixels(0, 0, 96, 96, GL_RGBA, GL_UNSIGNED_BYTE);
	$layers = [[$sprite, $time === 0 ? $regions : $green], [$plain, $regions]];
	$differences['animation '.$time] = outlineDifferences($pixels, $layers, $camera);
	$checks['animation '.$time] = !$differences['animation '.$time];
	$checks['mixed flags '.$time] = $renderer->draws === 1 && $renderer->instances === 2;
}

$atlas->restore();
$renderer->restore();
$device->begin([1, 1, 1, 1]);
$renderer->renderEntities($registry, $camera, 150);
$checks['restored outline and opt-out'] = $pixels
	=== glReadPixels(0, 0, 96, 96, GL_RGBA, GL_UNSIGNED_BYTE);

$renderer->close();
$atlas->close();
$error = glGetError();
$device->close();
echo json_encode(compact('checks', 'differences', 'error'));
