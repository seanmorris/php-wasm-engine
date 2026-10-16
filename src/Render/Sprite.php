<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;

use PhpWasmEngine\Tiled\TileMap;

final class Sprite
{
	public float $rotation = 0,$scaleX = 1,$scaleY = 1,$opacity = 1,$time = 0;
	public bool $flipX = false,$flipY = false;
	public array $tint = [1,1,1,1];
	/** @var array{int,int,int,int}|null Tile-relative pixel crop, or null for the whole frame. */
	public ?array $crop = null;
	public bool $animated = true;
	/** @var bool Expand the rendered silhouette by one world pixel with a black shader outline. */
	public bool $outline = true;
	/** @var bool Interpolate texels within this crop; false keeps pixel artwork sharp. */
	public bool $smooth = false;

	/**
	 * Create an atlas-backed sprite positioned by a normalized image anchor.
	 *
	 * @param int $gid Global tile ID in the renderer's atlas, including an animation tile when
	 * needed.
	 * @param float $x World X coordinate of the sprite anchor in pixels.
	 * @param float $y World Y coordinate of the sprite anchor in pixels.
	 * @param float $originX Horizontal anchor fraction of image width; 0 is left and 1 is right.
	 * @param float $originY Vertical anchor fraction of image height; 0 is top and 1 is bottom.
	 * @return void Initializes unit scale, full opacity, an outline, and no rotation or flips.
	 */
	public function __construct(
		public int $gid
		, public float $x = 0
		, public float $y = 0
		, public float $originX = .5
		, public float $originY = 1
	){
	}

	/**
	 * Resolve this sprite's playback position, or retain a frozen frame.
	 *
	 * @param TileMap $map Tilesets containing the sprite's global tile ID.
	 * @param float $milliseconds Simulation time added to this sprite's playback offset.
	 * @return int Concrete frame GID to draw from the atlas.
	 */
	public function frame(TileMap $map, float $milliseconds = 0): int
	{
		return $this->animated ? $map->animatedGid($this->gid, $milliseconds + $this->time) : $this->gid;
	}

	/**
	 * Apply an optional pixel crop inside an image or atlas frame.
	 *
	 * @param array{int,int,int,int} $frame Source rectangle containing the complete tile frame.
	 * @return array{int,int,int,int} Source rectangle of the visible region; invalid crops throw.
	 */
	public function rectangle(array $frame): array
	{
		if($this->crop === null)
		{
			return $frame;
		}

		if(array_keys($this->crop) !== [0, 1, 2, 3]
			|| count(array_filter($this->crop, 'is_int')) !== 4
		){
			throw new \InvalidArgumentException('Sprite crops require four integer pixel values');
		}

		[$x, $y, $width, $height] = $this->crop;

		if($x < 0 || $y < 0 || $width < 1 || $height < 1
			|| $width > $frame[2] - $x || $height > $frame[3] - $y
		){
			throw new \InvalidArgumentException('Sprite crop must fit inside its tile frame');
		}

		return [$frame[0] + $x, $frame[1] + $y, $width, $height];
	}
}
