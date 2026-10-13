<?php
use PhpWasmEngine\Ui\Menu;
use PhpWasmEngine\Ui\MenuInput;
use PhpWasmEngine\Ui\TextBuffer;
use PhpWasmEngine\Render\BitmapFont;
use PhpWasmEngine\Tiled\Loader;

test('each wheel event moves one visible row regardless of HID line count', function() {
	$items = [];

	for($i = 0; $i < 12; $i++)
	{
		$items['item'.$i] = ['hidden' => $i === 6];
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

test('bitmap alignment places each wrapped line and its carets within the line box', function() {
	$font = new BitmapFont(new Loader, dirname(__DIR__).'/examples/assets/font.tsj');

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
			$font->measure('AAI', 2, 36) === ['width' => $wrapped['width']
			, 'height' => $wrapped[
				'height'
			]]
		);
	}
});

test('unwrapped alignment supports fields, empty lines, themed symbols and overflow', function() {
	$path = dirname(__DIR__).'/examples/assets/';
	$font = new BitmapFont(new Loader, $path.'font.tsj', [$path.'buttons.tsj']);
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
		'first' => ['input' => 'string', 'value' => 'kept'
			, 'hidden' => function() use (&$hidden) { return $hidden; }
		]
		, 'locked' => ['input' => 'boolean', 'value' => false, 'disabled' => true
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

	$menu = new Menu(['remote' => ['children' => function() use (&$polls) {
		$polls++;
		return null;
	}]]);
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

	$menu = new Menu(['text' => ['input' => 'string'
		, 'get' => function() use (&$value) { return $value; }
		, 'set' => function($text) use (&$value, &$sets) {
			$value = $text;
			$sets++;
		}
	]]);
	$menu->open();
	$menu->activate();
	$input = new MenuInput;

	$input->update(
		$menu
		, []
		, 0
		, [['type' => 'composition', 'text' => 'かな', 'start' => 1, 'length' => 1]]
	);
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
	$menu = new Menu(
		['slider' => ['input' => 'number'
		, 'min' => 0
		, 'max' => 10
		, 'step' => .5
		, 'value' => 5
			, 'subType' => 'range'
		]]
	);
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

	$input->update(
		$menu
		, []
		, 0
		, [['type' => 'pointer', 'action' => 'move', 'x' => 0, 'y' => 10]]
		, $regions
	);
	check($menu->value('slider') == 0 && $menu->editor === null);

	$input->update(
		$menu
		, []
		, 0
		, [['type' => 'pointer', 'action' => 'move', 'x' => 200, 'y' => 10]]
		, $regions
	);
	check($menu->value('slider') == 10 && $menu->editor === null);
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
	$path = dirname(__DIR__).'/examples/assets/font.tsj';
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

test('button glyphs select annotated themes and retain their original colors', function() {
	$font = new BitmapFont(
		new Loader
		, dirname(__DIR__).'/examples/assets/font.tsj'
		, [dirname(__DIR__).'/examples/assets/buttons.tsj']
	);
	$ids = [];

	foreach(['keyboard', 'xbox', 'playstation', 'generic'] as $theme)
	{
		$font->theme = $theme;
		$glyph = $font->layout('⓿', tint: [1, 0, 0, .5])['sprites'][0];
		$ids[] = $glyph->gid;
		check($glyph->tint === [1, 1, 1, .5]);
	}
	check(count(array_unique($ids)) === 4);
	$font->theme = 'unknown';
	check($font->layout('⓿')['sprites'][0]->gid === $ids[3]);
});

test('menu pages restore selection and scroll, wrap rows and skip hidden entries', function() {
	$menu = new Menu(
		['first' => ['label' => 'First'], 'hidden' => ['hidden' => true]
		, 'settings' => [
			'children' => ['flag' => ['input' => 'boolean', 'value' => false]]
		]
		, 'locked' => [
			'disabled' => true, 'callback' => fn() => throw new Exception('disabled')
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
		'amount' => ['input' => 'number', 'min' => 0, 'max' => 1, 'step' => .1
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
	$menu = new Menu(
		['name' => ['input' => 'string', 'value' => 'Ada']
		, 'number' => ['input' => 'number'
			, 'value' => 3, 'min' => -10, 'max' => 10
		]]
	);
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

	$menu = new Menu(['load' => ['children' => function() use (&$count) {
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
	}]]);
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
		$items['item'.$i] = ['revert' => function() use (&$resets) {
			$resets++;
		}];
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
	$path = dirname(__DIR__).'/examples/assets/font.tsj';
	$font = new BitmapFont(new Loader, $path);
	$layout = $font->layout('aI, @?🙂');
	check(array_map(fn($s) => $s->gid, $layout['sprites']) === [27, 35, 15, 18, 16, 16]);
	near($layout['width'], 51);
	check($layout['sprites'][2]->crop === [0, 0, 3, 12]);
	near($layout['sprites'][2]->y, 2);
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
