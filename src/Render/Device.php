<?php
declare(strict_types=1);
namespace PhpWasmEngine\Render;
final class Device
{
	public $window;
	private $context;
	private bool $closed = false;
	public int $generation = 0;
	private $canvas = null, $webgl = null, $lostListener = null, $restoredListener = null;
	private bool $lost = false, $pendingRestore = false;
	public function __construct(public int $width = 960,public int $height = 540,string $title = 'PHP WASM Engine')
	{
		if(!function_exists('glDrawArraysInstanced'))
		{ throw new \RuntimeException('Use a php-sdl-wasm build with the GLES 3 engine bindings'); }
		if(SDL_Init(SDL_INIT_VIDEO | SDL_INIT_EVENTS) !== 0)
		{ throw new \RuntimeException(SDL_GetError()); }
		SDL_GL_SetAttribute(SDL_GL_CONTEXT_PROFILE_MASK,SDL_GL_CONTEXT_PROFILE_ES);
		SDL_GL_SetAttribute(SDL_GL_CONTEXT_MAJOR_VERSION,3);
		SDL_GL_SetAttribute(SDL_GL_CONTEXT_MINOR_VERSION,0);
		$this->window = SDL_CreateWindow($title,0,0,$width,$height,SDL_WINDOW_OPENGL | SDL_WINDOW_SHOWN);
		$this->context = SDL_GL_CreateContext($this->window);
		if(!$this->context || SDL_GL_MakeCurrent($this->window,$this->context) !== 0)
		{ $this->close();
		throw new \RuntimeException(SDL_GetError()); }
		if(function_exists('vrzno_env'))
		{
			$this->canvas = vrzno_env('canvas');
			if($this->canvas)
			{
				$this->webgl = $this->canvas->getContext('webgl2');
				$this->lostListener = function($event) {
					$event->preventDefault();
					$this->lost = $this->pendingRestore = true;
				};
				$this->restoredListener = function() { $this->lost = false; };
				$this->canvas->addEventListener('webglcontextlost',$this->lostListener);
				$this->canvas->addEventListener('webglcontextrestored',$this->restoredListener);
			}
		}
		$this->configure();
	}
	private function configure(): void
	{
		glEnable(GL_BLEND);
		glBlendFuncSeparate(GL_SRC_ALPHA,GL_ONE_MINUS_SRC_ALPHA,GL_ONE,GL_ONE_MINUS_SRC_ALPHA);
		glDisable(GL_DEPTH_TEST);
		glDisable(GL_CULL_FACE);
		glPixelStorei(GL_UNPACK_ALIGNMENT,1);
	}
	/** Also check the browser before the asynchronous loss event is delivered. */
	public function isLost(): bool
	{ return $this->lost || ($this->webgl && $this->webgl->isContextLost()); }
	/** Reapply GL state after restoration; Engine rebuilds resources before drawing. */
	public function ready(): bool
	{
		if($this->closed || $this->isLost())
		{ return false; }
		if($this->pendingRestore)
		{
			$this->configure();
			$this->pendingRestore = false;
			$this->generation++;
		}
		return true;
	}
	public function begin(array $color = [.055,.075,.12,1]): void
	{ glViewport(0,0,$this->width,$this->height);
	glClearColor(...$color);
	glClear(GL_COLOR_BUFFER_BIT); }
	public function present(): void { SDL_GL_SwapWindow($this->window); }
	public function close(): void
	{
		if($this->closed)
		{ return; } $this->closed = true;
		if($this->canvas)
		{
			$this->canvas->removeEventListener('webglcontextlost',$this->lostListener);
			$this->canvas->removeEventListener('webglcontextrestored',$this->restoredListener);
		}
		$this->lostListener = $this->restoredListener = $this->canvas = $this->webgl = null;
		if($this->context)
		{ SDL_GL_DeleteContext($this->context); }
		if($this->window)
		{ SDL_DestroyWindow($this->window); }
		SDL_Quit();
	}
}
