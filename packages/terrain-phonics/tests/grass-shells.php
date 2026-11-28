<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/autoload.php';

require dirname(__DIR__) . '/autoload.php';

use TerrainPhonics\GrassShells;
use TerrainPhonics\Recipe;
use TerrainPhonics\TerrainAppearance;

$checks = 0;

/**
 * Count a passing check or stop at the first failure.
 * @param bool $valid Whether the check passed.
 * @param string $message What the check establishes.
 * @return void Throws when the check fails.
 */
function grassCheck(bool $valid, string $message): void
{
	global $checks;

	if(!$valid) throw new RuntimeException($message);

	$checks++;
}

// Every style names a surface the sandbox can report, and its settings stay inside what the shaders expect.
$surfaces = array_merge(Recipe::PRESETS, array_keys(TerrainAppearance::USES));

foreach(array_keys(GrassShells::STYLES) as $name)
{
	$style = GrassShells::style($name);

	grassCheck(in_array($name, $surfaces, true), "$name is a preset or a surface style");
	grassCheck($style['height'] > 0. && $style['height'] <= 1.5, "$name grows a blade of sensible height");
	grassCheck($style['density'] >= 1. && $style['density'] <= 24., "$name packs one to twenty-four blades a meter");

	// A blade much wider than its cell would be cut off at the cell's edge.
	grassCheck(
		$style['width'] > 0. && $style['width'] * $style['density'] <= .75
		, "$name keeps each blade inside its cell"
	);

	grassCheck($style['lean'] >= 0. && $style['lean'] <= 1., "$name leans no further than it is tall");
	grassCheck($style['root'] > .3 && $style['root'] < 1., "$name darkens the ground between its blades");

	// The blend can at most double the ground's light.
	grassCheck(
		count($style['tip']) === 3 && min($style['tip']) >= .8 && max($style['tip']) <= 1.6
		, "$name brightens its tips by a displayable amount"
	);

	grassCheck(
		$style['slope'][0] < $style['slope'][1] && $style['slope'][1] <= 1.
		, "$name thins before it ends on a slope"
	);

	grassCheck($style['above'][0] < $style['above'][1], "$name starts above the water before it is full");
	grassCheck($style['top'] === null || $style['top'][0] < $style['top'][1], "$name thins before its upper limit");
}

grassCheck(GrassShells::style('canyon') === null && GrassShells::style('') === null, 'Bare surfaces grow nothing');

grassCheck(
	GrassShells::steps(0) === 0 && GrassShells::steps(1) > 0 && GrassShells::steps(2) > GrassShells::steps(1)
	&& GrassShells::steps(9) === 0
	, 'Quality steps from off upward, and an unknown level draws nothing'
);

// The radius is the room the finest grid leaves around the eye, up to the reach, and nothing when the room is small.
grassCheck(GrassShells::radius([-28., -28., 28., 28.], [0., 5., 0.]) === 28., 'A centered eye uses the whole grid');

grassCheck(
	GrassShells::radius([-28., -28., 28., 28.], [10., 5., -4.]) === 18.
	, 'An off-center eye uses the nearest edge'
);

grassCheck(
	GrassShells::radius([-200., -200., 200., 200.], [0., 5., 0.]) === 120.
	, 'A wide grid is capped at the reach'
);

grassCheck(GrassShells::radius([-28., -28., 28., 28.], [25., 5., 0.]) === 0., 'An eye near the edge draws nothing');
grassCheck(GrassShells::radius([-28., -28., 28., 28.], [40., 5., 0.]) === 0., 'An eye outside the grid draws nothing');

// Snow hides blades in place. Thinning by the spacing of the lattice moved every blade as the cover changed.
grassCheck(
	GrassShells::buried(0.) === 0. && GrassShells::buried(1.) === .9 && GrassShells::buried(4.) === .9
	&& GrassShells::buried(-1.) === 0.
	, 'Snow hides up to nine blades in ten'
);

grassCheck(GrassShells::buried(.25) < GrassShells::buried(.5), 'Deeper snow hides more blades');

$grassSource = (new ReflectionClass(GrassShells::class))->getReflectionConstant('FRAGMENT')->getValue();

grassCheck(str_contains($grassSource, 'uBuried'), 'The blade pass hides snowed-under blades by cell');

// Past the blades, grass is marked with dots, on bare ground and on snow.

// The dots are laid out once. Every block's dot is the dot of the smaller block it keeps, so the dots stay put as the
// blocks change size.
$dotTable = GrassShells::dotTable();
grassCheck(strlen($dotTable) === 4 * 64 * 64 * 16, 'The dot table holds 64 by 64 blocks for each of 16 sizes');

$dotAt = static fn(int $size, int $x, int $y): array => array_values(
	unpack('C4', substr($dotTable, 4 * ($size * 4096 + ($y & 63) * 64 + ($x & 63)), 4))
);

$dotDrift = 0.;

for($size = 1; $size < 16; $size++)
for($y = 0; $y < 64; $y += 5)
for($x = 0; $x < 64; $x += 5)
{
	[$px,$py,$tone,$quarter] = $dotAt($size, $x, $y);
	[$cx,$cy,$childTone] = $dotAt($size - 1, 2 * $x + ($quarter & 1), 2 * $y + ($quarter >> 1));

	$dotDrift = max(
		$dotDrift
		, abs(($px + .5) / 256 - (($quarter & 1) + ($cx + .5) / 256) / 2)
		, abs(($py + .5) / 256 - (($quarter >> 1) + ($cy + .5) / 256) / 2)
		, $tone === $childTone ? 0. : 1.
	);
}

grassCheck($dotDrift < .002, 'A block and the smaller block it keeps put the dot in the same place');

grassCheck(
	GrassShells::radius([-500, -500, 500, 500], [0, 0, 0]) === 120.
		&& GrassShells::radius([-2000, -2000, 2000, 2000], [0, 0, 0], 1200.) === 1200.
		&& GrassShells::radius([-500, -500, 500, 500], [300, 0, 0], 1200.) === 200.
	, 'The dots reach as far as the grid leaves room, past the blades'
);

grassCheck(
	str_contains($grassSource, 'dressedAt(vWorld.xz + run)') && str_contains($grassSource, 'texelFetch(uInk')
	, 'The pass reads the paint under the ground each pixel shows'
);

grassCheck(
	!str_contains($grassSource, 'vRoad.x/vRoad.w') && str_contains($grassSource, 'dot(abs(vArms')
	, 'Road arms that meet keep their own distances'
);

$grassVertex = (new ReflectionClass(GrassShells::class))->getReflectionConstant('VERTEX')->getValue();

grassCheck(
	str_contains($grassSource, 'if(dot(ray, normalize(vNormal)) >= 0.) discard;')
		&& str_contains($grassSource, 'passing = above > .5*top + .1;')
	, 'A ray that leaves the grass again casts no shade, and the surface is never drawn from underneath'
);


grassCheck(
	str_contains($grassVertex, 'vInked = road.y >= 0. ? 1. : 0.;')
		&& str_contains($grassSource, 'if(vInked > 0. || dressed > 0.) {')
	, 'Pixels with no paint at either end of the ray look up none along it'
);

grassCheck(
	!str_contains($grassVertex, 'road.y < 4. ? 1. : 0.') && str_contains($grassSource, 'dressedMid')
	, 'The raised surface does not drop over paint, and each blade is held against the paint at its place'
);


grassCheck(
	str_contains($grassSource, 'uDots') && str_contains($grassSource, 'dotAt(')
	, 'The pass draws a dot where a distant blade stands'
);

grassCheck(
	str_contains($grassSource, 'uSnowCover') && str_contains($grassSource, 'uGrass')
	, 'A blade standing in snow keeps the grass color'
);

$grassDraw = file_get_contents((new ReflectionMethod(GrassShells::class, 'draw'))->getFileName());

grassCheck(
	!preg_match('/\$style\[\'density\'\]\s*\*/', $grassDraw)
	, 'Weather never changes the spacing of the blade lattice'
);

// Grass keeps off the layers a style names as bare, and follows them when they are edited or removed.
$coast = (new TerrainPhonics\Landscape(['preset' => 'coast']))->recipe();
$layers = TerrainPhonics\SurfaceLayers::landscape($coast, new TerrainAppearance([]));
$sand = current(array_filter($layers->layers, static fn(array $row): bool => $row['id'] === 'sand'));
$cleared = GrassShells::clear(GrassShells::style('coast'), $layers);

grassCheck(
	$sand !== false && $cleared['above'] === [$sand['high'] + .5 * $sand['fade'], $sand['high'] + .9 * $sand['fade']]
	, 'Coast grass starts where half the sand has faded and is full where a tenth is left'
);

grassCheck(
	$cleared['above'][0] > 10. && GrassShells::style('coast')['above'] === [.5, 2.]
	, 'The sand reaches well above the grass\'s own start'
);

$rows = $layers->layers;

foreach($rows as $n => $row) if($row['id'] === 'sand') $rows[$n] = ['high' => 1., 'fade' => 2.] + $row;

$lower = new TerrainPhonics\SurfaceLayers($rows, $layers->fields, $layers->bounce);

grassCheck(
	GrassShells::clear(GrassShells::style('coast'), $lower)['above'] === [2., 2.8]
	, 'Lowering the sand lowers the grass'
);

$none = new TerrainPhonics\SurfaceLayers(
	array_values(array_filter($rows, static fn(array $row): bool => $row['id'] !== 'sand'))
	, $layers->fields
	, $layers->bounce
);

grassCheck(
	GrassShells::clear(GrassShells::style('coast'), $none) === GrassShells::style('coast')
	, 'Without sand the grass keeps its own start'
);

grassCheck(
	GrassShells::clear(GrassShells::style('mountain'), $layers) === GrassShells::style('mountain')
	, 'A style with no bare layers is left alone'
);

grassCheck(GrassShells::clear(null, $layers) === null, 'Where no grass grows there is nothing to clear');

foreach(GrassShells::STYLES as $name => $style)
grassCheck(is_array((GrassShells::style($name))['bare']), "The $name style lists its bare layers");

echo "Terrain grass shells: $checks checks passed.\n";
