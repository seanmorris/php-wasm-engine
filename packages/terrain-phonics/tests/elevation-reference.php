<?php
declare(strict_types=1);
use TerrainPhonics\{Landscape,Noise,Recipe,RoadAtlas};
require dirname(__DIR__).'/autoload.php';

$cases=[];
foreach(['stream','first-light','translated'] as $seed) foreach(['mountain','coast','canyon-v1','canyon'] as $style) {
	$recipe=new Recipe(['preset'=>$style==='canyon-v1'?'canyon':$style,'seed'=>$seed,'version'=>$style==='canyon-v1'?1:2,
		'worldSize'=>65536.,'heightScale'=>$style==='mountain'?1400:650,'baseFrequency'=>1/($style==='mountain'?2200:4000),
		'terraceSpacing'=>str_starts_with($style,'canyon')?80:0]
		+ ($seed==='translated' ? ['noiseOffsetX'=>-61111.12345,'noiseOffsetZ'=>63544.6789] : []));
	$samples=[];
	$points=[[-16384,-16384],[16350,16350],[170,3000],[-16,-16],
		[-32768,-32768],[32732,32768],[32732,-32768],[-32768,32768],
		[20177,-32734],[-29209,20892],[32000,-10412],[-31197,4110]];
	for($i=1;$i<=96;++$i) $points[]=[($i*2243)%65504-32768, ($i*6421)%65537-32768];
	foreach($points as [$x,$z]) {
		$row=[];
		for($i=0;$i<32;$i++) $row[]=$recipe->height($x+$i,$z);
		$samples[]=[$x,$z,$row];
	}
	$cases[]=['recipe'=>$recipe->values(),'perm'=>(new Noise($seed))->permutation(),'samples'=>$samples];
}

// Sample the authored landforms where vehicles can cross their banks, slopes and crests.
foreach([
	'limestone' => [[2440,-4880],[4110,-4933],[637,-3747],[-5100,-15600]]
	, 'basalt-fjords' => [[-3250,-2600],[-3800,-3000],[-4600,-3400],[2200,3100]]
	, 'amber-dunes' => [[-862,575],[140,1350],[750,1700],[-2420,3060]]
	, 'arctic-meltwater' => [[1550,775],[1660,785],[1667,892],[2123,1348]]
	, 'tidal-lagoon' => [[-648,-185],[-605,-26],[-578,76],[-531,250]]
	, 'red-mesas' => [[3300,4500],[1745,4917],[-409,5494],[-2611,6084]]
	, 'heather-moor' => [[300,900],[-209,1409],[-600,1800],[-1029,2229]]
	, 'salt-pans' => [[1600,2040],[1875,1775],[2700,2050],[4710,2205]]
	, 'cinder-highlands' => [[-760,-1120],[-415,-1409],[-55,-1711],[618,-2277]]
	, 'rolling-prairie' => [[448,3968],[832,3968],[1472,3968],[3008,3968]]
	, 'river-valley' => [[-4032,2624],[-4416,2624],[-4672,2624],[-4800,2624]]
	, 'wooded-foothills' => [[64,-1344],[704,-704],[1088,-320],[2112,704]]
	, 'high-desert-steppe' => [[-1664,-1024],[-1024,-384],[-384,256],[384,1024]]
	, 'alaska-range' => [[-360,1240],[-1104,1149],[-2841,935],[-4578,722]]
	, 'chalk-headlands' => [[1640,-80],[1747,-55],[1801,-43],[2497,118]]
	, 'karst-bay' => [[684,-1275],[636,-1775],[332,-2150],[400,-1733]]
	, 'tepui-highlands' => [[2049,-2593],[4657,-1093],[2625,2157],[4457,-343]]
	, 'painted-badlands' => [[161,1117],[353,1317],[305,917],[417,1250]]
	, 'emerald-ridges' => [[-704,975],[736,-1025],[-480,-150],[1000,-400]]
	, 'autumn-folds' => [[2360,-300],[2840,0],[3624,1100],[1828,2740]]
	, 'namib-coast' => [[-172,2700],[-284,2300],[852,700],[-360,0]]
	, 'black-sand-coast' => [[-44,950],[-540,700],[388,-925],[0,-300]]
	, 'yardang-desert' => [[-1120,-250],[-944,-175],[-768,-100],[-900,-200]]
] as $preset => $points)
{
	$recipe = new Recipe(['preset' => $preset]);
	$samples = [];
	foreach($points as [$x,$z]) {
		$row = [];
		for($i = 0; $i < 32; $i++) $row[] = $recipe->height($x+$i,$z);
		$samples[] = [$x,$z,$row];
	}
	$values = $recipe->values();
	$case = ['recipe' => $values, 'perm' => (new Noise($values['seed']))->permutation(), 'samples' => $samples];
	// Presets that orient or cut their landform draw with the specialized shader, so test them there.
	if(
		$values['domainRotation'] != 0 || $values['ridgeStretch'] != 1 || $values['coastBearing'] != 0
		|| $values['coastWidth'] != 4500 || $values['detailShape'] != 0 || $values['gullyDepth'] > 0
	)
	$case['axes'] = [Recipe::axes($values)];
	$cases[] = $case;
}

// Cross the negative block seam on a bank, turn back into the same tile, then cross a later road.
$ground = new Landscape(['worldSize' => 2048, 'seed' => 'road-atlas', 'heightScale' => 140, 'detailAmplitude' => 6]);
$ground->queue(['type' => 'road', 'width' => 14, 'shoulder' => 9, 'crown' => .5,
	'banks' => [-.1, .18, -.08, .15, -.1], 'points' => [
		[-610, 70, -512], [-480, 82, -512], [-460, 87, -462], [-510, 75, -494], [-560, 69, -530],
	]]);
$ground->queue(['type' => 'road', 'width' => 9, 'shoulder' => 4, 'material' => 'snow', 'endBlend' => 24,
	'points' => [[-530, 95, -555], [-499, 87, -478]]]);
$ground->queue(['type' => 'road', 'width' => 4, 'shoulder' => 1, 'material' => null,
	'points' => [[-508, 65, -540], [-508, 79, -480]]]);
$ground->queue(['type' => 'road', 'width' => 20, 'shoulder' => 5,
	'points' => [[935, 40, 975], [1030, 65, 1025]]]);
// Two smooth sections share both ends. Read through the outgoing and incoming caps of the loop.
foreach([[[130,5,90],[185,7,140],[130,5,190]], [[130,5,190],[75,3,140],[130,5,90]]] as $i=>$points) {
	$sign = $i === 0 ? 1 : -1;
	$ground->queue(['type'=>'road','width'=>20,'shoulder'=>12,'points'=>$points,
		'handles'=>[['out'=>[24*$sign,0,0]],null,['in'=>[24*$sign,0,0]]]]);
}
// Asphalt contributions overlap at the throat, on both shoulders and in the gore.
foreach([[[-120,3,360],[0,3,360]],[[0,3,360],[40,3,356],[80,3,344],[160,3,304]],
	[[0,3,360],[40,3,364],[80,3,376],[160,3,416]]] as $points)
	$ground->queue(['type'=>'road','width'=>28,'shoulder'=>18,'stationSpacing'=>7,'points'=>$points]);
$ground->commit();
$atlas = RoadAtlas::build($ground->roads(), $ground->recipe()['worldSize']);
$atlas['bytes'] = base64_encode($atlas['bytes']);
$samples = [];
foreach([[-565,-542,6,18], [-15,-5,1,2], [965,978,1,1], [993,1024,1,1], [102,70,2,9], [102,170,2,9],[-30,314,8,19]] as [$left,$front,$columns,$rows])
for($j = 0; $j < $rows; $j++)
for($i = 0; $i < $columns; $i++)
{
	$x = $left + 24*$i;
	$z = $front + 5*$j;
	$heights = []; $ink = '';
	for($n = 0; $n < 32; $n++)
	{
		$heights[] = $ground->height($x+$n, $z);
		$ink .= $ground->paintTexel($x+$n, $z);
	}
	$samples[] = [$x, $z, $heights, base64_encode($ink)];
}
$cases[] = ['recipe' => $ground->recipe(), 'perm' => (new Noise('road-atlas'))->permutation(),
	'atlas' => $atlas, 'samples' => $samples];
// Far from the origin a 32-bit sample position is off by up to 1 mm, which the kernel weights once turned into 27 mm
// of road height at 30 km. Grade the same banked, crossing roads near two corners of a 65.5 km world.
foreach([[29988, -30019], [-16000, 16000]] as [$cx, $cz])
{
	$far = new Landscape(['worldSize' => 65536, 'seed' => 'road-atlas', 'heightScale' => 140, 'detailAmplitude' => 6]
		+ ($cx < 0 ? ['noiseOffsetX'=>1800.53,'noiseOffsetZ'=>-2700.15] : []));
	$far->queue(['type' => 'road', 'width' => 14, 'shoulder' => 9, 'crown' => .5,
		'banks' => [-.1, .18, -.08, .15, -.1], 'points' => [
			[$cx-110, 70, $cz], [$cx+20, 82, $cz], [$cx+40, 87, $cz+50], [$cx-10, 75, $cz+18], [$cx-60, 69, $cz-18],
		]]);
	$far->queue(['type' => 'road', 'width' => 9, 'shoulder' => 4, 'material' => 'snow', 'endBlend' => 24,
		'points' => [[$cx-30, 95, $cz-43], [$cx+1, 87, $cz+34]]]);
	$far->commit();
	$farAtlas = RoadAtlas::build($far->roads(), $far->recipe()['worldSize']);
	$farAtlas['bytes'] = base64_encode($farAtlas['bytes']);
	$farSamples = [];
	for($j = 0; $j < 12; $j++)
	for($i = 0; $i < 6; $i++)
	{
		$x = $cx - 130 + 32*$i;
		$z = $cz - 55 + 11*$j;
		$heights = []; $ink = '';
		for($n = 0; $n < 32; $n++)
		{
			$heights[] = $far->height($x+$n, $z);
			$ink .= $far->paintTexel($x+$n, $z);
		}
		$farSamples[] = [$x, $z, $heights, base64_encode($ink)];
	}
	$cases[] = ['recipe' => $far->recipe(), 'perm' => (new Noise('road-atlas'))->permutation(),
		'atlas' => $farAtlas, 'samples' => $farSamples];
	$far->close();
}
foreach([32768,65536] as $span) foreach([1,2] as $joinVersion) {
	$east = [['preset'=>'mountain','seed'=>'ridge','waterLevel'=>51.,'noiseOffsetX'=>-4100.125,'noiseOffsetZ'=>305.789]];
	if($span === 65536) $east[] = ['preset'=>'canyon','waterLevel'=>2.];
	$joined = new Landscape(['preset'=>'coast','seed'=>'west','worldSize'=>$span,'waterLevel'=>-200.,
		'join'=>['version'=>$joinVersion,'span'=>$span,'destinations'=>$east]]);
	$r = $joined->recipe();
	$origins = TerrainPhonics\JoinedTerrain::origins($span,count($east));
	$perm = [];
	foreach([$r,...$r['join']['destinations']] as $field)
		array_push($perm,...(new Noise($field['seed']))->permutation());
	$samples=[];
	foreach([-$span,-2064,-1050,-16,1024,2032,$span] as $x)
	foreach([-3100,0,1120,4300] as $z) {
		$row=[];
		for($i=0;$i<32;$i++) $row[]=$joined->height($x+$i,$z);
		$samples[]=[$x,$z,$row];
	}
	$cases[]=['recipe'=>$r,'perm'=>$perm,'origins'=>$origins,'samples'=>$samples];
	$joined->close();
}
// Independently adjusted coast, ridges, detail and one scale of true terrain erosion.
$authored = ['domainRotation'=>-17.5,'ridgeStretch'=>3.2,'ridgeBearing'=>38.,'coastWidth'=>3200.,
	'coastBearing'=>77.,'detailShape'=>.85,'gullyDepth'=>3.5,'gullySpacing'=>96.,
	'noiseOffsetX'=>27711.123,'noiseOffsetZ'=>-18111.74];
foreach(['coast','painted-badlands','canyon','fork'] as $name) {
	$parameters = $authored+['preset'=>$name==='fork' ? 'coast' : $name];
	if($name==='fork') $parameters['join']=['version'=>2,'span'=>32768,'destinations'=>[
		['preset'=>'painted-badlands','detailShape'=>-.6,'gullyDepth'=>2.],['preset'=>'emerald-ridges']]];
	$field = $name==='fork' ? new TerrainPhonics\JoinedTerrain($parameters) : new Recipe($parameters);
	$r=$field->values(); $samples=[]; $perm=[]; $axes=[];
	foreach([$r,...($r['join']['destinations'] ?? [])] as $form) {
		array_push($perm,...(new Noise($form['seed']))->permutation());
		$axes[]=Recipe::axes($form);
	}
	$locations=[[-32768,32760],[31253,-25113],[-256,-277],[0,31],[511,2047],[-1704,1832]];
	for($i=1;$i<=32;$i++) $locations[]=[($i*4217)%65504-32768,($i*7441)%65537-32768];
	foreach($locations as [$x,$z]) {
		$row=[];
		for($i=0;$i<32;$i++) $row[]=$field->height($x+$i,$z);
		$samples[]=[$x,$z,$row];
	}
	$cases[]=['recipe'=>$r,'axes'=>$axes,'samples'=>$samples,'perm'=>$perm,
		'origins'=>$name==='fork' ? TerrainPhonics\JoinedTerrain::origins(32768,2) : null];
}
echo json_encode($cases,JSON_THROW_ON_ERROR);
