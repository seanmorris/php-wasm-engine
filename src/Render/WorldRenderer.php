<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
use PhpWasmEngine\Asset\Assets;
use PhpWasmEngine\Tiled\
{World,WorldStream};

/** Lazy GPU residency for a Tiled world. Scene order follows the world's maps list. */
final class WorldRenderer
{
	private array $resident = [];
	private int $frame = 0;
	private ?string $backgroundMap = null;
	public function __construct(public World|WorldStream $world,public int $residentLimit = 8)
	{
		if($residentLimit < 1)
		{ throw new \InvalidArgumentException('residentLimit must be positive'); }
	}
	public function render(Camera $camera,float $milliseconds = 0,float $margin = 128): array
	{
		$stats = ['maps' => 0,'draws' => 0,'instances' => 0,'uploads' => 0];
		$this->frame++;
		$active = [];
		$this->prune();
		foreach($this->world->visible($camera->x,$camera->y,$camera->width / $camera->zoom,$camera->height / $camera->zoom,$margin) as $map)
		{
			if(isset($this->resident[$map->path]) && $this->resident[$map->path][0]->map !== $map)
			{
				$this->resident[$map->path][0]->close();
				unset($this->resident[$map->path]);
			}
			if(!isset($this->resident[$map->path]))
			{ $this->resident[$map->path] = [new MapRenderer($map),0]; }
			[$renderer] = $this->resident[$map->path];
			$this->resident[$map->path][1] = $this->frame;
			$stats['maps']++;
			$backgroundEnd = count($map->layers);
			foreach($map->layers as $order => $layer)
			{ if($layer->type === 'tilelayer')
			{ $backgroundEnd = $order;
			break; } }
			$active[$map->path] = [$renderer,$backgroundEnd];
		}
		$this->backgroundMap = $this->selectBackground($active,$camera);
		// A repeating background from a neighboring map must not cover earlier terrain.
		foreach([true,false] as $background)
		{ foreach($active as $path => [$renderer,$boundary])
		{
			$start = $background?0:$boundary;
			$end = $background?$boundary:count($renderer->map->layers);
			if($start < $end)
			{
				$renderer->render($camera,$milliseconds,$start,$end,!$background || $path === $this->backgroundMap);
				foreach($renderer->stats as $key => $value)
				{ $stats[$key] += $value; }
			}
		} }
		uasort($this->resident,fn($a,$b) => $a[1] <=> $b[1]);
		foreach($this->resident as $path => [$renderer,$frame])
		{
			if(count($this->resident) <= $this->residentLimit)
			{ break; }
			if($frame === $this->frame)
			{ continue; }
			$renderer->close();
			unset($this->resident[$path]);
		}
		return $stats;
	}
	/** Match Tigl's current-map background without loading a pending destination. */
	private function selectBackground(array $active,Camera $camera): ?string
	{
		$world = $this->world instanceof WorldStream?$this->world->world:$this->world;
		$x = $camera->x + $camera->width / $camera->zoom / 2;
		$y = $camera->y + $camera->height / $camera->zoom / 2;
		foreach($world->entries($x,$y,0,0) as $entry)
		{
			$path = Assets::relative($world->path,$entry['fileName']);
			if(isset($active[$path]))
			{ return $path; }
		}
		// Keep a visible ready background while crossing a gap or waiting on a map.
		return isset($active[$this->backgroundMap ?? ''])?$this->backgroundMap:array_key_first($active);
	}
	private function prune(): void
	{
		if($this->world instanceof WorldStream)
		{
			$loaded = $this->world->loaded();
			foreach($this->resident as $path => [$renderer])
			{ if(!isset($loaded[$path]))
			{ $renderer->close();
			unset($this->resident[$path]); } }
		}
	}
	public function restore(): void
	{ $this->prune();
	foreach($this->resident as [$renderer])
	{ $renderer->restore(); } }
	public function close(): void
	{ foreach($this->resident as [$renderer])
	{ $renderer->close(); } $this->resident = [];
	$this->backgroundMap = null; }
}
