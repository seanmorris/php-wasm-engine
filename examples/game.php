<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';

use PhpWasmEngine\Engine;
use PhpWasmEngine\Effects\ParticleBurst;
use PhpWasmEngine\Gameplay\BreakableBlock;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Physics\
{
	Body,Hit,Platformer,MapTerrain
};
use PhpWasmEngine\Render\
{
	Device,Camera,MapRenderer,Sprite,SpriteRenderer
};

$scene = vrzno_env('scene')?:'platformer';
$profile = vrzno_env('profile')?:'runner';

if(!in_array($scene, ['platformer','loop','infinite','isometric','breakables'], true))
{
	throw new InvalidArgumentException('Invalid scene');
}

$loader = new Loader;
$map = $loader->map(__DIR__.'/assets/'.$scene.'.tmj');
$device = new Device;
$engine = new Engine($device);
$renderer = new MapRenderer($map);
$sprites = new SpriteRenderer($renderer->atlas);
$terrain = MapTerrain::build($map);
$blocks = null;
$onImpact = null;

if($scene === 'breakables')
{
	require __DIR__.'/breakables.php';
	$blocks = configureBreakables($engine, $terrain, $map);

	$onImpact =
		/**
		 * Break contacted blocks when the player is rolling, using the current animation time.
		 *
		 * @param Body $body Moving actor before collision response.
		 * @param Hit $hit Earliest terrain contact in the current sweep.
		 * @return bool True if the block was replaced by nonsolid debris.
		 */
		fn(Body $body, Hit $hit): bool => $blocks->impact($body, $hit, $engine->time * 1000);
}

$movement = new Platformer($terrain, $onImpact);
$body = new Body(80, 100, $profile);

// The demo PNG has two transparent rows below the visible soles.
$entity = $engine->entities->create(
	$body
	, new Sprite(['runner' => 7,'glider' => 8,'flyer' => 9][$profile], originY: 30 / 32)
);
$camera = new Camera(0, 0, 960, 540);
$browser = new Vrzno;
$canvas = vrzno_env('canvas');
$status = $browser->document->querySelector('#status');

$engine->systems->add('movement', function($entities, $dt) use ($engine, $movement, $body, $scene
	, $camera
) {
	if($scene === 'isometric')
	{
		$camera->x += $engine->controls->axis('left', 'right') * 300 * $dt;
		$camera->y += $engine->controls->axis('up', 'down') * 300 * $dt;
		return;
	}

	if($body->y > 1200 || $engine->controls->pressed('reset'))
	{
		$body->x = 80;
		$body->y = 100;
		$body->vx = $body->vy = $body->groundSpeed = 0;
		$body->angle = 0;
		$body->collisionLayer = 1;
		$body->grounded = false;
		$body->rolling = false;
		$body->halfHeight = $body->standingHeight;
		$body->charge = $body->dropCharge = 0;
		$body->ability = '';
	}

	$movement->step($body, $engine->controls, $dt);
	$camera->follow($body->x, $body->y, $dt);
	$camera->y = min(120, $camera->y);
}
);

if($scene === 'isometric')
{
	$camera->x = -160;
	$camera->y = -80;
}

$frames = 0;

$engine->start(function() use ($device, $renderer, $sprites, $body, $engine, $entity, $camera
	, $canvas
	, $status, $terrain, $scene, &$frames
) {
	$device->begin();
	$renderer->render($camera, $engine->time * 1000);

	if($scene !== 'isometric')
	{
		$sprite = $engine->entities->get($entity, Sprite::class);
		$sprite->x = $body->x - sin($body->angle) * $body->halfHeight;
		$sprite->y = $body->y + cos($body->angle) * $body->halfHeight;
		$sprite->rotation = $body->angle;
		$sprite->flipX = $body->facing < 0;
		$sprite->scaleY = $body->rolling?.7:1;
		$sprites->renderEntities($engine->entities, $camera, $engine->time * 1000);
	}

	$device->present();
	$frames++;
	$canvas->dataset->frames = (string)$frames;

	$canvas->dataset->body = json_encode(
		['x' => $body->x
		, 'y' => $body->y
		, 'grounded' => $body->grounded
		, 'rolling' => $body->rolling
		, 'ability' => $body->ability
			, 'speed' => $body->groundSpeed, 'angle' => $body->angle
			, 'collisionLayer' => $body
			->collisionLayer
		]
	);

	if($scene === 'breakables')
	{
		$intact = count($engine->entities->query(BreakableBlock::class));
		$pieces = 0;

		foreach($engine->entities->query(ParticleBurst::class) as $id)
		{
			$pieces += count($engine->entities->get($id, ParticleBurst::class)->sprites->sprites);
		}
		$canvas->dataset->breakables = json_encode(compact('intact', 'pieces'));
	}

	if($frames % 10 === 0)
	{
		$status->textContent = sprintf(
			'%s · %.0f px/s · %d draws · %d visible instances · %d tile uploads · %d collision casts'
			, $body->ability?:($body->rolling?'rolling':($body->grounded?'ground':'air'))
			, $body->groundSpeed
			, $renderer->stats['draws'] + $sprites->draws
			, $renderer->stats['instances'] + $sprites->instances
			, $renderer->stats['uploads']
			, $terrain->raycasts
		);

		if($scene === 'loop')
		{
			$status->textContent .= ' · collision layer '.$body->collisionLayer;
		}

		if($scene === 'breakables')
		{
			$status->textContent .= " · $intact intact blocks · $pieces shards · Roll into blocks";
		}

		if($engine->userPaused)
		{
			$status->textContent = 'Paused. Press Start / Options or P to resume.';
		}
	}
}, function() use ($sprites, $renderer, $blocks) {
	$blocks?->close();
	$sprites->close();
	$renderer->close();
}, function() use ($renderer, $sprites) {
	$renderer->restore();
	$sprites->restore();
});
