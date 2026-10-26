<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Game-owned command sampling/application and reliable-event hooks. */
final class ReplicationCallbacks
{
	private $sample;
	private $apply;
	private $event;

	/**
	 * Create and validate a replication callback set.
	 *
	 * @param callable(PlayerSlot,SimulationTick):array<string,bool|int|float|string|null> $sample Game callback that samples normalized player actions.
	 * @param callable(InputCommand,bool):void $apply Game callback that applies a command to simulation state.
	 * @param (callable(ReliableEvent):void)|null $event Idempotent reliable gameplay event.
	 */
	public function __construct(callable $sample, callable $apply, ?callable $event = null)
	{
		$this->sample = $sample;
		$this->apply = $apply;
		$this->event = $event ?? static function(): void {};
	}

	/**
	 * Sample normalized game actions for a player and simulation tick.
	 *
	 * @param PlayerSlot $slot Player slot whose input is sampled, owned, or queried.
	 * @param SimulationTick $tick Simulation tick associated with the command, event, or snapshot.
	 * @return array<string,bool|int|float|string|null> Normalized actions or interpolation bounds; null when no samples exist.
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
