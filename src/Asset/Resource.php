<?php
declare(strict_types=1);
namespace Phonics\Asset;

/** Canonical source identity is independent of an optional local transport mount. */
final class Resource
{

	/**
	 * Return an installed framework resource independently of the current game package.
	 *
	 * @param string $path Resource path or URL.
	 * @return string Absolute installed path under the Phonics package root.
	 */
	public static function framework(string $path): string
	{
		return dirname(__DIR__, 2) . '/' . $path;
	}

	private static array $mounts = [], $sources = [];

	/**
	 * Bind verified source bytes to a local mount without changing reference resolution.
	 *
	 * @param string $source Canonical URL of the declaring resource.
	 * @param string $local Local filesystem mount for verified bytes.
	 * @return void Records both source-to-mount and mount-to-source lookups.
	 */
	public static function mount(string $source, string $local): void
	{
		if(isset(self::$sources[$local]) && self::$sources[$local] !== $source)
		{
			throw new \LogicException("Resource mount conflict: $local");
		}

		self::$mounts[$source] = $local;
		self::$sources[$local] = $source;
	}

	/**
	 * Return a source's filesystem transport, or its original native path.
	 *
	 * @param string $source Canonical URL of the declaring resource.
	 * @return string Verified local mount when available, otherwise the original source.
	 */
	public static function local(string $source): string
	{
		if(isset(self::$mounts[$source])) return self::$mounts[$source];

		$resolve = function_exists('vrzno_env') ? vrzno_env('resourcePath') : null;

		return is_callable($resolve) ? (self::$mounts[$source] = $resolve($source)) : $source;
	}

	/**
	 * Return canonical provenance for a transport path.
	 *
	 * @param string $path Resource path or URL.
	 * @return string Canonical source URL or unchanged native path.
	 */
	public static function source(string $path): string
	{
		if(isset(self::$sources[$path])) return self::$sources[$path];

		$resolve = function_exists('vrzno_env') ? vrzno_env('resourceSource') : null;

		return is_callable($resolve) ? (self::$sources[$path] = $resolve($path)) : $path;
	}

	/**
	 * Resolve RFC 3986 references, retaining queries/fragments and native filesystem support.
	 *
	 * @param string $base Declaring resource URL or native filename.
	 * @param string $reference Reference resolved against the declaring resource.
	 * @return string Resolved URL or normalized native filesystem path.
	 */
	public static function resolve(string $base, string $reference): string
	{
		$base = self::source($base);

		if(str_starts_with($reference, '@') && function_exists('vrzno_env'))
		{
			$resolve = vrzno_env('resourceResolve');

			if(is_callable($resolve)) return $resolve($reference, $base);
		}

		$b = parse_url($base);
		$r = parse_url($reference);

		if($b === false || $r === false) throw new \InvalidArgumentException('Invalid resource URL');

		if(isset($r['scheme'])) $t = $r;
		elseif(isset($r['host'])) $t = $r + array_intersect_key($b, ['scheme' => true]);
		else
		{
			$t = array_intersect_key($b, array_flip(['scheme', 'host', 'port', 'user', 'pass']));
			$path = $r['path'] ?? '';

			if($path === '')
			{
				$t['path'] = $b['path'] ?? '';

				if(isset($r['query']) || isset($b['query'])) $t['query'] = $r['query'] ?? $b['query'];
			}
			else
			{
				$basePath = str_replace('\\', '/', $b['path'] ?? '/');
				$slash = strrpos($basePath, '/');
				$directory = $slash === false ? '' : substr($basePath, 0, $slash + 1);
				$t['path'] = str_starts_with($path, '/')
					? $path
					: $directory . $path;

				if(isset($r['query'])) $t['query'] = $r['query'];
			}

			if(isset($r['fragment'])) $t['fragment'] = $r['fragment'];
		}

		$path = str_replace('\\', '/', $t['path'] ?? '');
		$absolute = str_starts_with($path, '/');
		$parts = [];

		$segments = explode('/', $path);

		foreach($segments as $index => $part)
		{
			if($part === '.')
			{
				if($index === count($segments) - 1) $parts[] = '';

				continue;
			}

			if($part === '..')
			{
				if($parts && end($parts) !== '..' && !($absolute && count($parts) === 1)) array_pop($parts);
				elseif(!$absolute) $parts[] = '..';

				if($index === count($segments) - 1 && $parts && end($parts) !== '..') $parts[] = '';

				continue;
			}

			$parts[] = $part;
		}

		$path = implode('/', $parts);

		if(isset($t['host']) && $path === '') $path = '/';

		$authority = isset($t['host'])
			? '//' . (isset($t['user'])
				? $t['user']
			. (isset($t['pass']) ? ':' . $t['pass'] : '') . '@'
				: ''
			)
			. strtolower($t['host']) . (isset($t['port']) ? ':' . $t['port'] : '')
			: '';

		return (isset($t['scheme']) ? strtolower($t['scheme']) . ':' : '') . $authority . $path
			. (isset($t['query']) ? '?' . $t['query'] : '') . (isset($t['fragment']) ? '#' . $t['fragment'] : '');
	}
}
