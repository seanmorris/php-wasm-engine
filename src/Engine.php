<?php
declare(strict_types=1);
namespace PhpWasmEngine;
use PhpWasmEngine\Ecs\
{
	Registry,Scheduler
};
use PhpWasmEngine\Input\
{
	Controls,SdlInput
};
use PhpWasmEngine\Render\Device;

/** PHP owns scheduling, SDL event polling and fixed-step simulation. */
final class Engine
{
	public Registry $entities;
	public Scheduler $systems;
	public Controls $controls;
	public SdlInput $input;
	public FixedStep $clock;
	public bool $running = false;
	public float $time = 0;
	private $frame = null;
	private $window = null;
	private $raf = null;
	private $cleanup = null;
	private $refresh = null;
	private $refreshCleanup = null;
	private ?float $previous = null;
	private $restore = null, $document = null, $visibility = null;
	private int $generation;
	public bool $paused = false;
	public bool $userPaused = false;
	private bool $pauseHeld = false;
	private $inputHandler = null;
	private bool $inputCaptured = false;

	/**
	 * Install an optional input owner that runs before pause handling and fixed simulation.
	 *
	 * @param (callable(Engine,float):bool)|null $handler UI callback receiving real frame seconds;
	 * return true to capture this frame and suppress built-in pause and gameplay updates.
	 * @return void Replaces the handler; null restores the original input path.
	 */
	public function setInputHandler(?callable $handler): void { $this->inputHandler = $handler; }

	/**
	 * Create the entity registry, scheduler, input reader, and simulation clock.
	 *
	 * @param Device $device SDL graphics device owned and closed by this engine.
	 * @return void The engine is initialized without scheduling browser frames.
	 */
	public function __construct(public Device $device)
	{
		$this->entities = new Registry;
		$this->systems = new Scheduler($this->entities);
		$this->controls = new Controls;
		$this->input = new SdlInput;
		$this->clock = new FixedStep;
		$this->generation = $device->generation;
	}

	/**
	 * Suspend simulation and discard the previous browser timestamp.
	 *
	 * @return void The next visible frame starts with zero elapsed time.
	 */
	private function pause(): void
	{
		$this->paused = true;
		$this->previous = null;
	}

	/**
	 * Poll input, advance fixed simulation steps, and render an interpolation frame.
	 * Hidden or lost-context frames suspend simulation; restoration rebuilds registered resources.
	 *
	 * @param float $elapsed Nonnegative elapsed frame time in seconds.
	 * @param callable(Engine,float):void $render Receives this engine and the remaining fixed-step
	 * interpolation fraction.
	 * @return void Systems and the render callback run when the device is ready.
	 */
	public function tick(float $elapsed, callable $render): void
	{
		if(($this->document && $this->document->hidden) || !$this->device->ready())
		{
			$this->pause();
			return;
		}

		if($this->paused || $this->generation !== $this->device->generation)
		{
			$elapsed = 0;
			$this->input->reset();
			$this->controls->reset();
		}

		if($this->generation !== $this->device->generation)
		{
			if($this->restore)
			{
				($this->restore)();
			}

			$this->generation = $this->device->generation;
		}

		$this->paused = false;
		$this->input->poll();

		if($this->input->quit)
		{
			$this->stop();
			return;
		}

		$captured = $this->inputHandler ? (bool)($this->inputHandler)($this, $elapsed) : false;
		$pause = (bool)($this->input->snapshot()['pause'] ?? false);

		if(!$captured && $pause && !$this->pauseHeld)
		{
			$this->userPaused = !$this->userPaused;
			$elapsed = 0;
			$this->controls->reset();

			if(!$this->userPaused)
			{
				$this->input->reset();
			}
		}

		$this->pauseHeld = $pause;

		if($captured !== $this->inputCaptured)
		{
			$this->input->reset();
			$this->controls->reset();
			$elapsed = 0;
		}

		$this->inputCaptured = $captured;

		if($this->userPaused || $captured)
		{
			$elapsed = 0;
			$this->input->consume();
			$this->controls->reset();
		}

		$alpha = $this->clock->advance($elapsed,
			/**
			 * Consume buffered input and advance systems for one fixed simulation step.
			 *
			 * @param float $dt Fixed simulation interval in seconds.
			 * @return void Updates the simulation clock after all systems run.
			 */
			function($dt) {
				$this->controls->set($this->input->consume());
				$this->systems->update($dt);
				$this->time += $dt;
			}
		);

		$render($this, $alpha);
	}

	/**
	 * Schedule browser animation frames and install visibility and runtime-refresh cleanup.
	 *
	 * @param callable(Engine,float):void $render Draw callback receiving this engine and an
	 * interpolation fraction.
	 * @param (callable():void)|null $cleanup Optional callback that releases application resources
	 * before device teardown.
	 * @param (callable():void)|null $restore Optional callback that rebuilds application GPU
	 * resources after context restoration.
	 * @return void Starts the frame loop, or leaves an already running loop unchanged.
	 */
	public function start(callable $render, ?callable $cleanup = null, ?callable $restore = null): void
	{
		if($this->running)
		{
			return;
		}

		if(!function_exists('vrzno_env'))
		{
			throw new \RuntimeException(
				'Browser scheduling requires vrzno; native hosts can call tick()'
			);
		}

		$this->window = new \Vrzno;
		$this->cleanup = $cleanup;
		$this->restore = $restore;
		$this->document = $this->window->document;

		/**
		 * Suspend timing when document visibility changes.
		 *
		 * @return null Marks the next resumed frame to discard elapsed idle time.
		 */
		$this->visibility = fn() => $this->pause();
		$this->document->addEventListener('visibilitychange', $this->visibility);
		$this->running = true;
		$this->previous = null;

		/**
		 * Run one browser animation frame and schedule its successor.
		 *
		 * @param float $now Browser requestAnimationFrame timestamp in milliseconds.
		 * @return void Stops scheduling when the engine is stopped and pauses recoverable
		 * context losses.
		 */
		$this->frame = function($now) use ($render) {
			if(!$this->running)
			{
				return;
			}

			$elapsed = $this->previous === null?0:($now - $this->previous) / 1000;
			$this->previous = $now;

			try
			{
				$this->tick(max(0, $elapsed), $render);
			}
			catch(\Throwable $error)
			{
				// The GPU can disappear during a draw, before its loss event arrives.
				if(!$this->device->isLost())
				{
					$this->stop();
					throw $error;
				}

				$this->pause();
			}

			if($this->running)
			{
				$this->raf = $this->window->requestAnimationFrame($this->frame);
			}
		};

		$this->refresh = vrzno_env('onRefresh');

		/**
		 * Stop the engine before its owning WASM runtime refreshes.
		 *
		 * @return null Releases frame listeners, input, and graphics ownership.
		 */
		$this->refreshCleanup = fn() => $this->stop();

		if($this->refresh)
		{
			$this->refresh->add($this->refreshCleanup);
		}

		$this->raf = $this->window->requestAnimationFrame($this->frame);
	}

	/**
	 * Cancel frame scheduling, detach browser listeners, and release owned resources.
	 *
	 * @return void Stops once; repeated calls leave the stopped engine unchanged.
	 */
	public function stop(): void
	{
		if(!$this->running)
		{
			return;
		}

		$this->running = false;

		if($this->raf !== null && $this->window)
		{
			$this->window->cancelAnimationFrame($this->raf);
		}

		if($this->refresh)
		{
			$this->refresh->delete($this->refreshCleanup);
		}

		if($this->document)
		{
			$this->document->removeEventListener('visibilitychange', $this->visibility);
		}

		try
		{
			if($this->cleanup)
			{
				($this->cleanup)();
			}
		}
		finally
		{
			$this->input->close();
			$this->device->close();
			$this->frame = null;
			$this->cleanup = null;
			$this->restore = $this->visibility = $this->document = null;
			$this->raf = null;
			$this->refreshCleanup = null;
			$this->inputHandler = null;
		}
	}
}
