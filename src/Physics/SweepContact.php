<?php
declare(strict_types=1);
namespace Phonics\Physics;

/**
 * The interval over which a swept point is inside an expanded box, and the face it enters by.
 *
 * Times are fractions of the motion, unclamped: an entry before zero means the point started inside, an exit past
 * one that it is still inside at the end. Callers decide what counts as a hit; `Sweep::overlaps()` is the usual
 * test for motion over one step.
 */
final class SweepContact
{

	/**
	 * Describe one sweep's interval inside a box.
	 *
	 * @param float $entry Fraction of the motion at which the point enters the box; negative when it started inside.
	 * @param float $exit Fraction at which it leaves; greater than one when it is still inside at the end.
	 * @param int $axis Which axis's face the point enters by: 0 for x, 1 for y. A tie goes to y, as Doom's does.
	 * @param int $normalX X of the entered face's outward normal: -1, 0, or 1.
	 * @param int $normalY Y of the entered face's outward normal: -1, 0, or 1.
	 * @return void Holds the values as given.
	 */
	public function __construct(
		public readonly float $entry
		, public readonly float $exit
		, public readonly int $axis
		, public readonly int $normalX
		, public readonly int $normalY
	){
	}
}
