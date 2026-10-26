<?php
declare(strict_types=1);
namespace Phonics\Network;

/** Device-independent, tick-stamped game command sampled for one player. */
final class InputCommand implements \JsonSerializable
{
	/** @var array<string,bool|int|float|string|null> */
	private array $actions;

	/**
	 * Create and validate an input command.
	 *
	 * @param PlayerSlot $player One-based player slot that originated the command.
	 * @param SimulationTick $tick Simulation tick associated with the command, event, or snapshot.
	 * @param CommandSequence $sequence Command or snapshot sequence used as a boundary.
	 * @param array<string,bool|int|float|string|null> $actions Device-independent named action values.
	 */
	public function __construct(
		/** Player slot that originated the command. */
		public PlayerSlot $player
		, /** Simulation tick represented by the record. */
		public SimulationTick $tick
		, /** Monotonic command or snapshot sequence. */
		public CommandSequence $sequence
		, array $actions
	){
		foreach($actions as $name => $value)
		{
			if(!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $name))
			throw new \InvalidArgumentException('Input action names must be stable identifiers');

			if(!is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value) && $value !== null)
			throw new \InvalidArgumentException("Input action $name must be scalar or null");

			if(is_float($value) && !is_finite($value))
			throw new \InvalidArgumentException("Input action $name must be finite");
		}

		ksort($actions);
		json_encode($actions, JSON_THROW_ON_ERROR);
		$this->actions = $actions;
	}

	/**
	 * Restore an input command from transport-safe fields.
	 *
	 * @param array $value Scalar value or serialized fields to validate.
	 * @return self A validated value restored from serialized fields.
	 */
	public static function fromArray(array $value): self
	{
		if(!is_array($value['actions'] ?? null)) throw new \InvalidArgumentException('Input command has no actions');

		return new self(
			new PlayerSlot((int)($value['player'] ?? 0))
			, new SimulationTick((int)($value['tick'] ?? -1))
			, new CommandSequence((int)($value['sequence'] ?? -1))
			, $value['actions']
		);
	}

	/**
	 * Read every normalized action carried by this command.
	 *
	 * @return array<string,bool|int|float|string|null> All normalized action values keyed by action name.
	 */
	public function actions(): array { return $this->actions; }

	/**
	 * Read one named action with a caller-supplied fallback.
	 *
	 * @param string $name Stable action name to read.
	 * @param mixed $default Fallback returned when the action is absent.
	 * @return mixed The stored action value or the supplied fallback.
	 */
	public function action(string $name, $default = null)
	{
		return $this->actions[$name] ?? $default;
	}

	/**
	 * Serialize the input command into transport-safe fields.
	 *
	 * @return array Scalar fields suitable for JSON transport.
	 */
	public function jsonSerialize(): array
	{
		return [
			'player' => $this->player->value()
			, 'tick' => $this->tick->value()
			, 'sequence' => $this->sequence->value()
			, 'actions' => $this->actions
		];
	}
}
