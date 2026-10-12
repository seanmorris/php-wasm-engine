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
	public function __construct()
	{
		$this->gamepads = new Gamepads;
		if(function_exists('vrzno_env'))
		{ $this->canvas = vrzno_env('canvas'); }
		if($this->canvas)
		{
			$this->focusListener = fn() => $this->focus(true);
			$this->blurListener = fn() => $this->focus(false);
			$this->canvas->addEventListener('focus',$this->focusListener);
			$this->canvas->addEventListener('blur',$this->blurListener);
			$this->focus((bool)$this->canvas->matches(':focus'));
		}
	}
	private function focus(bool $focused): void
	{
		if($this->focused === $focused)
		{ return; }
		$this->focused = $focused;
		$this->keys = $this->pressedKeys = $this->pressedButtons = [];
		$this->resetPads = true;
	}
	/** Discard queued events and held keys after a suspended browser frame loop. */
	public function reset(): void
	{ $this->poll();
	$this->keys = $this->pressedKeys = $this->pressedButtons = [];
	$this->gamepads->reset(); }
	public function poll(): void
	{
		if($this->closed)
		{ return; }
		$event = new \SDL_Event;
		while(SDL_PollEvent($event))
		{
			if($event->type === SDL_QUIT)
			{ $this->quit = true; }
			if($event->type === SDL_WINDOWEVENT && $event->window->event === SDL_WINDOWEVENT_FOCUS_LOST)
			{ $this->focus(false); }
			if($event->type === SDL_WINDOWEVENT && $event->window->event === SDL_WINDOWEVENT_FOCUS_GAINED)
			{ $this->focus(!$this->canvas || $this->canvas->matches(':focus')); }
			if($event->type === SDL_KEYUP)
			{ $this->keys[$event->key->keysym->sym] = false; }
			// Repeated keydowns must not reactivate a key cleared by focus or pause recovery.
			if($event->type === SDL_KEYDOWN && !$event->key->repeat && $this->focused)
			{ $this->keys[$event->key->keysym->sym] = $this->pressedKeys[$event->key->keysym->sym] = true; }
		}
		if($this->resetPads || !$this->focused)
		{ $this->gamepads->reset(); }
		else
		{ $this->gamepads->poll(); }
		$this->resetPads = false;
		foreach([SDL_CONTROLLER_BUTTON_A,SDL_CONTROLLER_BUTTON_B,SDL_CONTROLLER_BUTTON_BACK,SDL_CONTROLLER_BUTTON_START] as $button)
		{ if($this->gamepads->button($button))
		{ $this->pressedButtons[$button] = true; } }
	}
	/** Consume buffered presses once per simulation step, even if no render occurred. */
	public function consume(): array
	{
		$snapshot = $this->snapshot();
		$this->pressedKeys = $this->pressedButtons = [];
		return $snapshot;
	}
	public function snapshot(): array
	{
		if($this->closed || !$this->focused)
		{ return []; }
		$down = fn(...$codes) => count(array_filter($codes,fn($code) => !empty($this->keys[$code]) || !empty($this->pressedKeys[$code]))) > 0;
		$button = fn($id) => $this->gamepads->button($id) || !empty($this->pressedButtons[$id]);
		$x = $this->gamepads->axis(SDL_CONTROLLER_AXIS_LEFTX);
		$y = $this->gamepads->axis(SDL_CONTROLLER_AXIS_LEFTY);
		$left = $button(SDL_CONTROLLER_BUTTON_DPAD_LEFT);
		$right = $button(SDL_CONTROLLER_BUTTON_DPAD_RIGHT);
		$up = $button(SDL_CONTROLLER_BUTTON_DPAD_UP);
		$downPad = $button(SDL_CONTROLLER_BUTTON_DPAD_DOWN);
		if($left || $right)
		{ $x = (int)$right - (int)$left; }
		if($up || $downPad)
		{ $y = (int)$downPad - (int)$up; }
		return ['left' => max((int)$down(SDLK_LEFT,SDLK_a),-$x,0),'right' => max((int)$down(SDLK_RIGHT,SDLK_d),$x,0),'up' => max((int)$down(SDLK_UP,SDLK_w),-$y,0),'down' => max((int)$down(SDLK_DOWN,SDLK_s),$y,0),'jump' => $down(SDLK_SPACE,SDLK_z,SDLK_RETURN) || $button(SDL_CONTROLLER_BUTTON_A),'action' => $down(SDLK_x,SDLK_LSHIFT,SDLK_LCTRL,SDLK_RCTRL) || $button(SDL_CONTROLLER_BUTTON_B),'reset' => $down(SDLK_r) || $button(SDL_CONTROLLER_BUTTON_BACK),'pause' => $down(SDLK_p,SDLK_ESCAPE) || $button(SDL_CONTROLLER_BUTTON_START)];
	}
	public function close(): void
	{
		if($this->closed)
		{ return; }
		$this->closed = true;
		$this->keys = $this->pressedKeys = $this->pressedButtons = [];
		if($this->canvas)
		{
			$this->canvas->removeEventListener('focus',$this->focusListener);
			$this->canvas->removeEventListener('blur',$this->blurListener);
		}
		$this->canvas = $this->focusListener = $this->blurListener = null;
		$this->gamepads->close();
	}
}
