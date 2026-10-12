<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
use PhpWasmEngine\Tiled\
{World,WorldStream};

/** Lazy GPU residency for a Tiled world. Scene order follows the world's maps list. */
final class WorldRenderer
{
	private array $resident = [];
	private int $frame = 0;
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
			$active[] = [$renderer,$backgroundEnd];
		}
		// A repeating background from a neighboring map must not cover earlier terrain.
		foreach([true,false] as $background)
		{ foreach($active as [$renderer,$boundary])
		{
			$start = $background?0:$boundary;
			$end = $background?$boundary:count($renderer->map->layers);
			if($start < $end)
			{
				$renderer->render($camera,$milliseconds,$start,$end);
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
	{ $renderer->close(); } $this->resident = []; }
}
