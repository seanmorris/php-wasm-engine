<?php
declare(strict_types=1);
namespace Phonics\Ui;

use Phonics\Render\Atlas;
use Phonics\Render\BitmapFont;
use Phonics\Render\Camera;
use Phonics\Render\RectRenderer;
use Phonics\Render\SpriteRenderer;

/** One shared font atlas and two instanced passes render every page of a menu. */
final class MenuRenderer
{
	/** Owned font and controller-symbol atlas. */
	public Atlas $atlas;
	/** @var list<array<string,mixed>> Pointer hit regions produced by the latest render. */
	public array $regions = [];
	/** @var array{float,float,float,float}|null Screen rectangle of the active editor row. */
	public ?array $editorRect = null;
	/** @var array<string,mixed> Mutable sizing, spacing, color, and panel settings. */
	public array $style = [
		'scale' => 2
		, 'width' => 720
		, 'padding' => 8
		, 'align' => 'left'
		, 'foreground' => [1, 1, 1, 1]
		, 'selected' => [1, .85, .25, 1]
		, 'disabled' => [.5, .5, .5, 1]
		, 'panel' => [.025, .035, .065, .94]
		, 'highlight' => [.16, .22, .32, 1]
		, 'selection' => [.2, .35, .6, 1]
		, 'dimmer' => [0, 0, 0, .45]
	];
	/** Whether navigation and action hints appear below the menu. */
	public bool $showHints = true;
	private SpriteRenderer $sprites;
	private RectRenderer $rects;
	private array $batch = [], $boxes = [], $cache = [], $nextCache = [];
	private bool $closed = false;
	private float $editScroll = 0;
	private ?TextBuffer $lastEditor = null;
	private ?Menu $scrollMenu = null;
	private array $scrollRows = [];
	private float $scrollOffset = 0, $scrollTime = 0;

	/**
	 * Upload a font and allocate reusable glyph and rectangle batches.
	 *
	 * @param BitmapFont $font Annotated font and themed symbol metadata.
	 * @return void Owns one atlas shared across all pages rendered by this object.
	 */
	public function __construct(public BitmapFont $font)
	{
		$this->atlas = new Atlas($font->map);
		$this->sprites = new SpriteRenderer($this->atlas);
		$this->rects = new RectRenderer;
	}

	/**
	 * Append a colored rectangle, optionally intersecting it with a clip rectangle.
	 *
	 * @param array{float,float,float,float} $rect Rectangle in screen pixels.
	 * @param array{float,float,float,float} $color RGBA components.
	 * @param array{float,float,float,float}|null $clip Optional screen-space clipping bounds.
	 * @return void Adds only nonempty intersections to the solid-shape batch.
	 */
	private function box(array $rect, array $color, ?array $clip = null): void
	{
		if($clip)
		{
			$x = max($rect[0], $clip[0]);
			$y = max($rect[1], $clip[1]);
			$rect = [
				$x, $y, min($rect[0] + $rect[2], $clip[0] + $clip[2]) - $x
				, min($rect[1] + $rect[3], $clip[1] + $clip[3]) - $y
			];
		}

		if($rect[2] > 0 && $rect[3] > 0)
		{
			$this->boxes[] = array_merge($rect, $color);
		}
	}

	/**
	 * Return the shared nonnegative screen-space area of two rectangles.
	 *
	 * @param array $a A value used by this operation.
	 * @param array $b B value used by this operation.
	 * @return array Result of this operation.
	 */
	private function intersect(array $a, array $b): array
	{
		$x = max($a[0], $b[0]);
		$y = max($a[1], $b[1]);

		return [
			$x, $y, max(0, min($a[0] + $a[2], $b[0] + $b[2]) - $x)
			, max(0, min($a[1] + $a[3], $b[1] + $b[3]) - $y)
		];
	}

	/**
	 * Append cached glyph instances cropped to a screen rectangle without changing GL scissor.
	 *
	 * @param string $text UTF-8 text to draw.
	 * @param float $x Screen-space left origin.
	 * @param float $y Screen-space top origin.
	 * @param float $scale Output scale in screen pixels per font pixel.
	 * @param array{float,float,float,float} $color Text tint; button glyphs keep their own colors.
	 * @param array{float,float,float,float} $clip Visible screen-space bounds.
	 * @param float $wrap Maximum line width, or infinity for a horizontally clipped field.
	 * @param string $align Left, center, or right alignment within the available width.
	 * @param float|null $alignWidth Alignment width; null uses the clip's right edge minus x.
	 * @param bool $includeVerticalOverhang Keep negative bearings and descenders outside the line box.
	 * @return array Layout including uncropped caret positions relative to the text origin.
	 */
	private function text(
		string $text
		, float $x
		, float $y
		, float $scale
		, array $color
		, array $clip
		, float $wrap = INF
		, string $align = 'left'
		, ?float $alignWidth = null
		, bool $includeVerticalOverhang = false
	): array {
		$alignWidth ??= max(1, $clip[0] + $clip[2] - $x);

		$key = serialize([
			$text, $x, $y, $scale, $color, $clip, $wrap, $align, $alignWidth
			, $this->font->theme, $includeVerticalOverhang
		]);

		$cached = $this->cache[$key] ?? null;

		if(!$cached)
		{
			$layout = $this->font->layout($text, $scale, $wrap, $color, $align, $alignWidth);
			$visible = [];

			if($includeVerticalOverhang && $layout['sprites'])
			{
				$clipTop = $clip[1];
				$clipBottom = $clip[1] + $clip[3];

				foreach($layout['sprites'] as $sprite)
				{
					$clipTop = min($clipTop, $y + $sprite->y);
					$clipBottom = max($clipBottom, $y + $sprite->y + $sprite->crop[3] * $sprite->scaleY);
				}

				$clip[1] = $clipTop;
				$clip[3] = $clipBottom - $clipTop;
			}

			foreach($layout['sprites'] as $sprite)
			{
				$sprite->x += $x;
				$sprite->y += $y;
				[$u, $v, $w, $h] = $sprite->crop;
				$left = max(0, (int)ceil(($clip[0] - $sprite->x) / $sprite->scaleX));
				$top = max(0, (int)ceil(($clip[1] - $sprite->y) / $sprite->scaleY));
				$right = min($w, (int)floor(($clip[0] + $clip[2] - $sprite->x) / $sprite->scaleX));
				$bottom = min($h, (int)floor(($clip[1] + $clip[3] - $sprite->y) / $sprite->scaleY));

				if($right <= $left || $bottom <= $top)
				{
					continue;
				}

				$sprite->crop = [$u + $left, $v + $top, $right - $left, $bottom - $top];
				$sprite->x += $left * $sprite->scaleX;
				$sprite->y += $top * $sprite->scaleY;
				$visible[] = $sprite;
			}

			$cached = [$layout, $visible];
		}

		$this->nextCache[$key] = $cached;
		array_push($this->batch, ...$cached[1]);

		return $cached[0];
	}

	/**
	 * Render a responsive screen-space menu after the world render and before present.
	 *
	 * @param Menu $menu Open page, selection, values, and optional editing buffer.
	 * @param int $width Drawing-buffer width in pixels.
	 * @param int $height Drawing-buffer height in pixels.
	 * @param float $seconds UI time used for the caret blink, independent of simulation pause.
	 * @return void Updates pointer regions and IME bounds and submits two instanced batches.
	 */
	public function render(Menu $menu, int $width, int $height, float $seconds = 0): void
	{
		$this->regions = $this->batch = $this->boxes = $this->nextCache = [];
		$this->editorRect = null;

		if(!$menu->isOpen || $this->closed)
		{
			$this->scrollMenu = null;
			$this->scrollRows = [];

			return;
		}

		$s = max(.5, min((float)$this->style['scale'], $width / 320, $height / 180));
		$pad = $this->style['padding'] * $s;
		$w = max(1, min($this->style['width'], $width - 16));
		$rowHeight = 20 * $s;
		$header = 24 * $s;
		$footer = ($this->showHints ? 38 : 20) * $s;
		$rows = $menu->rows();
		$visible = max(1, (int)floor(($height - 16 - 2 * $pad - $header - $footer) / $rowHeight));
		$count = min($visible, max(1, count($rows)));
		$h = $pad * 2 + $header + $footer + $count * $rowHeight;
		$x = floor(($width - $w) / 2);
		$y = floor(($height - $h) / 2);
		$inner = [$x + $pad, $y + $pad, $w - 2 * $pad, $h - 2 * $pad];
		$color = $this->style['foreground'];
		$index = (int)array_search($menu->selected, array_keys($rows), true);
		$menu->scroll = max(0, min($menu->scroll, max(0, count($rows) - $count)));
		$menu->scroll = min($menu->scroll, $index);
		$menu->scroll = max($menu->scroll, $index - $count + 1);
		$scroll = $this->scroll($menu, array_keys($rows), $menu->scroll, $seconds);

		$this->box([0, 0, $width, $height], $this->style['dimmer']);
		$this->box([$x, $y, $w, $h], $this->style['panel']);

		$this->text(
			$menu->title
			, $inner[0]
			, $inner[1]
			, $s
			, $color
			, [$inner[0], $inner[1], $inner[2], $header]
			, align: $this->style['titleAlign'] ?? $this->style['align']
		);

		$rowsClip = [$inner[0], $inner[1] + $header, $inner[2], $count * $rowHeight];
		$first = (int)floor($scroll);
		$line = $rowsClip[1] - ($scroll - $first) * $rowHeight;

		foreach(array_slice($rows, $first, $count + 1, true) as $id => $item)
		{
			$rect = [$inner[0], $line, $inner[2], $rowHeight];
			$this->row($menu, $id, $item, $rect, $rowsClip, $s, $seconds);
			$line += $rowHeight;
		}

		$line = $inner[1] + $header + $count * $rowHeight + 4 * $s;
		$message = $menu->loading
			? 'Loading...'
			: ($menu->error ?? ($rows[$menu->selected][
			'subtext'
			] ?? ''
			));

		$this->text(
			$message
			, $inner[0]
			, $line
			, $s * .75
			, $color
			, [$inner[0], $line, $inner[2], 16 * $s]
			, $inner[2]
			, $this->style['align']
		);

		if($this->showHints)
		{
			$hints = $menu->editor
				? 'ENTER / ESC Finish   CTRL+A Select all'
				: '✚ Move   ⓿ Select   ❶ Back   Hold ❸ Reset';

			$this->text(
				$hints
				, $inner[0]
				, $line + 18 * $s
				, $s * .75
				, $color
				, $inner
				, align: $this->style['hintAlign'] ?? $this->style['align']
			);
		}

		$this->rects->render($this->boxes, $width, $height);
		$this->sprites->render($this->batch, new Camera(0, 0, $width, $height));
		$this->cache = $this->nextCache;
	}

	/**
	 * Draw one themed control hint over an existing screen-space surface.
	 *
	 * This uses the same annotated button sheet as menu footers, so characters such as `❶`
	 * resolve to Ctrl, B, Circle, or the generic button for the active font theme.
	 *
	 * @param string $hint Hint text containing ordinary and themed glyphs.
	 * @param int $width Drawing-buffer width in pixels.
	 * @param int $height Drawing-buffer height in pixels.
	 * @param array{float,float,float,float} $rect Screen-space hint bounds.
	 * @param float $scale Output scale in screen pixels per logical font pixel.
	 * @param array{float,float,float,float} $color Text tint; button artwork keeps its source color.
	 * @return void Submits one glyph batch without drawing a menu panel.
	 */
	public function renderHint(
		string $hint
		, int $width
		, int $height
		, array $rect
		, float $scale = 1
		, array $color = [1, 1, 1, 1]
	): void {
		$this->renderHints([['text' => $hint, 'rect' => $rect, 'scale' => $scale]], $width, $height, $color);
	}

	/**
	 * Draw several control hints in one cached glyph batch over an existing surface.
	 *
	 * @param list<array{text:string,rect:array{float,float,float,float},scale?:float,theme?:string}> $hints
	 * Right-aligned hints, optionally overriding the font theme for individual device bindings.
	 * @param int $width Drawing-buffer width in pixels.
	 * @param int $height Drawing-buffer height in pixels.
	 * @param array{float,float,float,float} $color Text tint; button artwork keeps its source color.
	 * @return void Submits one glyph batch without drawing a menu panel.
	 */
	public function renderHints(array $hints, int $width, int $height, array $color = [1, 1, 1, 1]): void
	{
		$this->regions = $this->batch = $this->boxes = $this->nextCache = [];
		$this->editorRect = null;

		if($this->closed) return;

		$theme = $this->font->theme;

		foreach($hints as $hint)
		{
			$rect = $hint['rect'];

			if($rect[2] <= 0 || $rect[3] <= 0) continue;

			$this->font->theme = $hint['theme'] ?? $theme;
			$this->text(
				$hint['text']
				, $rect[0]
				, $rect[1]
				, $hint['scale'] ?? 1
				, $color
				, $rect
				, align: 'right'
				, alignWidth: $rect[2]
				, includeVerticalOverhang: true
			);
		}

		$this->font->theme = $theme;

		$this->sprites->render($this->batch, new Camera(0, 0, $width, $height));
		$this->cache = $this->nextCache;
	}

	/**
	 * Advance the displayed fractional menu viewport toward its integer navigation target.
	 *
	 * @param Menu $menu Menu value used by this operation.
	 * @param array $rows Rows value used by this operation.
	 * @param int $target Target value used by this operation.
	 * @param float $seconds Seconds value used by this operation.
	 * @return float Result of this operation.
	 */
	private function scroll(Menu $menu, array $rows, int $target, float $seconds): float
	{
		if($this->scrollMenu !== $menu || $this->scrollRows !== $rows || $seconds <= $this->scrollTime)
		{
			$this->scrollMenu = $menu;
			$this->scrollRows = $rows;
			$this->scrollOffset = $target;
			$this->scrollTime = $seconds;

			return $this->scrollOffset;
		}

		$elapsed = min(.05, $seconds - $this->scrollTime);
		$this->scrollTime = $seconds;

		// Page/Home/End jumps should reveal their selected destination immediately. Normal single-row
		// navigation retains the animated viewport motion below.
		if(abs($target - $this->scrollOffset) > 1)
		{
			$this->scrollOffset = $target;

			return $this->scrollOffset;
		}

		$this->scrollOffset = Easing::snappy($this->scrollOffset, $target, $elapsed);

		return $this->scrollOffset;
	}

	/**
	 * Lay out one row with a label, value, optional slider, and editable text selection.
	 *
	 * @param Menu $menu Owner of the row and any active text buffer.
	 * @param string $id Stable row ID used for hit testing.
	 * @param array<string,mixed> $item Resolved visible item metadata.
	 * @param array{float,float,float,float} $rect Row rectangle before viewport clipping.
	 * @param array{float,float,float,float} $rowsClip Visible scrolling row viewport.
	 * @param float $scale Screen scale for font metrics and padding.
	 * @param float $seconds Unpaused UI time for caret animation.
	 * @return void Appends row geometry and one pointer region.
	 */
	private function row(
		Menu $menu
		, string $id
		, array $item
		, array $rect
		, array $rowsClip
		, float $scale
		, float $seconds
	): void {
		[$x, $y, $w, $h] = $rect;
		$visible = $this->intersect($rect, $rowsClip);

		if($visible[2] <= 0 || $visible[3] <= 0)
		{
			return;
		}

		$selected = $menu->selected === $id;
		$type = $item['input'] ?? '';
		$color = $this->style[$item['disabled'] ? 'disabled' : ($selected ? 'selected' : 'foreground')];

		if($selected)
		{
			$this->box($rect, $this->style['highlight'], $rowsClip);
		}

		$labelWidth = $type !== '' ? $w * .49 : $w;
		$align = $item['align'] ?? $this->style['align'];
		$labelClip = $this->intersect([$x, $y, $labelWidth, $h], $rowsClip);

		if($labelClip[2] > 0 && $labelClip[3] > 0)
		{
			$this->text(
				($selected ? '> ' : '  ') . $item['label']
				, $x + 2 * $scale
				, $y + 4 * $scale
				, $scale
				, $color
				, $labelClip
				, align: $align
			);
		}

		$region = [
			'id' => $id
			, 'label' => (string)$item['label']
			, 'disabled' => (bool)$item['disabled']
			, 'rect' => $visible
		];

		if($type !== '')
		{
			$valueAlign = $item['valueAlign'] ?? $this->style['valueAlign'] ?? $align;
			$field = [$x + $w * .52, $y, $w * .48 - 4 * $scale, $h];
			$value = $type === 'boolean' ? ($item['value'] ? 'ON' : 'OFF') : (string)$item['value'];
			$isEditing = $selected && $menu->editor;

			if(($item['subType'] ?? '') === 'range' && isset($item['min'], $item['max'])
				&& !$isEditing && $item['max'] > $item['min']
			){
				$track = $field[2] * .6;

				$ratio = max(0, min(1, ($item['value'] - $item['min']) / ($item['max'] - $item['min'])));

				$this->box([$field[0], $y + 9 * $scale, $track, 2 * $scale], $this->style['disabled'], $rowsClip);

				$this->box(
					[
						$field[0] + $track * $ratio - 2 * $scale, $y + 5 * $scale, 4 * $scale
						, 10 * $scale
					]
					, $color
					, $rowsClip
				);

				$region['slider'] = [$field[0], $track];
				$field[0] += $track + 6 * $scale;
				$field[2] -= $track + 6 * $scale;
			}

			$clip = $this->intersect($field, $rowsClip);

			if($clip[2] > 0 && $clip[3] > 0 && $isEditing)
			{
				$region['carets'] = $this->field($menu->editor, $clip, $scale, $color, $seconds, $valueAlign);
				$this->editorRect = array_map('intval', $clip);
			}
			elseif($clip[2] > 0 && $clip[3] > 0)
			{
				$layout = $this->text(
					$value
					, $clip[0]
					, $y + 4 * $scale
					, $scale
					, $color
					, $clip
					, align: $valueAlign
					, alignWidth: max(1, $clip[2] - 2 * $scale)
				);

				$region['carets'] = [];

				foreach($layout['positions'] as $position)
				{
					$region['carets'][] = $clip[0] + $position[0];
				}
			}
			else
			{
				$region['carets'] = [];
			}
		}

		$this->regions[] = $region;
	}

	/**
	 * Draw a horizontally scrolling field, selection, composition, and blinking caret.
	 *
	 * @param TextBuffer $editor Committed value and uncommitted IME preview.
	 * @param array{float,float,float,float} $clip Field bounds in output pixels.
	 * @param float $scale Font scale for the field.
	 * @param array{float,float,float,float} $color Field glyph tint.
	 * @param float $seconds UI elapsed time for caret blinking.
	 * @param string $align Horizontal alignment applied to text, carets, and selection.
	 * @return list<float> Screen X coordinates of committed-text caret positions.
	 */
	private function field(
		TextBuffer $editor
		, array $clip
		, float $scale
		, array $color
		, float $seconds
		, string $align
	): array {
		if($this->lastEditor !== $editor)
		{
			$this->lastEditor = $editor;
			$this->editScroll = 0;
		}

		$alignWidth = max(1, $clip[2] - 2 * $scale);
		$base = $this->font->layout($editor->text, $scale, align: $align, alignWidth: $alignWidth);
		[$start, $end] = $editor->selection();
		$text = $editor->text;
		$layout = $base;
		$selectionStart = $start;
		$selectionEnd = $end;
		$caretIndex = $editor->caret;
		$composing = $editor->composition !== '';

		if($composing)
		{
			$chars = BitmapFont::characters($text);
			$preedit = BitmapFont::characters($editor->composition);
			array_splice($chars, $start, $end - $start, $preedit);
			$text = implode('', $chars);
			$layout = $this->font->layout($text, $scale, align: $align, alignWidth: $alignWidth);
			$selectionStart = $start + $editor->compositionStart;
			$selectionEnd = $selectionStart + $editor->compositionLength;
			$caretIndex = $selectionEnd;
		}

		$caret = $layout['positions'][$caretIndex][0];
		$this->editScroll = max(0, min($this->editScroll, $layout['width'] - $alignWidth, $caret));
		$this->editScroll = max($this->editScroll, $caret - $clip[2] + 2 * $scale);
		$x = $clip[0] - $this->editScroll;
		$y = $clip[1] + 4 * $scale;
		$selectionX = $layout['positions'][$selectionStart][0];
		$selectionWidth = $layout['positions'][$selectionEnd][0] - $selectionX;

		$this->box([$x + $selectionX, $y, $selectionWidth, 12 * $scale], $this->style['selection'], $clip);

		if($composing)
		{
			$left = $layout['positions'][$start][0];
			$length = $layout['positions'][$start + count($preedit)][0] - $left;

			$this->box([$x + $left, $y + 13 * $scale, $length, $scale], $color, $clip);
		}

		$this->text($text, $x, $y, $scale, $color, $clip, align: $align, alignWidth: $alignWidth);

		if(fmod($seconds, 1) < .55)
		{
			$this->box([$x + $caret, $y, $scale, 12 * $scale], $color, $clip);
		}

		$positions = [];

		foreach($base['positions'] as $position)
		{
			$positions[] = $x + $position[0];
		}

		return $positions;
	}

	/**
	 * Rebuild the font atlas and both batches after graphics recovery.
	 *
	 * @return void Preserves menu state and cached CPU layout while replacing GPU resources.
	 */
	public function restore(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->atlas->restore();
		$this->sprites->restore();
		$this->rects->restore();
	}

	/**
	 * Release all UI graphics owned by this renderer.
	 *
	 * @return void Closes borrowers before their atlas and drops retained text buffers.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$this->sprites->close();
		$this->rects->close();
		$this->atlas->close();
		$this->cache = $this->nextCache = $this->batch = $this->regions = [];
		$this->lastEditor = null;
	}
}
