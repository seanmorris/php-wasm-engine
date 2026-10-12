<?php
declare(strict_types=1);
namespace PhpWasmEngine\Input;

/** SDL owns browser discovery and mappings. Handles are keyed by SDL instance ID. */
final class Gamepads
{
	private array $handles = [], $axes = [], $buttons = [], $blockedAxes = [], $blockedButtons = [];
	private float $deadZone;
	private bool $closed = false;
	public function __construct(float $deadZone = .2)
	{
		$this->setDeadZone($deadZone);
		if(SDL_InitSubSystem(SDL_INIT_GAMECONTROLLER) !== 0)
		{ throw new \RuntimeException(SDL_GetError()); }
	}
	public function setDeadZone(float $deadZone): void
	{
		if(!is_finite($deadZone) || $deadZone < 0 || $deadZone >= 1)
		{ throw new \InvalidArgumentException('The stick dead zone must be in [0, 1)'); }
		$this->deadZone = $deadZone;
	}
	/** Connected SDL instance IDs, stable until unplugged, independent of browser slots. */
	public function ids(): array { return array_keys($this->handles); }
	public function poll(): void
	{
		if($this->closed)
		{ return; }
		SDL_GameControllerUpdate();
		foreach($this->handles as $id => $handle)
		{
			if(SDL_GameControllerGetAttached($handle))
			{ continue; }
			SDL_GameControllerClose($handle);
			unset($this->handles[$id],$this->axes[$id],$this->buttons[$id],$this->blockedAxes[$id],$this->blockedButtons[$id]);
		}
		for($index = 0,$count = SDL_NumJoysticks();$index < $count;$index++)
		{
			$id = SDL_JoystickGetDeviceInstanceID($index);
			if($id < 0 || isset($this->handles[$id]) || !SDL_IsGameController($index))
			{ continue; }
			$handle = SDL_GameControllerOpen($index);
			if($handle)
			{ $this->handles[$id] = $handle; }
		}
		foreach($this->handles as $id => $handle)
		{
			for($axis = 0;$axis < SDL_CONTROLLER_AXIS_MAX;$axis++)
			{
				$raw = SDL_GameControllerGetAxis($handle,$axis);
				$value = $raw / ($raw < 0?32768:32767);
				if($axis < SDL_CONTROLLER_AXIS_TRIGGERLEFT)
				{ $value = abs($value) <= $this->deadZone?0:($value <=> 0) * (abs($value) - $this->deadZone) / (1 - $this->deadZone); }
				else
				{ $value = max(0,$value); }
				if($value == 0)
				{ unset($this->blockedAxes[$id][$axis]); }
				$this->axes[$id][$axis] = empty($this->blockedAxes[$id][$axis])?$value:0;
			}
			for($button = 0;$button < SDL_CONTROLLER_BUTTON_MAX;$button++)
			{
				$held = (bool)SDL_GameControllerGetButton($handle,$button);
				if(!$held)
				{ unset($this->blockedButtons[$id][$button]); }
				$this->buttons[$id][$button] = $held && empty($this->blockedButtons[$id][$button]);
			}
		}
	}
	/** Normalized axis with the stick dead zone removed; null ID selects the strongest pad. */
	public function axis(int $axis,?int $id = null): float
	{
		if($id !== null)
		{ return $this->axes[$id][$axis] ?? 0; }
		$value = 0;
		foreach($this->axes as $axes)
		{ if(abs($axes[$axis] ?? 0) > abs($value))
		{ $value = $axes[$axis]; } }
		return $value;
	}
	/** Null ID combines all pads for the default single-player controls. */
	public function button(int $button,?int $id = null): bool
	{
		if($id !== null)
		{ return $this->buttons[$id][$button] ?? false; }
		foreach($this->buttons as $buttons)
		{ if($buttons[$button] ?? false)
		{ return true; } }
		return false;
	}
	/** Held controls must return to neutral after focus, visibility or graphics recovery. */
	public function reset(): void
	{
		$this->poll();
		foreach($this->axes as $id => $axes)
		{ foreach($axes as $axis => $value)
		{ if($value != 0)
		{ $this->blockedAxes[$id][$axis] = true; } } }
		foreach($this->buttons as $id => $buttons)
		{ foreach($buttons as $button => $held)
		{ if($held)
		{ $this->blockedButtons[$id][$button] = true; } } }
		$this->axes = $this->buttons = [];
	}
	public function close(): void
	{
		if($this->closed)
		{ return; }
		$this->closed = true;
		foreach($this->handles as $handle)
		{ SDL_GameControllerClose($handle); }
		$this->handles = $this->axes = $this->buttons = $this->blockedAxes = $this->blockedButtons = [];
		SDL_QuitSubSystem(SDL_INIT_GAMECONTROLLER);
	}
}
