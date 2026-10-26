<?php
declare(strict_types=1);
namespace Phonics\Ui;

use Phonics\Render\BitmapFont;

/** Transient text state deliberately independent of DOM and GPU resources. */
final class TextMessages
{
	/** @var array<string,mixed>|null */
	private ?array $center = null, $dialog = null, $focus = null, $demo = null;
	private float $time = 0;
	private ?bool $focused = null;

	/**
	 * Show a centered status line, optionally for a bounded number of UI seconds.
	 *
	 * @param string $text Text content to display.
	 * @param array{float,float,float,float} $color Normalized RGBA text color.
	 * @param float|null $duration Nonnegative display seconds, or null to remain visible.
	 * @return void Replaces the current centered callout.
	 */
	public function showCenter(string $text, array $color = [1, 1, 1, 1], ?float $duration = null): void
	{
		$this->center = $this->message($text, $color, $duration);
	}

	/**
	 * Hide the centered status line.
	 *
	 * @return void Clears only centered callout state.
	 */
	public function hideCenter(): void { $this->center = null; }

	/**
	 * Show a typewriter dialog frame. Speaker and side let renderers select portrait presentation while
	 * remaining usable without copying its character artwork.
	 *
	 * @param list<string>|string $lines Ordered dialog lines, or one line.
	 * @param string $speaker Optional speaker label.
	 * @param string $side Portrait/panel side: left or right.
	 * @param array{float,float,float,float} $color Normalized RGBA text color.
	 * @return void Replaces the active dialog and restarts its typewriter clock.
	 */
	public function showDialog(
		array|string $lines
		, string $speaker = ''
		, string $side = 'left'
		, array $color = [1, 1, 1, 1]
	): void {
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
			'lines' => $lines
			, 'speaker' => $speaker
			, 'side' => $side
			, 'started' => $this->time
		];
	}

	/**
	 * Hide the active dialog.
	 *
	 * @return void Clears only dialog state.
	 */
	public function hideDialog(): void { $this->dialog = null; }

	/**
	 * Clear centered and dialog messages while retaining focus and demo badges.
	 *
	 * @return void Removes the two primary message channels.
	 */
	public function clear(): void { $this->center = $this->dialog = null; }

	/**
	 * Display a keyboard/controller focus prompt.
	 *
	 * @param string $text Text content to display.
	 * @param float|null $duration Nonnegative display seconds, or null to remain visible.
	 * @return void Replaces the focus prompt with the warning color.
	 */
	public function showFocus(
		string $text = 'Click the game to enable keyboard control.'
		, ?float $duration = null
	): void {
		$this->focus = $this->message($text, [1, .88, .35, 1], $duration);
	}

	/**
	 * Hide the keyboard/controller focus prompt.
	 *
	 * @return void Clears only focus-prompt state.
	 */
	public function hideFocus(): void { $this->focus = null; }

	/**
	 * Show a demo-state badge without assuming a recording implementation exists.
	 *
	 * @param string $mode Record/recording/rec or play/playing/replay.
	 * @param float|null $duration Nonnegative display seconds, or null to remain visible.
	 * @return void Replaces the demo badge with normalized mode and color.
	 */
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

	/**
	 * Hide the demo recording/playback badge.
	 *
	 * @return void Clears only demo-badge state.
	 */
	public function hideDemo(): void { $this->demo = null; }

	/**
	 * Advance real UI time, including pause-menu frames, and expire timed callouts.
	 *
	 * @param float $elapsed Elapsed time in seconds.
	 * @return void Advances timers by at most one quarter second and clears expired channels.
	 */
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

	/**
	 * Show a focus warning on blur, then dismiss it shortly after focus returns.
	 *
	 * @param bool $focused Whether the UI owns focus.
	 * @return void Updates focus state and schedules the restored-focus prompt to expire.
	 */
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

	/**
	 * Return observable state without transferring ownership.
	 *
	 * @return array<string,mixed> Stable inspection state for browser diagnostics and tests.
	 */
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

	/**
	 * Normalize message content and display options.
	 *
	 * @param string $text Text content to display.
	 * @param array $color Text or panel color.
	 * @param float|null $duration Duration in seconds.
	 * @return array<string,mixed> Result of this operation.
	 */
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

		return [
			'text' => $text
			, 'color' => array_map('floatval', $color)
			, 'until' => $duration === null ? null : $this->time + $duration
		];
	}

	/**
	 * Return the public fields of one retained message.
	 *
	 * @param array|null $message Message state to inspect or render.
	 * @return array<string,mixed>|null Result of this operation.
	 */
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
