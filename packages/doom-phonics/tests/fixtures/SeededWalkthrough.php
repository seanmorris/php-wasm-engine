<?php
declare(strict_types=1);
namespace DoomPhonics\Tests;

require_once __DIR__ . '/LayeredWalkthrough.php';

/**
 * Exercise complete generated routes through ordinary fixed-step movement.
 *
 * The fixture starts once, then walks, uses pumps, swims and rides the generated
 * lifts. Region witnesses guide the player; positions are never patched mid-route.
 */
final class SeededWalkthrough
{
	/** Swim through a flooded ceiling seam and walk onto the destination room's rim. */
	public static function swimPortal(\DoomPhonics\Game\Level $level,array $prototype,array $portal): array
	{
		$source=$prototype['cells'][$prototype['regions'][$portal['to']]];
		$target=$prototype['cells'][$prototype['regions'][$portal['from']]];
		$walk=new LayeredWalkthrough($level,[...$source['center'],$source['ceiling']-45]);
		// Other routes may already have filled this live level. Drain through its
		// control first so every ascent exercises an actual refill operation.
		$drain=array_values(array_filter($prototype['actions'],static fn($a)=>$a['id']==='drain'))[0];
		$control=new \DoomPhonics\Component\Transform(...[...$drain['point'],$drain['angle']]);
		$walk->specials->use($control,new \DoomPhonics\Game\PlayerState);
		for($i=0;$i<200;$i++) $walk->specials->update(1/35);
		$fill=array_values(array_filter($prototype['actions'],static fn($a)=>$a['id']==='fill'))[0];
		$control=new \DoomPhonics\Component\Transform(...[...$fill['point'],$fill['angle']]);
		if(!$walk->specials->use($control,new \DoomPhonics\Game\PlayerState)) throw new \RuntimeException('Flood control did not activate');
		for($i=0;$i<200;$i++) $walk->specials->update(1/35);
		$walk->command->forward=.6; $walk->command->pitch=1.3; $walk->velocity->z=100;
		$frames=[];
		// Turn in a small circle to stay within the aperture during the ascent.
		for($i=0;$i<50;$i++) {
			$walk->pose->angle=$i*M_PI/4; $walk->tick();
			$frames[]=$walk->position()+['previousZ'=>$walk->pose->previousZ,'vz'=>$walk->velocity->z];
		}
		$room=preg_replace('/\.(shaft|gallery-aperture)$/','.core',$portal['from']);
		$rim=$prototype['cells'][$prototype['regions'][$room]];
		$walk->walkTo(...$rim['center']); $walk->command->forward=0; $walk->tick(20);
		return ['frames'=>$frames,'end'=>$walk->position(),'rim'=>$prototype['regions'][$room],
			'target'=>$prototype['regions'][$portal['from']],'source'=>$prototype['regions'][$portal['to']]];
	}

	/** Follow one graph route, returning its physical endpoints for native/browser checks. */
	public static function run(\DoomPhonics\Game\Level $level,array $prototype,array $route): array
	{
		$c = $prototype['cells'][$prototype['regions'][$prototype['start']]];
		$center = $c['center'] ?? [($c['x0']+$c['x1'])/2,($c['y0']+$c['y1'])/2];
		$walk = new LayeredWalkthrough($level,[...$center,$c['floor']]);
		$walk->travelStrength = .2;
		$state = 'dry'; $points = [];
		foreach($route as $edge)
		{
			if($edge['effects'])
			{
				$action = array_values(array_filter($prototype['actions'],static fn($a)=>$a['id'] === $edge['mode']))[0];
				$walk->walkTo($action['point'][0],$action['point'][1]);
				$walk->pose->angle = $action['angle'];
				$walk->command->forward = 0;
				if(!$walk->specials->use($walk->pose,new \DoomPhonics\Game\PlayerState)) throw new \RuntimeException('Route pump is unreachable');
				$walk->tick(200); $state = $edge['effects']['water'];
			}
			elseif($edge['mode'] === 'floor-portal')
			{
				[$x,$y] = $edge['witness']['start'];
				$target = $prototype['regions'][$edge['to']];
				// Approach the well center until gravity changes packed map space.
				$walk->command->pitch = 0;
				for($t=0;$t<1200;$t++)
				{
					if($level->sectorAt($walk->pose->x,$walk->pose->y)->index === $target) break;
					$walk->pose->angle = atan2($y-$walk->pose->y,$x-$walk->pose->x);
					$walk->command->forward = min(.15,hypot($x-$walk->pose->x,$y-$walk->pose->y)/100);
					$walk->tick();
				}
				$walk->command->forward = 0;
				$walk->tick(35);
			}
			elseif($edge['mode'] === 'elevator')
			{
				$lift = array_values(array_filter($prototype['lifts'],static fn($l)=>in_array($edge['from'],[$l['low'],$l['platform'],$l['high']],true)))[0];
				if($edge['from'] === $lift['low'])
				{
					$walk->walkTo(...array_slice($lift['lowPoint'],0,2));
					$walk->pose->angle = $lift['angle'];
					$walk->command->forward = 0;
					if(!$walk->specials->use($walk->pose,new \DoomPhonics\Game\PlayerState)) throw new \RuntimeException('Route lift cannot be called');
					for($t=0;$t<600;$t++) { $walk->tick(); if($level->sectors[$prototype['regions'][$lift['platform']]]->floor === (float)$lift['lowPoint'][2]) break; }
					$walk->walkTo(...array_slice($lift['platformPoint'],0,2));
					$walk->command->forward = 0; $walk->velocity->x = $walk->velocity->y = 0;
					$walk->tick(200);
				}
				elseif($edge['from'] === $lift['high'])
				{
					$walk->walkTo(...array_slice($lift['highPoint'],0,2));
					$walk->pose->angle = $lift['angle'] + M_PI;
					$walk->command->forward = 0;
					if(!$walk->specials->use($walk->pose,new \DoomPhonics\Game\PlayerState)) throw new \RuntimeException('Route lift cannot be called from above');
					for($t=0;$t<600;$t++) { $walk->tick(); if($level->sectors[$prototype['regions'][$lift['platform']]]->floor === (float)$lift['lowPoint'][2]) break; }
					$walk->walkTo(...array_slice($lift['platformPoint'],0,2));
					$walk->walkTo(...array_slice($lift['lowPoint'],0,2));
					$walk->command->forward = 0; $walk->velocity->x = $walk->velocity->y = 0;
					$walk->tick(200);
				}
				else $walk->walkTo(...array_slice($lift['highPoint'],0,2));
			}
			else
			{
				$witness = $edge['witness'][$state] ?? $edge['witness'];
				[$x,$y] = $witness['start']; [$tx,$ty] = $witness['end'];
				$walk->walkTo($x,$y);
				if($edge['mode'] === 'portal')
				{
					$pair = array_values(array_filter(array_merge(...$prototype['connections']),static fn($p)=>
						abs($p['point'][0]-$x)+abs($p['point'][1]-$y) < 19))[0];
					$angle = atan2($pair['normal'][1],$pair['normal'][0]);
				}
				else $angle = atan2($ty-$y,$tx-$x);
				if($edge['mode'] === 'door')
				{
					$door=$level->sectors[$prototype['regions'][$edge['to']]];
					$walk->pose->angle=$angle;
					$walk->command->forward=0;
					$walk->specials->use($walk->pose,new \DoomPhonics\Game\PlayerState);
					for($t=0;$t<600;$t++) {
						$walk->tick();
						if($door->ceiling-$door->floor >= 60) break;
					}
					if($door->ceiling-$door->floor < 60) throw new \RuntimeException('Route door does not open');
				}
				$walk->enter($prototype['regions'][$edge['to']],$angle,$edge['mode'] === 'swim' ? .75 : 0);
			}
			$position = $walk->position();
			if($position['sector'] !== $prototype['regions'][$edge['to']])
			throw new \RuntimeException('Route missed ' . $edge['to'] . ': ' . json_encode($position));
			$points[] = ['region'=>$edge['to'],'mode'=>$edge['mode']] + $position;
		}
		return $points;
	}

	/** Follow each successive curved tread without teleporting or patching height. */
	public static function spiral(\DoomPhonics\Game\Level $level,array $layer): array
	{
		[$ox,$oy,$oz] = $layer['packed'];
		$first = $layer['cells'][0];
		$walk = new LayeredWalkthrough($level,[$ox+$first['center'][0],$oy+$first['center'][1],$oz]);
		$trace = [];
		foreach($layer['cells'] as $cell)
		{
			$walk->walkTo($ox+$cell['center'][0],$oy+$cell['center'][1]);
			$trace[] = $walk->position();
			if(abs($walk->pose->z-($oz+$cell['floor']))>.01) throw new \RuntimeException('Spiral tread cannot be climbed');
		}
		return $trace;
	}
}
