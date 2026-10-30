<?php
declare(strict_types=1);
namespace Phonics\Network;

/**
 * Monotonic player-input command sequence.
 *
 * The bounded value makes ordering and duplicate rejection explicit instead of passing bare integers through
 * command validation.
 */
final class CommandSequence extends UnsignedValue
{
}
