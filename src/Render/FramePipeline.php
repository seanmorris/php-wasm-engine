<?php
declare(strict_types=1);
namespace Phonics\Render;

/** Compose ordered two-dimensional, three-dimensional, HUD, and overlay render passes. */
final class FramePipeline
{
	private array $passes = [];
	private ?array $ordered = null;
	private int $generation = -1;
	private bool $closed = false;

	/**
	 * Bind an ordered render pipeline to a device.
	 *
	 * @param object $device Device exposing ready(), begin(), present(), and generation.
	 * @return void Creates an empty pipeline.
	 */
	public function __construct(public object $device) {}

	/**
	 * Add a uniquely named pass.
	 *
	 * @param RenderPass $pass Restorable renderer.
	 * @return void Invalidates cached dependency order.
	 */
	public function add(RenderPass $pass): void
	{
		if($this->closed) throw new \LogicException('Render pipeline is closed');
		if(isset($this->passes[$pass->id()])) throw new \LogicException("Conflicting render pass {$pass->id()}");

		$this->passes[$pass->id()] = $pass;
		$this->ordered = null;
	}

	/**
	 * Remove a pass and optionally close it.
	 *
	 * @param string $id Registered pass ID.
	 * @param bool $close Whether to release pass resources.
	 * @return void Has no effect for an absent pass.
	 */
	public function remove(string $id, bool $close = false): void
	{
		if(!isset($this->passes[$id])) return;

		$pass = $this->passes[$id];
		unset($this->passes[$id]);
		$this->ordered = null;

		if($close) $pass->close();
	}

	/**
	 * Clear, draw every pass in dependency order, and present one frame.
	 *
	 * @param float $alpha Fixed-step interpolation fraction.
	 * @param array{0:float|int,1:float|int,2:float|int,3:float|int} $color Clear color.
	 * @return bool True when a frame was presented, false during context loss.
	 */
	public function render(float $alpha = 0.0, array $color = [.055,.075,.12,1]): bool
	{
		if($this->closed || !$this->device->ready()) return false;

		$generation = (int) ($this->device->generation ?? 0);

		if($generation !== $this->generation)
		{
			if($this->generation >= 0)
			foreach($this->ordered() as $pass) $pass->restore();

			$this->generation = $generation;
		}

		$this->device->begin($color);
		$frame = new FrameContext($this->device, $alpha, $generation);

		foreach($this->ordered() as $pass) $pass->render($frame);

		$this->device->present();

		return true;
	}

	/**
	 * Close every pass in reverse render order.
	 *
	 * @return void Releases each pass once and rethrows the first cleanup failure.
	 */
	public function close(): void
	{
		if($this->closed) return;

		$this->closed = true;
		$error = null;

		foreach(array_reverse($this->ordered()) as $pass)
		try { $pass->close(); } catch(\Throwable $failure) { $error ??= $failure; }

		$this->passes = [];
		$this->ordered = [];

		if($error) throw $error;
	}

	/**
	 * Resolve render passes into dependency order, caching the order until passes change.
	 *
	 * @return list<RenderPass> Passes in resolved dependency order; cycles and unknown dependencies throw.
	 */
	private function ordered(): array
	{
		/**
		 * Look up a registered pass by ID.
		 *
		 * @param string $id Registered pass ID.
		 * @return RenderPass The registered pass.
		 */
		$passById = fn(string $id): RenderPass => $this->passes[$id];

		if($this->ordered !== null)
		return array_map($passById, $this->ordered);

		$result = [];
		$active = [];
		$done = [];

		/**
		 * Append a pass after its dependencies, rejecting cycles and unknown passes.
		 *
		 * @param string $id Pass ID to visit.
		 * @return void Updates the resolved order and traversal state.
		 */
		$visit = function(string $id) use (&$visit, &$result, &$active, &$done): void {
			if(isset($done[$id])) return;
			if(isset($active[$id])) throw new \LogicException("Render-pass dependency cycle at $id");
			if(!isset($this->passes[$id])) throw new \LogicException("Unknown render-pass dependency $id");

			$active[$id] = true;

			foreach($this->passes[$id]->after() as $dependency) $visit($dependency);

			unset($active[$id]);
			$done[$id] = true;
			$result[] = $id;
		};

		foreach(array_keys($this->passes) as $id) $visit($id);

		$this->ordered = $result;

		return array_map($passById, $result);
	}
}
