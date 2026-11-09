<?php
declare(strict_types=1);
namespace Phonics\Physics;

/**
 * Swept tests against axis-aligned boxes: the slab test shared by games that move a body against another body's
 * expanded bounds.
 *
 * A point at (x, y) moving by (dx, dy) over one step is tested against a box centred at (cx, cy) with the given
 * half extents, usually the other body's extents grown by the mover's. Each axis gives the fraction of the motion
 * at which the point enters and leaves the box; the intersection of the two is the time inside. An axis without
 * motion decides by containment alone. The arithmetic is the one both callers used to write by hand, plane by plane,
 * so results are identical to theirs.
 */
final class Sweep
{

	/**
	 * The interval of the motion during which the point is inside the box, or null when there is none.
	 *
	 * @param float $x Start x of the moving point.
	 * @param float $y Start y of the moving point.
	 * @param float $dx Motion in x over the step.
	 * @param float $dy Motion in y over the step.
	 * @param float $cx Box centre x.
	 * @param float $cy Box centre y.
	 * @param float $halfWidth Half the box width, the mover's radius included.
	 * @param float $halfHeight Half the box height, the mover's half height included.
	 * @param float $epsilon Motion below which an axis counts as still and is decided by containment.
	 * @param bool $closed Whether a still axis on the box's edge counts as inside (true) or outside (false).
	 * @return SweepContact|null The raw interval and entered face, or null when a still axis is outside the box
	 * or the intervals of the two axes do not meet.
	 */
	public static function box(
		float $x
		, float $y
		, float $dx
		, float $dy
		, float $cx
		, float $cy
		, float $halfWidth
		, float $halfHeight
		, float $epsilon = 1e-9
		, bool $closed = true
	): ?SweepContact {
		$entryX = -INF;
		$exitX = INF;
		$entryY = -INF;
		$exitY = INF;

		if(abs($dx) < $epsilon)
		{
			if($closed ? abs($x - $cx) > $halfWidth : ($x <= $cx - $halfWidth || $x >= $cx + $halfWidth)) return null;
		}
		else
		{
			$a = ($cx - $halfWidth - $x) / $dx;
			$b = ($cx + $halfWidth - $x) / $dx;
			$entryX = min($a, $b);
			$exitX = max($a, $b);
		}

		if(abs($dy) < $epsilon)
		{
			if($closed ? abs($y - $cy) > $halfHeight : ($y <= $cy - $halfHeight || $y >= $cy + $halfHeight))
			return null;
		}
		else
		{
			$a = ($cy - $halfHeight - $y) / $dy;
			$b = ($cy + $halfHeight - $y) / $dy;
			$entryY = min($a, $b);
			$exitY = max($a, $b);
		}

		$entry = max($entryX, $entryY);
		$exit = min($exitX, $exitY);

		if($entry > $exit) return null;

		if($entryX > $entryY) return new SweepContact($entry, $exit, 0, $dx > 0 ? -1 : 1, 0);

		return new SweepContact($entry, $exit, 1, 0, $dy > 0 ? -1 : 1);
	}

	/**
	 * Whether a contact's interval meets the step itself: the point is inside the box at some moment between the
	 * start and the end of the motion, a start already inside included.
	 *
	 * @param SweepContact|null $contact A result of `box()`.
	 * @return bool True when the clamped interval is not empty.
	 */
	public static function overlaps(?SweepContact $contact): bool
	{
		return $contact !== null && max(0.0, $contact->entry) <= min(1.0, $contact->exit);
	}
}
