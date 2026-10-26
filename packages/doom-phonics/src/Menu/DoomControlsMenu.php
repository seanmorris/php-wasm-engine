<?php
declare(strict_types=1);
namespace DoomPhonics\Menu;

use DoomPhonics\Input\DoomActions;
use DoomPhonics\Input\InputSettings;
use Phonics\Input\ActionInput;
use Phonics\Input\Key;
use Phonics\Input\Pad;
use Phonics\Input\Source;
use Phonics\Ui\Menu;

/** Gameplay binding pages and a neutral-before-capture state machine. Menu controls stay fixed. */
final class DoomControlsMenu
{
	public string $theme = 'keyboard';
	private ?array $capture = null;
	private bool $armed = false;
	private string $message = '';

	public function __construct(private InputSettings $settings) {}

	public function capturing(): bool { return $this->capture !== null; }

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
				, 'get' => fn(): string => $this->message ?: ($this->settings->sessionOnly
					? 'Changes apply to this session'
					: ''
				)
			]
		];
	}

	private function devicePage(string $device): array
	{
		$this->message = '';
		$rows = $this->page();

		foreach(DoomActions::rows() as $id => [$code, $direction, $label])
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

		$s = $this->settings->settings;

		if($device === Source::CONTROLLER)
		{
			$steps = array_map(static fn(int $i): float => $i / 4, range(1, 12));

			$rows['horizontal'] = $this->choice(
				'Horizontal sensitivity'
				, 'controllerHorizontal'
				, $steps
				, fn() => $s->controllerHorizontal
			);

			$rows['vertical'] = $this->choice(
				'Vertical sensitivity'
				, 'controllerVertical'
				, $steps
				, fn() => $s->controllerVertical
			);

			$rows['deadzone'] = $this->choice(
				'Look deadzone'
				, 'controllerDeadZone'
				, array_map(static fn(int $i): float => $i / 100, range(0, 30))
				, fn() => $s->controllerDeadZone
			);
			$rows['deadzone']['format'] = 'percent';

			$rows['invert'] = $this->choice(
				'Invert vertical look'
				, 'controllerInvertY'
				, null
				, fn() => $s->controllerInvertY
			);
		} else
		{
			$rows['sensitivity'] = $this->choice(
				'Mouse sensitivity'
				, 'mouseSensitivity'
				, [.5,.75,1.0,1.25,1.5,1.75,2.0]
				, fn() => $s->lookSensitivity()
			);

			$rows['invert'] = $this->choice(
				'Invert mouse look'
				, 'mouseInvertY'
				, null
				, fn() => $s->invertVerticalLook()
			);
		}

		$rows['defaults'] = [
			'label' => 'Restore device defaults'
			, 'callback' => function() use ($device): void {
				$this->settings->reset($device);
				$this->message = 'Device defaults restored';
			}
		];

		return $rows;
	}

	private function choice(string $label, string $name, ?array $options, callable $get): array
	{
		return [
			'label' => $label
			, 'input' => $options === null ? 'boolean' : 'select'
			, 'options' => $options
			, 'get' => $get
			, 'set' => function(mixed $value) use ($name): void { $this->settings->preference($name, $value); }
		];
	}

	public function labels(int $code, int $direction, string $device): string
	{
		$sources = $this->settings->bindings->sources($code, $direction, $device);

		return $sources
			? implode(' / ', array_map(fn(Source $source): string => $source->label($this->theme), $sources))
			: 'Unbound';
	}

	/** Return controller bindings as theme-independent characters from the shared glyph atlas. */
	public function hints(int $code, int $direction): string
	{
		$sources = $this->settings->bindings->sources($code, $direction, Source::CONTROLLER);
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

		foreach($this->settings->bindings->sources($code, $direction, $device) as $source)
		{
			$hint = $device === Source::CONTROLLER ? $source->hint() : null;
			$rows['remove-' . $source->id()] = [
				'label' => $hint === null ? 'Remove ' . $source->label($this->theme) : 'Remove'
				, 'value' => $hint ?? ''
				, 'bindingDevice' => $device
				, 'callback' => function(Menu $menu) use ($code, $direction, $device, $source): void {
					$this->settings->bindings->remove($code, $direction, $source);
					$this->settings->save();
					$menu->setItems($this->actionPage($code, $direction, $device));
				}
			];
		}

		return $rows;
	}

	/** Consume a capture poll before ordinary menu navigation sees the same input. */
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

			if(!DoomActions::allowed($source))
			{
				$this->message = 'Pause controls cannot be reassigned';
				continue;
			}

			$conflicts = $this->settings->bindings->conflicts($code, $direction, $source);
			$this->capture = null;

			if(!$conflicts)
			{
				$this->settings->bindings->bind($code, $direction, $source);
				$this->settings->save();
				$this->finish($menu, $code, $direction, $device);

				return true;
			}

			$names = [];

			foreach(DoomActions::rows() as [$action, $sign, $label])
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
							$this->settings->bindings->bind($code, $direction, $source, $choice);
							$this->settings->save();
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
