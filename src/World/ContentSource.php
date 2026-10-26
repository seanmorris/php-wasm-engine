<?php
declare(strict_types=1);
namespace Phonics\World;

/** Catalog and activate stable scopes from one opened content source. */
interface ContentSource
{
	/**
	 * Enumerate known scopes without activating gameplay or render systems.
	 *
	 * @return iterable<ScopeDescriptor> Scope descriptors in source order.
	 */
	public function catalog(): iterable;

	/**
	 * Activate one scope and attach its format-specific content to a neutral context.
	 *
	 * @param string $id Stable scope ID from catalog().
	 * @param ScopeContext $context Ownership boundary for systems, entities, passes, and cleanup.
	 * @return ActiveScope Active adapter state.
	 */
	public function activate(string $id, ScopeContext $context): ActiveScope;

	/**
	 * Release source-level parsed data and resources.
	 *
	 * @return void Closes the source once no active scopes remain.
	 */
	public function close(): void;
}
