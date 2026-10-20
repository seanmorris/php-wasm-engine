<?php
declare(strict_types=1);
namespace PhpWasmEngine\Input;

final class SdlInput
{
	public bool $quit = false;
	private array $keys = [], $pressedKeys = [], $pressedButtons = [];
	public Gamepads $gamepads;
	public Sequences $sequences;
	private array $bindings = [];
	private bool $discarding = false;
	private float $sequenceTime = 0;
	private array $sequenceBlocked = [];
	private bool $focused = true, $closed = false;
	private bool $resetPads = false;
	private $canvas = null, $focusListener = null, $blurListener = null;
	private $keyboardWindow = null, $keyListener = null;
	private array $browserKeys = [];
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
		$this->sequences = new Sequences;
		$this->bindings = [
			0 => [SDLK_SPACE, SDLK_z, SDLK_RETURN, SDLK_KP_ENTER]
			, 1 => [SDLK_x, SDLK_LSHIFT, SDLK_LCTRL, SDLK_RCTRL]
			, 2 => [SDLK_c]
			, 3 => [SDLK_v]
			, 4 => [SDLK_q]
			, 5 => [SDLK_e]
			, 6 => [SDLK_1]
			, 7 => [SDLK_3]
			, 8 => [SDLK_r]
			, 9 => [SDLK_p, SDLK_ESCAPE]
			, 10 => [SDLK_TAB]
			, 11 => [SDLK_BACKSPACE]
			, 12 => [SDLK_UP, SDLK_w]
			, 13 => [SDLK_DOWN, SDLK_s]
			, 14 => [SDLK_LEFT, SDLK_a]
			, 15 => [SDLK_RIGHT, SDLK_d]
			, 16 => []
		];

		if(function_exists('vrzno_env'))
		{
			$this->canvas = vrzno_env('canvas');
		}

		if($this->canvas)
		{
			/**
			 * Mark the canvas focused and discard input held across the transition.
			 *
			 * @return null Requests fresh keyboard and controller state.
			 */
			$this->focusListener = fn() => $this->focus(true);

			/**
			 * Mark the canvas unfocused and release its buffered controls.
			 *
			 * @return null Suspends input until a later focus event.
			 */
			$this->blurListener = fn() => $this->switchingText ? null : $this->focus($this->ownsFocus());

			$this->canvas->addEventListener('focus', $this->focusListener);
			$this->canvas->addEventListener('blur', $this->blurListener);
			$this->focus((bool)$this->canvas->matches(':focus'));
			$this->keyboardWindow = $this->canvas->ownerDocument->defaultView;

			/**
			 * Reject browser repeats whose physical press was discarded during recovery.
			 * SDL can report the first repeat after blur as a new keydown.
			 *
			 * @param object $event Keyboard event for the canvas or IME target.
			 * @return void Filters stale repeats before SDL receives them.
			 */
			$this->keyListener = function(object $event): void {
				$this->focus($this->ownsFocus());

				if(!$this->focused)
				{
					return;
				}

				if(!$this->textInput && in_array($event->code, [
					'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'
					, 'PageUp', 'PageDown', 'Home', 'End', 'Space'
				], true))
				{
					$event->preventDefault();
				}

				if($event->type === 'keyup')
				{
					unset($this->browserKeys[$event->code]);
				}
				elseif(!$event->repeat)
				{
					$this->browserKeys[$event->code] = true;
				}
				elseif(empty($this->browserKeys[$event->code]))
				{
					$event->preventDefault();
					$event->stopImmediatePropagation();
				}
			};

			$this->keyboardWindow->addEventListener('keydown', $this->keyListener, true);
			$this->keyboardWindow->addEventListener('keyup', $this->keyListener, true);
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
		$this->browserKeys = [];
		$this->resetPads = true;
		$this->sequenceBlocked = [];
		$this->sequences->reset();
	}

	/**
	 * Discard queued events and held input after a suspended frame loop.
	 *
	 * @return void Clears keyboard buffers and suppresses currently held controller inputs.
	 */
	public function reset(): void
	{
		$this->discarding = true;

		try
		{
			$this->poll();
		}
		finally
		{
			$this->discarding = false;
		}

		$this->keys = $this->pressedKeys = $this->pressedButtons = [];
		$this->browserKeys = [];
		$this->gamepads->reset();
		$this->uiEvents = [];
		$this->generation++;
		$this->uiBlocked = [];
		$this->sequences->reset();
		$this->sequenceBlocked = [];
	}

	/**
	 * Suppress UI controls that remain held while ownership moves between UI layers.
	 *
	 * Unlike reset(), this retains focus, buffered gameplay state, and the current input generation.
	 * A modal can therefore consume Back once without its parent menu interpreting the same physical
	 * hold as a second Back action on the following frame.
	 *
	 * @return void Blocks held keyboard and controller controls until they return to neutral.
	 */
	public function suppressHeldUi(): void
	{
		foreach($this->keys as $code => $held)
		{
			if($held)
			{
				$this->uiBlocked[$code] = true;
			}
		}

		$this->gamepads->reset();
	}

	/**
	 * Replace the keyboard alternatives for a logical gamepad button.
	 *
	 * @param int $button Nonnegative standard button number, or a custom number above 16.
	 * @param list<int> $keys SDL key symbols; an empty list removes keyboard bindings.
	 * @return void Preserves SDL pad mappings and discards held input and partial sequences.
	 */
	public function bindButton(int $button, array $keys): void
	{
		if($button < 0 || count(array_filter($keys, 'is_int')) !== count($keys))
		{
			throw new \InvalidArgumentException('Button bindings require an index and SDL key symbols');
		}

		$this->reset();
		$this->bindings[$button] = array_values(array_unique($keys));
	}

	/**
	 * Feed physical input into the observer without consuming the simulation's buffered taps.
	 *
	 * @param float|null $timestamp SDL event timestamp in wrapping milliseconds, or null for now.
	 * @return void Preserves event order and suppresses editing and discarded inputs.
	 */
	private function observeSequences(?float $timestamp = null): void
	{
		if($this->discarding || !$this->focused || $this->textInput || $this->resetPads)
		{
			return;
		}

		$now = (float)SDL_GetTicks64() / 1000;
		$time = $timestamp === null ? $now : $now - fmod($now * 1000 - $timestamp + 4294967296.0, 4294967296.0) / 1000;
		$this->sequenceTime = max($this->sequenceTime, $time);
		$values = Buttons::normalize($this->snapshot(false));

		foreach($this->sequenceBlocked as $key => $value)
		{
			if(($values[$key] ?? 0) < .5)
			{
				unset($this->sequenceBlocked[$key]);
			}
			else
			{
				$values[$key] = 0;
			}
		}

		$this->sequences->update($values, $this->sequenceTime);
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

		// Sample pads before keyboard releases so a device handoff stays one logical hold.
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

		$event = new \SDL_Event;

		while(SDL_PollEvent($event))
		{
			if($event->type === SDL_QUIT)
			{
				$this->quit = true;
			}

			if($event->type === SDL_WINDOWEVENT && $event->window->event === SDL_WINDOWEVENT_FOCUS_LOST
			){
				$this->focus(false);
			}

			if($event->type === SDL_WINDOWEVENT && $event->window->event === SDL_WINDOWEVENT_FOCUS_GAINED
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
				$this->keys[$event->key->keysym->sym] = $this->pressedKeys[$event->key->keysym->sym
				] = true;
			}

			if($event->type === SDL_KEYUP
				|| ($event->type === SDL_KEYDOWN && !$event->key->repeat)
			){
				$this->observeSequences((float)$event->key->timestamp);
			}

			if($this->focused)
			{
				$this->uiEvent($event);
			}
		}

		// Native focus events can arrive after the initial controller sample.
		if($this->resetPads || !$this->focused)
		{
			$this->gamepads->reset();
			$this->resetPads = false;
		}

		$this->observeSequences();

		foreach($this->snapshot(false) as $key => $value)
		{
			if(strncmp($key, 'button', 6) === 0)
			{
				$button = (int)substr($key, 6);
				$this->pressedButtons[$button] = max($this->pressedButtons[$button] ?? 0, $value);
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
	 * @param bool $buffered Include short presses retained for the next simulation step.
	 * @return array<string,bool|int|float> Standard buttonN strengths plus existing named actions.
	 */
	public function snapshot(bool $buffered = true): array
	{
		if($this->closed || !$this->focused)
		{
			return [];
		}

		$pad = [
			0 => SDL_CONTROLLER_BUTTON_A
			, 1 => SDL_CONTROLLER_BUTTON_B
			, 2 => SDL_CONTROLLER_BUTTON_X
			, 3 => SDL_CONTROLLER_BUTTON_Y
			, 4 => SDL_CONTROLLER_BUTTON_LEFTSHOULDER
			, 5 => SDL_CONTROLLER_BUTTON_RIGHTSHOULDER
			, 8 => SDL_CONTROLLER_BUTTON_BACK
			, 9 => SDL_CONTROLLER_BUTTON_START
			, 10 => SDL_CONTROLLER_BUTTON_LEFTSTICK
			, 11 => SDL_CONTROLLER_BUTTON_RIGHTSTICK
			, 12 => SDL_CONTROLLER_BUTTON_DPAD_UP
			, 13 => SDL_CONTROLLER_BUTTON_DPAD_DOWN
			, 14 => SDL_CONTROLLER_BUTTON_DPAD_LEFT
			, 15 => SDL_CONTROLLER_BUTTON_DPAD_RIGHT
			, 16 => SDL_CONTROLLER_BUTTON_GUIDE
		];

		$values = [];

		foreach($this->bindings as $id => $keys)
		{
			$value = isset($pad[$id]) ? (float)$this->gamepads->button($pad[$id]) : 0;

			if($id === 6 || $id === 7)
			{
				$axis = $id === 6 ? SDL_CONTROLLER_AXIS_TRIGGERLEFT : SDL_CONTROLLER_AXIS_TRIGGERRIGHT;
				$value = $this->gamepads->axis($axis);
			}

			foreach($keys as $key)
			{
				if(!empty($this->keys[$key]) || ($buffered && !empty($this->pressedKeys[$key])))
				{
					$value = 1;
				}
			}

			$values['button' . $id] = $value;
		}

		foreach([[SDL_CONTROLLER_AXIS_LEFTX, 14, 15], [SDL_CONTROLLER_AXIS_LEFTY, 12, 13]]
			as [$axis, $negative, $positive]
		){
			// D-pad overrides the corresponding stick axis; keyboard still merges at full strength.
			if(!$this->gamepads->button($pad[$negative]) && !$this->gamepads->button($pad[$positive]))
			{
				$value = $this->gamepads->axis($axis);
				$values['button' . $negative] = max($values['button' . $negative], -$value);
				$values['button' . $positive] = max($values['button' . $positive], $value);
			}
		}

		if($buffered)
		{
			foreach($this->pressedButtons as $id => $value)
			{
				$values['button' . $id] = max($values['button' . $id] ?? 0, $value);
			}
		}

		foreach(Buttons::ALIASES as $name => $id)
		{
			$values[$name] = $id < 12 ? (bool)$values['button' . $id] : $values['button' . $id];
		}

		return $values;
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
		$this->browserKeys = [];

		if($this->canvas)
		{
			$this->canvas->removeEventListener('focus', $this->focusListener);
			$this->canvas->removeEventListener('blur', $this->blurListener);
			$this->keyboardWindow->removeEventListener('keydown', $this->keyListener, true);
			$this->keyboardWindow->removeEventListener('keyup', $this->keyListener, true);
		}

		$this->canvas = $this->focusListener = $this->blurListener = null;
		$this->keyboardWindow = $this->keyListener = null;
		$this->gamepads->close();
		$this->sequences->reset();
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
		if($enabled !== $this->textInput)
		{
			$this->sequences->reset();
			$this->sequenceBlocked = array_filter(Buttons::normalize($this->snapshot(false)));
		}

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
				$this->uiEvents[] = [
					'type' => 'composition'
					, 'text' => $event->edit->text
					, 'start' => $event->edit->start
					, 'length' => $event->edit->length
				];

				break;
			case SDL_KEYDOWN:
				// Recovery discards the original press; its repeats must not keep editing.
				if($event->key->repeat && empty($this->keys[$event->key->keysym->sym]))
				{
					break;
				}

				$this->uiEvents[] = [
					'type' => 'key'
					, 'key' => SDL_GetKeyName($event->key->keysym->sym)
					, 'shift' => (bool)($event->key->keysym->mod & KMOD_SHIFT)
					, 'ctrl' => (bool)($event->key->keysym->mod & (KMOD_CTRL | KMOD_GUI))
					, 'repeat' => (bool)$event->key->repeat
				];

				break;
			case SDL_MOUSEBUTTONDOWN: case SDL_MOUSEBUTTONUP:
				$this->inputTheme = 'keyboard';
				$this->uiEvents[] = [
					'type' => 'pointer'
					, 'action' => $event->type === SDL_MOUSEBUTTONDOWN ? 'down' : 'up'
					, 'x' => $event->button->x
					, 'y' => $event->button->y
					, 'button' => $event->button->button
				];

				break;
			case SDL_MOUSEMOTION:
				$this->uiEvents[] = [
					'type' => 'pointer'
					, 'action' => 'move'
					, 'x' => $event->motion->x
					, 'y' => $event->motion->y
				];

				break;
			case SDL_MOUSEWHEEL:
				$this->uiEvents[] = [
					'type' => 'wheel'
					, 'y' => $event->wheel->y * ($event->wheel->direction === SDL_MOUSEWHEEL_FLIPPED ? -1 : 1)
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
				[
					'left', 'right', SDL_CONTROLLER_AXIS_LEFTX
					, SDL_CONTROLLER_BUTTON_DPAD_LEFT, SDL_CONTROLLER_BUTTON_DPAD_RIGHT
					, SDLK_LEFT, SDLK_a, SDLK_RIGHT, SDLK_d
				]
				, [
					'up', 'down', SDL_CONTROLLER_AXIS_LEFTY
					, SDL_CONTROLLER_BUTTON_DPAD_UP, SDL_CONTROLLER_BUTTON_DPAD_DOWN
					, SDLK_UP, SDLK_w, SDLK_DOWN, SDLK_s
				]
			] as [
				$negative
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

				$buttons[$negative] = max(0, -$value, (int)(!$editing && $this->held($keyA, $keyB)));
				$buttons[$positive] = max(0, $value, (int)(!$editing && $this->held($keyC, $keyD)));
			}

			$buttons['confirm'] = (!$editing && $this->held(SDLK_RETURN, SDLK_KP_ENTER, SDLK_SPACE))
				|| $this->gamepads->button(SDL_CONTROLLER_BUTTON_A);

			$buttons['back'] = (!$editing && $this->held(SDLK_ESCAPE, SDLK_x, SDLK_LCTRL, SDLK_RCTRL))
				|| $this->gamepads->button(SDL_CONTROLLER_BUTTON_B);

			$buttons['pause'] = (!$editing && ($this->held(SDLK_p) || (!$open && $this->held(SDLK_ESCAPE))))
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

		return [
			'buttons' => $buttons
			, 'events' => $this->uiEvents
			, 'focused' => $this->focused
			, 'generation' => $this->generation
			, 'theme' => $this->inputTheme
		];
	}
}
