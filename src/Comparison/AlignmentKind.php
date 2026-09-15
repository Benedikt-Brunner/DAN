<?php

declare(strict_types=1);

namespace Dan\Harness\Comparison;

/**
 * How one position of the aligned statement sequences relates the baseline
 * to the candidate. Ambiguous marks an alignment the sequences do not pin
 * down - repeated identical statements around a change leave more than one
 * equally good way to line them up, and the comparison says so instead of
 * silently picking one.
 */
enum AlignmentKind: string
{
    case Unchanged = 'unchanged';
    case Modified = 'modified';
    case Inserted = 'inserted';
    case Removed = 'removed';
    case Ambiguous = 'ambiguous';
}
