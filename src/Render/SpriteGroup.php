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
	 * @param Registry $registry Entity registry containing sprite presentation components.
	 * @param list<int>|null $entityIds Explicit painter order, or null for entity creation order.
	 * @return list<Sprite> Primary sprite followed by group members for each selected live entity.
	 */
	public static function collect(Registry $registry, ?array $entityIds = null): array
	{
		$sprites = [];

		foreach(self::collectGroups($registry, $entityIds) as $group)
		{
			foreach($group as $sprite)
			{
				$sprites[] = $sprite;
			}
		}

		return $sprites;
	}

	/**
	 * Collect painter-ordered sprite planes while retaining composite outline boundaries.
	 *
	 * SpriteLayers can place an entity's rear and foreground planes immediately around another
	 * selected entity. This lets a vehicle surround its rider without coupling the renderer to
	 * gameplay types. Without a live selected anchor, its planes remain at the owner's position.
	 *
	 * @param Registry $registry Entity registry containing sprite presentation components.
	 * @param list<int>|null $entityIds Explicit painter order, or null for entity creation order.
	 * @return list<list<Sprite>> Nonempty entity groups retaining primary-then-member order.
	 */
	public static function collectGroups(Registry $registry, ?array $entityIds = null): array
	{
		$ids = $entityIds ?? $registry->query(Query::any(Sprite::class, self::class, SpriteLayers::class));
		$selected = array_fill_keys($ids, true);
		$before = $after = [];

		foreach($ids as $id)
		{
			if(!$registry->has($id, SpriteLayers::class)) continue;

			$layers = $registry->get($id, SpriteLayers::class);
			$anchor = $layers->anchor;

			if($anchor === null || !isset($selected[$anchor]) || !$registry->alive($anchor)) continue;

			if($layers->behind) $before[$anchor][] = $layers->behind;

			if($layers->front) $after[$anchor][] = $layers->front;
		}

		$groups = [];

		foreach($ids as $id)
		{
			foreach($before[$id] ?? [] as $sprites) $groups[] = $sprites;

			if($registry->has($id, SpriteLayers::class))
			{
				$layers = $registry->get($id, SpriteLayers::class);
				$anchored = $layers->anchor !== null && isset($selected[$layers->anchor])
					&& $registry->alive($layers->anchor);

				if(!$anchored)
				{
					if($layers->behind) $groups[] = $layers->behind;

					if($layers->front) $groups[] = $layers->front;
				}

				foreach($after[$id] ?? [] as $sprites) $groups[] = $sprites;
				continue;
			}

			$sprites = [];

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

			if($sprites)
			{
				$groups[] = $sprites;
			}

			foreach($after[$id] ?? [] as $sprites) $groups[] = $sprites;
		}

		return $groups;
	}
}
