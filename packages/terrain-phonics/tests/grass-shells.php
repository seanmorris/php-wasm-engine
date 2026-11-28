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

// Past the blades, grass standing in snow is marked with dots. They need snow, and they reach past the blades.
grassCheck(GrassShells::dotted(0.) === 0. && GrassShells::dotted(.1) === 0., 'Bare ground has no distant dots');
grassCheck(GrassShells::dotted(.5) > 0. && GrassShells::dotted(1.) <= 1., 'Snow marks distant grass with dots');

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
		&& str_contains($grassSource, 'bool passing = above > .5*top + .1;')
	, 'A ray that leaves the grass again casts no shade, and the surface is never drawn from underneath'
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

echo "Terrain grass shells: $checks checks passed.\n";
