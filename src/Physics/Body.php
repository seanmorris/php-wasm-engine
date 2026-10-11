<?php
declare(strict_types=1);
namespace PhpWasmEngine\Physics;
final class Body
{
	public float $vx = 0,$vy = 0,$groundSpeed = 0,$angle = 0;
	public float $radius = 8,$halfHeight = 18,$standingHeight = 18,$rollingHeight = 12;
	public bool $grounded = false,$rolling = false;
	public int $facing = 1,$wall = 0;
	public string $ability = '';
	public float $charge = 0,$dropCharge = 0,$flightRemaining = 5.8,$dropTimer = 0,$coyote = 0,$jumpBuffer = 0;
	public float $acceleration = 432,$braking = 1440,$friction = 360,$airAcceleration = 288,$gravity = 1800,$jumpSpeed = 660,$maxSpeed = 1080,$terminalSpeed = 1800;
	public function __construct(public float $x = 0,public float $y = 0,public string $profile = 'runner')
	{
		if(!in_array($profile,['runner','glider','flyer'],true))
		{ throw new \InvalidArgumentException('Unknown movement profile'); }
		if($profile === 'glider')
		{ $this->jumpSpeed = 570; }
	}
}
