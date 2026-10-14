<?php
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\Badnik;
use PhpWasmEngine\Gameplay\BadnikBehavior;
use PhpWasmEngine\Gameplay\Badniks;
use PhpWasmEngine\Gameplay\Monitor;
use PhpWasmEngine\Gameplay\Monitors;
use PhpWasmEngine\Gameplay\PlayerCombat;
use PhpWasmEngine\Gameplay\RingCounter;
use PhpWasmEngine\Gameplay\Shield;
use PhpWasmEngine\Gameplay\ShieldAura;
use PhpWasmEngine\Gameplay\ShieldMonitorEffect;
use PhpWasmEngine\Gameplay\Shields;
use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Physics\MapTerrain;
use PhpWasmEngine\Physics\Terrain;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Tiled\Loader;

test('shield inventory selects persistent types and restores one after timed star immunity', function() {
	$shield = new Shield(Shield::NORMAL);
	check($shield->active === Shield::NORMAL && $shield->inventory() === [Shield::NORMAL]);
	$shield->grant(Shield::ELECTRIC);
	check($shield->active === Shield::ELECTRIC);
	check($shield->inventory() === [Shield::NORMAL, Shield::ELECTRIC]);
	check($shield->select(Shield::NORMAL));
	$shield->grant(Shield::STAR, 2);
	check($shield->active === Shield::STAR && $shield->immune('normal'));
	check(!$shield->select(Shield::ELECTRIC));
	$shield->update(1.5);
	near($shield->remaining, .5);
	$shield->update(.5);
	check($shield->active === Shield::NORMAL && $shield->remaining === 0.0);
	check($shield->absorb('normal'));
	check($shield->active === '' && !$shield->has(Shield::NORMAL));
	check($shield->select(Shield::ELECTRIC));
});

test('shield damage keeps rings, elemental immunity skips knockback, and contacts defeat badniks', function() {
	$body = new Body(0, 0);
	$body->grounded = true;
	$counter = new RingCounter(12);
	$combat = new PlayerCombat(1);
	$shield = new Shield(Shield::NORMAL);
	check($combat->damage($body, $counter, 1, $shield));
	check($counter->rings === 12 && $combat->hits === 1 && $shield->active === '');
	near($body->vx, 240);

	$combat->mercy = 0;
	$shield->grant(Shield::FIRE);
	$body->vx = $body->vy = 0;
	check(!$combat->damage($body, $counter, -1, $shield, 'fire'));
	check($shield->active === Shield::FIRE && $combat->hits === 1);
	near($body->vx, 0);
	check(!$combat->damage($body, $counter, -1, $shield, 'projectile'));

	$registry = new Registry;
	$terrain = new Terrain;
	$badniks = new Badniks($registry, $terrain);
	$body = new Body(0, 0);
	$body->grounded = true;
	$counter = new RingCounter(7);
	$combat = new PlayerCombat(1);
	$shield = new Shield(Shield::NORMAL);
	$player = $registry->create($body, $counter, $combat, $shield);

	$enemy = $badniks->create(
		new Badnik(0, 16, points: 100)
		, new class implements BadnikBehavior {}
		, new Sprite(1)
	);

	$badniks->sync($player, $body);
	$badniks->update($registry, 1 / 60);
	check(!$registry->alive($enemy));
	check($counter->rings === 7 && $counter->score === 100);
	check($combat->hits === 1 && $shield->active === '');
	$badniks->close();
});

test('Tiled shield monitors grant animated auras and each movement ability uses generic controls', function() {
	$map = (new Loader)->map(dirname(__DIR__) . '/examples/assets/badniks.tmj');
	$registry = new Registry;
	$terrain = MapTerrain::build($map);
	$shields = new Shields($registry, $map);
	$monitors = new Monitors($registry, $terrain, $map, shields: $shields);
	$body = new Body(0, 0);
	$body->grounded = false;
	$player = $registry->create($body, new RingCounter, new PlayerCombat);
	$ids = $monitors->entities();

	check($registry->get($ids[2], ShieldMonitorEffect::class)->type === Shield::NORMAL);
	check($registry->get($ids[6], ShieldMonitorEffect::class)->type === Shield::STAR);
	check($monitors->pop($ids[2], $player));
	$shield = $registry->get($player, Shield::class);
	check($shield->active === Shield::NORMAL);
	check(count($registry->query(ShieldAura::class)) === 1);
	$aura = $registry->query(ShieldAura::class)[0];
	check($registry->get($aura, Sprite::class)->gid === 41);

	$controls = new Controls;
	$shields->grant($player, Shield::ELECTRIC);
	$controls->set([]);
	$controls->set(['jump' => true]);
	$shields->control($player, $controls, 1 / 60);
	near($body->vy, -600);
	check($shield->electricJumps === 2 && $body->ability === 'electric-jump');

	$shields->grant($player, Shield::FIRE);
	$body->vx = 0;
	$controls->set([]);
	$controls->set([5 => true]);
	$shields->control($player, $controls, .1);
	check($body->vx > 0 && $body->ability === 'fire-dash');
	near($shield->firePower, .15);

	$shields->grant($player, Shield::WATER);
	$controls->set([]);
	$controls->set(['jump' => true]);
	$shields->control($player, $controls, 1 / 60);
	near($body->vy, 840);
	check($shield->bubbleArmed && $body->ability === 'bubble-bounce');
	$body->grounded = true;
	$shields->update($registry, 1 / 60);
	check(!$body->grounded && !$shield->bubbleArmed && $body->ability === 'bubble-rebound');
	near($body->vy, -840);

	$shields->grant($player, Shield::STAR, .5);
	$shields->update($registry, .5);
	check($shield->active === Shield::WATER);
	check($registry->get($aura, Sprite::class)->gid === 47);
	$shields->close();
	check($registry->query(ShieldAura::class) === [] && $registry->has($player, Shield::class));
	$restored = new Shields($registry, $map);
	$restored->update($registry, 1 / 60);
	check(count($registry->query(ShieldAura::class)) === 1);
	$restored->close();
	$monitors->close();
});

test('shield components and effects reject invalid types, durations, recipients, and timing', function() {
	rejects(fn() => new Shield('unknown'));
	rejects(fn() => new Shield(Shield::STAR, 0));
	rejects(fn() => new ShieldMonitorEffect(Shield::STAR, NAN));
	rejects(fn() => new ShieldAura(0));
	$shield = new Shield(Shield::NORMAL);
	rejects(fn() => $shield->immune(''));
	rejects(fn() => $shield->absorb(''));
	rejects(fn() => $shield->update(-1));
	$shields = new Shields(new Registry);
	rejects(fn() => $shields->grant(1, Shield::NORMAL));
	$shields->close();
});
