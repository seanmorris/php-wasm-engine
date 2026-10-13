<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;

use PhpWasmEngine\Ecs\Query;
use PhpWasmEngine\Ecs\Registry;

/** Several independently positioned sprites attached to one entity. */
final class SpriteGroup
{
	/** @var list<Sprite> Sprites in painter order, with world-space positions. */
	public array $sprites;

	/**
	 * Group sprites without copying their mutable state.
	 *
	 * @param Sprite ...$sprites Initial sprites in painter order.
	 * @return void Retains the supplied sprites as one component.
	 */
	public function __construct(Sprite ...$sprites)
	{
		$this->sprites = $sprites;
	}

	/**
	 * Collect primary sprites and grouped sprites for a renderer sharing their atlas.
	 *
	 * @param Registry $registry Entity registry containing Sprite or SpriteGroup components.
	 * @param list<int>|null $entityIds Explicit painter order, or null for entity creation order.
	 * @return list<Sprite> Primary sprite followed by group members for each selected live entity.
	 */
	public static function collect(Registry $registry, ?array $entityIds = null): array
	{
		$ids = $entityIds ?? $registry->query(Query::any(Sprite::class, self::class));
		$sprites = [];

		foreach($ids as $id)
		{
			if($registry->has($id, Sprite::class))
			{
				$sprites[] = $registry->get($id, Sprite::class);
			}

			if($registry->has($id, self::class))
			{
				foreach($registry->get($id, self::class)->sprites as $sprite)
				{
					$sprites[] = $sprite;
				}
			}
		}

		return $sprites;
	}
}
