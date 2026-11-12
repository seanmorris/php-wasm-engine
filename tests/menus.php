<?php
use PhpWasmEngine\Ui\Menu;
use PhpWasmEngine\Ui\MenuInput;
use PhpWasmEngine\Ui\TextBuffer;
use PhpWasmEngine\Ui\Easing;
use Phonics\Ui\TextPage;
use PhpWasmEngine\Render\BitmapFont;
use PhpWasmEngine\Tiled\Loader;

test('snappy UI easing is frame-rate independent and settles exactly', function() {
	$oneStep = Easing::snappy(0, 100, .1);
	$twoSteps = Easing::snappy(Easing::snappy(0, 100, .05), 100, .05);

	near($oneStep, $twoSteps);
	check($oneStep > 0 && $oneStep < 100);
	check(Easing::snappy(99.9995, 100, .01) === 100.0);

	foreach([-1, INF, NAN] as $seconds)
	{
		rejects(fn() => Easing::snappy(0, 1, $seconds));
	}
});

test('each wheel event moves one visible row regardless of HID line count', function() {
	$items = [];

	for($i = 0; $i < 12; $i++)
	{
		$items['item' . $i] = ['hidden' => $i === 6];
	}

	$menu = new Menu($items);
	$menu->open();
	$input = new MenuInput;

	foreach([1, 4, 120, .25] as $amount)
	{
		$menu->select('item5');
		$input->update($menu, [], 0, [['type' => 'wheel', 'y' => -$amount]]);
		check($menu->selected === 'item7', "Wheel delta -$amount must move one visible row");
		$input->update($menu, [], 0, [['type' => 'wheel', 'y' => $amount]]);
		check($menu->selected === 'item5', "Wheel delta $amount must move one visible row");
	}

	$input->update($menu, [], 0, [['type' => 'wheel', 'y' => 0]]);
	check($menu->selected === 'item5');

	$input->update($menu, [], 0, [
		['type' => 'wheel', 'y' => -4], ['type' => 'wheel', 'y' => -4]
	]);

	check($menu->selected === 'item8');

	foreach(['item0' => 4, 'item11' => -4] as $id => $amount)
	{
		$menu->select($id);
		$input->update($menu, [], 0, [['type' => 'wheel', 'y' => $amount]]);
		check($menu->selected === $id, 'Wheel navigation clamps at the page boundary');
	}
});

test('horizontal submenus rotate navigation, adjustment, and wheel input', function() {
	$menu = new Menu([
		'horizontal' => [
			'orientation' => Menu::HORIZONTAL
			, 'children' => [
				'first' => ['input' => 'number', 'value' => 5]
				, 'second' => ['input' => 'number', 'value' => 10]
				, 'third' => ['input' => 'number', 'value' => 15]
			]
		]
	]);

	$menu->open();
	$menu->activate();
	$input = new MenuInput;

	check($menu->orientation === Menu::HORIZONTAL && $menu->selected === 'first');
	$input->update($menu, ['right' => true], 0);
	check($menu->selected === 'second');
	$input->update($menu, [], 0);
	$input->update($menu, ['down' => true], 0);
	check($menu->selected === 'second' && $menu->value('second') == 11);
	$input->update($menu, [], 0);
	$input->update($menu, [], 0, [['type' => 'wheel', 'x' => -2, 'y' => 0]]);
	check($menu->selected === 'third');
	$menu->back();
	check($menu->orientation === Menu::VERTICAL && $menu->selected === 'horizontal');
	rejects(fn() => new Menu(['bad' => ['orientation' => 'diagonal']]));
});

test('bitmap alignment places each wrapped line and its carets within the line box', function() {
	$font = new BitmapFont(new Loader, dirname(__DIR__) . '/examples/assets/font.tsj');

	foreach(['left' => 0, 'center' => .5, 'right' => 1] as $align => $factor)
	{
		$layout = $font->layout("AA\nI", 2, 100, align: $align);
		near($layout['width'], 32);
		near($layout['height'], 64);
		near($layout['sprites'][0]->x, 68 * $factor);
		near($layout['sprites'][2]->x, 88 * $factor);
		near($layout['positions'][2][0], 32 + 68 * $factor);
		near($layout['positions'][2][1], 0);
		near($layout['positions'][3][0], 88 * $factor);
		near($layout['positions'][4][0], 12 + 88 * $factor);

		$wrapped = $font->layout('AAI', 2, 36, align: $align);
		near($wrapped['sprites'][0]->x, 4 * $factor);
		near($wrapped['sprites'][2]->x, 24 * $factor);
		near($wrapped['positions'][2][0], 24 * $factor);
		near($wrapped['positions'][2][1], 32);

		check(
			$font->measure('AAI', 2, 36) === [
				'width' => $wrapped['width']
				, 'height' => $wrapped[
				'height'
				]
			]
		);
	}
});

test('unwrapped alignment supports fields, empty lines, themed symbols and overflow', function() {
	$path = dirname(__DIR__) . '/examples/assets/';
	$font = new BitmapFont(new Loader, $path . 'font.tsj', [$path . 'buttons.tsj']);
	$layout = $font->layout("AA\nI", align: 'center');
	near($layout['sprites'][2]->x, 5);
	$layout = $font->layout("I\n\n", align: 'right', alignWidth: 40);
	check($layout['positions'] === [[34.0, 0.0], [40.0, 0.0], [40.0, 16.0], [40.0, 32.0]]);
	$layout = $font->layout('', align: 'center', alignWidth: 40);
	check($layout['positions'] === [[20.0, 0.0]] && $layout['sprites'] === []);
	near($layout['height'], 0);
	$layout = $font->layout('AAAA', align: 'right', alignWidth: 12);
	near($layout['positions'][0][0], 0);
	near($layout['width'], 32);
	near($layout['height'], 16);

	foreach(['keyboard', 'xbox', 'playstation', 'generic'] as $theme)
	{
		$font->theme = $theme;
		$left = $font->layout('⓿ I');
		$right = $font->layout('⓿ I', align: 'right', alignWidth: 100);
		near($right['positions'][3][0], 100);
		near($right['sprites'][0]->x - $left['sprites'][0]->x, 100 - $left['width']);
		check($right['sprites'][0]->tint === $left['sprites'][0]->tint);
	}

	rejects(fn() => $font->layout('A', align: 'justify'));

	foreach([0, -1, NAN, INF] as $invalid)
	{
		rejects(fn() => $font->layout('A', alignWidth: $invalid));
	}
});

test('named menu buttons override their shared command codes', function() {
	$menu = new Menu(['play' => ['label' => 'Play', 'callback' => static function(): void {}]]);

	$menu->open();
	$input = new MenuInput;
	$input->update($menu, [\Phonics\Input\InputCode::PAUSE => true], 0);
	check(!$menu->isOpen);

	$menu->open();
	$input = new MenuInput;
	$input->update($menu, ['pause' => false, \Phonics\Input\InputCode::PAUSE => true, 'confirm' => true], 0);
	check($menu->isOpen);
});

test('repeated activation keys cannot finish a field entered by the same hold', function() {
	$menu = new Menu(['name' => ['input' => 'string', 'value' => 'Player']]);
	$menu->open();
	$input = new MenuInput;
	$input->update($menu, ['confirm' => true], 0);
	check($menu->editor !== null);
	$input->update($menu, [], .1, [['type' => 'key', 'key' => 'Return', 'repeat' => true]]);
	check($menu->editor !== null);
	$input->update($menu, [], .1, [['type' => 'key', 'key' => 'Return', 'repeat' => false]]);
	check($menu->editor === null && $menu->isOpen);
});

test('numeric edits never publish infinity when snapping overflows', function() {
	$menu = new Menu(['number' => ['input' => 'number', 'value' => 1, 'step' => 1e-300]]);
	check(!$menu->setValue('number', 1e300));
	check($menu->value('number') === 1);
});

test('menu conditions repair focus and block all ways of changing disabled values', function() {
	$hidden = false;
	$calls = 0;

	$menu = new Menu([
		'first' => [
			'input' => 'string'
			, 'value' => 'kept'
			, 'hidden' => function() use (&$hidden) { return $hidden; }
		]
		, 'locked' => [
			'input' => 'boolean'
			, 'value' => false
			, 'disabled' => true
			, 'set' => function() use (&$calls) { $calls++; }
			, 'revert' => function() use (&$calls) { $calls++; }
		]
		, 'empty' => ['input' => 'select', 'options' => []]
	]);

	$menu->open();
	$menu->activate();
	$hidden = true;
	$menu->refresh();
	check($menu->editor === null && $menu->selected === 'locked');
	$menu->activate();
	$menu->adjust(1);
	$menu->revert();
	check(!$menu->setValue('locked', true) && $calls === 0);
	$menu->select('empty');
	$menu->activate();
	$menu->adjust(-1);
	check($menu->rows()['empty']['disabled']);
	$menu->setItems([]);
	check($menu->selected === null);
	$menu->move(1);
	$menu->back();
	check(!$menu->isOpen);
});

test('leaving a pending submenu cancels polling and keeps the parent usable', function() {
	$polls = 0;

	$menu = new Menu([
		'remote' => [
			'children' => function() use (&$polls) {
				$polls++;

				return null;
			}
		]
	]);

	$menu->open();
	$menu->activate();
	check($menu->loading && $menu->selected === '@back');
	$menu->back();
	$menu->refresh();
	check($polls === 1 && !$menu->loading && $menu->selected === 'remote');
});

test('menu snapshots do not invoke setters and editor composition stays private', function() {
	$value = 'before';
	$sets = 0;

	$menu = new Menu([
		'text' => [
			'input' => 'string'
			, 'get' => function() use (&$value) { return $value; }
			, 'set' => function($text) use (&$value, &$sets) {
				$value = $text;
				$sets++;
			}
		]
	]);

	$menu->open();
	$menu->activate();
	$input = new MenuInput;

	$input->update($menu, [], 0, [['type' => 'composition', 'text' => 'かな', 'start' => 1, 'length' => 1]]);
	check($sets === 0 && $value === 'before');
	$input->update($menu, [], 0, [['type' => 'text', 'text' => '字']]);
	check($sets === 1 && $value === 'before字');
	$menu->refresh();
	$menu->rows();
	check($sets === 1);

	$input->update(
		$menu
		, []
		, 0
		, [['type' => 'key', 'key' => 'a', 'ctrl' => true], ['type' => 'key', 'key' => 'Delete']]
	);

	check($sets === 2 && $value === '');
	$menu->back();
	$menu->refresh();
	check($sets === 2);
});

test('slider dragging clamps outside its track instead of entering text editing', function() {
	$menu = new Menu([
		'slider' => [
			'input' => 'number'
			, 'min' => 0
			, 'max' => 10
			, 'step' => .5
			, 'value' => 5
			, 'subType' => 'range'
		]
	]);

	$menu->open();
	$input = new MenuInput;
	$regions = [['id' => 'slider', 'rect' => [0, 0, 150, 30], 'slider' => [50, 100]]];

	$input->update(
		$menu
		, []
		, 0
		, [['type' => 'pointer', 'action' => 'down', 'button' => 1, 'x' => 100, 'y' => 10]]
		, $regions
	);

	$input->update($menu, [], 0, [['type' => 'pointer', 'action' => 'move', 'x' => 0, 'y' => 10]], $regions);
	check($menu->value('slider') == 0 && $menu->editor === null);

	$input->update($menu, [], 0, [['type' => 'pointer', 'action' => 'move', 'x' => 200, 'y' => 10]], $regions);
	check($menu->value('slider') == 10 && $menu->editor === null);
});

test('a slider row\'s value text is a field: a press there places the caret instead of moving the knob', function() {
	$level = ['input' => 'number', 'subType' => 'range', 'min' => 0, 'max' => 10, 'step' => .5, 'value' => 5];
	$menu = new Menu(['level' => $level]);
	$menu->open();
	$input = new MenuInput;
	$event = ['type' => 'pointer', 'button' => 1, 'y' => 10];

	$press = static fn(float $x): array => [$event + ['action' => 'down', 'x' => $x]];
	$release = [$event + ['action' => 'up', 'x' => 0]];
	$idle = [['id' => 'level', 'rect' => [0, 0, 200, 30], 'slider' => [50, 100], 'carets' => [160, 168]]];

	$input->update($menu, [], 0, $press(75), $idle);
	$input->update($menu, [], 0, $release, $idle);
	check($menu->value('level') == 2.5 && $menu->editor === null, 'the track sets the value');

	$input->update($menu, [], 0, $press(167), $idle);
	$input->update($menu, [], 0, $release, $idle);
	$opened = $menu->editor !== null && $menu->editor->caret === 1;
	check($menu->value('level') == 2.5 && $opened, 'the text opens a field');

	// While the field is open the row has no track, and a press moves the caret within the text.
	$editing = [['id' => 'level', 'rect' => [0, 0, 200, 30], 'carets' => [100, 108, 116, 124]]];
	$input->update($menu, [], 0, $press(101), $editing);
	check($menu->editor !== null && $menu->editor->caret === 0 && $menu->value('level') == 2.5);
});

test('one pointer press cannot activate two nested menu levels through its confirm alias', function() {
	$menu = new Menu([
		'read-this' => [
			'label' => 'Read This'
			, 'children' => [
				'rebind' => [
					'label' => 'Rebind'
					, 'children' => ['keyboard' => ['label' => 'Keyboard']]
				]
			]
		]
	]);

	$menu->open();
	$input = new MenuInput;
	$regions = [['id' => 'read-this', 'rect' => [0, 0, 150, 30]]];
	$pointer = [['type' => 'pointer', 'action' => 'down', 'button' => 1, 'x' => 75, 'y' => 15]];

	$input->update($menu, ['confirm' => true], 0, $pointer, $regions);
	check($menu->depth() === 1 && $menu->title === 'Read This' && $menu->selected === 'rebind');

	$input->update($menu, ['confirm' => true], .1);
	check($menu->depth() === 1);
	$input->update($menu, [], .1);
	$input->update($menu, ['confirm' => true], 0);
	check($menu->depth() === 2 && $menu->title === 'Rebind');
});

test('invalid numeric definitions and malformed font annotations are rejected', function() {
	foreach(['align', 'valueAlign'] as $key)
	{
		rejects(fn() => new Menu(['bad' => [$key => 'justify']]));
	}

	foreach([['step' => 0], ['min' => 10, 'max' => 1], ['max' => INF]] as $invalid)
	{
		rejects(fn() => new Menu(['bad' => ['input' => 'number'] + $invalid]));
	}

	$path = dirname(__DIR__) . '/examples/assets/font.tsj';
	$original = json_decode(file_get_contents($path), true);

	foreach(['duplicate', 'crop', 'advance', 'offset'] as $invalid)
	{
		$data = $original;

		if($invalid === 'duplicate')
		{
			$data['tiles'][1]['properties'][0]['value'] = '0';
		}

		if($invalid === 'crop')
		{
			$data['tiles'][0]['properties'][] = ['name' => 'glyph.width', 'value' => 100];
		}

		if($invalid === 'advance')
		{
			$data['tiles'][0]['properties'][] = ['name' => 'glyph.advance', 'value' => -1];
		}

		if($invalid === 'offset')
		{
			$data['tiles'][0]['properties'][] = ['name' => 'glyph.offsetX', 'value' => 'NaN'];
		}

		rejects(fn() => new BitmapFont(fixture(['bad.tsj' => $data]), 'bad.tsj'));
	}
});

test('glyph texture density preserves layout, default advances and text filtering', function() {
	$path = dirname(__DIR__) . '/examples/assets/font.tsj';
	$data = json_decode(file_get_contents($path), true);
	$data['tiles'][0]['properties'] = [
		['name' => 'glyph.char', 'value' => '0']
		, ['name' => 'glyph.width', 'value' => 8]
		, ['name' => 'glyph.height', 'value' => 12]
		, ['name' => 'glyph.pixelRatio', 'value' => 2]
		, ['name' => 'glyph.smooth', 'value' => true]
		, ['name' => 'glyph.offsetX', 'value' => 1]
		, ['name' => 'glyph.offsetY', 'value' => -2]
	];

	$font = new BitmapFont(fixture(['dense.tsj' => $data]), 'dense.tsj');
	$layout = $font->layout('0A', 3);
	[$icon, $letter] = $layout['sprites'];
	near($icon->scaleX, 1.5);
	near($icon->scaleY, 1.5);
	near($icon->x, 3);
	near($icon->y, -6);
	near($letter->x, 12);
	near($letter->scaleX, 3);
	near($layout['width'], 36);
	check($icon->smooth && !$letter->smooth);
	check($icon->crop === [0, 0, 8, 12]);

	foreach([0, -1, 'NaN', INF] as $invalid)
	{
		$data['tiles'][0]['properties'][3]['value'] = $invalid;
		rejects(fn() => new BitmapFont(fixture(['bad.tsj' => $data]), 'bad.tsj'));
	}
});

test('button glyphs select annotated themes and retain their original colors', function() {
	$font = new BitmapFont(
		new Loader
		, dirname(__DIR__) . '/examples/assets/font.tsj'
		, [dirname(__DIR__) . '/examples/assets/buttons.tsj']
	);

	$ids = [];

	foreach(['keyboard', 'xbox', 'playstation', 'generic'] as $theme)
	{
		$font->theme = $theme;
		$glyph = $font->layout('⓿', tint: [1, 0, 0, .5])['sprites'][0];
		$ids[] = $glyph->gid;
		check($glyph->tint === [1, 1, 1, .5]);
		check($glyph->smooth && $glyph->crop[3] >= 64);
		near($glyph->crop[3] * $glyph->scaleY, 16);
		near($font->measure('⓿')['width'], $theme === 'keyboard' ? 26 : 18);
	}

	check(count(array_unique($ids)) === 4);
	$font->theme = 'unknown';
	check($font->layout('⓿')['sprites'][0]->gid === $ids[3]);
});

test('menu pages restore selection and scroll, wrap rows and skip hidden entries', function() {
	$menu = new Menu(
		[
			'first' => ['label' => 'First']
			, 'hidden' => ['hidden' => true]
			, 'settings' => [
				'children' => ['flag' => ['input' => 'boolean', 'value' => false]]
			]
			, 'locked' => [
				'disabled' => true
				, 'callback' => fn() => throw new Exception('disabled')
			]
		]
		, 'Pause'
	);

	$menu->open();
	check($menu->selected === 'first');
	$menu->move(-1);
	check($menu->selected === 'locked');
	$menu->activate();
	$menu->select('settings');
	$menu->scroll = 2;
	$menu->activate();
	check($menu->depth() === 1 && $menu->selected === 'flag');
	$menu->activate();
	check($menu->value('flag') === true);
	$menu->back();
	check($menu->selected === 'settings' && $menu->scroll === 2);
	$menu->activate();
	check($menu->value('flag') === true);
	$menu->close();
	$menu->open();
	check($menu->depth() === 0 && $menu->selected === 'settings');
	$menu->back();
	check(!$menu->isOpen);
});

test('menu live bindings change once, clamp steps, cycle choices and reset defaults', function() {
	$value = .5;
	$calls = 0;

	$menu = new Menu([
		'amount' => [
			'input' => 'number'
			, 'min' => 0
			, 'max' => 1
			, 'step' => .1
			, 'get' => function() use (&$value) { return $value; }
			, 'set' => function($next) use (&$value, &$calls) {
				$value = $next;
				$calls++;
			}
			, 'default' => .5
		]
		, 'choice' => ['input' => 'select', 'options' => ['Red', 'Blue'], 'value' => 'Red']
		, 'flag' => ['input' => 'boolean', 'value' => false]
		, 'output' => ['input' => 'output', 'value' => 'read only']
	]);

	$menu->open();
	$menu->refresh();
	check($calls === 0);
	$menu->adjust(1);
	near($value, .6);
	check($calls === 1);
	$menu->setValue('amount', 100);
	near($value, 1);
	$menu->adjust(1);
	check($calls === 2);
	$menu->revert();
	near($value, .5);
	check($calls === 3);
	$menu->select('choice');
	$menu->adjust(-1);
	check($menu->value('choice') === 'Blue');
	$menu->adjust(1);
	check($menu->value('choice') === 'Red');
	$menu->select('flag');
	$menu->adjust(-1);
	check($menu->value('flag') === true);
	check(!$menu->setValue('output', 'changed'));
});

test('editing retains live values and discards only incomplete numeric drafts', function() {
	$menu = new Menu([
		'name' => ['input' => 'string', 'value' => 'Ada']
		, 'number' => [
			'input' => 'number'
			, 'value' => 3
			, 'min' => -10
			, 'max' => 10
		]
	]);

	$menu->open();
	$menu->activate();
	$menu->editor->selectAll();
	$menu->editor->insert('Zoë');
	$menu->applyEdit();
	check($menu->value('name') === 'Zoë');
	$menu->back();
	check($menu->isOpen && $menu->editor === null);
	$menu->select('number');
	$menu->activate();
	$menu->editor->selectAll();
	$menu->editor->insert('-');
	$menu->applyEdit();
	check($menu->value('number') == 3);
	$menu->editor->insert('5');
	$menu->applyEdit();
	check($menu->value('number') == -5);
	$menu->editor->selectAll();
	$menu->editor->insert('bad');
	$menu->back();
	check($menu->value('number') == -5);
});

test('lazy menus poll only while active and recover from errors with retry', function() {
	$count = 0;

	$menu = new Menu([
		'load' => [
			'children' => function() use (&$count) {
				$count++;

				if($count === 1)
				{
					return null;
				}

				if($count === 2)
				{
					throw new RuntimeException('offline');
				}

				return ['ready' => ['label' => 'Ready']];
			}
		]
	]);

	$menu->open();
	$menu->activate();
	check($menu->loading);
	$menu->refresh();
	check($menu->error === 'offline');
	$menu->select('@retry');
	$menu->activate();
	check(!$menu->loading && $menu->selected === 'ready');
	$menu->back();
	$menu->refresh();
	check($count === 3);
});

test('text buffers select and edit UTF-8 without committing composition', function() {
	$text = new TextBuffer('aé🙂z');
	$text->move(-1);
	$text->erase(true);
	check($text->text === 'aéz' && $text->caret === 2);
	$text->move(-1, true);
	check($text->selectedText() === 'é');
	$text->insert('漢');
	check($text->text === 'a漢z');
	$text->compose('かな', 1, 1);
	check($text->text === 'a漢z' && $text->composition === 'かな');
	$text->insert('字');
	check($text->text === 'a漢字z' && $text->composition === '');
	$text->selectAll();
	$text->insert("one\r\ntwo");
	check($text->text === 'one two');
	rejects(fn() => new TextBuffer("\xff"));
});

test('menu repeat uses elapsed time, page jumps clamp and reset fires once per hold', function() {
	$items = [];
	$resets = 0;

	for($i = 0; $i < 20; $i++)
	{
		$items['item' . $i] = [
			'revert' => function() use (&$resets) {
				$resets++;
			}
		];
	}

	$menu = new Menu($items);
	$menu->open();
	$input = new MenuInput;
	$input->update($menu, ['down' => true], 0);
	check($menu->selected === 'item1');
	$input->update($menu, ['down' => true], .49);
	check($menu->selected === 'item1');
	$input->update($menu, ['down' => true], .01);
	check($menu->selected === 'item2');
	$input->update($menu, ['down' => true], 1.05);
	check($menu->selected === 'item7');
	$input->update($menu, ['pageDown' => true], 0);
	check($menu->selected === 'item14');
	$input->update($menu, [], 0);
	$input->update($menu, ['pageDown' => true], 0);
	check($menu->selected === 'item19');
	$input->update($menu, ['revert' => true], 0);
	$input->update($menu, ['revert' => true], .5);
	$input->update($menu, ['revert' => true], 1);
	check($resets === 1);
});

test('font annotations own the character map independently of tile positions', function() {
	$path = dirname(__DIR__) . '/examples/assets/font.tsj';
	$font = new BitmapFont(new Loader, $path);
	$layout = $font->layout('aI, @?🙂');
	check(array_map(fn($s) => $s->gid, $layout['sprites']) === [27, 35, 15, 18, 16, 16]);
	near($layout['width'], 51);
	check($layout['sprites'][2]->crop === [4, 0, 3, 14]);
	near($layout['sprites'][2]->y, 0);
	check(!$layout['sprites'][0]->outline);
	$wrapped = $font->layout("AA\nI", 2);
	near($wrapped['width'], 32);
	near($wrapped['height'], 64);

	$data = json_decode(file_get_contents($path), true);

	foreach($data['tiles'] as &$tile)
	{
		if($tile['id'] === 26)
		{
			$tile['id'] = 77;
		}
		elseif($tile['id'] === 77)
		{
			$tile['id'] = 26;
		}
	}

	unset($tile);
	$font = new BitmapFont(fixture(['reordered.tsj' => $data]), 'reordered.tsj');
	check($font->layout('A')['sprites'][0]->gid === 78);
	near($font->measure('II L')['width'], 26);
});

/**
 * Texts of each laid-out line, with a span's kind ahead of its text and its x after it when it is indented.
 *
 * @param TextPage $page Laid-out page.
 * @return list<string> One summary per line; a blank line is empty.
 */
function textPageLines(TextPage $page): array
{
	$summaries = [];

	foreach($page->lines() as $spans)
	{
		$parts = [];

		foreach($spans as $span)
		{
			$parts[] = $span['kind'] . ':' . $span['text'] . ($span['x'] > 0 ? '@' . $span['x'] : '');
		}

		$summaries[] = implode(' | ', $parts);
	}

	return $summaries;
}

test('text pages wrap words to the column and cut only a word wider than it', function() {
	$font = new BitmapFont(new Loader, dirname(__DIR__) . '/examples/assets/font.tsj');
	$page = new TextPage(['ONE  TWO THREE', "A\nB", 'ABCDEFGH-MNOPQRS']);

	check($page->layout($font, 72, 10) === 8);

	check(textPageLines($page) === [
		'text:ONE TWO'
		, 'text:THREE'
		, ''
		, 'text:A'
		, 'text:B'
		, ''
		, 'text:ABCDEFGH-'
		, 'text:MNOPQRS'
	], implode(',', textPageLines($page)));

	// A glyph wider than the column still makes progress, one character to a line.
	$narrow = new TextPage(['AB']);
	$narrow->layout($font, 4, 1);
	check(textPageLines($narrow) === ['text:A', 'text:B']);

	$invalid = [[['heading' => 1]], [['name' => 'ART']], [['text' => 'A', 'heading' => 'B']], [5], [[]], ["\xff"]];

	foreach($invalid as $blocks)
	{
		rejects(fn() => new TextPage($blocks));
	}

	rejects(fn() => $page->layout($font, 0, 1));
	rejects(fn() => $page->layout($font, 10, 0));
});

test('text pages separate blocks, keep headings with their text, and line every pair up in one table', function() {
	$font = new BitmapFont(new Loader, dirname(__DIR__) . '/examples/assets/font.tsj');

	$page = new TextPage([
		['heading' => 'CREDITS']
		, 'BUILT WITH PHONICS'
		, ['name' => 'ART', 'value' => 'VINCENT PONTIER']
		, ['name' => 'MUSIC', 'value' => 'MR SPASTIC']
		, ['heading' => 'LICENCE']
		, ['name' => 'CODE', 'value' => 'APACHE']
	]);

	$page->layout($font, 240, 20);

	check(textPageLines($page) === [
		'heading:CREDITS'
		, 'text:BUILT WITH PHONICS'
		, ''
		, 'name:ART | value:VINCENT PONTIER@54'
		, 'name:MUSIC | value:MR SPASTIC@54'
		, ''
		, 'heading:LICENCE'
		, 'name:CODE | value:APACHE@54'
	], implode(',', textPageLines($page)));

	// Where the column of names would take too much of the width, each value stands indented under its name.
	$page->layout($font, 112, 20);

	check(array_slice(textPageLines($page), 4, 5) === [
		'name:ART'
		, 'value:VINCENT@8'
		, 'value:PONTIER@8'
		, 'name:MUSIC'
		, 'value:MR SPASTIC@8'
	], implode(',', textPageLines($page)));

	$plain = "CREDITS\n\nBUILT WITH PHONICS\n\nART: VINCENT PONTIER\nMUSIC: MR SPASTIC\n\nLICENCE\n\nCODE: APACHE";
	check($page->plain() === $plain);
});

test('text page notes stand under a pair\'s value or alone, as their own kind of span', function() {
	$font = new BitmapFont(new Loader, dirname(__DIR__) . '/examples/assets/font.tsj');
	$credit = ['name' => 'ART', 'value' => 'ME', 'note' => 'CC0'];
	$licence = ['name' => 'CODE', 'note' => 'MIT'];
	$page = new TextPage([$credit, ['name' => 'TUNE', 'value' => 'YOU'], $licence, ['note' => 'MIT']]);

	$page->layout($font, 240, 20);
	$lines = textPageLines($page);
	$expected = ['name:ART | value:ME@48', 'note:CC0@48', 'name:TUNE | value:YOU@48', 'name:CODE | note:MIT@48'];
	$expected = [...$expected, '', 'note:MIT'];
	check($lines === $expected, implode(',', $lines));
	check($page->plain() === "ART: ME (CC0)\nTUNE: YOU\nCODE: MIT\n\nMIT");
	rejects(fn() => new TextPage([['name' => 'ART', 'heading' => 'CC0']]));
});

test('text pages scroll by whole lines and place the thumb in proportion', function() {
	$font = new BitmapFont(new Loader, dirname(__DIR__) . '/examples/assets/font.tsj');
	$page = new TextPage([implode("\n", range(1, 20))]);

	check($page->layout($font, 100, 5) === 20 && $page->last() === 15 && $page->visible() === 5);

	foreach([[-1, 0], [3, 3]] as [$lines, $first])
	{
		$page->scroll($lines);
		check($page->first === $first);
	}

	$page->page(1);
	check($page->first === 8 && count($page->shown()) === 5 && $page->shown()[0][0]['text'] === '9');
	$page->page(-7);
	check($page->first === 3);
	$page->scrollTo(100);
	check($page->first === 15);
	check($page->thumb(100, 10) === [75.0, 25.0]);

	check(
		$page->dragged(30, 100, 10) === 6 && $page->dragged(-5, 100, 10) === 0 && $page->dragged(900, 100, 10) === 15
	);

	$page->scrollTo(-4);
	check($page->first === 0 && $page->thumb(100, 10) === [0.0, 25.0] && $page->thumb(100, 40) === [0.0, 40.0]);

	// A taller view keeps the first line in range and drops the thumb once everything fits.
	$page->scrollTo(15);
	$page->layout($font, 100, 30);
	check($page->first === 0 && $page->last() === 0 && $page->thumb(100, 10) === null);
});

test('a text page that needs a scrollbar gives up its gutter without losing its place', function() {
	$font = new BitmapFont(new Loader, dirname(__DIR__) . '/examples/assets/font.tsj');
	$page = new TextPage(array_fill(0, 12, 'AAAA AAAA'));

	// Twelve one-line paragraphs and their eleven gaps fit 80 pixels; 60 pixels wrap each paragraph in two.
	check($page->layout($font, 80, 30, 20) === 23 && $page->last() === 0);
	check($page->layout($font, 80, 10, 20) === 35 && $page->last() === 25);

	// The last line stays in view from one frame to the next: a trial at the full width never pulls it back.
	$page->scrollTo(99);
	$page->layout($font, 80, 10, 20);
	check($page->first === 25);
	rejects(fn() => $page->layout($font, 80, 10, -1));
});

test('menu text pages scroll from keys, wheel, arrows, track, thumb and drags, then return with Back', function() {
	$font = new BitmapFont(new Loader, dirname(__DIR__) . '/examples/assets/font.tsj');
	$items = [
		'play' => ['label' => 'PLAY']
		, 'help' => ['label' => 'HELP', 'text' => [implode("\n", range(1, 20))]]
	];

	$menu = new Menu($items, 'TITLE');

	$menu->open();
	$menu->select('help');
	$menu->activate();
	$page = $menu->text;
	check($page instanceof TextPage && $menu->title === 'HELP' && $menu->depth() === 1);
	check(array_keys($menu->rows()) === ['@back'] && $menu->selected === '@back');
	$page->layout($font, 100, 5);
	$input = new MenuInput;

	$press = static function(string $button) use ($input, $menu): void {
		$input->update($menu, [$button => 1], 0);
		$input->update($menu, [], 0);
	};

	$steps = [['down', 1], ['down', 2], ['up', 1], ['pageDown', 6], ['end', 15], ['pageUp', 10], ['home', 0]];

	foreach($steps as [$key, $first])
	{
		$press($key);
		check($page->first === $first, "$key should start the view on line $first, not {$page->first}");
	}

	// Held, a line key repeats as menu navigation does.
	$input->update($menu, ['down' => 1], 0);
	$input->update($menu, ['down' => 1], .5);
	check($page->first === 2 && $menu->text === $page);
	$input->update($menu, [], 0);

	$input->update($menu, [], 0, [['type' => 'wheel', 'y' => -120], ['type' => 'wheel', 'y' => -.25]]);
	check($page->first === 4);
	$input->update($menu, [], 0, [['type' => 'wheel', 'y' => 3]]);
	check($page->first === 3);

	$regions = static fn(): array => [
		['id' => '@text-up', 'scroll' => 'up', 'rect' => [200, 0, 10, 10]]
		, ['id' => '@text-down', 'scroll' => 'down', 'rect' => [200, 110, 10, 10]]
		, ['id' => '@text', 'scroll' => 'view', 'drag' => true, 'rect' => [0, 0, 190, 80], 'pitch' => 16]
		, ['id' => '@text-track', 'scroll' => 'track', 'rect' => [200, 10, 10, 100], 'thumb' => $page->thumb(100, 10)]
		, ['id' => '@back', 'label' => 'Back', 'disabled' => false, 'rect' => [0, 90, 190, 20]]
	];

	$pointer = static function(string $action, float $x, float $y) use ($input, $menu, $regions): void {
		$event = ['type' => 'pointer', 'action' => $action, 'x' => $x, 'y' => $y, 'button' => 1];
		$input->update($menu, [], 0, [$event], $regions());
	};

	$pointer('down', 205, 115);
	$pointer('up', 205, 115);
	check($page->first === 4);
	$pointer('down', 205, 5);
	$pointer('up', 205, 5);
	check($page->first === 3);

	// Dragging the lines up two pitches shows two later lines; the drag ends with the press.
	$pointer('down', 50, 60);
	$pointer('move', 50, 28);
	check($page->first === 5);
	$pointer('up', 50, 28);
	$pointer('move', 50, 0);
	check($page->first === 5);

	// The track pages towards the press; the thumb drags in proportion.
	$page->scrollTo(0);
	$pointer('down', 205, 60);
	$pointer('up', 205, 60);
	check($page->first === 5);
	$pointer('down', 205, 40);
	$pointer('move', 205, 55);
	check($page->first === 8, 'thumb drag reached ' . $page->first);
	$pointer('up', 205, 55);

	check($menu->press('@text-down') && $page->first === 9);
	check($menu->press('@text-up') && $page->first === 8);
	check(!$menu->press('play'));

	$press('back');
	check($menu->text === null && $menu->depth() === 0 && $menu->selected === 'help' && $menu->title === 'TITLE');

	// Opening the page again starts from its top; Confirm on its Back row returns as well.
	$menu->activate();
	check($menu->text === $page && $page->first === 0);
	$press('confirm');
	check($menu->text === null && $menu->selected === 'help');

	check($menu->press('help') && $menu->text === $page);
	$menu->close();
	check($menu->text === null && $menu->depth() === 0 && !$menu->isOpen);

	rejects(fn() => new Menu(['bad' => ['text' => ['A'], 'children' => []]]));
	rejects(fn() => new Menu(['bad' => ['text' => ['A'], 'input' => 'boolean']]));
	rejects(fn() => new Menu(['bad' => ['text' => 'A']]));
	check((new Menu(['ok' => ['text' => $page]]))->rows()['ok']['text'] === $page);
});
