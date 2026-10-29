<?php
declare(strict_types=1);
namespace Phonics\World;

/** Open one declarative source without assuming its file format or renderer. */
interface ContentAdapter
{

	/**
	 * Open a source after its package graph and resources have been resolved.
	 *
	 * @param SourceDefinition $definition Declarative source configuration.
	 * @param AdapterContext $context Session-scoped host services.
	 * @return ContentSource Parsed, independently closeable source.
	 */
	public function open(SourceDefinition $definition, AdapterContext $context): ContentSource;
}
