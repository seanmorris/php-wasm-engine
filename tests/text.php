<?php
declare(strict_types=1);

use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\TextActor;
use PhpWasmEngine\Gameplay\TextActors;
use PhpWasmEngine\Ui\TextMessages;

test('Tiled TextActor objects preserve inherited coordinates, color, and level ownership', function() {
	$loader = fixture([
		'text.tmj' => mapData([
			[
				'id' => 4
				, 'name' => 'Labels'
				, 'type' => 'objectgroup'
				, 'offsetx' => 5
				, 'offsety' => 7
				, 'opacity' => .5
				, 'tintcolor' => '#80FFFFFF'
				, 'objects' => [
					[
						'id' => 12
						, 'class' => 'text-actor'
						, 'x' => 20
						, 'y' => 30
						, 'width' => 160
						, 'properties' => [
							['name' => 'content', 'value' => 'HELLO']
							, ['name' => 'color', 'value' => '#80FF8040']
							, ['name' => 'scale', 'value' => 1.5]
							, ['name' => 'align', 'value' => 'center']
						]
					]
					, ['id' => 13, 'class' => 'TextActor', 'x' => 1, 'y' => 2, 'text' => ['text' => 'NATIVE']]
					, [
						'id' => 14
						, 'class' => 'TextActor'
						, 'x' => 1
						, 'y' => 2
						, 'visible' => false
						, 'properties' => [
							['name' => 'content', 'value' => 'HIDDEN']
						]
					]
				]
			]
		])
		, 'tiles.tsj' => tiles()
	]);

	$map = $loader->map('text.tmj');
	$map->x = 100;
	$map->y = 200;
	$registry = new Registry;
	$actors = new TextActors($registry, $map);
	$ids = $registry->query(TextActor::class);
	check(count($ids) === 2 && !$actors->isEmpty());
	$first = $registry->get($ids[0], TextActor::class);
	check($first->text === 'HELLO' && $first->sourceId === 12 && $first->align === 'center');
	near($first->x, 125);
	near($first->y, 237);
	near($first->width, 160);
	near($first->scale, 1.5);
	near($first->opacity, .5);
	near($first->tint[0], 1);
	near($first->tint[1], 128 / 255);
	near($first->tint[2], 64 / 255);
	near($first->tint[3], 64 / 255);
	check($registry->get($ids[1], TextActor::class)->text === 'NATIVE');
	$actors->close();
	check($registry->query(TextActor::class) === []);
});

test('TextActor rejects invalid rendering metrics before it enters the registry', function() {
	rejects(fn() => new TextActor("\xff", 0, 0));
	rejects(fn() => new TextActor('X', 0, 0, 0));
	rejects(fn() => new TextActor('X', 0, 0, scale: 0));
	rejects(fn() => new TextActor('X', 0, 0, tint: [1, 1, 1]));
	rejects(fn() => new TextActor('X', 0, 0, align: 'diagonal'));
});

test('TextMessages expose MB2 dialog, center, focus, and demo state without a DOM dependency', function() {
	$messages = new TextMessages;
	$messages->showCenter('READY', [1, .5, 0, 1], .1);
	$messages->showDialog(['ONE', 'TWO'], 'GUIDE', 'right');
	$messages->showDemo('record', .1);
	$messages->setFocused(true);
	$messages->setFocused(false);
	$state = $messages->snapshot();
	check($state['center']['text'] === 'READY');
	check($state['dialog']['lines'] === ['ONE', 'TWO'] && $state['dialog']['speaker'] === 'GUIDE');
	check($state['dialog']['side'] === 'right');
	near($state['dialog']['age'], 0);
	check($state['focus']['text'] === 'Click the game to enable keyboard control.');
	check($state['demo']['text'] === '⏺ REC');
	$messages->update(.12);
	$state = $messages->snapshot();
	check($state['center'] === null && $state['demo'] === null && $state['dialog']['age'] > .1);
	$messages->setFocused(true);
	$messages->update(.25);
	$messages->update(.25);
	$messages->update(.25);
	check($messages->snapshot()['focus'] === null);
	$messages->showDemo('play');
	check($messages->snapshot()['demo']['text'] === '▶ PLAY');
	rejects(fn() => $messages->showDemo('pause'));
	rejects(fn() => $messages->showDialog([], 'Guide'));
});
