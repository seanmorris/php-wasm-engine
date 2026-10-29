<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Bounded idempotence ledger for reliable gameplay events. */
final class EventLedger
{
	/** @var array<string,true> */
	private array $seen = [];

	/**
	 * Create and validate an event ledger.
	 *
	 * @param int $capacity Maximum number of entries retained by the bounded collection.
	 * @return void Rejects a capacity outside 1 through 65536.
	 */
	public function __construct(
		/** Maximum retained entry count. */
		public int $capacity = 4096
	){
		if($capacity < 1 || $capacity > 65536) throw new \OutOfRangeException('Event ledger capacity is invalid');
	}

	/**
	 * Record an event ID once and reject redundant delivery.
	 *
	 * @param ReliableEvent $event Idempotent reliable gameplay event.
	 * @return bool True only for the first occurrence of the event ID.
	 */
	public function accept(ReliableEvent $event): bool
	{
		if(isset($this->seen[$event->id])) return false;

		$this->seen[$event->id] = true;

		while(count($this->seen) > $this->capacity) unset($this->seen[array_key_first($this->seen)]);

		return true;
	}

	/**
	 * Discard all retained event ledger state.
	 *
	 * @return void No value is returned.
	 */
	public function clear(): void { $this->seen = []; }
}
