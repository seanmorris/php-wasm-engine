<?php
declare(strict_types=1);

use Phonics\Input\ActionInput;
use Phonics\Input\Bindings;
use Phonics\Input\InputCode;
use Phonics\Input\Key;
use Phonics\Input\Pad;
use Phonics\Input\Source;

test('action profiles retain signed axes, buffered taps and independent device contributions', function() {
	$key = new Source(Source::KEY, ord('w'));
	$back = new Source(Source::KEY, ord('s'));
	$stick = new Source(Source::PAD_AXIS, Pad::LEFT_Y, -1);
	$bindings = new Bindings([InputCode::GAME_START => [1 => [$key, $stick], -1 => [$back]]]);
	$input = new ActionInput($bindings);
	$input->hold($key, 1);
	$input->hold($stick, .8);
	$frame = $input->consume();
	near($frame->value(InputCode::GAME_START), 1);
	near($frame->value(InputCode::GAME_START, Source::CONTROLLER), .8);
	$input->hold($stick, .15);
	$input->hold($key, 0);
	near($input->consume()->value(InputCode::GAME_START), .15);
	$input->hold($back, 1);
	near($input->consume()->value(InputCode::GAME_START), -.85);
	$input->hold($back, 0);
	$input->hold($stick, 0);
	$input->frame(); // Drain earlier display-frame edges before testing a new short tap.
	$input->hold($key, 1);
	$input->hold($key, 0);
	check(count($input->candidates()) > 0, 'same-poll tap is visible to capture');
	near($input->frame()->value(InputCode::GAME_START), 1);
	near($input->frame()->value(InputCode::GAME_START), 0);
	$frame = $input->consume();
	near($frame->value(InputCode::GAME_START), 1);
	check($frame->presses[InputCode::GAME_START] === 1);
	near($input->consume()->value(InputCode::GAME_START), 0);
	$input->hold($back, 1);
	$controls = new \Phonics\Input\Controls;
	$controls->set($input->consume());
	near($controls->value(InputCode::GAME_START), -1);
	check($controls->down(InputCode::GAME_START));
});

test('relative axes and wheel pulses are consumed once on their own clocks', function() {
	$mouse = new Source(Source::MOUSE_AXIS, Source::X, 1);
	$wheel = new Source(Source::WHEEL, Source::Y, -1);

	$input = new ActionInput(new Bindings([
		InputCode::GAME_START => [1 => [$mouse]]
		, InputCode::GAME_START + 1 => [1 => [$wheel]]
	]));

	$input->move(Source::MOUSE_AXIS, Source::X, 4);
	$input->move(Source::MOUSE_AXIS, Source::X, 6);
	$input->move(Source::WHEEL, Source::Y, -3);
	$input->consume(); // A fixed-step action sample must not consume display-frame motion.
	near($input->frame()->delta(InputCode::GAME_START), 10);
	near($input->frame()->delta(InputCode::GAME_START), 0);
	$input->move(Source::WHEEL, Source::Y, -2);
	$input->frame();
	check($input->consume()->presses[InputCode::GAME_START + 1] === 2);
	check($input->consume()->presses[InputCode::GAME_START + 1] === 0);
	$input->move(Source::MOUSE_AXIS, Source::X, 8, false);
	check(count($input->candidates()) > 0);
	near($input->frame()->delta(InputCode::GAME_START), 0, .0001);
});

test('binding conflicts and reset affect only the chosen physical source and device', function() {
	$a = new Source(Source::KEY, ord('a'));
	$b = new Source(Source::KEY, ord('b'));
	$pad = new Source(Source::PAD_BUTTON, Pad::A);
	$profile = new Bindings([InputCode::GAME_START => [1 => [$a, $pad]], InputCode::GAME_START + 1 => [1 => [$b]]]);
	check(!$profile->bind(InputCode::GAME_START + 1, 1, $a));
	check($profile->bind(InputCode::GAME_START + 1, 1, $a, 'share'));
	check(count($profile->sources(InputCode::GAME_START, 1)) === 2);
	check($profile->bind(InputCode::GAME_START + 1, 1, $a, 'replace'));
	check($profile->sources(InputCode::GAME_START, 1) === [$pad]);
	$profile->reset(Source::CONTROLLER);
	check($profile->sources(InputCode::GAME_START, 1) === [$pad]);
	$profile->reset(Source::KEYBOARD);
	check(count($profile->sources(InputCode::GAME_START, 1)) === 2);

	$profile->restore([
		InputCode::GAME_START => [
			1 => [
				Source::KEYBOARD => [['kind' => 'key', 'code' => 'bad', 'sign' => 1]]
				, Source::CONTROLLER => []
			]
		]
	]);

	check($profile->sources(InputCode::GAME_START, 1, Source::KEYBOARD) === [$a]);
	check($profile->sources(InputCode::GAME_START, 1, Source::CONTROLLER) === []);
});

test('shared UI aliases preserve legacy action names and sequence directions', function() {
	check(InputCode::GAME_START > InputCode::RESERVED_MAX);
	check(\Phonics\Input\Buttons::name('yes') === \Phonics\Input\Buttons::name(InputCode::YES));
	check(\Phonics\Input\Buttons::name('jump') === 'button0');
	check(\Phonics\Input\Buttons::name('back') === 'back', 'relative sequence directions remain intact');
	$menu = new \Phonics\Ui\Menu(['test' => ['label' => 'Test', 'children' => []]]);
	$menu->open();
	$input = new \Phonics\Ui\MenuInput;
	$input->update($menu, [InputCode::YES => 1], .016);
	check($menu->depth() === 1);
});
