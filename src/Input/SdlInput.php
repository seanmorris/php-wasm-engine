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
	private array $uiEvents = [];
	private array $uiBlocked = [];
	private bool $uiEditing = false;
	private bool $textInput = false, $switchingText = false;
	private $textTarget = null;
	public int $generation = 0;
	public string $inputTheme = 'keyboard';

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
				fn() => $this->switchingText ? null : $this->focus($this->ownsFocus());
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
		$this->generation++;
		$this->uiEvents = [];
		$this->uiBlocked = [];
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
		$this->uiEvents = [];
		$this->generation++;
		$this->uiBlocked = [];
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

		$this->uiEvents = [];

		if($this->canvas)
		{
			$this->focus($this->ownsFocus());
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
				$this->focus($this->ownsFocus());
			}

			if($event->type === SDL_KEYUP)
			{
				$this->keys[$event->key->keysym->sym] = false;
				unset($this->uiBlocked[$event->key->keysym->sym]);
			}

			// Repeated keydowns must not reactivate a key cleared by focus or pause recovery.
			if($event->type === SDL_KEYDOWN && !$event->key->repeat && $this->focused)
			{
				unset($this->uiBlocked[$event->key->keysym->sym]);
				$this->inputTheme = 'keyboard';
				$this->keys[$event->key->keysym->sym] = $this->pressedKeys[$event->key->keysym
					->sym
				] = true;
			}

			if($this->focused)
			{
				$this->uiEvent($event);
			}
		}

		$activity = $this->gamepads->activity;

		if($this->resetPads || !$this->focused)
		{
			$this->gamepads->reset();
		}
		else
		{
			$this->gamepads->poll();
		}

		$this->resetPads = false;

		if($activity !== $this->gamepads->activity && $this->focused)
		{
			$this->inputTheme = self::padTheme($this->gamepads->activeName());
		}
		elseif($this->inputTheme !== 'keyboard' && !$this->gamepads->ids())
		{
			$this->inputTheme = 'keyboard';
		}

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

		$this->setTextInput(false);
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

	/**
	 * Classify controller names without assuming that unknown standard pads are Xbox pads.
	 *
	 * @param string $name SDL/browser controller name, including optional USB vendor IDs.
	 * @return string xbox, playstation, or generic hint theme.
	 */
	public static function padTheme(string $name): string
	{
		if(preg_match('/playstation|dualshock|dualsense|sony|054c/i', $name))
		{
			return 'playstation';
		}

		if(preg_match('/xbox|xinput|microsoft|045e/i', $name))
		{
			return 'xbox';
		}

		return 'generic';
	}

	/**
	 * Check browser focus without treating SDL's owned IME textarea as an external editor.
	 *
	 * @return bool True for native input, the canvas, or the captured SDL editing target.
	 */
	private function ownsFocus(): bool
	{
		return !$this->canvas || $this->canvas->matches(':focus')
			|| ($this->textTarget && $this->textTarget->matches(':focus'));
	}

	/**
	 * Start or stop SDL's existing browser/native text transport and position its candidate hint.
	 *
	 * @param bool $enabled Whether an application text field is being edited.
	 * @param array{int,int,int,int}|null $rect Candidate rectangle in SDL window pixels.
	 * @return void Owns only the focus listeners added for SDL's editing target.
	 */
	public function setTextInput(bool $enabled, ?array $rect = null): void
	{
		if($rect && $enabled)
		{
			SDL_SetTextInputRect(new \SDL_Rect(...$rect));
		}

		if($enabled === $this->textInput || $this->closed)
		{
			return;
		}

		$this->switchingText = true;
		$this->textInput = $enabled;

		try
		{
			if($enabled)
			{
				SDL_StartTextInput();

				if($this->canvas)
				{
					$target = $this->canvas->getRootNode()->activeElement;

					if($target && $target->tagName === 'TEXTAREA')
					{
						$this->textTarget = $target;
						$target->addEventListener('focus', $this->focusListener);
						$target->addEventListener('blur', $this->blurListener);
					}
				}
			}
			else
			{
				if($this->textTarget)
				{
					$this->textTarget->removeEventListener('focus', $this->focusListener);
					$this->textTarget->removeEventListener('blur', $this->blurListener);
				}

				$this->textTarget = null;
				SDL_StopTextInput();
			}
		}
		finally
		{
			$this->switchingText = false;
			$this->focus($this->ownsFocus());
		}
	}

	/**
	 * Snapshot relevant SDL event fields before the reusable event object is overwritten.
	 *
	 * @param \SDL_Event $event Current event from the central SDL poll loop.
	 * @return void Appends text, composition, editing keys, or pointer events for this UI frame.
	 */
	private function uiEvent(\SDL_Event $event): void
	{
		switch($event->type)
		{
			case SDL_TEXTINPUT:
				$this->uiEvents[] = ['type' => 'text', 'text' => $event->text->text];
				break;
			case SDL_TEXTEDITING:
				$this->uiEvents[] = ['type' => 'composition', 'text' => $event->edit->text
					, 'start' => $event->edit->start, 'length' => $event->edit->length
				];
				break;
			case SDL_KEYDOWN:
				$this->uiEvents[] = ['type' => 'key'
				, 'key' => SDL_GetKeyName(
					$event->key->keysym->sym
				)
					, 'shift' => (bool)($event->key->keysym->mod & KMOD_SHIFT)
					, 'ctrl' => (bool)($event->key->keysym->mod & (KMOD_CTRL | KMOD_GUI))
					, 'repeat' => (bool)$event->key->repeat
				];
				break;
			case SDL_MOUSEBUTTONDOWN: case SDL_MOUSEBUTTONUP:
				$this->inputTheme = 'keyboard';
				$this->uiEvents[] = ['type' => 'pointer'
					, 'action' => $event->type === SDL_MOUSEBUTTONDOWN ? 'down' : 'up'
					, 'x' => $event->button->x, 'y' => $event->button->y
					, 'button' => $event->button
						->button
				];
				break;
			case SDL_MOUSEMOTION:
				$this->uiEvents[] = ['type' => 'pointer', 'action' => 'move'
					, 'x' => $event->motion->x, 'y' => $event->motion->y
				];
				break;
			case SDL_MOUSEWHEEL:
				$this->uiEvents[] = ['type' => 'wheel'
				, 'y' => $event->wheel->y
					* ($event->wheel->direction === SDL_MOUSEWHEEL_FLIPPED ? -1 : 1)
				];
				break;
		}
	}

	/**
	 * Check a UI keyboard binding, suppressing keys held across editing transitions.
	 *
	 * @param int ...$codes Alternative SDL key codes for one command.
	 * @return bool True if an unblocked key is held or has a buffered press edge.
	 */
	private function held(int ...$codes): bool
	{
		foreach($codes as $code)
		{
			if(empty($this->uiBlocked[$code])
				&& (!empty($this->keys[$code]) || !empty($this->pressedKeys[$code]))
			){
				return true;
			}
		}
		return false;
	}

	/**
	 * Read UI buttons and ordered events without consuming fixed-step gameplay edges.
	 *
	 * @param bool $editing Suppress printable keyboard navigation bindings during text entry.
	 * @param bool $open Interpret Escape as Back when open, or as opening Pause otherwise.
	 * @return array{buttons:array,events:array,focused:bool,generation:int,theme:string} UI frame
	 * with independent logical controls and focus/recovery generation.
	 */
	public function uiFrame(bool $editing = false, bool $open = false): array
	{
		$buttons = [];

		if($editing !== $this->uiEditing)
		{
			// A held Enter/Escape must not activate again after it ends editing.
			foreach($this->keys as $code => $held)
			{
				if($held)
				{
					$this->uiBlocked[$code] = true;
				}
			}
			$this->uiEditing = $editing;
		}

		if($this->focused && !$this->closed)
		{
			foreach([
				['left', 'right', SDL_CONTROLLER_AXIS_LEFTX
					, SDL_CONTROLLER_BUTTON_DPAD_LEFT, SDL_CONTROLLER_BUTTON_DPAD_RIGHT
					, SDLK_LEFT, SDLK_a, SDLK_RIGHT, SDLK_d
				]
				, ['up', 'down', SDL_CONTROLLER_AXIS_LEFTY
					, SDL_CONTROLLER_BUTTON_DPAD_UP, SDL_CONTROLLER_BUTTON_DPAD_DOWN
					, SDLK_UP, SDLK_w, SDLK_DOWN, SDLK_s
				]
			] as [$negative
			, $positive
			, $axis
			, $padNegative
			, $padPositive
			, $keyA
			, $keyB
			, $keyC
				, $keyD
			]){
				$minus = $this->gamepads->button($padNegative);
				$plus = $this->gamepads->button($padPositive);
				$value = $minus || $plus ? (int)$plus - (int)$minus : $this->gamepads->axis($axis);

				$buttons[$negative] = max(
					0
					, -$value
					, (int)(!$editing && $this->held($keyA, $keyB))
				);
				$buttons[$positive] = max(0, $value, (int)(!$editing && $this->held($keyC, $keyD)));
			}

			$buttons['confirm'] = (!$editing && $this->held(SDLK_RETURN, SDLK_KP_ENTER, SDLK_SPACE))
				|| $this->gamepads->button(SDL_CONTROLLER_BUTTON_A);

			$buttons['back'] = (!$editing && $this->held(
				SDLK_ESCAPE
				, SDLK_x
				, SDLK_LCTRL
				, SDLK_RCTRL
			))
				|| $this->gamepads->button(SDL_CONTROLLER_BUTTON_B);

			$buttons['pause'] = (!$editing && ($this->held(
				SDLK_p
			) || (!$open && $this->held(
				SDLK_ESCAPE
			))))
				|| $this->gamepads->button(SDL_CONTROLLER_BUTTON_START);
			$buttons['revert'] = (!$editing && $this->held(SDLK_z))
				|| $this->gamepads->button(SDL_CONTROLLER_BUTTON_Y);
			$buttons['pageUp'] = (!$editing && $this->held(SDLK_PAGEUP))
				|| $this->gamepads->button(SDL_CONTROLLER_BUTTON_LEFTSHOULDER);
			$buttons['pageDown'] = (!$editing && $this->held(SDLK_PAGEDOWN))
				|| $this->gamepads->button(SDL_CONTROLLER_BUTTON_RIGHTSHOULDER);
			$buttons['home'] = !$editing && $this->held(SDLK_HOME);
			$buttons['end'] = !$editing && $this->held(SDLK_END);
		}

		return ['buttons' => $buttons
		, 'events' => $this->uiEvents
		, 'focused' => $this->focused
			, 'generation' => $this->generation, 'theme' => $this->inputTheme
		];
	}
}
