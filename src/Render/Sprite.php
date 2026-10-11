<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
final class Sprite
{
	public float $rotation = 0,$scaleX = 1,$scaleY = 1,$opacity = 1,$time = 0;
	public bool $flipX = false,$flipY = false;
	public array $tint = [1,1,1,1];
	public function __construct(public int $gid,public float $x = 0,public float $y = 0,public float $originX = .5,public float $originY = 1) {}
}
