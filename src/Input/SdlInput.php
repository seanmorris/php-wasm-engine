<?php
declare(strict_types=1);
namespace PhpWasmEngine\Input;

final class SdlInput
{
	public bool $quit = false;
	private array $keys = [], $pressedKeys = [], $pressedButtons = [];
	public Gamepads $gamepads;
	private bool $focused = true, $closed = false;
	private bool $resetPads = false;
	private $canvas = null, $focusListener = null, $blurListener = null;

	/**
	 * Create gamepad polling and attach browser canvas focus listeners when available.
	 *
	 * @return void Initializes keyboard buffering and controller state.
	 */
	public function __construct()
	{
		$this->gamepads = new Gamepads;

		if(function_exists('vrzno_env'))
		{
			$this->canvas = vrzno_env('canvas');
		}

		if($this->canvas)
		{
			$this->focusListener =
				/**
				 * Mark the canvas focused and discard input held across the transition.
				 *
				 * @return null Requests fresh keyboard and controller state.
				 */
				fn() => $this->focus(true);
			$this->blurListener =
				/**
				 * Mark the canvas unfocused and release its buffered controls.
				 *
				 * @return null Suspends input until a later focus event.
				 */
				fn() => $this->focus(false);
			$this->canvas->addEventListener('focus', $this->focusListener);
			$this->canvas->addEventListener('blur', $this->blurListener);
			$this->focus((bool)$this->canvas->matches(':focus'));
		}
	}

	/**
	 * Apply a focus transition and discard stale input state.
	 *
	 * @param bool $focused Whether the canvas or native window currently accepts game input.
	 * @return void Clears keyboard edges and requests controller neutralization on the next poll.
	 */
	private function focus(bool $focused): void
	{
		if($this->focused === $focused)
		{
			return;
		}

		$this->focused = $focused;
		$this->keys = $this->pressedKeys = $this->pressedButtons = [];
		$this->resetPads = true;
	}

	/**
	 * Discard queued events and held input after a suspended frame loop.
	 *
	 * @return void Clears keyboard buffers and suppresses currently held controller inputs.
	 */
	public function reset(): void
	{
		$this->poll();
		$this->keys = $this->pressedKeys = $this->pressedButtons = [];
		$this->gamepads->reset();
	}

	/**
	 * Drain SDL events and buffer keyboard and controller presses for simulation.
	 *
	 * @return void Updates quit, focus, held controls, and short-lived button presses.
	 */
	public function poll(): void
	{
		if($this->closed)
		{
			return;
		}

		$event = new \SDL_Event;

		while(SDL_PollEvent($event))
		{
			if($event->type === SDL_QUIT)
			{
				$this->quit = true;
			}

			if($event->type === SDL_WINDOWEVENT && $event->window
				->event === SDL_WINDOWEVENT_FOCUS_LOST
			){
				$this->focus(false);
			}

			if($event->type === SDL_WINDOWEVENT && $event->window
				->event === SDL_WINDOWEVENT_FOCUS_GAINED
			){
				$this->focus(!$this->canvas || $this->canvas->matches(':focus'));
			}

			if($event->type === SDL_KEYUP)
			{
				$this->keys[$event->key->keysym->sym] = false;
			}

			// Repeated keydowns must not reactivate a key cleared by focus or pause recovery.
			if($event->type === SDL_KEYDOWN && !$event->key->repeat && $this->focused)
			{
				$this->keys[$event->key->keysym->sym] = $this->pressedKeys[$event->key->keysym
					->sym
				] = true;
			}
		}

		if($this->resetPads || !$this->focused)
		{
			$this->gamepads->reset();
		}
		else
		{
			$this->gamepads->poll();
		}

		$this->resetPads = false;

		foreach([SDL_CONTROLLER_BUTTON_A
		, SDL_CONTROLLER_BUTTON_B
		, SDL_CONTROLLER_BUTTON_BACK
			, SDL_CONTROLLER_BUTTON_START
		] as $button
		){
			if($this->gamepads->button($button))
			{
				$this->pressedButtons[$button] = true;
			}
		}
	}

	/**
	 * Read input for one simulation step and consume buffered press edges.
	 *
	 * @return array<string,bool|int|float> Named button states and analog movement strengths; empty
	 * while unfocused or closed.
	 */
	public function consume(): array
	{
		$snapshot = $this->snapshot();
		$this->pressedKeys = $this->pressedButtons = [];
		return $snapshot;
	}

	/**
	 * Merge keyboard keys, the strongest stick, and D-pad/button states.
	 *
	 * @return array<string,bool|int|float> Current logical controls without consuming buffered
	 * short presses.
	 */
	public function snapshot(): array
	{
		if($this->closed || !$this->focused)
		{
			return [];
		}

		$down =
			/**
			 * Check whether any alternative keyboard binding is held or buffered.
			 *
			 * @param int ...$codes Alternative SDL key symbols for one logical action.
			 * @return bool True if any supplied SDL key code is active.
			 */
			fn(...$codes) => count(array_filter($codes,
				/**
				 * Check a single SDL key against held state and buffered short presses.
				 *
				 * @param int $code SDL key symbol to inspect.
				 * @return bool Whether the key contributes an active binding.
				 */
				fn($code) => !empty($this->keys[$code]) || !empty($this->pressedKeys[$code])
			)) > 0;

		$button =
			/**
			 * Merge a held controller button with its buffered short press.
			 *
			 * @param int $id SDL controller button identifier.
			 * @return bool Whether any controller activates the requested button.
			 */
			fn($id) => $this->gamepads->button($id) || !empty($this->pressedButtons[$id]);
		$x = $this->gamepads->axis(SDL_CONTROLLER_AXIS_LEFTX);
		$y = $this->gamepads->axis(SDL_CONTROLLER_AXIS_LEFTY);
		$left = $button(SDL_CONTROLLER_BUTTON_DPAD_LEFT);
		$right = $button(SDL_CONTROLLER_BUTTON_DPAD_RIGHT);
		$up = $button(SDL_CONTROLLER_BUTTON_DPAD_UP);
		$downPad = $button(SDL_CONTROLLER_BUTTON_DPAD_DOWN);

		if($left || $right)
		{
			$x = (int)$right - (int)$left;
		}

		if($up || $downPad)
		{
			$y = (int)$downPad - (int)$up;
		}

		return ['left' => max((int)$down(SDLK_LEFT, SDLK_a), -$x, 0)
		, 'right' => max((int)$down(
			SDLK_RIGHT
			, SDLK_d
		), $x, 0
		)
			, 'up' => max((int)$down(SDLK_UP, SDLK_w), -$y, 0)
			, 'down' => max(
				(int)$down(SDLK_DOWN, SDLK_s)
				, $y
				, 0
			)
				, 'jump' => $down(SDLK_SPACE, SDLK_z, SDLK_RETURN)
			|| $button(SDL_CONTROLLER_BUTTON_A)
			, 'action' => $down(
				SDLK_x
				, SDLK_LSHIFT
				, SDLK_LCTRL
				, SDLK_RCTRL
			) || $button(SDL_CONTROLLER_BUTTON_B)
				, 'reset' => $down(SDLK_r) || $button(
					SDL_CONTROLLER_BUTTON_BACK
				)
					, 'pause' => $down(SDLK_p, SDLK_ESCAPE) || $button(
						SDL_CONTROLLER_BUTTON_START
					)
		];
	}

	/**
	 * Detach browser focus listeners and release owned gamepad handles.
	 *
	 * @return void Closes once and clears all retained input state.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$this->keys = $this->pressedKeys = $this->pressedButtons = [];

		if($this->canvas)
		{
			$this->canvas->removeEventListener('focus', $this->focusListener);
			$this->canvas->removeEventListener('blur', $this->blurListener);
		}

		$this->canvas = $this->focusListener = $this->blurListener = null;
		$this->gamepads->close();
	}
}
