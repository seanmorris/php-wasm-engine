<?php
declare(strict_types=1);
namespace PhpWasmEngine\Physics;

final class Hit
{

	/**
	 * Record the earliest terrain contact from a continuous collision query.
	 *
	 * @param float $t Fraction of the queried displacement at impact, from zero to one.
	 * @param float $x Contact X coordinate in world pixels.
	 * @param float $y Contact Y coordinate in world pixels.
	 * @param float $nx X component of the outward unit surface normal.
	 * @param float $ny Y component of the outward unit surface normal.
	 * @param bool $oneWay Whether the contacted edge belongs to a one-way platform.
	 * @param int|null $shape Removable terrain handle, or null for permanent geometry.
	 * @return void Stores the contact data without modifying terrain or a body.
	 */
	public function __construct(public float $t, public float $x, public float $y, public float $nx
		, public float $ny, public bool $oneWay = false, public ?int $shape = null
	){
	}
}
