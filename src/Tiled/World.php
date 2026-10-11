<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;
use PhpWasmEngine\Asset\Assets;
final class World
{
	public function __construct(private Loader $loader, public string $path, public array $data)
	{
		if(!empty($data['patterns']))
		{ throw new \UnexpectedValueException('Export an explicit world maps list; filesystem patterns are not supported'); }
	}
	/** Load only maps intersecting the supplied world-space viewport. */
	public function visible(float $x,float $y,float $width,float $height,float $margin = 0): array
	{
		$result = [];
		foreach($this->data['maps'] ?? [] as $entry)
		{
			if($entry['x'] + $entry['width'] < $x - $margin || $entry['y'] + $entry['height'] < $y - $margin || $entry['x'] > $x + $width + $margin || $entry['y'] > $y + $height + $margin)
			{ continue; }
			$map = $this->loader->map(Assets::relative($this->path,$entry['fileName']));
			$map->x = (float)$entry['x'];
			$map->y = (float)$entry['y'];
			$result[] = $map;
		}
		return $result;
	}
	public function unload(string $fileName): void { $this->loader->forget(Assets::relative($this->path,$fileName)); }
}
