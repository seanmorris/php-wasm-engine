<?php
declare(strict_types=1);
namespace Phonics\Tiled;

/** Loaded Tiled map with mutable placement/layers and shared normalized tileset definitions. */
final class TileMap
{
	/** @var list<Layer> Flattened display and object layers in painter order. */
	public array $layers = [];
	/** @var list<array<string,mixed>> Normalized tilesets sorted by first global tile ID. */
	public array $tilesets = [];
	/** Mutable horizontal world placement in pixels. */
	public float $x = 0;
	/** Mutable vertical world placement in pixels. */
	public float $y = 0;
	/** Coordinate converter derived from the map orientation and tile dimensions. */
	public Projection $projection;
	/** Stable placement identity, distinct for repeated uses of the same source map. */
	public string $identity;

	/**
	 * Copy mutable layer/chunk state while sharing immutable source definitions.
	 *
	 * @return void Replaces every layer and chunk with a mutable clone.
	 */
	public function __clone()
	{
		foreach($this->layers as $index => $layer)
		{
			$this->layers[$index] = clone $layer;

			foreach($layer->chunks as $key => $chunk) $this->layers[$index]->chunks[$key] = clone $chunk;
		}
	}

	/**
	 * Create a map model and its coordinate projection from decoded Tiled metadata.
	 *
	 * @param string $path Map source path used to resolve dependent assets.
	 * @param array<string,mixed> $data Decoded Tiled map containing tile dimensions and optional
	 * orientation/bounds.
	 * @return void Initializes projection and empty flattened layer and tileset collections.
	 */
	public function __construct(public string $path, public array $data)
	{
		$this->identity = $path;

		$this->projection = new Projection(
			$data['orientation'] ?? 'orthogonal'
			, (int)$data['tilewidth']
			, (int)$data['tileheight']
			, !empty($data['infinite'])?0:(int)($data['height'] ?? 0)
		);
	}

	/**
	 * Resolve a global tile ID against the map's sorted tilesets.
	 *
	 * @param int $gid Global tile identifier with flip bits already removed.
	 * @return array<string,mixed>|null Normalized tile definition, or null when the ID is absent.
	 */
	public function tile(int $gid): ?array
	{
		foreach(array_reverse($this->tilesets) as $set)
		{
			if($gid >= $set['firstgid'])
			{
				return $set['definitions'][$gid - $set['firstgid']] ?? null;
			}
		}

		return null;
	}

	/**
	 * Select the current looping frame for an animated tile.
	 *
	 * @param int $gid Global animation tile identifier without flip flags.
	 * @param float $milliseconds Playback time in milliseconds; negative values select the initial
	 * frame.
	 * @return int Frame GID, or the supplied GID when the tile has no animation.
	 */
	public function animatedGid(int $gid, float $milliseconds): int
	{
		$tile = $this->tile($gid);
		$animation = $tile['animation'] ?? [];

		if(!$animation)
		{
			return $gid;
		}

		$total = array_sum(array_column($animation, 'duration'));
		$time = fmod(max(0, $milliseconds), $total);

		foreach($animation as $frame)
		{
			if($time < $frame['duration'])
			{
				return $tile['firstgid'] + $frame['tileid'];
			}

			$time -= $frame['duration'];
		}

		return $gid;
	}

	/**
	 * Find the first flattened layer with the requested editor name.
	 *
	 * @param string $name Exact layer name to match.
	 * @return Layer Matching layer; a missing name raises OutOfBoundsException.
	 */
	public function layer(string $name): Layer
	{
		foreach($this->layers as $layer)
		{
			if($layer->name === $name)
			{
				return $layer;
			}
		}

		throw new \OutOfBoundsException("No layer $name");
	}
}
