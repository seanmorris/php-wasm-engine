<?php
declare(strict_types=1);
namespace Phonics\Network;

use Phonics\Ecs\Registry;
use Phonics\Network\Component\NetworkIdentity;
use Phonics\Network\Component\NetworkPrefab;

/** Applies whitelisted spawn, component, and despawn records to a receiving ECS registry. */
final class SnapshotApplier
{
	/** @var array<int,array<string,mixed>> */
	private array $state = [];
	/** @var array<int,array<int,array<string,mixed>>> */
	private array $baselines = [];
	private ?SnapshotSequence $sequence = null;
	/** @var (callable(Registry,int,ComponentCodec):bool)|null */
	private $componentFilter;

	/**
	 * Create and validate a snapshot applier.
	 *
	 * @param ReplicationRegistry $replication Whitelist of component codecs and prefab factories.
	 * @param NetworkEntityAllocator $entities Bidirectional network/local entity identity map.
	 * @param (callable(Registry,int,ComponentCodec):bool)|null $componentFilter Optional component policy predicate.
	 * @return void Creates a snapshot applier with optional component authority filtering.
	 */
	public function __construct(
		/** Registered component and prefab whitelist. */
		public ReplicationRegistry $replication
		, /** Network and local entity identity map. */
		public NetworkEntityAllocator $entities
		, ?callable $componentFilter = null
	){
		$this->componentFilter = $componentFilter;
	}

	/**
	 * Apply an authoritative snapshot to the receiving ECS registry.
	 *
	 * @param Registry $registry ECS registry whose replicated state is read or changed.
	 * @param WorldSnapshot $snapshot Validated authoritative world snapshot.
	 * @return void No value is returned.
	 */
	public function apply(Registry $registry, WorldSnapshot $snapshot): void
	{
		if($this->sequence && $snapshot->sequence->value() <= $this->sequence->value()) return;

		$target = $snapshot->full()
			? $snapshot->entities
			: $this->deltaTarget($snapshot);

		foreach(array_keys($this->state) as $value)
		if(!isset($target[$value])) $this->remove($registry, new NetworkEntityId($value));

		foreach($target as $value => $record) $this->reconcile($registry, (int)$value, $record);

		$this->state = $target;
		$this->sequence = $snapshot->sequence;
		$this->baselines[$snapshot->sequence->value()] = $target;

		while(count($this->baselines) > 32) unset($this->baselines[array_key_first($this->baselines)]);
	}

	/**
	 * Read the newest snapshot sequence applied by this receiver.
	 *
	 * @return ?SnapshotSequence The newest applied snapshot sequence, or null before the first snapshot.
	 */
	public function sequence(): ?SnapshotSequence { return $this->sequence; }

	/**
	 * Discard all retained snapshot applier state.
	 *
	 * @return void No value is returned.
	 */
	public function clear(): void
	{
		$this->state = [];
		$this->baselines = [];
		$this->sequence = null;
		$this->entities->clear();
	}

	/**
	 * Rebuild the full target state of a delta snapshot from its retained baseline.
	 *
	 * @param WorldSnapshot $snapshot Baseline-relative authoritative snapshot.
	 * @return array<int,array<string,mixed>> Entity records keyed and sorted by network ID; throws when the baseline
	 * is unavailable or a delta changes an entity's prefab or scope.
	 */
	private function deltaTarget(WorldSnapshot $snapshot): array
	{
		$baseline = $snapshot->baseline?->value();
		$target = $baseline === null ? null : ($this->baselines[$baseline] ?? null);

		if($target === null)
		throw new \UnexpectedValueException('Snapshot baseline is unavailable; request a full resync');

		foreach($snapshot->despawned as $value) unset($target[$value]);

		foreach($snapshot->entities as $value => $delta)
		{
			if(!isset($target[$value]))
			{
				if(!is_string($delta['prefab'] ?? null) || !is_string($delta['scope'] ?? null))
				throw new \UnexpectedValueException("Snapshot spawn $value has no prefab or scope");

				$target[$value] = $delta;
				continue;
			}

			if(isset($delta['prefab']) && $delta['prefab'] !== $target[$value]['prefab'])
			throw new \UnexpectedValueException("Snapshot changed prefab for network entity $value");

			if(isset($delta['scope']) && $delta['scope'] !== $target[$value]['scope'])
			throw new \UnexpectedValueException("Snapshot changed scope for network entity $value");

			foreach($delta['components'] ?? [] as $schema => $record)
			if($record === null) unset($target[$value]['components'][$schema]);
			else $target[$value]['components'][$schema] = $record;
		}

		ksort($target, SORT_NUMERIC);

		return $target;
	}

	/**
	 * Spawn or verify one replicated entity and bring its components to the target state.
	 *
	 * @param Registry $registry ECS registry receiving the snapshot.
	 * @param int $value Network entity ID.
	 * @param array<string,mixed> $target Target scope, prefab and component records.
	 * @return void Removes and applies changed components, skipping those the component filter rejects.
	 */
	private function reconcile(Registry $registry, int $value, array $target): void
	{
		$id = new NetworkEntityId($value);
		$prefab = $target['prefab'] ?? null;
		$scope = $target['scope'] ?? null;

		if(!is_string($prefab) || !is_string($scope) || !is_array($target['components'] ?? null))
		throw new \UnexpectedValueException("Snapshot entity $value is incomplete");

		$local = $this->entities->local($id);

		if($local === null)
		{
			$local = $this->replication->spawn($prefab, $registry, ['networkId' => $value,'scope' => $scope]);
			$registry->add($local, new NetworkIdentity($id, new ReplicationScope($scope)));
			$registry->add($local, new NetworkPrefab($prefab));
			$this->entities->bind($id, $local);
		}
		elseif(!$registry->alive($local)
			|| !$registry->has($local, NetworkIdentity::class)
			|| !$registry->has($local, NetworkPrefab::class)
			|| $registry->get($local, NetworkPrefab::class)->id !== $prefab
			|| $registry->get($local, NetworkIdentity::class)->scope->value() !== $scope
		) throw new \UnexpectedValueException("Existing network entity $value has incompatible identity");

		$current = $this->state[$value]['components'] ?? $this->replication->capture($registry, $local);

		foreach(array_diff_key($current, $target['components']) as $schema => $_record)
		{
			$codec = $this->replication->codec($schema);

			if($this->componentFilter && !($this->componentFilter)($registry, $local, $codec)) continue;

			$type = $codec->componentType();

			if($registry->has($local, $type)) $registry->remove($local, $type);
		}

		foreach($target['components'] as $schema => $record)
		{
			if(array_key_exists($schema, $current) && $current[$schema] === $record) continue;

			$codec = $this->replication->codec($schema);

			if($this->componentFilter && !($this->componentFilter)($registry, $local, $codec)) continue;

			$this->replication->apply($registry, $local, [$schema => $record]);
		}
	}

	/**
	 * Despawn a replicated entity and forget its network identity.
	 *
	 * @param Registry $registry ECS registry receiving the snapshot.
	 * @param NetworkEntityId $id Network entity to remove.
	 * @return void Uses the prefab's despawn hook when available, otherwise destroys the local entity.
	 */
	private function remove(Registry $registry, NetworkEntityId $id): void
	{
		$value = $id->value();
		$local = $this->entities->local($id);

		if($local !== null && $registry->alive($local))
		{
			$prefab = $registry->has($local, NetworkPrefab::class)
				? $registry->get($local, NetworkPrefab::class)->id
				: null;

			if($prefab !== null) $this->replication->despawn($prefab, $registry, $local);
			else $registry->destroy($local);
		}

		$this->entities->retire($id);
		unset($this->state[$value]);
	}
}
