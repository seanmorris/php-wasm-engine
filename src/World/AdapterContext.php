<?php
declare(strict_types=1);
namespace Phonics\World;

/** Host services available while a content adapter opens a source. */
final class AdapterContext
{
	/**
	 * Create an adapter context around canonical resource callbacks and host services.
	 *
	 * @param callable(string,string):string|null $resolve Resolve a reference against its declaring source.
	 * @param callable(string):string|null $read Read one canonical resource as bytes.
	 * @param array<string,mixed> $services Optional engine or host services keyed by stable names.
	 * @return void Stores callbacks without performing I/O.
	 */
	public function __construct(
		private $resolve = null
		, private $read = null
		, private array $services = []
	) {}

	/**
	 * Resolve a resource reference while retaining the declaring source as provenance.
	 *
	 * @param string $reference Relative, absolute, or package resource reference.
	 * @param string $declaring Canonical URL or native path containing the reference.
	 * @return string Canonical resource identity.
	 */
	public function resolve(string $reference, string $declaring): string
	{
		if($this->resolve) return ($this->resolve)($reference, $declaring);

		return \Phonics\Asset\Assets::relative($declaring, $reference);
	}

	/**
	 * Read a canonical resource through the session loader.
	 *
	 * @param string $url Canonical URL or native path.
	 * @return string Unmodified resource bytes.
	 */
	public function read(string $url): string
	{
		if(!$this->read) throw new \LogicException('No resource reader is available');

		return ($this->read)($url);
	}

	/**
	 * Look up an optional host service without coupling adapters to a concrete engine.
	 *
	 * @param string $name Stable service name.
	 * @param mixed $default Value returned when the service is absent.
	 * @return mixed Registered service or the supplied default.
	 */
	public function service(string $name, $default = null)
	{
		return $this->services[$name] ?? $default;
	}
}
