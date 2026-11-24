<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/autoload.php';
require dirname(__DIR__) . '/autoload.php';

use TerrainPhonics\GrassShells;
use TerrainPhonics\Recipe;
use TerrainPhonics\TerrainAppearance;

$checks = 0;

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
	grassCheck($style['height'] > 0. && $style['height'] <= 1.5, "$name grows a strand of sensible height");
	grassCheck($style['density'] >= 1. && $style['density'] <= 16., "$name packs one to sixteen strands a meter");
	grassCheck($style['radius'] > 0. && $style['radius'] <= .5, "$name keeps each strand inside its cell");
	grassCheck($style['root'] > .3 && $style['root'] < 1., "$name darkens the ground between its stalks");

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

	// A strand that crosses every shell ends at the tip brightness, whatever the shell count.
	foreach([1, 2] as $quality)
	{
		$shells = GrassShells::shells($style, $quality);
		$gain = GrassShells::gain($style, $shells);

		grassCheck(
			$shells >= GrassShells::FEWEST && $shells <= GrassShells::MOST && ($shells === GrassShells::MOST || $style[
				'height'
			] / $shells <= GrassShells::SLAB[$quality] + 1e-9 || $shells === GrassShells::FEWEST
			)
			, "$name gets shells thin enough for quality $quality"
		);

		foreach($gain as $channel => $factor)
		grassCheck(
			abs($style['root'] * $factor ** $shells - $style['tip'][$channel]) < 1e-9 && $factor > 1.
				&& $factor < 2.
			, "$name reaches its tip color through $shells shells"
		);
	}

	grassCheck(
		GrassShells::shells($style, 0) === 0 && GrassShells::shells($style, 1) <= GrassShells::shells($style, 2)
		, "$name draws nothing when off and no fewer shells at high quality"
	);
}

grassCheck(GrassShells::style('canyon') === null && GrassShells::style('') === null, 'Bare surfaces grow nothing');

grassCheck(
	GrassShells::shells(GrassShells::style('rolling-prairie'), 2) > GrassShells::shells(
		GrassShells::style('chalk-headlands')
		, 2
	)
	, 'Tall grass gets more shells than short turf'
);

// The radius is the room the finest grid leaves around the eye, up to the reach, and nothing when the room is small.
grassCheck(GrassShells::radius([-28., -28., 28., 28.], [0., 5., 0.]) === 28., 'A centered eye uses the whole grid');

grassCheck(
	GrassShells::radius([-28., -28., 28., 28.], [10., 5., -4.]) === 18.
	, 'An off-center eye uses the nearest edge'
);

grassCheck(GrassShells::radius([-200., -200., 200., 200.], [0., 5., 0.]) === 56., 'A wide grid is capped at the reach');
grassCheck(GrassShells::radius([-28., -28., 28., 28.], [25., 5., 0.]) === 0., 'An eye near the edge draws nothing');
grassCheck(GrassShells::radius([-28., -28., 28., 28.], [40., 5., 0.]) === 0., 'An eye outside the grid draws nothing');

echo "Terrain grass shells: $checks checks passed.\n";
