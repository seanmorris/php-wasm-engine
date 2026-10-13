<?php
declare(strict_types=1);
namespace PhpWasmEngine\Ui;

/** Declarative menu state and live bindings, usable without SDL or a renderer. */
final class Menu
{
	public bool $isOpen = false;
	public ?string $selected = null;
	public ?TextBuffer $editor = null;
	public int $scroll = 0;
	public bool $loading = false;
	public ?string $error = null;
	private array $items = [], $stack = [];
	private $loader = null;

	/**
	 * Define the root page using stable IDs and item metadata.
	 *
	 * @param array<string,array<string,mixed>> $items Ordered definitions keyed by stable IDs.
	 * @param string $title Root heading rendered above the rows.
	 * @return void Initializes a closed menu and validates the root definitions.
	 */
	public function __construct(array $items = [], public string $title = 'Menu')
	{
		$this->setItems($items);
	}

	/**
	 * Replace the current page while preserving its selected ID when possible.
	 *
	 * @param array<string,array<string,mixed>> $items Ordered item definitions; IDs beginning
	 * with @ are reserved for generated navigation rows.
	 * @return void Installs valid definitions and selects the first visible row if necessary.
	 */
	public function setItems(array $items): void
	{
		foreach($items as $id => $item)
		{
			if(!is_string($id) || $id === '' || $id[0] === '@' || !is_array($item))
			{
				throw new \InvalidArgumentException(
					'Menu items require stable string IDs without @'
				);
			}

			$type = $item['input'] ?? '';

			if(!in_array($type, ['', 'string', 'number', 'select', 'boolean', 'output'], true))
			{
				throw new \InvalidArgumentException("Unknown menu input: $type");
			}

			foreach(['align', 'valueAlign'] as $key)
			{
				if(isset($item[$key]) && !in_array($item[$key], ['left', 'center', 'right'], true))
				{
					throw new \InvalidArgumentException('Alignment must be left, center, or right');
				}
			}

			if($type === 'number')
			{
				foreach(['min', 'max', 'step'] as $key)
				{
					if(isset($item[$key]) && (!is_numeric(
						$item[$key]
					) || !is_finite(
						(float)$item[$key]
					))){
						throw new \InvalidArgumentException(
							'Numeric menu bounds and steps must be finite'
						);
					}
				}

				if(($item['step'] ?? 1) <= 0 || ($item['min'] ?? -INF) > ($item['max'] ?? INF))
				{
					throw new \InvalidArgumentException('Numeric menu bounds or step are invalid');
				}
			}

			$items[$id]['label'] = $item['label'] ?? $id;
			$items[$id]['value'] = $item['value'] ?? $item['default'] ?? match($type)
			{
				'boolean' => false, 'number' => $item['min'] ?? 0,
				'select' => $item['options'][0] ?? null, default => ''
			};
		}
		$this->finishEdit();
		$this->items = $items;
		$this->refresh();
	}

	/**
	 * Open the current root page and resolve its live values.
	 *
	 * @return void Marks the menu active without allocating rendering resources.
	 */
	public function open(): void
	{
		$this->isOpen = true;
		$this->refresh();
	}

	/**
	 * Leave editing and unwind submenus before closing.
	 *
	 * @return void Keeps valid live values and the root selection for the next opening.
	 */
	public function close(): void
	{
		$this->finishEdit();

		while($this->stack)
		{
			$this->back();
		}
		$this->isOpen = false;
	}

	/**
	 * Count open submenu levels.
	 *
	 * @return int Zero for the root page.
	 */
	public function depth(): int { return count($this->stack); }

	/**
	 * Evaluate a static or callable visibility/availability condition.
	 *
	 * @param mixed $condition Boolean value or callable receiving this menu.
	 * @return bool Current condition value.
	 */
	private function condition(mixed $condition): bool
	{
		return (bool)(is_callable($condition) ? $condition($this) : $condition);
	}

	/**
	 * Expose visible rows and generated Back/Retry actions for rendering and scripting.
	 *
	 * @return array<string,array<string,mixed>> Ordered resolved row metadata, keyed by ID.
	 */
	public function rows(): array
	{
		$rows = [];

		foreach($this->items as $id => $item)
		{
			if($this->condition($item['hidden'] ?? false))
			{
				continue;
			}

			$item['disabled'] = $this->condition($item['disabled'] ?? false)
				|| (($item['input'] ?? '') === 'select' && empty($item['options']));
			$rows[$id] = $item;
		}

		if($this->error !== null)
		{
			$rows['@retry'] = ['label' => 'Retry', 'disabled' => false];
		}

		if($this->stack)
		{
			$rows['@back'] = ['label' => 'Back', 'disabled' => false];
		}

		return $rows;
	}

	/**
	 * Poll a lazy page and refresh getters without firing setters during redraw.
	 *
	 * @return void Updates visible values and repairs selection after conditional row changes.
	 */
	public function refresh(): void
	{
		if($this->loading && $this->loader)
		{
			try
			{
				$items = ($this->loader)($this);

				if($items !== null)
				{
					$this->loading = false;
					$this->selected = null;
					$this->setItems($items);
				}
			}
			catch(\Throwable $error)
			{
				$this->loading = false;
				$this->error = $error->getMessage();
			}
		}

		foreach($this->items as $id => &$item)
		{
			if(isset($item['get']) && !($this->editor && $id === $this->selected))
			{
				$item['value'] = ($item['get'])();
			}
		}
		unset($item);
		$rows = $this->rows();

		if($this->selected === null || !isset($rows[$this->selected]))
		{
			$this->finishEdit();
			$this->selected = array_key_first($rows);
		}
		elseif($this->editor && $rows[$this->selected]['disabled'])
		{
			$this->editor = null;
		}
	}

	/**
	 * Read a current application value without formatting it for display.
	 *
	 * @param string $id Current page item ID.
	 * @return mixed Live or locally stored value; null for an absent item.
	 */
	public function value(string $id): mixed { return $this->items[$id]['value'] ?? null; }

	/**
	 * Select a visible row and finish any active field edit.
	 *
	 * @param string $id Stable ID of a visible row.
	 * @return bool True if selection changed to the requested visible row.
	 */
	public function select(string $id): bool
	{
		if(!isset($this->rows()[$id]) || $id === $this->selected)
		{
			return false;
		}

		$this->finishEdit();
		$this->selected = $id;
		return true;
	}

	/**
	 * Move through visible rows, optionally clamping page jumps at the ends.
	 *
	 * @param int $distance Signed number of rows, with large values usable for Home/End.
	 * @param bool $wrap Wrap single-row navigation when true; page jumps use false.
	 * @return void Changes selection if at least one row is visible.
	 */
	public function move(int $distance, bool $wrap = true): void
	{
		$ids = array_keys($this->rows());

		if(!$ids)
		{
			return;
		}

		$index = (int)array_search($this->selected, $ids, true);
		$next = $index + $distance;
		$next = $wrap ? (($next % count($ids)) + count($ids)) % count($ids)
			: max(0, min(count($ids) - 1, $next));
		$this->select($ids[$next]);
	}

	/**
	 * Execute an action, enter a submenu, begin editing, or toggle a choice.
	 *
	 * @return void Runs only enabled rows; generated navigation rows use the same command path.
	 */
	public function activate(): void
	{
		if($this->editor)
		{
			$this->finishEdit();
			return;
		}

		if($this->selected === '@back')
		{
			$this->back();
			return;
		}

		if($this->selected === '@retry')
		{
			$this->error = null;
			$this->loading = true;
			$this->refresh();
			return;
		}

		$item = $this->rows()[$this->selected] ?? null;

		if(!$item || $item['disabled'])
		{
			return;
		}

		$type = $item['input'] ?? '';

		if(in_array($type, ['string', 'number'], true))
		{
			$this->editor = new TextBuffer((string)$item['value']);
			return;
		}

		if(in_array($type, ['boolean', 'select'], true))
		{
			$this->adjust(1);
			return;
		}

		if(isset($item['children']))
		{
			$this->stack[] = ['items' => $this->items, 'selected' => $this->selected
				, 'title' => $this->title
				, 'scroll' => $this->scroll
				, 'loader' => $this->loader
			];
			$this->title = $item['label'];
			$this->selected = null;
			$this->scroll = 0;
			$this->items = [];
			$this->loader = is_callable($item['children']) ? $item['children'] : null;
			$this->loading = $this->loader !== null;
			$this->error = null;

			if($this->loading)
			{
				$this->refresh();
			}
			else
			{
				$this->setItems($item['children']);
			}

			return;
		}

		if(isset($item['callback']))
		{
			($item['callback'])($this, $this->selected);
		}
	}

	/**
	 * Leave a field, return to its parent page, or close the root menu.
	 *
	 * @return void Restores parent selection and scroll while retaining static child values.
	 */
	public function back(): void
	{
		if($this->editor)
		{
			$this->finishEdit();
			return;
		}

		if(!$this->stack)
		{
			$this->isOpen = false;
			return;
		}

		$parent = array_pop($this->stack);

		if(is_array($parent['items'][$parent['selected']]['children']))
		{
			$parent['items'][$parent['selected']]['children'] = $this->items;
		}

		$this->items = $parent['items'];
		$this->selected = $parent['selected'];
		$this->title = $parent['title'];
		$this->scroll = $parent['scroll'];
		$this->loader = $parent['loader'];
		$this->loading = false;
		$this->error = null;
		$this->refresh();
	}

	/**
	 * Validate and apply one edited value, calling its setter once per actual change.
	 *
	 * @param string $id Current page row ID.
	 * @param mixed $value Proposed boolean, number, string, or selection option.
	 * @return bool True if an enabled editable row accepted a changed value.
	 */
	public function setValue(string $id, mixed $value): bool
	{
		$item = $this->rows()[$id] ?? null;
		$type = $item['input'] ?? '';

		if(!$item || $item['disabled'] || !in_array(
			$type
			, ['string', 'number', 'boolean', 'select']
			, true
		)){
			return false;
		}

		if($type === 'number')
		{
			if(!is_numeric($value) || !is_finite((float)$value))
			{
				return false;
			}

			$step = (float)($item['step'] ?? 1);
			$base = (float)($item['min'] ?? 0);
			$value = $base + round(((float)$value - $base) / $step) * $step;
			$value = max($item['min'] ?? -INF, min($item['max'] ?? INF, $value));

			if(!is_finite($value))
			{
				return false;
			}

			$value = (float)sprintf('%.14g', $value);
		}
		elseif($type === 'boolean')
		{
			$value = (bool)$value;
		}
		elseif($type === 'string')
		{
			$value = (new TextBuffer((string)$value))->text;
		}
		elseif(!in_array($value, $item['options'] ?? [], true))
		{
			return false;
		}

		if($value === $item['value'] || ($type === 'number' && $value == $item['value']))
		{
			return false;
		}

		if(isset($item['set']))
		{
			($item['set'])($value);
		}

		$this->items[$id]['value'] = $value;
		return true;
	}

	/**
	 * Adjust the selected numeric, boolean, or selection input.
	 *
	 * @param int $direction Positive for next/increase, negative for previous/decrease.
	 * @return void Applies the row's step or wraps its option list.
	 */
	public function adjust(int $direction): void
	{
		$item = $this->rows()[$this->selected] ?? null;

		if(!$item || $direction === 0 || $this->editor)
		{
			return;
		}

		$value = $item['value'] ?? null;

		switch($item['input'] ?? '')
		{
			case 'number':
				$value += ($direction <=> 0) * ($item['step'] ?? 1);
				break;
			case 'boolean':
				$value = !$value;
				break;
			case 'select':
				$options = array_values($item['options'] ?? []);

				if(!$options)
				{
					return;
				}

				$index = array_search($value, $options, true);
				$index = $index === false ? ($direction > 0 ? -1 : 0) : $index;

				$value = $options[($index + ($direction <=> 0) + count(
					$options
				)) % count(
					$options
				)];
				break;
			default: return;
		}
		$this->setValue($this->selected, $value);
	}

	/**
	 * Reset an enabled row using its callback or declared default.
	 *
	 * @return void Restores an editable value or invokes an action-specific reset callback.
	 */
	public function revert(): void
	{
		$item = $this->rows()[$this->selected] ?? null;

		if(!$item || $item['disabled'])
		{
			return;
		}

		if(isset($item['revert']))
		{
			$value = ($item['revert'])($this);
			$value = isset($item['get']) ? ($item['get'])() : ($value ?? $item['default'] ?? null);
		}
		else
		{
			$value = $item['default'] ?? null;
		}

		if($value !== null)
		{
			$this->setValue($this->selected, $value);
		}

		$this->editor = null;
	}

	/**
	 * Publish a valid committed buffer value without altering its draft spelling.
	 *
	 * @return void Ignores incomplete numeric drafts and uncommitted composition.
	 */
	public function applyEdit(): void
	{
		if($this->editor && $this->selected !== null)
		{
			$this->setValue($this->selected, $this->editor->text);
		}
	}

	/**
	 * End a field edit, retaining valid live changes and discarding any unfinished draft.
	 *
	 * @return void Releases the editing buffer and composition preview.
	 */
	public function finishEdit(): void
	{
		$this->applyEdit();
		$this->editor = null;
	}
}
