<?php
declare(strict_types=1);
namespace Phonics\Input;

/** Poll SDL and browser events into gameplay controls, UI events, text input, and sequences. */
final class SdlInput
{
	/** Whether SDL delivered a quit request during the latest poll. */
	public bool $quit = false;
	private array $keys = [], $pressedKeys = [], $pressedButtons = [];
	/** Connected controller manager owned by this input reader. */
	public Gamepads $gamepads;
	/** Stateful command-sequence recognizer fed from logical controls. */
	public Sequences $sequences;
	private array $bindings = [];
	/** @var array<int,array{buttons:list<int>,axes:array<int,int>}> Gameplay controller overrides. */
	private array $padBindings = [];
	private bool $discarding = false;
	private float $sequenceTime = 0;
	private array $sequenceBlocked = [];
	private bool $focused = true, $closed = false;
	private bool $resetPads = false;
	private $canvas = null, $focusListener = null, $blurListener = null, $mouseListener = null;
	private bool $pointerLocked = false;
	private $keyboardWindow = null, $keyListener = null;
	private array $browserKeys = [];
	/** Whether the newest browser mouse-down originated on the canvas. */
	private ?bool $browserMouseTarget = null;
	private array $uiEvents = [];
	private array $uiBlocked = [];
	private bool $uiEditing = false;
	private bool $textInput = false, $switchingText = false;
	private $textTarget = null;
	/** Input revision incremented after polling so consumers can detect fresh snapshots. */
	public int $generation = 0;
	/** Last active device family used for keyboard or controller button hints. */
	public string $inputTheme = 'keyboard';
	/** Optional signed gameplay profile; legacy snapshots and menu bindings stay independent. */
	public ?ActionInput $actions = null;
	/** Radial right-stick dead zone for the signed profile. */
	public float $lookDeadZone = .12;
	private array $lookActivity = [];

	/**
	 * Install a gameplay profile while retaining legacy APIs for other games.
	 *
	 * @param Bindings $bindings Editable named-action profile.
	 * @return void Clears stale input and installs the profile buffer.
	 */
	public function useBindings(Bindings $bindings): void
	{
		$this->reset();
		$this->actions = new ActionInput($bindings);
	}

	/**
	 * Check whether gameplay may sample this reader on the current frame.
	 *
	 * @return bool True while focused and open.
	 */
	public function focused(): bool { return $this->focused && !$this->closed; }

	/**
	 * Read display-frame rates and consume relative displacement once.
	 *
	 * @return ActionFrame Current named actions or an empty sample without a profile.
	 */
	public function frameActions(): ActionFrame { return $this->actions?->frame() ?? new ActionFrame; }

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
				], true
				)){
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

			/**
			 * Preserve each mouse-down's DOM owner until SDL drains the matching event.
			 * A launcher button can close itself and refocus the canvas before that drain,
			 * so checking only the current focus would turn the launcher click into Fire.
			 *
			 * @param object $event Browser mouse event observed before SDL's listener.
			 * @return void Retains canvas ownership for the next SDL mouse-down.
			 */
			$this->mouseListener = function(object $event): void {
				$target = $event->target;
				$owned = $this->canvas->ownerDocument->pointerLockElement === $this->canvas
					|| ($target && (bool)$this->canvas->contains($target));

				// SDL listens on the canvas and may omit external DOM presses entirely. Keep
				// only the newest origin so an old launcher interaction cannot reject the
				// next canvas event.
				$this->browserMouseTarget = $owned;
			};

			$this->keyboardWindow->addEventListener('mousedown', $this->mouseListener, true);
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
		$this->actions?->reset();
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
		$this->actions?->reset();
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
	 * Replace the controller sources for one gameplay button without changing UI navigation.
	 *
	 * @param int $button Nonnegative logical gameplay button number.
	 * @param list<int> $buttons SDL controller buttons; an empty list removes digital bindings.
	 * @param array<int,int> $axes SDL controller axes mapped to -1 or 1 for their negative or positive half.
	 * @return void Preserves keyboard bindings and discards held input and partial sequences.
	 */
	public function bindGamepad(int $button, array $buttons = [], array $axes = []): void
	{
		if($button < 0) throw new \InvalidArgumentException('Gamepad bindings require a nonnegative button');

		foreach($buttons as $source)
		if(!is_int($source) || $source < 0 || $source >= SDL_CONTROLLER_BUTTON_MAX)
		throw new \InvalidArgumentException('Invalid SDL controller button');

		foreach($axes as $axis => $direction)
		if(!is_int($axis) || $axis < 0 || $axis >= SDL_CONTROLLER_AXIS_MAX || !in_array($direction, [-1, 1], true))
		throw new \InvalidArgumentException('Controller axes require an SDL axis and a direction of -1 or 1');

		$this->reset();
		$this->bindings[$button] ??= [];
		$this->padBindings[$button] = ['buttons' => array_values(array_unique($buttons)), 'axes' => $axes];
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
		$this->actions?->beginPoll();

		if($this->canvas)
		{
			$this->focus($this->ownsFocus());
			$locked = $this->canvas->ownerDocument->pointerLockElement === $this->canvas;

			if($this->pointerLocked && !$locked)
			{
				$this->actions?->release(Source::MOUSE_BUTTON);
				$this->actions?->release(Source::MOUSE_AXIS);
			}

			$this->pointerLocked = $locked;
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
			$mouseOwned = null;

			// Drain origin markers even while focus is elsewhere. Otherwise an ignored
			// launcher click would be mistaken for the next real canvas click.
			if($this->canvas && $event->type === SDL_MOUSEBUTTONDOWN)
			{
				$mouseOwned = $this->browserMouseTarget;
				$this->browserMouseTarget = null;
			}

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
				if(!$this->discarding) $this->actionEvent($event, $mouseOwned);

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

		if($this->actions && $this->focused && !$this->discarding) $this->sampleActionPads();

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
	 * @return array<string,bool|int|float>|ActionFrame Legacy buttons or signed actions; empty
	 * while unfocused or closed.
	 */
	public function consume(): array|ActionFrame
	{
		$snapshot = $this->actions
			? ($this->focused() ? $this->actions->consume() : new ActionFrame)
			: $this->snapshot();

		$this->pressedKeys = $this->pressedButtons = [];

		return $snapshot;
	}

	/**
	 * Translate physical SDL events before gameplay assigns their meaning.
	 *
	 * @param \SDL_Event $event Polled keyboard or pointer event.
	 * @param bool|null $mouseOwned Browser origin for this mouse-down, when available.
	 * @return void Updates physical buffers for the installed profile.
	 */
	private function actionEvent(\SDL_Event $event, ?bool $mouseOwned = null): void
	{
		if(!$this->actions) return;

		if(in_array($event->type, [SDL_MOUSEBUTTONDOWN, SDL_MOUSEWHEEL], true)
			|| ($event->type === SDL_MOUSEMOTION && hypot($event->motion->xrel, $event->motion->yrel) >= 3)
		)
		$this->inputTheme = 'keyboard';

		if(($event->type === SDL_KEYUP || ($event->type === SDL_KEYDOWN && !$event->key->repeat)) && $event->key->keysym
			->sym > 0
		)
		$this->actions->hold(new Source(Source::KEY, $event->key->keysym->sym), $event->type === SDL_KEYDOWN ? 1 : 0);

		if($event->type === SDL_MOUSEBUTTONDOWN || $event->type === SDL_MOUSEBUTTONUP)
		{
			$code = $event->button->button;
			$down = $event->type === SDL_MOUSEBUTTONDOWN;

			if($code >= 1 && $code <= 5 && (!$down || !$this->canvas
				|| ($mouseOwned ?? $this->ownsFocus())
			))
			$this->actions->hold(new Source(Source::MOUSE_BUTTON, $code), $down ? 1 : 0);
		}

		if($event->type === SDL_MOUSEWHEEL)
		{
			$sign = $event->wheel->direction === SDL_MOUSEWHEEL_FLIPPED ? -1 : 1;
			$this->actions->move(Source::WHEEL, Source::X, $event->wheel->x * $sign);
			$this->actions->move(Source::WHEEL, Source::Y, $event->wheel->y * $sign);
		}

		if($event->type === SDL_MOUSEMOTION)
		{
			$gameplay = !$this->canvas || $this->pointerLocked;
			$this->actions->move(Source::MOUSE_AXIS, Source::X, $event->motion->xrel, $gameplay);
			$this->actions->move(Source::MOUSE_AXIS, Source::Y, $event->motion->yrel, $gameplay);
		}
	}

	/**
	 * Merge connected pads after one radial response per right stick.
	 *
	 * @return void Updates current button and signed-axis strengths.
	 */
	private function sampleActionPads(): void
	{
		for($button = 0; $button < SDL_CONTROLLER_BUTTON_MAX; $button++)
		$this->actions->hold(new Source(Source::PAD_BUTTON, $button), (float)$this->gamepads->button($button));
		$axes = [];
		$activity = [];

		foreach($this->gamepads->ids() as $id)
		{
			[$x, $y] = ActionInput::stick(
				$this->gamepads->rawAxis(SDL_CONTROLLER_AXIS_RIGHTX, $id)
				, $this->gamepads->rawAxis(SDL_CONTROLLER_AXIS_RIGHTY, $id)
				, $this->lookDeadZone
			);

			[$previousX, $previousY] = $this->lookActivity[$id] ?? [0, 0];

			if(hypot($x, $y) > 0 && (hypot($previousX, $previousY) === 0.0 || hypot(
				$x - $previousX
				, $y - $previousY
			) > .002
			)){
				$this->gamepads->activeId = $id;
				$this->inputTheme = self::padTheme($this->gamepads->activeName());
			}

			$activity[$id] = [$x, $y];

			for($axis = 0; $axis < SDL_CONTROLLER_AXIS_MAX; $axis++)
			{
				$value = match($axis)
				{
					SDL_CONTROLLER_AXIS_RIGHTX => $x, SDL_CONTROLLER_AXIS_RIGHTY => $y, default => $this->gamepads
						->axis($axis, $id)
				};

				foreach([-1, 1] as $sign) $axes[$axis][$sign] = max($axes[$axis][$sign] ?? 0, $value * $sign);
			}
		}

		$this->lookActivity = $activity;

		for($axis = 0; $axis < SDL_CONTROLLER_AXIS_MAX; $axis++)
		foreach([-1, 1] as $sign)
		$this->actions->hold(new Source(Source::PAD_AXIS, $axis, $sign), $axes[$axis][$sign] ?? 0);
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
			$binding = $this->padBindings[$id] ?? null;
			$value = 0;

			if($binding !== null)
			{
				foreach($binding['buttons'] as $button)
				$value = max($value, (float)$this->gamepads->button($button));

				foreach($binding['axes'] as $axis => $direction)
				$value = max($value, $direction * $this->gamepads->axis($axis));
			}
			elseif($id === 6 || $id === 7)
			{
				$axis = $id === 6 ? SDL_CONTROLLER_AXIS_TRIGGERLEFT : SDL_CONTROLLER_AXIS_TRIGGERRIGHT;
				$value = $this->gamepads->axis($axis);
			}
			elseif(isset($pad[$id])) $value = (float)$this->gamepads->button($pad[$id]);

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

				if(!isset($this->padBindings[$negative]))
				$values['button' . $negative] = max($values['button' . $negative], -$value);

				if(!isset($this->padBindings[$positive]))
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
			$this->keyboardWindow->removeEventListener('mousedown', $this->mouseListener, true);
		}

		$this->canvas = $this->focusListener = $this->blurListener = $this->mouseListener = null;
		$this->keyboardWindow = $this->keyListener = null;
		$this->browserMouseTarget = null;
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
	 * Choose artwork for controller bindings independently of the last menu input method.
	 *
	 * @return string Xbox, PlayStation, or generic glyph theme for the preferred connected pad.
	 */
	public function controllerTheme(): string
	{
		return self::padTheme($this->gamepads->preferredName());
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

			$buttons['confirm'] = (!$editing && $this->held(SDLK_RETURN, SDLK_KP_ENTER, SDLK_SPACE, SDLK_y))
				|| $this->gamepads->button(SDL_CONTROLLER_BUTTON_A);

			$buttons['back'] = (!$editing && $this->held(SDLK_ESCAPE, SDLK_x, SDLK_LCTRL, SDLK_RCTRL))
				|| $this->gamepads->button(SDL_CONTROLLER_BUTTON_B);

			$pauseKeys = $this->bindings[InputCode::PAUSE] ?? [];
			$escapePauses = in_array(SDLK_ESCAPE, $pauseKeys, true);
			$pauseKeys = array_values(array_filter(
				$pauseKeys
				, /**
				 * Keep pause keys other than Escape, which only opens a closed menu.
				 *
				 * @param int $key SDL key bound to the pause button.
				 * @return bool True for keys that always toggle pause.
				 */
				static fn(int $key): bool => $key !== SDLK_ESCAPE
			));

			$buttons['pause'] = (!$editing && (
				$this->held(...$pauseKeys) || (!$open && $escapePauses && $this->held(SDLK_ESCAPE))
			))
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

		foreach(InputCode::MENU as $name => $code) $buttons[$code] = $buttons[$name] ?? 0;

		return [
			'buttons' => $buttons
			, 'events' => $this->uiEvents
			, 'focused' => $this->focused
			, 'generation' => $this->generation
			, 'theme' => $this->inputTheme
		];
	}
}
