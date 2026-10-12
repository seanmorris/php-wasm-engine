<?php
declare(strict_types=1);
namespace PhpWasmEngine\Asset;

/** Cache per asset source. Supply a reader for remote files or custom archives. */
final class Assets
{
	private array $bytes = [];
	private array $json = [];
	private $reader;

	/**
	 * Create independent byte and decoded-JSON caches for an asset source.
	 *
	 * @param (callable(string):(string|false))|null $reader Path-to-bytes callback; null uses
	 * file_get_contents and failed reads remain retryable.
	 * @return void Initializes empty caches and the selected reader.
	 */
	public function __construct(?callable $reader = null)
	{
		$this->reader = $reader ??
				/**
				 * Read an asset through PHP filesystem and stream support.
				 *
				 * @param string $path File or stream-wrapper URL requested by the asset cache.
				 * @return string|false Complete bytes, or false when the source cannot be read.
				 */
				static fn($path) => file_get_contents($path);
	}

	/**
	 * Read asset bytes once and reuse successful reads.
	 *
	 * @param string $path Asset path or URL understood by the configured reader.
	 * @return string Complete asset bytes; non-string reader results raise RuntimeException.
	 */
	public function read(string $path): string
	{
		if(!isset($this->bytes[$path]))
		{
			$bytes = ($this->reader)($path);
			if(!is_string($bytes))
			{
				throw new \RuntimeException("Cannot read asset: $path");
			}
			$this->bytes[$path] = $bytes;
		}
		return $this->bytes[$path];
	}

	/**
	 * Decode and cache an asset containing a JSON object or array.
	 *
	 * @param string $path Asset path or URL understood by the configured reader.
	 * @return array<array-key,mixed> Decoded associative data; invalid JSON or scalar roots raise
	 * an exception.
	 */
	public function json(string $path): array
	{
		if(!isset($this->json[$path]))
		{
			$data = json_decode($this->read($path), true, 512, JSON_THROW_ON_ERROR);
			if(!is_array($data))
			{
				throw new \UnexpectedValueException("Expected JSON object: $path");
			}
			$this->json[$path] = $data;
		}
		return $this->json[$path];
	}

	/**
	 * Remove both cached representations of one asset.
	 *
	 * @param string $path Exact cache key to invalidate.
	 * @return void The next access reads the source again.
	 */
	public function forget(string $path): void { unset($this->bytes[$path], $this->json[$path]); }

	/**
	 * Resolve an asset reference against its owning file while preserving URL authorities.
	 *
	 * @param string $base Owning file path or URL, including its filename.
	 * @param string $path Referenced path; absolute paths and URLs pass through unchanged.
	 * @return string Normalized relative or absolute path, or the supplied absolute URL.
	 */
	public static function relative(string $base, string $path): string
	{
		if(preg_match('~^[a-z][a-z0-9+.-]*:~i', $path) || str_starts_with($path, '/'))
		{
			return $path;
		}
		$directory = str_replace('\\', '/', dirname($base));
		$joined = ($directory === '.' ? '' : $directory.'/').$path;
		$prefix = '';
		if(preg_match('~^([a-z][a-z0-9+.-]*://[^/]+)(/.*)?$~i', $joined, $match))
		{
			$prefix = $match[1];
			$joined = $match[2] ?? '/';
		}
		$absolute = str_starts_with($joined, '/');
		$parts = [];
		foreach(explode('/', $joined) as $part)
		{
			if($part === '' || $part === '.')
			{
				continue;
			}
			if($part === '..' && $parts && end($parts) !== '..')
			{
				array_pop($parts);
			}
			elseif($part !== '..' || !$absolute)
			{
				$parts[] = $part;
			}
		}
		return $prefix.($absolute?'/':'').implode('/', $parts);
	}
}
