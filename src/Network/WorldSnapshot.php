<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Immutable authoritative full or baseline-relative ECS snapshot. */
final class WorldSnapshot implements \JsonSerializable
{

	/**
	 * Create and validate a world snapshot.
	 *
	 * @param SnapshotSequence $sequence Command or snapshot sequence used as a boundary.
	 * @param SimulationTick $tick Simulation tick associated with the command, event, or snapshot.
	 * @param ?SnapshotSequence $baseline Prior snapshot sequence used to encode a delta, or null for a full snapshot.
	 * @param array<int,array<string,mixed>> $entities Bidirectional network/local entity identity map.
	 * @param list<int> $despawned Validated despawned used by this world snapshot.
	 * @param array<int,int> $acknowledged Player-slot keyed command acknowledgements.
	 */
	public function __construct(
		/** Monotonic command or snapshot sequence. */
		public SnapshotSequence $sequence
		, /** Simulation tick represented by the record. */
		public SimulationTick $tick
		, /** Baseline snapshot sequence or null for a full snapshot. */
		public ?SnapshotSequence $baseline
		, /** Network and local entity identity map. */
		public array $entities
		, /** Network IDs removed since the baseline. */
		public array $despawned = []
		, /** Last accepted command sequence per player slot. */
		public array $acknowledged = []
	){
		foreach($entities as $id => $record)
		if(!is_int($id) || $id < 1 || !is_array($record))
		throw new \InvalidArgumentException('Snapshot entities must be keyed by positive network IDs');

		foreach($despawned as $id) new NetworkEntityId($id);

		foreach($acknowledged as $slot => $command)
		{
			new PlayerSlot((int)$slot);
			new CommandSequence($command);
		}

		json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR);
	}

	/**
	 * Restore a world snapshot from transport-safe fields.
	 *
	 * @param array $value Scalar value or serialized fields to validate.
	 * @return self A validated value restored from serialized fields.
	 */
	public static function fromArray(array $value): self
	{
		$entities = [];

		foreach($value['entities'] ?? [] as $id => $record) $entities[(int)$id] = $record;

		return new self(
			new SnapshotSequence((int)($value['sequence'] ?? -1))
			, new SimulationTick((int)($value['tick'] ?? -1))
			, isset($value['baseline']) ? new SnapshotSequence((int)$value['baseline']) : null
			, $entities
			, array_map('intval', $value['despawned'] ?? [])
			, array_map('intval', $value['acknowledged'] ?? [])
		);
	}

	/**
	 * Check whether this snapshot contains a complete baseline.
	 *
	 * @return bool True when the snapshot does not depend on a prior baseline.
	 */
	public function full(): bool { return $this->baseline === null; }

	/**
	 * Serialize the world snapshot into transport-safe fields.
	 *
	 * @return array Scalar fields suitable for JSON transport.
	 */
	public function jsonSerialize(): array
	{
		return [
			'sequence' => $this->sequence->value()
			, 'tick' => $this->tick->value()
			, 'baseline' => $this->baseline?->value()
			, 'entities' => $this->entities
			, 'despawned' => array_values($this->despawned)
			, 'acknowledged' => (object)$this->acknowledged
		];
	}
}
