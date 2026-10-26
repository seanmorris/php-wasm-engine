<?php
declare(strict_types=1);
namespace Phonics\World;

/** Declarative source definition retained independently from its active adapter state. */
final class SourceDefinition
{

	/**
	 * Describe one imported source and the resources that compose it.
	 *
	 * @param string $id World-local source ID.
	 * @param string $adapter Adapter export reference.
	 * @param list<array<string,mixed>> $resources Ordered canonical resource records.
	 * @param array<string,mixed> $options Adapter-specific declarative options.
	 * @param string $sourceUrl Canonical URL of the world declaring this source.
	 * @return void Validates stable identifiers and stores immutable definition data.
	 */
	public function __construct(
		public string $id
		, public string $adapter
		, public array $resources = []
		, public array $options = []
		, public string $sourceUrl = ''
	){
		if(!preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', $id))
		throw new \InvalidArgumentException("Invalid content source ID $id");

		if($adapter === '') throw new \InvalidArgumentException("Source $id has no adapter");

		foreach($resources as $resource)
		{
			if(!is_array($resource)) throw new \InvalidArgumentException("Source $id contains an invalid resource");

			$hasUrl = isset($resource['url']) && is_string($resource['url']) && $resource['url'] !== '';
			$hasSlot = isset($resource['slot']) && is_string($resource['slot']) && $resource['slot'] !== '';

			if($hasUrl === $hasSlot)
			throw new \InvalidArgumentException("Source $id resources require exactly one URL or slot");
		}
	}

	/**
	 * Return a source definition with every declared slot replaced by a resolved resource.
	 *
	 * @param list<array<string,mixed>> $resources Fully resolved resources in adapter stack order.
	 * @return self A validated copy which is ready for adapter activation.
	 */
	public function withResources(array $resources): self
	{
		foreach($resources as $resource)
		if(!is_array($resource) || !isset($resource['url']) || !is_string($resource['url']) || $resource['url'] === '')
		throw new \InvalidArgumentException("Source {$this->id} contains an unresolved resource");

		return new self($this->id, $this->adapter, $resources, $this->options, $this->sourceUrl);
	}

	/**
	 * Check whether any placement still awaits a session resource binding.
	 *
	 * @return bool True when at least one resource placement still names a slot.
	 */
	public function unresolved(): bool
	{
		foreach($this->resources as $resource) if(isset($resource['slot'])) return true;

		return false;
	}
}
