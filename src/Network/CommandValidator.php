<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Host-side ownership, tick-window, sequence, rate, and game-rule validation. */
final class CommandValidator
{
	/** @var array<int,PeerId> */
	private array $owners = [];
	/** @var array<string,int> */
	private array $latest = [];
	/** @var array<string,array<int,int>> */
	private array $rates = [];
	private $gameRule;

	/**
	 * Create and validate a command validator.
	 *
	 * @param int $pastTicks Maximum number of ticks a command may trail the host.
	 * @param int $futureTicks Maximum number of ticks a command may lead the host.
	 * @param int $maximumPerTick Maximum accepted commands for one player and tick.
	 * @param (callable(InputCommand):bool)|null $gameRule Optional game-specific command predicate.
	 */
	public function __construct(
		/** Accepted number of ticks behind the host. */
		public int $pastTicks = 8
		, /** Accepted number of ticks ahead of the host. */
		public int $futureTicks = 8
		, /** Maximum commands accepted for one player and tick. */
		public int $maximumPerTick = 4
		, ?callable $gameRule = null
	){
		if($pastTicks < 0 || $futureTicks < 0 || $maximumPerTick < 1)
		throw new \InvalidArgumentException('Invalid command validation limits');

		$this->gameRule = $gameRule;
	}

	/**
	 * Assign one player slot to an authenticated peer.
	 *
	 * @param PlayerSlot $slot Player slot whose input is sampled, owned, or queried.
	 * @param PeerId $peer Session peer associated with authority or input ownership.
	 * @return void No value is returned.
	 */
	public function own(PlayerSlot $slot, PeerId $peer): void
	{
		$this->owners[$slot->value()] = $peer;
	}

	/**
	 * Validate ownership, timing, sequence, rate, and game rules for a command.
	 *
	 * @param PeerId $peer Session peer associated with authority or input ownership.
	 * @param InputCommand $command Validated input command to inspect or apply.
	 * @param SimulationTick $hostTick Host tick used to enforce the accepted command window.
	 * @return bool True for a newly accepted command; false for redundancy or recoverable timing drift.
	 */
	public function validate(PeerId $peer, InputCommand $command, SimulationTick $hostTick): bool
	{
		$owner = $this->owners[$command->player->value()] ?? null;

		if(!$owner || !$owner->equals($peer))
		throw new \UnexpectedValueException("Peer $peer does not own player slot {$command->player}");

		$key = $peer->value() . ':' . $command->player->value();
		$sequence = $command->sequence->value();

		if(isset($this->latest[$key]) && $sequence <= $this->latest[$key]) return false;

		$distance = $command->tick->distanceFrom($hostTick);

		if($distance < -$this->pastTicks || $distance > $this->futureTicks) return false;

		$tick = $command->tick->value();
		$this->rates[$key][$tick] = ($this->rates[$key][$tick] ?? 0) + 1;

		if($this->rates[$key][$tick] > $this->maximumPerTick)
		throw new \UnexpectedValueException("Player slot {$command->player} exceeded the command rate");

		foreach(array_keys($this->rates[$key]) as $sample)
		if(abs($tick - $sample) > $this->pastTicks + $this->futureTicks + 2) unset($this->rates[$key][$sample]);

		if($this->gameRule && !($this->gameRule)($command))
		throw new \UnexpectedValueException('Game command validation failed');

		$this->latest[$key] = $sequence;

		return true;
	}

	/**
	 * Read the newest accepted command sequence for a peer-owned slot.
	 *
	 * @param PeerId $peer Session peer associated with authority or input ownership.
	 * @param PlayerSlot $slot Player slot whose input is sampled, owned, or queried.
	 * @return ?CommandSequence The newest accepted sequence, or null when none was accepted.
	 */
	public function latest(PeerId $peer, PlayerSlot $slot): ?CommandSequence
	{
		$value = $this->latest[$peer->value() . ':' . $slot->value()] ?? null;

		return $value === null ? null : new CommandSequence($value);
	}

	/**
	 * Discard all retained command validator state.
	 *
	 * @return void No value is returned.
	 */
	public function clear(): void { $this->latest = $this->rates = []; }
}
