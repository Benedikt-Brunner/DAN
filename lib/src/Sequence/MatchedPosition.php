<?php

declare(strict_types=1);

namespace Dan\Lib\Sequence;

/**
 * One item of a common subsequence: the position of the shared item in the
 * left sequence and in the right sequence.
 */
final readonly class MatchedPosition
{
    public function __construct(
        public int $leftIndex,
        public int $rightIndex,
    ) {}
}
