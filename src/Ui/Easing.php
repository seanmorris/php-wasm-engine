<?php
declare(strict_types=1);
namespace Phonics\Ui;

/** Frame-rate-independent, non-overshooting motion for responsive UI positions. */
final class Easing
{

	/**
	 * Move a scalar toward its target with a quick exponential response and a stable resting point.
	 *
	 * At the default response, a value covers 90 percent of the remaining distance in roughly 115
	 * milliseconds. This makes navigation feel immediate without turning a scrolling list into a
	 * sequence of visual jumps.
	 *
	 * @param float $value Current displayed value.
	 * @param float $target Desired value.
	 * @param float $seconds Nonnegative elapsed seconds since the preceding presentation.
	 * @param float $response Nonnegative convergence rate per second.
	 * @param float $epsilon Positive distance under which to use the exact target.
	 * @return float Updated value, exactly equal to target once it is within epsilon.
	 */
	public static function snappy(
		float $value
		, float $target
		, float $seconds
		, float $response = 20
		, float $epsilon = .001
	): float {
		if(!is_finite($value) || !is_finite($target) || !is_finite($seconds)
			|| !is_finite($response) || !is_finite($epsilon) || $seconds < 0 || $response < 0
			|| $epsilon <= 0
		){
			throw new \InvalidArgumentException('UI easing requires finite nonnegative time and response');
		}

		$next = $value + ($target - $value) * (1 - exp(-$response * $seconds));

		return abs($target - $next) < $epsilon ? $target : $next;
	}
}
