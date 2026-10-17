<?php
use PhpWasmEngine\Input\Controls;
use PhpWasmEngine\Input\Sequences;

/**
 * Deliver a fresh logical tap with a release between consecutive presses.
 *
 * @param Sequences $input Matcher receiving the tap.
 * @param int|string $button Logical button or direction.
 * @param float $time Press timestamp in seconds.
 * @return void Advances through the press and release snapshots.
 */
function sequenceTap(Sequences $input, int|string $button, float $time): void
{
	$input->update([$button => 1], $time);
	$input->update([], $time + .01);
}

test('numbered buttons and existing action names share strengths and edges', function() {
	$input = new Controls;
	$input->set(['jump' => true, 'button0' => .5, 'left' => .75]);
	check($input->pressed(0) && $input->pressed('Button0') && $input->pressed('jump'));
	near($input->value(0), 1);
	near($input->value(14), .75);
	$input->set([0 => true, 15 => .25]);
	check(!$input->pressed('jump') && $input->released(14));
	near($input->axis(14, 15), .25);
	$input->set([]);
	check($input->released(0) && $input->released('jump'));
});

test('Konami and moving-boxes debug sequences use the same numbered input stream', function() {
	$input = new Sequences;
	$input->add('konami', ['up','up','down','down','left','right','left','right',1,0], .75);
	$input->add('debug', [12,12,13,13,12,12,12,12], .75);

	foreach([12,12,13,13,14,15,14,15,'action','jump'] as $index => $key)
	{
		sequenceTap($input, $key, $index * .1);
	}

	$matches = $input->drain();
	check(array_column($matches, 'name') === ['konami']);
	near($matches[0]['duration'], .9);
	check($input->drain() === []);

	foreach([12,12,13,13,12,12,12,12] as $index => $key)
	{
		sequenceTap($input, $key, 2 + $index * .1);
	}

	check(array_column($input->drain(), 'name') === ['debug']);
});

test('sequences ignore repeats and releases and enforce both timing limits', function() {
	$input = new Sequences;
	$input->add('double', [0,0], .25);
	$input->update([0 => 1], 0);
	$input->update([0 => 1], .1);
	check($input->progress('double') === 1 && $input->drain() === []);
	$input->update([], .2);
	$input->update([], .26);
	check($input->progress('double') === 0);
	sequenceTap($input, 0, .4);
	sequenceTap($input, 0, .65);
	check(count($input->drain()) === 1);
	$input->add('slow', [1,2,3], .5, .6);
	sequenceTap($input, 1, 1);
	sequenceTap($input, 2, 1.4);
	sequenceTap($input, 3, 1.8);
	check($input->drain() === []);
});

test('overlapping prefixes restart correctly and simultaneous buttons are not ordered', function() {
	$input = new Sequences;
	$input->add('overlap', [0,0,1], .5);

	foreach([0,0,0,1] as $index => $button)
	{
		sequenceTap($input, $button, $index * .1);
	}

	check(count($input->drain()) === 1);
	$input->add('ordered', [2,3]);
	$input->update([2 => 1, 3 => 1], 1);
	check($input->drain() === []);
	$input->update([], 1.1);
	sequenceTap($input, 3, 1.2);
	check(array_column($input->drain(), 'name') === ['ordered']);
	$input->add('strict', [0,1]);
	sequenceTap($input, 0, 2);
	sequenceTap($input, 2, 2.1);
	sequenceTap($input, 1, 2.2);
	check($input->drain() === []);
});

test('fighting motions support diagonals, facing, chords and fresh attack presses', function() {
	foreach([1,-1] as $facing)
	{
		$input = new Sequences;
		$input->setFacing($facing);
		$input->add('motion', ['down','down-forward','forward',0], .25);
		$forward = $facing === 1 ? 'right' : 'left';
		$input->update(['down' => 1], 0);
		$input->update(['down' => 1, $forward => 1], .05);
		$input->update([$forward => 1], .1);
		$input->update([$forward => 1, 0 => 1], .15);
		check(array_column($input->drain(), 'name') === ['motion']);
		$input->add('uppercut', [['press' => 2, 'held' => ['down']]]);
		$input->update(['down' => 1], .2);
		$input->update(['down' => 1, 2 => 1], .3);
		check(array_column($input->drain(), 'name') === ['uppercut']);
		$input->update(['down' => 1, 2 => 1], .4);
		check($input->drain() === []);
		$input->add('chord', [[0,1]]);
		$input->update([0 => 1], .5);
		$input->update([0 => 1, 1 => 1], .6);
		check(array_column($input->drain(), 'name') === ['chord']);
	}
});

test(
	'charge releases require the full hold and facing changes discard partial motions'
	, function() {
		$input = new Sequences;
		$input->add('charge', [['release' => 0, 'minHold' => .5], 1], .3);
		sequenceTap($input, 0, 0);
		sequenceTap($input, 1, .1);
		check($input->drain() === []);
		$input->update([0 => 1], 1);
		$input->update([], 1.5);
		sequenceTap($input, 1, 1.6);
		check(array_column($input->drain(), 'name') === ['charge']);
		$input->add('turn', ['back','forward',0]);
		sequenceTap($input, 'left', 2);
		check($input->progress('turn') === 1);
		$input->setFacing(-1);
		check($input->progress('turn') === 0);
		sequenceTap($input, 'left', 2.1);
		sequenceTap($input, 0, 2.2);
		check($input->drain() === []);
	}
);

test('matcher resets discard progress, held inputs and unconsumed completions', function() {
	$input = new Sequences;
	$input->add('double', [0,0]);
	sequenceTap($input, 0, 0);
	$input->reset();
	sequenceTap($input, 0, 1);
	check($input->drain() === []);
	sequenceTap($input, 0, 1.1);
	$input->reset();
	check($input->progress('double') === 0 && $input->drain() === []);
	$input->remove('double');
	check($input->progress('double') === 0);
});

test('lenient sequences tolerate noise and retain a bounded completion queue', function() {
	$input = new Sequences;
	$input->add('combo', [0,1], .5, null, false);
	sequenceTap($input, 0, 0);
	sequenceTap($input, 2, .1);
	sequenceTap($input, 1, .2);
	check(array_column($input->drain(), 'name') === ['combo']);
	$input->add('tap', [3]);

	for($i = 0; $i < 100; $i++)
	{
		sequenceTap($input, 3, 1 + $i * .1);
	}

	$events = $input->drain();
	check(count($events) === 64);
	near($events[0]['time'], 4.6);
	near($events[63]['time'], 10.9);
});

test('same-sample direction plus attack is an explicit held-direction step', function() {
	$input = new Sequences;
	$input->add('special', ['down', ['press' => 1, 'held' => ['forward']]], .3);
	sequenceTap($input, 'down', 0);
	$input->update(['right' => 1, 1 => 1], .1);
	check(array_column($input->drain(), 'name') === ['special']);
	$input->update(['right' => 1, 1 => 1], .2);
	check($input->drain() === []);
});

test('custom action names do not gain direction aliases from substrings', function() {
	$input = new Sequences;
	$input->add('unexpected', [['press' => 0, 'held' => ['bforward']]]);
	$input->add('custom', [['press' => 0, 'held' => ['bright']]]);
	$input->update(['bright' => 1, 0 => 1], 0);
	check(array_column($input->drain(), 'name') === ['custom']);
});

test('analog thresholds cancel opposing directions and validate sequence definitions', function() {
	$input = new Sequences;
	$input->add('up', ['up']);
	$input->update(['up' => .4], 0);
	check($input->drain() === []);
	$input->update(['up' => .8], .1);
	check(count($input->drain()) === 1);
	$input->update(['left' => 1, 'right' => 1], .2);
	$input->add('right', ['right']);
	$input->update(['right' => 1], .3);
	check(array_column($input->drain(), 'name') === ['right']);

	foreach([
		[], [[]], [['press' => 0, 'release' => 1]]
		, [
			[
				'release' => 0
				, 'minHold' => -1
			]
		]
	] as $steps)
	{
		rejects(fn() => $input->add('bad', $steps));
	}

	rejects(fn() => $input->add('bad', [0], NAN));
	rejects(fn() => $input->add('bad', [0], 0));
	rejects(fn() => $input->update([], INF));
	rejects(fn() => $input->update([], .1));
	rejects(fn() => $input->setFacing(0));
});
