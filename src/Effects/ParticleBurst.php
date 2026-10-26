<?php
declare(strict_types=1);
namespace Phonics\Effects;

use Phonics\Render\Sprite;
use Phonics\Render\SpriteGroup;
use Phonics\Tiled\TileMap;

/** Cropped sprite shards with deterministic ballistic motion and a finite lifetime. */
final class ParticleBurst
{
	/** Elapsed burst lifetime in seconds. */
	public float $age = 0;

	/**
	 * Retain the pieces and their initial motion without allocating textures or entities.
	 *
	 * @param SpriteGroup $sprites Mutable group rendered on the owning entity.
	 * @param list<array{float,float,float,float}> $motion Per-piece velocity, spin, and opacity.
	 * @param float $gravity Downward acceleration in pixels per second squared.
	 * @param float $lifetime Positive duration in seconds before all pieces disappear.
	 * @return void Initializes an unadvanced burst with shared source artwork.
	 */
	private function __construct(
		public SpriteGroup $sprites
		, private array $motion
		, private float $gravity
		, private float $lifetime
	){
	}

	/**
	 * Freeze a sprite's current frame and partition its visible pixels into a grid of shards.
	 *
	 * @param Sprite $sprite Source pose, crop, flips, tint, and animation; never modified.
	 * @param TileMap $map Tilesets used to resolve the current image and its dimensions.
	 * @param int $columns Horizontal piece count, at most the visible source width in pixels.
	 * @param int $rows Vertical piece count, at most the visible source height in pixels.
	 * @param float $milliseconds Simulation time at which the source animation is frozen.
	 * @param float $speed Outward velocity scale in pixels per second, nonnegative.
	 * @param float $lift Initial upward velocity in pixels per second.
	 * @param float $gravity Downward acceleration in pixels per second squared.
	 * @param float $lifetime Positive duration in seconds, with opacity fading to zero.
	 * @return self Independent particles covering the source exactly at age zero.
	 */
	public static function grid(
		Sprite $sprite
		, TileMap $map
		, int $columns
		, int $rows
		, float $milliseconds = 0
		, float $speed = 240
		, float $lift = 240
		, float $gravity = 900
		, float $lifetime = 1.2
	): self {
		foreach([
			$milliseconds, $speed, $lift, $gravity, $lifetime
			, $sprite->x, $sprite->y, $sprite->rotation
			, $sprite->scaleX, $sprite->scaleY, $sprite->originX, $sprite->originY
			, $sprite->opacity, $sprite->time
		] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('Particle pose, timing, and motion must be finite');
			}
		}

		$gid = $sprite->frame($map, $milliseconds);
		$tile = $map->tile($gid) ?? throw new \OutOfBoundsException("Sprite GID $gid is missing");
		$rect = $sprite->rectangle($tile['rect']);
		[, , $width, $height] = $rect;
		$crop = $sprite->crop ?? [0, 0, $width, $height];

		if($columns < 1 || $rows < 1 || $columns > $width || $rows > $height
			|| $lifetime <= 0 || $speed < 0
		){
			throw new \InvalidArgumentException('Invalid particle grid, speed, or lifetime');
		}

		$pieces = [];
		$motion = [];
		$cos = cos($sprite->rotation);
		$sin = sin($sprite->rotation);

		for($row = 0; $row < $rows; $row++)
		{
			$top = (int)floor($row * $height / $rows);
			$bottom = (int)floor(($row + 1) * $height / $rows);

			for($column = 0; $column < $columns; $column++)
			{
				$left = (int)floor($column * $width / $columns);
				$right = (int)floor(($column + 1) * $width / $columns);
				$cx = ($left + $right) / 2;
				$cy = ($top + $bottom) / 2;
				$x = ($cx - $sprite->originX * $width) * $sprite->scaleX;
				$y = ($cy - $sprite->originY * $height) * $sprite->scaleY;

				$piece = clone $sprite;
				$piece->gid = $gid;
				$piece->animated = false;

				$piece->originX = $piece->originY = .5;
				$piece->x += $x * $cos - $y * $sin;
				$piece->y += $x * $sin + $y * $cos;
				$piece->crop = [
					$crop[0] + ($sprite->flipX ? $width - $right : $left)
					, $crop[1] + ($sprite->flipY ? $height - $bottom : $top)
					, $right - $left, $bottom - $top
				];

				$pieces[] = $piece;

				$vx = (2 * $cx / $width - 1) * $speed * ($sprite->scaleX < 0 ? -1 : 1);
				$vy = (2 * $cy / $height - 1) * $speed * ($sprite->scaleY < 0 ? -1 : 1);
				$motion[] = [
					$vx * $cos - $vy * $sin, $vx * $sin + $vy * $cos - $lift
					, (2 * $cx / $width - 1) * 8, $sprite->opacity
				];
			}
		}

		return new self(new SpriteGroup(...$pieces), $motion, $gravity, $lifetime);
	}

	/**
	 * Advance ballistic motion using exact constant-acceleration integration.
	 *
	 * @param float $dt Finite nonnegative simulation delta in seconds.
	 * @return void Updates piece transforms and fade, clearing all pieces at expiry.
	 */
	public function step(float $dt): void
	{
		if(!is_finite($dt) || $dt < 0)
		{
			throw new \InvalidArgumentException('Particle delta must be finite and nonnegative');
		}

		$dt = min($dt, max(0, $this->lifetime - $this->age));
		$this->age = min($this->lifetime, $this->age + $dt);

		if($this->finished())
		{
			$this->sprites->sprites = $this->motion = [];

			return;
		}

		foreach($this->sprites->sprites as $i => $piece)
		{
			[$vx, $vy, $spin, $opacity] = $this->motion[$i];
			$piece->x += $vx * $dt;
			$piece->y += $vy * $dt + .5 * $this->gravity * $dt * $dt;
			$piece->rotation += $spin * $dt;
			$piece->opacity = $opacity * (1 - $this->age / $this->lifetime);
			$this->motion[$i][1] += $this->gravity * $dt;
		}
	}

	/**
	 * Check whether the burst has exhausted its configured lifetime.
	 *
	 * @return bool True once the group is empty and its owning entity can be retired.
	 */
	public function finished(): bool
	{
		return $this->age >= $this->lifetime;
	}
}
