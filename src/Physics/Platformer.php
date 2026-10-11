<?php
declare(strict_types=1);
namespace PhpWasmEngine\Physics;
use PhpWasmEngine\Input\Controls;

/** Fresh surface-tangent controller. Distances are pixels, time is seconds. */
final class Platformer
{
	public function __construct(public Terrain $terrain) {}
	private static function approach(float $value,float $target,float $step): float { return $value < $target?min($target,$value + $step):max($target,$value - $step); }
	public function step(Body $b,Controls $input,float $dt): void
	{
		if($dt <= 0 || !is_finite($dt))
		{ throw new \InvalidArgumentException('Invalid physics step'); }
		$axis = $input->axis('left','right');
		if($axis)
		{ $b->facing = $axis; }
		$b->dropTimer = max(0,$b->dropTimer - $dt);
		$b->jumpBuffer = max(0,$b->jumpBuffer - $dt);
		$b->coyote = $b->grounded?.08:max(0,$b->coyote - $dt);
		if($input->pressed('jump'))
		{ $b->jumpBuffer = .1; }
		if($b->grounded)
		{ $b->flightRemaining = 5.8;
		$b->ability = ''; }

		if($b->ability === 'climb')
		{ $this->climb($b,$input,$dt);
		return; }
		if($b->grounded)
		{
			if($input->down('down') && abs($b->groundSpeed) > 100)
			{ $b->rolling = true; }
			if($b->profile === 'runner' && $input->down('down') && $input->down('action') && abs($b->groundSpeed) < 120)
			{
				$b->charge = min(1,$b->charge + $dt * 1.4);
				$b->groundSpeed = 0;
			} elseif($b->charge > 0)
			{ $b->groundSpeed = $b->facing * (480 + 960 * $b->charge);
			$b->rolling = true;
			$b->charge = 0; }
			if(!$b->charge)
			{
				$b->groundSpeed += sin($b->angle) * $b->gravity * ($b->rolling?.75:.35) * $dt;
				if($axis && !$b->rolling)
				{
					$accel = $axis * $b->groundSpeed < 0?$b->braking:$b->acceleration;
					// Input cannot erase momentum already above the running cap.
					if(abs($b->groundSpeed) < $b->maxSpeed || $axis * $b->groundSpeed < 0)
					{ $b->groundSpeed = self::approach($b->groundSpeed,$axis * $b->maxSpeed,$accel * $dt); }
				} else
				{ $b->groundSpeed = self::approach($b->groundSpeed,0,($b->rolling?$b->friction * .12:$b->friction) * $dt); }
			}
			if($b->rolling && abs($b->groundSpeed) < 60 && !$input->down('down'))
			{ $this->stand($b); }
			$this->height($b);
			$b->vx = cos($b->angle) * $b->groundSpeed;
			$b->vy = sin($b->angle) * $b->groundSpeed;
			// Slow actors lose adhesion on walls/ceilings. Fast actors retain tangent motion.
			if(cos($b->angle) < .5 && abs($b->groundSpeed) < 180)
			{ $b->grounded = false;
			$b->coyote = 0; }
		}
		$jumped = false;
		if($b->jumpBuffer > 0 && $b->coyote > 0)
		{
			$platform = $this->terrain->raycast($b->x,$b->y,-sin($b->angle) * ($b->halfHeight + 1),cos($b->angle) * ($b->halfHeight + 1));
			if($input->down('down') && $platform?->oneWay)
			{ $b->dropTimer = .25;
			$b->grounded = false;
			$b->y += .2; }
			else
			{
				$b->vx += sin($b->angle) * $b->jumpSpeed;
				$b->vy -= cos($b->angle) * $b->jumpSpeed;
				$b->grounded = false;
				$b->rolling = false;
				$b->x += sin($b->angle) * .05;
				$b->y -= cos($b->angle) * .05;
			}
			$b->jumpBuffer = 0;
			$b->coyote = 0;
			$jumped = true;
		}
		if(!$b->grounded)
		{
			if($input->released('jump') && $b->vy < -240 && $b->ability === '')
			{ $b->vy = -240; }
			if(!$jumped && $input->pressed('jump'))
			{
				if($b->profile === 'flyer' && $b->flightRemaining > 0)
				{ $b->ability = 'fly';
				$b->vy = -120; }
				if($b->profile === 'glider')
				{ $b->ability = 'glide';
				$b->vx = $b->facing * max(240,abs($b->vx));
				$b->vy = max(0,$b->vy); }
			}
			if($b->profile === 'runner' && $input->down('action'))
			{ $b->dropCharge = min(1,$b->dropCharge + $dt * 2); }
			$gravity = $b->gravity;
			if($b->ability === 'fly')
			{
				$b->flightRemaining = max(0,$b->flightRemaining - $dt);
				$gravity *= .125;
				if($input->down('jump') && $b->flightRemaining > 0)
				{ $b->vy = max(-180,$b->vy - 600 * $dt); }
				if($b->flightRemaining <= 0 || $input->down('down'))
				{ $b->ability = ''; }
			} elseif($b->ability === 'glide')
			{
				$gravity *= .125;
				$b->vx = self::approach($b->vx,$b->facing * 420,240 * $dt);
				$b->vy = min(100,$b->vy);
				if($input->down('down'))
				{ $b->ability = ''; }
			} elseif(abs($b->vx) < $b->maxSpeed || $axis * $b->vx < 0)
			{ $b->vx += $axis * $b->airAcceleration * $dt; }
			$b->vy = min($b->terminalSpeed,$b->vy + $gravity * $dt);
		}

		$wasGrounded = $b->grounded;
		if($wasGrounded)
		{
			// Arc increments control curvature. Every increment remains a continuous cast.
			$steps = max(1,min(1024,(int)ceil(abs($b->groundSpeed) * $dt / 4)));
			for($i = 0;$i < $steps;$i++)
			{
				if($b->grounded)
				{
					$b->vx = cos($b->angle) * $b->groundSpeed;
					$b->vy = sin($b->angle) * $b->groundSpeed;
				}
				$this->sweep($b,$b->vx * $dt / $steps,$b->vy * $dt / $steps);
				if($b->grounded)
				{ $this->adhere($b); }
			}
		}
		else
		{ $this->sweep($b,$b->vx * $dt,$b->vy * $dt); }
		if(!$wasGrounded && $b->grounded)
		{
			$b->groundSpeed = $b->vx * cos($b->angle) + $b->vy * sin($b->angle);
			if($b->dropCharge > 0)
			{ $b->groundSpeed += $b->facing * (360 + 480 * $b->dropCharge);
			$b->rolling = true;
			$b->dropCharge = 0; }
			$b->ability = '';
			$b->charge = 0;
		}
		if(!$b->grounded)
		{ $b->angle = 0; }
	}
	private function height(Body $b): void
	{ $height = $b->rolling?$b->rollingHeight:$b->standingHeight;
	$b->y += ($b->halfHeight - $height) * cos($b->angle);
	$b->x -= ($b->halfHeight - $height) * sin($b->angle);
	$b->halfHeight = $height; }
	private function stand(Body $b): void
	{
		$extra = ($b->standingHeight - $b->halfHeight) * 2;
		if(!$this->terrain->raycast($b->x,$b->y - $b->halfHeight,0,-$extra,true))
		{ $b->rolling = false; }
	}
	/** Swept body cast. Every ray spans the full remaining displacement.
     * Earliest time of impact wins; the remaining motion slides along that surface.
     * Expanded segment endpoints close the gaps between discrete perimeter sensors.
     */
	private function sweep(Body $b,float $dx,float $dy): void
	{
		for($iteration = 0;$iteration < 6 && abs($dx) + abs($dy) > 1e-7;$iteration++)
		{
			$best = $this->terrain->castBody($b,$dx,$dy,$b->dropTimer > 0);
			if(!$best)
			{ $b->x += $dx;
			$b->y += $dy;
			return; }
			$b->x += $dx * $best->t + $best->nx * .001;
			$b->y += $dy * $best->t + $best->ny * .001;
			$floor = $best->ny < -.25;
			if($floor || ($b->grounded && ($best->nx * sin($b->angle) - $best->ny * cos($b->angle)) > .5))
			{
				$b->grounded = true;
				$b->angle = atan2($best->nx,-$best->ny);
				$distance = ($b->x - $best->x) * $best->nx + ($b->y - $best->y) * $best->ny;
				$b->x += $best->nx * ($b->halfHeight + .001 - $distance);
				$b->y += $best->ny * ($b->halfHeight + .001 - $distance);
			} elseif(abs($best->nx) > .8)
			{
				if($b->ability === 'glide')
				{ $b->ability = 'climb';
				$b->wall = (int)-$best->nx;
				$b->vx = $b->vy = 0;
				return; }
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
			{ $b->vx -= $velocity * $best->nx;
			$b->vy -= $velocity * $best->ny; }
		}
	}
	/** Short inward rays keep two feet on the surface and allow floor/wall/ceiling transitions. */
	private function adhere(Body $b): void
	{
		$ca = cos($b->angle);
		$sa = sin($b->angle);
		$hits = [];
		foreach([-$b->radius * .6,$b->radius * .6] as $side)
		{
			$x = $b->x + $side * $ca - ($b->halfHeight - 6) * $sa;
			$y = $b->y + $side * $sa + ($b->halfHeight - 6) * $ca;
			$hit = $this->terrain->raycast($x,$y,-$sa * 16,$ca * 16,$b->dropTimer > 0);
			if($hit)
			{ $hits[] = $hit; }
		}
		if(!$hits)
		{ $b->grounded = false;
		return; }
		$distance = min(array_map(fn($h) => $h->t * 16 - 6,$hits));
		$b->x -= $sa * $distance;
		$b->y += $ca * $distance;
		if(count($hits) === 2)
		{ $b->angle = atan2($hits[1]->y - $hits[0]->y,$hits[1]->x - $hits[0]->x); }
		else
		{ $b->angle = atan2($hits[0]->nx,-$hits[0]->ny); }
	}
	private function climb(Body $b,Controls $input,float $dt): void
	{
		if($input->pressed('jump'))
		{ $b->ability = '';
		$b->vx = -$b->wall * 300;
		$b->vy = -$b->jumpSpeed * .8;
		$b->x -= $b->wall * .1;
		return; }
		$dy = $input->axis('up','down') * 240 * $dt;
		$b->vx = 0;
		$b->vy = $dy / $dt;
		$this->sweep($b,0,$dy);
		$hit = $this->terrain->raycast($b->x,$b->y,$b->wall * ($b->radius + 3),0,true);
		if(!$hit)
		{ $b->ability = '';
		$b->vx = $b->wall * 120;
		$b->vy = -180; }
	}
}
