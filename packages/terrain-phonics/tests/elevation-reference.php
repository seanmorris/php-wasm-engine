<?php
declare(strict_types=1);
use TerrainPhonics\{Landscape,Noise,Recipe,RoadAtlas};
require dirname(__DIR__).'/autoload.php';

$cases=[];
foreach(['stream','first-light'] as $seed) foreach(['mountain','coast','canyon-v1','canyon'] as $style) {
	$recipe=new Recipe(['preset'=>$style==='canyon-v1'?'canyon':$style,'seed'=>$seed,'version'=>$style==='canyon-v1'?1:2,
		'worldSize'=>65536.,'heightScale'=>$style==='mountain'?1400:650,'baseFrequency'=>1/($style==='mountain'?2200:4000),
		'terraceSpacing'=>str_starts_with($style,'canyon')?80:0]);
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

// Cross the negative block seam on a bank, turn back into the same tile, then cross a later road.
$ground = new Landscape(['worldSize' => 2048, 'seed' => 'road-atlas', 'heightScale' => 140, 'detailAmplitude' => 6]);
$ground->queue(['type' => 'road', 'width' => 14, 'shoulder' => 9, 'crown' => .5,
	'banks' => [-.1, .18, -.08, .15, -.1], 'points' => [
		[-610, 70, -512], [-480, 82, -512], [-460, 87, -462], [-510, 75, -494], [-560, 69, -530],
	]]);
$ground->queue(['type' => 'road', 'width' => 9, 'shoulder' => 4, 'material' => 'snow',
	'points' => [[-530, 95, -555], [-499, 87, -478]]]);
$ground->queue(['type' => 'road', 'width' => 4, 'shoulder' => 1, 'material' => null,
	'points' => [[-508, 65, -540], [-508, 79, -480]]]);
$ground->queue(['type' => 'road', 'width' => 20, 'shoulder' => 5,
	'points' => [[935, 40, 975], [1030, 65, 1025]]]);
$ground->commit();
$atlas = RoadAtlas::build($ground->roads(), $ground->recipe()['worldSize']);
$atlas['bytes'] = base64_encode($atlas['bytes']);
$samples = [];
foreach([[-565,-542,6,18], [-15,-5,1,2], [965,978,1,1], [993,1024,1,1]] as [$left,$front,$columns,$rows])
for($j = 0; $j < $rows; $j++)
for($i = 0; $i < $columns; $i++)
{
	$x = $left + 24*$i;
	$z = $front + 5*$j;
	$heights = $materials = [];
	for($n = 0; $n < 32; $n++)
	{
		$heights[] = $ground->height($x+$n, $z);
		$id = array_search($ground->material($x+$n, $z), RoadAtlas::MATERIALS, true);
		$materials[] = $id === false ? 0 : $id + 1;
	}
	$samples[] = [$x, $z, $heights, $materials];
}
$cases[] = ['recipe' => $ground->recipe(), 'perm' => (new Noise('road-atlas'))->permutation(),
	'atlas' => $atlas, 'samples' => $samples];
echo json_encode($cases,JSON_THROW_ON_ERROR);
