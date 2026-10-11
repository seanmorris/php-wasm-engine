<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ecs;
use PhpWasmEngine\Tiled\
{TileMap,Loader};
final class ObjectSpawner
{
	private array $factories = [];
	public function register(string $class,callable $factory): void { $this->factories[$class] = $factory; }
	public function spawn(TileMap $map,Registry $registry): array
	{
		$entities = [];
		foreach($map->layers as $layer)
		{ foreach($layer->objects as $object)
		{
			$class = $object['class'] ?? $object['type'] ?? '';
			if(!isset($this->factories[$class]))
			{ continue; }
			$object['x'] = ($object['x'] ?? 0) + $map->x + $layer->offsetX;
			$object['y'] = ($object['y'] ?? 0) + $map->y + $layer->offsetY;
			$object['properties'] = Loader::properties($object['properties'] ?? []);
			$entities[$object['id']] = $registry->create(...($this->factories[$class])($object,$map));
		} }
		return $entities;
	}
}
