<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;

/** Avoid unsigned-int casts: the WASM runtime has 32-bit PHP integers. */
final class Gid
{
	public function __construct(public int $id, public int $flags = 0) {}
	public static function from($raw): self
	{
		if(!is_numeric($raw) || !is_finite((float)$raw) || $raw < 0 || $raw > 4294967295.0 || floor((float)$raw) != $raw)
		{ throw new \InvalidArgumentException('GID must be uint32'); }
		return new self((int)fmod((float)$raw, 268435456.0), ((int)floor($raw / 268435456.0)) & 14);
	}
	public static function fromBytes(string $bytes): self
	{
		if(strlen($bytes) !== 4)
		{ throw new \InvalidArgumentException('GID needs four bytes'); }
		return new self(ord($bytes[0]) + (ord($bytes[1]) << 8) + (ord($bytes[2]) << 16) + ((ord($bytes[3]) & 15) << 24), (ord($bytes[3]) >> 4) & 14);
	}
	public function bytes(): string { return chr($this->id & 255).chr(($this->id >> 8) & 255).chr(($this->id >> 16) & 255).chr((($this->id >> 24) & 15) | ($this->flags << 4)); }
	/** Tiled applies diagonal swap, horizontal flip, then vertical flip. */
	public function uv(float $x, float $y): array
	{
		if($this->flags & 2)
		{ [$x,$y] = [$y,$x]; }
		if($this->flags & 8)
		{ $x = 1 - $x; }
		if($this->flags & 4)
		{ $y = 1 - $y; }
		return [$x,$y];
	}
	public function inverseUv(float $x,float $y): array
	{
		if($this->flags & 4)
		{ $y = 1 - $y; }
		if($this->flags & 8)
		{ $x = 1 - $x; }
		if($this->flags & 2)
		{ [$x,$y] = [$y,$x]; }
		return [$x,$y];
	}
}
