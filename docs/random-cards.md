# Deterministic random streams and finite piles

Use `RandomStream` when a game needs to repeat the same dice rolls, player selections, or shuffles from a supplied seed. Use `PileSet` to track a fixed collection of cards as they move between a deck, hands, and discards. The same card instance must always be in exactly one pile.

These APIs support local games and games where the random state is public. They do not establish agreement between players or hide cards from them. A known seed reveals the future stream; private online dealing needs a separate protocol.

## Generate repeatable results

Create a `Phonics\Random\RandomStream` with seed bytes and a UTF-8 purpose string. Repeating the same operations with the same seed and purpose produces the same results:

```php
$seedBytes = 'example-seed'; // Supplied by the game; this example seed is public.
$random = new Phonics\Random\RandomStream($seedBytes, 'example/deck/v1');
$dealer = $random->boundedInt(4); // A seat index from 0 through 3.
$roll = $random->dice(6);         // A die result from 1 through 6.
$deck = $random->shuffle(range(0, 51));

// Restore an independent stream at exactly this position.
$saved = $random->snapshot();
$continued = Phonics\Random\RandomStream::restore($saved);
```

`boundedInt(bound)` returns a zero-based index below `bound`; `dice(sides)` returns a one-based result. `shuffle()` returns a shuffled copy of its input. `sampleWithoutReplacement($values, $count)` selects distinct input positions, so two equal values can still represent two separate card instances.

### Keep operation order consistent

Every operation consumes part of the stream. To reproduce a game, keep both the operation order and the initial order of shuffled collections consistent. Give unrelated purposes their own streams: for example, deck shuffling and bot decisions should not consume each other's randomness. Presentation code should not advance the deck stream.

The JavaScript export `@seanmorris/phonics/random` produces the same byte format and bounded integer results as PHP. Its sampling methods are asynchronous. Await each operation on a stream before starting the next:

```js
import {RandomStream} from '@seanmorris/phonics/random';

const random = new RandomStream('example-seed', 'example/deck/v1');
const dealer = await random.boundedInt(4);
const roll = await random.dice(6);
const deck = await random.shuffle(Array.from({length: 52}, (_, i) => i));
const saved = random.snapshot();
```

Starting concurrent shuffles on one stream can interleave their byte consumption. Sequential calls preserve a reproducible order. Snapshots store the seed as base64, the purpose as `domain`, and the absolute byte position; take them after pending operations finish.

## Track cards across piles

`Phonics\Cards\PileSet` starts with an inventory mapping unique instance IDs to card definitions. In the example below, `a` and `b` are separate cards that both use the definition `ace`. Every instance must occur exactly once across all named piles.

Each pile contains a sequence of sections, called *segments* in the API. An `ordered` segment keeps its listed order. A `random` segment acquires a shuffled order when first inspected or drawn from, then keeps that order. Segments are nonempty; an empty pile is an empty list.

The example deck has one known card on top, a random middle section, and one known card at the bottom:

```php
$piles = new Phonics\Cards\PileSet(
    ['a'=>'ace', 'b'=>'ace', 'c'=>'king', 'd'=>'queen'],
    [
        'deck'=>[
            ['kind'=>'ordered', 'cards'=>['a']],
            ['kind'=>'random', 'cards'=>['b','c']],
            ['kind'=>'ordered', 'cards'=>['d']],
        ],
        'hand'=>[], 'discard'=>[],
    ],
    new Phonics\Random\RandomStream($seedBytes, 'example/piles/v1')
);
$next = $piles->peek('deck');         // ['a']; still in the deck.
$dealt = $piles->move('deck', 'hand'); // ['a']; now in the hand.
$piles->moveCards('hand', 'discard', $dealt);
```

`peek()` and `move()` return arrays of instance IDs, including when the default count is one. A peek does not remove cards. Drawing with `move()` returns the same cards as a preceding peek, provided no intervening operation changes that part of the pile.

### Move, cut, and shuffle

| Operation | Effect |
| --- | --- |
| `peek($pile, $count)` | Inspect the first cards without moving them. |
| `move($from, $to, $count)` | Move the first cards into a new ordered segment at the destination. |
| `moveCards($from, $to, $cards)` | Move selected instance IDs into a new ordered segment at the destination. |
| `cut($from, $to, $count)` | Move the first cards to an empty destination, preserving segment boundaries. |
| `shuffle($pile, $start, $count)` | Replace only the selected range with one fixed random permutation. `start` is zero-based; omitting `count` includes the remaining cards. |

Both move methods insert at the destination bottom by default. Pass `top: true` to insert at the top. Neighboring random segments do not merge automatically.

A random segment's order is chosen once when touched by a peek, draw, or cut. Invalid arguments are rejected before an operation can consume random state or move cards, so a failed operation cannot be used to try another result. An explicit `shuffle()` is how the caller requests a new order.

`PileSet::snapshot()` saves every pile, each resolved random order, and the random stream position. `PileSet::restore()` restores them together without drawing a new result.

## Keep private online dealing separate

This backend contains the full card identities and random state. It must not serve as a hidden-hand authority. Never use a public stream to generate secret keys, re-encryption masks, private permutations, or proof randomness.

A private online backend needs opaque card handles, verified setup and shuffles, and a complete allocation acceptance certificate before consuming a card. That certificate records the protocol's acceptance of the allocation; a local pile move alone is not authorization to deal it.

These APIs do not supply multiplayer consensus, decryption authorization, duplicate-action receipts, or hidden-hand security. The caller must validate each operation and its revision, then record the accepted change atomically. [Poker's private online dealing](https://github.com/seanmorris/phonics-examples/blob/main/games/php-poker/docs/architecture.md) demonstrates that separation between game operations and the network protocol.

## Byte format and unbiased sampling

The PHP and JavaScript implementations share a versioned byte format. Version 1 hashes the following fields, in order, with SHA-256:

1. The prefix `Phonics.RandomStream.v1\0`, ending in a zero byte.
2. The seed byte length as a four-byte big-endian integer, followed by the seed bytes.
3. The UTF-8 purpose byte length as a four-byte big-endian integer, followed by the purpose bytes.
4. A four-byte big-endian block counter, starting at zero.

Each block contains 32 bytes. The stream position is an absolute byte offset, so restoring or changing the size of byte reads does not change the stream.

`boundedInt(bound)` uses rejection sampling to avoid favoring some outcomes when the bound does not divide the 32-bit range evenly. It reads an unsigned big-endian 32-bit value and accepts it only when it is below `floor(2^32 / bound) * bound`. It rejects larger values and tries again, then returns the accepted value modulo `bound`.

Every possible result has exactly `floor(2^32 / bound)` accepted input values. This avoids modulo bias, including for 3-, 5-, 6-, and 7-seat selections. Supported bounds run from 1 through 2^31−1. `dice()`, `shuffle()`, and `sampleWithoutReplacement()` build on this operation; it does not establish that a multiplayer seed was fairly chosen.

## Verify the implementations

Tests compare known results across native PHP and the browser, check rejected samples and restored byte positions, and exercise repeated pile operations without losing or duplicating cards. They also cover stable peeks and cuts, range boundaries, and invalid operations leaving state unchanged.

```sh
php tests/cards-random.php
node --test tests/packages/random-stream.test.mjs
```
