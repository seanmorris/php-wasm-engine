<?php
require_once dirname(__DIR__) . '/autoload.php';
use PhpWasmEngine\Engine;
use PhpWasmEngine\Render\Device;

$engine = new Engine(new Device(160, 90));
$engine->clock = new PhpWasmEngine\FixedStep(vrzno_env('inputStep')?:1 / 60);
$probe = vrzno_env('inputProbe');
$presses = $releases = $ticks = 0;
$matches = [];

$engine->systems->add('input', function() use ($engine, &$presses, &$releases, &$ticks) {
	$ticks++;
	$presses += (int)$engine->controls->pressed('jump');
	$releases += (int)$engine->controls->released('jump');
});

$engine->start(function() use ($engine, $probe, &$presses, &$releases, &$ticks, &$matches) {
	$engine->device->begin();
	$engine->device->present();
	$input = $engine->input;
	$matches = array_merge($matches, $input->sequences->drain());

	$probe(
		json_encode([
			'keys' => $input->snapshot()
			, 'ids' => $input->gamepads->ids()
			, 'matches' => $matches
			, 'progress' => $input->sequences->progress('combo')
			, 'axis' => $engine->controls->axis('left', 'right')
			, 'rightStick' => $input->gamepads->axis(SDL_CONTROLLER_AXIS_RIGHTX)
			, 'trigger' => $input->gamepads->axis(SDL_CONTROLLER_AXIS_TRIGGERLEFT)
			, 'extra' => $input->gamepads->button(SDL_CONTROLLER_BUTTON_X)
			, 'presses' => $presses
			, 'releases' => $releases
			, 'ticks' => $ticks
			, 'time' => $engine->time
			, 'paused' => $engine->userPaused
		])
	);
});
