<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ecs;

/** Object components, stable entity IDs, type indexes, snapshot queries. */
final class Registry
{
	private int $next = 1;
	private array $entities = [], $index = [], $commands = [], $listeners = [];
	private array $typeCache = [], $queryCache = [];
	private int $cachedIds = 0;
	private const QUERY_CACHE_ENTRIES = 64;
	private const QUERY_CACHE_IDS = 131072;

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
		$this->entities[$id] = [];
		$this->invalidateQueries();

		foreach($components as $c)
		{
			$this->add($id, $c);
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
		$this->requireEntity($id);
		$type = get_class($component);

		if(isset($this->entities[$id][$type]))
		{
			$this->remove($id, $type);
		}

		// A removal listener may already have attached another instance of this concrete class.
		$indexed = isset($this->entities[$id][$type]);
		$this->entities[$id][$type] = $component;

		if(!$indexed)
		{
			foreach($this->types($component) as $class)
			{
				// Count overlapping concrete components without a map for each entity/type.
				$this->index[$class][$id] = ($this->index[$class][$id] ?? 0) + 1;
			}
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
		$this->requireEntity($id);

		foreach($this->entities[$id] as $class => $component)
		{
			if($component instanceof $type)
			{
				// Earlier callbacks can remove a later member of this foreach snapshot.
				if(isset($this->entities[$id][$class]))
				{
					unset($this->entities[$id][$class]);

					foreach($this->types($component) as $indexed)
					{
						if(--$this->index[$indexed][$id] === 0)
						{
							unset($this->index[$indexed][$id]);
						}
					}
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
		$this->requireEntity($id);

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
		$this->requireEntity($id);

		if(isset($this->entities[$id][$type]))
		{
			return $this->entities[$id][$type];
		}

		foreach($this->entities[$id] as $component)
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
	public function has(int $id, string $type): bool { return isset($this->index[$type][$id]); }

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
			return array_keys($this->index[$query] ?? []);
		}

		if(!$query instanceof Query)
		{
			throw new \InvalidArgumentException('Expected component type or Query');
		}

		// Value keys also cover freshly constructed descriptions and edits to nested public terms.
		$key = serialize($query);

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
					$ids = $this->index[$term] ?? [];

					if(count($ids) < count($candidates))
					{
						$candidates = $ids;
					}
				}
			}
		}

		// Intersect in the original candidate order, even when a nested set is more selective.
		$ids = $candidates ? array_keys(
			array_intersect_key($candidates, $this->matchingSet($query))
		) : [];
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
			return $this->index[$query] ?? [];
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
		foreach($this->listeners[$event] ?? [] as $listener)
		{
			$listener($this, $id, $component);
		}
	}

	/**
	 * Cache every class and interface under which a concrete component class is indexed.
	 *
	 * @param object $object Component instance whose runtime type hierarchy is inspected.
	 * @return list<class-string> Concrete class followed by parent classes and implemented
	 * interfaces.
	 */
	private function types(object $object): array
	{
		$class = get_class($object);

		return $this->typeCache[$class] ??= array_merge(
			[$class]
			, array_values(class_parents($object))
			, array_values(class_implements($object))
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
