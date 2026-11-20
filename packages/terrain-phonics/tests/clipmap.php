<?php
declare(strict_types=1);

use TerrainPhonics\{CanyonBeds, Clipmap, Landscape, Recipe, SurfaceLayers, TerrainAppearance};

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
	['color' => '#ffffff', 'parent' => 1, 'slopeHigh' => .25, 'datum' => 'water',
		'pattern' => 'gravel', 'patternColor' => '#f0bcc8', 'patternScale' => 4.5, 'patternAmount' => .6],
]);
check((new SurfaceLayers(json_decode(json_encode($layers->layers), true)))->layers === $layers->layers,
	'Nested masks must round-trip through the save format.');
check($layers->layers[0]['pattern'] === 'none' && $layers->layers[0]['patternColor'] === '#443322',
	'Older colour layers retain their solid fill.');
foreach([
	['parent' => 1], ['scale' => 0], ['slopeHigh' => 1.2], ['color' => 'black'], ['low' => NAN],
	['pattern' => 'unexpected'], ['patternColor' => 'black'], ['patternAmount' => 1.1],
	['patternScale' => 0], ['patternStretch' => -1],
] as $invalid)
{
	try
	{
		new SurfaceLayers([[], $invalid]);
		throw new RuntimeException('Invalid layer masks must be rejected.');
	}
	catch(InvalidArgumentException) {}
}
$appearance = new TerrainAppearance;
$coast = SurfaceLayers::landscape((new Recipe(['preset' => 'coast']))->values(), $appearance)->layers;
check($coast[0]['color'] === $appearance->settings['grassColor']
	&& abs($coast[1]['slopeLow'] - $coast[1]['slopeFade'] - $appearance->settings['rockSlopeStart']) < 1e-9,
	'Rock starts to cover coastal grass only when the slope reaches the configured threshold.');
$canyonRecipe = (new Recipe(['preset' => 'canyon', 'terraceSpacing' => 80]))->values();
$bands = array_values(array_filter(SurfaceLayers::landscape($canyonRecipe, $appearance)->layers,
	static fn(array $layer) => $layer['datum'] === 'beds'));
check(count($bands) === 7, 'The rock material covers every bed in the canyon profile.');
foreach($bands as $i => $band)
{
	check(abs($band['low'] - 80 * (CanyonBeds::CONTACTS[$i] + 5)) < 1e-9
		&& abs($band['high'] - 80 * (CanyonBeds::CONTACTS[$i + 1] + 5)) < 1e-9
		&& $band['period'] === 800.0, 'Rock bands align with the authored ledge heights.');
	check($band['slopeLow'] - $band['slopeFade'] > 0, 'Repeating bands are masked off level ground.');
}
check(count(array_unique(array_column($bands, 'color'))) === 6, 'Sandstone repeats and the other beds use distinct colours.');
$muted = SurfaceLayers::landscape($canyonRecipe, new TerrainAppearance(['bandContrast' => 0, 'bandSharpness' => 4]));
$mutedBands = array_slice($muted->layers, 1, 7);
check(count(array_unique(array_column($mutedBands, 'color'))) === 1
	&& abs(4 * $mutedBands[0]['fade'] - $bands[0]['fade']) < 1e-9, 'Band contrast and sharpness drive the editable layers.');
$v1 = SurfaceLayers::landscape(['preset' => 'canyon', 'version' => 1], $appearance)->layers;
check($v1[1]['datum'] === 'absolute' && $v1[1]['period'] === $appearance->settings['bandSpacing'],
	'Early canyons retain horizontal bands.');
echo "Clipmap and expanded terrain recipe checks passed.\n";
