<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\
{
	Vehicle, Vehicles, Rider, Skateboard, Combo, ComboReward, RingCounter, PlayerCombat
};
use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\Physics\
{
	Body, Terrain, Platformer
};
use PhpWasmEngine\Render\Sprite;

/**
 * Build a board and opt-in rider above a flat floor.
 *
 * @param string $profile Character movement profile retained while riding.
 * @return array{Registry,Terrain,Vehicles,Body,Rider,int,Vehicle,int} Test objects and entity IDs.
 */
function vehicleFixture(string $profile = 'runner'): array
{
	$registry = new Registry;
	$terrain = new Terrain;
	$terrain->add([[-2000, 100], [4000, 100], [4000, 500], [-2000, 500]]);
	$system = new Vehicles($registry, $terrain, new Platformer($terrain));
	$vehicle = new Vehicle(Skateboard::body(0, 94), seatHeight: 12);
	$vehicle->body->grounded = true;
	$id = $system->add($vehicle, new Skateboard, new Sprite(1));
	$body = new Body(0, 50, $profile);
	$rider = new Rider;
	$player = $registry->create($body, $rider, new Combo, new RingCounter, new PlayerCombat);

	return [$registry, $terrain, $system, $body, $rider, $player, $vehicle, $id];
}

test('vehicles mount all profiles without replacing character components and reject occupied seats', function() {
	foreach(['runner', 'flyer', 'glider'] as $profile)
	{
		[$registry, , $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture($profile);
		$body->vx = 300;
		check($system->mount($player, $id));
		check($rider->vehicle === $id && $vehicle->occupant === $player && $body->ability === 'riding');
		check($body->profile === $profile && $registry->get($player, Body::class) === $body);
		near($vehicle->body->groundSpeed, 300);
		near($body->supportY(), 88);
		$other = $registry->create(new Body, new Rider);
		check(!$system->mount($other, $id));
	}
});

test('descending seat sweeps board at speed but reject ascending, other-plane and obstructed approaches', function() {
	foreach([6000, -6000] as $vy)
	{
		[$registry, , $system, $body, $rider] = vehicleFixture();
		$body->y = $vy > 0 ? -20 : 160;
		$body->vy = $vy;
		$system->sync(2, $body);
		$body->y += $vy / 60;
		$system->update($registry, 1 / 60);
		check(($rider->vehicle !== null) === ($vy > 0));
	}

	[$registry, $terrain, $system, $body, , $player, , $id] = vehicleFixture();
	$body->collisionLayer = 2;
	check(!$system->mount($player, $id));
	$body->collisionLayer = 1;
	$terrain->add([[-30, 30], [30, 30], [30, 80], [-30, 80]]);
	check(!$system->mount($player, $id));
});

test('vehicle input jumps the board without triggering rider abilities and up-jump exits once', function() {
	foreach(['runner', 'flyer', 'glider'] as $profile)
	{
		[$registry, , $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture($profile);
		check($system->mount($player, $id));
		$input = new Controls;
		$input->set([0 => true, 'right' => true, 'down' => true]);
		check($system->control($player, $input, 1 / 60));
		$system->update($registry, 1 / 60);
		check($vehicle->body->jumping && $vehicle->body->vy < 0);
		check($body->ability === 'riding' && !$body->jumping && $body->charge === 0.0);
		$input->set([]);
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
		$input->set(['up' => true, 0 => true]);
		check($system->control($player, $input, 1 / 60));
		check($rider->vehicle === null && $vehicle->occupant === null && $body->jumping);
		near($body->vx, 120);
		near($body->vy, -$body->jumpSpeed);
		check(!$system->mount($player, $id));
	}
});

test('locked seats block voluntary exits and quick-drop preserves downward vehicle velocity', function() {
	[$registry, , $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture();
	$vehicle->lockedIn = true;
	check($system->mount($player, $id));
	check(!$system->dismount($player, 'jump'));
	$vehicle->lockedIn = false;
	$vehicle->quickDrop = true;
	$vehicle->body->vy = 180;
	check($system->dismount($player, 'jump'));
	near($body->vy, -$body->jumpSpeed + 180);
	check($rider->vehicle === null);
});

test('vehicle passenger casts stop at a low ceiling without tunneling or losing the seat', function() {
	[$registry, $terrain, $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture();
	check($system->mount($player, $id));
	$terrain->add([[40, -100], [41, -100], [41, 70], [40, 70]]);
	$vehicle->body->groundSpeed = 2400;
	$vehicle->body->friction = 0;
	$system->update($registry, .1);
	check($body->x + $body->radius <= 40.001);
	check($rider->vehicle === $id && $terrain->canPlace($body));
	near($body->x, $vehicle->body->x);
});

test('vehicle damage detaches without overwriting knockback and removals clear both association ends', function() {
	[$registry, , $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture();
	check($system->mount($player, $id));
	$registry->get($player, PlayerCombat::class)->damage($body, null, -1);
	$system->update($registry, 1 / 60);
	check($rider->vehicle === null && $vehicle->occupant === null);
	near($body->vx, -240);
	near($body->vy, -360);
	$rider->cooldowns = [];
	$body->ability = '';
	check($system->mount($player, $id));
	$registry->destroy($id);
	check($rider->vehicle === null && $body->ability === '');
	$system->close();
	$system->close();
	check($registry->alive($player));
});

test('skateboard tricks use numbered edges, preserve a manual chain and bank after landing', function() {
	[$registry, , $system, $body, , $player, $vehicle, $id] = vehicleFixture();
	check($system->mount($player, $id));
	$board = $registry->get($id, Skateboard::class);
	$combo = $registry->get($player, Combo::class);
	$vehicle->body->grounded = false;
	$vehicle->body->y = -400;
	$vehicle->body->gravity = 0;
	$input = new Controls;
	$input->set([2 => true]);
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	check($board->trick === 'handstand' && count($combo->chain) === 1);

	for($i = 0; $i < 60; $i++)
	{
		$input->set([2 => true]);
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
	}

	check(count($combo->chain) === 1 && $board->trick === 'handstand');
	$input->set([]);
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	$vehicle->body->y = 94;
	$vehicle->body->grounded = true;
	$input->set([3 => true]);
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	check($board->trick === 'manual' && count($combo->chain) === 2);

	for($i = 0; $i < 30; $i++)
	{
		$input->set([3 => true]);
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
	}

	check(count($combo->chain) === 2);

	for($i = 0; $i < 20; $i++)
	{
		$input->set([]);
		$system->control($player, $input, 1 / 60);
		$system->update($registry, 1 / 60);
	}

	check(!$combo->chain && $registry->get($player, RingCounter::class)->score === 400);
});

test('skateboard rail tricks accrue one grind reward only after a chain and never a second generic reward', function() {
	[$registry, $terrain, $system, $body, , $player, $vehicle, $id] = vehicleFixture();
	$terrain->addRail([[-1000, 0], [3000, 0]]);
	$vehicle->body->y = -6;
	check($system->mount($player, $id));
	$combo = $registry->get($player, Combo::class);
	$combo->add(new ComboReward('Handstand', 100));
	$vehicle->body->grinding = true;
	$vehicle->body->groundSpeed = 600;

	for($i = 0; $i < 30; $i++) $system->update($registry, 1 / 60);
	check(count($combo->chain) === 2);
	check($combo->chain[1]->points >= 390);
});

test('vehicle shutdown leaves the player alive and resets stale steering before remount', function() {
	[$registry, , $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture();
	check($system->mount($player, $id));
	$input = new Controls;
	$input->set(['right' => true]);
	$system->control($player, $input, 1 / 60);
	$system->close();
	check($rider->vehicle === null && $vehicle->occupant === null);
	check(!$registry->alive($id) && $registry->alive($player));
	check(!$system->mount($player, $id));
});

test('boarding does not synthesize a jump edge from an already held button', function() {
	[$registry, , $system, , , $player, $vehicle, $id] = vehicleFixture();
	$input = new Controls;
	$input->set([0 => true]);
	$input->set([0 => true]);
	check($system->mount($player, $id));
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	check($vehicle->body->grounded && !$vehicle->body->jumping);
	$input->set([]);
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	$input->set([0 => true]);
	$system->control($player, $input, 1 / 60);
	$system->update($registry, 1 / 60);
	check(!$vehicle->body->grounded && $vehicle->body->jumping);
});

test('seat sweeps still board when ordinary floor collision clamps the falling player first', function() {
	[$registry, $terrain, $system, $body, $rider, $player] = vehicleFixture();
	$body->y = 20;
	$body->vy = 2000;
	$system->sync($player, $body);
	(new Platformer($terrain))->step($body, new Controls, .1);
	check($body->grounded);
	$system->update($registry, .1);
	check($rider->vehicle !== null && $body->ability === 'riding');
});

test('a skateboard carries its rider over quarter bends and through both loop collision planes', function() {
	$map = (new PhpWasmEngine\Tiled\Loader)->map(dirname(__DIR__) . '/examples/assets/vehicles.tmj');

	foreach([1, -1] as $direction)
	{
		$registry = new Registry;
		$terrain = PhpWasmEngine\Physics\MapTerrain::build($map);
		$system = new Vehicles($registry, $terrain, new Platformer($terrain));
		$vehicle = new Vehicle(Skateboard::body($direction > 0 ? 80 : 1880, 442));
		$vehicle->body->grounded = true;
		$vehicle->body->collisionLayer = $direction > 0 ? 1 : 2;
		$id = $system->add($vehicle, new Skateboard);
		$body = new Body($vehicle->body->x, 400);
		$body->collisionLayer = $vehicle->body->collisionLayer;
		$body->vx = $direction * 900;
		$player = $registry->create($body, new Rider);
		check($system->mount($player, $id));
		$quarter = $ceiling = false;

		for($frame = 0; $frame < 480; $frame++)
		{
			$system->update($registry, 1 / 60);
			check($body->grounded && $body->ability === 'riding', 'passenger must remain seated around the course');
			near($body->angle, $vehicle->body->angle);
			$quarter = $quarter || ($body->x < 1280 && abs(abs($body->angle) - M_PI_2) < .2);
			$ceiling = $ceiling || abs(abs($body->angle) - M_PI) < .2;

			if($direction > 0 ? $body->x > 1920 : $body->x < 160) break;
		}

		check($frame < 480 && $quarter && $ceiling, 'course traversal failed at ' . json_encode($system->snapshot()));
		check($body->collisionLayer === ($direction > 0 ? 2 : 1));
	}
});

test('chassis ring sweeps and monitor effects credit the rider once', function() {
	[$registry, $terrain, $system, $body, , $player, $vehicle, $id] = vehicleFixture();
	check($system->mount($player, $id));
	$map = new PhpWasmEngine\Tiled\TileMap('empty', ['tilewidth' => 32, 'tileheight' => 32]);
	$rings = new PhpWasmEngine\Gameplay\Rings($registry, $map);
	$ring = $registry->create(new PhpWasmEngine\Gameplay\Ring(50, 98, 1));
	$vehicle->body->groundSpeed = 1200;
	$system->update($registry, .1);
	$rings->update($registry, .1);
	check(!$registry->alive($ring));
	$counter = $registry->get($player, RingCounter::class);
	check($counter->rings === 1 && !$registry->has($id, RingCounter::class));
	$monitors = new PhpWasmEngine\Gameplay\Monitors($registry, $terrain);
	$monitor = new PhpWasmEngine\Gameplay\Monitor(200, 100);
	$monitors->create($monitor, new PhpWasmEngine\Gameplay\RingMonitorEffect(10), new Sprite(1));
	$vehicle->body->rolling = true;
	$hit = $terrain->castBody($vehicle->body, 200, 0);
	check($hit !== null && $monitors->impact($vehicle->body, $hit));
	check($counter->rings === 11 && $monitor->broken);
	$rings->update($registry, .1);
	check($counter->rings === 11);
});

test('vehicle regions supply locked boards, retire on restricted exit, and sweep thin drop zones', function() {
	[$registry, , $system, $body, $rider, $player, , $original] = vehicleFixture();
	$system->remove($original);
	$body->y = 82;
	$body->grounded = true;
	$body->vx = $body->groundSpeed = 300;
	$region = new PhpWasmEngine\Gameplay\VehicleRegion(-20, 0, 100, 120, restricted: true);
	$system->region($region);
	$system->update($registry, 1 / 60);
	$vehicle = $system->ridden($player);
	check($vehicle !== null && $vehicle->lockedIn);
	near($vehicle->body->groundSpeed, 300);
	check(!$system->dismount($player));
	$vehicle->body->x = 100;
	$system->update($registry, 1 / 60);
	check($rider->vehicle === null && !$registry->alive($vehicle->entity));
	check($body->grounded && $body->groundSpeed > 0);
	near($body->supportY(), 100);
	check($region->spawned[$player] === false);
	$body->x = 0;
	$system->update($registry, 1 / 60);
	check($rider->vehicle === null && !$system->vehicles);
	$vehicle = new Vehicle(Skateboard::body(0, 94));
	$vehicle->body->grounded = true;
	$id = $system->add($vehicle, new Skateboard);
	check($system->mount($player, $id));
	$system->sync($player, $body);
	$system->region(new PhpWasmEngine\Gameplay\VehicleRegion(50, 0, 1, 120, drop: true));
	$vehicle->body->groundSpeed = 1200;
	$system->update($registry, .1);
	check($rider->vehicle === null && $body->vx > 1000);
});

test('vehicle loader preserves offsets and all GID flips without leaving ghost collision', function() {
	foreach(range(0, 7) as $flags)
	{
		$map = (new PhpWasmEngine\Tiled\Loader)->map(dirname(__DIR__) . '/examples/assets/vehicles.tmj');
		$map->x = 100;
		$map->y = 30;
		$layer = $map->layer('Skateboards');
		$layer->offsetX = 7;
		$layer->offsetY = 11;
		$layer->properties['solid'] = true;
		$layer->opacity = .4;
		$object = $layer->objects[1];
		$object['gid'] += $flags * 536870912.0;
		$layer->objects = [$object];
		$map->layers = [$layer];
		$registry = new Registry;
		$terrain = PhpWasmEngine\Physics\MapTerrain::build($map);
		$system = new Vehicles($registry, $terrain, new Platformer($terrain), $map);
		check(count($system->vehicles) === 1);
		$vehicle = array_values($system->vehicles)[0];
		near($vehicle->body->x, 187);
		near($vehicle->body->y, 483);
		$art = $registry->get($vehicle->entity, Sprite::class);
		near($art->opacity, .4);
		check($map->tile($art->gid)['class'] === 'skateboard');
		check(!$terrain->solidAt(187, 480), 'vehicle object cannot remain as a static rectangle');
		$system->close();
		check($registry->query(Vehicle::class) === []);
	}
});

test('post-platform vehicle carry follows the chassis without advancing its simulation twice', function() {
	[$registry, , $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture();
	check($system->mount($player, $id));
	$vehicle->body->x += 20;
	$vehicle->body->y -= 10;
	$system->carry($registry, 1 / 60);
	near($body->x, 20);
	near($body->supportY(), 78);
	check($rider->vehicle === $id);
	$registry->remove($player, Rider::class);
	check($vehicle->occupant === null && $body->ability === '');
});

test('vehicle attacks and badnik damage retain the rider as the score and inventory owner', function() {
	foreach([false, true] as $attack)
	{
		[$registry, $terrain, $system, $body, $rider, $player, $vehicle, $id] = vehicleFixture();
		check($system->mount($player, $id));
		$badniks = new PhpWasmEngine\Gameplay\Badniks($registry, $terrain);

		$enemy = $badniks->create(
			new PhpWasmEngine\Gameplay\Badnik(0, 100, width: 10, height: 4)
			, new class implements PhpWasmEngine\Gameplay\BadnikBehavior {}
			, new Sprite(1)
		);

		$vehicle->body->rolling = $attack;
		$registry->get($player, RingCounter::class)->rings = 5;
		$badniks->update($registry, 1 / 60);
		$system->carry($registry, 1 / 60);
		$combat = $registry->get($player, PlayerCombat::class);
		check($combat->defeats === ($attack ? 1 : 0));
		check($combat->hits === ($attack ? 0 : 1));
		check(($rider->vehicle === null) === !$attack);
		check($registry->get($player, RingCounter::class)->rings === ($attack ? 5 : 0));
		check($registry->has($enemy, PhpWasmEngine\Gameplay\Badnik::class) === !$attack);
		check(!$registry->has($id, PlayerCombat::class));
	}
});
