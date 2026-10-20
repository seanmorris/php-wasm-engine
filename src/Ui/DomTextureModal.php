<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ui;

use PhpWasmEngine\Engine;

/** Capture menu-style input for a browser-rendered HTML texture modal. */
final class DomTextureModal
{
	private array $held = [], $next = [];
	private int $generation = -1;
	private array $state = ['open' => false, 'ready' => false, 'scroll' => 0, 'maxScroll' => 0];

	/**
	 * @param object $bridge Browser DomTexture instance supplied through vrzno_env().
	 * @return void Starts closed and keeps all rendering state in the browser bridge.
	 */
	public function __construct(private object $bridge) {}

	/** Open trusted HTML from an application-owned source. */
	public function show(string $title, string $html): void
	{
		$this->bridge->show($title, $html);
		$this->state = ['open' => true, 'ready' => false, 'scroll' => 0, 'maxScroll' => 0];
		$this->reset();
	}

	/** Close the browser panel without changing the menu page beneath it. */
	public function close(): void
	{
		$this->bridge->close();
		$this->state = ['open' => false, 'ready' => false, 'scroll' => 0, 'maxScroll' => 0];
		$this->reset();
	}

	/** Whether the browser bridge is currently displaying a panel. */
	public function isOpen(): bool { return (bool)($this->state['open'] ?? false); }

	/** Return the last bridge snapshot for diagnostics and browser tests. */
	public function state(): array { return $this->state; }

	/**
	 * Capture familiar menu controls while the modal is visible.
	 *
	 * Escape/B returns to the Settings page, while P/Start performs the normal full menu close.
	 */
	public function update(Engine $engine, float $elapsed, Menu $menu): bool
	{
		$frame = $engine->input->uiFrame(false, true);

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

	/** Draw the browser texture over the engine's finished scene. */
	public function render(int $width, int $height): void
	{
		if(!$this->isOpen())
		{
			return;
		}

		$this->bridge->render($width, $height);
		$this->synchronize();
	}

	/** Discard held actions after focus and graphics recovery. */
	public function reset(): void { $this->held = $this->next = []; }

	private function scrollBy(float $amount): void
	{
		$this->bridge->scrollBy($amount);
		$this->synchronize();
	}

	private function scrollTo(float $offset): void
	{
		$this->bridge->scrollTo($offset);
		$this->synchronize();
	}

	/** Decode the bridge snapshot without allowing malformed diagnostic state to interrupt input. */
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

	/** Advance held durations using the same first-press and accelerated repeat cadence as MenuInput. */
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

	/** Return first press and optional accelerated repeats for one logical action. */
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
