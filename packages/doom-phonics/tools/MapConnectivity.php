<?php
declare(strict_types=1);
namespace DoomPhonics\Tools;

require_once __DIR__ . '/TraversalGraph.php';
require_once __DIR__ . '/RectangularMap.php';

use DoomPhonics\Archive\Archive;
use DoomPhonics\Map\MapData;
use DoomPhonics\Component\Body;
use DoomPhonics\Component\Transform;
use DoomPhonics\Component\Velocity;
use DoomPhonics\Game\Collision;
use DoomPhonics\Game\Level;
use DoomPhonics\Game\LineSpecials;
use DoomPhonics\Game\PlayerState;
use DoomPhonics\Game\VerticalPhysics;

/**
 * Compile a small generated map into physically checked, conditional connections.
 *
 * The scope is empty convex rectangles with flat floors, one liquid model and
 * settled switch states. Walking openings and wall portals are swept using game
 * collision; floor portals are tested using game gravity. This is not a general
 * navmesh, timing solver or guarantee for arbitrary obstacle-filled WADs.
 */
final class MapConnectivity
{

	/** Load the emitted bytes through the same map parser and structural setup as the game. */
	public static function level(array $prototype): Level
	{
		$archive = new Archive(RectangularMap::archive($prototype['lumps']),'LAYERS.WAD');
		return new Level(new MapData('LAYERS',$archive->mapLumps('LAYERS')));
	}

	/** Build a graph with runtime-tested opening witnesses for the selected player profile. */
	public static function compile(array $prototype, array $profile = []): TraversalGraph
	{
		$profile += ['radius' => 16,'height' => 56,'step' => 24,'drop' => 256,'swim' => true];
		if($profile['radius'] <= 0 || $profile['height'] <= 0 || $profile['step'] < 0 || $profile['drop'] < 0)
		throw new \InvalidArgumentException('Invalid traversal dimensions');
		$level = self::level($prototype);
		$graph = new TraversalGraph(['water' => ['dry','shallow','full']]);
		$names = array_flip($prototype['regions']);
		foreach($prototype['regions'] as $name => $id) $graph->region($name,$prototype['cells'][$id]);
		$collision = new Collision($level,$profile['step']);
		$waterStates = ['dry' => -$prototype['depth'],'shallow' => -intdiv($prototype['depth'],2),'full' => 0];
		$fits = static function(int $id) use ($prototype,$profile): bool {
			$c = $prototype['cells'][$id];
			return min($c['x1'] - $c['x0'],$c['y1'] - $c['y0']) > 2 * $profile['radius']
				&& $c['ceiling'] - $c['floor'] >= $profile['height'];
		};
		foreach($level->map->linedefs as $lineId => $line)
		{
			$sides = $level->sides($lineId);
			$a = $sides['front']; $b = $sides['back'];
			if($b === null || $a === $b || !isset($names[$a],$names[$b])) continue;
			$p = $level->map->vertices[$line['from']]; $q = $level->map->vertices[$line['to']];
			$length = hypot($q['x'] - $p['x'],$q['y'] - $p['y']);
			if($length <= 2 * $profile['radius'] || !$fits($a) || !$fits($b)) continue;
			$x = ($p['x'] + $q['x']) / 2; $y = ($p['y'] + $q['y']) / 2;
			$nx = ($q['y'] - $p['y']) / $length; $ny = ($p['x'] - $q['x']) / $length;
			$inset = $profile['radius'] + 2;
			foreach([[$a,$b,$nx,$ny],[$b,$a,-$nx,-$ny]] as [$from,$to,$dx,$dy])
			{
				// An open floor is a falling region, not a fictitious standing bridge.
				if($level->cameraPortalForPlane($from,0)?->link->passable()) continue;
				$start = [$x + $dx * $inset,$y + $dy * $inset];
				$end = [$x - $dx * $inset,$y - $dy * $inset];
				$source = $level->sectors[$from]; $destination = $level->sectors[$to];
				$floor = $source->floorZ(...$start); $target = $destination->floorZ(...$end);
				if($floor - $target > $profile['drop']) continue;
				$allowed = $witnesses = [];
				foreach($waterStates as $state => $surface)
				{
					$level->sectors[$prototype['model']]->ceiling = $surface;
					$z = $floor;
					$swimming = false;
					if($target - $z > $profile['step'])
					{
						if(!$profile['swim'] || $source->waterSurface(...[...$start,$z,$profile['height']]) === null) continue;
						$z = $surface - min(16,$profile['step']);
						$swimming = true;
					}
					if($target - $z > $profile['step'] || $source->ceilingZ(...$start) - $z < $profile['height']) continue;
					$pose = new Transform(...[...$start,$z]);
					$body = new Body($profile['radius'],$profile['height'],grounded: !$swimming);
					$hit = $collision->move($pose,$body,$end[0] - $start[0],$end[1] - $start[1]);
					if($hit || hypot($pose->x - $end[0],$pose->y - $end[1]) > .01) continue;
					$allowed[] = $state;
					$witnesses[$state] = ['start' => [...$start,$z],'end' => [...$end,$target],'line' => $lineId];
				}
				if(!$allowed) continue;
				$mode = $target - $floor > $profile['step'] ? 'swim' : ($floor - $target > $profile['step'] ? 'drop' : 'walk');
				$graph->connect($names[$from],$names[$to],$mode,count($allowed) === 3 ? [] : ['water' => $allowed],[], $witnesses);
			}
		}
		$level->sectors[$prototype['model']]->ceiling = -$prototype['depth'];
		foreach($prototype['connections'] as [$a,$b])
		foreach([[$a,$b],[$b,$a]] as [$from,$to])
		{
			[$x,$y,$z] = $from['point']; [$nx,$ny] = $from['normal'];
			[$tx,$ty,$tz] = $to['point']; [$tnx,$tny] = $to['normal'];
			$inset = $profile['radius'] + 2;
			$start = [$x - $nx * $inset,$y - $ny * $inset,$z];
			$end = [$tx - $tnx * $inset,$ty - $tny * $inset,$tz];
			$sourceId = $level->sectorAt($start[0],$start[1])->index;
			$targetId = $level->sectorAt($end[0],$end[1])->index;
			if(!$fits($sourceId) || !$fits($targetId) || $from['width'] <= 2 * $profile['radius']) continue;
			$pose = new Transform(...$start);
			$collision->moveAndSlide($pose,new Body($profile['radius'],$profile['height']),$nx * 2 * $inset,$ny * 2 * $inset);
			(new LineSpecials($level))->cross($start[0],$start[1],$pose,new PlayerState);
			if(hypot($pose->x - $end[0],$pose->y - $end[1]) > .1 || abs($pose->z - $tz) > .1)
			throw new \RuntimeException('Emitted wall portal does not realize its spatial pin');
			$graph->connect($names[$sourceId],$names[$targetId],'portal',[],[],['start' => $start,'end' => $end]);
		}
		$upper = $prototype['cells'][$prototype['regions']['upper.well']];
		$lower = $prototype['cells'][$prototype['regions']['building.well']];
		if($fits($prototype['regions']['upper.well']) && $fits($prototype['regions']['building.well'])
			&& $upper['floor'] - $lower['floor'] <= $profile['drop'])
		{
			$pose = new Transform(($upper['x0'] + $upper['x1']) / 2,($upper['y0'] + $upper['y1']) / 2,$upper['floor']);
			$start = [$pose->x,$pose->y,$pose->z];
			$velocity = new Velocity;
			$body = new Body($profile['radius'],$profile['height'],grounded: false);
			$physics = new VerticalPhysics($level,$profile['step']);
			for($i = 0; $i < 100; $i++) $physics->step($pose,$velocity,$body,1 / 35);
			if($level->sectorAt($pose->x,$pose->y)->index !== $prototype['regions']['building.well'] || !$body->grounded)
			throw new \RuntimeException('Gallery floor portal has no safe landing');
			$graph->connect('upper.well','building.well','floor-portal',[],[],['start' => $start,'end' => [$pose->x,$pose->y,$pose->z]]);
		}
		foreach($prototype['actions'] as $action)
		{
			$id = $prototype['regions'][$action['region']];
			if(!$fits($id)) continue;
			if($level->sectorAt($action['point'][0],$action['point'][1])->index !== $id)
			throw new \RuntimeException('Control is assigned to the wrong traversal region');
			// Execute the actual repeatable switch, including its settled destination.
			$target = $waterStates[$action['effects']['water']];
			$level->sectors[$prototype['model']]->ceiling = $target === 0 ? -$prototype['depth'] : 0;
			$specials = new LineSpecials($level);
			if(!$specials->use(new Transform(...[...$action['point'],$action['angle']]),new PlayerState))
			throw new \RuntimeException('Generated water control cannot be used');
			for($i = 0; $i < 200; $i++) $specials->update(1 / 35);
			if(abs($level->sectors[$prototype['model']]->ceiling - $target) > .01)
			throw new \RuntimeException('Water control does not produce its declared state');
			$graph->connect($action['region'],$action['region'],$action['id'],[],$action['effects'],['point' => $action['point']]);
		}
		return $graph;
	}
}
