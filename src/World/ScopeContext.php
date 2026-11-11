<?php
declare(strict_types=1);
namespace Phonics\World;

use Phonics\Ecs\OwnedEntities;
use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\Render\FramePipeline;
use Phonics\Render\RenderPass;

/**
 * Own systems, entities, render passes, state, and cleanup for one active source scope.
 *
 * All registrations are tagged to a stable source/scope key and released in reverse order, preventing
 * content transitions from leaking entities or passes.
 */
final class ScopeContext
{
	/** Reverse-order cleanup callbacks owned by this scope. */
	private array $cleanup = [];
	/** Entities owned by this scope, taken down newest first after the cleanup callbacks. */
	private OwnedEntities $entities;
	/** Whether this instance has released its owned resources. */
	private bool $closed = false;
	/** Content object exposed by the active adapter scope. */
	private $content = null;
	/** Activation is separate from resource ownership. */
	private bool $enabled = true;
	/** Optional active descendant lookup supplied by WorldRuntime. */
	private $descendants = null;
	/** Explicit snapshot callbacks owned by systems. */
	private array $snapshots = [];
	/** Registered system names, retained while a scope is suspended. */
	private array $systemNames = [];

	/**
	 * Bind a scope to shared ECS and render services.
	 *
	 * @param string $sourceId World-local source ID.
	 * @param ScopeDescriptor $descriptor Stable adapter scope description.
	 * @param Registry $registry Shared entity registry.
	 * @param Scheduler $scheduler Shared ordered system scheduler.
	 * @param FramePipeline|null $pipeline Optional ordered render pipeline.
	 * @param array<string,mixed> $state JSON-serializable retained scope state.
	 * @param array<string,mixed> $services Session host services keyed by stable names.
	 * @return void Creates an empty ownership boundary.
	 */
	public function __construct(
		/** World-local source ID. */
		public string $sourceId
		, /** Stable adapter scope description. */
		public ScopeDescriptor $descriptor
		, /** Shared entity registry. */
		public Registry $registry
		, /** Shared ordered system scheduler. */
		public Scheduler $scheduler
		, /** Optional ordered render pipeline. */
		public ?FramePipeline $pipeline = null
		, /** JSON-serializable retained scope state. */
		private array $state = []
		, /** Session host services keyed by stable names. */
		private array $services = []
	){
		$this->assertSerializable($state);
		$this->entities = new OwnedEntities($registry);
	}

	/**
	 * Build the globally stable source and scope key.
	 * @return string Namespaced ownership key.
	 */
	public function key(): string { return $this->sourceId . ':' . $this->descriptor->id; }

	/**
	 * Store adapter-specific content for system sets.
	 * @param mixed $content Active parsed content.
	 * @return void Replaces the current content reference.
	 */
	public function setContent($content): void { $this->content = $content; }

	/**
	 * Return adapter-specific active content.
	 * @return mixed Value installed by the active content source.
	 */
	public function content() { return $this->content; }

	/**
	 * Return state restored for this stable scope.
	 * @return array<string,mixed> Mutable JSON-safe state.
	 */
	public function state(): array { return $this->state; }

	/**
	 * Whether this scope may currently simulate or render.
	 *
	 * @return bool True only while this scope is enabled and open.
	 */
	public function active(): bool { return $this->enabled && !$this->closed; }

	/**
	 * Enable or suspend registrations without destroying their owned state.
	 *
	 * @param bool $active Whether simulation and rendering are enabled.
	 * @return void Enables or suspends this scope’s registrations.
	 */
	public function setActive(bool $active): void
	{
		$this->enabled = $active && !$this->closed;

		foreach($this->systemNames as $name)
		if($this->scheduler->has($name)) $this->scheduler->enable($name, $this->enabled);
	}

	/**
	 * Supply an ownership lookup without introducing content-format rules.
	 *
	 * @param callable $lookup Callback returning prepared descendant scopes.
	 * @return void Prepared descendants in parent-before-child order.
	 */
	public function descendants(callable $lookup): void { $this->descendants = $lookup; }

	/**
	 * Query only live entities owned by this active scope and, optionally, its active children.
	 *
	 * @param array|string|\Phonics\Ecs\Query $components Required component types or an explicit ECS query.
	 * @param bool $descendants Include active descendants when true.
	 * @return iterable Live entity IDs owned by the requested active scopes.
	 */
	public function query(array|string|\Phonics\Ecs\Query $components = [], bool $descendants = false): iterable
	{
		if(!$this->active()) return;

		foreach($this->registry->query(
			is_array($components) ? \Phonics\Ecs\Query::all(...$components) : $components
		) as $id
		)
		if($this->entities->owns($id)) yield $id;

		if($descendants && $this->descendants)
		foreach(($this->descendants)() as $child)
		foreach($child->query($components) as $id) yield $id;
	}

	/**
	 * Transfer cleanup responsibility while preserving an entity's identity.
	 *
	 * @param int $id Stable entity or source-local target identity.
	 * @param self $destination New ownership scope sharing the same registry.
	 * @return void Moves cleanup responsibility without changing entity identity.
	 */
	public function transfer(int $id, self $destination): void
	{
		$this->requireOpen();

		if($destination->registry !== $this->registry || !$this->entities->owns($id))
		throw new \LogicException('Entity transfer requires its owner and a shared registry');

		$destination->own($id);
		$this->entities->disown($id);
	}

	/**
	 * List owned entity IDs for construction and inspection, including while inactive.
	 *
	 * @return array Owned live entity IDs, including while inactive.
	 */
	public function entities(): array { return $this->entities->ids(); }

	/**
	 * Stop owning an entity that a system retired independently.
	 *
	 * @param int $id Stable entity or source-local target identity.
	 * @return void Removes cleanup responsibility for the given entity.
	 */
	public function disown(int $id): void { $this->entities->disown($id); }

	/**
	 * Register JSON-safe state capture; the returned values are restored through state().
	 *
	 * @param string $name Stable registration or member name.
	 * @param callable $capture Callback returning JSON-safe state for the next activation.
	 * @return void Registers one named state capture callback.
	 */
	public function onSnapshot(string $name, callable $capture): void
	{
		$this->requireOpen();

		if(isset($this->snapshots[$name])) throw new \LogicException("Duplicate snapshot $name");

		$this->snapshots[$name] = $capture;
	}

	/**
	 * Capture explicitly registered state without serializing arbitrary PHP objects.
	 *
	 * @return array Explicit JSON-safe state for later restoration.
	 */
	public function snapshot(): array
	{
		$state = $this->state;

		foreach($this->snapshots as $name => $capture) $state[$name] = $capture();
		$this->assertSerializable($state);

		return $this->state = $state;
	}

	/**
	 * Look up a session host service supplied by the game entry point.
	 * @param string $name Stable service name.
	 * @param mixed $default Value returned when the service is absent.
	 * @return mixed Registered service or the supplied default.
	 */
	public function service(string $name, $default = null)
	{
		return $this->services[$name] ?? $default;
	}

	/**
	 * Replace retained state after validating JSON serialization.
	 *
	 * @param array<string,mixed> $state New retained state.
	 * @return void Stores the state for the next snapshot.
	 */
	public function replaceState(array $state): void
	{
		$this->assertSerializable($state);
		$this->state = $state;
	}

	/**
	 * Register a scope-namespaced ECS system.
	 *
	 * @param string $name Local system name.
	 * @param callable $system Scheduler callback.
	 * @param list<string> $after Local or absolute system dependencies.
	 * @return string Fully qualified scheduler name.
	 */
	public function system(string $name, callable $system, array $after = []): string
	{
		$this->requireOpen();
		$prefix = $this->key() . '/';
		$id = $prefix . $name;

		/**
		 * Qualify a local dependency with this scope's prefix; names containing a colon are absolute.
		 *
		 * @param string $dependency Local or absolute system name.
		 * @return string Scheduler system name.
		 */
		$qualify = static fn(string $dependency): string => str_starts_with($dependency, '@')
			? substr($dependency, 1)
			: (str_contains($dependency, ':') ? $dependency : $prefix . $dependency);

		$dependencies = array_map($qualify, $after);

		if($this->scheduler->has($id)) throw new \LogicException("Conflicting scoped system $id");

		$this->scheduler->add($id, $system, $dependencies);
		$this->systemNames[] = $id;
		$this->scheduler->enable($id, $this->active());

		/**
		 * Unregister the scoped system when the scope closes.
		 *
		 * @return void Removes the system from the shared scheduler.
		 */
		$unregisterSystem = fn() => $this->scheduler->remove($id);
		$this->onClose($unregisterSystem);

		return $id;
	}

	/**
	 * Create an entity owned by this scope.
	 *
	 * @param object ...$components Initial ECS components.
	 * @return int Stable entity ID until scope closure.
	 */
	public function entity(object ...$components): int
	{
		$this->requireOpen();
		$id = $this->registry->create(...$components);
		$this->own($id);

		return $id;
	}

	/**
	 * Adopt an entity created by a format-specific factory.
	 *
	 * @param int $id Live entity in this scope's shared registry.
	 * @return int The same ID, convenient for factory pipelines.
	 */
	public function own(int $id): int
	{
		$this->requireOpen();

		return $this->entities->own($id);
	}

	/**
	 * Add an owned render pass to the shared frame pipeline.
	 *
	 * @param RenderPass $pass Pass with a globally unique ID.
	 * @return void Removes and closes the pass when the scope closes.
	 */
	public function pass(RenderPass $pass): void
	{
		$this->requireOpen();

		if(!$this->pipeline) throw new \LogicException('No render pipeline is available');

		$this->pipeline->add(new ScopedPass($this, $pass));

		/**
		 * Detach the owned render pass when the scope closes.
		 *
		 * @return void Removes and closes the pass if the pipeline still exists.
		 */
		$detachPass = fn() => $this->pipeline?->remove($pass->id(), true);
		$this->onClose($detachPass);
	}

	/**
	 * Retain arbitrary cleanup in reverse activation order.
	 *
	 * @param callable $cleanup Idempotent resource release callback.
	 * @return void Stores the callback until close().
	 */
	public function onClose(callable $cleanup): void
	{
		$this->requireOpen();
		$this->cleanup[] = $cleanup;
	}

	/**
	 * Capture retained state and release all scope-owned registrations.
	 *
	 * @return array<string,mixed> Validated state for later reactivation.
	 */
	public function close(): array
	{
		if($this->closed) return $this->state;

		$this->closed = true;
		$this->setActive(false);
		$error = null;

		foreach(array_reverse($this->cleanup) as $cleanup)
		try { $cleanup(); } catch(\Throwable $failure) { $error ??= $failure; }

		$this->entities->close(reverse: true);
		$this->registry->flush();
		$this->cleanup = [];
		$this->assertSerializable($this->state);

		if($error) throw $error;

		return $this->state;
	}

	/**
	 * Ensure retained scope state can be encoded as JSON.
	 *
	 * @param array<string,mixed> $value State to verify.
	 * @return void Rejects non-JSON state.
	 */
	private function assertSerializable(array $value): void
	{
		json_encode($value, JSON_THROW_ON_ERROR);
	}

	/**
	 * Guard scope registrations after close().
	 *
	 * @return void Rejects registrations after closure.
	 */
	private function requireOpen(): void
	{
		if($this->closed) throw new \LogicException('Scope context is closed');
	}
}
