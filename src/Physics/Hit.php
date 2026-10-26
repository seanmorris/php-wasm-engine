<?php
declare(strict_types=1);
namespace Phonics\Physics;

/** Earliest continuous terrain contact, its surface normal, ownership, and metadata. */
final class Hit
{
	/** Deprecated platformer annotation retained until 1.0. */
	public bool $grinding;
	/** Uninterpreted surface metadata supplied by the geometry owner. */
	public array $metadata;

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
	 * @param array|bool $grinding Surface metadata, or a legacy boolean annotation.
	 * @return void Stores the contact data without modifying terrain or a body.
	 */
	public function __construct(
		public float $t
		, public float $x
		, public float $y
		, public float $nx
		, public float $ny
		, public bool $oneWay = false
		, public ?int $shape = null
		, array|bool $grinding = false
	){
		$this->metadata = is_array($grinding) ? $grinding : [];
		$this->grinding = is_bool($grinding) ? $grinding : (bool)($grinding['grinding'] ?? false);
	}
}
