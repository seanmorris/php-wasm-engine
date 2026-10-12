<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ecs;
use PhpWasmEngine\Tiled\
{
	TileMap,Loader
};

final class ObjectSpawner
{
	private array $factories = [];

	/**
	 * Associate a Tiled object class with a PHP component factory.
	 *
	 * @param string $class Tiled object class or legacy type to recognize.
	 * @param callable(array<string,mixed>,TileMap):iterable<object> $factory Receives the
	 * translated object and its map, and returns initial components.
	 * @return void Replaces any factory registered under the same class name.
	 */
	public function register(string $class, callable $factory): void
	{
		$this->factories[$class] = $factory;
	}

	/**
	 * Create entities for recognized object classes, applying map and layer offsets.
	 *
	 * @param TileMap $map Map containing object layers and their world placement.
	 * @param Registry $registry Registry receiving the factory-created component objects.
	 * @return array<int,int> Mapping from Tiled object IDs to newly allocated entity IDs.
	 */
	public function spawn(TileMap $map, Registry $registry): array
	{
		$entities = [];
		foreach($map->layers as $layer)
		{
			foreach($layer->objects as $object)
			{
				$class = $object['class'] ?? $object['type'] ?? '';
				if(!isset($this->factories[$class]))
				{
					continue;
				}
				$object['x'] = ($object['x'] ?? 0) + $map->x + $layer->offsetX;
				$object['y'] = ($object['y'] ?? 0) + $map->y + $layer->offsetY;
				$object['properties'] = Loader::properties($object['properties'] ?? []);
				$entities[$object['id']] = $registry->create(...($this->factories[$class])($object
					, $map));
			}
		}
		return $entities;
	}
}
