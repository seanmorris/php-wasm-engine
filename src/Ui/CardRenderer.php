<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ui;

use PhpWasmEngine\Render\Atlas;
use PhpWasmEngine\Render\BitmapFont;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\RectRenderer;
use PhpWasmEngine\Render\SpriteRenderer;

/** Bitmap card headings, text, prompts, and file progress over an optional scene backdrop. */
final class CardRenderer
{
	public Atlas $atlas;
	public array $style = [
		'scale' => 2
		, 'align' => 'center'
		, 'heading' => [1, .85, .25, 1]
		, 'foreground' => [1, 1, 1, 1]
		, 'backdropDim' => .25
	];

	private SpriteRenderer $sprites;
	private RectRenderer $rects;
	private bool $ownsAtlas;
	private bool $closed = false;
	private array $cache = [], $nextCache = [];

	/**
	 * Allocate two batches, optionally borrowing a menu's font atlas.
	 *
	 * @param BitmapFont $font Annotated font and themed input symbols used by the cards.
	 * @param Atlas|null $atlas Matching font atlas to borrow; null creates an owned atlas.
	 * @return void Allocates graphics; a borrowed atlas must outlive this renderer.
	 */
	public function __construct(public BitmapFont $font, ?Atlas $atlas = null)
	{
		$this->ownsAtlas = $atlas === null;
		$this->atlas = $atlas ?? new Atlas($font->map);
		$this->sprites = new SpriteRenderer($this->atlas);
		$this->rects = new RectRenderer;
	}

	/**
	 * Draw the current card without advancing it or altering its readiness gate.
	 *
	 * @param CardSequence $sequence Sequence supplying content and fade progress.
	 * @param int $width Drawing-buffer width in pixels.
	 * @param int $height Drawing-buffer height in pixels.
	 * @param string $prompt Optional input hint; pass an empty string to hide it while blinking.
	 * @param array{ready:int,total:int,failed:int}|null $progress Known-file counts, or no bar.
	 * @param bool $backdrop Whether a scene was drawn underneath this card.
	 * @return void Renders batched rectangles and glyphs in screen space.
	 */
	public function render(
		CardSequence $sequence
		, int $width
		, int $height
		, string $prompt = ''
		, ?array $progress = null
		, bool $backdrop = false
	): void {
		$card = $sequence->current();

		if($this->closed || $card === null)
		{
			return;
		}

		$opacity = $sequence->opacity();
		$scale = min($this->style['scale'], $width / 420, $height / 240);
		$boxes = [
			[
				0, 0, $width, $height, 0, 0, 0
				, $backdrop ? $this->style['backdropDim'] : 1
			]
		];

		$batch = [];
		$this->nextCache = [];
		$align = $card->align ?? $this->style['align'];

		foreach([
			[$card->title, .3, $scale * 1.6, $this->style['heading']]
			, [$card->text, .5, $scale, $this->style['foreground']]
			, [$prompt, .8, $scale, $this->style['foreground']]
		] as [$text, $y, $size, $color])
		{
			$glyphs = $this->text($text, $width, $height * $y, $size, $color, $align);

			foreach($glyphs as $original)
			{
				$sprite = clone $original;
				$sprite->tint[3] *= $opacity;
				$batch[] = $sprite;
			}
		}

		if($progress !== null)
		{
			$track = min($width * .65, 480 * $scale);
			$x = ($width - $track) / 2;
			$y = $height * .68;
			$ratio = $progress['total'] > 0 ? min(1, max(0, $progress['ready'] / $progress['total'])) : 0;
			$boxes[] = [$x, $y, $track, 4 * $scale, .2, .25, .3, $opacity];
			$boxes[] = [$x, $y, $track * $ratio, 4 * $scale, 1, .85, .25, $opacity];
		}

		$this->rects->render($boxes, $width, $height);
		$this->sprites->render($batch, new Camera(0, 0, $width, $height));
		$this->cache = $this->nextCache;
	}

	/**
	 * Cache aligned, wrapped text without incorporating per-frame fade values in the key.
	 *
	 * @param string $text UTF-8 heading, message, or prompt.
	 * @param int $width Viewport width; text uses the middle 85 percent as its alignment box.
	 * @param float $y Vertical center of the text block in screen pixels.
	 * @param float $scale Output pixels per source font pixel.
	 * @param array{float,float,float,float} $color Text tint before the card fade.
	 * @param string $align Left, center, or right alignment for each wrapped line.
	 * @return list<\PhpWasmEngine\Render\Sprite> Cached glyphs; callers must not mutate them.
	 */
	private function text(
		string $text
		, int $width
		, float $y
		, float $scale
		, array $color
		, string $align
	): array {
		$key = serialize([$text, $width, $y, $scale, $color, $align, $this->font->theme]);
		$glyphs = $this->cache[$key] ?? null;

		if($glyphs === null)
		{
			$layout = $this->font->layout($text, $scale, $width * .85, $color, $align);
			$glyphs = $layout['sprites'];

			foreach($glyphs as $sprite)
			{
				$sprite->x += $width * .075;
				$sprite->y += $y - $layout['height'] / 2;
			}
		}

		$this->nextCache[$key] = $glyphs;

		return $glyphs;
	}

	/**
	 * Rebuild graphics after context recovery without restarting the sequence.
	 *
	 * @return void Restores owned graphics; the owner must restore a borrowed atlas first.
	 */
	public function restore(): void
	{
		if($this->closed)
		{
			return;
		}

		if($this->ownsAtlas)
		{
			$this->atlas->restore();
		}

		$this->sprites->restore();
		$this->rects->restore();
	}

	/**
	 * Release the card's graphics without disturbing a borrowed menu atlas.
	 *
	 * @return void Closes once and drops cached CPU glyph layouts.
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

		if($this->ownsAtlas)
		{
			$this->atlas->close();
		}

		$this->cache = $this->nextCache = [];
	}
}
