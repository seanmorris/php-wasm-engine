<?php
use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\Physics\
{
	Body,Platformer,Terrain
};

test('controls retain proportional input and button edges across fixed steps', function() {
	$input = new Controls;
	$input->set(['right' => .5,'jump' => true]);
	near($input->value('right'), .5);
	near($input->axis('left', 'right'), .5);
	check($input->pressed('jump'));
	$input->set(['right' => .75,'jump' => true]);
	check(!$input->pressed('jump'));
	$input->set(['left' => true,'right' => .25]);
	near($input->axis('left', 'right'), -.75);
	check($input->released('jump'));
	$input->reset();
	near($input->axis('left', 'right'), 0);
	check(!$input->released('jump'));
});

test('partial air steering preserves look direction and proportional acceleration', function() {
	$movement = new Platformer(new Terrain);
	$body = new Body(0, 0);
	$input = new Controls;
	$input->set(['left' => .5]);
	$movement->step($body, $input, 1 / 60);
	check($body->facing === 1 && $body->direction === 1);
	near($body->vx, -$body->airAcceleration * .5 / 60);
});

test('flyers retain horizontal air steering while flight is active', function() {
	$movement = new Platformer(new Terrain);
	$body = new Body(0, 0, 'flyer');
	$body->jumping = true;
	$body->vx = 120;
	tick($movement, $body, ['jump' => true]);
	check($body->ability === 'fly');
	tick($movement, $body, ['left' => true], 60);
	check($body->ability === 'fly');
	check($body->facing === 1, 'flight steering must retain the takeoff look direction');
	check(property_exists($body, 'direction') && $body->direction === 1);
	check($body->vx < 0, 'flight input must be able to reverse horizontal travel');
});

test('gliders brake and turn with horizontal input while facing follows actual travel', function() {
	foreach([-1, 1] as $direction)
	{
		foreach([1 / 60, 1 / 120] as $dt)
		{
			$movement = new Platformer(new Terrain);
			$body = new Body(0, -1000, 'glider');
			$body->jumping = true;
			$body->ability = 'glide';
			$body->vx = 300 * $direction;
			$body->facing = $body->direction = $direction;
			$input = new Controls;
			$input->set([$direction > 0 ? 'left' : 'right' => .5]);
			$movement->step($body, $input, $dt);
			check($body->vx * $direction > 0 && abs($body->vx) < 300, 'Opposite input brakes without snapping');
			check($body->facing === $direction && $body->direction === $direction);

			for($i = 0; $i < (int)(.5 / $dt); $i++)
			{
				$movement->step($body, $input, $dt);
			}

			check($body->ability === 'glide' && $body->vx * $direction < 0);
			check($body->facing === -$direction && $body->direction === -$direction);
			check(abs($body->vx) <= 420, 'Turning retains the existing glide speed limit');
		}
	}
});

test('a glider retains its requested turn after neutral input and enters glide along existing momentum', function() {
	foreach([-1, 1] as $direction)
	{
		$movement = new Platformer(new Terrain);
		$body = new Body(0, -1000, 'glider');
		$body->jumping = true;
		$body->vx = 300 * $direction;
		$body->facing = $body->direction = -$direction;
		$input = new Controls;
		$input->set(['jump' => true]);
		$movement->step($body, $input, 1 / 60);
		check($body->vx * $direction > 0 && $body->facing === $direction);
		$input->set([$direction > 0 ? 'left' : 'right' => true]);
		$movement->step($body, $input, 1 / 60);
		$input->set([]);

		for($i = 0; $i < 60; $i++)
		{
			$movement->step($body, $input, 1 / 60);
		}

		check($body->ability === 'glide' && $body->vx * $direction < 0);
		check($body->facing === -$direction && $body->direction === -$direction);
	}
});

test('glide reversals still catch walls in either direction and a new glide forgets the old turn', function() {
	foreach([-1, 1] as $direction)
	{
		$terrain = new Terrain;
		$left = $direction < 0 ? -200 : 180;
		$terrain->add([[$left, -2000], [$left + 20, -2000], [$left + 20, 2000], [$left, 2000]]);
		$movement = new Platformer($terrain);
		$body = new Body(0, -1000, 'glider');
		$body->ability = 'glide';
		$body->jumping = true;
		$body->vx = -300 * $direction;
		$body->facing = $body->direction = -$direction;
		$input = new Controls;
		$input->set([$direction < 0 ? 'left' : 'right' => true]);

		for($i = 0; $i < 180 && $body->ability === 'glide'; $i++)
		{
			$movement->step($body, $input, 1 / 60);
		}

		check($body->ability === 'climb' && $body->wall === $direction);
		check($body->glideDirection === 0 && $body->facing === $direction);
		$body->x = 0;
		$body->ability = '';
		$body->jumping = true;
		$body->vx = -300 * $direction;
		$input->set(['jump' => true]);
		$movement->step($body, $input, 1 / 60);
		check($body->ability === 'glide' && $body->glideDirection === -$direction);
		$input->set(['down' => true]);
		$movement->step($body, $input, 1 / 60);
		check($body->ability === '' && $body->glideDirection === 0);
	}
});
