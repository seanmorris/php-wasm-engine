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

	/**
	 * Create an SDL window, GLES 3 context, and browser context-loss listeners.
	 *
	 * @param int $width Drawing-buffer width in pixels.
	 * @param int $height Drawing-buffer height in pixels.
	 * @param string $title Native SDL window title.
	 * @return void Acquires video/event resources and configures two-dimensional alpha blending.
	 */
	public function __construct(public int $width = 960, public int $height = 540, string $title = 'PHP WASM Engine')
	{
		if(!function_exists('glDrawArraysInstanced'))
		{
			throw new \RuntimeException('Use a php-sdl-wasm build with the GLES 3 engine bindings');
		}

		if(SDL_Init(SDL_INIT_VIDEO | SDL_INIT_EVENTS) !== 0)
		{
			throw new \RuntimeException(SDL_GetError());
		}

		SDL_GL_SetAttribute(SDL_GL_CONTEXT_PROFILE_MASK, SDL_GL_CONTEXT_PROFILE_ES);
		SDL_GL_SetAttribute(SDL_GL_CONTEXT_MAJOR_VERSION, 3);
		SDL_GL_SetAttribute(SDL_GL_CONTEXT_MINOR_VERSION, 0);

		$this->window = SDL_CreateWindow($title, 0, 0, $width, $height, SDL_WINDOW_OPENGL | SDL_WINDOW_SHOWN);
		$this->context = SDL_GL_CreateContext($this->window);

		if(!$this->context || SDL_GL_MakeCurrent($this->window, $this->context) !== 0)
		{
			$this->close();
			throw new \RuntimeException(SDL_GetError());
		}

		if(function_exists('vrzno_env'))
		{
			$this->canvas = vrzno_env('canvas');

			if($this->canvas)
			{
				$this->webgl = $this->canvas->getContext('webgl2');

				/**
				 * Allow browser context restoration and mark graphics unavailable.
				 *
				 * @param object $event Browser WebGL context-loss event proxy exposing
				 * preventDefault.
				 * @return void Records that base GL state must be restored before drawing.
				 */
				$this->lostListener = function($event) {
					$event->preventDefault();
					$this->lost = $this->pendingRestore = true;
				};

				/**
				 * Record the browser's restored-context notification.
				 *
				 * @return void Clears the loss flag while leaving resource restoration pending.
				 */
				$this->restoredListener = function() { $this->lost = false; };

				$this->canvas->addEventListener('webglcontextlost', $this->lostListener);
				$this->canvas->addEventListener('webglcontextrestored', $this->restoredListener);
			}
		}

		$this->configure();
	}

	/**
	 * Apply blending, culling, depth, and pixel-unpack state for two-dimensional rendering.
	 *
	 * @return void Updates the current GL context state.
	 */
	private function configure(): void
	{
		glEnable(GL_BLEND);
		glBlendFuncSeparate(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA, GL_ONE, GL_ONE_MINUS_SRC_ALPHA);
		glDisable(GL_DEPTH_TEST);
		glDisable(GL_CULL_FACE);
		glPixelStorei(GL_UNPACK_ALIGNMENT, 1);
	}

	/**
	 * Check both recorded loss events and the browser's immediate context status.
	 *
	 * @return bool True when graphics are unavailable, including before a queued loss event is
	 * delivered.
	 */
	public function isLost(): bool
	{
		return $this->lost || ($this->webgl && $this->webgl->isContextLost());
	}

	/**
	 * Check render availability and restore base GL state after context recovery.
	 *
	 * @return bool True when drawing may proceed; a restored context increments generation for
	 * resource rebuilds.
	 */
	public function ready(): bool
	{
		if($this->closed || $this->isLost())
		{
			return false;
		}

		if($this->pendingRestore)
		{
			$this->configure();
			$this->pendingRestore = false;
			$this->generation++;
		}

		return true;
	}

	/**
	 * Set the device viewport and clear its color buffer.
	 *
	 * @param array{0:float|int,1:float|int,2:float|int,3:float|int} $color Clear color as
	 * normalized red, green, blue, and alpha channels.
	 * @return void Starts a frame in the current, ready graphics context.
	 */
	public function begin(array $color = [.055,.075,.12,1]): void
	{
		glViewport(0, 0, $this->width, $this->height);
		glClearColor(...$color);
		glClear(GL_COLOR_BUFFER_BIT);
	}

	/**
	 * Present the SDL window's rendered color buffer.
	 *
	 * @return void Swaps the window buffers.
	 */
	public function present(): void { SDL_GL_SwapWindow($this->window); }

	/**
	 * Detach context listeners and destroy the SDL window and graphics context.
	 *
	 * @return void Releases resources once; repeated calls have no effect.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;

		if($this->canvas)
		{
			$this->canvas->removeEventListener('webglcontextlost', $this->lostListener);
			$this->canvas->removeEventListener('webglcontextrestored', $this->restoredListener);
		}

		$this->lostListener = $this->restoredListener = $this->canvas = $this->webgl = null;

		if($this->context)
		{
			SDL_GL_DeleteContext($this->context);
		}

		if($this->window)
		{
			SDL_DestroyWindow($this->window);
		}

		SDL_Quit();
	}
}
