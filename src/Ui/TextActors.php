<?php
declare(strict_types=1);
namespace Phonics\Ui;

use Phonics\Ecs\Registry;
use Phonics\Tiled\Loader;
use Phonics\Tiled\TileMap;

/** Promote Tiled TextActor objects into non-colliding world text ECS entities. */
final class TextActors
{
	/** @var list<int> Entity IDs allocated from the level's object layers. */
	private array $entities = [];
	private bool $closed = false;

	/**
	 * Text can come from the compatibility `content` property, the conventional `text` property, or
	 * Tiled's native text object payload. Both `TextActor` and `text-actor` class names
	 * are accepted so maps do not need a conversion pass.
	 *
	 * @param Registry $registry Entity registry to update.
	 * @param TileMap $map Loaded Tiled map defining this stage.
	 * @return void Creates entities immediately and retains their IDs for level cleanup.
	 */
	public function __construct(private Registry $registry, TileMap $map)
	{
		foreach($map->layers as $layer)
		{
			if(!$layer->visible || $layer->type !== 'objectgroup')
			{
				continue;
			}

			foreach($layer->objects as $object)
			{
				if(empty($object['visible']) && array_key_exists('visible', $object))
				{
					continue;
				}

				$class = strtolower((string)(($object['class'] ?? '') ?: ($object['type'] ?? '')));
				$class = str_replace(['_', ' '], '-', $class);

				if(!in_array($class, ['textactor', 'text-actor'], true))
				{
					continue;
				}

				$properties = Loader::properties($object['properties'] ?? []);
				$native = is_array($object['text'] ?? null) ? $object['text'] : [];
				$text = $properties['content'] ?? $properties['text'] ?? $native['text'] ?? '';

				if(!is_string($text) || $text === '')
				{
					continue;
				}

				$width = (float)($object['width'] ?? 0);

				if($width <= 0 && isset($properties['width']))
				{
					$width = (float)$properties['width'];
				}

				$color = $properties['color'] ?? $native['color'] ?? null;
				$tint = is_string($color) ? Loader::color($color) : [1, 1, 1, 1];

				foreach($tint as $index => $channel)
				{
					$tint[$index] = $channel * $layer->tint[$index];
				}

				$align = strtolower((string)($properties['align'] ?? $native['halign'] ?? 'left'));

				if($align === 'justify')
				{
					$align = 'left';
				}

				$actor = new TextActor(
					$text
					, (float)($object['x'] ?? 0) + $map->x + $layer->offsetX
					, (float)($object['y'] ?? 0) + $map->y + $layer->offsetY
					, $width > 0 ? $width : INF
					, (float)($properties['scale'] ?? 1)
					, $tint
					, $align
					, $layer->opacity
					, isset($object['id']) ? (int)$object['id'] : null
				);

				$this->entities[] = $registry->create($actor);
			}
		}
	}

	/**
	 * Return whether the source map contained no usable TextActor objects.
	 *
	 * @return bool True when construction created no text entities.
	 */
	public function isEmpty(): bool { return $this->entities === []; }

	/**
	 * Retire only entities created for this level; persisted actors are left alone.
	 *
	 * @return void Destroys all still-live entities created by this loader exactly once.
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

		$this->entities = [];
	}
}
