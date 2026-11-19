<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use TerrainPhonics\Edits;
use TerrainPhonics\HeightTile;
use TerrainPhonics\Landscape;
use TerrainPhonics\Noise;
use TerrainPhonics\Recipe;

$checks = 0;

/** Fail immediately with the invariant that was violated. */
function terrainCheck(bool $condition, string $message): void
{
	global $checks;
	$checks++;

	if(!$condition) throw new RuntimeException($message);
}

/** Compare numerical quantities with a scale-appropriate tolerance. */
function terrainNear(float $a, float $b, float $tolerance, string $message): void
{
	terrainCheck(abs($a - $b) <= $tolerance, "$message: $a versus $b");
}

/** Verify rejected input without depending on incidental error wording. */
function terrainReject(callable $action, string $message): void
{
	try
	{
		$action();
	} catch(InvalidArgumentException|OutOfBoundsException|OverflowException|LogicException $error)
	{
		terrainCheck(true, $message);

		return;
	}

	terrainCheck(false, $message);
}

$noise = new Noise('terrain-tests');
$same = new Noise('terrain-tests');
$other = new Noise('different');
$step = 1e-5;

foreach([[-3.7,-1.9],[0.0,0.0],[1.0,.31],[-1.0,-2.0],[.27,.81],[255.999,.73]] as [$x,$z])
{
	[$value,$dx,$dz] = $noise->sample($x, $z);
	terrainNear($value, $same->value($x, $z), 0, 'noise replays its seed');

	terrainNear(
		$dx
		, ($noise->value($x + $step, $z) - $noise->value($x - $step, $z)) / (2 * $step)
		, 1e-7
		, 'noise dx differentiates value'
	);

	terrainNear(
		$dz
		, ($noise->value($x, $z + $step) - $noise->value($x, $z - $step)) / (2 * $step)
		, 1e-7
		, 'noise dz differentiates value'
	);
}

terrainCheck($noise->value(.32, .47) !== $other->value(.32, .47), 'different seeds change the field');

foreach([-2,-1,0,1,2,255,256] as $edge)
{
	$a = $noise->sample($edge - 1e-7, .32);
	$b = $noise->sample($edge + 1e-7, .32);
	terrainNear($a[0], $b[0], 5e-7, 'noise value joins at lattice cells');
	terrainNear($a[1], $b[1], 5e-7, 'noise gradient joins at lattice cells');
}

foreach(['mountain','canyon','coast'] as $preset)
{
	$recipe = new Recipe(['preset' => $preset]);

	foreach([[0,0],[371.3,-925.7],[-12000.5,14800.25]] as [$x,$z])
	{
		$c = $recipe->coarse($x, $z);

		terrainNear(
			$c[1]
			, ($recipe->coarse($x + .01, $z)[0] - $recipe->coarse($x - .01, $z)[0]) / .02
			, 1e-7
			, "$preset coarse world dx"
		);

		terrainNear(
			$c[2]
			, ($recipe->coarse($x, $z + .01)[0] - $recipe->coarse($x, $z - .01)[0]) / .02
			, 1e-7
			, "$preset coarse world dz"
		);
	}
}

// These are different contracts: generic recipe defaults and the sandbox's taller,
// more closely spaced, terraced canyon must both have actual cliff-like geometry.
$canyonCases = [
	[
		'recipe' => ['preset' => 'canyon']
		, 'z' => 3000
		, 'left' => 100
		, 'right' => 250
		, 'peak' => 182

		, 'rise' => 400
		, 'slope' => 20
		, 'width' => 20
		, 'edges' => [165.86925381765002,197.46091741620984]
	]

	, [
		'recipe' => [
			'preset' => 'canyon'
			, 'heightScale' => 650
			, 'baseFrequency' => 1 / 4000
			, 'terraceSpacing' => 80
			, 'terraceStrength' => .7
		]

		, 'z' => 2000
		, 'left' => 100
		, 'right' => 150
		, 'peak' => 122

		, 'rise' => 620
		, 'slope' => 80
		, 'width' => 15
		, 'edges' => [111.64456765749003,132.7178869119473]
	]
];

foreach($canyonCases as $case)
{
	$canyon = new Recipe($case['recipe']);
	$replayed = new Recipe($case['recipe']);
	$floor = $canyon->coarse($case['left'], $case['z']);
	$plateau = $canyon->coarse($case['right'], $case['z']);
	$wall = $canyon->coarse($case['peak'], $case['z']);
	$x = $case['peak'];
	$z = $case['z'];

	terrainNear(
		$wall[1]
		, (-$canyon->coarse($x + .002, $z)[0] + 8 * $canyon->coarse($x + .001, $z)[0] - 8 * $canyon->coarse(
			$x - .001
			, $z
		)[0] + $canyon->coarse($x - .002, $z)[0]
		) / .012
		, 1e-5
		, 'steep wall X derivative includes the contour, warp and terrace chain'
	);

	terrainNear(
		$wall[2]
		, (-$canyon->coarse($x, $z + .002)[0] + 8 * $canyon->coarse($x, $z + .001)[0] - 8 * $canyon->coarse(
			$x
			, $z - .001
		)[0] + $canyon->coarse($x, $z - .002)[0]
		) / .012
		, 1e-5
		, 'steep wall Z derivative includes the contour, warp and terrace chain'
	);

	terrainCheck($plateau[0] - $floor[0] > $case['rise'], 'canyon has distinct deep floor and high plateau');

	terrainCheck(
		hypot($floor[1], $floor[2]) < .1 && hypot($plateau[1], $plateau[2]) < .1
		, 'floor and plateau remain gently sloped outside the cliff'
	);

	terrainCheck(
		hypot($wall[1], $wall[2]) > $case['slope']
		, 'canyon walls exceed the required steep world-space slope'
	);

	$crossings = [];

	foreach([.1,.9] as $fraction)
	{
		$height = $floor[0] + ($plateau[0] - $floor[0]) * $fraction;
		$lo = $case['left'];
		$hi = $case['right'];

		for($iteration = 0; $iteration < 45; $iteration++)
		{
			$mid = ($lo + $hi) / 2;

			if($canyon->coarse($mid, $case['z'])[0] < $height) $lo = $mid;
			else $hi = $mid;
		}

		$crossings[] = ($lo + $hi) / 2;
	}

	terrainCheck(
		$crossings[1] - $crossings[0] < $case['width']
		, 'ten-to-ninety-percent cliff rise occurs across a narrow measured width in metres'
	);

	foreach($case['edges'] as $edge)
	{
		$epsilon = .0001;
		$before = $canyon->coarse($edge - $epsilon, $case['z']);
		$at = $canyon->coarse($edge, $case['z']);
		$after = $canyon->coarse($edge + $epsilon, $case['z']);
		terrainNear($before[0], $after[0], 2e-5, 'cliff joins its floor/plateau continuously');

		foreach([1,2] as $axis)
		{
			terrainNear($before[$axis], $after[$axis], 1e-6, 'cliff first derivatives join at both band edges');

			terrainNear(
				($at[$axis] - $before[$axis]) / $epsilon
				, ($after[$axis] - $at[$axis]) / $epsilon
				, .002
				, 'cliff second derivatives join at both band edges'
			);
		}
	}

	[$minimum, $maximum] = $canyon->bounds();

	foreach([$case['left'], $case['peak'], $case['right']] as $x)
	{
		$height = $canyon->height($x, $case['z']);

		terrainNear(
			$height
			, $replayed->height($x, $case['z'])
			, 0
			, 'steep canyon geometry replays exactly from its seed'
		);

		terrainCheck($height >= $minimum && $height <= $maximum, 'conservative bounds enclose cliff and terraces');
	}

	$physicalCanyon = new Landscape($case['recipe']);
	$contact = $physicalCanyon->sample($case['peak'], $case['z']);

	terrainCheck(
		hypot($contact['dx'], $contact['dz']) > max(20, $case['slope'] / 2) && $contact['normal'][1] < .05
		, 'one-metre collision reconstruction retains genuinely steep canyon walls'
	);

	$physicalCanyon->close();
}

$roughWall = new Recipe(['preset' => 'canyon', 'detailOctaves' => 1, 'damping' => 0]);
$dampedWall = new Recipe(['preset' => 'canyon', 'detailOctaves' => 1]);
$macroWall = $dampedWall->coarse(182, 3000)[0];

terrainCheck(
	abs($dampedWall->height(182, 3000) - $macroWall) < .001 * abs($roughWall->height(182, 3000) - $macroWall)
	, 'fine detail is strongly attenuated by the independent steep canyon guide'
);

terrainNear(
	(new Recipe(['preset' => 'mountain']))->height(371.3, -925.7)
	, 85.26550300469363
	, 1e-12
	, 'canyon correction leaves mountain geometry unchanged'
);

terrainNear(
	(new Recipe(['preset' => 'coast']))->height(371.3, -925.7)
	, -25.34068759546756
	, 1e-12
	, 'canyon correction leaves coast geometry unchanged'
);

$ungated = new Recipe(['detailOctaves' => 1,'damping' => 0]);
$gated = new Recipe(['detailOctaves' => 1,'damping' => 20]);

foreach(range(-8, 8) as $i)
{
	$x = $i * 413.7;
	$z = $i * -217.9;
	$base = $gated->coarse($x, $z)[0];

	terrainCheck(
		abs($gated->height($x, $z) - $base) <= abs($ungated->height($x, $z) - $base) + 1e-10
		, 'slope gate attenuates individual detail amplitude'
	);
}

$tile = new HeightTile(8, 0);
terrainCheck($tile->get(3, 4) === null, 'unpopulated packed nodes are absent');
$tile->set(3, 4, -12.75);
terrainNear($tile->get(3, 4), -12.75, 0, 'packed doubles preserve height');
terrainCheck($tile->samples === 1 && $tile->bytes() === 576, 'tile payload is fixed and bounded');
terrainReject(fn() => $tile->set(8, 0, 0), 'tile indexing rejects overflow');

$surface = new Landscape(['seed' => 'terrain-tests','maxTiles' => 32]);
terrainCheck($surface->stats()['samples'] === 0, 'construction does not generate the world');
$surface->height(.25, .7);
terrainCheck($surface->stats()['generated'] === 16, 'fractional height generates only sixteen required nodes');
$surface->height(.25, .7);
terrainCheck($surface->stats()['generated'] === 16, 'cache reuses lattice nodes');

foreach([[0,0],[.25,.75],[-.25,-.75],[8,8],[15.25,-16.75],[16384,16384]] as [$x,$z])
{
	$s = $surface->sample($x, $z);
	terrainNear($s['height'], $surface->height($x, $z), 1e-12, 'height and contact sample share reconstruction');

	terrainNear(
		array_sum(array_map(static fn($v) => $v * $v, $s['normal']))
		, 1
		, 1e-12
		, 'surface normal is unit length'
	);

	if(abs($x) < 16384 && abs($z) < 16384)
	{
		terrainNear(
			$s['dx']
			, ($surface->height($x + $step, $z) - $surface->height($x - $step, $z)) / (2 * $step)
			, 1e-5
			, 'surface dx matches height'
		);

		terrainNear(
			$s['dz']
			, ($surface->height($x, $z + $step) - $surface->height($x, $z - $step)) / (2 * $step)
			, 1e-5
			, 'surface dz matches height'
		);
	}
}

foreach([-16,-8,-1,0,1,8,16] as $edge)
{
	$a = $surface->sample($edge - 1e-6, .37);
	$b = $surface->sample($edge + 1e-6, .37);
	terrainNear($a['height'], $b['height'], 1e-5, 'height joins across negative and positive tile boundaries');
	terrainNear($a['dx'], $b['dx'], 1e-5, 'dx joins across tile boundaries');
	terrainNear($a['dz'], $b['dz'], 1e-5, 'dz joins across tile boundaries');
}

$reference = $surface->sample(3.25, 7.75);

foreach(range(0, 80) as $i) $surface->height($i * 81.3, -$i * 37.7);

terrainCheck(
	$surface->stats()['tiles'] <= 32 && $surface->stats()['cacheBytes'] <= $surface->stats()['maxCacheBytes']
	, 'LRU remains within budget'
);

terrainCheck($surface->stats()['evictions'] > 0, 'travel evicts old blocks');
terrainCheck($surface->sample(3.25, 7.75) === $reference, 'eviction and regeneration preserve exact samples');
terrainReject(fn() => $surface->height(16384.01, 0), 'queries reject outside world');
terrainReject(fn() => $surface->height(NAN, 0), 'queries reject NaN');
terrainReject(fn() => new Landscape(['spacing' => 0]), 'zero sample spacing rejected');
terrainReject(fn() => new Landscape(['detailOctaves' => 100]), 'unbounded octave count rejected');

$flatRecipe = ['heightScale' => 0,'detailAmplitude' => 0,'worldSize' => 256,'maxTiles' => 8];
$edited = new Landscape($flatRecipe);
$original = Landscape::load($edited->save());
terrainCheck($edited->changedRegion($original) === [], 'identical committed surfaces have no dirty region');
$edited->queue(['type' => 'raise','x' => 0,'z' => 0,'radius' => 8,'amount' => 10]);
terrainNear($edited->height(0, 0), 0, 0, 'queued edits are invisible to physics');

terrainCheck(
	$edited->revision() === 0 && $edited->commit() && $edited->revision() === 1
	, 'commit publishes one revision'
);

terrainNear($edited->height(0, 0), 10, 0, 'raise edit reaches its center amplitude');

terrainCheck(
	$edited->changedRegion($original) === [-10.0,-10.0,10.0,10.0]
	, 'dirty region includes reconstruction halo'
);

terrainCheck($edited->changedRegion(new Landscape([
	'heightScale' => 1
])) === null, 'recipe changes invalidate all geometry'
);

terrainNear($edited->height(9, 0), 0, 0, 'compact edit has no remote effect');
terrainCheck(!$edited->commit() && $edited->revision() === 1, 'empty commit does not advance revision');

$edited->queue(['type' => 'lower','x' => 0,'z' => 0,'radius' => 8,'amount' => 3]);
$edited->queue(['type' => 'flatten','x' => 0,'z' => 0,'radius' => 4,'height' => 2,'amount' => .5]);
$edited->commit();
terrainNear($edited->height(0, 0), 4.5, 1e-12, 'transaction applies operators in order');
terrainCheck($edited->undo() && $edited->revision() === 3, 'undo advances monotonic revision');
terrainNear($edited->height(0, 0), 10, 0, 'undo removes complete transaction');

$restored = Landscape::load($edited->save());
terrainCheck($restored->redo(), 'saved redo branch is restored');
terrainNear($restored->height(0, 0), 4.5, 1e-12, 'redo reproduces ordered edits');
terrainCheck($edited->redo(), 'original redo applies');

foreach([[0,0],[1.25,2.75],[7.1,-.4],[-8,0]] as [$x,$z])
terrainCheck(
	$edited->sample($x, $z) === $restored->sample($x, $z)
	, 'saved edits reproduce height and derivatives exactly'
);

$edited->queue(['type' => 'material','x' => 0,'z' => 0,'radius' => 4,'material' => 'gravel']);
$edited->commit();
terrainCheck($edited->sample(0, 0)['material'] === 'gravel', 'material paint reaches surface queries');
$edited->queue(['type' => 'material','x' => 0,'z' => 0,'radius' => 4,'material' => null]);
$edited->commit();
terrainCheck($edited->sample(0, 0)['material'] === null, 'material erasure survives ordered painting');

$edited->queue(['type' => 'smooth','x' => 0,'z' => 0,'radius' => 8,'amount' => 1]);
$edited->commit();
terrainCheck(is_finite($edited->height(0, 0)), 'smoothing evaluates previous stage without recursion cycles');
$edited->queue(['type' => 'crater','x' => 20,'z' => 20,'radius' => 5,'amount' => 7]);
$edited->commit();
terrainNear($edited->height(20, 20), -7, 0, 'crater lowers its center');
$bounds = $edited->bounds();

foreach(range(-12, 28) as $i)
{
	$h = $edited->height($i + .3, $i * .6);
	terrainCheck($h >= $bounds['minY'] && $h <= $bounds['maxY'], 'edited bounds include spline overshoot');
}

terrainReject(fn() => $edited->queue([
	'type' => 'raise'
	, 'x' => 0
	, 'z' => 0
	, 'radius' => 0
	, 'amount' => 1
]), 'invalid brush rejected before mutation'
);

terrainReject(fn() => $edited->queue([
	'type' => 'smooth'
	, 'x' => 0
	, 'z' => 0
	, 'radius' => 8
	, 'sampleStep' => 2
]), 'smoothing cannot create unbounded branching sample locations'
);

$before = $edited->revision();
terrainCheck(!$edited->commit() && $edited->revision() === $before, 'rejected edit leaves no pending transaction');
$edited->queue(['type' => 'raise','x' => 0,'z' => 0,'radius' => 1,'amount' => 1]);
terrainReject(fn() => $edited->save(), 'save refuses silently dropping staged edits');
terrainReject(fn() => $edited->undo(), 'undo refuses silently mixing pending transactions');
$edited->discard();

$road = new Landscape($flatRecipe);
$road->queue(['type' => 'road','points' => [[-25,5,0],[0,5,0],[25,5,0]],'width' => 8,'shoulder' => 3,'crown' => .2]);
$road->commit();
terrainNear($road->height(0, 0), 5.2, 1e-9, 'road grades and crowns centerline');
terrainNear($road->height(0, 12), 0, 0, 'road shoulders preserve remote terrain');
terrainCheck($road->sample(0, 0)['material'] === 'road', 'road material follows grading');

foreach([[-25.1,0],[-32,0],[0,4],[0,7],[0,0],[8,3]] as [$x,$z])
{
	$s = $road->sample($x, $z);

	terrainNear(
		$s['dx']
		, ($road->height($x + $step, $z) - $road->height($x - $step, $z)) / (2 * $step)
		, 2e-4
		, 'road endpoint and shoulder dx differentiates height'
	);

	terrainNear(
		$s['dz']
		, ($road->height($x, $z + $step) - $road->height($x, $z - $step)) / (2 * $step)
		, 2e-4
		, 'road endpoint and shoulder dz differentiates height'
	);
}

$road->queue(['type' => 'road','points' => [[0,5,-25],[0,5,25]],'width' => 8,'shoulder' => 3]);
$road->commit();
terrainNear($road->height(0, 0), 5, 1e-9, 'ordered crossing joins at shared grade');
$roadReloaded = Landscape::load($road->save());
terrainCheck($road->sample(.3, 3.7) === $roadReloaded->sample(.3, 3.7), 'road acceleration rebuild preserves geometry');
terrainCheck(!str_contains($road->save(), '_road'), 'persistent roads omit derived spatial indexes');

$pinned = new Landscape($flatRecipe);
$token = $pinned->pin([0,0,3,3]);

terrainCheck(
	$pinned->stats()['samples'] === 0 && $pinned->stats()['pinnedTiles'] > 0
	, 'pin reserves payload without eager generation'
);

$pinCount = $pinned->stats()['pinnedTiles'];
terrainReject(fn() => $pinned->pin([-128,-128,128,128]), 'oversized residency is rejected atomically');
terrainCheck($pinned->stats()['pinnedTiles'] === $pinCount, 'failed pin retains existing leases');
$pinned->height(3, 3);
$pinned->queue(['type' => 'raise','x' => 3,'z' => 3,'radius' => 2,'amount' => 2]);
$pinned->commit();

terrainCheck(
	$pinned->stats()['pinnedTiles'] === $pinCount && $pinned->stats()['samples'] === 0
	, 'edit publication invalidates reserved samples together'
);

terrainNear($pinned->height(3, 3), 2, 0, 'reserved samples refresh to committed revision');
$pinned->unpin($token);
$pinned->unpin($token);
terrainCheck($pinned->stats()['pinnedTiles'] === 0, 'leases release idempotently');

$plane = new Landscape($flatRecipe);
$hit = $plane->raycast([0,10,0], [0,-2,0]);
terrainNear($hit['distance'], 10, 1e-5, 'vertical ray normalizes direction');
terrainNear($hit['position'][1], 0, 1e-5, 'vertical hit lies on terrain');
terrainCheck($plane->raycast([0,10,0], [0,1,0]) === null, 'upward ray above plane misses');

$hit = $plane->raycast([-10,10,-10], [1,-1,1]);
terrainNear($hit['distance'], sqrt(300), 1e-5, 'diagonal ray intersects reconstructed surface');
terrainCheck($plane->raycast([200,10,0], [0,-1,0]) === null, 'ray outside bounded footprint misses');

$plane->queue(['type' => 'raise','x' => 0,'z' => 0,'radius' => 2,'amount' => 10]);
$plane->commit();
$hit = $plane->raycast([-5,5,0], [1,0,0], 10);
terrainCheck($hit !== null && $hit['position'][0] < 0, 'cell root isolation finds narrow raised crest');
terrainNear($plane->height($hit['position'][0], 0), 5, 1e-4, 'horizontal ray result matches authoritative height');
terrainReject(fn() => $plane->raycast([0,10,0], [0,0,0]), 'zero-length ray rejected');

$sweep = new Landscape($flatRecipe);
$sweep->queue(['type' => 'raise','x' => -15,'z' => 0,'radius' => 2,'amount' => 10]);
$sweep->queue(['type' => 'raise','x' => 15,'z' => 0,'radius' => 2,'amount' => 10]);
$sweep->commit();
$query = $sweep->rayQuery([-60,5,0], [1,0,0], 120);

terrainCheck(
	!$query->done() && $sweep->stats()['generated'] === 0
	, 'incremental picker construction generates no cells'
);

terrainReject(fn() => $query->result(), 'incomplete picking result cannot submit an edit');

terrainCheck(
	!$query->advance(.000001, 1) && $query->stats()['cells'] === 1
	, 'even a tiny cooperative budget makes one bounded cell of progress'
);

$turns = 0;

while(!$query->done() && $turns++ < 120)
{
	$beforeCells = $query->stats()['cells'];
	$query->advance(.2, 3);
	terrainCheck($query->stats()['cells'] - $beforeCells <= 3, 'each picking turn honors its hard cell cap');
}

terrainCheck($query->done() && $turns > 1, 'long exact traversal resumes across multiple frame budgets');
$incremental = $query->result();
$synchronous = $sweep->raycast([-60,5,0], [1,0,0], 120);

terrainCheck(
	$incremental === $synchronous && $incremental['position'][0] < -15
	, 'incremental query preserves the exact first hit before later crests'
);

terrainCheck(
	$query->advance() && $query->result() === $incremental
	, 'completed queries remain stable without more generation'
);

$cancel = $sweep->rayQuery([-60,5,0], [1,0,0], 120);
$cancel->advance(1, 1);
$cancel->cancel();
$cancel->cancel();
terrainCheck($cancel->done() && $cancel->stats()['cancelled'], 'cancellation retires traversal idempotently');
terrainReject(fn() => $cancel->advance(), 'cancelled work cannot restart');
terrainReject(fn() => $cancel->result(), 'cancelled query cannot publish a stale hit');

$stale = $sweep->rayQuery([-60,5,0], [1,0,0], 120);
$stale->advance(1, 1);
$sweep->queue(['type' => 'raise','x' => -30,'z' => 0,'radius' => 2,'amount' => 20]);
$sweep->commit();
terrainReject(fn() => $stale->advance(), 'source revision changes cancel resumable picks');
terrainCheck($stale->stats()['cancelled'], 'stale revision leaves no resumable work');
terrainReject(fn() => $query->result(), 'completed results reject subsequent source revision changes');

$miss = $sweep->rayQuery([200,10,0], [0,-1,0]);
terrainCheck($miss->advance() && $miss->result() === null, 'incremental miss is distinct from incomplete work');
$invalid = $sweep->rayQuery([0,10,0], [0,0,0]);
terrainReject(fn() => $invalid->advance(), 'invalid ray validation runs on first resumable advance');
terrainReject(fn() => $sweep->rayQuery([0,10,0], [0,-1,0])->advance(0), 'zero time budgets are rejected');

$plane->close();
$plane->close();
terrainCheck($plane->stats()['closed'] && $plane->stats()['tiles'] === 0, 'close releases packed storage');
terrainReject(fn() => $plane->height(0, 0), 'closed landscape rejects queries');
terrainReject(fn() => Landscape::load('{"format":"terrain-phonics","version":99}'), 'unknown terrain format rejected');

// Smooth domain warps and terraces must differentiate their complete composition.
$shaped = new Recipe([
	'preset' => 'canyon'
	, 'warpAmplitude' => 600.0
	, 'warpFrequency' => 1 / 2000
	, 'terraceSpacing' => 60.0
	, 'terraceStrength' => .85
]);

for($i = -8; $i <= 8; $i++)
{
	$x = $i * 317.13;
	$z = $i * -167.29 + 10;
	$sample = $shaped->coarse($x, $z);

	terrainNear(
		$sample[1]
		, (-$shaped->coarse($x + .002, $z)[0] + 8 * $shaped->coarse($x + .001, $z)[0]
			- 8 * $shaped->coarse($x - .001, $z)[0] + $shaped->coarse($x - .002, $z)[0]
		) / .012
		, 1e-7
		, 'warped terraced guide includes the X derivative chain'
	);

	terrainNear(
		$sample[2]
		, (-$shaped->coarse($x, $z + .002)[0] + 8 * $shaped->coarse($x, $z + .001)[0]
			- 8 * $shaped->coarse($x, $z - .001)[0] + $shaped->coarse($x, $z - .002)[0]
		) / .012
		, 1e-7
		, 'warped terraced guide includes the Z derivative chain'
	);
}

$unwarped = new Recipe(['warpAmplitude' => 0]);
$warped = new Recipe(['warpAmplitude' => 600]);
terrainCheck($unwarped->height(731, 249) !== $warped->height(731, 249), 'domain warp changes the landform');
terrainReject(fn() => new Recipe(['terraceStrength' => 1.1]), 'terraces reject strength beyond their smooth blend');

// Exhaustive reference splits each ray at all lattice crossings, sorts the distances,
// then solves each segment using the cell containing its midpoint.
$exhaustive = [
	'coast' => [181.946400631, 97.383072653, 3.978038728, 113.058136124, 2.747950511, null, 55.730226949, 59.663528949],
	'mountain' => [3.556661567, 71.338958719, 231.08807236, 113.058165181, 12.84570181, null, null, 7.280767374],
	'canyon' => [30.703922065, 42.54823098, 10.556674898, 113.058185298, null, null, 111.341562416, 13.43411505],
];
foreach(['coast','mountain','canyon'] as $preset)
{
	$world = new Landscape(['preset' => $preset,'worldSize' => 512,'spacing' => 1.25,'seed' => 'pick-regression',
		'heightScale' => 130,'baseFrequency' => .006,'warpAmplitude' => 75,'terraceSpacing' => 6]);
	$world->queue(['type' => 'raise','x' => -12,'z' => 0,'amount' => 65,'radius' => 8]);
	$world->queue(['type' => 'lower','x' => 35,'z' => 0,'amount' => 25,'radius' => 20]);
	$world->queue(['type' => 'flatten','x' => 2,'z' => 5,'height' => 3,'radius' => 6]);
	$world->queue(['type' => 'crater','x' => -10,'z' => -10,'amount' => 19,'radius' => 7]);
	$world->queue(['type' => 'road','points' => [[-68,6,-15],[0,8,2],[55,22,35]],'width' => 4,'shoulder' => 3]);
	$world->queue(['type' => 'smooth','x' => -6,'z' => -5,'amount' => .9,'radius' => 24]);
	$world->commit();
	$rays = [
		[[-220,40,-6],[1,-.15,.025]], [[210,32,9],[-1,-.18,-.035]],
		[[-200,6,2],[1,0,0]], [[-12,120,0],[0,-1,0]],
		[[-4,3,5],[.01,1,-.001]], [[200,200,200],[1,.1,.1]],
		[[-300,30,0],[1,-.125,0]], [[12,21,40],[-.5,-.3,-1]]
	];
	foreach($rays as $i => [$origin,$direction])
	{
		$hit = $world->raycast($origin, $direction, 600);
		$distance = $hit['distance'] ?? null;
		$expected = $exhaustive[$preset][$i];
		if($expected === null) terrainCheck($distance === null, "$preset ray $i matches exhaustive miss");
		else
		{
			terrainCheck($distance !== null, "$preset ray $i finds the first intersection");
			[$x,$y,$z] = $hit['position'];
			terrainNear($world->height($x,$z), $y, 1e-4, "$preset ray $i actually lies on the surface");
			terrainNear($distance, $expected, 2e-6, "$preset ray $i preserves exhaustive first-hit distance");
		}
	}
	$world->close();
}

$coast = new Landscape(['preset' => 'coast']);
$camera = new TerrainPhonics\Camera;
$camera->position = [3600,300,1800];
$camera->yaw = .7;
$camera->pitch = -.35;
$hit = $coast->raycast($camera->position, $camera->ray(.5, .5, 640/368), 6000);
terrainCheck($hit !== null && $coast->stats()['generated'] < 200, 'cold coast pick avoids generating the approach corridor');
$coast->close();

$world = new Landscape(['worldSize' => 1024, 'spacing' => 1]);
terrainCheck($world->proceduralMeshPatch(0, 0, 256), 'aligned root quadrant uses the batch generator');
terrainCheck(!$world->proceduralMeshPatch(.5, 0, 256), 'fractional physical samples require reconstruction');
terrainCheck(!$world->proceduralMeshPatch(0, 0, 16), 'sub-lattice mesh spacing requires reconstruction');
$world->queue(['type' => 'raise', 'x' => 4, 'z' => 4, 'radius' => 1, 'amount' => 5]);
$world->commit();
terrainCheck($world->proceduralMeshPatch(0, 0, 256), 'brush between coarse vertices leaves their samples intact');
terrainCheck(!$world->proceduralMeshPatch(0, 0, 64), 'the same brush reaches finer lattice vertices');
$world->queue(['type' => 'material', 'x' => -8, 'z' => 64, 'radius' => 1, 'material' => 'sand']);
$world->commit();
terrainCheck(!$world->proceduralMeshPatch(0, 0, 256), 'normal halo includes authored positions outside the patch');
terrainCheck($world->proceduralMeshPatch(256, 256, 256), 'distant edits preserve batch generation');
$world->close();

// A worker can be terminated, disabled, or fail between submission and retrieval.
$world = new Landscape;
$job = (new TerrainPhonics\Lod)->node() + ['index' => 0];
$transfer = null;
$worker = new TerrainPhonics\BrowserPatchBuilder(static function($action, $path) use (&$transfer) {
	if($action === 'take')
	{
		$transfer = $path;
		file_put_contents($path, 'short payload');
		return 'ready';
	}
	return '';
});
terrainCheck(!$worker->advance($world, $job, null) && $job['index'] === 0, 'incomplete worker mesh falls back to PHP');
terrainCheck($transfer !== null && !file_exists($transfer), 'failed worker releases its transfer file');
$worker->close();
$world->close();

echo "Terrain surface: $checks checks passed.\n";
