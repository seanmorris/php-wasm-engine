<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ecs;

/** Object components, stable entity IDs, type indexes, snapshot queries. */
final class Registry
{
	private int $next = 1;
	private array $entities = [], $index = [], $commands = [], $listeners = [];
	public function create(object ...$components): int
	{ $id = $this->next++;
	$this->entities[$id] = [];
	foreach($components as $c)
	{ $this->add($id,$c); } return $id; }
	public function alive(int $id): bool { return isset($this->entities[$id]); }
	public function add(int $id,object $component): void
	{
		$this->requireEntity($id);
		$type = get_class($component);
		if(isset($this->entities[$id][$type]))
		{ $this->remove($id,$type); }
		$this->entities[$id][$type] = $component;
		foreach($this->types($component) as $class)
		{ $this->index[$class][$id][$type] = true; }
		$this->emit('added',$id,$component);
	}
	public function remove(int $id,string $type): void
	{
		$this->requireEntity($id);
		foreach($this->entities[$id] as $class => $component)
		{ if($component instanceof $type)
		{
			unset($this->entities[$id][$class]);
			foreach($this->types($component) as $indexed)
			{ unset($this->index[$indexed][$id][$class]);
			if(empty($this->index[$indexed][$id]))
			{ unset($this->index[$indexed][$id]); } }
			$this->emit('removed',$id,$component);
		} }
	}
	public function destroy(int $id): void
	{ $this->requireEntity($id);
	foreach(array_keys($this->entities[$id]) as $type)
	{ $this->remove($id,$type); } unset($this->entities[$id]); }
	public function get(int $id,string $type): object
	{
		$this->requireEntity($id);
		if(isset($this->entities[$id][$type]))
		{ return $this->entities[$id][$type]; }
		foreach($this->entities[$id] as $component)
		{ if($component instanceof $type)
		{ return $component; } }
		throw new \OutOfBoundsException("Entity $id has no $type");
	}
	public function has(int $id,string $type): bool { return isset($this->index[$type][$id]); }
	public function query($query): array
	{
		if(is_string($query))
		{ return array_keys($this->index[$query] ?? []); }
		if(!$query instanceof Query)
		{ throw new \InvalidArgumentException('Expected component type or Query'); }
		$candidates = array_keys($this->entities);
		if($query->operation === 'all')
		{ foreach($query->terms as $term)
		{ if(is_string($term))
		{
			$ids = array_keys($this->index[$term] ?? []);
			if(count($ids) < count($candidates))
			{ $candidates = $ids; }
		} } }
		return array_values(array_filter($candidates,fn($id) => $query->matches($this,$id)));
	}
	public function defer(callable $command): void { $this->commands[] = $command; }
	public function flush(): void
	{
		for($round = 0;$this->commands;$round++)
		{
			if($round >= 32)
			{ throw new \RuntimeException('Recursive ECS command limit'); }
			$commands = $this->commands;
			$this->commands = [];
			foreach($commands as $command)
			{ $command($this); }
		}
	}
	public function on(string $event,callable $listener): void { $this->listeners[$event][] = $listener; }
	private function emit(string $event,int $id,object $component): void
	{ foreach($this->listeners[$event] ?? [] as $listener)
	{ $listener($this,$id,$component); } }
	private function types(object $object): array { return array_merge([get_class($object)],array_values(class_parents($object)),array_values(class_implements($object))); }
	private function requireEntity(int $id): void
	{ if(!$this->alive($id))
	{ throw new \OutOfBoundsException("Entity $id is not alive"); } }
}
