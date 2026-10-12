<?php
declare(strict_types=1);
namespace PhpWasmEngine\Tiled;
use PhpWasmEngine\Asset\Assets;

/** PHP selects maps and resolves dependencies; the host only transfers files. */
final class WorldStream
{
	private array $states = [], $references = [];
	public array $entered = [], $exited = [], $errors = [];
	private $request, $release;
	private bool $closed = false;
	/** request(path,retry): true if mounted, false while pending, or an error string. */
	public function __construct(public World $world,?callable $request = null,?callable $release = null,public float $loadMargin = 256,public float $retainMargin = 768)
	{
		if(!is_finite($loadMargin) || !is_finite($retainMargin) || $loadMargin < 0 || $retainMargin < $loadMargin)
		{ throw new \InvalidArgumentException('Retention margin must be at least the nonnegative load margin'); }
		$this->request = $request ?? static fn($path,$retry = false) => true;
		$this->release = $release;
	}
	public function update(float $x,float $y,float $width,float $height): void
	{
		if($this->closed)
		{ return; }
		$near = $this->world->entries($x,$y,$width,$height,$this->loadMargin);
		$keep = $this->world->entries($x,$y,$width,$height,$this->retainMargin);
		$this->entered = $this->exited = [];
		foreach($near as $order => $entry)
		{
			$path = Assets::relative($this->world->path,$entry['fileName']);
			$this->states[$path] ??= ['entry' => $entry,'order' => $order,'files' => [],'map' => null,'ready' => false,'failed' => null];
		}
		$retained = [];
		foreach($keep as $entry)
		{ $retained[Assets::relative($this->world->path,$entry['fileName'])] = true; }
		// Acquire new dependencies before releasing old maps that may share them.
		foreach($this->states as $path => &$state)
		{
			if(!isset($retained[$path]) || $state['ready'] || isset($this->errors[$path]))
			{ continue; }
			try
			{
				if(!$this->prepare($state,$path))
				{ continue; }
				$state['ready'] = true;
				$this->entered[] = $state['map'];
			}
			catch(\Throwable $error)
			{ $this->errors[$path] = $error->getMessage(); }
		}
		unset($state);
		foreach(array_keys($this->states) as $path)
		{ if(!isset($retained[$path]))
		{ $this->evict($path); } }
	}
	private function available(array &$state,string $path): bool
	{
		if(!isset($state['files'][$path]))
		{
			$state['files'][$path] = true;
			$this->references[$path] = ($this->references[$path] ?? 0) + 1;
		}
		$state['failed'] = $path;
		$result = ($this->request)($path,false);
		if($result === true)
		{ $state['failed'] = null;
		return true; }
		if($result === false)
		{ $state['failed'] = null;
		return false; }
		throw new \RuntimeException($path.': '.(string)$result);
	}
	private function prepare(array &$state,string $path): bool
	{
		if(!$this->available($state,$path))
		{ return false; }
		$state['failed'] = $path;
		$data = $this->world->loader->assets->json($path);
		$state['failed'] = null;
		$ready = true;
		foreach($data['tilesets'] ?? [] as $set)
		{ if(isset($set['source']))
		{
			$source = Assets::relative($path,$set['source']);
			if(!$this->available($state,$source))
			{ $ready = false;
			continue; }
			$state['failed'] = $source;
			$this->world->loader->assets->json($source);
			$state['failed'] = null;
		} }
		if(!$ready)
		{ return false; }
		$state['failed'] = $path;
		$state['map'] ??= $this->world->load($state['entry']);
		$state['failed'] = null;
		$images = [];
		foreach($state['map']->tilesets as $set)
		{ foreach($set['definitions'] as $tile)
		{ $images[$tile['image']] = true; } }
		foreach($state['map']->layers as $layer)
		{
			if($layer->image !== null)
			{ $images[$layer->image] = true; }
			foreach($layer->imageFrames as $frame)
			{ $images[$frame['image']] = true; }
		}
		foreach(array_keys($images) as $image)
		{ $ready = $this->available($state,$image) && $ready; }
		return $ready;
	}
	/** Ready maps in manifest order, including retained neighbors. */
	public function loaded(): array
	{
		$states = $this->states;
		uasort($states,fn($a,$b) => $a['order'] <=> $b['order']);
		$maps = [];
		foreach($states as $path => $state)
		{ if($state['ready'])
		{ $maps[$path] = $state['map']; } }
		return $maps;
	}
	public function pending(): array
	{ return array_keys(array_filter($this->states,fn($state) => !$state['ready'])); }
	public function visible(float $x,float $y,float $width,float $height,float $margin = 0): array
	{
		$maps = [];
		foreach($this->world->entries($x,$y,$width,$height,$margin) as $entry)
		{
			$path = Assets::relative($this->world->path,$entry['fileName']);
			if($this->states[$path]['ready'] ?? false)
			{ $maps[] = $this->states[$path]['map']; }
		}
		return $maps;
	}
	/** Gate physics/teleports on the whole destination or swept body bounds. */
	public function isReady(float $x,float $y,float $width,float $height,float $margin = 0): bool
	{
		foreach($this->world->entries($x,$y,$width,$height,$margin) as $entry)
		{ if(!($this->states[Assets::relative($this->world->path,$entry['fileName'])]['ready'] ?? false))
		{ return false; } }
		return true;
	}
	public function retry(string $fileName): void
	{
		$path = isset($this->states[$fileName])?$fileName:Assets::relative($this->world->path,$fileName);
		if(!isset($this->errors[$path]))
		{ return; }
		$failed = $this->states[$path]['failed'];
		if($failed !== null)
		{ $this->world->loader->assets->forget($failed);
		($this->request)($failed,true); }
		else
		{
			foreach(array_keys($this->states[$path]['files']) as $file)
			{ $this->world->loader->assets->forget($file); }
		}
		$this->world->unload($this->states[$path]['entry']['fileName']);
		$this->states[$path]['map'] = null;
		$this->states[$path]['failed'] = null;
		unset($this->errors[$path]);
	}
	private function evict(string $path): void
	{
		$state = $this->states[$path];
		if($state['ready'])
		{ $this->exited[] = $state['map']; }
		$this->world->unload($state['entry']['fileName']);
		foreach(array_keys($state['files']) as $file)
		{
			if(--$this->references[$file] > 0)
			{ continue; }
			unset($this->references[$file]);
			$this->world->loader->assets->forget($file);
			if($this->release)
			{ ($this->release)($file); }
		}
		unset($this->states[$path],$this->errors[$path]);
	}
	public function close(): void
	{
		if($this->closed)
		{ return; }
		$this->closed = true;
		$this->entered = $this->exited = [];
		foreach(array_keys($this->states) as $path)
		{ $this->evict($path); }
		$this->exited = [];
	}
}
