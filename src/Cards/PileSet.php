<?php
declare(strict_types=1);
namespace Phonics\Cards;

use Phonics\Random\RandomStream;

/**
 * A finite inventory partitioned into striped ordered and random piles.
 *
 * Random segments materialize once on inspection or consumption. The local/public
 * backend owns all instance identities; it is deliberately not a private online
 * dealer. A network adapter must authorize opaque allocations before committing.
 */
final class PileSet
{

	/**
	 * Validate an inventory and all of its initial locations.
	 * @param array $inventory Instance ID to definition mapping; duplicate faces are allowed.
	 * @param array $piles Named lists of segments containing kind, cards and optional fixed.
	 * @param RandomStream $random Independent pile random stream.
	 * @return void Validates conservation before exposing the pile set.
	 */
	public function __construct(private array $inventory, private array $piles, private RandomStream $random)
	{
		$this->validate();
	}

	/**
	 * Serialize all locations without sampling another random result.
	 * @return array Full local/public inventory and pile state, including materialized order.
	 */
	public function snapshot(): array
	{
		return [
			'version' => 1
			, 'inventory' => $this->inventory
			, 'piles' => $this->piles
			, 'random' => $this->random->snapshot()
		];
	}

	/**
	 * Restore all piles and exact random state together.
	 * @param array $state Snapshot produced by this backend.
	 * @return self Validated pile set.
	 */
	public static function restore(array $state): self
	{
		if(($state['version'] ?? null) !== 1 || !is_array($state['inventory'] ?? null)
			|| !is_array($state['piles'] ?? null) || !is_array($state['random'] ?? null)
		)
		throw new \InvalidArgumentException('Invalid pile snapshot');

		return new self($state['inventory'], $state['piles'], RandomStream::restore($state['random']));
	}

	/**
	 * Count instances without sampling their order.
	 * @param string $pile Existing pile name.
	 * @return int Number of instances in the pile.
	 */
	public function count(string $pile): int
	{
		$this->requirePile($pile);
		$count = 0;

		foreach($this->piles[$pile] as $segment) $count += count($segment['cards']);

		return $count;
	}

	/**
	 * Inspect a stable prefix, materializing touched random segments only once.
	 * @param string $pile Existing pile name.
	 * @param int $count Number of instances, or zero for an empty prefix.
	 * @return array Ordered instance IDs; repeated peeks return the same IDs.
	 */
	public function peek(string $pile, int $count = 1): array
	{
		if($count < 0 || $count > $this->count($pile)) throw new \InvalidArgumentException('Pile underflow');

		$result = [];

		foreach($this->piles[$pile] as &$segment)
		{
			if(count($result) >= $count) break;

			$this->fix($segment);
			$result = array_merge($result, array_slice($segment['cards'], 0, $count - count($result)));
		}

		unset($segment);

		return $result;
	}

	/**
	 * Move a stable prefix atomically, inserting an ordered segment at the destination.
	 * @param string $from Source pile.
	 * @param string $to Destination pile, distinct from the source.
	 * @param int $count Number of instances to move.
	 * @param bool $top Whether to put the moved cards above the existing destination.
	 * @return array Moved instance IDs in draw order.
	 */
	public function move(string $from, string $to, int $count = 1, bool $top = false): array
	{
		$this->requirePile($to);

		if($from === $to) throw new \InvalidArgumentException('Move needs distinct piles');

		$cards = $this->peek($from, $count);
		$remaining = $count;

		while($remaining > 0)
		{
			$take = min($remaining, count($this->piles[$from][0]['cards']));
			array_splice($this->piles[$from][0]['cards'], 0, $take);
			$remaining -= $take;

			if(!$this->piles[$from][0]['cards']) array_shift($this->piles[$from]);
		}

		$this->insert($to, $cards, $top);

		return $cards;
	}

	/**
	 * Move chosen visible instances while preserving every other segment's order.
	 * @param string $from Source pile.
	 * @param string $to Destination pile.
	 * @param array $cards Distinct instance IDs already in the source.
	 * @param bool $top Whether to insert above the destination.
	 * @return void Moves all selected instances or throws before mutation.
	 */
	public function moveCards(string $from, string $to, array $cards, bool $top = false): void
	{
		$this->requirePile($from);
		$this->requirePile($to);
		$flat = array_merge([], ...array_column($this->piles[$from], 'cards'));

		if($from === $to || count(array_unique($cards, SORT_REGULAR)) !== count($cards))
		throw new \InvalidArgumentException('Invalid chosen-card move');

		foreach($cards as $id)
		if(!in_array($id, $flat, true))
		throw new \InvalidArgumentException('Card outside source');
		$segments = [];

		foreach($this->piles[$from] as $segment)
		{
			$remaining = [];

			foreach($segment['cards'] as $id) if(!in_array($id, $cards, true)) $remaining[] = $id;

			if($remaining)
			{
				$segment['cards'] = $remaining;
				$segments[] = $segment;
			}
		}

		$this->piles[$from] = $segments;
		$this->insert($to, array_values($cards), $top);
	}

	/**
	 * Cut a prefix into an empty pile, retaining ordered/random stripe boundaries.
	 * @param string $from Source pile.
	 * @param string $to Empty destination pile.
	 * @param int $count Number of instances in the prefix.
	 * @return void Fixes one partition instead of sampling again on later inspection.
	 */
	public function cut(string $from, string $to, int $count): void
	{
		if($from === $to || $this->count($to) !== 0 || $count < 0 || $count > $this->count($from))
		throw new \InvalidArgumentException('Invalid cut');

		$this->peek($from, $count);

		while($count > 0)
		{
			$segment = $this->piles[$from][0];
			$take = min($count, count($segment['cards']));
			$segment['cards'] = array_splice($this->piles[$from][0]['cards'], 0, $take);
			$this->piles[$to][] = $segment;
			$count -= $take;

			if(!$this->piles[$from][0]['cards']) array_shift($this->piles[$from]);
		}
	}

	/**
	 * Explicitly shuffle a contiguous range, preserving the surrounding stripes.
	 * @param string $pile Target pile.
	 * @param int $start Zero-based first position.
	 * @param int|null $count Number of cards, or the complete remaining suffix.
	 * @return void Creates a new random segment with one committed permutation.
	 */
	public function shuffle(string $pile, int $start = 0, ?int $count = null): void
	{
		$total = $this->count($pile);
		$count ??= $total - $start;

		if($start < 0 || $count < 0 || $start + $count > $total)
		throw new \InvalidArgumentException('Invalid shuffle range');

		if($count === 0) return;

		$before = $after = $cards = [];
		$offset = 0;

		foreach($this->piles[$pile] as $segment)
		{
			$end = $offset + count($segment['cards']);

			if($end <= $start) $before[] = $segment;
			elseif($offset >= $start + $count) $after[] = $segment;
			else
			{
				$this->fix($segment);
				$a = max(0, $start - $offset);
				$b = min(count($segment['cards']), $start + $count - $offset);

				if($a) $before[] = array_replace($segment, ['cards' => array_slice($segment['cards'], 0, $a)]);

				$cards = array_merge($cards, array_slice($segment['cards'], $a, $b - $a));

				if($b < count($segment['cards']))
				$after[] = array_replace($segment, [
					'cards' => array_slice($segment['cards'], $b)
				]);
			}

			$offset = $end;
		}

		$this->piles[$pile] = array_merge($before, [
			[
				'kind' => 'random'
				, 'cards' => $this->random->shuffle($cards)
				, 'fixed' => true
			]
		], $after
		);
	}

	/**
	 * Verify the finite inventory partition and segment schema.
	 * @return void Throws if an instance is missing, repeated or outside the agreed inventory.
	 */
	public function validate(): void
	{
		if(count($this->inventory) > 100000 || count($this->piles) > 1024)
		throw new \InvalidArgumentException('Inventory too large');

		$seen = [];

		foreach($this->piles as $name => $segments)
		{
			if(!is_string($name) || $name === '' || !is_array($segments) || array_values($segments) !== $segments)
			throw new \InvalidArgumentException('Invalid pile');

			foreach($segments as $segment)
			{
				if(!is_array($segment) || !in_array($segment['kind'] ?? null, ['ordered','random'], true)
					|| !is_array($segment['cards'] ?? null) || !$segment['cards'] || array_values($segment[
						'cards'
					]) !== $segment['cards']
					|| (isset($segment['fixed']) && !is_bool($segment['fixed']))
				)
				throw new \InvalidArgumentException('Invalid pile segment');

				foreach($segment['cards'] as $id)
				{
					if((!is_int($id) && !is_string($id)) || !array_key_exists($id, $this->inventory) || isset($seen[
						$id
					]))
					throw new \InvalidArgumentException('Inventory instance missing or repeated');

					$seen[$id] = true;
				}
			}
		}

		if(count($seen) !== count($this->inventory)) throw new \InvalidArgumentException('Inventory is not conserved');
	}

	/**
	 * Reject an undeclared location.
	 * @param string $pile Existing pile name.
	 * @return void Rejects undeclared piles.
	 */
	private function requirePile(string $pile): void
	{
		if(!array_key_exists($pile, $this->piles)) throw new \InvalidArgumentException('Unknown pile');
	}

	/**
	 * Materialize one random stripe at most once.
	 * @param array $segment Segment to materialize once.
	 * @return void Fixes random order.
	 */
	private function fix(array &$segment): void
	{
		if($segment['kind'] === 'random' && !($segment['fixed'] ?? false))
		{
			$segment['cards'] = $this->random->shuffle($segment['cards']);
			$segment['fixed'] = true;
		}
	}

	/**
	 * Insert one ordered stripe without implicitly merging random neighbors.
	 * @param string $pile Destination pile.
	 * @param array $cards Ordered IDs.
	 * @param bool $top Whether to prepend the segment.
	 * @return void Adds a nonempty segment.
	 */
	private function insert(string $pile, array $cards, bool $top): void
	{
		if(!$cards) return;

		$segment = ['kind' => 'ordered', 'cards' => $cards];

		if($top) array_unshift($this->piles[$pile], $segment);
		else $this->piles[$pile][] = $segment;
	}
}
