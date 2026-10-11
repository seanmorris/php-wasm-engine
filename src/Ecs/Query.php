<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ecs;
final class Query
{
	private function __construct(public string $operation,public array $terms) {}
	public static function all(...$terms): self { return new self('all',$terms); }
	public static function any(...$terms): self { return new self('any',$terms); }
	public static function not(...$terms): self { return new self('not',$terms); }
	public function matches(Registry $registry,int $entity): bool
	{
		foreach($this->terms as $term)
		{
			$match = $term instanceof self?$term->matches($registry,$entity):$registry->has($entity,$term);
			if($this->operation === 'all' && !$match)
			{ return false; }
			if($this->operation === 'any' && $match)
			{ return true; }
			if($this->operation === 'not' && $match)
			{ return false; }
		}
		return $this->operation !== 'any';
	}
}
