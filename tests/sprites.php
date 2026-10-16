<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Render\
{
	Sprite,SpriteSheet
};
use PhpWasmEngine\Tiled\Loader;

test('switching sprite animations preserves the entity component and its appearance', function() {
	$sheet = new SpriteSheet(new Loader, __DIR__ . '/assets/sprites.tsj');
	$sprite = $sheet->sprite('idle', 120, 200);
	$sprite->rotation = .25;
	$sprite->scaleX = 2;
	$sprite->scaleY = .5;
	$sprite->flipX = $sprite->flipY = true;
	$sprite->opacity = .6;
	$sprite->tint = [.2,.3,.4,.5];
	$sprite->originX = .25;
	$sprite->originY = .75;
	$sprite->outline = false;
	$before = get_object_vars($sprite);
	$registry = new Registry;
	$entity = $registry->create($sprite);
	check($sheet->play($sprite, 'run', 1000));
	check($registry->get($entity, Sprite::class) === $sprite);
	$after = get_object_vars($sprite);
	unset($before['gid'], $before['time'], $after['gid'], $after['time']);
	check($before === $after);
	check($sprite->gid === $sheet->animations['run']);
	near($sprite->time, -1000);
});

test(
	'animation switches and explicit restarts use a per-sprite clock without rewinding repeated requests'
	, function() {
		$sheet = new SpriteSheet(new Loader, __DIR__ . '/assets/sprites.tsj');
		$sprite = $sheet->sprite('idle');
		$other = $sheet->sprite('idle');

		$frame = fn($sprite, $time) => $sheet->map->animatedGid($sprite->gid, $time + $sprite->time);
		check($sheet->play($sprite, 'run', 1000.5));
		check($sheet->play($other, 'run', 1050.5));

		foreach([[1000.5,1],[1100.49,1],[1100.5,2],[1300.49,2],[1300.5,1]] as [$time,$gid])
		{
			check(!$sheet->play($sprite, 'run', $time));
			check($frame($sprite, $time) === $gid);
		}

		check($frame($other, 1300.5) === 2, 'sprites sharing an animation need independent phases');
		check($sheet->play($sprite, 'jump', 1320.5));
		check($frame($sprite, 1320.5) === 3 && $frame($sprite, 1370.5) === 4);
		check(!$sheet->play($sprite, 'jump', 1400.5));
		check($frame($sprite, 1400.5) === 4);
		check($sheet->play($sprite, 'jump', 1400.5, restart: true));
		check($frame($sprite, 1400.5) === 3 && $frame($sprite, 1450.5) === 4);
		check($sheet->play($sprite, 'run', 1450.5));
		check($frame($sprite, 1450.5) === 1);
		check($sheet->play($sprite, 'idle', 1500));
		check($frame($sprite, 100000) === 1, 'static tiles must remain static');
		check($sheet->play($sprite, '3', 1600));
		check($frame($sprite, 1600) === 4, 'unnamed tiles remain selectable by local ID');
		near($other->time, -1050.5);
	}
);

test('invalid animation requests leave sprite playback unchanged', function() {
	$sheet = new SpriteSheet(new Loader, __DIR__ . '/assets/sprites.tsj');
	$sprite = $sheet->sprite('idle');
	$sheet->play($sprite, 'run', 1000);
	$before = get_object_vars($sprite);
	rejects(fn() => $sheet->play($sprite, 'missing', 1200));
	check(get_object_vars($sprite) === $before);

	foreach([INF,-INF,NAN] as $time)
	{
		rejects(fn() => $sheet->play($sprite, 'jump', $time));
		check(get_object_vars($sprite) === $before);
	}
});
