<?php
declare(strict_types=1);

use PhpWasmEngine\Gameplay\GravitySwitch;
use PhpWasmEngine\Gameplay\GravityFeatures;
use PhpWasmEngine\Gameplay\Spring;
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Physics\MapTerrain;
use PhpWasmEngine\Tiled\Loader;

test('bodies expose cardinal gravity and input-relative velocity projections', function() {
	$body = new Body;
	$body->setGravityDirection(1, 0);
	$body->vx = 30;
	$body->vy = -40;

	near($body->gravityVelocity(), 30);
	near($body->tangentVelocity(), 40);
	near($body->uprightAngle(), -M_PI_2);
	check($body->tangentX() === 0 && $body->tangentY() === -1);
	rejects(fn() => $body->setGravityDirection(0, 0));
	rejects(fn() => $body->setGravityDirection(1, 1));
});

test('terrain normals reground bodies against all four gravity directions', function() {
	$cases = [
		[
			[0, 1]
			, [[-200, 100], [200, 100], [200, 120], [-200, 120]]
			, [0, 0]
			, [0, 100]
		]
		, [
			[1, 0]
			, [[100, -200], [120, -200], [120, 200], [100, 200]]
			, [0, 0]
			, [100, 0]
		]
		, [
			[0, -1]
			, [[-200, -120], [200, -120], [200, -100], [-200, -100]]
			, [0, 0]
			, [0, -100]
		]
		, [
			[-1, 0]
			, [[-120, -200], [-100, -200], [-100, 200], [-120, 200]]
			, [0, 0]
			, [-100, 0]
		]
	];

	foreach($cases as [[$gravityX, $gravityY], $shape, [$x, $y], [$supportX, $supportY]])
	{
		$body = new Body($x, $y);
		$body->setGravityDirection($gravityX, $gravityY);
		$movement = new Platformer(new Terrain([['points' => $shape]]));

		for($frame = 0; $frame < 90 && !$body->grounded; $frame++)
		{
			$movement->step($body, new Controls, 1 / 60);
		}

		check($body->grounded, "gravity $gravityX,$gravityY did not reground");
		near($body->supportX(), $supportX, .01);
		near($body->supportY(), $supportY, .01);
		near(-sin($body->angle) * $gravityX + cos($body->angle) * $gravityY, 1, .001);
	}
});

test('ceiling regrounding uses its terrain normal and catches high-speed travel', function() {
	$terrain = new Terrain([
		['points' => [[-200, -101], [200, -101], [200, -100], [-200, -100]]]
	]);

	$body = new Body(0, 300);
	$body->setGravityDirection(0, -1);
	$body->terminalSpeed = 20000;
	$body->vy = -12000;
	(new Platformer($terrain))->step($body, new Controls, 1 / 30);

	check($body->grounded);
	near($body->supportY(), -100, .01);
	near(abs($body->angle), M_PI, .001);
});

test('air steering, jump release, and gravity caps follow the selected local axes', function() {
	$body = new Body;
	$body->setGravityDirection(1, 0);
	$body->jumping = true;
	$body->vx = -600;
	$controls = new Controls;
	$controls->set(['right' => true, 'jump' => true]);
	$movement = new Platformer(new Terrain);
	$movement->step($body, $controls, 1 / 60);
	$controls->set(['right' => true]);
	$movement->step($body, $controls, 1 / 60);

	check($body->vy < 0, 'logical right must follow the gravity-relative tangent');
	check($body->gravityVelocity() > -600 && $body->gravityVelocity() < -250);

	$body->vx = 4000;
	$body->vy = 0;
	$body->terminalSpeed = 900;
	$movement->step($body, new Controls, 1 / 60);
	near($body->gravityVelocity(), 900);
});

test('gravity switches detach bodies toward their target direction from any surface', function() {
	$cases = [
		[0, -1, 0, 1, 1, 0, 0]
		, [-1, 0, 1, 0, 0, -1, -M_PI_2]
		, [0, 1, 0, -1, -1, 0, M_PI]
		, [1, 0, -1, 0, 0, 1, M_PI_2]
	];

	foreach($cases as $index => [$normalX, $normalY, $gravityX, $gravityY, $targetX, $targetY, $angle])
	{
		$switch = new GravitySwitch($index + 1, 0, 0, $normalX, $normalY, 64, $targetX, $targetY);
		$body = new Body($normalX * 18, $normalY * 18);
		$body->setGravityDirection(-$normalX, -$normalY);
		$body->grounded = true;
		$body->angle = $angle;

		check($switch->pressedBy($body));
		check($switch->activate($body));
		check(!$body->grounded && $body->gravityX === $targetX && $body->gravityY === $targetY);
		check(!$switch->activate($body));
	}
});

test('oriented springs launch from floor, walls, and ceilings while preserving a plain fall pose', function() {
	$cases = [
		[0, -1, 0]
		, [-1, 0, -M_PI_2]
		, [0, 1, M_PI]
		, [1, 0, M_PI_2]
	];

	foreach($cases as $index => [$normalX, $normalY, $angle])
	{
		$spring = new Spring($index + 1, 0, 0, $normalX, $normalY, 48, 1200);
		$body = new Body($normalX * 18, $normalY * 18);
		$body->grounded = true;
		$body->angle = $angle;
		$body->rolling = $index === 0;

		check($spring->pressedBy($body));
		$spring->launch($body);
		near($body->vx, $normalX * 1200);
		near($body->vy, $normalY * 1200);
		check(!$body->grounded && !$body->jumping && $body->ability === 'spring');
		check($body->rolling === ($index === 0));
	}
});

test('spring contact edges catch high-speed bodies before launching them', function() {
	$terrain = new Terrain;
	$spring = new Spring(1, 0, 100, 0, -1, 64, 1400);
	$spring->shape = $terrain->addShape($spring->points(), true, 0, false, true);
	$body = new Body(0, -300);
	$body->gravity = 0;
	$body->terminalSpeed = 20000;
	$body->vy = 12000;
	(new Platformer($terrain))->step($body, new Controls, 1 / 30);

	check($body->grounded && $spring->pressedBy($body));
	$spring->launch($body);
	near($body->supportY(), 99.95, .01);
	near($body->vy, -1400);
});

test('directed one-way edges retain wall and ceiling normals for swept body casts', function() {
	$terrain = new Terrain;
	$ceiling = $terrain->addShape([[64, -100], [-64, -100]], true, 0, false, true);
	$wall = $terrain->addShape([[100, 64], [100, -64]], true, 0, false, true);
	$body = new Body(0, 0);

	check($terrain->castBody($body, 0, -200)?->shape === $ceiling);
	check($terrain->castBody($body, 200, 0)?->shape === $wall);
	rejects(fn() => $terrain->addShape([[0, 0], [1, 0], [1, 1]], true, 0, false, true));
});

test('Tiled gravity features become ECS sprites, collision edges, and scriptable diagnostics', function() {
	$map = fixture([
		'/map.tmj' => mapData([
			[
				'id' => 2
				, 'name' => 'Gravity features'
				, 'type' => 'objectgroup'
				, 'offsetx' => 5
				, 'offsety' => 7
				, 'objects' => [
					[
						'id' => 10
						, 'class' => 'GravitySwitch'
						, 'x' => 100
						, 'y' => 200
						, 'properties' => [
							['name' => 'gravity', 'value' => 'right']
							, ['name' => 'length', 'type' => 'float', 'value' => 48]
							, ['name' => 'spriteGid', 'type' => 'int', 'value' => 1]
						]
					]
					, [
						'id' => 11
						, 'class' => 'Spring'
						, 'x' => 300
						, 'y' => 200
						, 'rotation' => 90
						, 'properties' => [
							['name' => 'power', 'type' => 'float', 'value' => 1440]
							, ['name' => 'length', 'type' => 'float', 'value' => 64]
							, ['name' => 'spriteGid', 'type' => 'int', 'value' => 1]
						]
					]
				]
			]
		])
		, '/tiles.tsj' => tiles()
	])->map('/map.tmj');

	$map->x = 40;
	$map->y = 50;
	$registry = new Registry;
	$terrain = new Terrain;
	$features = new GravityFeatures($registry, $terrain, $map);

	check(array_keys($features->switches) === [10]);
	check(array_keys($features->springs) === [11]);
	near($features->switches[10]->x, 145);
	near($features->switches[10]->y, 257);
	near($features->springs[11]->normalX, 1);
	near($features->springs[11]->power, 1440);
	check(count($registry->query(GravitySwitch::class)) === 1);
	check(count($registry->query(Spring::class)) === 1);
	check(count($registry->query(PhpWasmEngine\Render\Sprite::class)) === 2);
	check(count($features->snapshot()['switches']) === 1);
	$body = new Body;
	check($features->activate(10, $body));
	check([$body->gravityX, $body->gravityY] === [1, 0]);
	$features->launch(11, $body);
	near($body->vx, 1440);
	near($body->vy, 0);
	rejects(fn() => $features->activate(99, $body));
	rejects(fn() => $features->launch(99, $body));

	$features->close();
	check(count($registry->query(GravitySwitch::class)) === 0);
	check(count($registry->query(Spring::class)) === 0);
});

test('the gravity course leaves room to settle between its four oriented controls', function() {
	$map = (new Loader)->map(dirname(__DIR__) . '/examples/assets/gravity.tmj');
	$terrain = MapTerrain::build($map);
	$registry = new Registry;
	$features = new GravityFeatures($registry, $terrain, $map);
	$body = new Body(240, 400);
	$registry->create($body);
	$movement = new Platformer($terrain);
	$input = new Controls;

	for($frame = 0; $frame < 120; $frame++)
	{
		$movement->step($body, $input, 1 / 60);
		$features->update($registry, 1 / 60);
	}

	check($body->grounded);
	check([$body->gravityX, $body->gravityY] === [0, 1]);
	near($body->x, 240);
	near($body->y, 526);
	check($features->switches[402]->x - $features->switches[404]->x > 2300);

	$landingClearances = [
		abs(240 - $features->switches[401]->x)
		, abs(526 - $features->switches[402]->y)
		, abs(2446 - $features->switches[403]->x)
		, abs(114 - $features->switches[404]->y)
		, abs(114 - $features->switches[401]->x)
	];

	foreach($landingClearances as $clearance)
	{
		check($clearance > 200, 'each landing needs an exploration stretch before the next switch');
	}

	$directions = [];

	foreach([401, 402, 403, 404] as $id)
	{
		check($features->activate($id, $body));
		$directions[] = [$body->gravityX, $body->gravityY];
	}

	check($directions === [[1, 0], [0, -1], [-1, 0], [0, 1]]);
	check(count($features->springs) === 4);
});
