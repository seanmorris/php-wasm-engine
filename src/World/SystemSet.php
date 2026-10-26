<?php
declare(strict_types=1);
namespace Phonics\World;

/** Register a reusable group of systems and render passes for one active scope. */
interface SystemSet
{
	/**
	 * Activate this set inside a scope-owned lifecycle boundary.
	 *
	 * @param ScopeContext $scope Active scope and shared runtime services.
	 * @param array<string,mixed> $options Declarative binding options.
	 * @return void Registers owned resources through the scope context.
	 */
	public function activate(ScopeContext $scope, array $options = []): void;
}
