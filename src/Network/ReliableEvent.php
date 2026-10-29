<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Idempotent reliable gameplay event such as a pickup, sound, score, or transition. */
final class ReliableEvent implements \JsonSerializable
{

	/**
	 * Create and validate a reliable event.
	 *
	 * @param string $id Stable identifier for an authority, event, prefab, or network entity.
	 * @param SimulationTick $tick Simulation tick associated with the command, event, or snapshot.
	 * @param string $type Stable game-owned reliable event type.
	 * @param array<string,mixed> $payload Validated scalar data carried by the message or component.
	 * @return void Stores the event after validating its ID, type and JSON-safe payload.
	 */
	public function __construct(
		/** Stable event or prefab identifier. */
		public string $id
		, /** Simulation tick represented by the record. */
		public SimulationTick $tick
		, /** Stable game-owned event type. */
		public string $type
		, /** Validated JSON-safe payload fields. */
		public array $payload = []
	){
		if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,127}$/', $id))
		throw new \InvalidArgumentException("Invalid reliable event ID $id");

		if(!preg_match('/^[A-Za-z][A-Za-z0-9_.:\/-]{0,127}$/', $type))
		throw new \InvalidArgumentException("Invalid reliable event type $type");

		json_encode($payload, JSON_THROW_ON_ERROR);
	}

	/**
	 * Restore a reliable event from transport-safe fields.
	 *
	 * @param array $value Scalar value or serialized fields to validate.
	 * @return self A validated value restored from serialized fields.
	 */
	public static function fromArray(array $value): self
	{
		if(!is_array($value['payload'] ?? [])) throw new \InvalidArgumentException('Event payload is invalid');

		return new self(
			(string)($value['id'] ?? '')
			, new SimulationTick((int)($value['tick'] ?? -1))
			, (string)($value['type'] ?? '')
			, $value['payload'] ?? []
		);
	}

	/**
	 * Serialize the reliable event into transport-safe fields.
	 *
	 * @return array Scalar fields suitable for JSON transport.
	 */
	public function jsonSerialize(): array
	{
		return ['id' => $this->id,'tick' => $this->tick->value(),'type' => $this->type,'payload' => $this->payload];
	}
}
