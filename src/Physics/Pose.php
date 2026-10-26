<?php
declare(strict_types=1);
namespace Phonics\Physics;

/** Oriented rectangular bounds used by geometry queries, with no movement policy. */
class Pose
{

	/**
	 * Construct bounds in world pixels.
	 * @param float $x Center X.
	 * @param float $y Center Y.
	 * @param float $radius Horizontal half extent.
	 * @param float $halfHeight Vertical half extent.
	 * @param float $angle Rotation in radians.
	 * @param int $collisionLayer Selected plane in addition to common plane zero.
	 * @return void Records the query pose.
	 */
	public function __construct(
		public float $x = 0
		, public float $y = 0
		, public float $radius = 8
		, public float $halfHeight = 18
		, public float $angle = 0
		, public int $collisionLayer = 1
	){
	}
}
