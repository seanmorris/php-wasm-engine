<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ui;

use PhpWasmEngine\Render\BitmapFont;

/** A single-line UTF-8 buffer; cursor offsets are Unicode codepoints, as in SDL text events. */
final class TextBuffer
{
	public string $text;
	public int $caret;
	public int $anchor;
	public string $composition = '';
	public int $compositionStart = 0;
	public int $compositionLength = 0;

	/**
	 * Create an editable value with its caret at the end.
	 *
	 * @param string $text Initial valid UTF-8 value.
	 * @return void Normalizes line breaks and initializes an empty selection.
	 */
	public function __construct(string $text)
	{
		$this->text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
		$this->caret = $this->anchor = count(BitmapFont::characters($this->text));
	}

	/**
	 * Get the ordered selection boundaries.
	 *
	 * @return array{int,int} Start and exclusive end in codepoints.
	 */
	public function selection(): array
	{
		return [min($this->anchor, $this->caret), max($this->anchor, $this->caret)];
	}

	/**
	 * Copy the current selection without modifying the buffer.
	 *
	 * @return string Selected UTF-8 text, empty for a collapsed selection.
	 */
	public function selectedText(): string
	{
		[$start, $end] = $this->selection();

		return implode('', array_slice(BitmapFont::characters($this->text), $start, $end - $start));
	}

	/**
	 * Replace the selection with committed text and clear any composition preview.
	 *
	 * @param string $text Committed UTF-8 text; pasted line breaks become spaces.
	 * @return void Moves the collapsed caret after the inserted codepoints.
	 */
	public function insert(string $text): void
	{
		$chars = BitmapFont::characters($this->text);
		$insert = BitmapFont::characters(str_replace(["\r\n", "\r", "\n"], ' ', $text));
		[$start, $end] = $this->selection();
		array_splice($chars, $start, $end - $start, $insert);
		$this->text = implode('', $chars);
		$this->caret = $this->anchor = $start + count($insert);
		$this->compose('');
	}

	/**
	 * Move or extend the selection by a codepoint offset.
	 *
	 * @param int $delta Signed cursor distance, clamped to the value's boundaries.
	 * @param bool $extend Preserve the anchor for Shift-selection when true.
	 * @return void Updates the caret without splitting UTF-8 bytes.
	 */
	public function move(int $delta, bool $extend = false): void
	{
		if(!$extend && $this->caret !== $this->anchor)
		{
			$this->caret = $delta < 0 ? min($this->caret, $this->anchor) : max($this->caret, $this->anchor);
		}
		else
		{
			$this->caret = max(0, min(count(BitmapFont::characters($this->text)), $this->caret + $delta));
		}

		if(!$extend)
		{
			$this->anchor = $this->caret;
		}

		$this->compose('');
	}

	/**
	 * Select the complete committed value.
	 *
	 * @return void Places the anchor at zero and the caret after the last codepoint.
	 */
	public function selectAll(): void
	{
		$this->anchor = 0;
		$this->caret = count(BitmapFont::characters($this->text));
	}

	/**
	 * Delete the selection or one adjacent codepoint.
	 *
	 * @param bool $backward Delete before the caret for Backspace, or after it for Delete.
	 * @return void Leaves a collapsed caret at the deletion boundary.
	 */
	public function erase(bool $backward = true): void
	{
		if($this->caret === $this->anchor)
		{
			$this->move($backward ? -1 : 1, true);
		}

		$this->insert('');
	}

	/**
	 * Store uncommitted IME text separately from the application value.
	 *
	 * @param string $text UTF-8 preedit text; empty text cancels composition.
	 * @param int $start Selected preedit offset in codepoints.
	 * @param int $length Selected preedit length in codepoints.
	 * @return void Updates the preview without inserting text or invoking a setter.
	 */
	public function compose(string $text, int $start = 0, int $length = 0): void
	{
		$count = count(BitmapFont::characters($text));
		$this->composition = $text;
		$this->compositionStart = max(0, min($count, $start));
		$this->compositionLength = max(0, min($count - $this->compositionStart, $length));
	}
}
