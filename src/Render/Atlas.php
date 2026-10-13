<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
use PhpWasmEngine\Tiled\TileMap;

/** Shelf-pack source images once; dense metadata keeps sparse firstgids cheap. */
final class Atlas
{
	public int $texture = 0,$metadata = 0,$width = 1,$height = 1;
	public array $indices = [], $rects = [], $tiles = [];
	private array $frames = [];
	private bool $closed = false;

	/**
	 * Pack a map's tileset images and upload dense tile metadata.
	 *
	 * @param TileMap $map Map supplying normalized tile definitions, source images, and animation
	 * frames.
	 * @return void Creates an image texture and metadata texture in the current GL context.
	 */
	public function __construct(public TileMap $map)
	{
		$this->create();
	}

	/**
	 * Shelf-pack source images and upload atlas pixels, rectangles, and tile offsets.
	 *
	 * @return void Initializes texture handles and GID indexes, rejecting content that exceeds GPU
	 * limits.
	 */
	private function create(): void
	{
		$map = $this->map;
		$max = glGetIntegerv(GL_MAX_TEXTURE_SIZE);
		$images = [];
		$surfaces = [];

		try
		{
			foreach($map->tilesets as $set)
			{
				foreach($set['definitions'] as $local => $tile)
				{
					$gid = $set['firstgid'] + $local;
					$this->indices[$gid] = count($this->indices) + 1;
					$this->tiles[$gid] = $tile;

					if(isset($images[$tile['image']]))
					{
						continue;
					}

					$surface = IMG_Load($tile['image']);

					if(!$surface)
					{
						throw new \RuntimeException(
							'Cannot load '.$tile['image'].': ' .SDL_GetError()
						);
					}

					$surfaces[$tile['image']] = $surface;
					$images[$tile['image']] = [$surface->w,$surface->h];
				}
			}

			if(count($this->indices) + 1 > $max)
			{
				throw new \RuntimeException('Atlas tile metadata exceeds GPU texture width');
			}

			$area = array_sum(array_map(
				/**
				 * Include one pixel of packing padding around an image.
				 *
				 * @param array{0:int,1:int} $s Source image width and height in pixels.
				 * @return int Padded image area in square pixels.
				 */
				fn($s) => ($s[0] + 2) * ($s[1] + 2), $images
			));
			$this->width = min($max, max(1, (int)ceil(sqrt($area))));

			foreach($images as [$w,$h])
			{
				if($w + 2 > $max || $h + 2 > $max)
				{
					throw new \RuntimeException('Tileset image exceeds GPU atlas dimensions');
				}

				$this->width = max($this->width, $w + 2);
			}
			$positions = [];
			$x = 1;
			$y = 1;
			$row = 0;

			foreach($images as $path => [$w,$h])
			{
				if($x + $w + 1 > $this->width)
				{
					$x = 1;
					$y += $row + 2;
					$row = 0;
				}

				$positions[$path] = [$x,$y];
				$x += $w + 2;
				$row = max($row, $h);
			}
			$this->height = max(1, $y + $row + 1);

			if($this->height > $max)
			{
				throw new \RuntimeException('Atlas exceeds GPU height; split the map tilesets');
			}

			$surface = new \SDL_Surface(
				0
				, $this->width
				, $this->height
				, 32
				, 255
				, 65280
				, 16711680
				, -16777216
			);

			try
			{
				SDL_FillRect($surface, null, SDL_MapRGBA($surface->format, 0, 0, 0, 0));

				foreach($surfaces as $path => $source)
				{
					SDL_SetSurfaceBlendMode($source, SDL_BLENDMODE_NONE);
					[$x,$y] = $positions[$path];
					$dest = new \SDL_Rect($x, $y, $source->w, $source->h);

					if(SDL_UpperBlit($source, null, $surface, $dest) !== 0)
					{
						throw new \RuntimeException(SDL_GetError());
					}
				}
				$this->texture = self::texture(GL_TEXTURE_2D);

				glTexImage2D(
					GL_TEXTURE_2D
					, 0
					, GL_RGBA8
					, $this->width
					, $this->height
					, 0
					, GL_RGBA
					, GL_UNSIGNED_BYTE
					, $surface
				);
			} finally
			{
				SDL_FreeSurface($surface);
			}
			$count = count($this->indices) + 1;
			$rectBytes = str_repeat("\0", $count * 16);
			$offsetBytes = $rectBytes;

			foreach($this->tiles as $gid => $tile)
			{
				[$x,$y] = $positions[$tile['image']];
				[$u,$v,$w,$h] = $tile['rect'];
				$index = $this->indices[$gid];
				$rect = $this->rects[$gid] = [$x + $u,$y + $v,$w,$h];
				$rectBytes = substr_replace($rectBytes, pack('f*', ...$rect), $index * 16, 16);

				$offsetBytes = substr_replace(
					$offsetBytes
					, pack('f*', $tile['offset'][0], $tile['offset'][1], 0, 0)
					, $index * 16
					, 16
				);
			}
			$this->metadata = self::texture(GL_TEXTURE_2D);

			glTexImage2D(
				GL_TEXTURE_2D
				, 0
				, GL_RGBA32F
				, $count
				, 2
				, 0
				, GL_RGBA
				, GL_FLOAT
				, $rectBytes .$offsetBytes
			);
		} catch(\Throwable $error)
		{
			$this->release();
			throw $error;
		}
		finally
		{
			foreach($surfaces as $surface)
			{
				SDL_FreeSurface($surface);
			}
		}
	}

	/**
	 * Rebuild GPU textures in place so borrowing sprite renderers keep the same atlas object.
	 *
	 * @return void Recreates image/metadata storage and resets frame caches unless closed.
	 */
	public function restore(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->release();
		$this->indices = $this->rects = $this->tiles = $this->frames = [];
		$this->create();
	}

	/**
	 * Allocate and bind a texture with nearest filtering and clamp-to-edge wrapping.
	 *
	 * @param int $target GL texture target, such as GL_TEXTURE_2D or GL_TEXTURE_2D_ARRAY.
	 * @return int Newly allocated GL texture handle owned by the caller.
	 */
	public static function texture(int $target): int
	{
		glGenTextures(1, $ids);
		glBindTexture($target, $ids[0]);
		glTexParameteri($target, GL_TEXTURE_MIN_FILTER, GL_NEAREST);
		glTexParameteri($target, GL_TEXTURE_MAG_FILTER, GL_NEAREST);
		glTexParameteri($target, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
		glTexParameteri($target, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);
		return $ids[0];
	}

	/**
	 * Update only metadata entries whose Tiled animation frame has changed.
	 *
	 * @param float $milliseconds Simulation or renderer time in milliseconds.
	 * @return int Number of tile rectangle entries uploaded during this call.
	 */
	public function animate(float $milliseconds): int
	{
		$updates = 0;
		glBindTexture(GL_TEXTURE_2D, $this->metadata);

		foreach($this->tiles as $gid => $tile)
		{
			if(!empty($tile['animation']))
			{
				$frame = $this->map->animatedGid($gid, $milliseconds);

				if(($this->frames[$gid] ?? null) === $frame)
				{
					continue;
				}

				if(!isset($this->rects[$frame]))
				{
					throw new \UnexpectedValueException('Animation refers to missing tile');
				}

				glTexSubImage2D(
					GL_TEXTURE_2D
					, 0
					, $this->indices[$gid]
					, 0
					, 1
					, 1
					, GL_RGBA
					, GL_FLOAT
					, pack('f*', ...$this->rects[$frame])
				);
				$this->frames[$gid] = $frame;
				$updates++;
			}
		}
		return $updates;
	}

	/**
	 * Permanently close the atlas and release its owned GPU textures.
	 *
	 * @return void Prevents restoration and deletes live texture handles.
	 */
	public function close(): void
	{
		$this->closed = true;
		$this->release();
	}

	/**
	 * Delete atlas image and metadata textures while retaining CPU descriptions.
	 *
	 * @return void Resets both texture handles to zero for teardown or restoration.
	 */
	private function release(): void
	{
		$ids = array_values(array_filter([$this->texture,$this->metadata]));

		if($ids)
		{
			glDeleteTextures(count($ids), $ids);
		}

		$this->texture = $this->metadata = 0;
	}
}
