<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PolygonGeometry.php';
require_once __DIR__ . '/RoomOutline.php';

/**
 * Finish outdoor rooms with a solid horizon wall and optional lower ground.
 *
 * A garden reaches the cluster exterior; a courtyard is enclosed by its rooms.
 * Like Doom II MAP01, the garden's far edge uses a collapsed sky sector above
 * a raised floor. It blocks movement while showing sky over the wall instead
 * of stretching a wall texture up to the roof. The lower ground retains a stair
 * return, and its edges participate in the ordinary directed traversal graph.
 */
final class OutdoorSpace
{

	/** Add a bounded exterior sky strip and a lowered garden trench when space permits. */
	public static function garden(array &$cells,array $regions,array $center): array
	{
		$ids=array_fill_keys($regions,true); $edges=RoomOutline::edges($cells);
		$rim=null;
		foreach($cells as $i=>$cell) {
			if(!isset($ids[$cell['id']])) continue;
			$p=$cell['polygon'];
			foreach($edges as [$a,$b]) {
				$dx=$b[0]-$a[0]; $dy=$b[1]-$a[1]; $length=hypot($dx,$dy);
				if($length<256) continue;
				$full=false;
				foreach($p as $j=>$v) {
					$w=$p[($j+1)%count($p)];
					if(hypot($v[0]-$a[0],$v[1]-$a[1])<1e-6 && hypot($w[0]-$b[0],$w[1]-$b[1])<1e-6) $full=true;
				}
				if(!$full) continue;
				// Leave the four central approach positions available to the caller.
				if(min($a[0],$b[0])<=$center[0]+192 && max($a[0],$b[0])>=$center[0]-192
					|| min($a[1],$b[1])<=$center[1]+192 && max($a[1],$b[1])>=$center[1]-192) continue;
				$gcd=static function(int $a,int $b): int { while($b) [$a,$b]=[$b,$a%$b]; return max(1,$a); };
				$g=$gcd((int)round(abs($dx)),(int)round(abs($dy))); $nx=-$dy/$g; $ny=$dx/$g;
				$d=$nx*$a[0]+$ny*$a[1]; $grid=6*($nx*$nx+$ny*$ny);
				$inset=ceil(48*hypot($nx,$ny)/$grid)*$grid;
				$inside=PolygonGeometry::clip($p,$nx,$ny,$d-$inset);
				$strip=PolygonGeometry::clip($p,-$nx,-$ny,-($d-$inset));
				if(!$inside || !$strip || PolygonGeometry::area($inside)<65536) continue;
				$cells[$i]=PolygonGeometry::cell($cell,$inside);
				$id=$cell['id'].'.sky-wall'; $height=$cell['floor']+80;
				$cells[]=PolygonGeometry::cell(array_replace($cell,['id'=>$id,'floor'=>$height,'ceiling'=>$height,
					'traversable'=>false,'wallTexture'=>'STONE2']),$strip);
				$rim=['region'=>$id,'height'=>80,'depth'=>$inset/hypot($nx,$ny)]; break 2;
			}
		}
		// Retain the original entrance height on the surrounding ledge. Only the
		// interior drops; no generated doorway or existing connection is moved.
		$trench=null;
		foreach($cells as $i=>$cell) {
			if(!isset($ids[$cell['id']])) continue;
			[$x,$y]=$cell['center']; $x=round($x/48)*48; $y=round($y/48)*48;
			if(!PolygonGeometry::contains($cell['polygon'],$x,$y,336)) continue;
			$opening=[[$x-288,$y-144],[$x-288,$y+144],[$x+288,$y+144],[$x+288,$y-144]];
			$pieces=PolygonGeometry::mergeConvex(PolygonGeometry::surround($cell['polygon'],$opening));
			$cells[$i]=PolygonGeometry::cell($cell,array_shift($pieces)); $ledges=[$cell['id']];
			foreach($pieces as $j=>$p) {
				$id=$cell['id'].'.ledge-'.$j; $ledges[]=$id;
				$cells[]=PolygonGeometry::cell(array_replace($cell,['id'=>$id]),$p);
			}
			$steps=[];
			for($j=0;$j<4;$j++) {
				$p=PolygonGeometry::clip($opening,-1,0,-($x-288+$j*96));
				if($j<3) $p=PolygonGeometry::clip($p,1,0,$x-288+($j+1)*96);
				$id=$cell['id'].($j<3 ? '.step-'.$j : '.trench'); $steps[]=$id;
				$cells[]=PolygonGeometry::cell(array_replace($cell,['id'=>$id,'floor'=>$cell['floor']-16*($j+1),
					'floorTexture'=>$j<3 ? 'FLOOR7_1' : 'FLOOR4_8']),$p);
			}
			$trench=['low'=>$id,'ledges'=>$ledges,'steps'=>$steps,'depth'=>64]; break;
		}
		return compact('rim','trench');
	}
}
