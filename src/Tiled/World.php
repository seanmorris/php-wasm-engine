<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;
use PhpWasmEngine\Asset\Assets;
use PhpWasmEngine\Spatial\BoundsIndex;
final class World
{
	private BoundsIndex $index;
	public function __construct(public Loader $loader,public string $path,public array $data)
	{
		if(!empty($data['patterns']))
		{ throw new \UnexpectedValueException('Export an explicit world maps list; filesystem patterns are not supported'); }
		$this->reindex();
	}
	/** Call after editing manifest bounds. No map contents are read here. */
	public function reindex(): void
	{
		$seen = [];
		$indexed = $this->data['maps'] ?? [];
		foreach($indexed as &$entry)
		{
			if(!is_string($entry['fileName'] ?? null) || $entry['fileName'] === '')
			{ throw new \UnexpectedValueException('World maps require fileName'); }
			foreach(['x','y','width','height'] as $key)
			{ if(!is_numeric($entry[$key] ?? null) || !is_finite((float)$entry[$key]))
			{ throw new \UnexpectedValueException('World map bounds must be finite numbers'); } }
			if($entry['width'] < 0 || $entry['height'] < 0)
			{ throw new \UnexpectedValueException('World map dimensions must be nonnegative'); }
			$path = Assets::relative($this->path,$entry['fileName']);
			if(isset($seen[$path]))
			{ throw new \UnexpectedValueException('World map filenames must be unique'); }
			$seen[$path] = true;
			// Optional local bounds keep negative infinite chunks separate from map origin.
			if(isset($entry['bounds']))
			{
				foreach(['x','y','width','height'] as $key)
				{ if(!is_numeric($entry['bounds'][$key] ?? null) || !is_finite((float)$entry['bounds'][$key]))
				{ throw new \UnexpectedValueException('Local map bounds must be finite numbers'); } }
				if($entry['bounds']['width'] < 0 || $entry['bounds']['height'] < 0)
				{ throw new \UnexpectedValueException('Local map dimensions must be nonnegative'); }
				$entry['x'] += $entry['bounds']['x'];
				$entry['y'] += $entry['bounds']['y'];
				$entry['width'] = $entry['bounds']['width'];
				$entry['height'] = $entry['bounds']['height'];
			}
		}
		unset($entry);
		$this->index = new BoundsIndex($indexed);
	}
	/** Metadata only, in manifest painter order. Coordinates are the top-left corner. */
	public function entries(float $x,float $y,float $width,float $height,float $margin = 0): array
	{
		foreach([$x,$y,$width,$height,$margin] as $value)
		{ if(!is_finite($value))
		{ throw new \InvalidArgumentException('World query must be finite'); } }
		if($width < 0 || $height < 0 || $margin < 0)
		{ throw new \InvalidArgumentException('World query dimensions and margin must be nonnegative'); }
		$result = [];
		foreach($this->index->query($x - $margin,$y - $margin,$x + $width + $margin,$y + $height + $margin) as $id => $entry)
		{ $result[$id] = $this->data['maps'][$id]; }
		return $result;
	}
	public function load(array $entry): TileMap
	{
		$map = $this->loader->map(Assets::relative($this->path,$entry['fileName']));
		$map->x = (float)$entry['x'];
		$map->y = (float)$entry['y'];
		return $map;
	}
	/** Synchronous loading for files already available to PHP. */
	public function visible(float $x,float $y,float $width,float $height,float $margin = 0): array
	{ return array_values(array_map(fn($entry) => $this->load($entry),$this->entries($x,$y,$width,$height,$margin))); }
	public function unload(string $fileName): void { $this->loader->forget(Assets::relative($this->path,$fileName)); }
}
