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
	 */
	public function __construct(
		private Bindings $bindings
		, private array $actions
		, callable $save
		, callable $reset
		, ?callable $allowed = null
		, ?callable $extras = null
		, ?callable $status = null
	) {
		$this->save = \Closure::fromCallable($save);
		$this->reset = \Closure::fromCallable($reset);
		$this->allowed = $allowed ? \Closure::fromCallable($allowed) : static fn(Source $source): bool => true;
		$this->extras = $extras ? \Closure::fromCallable($extras) : static fn(string $device): array => [];
		$this->status = $status ? \Closure::fromCallable($status) : static fn(): string => '';
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
		return [
			'label' => $label
			, 'children' => fn(): array => $this->page() + [
				'keyboard' => [
					'label' => 'Keyboard & Mouse'
					, 'children' => fn(): array => $this->devicePage(Source::KEYBOARD)
				]
				, 'controller' => [
					'label' => 'Controller'
					, 'children' => fn(): array => $this->devicePage(Source::CONTROLLER)
				]
				, 'pause-hint' => ['label' => 'Pause: Escape / P / Start', 'disabled' => true]
			]
		];
	}

	/** Hidden metadata identifies compact controls pages and their contextual footer. */
	private function page(): array
	{
		return [
			'controls-page' => ['hidden' => true, 'value' => true]
			, 'controls-message' => [
				'hidden' => true
				, 'get' => fn(): string => $this->message ?: ($this->status)()
			]
		];
	}

	private function devicePage(string $device): array
	{
		$this->message = '';
		$rows = $this->page();

		foreach($this->actions as $id => [$code, $direction, $label])
		{
			$rows[$id] = [
				'label' => $label
				, 'get' => fn(): string => $device === Source::CONTROLLER
					? $this->hints($code, $direction)
					: $this->labels($code, $direction, $device)
				, 'bindingDevice' => $device
				, 'children' => fn(): array => $this->actionPage($code, $direction, $device)
			];
		}

		$rows += ($this->extras)($device);

		$rows['defaults'] = [
			'label' => 'Restore device defaults'
			, 'callback' => function() use ($device): void {
				($this->reset)($device);
				$this->message = 'Device defaults restored';
			}
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

		return $sources
			? implode(' / ', array_map(fn(Source $source): string => $source->label($this->theme), $sources))
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
		$hints = array_map(fn(Source $source): string => $source->hint() ?? $source->label($this->theme), $sources);

		return $hints ? implode('  ', $hints) : 'Unbound';
	}

	private function actionPage(int $code, int $direction, string $device): array
	{
		$rows = $this->page();

		$rows['add-binding'] = [
			'label' => 'Add binding'
			, 'callback' => function(Menu $menu) use ($code, $direction, $device): void {
				$this->capture = [$code, $direction, $device];
				$this->armed = false;
				$this->message = 'Escape / Start cancels';

				$menu->setItems($this->page() + [
					'capture' => ['label' => 'Release controls first', 'disabled' => true]
				]);
			}
		];

		foreach($this->bindings->sources($code, $direction, $device) as $source)
		{
			$hint = $device === Source::CONTROLLER ? $source->hint() : null;
			$rows['remove-' . $source->id()] = [
				'label' => $hint === null ? 'Remove ' . $source->label($this->theme) : 'Remove'
				, 'value' => $hint ?? ''
				, 'bindingDevice' => $device
				, 'callback' => function(Menu $menu) use ($code, $direction, $device, $source): void {
					$this->bindings->remove($code, $direction, $source);
					($this->save)();
					$menu->setItems($this->actionPage($code, $direction, $device));
				}
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
				$rows[$choice] = [
					'label' => $label
					, 'callback' => function(Menu $menu) use ($code, $direction, $source, $device, $choice): void {
						if($choice !== 'cancel')
						{
							$this->bindings->bind($code, $direction, $source, $choice);
							($this->save)();
						}

						$this->finish($menu, $code, $direction, $device);
					}
				];
			}

			$menu->setItems($rows);

			return true;
		}

		return true;
	}

	private function finish(Menu $menu, int $code, int $direction, string $device): void
	{
		$this->capture = null;
		$this->message = '';
		$menu->setItems($this->actionPage($code, $direction, $device));
	}
}
