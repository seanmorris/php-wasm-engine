<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Monotonic host allocator and bidirectional network/local ECS identity map. */
final class NetworkEntityAllocator
{
	private int $first;
	private int $next;
	/** @var array<int,int> */
	private array $networkToLocal = [];
	/** @var array<int,int> */
	private array $localToNetwork = [];
	/** @var array<int,true> */
	private array $retired = [];

	/**
	 * Create and validate a network entity allocator.
	 *
	 * @param int $first First positive network entity ID available for allocation.
	 */
	public function __construct(int $first = 1)
	{
		if($first < 1 || $first > 0xffffffff)
		throw new \OutOfRangeException('First network entity ID is outside the unsigned 32-bit range');

		$this->first = $this->next = $first;
	}

	/**
	 * Allocate and bind the next stable network entity ID.
	 *
	 * @param int $localEntity Positive ECS entity ID in this runtime.
	 * @return NetworkEntityId The newly bound stable network entity ID.
	 */
	public function allocate(int $localEntity): NetworkEntityId
	{
		if($localEntity < 1) throw new \InvalidArgumentException('Local entity IDs must be positive');

		if(isset($this->localToNetwork[$localEntity]))
		throw new \LogicException("Local entity $localEntity is already network-bound");

		if($this->next > 0xffffffff) throw new \OverflowException('Network entity ID space exhausted');

		$id = new NetworkEntityId($this->next++);
		$this->bind($id, $localEntity);

		return $id;
	}

	/**
	 * Bind a host-assigned network ID to a local ECS entity.
	 *
	 * @param NetworkEntityId $id Stable identifier for an authority, event, prefab, or network entity.
	 * @param int $localEntity Positive ECS entity ID in this runtime.
	 * @return void No value is returned.
	 */
	public function bind(NetworkEntityId $id, int $localEntity): void
	{
		$value = $id->value();

		if($localEntity < 1) throw new \InvalidArgumentException('Local entity IDs must be positive');

		if(isset($this->retired[$value])) throw new \LogicException("Network entity $value is retired");

		if(isset($this->networkToLocal[$value]) && $this->networkToLocal[$value] !== $localEntity)
		throw new \LogicException("Network entity $value is already bound");

		if(isset($this->localToNetwork[$localEntity]) && $this->localToNetwork[$localEntity] !== $value)
		throw new \LogicException("Local entity $localEntity is already bound");

		$this->networkToLocal[$value] = $localEntity;
		$this->localToNetwork[$localEntity] = $value;
		$this->next = max($this->next, $value + 1);
	}

	/**
	 * Resolve a network entity ID to its local ECS entity.
	 *
	 * @param NetworkEntityId $id Stable identifier for an authority, event, prefab, or network entity.
	 * @return ?int The bound local ECS entity, or null when absent.
	 */
	public function local(NetworkEntityId $id): ?int { return $this->networkToLocal[$id->value()] ?? null; }

	/**
	 * Resolve a local ECS entity to its network identity.
	 *
	 * @param int $localEntity Positive ECS entity ID in this runtime.
	 * @return ?NetworkEntityId The network identity, or null when the entity is not bound.
	 */
	public function network(int $localEntity): ?NetworkEntityId
	{
		$value = $this->localToNetwork[$localEntity] ?? null;

		return $value === null ? null : new NetworkEntityId($value);
	}

	/**
	 * Retire a network identity and return its former local entity.
	 *
	 * @param NetworkEntityId $id Stable identifier for an authority, event, prefab, or network entity.
	 * @return ?int The former local ECS entity, or null when no binding existed.
	 */
	public function retire(NetworkEntityId $id): ?int
	{
		$value = $id->value();
		$local = $this->networkToLocal[$value] ?? null;

		if($local !== null)
		{
			unset($this->networkToLocal[$value], $this->localToNetwork[$local]);
		}

		$this->retired[$value] = true;

		return $local;
	}

	/**
	 * Check whether a network identity has been retired.
	 *
	 * @param NetworkEntityId $id Stable identifier for an authority, event, prefab, or network entity.
	 * @return bool True when the identity cannot be rebound during this match.
	 */
	public function retired(NetworkEntityId $id): bool { return isset($this->retired[$id->value()]); }

	/**
	 * Read the current network-to-local entity map.
	 *
	 * @return array<int,int> Network IDs mapped to local ECS entity IDs.
	 */
	public function bindings(): array { return $this->networkToLocal; }

	/**
	 * Discard all retained network entity allocator state.
	 *
	 * @return void No value is returned.
	 */
	public function clear(): void
	{
		$this->networkToLocal = $this->localToNetwork = $this->retired = [];
		$this->next = $this->first;
	}
}
