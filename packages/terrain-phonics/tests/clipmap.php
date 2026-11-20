<?php
declare(strict_types=1);

use TerrainPhonics\{Clipmap, Landscape, Recipe, SurfaceLayers};

require dirname(__DIR__) . '/autoload.php';

function check(bool $valid, string $message): void
{
	if(!$valid) throw new RuntimeException($message);
}

foreach(['coast', 'mountain', 'canyon'] as $preset)
{
	foreach([1, 2] as $version)
	{
		$fromPreset = new Recipe([
			'preset' => $preset, 'version' => $version, 'seed' => 'shape-test',
			'terraceSpacing' => $preset === 'canyon' ? 80 : 0,
		]);
		$manual = new Recipe(array_replace($fromPreset->values(), ['preset' => 'coast']));
		$bounds = [-3072, -800, 900, 2300];
		foreach([[-3072, -800], [-800, 0], [0, 0], [350, 2100], [900, 2300]] as [$x, $z])
		{
			check($fromPreset->height($x, $z) === $manual->height($x, $z), 'Preset labels must have no effect on elevation.');
			check($fromPreset->coarse($x, $z) === $manual->coarse($x, $z), 'Preset labels must have no effect on the macro gradient.');
		}
		check($fromPreset->regionalBounds($bounds) === $manual->regionalBounds($bounds), 'Preset labels must have no effect on regional bounds.');
	}
}

$mixed = new Recipe([
	'preset' => 'coast', 'seed' => 'terraced-coast', 'heightScale' => 580,
	'wallHeight' => .8, 'wallWidth' => .006, 'terraceSpacing' => 68, 'bedding' => .75,
]);
[$low, $high] = $mixed->regionalBounds([-900, -400, 1200, 1800]);
for($x = -900; $x <= 1200; $x += 70)
for($z = -400; $z <= 1800; $z += 55)
{
	$height = $mixed->height($x, $z);
	check($height >= $low && $height <= $high, 'Mixed shaping terms must stay inside regional bounds.');
}

$ground = new Landscape(['worldSize' => 512, 'spacing' => 1, 'heightScale' => 0, 'detailAmplitude' => 0]);
$ground->queue(['type' => 'raise', 'x' => 32, 'z' => -40, 'radius' => 12, 'amount' => 3]);
check($ground->editIndex()->intersect([28, -45, 34, -38]) === [], 'Uncommitted edits are invisible.');
$ground->commit();
check($ground->editIndex()->intersect([45, -41, 46, -39]) === [[45, -41, 46.0, -39]], 'Edited cells include the reconstruction halo.');
check($ground->editIndex()->intersect([-90, -41, -22, -39]) === [], 'Unrelated cells use the natural field.');
$ground->undo();
check($ground->editIndex()->intersect([28, -45, 34, -38]) === [], 'Undo invalidates the edit footprint.');
$ground->redo();
check(count($ground->editIndex()->intersect([28, -45, 34, -38])) === 1, 'Redo restores the edit footprint.');
$ground->close();

foreach([[-8, -8], [3, -9], [-16, 2], [2, 4], [100, -100]] as [$x, $z])
{
	$old = [-6, -6];
	$size = 16;
	$filled = [];
	foreach(Clipmap::exposed($x, $z, $size, $old) as [$left, $front, $right, $back])
	for($column = $left; $column < $right; ++$column)
	for($row = $front; $row < $back; ++$row)
	{
		$key = "$column:$row";
		check(!isset($filled[$key]), 'Exposed strips must not write the same cell twice.');
		$filled[$key] = true;
	}
	for($column = $x; $column < $x + $size; ++$column)
	for($row = $z; $row < $z + $size; ++$row)
	{
		$shared = $column >= $old[0] && $column < $old[0] + $size && $row >= $old[1] && $row < $old[1] + $size;
		check(isset($filled["$column:$row"]) === !$shared, 'Exposed strips must write every new cell exactly once.');
	}
}

$layers = new SurfaceLayers([
	['color' => '#443322'],
	['color' => '#996644', 'low' => -200, 'high' => 100],
	['color' => '#ffffff', 'parent' => 1, 'slopeHigh' => .25, 'datum' => 'water'],
]);
check((new SurfaceLayers(json_decode(json_encode($layers->layers), true)))->layers === $layers->layers,
	'Nested masks must round-trip through the save format.');
foreach([['parent' => 1], ['scale' => 0], ['slopeHigh' => 1.2], ['color' => 'black'], ['low' => NAN]] as $invalid)
{
	try
	{
		new SurfaceLayers([[], $invalid]);
		throw new RuntimeException('Invalid layer masks must be rejected.');
	}
	catch(InvalidArgumentException) {}
}
echo "Clipmap and expanded terrain recipe checks passed.\n";
