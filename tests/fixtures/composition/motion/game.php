<?php
$engine = new \Phonics\Engine(new \Phonics\Render\Device);
$engine->entities = $phonics->entities;
$engine->systems = $phonics->systems;
$phonics->factories->spawn($phonics->loader->map($phonics->world['start']), $phonics->entities);
$rects = new \Phonics\Render\RectRenderer;

$engine->start(static function() use ($phonics, $engine, $rects): void {
	$engine->device->begin();
	$batch = [];

	foreach($phonics->entities->query(\Phonics\Physics\Pose::class) as $id)
	{
		$pose = $phonics->entities->get($id, \Phonics\Physics\Pose::class);
		$batch[] = [$pose->x, $pose->y, 30, 30, 1, .5, .1, 1];
		vrzno_env('canvas')->dataset->composition = json_encode([$pose->x, $pose->y]);
	}

	$rects->render($batch, 960, 540);
}, static function() use ($phonics, $rects): void {
	$phonics->close();
	$rects->close();
});

(vrzno_env('sceneReady'))(null);
