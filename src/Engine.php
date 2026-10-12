<?php
declare(strict_types=1);
namespace PhpWasmEngine;
use PhpWasmEngine\Ecs\
{Registry,Scheduler};
use PhpWasmEngine\Input\
{Controls,SdlInput};
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
	public function __construct(public Device $device)
	{
		$this->entities = new Registry;
		$this->systems = new Scheduler($this->entities);
		$this->controls = new Controls;
		$this->input = new SdlInput;
		$this->clock = new FixedStep;
		$this->generation = $device->generation;
	}
	private function pause(): void
	{
		$this->paused = true;
		$this->previous = null;
	}
	public function tick(float $elapsed,callable $render): void
	{
		if(($this->document && $this->document->hidden) || !$this->device->ready())
		{ $this->pause();
		return; }
		if($this->paused || $this->generation !== $this->device->generation)
		{
			$elapsed = 0;
			$this->input->reset();
			$this->controls->reset();
		}
		if($this->generation !== $this->device->generation)
		{
			if($this->restore)
			{ ($this->restore)(); }
			$this->generation = $this->device->generation;
		}
		$this->paused = false;
		$this->input->poll();
		if($this->input->quit)
		{ $this->stop();
		return; }
		$pause = (bool)($this->input->snapshot()['pause'] ?? false);
		if($pause && !$this->pauseHeld)
		{
			$this->userPaused = !$this->userPaused;
			$elapsed = 0;
			$this->controls->reset();
			if(!$this->userPaused)
			{ $this->input->reset(); }
		}
		$this->pauseHeld = $pause;
		if($this->userPaused)
		{ $elapsed = 0;
		$this->input->consume(); }
		$alpha = $this->clock->advance($elapsed,function($dt) { $this->controls->set($this->input->consume());
		$this->systems->update($dt);
		$this->time += $dt; });
		$render($this,$alpha);
	}
	public function start(callable $render,?callable $cleanup = null,?callable $restore = null): void
	{
		if($this->running)
		{ return; }
		if(!function_exists('vrzno_env'))
		{ throw new \RuntimeException('Browser scheduling requires vrzno; native hosts can call tick()'); }
		$this->window = new \Vrzno;
		$this->cleanup = $cleanup;
		$this->restore = $restore;
		$this->document = $this->window->document;
		$this->visibility = fn() => $this->pause();
		$this->document->addEventListener('visibilitychange',$this->visibility);
		$this->running = true;
		$this->previous = null;
		$this->frame = function($now) use ($render) {
			if(!$this->running)
			{ return; }
			$elapsed = $this->previous === null?0:($now - $this->previous) / 1000;
			$this->previous = $now;
			try
			{ $this->tick(max(0,$elapsed),$render); }
			catch(\Throwable $error)
			{
				// The GPU can disappear during a draw, before its loss event arrives.
				if(!$this->device->isLost())
				{ $this->stop();
				throw $error; }
				$this->pause();
			}
			if($this->running)
			{ $this->raf = $this->window->requestAnimationFrame($this->frame); }
		};
		$this->refresh = vrzno_env('onRefresh');
		$this->refreshCleanup = fn() => $this->stop();
		if($this->refresh)
		{ $this->refresh->add($this->refreshCleanup); }
		$this->raf = $this->window->requestAnimationFrame($this->frame);
	}
	public function stop(): void
	{
		if(!$this->running)
		{ return; } $this->running = false;
		if($this->raf !== null && $this->window)
		{ $this->window->cancelAnimationFrame($this->raf); }
		if($this->refresh)
		{ $this->refresh->delete($this->refreshCleanup); }
		if($this->document)
		{ $this->document->removeEventListener('visibilitychange',$this->visibility); }
		try
		{ if($this->cleanup)
		{ ($this->cleanup)(); } } finally
		{ $this->input->close();
		$this->device->close();
		$this->frame = null;
		$this->cleanup = null;
		$this->restore = $this->visibility = $this->document = null;
		$this->raf = null;
		$this->refreshCleanup = null; }
	}
}
