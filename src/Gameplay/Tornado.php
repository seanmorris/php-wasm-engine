<?php
declare(strict_types=1);
namespace PhpWasmEngine\Gameplay;

use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\Physics\Body;

/** Fuelled two-dimensional aircraft with MB2-compatible takeoff, thrust, gear, pitch, and stall controls. */
final class Tornado implements VehicleBehavior
{
	public bool $flying = false, $thrusting = false, $landingGear = true, $crashed = false;
	public float $fuel = 100, $pitch = -.26;
	private float $refillDelay = 0, $noThrust = 0, $incomingSpeed = 0;

	/**
	 * Retain the annotated artwork GIDs used for the aircraft's observable states.
	 *
	 * @param array<string,int> $gids Tile GIDs keyed by tornado, tornado-flight, tornado-gear,
	 * tornado-thrust, and tornado-crashed class names.
	 * @return void Creates a fully fuelled aircraft with its landing gear extended.
	 */
	public function __construct(private array $gids = []) {}

	/**
	 * Create an aircraft chassis with reference speeds converted from sixty-hertz frame units.
	 *
	 * @param float $x Chassis center X coordinate.
	 * @param float $y Chassis center Y coordinate.
	 * @return Body Wide aircraft collision body suitable for swept terrain movement.
	 */
	public static function body(float $x = 0, float $y = 0): Body
	{
		$body = new Body($x, $y);
		$body->radius = 42;
		$body->halfHeight = $body->standingHeight = $body->rollingHeight = 18;
		$body->acceleration = 900;
		$body->braking = 1800;
		$body->friction = 480;

		$body->airAcceleration = 0;
		$body->gravity = 1800;
		$body->jumpSpeed = 360;
		$body->maxSpeed = 300;
		$body->terminalSpeed = 1200;

		return $body;
	}

	/**
	 * Transfer a rider's forward momentum and reset transient flight state when boarding.
	 *
	 * @param Vehicle $vehicle Newly occupied Tornado.
	 * @param Body $rider Character retaining its incoming velocity.
	 * @return void Initializes ground speed, gear, fuel timers, and presentation.
	 */
	public function mounted(Vehicle $vehicle, Body $rider): void
	{
		$body = $vehicle->body;
		$body->groundSpeed = $rider->vx;
		$this->flying = !$body->grounded;
		$this->thrusting = $this->crashed = false;
		$this->landingGear = true;
		$this->pitch = -.26 * $body->facing;
		$this->refillDelay = $this->noThrust = 0;

		$this->present($vehicle);
	}

	/**
	 * Apply takeoff, landing gear, rocket thrust, fuel, pitch, and stall controls before collision.
	 *
	 * @param Vehicle $vehicle Aircraft receiving controls.
	 * @param Controls $input Logical keyboard or gamepad controls.
	 * @param Combo|null $combo Rider reward chain, unused by flight.
	 * @param float $dt Positive simulation seconds.
	 * @return void Mutates aircraft velocity and tuning before the shared swept movement step.
	 */
	public function control(Vehicle $vehicle, Controls $input, ?Combo $combo, float $dt): void
	{
		$body = $vehicle->body;
		$wasThrusting = $this->thrusting;
		$this->noThrust = max(0, $this->noThrust - $dt);
		$this->incomingSpeed = hypot($body->vx, $body->vy);
		$this->thrusting = false;

		if(!$input->down(2)) $this->crashed = false;

		if($vehicle->occupant === null)
		{
			$this->refill($dt);

			return;
		}

		if(!$body->grounded && $input->pressed(1)) $this->landingGear = !$this->landingGear;

		$rocket = $input->down(2) && $this->fuel > ($wasThrusting ? 0 : 1)
			&& !$this->crashed && $this->noThrust <= 0;

		if($body->grounded)
		{
			$this->flying = false;
			$this->landingGear = true;
			$this->crashed = false;
			$this->pitch = -.26 * $body->facing;

			$body->gravity = 1800;
			$body->maxSpeed = 300;
			$this->refill($dt);

			if($rocket)
			{
				$body->grounded = false;
				$body->jumping = true;
				$body->vx = $body->facing * max(240, abs($body->groundSpeed));
				$body->vy = -180;
				$this->flying = true;
				$this->landingGear = false;
			}
		}

		if(!$body->grounded)
		{
			$this->flying = true;
			$direction = $body->facing ?: ($body->vx <=> 0) ?: 1;
			$body->facing = $body->direction = $direction;
			$speed = abs($body->vx);

			if($rocket)
			{
				$this->thrusting = true;
				$this->landingGear = false;
				$this->fuel = max(0, $this->fuel - 6 * $dt);
				$this->refillDelay = .5;
				$speed = min(600, $speed + 14400 * $dt);
			}
			else
			{
				$this->refill($dt);

				if($speed > 300 && !$input->axis('left', 'right')) $speed *= .95 ** (60 * $dt);
			}

			$axis = $input->axis('up', 'down');
			$this->pitch += $axis * 2.1 * $dt * $direction;

			if(!$axis)
			{
				$this->pitch += .6 * $dt * $direction;
			}

			if($this->landingGear) $this->pitch += .3 * $dt * $direction;

			$this->pitch = max(-1.35, min(1.35, $this->pitch * $direction)) * $direction;
			$stalling = $speed < 180 && !$this->thrusting;

			$body->gravity = $stalling ? 1800 : 0;
			$body->maxSpeed = $this->thrusting ? 600 : 480;
			$body->vx = $direction * min(600, $speed);

			if(!$stalling)
			{
				$body->vy = sin($this->pitch) * abs($body->vx) * $direction * 1.6;
			}
		}

		$this->present($vehicle);
	}

	/**
	 * Detect landing or a stopped airborne impact and publish final camera and artwork state.
	 *
	 * @param Vehicle $vehicle Aircraft after shared terrain collision.
	 * @param Combo|null $combo Rider reward chain, unused by flight.
	 * @param float $dt Positive simulation seconds.
	 * @return void Resolves landing, crash cooldown, camera mode, pose, and sprite selection.
	 */
	public function updated(Vehicle $vehicle, ?Combo $combo, float $dt): void
	{
		$body = $vehicle->body;

		if($body->grounded)
		{
			$this->flying = $this->thrusting = $this->crashed = false;
			$this->landingGear = true;
			$this->pitch = -.26 * $body->facing;
			$body->gravity = 1800;
			$body->maxSpeed = 300;
		}
		elseif($this->incomingSpeed > 240 && hypot($body->vx, $body->vy) < 30)
		{
			$this->crashed = true;
			$this->thrusting = false;
			$this->noThrust = .5;
			$body->gravity = 1800;
		}

		$this->present($vehicle);
	}

	/**
	 * Restore fuel after the reference half-second delay.
	 *
	 * @param float $dt Positive simulation seconds.
	 * @return void Advances the delay or replenishes fuel at fifteen units per second.
	 */
	private function refill(float $dt): void
	{
		$this->refillDelay = max(0, $this->refillDelay - $dt);

		if($this->refillDelay <= 0) $this->fuel = min(100, $this->fuel + 15 * $dt);
	}

	/**
	 * Synchronize vehicle-facing presentation with current flight state.
	 *
	 * @param Vehicle $vehicle Aircraft receiving camera, pose, angle, and annotated art state.
	 * @return void Selects an available state GID with graceful fallback to the base tile.
	 */
	private function present(Vehicle $vehicle): void
	{
		$state = $this->crashed
			? 'tornado-crashed'
			: ($this->thrusting
				? 'tornado-thrust'
				: ($this->flying ? ($this->landingGear ? 'tornado-gear' : 'tornado-flight') : 'tornado')
			);

		$vehicle->pose = $state;
		$vehicle->cameraMode = $this->flying ? 'airplane' : 'auto';
		$vehicle->visualAngle = $this->flying ? $this->pitch : 0;
		$vehicle->spriteGid = $this->gids[$state] ?? $this->gids['tornado'] ?? $vehicle->spriteGid;
	}
}
