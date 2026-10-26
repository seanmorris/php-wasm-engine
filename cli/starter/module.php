<?php
declare(strict_types=1);
use Phonics\Package\Context;
use Phonics\Physics\Pose;
use StarterGame\Player;

return static function(Context $context): void {
    $context->factory('StarterGame.Player', static fn(array $object): array => [
        new Pose((float)$object['x'], (float)$object['y'], 12, 12),
        new Player((float)($object['properties']['speed'] ?? 180))
    ]);
};
