<?php
declare(strict_types=1);
namespace Phonics\Ui;

use Phonics\Engine;

/**
 * Capture menu-style input for a browser-rendered HTML texture modal.
 *
 * It forwards navigation, confirm, cancel, paging, and accelerated scroll pulses through the browser bridge
 * while leaving HTML rendering outside PHP.
 */
final class DomTextureModal
{
	/** Held navigation states and their next repeat timestamps. */
	private array $held = [], $next = [];
	/** Graphics or runtime generation for which current state is valid. */
	private int $generation = -1;
	/** Active input theme used to choose control hints. */
	private string $theme = 'keyboard';
	/** Latest browser DOM-texture state returned through the bridge. */
	private array $state = ['open' => false, 'ready' => false, 'scroll' => 0, 'maxScroll' => 0];

	/**
	 * Initialize DomTextureModal state and its owned services.
	 *
	 * @param object $bridge Browser DomTexture instance supplied through vrzno_env().
	 * @param MenuRenderer|null $hintRenderer Optional shared bitmap renderer for themed back hints.
	 * @return void Starts closed and keeps all rendering state in the browser bridge.
	 */
	public function __construct(
		/** Browser DomTexture instance supplied through vrzno_env(). */
		private object $bridge
		, /** Optional shared bitmap renderer for themed back hints. */
		private ?MenuRenderer $hintRenderer = null
	) {}

	/**
	 * Open trusted HTML from an application-owned source.
	 *
	 * @param string $title Panel title rendered by the browser bridge.
	 * @param string $html Trusted application-owned body markup.
	 * @return void Resets input and scroll state for the new panel.
	 */
	public function show(string $title, string $html): void
	{
		$this->bridge->show($title, $html, $this->hintRenderer ? '' : 'Esc / B: Back');
		$this->state = ['open' => true, 'ready' => false, 'scroll' => 0, 'maxScroll' => 0];
		$this->reset();
	}

	/**
	 * Close the browser panel without changing the menu page beneath it.
	 *
	 * @return void Clears bridge and input state while retaining reusable renderers.
	 */
	public function close(): void
	{
		$this->bridge->close();
		$this->state = ['open' => false, 'ready' => false, 'scroll' => 0, 'maxScroll' => 0];
		$this->reset();
	}

	/**
	 * Whether the browser bridge is currently displaying a panel.
	 *
	 * @return bool True while a browser panel is open.
	 */
	public function isOpen(): bool { return (bool)($this->state['open'] ?? false); }

	/**
	 * Return the last bridge snapshot for diagnostics and browser tests.
	 *
	 * @return array<string,mixed> Open, readiness, scroll, theme, and optional back-hint state.
	 */
	public function state(): array { return $this->state + ['theme' => $this->theme]; }

	/**
	 * Capture familiar menu controls while the modal is visible.
	 *
	 * Escape/B returns to the Settings page, while P/Start performs the normal full menu close.
	 *
	 * @param Engine $engine Engine supplying UI input and pause state.
	 * @param float $elapsed Elapsed time in seconds.
	 * @param Menu $menu Menu beneath the modal, closed by the pause action.
	 * @return bool Always true while the modal captures the frame's input.
	 */
	public function update(Engine $engine, float $elapsed, Menu $menu): bool
	{
		$frame = $engine->input->uiFrame(false, true);
		$this->theme = $frame['theme'];

		if($frame['generation'] !== $this->generation || !$frame['focused'])
		{
			$this->reset();
			$this->generation = $frame['generation'];
		}

		$this->advance($frame['buttons'], min(.25, max(0, $elapsed)));

		if($this->pulses('pause'))
		{
			$this->close();
			$engine->input->suppressHeldUi();
			$menu->close();

			return true;
		}

		if($this->pulses('back'))
		{
			$this->close();
			$engine->input->suppressHeldUi();

			return true;
		}

		foreach($frame['events'] as $event)
		{
			if(($event['type'] ?? '') === 'wheel')
			{
				$this->scrollBy(-($event['y'] <=> 0) * 96);
			}
		}

		foreach(['up' => -48, 'down' => 48, 'pageUp' => -480, 'pageDown' => 480] as $key => $distance)
		{
			for($i = 0, $count = $this->pulses($key, true); $i < $count; $i++)
			{
				$this->scrollBy($distance);
			}
		}

		if($this->pulses('home', true)) $this->scrollTo(0);

		if($this->pulses('end', true)) $this->scrollTo((float)($this->state['maxScroll'] ?? 0));

		$engine->userPaused = true;

		return true;
	}

	/**
	 * Draw the browser texture over the engine's finished scene.
	 *
	 * @param int $width Width in pixels.
	 * @param int $height Height in pixels.
	 * @param int $hintScale Integer back-hint scale; header and hit bounds expand to fit.
	 * @return void Updates the bridge texture and optional clickable bitmap back hint.
	 */
	public function render(int $width, int $height, int $hintScale = 1): void
	{
		if(!$this->isOpen())
		{
			return;
		}

		$hintScale = max(1, $hintScale);
		$headerHeight = max(38, 24 * $hintScale);
		$this->bridge->render($width, $height, $headerHeight);
		$this->synchronize();

		if($this->hintRenderer)
		{
			$margin = max(12, round(min($width, $height) * .035));
			$panelWidth = max(160, min(920, $width - $margin * 2));
			$panelRight = round(($width + $panelWidth) / 2);
			$this->hintRenderer->font->theme = $this->theme;
			$size = $this->hintRenderer->font->measure('❶ Back', $hintScale);
			$padding = 8 * $hintScale;
			$left = $panelRight - $size['width'] - 2 * $padding;
			$this->state['backHint'] = [$left, $margin, $size['width'] + 2 * $padding, $headerHeight];
			$this->state['hintScale'] = $hintScale;

			$this->hintRenderer->renderHint(
				'❶ Back'
				, $width
				, $height
				, [
					$left + $padding
					, $margin + floor(($headerHeight - $size['height']) / 2)
					, $size['width'], $size['height']
				]
				, $hintScale
				, [.88, .92, 1, 1]
			);
		}
	}

	/**
	 * Discard held actions after focus and graphics recovery.
	 *
	 * @return void Clears held durations and repeat deadlines.
	 */
	public function reset(): void { $this->held = $this->next = []; }

	/**
	 * Scroll the modal by the requested offset.
	 *
	 * @param float $amount Signed scroll distance in document pixels.
	 * @return void Scrolls the bridge and refreshes the cached modal state.
	 */
	private function scrollBy(float $amount): void
	{
		$this->bridge->scrollBy($amount);
		$this->synchronize();
	}

	/**
	 * Scroll the modal to the requested position.
	 *
	 * @param float $offset Absolute vertical document position in pixels.
	 * @return void Positions the bridge and refreshes the cached modal state.
	 */
	private function scrollTo(float $offset): void
	{
		$this->bridge->scrollTo($offset);
		$this->synchronize();
	}

	/**
	 * Decode the bridge snapshot without allowing malformed diagnostic state to interrupt input.
	 *
	 * @return void Replaces cached state when the bridge returns a valid JSON object.
	 */
	private function synchronize(): void
	{
		try
		{
			$state = json_decode((string)$this->bridge->state(), true, flags: JSON_THROW_ON_ERROR);

			if(is_array($state))
			{
				$this->state = $state;
			}
		}
		catch(\Throwable)
		{
			// The next render can recover after a browser context restore.
		}
	}

	/**
	 * Advance held durations using the same first-press and accelerated repeat cadence as MenuInput.
	 *
	 * @param array<string,float> $buttons Logical UI action strengths for the current frame.
	 * @param float $elapsed Elapsed time in seconds.
	 * @return void Advances held durations and clears repeat deadlines for released actions.
	 */
	private function advance(array $buttons, float $elapsed): void
	{
		foreach(array_unique(array_merge(array_keys($buttons), array_keys($this->held))) as $key)
		{
			if(($buttons[$key] ?? 0) >= .5)
			{
				$this->held[$key] = isset($this->held[$key]) ? $this->held[$key] + $elapsed : 0;
			}
			else
			{
				unset($this->held[$key], $this->next[$key]);
			}
		}
	}

	/**
	 * Return first press and optional accelerated repeats for one logical action.
	 *
	 * @param string $key Logical UI action whose press and hold state should be sampled.
	 * @param bool $repeat Whether a held action should emit accelerated repeat pulses.
	 * @return int Number of fresh or repeated action pulses due this frame.
	 */
	private function pulses(string $key, bool $repeat = false): int
	{
		if(!isset($this->held[$key]))
		{
			return 0;
		}

		if(!isset($this->next[$key]))
		{
			$this->next[$key] = .5;

			return 1;
		}

		$count = 0;

		while($repeat && $this->held[$key] + 1e-9 >= $this->next[$key])
		{
			$count++;
			$this->next[$key] += $this->next[$key] < 1.5 - 1e-9 ? .25 : .05;
		}

		return $count;
	}
}
