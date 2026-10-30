<?php
declare(strict_types=1);
namespace Phonics\Network;

/**
 * Monotonic host snapshot sequence.
 *
 * The typed value separates snapshot ordering from packet and command sequences during stale-update
 * rejection.
 */
final class SnapshotSequence extends UnsignedValue
{
}
