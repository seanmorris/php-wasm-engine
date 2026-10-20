<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ui;

use PhpWasmEngine\Render\BitmapFont;

/** MB2-style transient text state, deliberately independent of DOM and GPU resources. */
final class TextMessages
{
	/** @var array<string,mixed>|null */
	private ?array $center = null, $dialog = null, $focus = null, $demo = null;
	private float $time = 0;
	private ?bool $focused = null;

	/** Show a centered status line, optionally for a bounded number of UI seconds. */
	public function showCenter(string $text, array $color = [1, 1, 1, 1], ?float $duration = null): void
	{
		$this->center = $this->message($text, $color, $duration);
	}

	public function hideCenter(): void { $this->center = null; }

	/**
	 * Show a typewriter dialog frame.  Speaker and side replace MB2's CSS portrait classes while
	 * remaining usable without copying its character artwork.
	 *
	 * @param list<string>|string $lines
	 */
	public function showDialog(array|string $lines, string $speaker = '', string $side = 'left', array $color = [1, 1, 1, 1]): void
	{
		$lines = is_string($lines) ? [$lines] : array_values($lines);

		if($lines === [] || !in_array($side, ['left', 'right'], true))
		{
			throw new \InvalidArgumentException('Dialog needs lines and a left or right side');
		}

		foreach($lines as $line)
		{
			if(!is_string($line))
			{
				throw new \InvalidArgumentException('Dialog lines must be strings');
			}

			BitmapFont::characters($line);
		}

		BitmapFont::characters($speaker);
		$this->dialog = $this->message('', $color, null) + [
			'lines' => $lines, 'speaker' => $speaker, 'side' => $side, 'started' => $this->time
		];
	}

	public function hideDialog(): void { $this->dialog = null; }
	public function clear(): void { $this->center = $this->dialog = null; }

	/** Display a keyboard/controller focus prompt. */
	public function showFocus(string $text = 'Click the game to enable keyboard control.', ?float $duration = null): void
	{
		$this->focus = $this->message($text, [1, .88, .35, 1], $duration);
	}

	public function hideFocus(): void { $this->focus = null; }

	/** Show MB2's demo state badge without assuming a recording implementation exists. */
	public function showDemo(string $mode, ?float $duration = null): void
	{
		$mode = strtolower($mode);
		[$text, $color] = match($mode)
		{
			'record', 'recording', 'rec' => ['⏺ REC', [1, .25, .25, 1]],
			'play', 'playing', 'replay' => ['▶ PLAY', [.3, 1, .45, 1]],
			default => throw new \InvalidArgumentException('Demo mode must be record or play')
		};

		$this->demo = $this->message($text, $color, $duration) + ['mode' => $mode];
	}

	public function hideDemo(): void { $this->demo = null; }

	/** Advance real UI time, including pause-menu frames, and expire timed callouts. */
	public function update(float $elapsed): void
	{
		if(!is_finite($elapsed) || $elapsed < 0)
		{
			throw new \InvalidArgumentException('Text message elapsed time must be nonnegative and finite');
		}

		$this->time += min(.25, $elapsed);

		foreach(['center', 'focus', 'demo'] as $name)
		{
			if($this->{$name} !== null && $this->{$name}['until'] !== null && $this->time >= $this->{$name}['until'])
			{
				$this->{$name} = null;
			}
		}
	}

	/** Mirror MB2's focus warning: show on blur, then dismiss shortly after focus returns. */
	public function setFocused(bool $focused): void
	{
		$previous = $this->focused;
		$this->focused = $focused;

		if($previous === null)
		{
			return;
		}

		if(!$focused)
		{
			$this->showFocus();
		}
		elseif($this->focus !== null)
		{
			$this->focus['until'] = $this->time + .75;
		}
	}

	/** @return array<string,mixed> Stable inspection state for browser diagnostics and tests. */
	public function snapshot(): array
	{
		$dialog = $this->dialog;

		if($dialog !== null)
		{
			$dialog['age'] = max(0, $this->time - $dialog['started']);
			unset($dialog['started'], $dialog['until']);
		}

		return [
			'center' => $this->snapshotMessage($this->center)
			, 'dialog' => $dialog
			, 'focus' => $this->snapshotMessage($this->focus)
			, 'demo' => $this->snapshotMessage($this->demo)
			, 'focused' => $this->focused
		];
	}

	/** @return array<string,mixed> */
	private function message(string $text, array $color, ?float $duration): array
	{
		BitmapFont::characters($text);

		if($duration !== null && (!is_finite($duration) || $duration < 0))
		{
			throw new \InvalidArgumentException('Message duration must be nonnegative and finite');
		}

		if(count($color) !== 4)
		{
			throw new \InvalidArgumentException('Message color must have four channels');
		}

		foreach($color as $channel)
		{
			if(!is_numeric($channel) || !is_finite((float)$channel) || $channel < 0 || $channel > 1)
			{
				throw new \InvalidArgumentException('Message color channels must be in [0, 1]');
			}
		}

		return ['text' => $text, 'color' => array_map('floatval', $color), 'until' => $duration === null ? null : $this->time + $duration];
	}

	/** @return array<string,mixed>|null */
	private function snapshotMessage(?array $message): ?array
	{
		if($message === null)
		{
			return null;
		}

		unset($message['until']);

		return $message;
	}
}
