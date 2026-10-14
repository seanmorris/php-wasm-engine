<?php
declare(strict_types=1);
namespace PhpWasmEngine\Gameplay;

use PhpWasmEngine\Ecs\Query;
use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Physics\Body;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Tiled\TileMap;

/** Tiled ring promotion, swept player pickup detection, and owned entity cleanup. */
final class Rings
{
	private array $entities = [], $previous = [];
	private bool $closed = false;

	/**
	 * Promote tiles annotated with a ring class into collectible sprite entities.
	 *
	 * @param Registry $registry Registry receiving ring and sprite components.
	 * @param TileMap $map Loaded map whose matching tile cells become entities and are cleared.
	 * @param string $tileClass Nonempty Tiled tile class identifying collectible rings.
	 * @return void Spawns every visible matching tile with metadata-defined radius and rewards.
	 */
	public function __construct(private Registry $registry, TileMap $map, string $tileClass = 'ring')
	{
		if($tileClass === '')
		{
			throw new \InvalidArgumentException('Ring tile class cannot be empty');
		}

		foreach($map->layers as $layer)
		{
			if(!$layer->visible || $layer->type !== 'tilelayer')
			{
				continue;
			}

			foreach($layer->chunks as $chunk)
			{
				for($row = $chunk->y; $row < $chunk->y + $chunk->height; $row++)
				{
					for($column = $chunk->x; $column < $chunk->x + $chunk->width; $column++)
					{
						$gid = $chunk->gidAt($column, $row);
						$tile = $gid->id ? $map->tile($gid->id) : null;

						if(($tile['class'] ?? $tile['type'] ?? '') !== $tileClass)
						{
							continue;
						}

						[$x, $y] = $map->projection->project($column, $row);
						[$offsetX, $offsetY] = $tile['offset'];
						[, , $width, $height] = $tile['rect'];

						$x += $map->x + $layer->offsetX + $offsetX;
						$y += $map->y + $layer->offsetY + $offsetY
							+ $map->projection->tileHeight - $height;

						if($map->projection->orientation === 'isometric')
						{
							$x -= $width / 2;
						}

						$x += $width / 2;
						$y += $height / 2;
						$properties = $tile['properties'];

						$ring = new Ring(
							$x
							, $y
							, (float)($properties['ring.radius'] ?? min($width, $height) * .3125)
							, (int)($properties['ring.value'] ?? 1)
							, (int)($properties['ring.points'] ?? 0)
						);

						$sprite = new Sprite($gid->id, $x, $y, .5, .5);
						$sprite->flipX = (bool)($gid->flags & 8);
						$sprite->flipY = (bool)($gid->flags & 4);
						$sprite->tint = $layer->tint;
						$sprite->opacity = $layer->opacity;

						if($gid->flags & 2)
						{
							$sprite->rotation = M_PI / 2;
							[$sprite->flipX, $sprite->flipY] = [$sprite->flipY, !$sprite->flipX];
						}

						$this->entities[] = $registry->create($ring, $sprite);
						$layer->set($column, $row, 0);
					}
				}
			}
		}
	}

	/**
	 * Collect rings intersected by each body's swept bounds during the current fixed step.
	 *
	 * @param Registry $registry Registry containing Body plus RingCounter collectors and Ring
	 * entities.
	 * @param float $dt Fixed simulation delta in seconds, unused because positions define the sweep.
	 * @return void Awards each ring once and destroys its entity before the rendered frame.
	 */
	public function update(Registry $registry, float $dt): void
	{
		if($this->closed)
		{
			return;
		}

		$rings = $registry->query(Ring::class);
		$active = [];

		foreach($registry->query(Query::all(Body::class, RingCounter::class)) as $collector)
		{
			$body = $registry->get($collector, Body::class);
			$counter = $registry->get($collector, RingCounter::class);
			$from = $this->previous[$collector] ?? [$body->x, $body->y];
			$active[$collector] = $this->previous[$collector] = [$body->x, $body->y];

			foreach($rings as $key => $entity)
			{
				if(!$registry->alive($entity))
				{
					unset($rings[$key]);

					continue;
				}

				$ring = $registry->get($entity, Ring::class);

				if(!$this->intersects($body, $from, $ring))
				{
					continue;
				}

				$counter->collect($ring);
				$registry->destroy($entity);
				unset($rings[$key]);
			}
		}

		$this->previous = array_intersect_key($this->previous, $active);
	}

	/**
	 * Reset a collector's sweep origin after spawning, teleporting, or changing levels.
	 *
	 * @param int $entity Collector entity whose previous position is replaced.
	 * @param Body $body Body supplying the new world position.
	 * @return void Prevents a teleport path from collecting rings between its endpoints.
	 */
	public function sync(int $entity, Body $body): void
	{
		$this->previous[$entity] = [$body->x, $body->y];
	}

	/**
	 * Test a moving body's center against a ring-expanded oriented-body bounding box.
	 *
	 * @param Body $body Collector at the sweep endpoint, including current surface rotation.
	 * @param array{float,float} $from Collector center at the previous fixed update.
	 * @param Ring $ring Candidate pickup and its circular collection radius.
	 * @return bool True when the center segment crosses the expanded ring bounds.
	 */
	private function intersects(Body $body, array $from, Ring $ring): bool
	{
		$cos = cos($body->angle);
		$sin = sin($body->angle);
		$halfWidth = abs($cos) * $body->radius + abs($sin) * $body->halfHeight + $ring->radius;
		$halfHeight = abs($sin) * $body->radius + abs($cos) * $body->halfHeight + $ring->radius;

		$delta = [$body->x - $from[0], $body->y - $from[1]];
		$origin = [$from[0] - $ring->x, $from[1] - $ring->y];
		$entry = 0.0;
		$exit = 1.0;

		foreach([[$origin[0], $delta[0], $halfWidth], [$origin[1], $delta[1], $halfHeight]] as [
			$position
			, $movement
			, $extent
		]){
			if(abs($movement) < 1e-9)
			{
				if(abs($position) > $extent)
				{
					return false;
				}

				continue;
			}

			$near = (-$extent - $position) / $movement;
			$far = ($extent - $position) / $movement;

			if($near > $far)
			{
				[$near, $far] = [$far, $near];
			}

			$entry = max($entry, $near);
			$exit = min($exit, $far);

			if($entry > $exit)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Destroy uncollected rings owned by this map and discard collector sweep history.
	 *
	 * @return void Releases level entities once; collected rings are already absent.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;

		foreach($this->entities as $entity)
		{
			if($this->registry->alive($entity))
			{
				$this->registry->destroy($entity);
			}
		}

		$this->entities = $this->previous = [];
	}
}
