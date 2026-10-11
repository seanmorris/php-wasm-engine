<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;
final class Chunk
{
	public int $revision = 0;
	public function __construct(public int $x, public int $y, public int $width, public int $height, public string $data)
	{
		if($width < 1 || $height < 1 || strlen($data) !== $width * $height * 4)
		{ throw new \UnexpectedValueException('Invalid chunk dimensions/data length'); }
	}
	public function contains(int $x,int $y): bool { return $x >= $this->x && $y >= $this->y && $x < $this->x + $this->width && $y < $this->y + $this->height; }
	public function gidAt(int $x,int $y): Gid { return $this->contains($x,$y) ? Gid::fromBytes(substr($this->data,(($y - $this->y) * $this->width + $x - $this->x) * 4,4)) : new Gid(0); }
	public function set(int $x,int $y,Gid $gid): void
	{
		if(!$this->contains($x,$y))
		{ throw new \OutOfBoundsException('Tile outside chunk'); }
		$this->data = substr_replace($this->data,$gid->bytes(),(($y - $this->y) * $this->width + $x - $this->x) * 4,4);
		$this->revision++;
	}
}
