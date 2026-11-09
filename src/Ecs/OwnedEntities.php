<?php
declare(strict_types=1);
namespace Phonics\Ecs;

/**
 * The entities a manager or scope created and must take down with itself.
 *
 * A manager that spawns entities over its life keeps their IDs here and calls `close()` when it is torn down: every
 * owned entity still alive is destroyed, in the order they were owned or in reverse, and a second `close()` does
 * nothing. Entities something else destroyed meanwhile are skipped, so ownership can be shared with systems that
 * retire entities on their own. `ScopeContext` builds its scope ownership on this.
 */
final class OwnedEntities
{
	/** @var array<int,true> Owned entity IDs in the order they were owned. */
	private array $ids = [];

	/**
	 * Track ownership within one registry.
	 *
	 * @param Registry $registry The registry the owned entities live in.
	 * @return void Starts with nothing owned.
	 */
	public function __construct(private Registry $registry) {}

	/**
	 * Record an entity as owned.
	 *
	 * @param int $id Live entity in the registry.
	 * @return int The same ID, convenient in spawn pipelines.
	 */
	public function own(int $id): int
	{
		if(!$this->registry->alive($id)) throw new \OutOfBoundsException("Cannot own missing entity $id");

		$this->ids[$id] = true;

		return $id;
	}

	/**
	 * Stop tracking an entity without destroying it, as when another owner takes it over.
	 *
	 * @param int $id Entity to release.
	 * @return void Removes the ID whether or not it was owned.
	 */
	public function disown(int $id): void { unset($this->ids[$id]); }

	/**
	 * Whether an entity is currently owned.
	 *
	 * @param int $id Entity to test.
	 * @return bool True while the entity is owned and not yet disowned or closed.
	 */
	public function owns(int $id): bool { return isset($this->ids[$id]); }

	/**
	 * The owned entities, alive or not.
	 *
	 * @return list<int> Owned IDs in the order they were owned.
	 */
	public function ids(): array { return array_keys($this->ids); }

	/**
	 * How many entities are owned.
	 *
	 * @return int The number of owned IDs, alive or not.
	 */
	public function count(): int { return count($this->ids); }

	/**
	 * Destroy every owned entity still alive and forget them all.
	 *
	 * @param callable(int):void|null $retire Takes each live entity down instead of `Registry::destroy()`, for
	 * owners that release colliders or listeners per entity.
	 * @param bool $reverse Whether to take the entities down newest first, as a scope does.
	 * @return void Idempotent: a second call finds nothing owned.
	 */
	public function close(?callable $retire = null, bool $reverse = false): void
	{
		$ids = array_keys($this->ids);
		$this->ids = [];

		if($reverse) $ids = array_reverse($ids);

		foreach($ids as $id)
		{
			if(!$this->registry->alive($id)) continue;

			if($retire) $retire($id);
			else $this->registry->destroy($id);
		}
	}
}
