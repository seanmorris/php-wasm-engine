<?php
declare(strict_types=1);

use TerrainPhonics\{CanyonBeds, Clipmap, Landscape, Recipe, SurfaceLayers, TerrainAppearance};

require dirname(__DIR__) . '/autoload.php';

function check(bool $valid, string $message): void
{
	if(!$valid) throw new RuntimeException($message);
}

foreach([0., M_PI/8, M_PI/4, M_PI/2, 7*M_PI/8] as $bearing)
{
	$diagonal = abs(cos($bearing)) + abs(sin($bearing));
	foreach([[0,0], [7200,-7200], [12000,400], [-1400,-12000]] as $position)
	{
		$room = 16384. - max(abs($position[0]), abs($position[1]));
		$span = Clipmap::chartSpan(32768., $position, $bearing, true);
		check($span > 4000. && $span <= 12000. && $span/2*$diagonal <= $room + 1e-9,
			'The wide chart shows more ground and stays filled at every heading away from the edges.');
	}
	$edge = [16000,15000];
	check(Clipmap::chartSpan(32768., $edge, $bearing, true) === 6000.
		&& Clipmap::chartSpan(32768., $edge, $bearing, false) === 4000.,
		'Very close to a boundary, the wide chart still shows 50% more than the local chart.');
	$small = Clipmap::chartSpan(128., [0,0], $bearing, true);
	check($small > Clipmap::chartSpan(128., [0,0], $bearing, false) && $small/2*$diagonal <= 64. + 1e-9,
		'A small world still has two distinct zoom levels and fills the wide view at a diagonal heading.');
}

foreach(Recipe::PRESETS as $preset)
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
$emptyHeights = $ground->heightEditIndex();
check($emptyHeights->isEmpty(), 'Pending edits cannot affect driving heights.');
$ground->commit();
$heights = $ground->heightEditIndex();
check($heights !== $emptyHeights && $heights === $ground->heightEditIndex()
	&& $heights->intersect([45,-41,46,-39]), 'A revision shares its height index and interpolation halo.');
check($ground->editIndex()->intersect([45, -41, 46, -39]) === [[45, -41, 46.0, -39]], 'Edited cells include the reconstruction halo.');
check($ground->editIndex()->intersect([-90, -41, -22, -39]) === [], 'Unrelated cells use the natural field.');
$ground->undo();
check($ground->heightEditIndex()->isEmpty(), 'Undo removes the committed height footprint.');
check($ground->editIndex()->intersect([28, -45, 34, -38]) === [], 'Undo invalidates the edit footprint.');
$ground->redo();
check(count($ground->editIndex()->intersect([28, -45, 34, -38])) === 1, 'Redo restores the edit footprint.');
$roadPoint = [-11, 0, -11, 0];
$ground->queue(['type' => 'road', 'points' => [[-20, 7, 0], [0, 7, 0]]]);
check(!$ground->roads(), 'Staged roads have no GPU records.');
$ground->commit();
check(count($ground->roads()) === 1 && $ground->editIndex()->intersect($roadPoint)
	&& !$ground->editIndex(false)->intersect($roadPoint), 'The clipmap can delegate only the road to the GPU.');
$ground->queue(['type' => 'raise', 'x' => -11, 'z' => 0, 'radius' => 2, 'amount' => 3]);
$ground->commit();
check($ground->editIndex(false)->intersect($roadPoint) && $ground->height(-11, 0) === 10.,
	'The overlapping brush supplies the fully composed road and brush height.');
$ground->undo();
$ground->undo();
check(!$ground->roads(), 'Undo removes the last road from the GPU input.');
$ground->redo();
check(count($ground->roads()) === 1, 'Redo restores its normalized planes.');
$ground->queue(['type'=>'material','material'=>'grass','x'=>180,'z'=>180,'radius'=>10]);
$ground->commit();
check($ground->heightEditIndex()->intersect($roadPoint) && !$ground->heightEditIndex()->intersect([179,179,181,181])
	&& $ground->editIndex()->intersect([179,179,181,181]), 'Driving sees the road and rendering also sees the grass paint.');
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
	'Older color layers retain their solid fill.');
foreach([
	['parent' => 1], ['scale' => 0], ['slopeHigh' => 1.2], ['color' => 'black'], ['low' => NAN],
	['pattern' => 'unexpected'], ['patternColor' => 'black'], ['patternAmount' => 1.1],
	['patternScale' => 0], ['patternStretch' => -1], ['reflectivity'=>1.1], ['roughness'=>-.1], ['roughness'=>NAN],
] as $invalid)
{
	try
	{
		new SurfaceLayers([[], $invalid]);
		throw new RuntimeException('Invalid layer masks must be rejected.');
	}
	catch(InvalidArgumentException) {}
}
foreach([
	[['id'=>'sand'],['id'=>'sand']], [['channel'=>'relief']], [[],['parent'=>'missing']],
	[[],['strength'=>NAN]], [[],['flatten'=>1.5]], [[],['mask'=>'missing']],
] as $rows) {
	try { new SurfaceLayers($rows); throw new RuntimeException('Expected an invalid material recipe.'); }
	catch(InvalidArgumentException) {}
}
$parented = new SurfaceLayers([['id'=>'sand'],['parent'=>'sand','channel'=>'relief','relief'=>'pattern','pattern'=>'ripples']]);
check($parented->layers[1]['parent'] === 0,'A saved mask ID resolves to its earlier layer.');
$retuned = $parented->layers;
$retuned[1]['strength'] = .25;
check((new SurfaceLayers($retuned))->structure === $parented->structure,'Relief strength is a uniform-only edit.');
$retuned[1]['relief'] = 'facets';
check((new SurfaceLayers($retuned))->structure !== $parented->structure,'A new relief operator selects its shader program.');
$appearance = new TerrainAppearance;
$coast = SurfaceLayers::landscape((new Recipe(['preset' => 'coast']))->values(), $appearance)->layers;
check($coast[0]['color'] === $appearance->settings['grassColor']
	&& abs($coast[1]['slopeLow'] - $coast[1]['slopeFade'] - $appearance->settings['rockSlopeStart']) < 1e-9,
	'Rock starts to cover coastal grass only when the slope reaches the configured threshold.');
$limestone = (new Recipe(['preset' => 'limestone']))->values();
$karst = SurfaceLayers::landscape($limestone, $appearance)->layers;
$stone = array_search('veins', array_column($karst, 'pattern'), true);
check($karst[0]['pattern'] === 'fractal' && $karst[1]['patternScale'] < $karst[0]['patternScale']
	&& $karst[0]['patternColor'] !== $karst[0]['color'], 'Limestone vegetation has separate grass and canopy scales.');
check($stone !== false && $karst[$stone]['parent'] >= 0 && $karst[$stone]['patternStretch'] > 1.
	&& $karst[$karst[$stone]['parent']]['color'] === $appearance->settings['rockColor'],
	'Limestone veins follow the exposed rock mask.');
$raised = SurfaceLayers::landscape(array_replace($limestone, ['baseBias' => .5]), $appearance)->layers;
check($raised[1]['high'] - $karst[1]['high'] === $limestone['heightScale'] * .5,
	'The grass transition follows the floor when its base elevation changes.');
check((new SurfaceLayers($karst))->layers === $karst, 'Authored limestone layers round-trip with both procedural patterns.');
$fjord = (new Recipe(['preset'=>'basalt-fjords']))->values();
$cliffs = SurfaceLayers::landscape($fjord,$appearance)->layers;
$flow = array_column($cliffs,null,'id')['lava-beds'];
check($cliffs[$flow['parent']]['id'] === 'basalt-faces' && $flow['pattern'] === 'contacts' && $flow['period'] === 0.,
	'Fjord flow contacts follow exposed basalt without a repeating elevation mask.');
check($fjord['terraceStrength'] === 0.
	&& SurfaceLayers::landscape(array_replace($fjord,['terraceStrength'=>.4,'terraceSpacing'=>60.]),$appearance)->layers === $cliffs,
	'Fjord flow thickness is independent of optional geometric terraces.');
$dunes = (new Recipe(['preset'=>'amber-dunes']))->values();
$sand = array_column(SurfaceLayers::landscape($dunes,$appearance)->layers,null,'id');
$lifted = SurfaceLayers::landscape(array_replace($dunes,['baseBias'=>$dunes['baseBias']+1.]),$appearance);
check(abs(array_column($lifted->layers,'high','id')['salt-crust']-$sand['salt-crust']['high']-$dunes['heightScale'])<1e-8
	&& $sand['salt-crust']['flatten'] === 1. && $sand['wind-ripples']['channel'] === 'relief',
	'Salt pans follow the elevation of the dune field and suppress sand ripple relief.');
$arctic = SurfaceLayers::landscape((new Recipe(['preset'=>'arctic-meltwater']))->values(),
	new TerrainAppearance(TerrainAppearance::PRESET_DEFAULTS['arctic-meltwater']))->layers;
$ice = array_column($arctic,null,'id');
check(!$layers->reflective && $layers->layers[0]['reflectivity'] === 0., 'Earlier material stacks default to matte.');
$coated = new SurfaceLayers([['reflectivity'=>1.,'roughness'=>.18]]);
check($coated->reflective && $coated->structure !== (new SurfaceLayers([[]]))->structure
	&& $coated->structure === (new SurfaceLayers([['reflectivity'=>.4,'roughness'=>.75]]))->structure
	&& !(new SurfaceLayers([[],['channel'=>'relief','reflectivity'=>1.]]))->reflective,
	'Reflective color layers enable the finish shader. Tuning a coating reuses its program.');
check($ice['glacier-ice']['datum'] === 'water' && $ice['glacier-ice']['pattern'] === 'fractures'
	&& $ice['glacier-ice']['reflectivity'] === 1. && $ice['wind-packed-snow']['reflectivity'] === 0.
	&& $ice['wind-packed-snow']['slopeHigh'] < $ice['rock-outcrops']['slopeLow']
	&& $arctic[$ice['snow-ripples']['parent']]['id'] === 'wind-packed-snow',
	'Blue ice follows the channel banks and snow ripples use the gentler snow-covered slopes.');
$lagoon = SurfaceLayers::landscape((new Recipe(['preset'=>'tidal-lagoon']))->values(),
	new TerrainAppearance(TerrainAppearance::PRESET_DEFAULTS['tidal-lagoon']))->layers;
$cays = array_column($lagoon,null,'id');
check($cays['seagrass-beds']['datum'] === 'water' && $cays['seagrass-beds']['high'] < 0
	&& $cays['cay-scrub']['datum'] === 'water' && $cays['cay-scrub']['low'] > 0
	&& $lagoon[$cays['sand-ripples']['parent']]['id'] === 'shoal-sand',
	'Lagoon vegetation straddles the waterline and underwater sand carries the ripple relief.');
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
check(count(array_unique(array_column($bands, 'color'))) === 6, 'Sandstone repeats and the other beds use distinct colors.');
$muted = SurfaceLayers::landscape($canyonRecipe, new TerrainAppearance(['bandContrast' => 0, 'bandSharpness' => 4]));
$mutedBands = array_slice($muted->layers, 1, 7);
check(count(array_unique(array_column($mutedBands, 'color'))) === 1
	&& abs(4 * $mutedBands[0]['fade'] - $bands[0]['fade']) < 1e-9, 'Band contrast and sharpness drive the editable layers.');
$v1 = SurfaceLayers::landscape(['preset' => 'canyon', 'version' => 1], $appearance)->layers;
check($v1[1]['datum'] === 'absolute' && $v1[1]['period'] === $appearance->settings['bandSpacing'],
	'Early canyons retain horizontal bands.');
foreach([.5, 1., 2.] as $quality)
{
	foreach([$canyonRecipe, (new Recipe(['preset' => 'coast']))->values()] as $recipe)
	{
		$spans = Clipmap::spans($recipe, $quality);
		foreach($spans as $level => $span)
		{
			check($span % 4 === 0, 'Every clipmap edge lands on an even shared parent cell.');
			if($level === 0) continue;

			check($span / 2 - Clipmap::transition($span) >= $spans[$level - 1] / 4 + 4,
				'Fine rings join the parent before its own transition begins.');
		}
	}
}
$spanAtCanyonWall = Clipmap::spans($canyonRecipe, 1.)[4];
check(($spanAtCanyonWall / 2 - Clipmap::transition($spanAtCanyonWall) - 3) * 16 >= 1500,
	'The balanced profile covers a canyon wall 1.5 km away at 16 meter spacing before transitioning.');
$doubleWidth = new Landscape(['worldSize' => 65536, 'heightScale' => 0, 'detailAmplitude' => 0]);
check($doubleWidth->height(32768, -32768) === 0.0, 'The expanded corner is available to physics.');
check(Landscape::load($doubleWidth->save())->recipe()['worldSize'] === 65536.0, 'The expanded extent survives save and reload.');
try
{
	new Landscape(['worldSize' => 131072]);
	throw new RuntimeException('The recipe accepted an extent beyond 65,536 meters.');
}
catch(InvalidArgumentException) {}
$wideRecipe = array_replace($canyonRecipe, ['worldSize' => 65536.0]);
check(Clipmap::spans($wideRecipe, 1.) === Clipmap::spans($canyonRecipe, 1.),
	'The wider playable canyon uses the same visible clipmap layout.');
echo "Clipmap and expanded terrain recipe checks passed.\n";
