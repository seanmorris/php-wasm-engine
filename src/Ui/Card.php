<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ui;

/** A screen's content, timing, readiness gate, and optional application lifecycle hooks. */
final class Card
{
	private $ready;
	private $onEnter;
	private $onLeave;

	/**
	 * Define one reusable card without allocating graphics or starting timers.
	 *
	 * @param string $id Nonempty identifier exposed to application code.
	 * @param string $title Primary heading, with optional explicit line breaks.
	 * @param string $text Supporting text, wrapped by the renderer.
	 * @param float|null $duration Ready seconds before fading out; null waits for activation.
	 * @param float $skipAfter Minimum active seconds before a fresh press may skip this card.
	 * @param float $fadeIn Seconds used to reveal the card.
	 * @param float $fadeOut Seconds used to dismiss the card.
	 * @param bool $skippable Whether user or scripted activation may dismiss the card.
	 * @param callable():bool|null $ready Optional polled loading gate; timers wait for true.
	 * @param callable(Card):void|null $onEnter Hook for starting application work or audio.
	 * @param callable(Card,bool):void|null $onLeave Cleanup hook; second argument means cancelled.
	 * @param string|null $align Left, center, or right; null inherits the renderer's alignment.
	 * @return void Stores validated timing and callbacks for CardSequence.
	 */
	public function __construct(public string $id, public string $title = '',
		public string $text = '', public ?float $duration = 2.5, public float $skipAfter = .5,
		public float $fadeIn = .25, public float $fadeOut = .25, public bool $skippable = true,
		?callable $ready = null, ?callable $onEnter = null, ?callable $onLeave = null,
		public ?string $align = null
	){
		if($id === '')
		{
			throw new \InvalidArgumentException('Cards require a nonempty ID');
		}

		if($align !== null && !in_array($align, ['left', 'center', 'right'], true))
		{
			throw new \InvalidArgumentException('Alignment must be left, center, or right');
		}

		foreach([$duration ?? 0, $skipAfter, $fadeIn, $fadeOut] as $seconds)
		{
			if(!is_finite($seconds) || $seconds < 0)
			{
				throw new \InvalidArgumentException('Card times must be finite and nonnegative');
			}
		}

		$this->ready = $ready;
		$this->onEnter = $onEnter;
		$this->onLeave = $onLeave;
	}

	/**
	 * Poll the application gate without introducing a dependency on its asset loader.
	 *
	 * @return bool True once the card may time out or accept activation.
	 */
	public function ready(): bool { return $this->ready === null || ($this->ready)(); }

	/**
	 * Notify the application that this card became current.
	 *
	 * @return void Calls the optional entry hook once per sequence visit.
	 */
	public function enter(): void
	{
		if($this->onEnter !== null)
		{
			($this->onEnter)($this);
		}
	}

	/**
	 * Release application resources after completion or interruption.
	 *
	 * @param bool $cancelled True for cancellation or restarting an unfinished sequence.
	 * @return void Calls the optional exit hook with the reason for leaving.
	 */
	public function leave(bool $cancelled): void
	{
		if($this->onLeave !== null)
		{
			($this->onLeave)($this, $cancelled);
		}
	}
}
