<?php
declare(strict_types=1);
namespace Phonics\Network;

use Phonics\Ecs\Registry;
use Phonics\Network\Component\NetworkIdentity;
use Phonics\Network\Component\NetworkPrefab;

/** Captures whitelisted components and derives deltas from retained authoritative baselines. */
final class SnapshotBuilder
{
	/** @var array<int,array<int,array<string,mixed>>> */
	private array $baselines = [];

	/**
	 * Create and validate a snapshot builder.
	 *
	 * @param ReplicationRegistry $replication Whitelist of component codecs and prefab factories.
	 * @param int $baselineCapacity Maximum authoritative baselines retained for deltas.
	 */
	public function __construct(
		/** Registered component and prefab whitelist. */
		public ReplicationRegistry $replication
		, /** Maximum retained authoritative baselines. */
		public int $baselineCapacity = 32
	){
		if($baselineCapacity < 1 || $baselineCapacity > 256)
		throw new \OutOfRangeException('Snapshot baseline capacity is invalid');
	}

	/**
	 * Capture a full or baseline-relative authoritative snapshot.
	 *
	 * @param Registry $registry ECS registry whose replicated state is read or changed.
	 * @param SnapshotSequence $sequence Command or snapshot sequence used as a boundary.
	 * @param SimulationTick $tick Simulation tick associated with the command, event, or snapshot.
	 * @param ?SnapshotSequence $baseline Prior snapshot sequence used to encode a delta, or null for a full snapshot.
	 * @param array<int,int> $acknowledged Player-slot keyed command acknowledgements.
	 * @return WorldSnapshot Encoded components or an authoritative world snapshot.
	 */
	public function capture(
		Registry $registry
		, SnapshotSequence $sequence
		, SimulationTick $tick
		, ?SnapshotSequence $baseline = null
		, array $acknowledged = []
	): WorldSnapshot {
		$current = $this->state($registry);
		$base = $baseline ? ($this->baselines[$baseline->value()] ?? null) : null;

		if($baseline && $base === null) $baseline = null;

		$entities = [];
		$despawned = [];

		if($baseline === null) $entities = $current;
		else
		{
			foreach($current as $id => $record)
			{
				if(!isset($base[$id]))
				{
					$entities[$id] = $record;
					continue;
				}

				$changed = $this->componentDelta($base[$id]['components'], $record['components']);

				if($changed) $entities[$id] = ['components' => $changed];
			}

			foreach(array_keys($base) as $id)
			if(!isset($current[$id])) $despawned[] = $id;
		}

		$this->baselines[$sequence->value()] = $current;

		while(count($this->baselines) > $this->baselineCapacity)
		unset($this->baselines[array_key_first($this->baselines)]);

		return new WorldSnapshot($sequence, $tick, $baseline, $entities, $despawned, $acknowledged);
	}

	/**
	 * Check whether a retained snapshot baseline is available.
	 *
	 * @param SnapshotSequence $sequence Command or snapshot sequence used as a boundary.
	 * @return bool True when the requested baseline is retained.
	 */
	public function has(SnapshotSequence $sequence): bool { return isset($this->baselines[$sequence->value()]); }

	/**
	 * Discard all retained snapshot builder state.
	 *
	 * @return void No value is returned.
	 */
	public function clear(): void { $this->baselines = []; }

	/** @return array<int,array<string,mixed>> */
	private function state(Registry $registry): array
	{
		$result = [];

		foreach($registry->query(NetworkIdentity::class) as $entity)
		{
			$identity = $registry->get($entity, NetworkIdentity::class);

			if(!$registry->has($entity, NetworkPrefab::class))
			throw new \LogicException("Replicated entity $entity has no NetworkPrefab");

			$record = [
				'scope' => $identity->scope->value()
				, 'prefab' => $registry->get($entity, NetworkPrefab::class)->id
				, 'components' => $this->replication->capture($registry, $entity)
			];

			$result[$identity->id->value()] = $record;
		}

		ksort($result, SORT_NUMERIC);

		return $result;
	}

	/** @return array<string,mixed> */
	private function componentDelta(array $before, array $after): array
	{
		$result = [];

		foreach($after as $schema => $record)
		if(!array_key_exists($schema, $before) || $before[$schema] !== $record) $result[$schema] = $record;

		foreach(array_keys($before) as $schema)
		if(!array_key_exists($schema, $after)) $result[$schema] = null;

		ksort($result);

		return $result;
	}
}
