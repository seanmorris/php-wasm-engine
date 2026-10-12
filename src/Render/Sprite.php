<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;

final class Sprite
{
	public float $rotation = 0,$scaleX = 1,$scaleY = 1,$opacity = 1,$time = 0;
	public bool $flipX = false,$flipY = false;
	public array $tint = [1,1,1,1];

	/**
	 * Create an atlas-backed sprite positioned by a normalized image anchor.
	 *
	 * @param int $gid Global tile ID in the renderer's atlas, including an animation tile when
	 * needed.
	 * @param float $x World X coordinate of the sprite anchor in pixels.
	 * @param float $y World Y coordinate of the sprite anchor in pixels.
	 * @param float $originX Horizontal anchor fraction of image width; 0 is left and 1 is right.
	 * @param float $originY Vertical anchor fraction of image height; 0 is top and 1 is bottom.
	 * @return void Initializes the sprite with unit scale, full opacity, and no rotation or flips.
	 */
	public function __construct(public int $gid, public float $x = 0, public float $y = 0
		, public float $originX = .5, public float $originY = 1
	){
	}
}
