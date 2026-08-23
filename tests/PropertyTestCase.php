<?php

declare(strict_types=1);

namespace Dan\Harness\Tests;

use Eris\Attributes\ErisShrink;
use Eris\Quantifier\ForAll;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Base class for Eris property-based tests. Seeds are random on every run;
 * a failing property prints the reproducing ERIS_SEED command. PR CI keeps
 * Eris's default 100 iterations per property; the nightly deep run raises it
 * via DAN_PROPERTY_ITERATIONS. Shrinking is time-boxed: an unbounded shrink
 * of a failing filesystem-backed property can run for minutes, and the
 * printed seed reproduces the failure anyway.
 *
 * Property tests stay PHPUnit-class style so Infection's PHPUnit adapter
 * counts them as mutant killers (see AGENTS.md).
 */
#[ErisShrink(15)]
abstract class PropertyTestCase extends TestCase
{
    use TestTrait {
        forAll as private erisForAll;
    }

    protected function forAll(mixed ...$generators): ForAll
    {
        $iterations = getenv('DAN_PROPERTY_ITERATIONS');
        if (is_string($iterations) && $iterations !== '' && ctype_digit($iterations)) {
            $this->limitTo((int) $iterations);
        }

        return $this->erisForAll(...$generators);
    }
}
