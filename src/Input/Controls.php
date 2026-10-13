<?php
declare(strict_types=1);
namespace PhpWasmEngine\Input;

final class Controls
{
	private array $current = [], $previous = [];

	/**
	 * Clear current and previous button states.
	 *
	 * @return void Removes held strengths and pending button edges.
	 */
	public function reset(): void { $this->current = $this->previous = []; }

	/**
	 * Advance the input snapshot by one simulation step.
	 *
	 * @param array<int|string,bool|int|float> $keys Button indices, actions or analog strengths.
	 * @return void Moves current values to previous and installs the supplied snapshot.
	 */
	public function set(array $keys): void
	{
		$this->previous = $this->current;
		$this->current = Buttons::normalize($keys);
	}

	/**
	 * Read a button strength or directional magnitude, clamped to [0,1].
	 *
	 * @param int|string $key Button index or logical control name to read.
	 * @return float Analog strength; absent controls return zero.
	 */
	public function value(int|string $key): float
	{
		return $this->current[Buttons::name($key)] ?? 0;
	}

	/**
	 * Check whether a logical control is currently held.
	 *
	 * @param int|string $key Button index or logical control name to inspect.
	 * @return bool True for a nonzero current control value.
	 */
	public function down(int|string $key): bool { return $this->value($key) > 0; }

	/**
	 * Detect a transition from released to held in the current simulation step.
	 *
	 * @param int|string $key Button index or logical control name to inspect.
	 * @return bool True only on the first held snapshot after a release.
	 */
	public function pressed(int|string $key): bool
	{
		return $this->down($key) && empty($this->previous[Buttons::name($key)]);
	}

	/**
	 * Detect a transition from held to released in the current simulation step.
	 *
	 * @param int|string $key Button index or logical control name to inspect.
	 * @return bool True only on the first released snapshot after a hold.
	 */
	public function released(int|string $key): bool
	{
		return !$this->down($key) && !empty($this->previous[Buttons::name($key)]);
	}

	/**
	 * Combine opposing logical controls into a signed analog axis.
	 *
	 * @param int|string $negative Control contributing movement in the negative direction.
	 * @param int|string $positive Control contributing movement in the positive direction.
	 * @return float Positive strength minus negative strength, in [-1,1].
	 */
	public function axis(int|string $negative, int|string $positive): float
	{
		return $this->value($positive) - $this->value($negative);
	}
}
