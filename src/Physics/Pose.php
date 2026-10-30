<?php
declare(strict_types=1);
namespace Phonics\Physics;

/**
 * Oriented rectangular bounds used by geometry queries, with no movement policy.
 *
 * The value describes center, extents, and orientation only, leaving velocity, collision response, and
 * gameplay rules to callers.
 */
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
		/** Center X. */
		public float $x = 0
		, /** Center Y. */
		public float $y = 0
		, /** Horizontal half extent. */
		public float $radius = 8
		, /** Vertical half extent. */
		public float $halfHeight = 18
		, /** Rotation in radians. */
		public float $angle = 0
		, /** Selected plane in addition to common plane zero. */
		public int $collisionLayer = 1
	){
	}
}
