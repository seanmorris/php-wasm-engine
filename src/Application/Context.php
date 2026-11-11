<?php
declare(strict_types=1);
namespace Phonics\Application;

/**
 * Executable application's connection to the browser platform and optional content runtime.
 *
 * It exposes the executable archive, mounted resources, browser bridge, and optional world runtime through
 * one validated object passed to application code.
 */
final class Context
{
	/**
 * Immutable application initialization data supplied by the executable manifest.
 *
 * @var array Host-supplied JSON initialization values available to the application bootstrap.
 */
	public readonly array $init;
	/** Browser application host bridge supplied through Vrzno. */
	private mixed $host;
	/** Verified PHP entry path prepared by the most recent composition. */
	private string $entry;
	/** Whether the packaged browser runtime has already been activated for this application. */
	private bool $runtime = false;

	/**
	 * Connect the running PHAR to the browser host and register its declared archive members.
	 *
	 * @return void Initializes the browser host and exposes validated initialization data.
	 */
	public function __construct()
	{
		if(!function_exists('vrzno_env'))
		throw new \RuntimeException('This application requires the Phonics browser host');

		$this->host = vrzno_env('phonicsApplication');

		if(!$this->host || $this->host->abi !== 1) throw new \RuntimeException('Unsupported Phonics application host');

		$this->init = json_decode($this->host->init, true, flags: JSON_THROW_ON_ERROR);
		$root = 'phar://' . \Phar::running(false) . '/';
		$descriptor = file_get_contents($root . 'application.json');
		$members = array_fill_keys(json_decode($descriptor, true, flags: JSON_THROW_ON_ERROR)['members'], true);

		/**
		 * Copy one declared archive member to a browser-requested mount point.
		 *
		 * @param string $member Declared application-archive member to copy.
		 * @param string $target Validated temporary filesystem destination.
		 * @return void Copies the member or throws when either path violates the archive contract.
		 */
		$copy = static function(string $member, string $target) use ($root, $members): void {
			if(!isset($members[$member])) throw new \RuntimeException('Undeclared archive member: ' . $member);

			if(!str_starts_with($target, '/phonics/archive-read/'))
			throw new \RuntimeException('Invalid archive read target');

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
		$this->runtime();

		$data = json_decode(vrzno_await((vrzno_env('phonicsCompose'))(json_encode([
			'world' => $this->init['world'] ?? $world
			, 'entry' => $entry
			, 'network' => $network
		], JSON_THROW_ON_ERROR
		))), true, flags: JSON_THROW_ON_ERROR
		);

		foreach($data['bootstraps'] as $bootstrap) require_once $bootstrap;
		$phonics = new \Phonics\Package\Context($data['world']);
		$phonics->factories->strictReferences = true;

		/**
		 * Remove refresh ownership and close the composed package context.
		 *
		 * @return void Releases systems, resources, and the registered refresh callback.
		 */
		$close = static function() use ($phonics, &$close): void {
			vrzno_env('onRefresh')->delete($close);
			$phonics->close();
		};

		vrzno_env('onRefresh')->add($close);

		foreach($data['entries'] as $module)
		{
			$register = require $module['file'];

			if(in_array($module['type'], ['adapter', 'system-set', 'scope-policy', 'component'], true))
			foreach([$module['id'], ...($module['aliases'] ?? [])] as $id)
			match($module['type'])
			{
				'adapter' => $phonics->runtime->adapter($id, $register),
				'system-set' => $phonics->runtime->systemSet($id, $register),
				'scope-policy' => $phonics->runtime->lifecycle($id, $register),
				'component' => $phonics->components->register($id, $register)
			};
			else $register($phonics);
		}

		if(!$data['legacy'])
		{
			foreach($phonics->world['sources'] as $id => $source)
			$phonics->runtime->source(
				new \Phonics\World\SourceDefinition(
					$id
					, $source['adapter']
					, $source['resources']
					, $source['options'] ?? []
					, $phonics->world['sourceUrl']
				)
			);

			foreach($phonics->world['bindings'] as $binding)
			$phonics->runtime->bind(
				$binding['id']
				, $binding['use']
				, $binding['on'] ?? []
				, $binding['options'] ?? []
				, $binding['after'] ?? []
			);
		}

		foreach($data['factories'] as $factory) $phonics->factories->alias($factory['id'], $factory['class']);

		foreach($data['components'] ?? [] as $component)
		$phonics->components->alias($component['id'], $component['class']);
		$this->entry = $data['entry'];

		return $phonics;
	}

	/**
	 * Create a room-backed lobby for an application that owns its game loop directly.
	 *
	 * The lobby resolves its provider from the application's declared dependencies. It does not compose a world,
	 * negotiate content, or create a gameplay transport; deterministic applications exchange related room events.
	 *
	 * @param array $configuration Lobby provider, room, game identity, rules, and presentation configuration.
	 * @return void Installs the embedded lobby shell and exposes its browser game port on the application session.
	 */
	public function lobby(array $configuration): void
	{
		$this->runtime();
		vrzno_await((vrzno_env('phonicsLobby'))(json_encode($configuration, JSON_THROW_ON_ERROR)));
	}

	/**
	 * Activate the verified browser runtime at most once for this application.
	 * @return void Loads the packaged runtime module on the first call; later calls do nothing.
	 */
	private function runtime(): void
	{
		if($this->runtime) return;

		$this->runtime = true;
		$this->browser('phar://' . \Phar::running(false) . '/runtime.mjs');
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
