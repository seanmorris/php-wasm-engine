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
