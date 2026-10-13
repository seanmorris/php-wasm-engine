<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ecs;

final class Query
{

	/**
	 * Store a boolean operation and its component or nested-query terms.
	 *
	 * @param string $operation Boolean operation: all, any, or not.
	 * @param list<Query|class-string> $terms Component types or nested predicates to combine.
	 * @return void Creates an immutable-by-convention query description.
	 */
	private function __construct(public string $operation, public array $terms) {}

	/**
	 * Build a predicate requiring every supplied term to match.
	 *
	 * @param Query|class-string ...$terms Component types or nested predicates that must all match.
	 * @return self Query that also matches when no terms are supplied.
	 */
	public static function all(...$terms): self { return new self('all', $terms); }

	/**
	 * Build a predicate requiring at least one supplied term to match.
	 *
	 * @param Query|class-string ...$terms Alternative component types or nested predicates.
	 * @return self Query that matches no entities when its term list is empty.
	 */
	public static function any(...$terms): self { return new self('any', $terms); }

	/**
	 * Build a predicate rejecting entities matching any supplied term.
	 *
	 * @param Query|class-string ...$terms Component types or nested predicates to exclude.
	 * @return self Query that matches only when all supplied terms fail.
	 */
	public static function not(...$terms): self { return new self('not', $terms); }

	/**
	 * Evaluate this predicate against one entity and its indexed component types.
	 *
	 * @param Registry $registry Registry providing component membership.
	 * @param int $entity Entity ID being evaluated.
	 * @return bool Whether the entity satisfies this query.
	 */
	public function matches(Registry $registry, int $entity): bool
	{
		foreach($this->terms as $term)
		{
			$match = $term instanceof self?$term->matches($registry, $entity):$registry->has(
				$entity
				, $term
			);

			if($this->operation === 'all' && !$match)
			{
				return false;
			}

			if($this->operation === 'any' && $match)
			{
				return true;
			}

			if($this->operation === 'not' && $match)
			{
				return false;
			}
		}
		return $this->operation !== 'any';
	}
}
