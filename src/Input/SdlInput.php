<?php
declare(strict_types=1);
namespace PhpWasmEngine\Input;
final class SdlInput
{
	public bool $quit = false;
	private array $keys = [];
	/** Discard queued events and held keys after a suspended browser frame loop. */
	public function reset(): void
	{ $this->poll();
	$this->keys = []; }
	public function poll(): void
	{
		$event = new \SDL_Event;
		while(SDL_PollEvent($event))
		{
			if($event->type === SDL_QUIT)
			{ $this->quit = true; }
			if($event->type === SDL_WINDOWEVENT && $event->window->event === SDL_WINDOWEVENT_FOCUS_LOST)
			{ $this->keys = []; }
			if($event->type === SDL_KEYDOWN || $event->type === SDL_KEYUP)
			{ $this->keys[$event->key->keysym->sym] = $event->type === SDL_KEYDOWN; }
		}
	}
	public function snapshot(): array
	{
		$down = fn(...$codes) => count(array_filter($codes,fn($code) => $this->keys[$code] ?? false)) > 0;
		return ['left' => $down(SDLK_LEFT,SDLK_a),'right' => $down(SDLK_RIGHT,SDLK_d),'up' => $down(SDLK_UP,SDLK_w),'down' => $down(SDLK_DOWN,SDLK_s),'jump' => $down(SDLK_SPACE,SDLK_z),'action' => $down(SDLK_x,SDLK_LSHIFT),'reset' => $down(SDLK_r)];
	}
}
