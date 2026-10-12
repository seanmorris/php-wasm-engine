<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;
final class Layer
{
	public array $chunks = [];
	public array $objects = [];
	public array $properties = [];
	public bool $visible = true;
	public float $offsetX = 0, $offsetY = 0, $opacity = 1, $parallaxX = 1, $parallaxY = 1;
	public array $tint = [1,1,1,1];
	public ?string $image = null;
	public int $imageWidth = 0, $imageHeight = 0;
	public bool $repeatX = false, $repeatY = false;
	public array $imageFrames = [];
	public ?array $transparentColor = null;
	/**
	 * Create a flattened map layer with default visibility and transform state.
	 *
	 * @param int $id Tiled layer identifier.
	 * @param string $name Editor-visible layer name.
	 * @param string $type Tiled layer kind, such as tilelayer, objectgroup, or imagelayer.
	 * @return void Initializes an empty layer ready for chunks, objects, or an image.
	 */
	public function __construct(public int $id, public string $name, public string $type) {}
	/**
	 * Locate a tile coordinate among this layer's sparse chunks.
	 *
	 * @param int $x Map-local tile column.
	 * @param int $y Map-local tile row.
	 * @return Gid Stored tile and flags, or an empty GID when no chunk contains the coordinate.
	 */
	public function gidAt(int $x,int $y): Gid
	{ foreach($this->chunks as $chunk)
	{ if($chunk->contains($x,$y))
	{ return $chunk->gidAt($x,$y); } } return new Gid(0); }
	/**
	 * Edit a tile, allocating a sparse 32-by-32 page when the coordinate is absent.
	 *
	 * @param int $x Map-local tile column, which may be negative.
	 * @param int $y Map-local tile row, which may be negative.
	 * @param Gid|int|float|numeric-string $gid Replacement GID object or unsigned 32-bit Tiled value.
	 * @return void Updates the owning chunk and its revision for subsequent GPU synchronization.
	 */
	public function set(int $x,int $y,$gid): void
	{
		$gid = $gid instanceof Gid?$gid:Gid::from($gid);
		foreach($this->chunks as $chunk)
		{ if($chunk->contains($x,$y))
		{ $chunk->set($x,$y,$gid);
		return; } }
		$cx = (int)floor($x / 32) * 32;
		$cy = (int)floor($y / 32) * 32;
		$chunk = new Chunk($cx,$cy,32,32,str_repeat("\0",4096));
		$chunk->set($x,$y,$gid);
		$this->chunks[] = $chunk;
	}
}
