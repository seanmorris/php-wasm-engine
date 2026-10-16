<?php
require dirname(__DIR__) . '/autoload.php';

use PhpWasmEngine\Render\Atlas;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\Device;
use PhpWasmEngine\Render\SpriteRenderer;
use PhpWasmEngine\Render\SpriteSheet;
use PhpWasmEngine\Tiled\Loader;

$device = new Device(96, 32);
$camera = new Camera(0, 0, 96, 32);
$sheet = new SpriteSheet(new Loader, __DIR__ . '/assets/sprites.tsj');
$atlas = new Atlas($sheet->map);
$renderer = new SpriteRenderer($atlas);
$pixels = '';

for($y = 0; $y < 8; $y++)
{
	for($x = 0; $x < 8; $x++)
	{
		$color = $x < 3
			? [255, 64, 32, 255]
			: ($y < 3 ? [64, 255, 96, 255] : [48, 96, 255, 255]);

		$pixels .= pack('C*', ...$color);
	}
}

glBindTexture(GL_TEXTURE_2D, $atlas->texture);
[$u, $v] = $atlas->rects[1];
glTexSubImage2D(GL_TEXTURE_2D, 0, $u, $v, 8, 8, GL_RGBA, GL_UNSIGNED_BYTE, $pixels);

$sprites = [];

foreach([[16, 0], [48, 256], [80, -256]] as [$x, $twist])
{
	$sprite = $sheet->sprite('idle', $x, 16);
	$sprite->originX = $sprite->originY = .5;
	$sprite->scaleX = $sprite->scaleY = 2;
	$sprite->outline = false;
	$sprite->twist = $twist;
	$sprites[] = $sprite;
}

/**
 * Read the same square around one test sprite from the current framebuffer.
 *
 * @param int $center Horizontal center of the sprite in output pixels.
 * @return string Raw RGBA pixels covering the expanded displacement field.
 */
$capture = static fn($center) => glReadPixels($center - 14, 2, 28, 28, GL_RGBA, GL_UNSIGNED_BYTE);

/**
 * Render the three strengths together and capture their identically sized output regions.
 *
 * @return list<string> Raw plain, positive, and negative displacement pixel buffers.
 */
$render = function() use($device, $renderer, $sprites, $camera, $capture) {
	$device->begin([0, 0, 0, 1]);
	$renderer->render($sprites, $camera);

	return array_map($capture, [16, 48, 80]);
};

$first = $render();
$checks = [
	'one displaced batch' => $renderer->draws === 1 && $renderer->instances === 3
	, 'positive displacement changes pixels' => $first[0] !== $first[1]
	, 'negative displacement changes pixels' => $first[0] !== $first[2]
	, 'signed displacement changes direction' => $first[1] !== $first[2]
];

$atlas->restore();
glBindTexture(GL_TEXTURE_2D, $atlas->texture);
[$u, $v] = $atlas->rects[1];
glTexSubImage2D(GL_TEXTURE_2D, 0, $u, $v, 8, 8, GL_RGBA, GL_UNSIGNED_BYTE, $pixels);
$renderer->restore();
$checks['restored displacement'] = $first === $render();
$renderer->close();
$atlas->close();
$error = glGetError();
$device->close();
echo json_encode(compact('checks', 'error'));
