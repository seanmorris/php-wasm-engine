<?php
declare(strict_types=1);
namespace Phonics\Input;

/** Editable alternatives for signed logical actions; menu aliases need no game wrappers. */
final class Bindings implements \JsonSerializable
{
	private array $bindings;

	/**
	 * Create an editable profile from named action defaults.
	 *
	 * @param array<int,array<int,list<Source>>> $defaults Actions, signed directions and physical alternatives.
	 * @return void Copies the default profile into the active bindings.
	 */
	public function __construct(private array $defaults) { $this->bindings = $defaults; }

	/**
	 * Read the sources for one direction and optionally one device group.
	 *
	 * @param int $code Logical action code.
	 * @param int $direction Positive or negative action direction.
	 * @param string|null $device Device group, or null for all alternatives.
	 * @return list<Source> Matching physical alternatives.
	 */
	public function sources(int $code, int $direction = 1, ?string $device = null): array
	{
		$sources = [];

		foreach($this->bindings[$code][$direction] ?? [] as $source)
		if($device === null || $source->device() === $device) $sources[] = $source;

		return $sources;
	}

	/**
	 * Identify other actions using exactly the proposed physical source.
	 *
	 * @param int $code Target action code.
	 * @param int $direction Target action direction.
	 * @param Source $source Proposed physical source.
	 * @return list<array{int,int}> Conflicting action codes and directions.
	 */
	public function conflicts(int $code, int $direction, Source $source): array
	{
		$found = [];

		foreach($this->bindings as $action => $directions)
		foreach($directions as $sign => $sources)
		foreach($sources as $candidate)
		if(($action !== $code || $sign !== $direction) && $candidate->id() === $source->id())
		$found[] = [$action, $sign];

		return $found;
	}

	/**
	 * Add an alternative, requiring an explicit choice for conflicting bindings.
	 *
	 * @param int $code Target action code.
	 * @param int $direction Target action direction.
	 * @param Source $source Physical alternative to add.
	 * @param string $conflict Replace, share or cancel policy for conflicts.
	 * @return bool True when the binding exists after this call.
	 */
	public function bind(int $code, int $direction, Source $source, string $conflict = 'cancel'): bool
	{
		if(!isset($this->defaults[$code][$direction])) throw new \InvalidArgumentException('Unknown action');

		$found = $this->conflicts($code, $direction, $source);

		if($found && !in_array($conflict, ['replace', 'share'], true)) return false;

		if($conflict === 'replace') foreach($found as [$action, $sign]) $this->remove($action, $sign, $source);

		foreach($this->sources($code, $direction) as $candidate) if($candidate->id() === $source->id()) return true;
		$this->bindings[$code][$direction][] = $source;

		return true;
	}

	/**
	 * Remove only the selected physical alternative.
	 *
	 * @param int $code Action code to edit.
	 * @param int $direction Action direction to edit.
	 * @param Source $source Physical alternative to remove.
	 * @return void Leaves other devices and alternatives intact.
	 */
	public function remove(int $code, int $direction, Source $source): void
	{
		$sources = [];

		foreach($this->sources($code, $direction) as $candidate)
		if($candidate->id() !== $source->id()) $sources[] = $candidate;
		$this->bindings[$code][$direction] = $sources;
	}

	/**
	 * Restore one device without changing the other device bindings.
	 *
	 * @param string $device Device group to restore.
	 * @return void Restores this device from the default profile.
	 */
	public function reset(string $device): void
	{
		foreach($this->defaults as $code => $directions)
		foreach($directions as $direction => $sources)
		{
			foreach($this->sources($code, $direction, $device) as $source) $this->remove($code, $direction, $source);

			foreach($sources as $source)
			if($source->device() === $device)
			$this->bind($code, $direction, $source, 'share');
		}
	}

	/**
	 * Resolve signed physical states without retaining peak analog strengths.
	 *
	 * @param array<string,float> $held Current strengths keyed by source identity.
	 * @param array<string,float> $relative Relative displacement keyed by source identity.
	 * @param array<string,int> $presses Buffered press counts keyed by source identity.
	 * @return ActionFrame Resolved strengths, displacement, counts and device contributions.
	 */
	public function sample(array $held, array $relative = [], array $presses = []): ActionFrame
	{
		$frame = new ActionFrame;

		foreach($this->bindings as $code => $directions)
		{
			$positive = $negative = 0;

			foreach($directions as $direction => $sources)
			{
				$devices = [];
				$count = 0;
				$delta = 0;

				foreach($sources as $source)
				{
					$id = $source->id();
					$device = $source->device();
					$devices[$device] = max($devices[$device] ?? 0, min(1, max(0, $held[$id] ?? 0)));
					$delta += $relative[$id] ?? 0;
					$count += $presses[$id] ?? 0;
				}

				$strength = $devices ? max($devices) : 0;

				if($direction > 0) $positive = $strength;
				else $negative = $strength;

				foreach($devices as $device => $value)
				$frame->devices[$device][$code] = ($frame->devices[$device][$code] ?? 0) + $direction * $value;
				$frame->deltas[$code] = ($frame->deltas[$code] ?? 0) + $direction * $delta;
				$frame->presses[$code] = ($frame->presses[$code] ?? 0) + $direction * $count;
			}

			$frame->values[$code] = $positive - $negative;
		}

		return $frame;
	}

	/**
	 * Store device groups separately for independent validation and restoration.
	 *
	 * @return array Action directions containing device-specific source lists.
	 */
	public function jsonSerialize(): array
	{
		$result = [];

		foreach($this->bindings as $code => $directions)
		foreach($directions as $direction => $sources)
		foreach([Source::KEYBOARD, Source::CONTROLLER] as $device)
		$result[$code][$direction][$device] = $this->sources($code, $direction, $device);

		return $result;
	}

	/**
	 * Restore valid action/device entries while retaining defaults for malformed ones.
	 *
	 * @param array $stored Previously serialized profile.
	 * @param callable|null $allowed Optional predicate rejecting reserved physical controls.
	 * @return void Applies valid entries without changing unknown action codes.
	 */
	public function restore(array $stored, ?callable $allowed = null): void
	{
		foreach($this->defaults as $code => $directions)
		foreach($directions as $direction => $_)
		foreach([Source::KEYBOARD, Source::CONTROLLER] as $device)
		{
			$data = $stored[$code][$direction][$device] ?? null;

			if(!is_array($data) || !array_is_list($data) || count($data) > 32) continue;

			try
			{
				$sources = array_map([Source::class, 'restore'], $data);

				foreach($sources as $source)
				if($source->device() !== $device || ($allowed && !$allowed($source)))
				throw new \InvalidArgumentException('Disallowed source');
			} catch(\InvalidArgumentException)
			{
				continue;
			}

			foreach($this->sources($code, $direction, $device) as $source) $this->remove($code, $direction, $source);

			foreach($sources as $source) $this->bind($code, $direction, $source, 'share');
		}
	}
}
