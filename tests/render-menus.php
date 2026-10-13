<?php
require dirname(__DIR__).'/autoload.php';

use PhpWasmEngine\Asset\Assets;
use PhpWasmEngine\Render\Atlas;
use PhpWasmEngine\Render\BitmapFont;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\Device;
use PhpWasmEngine\Render\SpriteRenderer;
use PhpWasmEngine\Tiled\Loader;
use PhpWasmEngine\Ui\Menu;
use PhpWasmEngine\Ui\MenuRenderer;

// Known solid quadrants let the test check glyph output without sampling the font itself.
$data = json_decode(file_get_contents(__DIR__.'/assets/sprites.tsj'), true);
$data['properties'] = [
	['name' => 'font.lineHeight', 'value' => 10]
	, ['name' => 'font.spaceAdvance', 'value' => 4]
	, ['name' => 'font.caseFold', 'value' => true]
];
$data['tiles'] = [];

foreach([
	['glyph.char' => 'A', 'glyph.width' => 6, 'glyph.height' => 5
		, 'glyph.advance' => 7, 'glyph.offsetX' => 1, 'glyph.offsetY' => 2
	]
	, ['glyph.char' => 'B', 'glyph.advance' => 9]
	, ['glyph.char' => '?']
	, ['glyph.char' => '⓿', 'glyph.theme' => 'keyboard', 'glyph.advance' => 10
		, 'glyph.tint' => false
	]
] as $id => $properties
){
	$tile = ['id' => $id, 'properties' => []];

	foreach($properties as $name => $value)
	{
		$tile['properties'][] = compact('name', 'value');
	}
	$data['tiles'][] = $tile;
}

$font = new BitmapFont(
	new Loader(new Assets(fn() => json_encode($data))), __DIR__.'/assets/font.tsj'
);
$font->theme = 'keyboard';
$device = new Device(320, 180);
$atlas = new Atlas($font->map);
$sprites = new SpriteRenderer($atlas);
$device->begin([0, 0, 0, 1]);

$sprites->render(
	$font->layout("aB ⓿?\nAA", 2, 60, [.5, .5, .5, 1])['sprites']
	, new Camera(0, 0, 320, 180)
);
$pixels = glReadPixels(0, 0, 320, 180, GL_RGBA, GL_UNSIGNED_BYTE);
$expected = [
	[2, 4, 12, 10, '800000ff'], [14, 0, 16, 16, '008000ff']
	, [40, 0, 16, 16, 'ffff00ff'], [0, 20, 16, 16, '000080ff']
	, [2, 44, 12, 10, '800000ff'], [16, 44, 12, 10, '800000ff']
];
$checks = [];
$differences = [];

/**
 * Read an RGBA pixel in top-left screen coordinates.
 *
 * @param string $pixels Bottom-up 320 by 180 framebuffer bytes.
 * @param int $x Screen X coordinate.
 * @param int $y Screen Y coordinate.
 * @return string Eight hexadecimal RGBA digits.
 */
function menuPixel(string $pixels, int $x, int $y): string
{
	return bin2hex(substr($pixels, ((179 - $y) * 320 + $x) * 4, 4));
}

for($y = 0; $y < 180; $y++)
{
	for($x = 0; $x < 320; $x++)
	{
		$color = '000000ff';

		foreach($expected as [$left, $top, $width, $height, $rgba])
		{
			if($x >= $left && $x < $left + $width && $y >= $top && $y < $top + $height)
			{
				$color = $rgba;
			}
		}
		$actual = menuPixel($pixels, $x, $y);

		if($actual !== $color && count($differences) < 8)
		{
			$differences[] = [$x, $y, $color, $actual];
		}
	}
}
$checks['glyph crops offsets wrapping scale and tint'] = !$differences;
$checks['one glyph batch'] = $sprites->draws === 1 && $sprites->instances === 6;
$sprites->close();
$atlas->close();

$renderer = new MenuRenderer($font);
$renderer->style['scale'] = 1;
$renderer->style['selected'] = [1, 1, 1, 1];
$renderer->style['selection'] = [0, 1, 0, 1];

foreach(['panel', 'dimmer', 'highlight'] as $key)
{
	$renderer->style[$key] = [0, 0, 0, 0];
}
$renderer->showHints = false;
$menu = new Menu(['name' => ['label' => '', 'input' => 'string', 'value' => 'AAA']], '');
$menu->open();
$menu->activate();
$menu->editor->anchor = 1;
$menu->editor->caret = 2;
$menu->editor->compose('BBBB', 1, 1);
$device->begin([0, 0, 0, 1]);
$renderer->render($menu, 320, 180);
$pixels = glReadPixels(0, 0, 320, 180, GL_RGBA, GL_UNSIGNED_BYTE);
// The committed selection is replaced by preedit. Its selected second B spans advances 16..25.
$clip = $renderer->regions[0]['rect'];
$left = $clip[0] + $clip[2] * .52;
$top = $clip[1] + 4;
$checks['preedit caret'] = menuPixel($pixels, (int)round($left + 25), (int)$top + 11)
	=== 'ffffffff';
$checks['preedit selection'] = menuPixel($pixels, (int)round($left + 20), (int)$top + 11)
	=== '00ff00ff';
$checks['preedit underline'] = menuPixel($pixels, (int)round($left + 12), (int)$top + 13)
	=== 'ffffffff';
$checks['preedit never changes application value'] = $menu->value('name') === 'AAA';

$menu->editor->compose(str_repeat('B', 40), 39, 1);
$device->begin([0, 0, 0, 1]);
$renderer->render($menu, 320, 180);
$pixels = glReadPixels(0, 0, 320, 180, GL_RGBA, GL_UNSIGNED_BYTE);
$right = $clip[0] + $clip[2] - 4;
$checks['long preedit scrolls its caret into view'] =
	menuPixel($pixels, (int)round($right - 2), (int)$top + 11) === 'ffffffff';
$checks['field clips its glyphs'] = true;

for($y = (int)$top; $y < $top + 14; $y++)
{
	for($x = (int)ceil($right); $x < 320; $x++)
	{
		$checks['field clips its glyphs'] &= menuPixel($pixels, $x, $y) === '000000ff';
	}
}
$checks['field clips its glyphs'] = (bool)$checks['field clips its glyphs'];
$texture = $renderer->atlas->texture;
$font->theme = 'generic';
$renderer->render($menu, 320, 180);
$checks['theme changes reuse the atlas'] = $renderer->atlas->texture === $texture;
$renderer->restore();
$device->begin([0, 0, 0, 1]);
$renderer->render($menu, 320, 180);
$checks['restoration preserves UI pixels'] = $pixels
	=== glReadPixels(0, 0, 320, 180, GL_RGBA, GL_UNSIGNED_BYTE);
glEnable(GL_SCISSOR_TEST);
glScissor(3, 4, 100, 80);
$renderer->render($menu, 320, 180);
$checks['caller scissor preserved'] = glIsEnabled(GL_SCISSOR_TEST)
	&& glGetIntegerv(GL_SCISSOR_BOX) === [3, 4, 100, 80];
glDisable(GL_SCISSOR_TEST);
$renderer->close();
$checks['atlas released'] = !glIsTexture($renderer->atlas->texture);
$error = glGetError();
$device->close();
echo json_encode(compact('checks', 'differences', 'error'));
