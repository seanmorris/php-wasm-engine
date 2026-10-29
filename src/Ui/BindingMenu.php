<?php
declare(strict_types=1);
namespace Phonics\Ui;

use Phonics\Input\ActionInput;
use Phonics\Input\Bindings;
use Phonics\Input\Key;
use Phonics\Input\Pad;
use Phonics\Input\Source;

/** Reusable gameplay binding pages with a neutral-before-capture state machine. */
class BindingMenu
{
	/** Current input-label theme used for human-readable source names. */
	public string $theme = 'keyboard';
	private ?array $capture = null;
	private bool $armed = false;
	private string $message = '';
	private \Closure $save;
	private \Closure $reset;
	private \Closure $allowed;
	private \Closure $extras;
	private \Closure $status;

	/**
	 * Configure reusable binding pages and their game-owned persistence hooks.
	 *
	 * @param Bindings $bindings Mutable gameplay binding profile edited by these pages.
	 * @param array<string,array{int,int,string}> $actions Named code, direction and label rows.
	 * @param callable():void $save Persist the current profile.
	 * @param callable(string):void $reset Restore all defaults for one device.
	 * @param callable(Source):bool|null $allowed Reject fixed or reserved sources.
	 * @param callable(string):array|null $extras Add game-specific device settings.
	 * @param callable():string|null $status Describe session-only persistence or another state.
	 * @return void Stores the profile, action rows and callbacks, substituting neutral defaults for omitted hooks.
	 */
	public function __construct(
		private Bindings $bindings
		, private array $actions
		, callable $save
		, callable $reset
		, ?callable $allowed = null
		, ?callable $extras = null
		, ?callable $status = null
	){
		$this->save = \Closure::fromCallable($save);
		$this->reset = \Closure::fromCallable($reset);

		/**
		 * Accept every physical source when the game reserves none.
		 *
		 * @param Source $source Candidate physical input.
		 * @return bool Always true.
		 */
		$allowEverySource = static fn(Source $source): bool => true;
		$this->allowed = $allowed ? \Closure::fromCallable($allowed) : $allowEverySource;

		/**
		 * Add no game-specific settings when the game supplies none.
		 *
		 * @param string $device Device family whose page is being built.
		 * @return array<string,array<string,mixed>> An empty row list.
		 */
		$noExtraRows = static fn(string $device): array => [];
		$this->extras = $extras ? \Closure::fromCallable($extras) : $noExtraRows;

		/**
		 * Report no persistence status when the game supplies none.
		 *
		 * @return string An empty status message.
		 */
		$noStatus = static fn(): string => '';
		$this->status = $status ? \Closure::fromCallable($status) : $noStatus;
	}

	/**
	 * Report whether the menu is waiting for a fresh physical input.
	 *
	 * @return bool True while an add-binding capture is active.
	 */
	public function capturing(): bool { return $this->capture !== null; }

	/**
	 * Build the root item that opens keyboard, mouse, and controller binding pages.
	 *
	 * @param string $label Label shown for the root controls row.
	 * @return array<string,mixed> Declarative menu item with device-specific child pages.
	 */
	public function item(string $label = 'Controls'): array
	{

		/**
		 * Build the keyboard and mouse binding page on demand.
		 *
		 * @return array<string,array<string,mixed>> Keyboard action rows.
		 */
		$keyboardPage = fn(): array => $this->devicePage(Source::KEYBOARD);

		/**
		 * Build the controller binding page on demand.
		 *
		 * @return array<string,array<string,mixed>> Controller action rows.
		 */
		$controllerPage = fn(): array => $this->devicePage(Source::CONTROLLER);

		/**
		 * Build the controls root page with keyboard, controller and pause-hint rows.
		 *
		 * @return array<string,array<string,mixed>> Root page rows including the hidden page metadata.
		 */
		$rootPage = fn(): array => $this->page() + [
			'keyboard' => [
				'label' => 'Keyboard & Mouse'
				, 'children' => $keyboardPage
			]
			, 'controller' => [
				'label' => 'Controller'
				, 'children' => $controllerPage
			]
			, 'pause-hint' => ['label' => 'Pause: Escape / P / Start', 'disabled' => true]
		];

		return [
			'label' => $label
			, 'children' => $rootPage
		];
	}

	/**
	 * Hidden metadata identifies compact controls pages and their contextual footer.
	 *
	 * @return array<string,array<string,mixed>> Hidden page marker and live status-message rows.
	 */
	private function page(): array
	{

		/**
		 * Read the latest capture message, or the game's persistence status when there is none.
		 *
		 * @return string Footer text for the current controls page.
		 */
		$footer = fn(): string => $this->message ?: ($this->status)();

		return [
			'controls-page' => ['hidden' => true, 'value' => true]
			, 'controls-message' => [
				'hidden' => true
				, 'get' => $footer
			]
		];
	}

	/**
	 * Build one device's action rows, game extras and a restore-defaults action.
	 *
	 * @param string $device Device family whose bindings are listed.
	 * @return array<string,array<string,mixed>> Menu rows for the device page; clears any earlier message.
	 */
	private function devicePage(string $device): array
	{
		$this->message = '';
		$rows = $this->page();

		foreach($this->actions as $id => [$code, $direction, $label])
		{

			/**
			 * Show the action's current bindings for this device.
			 *
			 * @return string Controller glyphs, or keyboard and mouse labels, or Unbound.
			 */
			$currentBindings = fn(): string => $device === Source::CONTROLLER
				? $this->hints($code, $direction)
				: $this->labels($code, $direction, $device);

			/**
			 * Open the add and remove page for one action on this device.
			 *
			 * @return array<string,array<string,mixed>> Action page rows.
			 */
			$actionRows = fn(): array => $this->actionPage($code, $direction, $device);

			$rows[$id] = [
				'label' => $label
				, 'get' => $currentBindings
				, 'bindingDevice' => $device
				, 'children' => $actionRows
			];
		}

		$rows += ($this->extras)($device);

		/**
		 * Restore this device's defaults through the game callback.
		 *
		 * @return void Resets the device and shows a confirmation message.
		 */
		$restoreDefaults = function() use ($device): void {
			($this->reset)($device);
			$this->message = 'Device defaults restored';
		};

		$rows['defaults'] = [
			'label' => 'Restore device defaults'
			, 'callback' => $restoreDefaults
		];

		return $rows;
	}

	/**
	 * Format all physical sources assigned to one logical action on a device.
	 *
	 * @param int $code Logical action code.
	 * @param int $direction Signed action direction.
	 * @param string $device Device family to inspect.
	 * @return string Slash-separated source labels, or Unbound when none exist.
	 */
	public function labels(int $code, int $direction, string $device): string
	{
		$sources = $this->bindings->sources($code, $direction, $device);

		/**
		 * Format one source with the current input theme.
		 *
		 * @param Source $source Bound physical source.
		 * @return string Human-readable source label.
		 */
		$sourceLabel = fn(Source $source): string => $source->label($this->theme);

		return $sources
			? implode(' / ', array_map($sourceLabel, $sources))
			: 'Unbound';
	}

	/**
	 * Return controller bindings as theme-independent characters from the shared glyph atlas.
	 *
	 * @param int $code Logical action code.
	 * @param int $direction Signed action direction.
	 * @return string Controller glyphs or readable labels, or Unbound when none exist.
	 */
	public function hints(int $code, int $direction): string
	{
		$sources = $this->bindings->sources($code, $direction, Source::CONTROLLER);

		/**
		 * Prefer a controller glyph, falling back to the themed label.
		 *
		 * @param Source $source Bound controller source.
		 * @return string Glyph character or readable label.
		 */
		$sourceHint = fn(Source $source): string => $source->hint() ?? $source->label($this->theme);
		$hints = array_map($sourceHint, $sources);

		return $hints ? implode('  ', $hints) : 'Unbound';
	}

	/**
	 * Build the add-binding row and one removal row per source bound to an action on a device.
	 *
	 * @param int $code Logical action code.
	 * @param int $direction Signed action direction.
	 * @param string $device Device family being edited.
	 * @return array<string,array<string,mixed>> Menu rows for the action page.
	 */
	private function actionPage(int $code, int $direction, string $device): array
	{
		$rows = $this->page();

		/**
		 * Start capturing a new source for this action.
		 *
		 * @param Menu $menu Menu whose page is replaced by the capture prompt.
		 * @return void Arms capture after controls are released.
		 */
		$startCapture = function(Menu $menu) use ($code, $direction, $device): void {
			$this->capture = [$code, $direction, $device];
			$this->armed = false;
			$this->message = 'Escape / Start cancels';

			$menu->setItems($this->page() + [
				'capture' => ['label' => 'Release controls first', 'disabled' => true]
			]);
		};

		$rows['add-binding'] = [
			'label' => 'Add binding'
			, 'callback' => $startCapture
		];

		foreach($this->bindings->sources($code, $direction, $device) as $source)
		{
			$hint = $device === Source::CONTROLLER ? $source->hint() : null;

			/**
			 * Remove this source from the action and persist the profile.
			 *
			 * @param Menu $menu Menu whose action page is rebuilt.
			 * @return void Saves the profile and refreshes the page.
			 */
			$removeSource = function(Menu $menu) use ($code, $direction, $device, $source): void {
				$this->bindings->remove($code, $direction, $source);
				($this->save)();
				$menu->setItems($this->actionPage($code, $direction, $device));
			};

			$rows['remove-' . $source->id()] = [
				'label' => $hint === null ? 'Remove ' . $source->label($this->theme) : 'Remove'
				, 'value' => $hint ?? ''
				, 'bindingDevice' => $device
				, 'callback' => $removeSource
			];
		}

		return $rows;
	}

	/**
	 * Consume a capture poll before ordinary menu navigation sees the same input.
	 *
	 * @param Menu $menu Menu that owns the active binding page.
	 * @param ActionInput|null $input Latest physical input state, or null when unavailable.
	 * @param bool $focused Whether the game currently accepts input.
	 * @return bool True when capture owns this poll and ordinary navigation must skip it.
	 */
	public function update(Menu $menu, ?ActionInput $input, bool $focused): bool
	{
		if(!$this->capture) return false;

		[$code, $direction, $device] = $this->capture;

		if(!$menu->isOpen || !$focused || !$input)
		{
			$this->finish($menu, $code, $direction, $device);

			return true;
		}

		$candidates = $input->candidates();

		foreach($candidates as $source)
		{
			if(($source->kind === Source::KEY && $source->code === Key::ESCAPE)
				|| ($source->kind === Source::PAD_BUTTON && $source->code === Pad::START)
			){
				$this->finish($menu, $code, $direction, $device);

				return true;
			}
		}

		if(!$this->armed)
		{
			if($input->neutral() && !$candidates)
			{
				$this->armed = true;

				$menu->setItems($this->page() + [
					'capture' => [
						'label' => $device === Source::CONTROLLER
							? 'Press a button or move a stick'
							: 'Press a key or move the mouse'
						, 'disabled' => true
					]
				]);
			}

			return true;
		}

		foreach($candidates as $source)
		{
			if($source->device() !== $device) continue;

			if(!($this->allowed)($source))
			{
				$this->message = 'Pause controls cannot be reassigned';
				continue;
			}

			$conflicts = $this->bindings->conflicts($code, $direction, $source);
			$this->capture = null;

			if(!$conflicts)
			{
				$this->bindings->bind($code, $direction, $source);
				($this->save)();
				$this->finish($menu, $code, $direction, $device);

				return true;
			}

			$names = [];

			foreach($this->actions as [$action, $sign, $label])
			if(in_array([$action, $sign], $conflicts, true))
			$names[] = $label;
			$this->message = $source->label($this->theme) . ': ' . implode(', ', $names);
			$rows = $this->page();

			foreach(['replace' => 'Replace', 'share' => 'Share', 'cancel' => 'Cancel'] as $choice => $label)
			{

				/**
				 * Resolve a binding conflict with the selected policy.
				 *
				 * @param Menu $menu Menu returned to the action page.
				 * @return void Binds and saves for replace or share; cancel leaves the profile unchanged.
				 */
				$resolveConflict = function(Menu $menu) use ($code, $direction, $source, $device, $choice): void {
					if($choice !== 'cancel')
					{
						$this->bindings->bind($code, $direction, $source, $choice);
						($this->save)();
					}

					$this->finish($menu, $code, $direction, $device);
				};

				$rows[$choice] = [
					'label' => $label
					, 'callback' => $resolveConflict
				];
			}

			$menu->setItems($rows);

			return true;
		}

		return true;
	}

	/**
	 * End capture and return to the action page.
	 *
	 * @param Menu $menu Menu whose page is replaced.
	 * @param int $code Logical action code.
	 * @param int $direction Signed action direction.
	 * @param string $device Device family being edited.
	 * @return void Clears capture state and the message, then rebuilds the action page.
	 */
	private function finish(Menu $menu, int $code, int $direction, string $device): void
	{
		$this->capture = null;
		$this->message = '';
		$menu->setItems($this->actionPage($code, $direction, $device));
	}
}
