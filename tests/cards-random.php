<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
use Phonics\Random\RandomStream;
use Phonics\Cards\PileSet;

$tests = [];

$test = static function(string $name, callable $run) use (&$tests): void { $tests[$name] = $run; };

$expect = static function(bool $ok, string $message = 'Assertion failed'): void {
	if(!$ok) throw new RuntimeException($message);
};

$reject = static function(callable $run) use ($expect): void {
	try { $run(); } catch(InvalidArgumentException) { return; }

	$expect(false, 'Expected invalid input rejection');
};

$test('stream preserves exact offsets across chunk boundaries and restore', static function() use ($expect): void {
	$a = new RandomStream("seed\0bytes", 'deck');
	$b = new RandomStream("seed\0bytes", 'deck');
	$expect($a->nextBytes(93) === $b->nextBytes(3) . $b->nextBytes(29) . $b->nextBytes(61));
	$c = RandomStream::restore($a->snapshot());
	$expect($a->nextBytes(200) === $c->nextBytes(200));
	$expect((new RandomStream('ab', 'c'))->nextBytes(32) !== (new RandomStream('a', 'bc'))->nextBytes(32));
});

$test('bounded rejection consumes rejected values and stays inside each bound', static function() use ($expect): void {
	$found = false;

	for($seed = 0; $seed < 50; $seed++)
	{
		$a = new RandomStream((string)$seed);
		$b = new RandomStream((string)$seed);
		$bound = 1073741825;
		$limit = 4294967296.0 - fmod(4294967296.0, $bound);
		$draws = 0;

		do
		{
			$v = hexdec(bin2hex($a->nextBytes(4)));
			$draws++;
		} while($v >= $limit);

		$expect($b->boundedInt($bound) === (int)fmod($v, $bound));
		$expect($b->snapshot()['position'] === $draws * 4);
		$found = $found || $draws > 1;
	}

	$expect($found, 'Fixture must exercise rejection');

	foreach([1,2,3,4,5,6,7,8,52,32768,32769,65535,65536,65537,1073741825,2147483647] as $bound)
	{
		$r = new RandomStream('bounds');
		$oracle = new RandomStream('bounds');
		$limit = 4294967296.0 - fmod(4294967296.0, $bound);

		for($i = 0; $i < 100; $i++)
		{
			$value = $r->boundedInt($bound);
			$expect($value >= 0 && $value < $bound);

			// Independent exactly representable double oracle for the integer-limb implementation.
			do
			{
				$word = hexdec(bin2hex($oracle->nextBytes(4)));
			} while($word >= $limit);

			$expect($value === (int)fmod($word, $bound));
			$expect($r->snapshot()['position'] === $oracle->snapshot()['position']);
		}

		$expect(fmod($limit, $bound) === 0.0, 'Every residue has exactly limit/bound accepted preimages');
	}
});

$test('sampling and invalid input preserve finite populations', static function() use ($expect, $reject): void {
	$r = new RandomStream('sample');
	$cards = range(0, 51);
	$shuffled = $r->shuffle($cards);
	$sorted = $shuffled;
	sort($sorted);
	$expect($sorted === $cards && $shuffled !== $cards);
	$sample = $r->sampleWithoutReplacement($cards, 12);
	$expect(count(array_unique($sample)) === 12);
	$state = $r->snapshot();
	$reject(static fn() => $r->boundedInt(0));
	$reject(static fn() => $r->sampleWithoutReplacement($cards, 53));
	$expect($state === $r->snapshot());
});

$striped = static fn() => new PileSet(array_fill_keys(range(0, 7), 'same-face'), [
	'deck' => [
		['kind' => 'ordered','cards' => [0,1]],['kind' => 'random','cards' => [2,3,4]]
		, [
			'kind' => 'ordered'
			, 'cards' => [
				5
			]
		]
		, ['kind' => 'random','cards' => [6,7]]
	]
	, 'hand' => []
	, 'cut' => []
], new RandomStream('striped'));

$test('ordered/random stripes preserve peeks, cuts and top insertion', static function() use ($expect, $striped): void {
	$p = $striped();
	$expect($p->peek('deck', 2) === [0,1]);
	$peek = $p->peek('deck', 5);
	$snapshot = $p->snapshot();
	$expect($peek === $p->peek('deck', 5) && $snapshot === $p->snapshot());
	$p->cut('deck', 'cut', 4);
	$expect($p->peek('cut', 4) === array_slice($peek, 0, 4));
	$expect(count($p->snapshot()['piles']['cut']) === 2);
	$p->move('cut', 'hand', 4);
	$p->move('deck', 'hand', 1, true);
	$expect($p->peek('hand')[0] === $peek[4]);
	$p->validate();
	$expect(PileSet::restore($p->snapshot())->snapshot() === $p->snapshot());
});

$test(
	'invalid moves leave cards and random position unchanged'
	, static function() use ($expect, $reject, $striped): void {
		$p = $striped();
		$before = $p->snapshot();
		$reject(static fn() => $p->move('deck', 'missing', 1));
		$reject(static fn() => $p->move('deck', 'hand', 9));
		$reject(static fn() => $p->moveCards('deck', 'hand', [0,0]));
		$reject(static fn() => $p->moveCards('deck', 'hand', [88]));
		$reject(static fn() => $p->shuffle('deck', -1, 3));
		$expect($before === $p->snapshot());
	}
);

$test(
	'range shuffles and repeated cross-pile moves conserve instance identities'
	, static function() use ($expect, $striped): void {
		$p = $striped();
		$original = $p->peek('deck', 8);
		$p->shuffle('deck', 2, 3);
		$after = $p->peek('deck', 8);
		$expect(array_slice($after, 0, 2) === array_slice($original, 0, 2));
		$expect(array_slice($after, 5) === array_slice($original, 5));
		$r = new RandomStream('operations');

		for($i = 0; $i < 300; $i++)
		{
			$from = $r->boundedInt(2) ? 'deck' : 'hand';
			$to = $from === 'deck'?'hand':'deck';

			if($p->count($from)) $p->move($from, $to, $r->boundedInt($p->count($from) + 1), (bool)$r->boundedInt(2));

			$p->validate();
			$expect($p->count('deck') + $p->count('hand') === 8);
		}
	}
);

$failed = 0;

foreach($tests as $name => $run)
try
{
	$run();
	echo "PASS $name\n";
} catch(Throwable $e)
{
	$failed++;
	fwrite(STDERR, "FAIL $name: {$e->getMessage()}\n");
}

echo count($tests) . " random/card tests, $failed failures\n";
exit($failed?1:0);
