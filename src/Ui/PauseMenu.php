<?php
declare(strict_types=1);
namespace Phonics\Ui;

use Phonics\Engine;
use Phonics\Render\BitmapFont;

/** Optional SDL/Engine adapter; Menu itself remains scriptable without a runtime. */
final class PauseMenu
{
	/** Owned screen-space menu renderer. */
	public MenuRenderer $renderer;
	/** Menu input adapter translating SDL UI frames into menu commands. */
	public MenuInput $controls;
	/** Font/button theme name, or auto to follow the latest input device. */
	public string $theme = 'auto';
	/** Total real time in seconds used by animated menu presentation. */
	public float $time = 0;
	private int $generation = -1;
	private bool $closed = false;

	/**
	 * Attach a menu to the engine's UI input hook.
	 *
	 * @param Engine $engine Engine whose gameplay pauses while this menu captures input.
	 * @param Menu $menu Application-defined root page and bindings.
	 * @param BitmapFont $font Annotated font plus optional themed symbols.
	 * @return void Creates owned graphics and installs the input handler.
	 */
	public function __construct(private Engine $engine, public Menu $menu, BitmapFont $font)
	{
		$this->renderer = new MenuRenderer($font);

		$this->controls = new MenuInput(
			/**
			 * Copy selected field text through SDL's clipboard implementation.
			 *
			 * @param string $text Selected UTF-8 text to copy.
			 * @return bool True when SDL accepts the clipboard write.
			 */
			static fn(string $text): bool => function_exists('SDL_SetClipboardText')
				&& SDL_SetClipboardText($text) === 0
		);

		$engine->setInputHandler([$this, 'update']);
	}

	/**
	 * Route unpaused UI input and synchronize SDL text focus with the active field.
	 *
	 * @param Engine $engine Engine invoking the registered pre-simulation hook.
	 * @param float $elapsed Seconds since the preceding active browser frame.
	 * @return bool True while the menu owns input, including opening and closing transitions.
	 */
	public function update(Engine $engine, float $elapsed): bool
	{
		$frame = $engine->input->uiFrame($this->menu->editor !== null, $this->menu->isOpen);

		if($frame['generation'] !== $this->generation || !$frame['focused'])
		{
			$this->controls->reset();
			$this->menu->editor?->compose('');
			$this->generation = $frame['generation'];
		}

		$this->time += min(.25, $elapsed);

		$captured = $this->controls->update(
			$this->menu
			, $frame['buttons']
			, min(.25, $elapsed)
			, $frame['events']
			, $this->renderer->regions
		);

		$engine->userPaused = $this->menu->isOpen;

		$engine->input->setTextInput($this->menu->editor !== null && $frame['focused'], $this->renderer->editorRect);
		$this->renderer->font->theme = $this->theme === 'auto' ? $frame['theme'] : $this->theme;

		return $captured;
	}

	/**
	 * Render the menu after the world and before swapping the window buffers.
	 *
	 * @return void Updates hit regions using the engine device's drawing-buffer dimensions.
	 */
	public function render(): void
	{
		$this->renderer->render($this->menu, $this->engine->device->width, $this->engine->device->height, $this->time);
	}

	/**
	 * Rebuild the adapter's GPU resources after the engine restores its context.
	 *
	 * @return void Retains menu state and live settings across restoration.
	 */
	public function restore(): void { $this->renderer->restore(); }

	/**
	 * Detach the input hook and release editing and graphics resources.
	 *
	 * @return void Closes once; applications should call this in the engine cleanup callback.
	 */
	public function close(): void
	{
		if($this->closed)
		{
			return;
		}

		$this->closed = true;
		$this->menu->close();
		$this->engine->input->setTextInput(false);
		$this->engine->setInputHandler(null);
		$this->engine->userPaused = false;
		$this->renderer->close();
	}
}
