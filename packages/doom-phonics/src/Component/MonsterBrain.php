<?php
declare(strict_types=1);
namespace DoomPhonics\Component;

/** Small mutable AI state; decisions are made by systems and DECORATE actions. */
final class MonsterBrain
{
	public ?int $target = null;
	public bool $awake = false;
	public float $reaction = 0;
	public float $attackCooldown = 0;
}
