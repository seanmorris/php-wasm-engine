<?php
declare(strict_types=1);
namespace Phonics\World;

use Phonics\Ecs\Registry;
use Phonics\Ecs\Scheduler;
use Phonics\Render\FramePipeline;

/**
 * Resolve adapters and declarative bindings into reference-counted active scopes.
 *
 * It opens sources lazily, matches descriptors to system-set bindings, reference-counts active scopes,
 * restores scope state, and tears ownership down deterministically.
 */
final class WorldRuntime
{
	/** Registered adapters, system sets, source definitions, and opened sources by stable name. */
	private array $adapters = [], $systemSets = [], $definitions = [], $sources = [];
	/** Scope catalogs, system bindings, active scopes, and saved scope state. */
	private array $catalogs = [], $bindings = [], $active = [], $state = [];
	/** Shared runtime services exposed to content adapters. */
	private array $services = [];
	/** Registered lifecycle factories and unique scope generations for stale-lease protection. */
	private array $policies = [];
	private int $generation = 0;
	/** Whether this instance has released its owned resources. */
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
		/** Host services used to open sources. */
		private AdapterContext $adaptersContext
		, ?Registry $registry = null
		, ?Scheduler $scheduler = null
		, /** Optional render pipeline. */
		public ?FramePipeline $pipeline = null
	){
		$this->registry = $registry ?? new Registry;
		$this->scheduler = $scheduler ?? new Scheduler($this->registry);
	}

	/** Shared entity registry used by every active source. */
	public Registry $registry;
	/** Shared ordered scheduler used by every active source. */
	public Scheduler $scheduler;

	/**
	 * Create an independent content session using the same already loaded code and engine services.
	 *
	 * @return self Independent content session sharing the already loaded code and engine services.
	 */
	public function fork(): self
	{
		$this->requireOpen();
		$runtime = new self($this->adaptersContext, $this->registry, $this->scheduler, $this->pipeline);
		$runtime->adapters = $this->adapters;
		$runtime->systemSets = $this->systemSets;
		$runtime->policies = $this->policies;
		$runtime->services = $this->services;

		return $runtime;
	}

	/**
	 * Register an application lifecycle under a stable export identity.
	 *
	 * @param string $reference Verified package export reference.
	 * @param ScopeLifecycle|callable $factory Already loaded constructor or policy factory.
	 * @return void Registers the application-defined lifecycle policy.
	 */
	public function lifecycle(string $reference, ScopeLifecycle|callable $factory): void
	{
		$this->requireOpen();

		if(isset($this->policies[$reference]) || in_array($reference, ['retain', 'snapshot', 'reset'], true))
		throw new \LogicException("Conflicting lifecycle $reference");

		$this->policies[$reference] = $factory;
	}

	/**
	 * Open an inspectable asset without preparing or activating gameplay.
	 *
	 * @param string $sourceId Registered content source identity.
	 * @return ContentSource Prepares content without enabling simulation.
	 */
	public function load(string $sourceId): ContentSource { return $this->open($sourceId); }

	/**
	 * Commit scope transitions outside a running system iteration.
	 *
	 * @param callable $change Transition to run after the current system iteration.
	 * @return void Commits the change outside the current system iteration.
	 */
	public function transition(callable $change): void { $this->scheduler->defer($change); }

	/**
	 * Retrieve a prepared scope; an inactive context remains inspectable.
	 *
	 * @param string $sourceId Registered content source identity.
	 * @param string $scopeId Stable scope identity within the source.
	 * @return ?ScopeContext Prepared context, or null when it has not been prepared.
	 */
	public function context(string $sourceId, string $scopeId): ?ScopeContext
	{
		return $this->active[$sourceId . ':' . $scopeId]['context'] ?? null;
	}

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
	 * @return ScopeContext Prepared inactive ownership context, or the existing context.
	 */
	public function prepare(string $sourceId, string $scopeId): ScopeContext
	{
		$this->requireOpen();

		if($this->scheduler->updating())
		throw new \LogicException('Prepare scopes at a scheduler boundary using transition()');

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
				, ['worldRuntime' => $this] + $this->services
			);

			$context->setActive(false);

			$context->descendants(/** Find prepared descendant ownership contexts.
 * @return array Prepared descendants in ancestry order.
 */
				fn() => $this->descendants($sourceId, $scopeId)
			);

			try
			{
				$active = $source->activate($scopeId, $context);

				if($active->descriptor()->id !== $scopeId)
				throw new \LogicException("Adapter activated the wrong scope for $key");

				$context->setContent($active->content());
				$policyName = $descriptor->metadata['lifecycle']
					?? $this->definitions[$sourceId]->options['lifecycle'] ?? 'snapshot';

				$policy = in_array($policyName, ['retain', 'snapshot', 'reset'], true)
					? new Lifecycle($policyName)
					: $this->instantiate($this->policies, $policyName, ScopeLifecycle::class);

				foreach($this->orderedBindings($sourceId, $descriptor) as $binding)
				{
					$set = $this->instantiate($this->systemSets, $binding['use'], SystemSet::class);
					$set->activate($context, $binding['options']);
				}

				$policy->load($context);
				$this->scheduler->validate();
				$this->active[$key] = compact('active', 'context', 'policy')
					+ ['references' => 0, 'generation' => ++$this->generation];
			}
			catch(\Throwable $error)
			{
				if(isset($policy)) try { $policy->unload($context); } catch(\Throwable $cleanup) {}

				try { $context->close(); } catch(\Throwable $cleanup) {}

				if(isset($active)) try { $active->close(); } catch(\Throwable $cleanup) {}

				throw $error;
			}
		}

		return $this->active[$key]['context'];
	}

	/**
	 * Activate a prepared scope, or prepare it first, retaining independent leases.
	 *
	 * @param string $sourceId Registered content source identity.
	 * @param string $scopeId Stable scope identity within the source.
	 * @return ScopeLease Reference-counted handle or content scope for this activation.
	 */
	public function activate(string $sourceId, string $scopeId): ScopeLease
	{
		$context = $this->prepare($sourceId, $scopeId);
		$key = $sourceId . ':' . $scopeId;

		if(($context->descriptor->metadata['requireParent'] ?? false)
			&& $context->descriptor->parent !== null
			&& !$this->context($sourceId, $context->descriptor->parent)?->active()
		)
		throw new \LogicException("Activate parent {$context->descriptor->parent} before $scopeId");

		if(!$context->active())
		{
			try
			{
				$this->active[$key]['policy']->activate($context);
				$this->scheduler->validate();
				$context->setActive(true);
			}
			catch(\Throwable $error)
			{
				try { $this->retire($key, true); } catch(\Throwable $cleanup) {}

				throw $error;
			}
		}

		$this->active[$key]['references']++;
		$generation = $this->active[$key]['generation'];

		/**
		 * Release this lease's reference to the active scope.
		 *
		 * @return void Deactivates the scope when no other lease remains.
		 */
		$releaseLease = fn() => $this->transition(/** Release the matching lease generation at a scheduler boundary.
 * @return void Completes the scope operation.
 */
			fn() => $this->release($key, $generation)
		);

		return new ScopeLease($sourceId, $scopeId, $context, $releaseLease);
	}

	/**
	 * Deactivate children before their parent, applying each scope's selected policy.
	 *
	 * @param string $sourceId Registered content source identity.
	 * @param string $scopeId Stable scope identity within the source.
	 * @return void Selected state disposition or completed deactivation.
	 */
	public function deactivate(string $sourceId, string $scopeId): void
	{
		$this->transition(/** Deactivate the selected hierarchy after the current simulation step.
 * @return void Completes the scope operation.
 */
			function() use ($sourceId, $scopeId): void {
				$keys = array_map(
					/** Read a prepared scope ownership key.
 * @param ScopeContext $scope Prepared descendant.
 * @return string Fully qualified scope key.
 */
					fn($scope) => $scope->key()
					, array_reverse($this->descendants($sourceId, $scopeId))
				);

				$keys[] = $sourceId . ':' . $scopeId;
				$error = null;

				foreach($keys as $key)
				try { $this->retire($key); } catch(\Throwable $failure) { $error ??= $failure; }

				if($error) throw $error;
			}
		);
	}

	/**
	 * Evict a scope and its children, optionally forgetting captured state for a restart.
	 *
	 * @param string $sourceId Registered content source identity.
	 * @param string $scopeId Stable scope identity within the source.
	 * @param bool $reset Forget captured state for this scope and its descendants.
	 * @return void Evicts this scope and its descendants.
	 */
	public function unloadScope(string $sourceId, string $scopeId, bool $reset = false): void
	{
		$this->transition(/** Evict the selected hierarchy after the current simulation step.
 * @return void Completes the scope operation.
 */
			function() use ($sourceId, $scopeId, $reset): void {
				$keys = array_map(
					/** Read a prepared scope ownership key.
 * @param ScopeContext $scope Prepared descendant.
 * @return string Fully qualified scope key.
 */
					fn($scope) => $scope->key()
					, array_reverse($this->descendants($sourceId, $scopeId))
				);

				$keys[] = $sourceId . ':' . $scopeId;
				$error = null;

				foreach($keys as $key)
				{
					try { $this->retire($key, true); } catch(\Throwable $failure) { $error ??= $failure; }

					if($reset) unset($this->state[$key]);
				}

				if($error) throw $error;
			}
		);
	}

	/**
	 * Unload one asset and all its instances without closing the session or other sources.
	 *
	 * @param string $sourceId Registered content source identity.
	 * @return void Releases prepared instances and source resources.
	 */
	public function unload(string $sourceId): void
	{
		$this->transition(/** Unload every instance of the selected source.
 * @return void Completes the scope operation.
 */
			function() use ($sourceId): void {
				$error = null;

				foreach($this->retirementOrder() as $key)
				if($this->active[$key]['context']->sourceId === $sourceId)
				try { $this->retire($key, true); } catch(\Throwable $failure) { $error ??= $failure; }

				try
				{
					($this->sources[$sourceId] ?? null)?->close();
				} catch(\Throwable $failure)
				{
					$error ??= $failure;
				}

				unset($this->sources[$sourceId], $this->catalogs[$sourceId]);

				foreach(array_keys($this->state) as $key)
				if(str_starts_with($key, $sourceId . ':')) unset($this->state[$key]);

				if($error) throw $error;
			}
		);
	}

	/**
	 * Loaded descendants in parent-before-child order, including retained inactive scopes.
	 *
	 * @param string $sourceId Registered content source identity.
	 * @param string $scopeId Stable scope identity within the source.
	 * @return array Prepared descendants in parent-before-child order.
	 */
	private function descendants(string $sourceId, string $scopeId): array
	{
		$result = [];

		foreach($this->active as $record)
		{
			$scope = $record['context'];

			if($scope->sourceId !== $sourceId || $scope->descriptor->parent !== $scopeId) continue;

			$result[] = $scope;
			array_push($result, ...$this->descendants($sourceId, $scope->descriptor->id));
		}

		return $result;
	}

	/**
	 * Order prepared instances by ancestry even when a child was prepared first.
	 * @return list<string> Scope keys with every child preceding its parent.
	 */
	private function retirementOrder(): array
	{
		$depths = [];

		foreach($this->active as $key => $record)
		{
			$scope = $record['context'];
			$catalog = [];

			foreach($this->catalogs[$scope->sourceId] as $descriptor) $catalog[$descriptor->id] = $descriptor;
			$parent = $scope->descriptor->parent;
			$depths[$key] = 0;

			while($parent !== null)
			{
				$depths[$key]++;
				$parent = $catalog[$parent]->parent;
			}
		}

		arsort($depths, SORT_NUMERIC);

		return array_keys($depths);
	}

	/**
	 * Close active scopes and opened sources in reverse order.
	 *
	 * @return void Releases every resource once and rethrows the first cleanup failure.
	 */
	public function close(): void
	{
		if($this->closed) return;

		if($this->scheduler->updating())
		{
			$this->transition(/** Close this runtime at the simulation boundary.
 * @return void Completes the scope operation.
 */
				fn() => $this->close()
			);

			return;
		}

		$this->closed = true;
		$error = null;

		foreach($this->retirementOrder() as $key)
		try { $this->retire($key, true); } catch(\Throwable $failure) { $error ??= $failure; }

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

		try
		{
			foreach($source->catalog() as $descriptor)
			{
				if(!$descriptor instanceof ScopeDescriptor)
				throw new \LogicException("Adapter {$definition->adapter} returned an invalid scope descriptor");

				if(isset($catalog[$descriptor->id]))
				throw new \LogicException("Duplicate scope {$descriptor->id} in source $sourceId");

				$catalog[$descriptor->id] = $descriptor;
			}

			foreach($catalog as $descriptor)
			{
				$seen = [$descriptor->id => true];
				$parent = $descriptor->parent;

				while($parent !== null)
				{
					if(isset($seen[$parent])) throw new \LogicException("Scope parent cycle at $parent");

					if(!isset($catalog[$parent])) throw new \LogicException("Unknown scope parent $parent");

					$seen[$parent] = true;
					$parent = $catalog[$parent]->parent;
				}
			}
		}
		catch(\Throwable $error)
		{
			try { $source->close(); } catch(\Throwable $cleanup) {}

			throw $error;
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

		foreach($scope->metadata['systems'] ?? [] as $binding)
		{
			if(!is_array($binding) || !is_string($binding['id'] ?? null) || !is_string($binding['use'] ?? null))
			throw new \InvalidArgumentException("Invalid authored system binding in {$scope->id}");

			if(isset($matching[$binding['id']])) throw new \LogicException("Conflicting binding {$binding['id']}");

			$matching[$binding['id']] = $binding + ['options' => [], 'after' => []];
		}

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
	 * @param int $generation Activation generation captured by the releasing lease.
	 * @return void Decrements and deactivates the last reference.
	 */
	private function release(string $key, int $generation): void
	{
		if(!isset($this->active[$key]) || $this->active[$key]['generation'] !== $generation
			|| --$this->active[$key]['references'] > 0
		) return;

		$scope = $this->active[$key]['context'];
		$this->deactivate($scope->sourceId, $scope->descriptor->id);
	}

	/**
	 * Snapshot an active scope's state, then close the adapter scope and its context.
	 *
	 * @param string $key Active scope key.
	 * @param bool $unload Release retained instances as well as active instances.
	 * @return void Snapshots and releases the scope, retaining its state and rethrowing the first failure.
	 */
	private function retire(string $key, bool $unload = false): void
	{
		if(!isset($this->active[$key])) return;

		$record = $this->active[$key];
		$error = null;
		$behavior = 'reset';
		$wasActive = $record['context']->active();
		$record['context']->setActive(false);
		$this->active[$key]['references'] = 0;
		$this->active[$key]['generation'] = ++$this->generation;

		try
		{
			$behavior = $wasActive
				? $record['policy']->deactivate($record['context'])
				: ($record['behavior'] ?? 'reset');

			if(!in_array($behavior, ['retain', 'snapshot', 'reset'], true))
			throw new \LogicException("Invalid lifecycle disposition $behavior");

			$this->active[$key]['behavior'] = $behavior;

			if($behavior === 'retain' && !$unload) return;

			if($behavior === 'snapshot')
			{
				$state = $record['active']->snapshot();
				$record['context']->replaceState($state + $record['context']->snapshot());
			}
		}
		catch(\Throwable $failure)
		{
			$error = $failure;
		}

		unset($this->active[$key]);

		try { $record['policy']->unload($record['context']); } catch(\Throwable $failure) { $error ??= $failure; }

		try { $this->state[$key] = $record['context']->close(); } catch(\Throwable $failure) { $error ??= $failure; }

		try { $record['active']->close(); } catch(\Throwable $failure) { $error ??= $failure; }

		if($behavior !== 'snapshot') unset($this->state[$key]);

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
