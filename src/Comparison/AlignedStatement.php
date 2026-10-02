<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

use InvalidArgumentException;

/**
 * One item of an aligned statement sequence: the kind of relation plus the
 * original positions in the baseline and candidate statement profiles, so a
 * reader can always get from the report back to the recorded statements.
 */
final class AlignedStatement
{
    public function __construct(
        public readonly AlignmentKind $kind,
        public readonly ?int $baselineIndex,
        public readonly ?int $candidateIndex,
    ) {
        $valid = match ($kind) {
            AlignmentKind::Unchanged, AlignmentKind::Modified => $baselineIndex !== null && $candidateIndex !== null,
            AlignmentKind::Removed => $baselineIndex !== null && $candidateIndex === null,
            AlignmentKind::Inserted => $baselineIndex === null && $candidateIndex !== null,
            AlignmentKind::Ambiguous => $baselineIndex !== null || $candidateIndex !== null,
        };
        if (!$valid) {
            throw new InvalidArgumentException(sprintf('An %s alignment item cannot reference baseline %s and candidate %s.', $kind->value, var_export($baselineIndex, true), var_export($candidateIndex, true)));
        }
    }
}
