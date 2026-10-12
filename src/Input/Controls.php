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
	public function down(string $key): bool { return (bool)($this->current[$key] ?? false); }
	public function pressed(string $key): bool { return $this->down($key) && empty($this->previous[$key]); }
	public function released(string $key): bool { return !$this->down($key) && !empty($this->previous[$key]); }
	public function axis(string $negative,string $positive): int { return (int)$this->down($positive) - (int)$this->down($negative); }
}
