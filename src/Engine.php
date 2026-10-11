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
	public function __construct(public Device $device)
	{
		$this->entities = new Registry;
		$this->systems = new Scheduler($this->entities);
		$this->controls = new Controls;
		$this->input = new SdlInput;
		$this->clock = new FixedStep;
	}
	public function tick(float $elapsed,callable $render): void
	{
		$this->input->poll();
		if($this->input->quit)
		{ $this->stop();
		return; }
		$alpha = $this->clock->advance($elapsed,function($dt) { $this->controls->set($this->input->snapshot());
		$this->systems->update($dt);
		$this->time += $dt; });
		$render($this,$alpha);
	}
	public function start(callable $render,?callable $cleanup = null): void
	{
		if($this->running)
		{ return; }
		if(!function_exists('vrzno_env'))
		{ throw new \RuntimeException('Browser scheduling requires vrzno; native hosts can call tick()'); }
		$this->window = new \Vrzno;
		$this->cleanup = $cleanup;
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
			{ $this->stop();
			throw $error; }
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
		try
		{ if($this->cleanup)
		{ ($this->cleanup)(); } } finally
		{ $this->device->close();
		$this->frame = null;
		$this->cleanup = null;
		$this->refreshCleanup = null; }
	}
}
