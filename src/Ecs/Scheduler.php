<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ecs;
final class Scheduler
{
	private array $systems = [];
	private ?array $ordered = null;
	public function __construct(public Registry $registry) {}
	public function add(string $name,callable $system,array $after = []): void
	{ $this->systems[$name] = [$system,$after];
	$this->ordered = null; }
	public function remove(string $name): void
	{ unset($this->systems[$name]);
	$this->ordered = null; }
	public function update(float $dt): void
	{
		if($this->ordered === null)
		{
			$result = [];
			$active = [];
			$done = [];
			$visit = function($name) use (&$visit,&$result,&$active,&$done) {
				if(isset($done[$name]))
				{ return; }
				if(isset($active[$name]))
				{ throw new \LogicException("System dependency cycle at $name"); }
				if(!isset($this->systems[$name]))
				{ throw new \LogicException("Unknown system dependency $name"); }
				$active[$name] = true;
				foreach($this->systems[$name][1] as $dependency)
				{ $visit($dependency); }
				unset($active[$name]);
				$done[$name] = true;
				$result[] = $name;
			};
			foreach(array_keys($this->systems) as $name)
			{ $visit($name); }
			$this->ordered = $result;
		}
		foreach($this->ordered as $name)
		{ ($this->systems[$name][0])($this->registry,$dt); }
		$this->registry->flush();
	}
}
