<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ui;

use PhpWasmEngine\Ecs\Registry;
use PhpWasmEngine\Gameplay\TextActor;
use PhpWasmEngine\Render\Atlas;
use PhpWasmEngine\Render\BitmapFont;
use PhpWasmEngine\Render\Camera;
use PhpWasmEngine\Render\RectRenderer;
use PhpWasmEngine\Render\Sprite;
use PhpWasmEngine\Render\SpriteRenderer;

/** Draw map text and MB2-style message overlays from the engine's shared bitmap font. */
final class TextRenderer
{
	private SpriteRenderer $sprites;
	private RectRenderer $rects;
	private bool $closed = false;
	/** @var array<string,array{sprites:list<Sprite>,width:float,height:float}> */
	private array $layouts = [];
	public array $snapshot = ['worldActors' => 0, 'worldGlyphs' => 0, 'overlayGlyphs' => 0];

	/** The Atlas belongs to MenuRenderer; this renderer owns only its own GL programs and buffers. */
	public function __construct(private BitmapFont $font, Atlas $atlas)
	{
		$this->sprites = new SpriteRenderer($atlas);
		$this->rects = new RectRenderer;
	}

	/** Render static, non-colliding TextActor components in the same camera plane as map sprites. */
	public function renderActors(Registry $registry, Camera $camera): void
	{
		if($this->closed)
		{
			return;
		}

		$shadow = $glyphs = [];
		$actors = 0;

		foreach($registry->query(TextActor::class) as $entity)
		{
			$actor = $registry->get($entity, TextActor::class);
			$layout = $this->layout($actor->text, $actor->scale, $actor->width, $actor->tint, $actor->align);
			$actors++;

			foreach($layout['sprites'] as $glyph)
			{
				$front = clone $glyph;
				$front->x += $actor->x;
				$front->y += $actor->y;
				$front->opacity *= $actor->opacity;
				$glyphs[] = $front;

				$ink = clone $front;
				$ink->x += 1;
				$ink->y += 1;
				$ink->tint = [0, 0, 0, .72];
				$shadow[] = $ink;
			}
		}

		$this->snapshot['worldActors'] = $actors;
		$this->snapshot['worldGlyphs'] = count($glyphs);
		$this->sprites->render([...$shadow, ...$glyphs], $camera);
	}

	/** Draw focus, demo, center, and dialog layers in screen space above gameplay. */
	public function renderMessages(TextMessages $messages, int $width, int $height): void
	{
		if($this->closed)
		{
			return;
		}

		$state = $messages->snapshot();
		$boxes = $glyphs = [];
		$scale = max(.75, min(2.0, $width / 640, $height / 360));
		$pad = 6 * $scale;

		if($state['demo'] !== null)
		{
			$this->panelText($boxes, $glyphs, $state['demo']['text'], 8, 8, $scale, $state['demo']['color'], $pad);
		}

		if($state['focus'] !== null)
		{
			$measure = $this->layout($state['focus']['text'], $scale, $width * .76, $state['focus']['color'], 'center');
			$x = floor(($width - min($width * .76, $measure['width'])) / 2);
			$y = floor($height * .13);
			$this->panelText(
				$boxes, $glyphs, $state['focus']['text'], $x, $y, $scale, $state['focus']['color'], $pad
				, max($measure['width'], 1), 'center'
			);
		}

		if($state['center'] !== null)
		{
			$measure = $this->layout($state['center']['text'], $scale * 1.25, $width * .8, $state['center']['color'], 'center');
			$x = floor(($width - min($width * .8, $measure['width'])) / 2);
			$y = floor($height * .38);
			$this->panelText(
				$boxes, $glyphs, $state['center']['text'], $x, $y, $scale * 1.25, $state['center']['color'], $pad
				, max($measure['width'], 1), 'center'
			);
		}

		if($state['dialog'] !== null)
		{
			$dialog = $state['dialog'];
			$panelWidth = min($width * .82, 720 * $scale);
			$x = floor(($width - $panelWidth) / 2);
			$y = floor($height * .62);
			$textX = $x + $pad * 2;
			$textY = $y + $pad * 2 + ($dialog['speaker'] === '' ? 0 : $this->font->lineHeight * $scale);
			$wrap = $panelWidth - $pad * 4;
			$lineHeight = $this->font->lineHeight * $scale;
			$lineY = $textY;
			$reveal = (int)floor($dialog['age'] / .015);
			$used = 0;
			$bodyGlyphs = [];

			foreach($dialog['lines'] as $line)
			{
				$characters = BitmapFont::characters($line);
				$visible = implode('', array_slice($characters, 0, max(0, $reveal - $used)));
				$layout = $this->append($bodyGlyphs, $visible, $textX, $lineY, $scale, $dialog['color'], $wrap);
				$lineY += max($lineHeight, $layout['height']);
				$used += count($characters);
			}

			$panelHeight = max($lineY - $y + $pad * 2, $lineHeight + $pad * 4);
			$boxes[] = [$x - 2, $y - 2, $panelWidth + 4, $panelHeight + 4, .45, .58, .82, .95];
			$boxes[] = [$x, $y, $panelWidth, $panelHeight, .015, .025, .06, .94];

			if($dialog['speaker'] !== '')
			{
				$speakerAlign = $dialog['side'] === 'right' ? 'right' : 'left';
				$this->append($glyphs, $dialog['speaker'], $textX, $y + $pad, $scale, [.55, .85, 1, 1], $wrap, $speakerAlign, $wrap);
			}

			array_push($glyphs, ...$bodyGlyphs);
		}

		$this->snapshot['overlayGlyphs'] = count($glyphs);
		$this->rects->render($boxes, $width, $height);
		$this->sprites->render($glyphs, new Camera(0, 0, $width, $height));
	}

	/** Rebuild program state after a browser WebGL context recovery. */
	public function restore(): void
	{
		if(!$this->closed)
		{
			$this->sprites->restore();
			$this->rects->restore();
		}
	}

	/** Release only GL resources owned by this renderer, not MenuRenderer's shared Atlas. */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$this->layouts = [];
		$this->sprites->close();
		$this->rects->close();
	}

	/** @return array{sprites:list<Sprite>,width:float,height:float} Cached origin-relative glyph layout. */
	private function layout(string $text, float $scale, float $wrap, array $color, string $align): array
	{
		$key = serialize([$text, $scale, $wrap, $color, $align, $this->font->theme]);

		return $this->layouts[$key] ??= $this->font->layout($text, $scale, $wrap, $color, $align, is_finite($wrap) ? $wrap : null);
	}

	/** @return array{sprites:list<Sprite>,width:float,height:float} */
	private function append(
		array &$target, string $text, float $x, float $y, float $scale, array $color, float $wrap = INF
		, string $align = 'left', ?float $alignWidth = null
	): array {
		$layout = $this->font->layout($text, $scale, $wrap, $color, $align, $alignWidth);

		foreach($layout['sprites'] as $sprite)
		{
			$sprite = clone $sprite;
			$sprite->x += $x;
			$sprite->y += $y;
			$target[] = $sprite;
		}

		return $layout;
	}

	/** Add a compact dark backing rectangle and its text to an overlay batch. */
	private function panelText(
		array &$boxes, array &$glyphs, string $text, float $x, float $y, float $scale, array $color
		, float $pad, float $wrap = INF, string $align = 'left'
	): void {
		$layout = $this->layout($text, $scale, $wrap, $color, $align);
		$panelWidth = is_finite($wrap) ? min($wrap, max(1, $layout['width'])) : max(1, $layout['width']);
		$boxes[] = [$x - $pad, $y - $pad, $panelWidth + $pad * 2, $layout['height'] + $pad * 2, 0, 0, 0, .72];
		$this->append($glyphs, $text, $x, $y, $scale, $color, $wrap, $align, $panelWidth);
	}
}
