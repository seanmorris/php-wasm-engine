<?php
declare(strict_types=1);
namespace Phonics\Network;

/**
 * Game-owned command sampling/application and reliable-event hooks.
 *
 * The callback set is the deliberate boundary where a game samples and applies commands or handles reliable
 * events without coupling core networking to game components.
 */
final class ReplicationCallbacks
{
	/** Callback that samples one local player command. */
	private $sample;
	/** Callback that applies one accepted command to simulation state. */
	private $apply;
	/** Callback that handles one accepted reliable event. */
	private $event;

	/**
	 * Create and validate a replication callback set.
	 *
	 * @param callable(PlayerSlot,SimulationTick):array<string,bool|int|float|string|null> $sample Game callback
	 * that samples normalized player actions.
	 * @param callable(InputCommand,bool):void $apply Game callback that applies a command to simulation state.
	 * @param (callable(ReliableEvent):void)|null $event Idempotent reliable gameplay event.
	 * @return void Stores the callbacks, substituting a no-op event handler when none is given.
	 */
	public function __construct(callable $sample, callable $apply, ?callable $event = null)
	{
		$this->sample = $sample;
		$this->apply = $apply;

		/**
		 * Ignore reliable events when the game supplies no handler.
		 *
		 * @return void Does nothing.
		 */
		$ignoreEvent = static function(): void {};

		$this->event = $event ?? $ignoreEvent;
	}

	/**
	 * Sample normalized game actions for a player and simulation tick.
	 *
	 * @param PlayerSlot $slot Player slot whose input is sampled, owned, or queried.
	 * @param SimulationTick $tick Simulation tick associated with the command, event, or snapshot.
	 * @return array<string,bool|int|float|string|null> Normalized actions; a non-array result throws.
	 */
	public function sample(PlayerSlot $slot, SimulationTick $tick): array
	{
		$value = ($this->sample)($slot, $tick);

		if(!is_array($value)) throw new \UnexpectedValueException('Input sampler must return an action array');

		return $value;
	}

	/**
	 * Apply one input command through game-owned logic.
	 *
	 * @param InputCommand $command Validated input command to inspect or apply.
	 * @param bool $replay Whether command application is reconciliation replay.
	 * @return void No value is returned.
	 */
	public function apply(InputCommand $command, bool $replay = false): void
	{
		($this->apply)($command, $replay);
	}

	/**
	 * Deliver an accepted reliable event to game code.
	 *
	 * @param ReliableEvent $event Idempotent reliable gameplay event.
	 * @return void No value is returned.
	 */
	public function event(ReliableEvent $event): void { ($this->event)($event); }
}
