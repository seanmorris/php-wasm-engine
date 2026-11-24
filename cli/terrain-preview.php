<?php
declare(strict_types=1);
use TerrainPhonics\{Camera,JoinedTerrain,LandformPreview,Recipe};

// The JS launcher resolves the optional terrain package in the user's project.
require $argv[1].'/autoload.php';
$options=json_decode($argv[2],true,flags:JSON_THROW_ON_ERROR);
try {
	$savedCamera = null;
	if(isset($options['file'])) {
		$doc=json_decode(file_get_contents($options['file']),true,flags:JSON_THROW_ON_ERROR);
		$recipe=$doc['landscape']['recipe'] ?? $doc['recipe'] ?? $doc;
		$savedCamera=$doc['camera'] ?? null;
	} else $recipe = Recipe::showcase($options['preset'] ?? 'mountain');
	if(isset($options['seed'])) $recipe['seed']=$options['seed'];
	$field=isset($recipe['join']) ? new JoinedTerrain($recipe) : new Recipe($recipe);
	$r=$field->values();
	$water=$doc['settings']['waterLevel'] ?? $r['waterLevel'];
	$size = filter_var($options['size'] ?? 128,FILTER_VALIDATE_INT);
	if($size===false) throw new InvalidArgumentException('Grid size must be an integer.');
	$grid=new LandformPreview($r,$water,$size);
	$survey=$grid->survey(); foreach($survey as $_) {}
	$stats=$survey->getReturn();
	$camera=new Camera;
	if(isset($options['camera'])) {
		$view=explode(',',$options['camera']);
		if(count($view)!==5 || count(array_filter($view,'is_numeric'))!==5)
			throw new InvalidArgumentException('Expected camera x,z,clearance,heading,pitch (meters and degrees).');
		[$x,$z,$clearance,$heading,$pitch]=array_map('floatval',$view);
		if(!is_finite($x+$z+$clearance+$heading+$pitch) || $clearance<0 || abs($pitch)>89)
			throw new InvalidArgumentException('Use finite camera coordinates, positive clearance and a pitch within ±89 degrees.');
		$camera->position=[$x,max($water,$field->height($x,$z))+$clearance,$z];
		$camera->yaw=deg2rad($heading); $camera->pitch=deg2rad($pitch);
	} elseif($savedCamera) {
		$camera->position=$savedCamera['position']; $camera->yaw=$savedCamera['yaw']; $camera->pitch=$savedCamera['pitch'];
	} else {
		$finder=$grid->findView(); foreach($finder as $_) {}
		[$x,$z,$clearance,$heading,$pitch]=$finder->getReturn();
		$camera->position=[$x,max($water,$field->height($x,$z))+$clearance,$z];
		$camera->yaw=deg2rad($heading); $camera->pitch=deg2rad($pitch);
	}
	$prefix=$options['out'] ?? 'terrain-preview';
	function png(string $path,int $w,int $h,string $rgb): void {
		$chunk=static fn($tag,$data)=>pack('N',strlen($data)).$tag.$data.pack('N',crc32($tag.$data));
		$scanlines='';
		for($y=0;$y<$h;$y++) $scanlines.="\0".substr($rgb,$y*$w*3,$w*3);
		$data="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('N2C5',$w,$h,8,2,0,0,0)).$chunk('IDAT',gzcompress($scanlines)).$chunk('IEND','');
		if(file_put_contents($path,$data)===false) throw new RuntimeException("Could not write $path");
	}
	png($prefix.'-map.png',$grid->size,$grid->size,base64_decode($stats['rgb']));
	png($prefix.'-view.png',256,144,$grid->oblique($camera));
	unset($stats['rgb']);
	echo json_encode(['preset'=>$r['preset'],'seed'=>$r['seed'],'camera'=>['position'=>$camera->position,
		'yaw'=>$camera->yaw,'pitch'=>$camera->pitch]]+$stats,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),"\n";
} catch(Throwable $e) {
	fwrite(STDERR,$e->getMessage()."\n"); exit(1);
}
