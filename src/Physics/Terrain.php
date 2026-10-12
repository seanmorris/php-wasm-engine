<?php
declare(strict_types=1);
namespace PhpWasmEngine\Physics;

/** Segment broadphase plus exact ray/segment intersection. Polygons may be concave. */
final class Terrain
{
	private array $segments = [], $grid = [], $edges = [], $opposites = [];
	private array $polygons = [], $polygonGrid = [], $switches = [];
	public int $raycasts = 0;

	/**
	 * Create spatial indexes and add the supplied solid or one-way shapes.
	 *
	 * @param list<array{points:array,oneWay?:bool,collisionLayer?:int}> $shapes Initial vertex
	 * lists with optional one-way and collision-plane metadata.
	 * @param int $cellSize Positive broadphase cell size in world pixels.
	 * @return void Initializes terrain with shared geometry unless a shape selects a plane.
	 */
	public function __construct(array $shapes = [], private int $cellSize = 64)
	{
		foreach($shapes as $shape)
		{
			$this->add($shape['points'], $shape['oneWay'] ?? false, $shape['collisionLayer'] ?? 0);
		}
	}

	/**
	 * Index a polygon or directed two-point edge on a collision plane.
	 * Closed polygons may be concave; opposite edges are linked for cancellation between active
	 * neighbors.
	 *
	 * @param list<array{0:float|int,1:float|int}> $points World-space vertices; two points form a
	 * directed edge, three or more a closed polygon.
	 * @param bool $oneWay Keep only upward-facing edges and permit passage from below.
	 * @param int $collisionLayer Nonnegative plane ID; zero is common to every body.
	 * @return void Adds geometry without changing any body's selected plane.
	 */
	public function add(array $points, bool $oneWay = false, int $collisionLayer = 0): void
	{
		if($collisionLayer < 0)
		{
			throw new \InvalidArgumentException('Collision layers must be nonnegative');
		}

		$count = count($points);

		if($count < 2)
		{
			return;
		}

		$area = 0;

		for($i = 0; $i < $count; $i++)
		{
			[$x,$y] = $points[$i];
			[$u,$v] = $points[($i + 1) % $count];
			$area += $x * $v - $u * $y;
		}

		if($count > 2 && $area < 0)
		{
			$points = array_reverse($points);
		}

		if($count > 2 && !$oneWay)
		{
			$id = count($this->polygons);
			$this->polygons[] = [$points,$collisionLayer];
			$xs = array_column($points, 0);
			$ys = array_column($points, 1);

			for($cy = (int)floor(min($ys) / $this->cellSize); $cy <= (int)floor(
				max($ys) / $this->cellSize
			); $cy++
			){
				for($cx = (int)floor(min($xs) / $this->cellSize); $cx <= (int)floor(
					max($xs) / $this->cellSize
				);
					$cx++
				){
					$this->polygonGrid["$cx,$cy"][] = $id;
				}
			}
		}

		$edges = $count === 2?1:$count;

		for($i = 0; $i < $edges; $i++)
		{
			[$x,$y] = $points[$i];
			[$u,$v] = $points[($i + 1) % $count];
			$dx = $u - $x;
			$dy = $v - $y;
			$length = hypot($dx, $dy);

			if($length < 1e-9)
			{
				continue;
			}

			$nx = $dy / $length;
			$ny = -$dx / $length;

			if($oneWay && $ny >= -.1)
			{
				continue;
			}

			$key = json_encode([$x + 0.0,$y + 0.0,$u + 0.0,$v + 0.0]);
			$reverse = json_encode([$u + 0.0,$v + 0.0,$x + 0.0,$y + 0.0]);
			$id = count($this->segments);
			$this->segments[] = [$x,$y,$u,$v,$nx,$ny,$oneWay,$collisionLayer];

			if(!$oneWay)
			{
				// A common edge cancels against an active neighbor, never an inactive plane.
				foreach($this->edges[$reverse] ?? [] as $other)
				{
					$this->opposites[$id][] = $other;
					$this->opposites[$other][] = $id;
				}
				$this->edges[$key][] = $id;
			}

			for($cy = (int)floor(min($y, $v) / $this->cellSize); $cy <= (int)floor(
				max($y, $v) / $this->cellSize
			); $cy++
			){
				for($cx = (int)floor(min($x, $u) / $this->cellSize); $cx <= (int)floor(
					max($x, $u) / $this->cellSize
				); $cx++
				){
					$this->grid["$cx,$cy"][] = $id;
				}
			}
		}
	}

	/**
	 * Determine whether an edge belongs to the queried route and remains exposed.
	 *
	 * @param int $id Internal segment index.
	 * @param int $collisionLayer Selected body plane, in addition to shared plane zero.
	 * @return bool True if the edge is active and has no active opposite neighbor.
	 */
	private function active(int $id, int $collisionLayer): bool
	{
		$plane = $this->segments[$id][7];

		if($plane !== 0 && $plane !== $collisionLayer)
		{
			return false;
		}

		foreach($this->opposites[$id] ?? [] as $other)
		{
			$plane = $this->segments[$other][7];

			if($plane === 0 || $plane === $collisionLayer)
			{
				return false;
			}
		}
		return true;
	}

	/**
	 * Test point occupancy against closed polygons on the selected and shared planes.
	 * One-way edges have no solid interior.
	 *
	 * @param float $x World X coordinate in pixels.
	 * @param float $y World Y coordinate in pixels.
	 * @param int $collisionLayer Additional collision plane included with shared terrain.
	 * @return bool True if any eligible polygon contains the point.
	 */
	public function solidAt(float $x, float $y, int $collisionLayer = 1): bool
	{
		$cx = (int)floor($x / $this->cellSize);
		$cy = (int)floor($y / $this->cellSize);

		foreach($this->polygonGrid["$cx,$cy"] ?? [] as $id)
		{
			[$points,$plane] = $this->polygons[$id];

			if($plane !== 0 && $plane !== $collisionLayer)
			{
				continue;
			}

			$inside = false;

			for($i = 0, $j = count($points) - 1; $i < count($points); $j = $i++)
			{
				[$ax,$ay] = $points[$i];
				[$bx,$by] = $points[$j];

				if(($ay > $y) !== ($by > $y) && $x < ($bx - $ax) * ($y - $ay) / ($by - $ay) + $ax)
				{
					$inside = !$inside;
				}
			}

			if($inside)
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * Register a nonsolid directional gate.
	 *
	 * @param LayerSwitch $switch Validated world-space gate to retain.
	 * @return void Appends the region for subsequent movement queries.
	 */
	public function addSwitch(LayerSwitch $switch): void { $this->switches[] = $switch; }

	/**
	 * Find the first eligible gate crossed by a body center, independent of object order.
	 *
	 * @param Body $body Actor providing the start point, direction, and selected collision layer.
	 * @param float $dx Full proposed horizontal displacement in pixels.
	 * @param float $dy Full proposed vertical displacement in pixels.
	 * @param array<int,bool> $seen Gate IDs already considered during this sweep, which must be
	 * skipped.
	 * @return array{id:int,t:float,switch:LayerSwitch}|null Earliest gate with its internal ID and
	 * entry fraction, or null when none is eligible.
	 */
	public function nextSwitch(Body $body, float $dx, float $dy, array $seen = []): ?array
	{
		$best = null;

		foreach($this->switches as $id => $switch)
		{
			if(isset($seen[$id]) || $switch->target($body) === null)
			{
				continue;
			}

			$t = $switch->crossing($body->x, $body->y, $dx, $dy);

			if($t !== null && ($best === null || $t < $best['t']))
			{
				$best = ['id' => $id,'t' => $t,'switch' => $switch];
			}
		}
		return $best;
	}

	/**
	 * Apply an eligible route change only when newly solid geometry leaves the body clear.
	 *
	 * @param Body $body Actor to update in place; the caller must already have reached the gate.
	 * @param LayerSwitch $switch Reached gate whose directional rule chooses the destination.
	 * @return bool True when the selected collision layer changed; false when ineligible or
	 * blocked.
	 */
	public function applySwitch(Body $body, LayerSwitch $switch): bool
	{
		$target = $switch->target($body);

		if($target === null || !$this->canSwitch($body, $target))
		{
			return false;
		}

		$body->collisionLayer = $target;
		return true;
	}

	/**
	 * Check destination occupancy and new solid edges against the oriented body interior.
	 *
	 * @param Body $body Actor whose center, bounds, orientation, and current plane are inspected.
	 * @param int $target Destination collision plane to test together with common terrain.
	 * @return bool True when changing planes will not embed the actor in newly active solid
	 * geometry.
	 */
	private function canSwitch(Body $body, int $target): bool
	{
		if($this->solidAt($body->x, $body->y, $target))
		{
			return false;
		}

		$extent = hypot($body->radius, $body->halfHeight);
		$ca = cos($body->angle);
		$sa = sin($body->angle);
		$seen = [];

		for($cy = (int)floor(($body->y - $extent) / $this->cellSize); $cy <= (int)floor(
			($body->y + $extent) / $this->cellSize
		); $cy++
		){
			for($cx = (int)floor(($body->x - $extent) / $this->cellSize); $cx <= (int)floor(
				($body->x + $extent) / $this->cellSize
			); $cx++
			){
				foreach($this->grid["$cx,$cy"] ?? [] as $id)
				{
					if(isset($seen[$id]))
					{
						continue;
					}

					$seen[$id] = true;

					if(!$this->active($id, $target) || $this->active($id, $body->collisionLayer))
					{
						continue;
					}

					[$ax,$ay,$bx,$by,,,$oneWay] = $this->segments[$id];

					if($oneWay)
					{
						continue;
					}

					$near = 0;
					$far = 1;

					foreach([[$ca,$sa,$body->radius],[-$sa,$ca,$body->halfHeight]] as [$ux,$uy
						, $radius
					]){
						$radius = max(0, $radius - .001);
						$origin = ($ax - $body->x) * $ux + ($ay - $body->y) * $uy;
						$delta = ($bx - $ax) * $ux + ($by - $ay) * $uy;

						if(abs($delta) < 1e-10)
						{
							if(abs($origin) >= $radius)
							{
								$far = -INF;
								break;
							}

							continue;
						}

						$t0 = (-$radius - $origin) / $delta;
						$t1 = ($radius - $origin) / $delta;
						$near = max($near, min($t0, $t1));
						$far = min($far, max($t0, $t1));
					}

					if($near < $far)
					{
						return false;
					}
				}
			}
		}
		return true;
	}

	/**
	 * Find the earliest front-facing edge along the entire displacement.
	 * Spatial-cell DDA traversal includes corner neighbors, so crossed thin edges cannot be
	 * skipped.
	 *
	 * @param float $x Ray origin X in world pixels.
	 * @param float $y Ray origin Y in world pixels.
	 * @param float $dx Full horizontal ray displacement in pixels.
	 * @param float $dy Full vertical ray displacement in pixels.
	 * @param bool $ignoreOneWay Exclude one-way platforms, such as during a drop-through.
	 * @param int $collisionLayer Additional collision plane included with shared terrain.
	 * @return Hit|null Earliest eligible contact, or null when the ray misses terrain.
	 */
	public function raycast(float $x, float $y, float $dx, float $dy, bool $ignoreOneWay = false
		, int $collisionLayer = 1
	): ?Hit
	{
		$this->raycasts++;

		if(abs($dx) + abs($dy) < 1e-12)
		{
			return null;
		}

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

		for($n = 0; $n < $limit; $n++)
		{
			foreach([[$cx,$cy],[$cx - 1,$cy],[$cx,$cy - 1],[$cx - 1,$cy - 1]] as [$gx,$gy])
			{
				foreach($this->grid["$gx,$gy"] ?? [] as $id)
				{
					if(isset($seen[$id]) || !$this->active($id, $collisionLayer))
					{
						continue;
					}

					$seen[$id] = true;
					[$ax,$ay,$bx,$by,$nx,$ny,$oneWay] = $this->segments[$id];

					if(($ignoreOneWay && $oneWay) || $dx * $nx + $dy * $ny >= -1e-9)
					{
						continue;
					}

					$rx = $bx - $ax;
					$ry = $by - $ay;
					$den = $dx * $ry - $dy * $rx;

					if(abs($den) < 1e-12)
					{
						continue;
					}

					$qx = $ax - $x;
					$qy = $ay - $y;
					$t = ($qx * $ry - $qy * $rx) / $den;
					$u = ($qx * $dy - $qy * $dx) / $den;

					if($t >= -1e-8 && $t <= 1.00000001 && $u >= -1e-8 && $u <= 1.00000001 && (!$best
						|| $t < $best->t
					)){
						$best = new Hit(
							max(0, $t)
							, $x + $dx * $t
							, $y + $dy * $t
							, $nx
							, $ny
							, $oneWay
						);
					}
				}
			}

			if($cx === $ex && $cy === $ey)
			{
				break;
			}

			if($tx < $ty)
			{
				$cx += $sx;
				$tx += $dtX;
			} else
			{
				$cy += $sy;
				$ty += $dtY;
			}
		}
		return $best;
	}

	/**
	 * Cast the body center against segments expanded by its oriented rectangle.
	 * Three separating axes include segment endpoints, covering obstacles between foot sensors
	 * without speed-based sampling.
	 *
	 * @param Body $body Actor providing bounds, angle, origin, and selected collision plane.
	 * @param float $dx Full proposed horizontal displacement in pixels.
	 * @param float $dy Full proposed vertical displacement in pixels.
	 * @param bool $ignoreOneWay Exclude one-way platforms during this cast.
	 * @return Hit|null Earliest body contact along the full displacement, or null when
	 * unobstructed.
	 */
	public function castBody(Body $body, float $dx, float $dy, bool $ignoreOneWay = false): ?Hit
	{
		$this->raycasts++;
		$ca = cos($body->angle);
		$sa = sin($body->angle);
		$best = null;
		$seen = [];
		$extent = hypot($body->radius, $body->halfHeight);
		$minX = (int)floor((min($body->x, $body->x + $dx) - $extent) / $this->cellSize);
		$maxX = (int)floor((max($body->x, $body->x + $dx) + $extent) / $this->cellSize);
		$minY = (int)floor((min($body->y, $body->y + $dy) - $extent) / $this->cellSize);
		$maxY = (int)floor((max($body->y, $body->y + $dy) + $extent) / $this->cellSize);

		for($gy = $minY; $gy <= $maxY; $gy++)
		{
			for($gx = $minX; $gx <= $maxX; $gx++)
			{
				foreach($this->grid["$gx,$gy"] ?? [] as $id)
				{
					if(isset($seen[$id]) || !$this->active($id, $body->collisionLayer))
					{
						continue;
					}

					$seen[$id] = true;
					[$ax,$ay,$bx,$by,$nx,$ny,$oneWay] = $this->segments[$id];

					if(($ignoreOneWay && $oneWay) || $dx * $nx + $dy * $ny >= -1e-8)
					{
						continue;
					}

					$near = -INF;
					$far = INF;
					$entryNormal = [$nx,$ny];

					foreach([[$ca,$sa],[-$sa,$ca],[$nx,$ny]] as [$ux,$uy])
					{
						$radius = abs($ux * $ca + $uy * $sa) * $body->radius + abs(
							-$ux * $sa + $uy * $ca
						) * $body->halfHeight;
						$a = $ax * $ux + $ay * $uy;
						$b = $bx * $ux + $by * $uy;
						$lo = min($a, $b) - $radius;
						$hi = max($a, $b) + $radius;
						$origin = $body->x * $ux + $body->y * $uy;
						$velocity = $dx * $ux + $dy * $uy;

						if(abs($velocity) < 1e-10)
						{
							if($origin < $lo - 1e-8 || $origin > $hi + 1e-8)
							{
								$far = -INF;
								break;
							}

							continue;
						}

						$t0 = ($lo - $origin) / $velocity;
						$t1 = ($hi - $origin) / $velocity;

						if(min($t0, $t1) > $near)
						{
							$near = min($t0, $t1);
							$entryNormal = $velocity > 0?[-$ux,-$uy]:[$ux,$uy];
						}

						$far = min($far, max($t0, $t1));
					}

					if($near < -1e-7 || $near > 1 || $near > $far + 1e-8 || ($best && $near >= $best
						->t
					)){
						continue;
					}

					// One-way casts are only valid when the lower face began above the platform.
					$support = abs($nx * $ca + $ny * $sa) * $body->radius + abs(
						-$nx * $sa + $ny * $ca
					) * $body->halfHeight;

					if($oneWay && ($body->x - $ax) * $nx + ($body->y - $ay) * $ny < $support - .01)
					{
						continue;
					}

					[$hx,$hy] = [$nx,$ny];
					$cx = $body->x + $dx * $near;
					$cy = $body->y + $dy * $near;
					$segmentLength = ($bx - $ax) ** 2 + ($by - $ay) ** 2;

					$along = max(
						0
						, min(
							1
							, (($cx - $ax) * ($bx - $ax) + ($cy - $ay) * (
								$by - $ay
							)) / $segmentLength
						)
					);

					$best = new Hit(
						max(0, $near)
						, $ax + ($bx - $ax) * $along
						, $ay + ($by - $ay) * $along
						, $hx
						, $hy
						, $oneWay
					);
				}
			}
		}
		return $best;
	}
}
