<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ecs;

final class Scheduler
{
	private array $systems = [];
	private ?array $ordered = null;

	/**
	 * Bind an ordered system scheduler to an entity registry.
	 *
	 * @param Registry $registry Registry passed to systems and flushed after each update.
	 * @return void Creates an empty system list.
	 */
	public function __construct(public Registry $registry) {}

	/**
	 * Register or replace a named system and invalidate the dependency order.
	 *
	 * @param string $name Unique system name.
	 * @param callable(Registry,float):void $system Receives the registry and simulation delta in
	 * seconds.
	 * @param list<string> $after Names of systems that must execute before this one.
	 * @return void Stores the callback and its ordering constraints.
	 */
	public function add(string $name, callable $system, array $after = []): void
	{
		$this->systems[$name] = [$system,$after];
		$this->ordered = null;
	}

	/**
	 * Remove a named system and invalidate the cached execution order.
	 *
	 * @param string $name Registered system name to remove.
	 * @return void Removes the system if present.
	 */
	public function remove(string $name): void
	{
		unset($this->systems[$name]);
		$this->ordered = null;
	}

	/**
	 * Resolve dependencies, run all systems, and flush deferred entity changes.
	 *
	 * @param float $dt Simulation delta in seconds passed unchanged to each system.
	 * @return void Completes one simulation update; cycles or missing dependencies raise
	 * LogicException.
	 */
	public function update(float $dt): void
	{
		if($this->ordered === null)
		{
			$result = [];
			$active = [];
			$done = [];

			$visit =
				/**
				 * Visit system dependencies and append the system in topological order.
				 *
				 * @param string $name Registered system name to visit.
				 * @return void Updates traversal state, rejecting missing dependencies and cycles.
				 */
				function($name) use (&$visit, &$result, &$active, &$done) {
					if(isset($done[$name]))
					{
						return;
					}
					if(isset($active[$name]))
					{
						throw new \LogicException("System dependency cycle at $name");
					}
					if(!isset($this->systems[$name]))
					{
						throw new \LogicException("Unknown system dependency $name");
					}
					$active[$name] = true;
					foreach($this->systems[$name][1] as $dependency)
					{
						$visit($dependency);
					}
					unset($active[$name]);
					$done[$name] = true;
					$result[] = $name;
				};
			foreach(array_keys($this->systems) as $name)
			{
				$visit($name);
			}
			$this->ordered = $result;
		}
		foreach($this->ordered as $name)
		{
			($this->systems[$name][0])($this->registry, $dt);
		}
		$this->registry->flush();
	}
}
