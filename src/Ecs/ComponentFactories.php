<?php
declare(strict_types=1);
namespace Phonics\Ecs;

use Phonics\World\ScopeContext;

/** Explicit factories for data-authored components; never instantiate arbitrary class names. */
final class ComponentFactories
{
	/** Registered constructors indexed by stable component identity. */
	private array $factories = [];

	/**
	 * Register a constructor accepting values, ownership scope, and adapter-specific target.
	 *
	 * @param string $name Stable registration or member name.
	 * @param callable $factory Already loaded constructor or policy factory.
	 * @return void Stores the constructor, rejecting conflicting names.
	 */
	public function register(string $name, callable $factory): void
	{
		if($name === '' || isset($this->factories[$name]))
		throw new \LogicException("Conflicting component factory $name");

		$this->factories[$name] = $factory;
	}

	/**
	 * Alias an already registered constructor under a verified package export identity.
	 *
	 * @param string $reference Verified package export reference.
	 * @param string $name Stable registration or member name.
	 * @return void Registers an additional identity for the existing constructor.
	 */
	public function alias(string $reference, string $name): void
	{
		if($reference === $name) return;

		$this->register(
			$reference
			, $this->factories[$name] ?? throw new \OutOfBoundsException("Unknown component $name")
		);
	}

	/**
	 * Test whether already loaded code supplies this component.
	 *
	 * @param string $name Stable registration or member name.
	 * @return bool True when the requested constructor is registered.
	 */
	public function has(string $name): bool { return isset($this->factories[$name]); }

	/**
	 * Construct components before publishing an entity, rejecting ambiguous duplicate types.
	 *
	 * @param array $descriptors Resolved component descriptors and their nested values.
	 * @param ScopeContext $scope Owning content scope.
	 * @param mixed $target Adapter target receiving the components.
	 * @return array Fresh component instances, with duplicate runtime types rejected.
	 */
	public function build(array $descriptors, ScopeContext $scope, mixed $target = null): array
	{
		$result = [];

		foreach($descriptors as $descriptor)
		{
			$name = $descriptor['use'];
			$factory = $this->factories[$name] ?? throw new \OutOfBoundsException("Unknown component $name");
			$component = $factory($descriptor['values'], $scope, $target);

			if(!is_object($component)) throw new \LogicException("Component factory $name must return an object");

			$type = get_class($component);

			if(isset($result[$type])) throw new \LogicException("Duplicate component type $type");

			$result[$type] = $component;
		}

		return array_values($result);
	}
}
