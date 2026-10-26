<?php
declare(strict_types=1);
namespace Phonics\Package;

use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\Ecs\ObjectSpawner;
use Phonics\Tiled\Loader;
use Phonics\Asset\Assets;
use Phonics\Asset\Resource;
use Phonics\World\AdapterContext;
use Phonics\World\WorldRuntime;

/** Session-scoped systems, factories, and cleanup for independently published modules. */
final class Context
{
	/** Entity registry shared by the game and every activated module. */
	public Registry $entities;
	/** Ordered system scheduler shared by the session. */
	public Scheduler $systems;
	/** Tiled object factory registry, including resolved pallet aliases. */
	public ObjectSpawner $factories;
	/** Tiled JSON loader using the session's canonical resource mappings. */
	public Loader $loader;
	/** Source-format-neutral adapter, scope, state, and system-set runtime. */
	public WorldRuntime $runtime;
	private array $registered = [], $cleanup = [];
	private bool $closed = false;

	/**
	 * Create a module context with optional engine-owned ECS services.
	 *
	 * @param array $world Tiled world configuration and source identity.
	 * @param Registry|null $entities Entity registry shared by the session.
	 * @param Scheduler|null $systems Scheduler shared by the session, or null to create one.
	 * @return void Initializes independent factory and Tiled-loader services.
	 */
	public function __construct(public array $world = [], ?Registry $entities = null, ?Scheduler $systems = null)
	{
		$this->entities = $entities ?? new Registry;
		$this->systems = $systems ?? new Scheduler($this->entities);
		$this->factories = new ObjectSpawner;
		$this->loader = new Loader;
		$this->runtime = new WorldRuntime(
			new AdapterContext(
				static fn(string $reference, string $declaring): string => Assets::relative($declaring, $reference)
				, static function(string $url): string {
					$bytes = file_get_contents(Resource::local($url));

					if(!is_string($bytes)) throw new \RuntimeException("Cannot read content source: $url");

					return $bytes;
				}
			)
			, $this->entities
			, $this->systems
		);
		$this->cleanup[] = fn() => $this->runtime->close();
	}

	/**
	 * Register a uniquely named system and explicit scheduler dependencies.
	 *
	 * @param string $name Unique registration or property name.
	 * @param callable $system System callback receiving the registry and elapsed seconds.
	 * @param array $after System names that must execute first.
	 * @return void Owns the registration until this context closes.
	 */
	public function system(string $name, callable $system, array $after = []): void
	{
		if($this->closed || $this->systems->has($name)) throw new \LogicException("Conflicting system $name");

		$this->registered[$name] = true;
		$this->systems->add($name, $system, $after);

		$this->onClose(/**
		 * Apply the registered session callback.
		 *
			 * @return void Removes the system from the session scheduler.
		 */
			fn() => $this->systems->remove($name)
		);
	}

	/**
	 * Register a namespaced Tiled factory.
	 *
	 * @param string $name Unique registration or property name.
	 * @param callable $factory Component factory for a translated Tiled object.
	 * @return void Makes the factory available to Tiled spawning in this session.
	 */
	public function factory(string $name, callable $factory): void
	{
		if($this->closed) throw new \LogicException('Context is closed');

		$this->factories->register($name, $factory);
	}

	/**
	 * Retain module cleanup in reverse registration order.
	 *
	 * @param callable $cleanup Callback invoked when the session closes.
	 * @return void Stores the callback until context shutdown.
	 */
	public function onClose(callable $cleanup): void
	{
		if($this->closed) throw new \LogicException('Context is closed');

		$this->cleanup[] = $cleanup;
	}

	/**
	 * Release all registered module resources, even when one cleanup throws.
	 *
	 * @return void Runs every cleanup once and marks the context closed.
	 */
	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$error = null;

		foreach(array_reverse($this->cleanup) as $cleanup)
		{
			try { $cleanup(); } catch(\Throwable $failure) { $error ??= $failure; }
		}

		$this->cleanup = [];

		if($error) throw $error;
	}
}
