<?php
declare(strict_types=1);
namespace PhpWasmEngine\Physics;

/** Segment broadphase plus exact ray/segment intersection. Polygons may be concave. */
final class Terrain
{
	private array $segments = [], $grid = [], $edges = [];
	public int $raycasts = 0;
	public function __construct(array $shapes = [],private int $cellSize = 64)
	{ foreach($shapes as $shape)
	{ $this->add($shape['points'],$shape['oneWay'] ?? false); } }
	public function add(array $points,bool $oneWay = false): void
	{
		$count = count($points);
		if($count < 2)
		{ return; }
		$area = 0;
		for($i = 0;$i < $count;$i++)
		{ [$x,$y] = $points[$i];
		[$u,$v] = $points[($i + 1) % $count];
		$area += $x * $v - $u * $y; }
		if($count > 2 && $area < 0)
		{ $points = array_reverse($points); }
		$edges = $count === 2?1:$count;
		for($i = 0;$i < $edges;$i++)
		{
			[$x,$y] = $points[$i];
			[$u,$v] = $points[($i + 1) % $count];
			$dx = $u - $x;
			$dy = $v - $y;
			$length = hypot($dx,$dy);
			if($length < 1e-9)
			{ continue; }
			$nx = $dy / $length;
			$ny = -$dx / $length;
			if($oneWay && $ny >= -.1)
			{ continue; }
			$key = json_encode([$x + 0.0,$y + 0.0,$u + 0.0,$v + 0.0]);
			$reverse = json_encode([$u + 0.0,$v + 0.0,$x + 0.0,$y + 0.0]);
			if(!$oneWay && isset($this->edges[$reverse]))
			{
				$this->segments[$this->edges[$reverse]] = null;
				unset($this->edges[$reverse]);
				continue;
			}
			$id = count($this->segments);
			$this->segments[] = [$x,$y,$u,$v,$nx,$ny,$oneWay];
			if(!$oneWay)
			{ $this->edges[$key] = $id; }
			for($cy = (int)floor(min($y,$v) / $this->cellSize);$cy <= (int)floor(max($y,$v) / $this->cellSize);$cy++)
			{
				for($cx = (int)floor(min($x,$u) / $this->cellSize);$cx <= (int)floor(max($x,$u) / $this->cellSize);$cx++)
				{ $this->grid["$cx,$cy"][] = $id; } }
		}
	}
	/** Earliest front-facing hit over the WHOLE displacement, not just its endpoint. */
	public function raycast(float $x,float $y,float $dx,float $dy,bool $ignoreOneWay = false): ?Hit
	{
		$this->raycasts++;
		if(abs($dx) + abs($dy) < 1e-12)
		{ return null; }
		$best = null;
		$seen = [];
		// Traverse only crossed spatial cells with a DDA, including corner neighbors.
		$cx = (int)floor($x / $this->cellSize);
		$cy = (int)floor($y / $this->cellSize);
		$ex = (int)floor(($x + $dx) / $this->cellSize);
		$ey = (int)floor(($y + $dy) / $this->cellSize);
		$sx = $dx <=> 0;
		$sy = $dy <=> 0;
		$tx = $sx?((($sx > 0?$cx + 1:$cx) * $this->cellSize - $x) / $dx):INF;
		$ty = $sy?((($sy > 0?$cy + 1:$cy) * $this->cellSize - $y) / $dy):INF;
		$dtX = $sx?$this->cellSize / abs($dx):INF;
		$dtY = $sy?$this->cellSize / abs($dy):INF;
		$limit = abs($ex - $cx) + abs($ey - $cy) + 3;
		for($n = 0;$n < $limit;$n++)
		{
			foreach([[$cx,$cy],[$cx - 1,$cy],[$cx,$cy - 1],[$cx - 1,$cy - 1]] as [$gx,$gy])
			{ foreach($this->grid["$gx,$gy"] ?? [] as $id)
			{
				if(isset($seen[$id]) || !isset($this->segments[$id]))
				{ continue; } $seen[$id] = true;
				[$ax,$ay,$bx,$by,$nx,$ny,$oneWay] = $this->segments[$id];
				if(($ignoreOneWay && $oneWay) || $dx * $nx + $dy * $ny >= -1e-9)
				{ continue; }
				$rx = $bx - $ax;
				$ry = $by - $ay;
				$den = $dx * $ry - $dy * $rx;
				if(abs($den) < 1e-12)
				{ continue; }
				$qx = $ax - $x;
				$qy = $ay - $y;
				$t = ($qx * $ry - $qy * $rx) / $den;
				$u = ($qx * $dy - $qy * $dx) / $den;
				if($t >= -1e-8 && $t <= 1.00000001 && $u >= -1e-8 && $u <= 1.00000001 && (!$best || $t < $best->t))
				{ $best = new Hit(max(0,$t),$x + $dx * $t,$y + $dy * $t,$nx,$ny,$oneWay); }
			} }
			if($cx === $ex && $cy === $ey)
			{ break; }
			if($tx < $ty)
			{ $cx += $sx;
			$tx += $dtX; } else
			{ $cy += $sy;
			$ty += $dtY; }
		}
		return $best;
	}
	/** Raycast a body center against segments expanded by the oriented body.
     * The three separating axes also cover segment endpoints between foot sensors.
     * This is a continuous convex cast; speed never determines collision sampling.
     */
	public function castBody(Body $body,float $dx,float $dy,bool $ignoreOneWay = false): ?Hit
	{
		$this->raycasts++;
		$ca = cos($body->angle);
		$sa = sin($body->angle);
		$best = null;
		$seen = [];
		$extent = hypot($body->radius,$body->halfHeight);
		$minX = (int)floor((min($body->x,$body->x + $dx) - $extent) / $this->cellSize);
		$maxX = (int)floor((max($body->x,$body->x + $dx) + $extent) / $this->cellSize);
		$minY = (int)floor((min($body->y,$body->y + $dy) - $extent) / $this->cellSize);
		$maxY = (int)floor((max($body->y,$body->y + $dy) + $extent) / $this->cellSize);
		for($gy = $minY;$gy <= $maxY;$gy++)
		{ for($gx = $minX;$gx <= $maxX;$gx++)
		{ foreach($this->grid["$gx,$gy"] ?? [] as $id)
		{
			if(isset($seen[$id]) || !isset($this->segments[$id]))
			{ continue; } $seen[$id] = true;
			[$ax,$ay,$bx,$by,$nx,$ny,$oneWay] = $this->segments[$id];
			if(($ignoreOneWay && $oneWay) || $dx * $nx + $dy * $ny >= -1e-8)
			{ continue; }
			$near = -INF;
			$far = INF;
			$entryNormal = [$nx,$ny];
			foreach([[$ca,$sa],[-$sa,$ca],[$nx,$ny]] as [$ux,$uy])
			{
				$radius = abs($ux * $ca + $uy * $sa) * $body->radius + abs(-$ux * $sa + $uy * $ca) * $body->halfHeight;
				$a = $ax * $ux + $ay * $uy;
				$b = $bx * $ux + $by * $uy;
				$lo = min($a,$b) - $radius;
				$hi = max($a,$b) + $radius;
				$origin = $body->x * $ux + $body->y * $uy;
				$velocity = $dx * $ux + $dy * $uy;
				if(abs($velocity) < 1e-10)
				{ if($origin < $lo - 1e-8 || $origin > $hi + 1e-8)
				{ $far = -INF;
				break; } continue; }
				$t0 = ($lo - $origin) / $velocity;
				$t1 = ($hi - $origin) / $velocity;
				if(min($t0,$t1) > $near)
				{ $near = min($t0,$t1);
				$entryNormal = $velocity > 0?[-$ux,-$uy]:[$ux,$uy]; }
				$far = min($far,max($t0,$t1));
			}
			if($near < -1e-7 || $near > 1 || $near > $far + 1e-8 || ($best && $near >= $best->t))
			{ continue; }
			// One-way casts are only valid when the lower face began above the platform.
			$support = abs($nx * $ca + $ny * $sa) * $body->radius + abs(-$nx * $sa + $ny * $ca) * $body->halfHeight;
			if($oneWay && ($body->x - $ax) * $nx + ($body->y - $ay) * $ny < $support - .01)
			{ continue; }
			[$hx,$hy] = [$nx,$ny];
			$cx = $body->x + $dx * $near;
			$cy = $body->y + $dy * $near;
			$segmentLength = ($bx - $ax) ** 2 + ($by - $ay) ** 2;
			$along = max(0,min(1,(($cx - $ax) * ($bx - $ax) + ($cy - $ay) * ($by - $ay)) / $segmentLength));
			$best = new Hit(max(0,$near),$ax + ($bx - $ax) * $along,$ay + ($by - $ay) * $along,$hx,$hy,$oneWay);
		} } }
		return $best;
	}
}
