<?php
declare(strict_types=1);
namespace Phonics\Ecs;

/**
 * Execute named ECS systems in dependency order and flush deferred entity changes.
 *
 * Systems declare before and after dependencies by name; the scheduler caches a topological order and
 * applies deferred registry changes after every update.
 */
final class Scheduler
{
	/** Named system callbacks and dependency metadata. */
	private array $systems = [];
	/** Cached dependency-ordered system callback list. */
	private ?array $ordered = null;
	/** Disabled registrations retain their dependency identities but do not execute. */
	private array $enabled = [];
	/** Work committed after the current system iteration and registry flush. */
	private array $pending = [];
	private bool $updating = false;
	/** CPU milliseconds used by each system during the latest update. */
	public array $timings = [];

	/**
	 * Bind an ordered system scheduler to an entity registry.
	 *
	 * @param Registry $registry Registry passed to systems and flushed after each update.
	 * @return void Creates an empty system list.
	 */
	public function __construct(
		/** Registry passed to systems and flushed after each update. */
		public Registry $registry
	){
	}

	/**
	 * Register or replace a named system and invalidate the dependency order.
	 *
	 * @param string $name Unique system name.
	 * @param callable(Registry,float):void $system Receives the registry and simulation delta in seconds.
	 * @param list<string> $after Names of systems that must execute before this one.
	 * @return void Stores the callback and its ordering constraints.
	 */
	public function add(string $name, callable $system, array $after = []): void
	{
		$this->systems[$name] = [$system,$after];
		$this->enabled[$name] = true;
		$this->ordered = null;
	}

	/**
	 * Check whether a system name is already owned by this scheduler.
	 * @param string $name System registration name.
	 * @return bool Whether the system exists.
	 */
	public function has(string $name): bool { return isset($this->systems[$name]); }

	/**
	 * Remove a named system and invalidate the cached execution order.
	 *
	 * @param string $name Registered system name to remove.
	 * @return void Removes the system if present.
	 */
	public function remove(string $name): void
	{
		unset($this->systems[$name], $this->enabled[$name], $this->timings[$name]);
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
		if($this->updating) throw new \LogicException('Scheduler updates cannot nest');

		$this->validate();
		$this->updating = true;
		$error = null;

		try
		{
			foreach($this->ordered as $name)
			{
				if(!($this->enabled[$name] ?? false)) continue;

				$started = hrtime(true);
				($this->systems[$name][0])($this->registry, $dt);
				$this->timings[$name] = (hrtime(true) - $started) / 1e6;
			}
		}
		catch(\Throwable $failure)
		{
			$error = $failure;
		}
		finally
		{
			$this->updating = false;

			try { $this->registry->flush(); } catch(\Throwable $failure) { $error ??= $failure; }

			$pending = $this->pending;
			$this->pending = [];

			foreach($pending as $commit)
			try { $commit(); } catch(\Throwable $failure) { $error ??= $failure; }
		}

		if($error) throw $error;
	}

	/**
	 * Check dependencies without executing a system, allowing transactional preparation.
	 *
	 * @return void Rejects invalid dependencies or configuration.
	 */
	public function validate(): void
	{
		if($this->ordered === null)
		{
			$result = [];
			$active = [];
			$done = [];

			/**
			 * Visit system dependencies and append the system in topological order.
			 *
			 * @param string $name Registered system name to visit.
			 * @return void Updates traversal state, rejecting missing dependencies and cycles.
			 */
			$visit = function($name) use (&$visit, &$result, &$active, &$done) {
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
	}

	/**
	 * Toggle execution while retaining dependencies and instances.
	 *
	 * @param string $name Stable registration or member name.
	 * @param bool $enabled Enabled supplied by the caller.
	 * @return void Updates whether the registered system executes.
	 */
	public function enable(string $name, bool $enabled): void
	{
		if(!isset($this->systems[$name])) throw new \OutOfBoundsException("Unknown system $name");

		$this->enabled[$name] = $enabled;

		if(!$enabled) unset($this->timings[$name]);
	}

	/**
	 * Whether a system iteration is in progress.
	 *
	 * @return bool True while the scheduler is iterating systems.
	 */
	public function updating(): bool { return $this->updating; }

	/**
	 * Commit a lifecycle change now, or after the current system iteration.
	 *
	 * @param callable $commit Commit supplied by the caller.
	 * @return void Runs immediately when idle or queues the transition for the boundary.
	 */
	public function defer(callable $commit): void
	{
		if($this->updating) $this->pending[] = $commit;
		else $commit();
	}
}
