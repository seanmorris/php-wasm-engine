<?php
declare(strict_types=1);
namespace Phonics\World;

/** Stable adapter scope identity, hierarchy, kind, tags, and declarative metadata. */
final class ScopeDescriptor
{
	/**
	 * Describe an activatable unit such as a world, episode, map, region, or room.
	 *
	 * @param string $id Stable ID within the source.
	 * @param string $kind Adapter-defined scope kind.
	 * @param string|null $parent Parent scope ID, or null for a source root.
	 * @param list<string> $tags Adapter-defined matching tags.
	 * @param array<string,mixed> $metadata Declarative metadata safe to inspect before activation.
	 * @return void Stores a validated descriptor.
	 */
	public function __construct(
		public string $id
		, public string $kind
		, public ?string $parent = null
		, public array $tags = []
		, public array $metadata = []
	) {
		if($id === '' || str_contains($id, "\0")) throw new \InvalidArgumentException('Invalid scope ID');
		if($kind === '') throw new \InvalidArgumentException("Scope $id has no kind");
	}
}
