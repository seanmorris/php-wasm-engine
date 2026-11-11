<?php
declare(strict_types=1);
namespace Phonics\Tiled;

use Phonics\Ecs\ComponentFactories;
use Phonics\World\ScopeContext;

/** Reconcile changed tile groups at scheduler boundaries, preserving unchanged entity identities. */
final class TileEntities
{
	/** Published group records indexed by complete membership signature. */
	private array $groups = [];
	/** Last reconciled chunk revisions and pending work marker. */
	private array $revisions = [];
	private bool $pending = false, $closed = false;

	/**
	 * Create tile entities and install a scope-owned revision observer.
	 *
	 * @param TileMap $map Map instance whose authored content is being inspected.
	 * @param ScopeContext $scope Owning content scope.
	 * @param ClassCatalog $catalog Portable project class defaults and component bindings.
	 * @param ComponentFactories $factories Registered constructors from already loaded game packages.
	 * @param mixed $transfer Optional callback receiving removed groups, added groups, and scope.
	 * @return void Initializes the owned references and validates configuration.
	 */
	public function __construct(
		private TileMap $map
		, private ScopeContext $scope
		, private ClassCatalog $catalog
		, private ComponentFactories $factories
		, private mixed $transfer = null
	){
		$this->rebuild();

		$scope->system('tiled-tiles', /** Observe edited tile chunks.
 * @return void Completes the scope operation.
 */
			fn() => $this->update()
		);

		$scope->onClose(/** Cancel queued reconciliation when the owning scope closes.
 * @return void Completes the scope operation.
 */
			function(): void {
				$this->closed = true;
				$this->groups = [];
			}
		);
	}

	/**
	 * Inspect live group membership and entity identities.
	 *
	 * @return array Published memberships and their entity identities.
	 */
	public function groups(): array { return $this->groups; }

	/**
	 * Schedule one reconciliation if tile chunks were added, removed, or edited.
	 *
	 * @return void Queues reconciliation only when tile revisions have changed.
	 */
	public function update(): void
	{
		if($this->closed || $this->pending || $this->revisions === $this->revisions()) return;

		$this->pending = true;

		$this->scope->scheduler->defer(/** Reconcile after systems finish using the current tile memberships.
 * @return void Completes the scope operation.
 */
			function(): void {
				$this->pending = false;

				if(!$this->closed) $this->rebuild();
			}
		);
	}

	/**
	 * Capture cheap revision identities without scanning unchanged tile buffers.
	 *
	 * @return array Layer and chunk identities paired with current revision counters.
	 */
	private function revisions(): array
	{
		$result = [];

		foreach($this->map->layers as $layer)
		foreach($layer->chunks as $chunk)
		$result[] = [$layer->key, spl_object_id($chunk), $chunk->revision];

		return $result;
	}

	/**
	 * Stage replacements before retiring old groups; factories and transfer hooks may fail safely.
	 *
	 * @return void Publishes replacement groups while retaining unchanged identities.
	 */
	private function rebuild(): void
	{
		$next = TileGroups::collect($this->map);
		$removed = array_diff_key($this->groups, $next);
		$added = [];

		try
		{
			foreach(array_diff_key($next, $this->groups) as $key => $group)
			{
				$target = new Target('tile', $key, $group['tile'], $this->map);

				$components = $this->factories->build(
					$this->catalog->components($group['tile'], $group['tile']['sourceUrl'])
					, $this->scope
					, $target
				);

				$added[$key] = $group + [
					'entity' => $this->scope->entity($target, $group['membership'], ...$components)
				];
			}

			if($this->transfer && ($removed || $added)) ($this->transfer)($removed, $added, $this->scope);
		}
		catch(\Throwable $error)
		{
			$this->destroy($added);
			throw $error;
		}

		$this->destroy($removed);
		$this->groups = array_intersect_key($this->groups, $next) + $added;
		$this->revisions = $this->revisions();
	}

	/**
	 * Destroy only owned group entities, leaving renderer and collider ownership unchanged.
	 *
	 * @param array $groups Membership records whose owned entities should be retired.
	 * @return void Releases the selected entities and ownership records.
	 */
	private function destroy(array $groups): void
	{
		foreach($groups as $group)
		{
			$this->scope->registry->destroy($group['entity']);
			$this->scope->disown($group['entity']);
		}

		$this->scope->registry->flush();
	}
}
