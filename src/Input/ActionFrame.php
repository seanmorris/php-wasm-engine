<?php
declare(strict_types=1);
namespace Phonics\Input;

/** Signed held values, relative displacement and counted presses sampled independently. */
final class ActionFrame
{

	/**
	 * Create one sample without discarding device contributions.
	 *
	 * @param array<int,float> $values Signed merged strengths.
	 * @param array<int,float> $deltas Signed relative displacement.
	 * @param array<int,int> $presses Signed press counts.
	 * @param array<string,array<int,float>> $devices Strengths grouped by device.
	 * @return void Initializes the sampled action channels.
	 */
	public function __construct(
		public array $values = []
		, public array $deltas = []
		, public array $presses = []
		, public array $devices = []
	){
	}

	/**
	 * Read a signed strength, optionally before merging devices.
	 *
	 * @param int $code Logical action code.
	 * @param string|null $device Device group, or null for the merged value.
	 * @return float Signed strength or zero for an absent action.
	 */
	public function value(int $code, ?string $device = null): float
	{
		return (float)($device === null ? ($this->values[$code] ?? 0) : ($this->devices[$device][$code] ?? 0));
	}

	/**
	 * Read accumulated relative movement in physical units.
	 *
	 * @param int $code Logical action code.
	 * @return float Signed displacement or zero for an absent action.
	 */
	public function delta(int $code): float { return (float)($this->deltas[$code] ?? 0); }
}
