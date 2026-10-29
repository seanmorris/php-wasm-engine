<?php
declare(strict_types=1);
namespace Phonics\Application;

/** Executable application's connection to the browser platform and optional content runtime. */
final class Context
{
	/** @var array Host-supplied JSON initialization values available to the application bootstrap. */
	public readonly array $init;
	private mixed $host;
	private string $entry;

	/** Connect the running PHAR to the browser host and register its declared archive members. */
	public function __construct()
	{
		if(!function_exists('vrzno_env')) throw new \RuntimeException('This application requires the Phonics browser host');
		$this->host = vrzno_env('phonicsApplication');
		if(!$this->host || $this->host->abi !== 1) throw new \RuntimeException('Unsupported Phonics application host');
		$this->init = json_decode($this->host->init, true, flags: JSON_THROW_ON_ERROR);
		$root = 'phar://' . \Phar::running(false) . '/';
		$descriptor = file_get_contents($root . 'application.json');
		$members = array_fill_keys(json_decode($descriptor, true, flags: JSON_THROW_ON_ERROR)['members'], true);
		$copy = static function(string $member, string $target) use ($root, $members): void {
			if(!isset($members[$member])) throw new \RuntimeException('Undeclared archive member: ' . $member);
			if(!str_starts_with($target, '/phonics/archive-read/')) throw new \RuntimeException('Invalid archive read target');
			if(!is_dir(dirname($target))) mkdir(dirname($target), 0777, true);
			if(!copy($root . $member, $target)) throw new \RuntimeException('Cannot read archive member: ' . $member);
		};
		vrzno_await(($this->host->initialize)($descriptor, $copy));
	}

	/**
	 * Activate application-owned browser code; the module returns optional session cleanup.
	 * @param string $file PHP-readable path to a self-contained JavaScript module exporting activate(host).
	 * @return void Registers the module's cleanup callback with the application session.
	 */
	public function browser(string $file): void
	{
		vrzno_await(($this->host->activate)(file_get_contents($file)));
	}

	/**
	 * Prepare content and an explicit game export. The application executes entry() in its own scope.
	 * @param string $world Content world URL or archive member path; init.world may select another world.
	 * @param string $entry Explicit package manifest URL or archive path with a game export fragment.
	 * @param array|null $network Application-owned lobby and transport configuration, or null for offline play.
	 * @return \Phonics\Package\Context Prepared game context with module registrations and content bindings.
	 */
	public function compose(string $world, string $entry, ?array $network = null): \Phonics\Package\Context
	{
		$this->browser('phar://' . \Phar::running(false) . '/runtime.mjs');
		$data = json_decode(vrzno_await((vrzno_env('phonicsCompose'))(json_encode([
			'world' => $this->init['world'] ?? $world, 'entry' => $entry, 'network' => $network
		], JSON_THROW_ON_ERROR))), true, flags: JSON_THROW_ON_ERROR);
		foreach($data['bootstraps'] as $bootstrap) require_once $bootstrap;
		$phonics = new \Phonics\Package\Context($data['world']);
		$phonics->factories->strictReferences = true;
		$close = static function() use ($phonics, &$close): void {
			vrzno_env('onRefresh')->delete($close);
			$phonics->close();
		};
		vrzno_env('onRefresh')->add($close);
		foreach($data['entries'] as $module)
		{
			$register = require $module['file'];
			match($module['type']) {
				'adapter' => $phonics->runtime->adapter($module['id'], $register),
				'system-set' => $phonics->runtime->systemSet($module['id'], $register),
				default => $register($phonics)
			};
		}
		if(!$data['legacy'])
		{
			foreach($phonics->world['sources'] as $id => $source)
			$phonics->runtime->source(new \Phonics\World\SourceDefinition(
				$id, $source['adapter'], $source['resources'], $source['options'] ?? [], $phonics->world['sourceUrl']
			));
			foreach($phonics->world['bindings'] as $binding)
			$phonics->runtime->bind($binding['id'], $binding['use'], $binding['on'] ?? [], $binding['options'] ?? [], $binding['after'] ?? []);
		}
		foreach($data['factories'] as $factory) $phonics->factories->alias($factory['id'], $factory['class']);
		$this->entry = $data['entry'];
		return $phonics;
	}

	/**
	 * Verified entry prepared by compose(), for require from the application bootstrap.
	 * @return string PHP-readable game entry path, available after compose().
	 */
	public function entry(): string { return $this->entry; }

	/**
	 * Signal readiness for applications that do not load a world.
	 * @return void Resolves the browser's application readiness promise.
	 */
	public function ready(): void { (vrzno_env('sceneReady'))(null); }
}
