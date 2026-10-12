<?php
declare(strict_types=1);
namespace PhpWasmEngine\Input;
final class Controls
{
	private array $current = [], $previous = [];
	public function reset(): void { $this->current = $this->previous = []; }
	public function set(array $keys): void
	{ $this->previous = $this->current;
	$this->current = $keys; }
	/** Button strengths and directional magnitudes share the range 0..1. */
	public function value(string $key): float { return max(0,min(1,(float)($this->current[$key] ?? 0))); }
	public function down(string $key): bool { return (bool)($this->current[$key] ?? false); }
	public function pressed(string $key): bool { return $this->down($key) && empty($this->previous[$key]); }
	public function released(string $key): bool { return !$this->down($key) && !empty($this->previous[$key]); }
	public function axis(string $negative,string $positive): float { return $this->value($positive) - $this->value($negative); }
}
