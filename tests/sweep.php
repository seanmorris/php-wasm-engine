<?php
use Phonics\Physics\Sweep;

// Sweep::box replaces two hand-written slab loops: a clamped accumulator (Sane-Phonics badniks, bosses and rings)
// and Doom-Phonics' raw-interval test with its own acceptance window. Both are copied here as they stood and the
// shared function must agree with each on every case, bit for bit.

/** The clamped loop: a hit when the point is inside the box at some moment of the step. */
function sweepReferenceClamped(float $ox, float $oy, float $mx, float $my, float $halfWidth, float $halfHeight): bool
{
	$entry = 0.0;
	$exit = 1.0;

	foreach([[$ox, $mx, $halfWidth], [$oy, $my, $halfHeight]] as [$position, $delta, $extent])
	{
		if(abs($delta) < 1e-9)
		{
			if(abs($position) > $extent) return false;

			continue;
		}

		$near = (-$extent - $position) / $delta;
		$far = ($extent - $position) / $delta;

		if($near > $far) [$near, $far] = [$far, $near];

		$entry = max($entry, $near);
		$exit = min($exit, $far);

		if($entry > $exit) return false;
	}

	return true;
}

/** Doom's test: raw interval, open containment on a still axis, and its acceptance window; returns [time, nx, ny]. */
function sweepReferenceDoom(
	float $x
	, float $y
	, float $dx
	, float $dy
	, float $centerX
	, float $centerY
	, float $extent
): ?array
{
	$entryX = -INF;
	$exitX = INF;
	$entryY = -INF;
	$exitY = INF;

	if(abs($dx) < 1e-12)
	{
		if($x <= $centerX - $extent || $x >= $centerX + $extent) return null;
	}
	else
	{
		$a = ($centerX - $extent - $x) / $dx;
		$b = ($centerX + $extent - $x) / $dx;
		$entryX = min($a, $b);
		$exitX = max($a, $b);
	}

	if(abs($dy) < 1e-12)
	{
		if($y <= $centerY - $extent || $y >= $centerY + $extent) return null;
	}
	else
	{
		$a = ($centerY - $extent - $y) / $dy;
		$b = ($centerY + $extent - $y) / $dy;
		$entryY = min($a, $b);
		$exitY = max($a, $b);
	}

	$entry = max($entryX, $entryY);
	$exit = min($exitX, $exitY);

	if($entry > $exit || $exit < 0 || $entry < -1e-7 || $entry > 1.0000001) return null;

	if($entryX > $entryY) return [max(0, $entry), $dx > 0 ? -1 : 1, 0];

	return [max(0, $entry), 0, $dy > 0 ? -1 : 1];
}

/** The way Doom calls the shared function. */
function sweepDoom(float $x, float $y, float $dx, float $dy, float $centerX, float $centerY, float $extent): ?array
{
	$contact = Sweep::box($x, $y, $dx, $dy, $centerX, $centerY, $extent, $extent, 1e-12, false);

	if(!$contact || $contact->exit < 0 || $contact->entry < -1e-7 || $contact->entry > 1.0000001) return null;

	return [max(0, $contact->entry), $contact->normalX, $contact->normalY];
}

test('Sweep::box agrees with the clamped slab loop on a grid of relative motions', function() {
	$values = [-30.0, -16.000001, -16.0, -15.999999, -7.5, -1e-10, 0.0, 1e-10, 3.25, 15.999999, 16.0, 16.000001, 40.0];
	$motions = [-50.0, -16.0, -0.5, -1e-10, 0.0, 1e-10, 0.5, 16.0, 50.0];
	$compared = 0;

	foreach($values as $ox)
	foreach($values as $oy)
	foreach($motions as $mx)
	foreach($motions as $my)
	{
		$expected = sweepReferenceClamped($ox, $oy, $mx, $my, 16.0, 12.0);
		$actual = Sweep::overlaps(Sweep::box($ox, $oy, $mx, $my, 0.0, 0.0, 16.0, 12.0));

		if($expected !== $actual) check(false, "clamped loop disagrees at origin ($ox, $oy) motion ($mx, $my)");

		$compared++;
	}

	check($compared === count($values) ** 2 * count($motions) ** 2, 'every grid case was compared');
	$box = static fn(float $x, float $y, float $dx, float $dy): bool
		=> Sweep::overlaps(Sweep::box($x, $y, $dx, $dy, 0.0, 0.0, 16.0, 12.0));
	check($box(0.0, 0.0, 0.0, 0.0), 'a still point inside the box overlaps');
	check($box(16.0, 0.0, 0.0, 0.0), 'a still point on the edge counts as inside when closed');
	check(!$box(40.0, 0.0, 0.0, 0.0), 'a still point outside does not');
	check($box(5.0, 5.0, 100.0, 0.0), 'a start already inside overlaps however far it moves');
	check(!$box(-40.0, 0.0, 10.0, 0.0), 'a point that stops short does not overlap');
	check(!$box(40.0, 0.0, 10.0, 0.0), 'a point moving away never did');
});

test('Sweep::box agrees with Doom\'s expanded-box test, acceptance window and normals included', function() {
	$positions = [-100.0, -52.0000001, -52.0, -51.9999999, -20.0, 0.0, 20.0, 51.9999999, 52.0, 52.0000001, 100.0];
	$motions = [-120.0, -40.0, -8.0, -1e-13, 0.0, 1e-13, 8.0, 40.0, 120.0];
	$compared = 0;

	foreach($positions as $x)
	foreach($positions as $y)
	foreach($motions as $dx)
	foreach($motions as $dy)
	{
		$expected = sweepReferenceDoom($x, $y, $dx, $dy, 0.0, 0.0, 52.0);
		$actual = sweepDoom($x, $y, $dx, $dy, 0.0, 0.0, 52.0);

		if($expected !== $actual) check(false, "Doom test disagrees at ($x, $y) motion ($dx, $dy)");

		$compared++;
	}

	check($compared === count($positions) ** 2 * count($motions) ** 2, 'every grid case was compared');
	// Centres away from the origin exercise the (centre - extent - x) arithmetic as Doom writes it.
	$offOrigin = [[100.5, -33.25, -7.0, 2.5, 120.0, -30.0, 36.0], [-3.0, 8.0, 12.0, 12.0, 60.0, 61.0, 50.0]];

	foreach($offOrigin as [$x, $y, $dx, $dy, $cx, $cy, $e])
	check(
		sweepReferenceDoom($x, $y, $dx, $dy, $cx, $cy, $e) === sweepDoom($x, $y, $dx, $dy, $cx, $cy, $e)
		, "off-origin case ($cx, $cy) agrees"
	);
	$tie = Sweep::box(-20.0, -20.0, 10.0, 10.0, 0.0, 0.0, 10.0, 10.0);
	check(
		$tie !== null && $tie->axis === 1 && $tie->normalX === 0 && $tie->normalY === -1
		, 'an equal entry on both axes enters by the y face, as Doom\'s does'
	);
	check(
		Sweep::box(52.0, 0.0, 0.0, 5.0, 0.0, 0.0, 52.0, 52.0, 1e-12, false) === null
		, 'open containment puts a still point on the edge outside'
	);
	$inside = Sweep::box(0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 52.0, 52.0, 1e-12, false);
	check(
		$inside !== null && $inside->entry === -INF && $inside->exit === INF
		, 'two still axes inside give an unbounded interval, which Doom rejects as a pre-existing overlap'
	);
});
