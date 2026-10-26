<?php
declare(strict_types=1);
namespace Phonics\Input;

/** Physical buffering shared by render-frame look, fixed-step actions, and rebinding. */
final class ActionInput
{
	private array $held = [], $presses = [], $relative = [], $sources = [], $motion = [];
	private array $framePresses = [];

	/**
	 * Attach a profile to a physical input buffer.
	 *
	 * @param Bindings $bindings Editable action bindings.
	 * @return void Initializes an empty input buffer.
	 */
	public function __construct(public Bindings $bindings) {}

	/**
	 * Clear transient capture candidates at the start of an event poll.
	 *
	 * @return void Retains gameplay taps and displacement until consumed.
	 */
	public function beginPoll(): void { $this->motion = []; }

	/**
	 * Update held state and retain digital taps until a simulation consumes them.
	 *
	 * @param Source $source Physical button or directional axis half.
	 * @param float $value Current strength in [0,1].
	 * @return void Replaces the current strength and records fresh digital presses.
	 */
	public function hold(Source $source, float $value): void
	{
		$id = $source->id();
		$value = max(0, min(1, $value));

		if(!$source->axis() && $value > 0 && empty($this->held[$id]))
		{
			$this->presses[$id] = ($this->presses[$id] ?? 0) + 1;
			$this->framePresses[$id] = true;
			$this->motion[$id] = $source;
		}

		$this->held[$id] = $value;
		$this->sources[$id] = $source;
	}

	/**
	 * Record relative displacement with independent positive and negative halves.
	 *
	 * @param string $kind Mouse-axis or wheel source kind.
	 * @param int $axis Horizontal or vertical physical axis.
	 * @param float $value Signed physical displacement.
	 * @param bool $gameplay Whether this motion also contributes to gameplay.
	 * @return void Buffers displacement and records deliberate capture candidates.
	 */
	public function move(string $kind, int $axis, float $value, bool $gameplay = true): void
	{
		if(!is_finite($value) || $value == 0) return;

		$source = new Source($kind, $axis, $value < 0 ? -1 : 1);
		$id = $source->id();
		$this->sources[$id] = $source;

		if($gameplay)
		{
			$this->relative[$id] = ($this->relative[$id] ?? 0) + abs($value);

			if($kind === Source::WHEEL) $this->presses[$id] = ($this->presses[$id] ?? 0) + max(1, (int)abs($value));
			elseif(abs($value) >= 3) $this->presses[$id] = ($this->presses[$id] ?? 0) + 1;
		}

		if($kind === Source::WHEEL || abs($value) >= 3) $this->motion[$id] = $source;
	}

	/**
	 * Read current rates and consume relative displacement once per display frame.
	 *
	 * @return ActionFrame Current device strengths and accumulated displacement.
	 */
	public function frame(): ActionFrame
	{
		$held = $this->held;

		foreach($this->framePresses as $id => $_) $held[$id] = 1;
		$this->framePresses = [];
		$relative = $this->relative;
		$this->relative = [];

		return $this->bindings->sample($held, $relative);
	}

	/**
	 * Read held values and consume buffered taps once per fixed step.
	 *
	 * @return ActionFrame Current strengths, with short taps retained for this step.
	 */
	public function consume(): ActionFrame
	{
		$held = $this->held;

		foreach($this->presses as $id => $count) if($count > 0) $held[$id] = 1;
		$frame = $this->bindings->sample($held, [], $this->presses);
		$this->presses = [];

		foreach(array_keys($this->relative) as $id)
		if($this->sources[$id]->kind === Source::WHEEL)
		unset($this->relative[$id]);

		return $frame;
	}

	/**
	 * Read capture candidates while ignoring small stick deflections.
	 *
	 * @return list<Source> Fresh motion and deliberately held physical sources.
	 */
	public function candidates(): array
	{
		$candidates = $this->motion;

		foreach($this->held as $id => $value) if($value >= .6) $candidates[$id] = $this->sources[$id];

		return array_values($candidates);
	}

	/**
	 * Check whether buttons and deliberate stick deflections have been released.
	 *
	 * @return bool True when no retained strength exceeds the neutral threshold.
	 */
	public function neutral(): bool
	{
		foreach($this->held as $value) if($value > .2) return false;

		return true;
	}

	/**
	 * Release one source kind without undoing integrated camera motion.
	 *
	 * @param string $kind Physical source kind to release.
	 * @return void Removes held values, taps, displacement and capture candidates.
	 */
	public function release(string $kind): void
	{
		foreach($this->sources as $id => $source)
		if($source->kind === $kind)
		unset($this->held[$id], $this->presses[$id], $this->framePresses[$id], $this->relative[$id], $this->motion[$id]
			, $this->sources[$id]
		);
	}

	/**
	 * Release retained state on focus, ownership or map transitions.
	 *
	 * @return void Clears all physical buffers.
	 */
	public function reset(): void
	{
		$this->held = $this->presses = $this->framePresses = $this->relative = $this->sources = $this->motion = [];
	}

	/**
	 * Apply a radial dead zone and magnitude curve while preserving direction.
	 *
	 * @param float $x Raw horizontal stick value.
	 * @param float $y Raw vertical stick value.
	 * @param float $deadZone Radial neutral threshold in [0,1).
	 * @param float $exponent Positive response exponent.
	 * @return array{float,float} Filtered horizontal and vertical values.
	 */
	public static function stick(float $x, float $y, float $deadZone = .12, float $exponent = 1.5): array
	{
		if(!is_finite($x) || !is_finite($y) || !is_finite($deadZone) || !is_finite($exponent)
			|| $deadZone < 0 || $deadZone >= 1 || $exponent <= 0
		)
		throw new \InvalidArgumentException('Invalid stick response parameters');

		$length = hypot($x, $y);

		if($length <= $deadZone || $length === 0.0) return [0.0, 0.0];

		$scale = pow((min(1, $length) - $deadZone) / (1 - $deadZone), $exponent) / $length;

		return [$x * $scale, $y * $scale];
	}
}
