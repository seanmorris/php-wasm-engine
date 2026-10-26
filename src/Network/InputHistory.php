<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Bounded command history used for redundant sends, acknowledgement, and replay. */
final class InputHistory
{
	/** @var array<int,InputCommand> */
	private array $commands = [];

	/**
	 * Create and validate an input history.
	 *
	 * @param int $capacity Maximum number of entries retained by the bounded collection.
	 */
	public function __construct(
		/** Maximum retained entry count. */
		public int $capacity = 128
	){
		if($capacity < 1 || $capacity > 4096) throw new \OutOfRangeException('Input history capacity is invalid');
	}

	/**
	 * Add a command or snapshot to the bounded history.
	 *
	 * @param InputCommand $command Validated input command to inspect or apply.
	 * @return void No value is returned.
	 */
	public function push(InputCommand $command): void
	{
		$key = $command->sequence->value();

		if(isset($this->commands[$key]))
		{
			if($this->commands[$key]->jsonSerialize() !== $command->jsonSerialize())
			throw new \LogicException("Conflicting input command sequence $key");

			return;
		}

		$this->commands[$key] = $command;
		ksort($this->commands, SORT_NUMERIC);

		while(count($this->commands) > $this->capacity) unset($this->commands[array_key_first($this->commands)]);
	}

	/**
	 * Select the newest commands for redundant transmission.
	 *
	 * @param int $count Maximum number of recent commands to return.
	 * @return list<InputCommand> Recent commands ordered from oldest to newest.
	 */
	public function redundant(int $count = 3): array
	{
		$count = max(1, min($this->capacity, $count));

		return array_values(array_slice($this->commands, -$count, null, true));
	}

	/**
	 * Remove commands through an acknowledged sequence.
	 *
	 * @param CommandSequence $sequence Command or snapshot sequence used as a boundary.
	 * @return void No value is returned.
	 */
	public function acknowledge(CommandSequence $sequence): void
	{
		foreach(array_keys($this->commands) as $value)
		if($value <= $sequence->value()) unset($this->commands[$value]);
	}

	/**
	 * Select commands newer than an acknowledged sequence.
	 *
	 * @param CommandSequence $sequence Command or snapshot sequence used as a boundary.
	 * @return list<InputCommand> Commands newer than the given acknowledgement.
	 */
	public function after(CommandSequence $sequence): array
	{
		return array_values(
			array_filter(
				$this->commands
				, static fn(InputCommand $command): bool => $command->sequence->value() > $sequence->value()
			)
		);
	}

	/**
	 * Count retained commands.
	 *
	 * @return int Number of commands currently retained.
	 */
	public function count(): int { return count($this->commands); }

	/**
	 * Discard all retained input history state.
	 *
	 * @return void No value is returned.
	 */
	public function clear(): void { $this->commands = []; }
}
