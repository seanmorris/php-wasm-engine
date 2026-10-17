<?php
require dirname(__DIR__).'/autoload.php';

use PhpWasmEngine\Render\Atlas;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\Device;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Render\SpriteRenderer;
use PhpWasmEngine\Render\SpriteSheet;
use PhpWasmEngine\Tiled\Loader;

$device = new Device(64, 32);
$camera = new Camera(0, 0, 64, 32);
$sheet = new SpriteSheet(new Loader, __DIR__.'/assets/sprites.tsj');
$atlas = new Atlas($sheet->map);
$renderer = new SpriteRenderer($atlas);
$checks = $differences = [];
$source = [[255, 0, 0, 255], [0, 255, 0, 128], [0, 0, 255, 0], [255, 255, 255, 255]];

// A colored neighbor around the crop must never enter the filtered image.
glBindTexture(GL_TEXTURE_2D, $atlas->texture);
[$u, $v] = $atlas->rects[1];

glTexSubImage2D(
	GL_TEXTURE_2D, 0, $u + 2, $v + 3, 2, 2, GL_RGBA, GL_UNSIGNED_BYTE
	, pack('C*', ...array_merge(...$source))
);

foreach([8, 1.75, .75] as $scale)
{
	foreach([false, true] as $flip)
	{
		$sprites = [];

		foreach([false, true] as $smooth)
		{
			$sprite = new Sprite(1, $smooth ? 36.25 : 4.25, 4.25, 0, 0);
			$sprite->crop = [2, 3, 2, 2];
			$sprite->scaleX = $sprite->scaleY = $scale;
			$sprite->outline = false;
			$sprite->smooth = $smooth;
			$sprite->flipX = $flip;
			$sprites[] = $sprite;
		}

		$device->begin([16 / 255, 24 / 255, 40 / 255, 1]);
		$renderer->render($sprites, $camera);
		$pixels = glReadPixels(0, 0, 64, 32, GL_RGBA, GL_UNSIGNED_BYTE);
		$key = "$scale ".($flip ? 'flipped' : 'normal');
		$checks[$key.' single mixed batch'] = $renderer->draws === 1 && $renderer->instances === 2;

		foreach($sprites as $sprite)
		{
			$name = $key.($sprite->smooth ? ' smooth' : ' nearest');
			$checks[$name] = true;

			for($y = 3; $y < 22; $y++)
			{
				for($x = (int)$sprite->x - 1; $x < $sprite->x + 18; $x++)
				{
					$px = ($x + .5 - $sprite->x) / $scale;
					$py = ($y + .5 - $sprite->y) / $scale;
					$expected = [16, 24, 40];

					if($px >= 0 && $px < 2 && $py >= 0 && $py < 2)
					{
						$px = $flip ? 2 - $px : $px;
						$samples = [[(int)$px, (int)$py, 1]];

						if($sprite->smooth)
						{
							$px = max(0, min(1, $px - .5));
							$py = max(0, min(1, $py - .5));
							$samples = [
								[0, 0, (1 - $px) * (1 - $py)], [1, 0, $px * (1 - $py)]
								, [0, 1, (1 - $px) * $py], [1, 1, $px * $py]
							];
						}

						$rgb = [0, 0, 0];
						$alpha = 0;

						foreach($samples as [$sx, $sy, $weight])
						{
							$texel = $source[$sy * 2 + $sx];
							$coverage = $texel[3] / 255 * $weight;
							$alpha += $coverage;

							for($channel = 0; $channel < 3; $channel++)
							{
								$rgb[$channel] += $texel[$channel] * $coverage;
							}
						}

						for($channel = 0; $channel < 3; $channel++)
						{
							$expected[$channel] = $rgb[$channel] + $expected[$channel] * (
								1 - $alpha
							);
						}
					}

					$actual = array_values(
						unpack('C*', substr($pixels, ((31 - $y) * 64 + $x) * 4, 4))
					);

					for($channel = 0; $channel < 3; $channel++)
					{
						if(abs($expected[$channel] - $actual[$channel]) > 2)
						{
							$checks[$name] = false;
							$differences[$name] ??= [$x, $y, $expected, $actual];
						}
					}
				}
			}
		}
	}
}

$error = glGetError();
$renderer->close();
$atlas->close();
$device->close();
echo json_encode(compact('checks', 'differences', 'error'));
