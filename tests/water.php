<?php
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Physics\WaterRegion;
use PhpWasmEngine\Physics\WaterRegions;

test('Tiled water rectangles retain map offsets, legacy anchors, physics, and render settings', function() {
	$loader = fixture([
		'/map.tmj' => mapData(
			[
				[
					'id' => 2
					, 'name' => 'Water'
					, 'type' => 'objectgroup'
					, 'offsetx' => 3
					, 'offsety' => 5
					, 'objects' => [
						[
							'id' => 1
							, 'class' => 'WaterRegion'
							, 'x' => 10
							, 'y' => 20
							, 'width' => 100
							, 'height' => 80
							, 'properties' => [
								['name' => 'gravity','type' => 'float','value' => .3]
								, ['name' => 'drag','type' => 'float','value' => .75]
								, ['name' => 'density','type' => 'float','value' => 1.2]
								, ['name' => 'skimSpeed','type' => 'float','value' => 720]
								, ['name' => 'currentX','type' => 'float','value' => 12]
								, ['name' => 'color','type' => 'color','value' => '#804080c0']
								, ['name' => 'displacement','type' => 'float','value' => 6]
							]
						]
						, [
							'id' => 2
							, 'type' => 'region'
							, 'gid' => 1
							, 'x' => 200
							, 'y' => 300
							, 'width' => 64
							, 'height' => 96
						]
					]
				]
			]
			, ['x' => 40,'y' => 50]
		)
		, '/tiles.tsj' => tiles()
	]);

	$map = $loader->map('/map.tmj');
	$map->x = 40;
	$map->y = 50;
	$waters = WaterRegions::fromMaps($map);
	check(count($waters->regions) === 2);
	$first = $waters->regions[0];
	near($first->x, 53);
	near($first->y, 75);
	near($first->gravity, .3);
	near($first->drag, .75);
	near($first->density, 1.2);
	near($first->skimSpeed, 720);
	near($first->currentX, 12);
	near($first->tint[0], 64 / 255);
	near($first->tint[3], 128 / 255);
	near($first->displacement, 6);
	near($waters->regions[1]->y, 259);
});

test('water scales gravity, control, jump force, and optional buoyancy without sticky state', function() {
	$water = new WaterRegion(-200, -200, 400, 400, .4, .85, 1);
	$waters = new WaterRegions($water);
	$movement = new Platformer(ground(), waters: $waters);
	$body = new Body(0, 0);
	$body->gravity = 1800;
	tick($movement, $body, ['right' => true]);
	check($body->underwater && $body->waterEntered && !$body->waterExited);
	near($body->vx, 288 * .85 / 60, .01);
	near($body->vy, 1800 * .4 / 60, .01);
	tick($movement, $body, ['right' => true]);
	check(!$body->waterEntered && !$body->waterExited);

	$floating = new Body(0, 0);
	$floating->density = .8;
	tick($movement, $floating);
	check($floating->underwater && $floating->waterDepth === 1.0 && $floating->vy < 0);

	$jumper = new Body(0, 82);
	$jumper->grounded = true;
	tick($movement, $jumper, ['jump' => true]);
	check($jumper->jumping && $jumper->vy < -500 && $jumper->vy > -570);

	$body->x = 500;
	tick($movement, $body);
	check(!$body->underwater && $body->waterExited && $body->water === null);
});

test('water currents and segment queries retain fast pass-through entry and exit edges', function() {
	$water = new WaterRegion(0, 0, 100, 100, currentX: 120, currentY: -60);
	$waters = new WaterRegions($water);
	$movement = new Platformer(new PhpWasmEngine\Physics\Terrain, waters: $waters);
	$body = new Body(50, 50);
	$body->gravity = 0;
	tick($movement, $body);
	near($body->vx, 2);
	near($body->vy, -1);

	$fast = new Body(-100, 50);
	$fast->gravity = 0;
	$fast->terminalSpeed = 30000;
	$fast->vx = 30000;
	tick($movement, $fast);
	check($fast->x > 100 && !$fast->underwater && $fast->waterEntered && $fast->waterExited);
	check($waters->crossed(-100, 50, 500, 0) === [$water]);
});

test('fast horizontal movement skims a water surface and slow falls enter it', function() {
	$waters = new WaterRegions(new WaterRegion(0, 100, 500, 300, skimSpeed: 900));
	$movement = new Platformer(new PhpWasmEngine\Physics\Terrain, waters: $waters);
	$fast = new Body(50, 81);
	$fast->gravity = 0;
	$fast->vx = 1000;
	$fast->vy = 120;
	tick($movement, $fast);
	check($fast->skimming && $fast->grounded && !$fast->underwater);
	near($fast->y + $fast->halfHeight, 100, .01);
	near($fast->vy, 0);
	tick($movement, $fast);
	check($fast->skimming && $fast->grounded);

	$slow = new Body(50, 81);
	$slow->gravity = 0;
	$slow->vx = 800;
	$slow->vy = 120;
	tick($movement, $slow);
	check(!$slow->skimming && !$slow->grounded && $slow->y > 82);
	tick($movement, $slow, [], 10);
	check($slow->underwater);
});

test('water regions reject shapes and invalid physical or shader parameters', function() {
	rejects(fn() => new WaterRegion(0, 0, 0, 1));
	rejects(fn() => new WaterRegion(0, 0, 1, 1, drag: 2));
	rejects(fn() => new WaterRegion(0, 0, 1, 1, tint: [1,1,1]));
	rejects(fn() => new WaterRegion(0, 0, 1, 1, tint: [1,1,1,2]));

	$loader = fixture([
		'/map.tmj' => mapData([
			[
				'id' => 1
				, 'name' => 'Water'
				, 'type' => 'objectgroup'
				, 'objects' => [
					[
						'id' => 1
						, 'class' => 'Water'
						, 'x' => 0
						, 'y' => 0
						, 'width' => 10
						, 'height' => 10
						, 'rotation' => 10
					]
				]
			]
		])
		, '/tiles.tsj' => tiles()
	]);

	rejects(fn() => WaterRegions::fromMaps($loader->map('/map.tmj')));
});
