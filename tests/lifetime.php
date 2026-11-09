<?php
use Phonics\Ecs\Registry;
use Phonics\Effects\Lifetime;
use Phonics\Effects\LifetimeSystem;
use Phonics\Render\Sprite;

// Lifetime replaces the age/duration/fade fields games kept on each short-lived effect. Its arithmetic must match
// the forms it replaces: `1 - age / duration` for a fade from birth, the `<=` boundary at the fade start, and a
// catch-up that never rewinds.

final class LifetimeTestMarker extends Lifetime {}

test('Lifetime advances with a clamp, keeps the previous age, and fades as the hand-written forms did', function() {
	$life = new Lifetime(.5);
	$life->advance(.2);
	near($life->previousAge, 0.0, 1e-12);
	near($life->age, .2, 1e-12);
	check($life->opacity() === 1 - .2 / .5, 'a fade from birth is 1 - age / duration, bit for bit');
	near($life->remaining(0.0), 1.0, 1e-12);
	near($life->remaining(.5), 1 - .1 / .5, 1e-12);
	near($life->remaining(1.0), 1 - .2 / .5, 1e-12);
	near($life->remaining(7.0), 1 - .2 / .5, 1e-12);
	$life->advance(-3.0);
	near($life->age, .2, 1e-12);
	$life->advance(5.0);

	check(
		$life->age === .5 && $life->expired() && $life->remaining() === 0.0
		, 'the age clamps to the duration and the lifetime expires'
	);

	$faded = new Lifetime(1.0, .25);
	$faded->advance(.25);
	check($faded->opacity() === 1.0, 'opacity holds at one up to and including the fade start');
	$faded->advance(.25);

	check(
		$faded->opacity() === 1 - (.5 - .25) / (1.0 - .25)
		, 'past the fade start opacity falls over the rest of the life'
	);

	$open = new Lifetime(1.0, 0.0, false);
	$open->advance(3.0);
	check($open->age === 3.0 && $open->expired(), 'an unclamped lifetime counts past its duration');
	$endless = new Lifetime(INF, 0.0, false);
	$endless->advance(100.0);

	check(
		!$endless->expired() && $endless->remaining() === 1.0 && $endless->opacity() === 1.0
		, 'an infinite duration never expires or fades'
	);

	rejects(fn() => new Lifetime(0.0));
	rejects(fn() => new Lifetime(-1.0));
	rejects(fn() => new Lifetime(1.0, 1.0));
	rejects(fn() => new Lifetime(1.0, -.1));
});

test('Lifetime synchronize catches up without rewinding and settles the step', function() {
	$life = new Lifetime(2.0);
	$life->advance(.5);
	$life->synchronize(.2);

	check(
		$life->age === .5 && $life->previousAge === .5
		, 'an older report leaves the age where it is and stills the step'
	);

	$life->synchronize(1.25);
	check($life->age === 1.25 && $life->previousAge === 1.25, 'a newer report moves the age forward');
	$life->synchronize(9.0);
	check($life->age === 2.0 && $life->expired(), 'a report past the end clamps to the duration');
	rejects(fn() => $life->synchronize(-1.0));
	rejects(fn() => $life->synchronize(INF));
});

test('LifetimeSystem fades sprites, finds subclasses, and retires the expired at the next flush', function() {
	$registry = new Registry;
	$system = new LifetimeSystem;
	$sprite = new Sprite(1);
	$visible = $registry->create(new Lifetime(1.0), $sprite);
	$bare = $registry->create(new Lifetime(.5));
	$marked = $registry->create(new LifetimeTestMarker(.25));
	$system->update($registry, .25);
	check($sprite->opacity === 1 - .25 / 1.0, 'a sprite takes the lifetime\'s opacity');

	check(
		$registry->get($marked, LifetimeTestMarker::class)->age === .25
		, 'a game marker that extends Lifetime is advanced too'
	);

	check($registry->alive($marked), 'an expired entity stays alive until the registry flushes');
	$registry->flush();

	check(
		!$registry->alive($marked) && $registry->alive($bare) && $registry->alive($visible)
		, 'the flush retires only the expired'
	);

	$system->update($registry, .5);
	$registry->flush();
	check(!$registry->alive($bare) && $registry->alive($visible), 'each lifetime expires at its own duration');
	rejects(fn() => $system->update($registry, -1.0));
	rejects(fn() => $system->update($registry, NAN));
});
