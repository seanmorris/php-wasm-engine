<?php
declare(strict_types=1);
namespace StarterGame;

/** Package-owned movement settings; geometry remains a Phonics component. */
final class Player
{
    public function __construct(public float $speed = 180) {}
}
