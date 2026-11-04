# Deterministic random streams and finite piles

`Phonics\Random\RandomStream` accepts explicit seed bytes and a UTF-8 purpose. Version 1 hashes `Phonics.RandomStream.v1\0`, a four-byte big-endian seed length, seed bytes, a four-byte purpose byte length, purpose bytes, and a four-byte block counter with SHA-256. Blocks contain 32 bytes; snapshots record the absolute byte offset. The JavaScript export `@seanmorris/phonics/random` uses the same format, base64 snapshot seed and bounded integer semantics. Await composite JavaScript operations in canonical order.

`boundedInt(bound)` reads a big-endian unsigned 32-bit word. It accepts values below `floor(2^32 / bound) * bound` and rejects the rest, then takes the remainder. Every residue has exactly `floor(2^32 / bound)` accepted preimages. Bounds are 1 through 2^31−1; 3-, 5-, 6- and 7-seat selections therefore avoid modulo bias. `dice`, `shuffle` and `sampleWithoutReplacement` build on this operation.

```php
$random = new Phonics\Random\RandomStream($seedBytes, 'example/deck/v1');
$dealer = $random->boundedInt($seatCount);
$deck = $random->shuffle(range(0, 51));
$saved = $random->snapshot();
$continued = Phonics\Random\RandomStream::restore($saved);
```

A known seed reveals the future stream. This library supplies deterministic local/public sampling, not network agreement or secret-deck encryption. Never feed a public stream into secret keys, re-encryption masks, private permutations or proof randomness. Distinct deck and bot streams keep presentation and opponent policy from consuming deck randomness.

`Phonics\Cards\PileSet` owns an agreed instance-to-definition inventory and named locations. Instance IDs are distinct even when definitions repeat. Every instance must occur exactly once across all piles. A pile is a sequence of nonempty `ordered` or `random` segments; empty piles have no segments.

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
$next = $piles->peek('deck');
$dealt = $piles->move('deck', 'hand'); // Same instance as the peek.
$piles->moveCards('hand', 'discard', $dealt);
```

A random stripe materializes once when touched by a peek, draw or cut. Failed argument validation cannot resample a different result. `cut` moves a stable prefix into an empty destination and retains stripe boundaries. `move`/`moveCards` insert an ordered stripe at the destination top or bottom; neighboring random stripes do not merge implicitly. `shuffle(pile, start, count)` explicitly replaces only that range with one fixed random permutation. Snapshots retain materialized order and the sampler offset.

This backend contains full local/public identities. A private online backend needs opaque handles, verified setup/shuffles and a complete allocation acceptance certificate before consuming a card. These APIs do not themselves supply consensus, decryption authorization, duplicate-action receipts or hidden-hand security. The caller must validate and atomically record an operation/revision; the poker reducer demonstrates that boundary.

Verification includes native/browser golden vectors, rejection-path accounting, restore/chunk boundaries, finite-inventory property sequences, stable peeks/cuts, range boundaries and invalid-operation atomicity. Run `php tests/cards-random.php` and `node --test tests/packages/random-stream.test.mjs`.
