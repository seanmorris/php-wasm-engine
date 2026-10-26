<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\Body;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;

/** Shared floor, ceiling, and gravity integration for grounded DECORATE actors. */
final class VerticalPhysics
{
	public function __construct(
		public Level $level
		, public float $maxStep = 24
		, public float $acceleration = DoomGravity::ACCELERATION
	) {}

	public function step(
		Transform $pose
		, Velocity $velocity
		, Body $body
		, float $dt
		, float $gravityScale = 1
	): void {
		$sector = $this->level->sectorAt($pose->x, $pose->y);
		$floor = $sector->floorZ($pose->x, $pose->y);
		$ceiling = $sector->ceilingZ($pose->x, $pose->y);

		if($body->grounded && $velocity->z <= 0
			&& ($floor > $pose->z || $sector->floorSlope !== [0.0,0.0,0.0])
			&& abs($floor - $pose->z) <= $this->maxStep
			&& $ceiling - $floor >= $body->height
		){
			$pose->z = $floor;
			$velocity->z = 0;
		}

		if($pose->z <= $floor + .01 && $velocity->z <= 0)
		{
			$pose->z = $floor;
			$velocity->z = 0;
			$body->grounded = true;
		}
		else
		{
			$elapsed = max(0, $dt);

			if(abs($velocity->z) < 1e-9 && $gravityScale > 0)
			$velocity->z = DoomGravity::initialVelocity($this->acceleration, $gravityScale);
			else $velocity->z -= $this->acceleration * $gravityScale * $elapsed;

			$pose->z += $velocity->z * $elapsed;

			if($pose->z <= $floor)
			{
				$pose->z = $floor;
				$velocity->z = 0;
				$body->grounded = true;
			}
			else $body->grounded = false;
		}

		$highest = $ceiling - $body->height;

		if($pose->z > $highest)
		{
			$pose->z = max($floor, $highest);

			if($velocity->z > 0) $velocity->z = 0;
		}
	}
}
