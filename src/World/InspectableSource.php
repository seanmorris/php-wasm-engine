<?php
declare(strict_types=1);
namespace Phonics\World;

/** Optional asset inspection capability; reading content never activates its systems. */
interface InspectableSource extends ContentSource
{

	/**
	 * Read a source-local resource or scope without creating gameplay instances.
	 *
	 * @param string $id Stable entity or source-local target identity.
	 * @return mixed Parsed content without activating its systems.
	 */
	public function inspect(string $id = ''): mixed;

	/**
	 * Enumerate canonical resource references discovered in this source.
	 *
	 * @return iterable Discoverable resource records with kind and URL.
	 */
	public function resources(): iterable;

	/**
	 * Read immutable bytes through the source's resource ownership.
	 *
	 * @param string $reference Verified package export reference.
	 * @return string Resource bytes resolved relative to this source.
	 */
	public function read(string $reference): string;
}
