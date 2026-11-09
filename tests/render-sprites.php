<?php
require dirname(__DIR__) . '/autoload.php';
use PhpWasmEngine\Render\
{
	Atlas,Camera,Device,Sprite,SpriteSheet,SpriteRenderer
};
use PhpWasmEngine\Tiled\Loader;

$device = new Device(48, 32);
$camera = new Camera(0, 0, 48, 32);
$sheet = new SpriteSheet(new Loader, __DIR__ . '/assets/sprites.tsj');
$atlas = new Atlas($sheet->map);
$renderer = new SpriteRenderer($atlas);
$left = $sheet->sprite('idle', 12, 16);
$right = $sheet->sprite('idle', 28, 16);
$texture = $atlas->texture;
$metadata = $atlas->metadata;
$checks = [];
$frames = [];

$capture = function($time) use ($device, $camera, $renderer, $left, $right) {
	$device->begin([0,0,0,1]);
	$renderer->render([$left,$right], $camera, $time);

	return [
		bin2hex(glReadPixels(12, 19, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE))
		, bin2hex(glReadPixels(28, 19, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE))
	];
};

foreach([
	[1000,'run','run',false]
	, [1100,'run','jump',false]
	, [1150,'run','jump',false]
	, [1200,'run','jump',true]
	, [1300,'run','idle',false]
	, [1450,'jump','run',false]
	, [1500,'jump','run',false]
	, [1550,'jump','run',false]
	, [1750,'run','run',false]
] as [$time,$a,$b,$restart])
{
	$sheet->play($left, $a, $time, restart: $restart);
	$sheet->play($right, $b, $time);
	$frames[] = $capture($time);
	$checks['single batch ' . $time] = $renderer->draws === 1;
	$checks['same atlas ' . $time] = $atlas->texture === $texture && $atlas->metadata === $metadata;
}

$before = $capture(1875);
$atlas->restore();
$renderer->restore();
$checks['restored playback'] = $before === $capture(1875);

$glyph = new Sprite($left->gid, 12, 16);
$glyph->animated = $glyph->outline = false;

$keyed = function(array $sprites, string $key, float $time) use ($device, $camera, $renderer) {
	$device->begin([0,0,0,1]);
	$renderer->render($sprites, $camera, $time, $key);

	return [
		bin2hex(glReadPixels(12, 19, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE))
		, bin2hex(glReadPixels(28, 19, 1, 1, GL_RGBA, GL_UNSIGNED_BYTE))
	];
};

$first = $keyed([$glyph], 'glyph at 12', 0);
$checks['a keyed static batch draws'] = $first[0] !== '000000ff' && $first[1] === '000000ff' && $renderer->draws === 1;
$checks['a repeated key draws the same pixels'] = $keyed([$glyph], 'glyph at 12', 0) === $first
	&& $renderer->draws === 1 && $renderer->instances === 1;
$glyph->x = 28;
$moved = $keyed([$glyph], 'glyph at 28', 0);
$checks['a new key repacks the batch'] = $moved === [$first[1], $first[0]];
$capture(1000);
$checks['an unkeyed batch replaces the kept upload'] = $keyed([$glyph], 'glyph at 28', 0) === $moved;
$sheet->play($left, 'run', 2000, restart: true);
$animatedFirst = $keyed([$left], 'animated', 2000);
$animatedLater = $keyed([$left], 'animated', 2100);
$checks['an animated sprite ignores its batch key'] = $animatedFirst !== $animatedLater;
$renderer->close();
$atlas->close();
$error = glGetError();
$device->close();
echo json_encode(compact('frames', 'checks', 'error'));
