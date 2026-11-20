<?php
declare(strict_types=1);
use TerrainPhonics\{Noise,Recipe};
require dirname(__DIR__).'/autoload.php';

$cases=[];
foreach(['stream','first-light'] as $seed) foreach(['mountain','coast','canyon-v1','canyon'] as $style) {
	$recipe=new Recipe(['preset'=>$style==='canyon-v1'?'canyon':$style,'seed'=>$seed,'version'=>$style==='canyon-v1'?1:2,
		'heightScale'=>$style==='mountain'?1400:650,'baseFrequency'=>1/($style==='mountain'?2200:4000),
		'terraceSpacing'=>str_starts_with($style,'canyon')?80:0]);
	$samples=[];
	foreach([[-16384,-16384],[16350,16350],[170,3000],[-16,-16]] as [$x,$z]) {
		$row=[];
		for($i=0;$i<32;$i++) $row[]=$recipe->height($x+$i,$z);
		$samples[]=[$x,$z,$row];
	}
	$cases[]=['recipe'=>$recipe->values(),'perm'=>(new Noise($seed))->permutation(),'samples'=>$samples];
}
echo json_encode($cases,JSON_THROW_ON_ERROR);
