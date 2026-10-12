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
	 * @param array<string,bool|int|float> $keys Named button states or analog strengths for this step.
	 * @return void Moves current values to previous and installs the supplied snapshot.
	 */
	public function set(array $keys): void
	{ $this->previous = $this->current;
	$this->current = $keys; }
	/**
	 * Read a button strength or directional magnitude, clamped to [0,1].
	 *
	 * @param string $key Logical control name to read.
	 * @return float Analog strength; absent controls return zero.
	 */
	public function value(string $key): float { return max(0,min(1,(float)($this->current[$key] ?? 0))); }
	/**
	 * Check whether a logical control is currently held.
	 *
	 * @param string $key Logical control name to inspect.
	 * @return bool True for a nonzero current control value.
	 */
	public function down(string $key): bool { return (bool)($this->current[$key] ?? false); }
	/**
	 * Detect a transition from released to held in the current simulation step.
	 *
	 * @param string $key Logical control name to inspect.
	 * @return bool True only on the first held snapshot after a release.
	 */
	public function pressed(string $key): bool { return $this->down($key) && empty($this->previous[$key]); }
	/**
	 * Detect a transition from held to released in the current simulation step.
	 *
	 * @param string $key Logical control name to inspect.
	 * @return bool True only on the first released snapshot after a hold.
	 */
	public function released(string $key): bool { return !$this->down($key) && !empty($this->previous[$key]); }
	/**
	 * Combine opposing logical controls into a signed analog axis.
	 *
	 * @param string $negative Control contributing movement in the negative direction.
	 * @param string $positive Control contributing movement in the positive direction.
	 * @return float Positive strength minus negative strength, in [-1,1].
	 */
	public function axis(string $negative,string $positive): float { return $this->value($positive) - $this->value($negative); }
}
