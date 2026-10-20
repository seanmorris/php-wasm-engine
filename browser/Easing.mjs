/**
 * Move a value toward a target with a frame-rate-independent, non-overshooting response.
 *
 * A response of 20 reaches 90 percent of a new target in roughly 115 milliseconds: quick enough
 * for menu navigation while leaving enough motion for a report or list to read as scrolling.
 */
export function snappyApproach(value, target, seconds, response = 20, epsilon = .05)
{
	const elapsed = Math.max(0, Number(seconds) || 0);
	const stiffness = Math.max(0, Number(response) || 0);
	const blend = 1 - Math.exp(-stiffness * elapsed);
	const next = value + (target - value) * blend;

	return Math.abs(target - next) < epsilon ? target : next;
}
