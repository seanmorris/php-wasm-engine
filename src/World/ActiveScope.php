<?php
declare(strict_types=1);
namespace Phonics\World;

/** Format-specific state retained while a neutral source scope is active. */
interface ActiveScope
{
	/**
	 * Return stable identity and matching metadata for this scope.
	 * @return ScopeDescriptor Descriptor selected during activation.
	 */
	public function descriptor(): ScopeDescriptor;

	/**
	 * Return adapter-owned active content.
	 * @return mixed Parsed map, region, archive, or other source-format value.
	 */
	public function content();

	/**
	 * Snapshot state retained across deactivation.
	 * @return array<string,mixed> JSON-serializable scope state.
	 */
	public function snapshot(): array;

	/**
	 * Release adapter-owned state once.
	 * @return void Closes active content without closing the source.
	 */
	public function close(): void;
}
