<?php
declare(strict_types=1);
namespace Phonics\Ui;

use Phonics\Render\BitmapFont;

/**
 * A page of text to read inside a menu: how to play, credits, licences.
 *
 * Its blocks are headings, paragraphs and name/value pairs. `layout()` sets them in one column for a font and a width,
 * as lines of equal height, and the page scrolls by whole lines, as a text-mode viewer does. The page holds no GPU
 * state: `MenuRenderer` draws it inside a menu panel, and a game with its own menu art can draw `shown()` itself.
 */
final class TextPage
{
	/** Heading span, styled separately from paragraph text by the renderer. */
	public const HEADING = 'heading';
	/** Paragraph text span, rendered in the page's normal text style. */
	public const TEXT = 'text';
	/** Label span on the name side of a name/value pair. */
	public const NAME = 'name';
	/** Content span on the value side of a name/value pair. */
	public const VALUE = 'value';
	/** Secondary text, such as a licence, which renderers set a little darker than the rest. */
	public const NOTE = 'note';
	/** Share of the width that a column of names may take before pairs stack each value under its name. */
	public const NAME_COLUMN = .45;

	/** First line in view; commands and layouts keep it between zero and `last()`. */
	public int $first = 0;
	/**
	 * Blocks in reading order, each normalized to a kind and its text.
	 *
	 * @var list<array{kind:string,text?:string,name?:string,value?:string,note?:string}> Normalized heading, text,
	 * note and pair blocks.
	 */
	public readonly array $blocks;
	/**
	 * Lines from the latest layout, each a list of spans placed in font pixels at scale one; a blank line has none.
	 *
	 * @var list<list<array{kind:string,text:string,x:float}>> Laid-out lines.
	 */
	private array $lines = [];
	/** Lines the view held at the latest layout. */
	private int $visible = 1;
	/** Font, theme and width of the latest layout, so an unchanged frame does not wrap the text again. */
	private string $key = '';

	/**
	 * Validate and normalize the page's blocks.
	 *
	 * @param list<string|array<string,string>> $blocks A string or `['text' => ...]` is a paragraph,
	 * `['note' => ...]` a paragraph of secondary text, `['heading' => ...]` a heading, and
	 * `['name' => ..., 'value' => ...]` a pair, with an optional `note` set under its value (or in its place, when a
	 * pair has a note and no value); pairs are set as a table. A paragraph keeps its explicit line breaks.
	 * @return void Throws InvalidArgumentException for any other block shape.
	 */
	public function __construct(array $blocks)
	{
		$normalized = [];

		foreach($blocks as $block)
		{
			if(is_string($block))
			{
				$block = ['text' => $block];
			}

			if(!is_array($block) || $block === [])
			{
				throw new \InvalidArgumentException('Text page blocks are strings or keyed arrays');
			}

			$keys = array_keys($block);
			sort($keys);

			$kind = match($keys)
			{
				['heading'] => self::HEADING, ['text'] => self::TEXT, ['note'] => self::NOTE
				, ['name', 'value'], ['name', 'note', 'value'], ['name', 'note'] => 'pair'
				, default => throw new \InvalidArgumentException(
					'Text page blocks are a heading, text, a note, or a name and value with an optional note'
				)
			};

			foreach($block as $value)
			{
				if(!is_string($value))
				{
					throw new \InvalidArgumentException('Text page block contents must be strings');
				}

				BitmapFont::characters($value);
			}

			$normalized[] = ['kind' => $kind] + $block;
		}

		$this->blocks = $normalized;
	}

	/**
	 * Set the blocks for a font, a column width and a view height, and keep the first line in range.
	 *
	 * A page whose lines do not all fit gives up a gutter for its scrollbar and is set again in what is left; the
	 * first line is kept in range only against that final setting, so a trial at the full width never moves it.
	 *
	 * @param BitmapFont $font Font whose advances and current theme measure the text.
	 * @param float $width Column width in font pixels at scale one; a word wider than it is cut.
	 * @param int $visible Whole lines the view shows at once.
	 * @param float $gutter Font pixels a scrollbar takes from the column when the lines do not all fit.
	 * @return int Number of lines, including the blank lines between blocks.
	 */
	public function layout(BitmapFont $font, float $width, int $visible, float $gutter = 0): int
	{
		if(!is_finite($width) || $width <= 0 || $visible < 1 || !is_finite($gutter) || $gutter < 0)
		{
			throw new \InvalidArgumentException(
				'A text page needs a positive width, at least one visible line, and a nonnegative gutter'
			);
		}

		$key = implode(':', [spl_object_id($font), $font->theme, $width, $visible, $gutter]);

		if($key !== $this->key)
		{
			$this->key = $key;
			$this->lines = $this->set($font, $width);

			if($gutter > 0 && count($this->lines) > $visible)
			{
				$this->lines = $this->set($font, max(1, $width - $gutter));
			}
		}

		$this->visible = $visible;
		$this->scrollTo($this->first);

		return count($this->lines);
	}

	/**
	 * Lay every block out as lines of spans.
	 *
	 * Headings and paragraphs are separated from what precedes them by a blank line; a heading keeps its first block
	 * close, and consecutive pairs stay together. Every pair on the page shares one column of names, as wide as the
	 * widest name and a two-space gap, so tables under different headings line up; where that column would take more
	 * than `NAME_COLUMN` of the width, each value hangs under its name instead.
	 *
	 * @param BitmapFont $font Font measuring the text.
	 * @param float $width Column width in font pixels at scale one.
	 * @return list<list<array{kind:string,text:string,x:float}>> Laid-out lines.
	 */
	private function set(BitmapFont $font, float $width): array
	{
		$column = 2 * $font->spaceAdvance;

		foreach($this->blocks as $block)
		{
			if($block['kind'] === 'pair')
			{
				$column = max($column, $this->measure($font, $block['name']) + 2 * $font->spaceAdvance);
			}
		}

		// Hanging values are two spaces in, or one in a column too narrow to spare a second.
		$table = $column <= $width * self::NAME_COLUMN;
		$spaces = $width >= 24 * $font->spaceAdvance ? 2 : ($width > 8 * $font->spaceAdvance ? 1 : 0);
		$indent = $table ? $column : $spaces * $font->spaceAdvance;
		$lines = [];
		$previous = null;

		foreach($this->blocks as $block)
		{
			$together = $previous === 'pair' && $block['kind'] === 'pair';

			if($previous !== null && $previous !== self::HEADING && !$together)
			{
				$lines[] = [];
			}

			$previous = $block['kind'];

			if($block['kind'] !== 'pair')
			{
				foreach($this->wrap($font, $block[$block['kind']], $width) as $text)
				{
					$lines[] = $text === '' ? [] : [['kind' => $block['kind'], 'text' => $text, 'x' => 0.0]];
				}

				continue;
			}

			$names = $table ? [$block['name']] : $this->wrap($font, $block['name'], $width);

			if(!$table)
			{
				foreach($names as $name)
				{
					$lines[] = [['kind' => self::NAME, 'text' => $name, 'x' => 0.0]];
				}
			}

			// The value's lines, then the note's; a pair without a value starts its note beside the name.
			$rows = [];

			foreach(['value' => self::VALUE, 'note' => self::NOTE] as $key => $kind)
			{
				foreach(($block[$key] ?? '') === '' ? [] : $this->wrap($font, $block[$key], $width - $indent) as $text)
				{
					$rows[] = [$kind, $text];
				}
			}

			foreach($rows ?: [[self::VALUE, '']] as $row => [$kind, $text])
			{
				$spans = $table && $row === 0 ? [['kind' => self::NAME, 'text' => $names[0], 'x' => 0.0]] : [];

				if($text !== '')
				{
					$spans[] = ['kind' => $kind, 'text' => $text, 'x' => $indent];
				}

				$lines[] = $spans;
			}
		}

		return $lines;
	}

	/**
	 * Break text into lines no wider than a limit, on spaces, keeping explicit line breaks.
	 *
	 * @param BitmapFont $font Font measuring the text.
	 * @param string $text UTF-8 text; runs of spaces collapse to one.
	 * @param float $width Line width in font pixels at scale one.
	 * @return list<string> At least one line; a word wider than the limit is cut, after a hyphen or slash if it can be.
	 */
	private function wrap(BitmapFont $font, string $text, float $width): array
	{
		$lines = [];

		foreach(explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $paragraph)
		{
			$line = '';
			$lineWidth = 0.0;

			foreach(explode(' ', $paragraph) as $word)
			{
				if($word === '')
				{
					continue;
				}

				$wordWidth = $this->measure($font, $word);

				if($line !== '' && $lineWidth + $font->spaceAdvance + $wordWidth > $width)
				{
					$lines[] = $line;
					$line = '';
					$lineWidth = 0.0;
				}

				while($line === '' && $wordWidth > $width)
				{
					[$piece, $word] = $this->cut($font, $word, $width);

					if($word === '')
					{
						$word = $piece;

						break;
					}

					$lines[] = $piece;
					$wordWidth = $this->measure($font, $word);
				}

				$lineWidth += ($line === '' ? 0 : $font->spaceAdvance) + $wordWidth;
				$line .= ($line === '' ? '' : ' ') . $word;
			}

			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Split the longest leading piece of a word that fits a width, preferring to end it after a hyphen or slash.
	 *
	 * @param BitmapFont $font Font measuring the word.
	 * @param string $word UTF-8 word wider than the width.
	 * @param float $width Line width in font pixels at scale one.
	 * @return array{string,string} The piece, at least one character long, and the rest of the word.
	 */
	private function cut(BitmapFont $font, string $word, float $width): array
	{
		$chars = BitmapFont::characters($word);
		$fits = 1;
		$x = $this->measure($font, $chars[0]);

		while($fits < count($chars) && $x + $this->measure($font, $chars[$fits]) <= $width)
		{
			$x += $this->measure($font, $chars[$fits++]);
		}

		for($break = $fits - 1; $break > 0; $break--)
		{
			if($chars[$break - 1] === '-' || $chars[$break - 1] === '/')
			{
				$fits = $break;

				break;
			}
		}

		return [implode('', array_slice($chars, 0, $fits)), implode('', array_slice($chars, $fits))];
	}

	/**
	 * Measure a single line as `BitmapFont::layout()` advances through it.
	 *
	 * @param BitmapFont $font Font measuring the text.
	 * @param string $text UTF-8 text without line breaks.
	 * @return float Width in font pixels at scale one.
	 */
	private function measure(BitmapFont $font, string $text): float
	{
		$width = 0.0;

		foreach(BitmapFont::characters($text) as $char)
		{
			$width += $char === ' ' ? $font->spaceAdvance : $font->glyph($char)['advance'];
		}

		return $width;
	}

	/**
	 * Lines from the latest layout.
	 *
	 * @return list<list<array{kind:string,text:string,x:float}>> Every line, each a list of spans.
	 */
	public function lines(): array { return $this->lines; }

	/**
	 * Lines currently in view, from `first`.
	 *
	 * @return list<list<array{kind:string,text:string,x:float}>> At most `visible()` lines, each a list of spans.
	 */
	public function shown(): array { return array_slice($this->lines, $this->first, $this->visible); }

	/**
	 * Lines the view held at the latest layout.
	 *
	 * @return int At least one.
	 */
	public function visible(): int { return $this->visible; }

	/**
	 * The last line the view can start on, from which the end of the page is in view.
	 *
	 * @return int Zero when every line fits.
	 */
	public function last(): int { return max(0, count($this->lines) - $this->visible); }

	/**
	 * Move the view by whole lines.
	 *
	 * @param int $lines Negative scrolls towards the top.
	 * @return void Stops at the first and last lines.
	 */
	public function scroll(int $lines): void { $this->scrollTo($this->first + $lines); }

	/**
	 * Move the view by a page: as many lines as it shows.
	 *
	 * @param int $direction Negative pages up, positive pages down; only its sign counts.
	 * @return void Stops at the first and last lines.
	 */
	public function page(int $direction): void { $this->scroll(($direction <=> 0) * $this->visible); }

	/**
	 * Start the view on a line.
	 *
	 * @param int $line Line to start on; out-of-range lines clamp to the first or last.
	 * @return void Updates `first`.
	 */
	public function scrollTo(int $line): void { $this->first = max(0, min($this->last(), $line)); }

	/**
	 * Where the scrollbar's thumb stands on its track.
	 *
	 * @param float $length Track length in output pixels.
	 * @param float $minimum Shortest thumb, usually the bar's width, so the thumb is at least a square.
	 * @return array{float,float}|null The thumb's offset from the start of the track and its length, or null when
	 * every line is in view.
	 */
	public function thumb(float $length, float $minimum = 0): ?array
	{
		$last = $this->last();

		if($last === 0 || $length <= 0)
		{
			return null;
		}

		$size = min($length, max($minimum, $length * $this->visible / count($this->lines)));

		return [($length - $size) * $this->first / $last, $size];
	}

	/**
	 * The line the view starts on once the thumb has been dragged to an offset along its track.
	 *
	 * @param float $offset Distance of the thumb's start from the start of the track, in output pixels.
	 * @param float $length Track length in output pixels.
	 * @param float $minimum Shortest thumb, as passed to `thumb()`.
	 * @return int Nearest line the view can start on.
	 */
	public function dragged(float $offset, float $length, float $minimum = 0): int
	{
		$thumb = $this->thumb($length, $minimum);

		if($thumb === null || $length - $thumb[1] <= 0)
		{
			return $this->first;
		}

		return (int)round($this->last() * max(0, min(1, $offset / ($length - $thumb[1]))));
	}

	/**
	 * The page as plain text, for screen readers and logs: one block per paragraph, pairs as "name: value (note)".
	 *
	 * @return string Blocks joined by blank lines, with each run of pairs on consecutive lines.
	 */
	public function plain(): string
	{
		$text = '';
		$previous = null;

		foreach($this->blocks as $block)
		{
			if($previous !== null)
			{
				$text .= $previous === 'pair' && $block['kind'] === 'pair' ? "\n" : "\n\n";
			}

			$value = $block['value'] ?? '';
			$note = $block['note'] ?? '';
			$detail = $value !== '' && $note !== '' ? "$value ($note)" : $value . $note;
			$text .= $block['kind'] === 'pair' ? $block['name'] . ': ' . $detail : $block[$block['kind']];
			$previous = $block['kind'];
		}

		return $text;
	}
}
