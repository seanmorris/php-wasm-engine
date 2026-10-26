<?php
declare(strict_types=1);
namespace Phonics\Tiled;

/** Flattened Tiled tile, object, or image layer with inherited display properties. */
final class Layer
{
	/** @var list<Chunk> Sparse tile chunks in authored order. */
	public array $chunks = [];
	/** @var list<array<string,mixed>> Translated Tiled objects in authored order. */
	public array $objects = [];
	/** @var array<string,mixed> Inherited custom properties keyed by name. */
	public array $properties = [];
	/** Effective visibility after flattening parent groups. */
	public bool $visible = true;
	/** Accumulated horizontal world-pixel offset. */
	public float $offsetX = 0;
	/** Accumulated vertical world-pixel offset. */
	public float $offsetY = 0;
	/** Accumulated normalized layer opacity. */
	public float $opacity = 1;
	/** Accumulated horizontal camera parallax factor. */
	public float $parallaxX = 1;
	/** Accumulated vertical camera parallax factor. */
	public float $parallaxY = 1;
	/** @var array{float,float,float,float} Accumulated normalized RGBA tint. */
	public array $tint = [1,1,1,1];
	/** Canonical source URL of an image layer, or null for other layer kinds. */
	public ?string $image = null;
	/** Native pixel width of the current image-layer frame. */
	public int $imageWidth = 0;
	/** Native pixel height of the current image-layer frame. */
	public int $imageHeight = 0;
	/** Whether the image repeats across the horizontal world axis. */
	public bool $repeatX = false;
	/** Whether the image repeats across the vertical world axis. */
	public bool $repeatY = false;
	/** @var list<array{image:string,duration:float}> Optional timed image-layer frames. */
	public array $imageFrames = [];
	/** @var array{float,float,float,float}|null Color-key RGBA value removed during upload. */
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
	public function gidAt(int $x, int $y): Gid
	{
		foreach($this->chunks as $chunk)
		{
			if($chunk->contains($x, $y))
			{
				return $chunk->gidAt($x, $y);
			}
		}

		return new Gid(0);
	}

	/**
	 * Edit a tile, allocating a sparse 32-by-32 page when the coordinate is absent.
	 *
	 * @param int $x Map-local tile column, which may be negative.
	 * @param int $y Map-local tile row, which may be negative.
	 * @param Gid|int|float|numeric-string $gid Replacement GID object or unsigned 32-bit Tiled
	 * value.
	 * @return void Updates the owning chunk and its revision for subsequent GPU synchronization.
	 */
	public function set(int $x, int $y, $gid): void
	{
		$gid = $gid instanceof Gid?$gid:Gid::from($gid);

		foreach($this->chunks as $chunk)
		{
			if($chunk->contains($x, $y))
			{
				$chunk->set($x, $y, $gid);

				return;
			}
		}

		$cx = (int)floor($x / 32) * 32;
		$cy = (int)floor($y / 32) * 32;
		$chunk = new Chunk($cx, $cy, 32, 32, str_repeat("\0", 4096));
		$chunk->set($x, $y, $gid);
		$this->chunks[] = $chunk;
	}
}
