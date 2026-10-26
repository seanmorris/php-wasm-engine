<?php
declare(strict_types=1);
namespace Phonics\World;

/** Reference-counted handle that keeps one stable source scope active. */
final class ScopeLease
{
	private bool $released = false;

	/**
	 * Create a lease with a runtime-owned release callback.
	 *
	 * @param string $sourceId World-local source ID.
	 * @param string $scopeId Stable source-local scope ID.
	 * @param ScopeContext $context Shared active scope context.
	 * @param callable $release Decrement callback invoked once.
	 * @return void Stores the active context.
	 */
	public function __construct(
		public string $sourceId
		, public string $scopeId
		, public ScopeContext $context
		, private $release
	) {}

	/**
	 * Release one active-scope reference.
	 * @return void Repeated calls have no effect.
	 */
	public function release(): void
	{
		if($this->released) return;

		$this->released = true;
		($this->release)();
	}

	/**
	 * Release the lease when it leaves scope.
	 * @return void Delegates to the idempotent release method.
	 */
	public function __destruct() { $this->release(); }
}
