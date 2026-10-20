<?php
declare(strict_types=1);
namespace PhpWasmEngine\Input;

/** SDL owns browser discovery and mappings. Handles are keyed by SDL instance ID. */
final class Gamepads
{
	private array $handles = [], $axes = [], $buttons = [], $blockedAxes = [], $blockedButtons = [];
	private float $deadZone;
	private bool $closed = false;
	public ?int $activeId = null;
	public int $activity = 0;
	private array $names = [];

	/**
	 * Initialize SDL controller discovery and configure analog stick filtering.
	 *
	 * @param float $deadZone Stick dead-zone magnitude in [0,1); defaults to 20 percent.
	 * @return void Acquires the SDL game-controller subsystem.
	 */
	public function __construct(float $deadZone = .2)
	{
		$this->setDeadZone($deadZone);

		if(SDL_InitSubSystem(SDL_INIT_GAMECONTROLLER) !== 0)
		{
			throw new \RuntimeException(SDL_GetError());
		}
	}

	/**
	 * Configure the radial-independent dead zone used on each stick axis.
	 *
	 * @param float $deadZone Finite threshold in [0,1); values outside this range are rejected.
	 * @return void Stores the threshold for subsequent polls.
	 */
	public function setDeadZone(float $deadZone): void
	{
		if(!is_finite($deadZone) || $deadZone < 0 || $deadZone >= 1)
		{
			throw new \InvalidArgumentException('The stick dead zone must be in [0, 1)');
		}

		$this->deadZone = $deadZone;
	}

	/**
	 * List connected SDL instance IDs, independent of reusable browser gamepad slots.
	 *
	 * @return list<int> Controller instance IDs discovered by the most recent poll.
	 */
	public function ids(): array { return array_keys($this->handles); }

	/**
	 * Identify the last-used controller for themed control hints.
	 *
	 * @return string SDL controller name, or an empty string when no active pad remains.
	 */
	public function activeName(): string
	{
		return $this->names[$this->activeId] ?? '';
	}

	/**
	 * Request one dual-motor pulse on the controller that most recently supplied input.
	 *
	 * @param int $duration Nonnegative pulse duration in milliseconds.
	 * @param float $strong Low-frequency motor magnitude in [0,1].
	 * @param float $weak High-frequency motor magnitude in [0,1].
	 * @return bool True when the active SDL controller supports and accepted the pulse.
	 */
	public function rumble(int $duration, float $strong, float $weak): bool
	{
		if($duration < 0 || !is_finite($strong) || $strong < 0 || $strong > 1
			|| !is_finite($weak) || $weak < 0 || $weak > 1
		){
			throw new \InvalidArgumentException('Invalid gamepad rumble pulse');
		}

		if($this->rumbleStatus() !== 'Ready')
		{
			return false;
		}

		$handle = $this->handles[$this->activeId];

		// MB2 silences these pulses on PS3-era pads: they can latch an audible motor buzz
		// instead of playing a very short weak vibration. SDL's duration-based request also
		// makes an explicit zero pulse the correct way to stop any current browser effect.
		if(preg_match('/playstation.{0,5}3/i', $this->activeName()) && $duration < 100 && $strong < .75)
		{
			$duration = 0;
			$strong = $weak = 0;
		}

		return SDL_GameControllerRumble(
			$handle
			, (int)round($strong * 65535)
			, (int)round($weak * 65535)
			, $duration
		) === 0;
	}

	/**
	 * Describe whether the most recently active controller can receive a rumble request.
	 *
	 * This is intentionally synchronous: browser haptic APIs settle asynchronously, but the
	 * SDL call can still expose missing bindings, a missing active pad, and unsupported motors.
	 *
	 * @return string A concise status suitable for a settings-menu diagnostic.
	 */
	public function rumbleStatus(): string
	{
		if($this->closed || $this->activeId === null || !isset($this->handles[$this->activeId]))
		{
			return 'Use a controller first';
		}

		// Rumble was added to php-sdl-wasm after controller input. Keep the
		// engine usable with older runtimes and builds that omit those bindings.
		if(!function_exists('SDL_GameControllerHasRumble')
			|| !function_exists('SDL_GameControllerRumble')
		){
			return 'Runtime has no rumble API';
		}

		if(!SDL_GameControllerHasRumble($this->handles[$this->activeId]))
		{
			return 'Controller has no rumble motor';
		}

		return 'Ready';
	}

	/**
	 * Discover controllers, release disconnected handles, and sample normalized inputs.
	 *
	 * @return void Refreshes per-controller axes and buttons unless this owner has closed.
	 */
	public function poll(): void
	{
		if($this->closed)
		{
			return;
		}

		SDL_GameControllerUpdate();

		foreach($this->handles as $id => $handle)
		{
			if(SDL_GameControllerGetAttached($handle))
			{
				continue;
			}

			SDL_GameControllerClose($handle);
			unset($this->names[$id]);

			if($this->activeId === $id)
			{
				$this->activeId = null;
			}

			unset($this->handles[$id], $this->axes[$id], $this->buttons[$id], $this->blockedAxes[
				$id
			]
				, $this->blockedButtons[$id]
			);
		}

		for($index = 0, $count = SDL_NumJoysticks(); $index < $count; $index++)
		{
			$id = SDL_JoystickGetDeviceInstanceID($index);

			if($id < 0 || isset($this->handles[$id]) || !SDL_IsGameController($index))
			{
				continue;
			}

			$handle = SDL_GameControllerOpen($index);

			if($handle)
			{
				$this->handles[$id] = $handle;
				// Controller mappings may rename every pad to "Standard Gamepad".
				$this->names[$id] = (string)SDL_JoystickNameForIndex($index);
			}
		}

		foreach($this->handles as $id => $handle)
		{
			for($axis = 0; $axis < SDL_CONTROLLER_AXIS_MAX; $axis++)
			{
				$raw = SDL_GameControllerGetAxis($handle, $axis);
				$value = $raw / ($raw < 0?32768:32767);

				if($axis < SDL_CONTROLLER_AXIS_TRIGGERLEFT)
				{
					$value = abs($value) <= $this->deadZone
						? 0
						: ($value <=> 0) * (abs($value) - $this->deadZone
						) / (
							1 - $this->deadZone
						);
				}
				else
				{
					$value = max(0, $value);
				}

				if($value == 0)
				{
					unset($this->blockedAxes[$id][$axis]);
				}

				$value = empty($this->blockedAxes[$id][$axis]) ? $value : 0;

				if(abs($value) >= .5 && abs($value - ($this->axes[$id][$axis] ?? 0)) >= .1)
				{
					$this->activeId = $id;
					$this->activity++;
				}

				$this->axes[$id][$axis] = $value;
			}

			for($button = 0; $button < SDL_CONTROLLER_BUTTON_MAX; $button++)
			{
				$held = (bool)SDL_GameControllerGetButton($handle, $button);

				if(!$held)
				{
					unset($this->blockedButtons[$id][$button]);
				}

				$held = $held && empty($this->blockedButtons[$id][$button]);

				if($held && empty($this->buttons[$id][$button]))
				{
					$this->activeId = $id;
					$this->activity++;
				}

				$this->buttons[$id][$button] = $held;
			}
		}
	}

	/**
	 * Read a normalized axis after removing its stick dead zone.
	 *
	 * @param int $axis SDL_CONTROLLER_AXIS_* identifier.
	 * @param int|null $id SDL instance ID; null selects the connected pad with the largest absolute
	 * value.
	 * @return float Signed stick value in [-1,1], trigger value in [0,1], or zero when unavailable.
	 */
	public function axis(int $axis, ?int $id = null): float
	{
		if($id !== null)
		{
			return $this->axes[$id][$axis] ?? 0;
		}

		$value = 0;

		foreach($this->axes as $axes)
		{
			if(abs($axes[$axis] ?? 0) > abs($value))
			{
				$value = $axes[$axis];
			}
		}

		return $value;
	}

	/**
	 * Read a controller button or combine that button across all pads.
	 *
	 * @param int $button SDL_CONTROLLER_BUTTON_* identifier.
	 * @param int|null $id SDL instance ID, or null to combine all connected controllers.
	 * @return bool True if the selected controller, or any controller, holds the button.
	 */
	public function button(int $button, ?int $id = null): bool
	{
		if($id !== null)
		{
			return $this->buttons[$id][$button] ?? false;
		}

		foreach($this->buttons as $buttons)
		{
			if($buttons[$button] ?? false)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Suppress controls held across focus, visibility, or graphics recovery.
	 *
	 * @return void Clears samples and requires affected controls to return to neutral before reuse.
	 */
	public function reset(): void
	{
		$this->poll();

		foreach($this->axes as $id => $axes)
		{
			foreach($axes as $axis => $value)
			{
				if($value != 0)
				{
					$this->blockedAxes[$id][$axis] = true;
				}
			}
		}

		foreach($this->buttons as $id => $buttons)
		{
			foreach($buttons as $button => $held)
			{
				if($held)
				{
					$this->blockedButtons[$id][$button] = true;
				}
			}
		}

		$this->axes = $this->buttons = [];
	}

	/**
	 * Release controller handles and this owner's SDL subsystem reference.
	 *
	 * @return void Closes once and clears controller state; repeated calls have no effect.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$this->names = [];
		$this->activeId = null;

		foreach($this->handles as $handle)
		{
			SDL_GameControllerClose($handle);
		}

		$this->handles
		= $this->axes
		= $this->buttons
		= $this->blockedAxes
		= $this->blockedButtons
		= [];

		SDL_QuitSubSystem(SDL_INIT_GAMECONTROLLER);
	}
}
