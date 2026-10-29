<?php
declare(strict_types=1);
namespace Phonics\Ecs;

/** Object components, stable entity IDs, type indexes, snapshot queries. */
final class Registry
{
	private static array $canonicalNames = [];
	private int $next = 1;
	private array $entities = [], $index = [], $commands = [], $listeners = [];
	private array $typeCache = [], $queryCache = [];
	private bool $observesAdded = false, $observesRemoved = false;
	private int $cachedIds = 0;
	private const QUERY_CACHE_ENTRIES = 64;
	private const QUERY_CACHE_IDS = 131072;

	/**
	 * Normalize aliases before using a component name as an index key.
	 * @param string $type Class or interface name, including a legacy alias.
	 * @return string Canonical declared name, or the unresolved name.
	 */
	public static function canonical(string $type): string
	{
		if(isset(self::$canonicalNames[$type])) return self::$canonicalNames[$type];

		// Loaded types and aliases cannot change their declared name. Do not cache
		// misses: a later package or autoloader may still define that component.
		if(!class_exists($type) && !interface_exists($type)) return $type;

		return self::$canonicalNames[$type] = (new \ReflectionClass($type))->getName();
	}

	/**
	 * Allocate a stable entity ID and attach its initial components.
	 *
	 * @param object ...$components Initial component objects, indexed by class, parents, and
	 * interfaces.
	 * @return int New live entity ID.
	 */
	public function create(object ...$components): int
	{
		$id = $this->next++;

		if(!$this->observesAdded && !$this->observesRemoved)
		{
			$entity = [];
			$duplicate = false;

			foreach($components as $component)
			{
				$type = get_class($component);
				$duplicate = $duplicate || isset($entity[$type]);
				$entity[$type] = $component;
			}

			if($duplicate)
			{
				// Replacing an exact parent can also remove subclasses attached earlier
				// in this call. Preserve the ordered attachment path for repeated types.
				$this->entities[$id] = [];

				foreach($components as $component)
				{
					$this->addWithoutListeners($id, $component);
				}
			}
			else
			{
				// No callbacks can observe construction: install the component table once.
				$this->entities[$id] = $entity;

				foreach($entity as $type => $component)
				{
					$types = $this->typeCache[$type] ?? $this->types($type);

					foreach($types as $indexed)
					{
						$this->index[$indexed][$id] = ($this->index[$indexed][$id] ?? 0) + 1;
					}
				}
			}

			$this->invalidateQueries();

			return $id;
		}

		$this->entities[$id] = [];
		$this->invalidateQueries();

		foreach($components as $component)
		{
			$this->add($id, $component);
		}

		return $id;
	}

	/**
	 * Check whether an entity ID still belongs to this registry.
	 *
	 * @param int $id Entity ID to inspect.
	 * @return bool True if the entity has not been destroyed.
	 */
	public function alive(int $id): bool { return isset($this->entities[$id]); }

	/**
	 * Attach a component, replacing an existing component of the same concrete class.
	 *
	 * @param int $id Live entity receiving the component.
	 * @param object $component Component instance to store without copying.
	 * @return void Updates type indexes and emits removal/addition events as needed.
	 */
	public function add(int $id, object $component): void
	{
		if(!isset($this->entities[$id]))
		{
			throw new \OutOfBoundsException("Entity $id is not alive");
		}

		$type = get_class($component);

		if(!$this->observesAdded && !$this->observesRemoved)
		{
			if(isset($this->entities[$id][$type]))
			{
				// Replacing the sole matching concrete component changes no memberships.
				if(($this->index[$type][$id] ?? 0) === 1)
				{
					$this->entities[$id][$type] = $component;
					$this->invalidateQueries();

					return;
				}

				$this->removeWithoutListeners($id, $type);
			}

			$this->entities[$id][$type] = $component;
			$this->indexComponent($id, $type);
			$this->invalidateQueries();

			return;
		}

		if(isset($this->entities[$id][$type]))
		{
			$this->remove($id, $type);
		}

		// A removal listener may already have attached another instance of this concrete class.
		$indexed = isset($this->entities[$id][$type]);
		$this->entities[$id][$type] = $component;

		if(!$indexed)
		{
			$this->indexComponent($id, $type);
		}

		$this->invalidateQueries();
		$this->emit('added', $id, $component);
	}

	/**
	 * Remove every component matching a class or interface from a live entity.
	 *
	 * @param int $id Live entity whose components are inspected.
	 * @param class-string $type Concrete class, parent class, or interface to remove.
	 * @return void Updates type indexes and emits a removed event for each detached component.
	 */
	public function remove(int $id, string $type): void
	{
		if(!isset($this->entities[$id]))
		{
			throw new \OutOfBoundsException("Entity $id is not alive");
		}

		if(!$this->observesRemoved
			&& ($this->index[$type][$id] ?? 0) === 1
			&& isset($this->entities[$id][$type])
		){
			$this->detachComponent($id, $type);
			$this->invalidateQueries();

			return;
		}

		if(!isset($this->index[$type]))
		{
			$type = self::canonical($type);
		}

		if(!$this->observesRemoved)
		{
			if($this->removeWithoutListeners($id, $type))
			{
				$this->invalidateQueries();
			}

			return;
		}

		// The common concrete removal has one matching component and needs no entity scan.
		if(($this->index[$type][$id] ?? 0) === 1 && isset($this->entities[$id][$type]))
		{
			$component = $this->entities[$id][$type];
			$this->detachComponent($id, $type);
			$this->invalidateQueries();
			$this->emit('removed', $id, $component);

			return;
		}

		foreach($this->entities[$id] as $class => $component)
		{
			if($component instanceof $type)
			{
				// Earlier callbacks can remove a later member of this foreach snapshot.
				if(isset($this->entities[$id][$class]))
				{
					$this->detachComponent($id, $class);
				}

				$this->invalidateQueries();
				$this->emit('removed', $id, $component);
			}
		}
	}

	/**
	 * Remove all components and retire an entity ID.
	 *
	 * @param int $id Live entity to destroy.
	 * @return void Emits component removal events and marks the entity dead.
	 */
	public function destroy(int $id): void
	{
		if(!isset($this->entities[$id]))
		{
			throw new \OutOfBoundsException("Entity $id is not alive");
		}

		if(!$this->observesRemoved)
		{
			// Retain components until all memberships and snapshots are retired, so
			// their destructors cannot observe a partially destroyed entity.
			$components = $this->entities[$id];
			unset($this->entities[$id]);

			foreach($components as $type => $component)
			{
				$types = $this->typeCache[$type];

				foreach($types as $indexed)
				{
					// All components are leaving, including every shared ancestor.
					unset($this->index[$indexed][$id]);
				}
			}

			$this->invalidateQueries();

			return;
		}

		foreach(array_keys($this->entities[$id]) as $type)
		{
			$this->remove($id, $type);
		}

		unset($this->entities[$id]);
		$this->invalidateQueries();
	}

	/**
	 * Find a component by concrete type, parent class, or interface.
	 *
	 * @param int $id Live entity to inspect.
	 * @param class-string $type Requested component class or interface.
	 * @return object Stored component instance; missing entities or components raise
	 * OutOfBoundsException.
	 */
	public function get(int $id, string $type): object
	{
		// Retain the entity's component array locally so exact hits, liveness checks,
		// alias fallback, and hierarchy scans share one outer-table lookup.
		$components = $this->entities[$id] ?? null;

		if($components === null)
		{
			throw new \OutOfBoundsException("Entity $id is not alive");
		}

		$component = $components[$type] ?? null;

		if($component !== null)
		{
			return $component;
		}

		// Parent classes and interfaces also have canonical index entries. Normalize
		// only aliases and case variants that cannot be found under their given name.
		if(!isset($this->index[$type]))
		{
			$type = self::canonical($type);

			$component = $components[$type] ?? null;

			if($component !== null)
			{
				return $component;
			}
		}

		foreach($components as $component)
		{
			if($component instanceof $type)
			{
				return $component;
			}
		}

		throw new \OutOfBoundsException("Entity $id has no $type");
	}

	/**
	 * Check indexed component membership without requiring a live entity.
	 *
	 * @param int $id Entity ID to inspect.
	 * @param class-string $type Component class, parent class, or interface.
	 * @return bool True when at least one attached component matches the type.
	 */
	public function has(int $id, string $type): bool
	{
		$members = $this->index[$type] ?? null;

		if($members !== null)
		{
			return isset($members[$id]);
		}

		return isset($this->index[self::canonical($type)][$id]);
	}

	/**
	 * Take a snapshot of live entity IDs matching a type or boolean query.
	 *
	 * @param Query|class-string $query Component type or composed all/any/not predicate.
	 * @return list<int> Matching entity IDs, safe to iterate while scheduling deferred changes.
	 */
	public function query($query): array
	{
		if(is_string($query))
		{
			$type = $query;
			$members = $this->index[$type] ?? null;

			if($members === null)
			{
				$type = self::canonical($type);
				$members = $this->index[$type] ?? [];
			}

			$key = 'type:' . $type;

			if(isset($this->queryCache[$key]))
			{
				return $this->queryCache[$key];
			}

			return $this->cacheSnapshot($key, array_keys($members));
		}

		if(!$query instanceof Query)
		{
			throw new \InvalidArgumentException('Expected component type or Query');
		}

		// Value keys also cover freshly constructed descriptions and edits to nested public terms.
		$key = 'query:' . serialize($query);

		if(isset($this->queryCache[$key]))
		{
			return $this->queryCache[$key];
		}

		$candidates = $this->entities;

		if($query->operation === 'all')
		{
			foreach($query->terms as $term)
			{
				if(is_string($term))
				{
					$ids = $this->index[$term]
						?? $this->index[self::canonical($term)]
						?? [];

					if(count($ids) < count($candidates))
					{
						$candidates = $ids;
					}
				}
			}
		}

		// Intersect in the original candidate order, even when a nested set is more selective.
		$ids = $candidates ? array_keys(array_intersect_key($candidates, $this->matchingSet($query))) : [];

		return $this->cacheSnapshot($key, $ids);
	}

	/**
	 * Retain a query snapshot within the shared entry and entity-ID budgets.
	 *
	 * @param string $key Canonical type or serialized boolean-query cache key.
	 * @param list<int> $ids Ordered entity snapshot to retain when it fits.
	 * @return list<int> The supplied snapshot, whether cached or returned directly.
	 */
	private function cacheSnapshot(string $key, array $ids): array
	{
		$count = count($ids);

		if($count > self::QUERY_CACHE_IDS)
		{
			return $ids;
		}

		if(count($this->queryCache) >= self::QUERY_CACHE_ENTRIES
			|| $this->cachedIds + $count > self::QUERY_CACHE_IDS
		){
			$this->invalidateQueries();
		}

		$this->cachedIds += $count;
		$this->queryCache[$key] = $ids;

		return $ids;
	}

	/**
	 * Evaluate membership using indexed sets, starting with the smallest intersection.
	 *
	 * @param Query|class-string $query Component type or nested boolean expression.
	 * @return array<int,mixed> Entity-keyed set; values are not retained in snapshots.
	 */
	private function matchingSet($query): array
	{
		if(is_string($query))
		{
			return $this->index[$query]
				?? $this->index[self::canonical($query)]
				?? [];
		}

		if(!in_array($query->operation, ['all', 'any', 'not'], true))
		{
			// Public operation fields historically allow other values; keep matches() semantics.
			$matches = [];

			foreach($this->entities as $id => $components)
			{
				if($query->matches($this, $id))
				{
					$matches[$id] = true;
				}
			}

			return $matches;
		}

		$sets = [];
		$exclusions = [];
		$smallest = null;

		foreach($query->terms as $term)
		{
			if($query->operation === 'all' && $term instanceof Query && $term->operation === 'not')
			{
				// A AND NOT(B, C) subtracts both sets without building a world-sized complement.
				foreach($term->terms as $excluded)
				{
					$exclusions[] = $this->matchingSet($excluded);
				}

				continue;
			}

			$set = $this->matchingSet($term);

			if($smallest === null || count($set) < count($sets[$smallest]))
			{
				$smallest = count($sets);
			}

			$sets[] = $set;
		}

		$result = $query->operation === 'any' ? [] : $this->entities;

		if($query->operation === 'all' && $smallest !== null)
		{
			$result = $sets[$smallest];
			unset($sets[$smallest]);
		}

		foreach($sets as $set)
		{
			if($query->operation === 'any')
			{
				$result += $set;
			}
			elseif($query->operation === 'not')
			{
				$result = array_diff_key($result, $set);
			}
			else
			{
				$result = array_intersect_key($result, $set);
			}
		}

		foreach($exclusions as $set)
		{
			$result = array_diff_key($result, $set);
		}

		return $result;
	}

	/**
	 * Retire query snapshots before lifecycle listeners can observe a structural change.
	 *
	 * @return void Drops cached arrays while existing caller snapshots retain their own values.
	 */
	private function invalidateQueries(): void
	{
		if(!$this->queryCache)
		{
			return;
		}

		$this->queryCache = [];
		$this->cachedIds = 0;
	}

	/**
	 * Queue a structural change for the next command flush.
	 *
	 * @param callable(Registry):void $command Callback receiving this registry when commands are
	 * flushed.
	 * @return void Appends the command without executing it immediately.
	 */
	public function defer(callable $command): void { $this->commands[] = $command; }

	/**
	 * Execute deferred commands, including newly queued commands, for at most 32 rounds.
	 *
	 * @return void Empties the queue or throws when commands recurse beyond the round limit.
	 */
	public function flush(): void
	{
		for($round = 0; $this->commands; $round++)
		{
			if($round >= 32)
			{
				throw new \RuntimeException('Recursive ECS command limit');
			}

			$commands = $this->commands;
			$this->commands = [];

			foreach($commands as $command)
			{
				$command($this);
			}
		}
	}

	/**
	 * Subscribe to component lifecycle events.
	 *
	 * @param string $event Event name, normally added or removed.
	 * @param callable(Registry,int,object):void $listener Receives this registry, the entity ID,
	 * and the affected component.
	 * @return void Retains the listener for subsequent matching events.
	 */
	public function on(string $event, callable $listener): void
	{
		$this->listeners[$event][] = $listener;

		if($event === 'added')
		{
			$this->observesAdded = true;
		}
		elseif($event === 'removed')
		{
			$this->observesRemoved = true;
		}
	}

	/**
	 * Unsubscribe a scene's callback without disturbing other lifecycle listeners.
	 *
	 * @param string $event Event name used when subscribing.
	 * @param callable(Registry,int,object):void $listener Exact callback previously registered.
	 * @return void Removes all matching registrations; an unknown callback has no effect.
	 */
	public function off(string $event, callable $listener): void
	{
		foreach($this->listeners[$event] ?? [] as $key => $registered)
		{
			if($registered === $listener)
			{
				unset($this->listeners[$event][$key]);
			}
		}

		if(isset($this->listeners[$event]) && !$this->listeners[$event])
		{
			unset($this->listeners[$event]);

			if($event === 'added')
			{
				$this->observesAdded = false;
			}
			elseif($event === 'removed')
			{
				$this->observesRemoved = false;
			}
		}
	}

	/**
	 * Notify all listeners registered for a component lifecycle event.
	 *
	 * @param string $event Lifecycle event name.
	 * @param int $id Entity associated with the change.
	 * @param object $component Component that was attached or detached.
	 * @return void Invokes listeners in registration order.
	 */
	private function emit(string $event, int $id, object $component): void
	{
		if(!$this->hasListeners($event))
		{
			return;
		}

		foreach($this->listeners[$event] as $listener)
		{
			$listener($this, $id, $component);
		}
	}

	/**
	 * Check whether an event currently has at least one observer.
	 *
	 * @param string $event Lifecycle event name.
	 * @return bool Whether dispatch has any work to perform.
	 */
	private function hasListeners(string $event): bool
	{
		return !empty($this->listeners[$event]);
	}

	/**
	 * Attach a component when no lifecycle callback can observe intermediate state.
	 *
	 * @param int $id Live entity receiving the component.
	 * @param object $component Component instance to attach.
	 * @param class-string|null $type Known concrete class, when already resolved by the caller.
	 * @return void Updates component storage and type indexes without cache or event work.
	 */
	private function addWithoutListeners(int $id, object $component, ?string $type = null): void
	{
		$type ??= get_class($component);

		// Preserve replacement semantics: an existing exact type causes every matching
		// subclass to be removed before the new concrete component is installed.
		if(isset($this->entities[$id][$type]))
		{
			if(($this->index[$type][$id] ?? 0) === 1)
			{
				$this->entities[$id][$type] = $component;

				return;
			}

			$this->removeWithoutListeners($id, $type);
		}

		$this->entities[$id][$type] = $component;
		$this->indexComponent($id, $type);
	}

	/**
	 * Remove matching components without event dispatch or repeated invalidation.
	 *
	 * @param int $id Live entity whose components are inspected.
	 * @param class-string $type Concrete class, parent, or interface to remove.
	 * @return bool Whether at least one component was detached.
	 */
	private function removeWithoutListeners(int $id, string $type): bool
	{
		if(($this->index[$type][$id] ?? 0) === 1 && isset($this->entities[$id][$type]))
		{
			$this->detachComponent($id, $type);

			return true;
		}

		$removed = false;

		foreach($this->entities[$id] as $class => $component)
		{
			if($component instanceof $type)
			{
				$this->detachComponent($id, $class);
				$removed = true;
			}
		}

		return $removed;
	}

	/**
	 * Add one concrete component's memberships to every cached hierarchy index.
	 *
	 * @param int $id Entity receiving the component.
	 * @param class-string $type Concrete component class.
	 * @return void Adds one membership count for the concrete class, parents, and interfaces.
	 */
	private function indexComponent(int $id, string $type): void
	{
		$types = $this->types($type);

		for($i = 0, $count = count($types); $i < $count; $i++)
		{
			$indexed = $types[$i];
			// Count overlapping concrete components without a map for each entity/type.
			$this->index[$indexed][$id] = ($this->index[$indexed][$id] ?? 0) + 1;
		}
	}

	/**
	 * Detach one concrete component from storage and every hierarchy index.
	 *
	 * @param int $id Entity losing the component.
	 * @param class-string $type Concrete component class stored on the entity.
	 * @return void Removes concrete membership and decrements shared ancestors.
	 */
	private function detachComponent(int $id, string $type): void
	{
		unset($this->entities[$id][$type]);
		$types = $this->types($type);

		for($i = 0, $count = count($types); $i < $count; $i++)
		{
			$indexed = $types[$i];

			if($this->index[$indexed][$id] === 1)
			{
				unset($this->index[$indexed][$id]);
			}
			else
			{
				$this->index[$indexed][$id]--;
			}
		}
	}

	/**
	 * Cache every class and interface under which a concrete component class is indexed.
	 *
	 * @param class-string $class Concrete component class whose hierarchy is inspected.
	 * @return list<class-string> Concrete class followed by parent classes and implemented
	 * interfaces.
	 */
	private function types(string $class): array
	{
		return $this->typeCache[$class] ??= array_merge(
			[$class]
			, array_values(class_parents($class))
			, array_values(class_implements($class))
		);
	}

	/**
	 * Reject operations targeting an entity that is no longer alive.
	 *
	 * @param int $id Entity ID that must exist.
	 * @return void Returns normally for live entities; otherwise throws OutOfBoundsException.
	 */
	private function requireEntity(int $id): void
	{
		if(!$this->alive($id))
		{
			throw new \OutOfBoundsException("Entity $id is not alive");
		}
	}
}
