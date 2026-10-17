<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ui;

/** Deterministic, renderer-independent sequencing with readiness and fresh-press guards. */
final class CardSequence
{
	public string $state = 'idle';
	public float $elapsed = 0;

	private int $index = -1;
	private float $readyTime = 0;
	private float $closingTime = 0;
	private float $exitOpacity = 1;
	private bool $held = true;
	private bool $ready = false;

	/**
	 * Store an ordered list of uniquely named screens.
	 *
	 * @param list<Card> $cards Cards visited in order; an empty sequence completes on start.
	 * @return void Validates definitions without invoking callbacks.
	 */
	public function __construct(private array $cards)
	{
		$ids = [];

		foreach($cards as $card)
		{
			if(!$card instanceof Card || isset($ids[$card->id]))
			{
				throw new \InvalidArgumentException('Sequences require cards with unique IDs');
			}

			$ids[$card->id] = true;
		}

		$this->cards = array_values($cards);
	}

	/**
	 * Begin or replay the sequence, cancelling an unfinished visit first.
	 *
	 * @return void Enters the first card and requires release before an input press can skip it.
	 */
	public function start(): void
	{
		$this->cancel();
		$this->index = -1;
		$this->held = true;
		$this->next();
	}

	/**
	 * Read the active screen for rendering or application inspection.
	 *
	 * @return Card|null Current card, or null before start and after completion/cancellation.
	 */
	public function current(): ?Card { return $this->cards[$this->index] ?? null; }

	/**
	 * Check whether every card has finished normally.
	 *
	 * @return bool True only after exhausting the sequence, including an empty sequence.
	 */
	public function complete(): bool { return $this->state === 'complete'; }

	/**
	 * Advance active UI time and sample a held input, independently of simulation time.
	 *
	 * @param float $seconds Nonnegative active seconds; hosts should pass zero after suspension.
	 * @param bool $pressed Current Start/confirm state, not a key-repeat event.
	 * @return void Polls readiness and consumes early or held presses without queuing a skip.
	 */
	public function update(float $seconds, bool $pressed = false): void
	{
		if(!is_finite($seconds) || $seconds < 0)
		{
			throw new \InvalidArgumentException('Card delta must be finite and nonnegative');
		}

		$fresh = $pressed && !$this->held;
		$this->held = $pressed;
		$card = $this->current();

		if($card === null)
		{
			return;
		}

		$this->elapsed += $seconds;

		if($this->state === 'closing')
		{
			$this->closingTime += $seconds;

			if($this->closingTime >= $card->fadeOut)
			{
				$this->next();
			}

			return;
		}

		$this->ready = $this->ready || $card->ready();
		$this->readyTime += $this->ready ? $seconds : 0;
		$this->state = $this->elapsed < $card->fadeIn ? 'opening' : ($this->ready ? 'shown' : 'waiting');

		if($this->ready && $card->duration !== null && $this->readyTime >= $card->duration)
		{
			$this->dismiss();
		}
		elseif($fresh)
		{
			$this->advance();
		}
	}

	/**
	 * Request activation from scripting, respecting readiness, timing, and skip policy.
	 *
	 * @return bool True if this call began the exit transition.
	 */
	public function advance(): bool
	{
		$card = $this->current();

		if($card === null || !$this->ready || !$card->skippable
			|| $this->state === 'closing' || $this->elapsed < $card->skipAfter
		){
			return false;
		}

		$this->dismiss();

		return true;
	}

	/**
	 * Compute the current card's fade without coupling it to a graphics API.
	 *
	 * @return float Visibility from zero to one; zero when no card is active.
	 */
	public function opacity(): float
	{
		$card = $this->current();

		if($card === null)
		{
			return 0;
		}

		if($this->state === 'closing')
		{
			return $card->fadeOut > 0 ? $this->exitOpacity * max(0, 1 - $this->closingTime / $card->fadeOut) : 0;
		}

		return $card->fadeIn > 0 ? min(1, $this->elapsed / $card->fadeIn) : 1;
	}

	/**
	 * Cancel the current card without entering any subsequent card.
	 *
	 * @return void Calls its cleanup once; repeated cancellation is harmless.
	 */
	public function cancel(): void
	{
		$card = $this->current();
		$this->index = -1;

		if($card !== null)
		{
			$this->state = 'cancelled';
			$card->leave(true);
		}
	}

	/**
	 * Begin dismissal after a timeout or accepted activation.
	 *
	 * @return void Finishes immediately when the card has no exit fade.
	 */
	private function dismiss(): void
	{
		$this->exitOpacity = $this->opacity();
		$this->state = 'closing';
		$this->closingTime = 0;

		if($this->current()->fadeOut === 0.0)
		{
			$this->next();
		}
	}

	/**
	 * Leave the completed card and enter its successor without carrying excess frame time.
	 *
	 * @return void Resets per-card timers while retaining the held-input guard across visits.
	 */
	private function next(): void
	{
		$previous = $this->current();
		$this->index++;
		$this->elapsed = $this->readyTime = $this->closingTime = 0;
		$this->ready = false;

		$this->state = $this->current() === null ? 'complete' : 'opening';
		$previous?->leave(false);
		$this->current()?->enter();
	}
}
