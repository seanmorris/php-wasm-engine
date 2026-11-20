<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';

use TerrainPhonics\Edits;
use TerrainPhonics\HeightTile;
use TerrainPhonics\CanyonBeds;
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
			, 'version' => 1
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

// A contact is fixed by the bedding datum for every local amount of erosion.
foreach([.18, .92, 1.0] as $strength)
foreach([-2, 0, 2] as $cycle)
foreach(CanyonBeds::CONTACTS as $contact)
{
	$h = ($contact + 10*$cycle)*80;
	[$height,$slope] = CanyonBeds::shape($h,80,$strength);
	terrainNear($height,$h,1e-10,'stratal shelves stay at their material contact over multiple cycles');
	terrainNear($slope,1-$strength,1e-10,'the two beds meet with a continuous shelf slope');
}

$section = new Recipe(['preset' => 'canyon','heightScale' => 650,'baseFrequency' => 1/4000,'terraceSpacing' => 80]);
$cliffs = $shelves = 0;
$spans = [];
for($x = 80; $x <= 330; $x += 2)
{
	[$h,$dx,$dz] = $section->coarse($x,2000);
	$incline = hypot($dx,$dz);
	$bed = ($h - $x*CanyonBeds::DIP[0] - 2000*CanyonBeds::DIP[1])/80;
	if($incline > 8) $cliffs++;
	if($incline < .8 && $bed > -2.2 && $bed < 2.25)
	{
		foreach(CanyonBeds::CONTACTS as $contact)
			if(abs($bed - $contact) < .09) $shelves++;
	}
	foreach([[1,0,$dx],[0,1,$dz]] as [$ax,$az,$derivative])
	{
		$step = .0001;
		$finite = ($section->coarse($x+$step*$ax,2000+$step*$az)[0]
			- $section->coarse($x-$step*$ax,2000-$step*$az)[0])/(2*$step);
		terrainNear($derivative,$finite,2e-6,'eroded canyon differentiates the retreat, bed strength and dip');
	}
	[$lo,$hi] = $section->regionalBounds([$x-1,1999,$x+1,2001]);
	$spans[] = $hi - $lo;
	foreach([[$x-1,1999],[$x,2000],[$x+1,2001]] as [$wx,$wz])
	{
		$actual = $section->height($wx,$wz);
		terrainCheck($actual >= $lo && $actual <= $hi,'canyon picking bounds enclose channel and shelf relief');
	}
}
terrainCheck($cliffs >= 3 && $shelves >= 3, 'the sandbox section has steep faces and traversable shelves at bed contacts');
sort($spans);
// Bounding erosion strength by its whole range, not by the gullies in the box, makes this median 31.9 m.
terrainCheck($spans[63] < 26, 'canyon bounds follow the local erosion strength: median span ' . $spans[63]);

// Without detail noise to hide an error, the bedded section must still enclose every height in a small box.
$bare = new Recipe(['preset' => 'canyon','heightScale' => 650,'baseFrequency' => 1/4000,'terraceSpacing' => 80,
	'detailAmplitude' => 0]);
$escaped = 0;
for($i = 0; $i < 400; $i++)
{
	$x = 61.7 * $i - 12000;
	$z = 9000 - 47.3 * $i;
	$size = 1 + $i % 6;
	[$lo,$hi] = $bare->regionalBounds([$x,$z,$x+$size,$z+$size]);
	for($j = 0; $j < 16; $j++)
	{
		$actual = $bare->height($x + $size * ($j % 4) / 3, $z + $size * intdiv($j, 4) / 3);
		if($actual < $lo || $actual > $hi) $escaped++;
	}
}
terrainCheck($escaped === 0, "bare canyon bounds enclose the bedded section: $escaped of 6400 heights escaped");

$savedCanyon = new Landscape(['preset'=>'canyon','terraceSpacing'=>80]);
$document = json_decode($savedCanyon->save(), true, flags: JSON_THROW_ON_ERROR);
terrainCheck($document['recipe']['version'] === 2, 'new canyon records its bedded recipe');
$reloaded = Landscape::load(json_encode($document, JSON_THROW_ON_ERROR));
terrainNear($savedCanyon->height(182,3000),$reloaded->height(182,3000),0,'new canyon replays exactly');
unset($document['recipe']['version']);
$legacy = Landscape::load(json_encode($document, JSON_THROW_ON_ERROR));
terrainCheck($legacy->recipe()['version'] === 1, 'older documents without a recipe version reopen on the original surface');

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

/**
 * Remap an elevation through fmod(), max(), min() and Noise::fade(), which CanyonBeds::shape() writes out.
 * @param float $height Elevation above the bedding datum in metres.
 * @param float $spacing Positive scale of the section in metres.
 * @param float $strength Interpolation from the unmodified slope to the bed profile.
 * @return array Remapped elevation and partial derivatives with respect to height and strength.
 */
function terrainBedsByCalls(float $height, float $spacing, float $strength): array
{
	$level = fmod($height, 10 * $spacing) / $spacing;

	if($level < -5) $level += 10;

	if($level >= 5) $level -= 10;

	$i = 0;

	while($i < 6 && $level >= CanyonBeds::CONTACTS[$i + 1]) $i++;

	$width = CanyonBeds::CONTACTS[$i + 1] - CanyonBeds::CONTACTS[$i];
	$fraction = ($level - CanyonBeds::CONTACTS[$i]) / $width;
	[$start,$end] = CanyonBeds::RAMPS[$i];
	$t = max(0.0, min(1.0, ($fraction - $start) / ($end - $start)));
	$displacement = $width * $spacing * (Noise::fade($t) - $fraction);

	return [
		$height + $strength * $displacement
		, 1 - $strength + $strength * 30 * $t * $t * ($t - 1) ** 2 / ($end - $start)
		, $displacement
	];
}

/**
 * Evaluate a recipe through the Noise methods and the plain numeric functions alone.
 *
 * Recipe::coarse(), Recipe::height() and CanyonBeds::shape() write those calls out in place because calls
 * dominate their cost in the browser. This is the definition that every written-out copy must reproduce to
 * the bit.
 * @param array $r Normalized recipe values with a positive height scale.
 * @param Noise $noise Noise source built from the recipe's seed.
 * @param float $x World X coordinate in metres.
 * @param float $z World Z coordinate in metres.
 * @return array Coarse height, its world derivatives and the detailed height [height,dx,dz,detailed].
 */
function terrainRecipeByCalls(array $r, Noise $noise, float $x, float $z): array
{
	[$worldX,$worldZ] = [$x,$z];
	$f = $r['baseFrequency'];
	$layered = $r['preset'] === 'canyon' && $r['version'] === 2
		&& $r['terraceSpacing'] > 0 && $r['terraceStrength'] > 0;
	$wallStart = .055;
	$wallWidth = .006;

	if($layered)
	{
		$wallStart -= .025 * $r['terraceStrength'];
		$wallWidth += .105 * $r['terraceStrength'];
	}

	$datum = $layered ? CanyonBeds::DIP[0]*$x + CanyonBeds::DIP[1]*$z : 0;
	$jacobian = [1.0,0.0,0.0,1.0];

	if($r['warpAmplitude'] > 0)
	{
		$wf = $r['warpFrequency'];
		$wa = $r['warpAmplitude'];
		$wx = $noise->sample($x * $wf + 271.13, $z * $wf - 197.41);
		$wz = $noise->sample($x * $wf - 419.27, $z * $wf + 113.71);
		$x += $wa * $wx[0];
		$z += $wa * $wz[0];

		$jacobian = [1 + $wa * $wf * $wx[1], $wa * $wf * $wx[2], $wa * $wf * $wz[1], 1 + $wa * $wf * $wz[2]];
	}

	$a = $noise->sample($x * $f + 13.17, $z * $f - 9.31);
	$b = $noise->sample($x * $f * 2 + 71.43, $z * $f * 2 + 53.11);

	if($r['preset'] === 'mountain')
	{
		$rounded = sqrt($a[0] * $a[0] + .04 * .04);
		$value = .35 - $rounded + .24 * $b[0];

		$factor = -$a[0] / $rounded;
		$dx = $factor * $a[1] * $f + .48 * $b[1] * $f;
		$dz = $factor * $a[2] * $f + .48 * $b[2] * $f;
	}
	elseif($r['preset'] === 'canyon')
	{
		$distance = abs($a[0]) - $wallStart;
		$sign = $a[0] < 0 ? -1 : 1;
		$erosionX = $erosionZ = 0.0;

		if($layered)
		{
			$ef = 22*$f;
			$erosion = $noise->sample($x*$ef+43.7, $z*$ef-81.3);
			$retreat = .029*$r['terraceStrength'];
			$distance -= $retreat*$erosion[0];
			$erosionX = $retreat*$erosion[1]*$ef;
			$erosionZ = $retreat*$erosion[2]*$ef;

			$channel = max(0.0,min(1.0,($erosion[0]+.13)/.62));
			$strength = $r['terraceStrength']*(1-.80*Noise::fade($channel));
			$ds = -$r['terraceStrength']*.80*30*$channel**2*($channel-1)**2/.62*$ef;
			$strengthX = $ds*($erosion[1]*$jacobian[0]+$erosion[2]*$jacobian[2]);
			$strengthZ = $ds*($erosion[1]*$jacobian[1]+$erosion[2]*$jacobian[3]);
		}

		$wall = max(0.0, min(1.0, $distance / $wallWidth));
		$value = -.55 + Noise::fade($wall) + .15 * $b[0];
		$factor = 30 * $wall * $wall * ($wall - 1) ** 2 / $wallWidth;

		$dx = $sign*$factor*$a[1]*$f - $factor*$erosionX + .3*$b[1]*$f;
		$dz = $sign*$factor*$a[2]*$f - $factor*$erosionZ + .3*$b[2]*$f;
	}
	else
	{
		$coast = tanh($x / 4500);
		$value = .6 * $a[0] + .35 * $coast + .15 * $b[0];
		$dx = .6 * $a[1] * $f + .35 * (1 - $coast * $coast) / 4500 + .3 * $b[1] * $f;
		$dz = .6 * $a[2] * $f + .3 * $b[2] * $f;
	}

	$height = $r['heightScale'] * $value;

	[$dx,$dz] = [
		$r['heightScale'] * ($dx * $jacobian[0] + $dz * $jacobian[2])
		, $r['heightScale'] * ($dx * $jacobian[1] + $dz * $jacobian[3])
	];

	if($layered)
	{
		[$relative,$factor,$displacement] = terrainBedsByCalls($height - $datum, $r['terraceSpacing'], $strength);
		$height = $relative + $datum;

		$dx = $factor * $dx + (1 - $factor) * CanyonBeds::DIP[0] + $displacement*$strengthX;
		$dz = $factor * $dz + (1 - $factor) * CanyonBeds::DIP[1] + $displacement*$strengthZ;
	}
	elseif($r['terraceSpacing'] > 0 && $r['terraceStrength'] > 0)
	{
		$spacing = $r['terraceSpacing'];
		$strength = $r['terraceStrength'];
		$level = floor($height / $spacing);
		$fraction = $height / $spacing - $level;

		$height += $strength * ($spacing * ($level + Noise::fade($fraction)) - $height);
		$factor = 1 - $strength + $strength * 30 * $fraction * $fraction * ($fraction - 1) ** 2;
		$dx *= $factor;
		$dz *= $factor;
	}

	$detailed = $height;
	$slope = ($dx * $dx + $dz * $dz) / ($r['slopeScale'] * $r['slopeScale']);
	$frequency = $f * 8;
	$amplitude = $r['detailAmplitude'];
	// Only a recipe without detail skips the loop. An amplitude that persistence reduces to zero still adds.
	$octaves = $amplitude === 0.0 ? 0 : $r['detailOctaves'];

	for($i = 0; $i < $octaves; $i++)
	{
		$weight = 1 / (1 + $r['damping'] * ($i + 1) * ($i + 1) * $slope);

		$detailed += $amplitude * $weight * $noise->value(
			$worldX * $frequency + 91.73 + $i * 19.17
			, $worldZ * $frequency - 23.49 - $i * 31.29
		);

		$frequency *= $r['lacunarity'];
		$amplitude *= $r['persistence'];
	}

	return [$height,$dx,$dz,$detailed];
}

// Recipe::coarse(), Recipe::height() and CanyonBeds::shape() repeat the Noise methods and the clamps in place.
// A change to Noise or to one written-out copy that the others do not follow must fail here, because saved
// heights and the mesh worker depend on every bit.
$expandedForms = [
	'coast' => ['preset' => 'coast']
	, 'mountain' => ['preset' => 'mountain', 'heightScale' => 1400, 'baseFrequency' => 1 / 2200]

	, 'bedded canyon' => [
		'preset' => 'canyon', 'heightScale' => 650, 'baseFrequency' => 1 / 4000
		, 'terraceSpacing' => 80
	]

	// Beds thinner than the relief take the section through fmod(). The beds above stay inside one period.
	, 'thin beds' => ['preset' => 'canyon', 'terraceSpacing' => 3, 'terraceStrength' => .5]
	, 'original canyon' => ['preset' => 'canyon', 'version' => 1, 'heightScale' => 650, 'terraceSpacing' => 80]
	, 'plain canyon' => ['preset' => 'canyon', 'warpAmplitude' => 0, 'seed' => 7]
	// The fastest warp crosses hundreds of lattice cells. The default warp stays within five.
	, 'terraced coast' => ['preset' => 'coast', 'terraceSpacing' => 35, 'warpAmplitude' => 5000, 'warpFrequency' => .01]
	, 'steep octaves' => ['preset' => 'mountain', 'detailOctaves' => 12, 'lacunarity' => 4, 'baseFrequency' => 1]
];

foreach($expandedForms as $form => $values)
{
	$recipe = new Recipe($values);
	$noise = new Noise($recipe->values()['seed']);
	// Inside the world the first warp sample never has a negative X cell. A point past the negative corner puts
	// both coordinates of every sample in negative cells, where the cell index steps down from the cast.
	$points = [[0.0,0.0],[-.25,.25],[16384.0,-16384.0],[-16384.0,16384.0],[-4.0e6,-4.0e6]];

	// Samples beyond the range that the expanded copies accept go to the Noise methods, so pin that path too.
	// The steep form leaves the range with its upper octaves inside the world. A distant point takes every
	// sample of the other forms out of it.
	if($form !== 'steep octaves') $points[] = [6.0e12,-6.0e12];

	// Quarter-metre nodes scattered over the whole world, on both sides of every axis.
	for($i = 1; $i <= 400; $i++) $points[] = [($i * 7919 % 131072 - 65536) / 4, ($i * 104729 % 131072 - 65536) / 4];

	$different = null;

	foreach($points as [$x,$z])
	{
		$expected = terrainRecipeByCalls($recipe->values(), $noise, $x, $z);
		$actual = [...$recipe->coarse($x, $z), $recipe->height($x, $z)];
		$different ??= pack('e4', ...$expected) === pack('e4', ...$actual) ? null : "($x,$z)";
	}

	terrainCheck($different === null, "$form recipe matches its calls to the bit, first miss at $different");
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
terrainNear($edited->height(.25, .5), 0, 0, 'warm a fractional hull-probe cell before commit');

terrainCheck(
	$edited->revision() === 0 && $edited->commit() && $edited->revision() === 1
	, 'commit publishes one revision'
);

terrainNear($edited->height(0, 0), 10, 0, 'raise edit reaches its center amplitude');
$raisedProbe = $edited->height(.25, .5);
terrainCheck($raisedProbe > 9, 'commit refreshes cached interpolation coefficients');

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
terrainCheck($edited->height(.25, .5) < $raisedProbe, 'the next transaction replaces the warmed hull-probe cell');
terrainCheck($edited->undo() && $edited->revision() === 3, 'undo advances monotonic revision');
terrainNear($edited->height(0, 0), 10, 0, 'undo removes complete transaction');
terrainNear($edited->height(.25, .5), $raisedProbe, 0, 'undo restores the exact hull-probe height');

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

// The cache counters are part of the contract. A stencil visits its sixteen nodes in row-major order, every
// visit refreshes that node's tile, and a tile that has to be created evicts the least recently used one.
// The counts below follow from that rule alone, worked out by hand for four-node tiles with room for four.
$tiny = ['seed' => 'cache-order','tileSize' => 4,'maxTiles' => 4];
$cache = new Landscape($tiny);
$ample = new Landscape(['maxTiles' => 64] + $tiny);

/**
 * Report the cache counters that the following checks pin.
 * @param Landscape $land Landscape whose cache is inspected.
 * @return array Generated nodes, cache hits, evictions and stored samples.
 */
$cacheCounts = static function(Landscape $land): array {
	$stats = $land->stats();

	return [$stats['generated'], $stats['cacheHits'], $stats['evictions'], $stats['samples']];
};

/**
 * Query the tiny cache and the ample one, require the same height, and report the tiny cache's counters.
 * @param float $x World X coordinate in metres.
 * @param float $z World Z coordinate in metres.
 * @return array Generated nodes, cache hits, evictions and stored samples of the tiny cache.
 */
$cacheStep = static function(float $x, float $z) use ($cache, $ample, $cacheCounts): array {
	terrainCheck($cache->height($x, $z) === $ample->height($x, $z), "tiny cache reproduces the height at $x, $z");

	return $cacheCounts($cache);
};

terrainCheck($cacheStep(-.5, -.5) === [16,0,0,16], 'a cold stencil over four tiles generates sixteen nodes');
terrainCheck($cacheStep(.5, -.5) === [20,12,0,20], 'the next cell reads twelve nodes and generates one column');
terrainCheck($cacheStep(-.25, -.75) === [20,12,0,20], 'a remembered cell reads no nodes');
terrainCheck($cache->sample(0, 0) === $ample->sample(0, 0), 'lattice sample matches through the tiny cache');
terrainCheck($cache->stats()['cacheHits'] === 17, 'a lattice sample reads five nodes');

terrainCheck($cacheStep(9.5, .5) === [36,17,2,28], 'two new tiles evict the two least recently used');
terrainCheck($cacheStep(-.5, -.5) === [36,17,2,28], 'a remembered cell outlives its evicted tiles');
terrainCheck($cacheStep(-1.5, -.5) === [50,19,5,20], 'a stencil evicts and recreates one of its own tiles');

$lease = $cache->pin([-3,-3,1,1]);
terrainCheck($cache->stats()['pinnedTiles'] === 4, 'one lease can hold the whole capacity');
terrainCheck($cacheStep(20.5, 20.5) === [66,19,5,20], 'nodes outside a full lease are generated and not stored');
terrainCheck($cacheStep(20.25, 20.75) === [66,19,5,20], 'a cell of unstored nodes is still remembered');
terrainCheck($cache->sample(20, 20) === $ample->sample(20, 20), 'lattice sample matches without storage');
terrainCheck($cache->stats()['generated'] === 71, 'an unstored lattice sample generates five nodes');
terrainCheck($cacheStep(-.5, .5) === [77,29,5,26], 'a stencil over part-filled tiles reads ten nodes and fills six');

$cache->unpin($lease);
$cache->queue(['type' => 'raise','x' => 30,'z' => 30,'radius' => 1,'amount' => 1]);
$cache->commit();
terrainCheck($cacheCounts($cache) === [77,29,5,0], 'publication drops every unleased tile');
$cache->height(-.5, -.5);
terrainCheck($cacheCounts($cache) === [93,29,5,16], 'publication forgets remembered cells');
$cache->close();

// Sixteen cached nodes around a cell that is not remembered. The stencil spans four tiles and leaves them
// most recently used in row-major order, whatever their order was before. The next evictions reveal it.
$order = new Landscape($tiny);
$order->height(2.5, 2.5);
$order->height(4.5, 2.5);
terrainCheck($cacheCounts($order) === [24,8,0,24], 'two overlapping cells share eight nodes');

foreach([[4,4],[1,4],[4,1],[1,1]] as [$x,$z]) $order->height($x, $z);
terrainCheck($order->height(3.5, 2.5) === $ample->height(3.5, 2.5), 'a cell of cached nodes reproduces the height');
terrainCheck($cacheCounts($order) === [24,28,0,24], 'sixteen cached nodes count as sixteen hits');

foreach([[40,40],[80,80],[1,4],[4,4]] as [$x,$z]) $order->height($x, $z);
terrainCheck($cacheCounts($order) === [26,30,2,8], 'new tiles evict the first two tiles of the stencil');
$order->height(4, 1);
$order->height(1, 1);
terrainCheck($cacheCounts($order) === [28,30,4,8], 'the two evicted tiles are generated again');
$order->close();

// The same order holds while a stencil fills holes: a tile that only serves cached nodes is refreshed too.
$holes = new Landscape($tiny);
$holes->height(2.5, 2.5);

foreach([[4,4],[1,4],[4,1],[1,1]] as [$x,$z]) $holes->height($x, $z);
terrainCheck($holes->height(4.5, 2.5) === $ample->height(4.5, 2.5), 'a part-cached cell reproduces the height');
terrainCheck($cacheCounts($holes) === [24,12,0,24], 'a part-cached cell reads eight nodes and generates eight');
$holes->height(40, 40);
$holes->height(1, 4);
terrainCheck($cacheCounts($holes) === [25,13,1,16], 'the eviction takes the first tile of the stencil');
$holes->close();

// Sixty-four cells are remembered, first in first out. A forgotten cell over cached nodes costs sixteen hits.
$row = new Landscape(['maxTiles' => 64] + $tiny);

for($k = 0; $k <= 64; $k++) $row->height($k + .5, .5);
terrainCheck($cacheCounts($row) === [272,768,0,272], 'each step along a row generates one new column');
$row->height(.25, .75);
terrainCheck($row->stats()['cacheHits'] === 784, 'the sixty-fifth cell displaced the first');
$row->height(2.25, .75);
terrainCheck($row->stats()['cacheHits'] === 784, 'the third cell is still remembered');
$row->height(1.25, .75);
terrainCheck($row->stats()['cacheHits'] === 800, 'returning to the first cell displaced the second');
$row->close();
$ample->close();

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
	$world = new Landscape(['version' => 1,'preset' => $preset,'worldSize' => 512,'spacing' => 1.25,'seed' => 'pick-regression',
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

// A caller that follows a moving point skips exact samples while the point stays above its block.
$sandboxWorlds = [
	['preset' => 'coast']
	, ['preset' => 'mountain','heightScale' => 1400,'baseFrequency' => 1/2200]
	, ['preset' => 'canyon','heightScale' => 650,'baseFrequency' => 1/4000,'terraceSpacing' => 80,'spacing' => 1.25]
];

foreach($sandboxWorlds as $recipe)
{
	$world = new Landscape($recipe);
	$spacing = $world->recipe()['spacing'];
	$blocks = [];
	foreach([1, 2, 16, 128] as $cells)
	for($i = 0; $i < 5; $i++)
	{
		$x = 911.3 * $i - 2500.2;
		$z = 4000.7 - 1377.9 * $i;
		$blocks[] = [$x, $z, $cells, $world->localBounds($x, $z, $cells)];
	}
	terrainCheck($world->stats()['generated'] === 0, 'block bounds generate no lattice nodes');
	$enclosed = $aligned = 0;
	foreach($blocks as [$x, $z, $cells, $block])
	{
		$width = $cells * $spacing;
		$aligned += (int)($block['minX'] <= $x && $x < $block['maxX'] && $block['minZ'] <= $z && $z < $block['maxZ']
			&& $block['maxX'] - $block['minX'] === $width && $block['maxZ'] - $block['minZ'] === $width
			&& fmod($block['minX'], $width) === 0.0 && fmod($block['minZ'], $width) === 0.0);
		// The lower edges belong to the block. The upper edges belong to its neighbours.
		foreach([[0,0],[0,.999],[.999,0],[.999,.999],[.5,.5],[.37,.81]] as [$u,$v])
		{
			$height = $world->height($block['minX'] + $u * $width, $block['minZ'] + $v * $width);
			$enclosed += (int)($height >= $block['minY'] && $height <= $block['maxY']);
		}
		$enclosed += (int)($world->height($x, $z) <= $block['maxY']);
	}
	terrainCheck($aligned === 20, "block bounds name the aligned block around the point: $aligned of 20");
	terrainCheck($enclosed === 140, "block bounds enclose the reconstructed surface: $enclosed of 140");
	terrainCheck(
		$blocks[0][3]['maxY'] < $world->bounds()['maxY'] - 100
		, 'a one-cell block bounds far below the global maximum'
	);
	[$x, $z] = [$blocks[5][0], $blocks[5][1]];
	$before = $world->localBounds($x, $z, 2);
	$far = $world->localBounds($x + 500, $z, 2);
	$world->queue(['type' => 'raise','x' => $x,'z' => $z,'radius' => 30,'amount' => 250]);
	$world->commit();
	$after = $world->localBounds($x, $z, 2);
	terrainCheck($after['maxY'] >= $world->height($x, $z) && $world->height($x, $z) > $before['maxY'],
		'block bounds rise with an edit that lifts the surface past the old bound');
	terrainCheck($world->localBounds($x + 500, $z, 2) === $far, 'an edit leaves distant block bounds unchanged');
	$refusals = [[$x, 0, 'an empty block'], [1e9, 4, 'a point outside the world'], [$x, 4, 'a closed landscape']];

	foreach($refusals as $i => [$px, $cells, $what])
	{
		if($i === 2) $world->close();

		try
		{
			$world->localBounds($px, $z, $cells);
			$refused = false;
		}
		catch(InvalidArgumentException|OutOfBoundsException|LogicException)
		{
			$refused = true;
		}

		terrainCheck($refused, "block bounds refuse $what");
	}
}

echo "Terrain surface: $checks checks passed.\n";
