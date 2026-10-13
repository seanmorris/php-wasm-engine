<?php
declare(strict_types=1);
namespace PhpWasmEngine\Input;

/** Timed, device-independent input recognition; no SDL or rendering dependency. */
final class Sequences
{
	private array $definitions = [], $candidates = [], $matches = [];
	private array $held = [], $since = [];
	private ?float $time = null;
	private int $facing = 1;
	private string $direction = 'neutral';

	/**
	 * Register or replace a named sequence of fresh presses, chords or release steps.
	 *
	 * @param string $name Identifier returned with each completion.
	 * @param list<int|string|array> $steps Button names/indices, chord lists, or descriptors with
	 * press/release, optional held controls and minHold seconds for a release.
	 * @param float $maxGap Maximum seconds between matched steps; defaults to 750 milliseconds.
	 * @param float|null $maxDuration Optional limit on total sequence duration in seconds.
	 * @param bool $strict Whether an unrelated fresh press cancels partial matches.
	 * @return void Installs validated steps and clears progress for this name.
	 */
	public function add(string $name, array $steps, float $maxGap = .75,
		?float $maxDuration = null, bool $strict = true
	): void
	{
		if($name === '' || !$steps || !is_finite($maxGap) || $maxGap <= 0
			|| ($maxDuration !== null && (!is_finite($maxDuration) || $maxDuration <= 0))
		){
			throw new \InvalidArgumentException('Sequences need a name, steps and positive timing');
		}

		$compiled = [];

		foreach($steps as $step)
		{
			$compiled[] = self::compile($step);
		}

		$this->definitions[$name] = [$compiled, $maxGap, $maxDuration, $strict];
		$this->candidates[$name] = [];
	}

	/**
	 * Convert a button spelling into a matcher token, including standard D-pad aliases.
	 *
	 * @param int|string $key Button index, action or directional motion token.
	 * @return string Canonical action or direction used during matching.
	 */
	private static function token(int|string $key): string
	{
		$name = Buttons::name($key);

		if($name === '')
		{
			throw new \InvalidArgumentException('Sequence controls cannot be empty');
		}

		return ['button12' => 'up', 'button13' => 'down', 'button14' => 'left'
			, 'button15' => 'right'
		][$name] ?? $name;
	}

	/**
	 * Validate one step without permitting ambiguous press and release descriptors.
	 *
	 * @param int|string|array $step Single press, nonempty chord or explicit edge descriptor.
	 * @return array{edge:string,triggers:list<string>,held:list<string>,minHold:float} Step rule.
	 */
	private static function compile(int|string|array $step): array
	{
		$edge = 'press';
		$held = [];
		$minimum = 0.0;

		if(!is_array($step))
		{
			$triggers = [self::token($step)];
		}
		elseif(array_keys($step) === range(0, count($step) - 1))
		{
			$triggers = $held = array_map([self::class, 'token'], $step);
		}
		else
		{
			if(array_diff(array_keys($step), ['press', 'release', 'held', 'minHold'])
				|| isset($step['press']) === isset($step['release'])
			){
				throw new \InvalidArgumentException('A step needs exactly one press or release');
			}

			$edge = isset($step['release']) ? 'release' : 'press';
			$triggers = [self::token($step[$edge])];
			$held = array_map([self::class, 'token'], $step['held'] ?? []);
			$minimum = (float)($step['minHold'] ?? 0);
		}

		if(!$triggers || !is_finite($minimum) || $minimum < 0
			|| ($minimum > 0 && $edge !== 'release')
		){
			throw new \InvalidArgumentException(
				'A charge needs a release and nonnegative hold time'
			);
		}

		return ['edge' => $edge, 'triggers' => $triggers, 'held' => $held, 'minHold' => $minimum];
	}

	/**
	 * Remove a definition and its partial matches.
	 *
	 * @param string $name Registered sequence identifier.
	 * @return void Leaves other definitions and already completed events intact.
	 */
	public function remove(string $name): void
	{
		unset($this->definitions[$name], $this->candidates[$name]);
	}

	/**
	 * Orient forward/back tokens toward the opponent without synthesizing input edges.
	 *
	 * @param int $facing Positive one faces right; negative one faces left.
	 * @return void Clears partial motions on an actual facing change; preserves completions.
	 */
	public function setFacing(int $facing): void
	{
		if($facing !== -1 && $facing !== 1)
		{
			throw new \InvalidArgumentException('Facing must be -1 or 1');
		}

		if($facing !== $this->facing)
		{
			$this->candidates = [];
			$this->facing = $facing;
			$this->since = [];
		}
	}

	/**
	 * Discard input history, partial matches and unread completions after input recovery.
	 *
	 * @return void Retains definitions and facing; the next snapshot begins a fresh timeline.
	 */
	public function reset(): void
	{
		$this->candidates = $this->matches = $this->held = $this->since = [];
		$this->time = null;
		$this->direction = 'neutral';
	}

	/**
	 * Read the longest currently matched prefix of a definition.
	 *
	 * @param string $name Sequence identifier to inspect.
	 * @return int Matched step count, or zero after timeout, reset or an unknown name.
	 */
	public function progress(string $name): int
	{
		return max(array_merge([0], array_keys($this->candidates[$name] ?? [])));
	}

	/**
	 * Consume completion events without consuming gameplay controls or other input observers.
	 *
	 * @return list<array{name:string,time:float,duration:float}> Completions in recognition order;
	 * only the latest 64 are retained if a caller never drains them.
	 */
	public function drain(): array
	{
		$matches = $this->matches;
		$this->matches = [];
		return $matches;
	}

	/**
	 * Add facing-relative aliases to an absolute cardinal or diagonal direction.
	 *
	 * @param string $direction Absolute direction token to expand.
	 * @return list<string> Absolute token and its forward/back counterpart when applicable.
	 */
	private function directions(string $direction): array
	{
		if(!preg_match('/^(?:up-|down-)?(?:left|right)$/', $direction))
		{
			return [$direction];
		}

		$relative = strtr(
			$direction
			, ['left' => $this->facing === 1 ? 'back' : 'forward'
			, 'right' => $this
				->facing === 1 ? 'forward' : 'back'
			]
		);
		return array_values(array_unique([$direction, $relative]));
	}

	/**
	 * Expand held controls with direction and facing aliases for chord and charge conditions.
	 *
	 * @param array<string,bool> $buttons Digital controls after opposing directions cancel.
	 * @param string $direction Exact current cardinal, diagonal or neutral direction.
	 * @return array<string,bool> All held tokens, including exact direction and relative aliases.
	 */
	private function heldTokens(array $buttons, string $direction): array
	{
		$tokens = array_fill_keys($this->directions($direction), true);

		foreach($buttons as $key => $active)
		{
			foreach($this->directions(self::token($key)) as $token)
			{
				$tokens[$token] = true;
			}
		}

		return $tokens;
	}

	/**
	 * Check one edge, its held modifiers and any required charge duration.
	 *
	 * @param array $step Compiled press, chord or release rule.
	 * @param array $event Fresh press/release sets, held tokens and hold start times.
	 * @param float $time Current monotonic seconds.
	 * @return bool True if at least one allowed fresh edge satisfies every condition.
	 */
	private static function matches(array $step, array $event, float $time): bool
	{
		foreach($step['held'] as $key)
		{
			if(!isset($event['held'][$key]))
			{
				return false;
			}
		}

		foreach($step['triggers'] as $key)
		{
			if(isset($event[$step['edge']][$key])
				&& $time - ($event['since'][$key] ?? $time) + 1e-9 >= $step['minHold']
			){
				return true;
			}
		}

		return false;
	}

	/**
	 * Observe a chronological input snapshot, independently of fixed simulation steps.
	 *
	 * @param array<int|string,bool|int|float> $buttons Strengths; .5 is digital down.
	 * @param float $time Nonnegative monotonic timestamp in seconds, shared by all input sources.
	 * @return void Updates progress and queues completions; simultaneous edges advance one step.
	 */
	public function update(array $buttons, float $time): void
	{
		if(!is_finite($time) || $time < 0 || ($this->time !== null && $time < $this->time))
		{
			throw new \InvalidArgumentException('Input timestamps must be finite and monotonic');
		}

		$next = [];

		foreach(Buttons::normalize($buttons) as $key => $value)
		{
			if($value >= .5)
			{
				$next[$key] = true;
			}
		}

		foreach([['button12','button13'], ['button14','button15']] as [$a, $b])
		{
			if(isset($next[$a], $next[$b]))
			{
				unset($next[$a], $next[$b]);
			}
		}

		$vertical = isset($next['button12']) ? 'up' : (isset($next['button13']) ? 'down' : '');
		$horizontal = isset($next['button14']) ? 'left' : (isset($next['button15']) ? 'right' : '');
		$direction = trim($vertical.'-'.$horizontal, '-') ?: 'neutral';
		$held = $this->heldTokens($next, $direction);
		$previous = $this->heldTokens($this->held, $this->direction);
		$press = [];

		foreach(array_diff_key($next, $this->held) as $key => $active)
		{
			if(!in_array($key, ['button12','button13','button14','button15'], true))
			{
				$press[$key] = true;
			}
		}

		if($direction !== $this->direction)
		{
			$press += array_fill_keys($this->directions($direction), true);
		}

		$since = $this->since;

		foreach($held as $key => $active)
		{
			if(!isset($previous[$key]) || !isset($since[$key]))
			{
				$since[$key] = $time;
			}
		}

		$event = ['press' => $press, 'release' => array_diff_key($previous, $held)
			, 'held' => $held, 'since' => $since
		];
		$this->held = $next;
		$this->since = array_intersect_key($since, $held);
		$this->direction = $direction;
		$this->time = $time;
		$this->advance($event, $time);
	}

	/**
	 * Advance all viable prefixes, including overlapping starts, with bounded retained history.
	 *
	 * @param array $event Fresh edges and held conditions for one chronological observation.
	 * @param float $time Timestamp used for gap, total duration and charge checks.
	 * @return void Queues each definition at most once per observation and expires idle prefixes.
	 */
	private function advance(array $event, float $time): void
	{
		$significant = count(array_diff_key($event['press'], ['neutral' => true])) > 0;

		foreach($this->definitions as $name => [$steps, $gap, $duration, $strict])
		{
			$pending = $this->candidates[$name] ?? [];
			$pending[0] = ['start' => $time, 'last' => $time];
			$next = [];
			$completed = false;

			foreach($pending as $index => $candidate)
			{
				if($time - $candidate['last'] > $gap + 1e-9
					|| ($duration !== null && $time - $candidate['start'] > $duration + 1e-9)
				){
					continue;
				}

				if(!self::matches($steps[$index], $event, $time))
				{
					if($index > 0 && (!$significant || !$strict))
					{
						$next[$index] = $candidate;
					}

					continue;
				}

				if($index + 1 === count($steps))
				{
					if(!$completed)
					{
						$this->matches[] = ['name' => $name, 'time' => $time
							, 'duration' => $time - $candidate['start']
						];
						$completed = true;
					}
				}
				else
				{
					$next[$index + 1] = ['start' => $candidate['start'], 'last' => $time];
				}
			}

			$this->candidates[$name] = $next;
		}

		$this->matches = array_slice($this->matches, -64);
	}
}
