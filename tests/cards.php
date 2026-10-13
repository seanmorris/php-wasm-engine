<?php
use PhpWasmEngine\Ui\Card;
use PhpWasmEngine\Ui\CardSequence;

require_once dirname(__DIR__).'/examples/cards.php';

test('demo preload is readable but leaves promptly after cached or delayed assets are ready'
	, function() {
		foreach([0, 5] as $delay)
		{
			$ready = $delay === 0;
			$sequence = demoCards(function() use (&$ready): bool { return $ready; });
			$sequence->start();

			if($delay)
			{
				$sequence->update($delay, true);
				check($sequence->current()->id === 'loading');
				check(!$sequence->advance());
			}

			$ready = true;
			$sequence->update(.6);
			check($sequence->current()->id === 'loading' && $sequence->state === 'shown');
			near($sequence->opacity(), 1);
			$sequence->update(.5);
			$sequence->update(.3);
			check($sequence->current()->id === 'engine');
			$sequence->update(.5);
			near($sequence->opacity(), 1);
			$sequence->update(3.9);
			check($sequence->current()->id === 'engine' && $sequence->state === 'shown');
		}
	}
);

test('cards wait for readiness before timing out or accepting a skip', function() {
	$ready = false;

	$sequence = new CardSequence([
		new Card('loading', duration: 1, fadeIn: .2, fadeOut: .2,
			ready: function() use (&$ready) { return $ready; }
		)
	]);
	$sequence->start();
	$sequence->update(.5);
	near($sequence->opacity(), 1);
	$sequence->update(10, true);
	check($sequence->state === 'waiting');
	check(!$sequence->advance());
	$ready = true;
	$sequence->update(.5, true);
	check($sequence->state === 'shown');
	$sequence->update(.5, true);
	check($sequence->state === 'closing');
	$sequence->update(.1);
	near($sequence->opacity(), .5);
	$sequence->update(.1);
	check($sequence->complete() && $sequence->current() === null);
});

test('card input requires a fresh press after the guard and between cards', function() {
	$sequence = new CardSequence(
		[new Card('one', duration: null, skipAfter: .5, fadeOut: 0)
		, new Card(
			'two'
			, duration: null
			, skipAfter: .5
			, fadeOut: 0
		)]
	);
	$sequence->start();
	$sequence->update(.1);
	$sequence->update(.1, true);
	$sequence->update(1, true);
	check($sequence->current()->id === 'one');
	$sequence->update(0);
	$sequence->update(0, true);
	check($sequence->current()->id === 'two');
	$sequence->update(1, true);
	check(!$sequence->complete());
	$sequence->update(0);
	$sequence->update(0, true);
	check($sequence->complete());
});

test('card callbacks run once on completion, cancellation, and replay', function() {
	$events = [];

	$card = new Card('credit', duration: .5, fadeOut: 0,
		onEnter: function(Card $card) use (&$events) { $events[] = 'enter:'.$card->id; },
		onLeave: function(Card $card, bool $cancelled) use (&$events) {
			$events[] = $cancelled ? 'cancel' : 'leave';
		}
	);
	$sequence = new CardSequence([$card]);
	$sequence->start();
	$sequence->update(.5);
	$sequence->update(10);
	$sequence->cancel();
	$sequence->start();
	$sequence->cancel();
	$sequence->cancel();
	check($events === ['enter:credit', 'leave', 'enter:credit', 'cancel']);
});

test('unskippable and indefinite cards expose guarded scripting verbs', function() {
	$sequence = new CardSequence(
		[new Card('legal', duration: 1, skippable: false, fadeIn: .4, fadeOut: 0)]
	);
	$sequence->start();
	$sequence->update(.2);
	near($sequence->opacity(), .5);
	$sequence->update(.4, true);
	check(!$sequence->advance());
	$sequence->update(.4);
	check($sequence->complete());
	$empty = new CardSequence([]);
	$empty->start();
	check($empty->complete());
});

test('card timing and sequence definitions reject invalid values', function() {
	rejects(fn() => new Card(''));
	rejects(fn() => new Card('bad', duration: -1));
	rejects(fn() => new Card('bad', fadeIn: NAN));
	rejects(fn() => new Card('bad', skipAfter: INF));
	rejects(fn() => new Card('bad', align: 'justify'));
	rejects(fn() => new CardSequence([new Card('same'), new Card('same')]));
	rejects(fn() => new CardSequence([false]));
	$sequence = new CardSequence([new Card('ok')]);
	$sequence->start();
	rejects(fn() => $sequence->update(-1));
	rejects(fn() => $sequence->update(INF));
});

test('dismissing a partly revealed card fades from its current opacity without flashing'
	, function() {
		$sequence = new CardSequence(
			[new Card('quick', duration: null, skipAfter: 0, fadeIn: 1, fadeOut: 1)]
		);
		$sequence->start();
		$sequence->update(.2);
		near($sequence->opacity(), .2);
		check($sequence->advance());
		near($sequence->opacity(), .2);
		$sequence->update(.5);
		near($sequence->opacity(), .1);
		$sequence->update(.5);
		check($sequence->complete());
	}
);
