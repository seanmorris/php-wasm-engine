<?php
require dirname(__DIR__).'/autoload.php';

use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Effects\ParticleBurst;
use PhpWasmEngine\Render\Atlas;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\Device;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Render\SpriteRenderer;
use PhpWasmEngine\Render\SpriteSheet;
use PhpWasmEngine\Tiled\Loader;

$device = new Device(96, 96);
$camera = new Camera(0, 0, 96, 96);
$sheet = new SpriteSheet(new Loader, __DIR__.'/assets/shatter.tsj');
$atlas = new Atlas($sheet->map);
$renderer = new SpriteRenderer($atlas);
$registry = new Registry;
$texture = $atlas->texture;
$checks = [];

$capture =
	/**
	 * Read the entire drawing buffer to compare whole sprites and tiled particle regions.
	 *
	 * @return string Raw RGBA pixels in framebuffer order.
	 */
	static function(): string {
		return glReadPixels(0, 0, 96, 96, GL_RGBA, GL_UNSIGNED_BYTE);
	};

/**
 * Compare rotated quads while allowing subpixel coverage differences at texel boundaries.
 *
 * @param string $whole Pixels from the original quad.
 * @param string $pieces Pixels from the partitioned grid.
 * @param Sprite $sprite Original sprite pose and crop defining the source pixel grid.
 * @return bool True for identical pixels, allowing an eighth-pixel edge band after rotation.
 */
function samePartition(string $whole, string $pieces, Sprite $sprite): bool
{
	if($sprite->rotation !== .37)
	{
		return $whole === $pieces;
	}

	// Splitting triangles changes subpixel rasterization and nearest-neighbor decisions.
	// Check every interior pixel exactly; only source texel boundaries get a tolerance.
	$cos = cos($sprite->rotation);
	$sin = sin($sprite->rotation);
	[, , $width, $height] = $sprite->crop;

	for($pixel = 0; $pixel < 96 * 96; $pixel++)
	{
		if(substr($whole, $pixel * 4, 4) === substr($pieces, $pixel * 4, 4))
		{
			continue;
		}

		$x = $pixel % 96 + .5 - $sprite->x;
		$y = 96 - intdiv($pixel, 96) - .5 - $sprite->y;
		$u = ($x * $cos + $y * $sin) / $sprite->scaleX + $sprite->originX * $width;
		$v = (-$x * $sin + $y * $cos) / $sprite->scaleY + $sprite->originY * $height;

		$distance = min(
			abs(($u - round($u)) * $sprite->scaleX)
			, abs(($v - round($v)) * $sprite->scaleY)
		);

		if($distance > .125)
		{
			return false;
		}
	}

	return true;
}

foreach([0, M_PI / 2, .37] as $rotation)
{
	foreach([1, -1] as $scale)
	{
		for($flags = 0; $flags < 4; $flags++)
		{
			$sprite = new Sprite(1, 48, 48, .35, .8);
			// Compare the underlying image partition without per-piece outline borders.
			$sprite->outline = false;
			$sprite->crop = [1, 2, 13, 11];
			$sprite->rotation = $rotation;
			$sprite->scaleX = 3 * $scale;
			$sprite->scaleY = 2;
			$sprite->flipX = (bool)($flags & 1);
			$sprite->flipY = (bool)($flags & 2);

			$sprite->tint = [.5, .75, 1, .8];
			$sprite->opacity = .75;

			$device->begin([0, 0, 0, 1]);
			$renderer->render([$sprite], $camera);
			$whole = $capture();

			$burst = ParticleBurst::grid($sprite, $sheet->map, 3, 4);
			$id = $registry->create($burst->sprites);

			$device->begin([0, 0, 0, 1]);
			$renderer->renderEntities($registry, $camera);
			$key = "$rotation $scale $flags";
			$partition = $capture();
			$checks['image partition '.$key] = samePartition($whole, $partition, $sprite);

			$checks['one shared batch '.$key] = $renderer->draws === 1
				&& $renderer->instances === 12 && $atlas->texture === $texture;

			$registry->destroy($id);
		}
	}
}

$burst->step(.15);
$id = $registry->create($burst->sprites);
$primary = new Sprite(1, 8, 24);
$registry->create($primary);

$device->begin([0, 0, 0, 1]);
$renderer->render([...$burst->sprites->sprites, $primary], $camera);
$expected = $capture();

$device->begin([0, 0, 0, 1]);
$renderer->renderEntities($registry, $camera);
$checks['mixed entity rendering'] = $expected === $capture() && $renderer->instances === 13;

$atlas->restore();
$renderer->restore();

$device->begin([0, 0, 0, 1]);
$renderer->renderEntities($registry, $camera);
$checks['restored moving crops'] = $expected === $capture();

$renderer->close();
$checks['borrowed texture survives close'] = (bool)glIsTexture($atlas->texture);
$atlas->close();

$sheet = new SpriteSheet(new Loader, __DIR__.'/assets/sprites.tsj');
$atlas = new Atlas($sheet->map);
$renderer = new SpriteRenderer($atlas);
$sprite = $sheet->sprite('run', 40, 40);
$sprite->outline = false;
$sprite->scaleX = $sprite->scaleY = 4;

$device->begin([0, 0, 0, 1]);
$renderer->render([$sprite], $camera, 150);
$expected = $capture();

$burst = ParticleBurst::grid($sprite, $sheet->map, 3, 2, 150);

$device->begin([0, 0, 0, 1]);
$renderer->render($burst->sprites->sprites, $camera, 0);
$checks['frozen animated frame'] = $expected === $capture();

$renderer->renderEntities(new Registry, $camera);
$checks['empty batch clears statistics'] = $renderer->draws === 0 && $renderer->instances === 0;

$renderer->close();
$atlas->close();
$error = glGetError();
$device->close();
echo json_encode(compact('checks', 'error'));
