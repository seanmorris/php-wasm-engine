<?php
declare(strict_types=1);
namespace Phonics\World;

use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\Render\FramePipeline;
use Phonics\Render\RenderPass;

/** Own systems, entities, render passes, state, and cleanup for one active source scope. */
final class ScopeContext
{
	private array $cleanup = [], $entities = [];
	private bool $closed = false;
	private $content = null;

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
		public string $sourceId
		, public ScopeDescriptor $descriptor
		, public Registry $registry
		, public Scheduler $scheduler
		, public ?FramePipeline $pipeline = null
		, private array $state = []
		, private array $services = []
	) {
		$this->assertSerializable($state);
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
		$qualify = static fn(string $dependency): string => str_contains($dependency, ':')
			? $dependency
			: $prefix . $dependency;

		$dependencies = array_map($qualify, $after);

		if($this->scheduler->has($id)) throw new \LogicException("Conflicting scoped system $id");

		$this->scheduler->add($id, $system, $dependencies);

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
		if(!$this->registry->alive($id)) throw new \OutOfBoundsException("Cannot own missing entity $id");
		if(!in_array($id, $this->entities, true)) $this->entities[] = $id;

		return $id;
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

		$this->pipeline->add($pass);

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
		$error = null;

		foreach(array_reverse($this->cleanup) as $cleanup)
		try { $cleanup(); } catch(\Throwable $failure) { $error ??= $failure; }

		foreach(array_reverse($this->entities) as $id)
		if($this->registry->alive($id)) $this->registry->destroy($id);

		$this->registry->flush();
		$this->cleanup = $this->entities = [];
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
