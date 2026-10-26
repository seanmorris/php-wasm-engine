<?php
declare(strict_types=1);
namespace Phonics\Tiled;
use Phonics\Asset\Assets;

/** Load, normalize, cache, and provenance-track supported Tiled JSON resources. */
final class Loader
{
	private array $maps = [];
	/** Byte and decoded-JSON cache used for maps, worlds, templates, tilesets, and images. */
	public Assets $assets;

	/**
	 * Create a Tiled JSON loader backed by reusable asset caches.
	 *
	 * @param Assets|null $assets Optional shared byte/JSON cache; null creates a filesystem-backed
	 * source.
	 * @return void Initializes an empty map cache using the supplied or default source.
	 */
	public function __construct(?Assets $assets = null) { $this->assets = $assets ?? new Assets; }

	/**
	 * Load and cache a Tiled map, resolving tilesets and flattening nested layers.
	 *
	 * @param string $path Tiled JSON map path or URL understood by the asset source.
	 * @return TileMap Shared cached map instance with normalized tile definitions and bounded
	 * chunks.
	 */
	public function map(string $path): TileMap
	{
		$path = \Phonics\Asset\Resource::source($path);

		if(isset($this->maps[$path]))
		{
			return $this->maps[$path];
		}

		$data = $this->assets->json($path);
		$map = new TileMap(self::properties($data['properties'] ?? [])['phonics.source'] ?? $path, $data);

		foreach($data['tilesets'] ?? [] as $entry)
		{
			$source = isset($entry['source'])?Assets::relative($path, $entry['source']):$path;
			$set = isset($entry['source'])?$this->assets->json($source):$entry;
			$set['sourceUrl'] = self::properties($set['properties'] ?? [])['phonics.source'] ?? $source;
			$set['firstgid'] = (int)$entry['firstgid'];
			$set['definitions'] = $this->definitions($set, $set['sourceUrl']);
			$map->tilesets[] = $set;
		}

		usort(
			$map->tilesets
			, /**
			 * Order tilesets by their first global tile identifier.
			 *
			 * @param array<string,mixed> $a First tileset record.
			 * @param array<string,mixed> $b Second tileset record.
			 * @return int Negative, zero, or positive comparison of firstgid values.
			 */
			fn($a, $b) => $a['firstgid'] <=> $b['firstgid']
		);

		$this->layers($map, $data['layers'] ?? [], null);

		return $this->maps[$path] = $map;
	}

	/**
	 * Invalidate one loaded map and its source JSON/byte cache.
	 *
	 * @param string $path Exact map cache key to discard.
	 * @return void Future access reloads the map; external dependency caches remain independently
	 * managed.
	 */
	public function forget(string $path): void
	{
		unset($this->maps[$path]);
		$this->assets->forget($path);
	}

	/**
	 * Read an explicit Tiled world manifest without loading its maps.
	 *
	 * @param string $path Tiled world JSON path or URL.
	 * @return World Manifest model with a bounds index and this loader as its asset source.
	 */
	public function world(string $path): World
	{
		return new World($this, $path, $this->assets->json($path));
	}

	/**
	 * Load a standalone tileset for use by sprite sheets or custom maps.
	 *
	 * @param string $path External Tiled tileset JSON path or URL.
	 * @return array<int,array<string,mixed>> Normalized tile definitions keyed by local tile ID,
	 * using firstgid 1.
	 */
	public function tileset(string $path): array
	{
		$set = $this->assets->json($path);
		$set['firstgid'] = 1;

		return $this->definitions($set, $path);
	}

	/**
	 * Normalize tileset images, atlas rectangles, offsets, properties, and animations.
	 *
	 * @param array<string,mixed> $set Decoded tileset augmented with its map-level firstgid.
	 * @param string $path Owning tileset or map filename used to resolve image references.
	 * @return array<int,array<string,mixed>> Tile definitions keyed by local tile ID.
	 */
	private function definitions(array $set, string $path): array
	{
		$definitions = [];
		$specific = [];

		foreach($set['tiles'] ?? [] as $tile)
		{
			$specific[$tile['id']] = $tile;
		}

		$ids = isset($set['image'])?range(0, max(0, ($set['tilecount'] ?? 0) - 1)):array_keys($specific);

		foreach($ids as $id)
		{
			$tile = $specific[$id] ?? [];
			$width = (int)($tile['imagewidth'] ?? $set['tilewidth']);
			$height = (int)($tile['imageheight'] ?? $set['tileheight']);
			$columns = max(1, (int)($set['columns'] ?? 1));
			$margin = $set['margin'] ?? 0;
			$spacing = $set['spacing'] ?? 0;
			$rect = isset($tile['image'])
				? [0,0,$width,$height]
				: [
					$margin + ($id % $columns) * (
						$width + $spacing
					)
					, $margin + intdiv($id, $columns) * ($height + $spacing)
					, $width
					, $height
				];

			foreach($tile['animation'] ?? [] as $frame)
			{
				if(($frame['duration'] ?? 0) <= 0)
				{
					throw new \UnexpectedValueException('Animation durations must be positive');
				}
			}

			$definitions[$id] = $tile + [
				'id' => $id
				, 'firstgid' => $set['firstgid']
				, 'rect' => $rect
				, 'offset' => [$set['tileoffset']['x'] ?? 0,$set['tileoffset']['y'] ?? 0]
			];

			$definitions[$id]['image'] = Assets::relative(
				$path
				, self::properties($tile['properties'] ?? [])['phonics.source.image'] ?? self::properties(
					$set['properties'] ?? []
				)['phonics.source.image'] ?? $tile['image'] ?? $set['image'] ?? throw new \UnexpectedValueException(
					'Tile has no image'
				)
			);

			$definitions[$id]['sourceUrl'] = $path;
			$definitions[$id]['propertyRecords'] = self::propertyRecords($tile['properties'] ?? [], $path);
			$definitions[$id]['properties'] = self::properties($definitions[$id]['propertyRecords']);
		}

		return $definitions;
	}

	/**
	 * Flatten group layers and decode their inherited render state and tile pages.
	 *
	 * @param TileMap $map Destination map receiving normalized layers.
	 * @param list<array<string,mixed>> $layers Tiled layer definitions at the current group level.
	 * @param Layer|null $parent Parent group supplying inherited offsets, tint, opacity,
	 * visibility, and parallax.
	 * @return void Appends normalized leaf layers to the map in painter order.
	 */
	private function layers(TileMap $map, array $layers, ?Layer $parent): void
	{
		foreach($layers as $data)
		{
			if(($data['mode'] ?? 'normal') !== 'normal')
			{
				throw new \UnexpectedValueException('Only normal layer blending is supported');
			}

			$layer = new Layer((int)($data['id'] ?? 0), $data['name'] ?? '', $data['type']);
			$layer->visible = ($parent?->visible ?? true) && ($data['visible'] ?? true);
			$layer->offsetX = ($parent?->offsetX ?? 0) + ($data['offsetx'] ?? 0);
			$layer->offsetY = ($parent?->offsetY ?? 0) + ($data['offsety'] ?? 0);
			$layer->opacity = ($parent?->opacity ?? 1) * ($data['opacity'] ?? 1);
			$layer->parallaxX = ($parent?->parallaxX ?? 1) * ($data['parallaxx'] ?? 1);
			$layer->parallaxY = ($parent?->parallaxY ?? 1) * ($data['parallaxy'] ?? 1);
			$layer->tint = self::color($data['tintcolor'] ?? '#ffffff');

			foreach($layer->tint as $i => &$value)
			{
				$value *= ($parent?->tint[$i] ?? 1);
			}

			unset($value);

			$layer->properties = array_replace(
				$parent?->properties ?? []
				, self::properties(self::propertyRecords($data['properties'] ?? [], $map->path))
			);

			if($data['type'] === 'group')
			{
				$this->layers($map, $data['layers'] ?? [], $layer);
				continue;
			}

			if($data['type'] === 'tilelayer')
			{
				$chunks = $data['chunks'] ?? [
					[
						'x' => $data['x'] ?? 0
						, 'y' => $data['y'] ?? 0
						, 'width' => $data['width']
						, 'height' => $data['height']
						, 'data' => $data['data'] ?? []
					]
				];

				foreach($chunks as $chunk)
				{
					$w = (int)$chunk['width'];
					$h = (int)$chunk['height'];

					if(!$w || !$h)
					{
						continue;
					}

					$bytes = self::decode(
						$chunk['data']
						, $w * $h
						, $data['encoding'] ?? 'csv'
						, $data['compression'] ?? ''
					);

					// Bounded pages also keep a large finite map cullable.
					for($y = 0; $y < $h; $y += 32)
					{
						for($x = 0; $x < $w; $x += 32)
						{
							$cw = min(32, $w - $x);
							$ch = min(32, $h - $y);
							$slice = '';

							for($row = 0; $row < $ch; $row++)
							{
								$slice .= substr($bytes, (($y + $row) * $w + $x) * 4, $cw * 4);
							}

							$layer->chunks[] = new Chunk($chunk['x'] + $x, $chunk['y'] + $y, $cw, $ch, $slice);
						}
					}
				}
			}
			elseif($data['type'] === 'objectgroup')
			{
				$layer->objects = array_map(/**
				 * Apply the registered component factory.
				 *
				 * @param mixed $object Tiled object definition to resolve.
				 * @return mixed Result of this operation.
				 */
					fn($object) => $this->object($object, $map), $data['objects'] ?? []
				);
			}
			elseif($data['type'] === 'imagelayer')
			{
				$layer->image = isset($data['image'])
					? Assets::relative($map->path, $layer->properties['phonics.source.image'] ?? $data['image'])
					: null;

				$layer->imageWidth = (int)($data['imagewidth'] ?? 0);
				$layer->imageHeight = (int)($data['imageheight'] ?? 0);
				$layer->repeatX = (bool)($data['repeatx'] ?? isset($layer->properties['from']));
				$layer->repeatY = (bool)($data['repeaty'] ?? false);

				$layer->transparentColor = isset($data['transparentcolor'])?self::color($data['transparentcolor']):null;

				if(isset($layer->properties['from']))
				{
					if(!in_array($layer->properties['from'], ['top','center','bottom'], true))
					{
						throw new \UnexpectedValueException('Image anchor must be top, center or bottom');
					}

					// Anchored strips stay at the horizon unless vertical parallax is requested.
					$layer->parallaxY = ($parent?->parallaxY ?? 1) * ($data['parallaxy'] ?? 0);
				}

				if(isset($layer->properties['frames']))
				{
					$frames = $layer->properties['frames'];

					if(is_string($frames))
					{
						$frames = json_decode($frames, true, 512, JSON_THROW_ON_ERROR);
					}

					if(!is_array($frames) || !$frames || array_keys($frames) !== range(0, count($frames) - 1))
					{
						throw new \UnexpectedValueException('Image frames must be a nonempty list');
					}

					foreach($frames as $frame)
					{
						if(!is_array($frame) || !is_string($frame['image'] ?? null) || $frame[
							'image'
						] === '' || !is_numeric($frame['duration'] ?? null)
							|| !is_finite((float)$frame['duration']) || $frame['duration'] <= 0
						){
							throw new \UnexpectedValueException('Image frames require an image and positive duration');
						}

						$layer->imageFrames[] = [
							'image' => Assets::relative($map->path, $frame['image'])
							, 'duration' => (float)$frame['duration']
						];
					}
				}
			}
			else
			{
				throw new \UnexpectedValueException('Unsupported layer type ' . $data['type']);
			}

			$map->layers[] = $layer;
		}
	}

	/**
	 * Preserve the declaring source when properties are inherited or merged.
	 *
	 * @param list<array<string,mixed>> $properties Typed Tiled property records.
	 * @param string $source Canonical URL of the declaring resource.
	 * @return list<array<string,mixed>> Property records carrying canonical declaring identity.
	 */
	public static function propertyRecords(array $properties, string $source): array
	{
		$values = self::properties($properties);

		foreach($properties as &$property)
		{
			$property['source'] ??= $source;

			if(($property['type'] ?? '') === 'file'
				|| (($property['name'] ?? '') === 'phonics.entity' && !str_starts_with($property['value'], '@'))
			)
			$property['value'] = Assets::relative(
				$property['source']
				, $values['phonics.source.property.' . $property['name']] ?? $property['value']
			);
		}

		unset($property);

		return $properties;
	}

	/**
	 * Resolve templates and tile defaults before applying instance properties.
	 *
	 * @param array $object Tiled object definition to resolve.
	 * @param TileMap $map Loaded Tiled map defining this stage.
	 * @param array $chain Canonical template references already being resolved.
	 * @param string|null $source Canonical URL of the declaring resource.
	 * @return array Result of this operation.
	 */
	private function object(array $object, TileMap $map, array $chain = [], ?string $source = null): array
	{
		$source ??= $map->path;
		$source = self::properties($object['properties'] ?? [])['phonics.source'] ?? $source;
		$object['properties'] = self::propertyRecords($object['properties'] ?? [], $source);
		$defaults = [];

		if(isset($object['template']))
		{
			$template = Assets::relative(
				$source
				, self::properties($object['properties'] ?? [])['phonics.source.template'] ?? $object['template']
			);

			if(in_array($template, $chain, true))
			throw new \LogicException('Template cycle: ' . implode(' -> ', [...$chain, $template]));

			$data = $this->assets->json($template);
			$defaults = $this->object($data['object'], $map, [...$chain, $template], $template);

			if(isset($defaults['gid'], $data['tileset']['source']))
			{
				$setSource = Assets::relative($template, $data['tileset']['source']);
				$found = false;

				foreach($map->tilesets as $set)
				{
					if($set['sourceUrl'] !== $setSource) continue;

					$defaults['gid'] += $set['firstgid'] - $data['tileset']['firstgid'];
					$found = true;
				}

				if(!$found)
				throw new \UnexpectedValueException("Template tileset $setSource missing from {$map->path}");
			}

			unset($object['template']);
		}

		$tile = isset($object['gid']) ? $map->tile(Gid::from($object['gid'])->id) : null;
		$properties = [];

		foreach([
			...($tile['propertyRecords'] ?? [])
			, ...($defaults['properties'] ?? [])
			, ...$object['properties']
		] as $p)
		$properties[$p['name']] = $p;
		$result = $object + $defaults;
		$result['properties'] = array_values($properties);
		$result['sourceUrl'] = $source;

		if(!isset($result['class']) && !isset($result['type']) && $tile)
		$result['class'] = $tile['class'] ?? $tile['type'] ?? '';

		return $result;
	}

	/**
	 * Decode a JSON GID array or a base64 layer, optionally inflating zlib or gzip.
	 *
	 * @param list<int|float|numeric-string>|string $data Row-major numeric GIDs or encoded base64
	 * text.
	 * @param int $count Expected number of cells, limited to 16 million.
	 * @param string $encoding Layer encoding; encoded strings require base64.
	 * @param string $compression Empty for uncompressed data, or zlib/gzip when the extension is
	 * available.
	 * @return string Exactly four little-endian bytes per cell, with unsupported hexagonal rotation
	 * flags cleared.
	 */
	public static function decode(
		$data
		, int $count
		, string $encoding = 'csv'
		, string $compression = ''
	): string {
		if($count < 0 || $count > 16777216)
		{
			throw new \UnexpectedValueException('Layer exceeds 16M cell limit');
		}

		if(is_array($data))
		{
			if(count($data) !== $count)
			{
				throw new \UnexpectedValueException('Layer length mismatch');
			}

			$bytes = '';

			foreach($data as $gid)
			{
				$bytes .= Gid::from($gid)->bytes();
			}

			return $bytes;
		}

		if($encoding !== 'base64')
		{
			throw new \UnexpectedValueException('Expected JSON array or base64 layer');
		}

		$bytes = base64_decode(preg_replace('/\s+/', '', $data), true);

		if($bytes === false)
		{
			throw new \UnexpectedValueException('Invalid base64 layer');
		}

		if($compression === 'zlib')
		{
			$bytes = function_exists('gzuncompress')?@gzuncompress($bytes, $count * 4 + 1):false;
		}
		elseif($compression === 'gzip')
		{
			$bytes = function_exists('gzdecode')?@gzdecode($bytes, $count * 4 + 1):false;
		}
		elseif($compression !== '')
		{
			throw new \UnexpectedValueException(
				"Unsupported compression: $compression (export zlib/gzip or uncompressed)"
			);
		}

		if(!is_string($bytes) || strlen($bytes) !== $count * 4)
		{
			throw new \UnexpectedValueException('Invalid compressed data or layer length');
		}

		// Clear the hexagonal rotation bit even on orthogonal/isometric exports.
		for($i = 3; $i < strlen($bytes); $i += 4)
		{
			$bytes[$i] = chr(ord($bytes[$i]) & 239);
		}

		return $bytes;
	}

	/**
	 * Flatten Tiled's named property records into a lookup array.
	 *
	 * @param list<array{name:string,value:mixed}> $properties Decoded Tiled property records.
	 * @return array<string,mixed> Property values keyed by name, with later duplicates replacing
	 * earlier ones.
	 */
	public static function properties(array $properties): array
	{
		$result = [];

		foreach($properties as $p)
		{
			$result[$p['name']] = $p['value'];
		}

		return $result;
	}

	/**
	 * Decode Tiled RGB or ARGB hexadecimal color notation.
	 *
	 * @param string $hex Six-digit RGB or eight-digit ARGB color, optionally prefixed with #.
	 * @return array{0:float,1:float,2:float,3:float} Normalized red, green, blue, and alpha
	 * channels in [0,1].
	 */
	public static function color(string $hex): array
	{
		$hex = ltrim($hex, '#');

		if(strlen($hex) === 6)
		{
			$hex = 'ff' . $hex;
		}

		if(!preg_match('/^[0-9a-f]{8}$/i', $hex))
		{
			throw new \InvalidArgumentException('Invalid Tiled color');
		}

		$v = array_map(
			/**
			 * Normalize one hexadecimal color channel.
			 *
			 * @param string $v Two hexadecimal digits for one color channel.
			 * @return float Channel intensity in [0,1].
			 */
			fn($v) => hexdec($v) / 255
			, str_split($hex, 2)
		);

		return [$v[1],$v[2],$v[3],$v[0]];
	}
}
