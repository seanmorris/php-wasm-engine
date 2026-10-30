<?php
declare(strict_types=1);

use DoomPhonics\Tools\SeededLevel;

// Use the emitted default map: these checks describe player-visible geometry.
$footprints=[]; $leaning=0;
foreach($default['layers'] as $name=>$layer) {
	if($name==='spiral' || isset($layer['cluster']) || $name==='basement.0') continue;
	$footprints[]=implode(':',$layer['footprint']['dimensions']);
	$p=$layer['footprint']['polygon'];
	foreach($p as $i=>$a) {
		$b=$p[($i+1)%count($p)]; $dx=abs($b[0]-$a[0]); $dy=abs($b[1]-$a[1]);
		if(min($dx,$dy)>=64 && max($dx,$dy)>=512) { $leaning++; break; }
	}
	verifyWad(count($p)>=4,'ordinary rooms retain clear, simple outlines');
}
verifyWad(count(array_unique($footprints))>=6,'room widths and depths vary independently before wall deformation');
verifyWad($leaning>=4 && $leaning<=18,'a minority of ordinary rooms use gentle coordinated diagonals');
verifyWad($default['layers']['upper.0']['cluster']['minimumRoomSize']>=448
	&& $default['layers']['upper.0']['cluster']['outerDiameter']>=1152,'concentric chamber and surrounding hall have larger minimum dimensions');
foreach($default['layers']['basement.0']['ledges'] as $name) {
	$id=$default['regions'][$name]; $cell=$default['cells'][$id];
	verifyWad($cell['floor']>=0 && !in_array($id,$default['waterSectors'],true),'ledge chambers remain above the full water surface through geometric flood selection');
	verifyWad($cell['ceiling']-$cell['floor']>=192,'dry ledges retain standing headroom');
	$return=$default['graph']->search($name,['water'=>'dry'],false);
	verifyWad(in_array($default['start'],$return['reachable'],true),'a drained basin does not strand someone on a dry ledge');
}
verifyWad(count($default['galleryStack'])===3,'the default map contains a three-level portal gallery');
[$top,$middle,$bottom]=$default['galleryStack'];
foreach([$top,$middle] as $from) {
	$edges=array_values(array_filter($default['graph']->edges,static fn($e)=>$e['from']===$from && $e['mode']==='floor-portal'));
	verifyWad(count($edges)===1 && $edges[0]['to']===$bottom,'gallery fall edges end at the actual safe landing after traversing the stack');
}
verifyWad(!array_filter($default['graph']->edges,static fn($e)=>$e['from']===$bottom && $e['mode']==='floor-portal'),
	'falling into a gallery does not invent an upward traversal');
foreach(['all','none'] as $mode) {
	$galleryMap=SeededLevel::build(galleryHalls:$mode);
	verifyWad(count($galleryMap['galleryStack'])===3,'explicit gallery styles retain the full descending stack');
	foreach($galleryMap['layers'] as $layer) if(isset($layer['gallery'])) {
		verifyWad($layer['gallery']['hallways']===($mode==='all'),'gallery halls can be enabled or removed independently of the drop route');
		if($mode==='all') verifyWad($layer['gallery']['wallThickness']===64 && $layer['gallery']['rimWidth']>=128,
			'four-sided gallery halls reserve parallel walls and clear inner walkways');
	}
	verifyWad(!$galleryMap['overlaps'],'gallery hall options preserve architectural separation');
}

// Shared slopes, half-door crops and room subgraphs describe actual generated geometry.
$slopes=[]; $terrain=[];
foreach($default['layers'] as $name=>$layer) {
	if(isset($layer['footprint']['wallSlope']) && !isset($layer['cluster'])) $slopes[$layer['plane']][]=json_encode($layer['footprint']['wallSlope']);
	if(!isset($layer['terrain'])) continue;
	$terrain[$layer['terrain']['kind']]=$name;
	$low=$layer['terrain']['low']; $nav=$default['roomNavigation'][$name];
	$drops=array_filter($nav['oneWay']['dry']??[],static fn($e)=>$e['to']===$low && $e['mode']==='drop');
	verifyWad((bool)$drops,'lowered room centers have physically checked one-way drops');
	$summary=array_values($drops)[0];
	$drop=array_values(array_filter($default['graph']->edges,static fn($e)=>$e['from']===$summary['from'] && $e['to']===$summary['to'] && $e['mode']==='drop'))[0];
	$return=$default['graph']->search($low,['water'=>'dry'],false)['routes'][$drop['from']];
	$local=$default; $local['start']=$drop['from'];
	$trace=\DoomPhonics\Tests\SeededWalkthrough::run(\DoomPhonics\Tools\MapConnectivity::level($local),$local,[$drop,...$return]);
	verifyWad(end($trace)['sector']===$default['regions'][$drop['from']],'ordinary player physics traverses the drop and stair return without position corrections');
	foreach($layer['terrain']['ledges'] as $ledge) {
		$return=$default['graph']->search($low,['water'=>'dry'],false);
		verifyWad(in_array($ledge,$return['reachable'],true),'a lowered room has a real stair return to all surrounding ledges');
	}
	foreach($nav['routes']['dry']??[] as $ports) foreach($ports as $route)
	verifyWad($route!==null,'all ordinary room entrances have internal return routes without leaving the room');
}
verifyWad(isset($terrain['trench'],$terrain['bowl']),'default map includes both an E1M4-style trench and a rounded lowered-center room');
foreach($slopes as $values) verifyWad(count(array_unique($values))===1,'neighboring angled rooms use the same layer-wide slope');
foreach([90,180,270] as $sweep) foreach([0,2,4,6] as $rotation) {
	$c=\DoomPhonics\Tools\RoomCluster::circular('arc',64,256,false,$sweep,$rotation);
	verifyWad(count($c['curvedBays'])===$sweep/45 && count($c['straightBays'])===8-$sweep/45,
		'concentric arcs stop at the selected sweep and leave a straight asymmetric gallery');
	foreach($c['cells'] as $i=>$a) foreach($c['cells'] as $j=>$b) if($j>$i)
	verifyWad(!\DoomPhonics\Tools\PolygonGeometry::overlaps($a['polygon'],$b['polygon']),'partial arcs and their replacement bays never overlap');
}
