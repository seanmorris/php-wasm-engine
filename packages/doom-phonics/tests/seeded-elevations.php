<?php
declare(strict_types=1);

use DoomPhonics\Tools\{MapConnectivity,OutdoorSpace,PolygonGeometry};
use DoomPhonics\Tests\SeededWalkthrough;
use DoomPhonics\Graphics\{IndexedAtlas,TextureCatalog,Palette};
use DoomPhonics\Archive\{Archive,Stack};
use DoomPhonics\Render\MapMeshBuilder;

$changes=0;
foreach($default['layers'] as $name=>$layer) {
	if(!isset($layer['elevation'])) continue;
	$changes++; $elevation=$layer['elevation']; [$a,$b]=$elevation['ports'];
	verifyWad(abs($elevation['floors'][$a]-$elevation['floors'][$b])>=32,'opposing entrances occupy different room elevations');
	foreach($elevation['floors'] as $port=>$floor)
	verifyWad($layer['corridors'][$port]['from']===$floor,'each approach starts at its own room landing height');
	$owned=array_fill_keys(array_column($layer['cells'],'id'),true); $graph=clone $default['graph'];
	$graph->edges=array_values(array_filter($graph->edges,static fn($e)=>isset($owned[$e['from']],$owned[$e['to']])));
	$entrances=$default['roomNavigation'][$name]['entrances'];
	foreach($entrances as $port=>$entry) foreach($entrances as $exit=>$target) {
		if($port===$exit) continue;
		$route=$graph->search($entry,['water'=>'dry'],false)['routes'][$target]??null;
		verifyWad($route!==null,'different-height entrances connect inside the room without external detours');
		$fixture=$default; $fixture['start']=$entry;
		$trace=SeededWalkthrough::run(MapConnectivity::level($default),$fixture,$route);
		verifyWad(end($trace)['sector']===$default['regions'][$target],'real player motion reaches every room exit in either direction');
	}
	$interior=array_values(array_filter($layer['cells'],static fn($c)=>in_array($c['id'],$elevation['route'],true)));
	verifyWad(count(array_unique(array_column($interior,'floorTexture')))===1,'landings and stairs share one continuous floor material');
	if($elevation['kind']!=='stairs') continue;
	verifyWad(abs(array_sum(array_map(static fn($c)=>PolygonGeometry::area($c['polygon']),$interior))
		-PolygonGeometry::area($layer['footprint']['polygon']))<.01,'stair room subdivisions preserve the whole footprint');
	foreach($interior as $i=>$cell) {
		if($i) verifyWad(abs($cell['floor']-$interior[$i-1]['floor'])===16,'room treads change height monotonically in climbable steps');
		foreach($interior as $j=>$other) if($j>$i) verifyWad(!PolygonGeometry::overlaps($cell['polygon'],$other['polygon']),'stair landings and treads do not overlap');
	}
}
verifyWad($changes>=2,'the default layout has multiple functional rooms with elevation changes');

// Check the emitted sidedefs and actual upper wall spans, not just roof metadata.
$roofLevel=MapConnectivity::level($default);
$stack=new Stack(new Archive(file_get_contents(dirname(__DIR__).'/demo/DOOM1.WAD'),'DOOM1.WAD'));
$textures=new TextureCatalog($stack);
$atlas=IndexedAtlas::forLevel($roofLevel,$textures,1024,null,new Palette($stack));
$mesh=(new MapMeshBuilder($roofLevel,$atlas))->build(); $openings=0;
foreach($roofLevel->map->linedefs as $line) {
	if($line['left']<0) continue;
	foreach([[$line['right'],$line['left']],[$line['left'],$line['right']]] as [$outside,$inside]) {
		$a=$roofLevel->map->sidedefs[$outside]; $b=$roofLevel->map->sidedefs[$inside];
		$sky=$default['cells'][$a['sector']]; $room=$default['cells'][$b['sector']];
		if(!isset($sky['outdoor']) || $sky['ceilingTexture']!=='F_SKY1' || $sky['floor']===$sky['ceiling'] || $room['ceilingTexture']==='F_SKY1') continue;
		$openings++;
		verifyWad($sky['ceiling']-$room['ceiling']>=OutdoorSpace::ROOF_THICKNESS,'open sky leaves a visible solid roof above indoor openings');
		verifyWad($a['upper']!=='-' && $textures->texture($a['upper'])!==null,'the wall above each outdoor opening has an available texture');
		$spans=array_filter($mesh->wallSpans,static fn($s)=>$s['line']===$line['index'] && $s['slot']==='upper');
		verifyWad((bool)$spans,'the renderer emits an upper wall at the exterior opening');
	}
}
verifyWad($openings>=2,'roof coverage includes multiple courtyard and garden openings');
