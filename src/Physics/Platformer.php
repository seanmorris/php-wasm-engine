<?php
declare(strict_types=1);
namespace PhpWasmEngine\Physics;
use PhpWasmEngine\Input\Controls;

/** Fresh surface-tangent controller. Distances are pixels, time is seconds. */
final class Platformer
{
	private ?\Closure $onImpact;

	/**
	 * Bind surface-tangent movement to a terrain collision source.
	 *
	 * @param Terrain $terrain Collision terrain and directional gates used by movement and sensor
	 * casts.
	 * @param callable(Body,Hit):mixed|null $onImpact Callback before collision response; removing
	 * the contacted shape allows remaining movement. The callback's return value is ignored.
	 * @return void Creates a controller that can update multiple independent bodies.
	 */
	public function __construct(public Terrain $terrain, ?callable $onImpact = null)
	{
		$this->onImpact = $onImpact === null ? null : \Closure::fromCallable($onImpact);
	}

	/**
	 * Move a scalar toward a target without overshooting.
	 *
	 * @param float $value Current scalar value.
	 * @param float $target Desired scalar value.
	 * @param float $step Nonnegative maximum change for this update.
	 * @return float Value after applying at most the supplied step.
	 */
	private static function approach(float $value, float $target, float $step): float
	{
		return $value < $target?min($target, $value + $step):max($target, $value - $step);
	}

	/**
	 * Advance one actor through controls, abilities, continuous collision, and surface adhesion.
	 *
	 * @param Body $b Actor to simulate in place.
	 * @param Controls $input Current logical controls and button edges.
	 * @param float $dt Finite, positive simulation delta in seconds.
	 * @return void Mutates pose, momentum, collision layer, and ability state on the supplied body.
	 */
	public function step(Body $b, Controls $input, float $dt): void
	{
		if($dt <= 0 || !is_finite($dt))
		{
			throw new \InvalidArgumentException('Invalid physics step');
		}

		$axis = $input->axis('left', 'right');

		if($axis)
		{
			$b->facing = $axis <=> 0;
		}

		// Retry blocked gates while overlapping, including actors at rest.
		$seen = [];

		while($gate = $this->terrain->nextSwitch($b, 0, 0, $seen))
		{
			$seen[$gate['id']] = true;
			$this->terrain->applySwitch($b, $gate['switch']);
		}

		$b->dropTimer = max(0, $b->dropTimer - $dt);
		$b->jumpBuffer = max(0, $b->jumpBuffer - $dt);
		$b->coyote = $b->grounded?.08:max(0, $b->coyote - $dt);

		if($input->pressed('jump'))
		{
			$b->jumpBuffer = .1;
		}

		if($b->grounded)
		{
			$b->flightRemaining = 5.8;
			$b->ability = '';
		}

		if($b->ability === 'climb')
		{
			$b->crouching = false;
			$b->charge = 0;
			$b->crouchTime = 0;
			$this->climb($b, $input, $dt);

			return;
		}

		if($b->grounded)
		{
			$this->groundPose($b, $input, $dt);

			if(!$b->charge)
			{
				$b->groundSpeed += sin($b->angle) * $b->gravity * ($b->rolling?.75:.35) * $dt;

				if($axis && !$b->rolling)
				{
					$accel = $axis * $b->groundSpeed < 0?$b->braking:$b->acceleration;

					// Input cannot erase momentum already above the running cap.
					if(abs($b->groundSpeed) < $b->maxSpeed || $axis * $b->groundSpeed < 0)
					{
						$b->groundSpeed = self::approach($b->groundSpeed, $axis * $b->maxSpeed, $accel * $dt);
					}
				}
				else
				{
					$b->groundSpeed = self::approach(
						$b->groundSpeed
						, 0
						, ($b->rolling?$b->friction * .12:$b->friction) * $dt
					);
				}
			}

			if($b->rolling && abs($b->groundSpeed) < 60 && !$input->down('down'))
			{
				$this->stand($b);
			}

			$this->height($b);
			$b->vx = cos($b->angle) * $b->groundSpeed;
			$b->vy = sin($b->angle) * $b->groundSpeed;

			// Slow actors lose adhesion on walls/ceilings. Fast actors retain tangent motion.
			if(cos($b->angle) < .5 && abs($b->groundSpeed) < 180)
			{
				$b->grounded = false;
				$b->coyote = 0;
			}
		}

		$jumped = false;

		if($b->jumpBuffer > 0 && $b->coyote > 0)
		{
			$platform = $this->terrain->raycast(
				$b->x
				, $b->y
				, -sin($b->angle) * ($b->halfHeight + 1)
				, cos($b->angle) * ($b->halfHeight + 1)
				, false
				, $b->collisionLayer
			);

			$spindashTap = $input->down('down') && $input->pressed('jump') && $b->crouching
				&& !($platform?->oneWay ?? false);

			if($input->down('down') && $platform?->oneWay)
			{
				$b->dropTimer = .25;
				$b->grounded = false;
				$b->y += .2;
			}
			elseif(!$spindashTap)
			{
				$b->vx += sin($b->angle) * $b->jumpSpeed;
				$b->vy -= cos($b->angle) * $b->jumpSpeed;
				$b->grounded = false;
				$b->rolling = false;
				$b->crouching = false;
				$b->charge = 0;
				$b->crouchTime = 0;
				$b->x += sin($b->angle) * .05;
				$b->y -= cos($b->angle) * .05;
			}

			$b->jumpBuffer = 0;
			$b->coyote = 0;
			$jumped = !$spindashTap;
		}

		if(!$b->grounded)
		{
			$b->crouching = false;
			$b->charge = 0;
			$b->crouchTime = 0;

			if($input->released('jump') && $b->vy < -240 && $b->ability === '')
			{
				$b->vy = -240;
			}

			if(!$jumped && $input->pressed('jump'))
			{
				if($b->profile === 'flyer' && $b->flightRemaining > 0)
				{
					$b->ability = 'fly';
					$b->vy = -120;
				}

				if($b->profile === 'glider')
				{
					$b->ability = 'glide';
					$b->vx = $b->facing * max(240, abs($b->vx));
					$b->vy = max(0, $b->vy);
				}
			}

			if($b->profile === 'runner' && $input->down('action'))
			{
				$b->dropCharge = min(1, $b->dropCharge + $dt * 2);
			}

			$gravity = $b->gravity;

			if($b->ability === 'fly')
			{
				$b->flightRemaining = max(0, $b->flightRemaining - $dt);
				$gravity *= .125;

				if($input->down('jump') && $b->flightRemaining > 0)
				{
					$b->vy = max(-180, $b->vy - 600 * $dt);
				}

				if($b->flightRemaining <= 0 || $input->down('down'))
				{
					$b->ability = '';
				}
			}
			elseif($b->ability === 'glide')
			{
				$gravity *= .125;
				$b->vx = self::approach($b->vx, $b->facing * 420, 240 * $dt);
				$b->vy = min(100, $b->vy);

				if($input->down('down'))
				{
					$b->ability = '';
				}
			}
			elseif(abs($b->vx) < $b->maxSpeed || $axis * $b->vx < 0)
			{
				$b->vx += $axis * $b->airAcceleration * $dt;
			}

			$b->vy = min($b->terminalSpeed, $b->vy + $gravity * $dt);
		}

		$wasGrounded = $b->grounded;

		if($wasGrounded)
		{
			// Arc increments control curvature. Every increment remains a continuous cast.
			$steps = max(1, min(1024, (int)ceil(abs($b->groundSpeed) * $dt / 4)));

			for($i = 0; $i < $steps; $i++)
			{
				if($b->grounded)
				{
					$b->vx = cos($b->angle) * $b->groundSpeed;
					$b->vy = sin($b->angle) * $b->groundSpeed;
				}

				$this->sweep($b, $b->vx * $dt / $steps, $b->vy * $dt / $steps);

				if($b->grounded)
				{
					$this->adhere($b);
				}
			}
		}
		else
		{
			$this->sweep($b, $b->vx * $dt, $b->vy * $dt);
		}

		if(!$wasGrounded && $b->grounded)
		{
			$b->groundSpeed = $b->vx * cos($b->angle) + $b->vy * sin($b->angle);

			if($b->dropCharge > 0)
			{
				$b->groundSpeed += $b->facing * (360 + 480 * $b->dropCharge);
				$b->rolling = true;
				$b->dropCharge = 0;
			}

			$b->ability = '';
			$b->charge = 0;
		}

		if(!$b->grounded)
		{
			$b->angle = 0;
		}
	}

	/**
	 * Select standing, crouching, rolling, or charged-spindash state on a surface.
	 *
	 * @param Body $b Grounded actor whose pose and tangent speed are updated.
	 * @param Controls $input Logical directions plus jump taps and held action input.
	 * @param float $dt Simulation delta in seconds used for passive charge decay.
	 * @return void Retains charge while Down stays held and launches it when Down is released.
	 */
	private function groundPose(Body $b, Controls $input, float $dt): void
	{
		$down = $input->down('down');
		$b->crouching = false;

		if($down && abs($b->groundSpeed) > 100)
		{
			$b->rolling = true;
			$b->charge = 0;
			$b->crouchTime = 0;

			return;
		}

		if(!$down)
		{
			$b->crouchTime = 0;

			if($b->charge > 0)
			{
				$power = max(.375, min(1, $b->charge));
				$b->groundSpeed = $b->facing * (480 + 960 * $power);
				$b->rolling = true;
				$b->charge = 0;
			}

			return;
		}

		if($b->rolling || abs($b->groundSpeed) >= 1)
		{
			$b->charge = 0;
			$b->crouchTime = 0;

			return;
		}

		$b->crouching = true;
		$b->groundSpeed = 0;

		if($input->pressed('jump') || $input->pressed('action'))
		{
			$b->charge = min(1, $b->charge + .25);
		}

		if($input->down('action'))
		{
			$b->charge = min(1, $b->charge + 1.5 * $dt);
		}

		$b->charge = max(0, $b->charge - .3 * $dt);
		$b->crouchTime = $b->charge > 0 ? 0 : $b->crouchTime + $dt;
	}

	/**
	 * Apply rolling or standing height while preserving the contact point along the body normal.
	 *
	 * @param Body $b Actor whose rolling flag selects the desired height.
	 * @return void Moves the body center and updates its half-height.
	 */
	private function height(Body $b): void
	{
		$height = $b->rolling?$b->rollingHeight:$b->standingHeight;
		$b->y += ($b->halfHeight - $height) * cos($b->angle);
		$b->x -= ($b->halfHeight - $height) * sin($b->angle);
		$b->halfHeight = $height;
	}

	/**
	 * Leave rolling mode when a head-clearance ray finds room to stand.
	 *
	 * @param Body $b Actor whose current head position and standing height are tested.
	 * @return void Clears the rolling flag only when the selected collision plane permits standing.
	 */
	private function stand(Body $b): void
	{
		$extra = ($b->standingHeight - $b->halfHeight) * 2;

		if(!$this->terrain->raycast($b->x, $b->y - $b->halfHeight, 0, -$extra, true, $b->collisionLayer))
		{
			$b->rolling = false;
		}
	}

	/**
	 * Resolve the earliest terrain impact or gate crossing over the full remaining motion.
	 * Expanded segment endpoints close sensor gaps; unresolved motion after six contacts is
	 * discarded. Removed shapes preserve momentum and do not consume that contact budget;
	 * at most 1024 removals are processed per sweep to bound custom impact callbacks.
	 *
	 * @param Body $b Actor whose oriented bounds and collision layer participate in each cast.
	 * @param float $dx Full proposed horizontal displacement in pixels.
	 * @param float $dy Full proposed vertical displacement in pixels.
	 * @return void Moves the body, switches eligible routes, and clips momentum against contact
	 * normals.
	 */
	private function sweep(Body $b, float $dx, float $dy): void
	{
		$seen = [];
		$contacts = 0;
		$removed = 0;

		while($contacts < 6 && $removed < 1024 && abs($dx) + abs($dy) > 1e-7)
		{
			$best = $this->terrain->castBody($b, $dx, $dy, $b->dropTimer > 0);
			$gate = $this->terrain->nextSwitch($b, $dx, $dy, $seen);

			if($gate && (!$best || $gate['t'] < $best->t))
			{
				$b->x += $dx * $gate['t'];
				$b->y += $dy * $gate['t'];
				$dx *= 1 - $gate['t'];
				$dy *= 1 - $gate['t'];
				$seen[$gate['id']] = true;
				$this->terrain->applySwitch($b, $gate['switch']);
				// Recast the unconsumed motion against the newly selected plane.
				continue;
			}

			if(!$best)
			{
				$b->x += $dx;
				$b->y += $dy;

				return;
			}

			if($this->onImpact !== null)
			{
				$shape = $best->shape;
				$t = $best->t;
				($this->onImpact)($b, $best);

				if($shape !== null && !$this->terrain->hasShape($shape))
				{
					// Advance to the removed obstacle, then sweep the rest against live geometry.
					$b->x += $dx * $t;
					$b->y += $dy * $t;
					$dx *= 1 - $t;
					$dy *= 1 - $t;
					$removed++;
					continue;
				}
			}

			$contacts++;
			$b->x += $dx * $best->t + $best->nx * .001;
			$b->y += $dy * $best->t + $best->ny * .001;
			$floor = $best->ny < -.25;

			if($floor || ($b->grounded && ($best->nx * sin($b->angle) - $best->ny * cos($b->angle)) > .5
			)){
				$b->grounded = true;
				$b->angle = atan2($best->nx, -$best->ny);
				$distance = ($b->x - $best->x) * $best->nx + ($b->y - $best->y) * $best->ny;
				$b->x += $best->nx * ($b->halfHeight + .001 - $distance);
				$b->y += $best->ny * ($b->halfHeight + .001 - $distance);
			}
			elseif(abs($best->nx) > .8)
			{
				if($b->ability === 'glide')
				{
					$b->ability = 'climb';
					$b->wall = (int)-$best->nx;
					$b->vx = $b->vy = 0;

					return;
				}

				$b->groundSpeed = 0;
			}

			$remaining = 1 - $best->t;
			$dx *= $remaining;
			$dy *= $remaining;
			$into = $dx * $best->nx + $dy * $best->ny;
			$dx -= $into * $best->nx;
			$dy -= $into * $best->ny;
			$velocity = $b->vx * $best->nx + $b->vy * $best->ny;

			if($velocity < 0)
			{
				$b->vx -= $velocity * $best->nx;
				$b->vy -= $velocity * $best->ny;
			}
		}
	}

	/**
	 * Cast short inward foot rays to maintain floor, wall, or ceiling attachment.
	 *
	 * @param Body $b Grounded actor whose pose and surface angle are updated.
	 * @return void Aligns the actor to the nearest support, or releases it when both sensors miss.
	 */
	private function adhere(Body $b): void
	{
		$ca = cos($b->angle);
		$sa = sin($b->angle);
		$hits = [];

		foreach([-$b->radius * .6,$b->radius * .6] as $side)
		{
			$x = $b->x + $side * $ca - ($b->halfHeight - 6) * $sa;
			$y = $b->y + $side * $sa + ($b->halfHeight - 6) * $ca;

			$hit = $this->terrain->raycast($x, $y, -$sa * 16, $ca * 16, $b->dropTimer > 0, $b->collisionLayer);

			if($hit)
			{
				$hits[] = $hit;
			}
		}

		if(!$hits)
		{
			$b->grounded = false;

			return;
		}

		$distance = min(array_map(
			/**
			 * Convert a foot-ray contact fraction to distance from the nominal sole.
			 *
			 * @param Hit $h Contact on a 16-pixel inward ray starting six pixels above the sole.
			 * @return float Signed support correction in pixels.
			 */
			fn($h) => $h->t * 16 - 6
			, $hits
		));

		$b->x -= $sa * $distance;
		$b->y += $ca * $distance;

		if(count($hits) === 2)
		{
			$b->angle = atan2($hits[1]->y - $hits[0]->y, $hits[1]->x - $hits[0]->x);
		}
		else
		{
			$b->angle = atan2($hits[0]->nx, -$hits[0]->ny);
		}
	}

	/**
	 * Advance a glider along a caught wall, handling jumps and the wall's end.
	 *
	 * @param Body $b Actor currently using the climb ability.
	 * @param Controls $input Logical climb and jump controls.
	 * @param float $dt Simulation delta in seconds.
	 * @return void Updates the actor's pose, velocity, and climb ability state.
	 */
	private function climb(Body $b, Controls $input, float $dt): void
	{
		if($input->pressed('jump'))
		{
			$b->ability = '';
			$b->vx = -$b->wall * 300;
			$b->vy = -$b->jumpSpeed * .8;
			$b->x -= $b->wall * .1;

			return;
		}

		$dy = $input->axis('up', 'down') * 240 * $dt;
		$b->vx = 0;
		$b->vy = $dy / $dt;
		$this->sweep($b, 0, $dy);

		$hit = $this->terrain->raycast($b->x, $b->y, $b->wall * ($b->radius + 3), 0, true, $b->collisionLayer);

		if(!$hit)
		{
			$b->ability = '';
			$b->vx = $b->wall * 120;
			$b->vy = -180;
		}
	}
}
