<?php
declare(strict_types=1);
namespace Phonics\Tiled;

/** Mutable sparse Tiled chunk storing packed little-endian GIDs and upload revision. */
final class Chunk
{
	/** Revision incremented after each tile edit so renderers can refresh GPU data. */
	public int $revision = 0;

	/**
	 * Store a rectangular tile page as four little-endian bytes per cell.
	 *
	 * @param int $x Left tile coordinate, including negative infinite-map coordinates.
	 * @param int $y Top tile coordinate.
	 * @param int $width Positive number of tile columns.
	 * @param int $height Positive number of tile rows.
	 * @param string $data Row-major GID bytes; length must equal width times height times four.
	 * @return void Creates an unmodified chunk with revision zero.
	 */
	public function __construct(
		public int $x
		, public int $y
		, public int $width
		, public int $height
		, public string $data
	){
		if($width < 1 || $height < 1 || strlen($data) !== $width * $height * 4)
		{
			throw new \UnexpectedValueException('Invalid chunk dimensions/data length');
		}
	}

	/**
	 * Check whether a tile coordinate lies inside this chunk.
	 *
	 * @param int $x Map-local tile column.
	 * @param int $y Map-local tile row.
	 * @return bool True for coordinates inside its half-open tile bounds.
	 */
	public function contains(int $x, int $y): bool
	{
		return $x >= $this->x && $y >= $this->y && $x < $this->x + $this->width && $y < $this->y + $this->height;
	}

	/**
	 * Decode one tile at a map-local coordinate.
	 *
	 * @param int $x Map-local tile column.
	 * @param int $y Map-local tile row.
	 * @return Gid Tile identifier and flags, or an empty GID outside the chunk.
	 */
	public function gidAt(int $x, int $y): Gid
	{
		return $this->contains($x, $y)
			? Gid::fromBytes(substr($this->data, (($y - $this->y) * $this->width + $x - $this->x) * 4, 4))
			: new Gid(0);
	}

	/**
	 * Replace a tile in this chunk and increment its upload revision.
	 *
	 * @param int $x Map-local tile column to edit.
	 * @param int $y Map-local tile row to edit.
	 * @param Gid $gid Replacement tile identifier and flip flags.
	 * @return void Updates the packed bytes; coordinates outside the chunk raise
	 * OutOfBoundsException.
	 */
	public function set(int $x, int $y, Gid $gid): void
	{
		if(!$this->contains($x, $y))
		{
			throw new \OutOfBoundsException('Tile outside chunk');
		}

		$this->data = substr_replace(
			$this->data
			, $gid->bytes()
			, (($y - $this->y) * $this->width + $x - $this->x) * 4
			, 4
		);

		$this->revision++;
	}
}
