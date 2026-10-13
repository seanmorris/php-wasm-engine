<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;
use PhpWasmEngine\Asset\Assets;
use PhpWasmEngine\Spatial\BoundsIndex;

final class World
{
	private BoundsIndex $index;

	/**
	 * Create a world bounds index from an explicit map manifest.
	 *
	 * @param Loader $loader Shared loader used for map and dependency caches.
	 * @param string $path World manifest path used to resolve map filenames.
	 * @param array<string,mixed> $data Decoded Tiled world data; filesystem patterns are
	 * unsupported.
	 * @return void Validates and indexes entries without reading any map contents.
	 */
	public function __construct(public Loader $loader, public string $path, public array $data)
	{
		if(!empty($data['patterns']))
		{
			throw new \UnexpectedValueException(
				'Export an explicit world maps list; filesystem patterns are not supported'
			);
		}

		$this->reindex();
	}

	/**
	 * Rebuild the spatial index after editing manifest bounds.
	 *
	 * @return void Validates unique map filenames and finite nonnegative bounds without loading
	 * maps.
	 */
	public function reindex(): void
	{
		$seen = [];
		$indexed = $this->data['maps'] ?? [];

		foreach($indexed as &$entry)
		{
			if(!is_string($entry['fileName'] ?? null) || $entry['fileName'] === '')
			{
				throw new \UnexpectedValueException('World maps require fileName');
			}

			foreach(['x','y','width','height'] as $key)
			{
				if(!is_numeric($entry[$key] ?? null) || !is_finite((float)$entry[$key]))
				{
					throw new \UnexpectedValueException('World map bounds must be finite numbers');
				}
			}

			if($entry['width'] < 0 || $entry['height'] < 0)
			{
				throw new \UnexpectedValueException('World map dimensions must be nonnegative');
			}

			$path = Assets::relative($this->path, $entry['fileName']);

			if(isset($seen[$path]))
			{
				throw new \UnexpectedValueException('World map filenames must be unique');
			}

			$seen[$path] = true;

			// Optional local bounds keep negative infinite chunks separate from map origin.
			if(isset($entry['bounds']))
			{
				foreach(['x','y','width','height'] as $key)
				{
					if(!is_numeric($entry['bounds'][$key] ?? null) || !is_finite(
						(float)$entry['bounds'][$key]
					)){
						throw new \UnexpectedValueException(
							'Local map bounds must be finite numbers'
						);
					}
				}

				if($entry['bounds']['width'] < 0 || $entry['bounds']['height'] < 0)
				{
					throw new \UnexpectedValueException('Local map dimensions must be nonnegative');
				}

				$entry['x'] += $entry['bounds']['x'];
				$entry['y'] += $entry['bounds']['y'];
				$entry['width'] = $entry['bounds']['width'];
				$entry['height'] = $entry['bounds']['height'];
			}
		}
		unset($entry);
		$this->index = new BoundsIndex($indexed);
	}

	/**
	 * Select map metadata intersecting a world rectangle in manifest painter order.
	 *
	 * @param float $x Left world coordinate in pixels.
	 * @param float $y Top world coordinate in pixels.
	 * @param float $width Nonnegative query width in pixels.
	 * @param float $height Nonnegative query height in pixels.
	 * @param float $margin Nonnegative expansion on every side in pixels.
	 * @return array<int,array<string,mixed>> Original manifest entries keyed by their manifest
	 * index; map contents remain unloaded.
	 */
	public function entries(float $x, float $y, float $width, float $height, float $margin = 0):
	array
	{
		foreach([$x,$y,$width,$height,$margin] as $value)
		{
			if(!is_finite($value))
			{
				throw new \InvalidArgumentException('World query must be finite');
			}
		}

		if($width < 0 || $height < 0 || $margin < 0)
		{
			throw new \InvalidArgumentException(
				'World query dimensions and margin must be nonnegative'
			);
		}

		$result = [];

		foreach($this->index->query(
			$x - $margin
			, $y - $margin
			, $x + $width + $margin
			, $y + $height + $margin
		) as $id => $entry
		){
			$result[$id] = $this->data['maps'][$id];
		}
		return $result;
	}

	/**
	 * Load one manifest entry and apply its world placement to the cached map.
	 *
	 * @param array<string,mixed> $entry Manifest record containing fileName and world x/y
	 * placement.
	 * @return TileMap Loaded map instance with its world origin set from the entry.
	 */
	public function load(array $entry): TileMap
	{
		$map = $this->loader->map(Assets::relative($this->path, $entry['fileName']));
		$map->x = (float)$entry['x'];
		$map->y = (float)$entry['y'];
		return $map;
	}

	/**
	 * Synchronously load maps intersecting a rectangle when their files are already available.
	 *
	 * @param float $x Left world coordinate in pixels.
	 * @param float $y Top world coordinate in pixels.
	 * @param float $width Nonnegative query width in pixels.
	 * @param float $height Nonnegative query height in pixels.
	 * @param float $margin Nonnegative expansion on every side in pixels.
	 * @return list<TileMap> Intersecting maps in manifest painter order.
	 */
	public function visible(float $x, float $y, float $width, float $height, float $margin = 0):
	array
	{
		return array_values(array_map(
			/**
			 * Load and position one selected world manifest entry.
			 *
			 * @param array<string,mixed> $entry Manifest entry containing a filename and placement.
			 * @return TileMap Cached map with its world origin applied.
			 */
			fn($entry) => $this->load($entry)
			, $this->entries($x, $y, $width, $height, $margin)
		));
	}

	/**
	 * Invalidate a map cache entry without releasing shared dependencies.
	 *
	 * @param string $fileName Map filename relative to the world manifest, or an absolute path.
	 * @return void Removes the cached map and its source bytes/JSON.
	 */
	public function unload(string $fileName): void
	{
		$this->loader->forget(Assets::relative($this->path, $fileName));
	}
}
