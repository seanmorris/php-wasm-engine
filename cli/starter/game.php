<?php
declare(strict_types=1);
use Phonics\Engine;
use Phonics\Render\Device;
use Phonics\Render\RectRenderer;
use Phonics\Physics\Pose;
use StarterGame\Player;

$engine = new Engine(new Device(640, 360, 'My game'));
$engine->entities = $phonics->entities;
$engine->systems = $phonics->systems;
$phonics->factories->spawn($phonics->loader->map($phonics->world['start']), $phonics->entities);
$rectangles = new RectRenderer;

$phonics->system('StarterGame.movement', static function($entities, float $dt) use ($engine): void {
    foreach($entities->query(Player::class) as $id) {
        $pose = $entities->get($id, Pose::class);
        $speed = $entities->get($id, Player::class)->speed;
        $pose->x = max(12, min(628, $pose->x + $engine->controls->axis('left', 'right') * $speed * $dt));
        $pose->y = max(12, min(348, $pose->y + $engine->controls->axis('up', 'down') * $speed * $dt));
    }
});

$engine->start(static function() use ($engine, $rectangles): void {
    $engine->device->begin();
    $batch = [];
    foreach($engine->entities->query(Player::class) as $id) {
        $pose = $engine->entities->get($id, Pose::class);
        $batch[] = [$pose->x - 12, $pose->y - 12, 24, 24, .35, .85, .7, 1];
    }
    $rectangles->render($batch, 640, 360);
}, static function() use ($phonics, $rectangles): void {
    $phonics->close();
    $rectangles->close();
});

(vrzno_env('sceneReady'))(null);
