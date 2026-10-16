<?php
declare(strict_types=1);
namespace PhpWasmEngine\Physics;

final class Body
{
	public float $vx = 0,$vy = 0,$groundSpeed = 0,$angle = 0;
	public float $radius = 8,$halfHeight = 18,$standingHeight = 18,$rollingHeight = 12;
	public bool $grounded = false,$rolling = false,$jumping = false,$crouching = false,$grinding = false;
	public bool $underwater = false,$waterEntered = false,$waterExited = false,$skimming = false;
	/** Horizontal look direction used by sprite rendering and facing-relative controls. */
	public int $facing = 1;
	/** Retained movement orientation used by surface travel and facing-relative actions. */
	public int $direction = 1;
	/** Requested glide steering, retained through neutral input independently of current travel. */
	public int $glideDirection = 0;
	public int $wall = 0;
	/** Zero is shared terrain; positive numbers select an additional collision plane. */
	public int $collisionLayer = 1;
	public string $ability = '';
	/** Retains light-dash jump-release suppression until landing or an explicit player reset. */
	public bool $lightDashed = false;
	public float $charge = 0,$crouchTime = 0,$dropCharge = 0,$flightRemaining = 5.8,$dropTimer = 0
		,$coyote = 0,$jumpBuffer = 0;
	public float $acceleration = 432,$braking = 1440,$friction = 360,$airAcceleration = 288
		,$gravity = 1800,$jumpSpeed = 660,$maxSpeed = 1080,$terminalSpeed = 1800;
	public float $minGrindSpeed = 360,$maxGrindSpeed = 2400,$grindAcceleration = 360;
	public float $density = 0,$waterDepth = 0;
	public ?WaterRegion $water = null;

	/**
	 * Create a mutable platformer body with default dimensions and movement tuning.
	 *
	 * @param float $x Body-center world X coordinate in pixels.
	 * @param float $y Body-center world Y coordinate in pixels.
	 * @param string $profile Movement profile: runner, glider, or flyer; unknown names are
	 * rejected.
	 * @return void Initializes an airborne actor on collision layer 1.
	 */
	public function __construct(public float $x = 0, public float $y = 0, public string $profile = 'runner')
	{
		if(!in_array($profile, ['runner','glider','flyer'], true))
		{
			throw new \InvalidArgumentException('Unknown movement profile');
		}

		if($profile === 'glider')
		{
			$this->jumpSpeed = 570;
		}
	}

	/**
	 * Resolve the world X coordinate where the body meets its current support plane.
	 *
	 * @return float Foot or surface-contact X coordinate after applying body angle and height.
	 */
	public function supportX(): float
	{
		return $this->x - sin($this->angle) * $this->halfHeight;
	}

	/**
	 * Resolve the world Y coordinate where the body meets its current support plane.
	 *
	 * @return float Foot or surface-contact Y coordinate after applying body angle and height.
	 */
	public function supportY(): float
	{
		return $this->y + cos($this->angle) * $this->halfHeight;
	}
}
