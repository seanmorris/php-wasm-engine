<?php
declare(strict_types=1);
namespace Phonics\Random;

/**
 * Versioned deterministic sampling from an explicitly supplied seed.
 *
 * SHA-256 counter blocks and rejection sampling have identical byte semantics on
 * native PHP and WASM. This replayable stream does not establish multiplayer
 * agreement and must never generate secrets for a public-seed private deck.
 */
final class RandomStream
{
	private int $position = 0;

	/**
	 * Create an independent deterministic stream.
	 * @param string $seed Seed bytes supplied by the caller, not generated here.
	 * @param string $domain Stream purpose, separated from the seed by length framing.
	 * @return void Validates the stream identity without consuming bytes.
	 */
	public function __construct(private string $seed, private string $domain = 'default')
	{
		if(strlen($seed) < 1 || strlen($seed) > 1024 || strlen($domain) > 256 || preg_match('//u', $domain) !== 1)
		throw new \InvalidArgumentException('Invalid random stream seed or domain');
	}

	/**
	 * Read bytes, advancing a serializable position.
	 * @param int $length Number of bytes to read, up to one MiB per call.
	 * @return string Deterministic binary data.
	 */
	public function nextBytes(int $length): string
	{
		if($length < 0 || $length > 1048576 || $this->position > 2147483647 - $length)
		throw new \InvalidArgumentException('Random stream request exceeds its bounds');

		$result = '';
		$prefix = "Phonics.RandomStream.v1\0" . pack('N', strlen($this->seed)) . $this->seed
			. pack('N', strlen($this->domain)) . $this->domain;

		while(strlen($result) < $length)
		{
			$offset = $this->position % 32;
			$block = hash('sha256', $prefix . pack('N', intdiv($this->position, 32)), true);
			$take = min(32 - $offset, $length - strlen($result));
			$result .= substr($block, $offset, $take);
			$this->position += $take;
		}

		return $result;
	}

	/**
	 * Sample an integer without modulo bias.
	 * @param int $bound Exclusive positive upper bound, at most 2^31-1.
	 * @return int Uniform value in [0, bound), assuming uniform input bytes.
	 */
	public function boundedInt(int $bound): int
	{
		if($bound < 1 || $bound > 2147483647) throw new \InvalidArgumentException('Invalid random bound');

		// Work in unsigned 16-bit limbs so WASM never overflows a signed 32-bit PHP
		// integer. tail is 2^32 mod bound; subtract it from 2^32 to form the limit.
		$tail = (self::remainder32(65535, 65535, $bound) + 1) % $bound;
		$limitHigh = $tail ? 65535 - intdiv($tail - 1, 65536) : 65536;
		$limitLow = $tail ? 65535 - (($tail - 1) % 65536) : 0;

		do
		{
			[$high,$low] = array_values(unpack('n2', $this->nextBytes(4)));
		} while($high > $limitHigh || ($high === $limitHigh && $low >= $limitLow));

		return self::remainder32($high, $low, $bound);
	}

	/**
	 * Divide unsigned limbs without overflowing a signed 32-bit intermediate.
	 * @param int $high Most significant 16 bits.
	 * @param int $low Least significant 16 bits.
	 * @param int $bound Positive divisor no larger than 2^31-1.
	 * @return int Exact nonnegative remainder using only integer arithmetic.
	 */
	private static function remainder32(int $high, int $low, int $bound): int
	{
		$remainder = $high % $bound;

		// Dice, card shuffles and the bot policy use this constant-time small-bound path.
		if($bound <= 32768) return ($remainder * 65536 + $low) % $bound;

		for($bit = 15; $bit >= 0; $bit--)
		{
			$gap = $bound - $remainder;
			$remainder = $remainder >= $gap ? $remainder - $gap : $remainder + $remainder;

			if(($low >> $bit) & 1) $remainder = $remainder === $bound - 1 ? 0 : $remainder + 1;
		}

		return $remainder;
	}

	/**
	 * Roll a die using unbiased bounded sampling.
	 * @param int $sides Number of sides.
	 * @return int One-based die result.
	 */
	public function dice(int $sides = 6): int { return $this->boundedInt($sides) + 1; }

	/**
	 * Shuffle a copy with Fisher-Yates; callers control the canonical input order.
	 * @param array $values Ordered input values.
	 * @return array Shuffled list retaining every input value exactly once.
	 */
	public function shuffle(array $values): array
	{
		$values = array_values($values);

		for($i = count($values) - 1; $i > 0; $i--)
		{
			$j = $this->boundedInt($i + 1);
			[$values[$i], $values[$j]] = [$values[$j], $values[$i]];
		}

		return $values;
	}

	/**
	 * Sample distinct positions from a finite collection.
	 * @param array $values Ordered population; equal values can represent distinct instances.
	 * @param int $count Number of instances to take.
	 * @return array Random sample without replacing an input position.
	 */
	public function sampleWithoutReplacement(array $values, int $count): array
	{
		if($count < 0 || $count > count($values)) throw new \InvalidArgumentException('Invalid sample size');

		return array_slice($this->shuffle($values), 0, $count);
	}

	/**
	 * Serialize the stream without advancing it.
	 * @return array Complete local/public replay state; never a secret network capability.
	 */
	public function snapshot(): array
	{
		return [
			'version' => 1
			, 'seed' => base64_encode($this->seed)
			, 'domain' => $this->domain
			, 'position' => $this->position
		];
	}

	/**
	 * Restore an exact byte position from validated state.
	 * @param array $state Previously serialized stream.
	 * @return self Restored independent stream.
	 */
	public static function restore(array $state): self
	{
		if(($state['version'] ?? null) !== 1 || !is_string($state['seed'] ?? null)
			|| !is_string($state['domain'] ?? null) || !is_int($state['position'] ?? null)
			|| $state['position'] < 0 || $state['position'] > 2147483647
		)
		throw new \InvalidArgumentException('Invalid random stream snapshot');

		$seed = base64_decode($state['seed'], true);

		if($seed === false || base64_encode($seed) !== $state['seed'])
		throw new \InvalidArgumentException('Invalid random seed encoding');

		$result = new self($seed, $state['domain']);
		$result->position = $state['position'];

		return $result;
	}
}
