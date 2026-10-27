<?php
declare(strict_types=1);
namespace PhpSlalom;

/** One colored tetrahedral gate moving from the horizon toward the player. */
final class Obstacle
{
	public float $previousX;
	public float $previousZ;

	/**
	 * @param array{float,float,float} $color Normalized base color.
	 */
	public function __construct(
		public float $x
		, public float $width
		, public float $z
		, public array $color
	){
		$this->previousX = $x;
		$this->previousZ = $z;
	}

	/** Retain the last fixed pose and move the obstacle with the course. */
	public function move(float $x, float $z): void
	{
		$this->previousX = $this->x;
		$this->previousZ = $this->z;
		$this->x += $x;
		$this->z += $z;
	}
}
