<?php
declare(strict_types=1);
namespace Phonics\World;

use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\Render\FramePipeline;

/** Resolve adapters and declarative bindings into reference-counted active scopes. */
final class WorldRuntime
{
	private array $adapters = [], $systemSets = [], $definitions = [], $sources = [];
	private array $catalogs = [], $bindings = [], $active = [], $state = [];
	private array $services = [];
	private bool $closed = false;

	/**
	 * Create a neutral world runtime around shared engine services.
	 *
	 * @param AdapterContext $adaptersContext Host services used to open sources.
	 * @param Registry|null $registry Shared entity registry.
	 * @param Scheduler|null $scheduler Shared system scheduler.
	 * @param FramePipeline|null $pipeline Optional render pipeline.
	 * @return void Initializes empty adapter, source, binding, and scope registries.
	 */
	public function __construct(
		private AdapterContext $adaptersContext
		, ?Registry $registry = null
		, ?Scheduler $scheduler = null
		, public ?FramePipeline $pipeline = null
	){
		$this->registry = $registry ?? new Registry;
		$this->scheduler = $scheduler ?? new Scheduler($this->registry);
	}

	/** Shared entity registry used by every active source. */
	public Registry $registry;
	/** Shared ordered scheduler used by every active source. */
	public Scheduler $scheduler;

	/**
	 * Register a host service for subsequently activated system sets.
	 * @param string $name Stable service name.
	 * @param mixed $value Engine or host service value.
	 * @return void Replaces the service under this session-local name.
	 */
	public function service(string $name, $value): void
	{
		$this->requireOpen();

		if($name === '') throw new \InvalidArgumentException('World service names cannot be empty');

		$this->services[$name] = $value;
	}

	/**
	 * Register a package adapter factory under its resolved export identity.
	 *
	 * @param string $reference Stable export ID or pallet reference.
	 * @param ContentAdapter|callable():ContentAdapter $factory Adapter or lazy adapter factory.
	 * @return void Rejects conflicts rather than silently replacing code.
	 */
	public function adapter(string $reference, $factory): void
	{
		$this->requireOpen();

		if(isset($this->adapters[$reference])) throw new \LogicException("Conflicting adapter $reference");

		if(!$factory instanceof ContentAdapter && !is_callable($factory))
		throw new \InvalidArgumentException("Invalid adapter $reference");

		$this->adapters[$reference] = $factory;
	}

	/**
	 * Register a package system set under its resolved export identity.
	 *
	 * @param string $reference Stable export ID or pallet reference.
	 * @param SystemSet|callable():SystemSet $factory Set or lazy set factory.
	 * @return void Rejects conflicts rather than silently replacing code.
	 */
	public function systemSet(string $reference, $factory): void
	{
		$this->requireOpen();

		if(isset($this->systemSets[$reference])) throw new \LogicException("Conflicting system set $reference");

		if(!$factory instanceof SystemSet && !is_callable($factory))
		throw new \InvalidArgumentException("Invalid system set $reference");

		$this->systemSets[$reference] = $factory;
	}

	/**
	 * Add a declarative source definition.
	 *
	 * @param SourceDefinition $definition Source configuration from the top-level world.
	 * @return void Rejects duplicate source IDs.
	 */
	public function source(SourceDefinition $definition): void
	{
		$this->requireOpen();

		if(isset($this->definitions[$definition->id]))
		throw new \LogicException("Conflicting content source {$definition->id}");

		$this->definitions[$definition->id] = $definition;
	}

	/**
	 * Replace source resource placements from a verified browser-bound world.
	 *
	 * @param array<string,mixed> $world Normalized world containing resolved source resources.
	 * @return void Rejects active sources and adapter substitution before changing any definition.
	 */
	public function bindWorld(array $world): void
	{
		$this->requireOpen();
		$sources = $world['sources'] ?? null;

		if(!is_array($sources)) throw new \InvalidArgumentException('Bound world has no sources');

		$staged = [];

		foreach($sources as $id => $source)
		{
			if(!is_string($id) || !is_array($source) || !is_array($source['resources'] ?? null))
			throw new \InvalidArgumentException('Bound world contains an invalid source');

			$definition = $this->definitions[$id]
				?? throw new \OutOfBoundsException("Bound world names unknown source $id");

			if(($source['adapter'] ?? $definition->adapter) !== $definition->adapter)
			throw new \LogicException("Bound world cannot replace adapter for source $id");

			foreach(array_keys($this->active) as $key)
			if(str_starts_with($key, $id . ':'))
			throw new \LogicException("Cannot bind resources while source $id has active scopes");

			$staged[$id] = $definition->withResources($source['resources']);
		}

		foreach($staged as $id => $definition)
		{
			if(isset($this->sources[$id])) $this->sources[$id]->close();

			unset($this->sources[$id], $this->catalogs[$id]);
			$this->definitions[$id] = $definition;

			foreach(array_keys($this->state) as $key)
			if(str_starts_with($key, $id . ':')) unset($this->state[$key]);
		}
	}

	/**
	 * Add one declarative system-set binding.
	 *
	 * @param string $id Stable binding ID.
	 * @param string $use Registered system-set reference.
	 * @param array<string,mixed> $on Scope selector supporting source, kind, id, and tags.
	 * @param array<string,mixed> $options Options passed to the set.
	 * @param list<string> $after Binding IDs that must activate first.
	 * @return void Adds the binding after rejecting conflicts.
	 */
	public function bind(
		string $id
		, string $use
		, array $on = []
		, array $options = []
		, array $after = []
	): void {
		$this->requireOpen();

		if(isset($this->bindings[$id])) throw new \LogicException("Conflicting binding $id");

		$this->bindings[$id] = compact('id', 'use', 'on', 'options', 'after');
	}

	/**
	 * Enumerate one source's stable scope descriptors.
	 *
	 * @param string $sourceId Registered source ID.
	 * @return list<ScopeDescriptor> Cached descriptor list.
	 */
	public function catalog(string $sourceId): array
	{
		$this->open($sourceId);

		return $this->catalogs[$sourceId];
	}

	/**
	 * Retain one source scope, activating its adapter and matching system sets once.
	 *
	 * @param string $sourceId Registered source ID.
	 * @param string $scopeId Stable source-local scope ID.
	 * @return ScopeLease Reference-counted active-scope handle.
	 */
	public function activate(string $sourceId, string $scopeId): ScopeLease
	{
		$this->requireOpen();
		$source = $this->open($sourceId);
		$key = $sourceId . ':' . $scopeId;

		if(!isset($this->active[$key]))
		{
			$descriptor = null;

			foreach($this->catalogs[$sourceId] as $candidate)
			if($candidate->id === $scopeId)
			{
				$descriptor = $candidate;
				break;
			}

			if(!$descriptor) throw new \OutOfBoundsException("Unknown scope $key");

			$context = new ScopeContext(
				$sourceId
				, $descriptor
				, $this->registry
				, $this->scheduler
				, $this->pipeline
				, $this->state[$key] ?? []
				, $this->services
			);

			try
			{
				$active = $source->activate($scopeId, $context);

				if($active->descriptor()->id !== $scopeId)
				throw new \LogicException("Adapter activated the wrong scope for $key");

				$context->setContent($active->content());

				foreach($this->orderedBindings($sourceId, $descriptor) as $binding)
				{
					$set = $this->instantiate($this->systemSets, $binding['use'], SystemSet::class);
					$set->activate($context, $binding['options']);
				}

				$this->active[$key] = compact('active', 'context') + ['references' => 0];
			}
			catch(\Throwable $error)
			{
				try { $context->close(); } catch(\Throwable $cleanup) {}

				if(isset($active)) try { $active->close(); } catch(\Throwable $cleanup) {}

				throw $error;
			}
		}

		$this->active[$key]['references']++;
		$context = $this->active[$key]['context'];

		/**
		 * Release this lease's reference to the active scope.
		 *
		 * @return void Deactivates the scope when no other lease remains.
		 */
		$releaseLease = fn() => $this->release($key);

		return new ScopeLease($sourceId, $scopeId, $context, $releaseLease);
	}

	/**
	 * Close active scopes and opened sources in reverse order.
	 *
	 * @return void Releases every resource once and rethrows the first cleanup failure.
	 */
	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$error = null;

		foreach(array_reverse(array_keys($this->active)) as $key)
		try { $this->deactivate($key); } catch(\Throwable $failure) { $error ??= $failure; }

		foreach(array_reverse($this->sources) as $source)
		try { $source->close(); } catch(\Throwable $failure) { $error ??= $failure; }

		$this->sources = $this->catalogs = [];

		if($error) throw $error;
	}

	/**
	 * Open a registered source once and cache its validated scope catalog.
	 *
	 * @param string $sourceId Source to open.
	 * @return ContentSource Parsed source, reused on later calls.
	 */
	private function open(string $sourceId): ContentSource
	{
		$this->requireOpen();

		if(isset($this->sources[$sourceId])) return $this->sources[$sourceId];

		$definition = $this->definitions[$sourceId]
			?? throw new \OutOfBoundsException("Unknown content source $sourceId");

		if($definition->unresolved())
		throw new \LogicException("Content source $sourceId has unresolved resource slots");

		$adapter = $this->instantiate($this->adapters, $definition->adapter, ContentAdapter::class);
		$source = $adapter->open($definition, $this->adaptersContext);
		$catalog = [];

		foreach($source->catalog() as $descriptor)
		{
			if(!$descriptor instanceof ScopeDescriptor)
			throw new \LogicException("Adapter {$definition->adapter} returned an invalid scope descriptor");

			if(isset($catalog[$descriptor->id]))
			throw new \LogicException("Duplicate scope {$descriptor->id} in source $sourceId");

			$catalog[$descriptor->id] = $descriptor;
		}

		$this->sources[$sourceId] = $source;
		$this->catalogs[$sourceId] = array_values($catalog);

		return $source;
	}

	/**
	 * Resolve a registered adapter or system set, calling a lazy factory when needed.
	 *
	 * @param array<string,mixed> $items Registry to read.
	 * @param string $key Entry key.
	 * @param string $type Required interface.
	 * @return object Instantiated service implementing the required interface.
	 */
	private function instantiate(array $items, string $key, string $type): object
	{
		$item = $items[$key] ?? throw new \OutOfBoundsException("Missing registered export $key");
		$value = is_callable($item) && !$item instanceof $type ? $item() : $item;

		if(!$value instanceof $type) throw new \LogicException("Export $key must implement $type");

		return $value;
	}

	/**
	 * Select the bindings whose selector matches a scope and order them by their after dependencies.
	 *
	 * @param string $sourceId Source ID.
	 * @param ScopeDescriptor $scope Candidate scope.
	 * @return list<array<string,mixed>> Ordered matching bindings; cycles and missing dependencies throw.
	 */
	private function orderedBindings(string $sourceId, ScopeDescriptor $scope): array
	{

		/**
		 * Match a binding selector's source, kind, ID and tags against the scope.
		 *
		 * @param array<string,mixed> $binding Declarative binding with its on selector.
		 * @return bool True when every declared selector field matches.
		 */
		$selectsScope = static function(array $binding) use ($sourceId, $scope): bool {
			$on = $binding['on'];

			if(isset($on['source']) && $on['source'] !== $sourceId) return false;

			if(isset($on['kind']) && $on['kind'] !== $scope->kind) return false;

			if(isset($on['id']) && $on['id'] !== $scope->id) return false;

			foreach((array) ($on['tags'] ?? []) as $tag)
			if(!in_array($tag, $scope->tags, true)) return false;

			return true;
		};

		$matching = array_filter($this->bindings, $selectsScope);

		$result = [];
		$active = [];
		$done = [];

		/**
		 * Append a binding after its dependencies, rejecting cycles and missing matches.
		 *
		 * @param string $id Binding ID to visit.
		 * @return void Updates the ordered result and traversal state.
		 */
		$visit = function(string $id) use (&$visit, &$result, &$active, &$done, $matching): void {
			if(isset($done[$id])) return;

			if(isset($active[$id])) throw new \LogicException("Binding dependency cycle at $id");

			if(!isset($matching[$id])) throw new \LogicException("Missing matching binding dependency $id");

			$active[$id] = true;

			foreach($matching[$id]['after'] as $dependency) $visit($dependency);

			unset($active[$id]);
			$done[$id] = true;
			$result[] = $matching[$id];
		};

		foreach(array_keys($matching) as $id) $visit($id);

		return $result;
	}

	/**
	 * Drop one lease reference to an active scope.
	 *
	 * @param string $key Active scope key.
	 * @return void Decrements and deactivates the last reference.
	 */
	private function release(string $key): void
	{
		if(!isset($this->active[$key]) || --$this->active[$key]['references'] > 0) return;

		$this->deactivate($key);
	}

	/**
	 * Snapshot an active scope's state, then close the adapter scope and its context.
	 *
	 * @param string $key Active scope key.
	 * @return void Snapshots and releases the scope, retaining its state and rethrowing the first failure.
	 */
	private function deactivate(string $key): void
	{
		if(!isset($this->active[$key])) return;

		$record = $this->active[$key];
		unset($this->active[$key]);
		$error = null;

		try
		{
			$state = $record['active']->snapshot();
			$record['context']->replaceState($state + $record['context']->state());
		}
		catch(\Throwable $failure)
		{
			$error = $failure;
		}

		try { $record['active']->close(); } catch(\Throwable $failure) { $error ??= $failure; }

		try { $this->state[$key] = $record['context']->close(); } catch(\Throwable $failure) { $error ??= $failure; }

		if($error) throw $error;
	}

	/**
	 * Guard runtime operations after close().
	 *
	 * @return void Rejects mutations after closure.
	 */
	private function requireOpen(): void
	{
		if($this->closed) throw new \LogicException('World runtime is closed');
	}
}
