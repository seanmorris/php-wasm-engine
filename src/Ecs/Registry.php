<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ecs;

/** Object components, stable entity IDs, type indexes, snapshot queries. */
final class Registry
{
	private int $next = 1;
	private array $entities = [], $index = [], $commands = [], $listeners = [];

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
		$this->entities[$id][$type] = $component;
		foreach($this->types($component) as $class)
		{
			$this->index[$class][$id][$type] = true;
		}
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
				unset($this->entities[$id][$class]);
				foreach($this->types($component) as $indexed)
				{
					unset($this->index[$indexed][$id][$class]);
					if(empty($this->index[$indexed][$id]))
					{
						unset($this->index[$indexed][$id]);
					}
				}
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
		$candidates = array_keys($this->entities);
		if($query->operation === 'all')
		{
			foreach($query->terms as $term)
			{
				if(is_string($term))
				{
					$ids = array_keys($this->index[$term] ?? []);
					if(count($ids) < count($candidates))
					{
						$candidates = $ids;
					}
				}
			}
		}
		return array_values(array_filter($candidates,
			/**
			 * Evaluate the composed query against a candidate entity.
			 *
			 * @param int $id Candidate live entity ID.
			 * @return bool Whether this candidate belongs in the query snapshot.
			 */
			fn($id) => $query->matches($this, $id)));
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
	 * Collect every class and interface under which a component is indexed.
	 *
	 * @param object $object Component instance whose runtime type hierarchy is inspected.
	 * @return list<class-string> Concrete class followed by parent classes and implemented
	 * interfaces.
	 */
	private function types(object $object): array
	{
		return array_merge([get_class($object)], array_values(class_parents($object)), array_values(
			class_implements($object)));
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
