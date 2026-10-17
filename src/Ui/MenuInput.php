<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ui;

/** Translate host-neutral button snapshots and text/pointer events into menu commands. */
final class MenuInput
{
	private array $held = [], $next = [];
	private ?array $drag = null;
	private $copy;

	/**
	 * Configure optional clipboard writes without coupling menu state to browser APIs.
	 *
	 * @param (callable(string):bool)|null $copy Writes selected UTF-8 text; true permits cutting.
	 * @return void Initializes neutral navigation and pointer state.
	 */
	public function __construct(?callable $copy = null) { $this->copy = $copy; }

	/**
	 * Discard navigation repeats and pointer drags after focus or graphics recovery.
	 *
	 * @return void Requires a new input snapshot before any repeated operation.
	 */
	public function reset(): void
	{
		$this->held = $this->next = [];
		$this->drag = null;
	}

	/**
	 * Compute first-press and accelerated repeat counts using elapsed seconds.
	 *
	 * @param string $key Logical button whose state was advanced for this frame.
	 * @param bool $repeat Permit navigation repeats when true.
	 * @return int Number of commands due; activation buttons never repeat.
	 */
	private function pulses(string $key, bool $repeat = false): int
	{
		if(!isset($this->held[$key]))
		{
			return 0;
		}

		if(!isset($this->next[$key]))
		{
			$this->next[$key] = .5;

			return 1;
		}

		$count = 0;

		while($repeat && $this->held[$key] + 1e-9 >= $this->next[$key])
		{
			$count++;
			$this->next[$key] += $this->next[$key] < 1.5 - 1e-9 ? .25 : .05;
		}

		return $count;
	}

	/**
	 * Route one UI frame, including frames when the fixed simulation is paused.
	 *
	 * @param Menu $menu Target menu receiving commands.
	 * @param array<string,bool|float> $buttons Named up/down/left/right, confirm, back, pause,
	 * revert, pageUp/pageDown, home/end states; directions activate at half strength.
	 * @param float $dt Nonnegative elapsed UI time in seconds.
	 * @param list<array<string,mixed>> $events Ordered key, text, composition, pointer,
	 * and wheel events; each wheel event moves one row using only its vertical direction.
	 * @param list<array<string,mixed>> $regions Visible row hit regions from MenuRenderer.
	 * @return bool True when this frame belongs to the menu, including its opening/closing frame.
	 */
	public function update(
		Menu $menu
		, array $buttons
		, float $dt
		, array $events = []
		, array $regions = []
	): bool {
		if(!is_finite($dt) || $dt < 0)
		{
			throw new \InvalidArgumentException('UI elapsed time must be finite and nonnegative');
		}

		foreach(array_unique(array_merge(array_keys($buttons), array_keys($this->held))) as $key)
		{
			if(($buttons[$key] ?? 0) >= .5)
			{
				$this->held[$key] = isset($this->held[$key]) ? $this->held[$key] + $dt : 0;
			}
			else
			{
				unset($this->held[$key], $this->next[$key]);
			}
		}

		$wasOpen = $menu->isOpen;

		if($this->pulses('pause'))
		{
			if($menu->isOpen)
			{
				$menu->close();
			}
			else
			{
				$menu->open();
			}

			return true;
		}

		if(!$menu->isOpen)
		{
			return false;
		}

		$menu->refresh();

		foreach($events as $event)
		{
			if(!$menu->isOpen)
			{
				break;
			}

			if($menu->editor && in_array($event['type'], ['text', 'composition', 'key'], true))
			{
				$this->edit($menu, $event);
			}
			elseif($event['type'] === 'pointer')
			{
				$this->pointer($menu, $event, $regions);
			}
			elseif($event['type'] === 'wheel')
			{
				$menu->move(-($event['y'] <=> 0), false);
			}
		}

		if(!$menu->isOpen)
		{
			return $wasOpen;
		}

		if($this->pulses('back'))
		{
			$menu->back();
		}
		elseif($this->pulses('confirm'))
		{
			$menu->activate();
		}

		foreach([
			'up' => -1
			, 'down' => 1
			, 'pageUp' => -7
			, 'pageDown' => 7
			, 'home' => -1000000
			, 'end' => 1000000
		] as $key => $distance)
		{
			$count = $this->pulses($key, true);

			for($i = 0; $i < $count; $i++)
			{
				$menu->move($distance, abs($distance) === 1);
			}
		}

		foreach(['left' => -1, 'right' => 1] as $key => $direction)
		{
			$count = $this->pulses($key, true);

			for($i = 0; $i < $count; $i++)
			{
				if($menu->editor)
				{
					$menu->editor->move($direction);
				}
				else
				{
					$menu->adjust($direction);
				}
			}
		}

		if(!$menu->editor && isset($this->held['revert']) && $this->held['revert'] >= .5
			&& !isset($this->next['revert'])
		){
			$menu->revert();
			$this->next['revert'] = INF;
		}

		return true;
	}

	/**
	 * Apply editor events separately from navigation bindings and live application values.
	 *
	 * @param Menu $menu Menu with an active field buffer.
	 * @param array<string,mixed> $event Text/composition payload or named editing key.
	 * @return void Edits committed text, publishes valid values, or leaves the field.
	 */
	private function edit(Menu $menu, array $event): void
	{
		$buffer = $menu->editor;

		if($event['type'] === 'text')
		{
			$buffer->insert($event['text']);
		}
		elseif($event['type'] === 'composition')
		{
			$buffer->compose($event['text'], $event['start'] ?? 0, $event['length'] ?? 0);

			return;
		}
		else
		{
			$key = strtolower($event['key']);
			$shift = $event['shift'] ?? false;

			if(($event['repeat'] ?? false)
				&& in_array($key, ['escape', 'return', 'enter', 'keypad enter'], true)
			){
				return;
			}

			if($buffer->composition !== '' && !in_array($key, ['escape'], true))
			{
				return;
			}

			switch($key)
			{
				case 'escape':
					if($buffer->composition !== '')
					{
						$buffer->compose('');
					}
					else
					{
						$menu->finishEdit();
					}

					return;
				case 'return': case 'enter': case 'keypad enter':
					$menu->finishEdit();

					return;
				case 'up': case 'down':
					$menu->move($key === 'up' ? -1 : 1);

					return;
				case 'left': $buffer->move(-1, $shift);
					break;
				case 'right': $buffer->move(1, $shift);
					break;
				case 'home':
					$buffer->caret = 0;

					if(!$shift)
					{
						$buffer->anchor = 0;
					}

					break;
				case 'end':
					$anchor = $buffer->anchor;
					$buffer->selectAll();
					$buffer->anchor = $shift ? $anchor : $buffer->caret;
					break;
				case 'backspace': $buffer->erase(true);
					break;
				case 'delete': $buffer->erase(false);
					break;
				case 'a': if($event['ctrl'] ?? false)
				{
					$buffer->selectAll();
				}

					break;
				case 'c': case 'x':
					if(($event['ctrl'] ?? false) && $this->copy && $buffer->selectedText() !== ''
						&& ($this->copy)($buffer->selectedText()) && $key === 'x'
					){
						$buffer->insert('');
					}

					break;
			}
		}

		$menu->applyEdit();
	}

	/**
	 * Hit-test rows and drag sliders or a field selection using rendered coordinates.
	 *
	 * @param Menu $menu Menu receiving pointer commands.
	 * @param array<string,mixed> $event Pointer action, button, and output-pixel coordinates.
	 * @param list<array<string,mixed>> $regions Current visible row rectangles.
	 * @return void Ignores clipped or disabled controls and clears drags on release.
	 */
	private function pointer(Menu $menu, array $event, array $regions): void
	{
		if($event['action'] === 'up')
		{
			$this->drag = null;

			return;
		}

		$hit = $this->drag;

		if($hit)
		{
			foreach($regions as $region)
			{
				if($region['id'] === $hit['id'])
				{
					$hit = $region;
					break;
				}
			}
		}

		if(!$hit)
		{
			foreach($regions as $region)
			{
				[$x, $y, $w, $h] = $region['rect'];

				if($event['x'] >= $x && $event['x'] < $x + $w
					&& $event['y'] >= $y && $event['y'] < $y + $h
				){
					$hit = $region;
					break;
				}
			}
		}

		if(!$hit || ($event['action'] === 'move' && !$this->drag))
		{
			return;
		}

		$menu->select($hit['id']);
		$item = $menu->rows()[$hit['id']] ?? null;

		if(!$item || $item['disabled'])
		{
			return;
		}

		if(($event['button'] ?? 0) === 2)
		{
			$menu->revert();

			return;
		}

		if($event['action'] === 'down' && ($event['button'] ?? 1) !== 1)
		{
			return;
		}

		if(isset($hit['slider']) && ($this->drag || $event['x'] >= $hit['slider'][0]))
		{
			[$left, $width] = $hit['slider'];
			$fraction = max(0, min(1, ($event['x'] - $left) / max(1, $width)));
			$menu->setValue($hit['id'], $item['min'] + $fraction * ($item['max'] - $item['min']));
			$this->drag = $hit;
		}
		elseif(in_array($item['input'] ?? '', ['string', 'number'], true))
		{
			if(!$menu->editor)
			{
				$menu->activate();
			}

			if(isset($hit['carets']) && $menu->editor)
			{
				$nearest = 0;
				$distance = INF;

				foreach($hit['carets'] as $index => $x)
				{
					if(abs($x - $event['x']) < $distance)
					{
						$distance = abs($x - $event['x']);
						$nearest = $index;
					}
				}

				$menu->editor->caret = $nearest;

				if(!$this->drag)
				{
					$menu->editor->anchor = $nearest;
				}
			}

			$this->drag = $hit;
		}
		elseif($event['action'] === 'down')
		{
			$menu->activate();
		}
	}
}
