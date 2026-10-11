<?php
declare(strict_types=1);
namespace PhpWasmEngine\Asset;

/** Cache per asset source. Supply a reader for remote files or custom archives. */
final class Assets
{
	private array $bytes = [];
	private array $json = [];
	private $reader;
	public function __construct(?callable $reader = null) { $this->reader = $reader ?? static fn($path) => file_get_contents($path); }
	public function read(string $path): string
	{
		if(!isset($this->bytes[$path]))
		{
			$bytes = ($this->reader)($path);
			if(!is_string($bytes))
			{ throw new \RuntimeException("Cannot read asset: $path"); }
			$this->bytes[$path] = $bytes;
		}
		return $this->bytes[$path];
	}
	public function json(string $path): array
	{
		if(!isset($this->json[$path]))
		{
			$data = json_decode($this->read($path), true, 512, JSON_THROW_ON_ERROR);
			if(!is_array($data))
			{ throw new \UnexpectedValueException("Expected JSON object: $path"); }
			$this->json[$path] = $data;
		}
		return $this->json[$path];
	}
	public function forget(string $path): void { unset($this->bytes[$path], $this->json[$path]); }
	public static function relative(string $base, string $path): string
	{
		if(preg_match('~^[a-z][a-z0-9+.-]*:~i', $path) || str_starts_with($path, '/'))
		{ return $path; }
		$directory = str_replace('\\', '/', dirname($base));
		$joined = ($directory === '.' ? '' : $directory.'/').$path;
		$prefix = '';
		if(preg_match('~^([a-z][a-z0-9+.-]*://[^/]+)(/.*)?$~i', $joined, $match))
		{ $prefix = $match[1];
		$joined = $match[2] ?? '/'; }
		$absolute = str_starts_with($joined, '/');
		$parts = [];
		foreach(explode('/', $joined) as $part)
		{
			if($part === '' || $part === '.')
			{ continue; }
			if($part === '..' && $parts && end($parts) !== '..')
			{ array_pop($parts); }
			elseif($part !== '..' || !$absolute)
			{ $parts[] = $part; }
		}
		return $prefix.($absolute?'/':'').implode('/', $parts);
	}
}
