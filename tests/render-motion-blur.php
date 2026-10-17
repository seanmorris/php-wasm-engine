<?php
require dirname(__DIR__) . '/autoload.php';

use PhpWasmEngine\Render\Atlas;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\Device;
use PhpWasmEngine\Render\MotionBlurRenderer;
use PhpWasmEngine\Render\RectRenderer;
use PhpWasmEngine\Render\SpriteRenderer;
use PhpWasmEngine\Render\SpriteSheet;
use PhpWasmEngine\Tiled\Loader;

$width = 96;
$height = 64;
$device = new Device($width, $height);
$camera = new Camera(0, 0, $width, $height);
$rects = new RectRenderer;
$renderer = new MotionBlurRenderer($width, $height);
$sheet = new SpriteSheet(new Loader, __DIR__ . '/assets/sprites.tsj');
$atlas = new Atlas($sheet->map);
$spriteRenderer = new SpriteRenderer($atlas);
$foreground = $sheet->sprite('idle', 48, 32);
$foreground->originX = $foreground->originY = .5;
$foreground->scaleX = $foreground->scaleY = 2;
$foreground->outline = false;
$background = [];

for($y = 0; $y < $height; $y += 2)
{
	for($x = 0; $x < $width; $x += 2)
	{
		$color = (($x + $y) / 2) % 2
			? [28 / 255, 198 / 255, 236 / 255, 1]
			: [242 / 255, 88 / 255, 54 / 255, 1];

		$background[] = [$x, $y, 2, 2, ...$color];
	}
}

/**
 * Render a checkerboard through directional blur before adding an opaque foreground sprite.
 *
 * @param float $sigma Gaussian standard deviation in output pixels.
 * @param float $directionX Horizontal normalized direction component.
 * @param float $directionY Vertical normalized direction component.
 * @param int $target Framebuffer receiving the completed terrain and foreground.
 * @return array{pixels:string,draws:int,binding:int} Raw framebuffer pixels and renderer state.
 */
$render = function(
	float $sigma
	, float $directionX
	, float $directionY
	, int $target = 0
) use(
	$width
	, $height
	, $camera
	, $background
	, $foreground
	, $rects
	, $renderer
	, $spriteRenderer
): array {
	glBindFramebuffer(GL_FRAMEBUFFER, $target);
	glViewport(0, 0, $width, $height);
	glClearColor(0, 0, 0, 1);
	glClear(GL_COLOR_BUFFER_BIT);
	$renderer->begin([0, 0, 0, 1]);
	$rects->render($background, $width, $height);
	$renderer->compose($sigma, $directionX, $directionY);
	$spriteRenderer->render([$foreground], $camera);

	return [
		'pixels' => glReadPixels(0, 0, $width, $height, GL_RGBA, GL_UNSIGNED_BYTE)
		, 'draws' => $renderer->draws
		, 'binding' => glGetIntegerv(GL_FRAMEBUFFER_BINDING)
	];
};

$plain = $render(0, 0, 0);
$horizontal = $render(7, 1, 0);
$vertical = $render(7, 0, 1);
$diagonal = $render(7, 1 / sqrt(2), 1 / sqrt(2));
$spriteExact = true;
$horizontalChanges = $verticalChanges = $directionChanges = 0;

for($y = 0; $y < $height; $y++)
{
	for($x = 0; $x < $width; $x++)
	{
		$offset = (($height - 1 - $y) * $width + $x) * 4;
		$base = substr($plain['pixels'], $offset, 4);
		$across = substr($horizontal['pixels'], $offset, 4);
		$down = substr($vertical['pixels'], $offset, 4);
		$angle = substr($diagonal['pixels'], $offset, 4);
		$spritePixel = $x >= 40 && $x < 56 && $y >= 24 && $y < 40;

		if($spritePixel)
		{
			$spriteExact = $spriteExact
				&& $base === "\xff\x00\x00\xff"
				&& $across === $base
				&& $down === $base
				&& $angle === $base;
		}
		else
		{
			$horizontalChanges += $base !== $across ? 1 : 0;
			$verticalChanges += $base !== $down ? 1 : 0;
			$directionChanges += $across !== $angle ? 1 : 0;
		}
	}
}

glGenFramebuffers(1, $parentFramebuffer);
$parentTexture = Atlas::texture(GL_TEXTURE_2D);
glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $width, $height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
glBindFramebuffer(GL_FRAMEBUFFER, $parentFramebuffer[0]);
glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $parentTexture, 0);
$parentComplete = glCheckFramebufferStatus(GL_FRAMEBUFFER) === GL_FRAMEBUFFER_COMPLETE;
$nested = $render(7, 1, 0, $parentFramebuffer[0]);
$nestedExact = $nested['pixels'] === $horizontal['pixels'];
$nestedRestored = $nested['binding'] === $parentFramebuffer[0];
glBindFramebuffer(GL_FRAMEBUFFER, 0);

$renderer->restore();
$restored = $render(7, 1, 0);
$restoredExact = $restored['pixels'] === $horizontal['pixels'];
$sceneTexture = (new ReflectionProperty($renderer, 'sceneTexture'))->getValue($renderer);
$sceneFramebuffer = (new ReflectionProperty($renderer, 'framebuffer'))->getValue($renderer)[0];
$live = [glIsTexture($sceneTexture), glIsFramebuffer($sceneFramebuffer)];
$checks = [
	'zero sigma copies terrain in one draw' => $plain['draws'] === 1
	, 'active blur composites terrain in one draw' => $horizontal['draws'] === 1
	, 'horizontal motion changes terrain pixels' => $horizontalChanges > 4000
	, 'vertical motion changes terrain pixels' => $verticalChanges > 4000
	, 'diagonal direction produces distinct samples' => $directionChanges > 1000
	, 'sprite drawn afterward stays exact' => $spriteExact
	, 'nested framebuffer receives identical output' => $parentComplete && $nestedExact
	, 'nested framebuffer binding is restored' => $nestedRestored
	, 'context restoration preserves output' => $restoredExact
	, 'resources are live before closure' => $live === [true, true]
];

$renderer->close();
$released = [!glIsTexture($sceneTexture), !glIsFramebuffer($sceneFramebuffer)];
$checks['owned resources are released'] = $released === [true, true];
$spriteRenderer->close();
$atlas->close();
$rects->close();
glDeleteTextures(1, [$parentTexture]);
glDeleteFramebuffers(1, $parentFramebuffer);
$error = glGetError();
$device->close();
echo json_encode(compact('checks', 'error', 'horizontalChanges', 'verticalChanges', 'directionChanges'));
