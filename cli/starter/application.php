<?php
declare(strict_types=1);
require_once 'phar://' . Phar::running(false) . '/core/autoload.php';
$app = new \Phonics\Application\Context;
$phonics = $app->compose(__DIR__ . '/worlds/game.world', __DIR__ . '/phonics.json#game');

require $app->entry();
