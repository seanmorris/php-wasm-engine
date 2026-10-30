<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/PolygonGeometry.php';

/**
 * Carve a vertical portal and its walkable surround from a convex room core.
 *
 * The floor side is recessed into the slab. The matching ceiling aperture keeps
 * a clear landing below it; both sides retain the same shape and dimensions.
 * Rooms remain subdivided traversal regions, so crossing a hole is never
 * mistaken for walking across an uninterrupted floor.
 */
final class PlanePortalSite
{
	/** Reserve a square aperture with ample space to walk around all four sides. */
	public static function carve(array &$layer,string $room,bool $floor): string
	{
		$index=array_search($room.'.core',array_column($layer['cells'],'id'),true);
		if($index===false) throw new \InvalidArgumentException('Portal site needs an ordinary room core');
		$core=$layer['cells'][$index]; $shell=PolygonGeometry::points($core);
		$x=round(($core['x0']+$core['x1'])/16)*8; $y=round(($core['y0']+$core['y1'])/16)*8;
		$opening=[[$x-96,$y-96],[$x-96,$y+96],[$x+96,$y+96],[$x+96,$y-96]];
		foreach($opening as [$px,$py])
		if(!PolygonGeometry::contains($shell,$px,$py,96)) throw new \InvalidArgumentException('Portal site needs a clear walkway around its aperture: '.$room);
		$id=$room.($floor ? '.shaft' : '.arrival');
		$pieces=PolygonGeometry::surround($shell,$opening);
		$layer['cells'][$index]=PolygonGeometry::cell($core,array_shift($pieces));
		foreach($pieces as $i=>$polygon)
		$layer['cells'][]=PolygonGeometry::cell(array_replace($core,['id'=>"$id.rim-$i"]),$polygon);
		$aperture=array_replace($core,['id'=>$id,'verticalPortal'=>$floor ? 'floor' : 'ceiling']);
		if($floor) $aperture['floor']-=32;
		$layer['cells'][]=PolygonGeometry::cell($aperture,$opening);
		return $id;
	}
}
