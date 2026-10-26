<?php
declare(strict_types=1);
namespace DoomPhonics\Game;

use DoomPhonics\Component\Body;
use DoomPhonics\Component\Transform;

/** Sweeps projectile height through portals and mutable sector planes. */
final class ProjectileTracer
{

	public function __construct(public Level $level) {}

	public function trace(
		Transform $pose
		, Body $body
		, float $dx
		, float $dy
		, float $dz
	): ?ProjectileImpact {
		$distance = hypot($dx, $dy);
		$current = $this->level->sectorAt($pose->x, $pose->y)->index;
		$from = 0.0;
		$wall = $this->wall($pose, $body, $dx, $dy, $dz);
		$limit = $wall?->fraction ?? 1.0;

		$hits = $distance > 1e-9
			? $this->level->spatial->cast($pose->x, $pose->y, $dx, $dy, $distance)
			: [];

		foreach($hits as $hit)
		{
			$fraction = min(1.0, max(0.0, $hit['distance'] / $distance));

			if($fraction > $limit + 1e-7) break;

			if($fraction < $from) continue;

			$plane = $this->plane($pose, $body, $dx, $dy, $dz, $current, $from, $fraction);

			if($plane) return $plane;

			$z = $pose->z + $dz * $fraction;
			$portal = $this->level->portal($hit['line']);
			$back = $portal?->opposite($current);
			$surface = $this->lineSurface($hit['line'], $z, $body->height, $pose->x + $dx * $fraction, $pose->y + $dy * $fraction);

			if($portal && $back === null) $surface = ProjectileImpact::WALL;

			if($surface !== null)
			return new ProjectileImpact(
				$surface
				, $fraction
				, $pose->x + $dx * $fraction
				, $pose->y + $dy * $fraction
				, $z
				, $current
				, $hit['line']
			);

			$current = $back;
			$from = $fraction;
		}

		$plane = $this->plane($pose, $body, $dx, $dy, $dz, $current, $from, $limit);

		return $plane ?? $wall;
	}

	private function wall(
		Transform $pose
		, Body $body
		, float $dx
		, float $dy
		, float $dz
	): ?ProjectileImpact {
		if(hypot($dx, $dy) < 1e-9) return null;

		$nearest = null;

		$lines = $this->level->spatial->near(
			min($pose->x, $pose->x + $dx) - $body->radius
			, min($pose->y, $pose->y + $dy) - $body->radius
			, max($pose->x, $pose->x + $dx) + $body->radius
			, max($pose->y, $pose->y + $dy) + $body->radius
		);

		foreach($lines as $lineId)
		{
			$line = $this->level->map->linedefs[$lineId];
			$a = $this->level->map->vertices[$line['from']];
			$b = $this->level->map->vertices[$line['to']];

			$hit = $this->capsuleHit(
				$pose->x
				, $pose->y
				, $dx
				, $dy
				, (float)$a['x']
				, (float)$a['y']
				, (float)$b['x']
				, (float)$b['y']
				, $body->radius
			);

			if($hit === null || ($nearest && $hit >= $nearest->fraction)) continue;

			$z = $pose->z + $dz * $hit;
			$surface = $this->lineSurface($lineId, $z, $body->height, $pose->x + $dx * $hit, $pose->y + $dy * $hit);

			// A capsule reaches a wall before its center ray. Solid walls stop at that
			// earlier contact; sky escapes are classified when the center crosses the
			// terminal boundary so they retain an exact map-exit position.
			if($surface === null || $surface === ProjectileImpact::SKY) continue;

			$nearest = new ProjectileImpact(
				ProjectileImpact::WALL
				, $hit
				, $pose->x + $dx * $hit
				, $pose->y + $dy * $hit
				, $z
				, $this->level->sectorAt($pose->x + $dx * $hit, $pose->y + $dy * $hit)->index
				, $lineId
			);
		}

		return $nearest;
	}

	/** Return the physical response for crossing a linedef at this vertical interval. */
	private function lineSurface(int $lineId, float $bottom, float $height, float $x, float $y): ?string
	{
		$portal = $this->level->portal($lineId);

		if($portal)
		return $portal->admitsProjectile($bottom, $height, .001, $x, $y) ? null : ProjectileImpact::WALL;

		$terminal = $this->level->terminalSector($lineId);

		return $terminal && $terminal->hasSkyCeiling() && $bottom >= $terminal->ceilingZ($x, $y) - .001
			? ProjectileImpact::SKY
			: ProjectileImpact::WALL;
	}

	private function capsuleHit(
		float $px
		, float $py
		, float $dx
		, float $dy
		, float $ax
		, float $ay
		, float $bx
		, float $by
		, float $radius
	): ?float {
		$vx = $bx - $ax;
		$vy = $by - $ay;
		$length = hypot($vx, $vy);

		if($length < 1e-9) return $this->circleHit($px, $py, $dx, $dy, $ax, $ay, $radius);

		$tx = $vx / $length;
		$ty = $vy / $length;
		$nx = -$ty;
		$ny = $tx;
		$nearest = null;

		foreach([-1.0, 1.0] as $sign)
		{
			$outX = $nx * $sign;
			$outY = $ny * $sign;
			$start = ($px - $ax) * $outX + ($py - $ay) * $outY;
			$toward = $dx * $outX + $dy * $outY;

			if($start < $radius - 1e-6 || $toward >= -1e-9) continue;

			$time = ($radius - $start) / $toward;

			if($time < -1e-7 || $time > 1.0000001) continue;

			$along = ($px + $dx * $time - $ax) * $tx + ($py + $dy * $time - $ay) * $ty;

			if($along < 0 || $along > $length) continue;

			$time = max(0, $time);

			if($nearest === null || $time < $nearest) $nearest = $time;
		}

		foreach([[$ax,$ay],[$bx,$by]] as [$cx,$cy])
		{
			$hit = $this->circleHit($px, $py, $dx, $dy, $cx, $cy, $radius);

			if($hit !== null && ($nearest === null || $hit < $nearest)) $nearest = $hit;
		}

		return $nearest;
	}

	private function circleHit(
		float $px
		, float $py
		, float $dx
		, float $dy
		, float $cx
		, float $cy
		, float $radius
	): ?float {
		$mx = $px - $cx;
		$my = $py - $cy;
		$a = $dx * $dx + $dy * $dy;

		if($a < 1e-12) return null;

		$c = $mx * $mx + $my * $my - $radius * $radius;

		if($c < -1e-6) return null;

		$b = 2 * ($mx * $dx + $my * $dy);
		$discriminant = $b * $b - 4 * $a * $c;

		if($discriminant < 0) return null;

		$time = (-$b - sqrt($discriminant)) / (2 * $a);

		return $time >= -1e-7 && $time <= 1.0000001 ? max(0, $time) : null;
	}

	private function plane(
		Transform $pose
		, Body $body
		, float $dx
		, float $dy
		, float $dz
		, int $sectorId
		, float $from
		, float $to
	): ?ProjectileImpact {
		$sector = $this->level->sectors[$sectorId];
		$floor = $pose->z - $sector->floorZ($pose->x, $pose->y);
		$ceiling = $pose->z + $body->height - $sector->ceilingZ($pose->x, $pose->y);
		$floorRate = $dz - $sector->floorSlope[0] * $dx - $sector->floorSlope[1] * $dy;
		$ceilingRate = $dz - $sector->ceilingSlope[0] * $dx - $sector->ceilingSlope[1] * $dy;
		$fraction = null;
		$surface = null;

		if($floor + $floorRate * $from < -.001)
		{
			$fraction = $from;
			$surface = ProjectileImpact::FLOOR;
		}
		elseif($floorRate < 0 && $floor + $floorRate * $to <= 0)
		{
			$fraction = -$floor / $floorRate;
			$surface = ProjectileImpact::FLOOR;
		}
		elseif(!$sector->hasSkyCeiling() && $ceiling + $ceilingRate * $from > .001)
		{
			$fraction = $from;
			$surface = ProjectileImpact::CEILING;
		}
		elseif(!$sector->hasSkyCeiling() && $ceilingRate > 0 && $ceiling + $ceilingRate * $to >= 0)
		{
			$fraction = -$ceiling / $ceilingRate;
			$surface = ProjectileImpact::CEILING;
		}

		if($fraction === null || $fraction < $from - 1e-7 || $fraction > $to + 1e-7) return null;

		$fraction = min($to, max($from, $fraction));

		return new ProjectileImpact(
			$surface
			, $fraction
			, $pose->x + $dx * $fraction
			, $pose->y + $dy * $fraction
			, $pose->z + $dz * $fraction
			, $sectorId
		);
	}
}
