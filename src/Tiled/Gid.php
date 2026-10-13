<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;

/** Avoid unsigned-int casts: the WASM runtime has 32-bit PHP integers. */
final class Gid
{

	/**
	 * Store a tile ID separately from Tiled's upper-nibble flip flags.
	 *
	 * @param int $id Tile identifier from the low 28 bits; zero denotes an empty cell.
	 * @param int $flags Upper-nibble flags: 8 horizontal, 4 vertical, and 2 diagonal.
	 * @return void Stores the already decoded values without narrowing an unsigned 32-bit input.
	 */
	public function __construct(public int $id, public int $flags = 0) {}

	/**
	 * Decode an unsigned Tiled GID without overflowing 32-bit PHP integers.
	 *
	 * @param int|float|numeric-string $raw Integer-valued unsigned 32-bit GID, including high flip
	 * bits.
	 * @return self Decoded tile ID and supported flip flags, with the hexagonal rotation bit
	 * cleared.
	 */
	public static function from($raw): self
	{
		if(!is_numeric($raw) || !is_finite((float)$raw) || $raw < 0 || $raw > 4294967295.0 || floor(
			(float)$raw
		) != $raw
		){
			throw new \InvalidArgumentException('GID must be uint32');
		}

		return new self((int)fmod((float)$raw, 268435456.0), ((int)floor($raw / 268435456.0)) & 14);
	}

	/**
	 * Decode one little-endian four-byte tile identifier.
	 *
	 * @param string $bytes Exactly four packed bytes from a Tiled layer.
	 * @return self Tile ID and supported flip flags; any other byte length is rejected.
	 */
	public static function fromBytes(string $bytes): self
	{
		if(strlen($bytes) !== 4)
		{
			throw new \InvalidArgumentException('GID needs four bytes');
		}

		return new self(
			ord($bytes[0]) + (ord($bytes[1]) << 8) + (ord($bytes[2]) << 16) + ((ord($bytes[
				3
			]) & 15
			) << 24
			)
			, (ord($bytes[3]) >> 4) & 14
		);
	}

	/**
	 * Pack the tile ID and flip flags into a little-endian uint32 representation.
	 *
	 * @return string Exactly four bytes, safe to use on both native and 32-bit WASM PHP.
	 */
	public function bytes(): string
	{
		return chr($this->id & 255).chr(($this->id >> 8) & 255).chr(($this->id >> 16) & 255).chr(
			(($this->id >> 24) & 15) | ($this->flags << 4)
		);
	}

	/**
	 * Apply Tiled's diagonal swap, horizontal flip, then vertical flip.
	 *
	 * @param float $x Normalized source horizontal coordinate.
	 * @param float $y Normalized source vertical coordinate.
	 * @return array{0:float,1:float} Transformed normalized X and Y coordinates.
	 */
	public function uv(float $x, float $y): array
	{
		if($this->flags & 2)
		{
			[$x,$y] = [$y,$x];
		}

		if($this->flags & 8)
		{
			$x = 1 - $x;
		}

		if($this->flags & 4)
		{
			$y = 1 - $y;
		}

		return [$x,$y];
	}

	/**
	 * Undo the tile's flip operations in reverse order.
	 *
	 * @param float $x Transformed normalized horizontal coordinate.
	 * @param float $y Transformed normalized vertical coordinate.
	 * @return array{0:float,1:float} Original normalized X and Y coordinates.
	 */
	public function inverseUv(float $x, float $y): array
	{
		if($this->flags & 4)
		{
			$y = 1 - $y;
		}

		if($this->flags & 8)
		{
			$x = 1 - $x;
		}

		if($this->flags & 2)
		{
			[$x,$y] = [$y,$x];
		}

		return [$x,$y];
	}
}
