<?php
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\MotionBlur;

test('motion blur reproduces the reference response curve and screen direction', function() {
	$camera = new Camera;
	$blur = new MotionBlur;
	check(!$blur->sample($camera, 1 / 60)['active']);

	$camera->x = 14;
	$below = $blur->sample($camera, 1 / 60);
	check(!$below['active']);

	$camera->x = 29;
	$on = $blur->sample($camera, 1 / 60);
	near($on['sigma'], .5625);
	near($on['x'], 1);
	near($on['y'], 0);
	check($on['active']);

	$camera->x = 53;
	$strong = $blur->sample($camera, 1 / 60);
	near($strong['sigma'], 3);

	$camera->x = 153;
	$camera->y = 100;
	$diagonal = $blur->sample($camera, 1 / 60);
	near($diagonal['sigma'], 8 * sqrt(2) - 1);
	near($diagonal['x'], 1 / sqrt(2));
	near($diagonal['y'], 1 / sqrt(2));
});

test('motion blur normalizes frame time and output zoom', function() {
	$camera = new Camera;
	$blur = new MotionBlur;
	$blur->sample($camera, 1 / 60);
	$camera->x = 24;
	near($blur->sample($camera, 1 / 60)['sigma'], 3);

	$blur->reset();
	$camera->x = 0;
	$blur->sample($camera, 1 / 60);
	$camera->x = 48;
	near($blur->sample($camera, 1 / 30)['sigma'], 3);

	$blur->reset();
	$camera->x = 0;
	$camera->zoom = 2;
	$blur->sample($camera, 1 / 60);
	$camera->x = 12;
	near($blur->sample($camera, 1 / 60)['sigma'], 3);
});

test('motion blur resets around pause, toggles, zoom changes and explicit recovery', function() {
	$camera = new Camera;
	$blur = new MotionBlur;
	$blur->sample($camera, 1 / 60);
	$camera->x = 1000;
	check(!$blur->sample($camera, 1 / 60, false)['active']);
	check(!$blur->sample($camera, 1 / 60)['active']);

	$camera->x = 2000;
	check(!$blur->sample($camera, 0)['active']);
	check(!$blur->sample($camera, 1 / 60)['active']);

	$camera->zoom = 2;
	check(!$blur->sample($camera, 1 / 60)['active']);
	$camera->x += 12;
	check($blur->sample($camera, 1 / 60)['active']);

	$blur->reset();
	$camera->x += 1000;
	check(!$blur->sample($camera, 1 / 60)['active']);
});

test('motion blur rejects invalid timing and camera state', function() {
	$blur = new MotionBlur;
	rejects(fn() => $blur->sample(new Camera, -1));
	rejects(fn() => $blur->sample(new Camera(zoom: 0), 1 / 60));
	rejects(fn() => $blur->sample(new Camera(x: INF), 1 / 60));
});
