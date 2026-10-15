<?php
declare(strict_types=1);
namespace PhpWasmEngine\Gameplay;

use PhpWasmEngine\Physics\Body;

/** A pressure switch that selects one target surface for a Tiled water object. */
final class WaterSwitch
{
	public bool $active = false;
	public int $entity = 0;

	/**
	 * Define a body-triggered water-level switch.
	 *
	 * @param int $sourceId Tiled switch object ID retained for scripts and diagnostics.
	 * @param int $waterId Referenced Tiled water object ID.
	 * @param float $targetY Referenced set-point Y coordinate in world pixels.
	 * @param float $x Left edge of the trigger rectangle in world pixels.
	 * @param float $y Bottom edge of the trigger rectangle in world pixels.
	 * @param float $width Positive trigger width in world pixels.
	 * @param float $height Positive trigger height in world pixels.
	 * @param float $fillSpeed Positive upward water speed in pixels per second.
	 * @param float $drainSpeed Positive downward water speed in pixels per second.
	 * @return void Initializes an inactive switch.
	 */
	public function __construct(
		public int $sourceId
		, public int $waterId
		, public float $targetY
		, public float $x
		, public float $y
		, public float $width
		, public float $height
		, public float $fillSpeed = 240
		, public float $drainSpeed = 480
	){
		foreach([$targetY, $x, $y, $width, $height, $fillSpeed, $drainSpeed] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Water switch values must be finite');
			}
		}

		if($sourceId < 1 || $waterId < 1 || $width <= 0 || $height <= 0
			|| $fillSpeed <= 0 || $drainSpeed <= 0
		){
			throw new \InvalidArgumentException('Invalid water switch');
		}
	}

	/**
	 * Test whether a grounded body's feet depress this switch.
	 *
	 * @param Body $body Candidate actor using center position and collision radii.
	 * @return bool True when the feet overlap the switch's horizontal and vertical bounds.
	 */
	public function pressedBy(Body $body): bool
	{
		$bottom = $body->y + $body->halfHeight;
		$top = $this->y - $this->height;

		return $body->grounded
			&& $body->x + $body->radius >= $this->x
			&& $body->x - $body->radius <= $this->x + $this->width
			&& $bottom >= $top - 4
			&& $bottom <= $this->y + 4;
	}
}
