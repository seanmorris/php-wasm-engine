<?php
declare(strict_types=1);
namespace Phonics\Ecs;
use Phonics\Tiled\TileMap;
use Phonics\Tiled\Loader;

/** Translate recognized Tiled object classes and pallet references into ECS entities. */
final class ObjectSpawner
{
	/** Require every phonics.entity reference to resolve through a registered pallet alias. */
	public bool $strictReferences = false;
	private array $factories = [];
	private array $aliases = [];

	/**
	 * Associate a pallet export reference with an already registered factory.
	 * @param string $reference Session-scoped export reference.
	 * @param string $class Factory class name.
	 * @return void Registers the alias, rejecting conflicts and missing factories.
	 */
	public function alias(string $reference, string $class): void
	{
		if(!isset($this->factories[$class])) throw new \LogicException("Missing factory $class for $reference");

		if(isset($this->aliases[$reference]) && $this->aliases[$reference] !== $class)
		throw new \LogicException("Conflicting factory reference $reference");

		$this->aliases[$reference] = $class;
	}

	/**
	 * Associate a Tiled object class with a PHP component factory.
	 *
	 * @param string $class Tiled object class or legacy type to recognize.
	 * @param callable(array<string,mixed>,TileMap):iterable<object> $factory Receives the
	 * translated object and its map, and returns initial components.
	 * @return void Registers the factory; duplicate names raise LogicException.
	 */
	public function register(string $class, callable $factory): void
	{
		if(isset($this->factories[$class])) throw new \LogicException("Conflicting factory $class");

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
				$reference = Loader::properties($object['properties'] ?? [])['phonics.entity'] ?? null;

				if($reference && $this->strictReferences && !isset($this->aliases[$reference]))
				throw new \LogicException(
					"Unresolved entity export $reference in {$map->path}; enable its module in phonics.modules"
				);

				$class = $reference
					? ($this->aliases[$reference] ?? ($object['class'] ?? $object['type'] ?? ''))
					: ($object['class'] ?? $object['type'] ?? '');

				if(!isset($this->factories[$class]))
				{
					continue;
				}

				$object['x'] = ($object['x'] ?? 0) + $map->x + $layer->offsetX;
				$object['y'] = ($object['y'] ?? 0) + $map->y + $layer->offsetY;
				$object['properties'] = Loader::properties($object['properties'] ?? []);

				$entities[$object['id']] = $registry->create(...($this->factories[$class])($object, $map));
			}
		}

		return $entities;
	}
}
