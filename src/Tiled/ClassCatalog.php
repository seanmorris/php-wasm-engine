<?php
declare(strict_types=1);
namespace Phonics\Tiled;

use Phonics\Asset\Assets;

/** Resolve Tiled custom classes, nested presets, defaults, and source-relative file members. */
final class ClassCatalog
{
	/** Tiled class definitions indexed by editor-visible class name. */
	private array $classes = [];
	/** Canonical file values encountered by the latest pure expansion. */
	private array $references = [];

	/**
	 * Accept a portable export of project propertyTypes and explicit component bindings.
	 *
	 * @param array $data Authored data with class definitions or properties.
	 * @param string $source Declaring resource used to resolve relative files.
	 * @return void Initializes the owned references and validates configuration.
	 */
	public function __construct(private array $data = [], private string $source = '')
	{
		foreach($data['classes'] ?? [] as $class)
		{
			$name = $class['name'];

			if(isset($this->classes[$name])) throw new \LogicException("Duplicate Tiled class $name");

			$this->classes[$name] = $class;
		}
	}

	/**
	 * Return all authored classes without activating gameplay code.
	 *
	 * @return array Definitions indexed by Tiled class name.
	 */
	public function classes(): array { return $this->classes; }

	/**
	 * Resolve a target's main class and class-valued properties into component descriptors.
	 *
	 * @param array $target Adapter target receiving the components.
	 * @param string $source Declaring resource used to resolve relative files.
	 * @return array Resolved component identities and independent nested values.
	 */
	public function components(array $target, string $source): array
	{
		$this->references = [];
		$records = $target['propertyRecords'] ?? $target['properties'] ?? [];
		$layers = [];

		foreach($records as $property)
		foreach($property['layers'] ?? [$property] as $record)
		$layers[] = [
			'values' => [$record['name'] => $record['value']]
			, 'source' => $record['source'] ?? $source
		];
		$main = $target['class'] ?? $target['type'] ?? '';
		$result = $main !== '' && isset($this->classes[$main]) ? $this->expand($main, $layers, [])['components'] : [];
		$members = array_column($this->classes[$main]['members'] ?? [], null, 'name');

		foreach($records as $record)
		{
			if(($record['type'] ?? '') !== 'class' || isset($members[$record['name']])) continue;

			$memberLayers = [];

			foreach($record['layers'] ?? [$record] as $part)
			$memberLayers[] = ['values' => $part['value'] ?? [], 'source' => $part['source'] ?? $source];
			array_push($result, ...$this->expand($record['propertytype'], $memberLayers, [])['components']);
		}

		$seen = [];

		foreach($result as $component)
		{
			if(isset($seen[$component['use']]))
			throw new \LogicException("Duplicate authored component {$component['use']}");

			$seen[$component['use']] = true;
		}

		return $result;
	}

	/**
	 * Discover file dependencies contributed by project defaults and nested component values.
	 *
	 * @param array $target Adapter target receiving the components.
	 * @param string $source Declaring resource used to resolve relative files.
	 * @return array Canonical file dependencies contributed by defaults and overrides.
	 */
	public function files(array $target, string $source): array
	{
		$this->components($target, $source);

		return array_keys($this->references);
	}

	/**
	 * Resolve layered member values while retaining each overridden file's declaring resource.
	 *
	 * @param string $name Stable registration or member name.
	 * @param array $layers Ordered value overrides with their declaring resource origins.
	 * @param array $chain Class names already expanded, used to reject recursive presets.
	 * @return array Resolved member values and component descriptors.
	 */
	private function expand(string $name, array $layers, array $chain): array
	{
		if(in_array($name, $chain, true)) throw new \LogicException("Tiled class cycle at $name");

		$class = $this->classes[$name] ?? throw new \OutOfBoundsException("Unknown Tiled class $name");
		$chain[] = $name;
		$values = $components = [];

		foreach($class['members'] ?? [] as $member)
		{
			$key = $member['name'];
			$entries = [['values' => $member['value'] ?? null, 'source' => $class['source'] ?? $this->source]];

			foreach($layers as $layer)
			if(array_key_exists($key, $layer['values']))
			$entries[] = ['values' => $layer['values'][$key], 'source' => $layer['source']];

			if(($member['type'] ?? '') === 'class')
			{
				foreach($entries as &$entry)
				{
					$entry['values'] ??= [];

					if(!is_array($entry['values']))
					throw new \UnexpectedValueException("Invalid class member $name.$key");
				}

				unset($entry);
				$nested = $this->expand($member['propertyType'] ?? $member['propertytype'], $entries, $chain);
				$values[$key] = $nested['values'];
				array_push($components, ...$nested['components']);
				continue;
			}

			$entry = end($entries);
			$value = $entry['values'];
			$valid = match($member['type'] ?? 'string')
			{
				'int', 'object' => is_int($value),
				'float' => is_numeric($value) && !is_string($value) && is_finite((float)$value),
				'bool' => is_bool($value),
				'string', 'file', 'color' => is_string($value),
				default => false
			};

			if(!$valid) throw new \UnexpectedValueException("Invalid {$member['type']} member $name.$key");

			if(($member['type'] ?? '') === 'file' && $value !== '')
			{
				$value = Assets::relative($entry['source'], $value);
				$this->references[$value] = true;
			}

			$values[$key] = $value;
		}

		if(isset($this->data['bindings'][$name]))
		$components = [['use' => $this->data['bindings'][$name], 'values' => $values, 'class' => $name]];

		return compact('values', 'components');
	}
}
