<?php
declare(strict_types=1);

use TerrainPhonics\{CanyonBeds, Clipmap, Landscape, Recipe, RoadsideDetail, SurfaceLayers, TerrainAppearance};

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
foreach([8,16,24] as $oldSize)
foreach([[16,$oldSize],[9,24],[22,7]] as [$rows,$oldRows])
{
	$old = [-6, -6];
	$size = 16;
	$filled = [];
	foreach(Clipmap::exposed($x, $z, $size, $old, $oldSize,$rows,$oldRows) as [$left, $front, $right, $back])
	for($column = $left; $column < $right; ++$column)
	for($row = $front; $row < $back; ++$row)
	{
		$key = "$column:$row";
		check(!isset($filled[$key]), 'Exposed strips must not write the same cell twice.');
		$filled[$key] = true;
	}
	for($column = $x; $column < $x + $size; ++$column)
	for($row = $z; $row < $z + $rows; ++$row)
	{
		$shared = $column >= $old[0] && $column < $old[0] + $oldSize && $row >= $old[1] && $row < $old[1] + $oldRows;
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
$retuned = $parented->layers;
$retuned[1]['parent'] = -1;
check((new SurfaceLayers($retuned))->structure !== $parented->structure,'Removing an advanced layer parent changes its shader lookup.');
$retuned = $parented->layers;
$retuned[1]['high'] = 120.;
$limited = new SurfaceLayers($retuned);
check($limited->structure !== $parented->structure,'Adding elevation bounds enables the range calculation.');
$retuned[1]['high'] = 150.;
check((new SurfaceLayers($retuned))->structure === $limited->structure,'Adjusting an existing elevation bound reuses the program.');
$section = new SurfaceLayers([['reflectivity'=>.2],['period'=>10.,'low'=>0.,'high'=>5.],['period'=>10.,'low'=>5.,'high'=>10.]]);
$retuned = $section->layers;
$retuned[2]['low'] = 6.;
check($section->section(2) === 2 && (new SurfaceLayers($retuned))->structure !== $section->structure,
	'Breaking a full repeating section changes how its color and coating are composed.');
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
$coated = new SurfaceLayers([['reflectivity' => 1.,'roughness' => .18]]);

check(
	$coated->reflective && $coated->structure !== (new SurfaceLayers([[]]))->structure && $coated->structure === (
		new SurfaceLayers([['reflectivity' => .4,'roughness' => .75]])
	)->structure && !(new SurfaceLayers([
		[]
		, [
			'channel' => 'relief'
			, 'reflectivity' => 1.
		]
	]))->reflective
	, 'Reflective color layers enable the finish shader. Tuning a coating reuses its program.'
);

check(
	$ice['glacier-ice']['datum'] === 'water' && $ice['glacier-ice']['pattern'] === 'fractures' && $ice['glacier-ice'][
		'reflectivity'
	] === 1. && $ice['wind-packed-snow']['reflectivity'] === 0. && $ice['wind-packed-snow'][
		'slopeHigh'
	] < $ice['rock-outcrops']['slopeLow'] && $arctic[$ice['snow-ripples']['parent']][
		'id'
	] === 'wind-packed-snow'
	, 'Blue ice follows the channel banks and snow ripples use the gentler snow-covered slopes.'
);

$lagoon = SurfaceLayers::landscape(
	(new Recipe(['preset' => 'tidal-lagoon']))->values()
	, new TerrainAppearance(TerrainAppearance::PRESET_DEFAULTS['tidal-lagoon'])
)->layers;

$cays = array_column($lagoon, null, 'id');

check(
	$cays['seagrass-beds']['datum'] === 'water' && $cays['seagrass-beds']['high'] < 0 && $cays['cay-scrub'][
		'datum'
	] === 'water' && $cays['cay-scrub']['low'] > 0 && $lagoon[$cays['sand-ripples']['parent']][
		'id'
	] === 'shoal-sand'
	, 'Lagoon vegetation straddles the waterline and underwater sand carries the ripple relief.'
);

$mesas = (new Recipe(['preset' => 'red-mesas']))->values();
$mesaLayers = SurfaceLayers::landscape($mesas, new TerrainAppearance(TerrainAppearance::PRESET_DEFAULTS['red-mesas']));
$canyonLayers = SurfaceLayers::landscape((new Recipe(['preset' => 'canyon']))->values(), new TerrainAppearance);

check(
	TerrainAppearance::style($mesas) === 'canyon' && $mesaLayers->structure === $canyonLayers->structure && $mesaLayers
		->layers[1]['color'] !== $canyonLayers->layers[1]['color']
	, 'Red Mesas reuse the canyon program with their own bed colors.'
);

$moor = SurfaceLayers::landscape(
	(new Recipe(['preset' => 'heather-moor']))->values()
	, new TerrainAppearance(TerrainAppearance::PRESET_DEFAULTS['heather-moor'])
)->layers;

$heath = array_column($moor, null, 'id');

check(
	count($moor) === 5 && $heath['moor-grass']['datum'] === 'water' && $heath['peat-banks']['datum'] === 'water'
		&& $heath['peat-banks']['high'] < $heath['moor-grass']['high'] && $heath['bracken']['slopeHigh'] <= $heath[
		'granite-tors'
		]['slopeLow'] && $heath['granite-tors']['pattern'] === 'jointed'
	, 'Moor grass and peat follow the lochans, and bracken gives way to granite on the steepest ground.'
);

$pans = new Recipe(['preset'=>'salt-pans']);
$salt = array_column(SurfaceLayers::landscape($pans->values(),$appearance)->layers,null,'id');
check($pans->height(1875,1775) < $pans->values()['waterLevel'] && $pans->height(1600,2040) > $pans->values()['waterLevel']
	&& $pans->height(4710,2205) > 300., 'Dry playa and shallow brine share a basin beneath a high rim.');
check($salt['salt-crust']['datum'] === 'absolute' && $salt['salt-crust']['reflectivity'] === 0.
	&& $salt['brine-margin']['datum'] === 'water' && $salt['brine-margin']['reflectivity'] > 0.,
	'Salt deposits stay on the basin floor while the wet margin follows the water level.');
$cinders = new Recipe(['preset'=>'cinder-highlands']);
$volcanic = array_column(SurfaceLayers::landscape($cinders->values(),$appearance)->layers,null,'id');
check($cinders->height(618,-2277) - $cinders->height(-415,-1409) > 700.
	&& $volcanic['lichen-islands']['high'] < $volcanic['oxidized-scoria']['low'] && $volcanic['lava-ribs']['slopeLow'] > 0.,
	'The cinder slopes rise above sheltered lichen ground and expose lava on steep faces.');

$prairie = new Recipe(['preset'=>'rolling-prairie']);
check($prairie->height(832,3968) - $prairie->height(448,3968) > 45.
	&& $prairie->height(832,3968) - $prairie->height(1472,3968) > 45., 'Grass swells separate the prairie swales.');
$river = new Recipe(['preset'=>'river-valley']);
$waterline = $river->values()['waterLevel'];
check($river->height(-4032,2624) > $waterline && $river->height(-4416,2624) < $waterline - 2.
	&& $river->height(-4800,2624) > $waterline + 20., 'The opening river reach has a submerged bed between two dry banks.');
$foothills = new Recipe(['preset'=>'wooded-foothills']);
$woods = array_column(SurfaceLayers::landscape($foothills->values(),$appearance)->layers,null,'id');
check($foothills->height(704,-704) - $foothills->height(64,-1344) > 85.
	&& $woods['woodland-canopy']['mask'] === 'trees' && $woods['woodland-canopy']['channel'] === 'both',
	'Wooded spurs rise above the glades and the canopy carries its own color and relief.');
$steppe = new Recipe(['preset'=>'high-desert-steppe']);
check($steppe->height(-1664,-1024) - $steppe->values()['waterLevel'] > 600.
	&& $steppe->height(-1024,-384) - $steppe->height(-1664,-1024) > 200., 'The dry steppe apron gives way to a high rim.');

$alaska = new Recipe(['preset'=>'alaska-range']);
check($alaska->height(-2841,935)-max($alaska->height(-1104,1149),$alaska->height(-4578,722)) > 1500.,
	'A snow-bearing divide rises over 1,500 m above the glacial valleys on both sides.');
$chalk = new Recipe(['preset'=>'chalk-headlands']);
check($chalk->height(1801,-43)<-8. && $chalk->height(1747,-55)-$chalk->height(1801,-43)>65.
	&& $chalk->height(2497,118)>100., 'Two chalk headlands rise above a navigable cove with a steep seaward face.');

$karst = new Recipe(['preset' => 'karst-bay']);
check(
	$karst->height(684, -1275) - 18. > 200. && $karst->height(715, -1275) < 8. && $karst->height(400, -1733) < 10.
	, 'Limestone towers rise over 200 m straight out of a bay deep enough for the craft.'
);

$tepui = new Recipe(['preset' => 'tepui-highlands']);
check(
	$tepui->height(2049, -2593) - $tepui->height(2080, -2593) > 700. && $tepui->height(4457, -343) < 100.
	, 'Sandstone walls fall over 700 m from a table top to the forest floor.'
);

$badlands = new Recipe(['preset' => 'painted-badlands']);
$badlandLook = new TerrainAppearance(TerrainAppearance::PRESET_DEFAULTS['painted-badlands']);
$badlandBeds = array_filter(
	SurfaceLayers::landscape($badlands->values(), $badlandLook)->layers
	, static fn(array $layer) => $layer['datum'] === 'beds'
);
check(
	$badlands->height(192, 1117) - $badlands->height(161, 1117) > 40. && count($badlandBeds) === 7
	, 'Banded clay crests rise over 40 m within 31 m of a wash and show every bed.'
);

$emerald = new Recipe(['preset' => 'emerald-ridges']);
check(
	$emerald->height(-673, 975) - $emerald->height(-704, 975) > 70. && $emerald->height(1000, -400) > 550.
	&& $emerald->height(-1714, 1029) < -100.
	, 'Green ridges climb over 70 m in 31 m to peaks above 550 m, across a bay deep enough for the craft.'
);

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
		$driving = Clipmap::spans($recipe, $quality, true);
		check($driving[0] >= $spans[0]*4/3 && $driving[0]%4 === 0, 'The focused grid widens both shoulders.');
		foreach($driving as $level=>$span) {
			if($level === 0) continue;
			$lead = $level === 1 ? $driving[0]/12 : 0.;
			check($span/2-Clipmap::transition($span) >= $driving[$level-1]/4+$lead+4,
				'At maximum lead the child still fits entirely inside the unmorphed parent.');
		}
		foreach($spans as $level => $span)
		{
			check($span % 4 === 0, 'Every clipmap edge lands on an even shared parent cell.');
			if($level === 0) continue;

			check($span / 2 - Clipmap::transition($span) >= $spans[$level - 1] / 4 + 4,
				'Fine rings join the parent before its own transition begins.');
		}
	}
}
check(Clipmap::roadsidePad(76,32.) === 0, 'Very coarse grids skip roadside processing entirely.');
$graded = new Landscape(['worldSize'=>512, 'spacing'=>1]);
$graded->queue(['type'=>'road','points'=>[[-32,0,-150],[-32,0,-16],[-32,0,32],[-120,0,100]],'width'=>16,'shoulder'=>8]);
$graded->commit();
$shoulders = new RoadsideDetail($graded->roads());
function shoulderWeight(RoadsideDetail $mask, array $focus, int $x, int $z): int
{
	$window = RoadsideDetail::window($focus,100.);
	[$bytes] = $mask->sampleWindow($window);
	[$left,$top,$size] = $window;
	return ord($bytes[(intdiv($z,16)-$top)*$size+intdiv($x,16)-$left]);
}
foreach([[-33.,-65.],[-31.,-63.]] as $focus) {
	check(shoulderWeight($shoulders,$focus,-64,-64) === 255
		&& shoulderWeight($shoulders,$focus,0,-64) === 255, 'Both cut banks retain full detail across a mask scroll.');
	$fade = shoulderWeight($shoulders,$focus,16,-64);
	check($fade > 0 && $fade < 255 && shoulderWeight($shoulders,$focus,32,-64) === 0,
		'The roadside footprint tapers into unedited terrain.');
}
check(shoulderWeight($shoulders,[-100,100],-112,96) === 255, 'The footprint follows the turn.');
$graded->queue(['type'=>'road','points'=>[[96,0,-150],[96,0,150]],'width'=>16,'shoulder'=>80]);
$graded->commit();
$wideBank = new RoadsideDetail($graded->roads());
check(shoulderWeight($wideBank,[160,0],176,0) === 255 && shoulderWeight($wideBank,[160,0],224,0) === 0,
	'The full graded cut is refined even where an authored shoulder is wider than the normal roadside band.');
check($shoulders->sampleWindow(RoadsideDetail::window([1000,1000],100.))[1] === null, 'Distant roads have no footprint.');
foreach([.5,1.,2.] as $quality) {
	[$span,$parent] = Clipmap::spans($canyonRecipe,$quality,true);
	$pad = Clipmap::roadsidePad($span,1.);
	check($pad > 0 && $pad <= 48 && $pad%2 === 0, 'Extension fits the target distance and parent lattice.');
	$origin = [-$span/2-2,-$span/2-2];
	$eye = [0.,0.,-$span/6];
	$safe = 2*($parent/2-Clipmap::transition($parent)-3);
	$rect = Clipmap::roadsideRect($origin,$span,$pad,1.,[-200,-200,200,200],$eye,$safe);
	check($rect !== null && $rect[0] < -$span/2 && $rect[2] > $span/2, 'Both sides can grow at every quality.');
	foreach($rect as $i=>$edge) {
		check($edge%2 === 0 && abs($edge) <= $span/2+$pad
			&& abs($edge-$eye[$i%2===0 ? 0 : 2]) <= $safe, 'All edges stitch inside the unmorphed parent.');
	}
	check(Clipmap::roadsideRect($origin,$span,$pad,1.,[0,-4,$span/2-3,4],$eye,$safe)
		=== [(int)(-$span/2),(int)(-$span/2),(int)($span/2),(int)($span/2)],
		'A road ending inside the regular morph strip keeps its weights when the extra draw area shrinks.');
	check(Clipmap::roadsideRect($origin,$span,$pad,1.,[500,500,700,700],$eye,$safe) === null,
		'The standard footprint is used away from roads.');
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
