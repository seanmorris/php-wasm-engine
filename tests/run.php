<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use PhpWasmEngine\Asset\Assets;
use PhpWasmEngine\Tiled\Gid;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Tiled\Projection;
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Ecs\Query;
use PhpWasmEngine\Ecs\Scheduler;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\Platformer;
use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\FixedStep;

$tests = [];

function check(bool $value, string $message = 'assertion failed'): void
{
	if(!$value)
	{
		throw new RuntimeException($message);
	}
}

function near(float $a, float $b, float $epsilon = .001): void
{
	check(abs($a - $b) <= $epsilon, "$a != $b");
}

function rejects(callable $fn): void
{
	try
	{
		$fn();
	}
	catch(Throwable $e)
	{
		return;
	}

	throw new RuntimeException('expected exception');
}

function test(string $name, callable $fn): void
{
	global $tests;
	$tests[$name] = $fn;
}

function fixture(array $data): Loader
{
	return new Loader(new Assets(fn($path) => json_encode($data[$path], JSON_THROW_ON_ERROR)));
}

function mapData(array $layers = [], array $extra = []): array
{
	return $extra + [
		'type' => 'map'
		, 'orientation' => 'orthogonal'
		, 'width' => 2
		, 'height' => 2
		, 'tilewidth' => 16
		, 'tileheight' => 16
		, 'layers' => $layers
		, 'tilesets' => [
			[
				'firstgid' => 1
				, 'source' => 'tiles.tsj'
			]
		]
	];
}

function tiles(): array
{
	return [
		'tilewidth' => 16
		, 'tileheight' => 16
		, 'tilecount' => 2
		, 'columns' => 2
		, 'image' => 'tiles.png'
		, 'imagewidth' => 32
		, 'imageheight' => 16
		, 'tiles' => [
			[
				'id' => 0
				, 'properties' => [
					[
						'name' => 'solid'
						, 'value' => true
					]
				]
			]
			, [
				'id' => 1
				, 'animation' => [
					['tileid' => 0,'duration' => 100]
					, [
						'tileid' => 1
						, 'duration' => 200
					]
				]
			]
		]
	];
}

function layer(array $extra = []): array
{
	return $extra + [
		'type' => 'tilelayer'
		, 'id' => 1
		, 'width' => 2
		, 'height' => 2
		, 'data' => [
			1
			, 0
			, 0
			, 2
		]
	];
}

test('unsigned GIDs preserve all flips on wasm32 and clear the hex flag', function() {
	$gid = Gid::from(4026531841.0);
	check($gid->id === 1 && $gid->flags === 14);
	check(bin2hex($gid->bytes()) === '010000e0');

	foreach(range(0, 7) as $bits)
	{
		$g = Gid::from(1 + $bits * 536870912.0);
		[$x,$y] = $g->uv(.2, .7);
		[$u,$v] = $g->inverseUv($x, $y);
		near($u, .2);
		near($v, .7);
	}

	rejects(fn() => Gid::from(-1));
	rejects(fn() => Gid::from(4294967296.0));
});

test('external tilesets, inherited group state and relative paths', function() {
	$loader = fixture([
		'/maps/a.tmj' => mapData([
			[
				'type' => 'group'
				, 'offsetx' => 4
				, 'opacity' => .5
				, 'parallaxx' => .5
				, 'layers' => [
					layer(['offsetx' => 3,'opacity' => .5])
				]
			]
		])
		, '/maps/tiles.tsj' => tiles()
	]);

	$map = $loader->map('/maps/a.tmj');
	check($map === $loader->map('/maps/a.tmj'));
	check($map->layers[0]->offsetX === 7.0);
	near($map->layers[0]->opacity, .25);
	near($map->layers[0]->parallaxX, .5);
	check($map->tile(1)['image'] === '/maps/tiles.png');

	check($map->animatedGid(2, 99) === 1 && $map->animatedGid(2, 100) === 2 && $map->animatedGid(2, 300) === 1);
});

test('base64 and compressed layers reject corrupt lengths', function() {
	foreach(['' => '', 'zlib' => 'gzcompress', 'gzip' => 'gzencode'] as $compression => $compress)
	{
		$bytes = hex2bin('01000080000000000000000002000000');
		$data = base64_encode($compress ? $compress($bytes) : $bytes);

		$loader = fixture([
			'a.tmj' => mapData([layer(['data' => $data, 'encoding' => 'base64', 'compression' => $compression])])
			, 'tiles.tsj' => tiles()
		]);

		check($loader->map('a.tmj')->layers[0]->gidAt(0, 0)->flags === 8);
	}

	$loader = fixture(['a.tmj' => mapData([layer(['data' => [1]])]),'tiles.tsj' => tiles()]);
	rejects(fn() => $loader->map('a.tmj'));
});

test('infinite maps keep sparse negative chunks and allow edits', function() {
	$loader = fixture([
		'a.tmj' => mapData(
			[
				layer([
					'chunks' => [
						[
							'x' => -10000
							, 'y' => -4
							, 'width' => 2
							, 'height' => 1
							, 'data' => [
								1
								, 2
							]
						]
					]
				])
			]
			, ['infinite' => true]
		)
		, 'tiles.tsj' => tiles()
	]);

	$map = $loader->map('a.tmj');
	$layer = $map->layers[0];
	check($layer->gidAt(-10000, -4)->id === 1 && $layer->gidAt(0, 0)->id === 0);
	$layer->set(-9999, -4, 1);
	check($layer->gidAt(-9999, -4)->id === 1);
	$layer->set(50000, 3, 2);
	check(count($layer->chunks) === 2);
});

test('orthogonal and isometric projections round trip negative coordinates', function() {
	foreach(['orthogonal','isometric'] as $orientation)
	{
		$p = new Projection($orientation, 64, 32, 10);

		foreach([[-17.25,9.5],[0,0],[4,7]] as [$x,$y])
		{
			[$sx,$sy] = $p->project($x, $y);
			[$tx,$ty] = $p->unproject($sx, $sy);
			near($x, $tx);
			near($y, $ty);
		}
	}
});

test('world maps load on intersection and reuse assets', function() {
	$reads = [];
	$data = [
		'world.world' => [
			'maps' => [
				[
					'fileName' => 'a.tmj'
					, 'x' => 0
					, 'y' => 0
					, 'width' => 32
					, 'height' => 32
				]
				, ['fileName' => 'b.tmj','x' => 1000,'y' => 0,'width' => 32,'height' => 32]
			]
		]
		, 'a.tmj' => mapData([layer()])
		, 'b.tmj' => mapData([layer()])
		, 'tiles.tsj' => tiles()
	];

	$loader = new Loader(new Assets(function($path) use (&$reads, $data) {
		$reads[] = $path;

		return json_encode($data[$path]);
	}));

	$world = $loader->world('world.world');
	check($reads === ['world.world']);
	check(count($world->visible(0, 0, 64, 64)) === 1);
	check(!in_array('b.tmj', $reads, true));
	$world->visible(0, 0, 64, 64);
	check(count(array_keys($reads, 'a.tmj')) === 1);
});

class Position
{

	public function __construct(public float $x = 0) {}
}

class Velocity
{

	public function __construct(public float $x = 0) {}
}

class Frozen {}

test('ECS supports inheritance, boolean queries, replacement and destruction', function() {
	$r = new Registry;
	$a = $r->create(new Position, new Velocity);
	$b = $r->create(new Position, new Frozen);
	check($r->query(Query::all(Position::class, Query::not(Frozen::class))) === [$a]);
	check(count($r->query(Query::any(Velocity::class, Frozen::class))) === 2);
	$r->add($a, new Position(9));
	near($r->get($a, Position::class)->x, 9);
	$r->destroy($a);
	check($r->query(Velocity::class) === []);
	rejects(fn() => $r->get($a, Position::class));
	$r->remove($b, Frozen::class);
	check($r->query(Query::not(Frozen::class)) === [$b]);
});

test('systems order dependencies and defer structural changes', function() {
	$r = new Registry;
	$e = $r->create(new Position);
	$s = new Scheduler($r);
	$order = [];

	$s->add('b', function($r) use (&$order, $e) {
		$order[] = 'b';
		check($r->has($e, Position::class));
	}, ['a']);

	$s->add('a', function($r) use (&$order, $e) {
		$order[] = 'a';
		$r->defer(fn($r) => $r->destroy($e));
	});

	$s->update(1 / 60);
	check($order === ['a','b'] && !$r->alive($e));
	$s->add('c', fn() => null, ['d']);
	$s->add('d', fn() => null, ['c']);
	rejects(fn() => $s->update(1 / 60));
});

test('fixed step is deterministic and limits catch-up', function() {
	$clock = new FixedStep(1 / 60, 5);
	$n = 0;

	$step = function($dt) use (&$n) {
		$n++;
		near($dt, 1 / 60);
	};

	$clock->advance(1 / 120, $step);
	check($n === 0);
	$clock->advance(1 / 120, $step);
	check($n === 1);
	$clock->advance(5, $step);
	check($n === 6 && $clock->droppedTime > 4);
});

function ground(): Terrain
{
	return new Terrain([['points' => [[-10000,100],[10000,100],[10000,500],[-10000,500]]]]);
}

function tick(Platformer $p, Body $b, array $keys = [], int $frames = 1): void
{
	static $inputs;
	$inputs ??= new WeakMap;
	$i = $inputs[$b] ??= new Controls;

	for($n = 0; $n < $frames; $n++)
	{
		$i->set($keys);
		$p->step($b, $i, 1 / 60);
	}
}

test('landing, acceleration, braking, jumping and variable jump height', function() {
	$p = new Platformer(ground());
	$b = new Body(0, 0);
	tick($p, $b, [], 50);
	check($b->grounded);
	near($b->y + $b->halfHeight, 100, .1);
	tick($p, $b, ['right' => true], 30);
	check($b->groundSpeed > 0 && $b->x > 0);
	$speed = $b->groundSpeed;
	tick($p, $b, ['left' => true], 3);
	check($b->groundSpeed < $speed);
	tick($p, $b, ['jump' => true]);
	check(!$b->grounded && $b->jumping && !$b->rolling && $b->vy < 0);
	near($b->halfHeight, $b->rollingHeight);
	$upward = $b->vy;
	tick($p, $b, []);
	near($b->vy, $upward / 2 + $b->gravity / 60);
	check($b->jumping);
	tick($p, $b, [], 90);
	check($b->grounded && !$b->jumping && !$b->rolling);
	near($b->halfHeight, $b->standingHeight);
});

test('fast bodies do not tunnel through floors or walls', function() {
	$terrain = new Terrain([
		['points' => [[-500,100],[500,100],[500,101],[-500,101]]]
		, [
			'points' => [
				[100,-500]
				, [
					101
					, -500
				]
				, [101,100],[100,100]
			]
		]
	]);

	$p = new Platformer($terrain);
	$b = new Body(0, 0);
	$b->terminalSpeed = 10000;
	$b->vy = 8000;
	tick($p, $b);
	check($b->y + $b->halfHeight <= 100.01 && $b->grounded);
	$b->groundSpeed = 8000;
	tick($p, $b);
	check($b->x + $b->radius <= 100.1);
});

test('slope sensors align feet and momentum leaves a ledge tangentially', function() {
	$p = new Platformer(new Terrain([['points' => [[-1000,550],[100,0],[100,600],[-1000,600]]]]));
	$b = new Body(-70, 20);
	tick($p, $b, [], 30);
	check($b->grounded && $b->angle < -.3);
	$b->groundSpeed = 500;
	tick($p, $b, ['right' => true], 30);
	check(!$b->grounded && !$b->jumping && !$b->rolling && $b->x > 100);
	near($b->halfHeight, $b->standingHeight);
});

test('one-way platforms permit upward passage and down-jump drop', function() {
	$p = new Platformer(new Terrain([['points' => [[-100,100],[100,100]],'oneWay' => true]]));
	$b = new Body(0, 150);
	$b->vy = -700;
	tick($p, $b, ['jump' => true], 40);
	check($b->grounded);
	tick($p, $b, []);
	tick($p, $b, ['down' => true,'jump' => true]);
	check(!$b->grounded);
	tick($p, $b, [], 12);
	check($b->y > 100);
});

test('stationary down crouches while moving down enters a roll', function() {
	$p = new Platformer(ground());
	$b = new Body(0, 60, 'runner');
	tick($p, $b, [], 10);
	tick($p, $b, ['down' => true]);
	check($b->crouching && !$b->rolling);
	near($b->halfHeight, $b->standingHeight);
	tick($p, $b, ['down' => true], 45);
	check($b->crouchTime >= .75);
	tick($p, $b, []);
	check(!$b->crouching && !$b->rolling && $b->crouchTime === 0.0);
	$b->groundSpeed = 300;
	tick($p, $b, ['down' => true]);
	check($b->rolling && !$b->crouching);
});

test('down plus jump taps or held action build a decaying spindash charge', function() {
	$p = new Platformer(ground());
	$b = new Body(0, 60, 'runner');
	tick($p, $b, [], 10);
	tick($p, $b, ['down' => true]);
	tick($p, $b, ['down' => true,'jump' => true]);
	$first = $b->charge;
	check($first > 0 && $b->grounded && $b->crouching && !$b->rolling && $b->crouchTime === 0.0);
	tick($p, $b, ['down' => true], 8);
	check($b->charge < $first);
	tick($p, $b, ['down' => true]);
	$decayed = $b->charge;
	check($decayed > 0 && $b->crouching && !$b->rolling);
	tick($p, $b, ['down' => true,'action' => true], 8);
	check($b->charge > $decayed, 'holding action must build charge every step');
	tick($p, $b, ['down' => true]);
	check($b->charge > 0 && !$b->rolling);
	tick($p, $b, []);
	check(!$b->crouching && $b->rolling && $b->groundSpeed > 500);
});

test('drop dash requires a held action during a jump', function() {
	$p = new Platformer(ground());
	$falling = new Body(0, 0, 'runner');
	tick($p, $falling, ['action' => true], 8);
	check($falling->dropCharge === 0.0, 'plain falling must not charge a drop dash');

	$b = new Body(0, 60, 'runner');
	tick($p, $b, [], 10);
	tick($p, $b, ['jump' => true]);
	tick($p, $b, ['action' => true], 6);
	check($b->dropCharge > 0, 'holding action during a jump must build charge');
	tick($p, $b);
	check($b->dropCharge === 0.0, 'releasing action before landing must cancel the charge');
	tick($p, $b, ['action' => true], 70);
	check($b->grounded && $b->rolling);
});

test('flight has a finite budget and glide can catch a wall and jump away', function() {
	$p = new Platformer(ground());
	$fly = new Body(0, -300, 'flyer');
	$fly->jumping = true;
	tick($p, $fly, ['jump' => true]);
	check($fly->ability === 'fly');
	tick($p, $fly, ['jump' => true], 360);
	check($fly->flightRemaining <= 0 || $fly->grounded);
	$p = new Platformer(new Terrain([['points' => [[100,-500],[120,-500],[120,500],[100,500]]]]));
	$b = new Body(70, 0, 'glider');
	$b->vx = 300;
	$b->jumping = true;
	tick($p, $b, ['jump' => true], 12);
	check($b->ability === 'climb');
	check(!$b->jumping);
	$old = $b->y;
	tick($p, $b, ['up' => true], 10);
	check($b->y < $old);
	tick($p, $b, ['jump' => true]);
	check($b->vx < 0 && $b->jumping && $b->ability !== 'climb');
});

test('gliders climb over the top of a solid ledge in either direction', function() {
	foreach([1, -1] as $wall)
	{
		$near = $wall > 0 ? 100 : -300;
		$far = $wall > 0 ? 300 : -100;
		$terrain = new Terrain([['points' => [[$near,100],[$far,100],[$far,500],[$near,500]]]]);
		$body = new Body($wall * 92, 130, 'glider');
		$body->ability = 'climb';
		$body->wall = $wall;
		$body->facing = $wall;
		$p = new Platformer($terrain);
		tick($p, $body, ['up' => true], 20);
		check($body->grounded && $body->ability === '' && $body->wall === 0);
		check($body->x * $wall > 100 && $body->facing === $wall);
		check(!$body->jumping && !$body->rolling);
		near($body->y + $body->halfHeight, 100, .01);
	}
});

test('climbers detach when a ledge top has no standing clearance', function() {
	$terrain = new Terrain([
		['points' => [[100,100],[300,100],[300,500],[100,500]]]
		, ['points' => [[90,50],[130,50],[130,70],[90,70]]]
	]);

	$body = new Body(92, 130, 'glider');
	$body->ability = 'climb';
	$body->wall = 1;
	$p = new Platformer($terrain);
	tick($p, $body, ['up' => true], 20);
	check(!$body->grounded && $body->ability === '');
	check($body->x < 100, 'blocked clearance must not place the climber inside the ledge');
});

require __DIR__ . '/physics.php';
require __DIR__ . '/loops.php';
require __DIR__ . '/integration.php';
require __DIR__ . '/sprites.php';
require __DIR__ . '/parallax.php';
require __DIR__ . '/world.php';
require __DIR__ . '/input.php';
require __DIR__ . '/air-control.php';
require __DIR__ . '/gravity.php';
require __DIR__ . '/sequences.php';
require __DIR__ . '/ecs.php';
require __DIR__ . '/breakables.php';
require __DIR__ . '/rolling-breakables.php';
require __DIR__ . '/menus.php';
require __DIR__ . '/cards.php';
require __DIR__ . '/debug-overlay.php';
require __DIR__ . '/rings.php';
require __DIR__ . '/light-dash.php';
require __DIR__ . '/grapples.php';
require __DIR__ . '/vehicles.php';
require __DIR__ . '/badniks.php';
require __DIR__ . '/mini-bosses.php';
require __DIR__ . '/boss-regions.php';
require __DIR__ . '/platform-camera.php';
require __DIR__ . '/motion-blur.php';
require __DIR__ . '/monitors.php';
require __DIR__ . '/shields.php';
require __DIR__ . '/grinding.php';
require __DIR__ . '/water.php';
require __DIR__ . '/platforms.php';
require __DIR__ . '/bridges.php';
require __DIR__ . '/text.php';

$failed = 0;

foreach($tests as $name => $fn)
{
	try
	{
		$fn();
		echo "PASS $name\n";
	}
	catch(Throwable $e)
	{
		$failed++;
		echo "FAIL $name: {$e->getMessage()}\n{$e->getTraceAsString()}\n";
	}
}

echo count($tests) . " tests, $failed failures\n";

if($failed)
{
	exit(1);
}
