<?php
declare(strict_types=1);
namespace Phonics\Tiled;

use Phonics\World\ActiveScope;
use Phonics\World\ScopeDescriptor;

/** Owned Tiled content instance; gameplay state is captured by explicitly registered systems. */
final class SourceScope implements ActiveScope
{
	private bool $closed = false;

	/**
	 * Retain one parsed content instance and its release callback.
	 *
	 * @param ScopeDescriptor $scope Owning content scope.
	 * @param World|TileMap $value Parsed content retained by this scope.
	 * @param mixed $release Idempotent resource release callback.
	 * @return void Initializes the owned references and validates configuration.
	 */
	public function __construct(private ScopeDescriptor $scope, private World|TileMap $value, private mixed $release) {}

	/**
	 * Return the stable content descriptor.
	 *
	 * @return ScopeDescriptor Stable description of this content scope.
	 */
	public function descriptor(): ScopeDescriptor { return $this->scope; }

	/**
	 * Expose the map or world to game systems.
	 *
	 * @return World|TileMap Parsed world or map instance.
	 */
	public function content(): World|TileMap { return $this->value; }

	/**
	 * Components opt into snapshots through ScopeContext instead of arbitrary object serialization.
	 *
	 * @return array Explicit JSON-safe state for later restoration.
	 */
	public function snapshot(): array { return []; }

	/**
	 * Release this instance once.
	 *
	 * @return void Releases owned resources idempotently.
	 */
	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		($this->release)();
	}
}
