<?php
declare(strict_types=1);
namespace PhpWasmEngine\Physics;
final class Hit
{
	public function __construct(public float $t,public float $x,public float $y,public float $nx,public float $ny,public bool $oneWay = false) {}
}
