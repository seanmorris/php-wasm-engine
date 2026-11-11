<?php
declare(strict_types=1);
namespace Phonics\Tiled;

use Phonics\Asset\Assets;
use Phonics\Ecs\ComponentFactories;
use Phonics\World\ActiveScope;
use Phonics\World\InspectableSource;
use Phonics\World\ScopeContext;
use Phonics\World\ScopeDescriptor;

/** Inspectable world asset with independently prepared world and map gameplay scopes. */
final class Source implements InspectableSource
{
	/** Descriptors and authored map entries indexed by stable placement identity. */
	private array $descriptors = [], $entries = [];
	/** Optional project-exported class definitions, separate from game code. */
	public ClassCatalog $classes;
	private bool $closed = false;
	/** Canonical portable class resource when component authoring is enabled. */
	private ?string $classSource = null;

	/**
	 * Read world metadata and the class catalog without loading maps or spawning entities.
	 *
	 * @param World $world Loaded Tiled world asset.
	 * @param array $options Adapter or system configuration.
	 * @return void Initializes the owned references and validates configuration.
	 */
	public function __construct(public World $world, private array $options = [])
	{
		$properties = Loader::properties($world->data['properties'] ?? []);
		$path = $options['classes'] ?? $properties['phonics.classes'] ?? null;
		$path = $path ? Assets::relative($world->path, $path) : null;
		$this->classSource = $path;
		$this->classes = new ClassCatalog($path ? $world->loader->assets->json($path) : [], $path ?? $world->path);
		$this->descriptors['world'] = new ScopeDescriptor('world', 'world', metadata: $this->metadata($world->data));

		foreach($world->data['maps'] ?? [] as $entry)
		{
			$id = $world->identity($entry);
			$this->entries[$id] = $entry;

			$this->descriptors[$id] = new ScopeDescriptor($id, 'map', 'world', metadata: [
				'requireParent' => true
				, 'placement' => $entry
			]);
		}
	}

	/**
	 * Enumerate map identities without reading their contents.
	 *
	 * @return iterable World and map descriptors in authored order.
	 */
	public function catalog(): iterable { return array_values($this->descriptors); }

	/**
	 * Inspect a world or map, including all layers, tilesets, and resolved object templates.
	 *
	 * @param string $id Stable entity or source-local target identity.
	 * @return mixed Parsed content without activating its systems.
	 */
	public function inspect(string $id = ''): mixed
	{
		if($this->closed) throw new \LogicException('Tiled source is closed');

		if($id === '' || $id === 'world') return $this->world;

		$entry = $this->entries[$id] ?? throw new \OutOfBoundsException("Unknown Tiled map $id");
		$map = $this->world->load($entry);
		$this->descriptors[$id]->metadata = $this->metadata($map->data) + $this->descriptors[$id]->metadata;

		return $map;
	}

	/**
	 * List world and map resources without requiring spatial arrangement or loading their bytes.
	 *
	 * @return iterable Discoverable resource records with kind and URL.
	 */
	public function resources(): iterable
	{
		yield ['kind' => 'world', 'url' => $this->world->path];

		if($this->classSource) yield ['kind' => 'classes', 'url' => $this->classSource];

		foreach($this->entries as $id => $entry)
		yield ['kind' => 'map', 'id' => $id, 'url' => Assets::relative($this->world->path, $entry['fileName'])];
	}

	/**
	 * Read a resource relative to the world, without activating any scopes.
	 *
	 * @param string $reference Absolute or world-relative resource path.
	 * @return string Resource bytes resolved relative to this source.
	 */
	public function read(string $reference): string
	{
		if($this->closed) throw new \LogicException('Tiled source is closed');

		return $this->world->loader->assets->read(Assets::relative($this->world->path, $reference));
	}

	/**
	 * Assemble components and scope-owned entities; systems come from declarative bindings.
	 *
	 * @param string $id Stable entity or source-local target identity.
	 * @param ScopeContext $context Host or ownership context supplied by the runtime.
	 * @return ActiveScope Reference-counted handle or content scope for this activation.
	 */
	public function activate(string $id, ScopeContext $context): ActiveScope
	{
		$content = $this->inspect($id);
		$factories = $context->service('components', new ComponentFactories);

		if($content instanceof World)
		{
			$this->entity(new Target('world', 'world', $content), $content->data, $content->path, $context, $factories);
		}
		else
		{
			// Position is authored data. A campaign or spatial game system chooses how to use it.
			$this->entity(
				new Target('map', $id, $content, $content)
				, $content->data
				, $content->path
				, $context
				, $factories
			);

			foreach($content->allLayers as $layer)
			{
				$this->entity(
					new Target('layer', (string)$layer->key, $layer, $content, $layer)
					, $layer->data
					, $content->path
					, $context
					, $factories
				);

				foreach($layer->objects as $object)
				$this->entity(
					new Target('object', (string)$object['id'], $object, $content, $layer)
					, $object
					, $object['sourceUrl'] ?? $content->path
					, $context
					, $factories
				);
			}

			new TileEntities($content, $context, $this->classes, $factories, $context->service('tiled.tileTransfer'));
		}

		return new SourceScope(
			$this->descriptors[$id]
			, $content
			, /** Release the parsed map placement after scope cleanup.
 * @return void Completes the scope operation.
 */
			function() use ($id): void {
				if(isset($this->entries[$id])) $this->world->unload($this->entries[$id]);
			}
		);
	}

	/**
	 * Create an entity only when the target actually has authored components.
	 *
	 * @param Target $target Adapter target receiving the components.
	 * @param array $data Authored data with class definitions or properties.
	 * @param string $source Declaring resource used to resolve relative files.
	 * @param ScopeContext $context Host or ownership context supplied by the runtime.
	 * @param ComponentFactories $factories Registered constructors from already loaded game packages.
	 * @return void Creates scope-owned components only for opted-in targets.
	 */
	private function entity(
		Target $target
		, array $data
		, string $source
		, ScopeContext $context
		, ComponentFactories $factories
	): void {
		$descriptors = $this->classes->components($data, $source);

		if($descriptors) $context->entity($target, ...$factories->build($descriptors, $context, $target));
	}

	/**
	 * Decode system-set declarations while leaving execution and lifecycle policy to WorldRuntime.
	 *
	 * @param array $data Authored data with class definitions or properties.
	 * @return array System bindings and optional lifecycle selected by authored properties.
	 */
	private function metadata(array $data): array
	{
		$properties = Loader::properties($data['properties'] ?? []);
		$systems = $properties['phonics.systems'] ?? [];

		if(is_string($systems)) $systems = json_decode($systems, true, flags: JSON_THROW_ON_ERROR);

		if(!is_array($systems) || !array_is_list($systems))
		throw new \UnexpectedValueException('phonics.systems must be a list');

		$result = [
			'systems' => array_map(
				/** Normalize a shorthand system binding.
 * @param string|array $binding Authored system declaration.
 * @return array Binding with stable identity and export reference.
 */
				static fn($binding) => is_string($binding) ? ['id' => $binding, 'use' => $binding] : $binding
				, $systems
			)
		];

		if(isset($properties['phonics.lifecycle'])) $result['lifecycle'] = $properties['phonics.lifecycle'];

		return $result;
	}

	/**
	 * Release parsed map placements; the host independently owns mounted resource references.
	 *
	 * @return void Releases owned resources idempotently.
	 */
	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;

		foreach($this->entries as $entry) $this->world->unload($entry);
	}
}
