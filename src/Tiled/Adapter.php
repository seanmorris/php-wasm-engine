<?php
declare(strict_types=1);
namespace Phonics\Tiled;

use Phonics\Asset\Assets;
use Phonics\World\AdapterContext;
use Phonics\World\ContentAdapter;
use Phonics\World\ContentSource;
use Phonics\World\SourceDefinition;

/** Optional Tiled adapter; merely opening a world does not run any game systems. */
final class Adapter implements ContentAdapter
{

	/**
	 * Open one explicit Tiled world using the host's resource reader.
	 *
	 * @param SourceDefinition $definition Source resources and adapter options.
	 * @param AdapterContext $context Host or ownership context supplied by the runtime.
	 * @return ContentSource Inspectable content source without activated gameplay.
	 */
	public function open(SourceDefinition $definition, AdapterContext $context): ContentSource
	{
		if(count($definition->resources) !== 1) throw new \InvalidArgumentException('Tiled sources require one world');

		$loader = new Loader(new Assets(/** Read adapter resource bytes.
 * @param string $url Canonical resource URL.
 * @return string Loaded bytes.
 */
			fn($url) => $context->read($url)
		));

		$world = $loader->world($definition->resources[0]['url']);

		return new Source($world, $definition->options);
	}
}
