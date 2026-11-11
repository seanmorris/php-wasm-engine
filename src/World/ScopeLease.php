<?php
declare(strict_types=1);
namespace Phonics\World;

/**
 * Reference-counted handle that keeps one stable source scope active.
 *
 * Each activate() call acquires independent ownership. Idempotent release deactivates the scope only after
 * the final holder lets go; leases cannot be cloned.
 */
final class ScopeLease
{
	/** Whether this lease has released its ownership reference. */
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
		/** World-local source ID. */
		public string $sourceId
		, /** Stable source-local scope ID. */
		public string $scopeId
		, /** Shared active scope context. */
		public ScopeContext $context
		, /** Decrement callback invoked once. */
		private $release
	){
	}

	/**
	 * Prevent uncounted ownership copies; acquire another lease through WorldRuntime.
	 *
	 * @return void Prevents cloning a reference-counted lease.
	 */
	private function __clone() {}

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
